<?php

namespace Elkarte\Admin;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['admin.helpers'] = function ($elk) {
			return new Admin();
		};
	}

	public function boot(Container $elk)
	{
		// Register attachment context handler
	}

	protected function controllers(Container $elk)
	{
		$elk['admin.controller'] = function ($elk) {
			return new AdminController();
		};
	}

	protected function actions()
	{

	}
}