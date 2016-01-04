<?php

namespace BadBehavior;

class BadBehavior
{
	protected $settings = [];
	protected $headers = [];

	protected $superglobals = [
		'post'      => [],
		'cookie'    => [],
		'get'       => [],
		'server'    => [],
	];

	public function __construct(array $settings, array $globals = [])
	{
		$this->settings = $settings;

		$this->setSuperGlobals($globals);
	}

	public function screen()
	{

	}

	public function isWhiteListed()
	{

	}

	public function isBlackListed()
	{

	}

	protected function setSuperGlobals(array $globals = [])
	{
		foreach ($this->superglobals as $key => &$val)
		{
			if (isset($globals[$key]))
			{
				$val = $globals[$key];
			}
			else
			{
				$global_key = '_' . strtoupper($key);
				$val = $GLOBALS[$global_key];
			}
		}

		return $this;
	}

	/**
	 * @return array|false
	 */
	protected function loadHeaders()
	{
		if (!is_callable('getallheaders'))
		{
			foreach ($_SERVER as $h => $v)
			{
				if (preg_match('/HTTP_(.+)/', $h, $hp))
				{
					$this->headers[str_replace("_", "-", uc_all($hp[1]))] = $v;
				}
			}
		} else {
			$this->headers = getallheaders();
		}

		return $this->headers;
	}
}