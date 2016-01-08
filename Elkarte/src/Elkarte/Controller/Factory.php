<?php

namespace Elkarte\Elkarte\Controller;

use Elkarte\Elkarte;

class ControllerContainer
{
	/** @var Elkarte */
	protected $elk;

	public function register(AbstractController $controller)
	{
		$controller->bootstrap($this->elk);
	}
}