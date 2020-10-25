<?php
namespace Microse\Tests;

use Error;
use Microse\ModuleProxyApp;
use PHPUnit\Framework\TestCase;
use Throwable;

include_once __DIR__ . "/app.php";

final class ClientOnlyTest extends TestCase
{
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

        $this->assertFalse($app->_processInterop);
        $this->assertTrue($err instanceof Error);
        $this->assertEquals(
            "serve() is not available for client-only module proxy app",
            $err->getMessage()
        );
    }
}
