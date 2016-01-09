<?php

namespace Elkarte\PaidSubscriptions;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['profile'] = function ($elk) {
			return new Profile($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['members.manager']);
		};

		$elk['profile.options'] = function ($elk) {
			return new ProfileOptions;//($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['members.container']);
		};

		$elk['profile.options'] = function ($elk) {
			return new ProfileHistory;//($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['members.container']);
		};

		$elk['profile.custom_fields'] = function ($elk) {
			return new CustomFields();//($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['members.container']);
		};
	}

	public function boot(Container $elk)
	{

	}

	protected function controllers(Container $elk)
	{
		$elk['profile.index_controller'] = function ($elk) {
			return new ProfileAccountController($elk, $elk['members.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};

		$elk['members.history_controller'] = function ($elk) {
			return new ManageMembersController($elk, $elk['members.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	protected function actions()
	{

	}
}