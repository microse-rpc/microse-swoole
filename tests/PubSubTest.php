<?php
namespace Microse\Tests;

use Microse\Map;
use Microse\Rpc\RpcClient;
use Microse\Rpc\RpcServer;
use PHPUnit\Framework\TestCase;

include_once __DIR__ . "/app.php";

final class PubSubTest extends TestCase
{
    private RpcServer $server;
    private RpcClient $client;

    public function setUp(): void
    {
        global $app;
        $this->server = $app->serve(["port" => 0]);
        $this->client = $app->connect(["port" => $this->server->port]);
    }

    public function tearDown(): void
    {
        $this->server->close();
        $this->client->close();
        unset($this->server);
        unset($this->client);
    }

    public function testGettingAllClients()
    {
        $clients = $this->server->getClients();
        $this->assertEquals([$this->client->id], $clients);
    }

    public function testSubscribingAndPublishingTopic()
    {
        $data = "";

        $this->client->subscribe("set-data", function ($msg) use (&$data) {
            $data = $msg;
        });
        $this->server->publish("set-data", "Mr. World");

        while (!$data) {
            \co::sleep(0.1);
        }

        $this->assertEquals("Mr. World", $data);
    }

    public function testScribingAndPublishingMultiTopics()
    {
        $data1 = "";
        $data2 = "";
        $data3 = "";

        $this->client->subscribe("set-data-1", function ($msg) use (&$data1) {
            $data1 = $msg;
        })->subscribe("set-data-1", function ($msg) use (&$data2) {
            $data2 = $msg;
        })->subscribe("set-data-2", function ($msg) use (&$data3) {
            $data3 = $msg;
        });

        $this->server->publish("set-data-1", "Mr. World");
        $this->server->publish("set-data-2", "Mr. Handsome");

        while (!$data1 || !$data2 || !$data3) {
            \co::sleep(0.1);
        }

        $this->assertEquals("Mr. World", $data1);
        $this->assertEquals("Mr. World", $data2);
        $this->assertEquals("Mr. Handsome", $data3);
    }

    public function testUnsubscribingTopicHandlers()
    {
        $handler1 = function () {
        };
        $handler2 = function () {
        };

        $this->client
            ->subscribe("set-data-3", $handler1)
            ->subscribe("set-data-3", $handler2)
            ->subscribe("set-data-4", $handler1)
            ->subscribe("set-data-4", $handler2);

        $this->client->unsubscribe("set-data-3", $handler1);
        $this->client->unsubscribe("set-data-4");

        $topics = $this->client->topics;
        $this->assertTrue($topics instanceof Map);
        $this->assertEquals($topics->get("set-data-3")->getSize(), 1);
        $this->assertEquals($topics->get("set-data-4"), null);
    }

    public function testPublishingTopicToSpecifiedClients()
    {
        global $app;
        $client = $app->connect(["port" => $this->server->port, "id" => "abc"]);
        $data = "";
        $data2 = "";

        $this->assertEquals($client->id, "abc");

        $client->subscribe("set-data-5", function ($msg) use (&$data) {
            $data = $msg;
        });
        $this->client->subscribe("set-data-5", function ($msg) use (&$data2) {
            $data2 = $msg;
        });

        $this->server->publish("set-data-5", "foo", ["abc"]);
        \co::sleep(0.1);

        $this->assertEquals($data, "foo");
        $this->assertEquals($data2, "");

        $client->close();
    }
}
