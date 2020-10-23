<?php
namespace Microse;

use ArgumentCountError;
use AssertionError;
use BadFunctionCallException;
use BadMethodCallException;
use ClosedGeneratorException;
use DomainException;
use DOMException;
use Error;
use ErrorException;
use Exception;
use Generator;
use IntlException;
use InvalidArgumentException;
use JsonException;
use LengthException;
use LogicException;
use Microse\ModuleProxyApp;
use OutOfBoundsException;
use OutOfRangeException;
use OverflowException;
use PDOException;
use PharException;
use RangeException;
use ReflectionException;
use RuntimeException;
use TypeError;
use UnderflowException;
use UnexpectedValueException;

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
        throw new RuntimeException("Service {$module} is not available");
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

            if ($name === "Error") {
                $err = new Error($message, $code);
            } elseif ($name === "TypeError") {
                $err = new TypeError($message, $code);
            } elseif ($name === "ArgumentCountError") {
                $err = new ArgumentCountError($message, $code);
            } elseif ($name === "AssertionError"
                || $name === "AssertionFailedError"
            ) {
                $err = new AssertionError($message, $code);
            } elseif ($name === "ErrorException") {
                $err = new ErrorException($message, $code, 1, $file, $line);
            } elseif ($name === "RuntimeException") {
                $err = new RuntimeException($message, $code);
            } elseif ($name === "RangeException"
                || $name === "RangeError"
            ) {
                $err = new RangeException($message, $code);
            } elseif ($name === "OutOfRangeException") {
                $err = new OutOfRangeException($message, $code);
            } elseif ($name === "OutOfBoundsException") {
                $err = new OutOfBoundsException($message, $code);
            } elseif ($name === "OverflowException") {
                $err = new OverflowException($message, $code);
            } elseif ($name === "UnderflowException") {
                $err = new UnderflowException($message, $code);
            } elseif ($name === "LengthException") {
                $err = new LengthException($message, $code);
            } elseif ($name === "LogicException") {
                $err = new LogicException($message, $code);
            } elseif ($name === "BadFunctionCallException") {
                $err = new BadFunctionCallException($message, $code);
            } elseif ($name === "BadMethodCallException") {
                $err = new BadMethodCallException($message, $code);
            } elseif ($name === "InvalidArgumentException") {
                $err = new InvalidArgumentException($message, $code);
            } elseif ($name === "UnexpectedValueException") {
                $err = new UnexpectedValueException($message, $code);
            } elseif ($name === "ClosedGeneratorException") {
                $err = new ClosedGeneratorException($message, $code);
            } elseif ($name === "ReflectionException") {
                $err = new ReflectionException($message, $code);
            } elseif ($name === "JsonException") {
                $err = new JsonException($message, $code);
            } elseif ($name === "PharException") {
                $err = new PharException($message, $code);
            } elseif ($name === "PDOException") {
                $err = new PDOException($message, $code);
            } elseif ($name === "DOMException") {
                $err = new DOMException($message, $code);
            } elseif ($name === "IntlException") {
                $err = new IntlException($message, $code);
            } elseif ($name === "DomainException") {
                $err = new DomainException($message, $code);
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
