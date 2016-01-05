<?php

namespace Elkarte\Elkarte\Theme;

abstract class AbstractContext extends \ArrayObject
{
	protected $object;
	protected $default;

	public function __construct($input, $flags = 0, $iterator_class = 'ArrayIterator')
	{
		$this->default = array_merge($this->getDefault(), $input);
		parent::__construct($this->default, $flags, $iterator_class);
	}

	public function hydrate($object)
	{
		$this->exchangeArray($this->default);
		$this->object = $object;
	}

	/**
	 * Set the default for this object
	 * @return array
	 */
	protected function getDefault()
	{
		return [];
	}

	// Check if it exists in this class, then check if it exists in the $object

	public function offsetGet($index)
	{
		if ($this->offsetExists($index))
		{
			return parent::offsetGet($index);
		}

		$return = $this->__get($index);
		if ($return !== null)
		{
			return $return;
		}

		// Finally, try the object (I don't know why it gets this far)
		if (isset($this->object[$index]))
		{
			return $this->object[$index];
		}
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
		if (isset($this->$name))
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