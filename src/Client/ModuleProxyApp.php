<?php
namespace Microse\Client;

use Microse\Rpc\RpcChannel;
use Microse\Rpc\RpcClient;

class ModuleProxyApp extends ModuleProxy
{
    public ?RpcChannel $_server;
    public array $_cache = [];
    public array $_singletons = [];
    public array $_remoteSingletons = [];

    public function __construct(string $name)
    {
        parent::__construct($name, $this);
    }

    public function connect($options): RpcClient
    {
        $client = new RpcClient($options);
        $client->open();
        return $client;
    }
}
