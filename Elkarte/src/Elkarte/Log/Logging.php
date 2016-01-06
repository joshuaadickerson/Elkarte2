<?php

// @todo almost every function in here should be in a seperate file.

/**
 * This file concerns itself with logging, whether in the database or files.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

/**
 * Logs the last database error into a file.
 *
 * What it does:
 * - Attempts to use the backup file first, to store the last database error
 * - only updates db_last_error.txt if the first was successful.
 */
function logLastDatabaseError()
{
	// Make a note of the last modified time in case someone does this before us
	$last_db_error_change = @filemtime(BOARDDIR . '/db_last_error.txt');

	// Save the old file before we do anything
	$file = BOARDDIR . '/db_last_error.txt';
	$dberror_backup_fail = !@is_writable(BOARDDIR . '/db_last_error_bak.txt') || !@copy($file, BOARDDIR . '/db_last_error_bak.txt');
	$dberror_backup_fail = !$dberror_backup_fail ? (!file_exists(BOARDDIR . '/db_last_error_bak.txt') || filesize(BOARDDIR . '/db_last_error_bak.txt') === 0) : $dberror_backup_fail;

	clearstatcache();
	if (filemtime(BOARDDIR . '/db_last_error.txt') === $last_db_error_change)
	{
		// Write the change
		$write_db_change = time();
		$written_bytes = file_put_contents(BOARDDIR . '/db_last_error.txt', $write_db_change, LOCK_EX);

		// Survey says ...
		if ($written_bytes !== strlen($write_db_change) && !$dberror_backup_fail)
		{
			// Oops. maybe we have no more disk space left, or some other troubles, troubles...
			// Copy the file back and run for your life!
			@copy(BOARDDIR . '/db_last_error_bak.txt', BOARDDIR . '/db_last_error.txt');
			return false;
		}
		return true;
	}

	return false;
}

/**
 * Track Statistics.
 *
 * What it does:
 * - Caches statistics changes, and flushes them if you pass nothing.
 * - If '+' is used as a value, it will be incremented.
 * - It does not actually commit the changes until the end of the page view.
 * - It depends on the trackStats setting.
 *
 * @param mixed[] $stats = array() array of array => direction (+/-)
 * @return boolean|array
 */
function trackStats($stats = array())
{
	global $modSettings;
	static $cache_stats = array();

	if (empty($modSettings['trackStats']))
		return false;

	if (!empty($stats))
		return $cache_stats = array_merge($cache_stats, $stats);
	elseif (empty($cache_stats))
		return false;

	$setStringUpdate = array();
	$insert_keys = array();

	$date = strftime('%Y-%m-%d', forum_time(false));
	$update_parameters = array(
		'current_date' => $date,
	);

	foreach ($cache_stats as $field => $change)
	{
		$setStringUpdate[] = $field . ' = ' . ($change === '+' ? $field . ' + 1' : '{int:' . $field . '}');

		if ($change === '+')
			$cache_stats[$field] = 1;
		else
			$update_parameters[$field] = $change;

		$insert_keys[$field] = 'int';
	}

	$setStringUpdate = implode(',', $setStringUpdate);

	updateLogActivity($update_parameters, $setStringUpdate, $insert_keys, $cache_stats, $date);

	// Don't do this again.
	$cache_stats = array();

	return true;
}

/**
 * This function logs a single action in the respective log. (database log)
 *
 * - You should use {@link logActions()} instead if you have multiple entries to add
 * @example logAction('remove', array('starter' => $id_member_started));
 *
 * @param string $action The action to log
 * @param string[] $extra = array() An array of extra data
 * @param string $log_type options: 'moderate', 'Admin', ...etc.
 * @return int
 */
function logAction($action, $extra = array(), $log_type = 'moderate')
{
	// Set up the array and pass through to logActions
	return logActions(array(array(
		'action' => $action,
		'log_type' => $log_type,
		'extra' => $extra,
	)));
}

/**
 * Log changes to the forum, such as moderation events or administrative changes.
 *
 * - This behaves just like logAction() did, except that it is designed to
 * log multiple actions at once.
 *
 * @param mixed[] $logs array of actions to log [] = array(action => log_type=> extra=>)
 *   - action => A code for the log
 *   - extra => An associated array of parameters for the item being logged.
 *     This will include 'topic' for the topic id or message for the message id
 *   - log_type => A string reflecting the type of log, moderate for moderation actions,
 *     Admin for administrative actions, user for user
 *
 * @return int the last logged ID
 */
