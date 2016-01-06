<?php

namespace Elkarte\Elkarte\Database;

class Database
{
	protected $drivers = [
		'mysql'         => '\\Elkarte\\Elkarte\\Database\\Drivers\\MySQL',
		'postgresql'    => '\\Elkarte\\Elkarte\\Database\\Drivers\\PostgreSQL',
	];

	protected $driver = 'mysql';

	protected $errors;
	protected $hooks;
	protected $debug;

	public function __construct($driver, $errors, $debug, $hooks)
	{
		// Make it possible to add drivers
		$drivers = $this->drivers;
		//$hooks->call('database_drivers', [&$drivers, &$driver]);
		$this->drivers = $drivers;

		$this->setDriver($driver);

		$this->errors = $errors;
		$this->hooks = $hooks;
		$this->debug = $debug;
	}

	public function connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options = array())
	{
		$class_name = $this->database();
		$class = new $class_name($this->errors, $this->debug, $this->hooks);

		return $class->connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $db_options);
	}

	public function database()
	{
		return $this->drivers[strtolower($this->driver)] . '\\Database';
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