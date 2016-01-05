<?php

namespace Elkarte\Elkarte;

class Entity extends \ArrayObject
{
	public function __construct($input, $flags = 0, $iterator_class = 'ArrayIterator')
	{
		parent::__construct($this->getDefault(), $flags, $iterator_class);
		$this->exchangeArray(array_merge($this->getArrayCopy(), $input));
	}

	/**
	 * Set the default for this object
	 * @return array the default array
	 */
	protected function getDefault()
	{
		return [];
	}

	/**
	 * Merge two entities.
	 * Should be used sparingly. Probably best to just use a loop and set the new values individually
	 *
	 * @param Entity $entity
	 * @return $this
	 */
	public function merge(Entity $entity)
	{
		$old = $this->getArrayCopy();
		$merge = array_merge($old, $entity->getArrayCopy());

		var_dump($old, $entity, $merge);
		$this->exchangeArray($merge);
		return $this;
	}

	/**
	 * @param $name
	 * @return mixed|void
	 */
	public function __get($name)
	{
		// It exists, just give it a shot
		if ($this->offsetExists($name))
		{
			return $this->offsetGet($name);
		}

		// A property exists with this name
		if (property_exists($this, $name))
		{
			return $this->$name;
		}

		// A method exists with this name
		if (method_exists($this, $name))
		{
			return $this->$name();
		}

		// Finally, try getMethodname
		$method = 'get' . ucfirst(str_replace(['_', '-'], '', $name));
		if (method_exists($this, $method))
		{
			return $this->$method();
		}
	}
}