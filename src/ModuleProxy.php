<?php
namespace Microse;

use Microse\ModuleProxyApp;
use Microse\Utils;
use Microse\Rpc\RpcInstance;

class ModuleProxy
{
    public string $name;
    public ModuleProxyApp $_root;
    public $_children = [];

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

            /** @var RpcInstance $singleton */
            foreach ($singletons as $serverId => $singleton) {
                if ($singleton->readyState === 1) {
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
            } else {
                Utils::throwUnavailableError($this->name);
            }
        } else {
            if ($this->_root->_clientOnly) {
                Utils::throwUnavailableError($this->name);
            } else {
                // The module hasn't been registered to rpc channel, access the
                // local instance instead.
                $ins = Utils::getInstance($this->_root, $this->name);

                if ($ins) {
                    return $ins->{$name}(...$args);
                } else {
                    Utils::throwUnavailableError($this->name);
                }
            }
        }
    }

    public function __toString()
    {
        return $this->name;
    }
}
