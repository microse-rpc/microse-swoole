<?php
namespace Microse\Rpc;

use Exception;
use Microse\ModuleProxy;
use Microse\Utils;
use Rowbot\URL\URL;
use TypeError;

class ChannelEvents
{
    const CONNECT = 1;
    const INVOKE = 2;
    const _RETURN = 3;
    const _THROW = 4;
    const _YIELD = 5;
    const PUBLISH = 6;
    const PING = 7;
    const PONG = 8;
}

/** An RPC channel that allows modules to communicate remotely. */
abstract class RpcChannel
{
    public string $protocol = "ws:";
    public string $hostname = "127.0.0.1";
    public int $port = 80;
    public string $pathname = "/";
    public string $id = "";
    public string $secret = "";
    public string $codec = "JSON";
    public $ssl = null;
    protected array $events = [];

    public function __construct($options, string $hostname = "")
    {
        if (is_int($options)) {
            $this->port = intval($options);
            $this->hostname = $hostname ?: $this->hostname;
        } elseif (is_array($options)) {
            $this->protocol = @$options["protocol"] ?? $this->protocol;
            $this->hostname = @$options["hostname"] ?? $this->hostname;
            $this->port = @$options["port"] ?? $this->port;
            $this->pathname = @$options["pathname"] ?? $this->pathname;
            $this->id = @$options["id"] ?? $this->id;
            $this->secret = @$options["secret"] ?? $this->secret;
            $this->codec = @$options["codec"] ?? $this->codec;
            $this->ssl = @$options["ssl"] ?? $this->codec;
        } elseif (is_string($options)) {
            $url = strval($options);
            $isAbsPath = $url[0] === "/";

            if (!Utils::startsWith($url, "ws:") ||
                !Utils::startsWith($url, "wss:")
            ) {
                // Windows absolute path
                if (preg_match('/^[a-zA-Z]:[\\/]/', $url)) {
                    $url = "ws+unix:"+$url;
                    $isAbsPath = true;
                }

                $urlObj = new URL($url, "ws+unix://localhost:80");
                $pathname = $urlObj->pathname;
                $searchParams = $urlObj->searchParams;
                $isUnixSocket = $urlObj->protocol === "ws+unix:";

                $this->protocol = $urlObj->protocol;
                $this->hostname = $isUnixSocket ? "" : $urlObj->hostname;
                $this->port = $isUnixSocket ? 0 : intval($urlObj->port ?: 80);
                $this->id = $searchParams->get("id") ?: "";
                $this->secret = $searchParams->get("secret") ?: "";
                $this->codec = $searchParams->get("codec") ?: "";

                if ($isUnixSocket) {
                    if (Utils::startsWith(PHP_OS, "WIN")) {
                        throw new Exception(
                            "IPC on Windows is currently not supported"
                        );
                    } elseif ($isAbsPath) {
                        $this->pathname = $pathname;
                    } elseif ($pathname !== "/") {
                        $this->pathname = \getcwd() . "/" . $pathname;
                    } else {
                        throw new Exception("IPC requires a pathname");
                    }
                } else {
                    $this->pathname = $pathname;
                }
            }
        } else {
            throw new TypeError(
                '$options must be a string, number or an assoc array'
            );
        }

        $this->codec = $this->codec ?: "JSON";
        $this->onError(function (Exception $err) {
            var_dump($err);
        });
    }

    /** Gets the data source name according to the configuration. */
    public function getDSN()
    {
        if ($this->protocol === "ws+unix:") {
            return "ipc:" . $this->pathname;
        } else {
            return "rpc://{$this->hostname}:{$this->port}";
        }
    }

    /**
     * Binds an error handler invoked whenever an error occurred in asynchronous
     * operations which can't be caught during run-time.
     */
    public function onError(callable $handler)
    {
        $this->events["error"] = $handler;
    }

    /**
     * Handles any error happened during runtime asynchronously.
     */
    protected function handleError(Exception $err)
    {
        go(function () use (&$err) {
            /** @var callable */
            $handle = $this->events["error"];
            $handle($err);
        });
    }

    /** Opens the channel. */
    abstract public function open(): void;

    /** Closes the channel. */
    abstract public function close(): void;

    /** Registers a module proxy to the channel. */
    abstract public function register(ModuleProxy $mod): void;
}
