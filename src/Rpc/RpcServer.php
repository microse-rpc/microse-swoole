<?php
namespace Microse\Rpc;

use Co\Http\Server;
use Exception;
use Generator;
use Microse\ModuleProxy;
use Microse\ModuleProxyApp;
use Microse\Map;
use Microse\Utils;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Throwable;

class RpcServer extends RpcChannel
{
    public ?Server $httpServer = null;
    private Map $registry;
    private Map $clients;
    private Map $tasks;
    private ?ModuleProxyApp $proxyRoot = null;

    public function __construct($options, $hostname="")
    {
        parent::__construct($options, $hostname);
        $this->id = $this->id ?: $this->getDSN();
        $this->registry = new Map();
        $this->clients = new Map();
        $this->tasks = new Map();
    }

    public function open(): void
    {
        $pathname = $this->pathname;
        $isUnixSocket = $this->protocol === "ws+unix:";

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
            $this->httpServer = new Server(
                "unix:" . $pathname,
                0,
                false,
                SWOOLE_UNIX_STREAM
            );
        } elseif ($this->protocol === "wss:") {
            $this->httpServer = new Server(
                $this->hostname,
                $this->port,
                false,
                SWOOLE_SOCK_TCP | SWOOLE_SSL
            );
            $this->httpServer->set([
                'ssl_cert_file' => $this->certFile,
                'ssl_key_file' => $this->keyFile,
                'ssl_allow_self_signed' => true,
            ]);
        } else {
            $this->httpServer = new Server($this->hostname, $this->port);
        }

        $path = $isUnixSocket ? "/" : $pathname;
        $this->httpServer->handle(
            $path,
            fn ($req, $res) => $this->handleHandshake($req, $res)
        );
        
        // Starts the server in the background.
        go(fn () => $this->httpServer->start());

