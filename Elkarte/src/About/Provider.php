<?php

namespace Elkarte\About;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['about.credits'] = function ($elk) {
			return new Credits($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text']);
		};

		$elk['about.stats'] = function ($elk) {
			return new Stats($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors'], $elk['text']);
		};
	}

	public function boot(Container $elk)
	{

	}

	protected function controllers(Container $elk)
	{
		$elk['about.controller'] = function ($elk) {
			//Container $elk, Credits $credits, Hooks $hooks, Errors $errors, Util $text
			return new AboutController($elk, $elk['about.credits'], $elk['hooks'], $elk['errors'], $elk['text'], $elk['members.manager'], $elk['groups.manager']);
		};

		$elk['about.stats_controller'] = function ($elk) {
			return new StatsController($elk, $elk['about.stats'], $elk['hooks'], $elk['errors'], $elk['text']);
		};

		$elk['about.help_controller'] = function ($elk) {
			return new HelpController($elk, $elk['hooks'], $elk['errors'], $elk['text'], $elk['templates'], $elk['http_req']);
		};
	}

	protected function actions($actions)
	{
		// function addAction($action, $index_callable, string[] $permissions = [], SubAction[] $subactions = [])
		$about = $actions->addAction('about', ['about.controller', 'action_credits']);
		// function addSubAction($name, $index_callable, string[] $permissions)
		$about->addSubAction('credits', ['about.controller', 'action_credits']);
		$about->addSubAction('contact', ['about.controller', 'action_contact']);
		$about->addSubAction('coppa',   ['about.controller', 'action_coppa']);

		$help = $actions->addAction('help', ['about.help', 'action_help']);
		$help->addSubAction('quickhelp', ['about.controller', 'action_quickhelp']);

		$actions->addAction('stats', ['about.stats', 'action_stats']);
	}

	protected function context(Container $elk)
	{

	}
}