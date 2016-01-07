<?php

namespace Elkarte\Elkarte\Ips;

class IP
{
	const IP_V6 = 6;
	const IP_V4 = 4;
	const IP_ANY = 10;

	protected $ip;
	protected $hostname;
	protected $id = 0;

	public function __construct($ip, $id = 0)
	{
		$this->ip = $ip;
		// IPs are huge numbers. Even large forums will never see even a fraction of all of the possible ones;
		// especially if we're talking about IPv6. Those big numbers use a lot of space and we want to be able to track
		// users by their IP - every IP they use.
		$this->id = $id;
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

	public function __toString()
	{
		return (string) $this->ip;
	}
}