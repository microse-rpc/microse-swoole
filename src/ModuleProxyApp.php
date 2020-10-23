<?php
namespace Microse;

use Exception;
use Microse\Rpc\RpcChannel;
use Microse\Rpc\RpcClient;
use Microse\Rpc\RpcServer;

class ModuleProxyApp extends ModuleProxy
{
    public ?RpcChannel $_server = null;
    public bool $_clientOnly;
    public array $_cache = [];
    public array $_singletons = [];
    public array $_remoteSingletons = [];

    public function __construct(string $name, $canServe = true)
    {
        parent::__construct($name, $this);
        $this->_clientOnly = !$canServe;
    }

    public function serve($options): RpcServer
    {
        if ($this->_clientOnly) {
            throw new Exception(
                "serve() is not available for client-only module proxy app"
            );
        }

        $server = new RpcServer($options);
        $this->_server = $server;
        return $server;
    }

    public function connect($options): RpcClient
    {
        $client = new RpcClient($options);
        $client->open();
        return $client;
    }
}