function logActions($logs)
{
	global $modSettings, $user_info;

	$inserts = array();
	$log_types = array(
		'moderate' => 1,
		'user' => 2,
		'Admin' => 3,
	);

	$GLOBALS['elk']['hooks']->hook('log_types', array(&$log_types));

	// No point in doing anything, if the log isn't even enabled.
	if (empty($modSettings['modlog_enabled']))
		return false;

	foreach ($logs as $log)
	{
		if (!isset($log_types[$log['log_type']]))
			return false;

		// Do we have something to log here, after all?
		if (!is_array($log['extra']))
			trigger_error('logActions(): data is not an array with action \'' . $log['action'] . '\'', E_USER_NOTICE);

		// Pull out the parts we want to store separately, but also make sure that the data is proper
		if (isset($log['extra']['topic']))
		{
			if (!is_numeric($log['extra']['topic']))
				trigger_error('logActions(): data\'s topic is not a number', E_USER_NOTICE);

			$topic_id = empty($log['extra']['topic']) ? 0 : (int) $log['extra']['topic'];
			unset($log['extra']['topic']);
		}
		else
			$topic_id = 0;

		if (isset($log['extra']['message']))
		{
			if (!is_numeric($log['extra']['message']))
				trigger_error('logActions(): data\'s message is not a number', E_USER_NOTICE);
			$msg_id = empty($log['extra']['message']) ? 0 : (int) $log['extra']['message'];
			unset($log['extra']['message']);
		}
		else
			$msg_id = 0;

		// @todo cache this?
		// Is there an associated report on this?
		if (in_array($log['action'], array('move', 'remove', 'split', 'merge')))
		{
			if (loadLogReported($msg_id, $topic_id))
			{
				require_once(ROOTDIR . '/Messages/Moderation.subs.php');
				updateSettings(array('last_mod_report_action' => time()));
				recountOpenReports(true, allowedTo('admin_forum'));
			}
		}

		if (isset($log['extra']['member']) && !is_numeric($log['extra']['member']))
			trigger_error('logActions(): data\'s member is not a number', E_USER_NOTICE);

		if (isset($log['extra']['board']))
		{
			if (!is_numeric($log['extra']['board']))
				trigger_error('logActions(): data\'s board is not a number', E_USER_NOTICE);

			$board_id = empty($log['extra']['board']) ? 0 : (int) $log['extra']['board'];
			unset($log['extra']['board']);
		}
		else
			$board_id = 0;

		if (isset($log['extra']['board_to']))
		{
			if (!is_numeric($log['extra']['board_to']))
				trigger_error('logActions(): data\'s board_to is not a number', E_USER_NOTICE);

			if (empty($board_id))
			{
				$board_id = empty($log['extra']['board_to']) ? 0 : (int) $log['extra']['board_to'];
				unset($log['extra']['board_to']);
			}
		}

		if (isset($log['extra']['member_affected']))
			$memID = $log['extra']['member_affected'];
		else
			$memID = $user_info['id'];

		$inserts[] = array(
			time(), $log_types[$log['log_type']], $memID, $user_info['ip'], $log['action'],
			$board_id, $topic_id, $msg_id, serialize($log['extra']),
		);
	}

	return insertLogActions($inserts);
}


/**
 * @todo
 *
 * @param string $session_id
 */
function deleteLogOnlineInterval($session_id)
{
	global $modSettings;

	$db = $GLOBALS['elk']['db'];

	$db->query('delete_log_online_interval', '
		DELETE FROM {db_prefix}log_online
		WHERE log_time < {int:log_time}
			AND session != {string:session}',
		array(
			'log_time' => time() - $modSettings['lastActive'] * 60,
			'session' => $session_id,
		)
	);
}

/**
 * Update a users entry in the online log
 *
 * @param string $session_id
 * @param string $serialized
 */
function updateLogOnline($session_id, $serialized)
{
	global $user_info;

	$db = $GLOBALS['elk']['db'];

	$result = $db->query('', '
		UPDATE {db_prefix}log_online
		SET
			log_time = {int:log_time},
			ip = {string:ip},
			url = {string:url}
		WHERE session = {string:session}',
		array(
			'log_time' => time(),
			'ip' => $user_info['ip'],
			'url' => $serialized,
			'session' => $session_id,
		)
	);

	// Guess it got deleted.
	if ($result->numAffectedRows() == 0)
		$_SESSION['log_time'] = 0;
}

/**
 * Update a users entry in the online log
 *
 * @param string $session_id
 * @param string $serialized
 * @param boolean $do_delete
 */
