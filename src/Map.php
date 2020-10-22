<?php
namespace Microse;

class Map implements \IteratorAggregate
{
    protected $_keys = [];
    protected $_values = [];

    public function __construct(array $entry = [])
    {
        foreach ($entry as $item) {
            $key = $item[0];
            $value = @$item[1];

            $this->set($key, $value);
        }
    }

    public function set($key, $value)
    {
        array_push($this->_keys, $key);
        array_push($this->_values, $value);
        return $this;
    }

    public function get($key)
    {
        $index = \array_search($key, $this->_keys);

        if ($index !== false) {
            return $this->_values[$index];
        }
    }

    public function delete($key): bool
    {
        $index = \array_search($key, $this->_keys);

        if ($index !== false) {
            \array_splice($this->_keys, $index, 1);
            \array_splice($this->_values, $index, 1);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Removes and returns the corresponding value according to the `$key`.
     */
    public function pop($key)
    {
        $index = \array_search($key, $this->_keys);

        if ($index !== false) {
            \array_splice($this->_keys, $index, 1);
            $values = \array_splice($this->_values, $index, 1);
            return $values[0];
        } else {
            return null;
        }
    }

    public function has($key): bool
    {
        return false !== \array_search($key, $this->_keys);
    }

    public function clear($key): void
    {
        $this->_keys = [];
        $this->_values = [];
    }

    public function keys()
    {
        foreach ($this->_keys as $key) {
            yield $key;
        }
    }

    public function values()
    {
        foreach ($this->_values as $value) {
            yield $value;
        }
    }

    public function getIterator()
    {
        foreach ($this->_keys as $i => $key) {
            yield $key => $this->_values[$i];
        }
    }
}
