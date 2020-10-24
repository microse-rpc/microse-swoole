<?php
namespace Microse\Tests;

use Exception;
use PHPUnit\Framework\TestCase;

include_once __DIR__ . "/app.php";

final class LocalInstanceTest extends TestCase
{
    public function testCreatingRootModuleProxyInstance()
    {
        global $app;
        $this->assertEquals("Microse.Tests.App", $app->name);
    }

    public function testAccessingModule()
    {
        global $app;
        $this->assertEquals("Microse.Tests.App.Config", $app->Config->name);
    }

    public function testAccessingDeepModule()
    {
        global $app;
        $this->assertEquals(
            $app->Services->Detail->name,
            "Microse.Tests.App.Services.Detail"
        );
    }

    public function testGettingSingleton()
    {
        global $app;
        $app->Services->Detail->setName("Mr. Handsome");
        $this->assertEquals("Mr. Handsome", $app->Services->Detail->getName());
        $app->Services->Detail->setName("Mr. World");
        $this->assertEquals("Mr. World", $app->Services->Detail->getName());
    }

    public function testGettingResultFromLocalGenerator()
    {
        global $app;
        $gen = $app->Services->Detail->getOrgs();
        $expected = ["Mozilla", "GitHub", "Linux"];
        $result = [];

        foreach ($gen as $value) {
            \array_push($result, $value);
        }
        
        $returns = $gen->getReturn();
        $this->assertEquals($expected, $result);
        $this->assertEquals("Big Companies", $returns);
    }

    public function testYieldKeyValueOnRemoteGenerator()
    {
        global $app;
        $gen = $app->Services->Detail->yieldKeyValue();
        $expected = [["foo", "hello"], ["bar", "world"]];
        $result = [];

        foreach ($gen as $key => $value) {
            \array_push($result, [$key, $value]);
        }

        $returns = $gen->getReturn();
        $this->assertEquals($expected, $result);
        $this->assertEquals("foo => bar", $returns);
    }

    public function testInvokingSendMethodOnLocalGenerarator()
    {
        global $app;
        $gen = $app->Services->Detail->repeatAfterMe();
        $result = $gen->send("Google");

        $this->assertEquals("Google", $result);
    }

    public function testInvokingThrowMethodOnLocalGenerator()
    {
        global $app;
        $gen = $app->Services->Detail->repeatAfterMe();
        $msg = "test throw method";
        $err = \null;

        try {
            $gen->throw(new Exception($msg, 1001));
        } catch (Exception $e) {
            $err = $e;
        }

        $this->assertTrue($err instanceof Exception);
        $this->assertEquals($msg, $err->getMessage());
    }

    // public function testUsingLocalInstanceWhenServerRunsInSameProcess()
    // {
    //     global $app;
    //     $server = $app->serve(["port" => 0]); // use a random port
    //     $client = $app->connect(["port" => $server->port]);

    //     $server->register($app->Services->Detail);
    //     $client->register($app->Services->Detail);

    //     $data = new Exception("something went wrong");
    //     $res = $app->Services->Detail->setAndGet($data);

    //     $this->assertTrue($res === $data);

    //     $client->close();
    //     $server->close();
    // }
}
