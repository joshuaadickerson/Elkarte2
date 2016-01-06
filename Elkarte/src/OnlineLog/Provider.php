<?php

namespace Elkarte\OnlineLog;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['onlinelog.manager'] = function ($elk) {
			return new MembersOnline();
		};

		$elk['onlinelog.container'] = function ($elk) {
			return new OnlineLog();
		};

		$elk['onlinelog.viewing'] = function ($elk) {
			return new Who();
		};
	}

	public function boot(Container $elk)
	{

	}

	protected function controllers(Container $elk)
	{
		$elk['onlinelog.controller'] = function ($elk) {
			return new WhoController();
		};
	}

	protected function actions()
	{

	}
}