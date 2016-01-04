<?php

namespace Elkarte\Elkarte;

use Pimple\Container;

interface ProviderInterface
{
	/**
	 * Register services, routes, etc.
	 * All register() calls are done before the application does anything else.
	 *
	 * @param Container $elk
	 * @return void
	 */
	public function register(Container $elk);

	/**
	 * Called before the controller is dispatched
	 *
	 * @param Container $elk
	 * @return void
	 */
	public function boot(Container $elk);
}