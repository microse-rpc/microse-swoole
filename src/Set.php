<?php
namespace Microse;

use IteratorAggregate;

class Set implements IteratorAggregate
{
    protected $_values = [];

    public function __construct(array $entry = [])
    {
        foreach ($entry as $item) {
            $this->add($item);
        }
    }

    public function add($value)
    {
        array_push($this->_values, $value);
        return $this;
    }

    public function delete($value): bool
    {
        $index = \array_search($value, $this->_keys, true);

        if ($index !== false) {
            \array_splice($this->_values, $index, 1);
            return true;
        } else {
            return false;
        }
    }

    public function has($value): bool
    {
        return false !== \array_search($value, $this->_values, true);
    }

    public function clear(): void
    {
        $this->_values = [];
    }

    public function values()
    {
        foreach ($this->_values as $value) {
            yield $value;
        }
    }

    public function getIterator()
    {
        return $this->values();
    }

    public function getSize()
    {
        return count($this->_values);
    }
}
