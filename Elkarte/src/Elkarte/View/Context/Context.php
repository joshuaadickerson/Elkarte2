<?php

namespace Elkarte\Elkarte\Theme;

class Context
{
	/** @var ContextInterface[] */
	public $handlers = [];

	/**
	 * Register a context handler
	 * @param ContextInterface $handler
	 * @param string $hint
	 * @return $this
	 */
	public function register(ContextInterface $handler, $hint = '')
	{
		if ($hint !== '')
		{
			$this->handlers[(string) $hint] = $handler;
		}
		else
		{
			$this->handlers[] = $handler;
		}

		return $this;
	}

	/**
	 * Check if an object is supported
	 *
	 * @param mixed $object
	 * @return bool
	 */
	public function supports($object)
	{
		foreach ($this->handlers as $handler)
		{
			if ($handler->supports($object))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if there is a handler with the hint
	 *
	 * @param (string) $hint
	 * @return bool
	 */
	public function hasHint($hint)
	{
		return $hint !== null && isset($this->handlers[$hint]);
	}

	/**
	 * Find a handler that supports the object
	 *
	 * @param $object
	 * @return bool|ContextInterface
	 */
	public function findHandler($object)
	{
		foreach ($this->handlers as $handler)
		{
			if ($handler->supports($object))
			{
				return $handler;
			}
		}

		return false;
	}

	/**
	 * Get a context object for the object
	 *
	 * @param $object
	 * @param string|null $hint
	 * @return bool|ContextInterface false if no
	 */
	public function context($object, $hint = null)
	{
		if ($this->hasHint($hint))
		{
			$this->handlers[$hint]->hydrate($object);
		}

		$handler = $this->findHandler($object);
		return $handler ?: $object;
	}
}