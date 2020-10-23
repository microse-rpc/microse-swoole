<?php
namespace Microse\Tests;

use Exception;
use PHPUnit\Framework\TestCase;

include_once __DIR__ . "/Base.php";

final class ModuleProxyTest extends TestCase
{
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
}
