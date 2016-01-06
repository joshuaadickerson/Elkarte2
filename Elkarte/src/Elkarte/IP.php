<?php

namespace Elkarte\Elkarte;

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
		$valid = false;
		if ($version === self::IP_V4 || $version === self::IP_ANY)
		{
			$valid = $this->isV4();
		}

		if (!$valid && ($version === self::IP_V6 || $version === self::IP_ANY))
		{
			$valid = $this->isV6();
		}

		return $valid;
	}

	public function isV6()
	{
		return filter_var($this->ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
	}

	public function isV4()
	{

	}

	public function hostname()
	{

	}

	/**
	 * Determine if an IP address resides in a CIDR netblock or netblocks.
	 * @copyright BadBehavior
	 * @param $cidr
	 * @return bool
	 */
	public function matchCidr($cidr)
	{
		@list($ip, $mask) = explode('/', $cidr);
		$mask = pow(2,32) - pow(2, (32 - (!$mask ? 32 : $mask)));

		return (ip2long($this->ip) & $mask) == (ip2long($ip) & $mask);
	}

	public function between($start, $end)
	{

	}
}