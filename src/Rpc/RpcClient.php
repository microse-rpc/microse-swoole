<?php
namespace Microse\Rpc;

use Chan;
use Error;
use Exception;
use Microse\ModuleProxy;
use Microse\Incremental;
use Iterator;
use Microse\Utils;
use Microse\Map;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;
use Swoole\Timer;
use Throwable;

class RpcClient extends RpcChannel
{
    public $timeout = 5000;
    public $pingTimeout = 5000;
    public $pingInterval = 5000;
    public $serverId = "";
    public $state = "initiated";
    public ?Client $socket = null;
    public $registry = [];
    public Map $topics;
    public Map $tasks;
    public Incremental $taskId;
    private int $pingTimer = 0;
    private int $destructTimer = 0;
    private $ResolvableEvents = [
        ChannelEvents::_RETURN,
        ChannelEvents::INVOKE,
        ChannelEvents::_YIELD
    ];

    public function __construct($options, string $hostname = "")
    {
        parent::__construct($options, $hostname);

        if (\is_array($options)) {
            $this->timeout = @$options["timeout"] ?? $this->timeout;
            $this->serverId = @$options["serverId"] ?? $this->serverId;
            $this->pingTimeout = @$options["pingTimeout"] ?? $this->pingTimeout;
            $this->pingInterval = @$options["pingInterval"] ?? $this->pingInterval;
        }

        $this->id = $this->id ?: Utils::randStr(10);
        $this->serverId = $this->serverId ?: $this->getDSN();
        $this->topics = new Map();
        $this->tasks = new Map();
        $this->taskId = new Incremental();
    }

    public function isConnecting(): bool
    {
        return $this->state === "connecting";
    }

    public function isConnected(): bool
    {
        return $this->state === "connected";
    }

    public function isClosed(): bool
    {
        return $this->state === "closed";
    }

    public function open(): void
    {
        if ($this->socket) {
            throw new Exception("Channel to {$this->serverId} is already open");
        } elseif ($this->isClosed()) {
            throw new Exception("Cannot reconnect to "
                . $this->serverId . " after closing the channel");
        }

        $this->state = "connecting";
        $url = "";

        if ($this->protocol === "ws+unix:") {
            // create unix socket
            $this->socket = new Client("unix://{$this->pathname}", 0);
            $url = "/";
        } else {
            $this->socket = new Client($this->hostname, $this->port, false);
            $url = $this->pathname;
        }

        $url .= "?id={$this->id}";

        if ($this->secret) {
            $url .= "&secret={$this->secret}";
        }

        /** @var bool */
        $ok = $this->socket->upgrade($url); // upgrade protocol

        if (!$ok || 101 !== $this->socket->statusCode) {
            throw new Error("Cannot connect to {$this->serverId}");
        }

        // Accept the first message for handshake.
        $frame = $this->socket->recv();
        $res = null;

        if ($frame->opcode === WEBSOCKET_OPCODE_TEXT) {
            $res = $this->parseResponse($frame->data);
        }

        if (!\is_array($res) || !\is_int(@$res[0])) {
            $this->close();
            throw new Error("Cannot connect to {$this->serverId}");
        } else {
            $this->state = "connected";
            $this->updateServerId(\strval($res[1]));
            go(fn () => $this->listenMessage());
            $this->resume();
        }
    }

    private function updateServerId(string $serverId)
    {
        if ($serverId !== $this->serverId) {
            foreach ($this->registry as $name => $mod) {
                $singletons = $mod->_root->_remoteSingletons;

                if (array_key_exists($this->serverId, $singletons)) {
                    $singletons[$serverId] = $singletons[$this->serverId];
                    unset($singletons[$this->serverId]);
                }
            }
        }
    }

    private function parseResponse($msg)
    {
        if (is_string($msg)) {
            try {
                return \json_decode($msg, true);
            } catch (Throwable $err) {
                $this->handleError($err);
            }
        }
    }

