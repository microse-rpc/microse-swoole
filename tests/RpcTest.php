<?php
namespace Microse\Tests;

use Microse\Utils;
use PHPUnit\Framework\TestCase;

include_once __DIR__ . "/app.php";

final class RpcTest extends TestCase
{
    public function testCreatingAndConnectingViaUrl()
    {
        global $app;
        $url = "ws://localhost:12345";
        $dsn = "ws://localhost:12345/";
        $server = $app->serve($url);
        $client = $app->connect($url);

        $this->assertEquals($dsn, $server->getDSN());
        $this->assertEquals($dsn, $server->id);
        $this->assertEquals($dsn, $client->getDSN());
        $this->assertEquals($dsn, $client->serverId);

        // $this->assertEquals($dsn, $client->id);

        $app->Services->Detail->setName("Mr. Handsome");
        $res = $app->Services->Detail->getName();

        $this->assertEquals("Mr. Handsome", $res);

        $client->close();
        $server->close();
    }

    public function testSeringAndConnectingIpcService()
    {
        if (Utils::startsWith(PHP_OS, "WIN")) {
            return;
        }

        global $app;

        $sockPath = \getcwd() . "/test.sock";
        $dsn = "ws+unix:" . $sockPath;
        $server = $app->serve($sockPath);
        $client = $app->connect($sockPath);

        $this->assertEquals($dsn, $server->getDSN());
        $this->assertEquals($dsn, $client->getDSN());
        $this->assertEquals($dsn, $server->id);
        // $this->assertEquals($dsn, $client->id);

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

    public function testYieldingValueFromRemoteGenerator()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = $app->connect(["port" => $server->port]);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $gen = $app->Services->Detail->getOrgs();
        $expected = ["Mozilla", "GitHub", "Linux"];
        $result = [];

        foreach ($gen as $value) {
            \array_push($result, $value);
        }
        
        $returns = $gen->getReturn();
        $this->assertEquals($expected, $result);
        $this->assertEquals("Big Companies", $returns);

        $client->close();
        $server->close();
    }


    public function testYieldingKeyValueOnRemoteGenerator()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = $app->connect(["port" => $server->port]);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $gen = $app->Services->Detail->yieldKeyValue();
        $expected = [["foo", "hello"], ["bar", "world"]];
        $result = [];

        foreach ($gen as $key => $value) {
            \array_push($result, [$key, $value]);
        }

        $this->assertEquals($expected, $result);

        $client->close();
        $server->close();
    }
}
