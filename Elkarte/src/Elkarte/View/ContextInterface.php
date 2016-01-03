<?php

namespace Elkarte\Elkarte\View;

interface ContextInterface
{
	/**
	 * Check if this class supports the given object
	 * @param mixed $object
	 * @return boolean
	 */
	public function supports($object);

	/**
	 * Add the variables to the decorator
	 * @param mixed $object
	 * @return $this
	 */
	public function hydrate($object);
}