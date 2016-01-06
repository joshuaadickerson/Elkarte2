<?php

namespace Elkarte\Messages;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['messages.manager'] = function ($elk) {
			return new Messages($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text']);
		};

		$elk['messages.container'] = function ($elk) {
			return new MessageContainer;
		};
	}

	public function boot(Container $elk)
	{
		// Register message context handler
		$elk['messages.context'] = function ($elk) {
			return new MessageContext;
		};
	}

	protected function controllers(Container $elk)
	{
		$elk['messages.display_controller'] = function ($elk) {
			return new DisplayController($elk, $elk['messages.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};

		$elk['messages.manage_controller'] = function ($elk) {
			return new ManageMessagesController($elk, $elk['messages.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	protected function actions()
	{

	}
}