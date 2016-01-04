<?php

namespace Elkarte\Elkarte\Theme;

abstract class AbstractContext extends \ArrayObject
{
	protected $object;

	public function __construct($input, $flags, $iterator_class)
	{
		$this->exchangeArray($this->setDefault());

		parent::__construct($input, $flags, $iterator_class);
	}

	public function hydrate($object)
	{
		$this->object = $object;
	}

	/**
	 * Set the default for this object
	 * @return array
	 */
	protected function setDefault()
	{
		return [];
	}

	// Check if it exists in this class, then check if it exists in the $object
}