<?php

namespace Elkarte\Topics;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['topics.context'] = $elk->factory(function ($elk) {
			return new TopicContext;
		});
	}

	public function boot(Container $elk)
	{
		// Register board context handler

		// Register delete board hook
	}

	protected function controllers(Container $elk)
	{
		$elk['topics.index_controller'] = function ($elk) {
			return new MessageIndexController();
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