function insertdeleteLogOnline($session_id, $serialized, $do_delete = false)
{
	global $user_info, $modSettings;

	$db = $GLOBALS['elk']['db'];

	if ($do_delete || !empty($user_info['id']))
	{
		$db->query('', '
			DELETE FROM {db_prefix}log_online
			WHERE ' . ($do_delete ? 'log_time < {int:log_time}' : '') . ($do_delete && !empty($user_info['id']) ? ' OR ' : '') . (empty($user_info['id']) ? '' : 'id_member = {int:current_member}'),
			array(
				'current_member' => $user_info['id'],
				'log_time' => time() - $modSettings['lastActive'] * 60,
			)
		);
	}

	$db->insert($do_delete ? 'ignore' : 'replace',
		'{db_prefix}log_online',
		array(
			'session' => 'string', 'id_member' => 'int', 'id_spider' => 'int', 'log_time' => 'int', 'ip' => 'string', 'url' => 'string'
		),
		array(
			$session_id, $user_info['id'], empty($_SESSION['id_robot']) ? 0 : $_SESSION['id_robot'], time(), $user_info['ip'], $serialized
		),
		array(
			'session'
		)
	);
}

/**
 * Update the system tracking statistics
 *
 * - Used by trackStats
 *
 * @param mixed[] $update_parameters
 * @param string $setStringUpdate
 * @param mixed[] $insert_keys
 * @param mixed[] $cache_stats
 * @param string $date
 */
function updateLogActivity($update_parameters, $setStringUpdate, $insert_keys, $cache_stats, $date)
{
	$db = $GLOBALS['elk']['db'];

	$result = $db->query('', '
		UPDATE {db_prefix}log_activity
		SET ' . $setStringUpdate . '
		WHERE date = {date:current_date}',
		$update_parameters
	);

	if ($result->numAffectedRows() == 0)
	{
		$db->insert('ignore',
			'{db_prefix}log_activity',
			array_merge($insert_keys, array('date' => 'date')),
			array_merge($cache_stats, array($date)),
			array('date')
		);
	}
}

/**
 * Actualize login history, for the passed member and IPs.
 *
 * - It will log it as entry for the current time.
 *
 * @param int $id_member
 * @param string $ip
 * @param string $ip2
 */
function logLoginHistory($id_member, $ip, $ip2)
{
	$db = $GLOBALS['elk']['db'];

	$db->insert('insert',
		'{db_prefix}member_logins',
		array(
			'id_member' => 'int', 'time' => 'int', 'ip' => 'string', 'ip2' => 'string',
		),
		array(
			$id_member, time(), $ip, $ip2
		),
		array(
			'id_member', 'time'
		)
	);
}

/**
 * Checks if a messages or topic has been reported
 *
 * @param string $msg_id
 * @param string $topic_id
 */
function loadLogReported($msg_id, $topic_id, $type = 'msg')
{
	$db = $GLOBALS['elk']['db'];

	$request = $db->query('', '
		SELECT id_report
		FROM {db_prefix}log_reported
		WHERE {raw:column_name} = {int:reported}
			AND type = {string:type}
		LIMIT 1',
		array(
			'column_name' => !empty($msg_id) ? 'id_msg' : 'id_topic',
			'reported' => !empty($msg_id) ? $msg_id : $topic_id,
			'type' => $type,
		)
	);
	$num = $request->numRows();
	$request->free();

	return ($num > 0);
}

/**
 * Log a change to the forum, such as moderation events or administrative changes.
 *
 * @param mixed[] $inserts
 */
function insertLogActions($inserts)
{
	$db = $GLOBALS['elk']['db'];

	$result = $db->insert('',
		'{db_prefix}log_actions',
		array(
			'log_time' => 'int', 'id_log' => 'int', 'id_member' => 'int', 'ip' => 'string-16', 'action' => 'string',
			'id_board' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'extra' => 'string-65534',
		),
		$inserts,
		array('id_action')
	);

	return $result->insertId('{db_prefix}log_actions', 'id_action');
}

function deleteMemberLogOnline()
{
	global $user_info;

	$db = $GLOBALS['elk']['db'];

	$db->query('', '
		DELETE FROM {db_prefix}log_online
		WHERE id_member = {int:current_member}',
		array(
			'current_member' => $user_info['id'],
		)
	);
}

/**
 * Delete expired/outdated session from log_online
 *
 * @package Authorization
 * @param string $session
 */
function deleteOnline($session)
{
	$db = $GLOBALS['elk']['db'];

	$db->query('', '
		DELETE FROM {db_prefix}log_online
		WHERE session = {string:session}',
		array(
			'session' => $session,
		)
	);
}

/**
 * Set the passed users online or not, in the online log table
 *
 * @package Authorization
 * @param int[]|int $ids ids of the member(s) to log
 * @param bool $on = false if true, add the user(s) to online log, if false, remove 'em
 */
function logOnline($ids, $on = false)
{
	$db = $GLOBALS['elk']['db'];

	if (!is_array($ids))
		$ids = array($ids);

	if (empty($on))
	{
		// set the user(s) out of log_online
		$db->query('', '
			DELETE FROM {db_prefix}log_online
			WHERE id_member IN ({array_int:members})',
			array(
				'members' => $ids,
			)
		);
	}
}