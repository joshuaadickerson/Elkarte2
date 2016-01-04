<?php

namespace Elkarte\Elkarte;

use Pimple\Container;
use Pimple\ServiceProviderInterface;

interface ProviderInterface extends ServiceProviderInterface
{
	/**
	 * Called before the controller is dispatched
	 *
	 * @param Container $elk
	 * @return void
	 */
	public function boot(Container $elk);
}