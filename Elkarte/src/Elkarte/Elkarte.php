<?php

namespace Elkarte;
use Elkarte\Elkarte\ProviderInterface;

/**
 * Get Elkarte running
 * @package Elkarte
 *
 */
class Elkarte
{
	const VERSION = '2.0';

	protected $config;
	protected $container;
	protected $providers = [];

	public function __construct(Config $config)
	{
		$this->config = $config;
	}

	public function run()
	{
		$this->boot();
		// Get the route/action
		// Dispatch
	}

	public function services()
	{
		$elk = new \Pimple\Container;
		require_once 'Services.php';
		return $elk;
	}

	public function register(ProviderInterface $provider)
	{
		$provider->register($this->container);
		$this->providers[] = $provider;
		return $this;
	}

	public function boot()
	{
		$this->container['hooks']->hook('pre_boot');

		foreach ($this->providers as $provider)
		{
			$provider->boot($this->container);
		}

		$this->container['hooks']->hook('after_provider_boot');

		return $this;
	}

	public function database()
	{
		return loadDatabase(
			$this->config['db.persist'],
			$this->config['db.server'],
			$this->config['db.user'],
			$this->config['db.passwd'],
			$this->config['db.port'],
			$this->config['db.type'],
			$this->config['db.name'],
			$this->config['db.prefix']
		);
	}

	public function cache()
	{

	}

	public function events()
	{

	}

	public function errors()
	{

	}

	public function debugger()
	{

	}
}