<?php
namespace Microse\Rpc;

use Exception;
use Generator;
use Microse\ModuleProxy;
use Microse\ModuleProxyApp;
use Microse\Map;
use Microse\Utils;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
// use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Server;

class RpcServer extends RpcChannel
{
    public ?Server $wsServer = null;
    private array $registry = [];
    private Map $clients;
    private Map $tasks;
    private ?ModuleProxyApp $proxyRoot = null;

    public function __construct($options, $hostname="")
    {
        parent::__construct($options, $hostname);
        $this->id = $this->id ?: $this->getDSN();
        $this->clients = new Map();
        $this->tasks = new Map();
    }

    public function open(): void
    {
        $pathname = $this->pathname;
        $isUnixSocket = $this->protocol === "ws+unix:";
        $onWorkerStart = @$this->events["WorkerStart"];

        if ($isUnixSocket && $pathname) {
            $dir = \dirname($pathname);

            if (!\file_exists($dir)) {
                \mkdir($dir, 0777, true);
            }

            // If the path exists, it's more likely caused by a previous
            // server process closing unexpected, just remove it before ship
            // the new server.
            if (\file_exists($pathname)) {
                \unlink($pathname);
            }
        }

        if ($isUnixSocket) {
            $this->wsServer = new Server(
                "unix:" . $pathname,
                0,
                SWOOLE_PROCESS,
                SWOOLE_UNIX_STREAM
            );
        } else {
            $this->wsServer = new Server($this->hostname, $this->port);
        }

        $this->wsServer->on(
            "handshake",
            fn ($req, $res) => $this->handleHandshake($req, $res)
        );
        $this->wsServer->on(
            "message",
            fn ($_, $frame) => $this->listenMessage($frame)
        );
        $this->wsServer->on("close", function ($_, int $fd) {
            $this->tasks->pop($fd);
            $this->clients->delete($fd);
        });

        if ($onWorkerStart) {
            $this->wsServer->on("WorkerStart", $onWorkerStart);
        }
        
        $this->wsServer->start();
    }

    private function handleHandshake(Request $req, Response $res)
    {
        // verify authentication

        $isUnixSocket = $this->protocol === "ws+unix:";
        $pathname = @$req->server["PATH_INFO"] ?: "/";
        $clientId = @$req->get["id"] ?: "";
        $secret = @$req->get["secret"] ?: "";

        if (!$isUnixSocket && $pathname !== $this->pathname) {
            $res->status(404);
            $res->end();
            return false;
        }

        if (!$clientId || ($this->secret && $secret !== $this->secret)) {
            $res->status(401);
            $res->end();
            return false;
        }

        $this->handleUpgrade($req, $res);
    }

    private function handleUpgrade(Request $req, Response $res)
    {
        $secWebSocketKey = $req->header['sec-websocket-key'];
        $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

        if (0 === preg_match($patten, $secWebSocketKey) ||
            16 !== strlen(base64_decode($secWebSocketKey))
        ) {
            $res->status(400);
            $res->end();
            return false;
        }
    
        $key = base64_encode(sha1(
            $secWebSocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
            true
        ));

        $headers = [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version' => '13',
        ];

        // Response must not include 'Sec-WebSocket-Protocol' header if not
        // present in request.
        if (isset($req->header['sec-websocket-protocol'])) {
            $headers['Sec-WebSocket-Protocol'] = $req->header['sec-websocket-protocol'];
        }

        foreach ($headers as $key => $val) {
            $res->header($key, $val);
        }

        $res->status(101);
        $res->end();

        $this->wsServer->defer(function () use ($req) {
            $this->handleConnection($req);
        });
    }

    private function handleConnection(Request $req)
    {
        $this->clients->set($req->fd, $req->get["id"]);
        $this->tasks->set($req->fd, new Map());

        // Notify the client that the connection is ready.
        $this->dispatch($req->fd, ChannelEvents::CONNECT, $this->id);
    }

    private function dispatch(int $fd, int $event, $taskId, $data = null)
    {
        if ($event === ChannelEvents::_THROW && $data instanceof Exception) {
            $data = [
                "name" => \get_class($data),
                "message" => $data->getMessage(),
                "code" => $data->getCode(),
                "file" => $data->getFile(),
                "line" => $data->getLine(),
                "stack" => $data->getTraceAsString()
            ];
        }

        $_data = null;

        if ($event === ChannelEvents::CONNECT) {
            $_data = [$event, \strval($taskId)];
        } elseif ($event === ChannelEvents::PONG) {
            $_data = [$event, \intval($taskId)];
        } elseif ($this->codec === "JSON") {
            $_data = [$event, $taskId, $data];
        }

        if ($_data) {
            go(function () use ($fd, $_data) {
                $this->wsServer->push($fd, \json_encode($_data));
            });
        }
    }

    private function listenMessage(Frame $frame)
    {
        if ($frame->opcode === WEBSOCKET_OPCODE_TEXT) {
            $this->handleMessage($frame->fd, $frame->data);
        }
    }

