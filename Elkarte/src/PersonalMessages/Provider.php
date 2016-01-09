<?php

namespace Elkarte\PersonalMessages;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['pm.manager'] = function ($elk) {
			return new PersonalMessagesManager($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['members.manager']);//, $elk['text']
		};
	}

	public function boot(Container $elk)
	{

	}

	protected function controllers(Container $elk)
	{
		$elk['pm.controller'] = function ($elk) {
			return new PersonalMessagesController;//($elk, $elk['members.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};
	}

	protected function actions()
	{

	}
}