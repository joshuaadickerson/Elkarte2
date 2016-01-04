<?php

namespace Elkarke\Elkarte;

class IP
{
	const IP_V6 = 6;
	const IP_V4 = 4;
	const IP_ANY = 10;

	protected $ip;
	protected $hostname;

	public function __construct($ip)
	{
		$this->ip = $ip;
	}

	public function isValid($version = self::IP_ANY)
	{

	}

	public function isV6()
	{

	}

	public function isV4()
	{

	}

	public function hostname()
	{

	}

	public function matchCidr($cidr)
	{

	}

	public function between($start, $end)
	{

	}
}