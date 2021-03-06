<?php

namespace Elkarte\Elkarte\Session;

use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;

/**
 * Class DatabaseSessionHandler
 * @package Elkarte
 * @see http://php.net/manual/en/class.sessionhandler.php
 */
class DatabaseSessionHandler extends \SessionHandler
{
	/** @var  \Database */
	public $db;


	/**
	 * DatabaseSessionHandler constructor.
	 * @param \Database $db
	 */
	public function __construct(DatabaseInterface $db)
	{
		@ini_set('session.serialize_handler', 'php');
		@ini_set('session.gc_probability', '1');

		$this->db = $db;
	}

	/**
	 * {@inheritDoc}
	 */
	public function open($save_path, $session_id)
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function close()
	{
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function read($session_id)
	{
		if (preg_match('~^[A-Za-z0-9,-]{16,64}$~', $session_id) == 0)
			return false;

		// Look for it in the database.
		$result = $this->db->query('', '
			SELECT data
			FROM {db_prefix}sessions
			WHERE session_id = {string:session_id}
			LIMIT 1',
			array(
				'session_id' => $session_id,
			)
		);
		list ($sess_data) = $result->fetchRow();
		$result->free();

		return empty($sess_data) ? '' : $sess_data;
	}

	/**
	 * {@inheritDoc}
	 */
	public function write($session_id, $session_data)
	{
		if (preg_match('~^[A-Za-z0-9,-]{16,64}$~', $session_id) == 0)
			return false;

		// First try to update an existing row...
		$result = $this->db->query('', '
			UPDATE {db_prefix}sessions
			SET data = {string:data}, last_update = {int:last_update}
			WHERE session_id = {string:session_id}',
			array(
				'last_update' => time(),
				'data' => $session_data,
				'session_id' => $session_id,
			)
		);
		$num_rows = $result->numAffectedRows();

		// If that didn't work, try inserting a new one.
		if (empty($num_rows))
		{
			$result = $this->db->insert('ignore',
				'{db_prefix}sessions',
				array('session_id' => 'string', 'data' => 'string', 'last_update' => 'int'),
				array($session_id, $session_data, time()),
				array('session_id')
			);

			$num_rows = $result->numAffectedRows();
		}

		return !empty($num_rows);
	}

	/**
	 * {@inheritDoc}
	 */
	public function destroy($session_id)
	{
		if (preg_match('~^[A-Za-z0-9,-]{16,64}$~', $session_id) == 0)
			return false;

		// Just delete the row...
		$result = $this->db->delete('', '
		FROM {db_prefix}sessions
		WHERE session_id = {string:session_id}',
			array(
				'session_id' => $session_id,
			)
		);

		return $result->numAffectedRows() != 0;
	}

	/**
	 * {@inheritDoc}
	 */
	public function gc($maxlifetime)
	{
		global $modSettings;

		// Just set to the default or lower?  Ignore it for a higher value. (hopefully)
		if (!empty($modSettings['databaseSession_lifetime']) && ($maxlifetime <= 1440 || $modSettings['databaseSession_lifetime'] > $maxlifetime))
			$maxlifetime = max($modSettings['databaseSession_lifetime'], 60);

		// Clean up after yerself ;).
		$result = $this->db->delete('', '
		FROM {db_prefix}sessions
		WHERE last_update < {int:last_update}',
			array(
				'last_update' => time() - $maxlifetime,
			)
		);

		return $result->numAffectedRows() != 0;
	}
}