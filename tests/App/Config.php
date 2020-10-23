<?php
namespace Microse\Tests\App;

$hostname = "127.0.0.1";
$port = 18888;
$timeout = 1000;

class Config
{
    public $hostname = "127.0.0.1";
    public $port = 18888;
    public $timeout = 1000;

    public function get(string $name)
    {
        if (\property_exists($this, $name)) {
            return $this->{$name};
        } else {
            return null;
        }
    }

    public function toJSON()
    {
        return [
            "hostname" => $this->hostname,
            "port" => $this->port,
            "timeout" => $this->timeout
        ];
    }
}
