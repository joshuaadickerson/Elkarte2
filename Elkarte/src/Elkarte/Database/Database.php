<?php

namespace Elkarte\Elkarte\Database;

class Database
{
	protected $drivers = [
		'mysql'         => '\\Elkarte\\Database\\Drivers\\MySQL',
		'postgresql'    => '\\Elkarte\\Database\\Drivers\\PostgreSQL',
	];

	protected $driver = 'mysql';

	public function __construct($driver, $hooks = null)
	{
		// Make it possible to add drivers
		$drivers = $this->drivers;
		//$hooks->call('database_drivers', [&$drivers, &$driver]);
		$this->drivers = $drivers;

		$this->setDriver($driver);
	}

	public function connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options = array())
	{
		$class = $this->database();

		return $class::initiate($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options);
	}

	public function database()
	{
		return $this->drivers[$this->driver] . '\\Database';
	}

	public function table()
	{
		return $this->drivers[$this->driver] . '\\Table';
	}

	public function search()
	{
		return $this->drivers[$this->driver] . '\\Search';
	}

	/**
	 * Set the database driver
	 * @param string $type
	 * @return $this
	 */
	public function setDriver($type)
	{
		$this->type = isset($this->drivers[$type]) ? $this->drivers[$type] : $type;

		return $this;
	}
}