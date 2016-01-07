<?php

namespace Elkarte\ElkArte\Ips;

class IPs
{

	/**
	 * Lookup an IP; try shell_exec first because we can do a timeout on it.
	 *
	 * @param IP $ip A full dot notation IP address
	 * @return string
	 */
	function host_from_ip(IP $ip)
	{
		global $modSettings;

		$cache = $GLOBALS['elk']['cache'];

		if ($cache->getVar($host, 'hostlookup-' . $ip, 600) || empty($ip))
			return $host;

		$t = microtime(true);

		// Try the Linux host command, perhaps?
		if (!isset($host) && (strpos(strtolower(PHP_OS), 'win') === false || strpos(strtolower(PHP_OS), 'darwin') !== false) && mt_rand(0, 1) == 1)
		{
			if (!isset($modSettings['host_to_dis']))
				$test = @shell_exec('host -W 1 ' . @escapeshellarg($ip));
			else
				$test = @shell_exec('host ' . @escapeshellarg($ip));

			// Did host say it didn't find anything?
			if (strpos($test, 'not found') !== false)
				$host = '';
			// Invalid server option?
			elseif ((strpos($test, 'invalid option') || strpos($test, 'Invalid query name 1')) && !isset($modSettings['host_to_dis']))
				updateSettings(array('host_to_dis' => 1));
			// Maybe it found something, after all?
			elseif (preg_match('~\s([^\s]+?)\.\s~', $test, $match) == 1)
				$host = $match[1];
		}

		// This is nslookup; usually only Windows, but possibly some Unix?
		if (!isset($host) && stripos(PHP_OS, 'win') !== false && strpos(strtolower(PHP_OS), 'darwin') === false && mt_rand(0, 1) == 1)
		{
			$test = @shell_exec('nslookup -timeout=1 ' . @escapeshellarg($ip));

			if (strpos($test, 'Non-existent domain') !== false)
				$host = '';
			elseif (preg_match('~Name:\s+([^\s]+)~', $test, $match) == 1)
				$host = $match[1];
		}

		// This is the last try :/.
		if (!isset($host) || $host === false)
			$host = @gethostbyaddr($ip);

		// It took a long time, so let's cache it!
		if (microtime(true) - $t > 0.5)
			$cache->put('hostlookup-' . $ip, $host, 600);

		return $host;
	}
}