<?php

/**
 * This file has all the main functions in it that relate to the mysql database.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code covered by:
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * copyright:	2004-2011, GreyWyvern - All rights reserved.
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Elkarte\Database\Drivers\MySQL;

use Elkarte\Elkarte\Database\Drivers\AbstractDatabase;
use Elkarte\Elkarte\Database\FixDatabase;

/**
 * SQL database class, implements database class to control mysql functions
 */
class Database extends AbstractDatabase
{
	/**
	 * {@inheritdoc}
	 */
	const TITLE = 'MySQL';

	/**
	 * {@inheritdoc}
	 */
	const DB_CASE_SENSITIVE = false;

	/**
	 * {@inheritdoc}
	 */
	const DB_SUPPORTS_IGNORE = true;

	// Comments that are allowed in a query are preg_removed.
	protected $allowed_comments_from = array(
		'~\s+~s',
		'~/\*!40001 SQL_NO_CACHE \*/~',
		'~/\*!40000 USE INDEX \([A-Za-z\_]+?\) \*/~',
		'~/\*!40100 ON DUPLICATE KEY UPDATE id_msg = \d+ \*/~',
	);

	protected $allowed_comments_to = array(
		' ',
		'',
		'',
		'',
	);

	/**
	 * {@inheritdoc}
	 */
	public function connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, array $db_options = array())
	{
		$this->prefix = (string) $db_prefix;

		// Non-standard port
		if (!empty($db_options[self::PORT]))
			$db_port = (int) $db_options[self::PORT];
		else
			$db_port = 0;

		// Select the database. Maybe.
		if (empty($db_options[self::DONT_SELECT_DB]))
			$this->connection = new \mysqli((!empty($db_options[self::PERSIST]) ? 'p:' : '') . $db_server, $db_user, $db_passwd, $db_name, $db_port);
		else
			$this->connection = new \mysqli((!empty($db_options[self::PERSIST]) ? 'p:' : '') . $db_server, $db_user, $db_passwd, '', $db_port);

		// Something's wrong, show an error if its fatal (which we assume it is)
		if (!$this->connection)
		{
			if (!empty($db_options[self::NON_FATAL]))
				return null;
			else
				$this->errors->display_db_error();
		}

		// This makes it possible to automatically change the sql_mode and autocommit if needed.
		if (isset($db_options[self::SET_MODE]) && $db_options[self::SET_MODE] === true)
		{
			$this->query('', 'SET sql_mode = \'\', AUTOCOMMIT = 1',
				[]
			);
		}

		// Few databases still have not set UTF-8 as their default input charset
		$this->query('', '
			SET NAMES UTF8',
			[]
		);

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function query($identifier, $db_string, array $db_values = array())
	{
		global $db_unbuffered, $db_callback, $modSettings;

		// One more query....
		$this->_query_count++;

		if (empty($modSettings['disableQueryCheck']) && strpos($db_string, '\'') !== false && empty($db_values['security_override']))
			$this->errorBacktrace('Hacking attempt...', 'Illegal character (\') used in query...', true, __FILE__, __LINE__);

		// Use "ORDER BY null" to prevent Mysql doing filesorts for Group By clauses without an Order By
		if (strpos($db_string, 'GROUP BY') !== false && strpos($db_string, 'ORDER BY') === false && strpos($db_string, 'INSERT INTO') === false)
		{
			// Add before LIMIT
			if ($pos = strpos($db_string, 'LIMIT '))
				$db_string = substr($db_string, 0, $pos) . "\t\t\tORDER BY null\n" . substr($db_string, $pos, strlen($db_string));
			else
				// Append it.
				$db_string .= "\n\t\t\tORDER BY null";
		}

		if (empty($db_values['security_override']) && (!empty($db_values) || strpos($db_string, '{db_prefix}') !== false))
		{
			// Pass some values to the global space for use in the callback function.
			$db_callback = array($db_values, $this->connection());

			// Inject the values passed to this function.
			$db_string = preg_replace_callback('~{([a-z_]+)(?::([a-zA-Z0-9_-]+))?}~', array($this, 'replacement__callback'), $db_string);

			// This shouldn't be residing in global space any longer.
			$db_callback = array();
		}

		// Debugging.
		if ($this->debug_enabled)
		{
			// Get the file and line number this function was called.
			list ($file, $line) = $this->errorBacktrace('', '', 'return', __FILE__, __LINE__);

			if (!empty($_SESSION['debug_redirect']))
			{
				$this->debugger->merge_db($_SESSION['debug_redirect']);
				// @todo this may be off by 1
				$this->_query_count += count($_SESSION['debug_redirect']);
				$_SESSION['debug_redirect'] = array();
			}

			// Don't overload it.
			$st = microtime(true);
			$db_cache = array();
			$db_cache['q'] = $this->_query_count < 50 ? $db_string : '...';
			$db_cache['f'] = $file;
			$db_cache['l'] = $line;
			$db_cache['s'] = $st - $_SERVER['REQUEST_TIME_FLOAT'];
		}

		// First, we clean strings out of the query, reduce whitespace, lowercase, and trim - so we can check it over.
		if (empty($modSettings['disableQueryCheck']))
		{
			$this->queryCheck($db_string);
		}

		/** @var \MySQLi $connection */
		$connection = $this->connection();

		if (empty($db_unbuffered))
			$ret = $connection->query($db_string);
		else
			$ret = $connection->query($db_string, MYSQLI_USE_RESULT);

		if ($ret === false && !$this->_skip_error)
			$ret = $this->error($db_string);

		// Debugging.
		if ($this->debug_enabled)
		{
			$db_cache['t'] = microtime(true) - $st;
			$this->debugger->db($db_cache);
		}

		return new Result($this, $ret);
	}

	/**
	 * Checks if the string contains any 4byte chars and if so,
	 * converts them into HTML entities.
	 *
	 * This is necessary because MySQL utf8 doesn't know how to store such
	 * characters and would generate an error any time one is used.
	 * The 4byte chars are used by emoji
	 *
	 * @param string $string
	 * @return string
	 */
	protected function _clean_4byte_chars($string)
	{
		global $modSettings;

		if (!empty($modSettings['using_utf8mb4']))
			return $string;

		$result = $string;
		$ord = array_map('ord', str_split($string));

		// If we are in the 4-byte range
		if (max($ord) >= 240)
		{
			// Byte length
			$length = strlen($string);
			$result = '';

			// Look for a 4byte marker
			for ($i = 0; $i < $length; $i++)
			{
				// The first byte of a 4-byte character encoding starts with the bytes 0xF0-0xF4 (240 <-> 244)
				// but look all the way to 247 for safe measure
				$ord1 = $ord[$i];
				if ($ord1 >= 240 && $ord1 <= 247)
				{
					// Replace it with the corresponding html entity
					$entity = $this->_uniord(chr($ord[$i]) . chr($ord[$i + 1]) . chr($ord[$i + 2]) . chr($ord[$i + 3]));
					if ($entity === false)
						$result .= "\xEF\xBF\xBD";
					else
						$result .= '&#x' . dechex($entity) . ';';
					$i += 3;
				}
				else
					$result .= $string[$i];
			}
		}

		return $result;
	}

	/**
	 * Converts a 4byte char into the corresponding HTML entity code.
	 *
	 * This function is derived from:
	 * http://www.greywyvern.com/code/php/utf8_html.phps
	 *
	 * @param string $c
	 * @return integer|false
	 */
	protected function _uniord($c)
	{
		if (ord($c[0]) >= 0 && ord($c[0]) <= 127)
			return ord($c[0]);
		if (ord($c[0]) >= 192 && ord($c[0]) <= 223)
			return (ord($c[0]) - 192) * 64 + (ord($c[1]) - 128);
		if (ord($c[0]) >= 224 && ord($c[0]) <= 239)
			return (ord($c[0]) - 224) * 4096 + (ord($c[1]) - 128) * 64 + (ord($c[2]) - 128);
		if (ord($c[0]) >= 240 && ord($c[0]) <= 247)
			return (ord($c[0]) - 240) * 262144 + (ord($c[1]) - 128) * 4096 + (ord($c[2]) - 128) * 64 + (ord($c[3]) - 128);
		if (ord($c[0]) >= 248 && ord($c[0]) <= 251)
			return (ord($c[0]) - 248) * 16777216 + (ord($c[1]) - 128) * 262144 + (ord($c[2]) - 128) * 4096 + (ord($c[3]) - 128) * 64 + (ord($c[4]) - 128);
		if (ord($c[0]) >= 252 && ord($c[0]) <= 253)
			return (ord($c[0]) - 252) * 1073741824 + (ord($c[1]) - 128) * 16777216 + (ord($c[2]) - 128) * 262144 + (ord($c[3]) - 128) * 4096 + (ord($c[4]) - 128) * 64 + (ord($c[5]) - 128);
		if (ord($c[0]) >= 254 && ord($c[0]) <= 255)
			return false;
	}

	/**
	 * {@inheritdoc }
	 */
	public function transaction($type = self::COMMIT)
	{
		/** @var \MySQLi $connection */
		$connection = $this->connection();

		if ($type == 'begin')
			return $connection->query('BEGIN');
		elseif ($type == 'rollback')
			return $connection->query('ROLLBACK');
		elseif ($type == 'commit')
			return $connection->query('COMMIT');

		return false;
	}

	/**
	 * {@inheritdoc }
	 */
	public function lastError()
	{
		if (is_resource($this->connection()))
			return mysqli_error($this->connection->error);
	}

	/**
	 * {@inheritdoc }
	 */
	public function error($db_string)
	{
		global $txt, $context, $modSettings, $db_show_debug;

		// Get the file and line numbers.
		list ($file, $line) = $this->errorBacktrace('', '', 'return', __FILE__, __LINE__);

		// This is the error message...
		$query_error = $this->connection->error;
		$query_errno = $this->connection->errorno;

		// Error numbers:
		//    1016: Can't open file '....MYI'
		//    1030: Got error ??? from table handler.
		//    1034: Incorrect key file for table.
		//    1035: Old key file for table.
		//    1142: Command denied
		//    1205: Lock wait timeout exceeded.
		//    1213: Deadlock found.
		//    2006: Server has gone away.
		//    2013: Lost connection to server during query.

		// We cannot do something, try to find out what and act accordingly
		if ($query_errno == 1142)
		{
			$command = substr(trim($db_string), 0, 6);
			if ($command === 'DELETE' || $command === 'UPDATE' || $command === 'INSERT')
			{
				// We can try to ignore it (warning the Admin though it's a thing to do)
				// and serve the page just SELECTing
				$_SESSION['query_command_denied'][$command] = $query_error;

				// Let the Admin know there is a command denied issue
				if (function_exists('log_error'))
					$this->errors->log_error($txt['database_error'] . ': ' . $query_error . (!empty($modSettings['enableErrorQueryLogging']) ? "\n\n$db_string" : ''), 'database', $file, $line);

				return false;
			}
		}

		// Log the error.
		if ($query_errno != 1213 && $query_errno != 1205 && function_exists('log_error'))
			$this->errors->log_error($txt['database_error'] . ': ' . $query_error . (!empty($modSettings['enableErrorQueryLogging']) ? "\n\n$db_string" : ''), 'database', $file, $line);

		// Database error auto fixing ;).
		// @todo this should definitely not be here
		if (function_exists(['Cache', 'get']) && (!isset($modSettings['autoFixDatabase']) || $modSettings['autoFixDatabase'] == '1'))
		{
			$fixer = new FixDatabase($this, $GLOBALS['elk']['cache']);
			$fixer->fix();
		}

		// Nothing's defined yet... just die with it.
		if (empty($context) || empty($txt))
			die($query_error);

		// Show an error message, if possible.
		$context['error_title'] = $txt['database_error'];
		if (allowedTo('admin_forum'))
			$context['error_message'] = nl2br($query_error) . '<br />' . $txt['file'] . ': ' . $file . '<br />' . $txt['line'] . ': ' . $line;
		else
			$context['error_message'] = $txt['try_again'];

		// Add database version that we know of, for the Admin to know. (and ask for support)
		if (allowedTo('admin_forum'))
			$context['error_message'] .= '<br /><br />' . sprintf($txt['database_error_versions'], $modSettings['elkVersion']);

		if (allowedTo('admin_forum') && $db_show_debug === true)
			$context['error_message'] .= '<br /><br />' . nl2br($db_string);

		// It's already been logged... don't log it again.
		$this->errors->fatal_error($context['error_message'], false);
	}

	/**
	 * {@inheritdoc }
	 */
	public function insert($method = 'replace', $table, array $columns, array $data, array $keys, $disable_trans = false)
	{
		// With nothing to insert, simply return.
		if (empty($data))
			return Result($this, null);

		// Inserting data as a single row can be done as a single array.
		if (!is_array($data[array_rand($data)]))
			$data = array($data);

		// Replace the prefix holder with the actual prefix.
		$table = str_replace('{db_prefix}', $this->prefix, $table);

		// Create the mold for a single row insert.
		$insertData = '(';
		foreach ($columns as $columnName => $type)
		{
			// Are we restricting the length?
			if (strpos($type, 'string-') !== false)
				$insertData .= sprintf('SUBSTRING({string:%1$s}, 1, ' . substr($type, 7) . '), ', $columnName);
			else
				$insertData .= sprintf('{%1$s:%2$s}, ', $type, $columnName);
		}
		$insertData = substr($insertData, 0, -2) . ')';

		// Create an array consisting of only the columns.
		$indexed_columns = array_keys($columns);

		// Here's where the variables are injected to the query.
		$insertRows = array();
		foreach ($data as $dataRow)
			$insertRows[] = $this->quote($insertData, array_combine($indexed_columns, $dataRow));

		// Determine the method of insertion.
		$queryTitle = $method == 'replace' ? 'REPLACE' : ($method == 'ignore' ? 'INSERT IGNORE' : 'INSERT');

		// Do the insert.
		return $this->query('', '
			' . $queryTitle . ' INTO ' . $table . '(`' . implode('`, `', $indexed_columns) . '`)
			VALUES
				' . implode(',
				', $insertRows),
			array(
				'security_override' => true,
				'db_error_skip' => $table === $this->prefix . 'log_errors',
			)
		);
	}

	/**
	 * {@inheritdoc }
	 */
	public function unescapeString($string)
	{
		return stripslashes($string);
	}

	/**
	 * {@inheritdoc}
	 */
	public function serverVersion()
	{
		return $this->connection()->server_version;
	}

	/**
	 * {@inheritdoc}
	 */
	public function escapeString($string)
	{
		$string = $this->_clean_4byte_chars($string);

		return mysqli_real_escape_string($this->connection(), $string);
	}

	/**
	 * {@inheritdoc}
	 */
	public function serverInfo()
	{
		return mysqli_get_server_info($this->connection());
	}

	/**
	 * {@inheritdoc}
	 */
	public function clientVersion()
	{
		/** @var \MySQLi $connection */
		$connection = $this->connection();

		return $connection->client_version;
	}

	/**
	 * {@inheritdoc}
	 */
	public function changeSchema($dbName = null)
	{
		return mysqli_select_db($this->connection(), $dbName);
	}
}