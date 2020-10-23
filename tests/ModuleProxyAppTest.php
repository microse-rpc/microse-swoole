<?php
namespace Microse\Tests;

use Error;
use Exception;
use Microse\ModuleProxyApp;
use PHPUnit\Framework\TestCase;
use Throwable;

include_once __DIR__ . "/Base.php";

final class ModuleProxyAppTest extends TestCase
{
    public function testCreatingRootModuleProxyInstance()
    {
        global $app;
        $this->assertEquals("Microse.Tests.App", $app->name);
    }

    public function testCreatingClientOnlyApp()
    {
        global $config;
        $app = new ModuleProxyApp("Microse.Tests.App", \false);
        $this->assertEquals(true, $app->_clientOnly);
        $err = \null;

        try {
            $app->serve($config);
        } catch (Throwable $e) {
            $err = $e;
        }

        $this->assertTrue($err instanceof Error);
        $this->assertEquals(
            "serve() is not available for client-only module proxy app",
            $err->getMessage()
        );
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
