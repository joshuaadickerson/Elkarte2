<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['boards.context'] = $elk->factory(function ($elk) {
			return new BoardContext;
		});
	}

	public function boot(Container $elk)
	{
		// Register board context handler
	}

	protected function controllers(Container $elk)
	{
		$elk['boards.index_controller'] = function ($elk) {
			return new BoardIndexController();
		};

		$elk['boards.manage_controller'] = function ($elk) {
			return new ManageBoardsController();
		};

		$elk['boards.repair_controller'] = function ($elk) {
			return new RepairBoardsController();
		};
	}

	protected function actions()
	{

	}
}