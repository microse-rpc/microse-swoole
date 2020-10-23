<?php
namespace Microse\Tests\App\Services;

use TypeError;

class Detail
{
    private string $name;

    public function __construct($name = "Mr. World")
    {
        $this->name = $name;
    }

    public function setName(string $name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getOrgs()
    {
        yield "Mozilla";
        yield "GitHub";
        yield "Linux";
        return "Big Companies";
    }

    public function repeatAfterMe()
    {
        $value = null;

        while (true) {
            $value = yield $value;

            if ($value === "break") {
                break;
            }
        }
    }

    public function yieldKeyValue()
    {
        while (true) {
            yield "foo" => "hello";
            yield "bar" => "world";
            break;
        }
    }

    public function throwError()
    {
        throw new TypeError("something went wrong");
    }

    public function triggerTimeout()
    {
        co::sleep(1.5);
    }

    public function setAndGet($data)
    {
        return $data;
    }
}