    private function listenMessage()
    {
        while (true) {
            $frame = $this->socket->recv();

            if (false === $frame) { // connection closed
                    if ($this->socket->errCode !== 0) { // closed with error
                        $message = socket_strerror($this->socket->errCode);
                        $err = new Exception($message, $this->socket->errCode);
                        $this->handleError($err);
                        $this->socket->close();
                        break;
                    }
            } else {
                if ($frame->opcode === WEBSOCKET_OPCODE_PONG) { // accept pong
                    if ($this->destructTimer) {
                        Timer::clear($this->destructTimer);
                    }
                } elseif ($frame->opcode === WEBSOCKET_OPCODE_TEXT) {
                    $msg = $frame->data;
                    $this->handleMessage($msg);
                }
            }
        }

        // If the socket is closed or reset. but the channel remains open,
        // pause the service immediately and try to reconnect.
        if (!$this->isConnecting() && !$this->isClosed()) {
            $this->pause();
            go(fn () => $this->reconnect());
        }
    }

    private function handleMessage($msg)
    {
        $res = $this->parseResponse($msg);

        if (!\is_array($res) || !\is_int($res[0])) {
            return;
        }

        $event = \intval($res[0]);
        $taskId = $res[1];
        $data = @$res[2];

        if (\array_search($event, $this->ResolvableEvents) !== false) {
            /** @var Task */
            $task = $this->tasks->pop($taskId);

            if ($task) {
                $task->resolve($data, $event);
            }
        } elseif ($event === ChannelEvents::_THROW) {
            /** @var Task */
            $task = $this->tasks->pop($taskId);

            if ($task) {
                $task->reject(Utils::parseError($data));
            }
        } elseif ($event === ChannelEvents::PUBLISH) {
            // If receives the PUBLISH event, call all the handlers bound to the
            // corresponding topic.
            $handlers = $this->topics->get($taskId);

            if ($handlers && count($handlers) > 0) {
                foreach ($handlers as $handle) {
                    try {
                        // run the handler asynchronously.
                        go(fn () => $handle($data));
                    } catch (Throwable $err) {
                        $this->handleError($err);
                    }
                }
            }
        }
    }

    private function reconnect()
    {
        while (true) {
            if ($this->isClosed()) {
                break;
            }

            try {
                $this->open();
                break;
            } catch (Throwable $err) {
                \co::sleep(2);
            }
        }
    }

    public function send(...$data)
    {
        go(function () use ($data) {
            if ($this->socket) {
                if ($this->codec === "JSON") {
                    $this->socket->push(\json_encode($data));
                }
            }
        });
    }

    /**
     * Subscribes a handle function to the corresponding topic.
     */
    public function subscribe(string $topic, callable $handler)
    {
        $handlers = $this->topics->get($topic);

        if (!$handlers) {
            $handlers = [$handler];
            $this->topics->set($topic, $handlers);
        } else {
            array_push($handlers, $handler);
        }

        return $this;
    }

    /**
     * Unsubscribes the handle function or all handlers from the corresponding
     * topic.
     */
    public function unsubscribe(string $topic, callable $handler = null): bool
    {
        if (!$handler) {
            return $this->topics->delete($topic);
        } else {
            $handlers = $this->topics->get($topic);

            if ($handlers) {
                $i = array_search($handler, $handlers);

                if (false !== $i) {
                    \array_splice($handlers, $i, 1);
                    return true;
                }
            }
        }
    }

    public function close(): void
    {
        $this->state = "closed";
        $this->pause();

        if ($this->socket) {
            $frame = new CloseFrame();
            $frame->code = 1000;
            $this->socket->push($frame); // send a close frame
            $this->socket->close(); // terminate connection
        }

        foreach ($this->registry as $name => $mod) {
            /** @var array */
            $singletons = @$mod->_root->_remoteSingletons[$mod->name];

            if ($singletons && array_key_exists($this->serverId, $singletons)) {
                unset($singletons[$this->serverId]);
            }
        }
    }

