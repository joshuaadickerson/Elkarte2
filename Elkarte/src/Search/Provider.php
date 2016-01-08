<?php

namespace Elkarte\Search;

use Elkarte\Elkarte\ProviderInterface;
use Pimple\Container;

class Provider implements ProviderInterface
{
	public function register(Container $elk)
	{
		$this->controllers($elk);

		$elk['search.manager'] = function ($elk) {
			return new Search();
		};

	}

	public function boot(Container $elk)
	{

	}

	protected function controllers(Container $elk)
	{
		$elk['search.controller'] = function ($elk) {
			return new SearchController();//Controller($elk, $elk['messages.manager'], $elk['hooks'], $elk['errors'], $elk['layers']);
			// (Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers)
		};

	}

	protected function actions()
	{

	}
}