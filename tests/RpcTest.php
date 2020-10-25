<?php
namespace Microse\Tests;

use Error;
use Exception;
use Microse\Utils;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine\Http\Client;
use Throwable;

include_once __DIR__ . "/app.php";

final class RpcTest extends TestCase
{
    public function testServiceAndConnectingRpc()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = $app->connect(["port" => $server->port]);
        $dsn = "ws://127.0.0.1:{$server->port}/";

        $this->assertEquals($dsn, $server->getDSN());
        $this->assertEquals($dsn, $server->id);
        $this->assertEquals($dsn, $client->getDSN());
        $this->assertEquals($dsn, $client->serverId);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $app->Services->Detail->setName("Mr. Handsome");
        $res = $app->Services->Detail->getName();

        $this->assertEquals("Mr. Handsome", $res);

        $client->close();
        $server->close();
    }

    public function testServiceAndConnectingRpcWithRandomPort()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = $app->connect(["port" => $server->port]);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $app->Services->Detail->setName("Mr. Handsome");
        $res = $app->Services->Detail->getName();

        $this->assertEquals("Mr. Handsome", $res);

        $client->close();
        $server->close();
    }

    public function testServiceAndConnectingRpcWithSecret()
    {
        global $app;
        $server = $app->serve(["port" => 0, "secret" => "abc"]);
        $client = $app->connect(["port" => $server->port, "secret" => "abc"]);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $app->Services->Detail->setName("Mr. Handsome");
        $res = $app->Services->Detail->getName();

        $this->assertEquals("Mr. Handsome", $res);

        $client->close();
        $server->close();
    }

    public function testServingAndConnectingViaUrl()
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

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $app->Services->Detail->setName("Mr. Handsome");
        $res = $app->Services->Detail->getName();

        $this->assertEquals("Mr. Handsome", $res);

        $client->close();
        $server->close();
    }

    public function testServingAndConnectingWithSSL()
    {
        global $app;
        $server = $app->serve([
            "protocol" => "wss:",
            "hostname" => "localhost",
            "port" => 0,
            "certFile" => __DIR__ . "/cert.pem",
            "keyFile" => __DIR__ . "/key.pem",
            "passphrase" => "alartest"
        ]);
        $client = $app->connect([
            "protocol" => "wss:",
            "hostname" => "localhost",
            "port" => $server->port
        ]);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

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

    public function testRejectingErrorIfServiceUnavailable()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = $app->connect(["port" => $server->port]);
        $err = null;

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);
        $server->close();
        
        // wait a while for the server shutdown.
        \co::sleep(0.1);

        try {
            $app->Services->Detail->getName();
        } catch (Throwable $e) {
            $err = $e;
        }

        $this->assertTrue($err instanceof RuntimeException);
        $this->assertEquals(
            "Microse.Tests.App.Services.Detail is not available",
            $err->getMessage()
        );

        $client->close();
        $server->close();
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
    
    public function testTriggeringTimeoutError()
    {
        global $app;
        $server = $app->serve(["port" => 0, "timeout" => 1000]);
        $client = $app->connect(["port" => $server->port, "timeout" => 1000]);

        $server->register($app->Services->Detail);
        $client->register($app->Services->Detail);

        $err = \null;

        try {
            $app->Services->Detail->triggerTimeout();
        } catch (Exception $e) {
            $err = $e;
        }

        $this->assertTrue($err instanceof Exception);
        $this->assertEquals(
            "Microse.Tests.App.Services.Detail.triggerTimeout() timeout after 1000 ms",
            $err->getMessage()
        );

        $client->close();
        $server->close();
    }

    public function testRefusingConnectWhenSecretNotMatch()
    {
        global $app;
        $server = $app->serve(["port" => 0, "secret" => "tesla"]);
        $err = \null;

        try {
            $app->connect(["port" => $server->port, "secret" => "test"]);
        } catch (Throwable $e) {
            $err = $e;
        }

        $this->assertTrue($err instanceof Error);
        $this->assertEquals(
            "Cannot connect to {$server->getDSN()}",
            $err->getMessage()
        );

        $server->close();
    }

    public function testRefusingConnectWhenMissingClientId()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = new Client("127.0.0.1", $server->port);
        $client->upgrade("/");

        $this->assertEquals(401, $client->statusCode);

        $server->close();
    }

    public function testRefusingConnectWhenUsingUnrecognizedPathname()
    {
        global $app;
        $server = $app->serve(["port" => 0]);
        $client = new Client("127.0.0.1", $server->port);
        $client->upgrade("/somewhere?id=abc");

        $this->assertEquals(404, $client->statusCode);

        $server->close();
    }
}