    /**
     * Pauses the channel and redirect traffic to other channels.
     */
    public function pause()
    {
        $this->flushReadyState(0);

        if ($this->pingTimer) {
            Timer::clear($this->pingTimer);
        }

        if ($this->destructTimer) {
            Timer::clear($this->destructTimer);
        }
    }

    /**
     * Resumes the channel and continue handling traffic.
     */
    public function resume()
    {
        $this->flushReadyState(1);

        // Ping the server constantly in order to check connection
        // availability.
        $this->pingTimer = Timer::tick($this->pingInterval, function () {
            $frame = new Frame();
            $frame->opcode = WEBSOCKET_OPCODE_PING;
            $frame->data = Utils::getMilliseconds();
            $this->socket->push($frame);

            $this->destructTimer = Timer::after($this->pingTimeout, function () {
                $frame = new CloseFrame();
                $frame->code = 1002;
                $this->socket->push($frame);
                $this->socket->close();
            });
        });
    }

    public function register(ModuleProxy $mod): void
    {
        if (!array_key_exists($mod->name, $this->registry)) {
            $this->registry[$mod->name] = $mod;
            /** @var array */
            $singletons = @$mod->_root->_remoteSingletons[$mod->name];

            if (!$singletons) {
                $singletons = [];
                $mod->_root->_remoteSingletons[$mod->name] = &$singletons;
            }

            /** @var RpcInstance */
            $singleton = &$singletons[$this->serverId];
            $singleton = $this->createRemoteInstance($mod);

            if ($this->isConnected()) {
                $singleton->readyState = 1;
            } else {
                $singleton->readyState = 0;
            }
        }
    }

    private function flushReadyState(int $state)
    {
        /** @var ModuleProxy $mod */
        foreach ($this->registry as $name => $mod) {
            /** @var array */
            $singletons = @$mod->_root->_remoteSingletons[$mod->name];

            if ($singletons && array_key_exists($this->serverId, $singletons)) {
                $singletons[$this->serverId]->readyState = $state;
            }
        }
    }

    private function createRemoteInstance($mod)
    {
        return new RpcInstance($mod, $this);
    }
}

class RpcInstance
{
    private $module = null;
    private RpcClient $client;
    public int $readyState = 0;

    public function __construct(ModuleProxy $module, RpcClient $client)
    {
        $this->module = $module;
        $this->client = $client;
    }

    public function __call(string $name, array $args)
    {
        $mod = $this->module;

        if (!$mod->_root->_clientOnly) {
            $className = \str_replace(".", "\\", $mod->name);
            if (!\class_exists($className)) {
                throw new Error("Class '{$className}' not found");
            } elseif (!\method_exists($className, $name)) {
                throw new Error(
                    "Call to undefined method {$mod->name}::{$name}()"
                );
            }

            $server = $mod->_root ? $mod->_root->_server : null;

            // If the RPC server and the RPC client runs in the same
            // process, then directly call the local instance to prevent
            // unnecessary network traffics.
            if ($server && $server->id === $this->client->serverId) {
                $ins = Utils::getInstance($mod->_root, $mod->name);
                return $ins->{$name}(...$args);
            }

            if (!$this->client->isConnected()) {
                Utils::throwUnavailableError($mod->name);
            }
        }

        // In swoole, RPC calls can happen immediately.
        $task = new Task($this->module->name, $name, $this->client->timeout);
        $taskId = $this->client->taskId->next();
        $this->client->tasks->set($taskId, $task);
        $this->client->send(
            ChannelEvents::INVOKE,
            $taskId,
            $this->module->name,
            $name,
            $args
        );

        /** @var array */
        $res = $task->wait();
        $event = $res["event"];
        $value = $res["value"];

        if ($event === ChannelEvents::_RETURN) { // regular function call
            return $value;
        } elseif ($event === ChannelEvents::_THROW) { // throw exception
            throw $value;
        } else { // generator call
            return new RpcGenerator(
                $this->client,
                $this->module->name,
                $name,
                $taskId
            );
        }
    }
}

