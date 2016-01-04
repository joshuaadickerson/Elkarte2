<?php

/**
 * This class is the base class for database drivers implementations.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Elkarte\Database\Drivers;

/**
 * Database driver interface
 */
interface DatabaseInterface
{
	const PERSIST = 'persist';
	const DONT_SELECT_DB = 'dont_select_db';
	const COMMIT = 'commit';

	/**
	 * Initializes a database connection.
	 * It returns the connection, if successful.
	 *
	 * @param string $db_server
	 * @param string $db_name
	 * @param string $db_user
	 * @param string $db_passwd
	 * @param string $this->prefix
	 * @param mixed[] $db_options
	 *
	 * @return DatabaseInterface
	 */
	public function connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, array $db_options = array());

	/**
	 * Fix up the prefix so it doesn't require the database to be selected.
	 *
	 * @param string $db_prefix
	 * @param string $db_name
	 *
	 * @return string
	 */
	public function fixPrefix($db_name);

	/**
	 * Callback for preg_replace_callback on the query.
	 * It allows to replace on the fly a few pre-defined strings, for convenience ('query_see_board', 'query_wanna_see_board'), with
	 * their current values from $user_info.
	 * In addition, it performs checks and sanitation on the values sent to the database.
	 *
	 * @param mixed[] $matches
	 */
	public function replacement__callback($matches);

	/**
	 * This function works like $db->query(), escapes and quotes a string,
	 * but it doesn't execute the query.
	 *
	 * @param string $db_string
	 * @param mixed[] $db_values
	 * @param resource|null 
	 * @return string
	 */
	public function quote($db_string, array $db_values);

	/**
	 * Do a query.  Takes care of errors too.
	 *
	 * @param string $identifier
	 * @param string $db_string
	 * @param mixed[] $db_values = array()
	 * @return ResultInterface
	 */
	public function query($identifier, $db_string, array $db_values = array());

	/**
	 *
	 * @param string $identifier
	 * @param string $db_string
	 * @param mixed[] $db_values = array()
	 * @return ResultInterface
	 */
	public function select($identifier, $db_string, array $db_values = array());

	/**
	 *
	 * @param string $identifier
	 * @param string $db_string
	 * @param mixed[] $db_values = array()
	 * @return ResultInterface
	 */
	public function update($identifier, $db_string, array $db_values = array());

	/**
	 *
	 * @param string $identifier
	 * @param string $db_string
	 * @param mixed[] $db_values = array()
	 * @return ResultInterface
	 */
	public function delete($identifier, $db_string, array $db_values = array());

	/**
	 * Do a query, and returns the results.
	 *
	 * @param string $db_string
	 * @param mixed[] $db_values = array()
	 * @param array $seeds = array()
	 * @return array
	 */
	public function fetchQuery($db_string, array $db_values = array(), array $seeds = array());

	/**
	 * Do a query and returns the results calling a callback on each row.
	 *
	 * The callback is supposed to accept as argument the row of data fetched
	 * by the query from the database.
	 *
	 * @param string $db_string
	 * @param mixed[] $db_values = array()
	 * @param Callable|null $callback
	 * @param array $seeds = array()
	 * @return array
	 */
	public function fetchQueryCallback($db_string, array $db_values = array(), Callable $callback = null, array $seeds = array());

	/**
	 * Do a transaction.
	 *
	 * @param string $type - the step to perform (i.e. 'begin', 'commit', 'rollback')
	 * @param resource|null 
	 */
	public function transaction($type = 'commit');

	/**
	 * Database error.
	 * Backtrace, log, try to fix.
	 *
	 * @param string $db_string
	 * @param resource|null 
	 */
	public function error($db_string);

	/**
	 * Sets the class not to return the error in case of failures.
	 *
	 * @param null|boolean $set if true the query method will not return any error
	 *                     if null will restore the last known value of skip_error
	 */
	public function skip_error($set = true);

	/**
	 * Insert data.
	 *
	 * @param string $method - options 'replace', 'ignore', 'insert'
	 * @param string $table
	 * @param mixed[] $columns
	 * @param mixed[] $data
	 * @param mixed[] $keys
	 * @param bool $disable_trans = false
	 * @return ResultInterface
	 */
	public function insert($method = 'replace', $table, array $columns, array $data, array $keys, $disable_trans = false);

	/**
	 * This function tries to work out additional error information from a back trace.
	 *
	 * @param string $error_message
	 * @param string $log_message
	 * @param string|boolean $error_type
	 * @param string|null $file
	 * @param int|null $line
	 */
	public function errorBacktrace($error_message, $log_message = '', $error_type = false, $file = null, $line = null);

	/**
	 * Escape string for the database input
	 *
	 * @param string $string
	 * @return string
	 */
	public function escapeString($string);

	/**
	 * Escape the LIKE wildcards so that they match the character and not the wildcard.
	 *
	 * @param string $string
	 * @param bool $translate_human_wildcards = false, if true, turns human readable wildcards into SQL wildcards.
	 * @return string
	 */
	public function escape_wildcard_string($string, $translate_human_wildcards = false);

	/**
	 * Unescape an escaped string.
	 *
	 * @param string $string
	 * @return string
	 */
	public function unescapeString($string);

	/**
	 * Return last error string from the database server
	 *
	 * @return string
	 */
	public function lastError();

	/**
	 * Get the name (title) of the database system.
	 * @return string
	 */
	public function title();

	/**
	 * Select database.
	 *
	 * @param string|null $dbName = null
	 */
	public function changeSchema($dbName = null);

	/**
	 * Return the number of queries executed
	 *
	 * @return int
	 */
	public function numQueries();

	/**
	 * Retrieve the connection object
	 *
	 * @return resource
	 */
	public function connection();
}