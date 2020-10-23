<?php
namespace Microse\Tests\Rpc;

use Microse\Utils;
use PHPUnit\Framework\TestCase;

include_once __DIR__ . "/../Base.php";

class RpcServerTest extends TestCase
{
    public function testSeringAndConnectingIpcService()
    {
        if (Utils::startsWith(PHP_OS, "WIN")) {
            return;
        }

        global $app;

        $sockPath = \getcwd() . "/test.sock";
        $server = $app->serve($sockPath);
        $client = $app->connect($sockPath);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $app->Services->Detail->setName("Mr. Handsome");
        $res = $app->Services->Detail->getName();

        $this->assertEquals("Mr. Handsome", $res);

        $client->close();
        $server->close();
    }

    public function testClosingServerBeforeClosingClient()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = $app->connect(["port" => $server->port]);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $app->Services->Detail->getOrgs();
        $this->assertEquals(1, count($server->getClients()));

        $server->close();
        $this->assertEquals(0, count($server->getClients()));

        $client->close();
    }
}
