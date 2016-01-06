<?php

/**
 * This file provides an implementation of the most common functions needed
 * for the database drivers to work.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code covered by:
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev Release Candidate 1
 *
 */

namespace Elkarte\Elkarte\Database\Drivers;

use Elkarte\Elkarte\Debug\Debug;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;

/**
 * Abstract database class, implements database to control functions
 */
abstract class AbstractDatabase implements DatabaseInterface
{
	/**
	 * Current connection to the database
	 * @todo make private and only allow access through connection()
	 * @var resource
	 */
	protected $connection;

	/**
	 * Number of queries run (may include queries from $_SESSION if is a redirect)
	 * @var int
	 */
	protected $_query_count = 0;

	/**
	 * Yet another way to skip a database error
	 * @var boolean
	 */
	protected $_skip_error = false;

	/**
	 * This is used to remember the "previous" state of the skip_error parameter
	 * @var null|boolean
	 */
	protected $_old_skip_error;

	protected $autocommit = true;

	protected $debugger;
	protected $debug_enabled = false;

	public $prefix = '';

	protected $errors;
	protected $hooks;

	protected $allowed_comments_from = array();
	protected $allowed_comments_to = array();


	public function __construct(Errors $errors, Debug $debugger, Hooks $hooks)
	{
		$this->errors = $errors;
		$this->debugger = $debugger;
		$this->hooks = $hooks;
	}

	/**
	 * Fix up the prefix so it doesn't require the database to be selected.
	 *
	 * @param string $this->prefix
	 * @param string $db_name
	 *
	 * @return string
	 */
	public function fixPrefix($db_name)
	{
		$prefix = is_numeric(substr($this->prefix, 0, 1)) ? $db_name . '.' . $this->prefix : '`' . $db_name . '`.' . $this->prefix;

		return $prefix;
	}

