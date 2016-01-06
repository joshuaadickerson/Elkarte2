<?php

namespace Elkarte\Notifications;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['notifications.manager'] = function ($elk) {
			return new Notifications($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text']);
		};

		$elk['notifications.board_manager'] = function ($elk) {
			return new BoardNotifications($elk['db']);//, $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text']);
		};

		$elk['notifications.container'] = function ($elk) {
			return new NotificationContainer;
		};
	}

	public function boot(Container $elk)
	{
		// Register notification context handler
		$elk['notifications.context'] = function ($elk) {
			return new NotificationContext;
		};
	}

	protected function controllers(Container $elk)
	{
		$elk['notifications.display_controller'] = function ($elk) {
			return new DisplayController($elk, $elk['notifications.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};

		$elk['notifications.manage_controller'] = function ($elk) {
			return new ManageNotificationsController($elk, $elk['notifications.manager'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	protected function actions()
	{

	}
}