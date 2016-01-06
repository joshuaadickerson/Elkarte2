<?php

namespace Elkarte\Groups;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['groups.manager'] = function ($elk) {
			return new GroupsManager();
		};
	}

	public function boot(Container $elk)
	{

	}

	protected function controllers(Container $elk)
	{
		$elk['groups.controller'] = function ($elk) {
			return new GroupsController();
		};
	}

	protected function actions()
	{

	}
}