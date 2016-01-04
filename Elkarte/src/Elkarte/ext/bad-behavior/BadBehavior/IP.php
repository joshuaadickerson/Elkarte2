<?php

namespace BadBehavior;

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
		$output = false;

		if (is_array($cidr))
		{
			foreach ($cidr as $cidrlet)
			{
				if ($this->matchCidr($cidrlet))
				{
					$output = true;
					break;
				}
			}
		} else {
			@list($ip, $mask) = explode('/', $cidr);
			if (!$mask) $mask = 32;
			$mask = pow(2,32) - pow(2, (32 - $mask));
			$output = ((ip2long($this->ip) & $mask) == (ip2long($ip) & $mask));
		}

		return $output;
	}

	public function isRFC1918()
	{
		return $this->matchCidr(array("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16"));
	}

	public function between($start, $end)
	{

	}
}