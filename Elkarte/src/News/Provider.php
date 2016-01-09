<?php

namespace Elkarte\News;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['news'] = function ($elk) {
			return new News($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['members.manager']);
		};
	}

	public function boot(Container $elk)
	{

	}

	protected function controllers(Container $elk)
	{
		$elk['news.controller'] = function ($elk) {
			return new ProfileAccountController($elk, $elk['members.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};

		$elk['news.manage_controller'] = function ($elk) {
			return new ManageMembersController($elk, $elk['members.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	protected function actions()
	{

	}
}