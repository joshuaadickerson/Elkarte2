<?php

namespace Elkarte\Elkarte\Controller;

// @todo change this to not extend ValuesContainer
use Elkarte\Elkarte\ValuesContainer;

class SubAction extends ValuesContainer
{
	public $name;
	public $callback;
	public $permission = [];
	public $enabled = true;
	public $filename = '';

	public function __construct($name, Callable $callback)
	{
		$this->name = (string) $name;
		$this->callback = $callback;
	}

	public function enabled($enabled)
	{
		$this->enabled = (bool) $enabled;
		return $this;
	}

	public function permission(array $permissions)
	{
		$this->permission = $permissions;
		return $this;
	}

	public function file($filename)
	{
		$this->filename = (string) $filename;
		return $this;
	}
}