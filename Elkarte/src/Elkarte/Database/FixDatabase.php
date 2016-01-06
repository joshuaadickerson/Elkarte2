<?php

namespace Elkarte\Elkarte\Database;

use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Cache\Cache;

class FixDatabase
{
	public function __construct(DatabaseInterface $db, Cache $cache)
	{

	}

	public function fix()
	{
		$db_last_error = $this->last_error();
		// Force caching on, just for the error checking.
		$old_cache = isset($modSettings['cache_enable']) ? $modSettings['cache_enable'] : null;
		$modSettings['cache_enable'] = '1';

		if ($GLOBALS['elk']['cache']->getVar($temp, 'db_last_error', 600))
			$db_last_error = max($db_last_error, $temp);

		if ($db_last_error < time() - 3600 * 24 * 3)
		{
			// We know there's a problem... but what?  Try to auto detect.
			if ($query_errno == 1030 && strpos($query_error, ' 127 ') !== false)
			{
				preg_match_all('~(?:[\n\r]|^)[^\']+?(?:FROM|JOIN|UPDATE|TABLE) ((?:[^\n\r(]+?(?:, )?)*)~s', $db_string, $matches);

				$fix_tables = array();
				foreach ($matches[1] as $tables)
				{
					$tables = array_unique(explode(',', $tables));
					foreach ($tables as $table)
					{
						// Now, it's still theoretically possible this could be an injection.  So backtick it!
						if (trim($table) != '')
							$fix_tables[] = '`' . strtr(trim($table), array('`' => '')) . '`';
					}
				}

				$fix_tables = array_unique($fix_tables);
			}
			// Table crashed.  Let's try to fix it.
			elseif ($query_errno == 1016)
			{
				if (preg_match('~\'([^\.\']+)~', $query_error, $match) != 0)
					$fix_tables = array('`' . $match[1] . '`');
			}
			// Indexes crashed.  Should be easy to fix!
			elseif ($query_errno == 1034 || $query_errno == 1035)
			{
				preg_match('~\'([^\']+?)\'~', $query_error, $match);
				$fix_tables = array('`' . $match[1] . '`');
			}
		}

		// Check for errors like 145... only fix it once every three days, and send an email. (can't use empty because it might not be set yet...)
		if (!empty($fix_tables))
		{
			// subs/Admin.subs.php for updateDbLastError(), subs/Mail.subs.php for sendmail().
			// @todo this should go somewhere else, not into the db-mysql layer I think
			require_once(SUBSDIR . '/Admin.subs.php');
			require_once(ROOTDIR . '/Mail/Mail.subs.php');

			// Make a note of the REPAIR...
			$GLOBALS['elk']['cache']->put('db_last_error', time(), 600);
			if (!$GLOBALS['elk']['cache']->getVar($temp, 'db_last_error', 600))
				updateDbLastError(time());

			// Attempt to find and repair the broken table.
			foreach ($fix_tables as $table)
				$this->query('', "
						REPAIR TABLE $table", false, false);

			// And send off an email!
			sendmail($webmaster_email, $txt['database_error'], $txt['tried_to_repair']);

			$modSettings['cache_enable'] = $old_cache;

			// Try the query again...?
			$ret = $this->query('', $db_string, false, false);
			if ($ret !== false)
				return $ret;
		}
		else
			$modSettings['cache_enable'] = $old_cache;

		// Check for the "lost connection" or "deadlock found" errors - and try it just one more time.
		if (in_array($query_errno, array(1205, 1213, 2006, 2013)))
		{
			$new_connection = false;
			if (in_array($query_errno, array(2006, 2013)) && $this->_connection == $this->connection)
			{
				// Fall back to the regular username and password if need be
				if (!$new_connection)
					$new_connection = @mysqli_connect((!empty($db_persist) ? 'p:' : '') . $db_server, $db_user, $db_passwd, $db_name);
			}

			if ($new_connection)
			{
				$this->_connection = $new_connection;

				// Try a deadlock more than once more.
				for ($n = 0; $n < 4; $n++)
				{
					$ret = $this->query('', $db_string, false, false);

					$new_errno = mysqli_errno($new_connection);
					if ($ret !== false || in_array($new_errno, array(1205, 1213)))
						break;
				}

				// If it failed again, shucks to be you... we're not trying it over and over.
				if ($ret !== false)
					return $ret;
			}
		}
		// Are they out of space, perhaps?
		elseif ($query_errno == 1030 && (strpos($query_error, ' -1 ') !== false || strpos($query_error, ' 28 ') !== false || strpos($query_error, ' 12 ') !== false))
		{
			if (!isset($txt))
				$query_error .= ' - check database storage space.';
			else
			{
				if (!isset($txt['mysql_error_space']))
					loadLanguage('Errors');

				$query_error .= !isset($txt['mysql_error_space']) ? ' - check database storage space.' : $txt['mysql_error_space'];
			}
		}
	}
}