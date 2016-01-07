<?php

namespace Elkarte\Topics;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['topics.manager'] = function ($elk) {
			return new TopicsManager($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'],
				$elk['topics.container'], $elk['members.container']);
		};

		$elk['topics.container'] = function ($elk) {
			return new TopicsContainer;
		};

		$elk['topics.list'] = function ($elk) {
			return new MessageIndex;//($elk['db'], $elk['cache'], $elk['text'], $elk['topics.container'], $elk['members.container']);
		};

		// @todo this should check settings/hooks to see if the topics have a separate BBC parser
		$elk['topics.bbc_parser'] = function ($elk) {
			return $elk['bbc'];
		};

		$elk['topics.context'] = function ($elk) {
			return new BoardContext([
				'elk' => $elk,
				'bbc_parser' => $elk['topics.bbc_parser'],
			]);
		};
	}

	public function boot(Container $elk)
	{
		// Register board context handler

		// Register delete board hook
	}

	protected function controllers(Container $elk)
	{
		$elk['topics.index_controller'] = function ($elk) {
			return new MessageIndexController($elk, $elk['hooks'], $elk['errors'], $elk['layers'], $elk['boards.manager']);
		};

		$elk['topics.manage_controller'] = function ($elk) {
			return new ManageTopicsController();
		};

		$elk['topics.remove_controller'] = function ($elk) {
			return new RemoveTopicController();
		};

		$elk['topics.split_controller'] = function ($elk) {
			return new SplitTopicController();
		};

		$elk['topics.merge_controller'] = function ($elk) {
			return new MergeTopicController();
		};
	}

	protected function actions()
	{

	}
}