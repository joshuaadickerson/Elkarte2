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
			// DatabaseInterface $db, Cache $cache, MembersManager $member_manager, BoardsManager $boards_manager)
			return new Who($elk['db'], $elk['cache'], $elk['members.manager'], $elk['boards.manager'], $elk['boards.list']);
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