        $this->updateAddress();
    }

    private function updateAddress()
    {
        $dsn = $this->getDSN();

        if ($this->protocol !== "ws+unix:") {
            $this->port = $this->httpServer->port;
        }

        if ($this->id === $dsn) {
            $this->id = $this->getDSN();
        }
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

        $res->upgrade();
        $this->handleConnection($req, $res);
    }

    private function handleConnection(Request $req, Response $ws)
    {
        $this->clients->set($ws, $req->get["id"]);
        $this->tasks->set($ws, new Map());

        // Notify the client that the connection is ready.
        $this->dispatch($ws, ChannelEvents::CONNECT, $this->id);

        go(fn () => $this->listenMessage($ws));
    }

    private function dispatch(Response $ws, int $event, $taskId, $data = null)
    {
        if ($event === ChannelEvents::_THROW && $data instanceof Throwable) {
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
            try {
                $msg = \json_encode($_data);
                go(fn () => $ws->push($msg));
            } catch (Throwable $err) {
                $this->dispatch($ws, ChannelEvents::_THROW, $taskId, $err);
            }
        }
    }

    private function listenMessage(Response $ws)
    {
        while (true) {
            $frame = $ws->recv();

            if ($frame === false) { // connection error
                $errno = \swoole_last_error();
                $message = \swoole_strerror($errno);

                if (!\preg_match("/reset|close/", $message)) {
                    $this->handleError(new Exception($message, $errno));
                    go(fn () => $this->handleDisconnection($ws));
                }

                break;
            } elseif ($frame === "") { // connection close
                go(fn () => $this->handleDisconnection($ws));
                break;
            } else {
                if ($frame->opcode === WEBSOCKET_OPCODE_PING) { // ping frame
                    go(fn () => $this->handlePing($ws, $frame->data));
                } elseif ($frame->opcode === WEBSOCKET_OPCODE_TEXT) { // text frame
                    go(fn () => $this->handleMessage($ws, $frame->data));
                }
            }
        }
    }

    private function handleDisconnection(Response $ws)
    {
        $ws->close();
        $this->tasks->pop($ws);
        $this->clients->delete($ws);
    }

    private function handlePing(Response $ws, string $data)
    {
        $_frame = new Frame();
        $_frame->opcode = WEBSOCKET_OPCODE_PONG;
        $_frame->data = $data;
        $ws->push($_frame);
    }

    private function handleMessage(Response $ws, string $msg)
    {
        /** @var array */
        $req = null;

        try {
            $req = \json_decode($msg, true);
        } catch (Throwable $err) {
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
            $args[0] = Utils::parseError($args[0]);
        }

        if ($event === ChannelEvents::INVOKE) {
            $this->handleInvokeEvent($ws, $taskId, $module, $method, $args);
        } elseif ($event === ChannelEvents::_YIELD
            || $event === ChannelEvents::_THROW
            || $event === ChannelEvents::_RETURN
        ) {
            $this->handleGeneratorEvents(
                $ws,
                $event,
                $taskId,
                $module,
                $method,
                $args
            );
        } elseif ($event === ChannelEvents::PING) {
            $this->dispatch($ws, ChannelEvents::PONG, $taskId);
        }
    }

    private function handleInvokeEvent(
        Response $ws,
        int $taskId,
        string $module,
        string $method,
        array $args
    ) {
        /** @var Map */
        $tasks = $this->tasks->get($ws);
        $event = 0;
        $result = null;

        try {
            /** @var ModuleProxy */
            $mod = $this->registry->get($module);

            if (!$mod) {
                Utils::throwUnavailableError($module);
            }

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
        } catch (Throwable $err) {
            $event = ChannelEvents::_THROW;
            $result = $err;
        }

        $this->dispatch($ws, $event, $taskId, $result);
    }

    private function handleGeneratorEvents(
        Response $ws,
        int $event,
        int $taskId,
        string $module,
        string $method,
        array $args
    ) {
        /** @var Map */
        $tasks = $this->tasks->get($ws);
        /** @var Generator */
        $task = $tasks->get($taskId);
        $result = null;

        try {
            if (!$task) {
                throw new Exception("Failed to call {$module}.{$method}()");
            }

            if ($event === ChannelEvents::_YIELD) {
                if (count($args) > 0) { // calling `send()`
                    $value = $task->send($args[0]);
                    $key = $task->key();

                    if ($task->valid()) {
                        $result = [
                            "done" => false,
                            "key" => $key,
                            "value" => $value,
                        ];
                    } else {
                        $event = ChannelEvents::_RETURN;
                        $result = [
                            "done" => true,
                            "key" => $key,
                            "value" => $task->getReturn()
                        ];
                    }
                } else { // in foreach
                    $value = $task->current();
                    $key = $task->key();

                    if ($task->valid()) {
                        $task->next();
                        $result = [
                            "done" => false,
                            "key" => $key,
                            "value" => $value,
                        ];
                    } else {
                        $event = ChannelEvents::_RETURN;
                        $result = [
                            "done" => true,
                            "key" => $key,
                            "value" => $task->getReturn()
                        ];
                    }
                }
            } elseif ($event === ChannelEvents::_THROW) {
                // Calling the throw method will cause an error being thrown and
                // go to the catch block.
                $task->throw(@$args[0]);
            }
        } catch (Throwable $err) {
            $event = ChannelEvents::_THROW;
            $result = $err;
            $tasks->delete($taskId);
        }

        $this->dispatch($ws, $event, $taskId, $result);
    }

    public function close(): void
    {
        if ($this->httpServer) {
            $this->httpServer->shutdown();
            $this->clients = new Map();
            $this->tasks = new Map();
        }

        if ($this->proxyRoot) {
            $this->proxyRoot->_server = null;
            $this->proxyRoot->_remoteSingletons = new Map();
            $this->proxyRoot = null;
        }
    }

    public function register($mod): void
    {
        /** @var ModuleProxy $mod */
        $this->registry->set($mod->name, $mod);
    }

    /**
     * Publishes data to the corresponding topic, if `$clients` are provided,
     * the topic will only be published to them.
     */
    public function publish(string $topic, $data, array $clients = []): bool
    {
        $sent = false;

        foreach ($this->clients as $ws => $id) {
            if (\count($clients) === 0 || \in_array($id, $clients, \true)) {
                $this->dispatch($ws, ChannelEvents::PUBLISH, $topic, $data);
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
}
