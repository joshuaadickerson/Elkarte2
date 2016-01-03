<?php

namespace Elkarte\Elkarte\View;

class Context
{
	public $namespaces = [];
	public $handlers = [];

	public function registerNamespace($namespace)
	{
		$this->namespaces[] = $namespace;
	}

	public function registerHandler(ContextInterface $handler, $hint = '')
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

	public function hasHint($hint)
	{
		return isset($this->handlers[$hint]);
	}
}