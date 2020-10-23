<?php
namespace Microse;

use AssertionError;
use ErrorException;
use Exception;
use Generator;
use Microse\ModuleProxyApp;
use RangeException;
use RuntimeException;

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
        if (!array_key_exists($module, $app->_singletons)) {
            $mod = $app->_cache[$module];
            $className = \str_replace(".", "\\", $module);

            if ($mod && class_exists($className)) {
                $app->_singletons[$module] = new $className();
            } else {
                self::throwUnavailableError($module);
            }
        }

        return @$app->_singletons[$module] ?? null;
    }

    public static function throwUnavailableError(string $module)
    {
        throw new RuntimeException("{$module} is not available");
    }

    public static function getMilliseconds(): int
    {
        list($s1, $s2) = explode(' ', microtime());
        return (int)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
    }

    public static function parseError($data)
    {
        $err = null;

        if (\is_array($data)) {
            $name = @$data["name"];
            $message = $data["message"];
            $code = @$data["code"] ?? @$data["errno"] ?? 0;
            $file = @$data["file"] ?? null;
            $line = @$data["line"] ?? null;

            if ($name === "ErrorException") {
                $err = new ErrorException($message, $code, 1, $file, $line);
            } elseif ($name && class_exists($name)) {
                $err = new $name($message, $code);
            } elseif ($name === "AssertionFailedError") {
                $err = new AssertionError($message, $code);
            } elseif ($name === "RangeError") {
                $err = new RangeException($message, $code);
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
    private Generator $gen;

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
