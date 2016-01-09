<?php

namespace Elkarte\Members;

use Elkarte\Elkarte\ProviderInterface;
use Elkarte\Members\Authentication\AuthController;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['members.manager'] = function ($elk) {
			return new MembersManager($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['members.container']);
		};

		$elk['members.container'] = function ($elk) {
			return new MemberContainer;
		};

		$elk['auth.controller'] = function ($elk) {
			return new AuthController();
		};

		$elk['members.moderator'] = function ($elk) {
			return new Moderator();
		};
	}

	public function boot(Container $elk)
	{
		// Register member context handler
		$elk['members.context'] = function ($elk) {
			return new MemberContext;
		};
	}

	protected function controllers(Container $elk)
	{
		$elk['members.index_controller'] = function ($elk) {
			return new MemberIndexController($elk, $elk['members.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};

		$elk['members.manage_controller'] = function ($elk) {
			return new ManageMembersController($elk, $elk['members.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	protected function actions()
	{

	}
}