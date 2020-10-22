<?php
namespace Microse;

use Error;
use Exception;
use JsonException;
use Microse\Client\ModuleProxyApp;
use RangeException;
use RuntimeException;
use TypeError;

class Utils
{
    public static function startsWith(string $str, string $substr): bool
    {
        return substr($str, 0, strlen($substr)) === $substr;
    }

    public static function endsWith(string $str, string $substr): bool
    {
        return substr($str, -strlen($substr)) === $substr;
    }

    public static function randStr(int $length): string
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $max = strlen($chars) - 1;
        $str = '';
  
        for ($i = 0; $i < $length; $i++) {
            $index = rand(0, $max);
            $str .= $chars[$index];
        }
  
        return $str;
    }

    public static function getInstance(ModuleProxyApp $app, string $module)
    {
        if (array_key_exists($module, $app->_singletons)) {
            $mod = $app->_cache[$module];

            if ($mod) {
                $app->_singletons[$module] = new $mod();
            } else {
                self::throwUnavailableError($module);
            }
        }

        return $app->_singletons[$module];
    }

    public static function throwUnavailableError(string $module)
    {
        throw new \RuntimeException("Service {$module} is not available");
    }

    public static function getMilliseconds(): int
    {
        list($s1, $s2) = explode(' ', microtime());
        return (int)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
    }

    public static function parseException($data)
    {
        $err = null;

        if (\is_array($data)) {
            $name = @$data["name"];
            $message = $data["message"];
            $code = @$data["code"] ?? 0;

            if ($name === "Error") {
                $err = new Error($message, $code);
            } elseif ($name === "TypeError") {
                $err = new TypeError($message, $code);
            } elseif (\in_array(
                $name,
                ["RangeError", "RangeException", "OutOfRangeException"]
            )) {
                $err = new RangeException($message, $code);
            } elseif ($name === "RuntimeException") {
                $err = new RuntimeException();
            } elseif ($name === "JsonException") {
                $err = new JsonException($message, $code);
            } else {
                $err = new Exception($message, $code);
            }
        } elseif (\is_string($data)) {
            $err = new Exception(\strval($data));
        } else {
            $err = new Exception("Unexpected exception: " + \strval($data));
        }

        return $err;
    }
}

class Incremental
{
    private \Generator $gen;

    public function __construct(int $offset = 0, bool $loop = false)
    {
        $this->gen = (function (int $offset = 0, bool $loop = false) {
            $id = $offset ? $offset : 0;

            while (true) {
                yield ++$id;

                if ($id === PHP_INT_MAX) {
                    if ($loop) {
                        $id = $offset ? $offset : 0;
                    } else {
                        break;
                    }
                }
            }
        })($offset, $loop);
    }

    public function next(): int
    {
        $value = $this->gen->current();
        $this->gen->next();
        return $value;
    }
}
