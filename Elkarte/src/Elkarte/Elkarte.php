<?php

namespace Elkarte;

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

	public function __construct(Config $config)
	{
		$this->config = $config;
	}

	public function run()
	{

	}

	public function services()
	{
		return new \Pimple\Conainter;
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