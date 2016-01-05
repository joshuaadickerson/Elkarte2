<?php

namespace Elkarte;
use Pimple\ServiceProviderInterface;
use Pimple\Container;

/**
 * Get Elkarte running
 * @package Elkarte
 *
 */
class Elkarte extends Container
{
	const VERSION = '2.0';

	protected $config;
	protected $container;
	/** @var ServiceProviderInterface[] */
	protected $providers = [];

	public function __construct(Config $config = null)
	{
		$this->config = $config === null ? $this->findConfig() : $config;
	}

	public function run()
	{
		$this->boot();
		// Get the route/action
		// Dispatch
	}

	/**
	 * {@inheritdoc}
	 */
	public function register(ServiceProviderInterface $provider, array $values = array())
	{
		$this->providers[] = $provider;
		parent::register($provider, $values);
		return $this;
	}

	public function boot()
	{
		$this->offsetGet('hooks')->hook('pre_boot');

		foreach ($this->providers as $provider)
		{
			$provider->boot($this->container);
		}

		$this->offsetGet('hooks')->hook('after_provider_boot');

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

	public function view()
	{

	}

	public function defaultProviders()
	{
		$this->register(new About\Provider);
		$this->register(new Boards\Provider);
		$this->register(new Members\Provider);

		return $this;
	}
}