	/**
	 * Callback for preg_replace_callback on the query.
	 * It allows to replace on the fly a few pre-defined strings, for
	 * convenience ('query_see_board', 'query_wanna_see_board'), with
	 * their current values from $user_info.
	 * In addition, it performs checks and sanitization on the values
	 * sent to the database.
	 *
	 * @param mixed[] $matches
	 * @return string
	 */
	public function replacement__callback($matches)
	{
		global $db_callback, $user_info, $db_prefix;

		list ($values, $connection) = $db_callback;

		// Connection gone???  This should *never* happen at this point, yet it does :'(
		if (!$this->_validConnection($connection))
			$GLOBALS['elk']['errors']->display_db_error();

		if ($matches[1] === 'db_prefix')
			return $db_prefix;

		if ($matches[1] === 'query_see_board')
			return $user_info['query_see_board'];

		if ($matches[1] === 'query_wanna_see_board')
			return $user_info['query_wanna_see_board'];

		if (!isset($matches[2]))
			$this->errorBacktrace('Invalid value inserted or no type specified.', '', E_USER_ERROR, __FILE__, __LINE__);

		if (!isset($values[$matches[2]]))
			$this->errorBacktrace('The database value you\'re trying to insert does not exist: ' . htmlspecialchars($matches[2], ENT_COMPAT, 'UTF-8'), '', E_USER_ERROR, __FILE__, __LINE__);

		$replacement = $values[$matches[2]];

		switch ($matches[1])
		{
			case 'int':
				if (!is_numeric($replacement) || (string) $replacement !== (string) (int) $replacement)
					$this->errorBacktrace('Wrong value type sent to the database. Integer expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
				return (string) (int) $replacement;
			break;

			case 'string':
			case 'text':
				return sprintf('\'%1$s\'', $this->escapeString($replacement));
			break;

			case 'array_int':
				if (is_array($replacement))
				{
					if (empty($replacement))
						$this->errorBacktrace('Database error, given array of integer values is empty. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

					foreach ($replacement as $key => $value)
					{
						if (!is_numeric($value) || (string) $value !== (string) (int) $value)
							$this->errorBacktrace('Wrong value type sent to the database. Array of integers expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

						$replacement[$key] = (string) (int) $value;
					}

					return implode(', ', $replacement);
				}
				else
					$this->errorBacktrace('Wrong value type sent to the database. Array of integers expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

			break;

			case 'array_string':
				if (is_array($replacement))
				{
					if (empty($replacement))
						$this->errorBacktrace('Database error, given array of string values is empty. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);

					foreach ($replacement as $key => $value)
						$replacement[$key] = sprintf('\'%1$s\'', $this->escapeString($value));

					return implode(', ', $replacement);
				}
				else
					$this->errorBacktrace('Wrong value type sent to the database. Array of strings expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
			break;

			case 'date':
				if (preg_match('~^(\d{4})-([0-1]?\d)-([0-3]?\d)$~', $replacement, $date_matches) === 1)
					return sprintf('\'%04d-%02d-%02d\'', $date_matches[1], $date_matches[2], $date_matches[3]);
				else
					$this->errorBacktrace('Wrong value type sent to the database. Date expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
			break;

			case 'float':
				if (!is_numeric($replacement))
					$this->errorBacktrace('Wrong value type sent to the database. Floating point number expected. (' . $matches[2] . ')', '', E_USER_ERROR, __FILE__, __LINE__);
				return (string) (float) $replacement;
			break;

			case 'identifier':
				return '`' . strtr($replacement, array('`' => '', '.' => '')) . '`';
			break;

			case 'raw':
				return $replacement;
			break;

			default:
				$this->errorBacktrace('Undefined type used in the database query. (' . $matches[1] . ':' . $matches[2] . ')', '', false, __FILE__, __LINE__);
			break;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function quote($db_string, array $db_values)
	{
		global $db_callback;

		// Only bother if there's something to replace.
		if (strpos($db_string, '{') !== false)
		{
			// This is needed by the callback function.
			$db_callback = array($db_values, $this->connection);

			// Do the quoting and escaping
			$db_string = preg_replace_callback('~{([a-z_]+)(?::([a-zA-Z0-9_-]+))?}~', array($this, 'replacement__callback'), $db_string);

			// Clear this global variable.
			$db_callback = array();
		}

		return $db_string;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetchQuery($db_string, array $db_values = array(), array $seeds = array())
	{
		$request = $this->query('', $db_string, $db_values);

		$results = $seeds;
		while ($row = $request->fetchAssoc())
			$results[] = $row;
		$request->free();

		return $results;
	}

	/**
	 * {@inheritDoc}
	 */
	public function fetchQueryCallback($db_string, array $db_values = array(), Callable $callback = null, array $seeds = array())
	{
		if ($callback === null)
			return $this->fetchQuery($db_string, $db_values);

		$request = $this->query('', $db_string, $db_values);

		$results = $seeds;
		while ($row = $request->fetchAssoc())
			$results[] = $callback($row);
		
		$request->free();

		return $results;
	}

	/**
	 * This function tries to work out additional error information from a back trace.
	 *
	 * @param string $error_message
	 * @param string $log_message
	 * @param string|boolean $error_type
	 * @param string|null $file
	 * @param integer|null $line
	 * @return null
	 */
	public function errorBacktrace($error_message, $log_message = '', $error_type = false, $file = null, $line = null)
	{
		if (empty($log_message))
			$log_message = $error_message;

		foreach (debug_backtrace() as $step)
		{
			// Found it?
			if (!method_exists($this, $step['function']) && !in_array(substr($step['function'], 0, 7), array('elk_db_', 'preg_re', 'db_erro', 'call_us')))
			{
				$log_message .= '<br />Function: ' . $step['function'];
				break;
			}

			if (isset($step['line']))
			{
				$file = $step['file'];
				$line = $step['line'];
			}
		}

		// A special case - we want the file and line numbers for debugging.
		if ($error_type == 'return')
			return array($file, $line);

		// Is always a critical error.
		if (function_exists('log_error'))
			$GLOBALS['elk']['errors']->log_error($log_message, 'critical', $file, $line);

		if (function_exists('fatal_error'))
		{
			$GLOBALS['elk']['errors']->fatal_error($error_message, false);

			// Cannot continue...
			exit;
		}
		elseif ($error_type)
			trigger_error($error_message . ($line !== null ? '<em>(' . basename($file) . '-' . $line . ')</em>' : ''), $error_type);
		else
			trigger_error($error_message . ($line !== null ? '<em>(' . basename($file) . '-' . $line . ')</em>' : ''));
	}

	/**
	 * {@inheritdoc }
	 */
	public function escapeStringWildcard($string, $translate_human_wildcards = false)
	{
		$replacements = array(
			'%' => '\%',
			'_' => '\_',
			'\\' => '\\\\',
		);

		if ($translate_human_wildcards)
			$replacements += array(
				'*' => '%',
			);

		return strtr($string, $replacements);
	}

	/**
	 * {@inheritdoc }
	 */
	public function connection()
	{
		// find it, find it
		return $this->connection;
	}

	/**
	 * {@inheritdoc }
	 */
	public function numQueries()
	{
		return $this->_query_count;
	}

	/**
	 * {@inheritdoc }
	 */
	public function skip_error($set = true)
	{
		if ($set === null)
			$this->_skip_error = $this->_old_skip_error;
		else
			$this->_old_skip_error = $this->_skip_error;

		$this->_skip_error = $set;
	}

	/**
	 * {@inheritdoc }
	 */
	public function select($identifier, $db_string, array $db_values = array())
	{
		// Add the SELECT only if it's not there
		if (strpos(ltrim($db_string), 'SELECT') !== 0)
			$db_string = 'SELECT ' . $db_string;

		$GLOBALS['elk']['hooks']->hook('db_select', array(&$identifier, &$db_string, &$db_values));
		return $this->query($identifier, $db_string, $db_values);
	}

	/**
	 * {@inheritdoc }
	 */
	public function update($identifier, $db_string, array $db_values = array())
	{
		if (strpos(ltrim($db_string), 'UPDATE') !== 0)
			$db_string = 'UPDATE ' . $db_string;

		$GLOBALS['elk']['hooks']->hook('db_update', array(&$identifier, &$db_string, &$db_values));
		return $this->query($identifier, $db_string, $db_values);
	}

	/**
	 * {@inheritdoc }
	 */
	public function delete($identifier, $db_string, array $db_values = array())
	{
		if (strpos(ltrim($db_string), 'DELETE') !== 0)
			$db_string = 'DELETE ' . $db_string;

		$GLOBALS['elk']['hooks']->hook('db_delete', array(&$identifier, &$db_string, &$db_values));
		return $this->query($identifier, $db_string, $db_values);
	}

	public function setDebugger(Debug $debugger)
	{
		$this->debugger = $debugger;
		return $this;
	}

	public function autoCommit($autocommit)
	{
		$this->auto_commit = (bool) $autocommit;
		return $this;
	}

	public function queryCheck($db_string)
	{
		$clean = '';
		$old_pos = 0;
		$pos = -1;
		$fail = false;
		while (true)
		{
			$pos = strpos($db_string, '\'', $pos + 1);
			if ($pos === false)
				break;
			$clean .= substr($db_string, $old_pos, $pos - $old_pos);

			while (true)
			{
				$pos1 = strpos($db_string, '\'', $pos + 1);
				$pos2 = strpos($db_string, '\\', $pos + 1);
				if ($pos1 === false)
					break;
				elseif ($pos2 == false || $pos2 > $pos1)
				{
					$pos = $pos1;
					break;
				}

				$pos = $pos2 + 1;
			}

			$clean .= ' %s ';
			$old_pos = $pos + 1;
		}

		$clean .= substr($db_string, $old_pos);
		$clean = trim(strtolower(preg_replace($this->allowed_comments_from, $this->allowed_comments_to, $clean)));

		// Comments?  We don't use comments in our queries, we leave 'em outside!
		if (strpos($clean, '/*') > 2 || strpos($clean, '--') !== false || strpos($clean, ';') !== false)
			$fail = true;
		// Trying to change passwords, slow us down, or something?
		elseif (strpos($clean, 'sleep') !== false && preg_match('~(^|[^a-z])sleep($|[^[_a-z])~s', $clean) != 0)
			$fail = true;
		elseif (strpos($clean, 'benchmark') !== false && preg_match('~(^|[^a-z])benchmark($|[^[a-z])~s', $clean) != 0)
			$fail = true;

		if (!empty($fail) && function_exists('log_error'))
			$this->errorBacktrace('Hacking attempt...', 'Hacking attempt...' . "\n" . $db_string, E_USER_ERROR, __FILE__, __LINE__);

		return $fail;
	}

	/**
	 * Finds out if the connection is still valid.
	 *
	 * @param object|null
	 * @return bool
	 */
	protected function _validConnection()
	{
		return is_object($this->connection());
	}
}