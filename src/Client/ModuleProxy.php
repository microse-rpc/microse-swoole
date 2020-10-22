<?php
namespace Microse\Client;

use Microse\Utils;

class ModuleProxy
{
    public string $name;
    public ModuleProxyApp $_root;
    public $_children = [];
    public $ctor = null;

    public function __construct(string $name, ModuleProxyApp $root)
    {
        $this->name = $name;
        $this->_root = $root;
        $root->_cache[$name] = $this;
    }

    public function __get($name)
    {
        $mod = @$this->_children[$name];

        if ($mod) {
            return $mod;
        } elseif ($name[0] !== "_") {
            $mod = new ModuleProxy(
                "{$this->name}.{$name}",
                $this->_root ?: $this
            );
            $this->_children[$name] = $mod;
            return $mod;
        } else {
            return null;
        }
    }

    public function __call($name, $args)
    {
        /** @var array */
        $singletons = @$this->_root->_remoteSingletons[$this->name];

        if ($singletons && \count($singletons) > 0) {
            $route = @$args[0] ?? "";

            // If the route matches any key of the _remoteSingletons, return the
            // corresponding singleton as wanted.
            if (is_string($route) && array_key_exists($route, $singletons)) {
                return $singletons[$route];
            }

            $_singletons = [];

            foreach ($singletons as $serverId => $singleton) {
                if (@$singleton->_readyState) {
                    array_push($_singletons, $singleton);
                }
            }

            $count = \count($_singletons);
            $ins = null;

            if ($count === 1) {
                $ins = $_singletons[0];
            } elseif ($count >= 2) {
                $ins = $_singletons(\rand(0, $count));
            }

            if ($ins) {
                return $ins->{$name}(...$args);
            }
        }

        Utils::throwUnavailableError($this->name);
    }

    public function __toString()
    {
        return $this->name;
    }
}