    private function handleMessage(int $fd, string $msg)
    {
        /** @var array */
        $req = null;

        try {
            $req = \json_decode($msg, true);
        } catch (\Exception $err) {
            $this->handleError($err);
        }

        if (!\is_array($req) || !\is_int(@$req[0])) {
            return;
        }

        $event = \intval($req[0]);
        $taskId = \intval($req[1]);
        $module = \strval(@$req[2] ?? "");
        $method = \strval(@$req[3] ?? "");
        /** @var array */
        $args = @$req[4] ?? [];

        if ($event === ChannelEvents::_THROW &&
            \count($args) === 1 && \is_array($args[0])
        ) {
            $args[0] = Utils::parseException($args[0]);
        }

        if ($event === ChannelEvents::INVOKE) {
            $this->handleInvokeEvent($fd, $taskId, $module, $method, $args);
        } elseif ($event === ChannelEvents::_YIELD
            || $event === ChannelEvents::_THROW
            || $event === ChannelEvents::_RETURN
        ) {
            $this->handleGeneratorEvents(
                $fd,
                $event,
                $taskId,
                $module,
                $method,
                $args
            );
        } elseif ($event === ChannelEvents::PING) {
            $this->dispatch($fd, ChannelEvents::PONG, $taskId);
        }
    }

    private function handleInvokeEvent(
        int $fd,
        int $taskId,
        string $module,
        string $method,
        array $args
    ) {
        /** @var Map */
        $tasks = $this->tasks->get($fd);
        $event = 0;
        $result = null;

        try {
            /** @var ModuleProxy */
            $mod = @$this->registry[$module];

            if (!$mod) {
                Utils::throwUnavailableError($module);
            }

            /** @var ModuleProxyApp */
            $app = $mod->_root;
            $ins = Utils::getInstance($app, $module);
            $task = $ins->{$method}(...$args);

            if ($task instanceof Generator) {
                $tasks->set($taskId, $task);
                $event = ChannelEvents::INVOKE;
            } else {
                $result = $task;
                $event = ChannelEvents::_RETURN;
            }
        } catch (\Exception $err) {
            $event = ChannelEvents::_THROW;
            $result = $err;
        }

        $this->dispatch($fd, $event, $taskId, $result);
    }

    private function handleGeneratorEvents(
        int $fd,
        int $event,
        int $taskId,
        string $module,
        string $method,
        array $args
    ) {
        /** @var Map */
        $tasks = $this->tasks->get($fd);
        /** @var Generator */
        $task = $tasks->get($taskId);
        $result = null;

        try {
            if (!$task) {
                throw new Exception("Failed to call {$module}.{$method}()");
            }

            if ($event === ChannelEvents::_YIELD) {
                if (count($args) > 0) { // calling `send()`
                    $result = $task->send($args[0]);

                    if ($task->valid()) {
                        $result = ["done" => false, "value" => $result];
                    } else {
                        $event = ChannelEvents::_RETURN;
                        $result = ["done" => true, "value" => $task->getReturn()];
                    }
                } else { // in foreach
                    $result = $task->current();

                    if ($task->valid()) {
                        $task->next();
                        $result = ["done" => false, "value" => $result];
                    } else {
                        $event = ChannelEvents::_RETURN;
                        $result = ["done" => true, "value" => $task->getReturn()];
                    }
                }
            } elseif ($event === ChannelEvents::_THROW) {
                // Calling the throw method will cause an error being thrown and
                // go to the catch block.
                $task->throw(@$args[0]);
            }
        } catch (\Exception $err) {
            $event = ChannelEvents::_THROW;
            $result = $err;
            $tasks->delete($taskId);
        }

        $this->dispatch($fd, $event, $taskId, $result);
    }

    public function close(): void
    {
        if ($this->wsServer) {
            $this->wsServer->shutdown();
        }

        if ($this->proxyRoot) {
            $this->proxyRoot->_server = null;
            $this->proxyRoot->_remoteSingletons = [];
            $this->proxyRoot = null;
        }
    }

    public function register(ModuleProxy $mod): void
    {
        $this->registry[$mod->name] = $mod;
    }

    /**
     * Publishes data to the corresponding topic, if `$clients` are provided,
     * the topic will only be published to them.
     */
    public function publish(string $topic, $data, array $clients = []): bool
    {
        $sent = false;

        foreach ($this->clients as $fd => $id) {
            if (\count($clients) === 0 || \in_array($id, $clients)) {
                $this->dispatch($fd, ChannelEvents::PUBLISH, $topic, $data);
                $sent = true;
            }
        }

        return $sent;
    }

    /**
     * Returns all IDs of clients that connected to the server.
     */
    public function getClients(): array
    {
        return [...$this->clients->values()];
    }

    /**
     * Binds a function to the swoole server's WorkerStart event.
     */
    public function onWorkerStart(callable $handler)
    {
        $this->events["WorkerStart"] = $handler;
    }
}
