<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['boards.manager'] = function ($elk) {
			return new BoardsManager($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	public function boot(Container $elk)
	{
		// Register board context handler
		$elk['boards.context'] = $elk->factory(function ($elk) {
			return new BoardContext;
		});
	}

	protected function controllers(Container $elk)
	{
		$elk['boards.index_controller'] = function ($elk) {
			return new BoardIndexController($elk, $elk['boards.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};

		$elk['boards.manage_controller'] = function ($elk) {
			return new ManageBoardsController($elk, $elk['boards.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};

		$elk['boards.repair_controller'] = function ($elk) {
			return new RepairBoardsController($elk, $elk['boards.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	protected function actions()
	{

	}
}