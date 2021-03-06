<?php

namespace Elkarte\Elkarte\Server;

class Server
{

	/**
	 * Returns the current server load for nix systems
	 *
	 * - Used to enable / disable features based on current system overhead
	 */
	function detectServerLoad()
	{
		if (stristr(PHP_OS, 'win'))
			return false;

		$cores = $this->detectServerCores();

		// The internal function should always be available
		if (function_exists('sys_getloadavg')) {
			$sys_load = sys_getloadavg();
			return $sys_load[0] / $cores;
		} // Maybe someone has a custom compile
		else {
			$load_average = @file_get_contents('/proc/loadavg');

			if (!empty($load_average) && preg_match('~^([^ ]+?) ([^ ]+?) ([^ ]+)~', $load_average, $matches) != 0)
				return (float)$matches[1] / $cores;
			elseif (($load_average = @`uptime`) != null && preg_match('~load average[s]?: (\d+\.\d+), (\d+\.\d+), (\d+\.\d+)~i', $load_average, $matches) != 0)
				return (float)$matches[1] / $cores;

			return false;
		}
	}

	/**
	 * Determines the number of cpu cores available
	 *
	 * - Used to normalize server load based on cores
	 *
	 * @return int
	 */
	function detectServerCores()
	{
		$cores = @file_get_contents('/proc/cpuinfo');

		if (!empty($cores)) {
			$cores = preg_match_all('~^physical id~m', $cores, $matches);
			if (!empty($cores))
				return (int)$cores;
		}

		return 1;
	}
}