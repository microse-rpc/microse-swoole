<?php
namespace Microse\Tests;

use Microse\ModuleProxyApp;
use Microse\Tests\App\Config;
use Microse\Tests\App\Services\Detail;

/** @var AppInstance */
$app = new ModuleProxyApp("Microse.Tests.App");
$config = new Config();

$app->_processInterop = false;

abstract class AppInstance extends ModuleProxyApp
{
    public Config $Config;
    public Services $Services;
}

abstract class Services
{
    public Detail $Detail;
}
