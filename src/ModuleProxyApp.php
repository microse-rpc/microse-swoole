<?php
namespace Microse;

use Error;
use Microse\Rpc\RpcChannel;
use Microse\Rpc\RpcClient;
use Microse\Rpc\RpcServer;

class ModuleProxyApp extends ModuleProxy
{
    public ?RpcChannel $_server = null;
    public bool $_clientOnly;
    public Map $_singletons;
    public Map $_remoteSingletons;

    /**
     * @param string $name must be a valid namespace in order to load modules.
     * @param bool $canServe when false, the proxy cannot be used to serve
     *  modules, and a client-only application will be created.
     */
    public function __construct(string $name, $canServe = true)
    {
        parent::__construct($name, $this);
        $this->_clientOnly = !$canServe;
        $this->_singletons = new Map();
        $this->_remoteSingletons = new Map();
    }

    /**
     * Serves an RPC server according to the given URL or Unix socket
     * filename, or provide a dict for detailed options.
     * 
     * - `serve(string $url)`
     * - `serve(array $options)`
     */
    public function serve($options): RpcServer
    {
        if ($this->_clientOnly) {
            throw new Error(
                "serve() is not available for client-only module proxy app"
            );
        }

        $server = new RpcServer($options);
        $server->open();
        $this->_server = $server;
        return $server;
    }

    /**
     * Connects to an RPC server according to the given URL or Unix socket
     * filename, or provide a dict for detailed options.
     * 
     * - `connect(string $url)`
     * - `connect(array $options)`
     */
    public function connect($options): RpcClient
    {
        $client = new RpcClient($options);
        $client->open();
        return $client;
    }
}
