<?php

namespace Elkarte\Elkarte\Theme;

abstract class AbstractContext extends \ArrayObject
{
	protected $object;

	public function hydrate($object)
	{
		$this->object = $object;
	}

	// Check if it exists in this class, then check if it exists in the $object
}