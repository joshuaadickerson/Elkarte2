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
			return new BoardsManager($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'],
				$elk['boards.container'], $elk['members.container']);
		};

		$elk['boards.container'] = function ($elk) {
			return new BoardsContainer;
		};

		$elk['boards.list'] = function ($elk) {
			return new BoardsList($elk['db'], $elk['cache'], $elk['text'], $elk['boards.container'], $elk['members.container']);
		};

		// @todo this should check settings/hooks to see if the boards have a separate BBC parser
		$elk['boards.bbc_parser'] = function ($elk) {
			return $elk['bbc'];
		};

		$elk['boards.context'] = function ($elk) {
			return new BoardContext([
				'elk' => $elk,
				'bbc_parser' => $elk['boards.bbc_parser'],
			]);
		};

		$elk['boards.readlog'] = function ($elk) {
			return new ReadLog\BoardReadLog($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text']);
		};

		$elk['category.manager'] = function ($elk) {
			return new Categories($elk['boards.manager'], $elk['db'], $elk['hooks']);
		};
	}

	public function boot(Container $elk)
	{
		// Register board context handler
		$elk['context']->register($elk['boards.context'], 'board');
	}

	protected function controllers(Container $elk)
	{
		$elk['boards.index_controller'] = function ($elk) {
			return new BoardIndexController($elk, $elk['boards.manager'], $elk['hooks'], $elk['errors'], $elk['layers'],
				$elk['category.manager']);
		};

		$elk['boards.manage_controller'] = function ($elk) {
			return new ManageBoardsController($elk, $elk['boards.manager'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['boards.manager']);
		};

		$elk['boards.repair_controller'] = function ($elk) {
			return new RepairBoardsController($elk, $elk['boards.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	protected function actions()
	{
		//'collapse' 				=> ['boards.index_controller', 'action_collapse'],

	}
}