class Task
{
    private Chan $msg;
    private int $timer;
    
    public function __construct(string $module, string $method, int $timeout)
    {
        $this->msg = new Chan(1);
        $this->timer = Timer::after(
            $timeout,
            function () use ($module, $method, $timeout) {
                $this->reject(new Exception(
                    "{$module}.{$method}() timeout after {$timeout} ms"
                ));
            }
        );
    }

    public function resolve($value, int $event)
    {
        $this->msg->push([ "event"=>$event, "value"=>$value]);
    }

    public function reject($err)
    {
        $this->msg->push(["event"=>ChannelEvents::_THROW, "value"=>$err]);
    }

    public function wait()
    {
        $res = $this->msg->pop();
        $this->msg->close();
        Timer::clear($this->timer);

        if ($res["event"] === ChannelEvents::_THROW) {
            throw $res;
        } else {
            return $res;
        }
    }
}

/**
 * The RPC implementation of `Generator`.
 */
final class RpcGenerator implements Iterator
{
    private RpcClient $client;
    private string $module;
    private string $method;
    private int $taskId;
    private string $state;
    private $value = null;
    private $counter = 0;

    public function __construct(
        RpcClient $client,
        string $module,
        string $method,
        int $taskId
    ) {
        $this->client = $client;
        $this->module = $module;
        $this->method = $method;
        $this->taskId = $taskId;
        $this->state = "pending";

        $this->invokeTask(ChannelEvents::_YIELD, []);
    }

    public function rewind(): void
    {
        $this->counter = 0;
    }

    public function valid(): bool
    {
        return $this->state === "pending";
    }

    public function current()
    {
        return $this->value;
    }

    public function key()
    {
        return $this->counter;
    }

    public function next()
    {
        return $this->invokeTask(ChannelEvents::_YIELD, []);
    }

    public function send($value)
    {
        return $this->invokeTask(ChannelEvents::_YIELD, [$value]);
    }

    public function throw(\Throwable $err)
    {
        return $this->invokeTask(ChannelEvents::_THROW, [$err]);
    }

    public function getReturn()
    {
        if ($this->state !== "resolved") {
            throw new Exception(
                "Cannot get return value of a generator that hasn't returned"
            );
        } else {
            return $this->value;
        }
    }

    public function __wakeup()
    {
        throw new Exception("Serialization of 'RpcGenerator' is not allowed");
    }

    private function invokeTask(int $event, array $args)
    {
        if ($this->state === "closed") {
            if ($event === ChannelEvents::INVOKE) {
                return $this->result;
            } elseif ($event === ChannelEvents::_YIELD) {
                return null;
            } elseif ($event === ChannelEvents::_RETURN) {
                return @$args[0];
            } elseif ($event === ChannelEvents::_THROW) {
                throw @$args[0];
            }
        } else {
            return $this->prepareTask($event, $args);
        }
    }

    private function prepareTask(int $event, $args)
    {
        $this->client->send(
            $event,
            $this->taskId,
            $this->module,
            $this->method,
            $args
        );

        $task = new Task($this->module, $this->method, $this->client->timeout);
        $this->client->tasks->set($this->taskId, $task);
        $res = $task->wait();

        $event = $res["event"];
        $value = $res["value"];

        if ($event === ChannelEvents::_YIELD) {
            $this->counter += 1;
            $this->value = $value["value"];
            return $this->value;
        } elseif ($event === ChannelEvents::_RETURN) {
            $this->state = "resolved";
            $this->value = @$value["value"];
            $this->rewind();
            return $this->value;
        } elseif ($event === ChannelEvents::_THROW) {
            $this->state = "rejected";
            $this->value = null;
            $this->rewind();
            throw $value;
        }
    }
}
