<?php

/**
 * This file handles tasks related to personal messages. It performs all
 * the necessary (database updates, statistics updates) to add, delete, mark
 * etc personal messages.
 *
 * The functions in this file do NOT check permissions.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\PersonalMessages;

use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\ElkArte\Database\Drivers\ResultInterface;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\Text\StringUtil;
use Elkarte\Members\MembersManager;

class PersonalMessagesManager
{
	protected $db;
	protected $cache;
	protected $hooks;
	protected $errors;
	/** @var MembersManager */
	protected $mem_manager;

	public function __construct(DatabaseInterface $db, Cache $cache, Hooks $hooks, Errors $errors, MembersManager $mem_manager)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->hooks = $hooks;
		$this->errors = $errors;
		$this->mem_manager = $mem_manager;
	}
	
	/**
	 * Loads information about the users personal message limit.
	 *
	 * @package PersonalMessage
	 */
	public function loadMessageLimit()
	{
		global $user_info;

		if ($user_info['is_admin'])
			$message_limit = 0;
		elseif (!$this->cache->getVar($message_limit, 'msgLimit:' . $user_info['id'], 360))
		{
			$request = $this->db->query('', '
			SELECT
				MAX(max_messages) AS top_limit, MIN(max_messages) AS bottom_limit
			FROM {db_prefix}membergroups
			WHERE id_group IN ({array_int:users_groups})',
				array(
					'users_groups' => $user_info['groups'],
				)
			);
			list ($maxMessage, $minMessage) = $request->fetchRow();
			$request->free();

			$message_limit = $minMessage == 0 ? 0 : $maxMessage;

			// Save us doing it again!
			$this->cache->put('msgLimit:' . $user_info['id'], $message_limit, 360);
		}

		return $message_limit;
	}

	/**
	 * Loads the count of messages on a per label basis.
	 *
	 * @param $labels mixed[] array of labels that we are calculating the message count
	 * @package PersonalMessage
	 * @return array
	 */
	function loadPMLabels(array $labels)
	{
		global $user_info;

		// Looks like we need to reseek!
		$result = $this->db->query('', '
		SELECT
			labels, is_read, COUNT(*) AS num
		FROM {db_prefix}pm_recipients
		WHERE id_member = {int:current_member}
			AND deleted = {int:not_deleted}
		GROUP BY labels, is_read',
			array(
				'current_member' => $user_info['id'],
				'not_deleted' => 0,
			)
		);
		while ($row = $result->fetchAssoc())
		{
			$this_labels = explode(',', $row['labels']);
			foreach ($this_labels as $this_label)
			{
				$labels[(int) $this_label]['messages'] += $row['num'];

				if (!($row['is_read'] & 1))
				{
					$labels[(int) $this_label]['unread_messages'] += $row['num'];
				}
			}
		}
		$result->free();

		// Store it please!
		$this->cache->put('labelCounts:' . $user_info['id'], $labels, 720);

		return $labels;
	}

	/**
	 * Get the number of PMs.
	 *
	 * @package PersonalMessage
	 * @param bool $descending
	 * @param int|null $pmID
	 * @param string $labelQuery
	 * @return int
	 */
	function getPMCount($descending = false, $pmID = null, $labelQuery = '')
	{
		global $user_info, $context;

		// Figure out how many messages there are.
		if ($context['folder'] == 'sent')
		{
			$request = $this->db->query('', '
			SELECT
				COUNT(' . ($context['display_mode'] == 2 ? 'DISTINCT id_pm_head' : '*') . ')
			FROM {db_prefix}personal_messages
			WHERE id_member_from = {int:current_member}
				AND deleted_by_sender = {int:not_deleted}' . ($pmID !== null ? '
				AND id_pm ' . ($descending ? '>' : '<') . ' {int:id_pm}' : ''),
				array(
					'current_member' => $user_info['id'],
					'not_deleted' => 0,
					'id_pm' => $pmID,
				)
			);
		}
		else
		{
			$request = $this->db->query('', '
			SELECT
				COUNT(' . ($context['display_mode'] == 2 ? 'DISTINCT pm.id_pm_head' : '*') . ')
			FROM {db_prefix}pm_recipients AS pmr' . ($context['display_mode'] == 2 ? '
				INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)' : '') . '
			WHERE pmr.id_member = {int:current_member}
				AND pmr.deleted = {int:not_deleted}' . $labelQuery . ($pmID !== null ? '
				AND pmr.id_pm ' . ($descending ? '>' : '<') . ' {int:id_pm}' : ''),
				array(
					'current_member' => $user_info['id'],
					'not_deleted' => 0,
					'id_pm' => $pmID,
				)
			);
		}

		list ($count) = $request->fetchRow();
		$request->free();

		return $count;
	}

	/**
	 * Delete the specified personal messages.
	 *
	 * @package PersonalMessage
	 * @param int[]|null $personal_messages array of pm ids
	 * @param string|null $folder = null
	 * @param int|int[]|null $owner = null
	 */
	function deleteMessages($personal_messages, $folder = null, $owner = null)
	{
		global $user_info;


		if ($owner === null)
			$owner = array($user_info['id']);
		elseif (empty($owner))
			return;
		elseif (!is_array($owner))
			$owner = array($owner);

		if ($personal_messages !== null)
		{
			if (empty($personal_messages) || !is_array($personal_messages))
				return;

			foreach ($personal_messages as $index => $delete_id)
				$personal_messages[$index] = (int) $delete_id;

			$where = '
				AND id_pm IN ({array_int:pm_list})';
		}
		else
			$where = '';

		if ($folder == 'sent' || $folder === null)
		{
			$this->db->query('', '
			UPDATE {db_prefix}personal_messages
			SET deleted_by_sender = {int:is_deleted}
			WHERE id_member_from IN ({array_int:member_list})
				AND deleted_by_sender = {int:not_deleted}' . $where,
				array(
					'member_list' => $owner,
					'is_deleted' => 1,
					'not_deleted' => 0,
					'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
				)
			);
		}
		if ($folder != 'sent' || $folder === null)
		{
			// Calculate the number of messages each member's gonna lose...
			$request = $this->db->query('', '
			SELECT
				id_member, COUNT(*) AS num_deleted_messages, CASE WHEN is_read & 1 >= 1 THEN 1 ELSE 0 END AS is_read
			FROM {db_prefix}pm_recipients
			WHERE id_member IN ({array_int:member_list})
				AND deleted = {int:not_deleted}' . $where . '
			GROUP BY id_member, is_read',
				array(
					'member_list' => $owner,
					'not_deleted' => 0,
					'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
				)
			);
				// ...And update the statistics accordingly - now including unread messages!.
			while ($row = $request->fetchAssoc())
			{
				if ($row['is_read'])
					$this->elk['members.manager']->updateMemberData($row['id_member'], array('personal_messages' => $where == '' ? 0 : 'personal_messages - ' . $row['num_deleted_messages']));
				else
					$this->elk['members.manager']->updateMemberData($row['id_member'], array('personal_messages' => $where == '' ? 0 : 'personal_messages - ' . $row['num_deleted_messages'], 'unread_messages' => $where == '' ? 0 : 'unread_messages - ' . $row['num_deleted_messages']));

				// If this is the current member we need to make their message count correct.
				if ($user_info['id'] == $row['id_member'])
				{
					$user_info['messages'] -= $row['num_deleted_messages'];
					if (!($row['is_read']))
						$user_info['unread_messages'] -= $row['num_deleted_messages'];
				}
			}
			$request->free();

			// Do the actual deletion.
			$this->db->query('', '
			UPDATE {db_prefix}pm_recipients
			SET deleted = {int:is_deleted}
			WHERE id_member IN ({array_int:member_list})
				AND deleted = {int:not_deleted}' . $where,
				array(
					'member_list' => $owner,
					'is_deleted' => 1,
					'not_deleted' => 0,
					'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
				)
			);
		}

		// If sender and recipients all have deleted their message, it can be removed.
		$request = $this->db->query('', '
		SELECT
			pm.id_pm AS sender, pmr.id_pm
		FROM {db_prefix}personal_messages AS pm
			LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm AND pmr.deleted = {int:not_deleted})
		WHERE pm.deleted_by_sender = {int:is_deleted}
			' . str_replace('id_pm', 'pm.id_pm', $where) . '
		GROUP BY sender, pmr.id_pm
		HAVING pmr.id_pm IS null',
			array(
				'not_deleted' => 0,
				'is_deleted' => 1,
				'pm_list' => $personal_messages !== null ? array_unique($personal_messages) : array(),
			)
		);
		$remove_pms = array();
		while ($row = $request->fetchAssoc())
			$remove_pms[] = $row['sender'];
		$request->free();

		if (!empty($remove_pms))
		{
			$this->db->query('', '
			DELETE FROM {db_prefix}personal_messages
			WHERE id_pm IN ({array_int:pm_list})',
				array(
					'pm_list' => $remove_pms,
				)
			);

			$this->db->query('', '
			DELETE FROM {db_prefix}pm_recipients
			WHERE id_pm IN ({array_int:pm_list})',
				array(
					'pm_list' => $remove_pms,
				)
			);
		}

		// Any cached numbers may be wrong now.
		$this->cache->put('labelCounts:' . $user_info['id'], null, 720);
	}

	/**
	 * Mark the specified personal messages read.
	 *
	 * @package PersonalMessage
	 * @param int[]|int|null $personal_messages null or array of pm ids
	 * @param string|null $label = null, if label is set, only marks messages with that label
	 * @param int|null $owner = null, if owner is set, marks messages owned by that member id
	 */
	function markMessages($personal_messages = null, $label = null, $owner = null)
	{
		global $user_info;

		if ($owner === null)
			$owner = $user_info['id'];

		if (!is_null($personal_messages) && !is_array($personal_messages))
			$personal_messages = array($personal_messages);

		$result = $this->db->query('', '
		UPDATE {db_prefix}pm_recipients
		SET is_read = is_read | 1
		WHERE id_member = {int:id_member}
			AND NOT (is_read & 1 >= 1)' . ($label === null ? '' : '
			AND FIND_IN_SET({string:label}, labels) != 0') . ($personal_messages !== null ? '
			AND id_pm IN ({array_int:personal_messages})' : ''),
			array(
				'personal_messages' => $personal_messages,
				'id_member' => $owner,
				'label' => $label,
			)
		);

		// If something wasn't marked as read, get the number of unread messages remaining.
		if ($result->numAffectedRows() > 0)
			$this->updatePMMenuCounts($owner);
	}

	/**
	 * Mark the specified personal messages as unread.
	 *
	 * @package PersonalMessage
	 * @param int|int[] $personal_messages
	 */
	function markMessagesUnread($personal_messages)
	{
		global $user_info;

		if (empty($personal_messages))
			return;

		if (!is_array($personal_messages))
			$personal_messages = array($personal_messages);

		$owner = $user_info['id'];

		// Flip the "read" bit on this
		$result = $this->db->query('', '
		UPDATE {db_prefix}pm_recipients
		SET is_read = is_read & 2
		WHERE id_member = {int:id_member}
			AND (is_read & 1 >= 1)
			AND id_pm IN ({array_int:personal_messages})',
			array(
				'personal_messages' => $personal_messages,
				'id_member' => $owner,
			)
		);

		// If something was marked unread, update the number of unread messages remaining.
		if ($result->numAffectedRows() > 0)
			$this->updatePMMenuCounts($owner);
	}

	/**
	 * Updates the number of unread messages for a user
	 *
	 * - Updates the per label totals as well as the overall total
	 *
	 * @package PersonalMessage
	 * @param int $owner
	 */
	function updatePMMenuCounts($owner)
	{
		global $user_info, $context;

		if ($owner == $user_info['id'])
		{
			foreach ($context['labels'] as $label)
				$context['labels'][(int) $label['id']]['unread_messages'] = 0;
		}

		$result = $this->db->query('', '
		SELECT
			labels, COUNT(*) AS num
		FROM {db_prefix}pm_recipients
		WHERE id_member = {int:id_member}
			AND NOT (is_read & 1 >= 1)
			AND deleted = {int:is_not_deleted}
		GROUP BY labels',
			array(
				'id_member' => $owner,
				'is_not_deleted' => 0,
			)
		);
		$total_unread = 0;
		while ($row = $result->fetchAssoc())
		{
			$total_unread += $row['num'];

			if ($owner != $user_info['id'])
				continue;

			$this_labels = explode(',', $row['labels']);
			foreach ($this_labels as $this_label)
				$context['labels'][(int) $this_label]['unread_messages'] += $row['num'];
		}
		$result->free();

		// Need to store all this.
		$this->cache->put('labelCounts:' . $owner, $context['labels'], 720);
		$this->elk['members.manager']->updateMemberData($owner, array('unread_messages' => $total_unread));

		// If it was for the current member, reflect this in the $user_info array too.
		if ($owner == $user_info['id'])
			$user_info['unread_messages'] = $total_unread;
	}

	/**
	 * Check if the PM is available to the current user.
	 *
	 * @package PersonalMessage
	 * @param int $pmID
	 * @param string $validFor
	 * @return boolean|null
	 */
	function isAccessiblePM($pmID, $validFor = 'in_or_outbox')
	{
		global $user_info;
		$request = $this->db->query('', '
		SELECT
			pm.id_member_from = {int:id_current_member} AND pm.deleted_by_sender = {int:not_deleted} AS valid_for_outbox,
			pmr.id_pm IS NOT NULL AS valid_for_inbox
		FROM {db_prefix}personal_messages AS pm
			LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm AND pmr.id_member = {int:id_current_member} AND pmr.deleted = {int:not_deleted})
		WHERE pm.id_pm = {int:id_pm}
			AND ((pm.id_member_from = {int:id_current_member} AND pm.deleted_by_sender = {int:not_deleted}) OR pmr.id_pm IS NOT NULL)',
			array(
				'id_pm' => $pmID,
				'id_current_member' => $user_info['id'],
				'not_deleted' => 0,
			)
		);
		if ($request->numRows() === 0)
		{
			$request->free();
			return false;
		}
		$validationResult = $request->fetchAssoc();
		$request->free();

		switch ($validFor)
		{
			case 'inbox':
				return !empty($validationResult['valid_for_inbox']);
				break;
			case 'outbox':
				return !empty($validationResult['valid_for_outbox']);
				break;
			case 'in_or_outbox':
				return !empty($validationResult['valid_for_inbox']) || !empty($validationResult['valid_for_outbox']);
				break;
			default:
				trigger_error('Undefined validation type given', E_USER_ERROR);
				break;
		}
	}

	/**
	 * Sends a personal message from the specified person to the specified people
	 * ($from defaults to the user)
	 *
	 * @package PersonalMessage
	 * @param mixed[] $recipients - an array containing the arrays 'to' and 'bcc', both containing id_member's.
	 * @param string $subject - should have no slashes and no html entities
	 * @param string $message - should have no slashes and no html entities
	 * @param bool $store_outbox
	 * @param mixed[]|null $from - an array with the id, name, and username of the member.
	 * @param int $pm_head - the ID of the chain being replied to - if any.
	 * @return mixed[] an array with log entries telling how many recipients were successful and which recipients it failed to send to.
	 */
	function sendpm($recipients, $subject, $message, $store_outbox = true, $from = null, $pm_head = 0)
	{
		global $scripturl, $txt, $user_info, $language, $modSettings, $webmaster_email;

		/** @var StringUtil $text */
		$text = $GLOBALS['elk']['text'];

		// Make sure the PM language file is loaded, we might need something out of it.
		loadLanguage('PersonalMessage');

		// Initialize log array.
		$log = array(
			'failed' => array(),
			'sent' => array()
		);

		if ($from === null)
			$from = array(
				'id' => $user_info['id'],
				'name' => $user_info['name'],
				'username' => $user_info['username']
			);
		// Probably not needed.  /me something should be of the typer.
		else
			$user_info['name'] = $from['name'];

		// This is the one that will go in their inbox.
		$htmlmessage = $text->htmlspecialchars($message, ENT_QUOTES, 'UTF-8', true);
		preparsecode($htmlmessage);
		$htmlsubject = strtr($text->htmlspecialchars($subject), array("\r" => '', "\n" => '', "\t" => ''));
		if ($text->strlen($htmlsubject) > 100)
			$htmlsubject = $text->substr($htmlsubject, 0, 100);

		// Make sure is an array
		if (!is_array($recipients))
			$recipients = array($recipients);

		// Integrated PMs
		$this->hooks->hook('personal_message', array(&$recipients, &$from, &$subject, &$message));

		// Get a list of usernames and convert them to IDs.
		$usernames = array();
		foreach ($recipients as $rec_type => $rec)
		{
			foreach ($rec as $id => $member)
			{
				if (!is_numeric($recipients[$rec_type][$id]))
				{
					$recipients[$rec_type][$id] = $text->strtolower(trim(preg_replace('/[<>&"\'=\\\]/', '', $recipients[$rec_type][$id])));
					$usernames[$recipients[$rec_type][$id]] = 0;
				}
			}
		}

		if (!empty($usernames))
		{
			$request = $this->db->query('pm_find_username', '
			SELECT
				id_member, member_name
			FROM {db_prefix}members
			WHERE ' . (defined('DB_CASE_SENSITIVE') ? 'LOWER(member_name)' : 'member_name') . ' IN ({array_string:usernames})',
				array(
					'usernames' => array_keys($usernames),
				)
			);
			while ($row = $request->fetchAssoc())
			{
				if (isset($usernames[$text->strtolower($row['member_name'])]))
				{
					$usernames[$text->strtolower($row['member_name'])] = $row['id_member'];
				}
			}
			$request->free();

			// Replace the usernames with IDs. Drop usernames that couldn't be found.
			foreach ($recipients as $rec_type => $rec)
			{
				foreach ($rec as $id => $member)
				{
					if (is_numeric($recipients[$rec_type][$id]))
						continue;

					if (!empty($usernames[$member]))
						$recipients[$rec_type][$id] = $usernames[$member];
					else
					{
						$log['failed'][$id] = sprintf($txt['pm_error_user_not_found'], $recipients[$rec_type][$id]);
						unset($recipients[$rec_type][$id]);
					}
				}
			}
		}

		// Make sure there are no duplicate 'to' members.
		$recipients['to'] = array_unique($recipients['to']);

		// Only 'bcc' members that aren't already in 'to'.
		$recipients['bcc'] = array_diff(array_unique($recipients['bcc']), $recipients['to']);

		// Combine 'to' and 'bcc' recipients.
		$all_to = array_merge($recipients['to'], $recipients['bcc']);

		// Check no-one will want it deleted right away!
		$request = $this->db->query('', '
		SELECT
			id_member, criteria, is_or
		FROM {db_prefix}pm_rules
		WHERE id_member IN ({array_int:to_members})
			AND delete_pm = {int:delete_pm}',
			array(
				'to_members' => $all_to,
				'delete_pm' => 1,
			)
		);
		$deletes = array();
		// Check whether we have to apply anything...
		while ($row = $request->fetchAssoc())
		{
			$criteria = unserialize($row['criteria']);

			// Note we don't check the buddy status, cause deletion from buddy = madness!
			$delete = false;
			foreach ($criteria as $criterium)
			{
				if (($criterium['t'] == 'mid' && $criterium['v'] == $from['id']) || ($criterium['t'] == 'gid' && in_array($criterium['v'], $user_info['groups'])) || ($criterium['t'] == 'sub' && strpos($subject, $criterium['v']) !== false) || ($criterium['t'] == 'msg' && strpos($message, $criterium['v']) !== false))
					$delete = true;
				// If we're adding and one criteria don't match then we stop!
				elseif (!$row['is_or'])
				{
					$delete = false;
					break;
				}
			}
			if ($delete)
				$deletes[$row['id_member']] = 1;
		}
		$request->free();

		// Load the membergroup message limits.
		static $message_limit_cache = array();
		if (!allowedTo('moderate_forum') && empty($message_limit_cache))
		{
			$request = $this->db->query('', '
			SELECT
				id_group, max_messages
			FROM {db_prefix}membergroups',
				array(
				)
			);
			while ($row = $request->fetchAssoc())
				$message_limit_cache[$row['id_group']] = $row['max_messages'];
			$request->free();
		}

		// Load the groups that are allowed to read PMs.
		// @todo move into a separate function on $permission.
		$allowed_groups = array();
		$disallowed_groups = array();
		$request = $this->db->query('', '
		SELECT
			id_group, add_deny
		FROM {db_prefix}permissions
		WHERE permission = {string:read_permission}',
			array(
				'read_permission' => 'pm_read',
			)
		);

		while ($row = $request->fetchAssoc())
		{
			if (empty($row['add_deny']))
				$disallowed_groups[] = $row['id_group'];
			else
				$allowed_groups[] = $row['id_group'];
		}

		$request->free();

		if (empty($modSettings['permission_enable_deny']))
			$disallowed_groups = array();

		$request = $this->db->query('', '
		SELECT
			member_name, real_name, id_member, email_address, lngfile,
			pm_email_notify, personal_messages,' . (allowedTo('moderate_forum') ? ' 0' : '
			(receive_from = {int:admins_only}' . (empty($modSettings['enable_buddylist']) ? '' : ' OR
			(receive_from = {int:buddies_only} AND FIND_IN_SET({string:from_id}, buddy_list) = 0) OR
			(receive_from = {int:not_on_ignore_list} AND FIND_IN_SET({string:from_id}, pm_ignore_list) != 0)') . ')') . ' AS ignored,
			FIND_IN_SET({string:from_id}, buddy_list) != 0 AS is_buddy, is_activated,
			additional_groups, id_group, id_post_group
		FROM {db_prefix}members
		WHERE id_member IN ({array_int:recipients})
		ORDER BY lngfile
		LIMIT {int:count_recipients}',
			array(
				'not_on_ignore_list' => 1,
				'buddies_only' => 2,
				'admins_only' => 3,
				'recipients' => $all_to,
				'count_recipients' => count($all_to),
				'from_id' => $from['id'],
			)
		);
		$notifications = array();
		while ($row = $request->fetchAssoc())
		{
			// Don't do anything for members to be deleted!
			if (isset($deletes[$row['id_member']]))
				continue;

			// We need to know this members groups.
			$groups = explode(',', $row['additional_groups']);
			$groups[] = $row['id_group'];
			$groups[] = $row['id_post_group'];

			$message_limit = -1;

			// For each group see whether they've gone over their limit - assuming they're not an Admin.
			if (!in_array(1, $groups))
			{
				foreach ($groups as $id)
				{
					if (isset($message_limit_cache[$id]) && $message_limit != 0 && $message_limit < $message_limit_cache[$id])
						$message_limit = $message_limit_cache[$id];
				}

				if ($message_limit > 0 && $message_limit <= $row['personal_messages'])
				{
					$log['failed'][$row['id_member']] = sprintf($txt['pm_error_data_limit_reached'], $row['real_name']);
					unset($all_to[array_search($row['id_member'], $all_to)]);
					continue;
				}

				// Do they have any of the allowed groups?
				if (count(array_intersect($allowed_groups, $groups)) == 0 || count(array_intersect($disallowed_groups, $groups)) != 0)
				{
					$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
					unset($all_to[array_search($row['id_member'], $all_to)]);
					continue;
				}
			}

			// Note that PostgreSQL can return a lowercase t/f for FIND_IN_SET
			if (!empty($row['ignored']) && $row['ignored'] != 'f' && $row['id_member'] != $from['id'])
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_ignored_by_user'], $row['real_name']);
				unset($all_to[array_search($row['id_member'], $all_to)]);
				continue;
			}

			// If the receiving account is banned (>=10) or pending deletion (4), refuse to send the PM.
			if ($row['is_activated'] >= 10 || ($row['is_activated'] == 4 && !$user_info['is_admin']))
			{
				$log['failed'][$row['id_member']] = sprintf($txt['pm_error_user_cannot_read'], $row['real_name']);
				unset($all_to[array_search($row['id_member'], $all_to)]);
				continue;
			}

			// Send a notification, if enabled - taking the buddy list into account.
			if (!empty($row['email_address']) && ($row['pm_email_notify'] == 1 || ($row['pm_email_notify'] > 1 && (!empty($modSettings['enable_buddylist']) && $row['is_buddy']))) && $row['is_activated'] == 1)
				$notifications[empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile']][] = $row['email_address'];

			$log['sent'][$row['id_member']] = sprintf(isset($txt['pm_successfully_sent']) ? $txt['pm_successfully_sent'] : '', $row['real_name']);
		}
		$request->free();

		// Only 'send' the message if there are any recipients left.
		if (empty($all_to))
			return $log;

		// Track the pm count for our stats
		if (!empty($modSettings['trackStats']))
			trackStats(array('pm' => '+'));

		// Insert the message itself and then grab the last insert id.
		$result = $this->db->insert('',
			'{db_prefix}personal_messages',
			array(
				'id_pm_head' => 'int', 'id_member_from' => 'int', 'deleted_by_sender' => 'int',
				'from_name' => 'string-255', 'msgtime' => 'int', 'subject' => 'string-255', 'body' => 'string-65534',
			),
			array(
				$pm_head, $from['id'], ($store_outbox ? 0 : 1),
				$from['username'], time(), $htmlsubject, $htmlmessage,
			),
			array('id_pm')
		);
		$id_pm = $result->insertId('{db_prefix}personal_messages', 'id_pm');

		// Add the recipients.
		if (!empty($id_pm))
		{
			// If this is new we need to set it part of it's own conversation.
			if (empty($pm_head))
				$this->db->query('', '
				UPDATE {db_prefix}personal_messages
				SET id_pm_head = {int:id_pm_head}
				WHERE id_pm = {int:id_pm_head}',
					array(
						'id_pm_head' => $id_pm,
					)
				);

			// Some people think manually deleting personal_messages is fun... it's not. We protect against it though :)
			$this->db->query('', '
			DELETE FROM {db_prefix}pm_recipients
			WHERE id_pm = {int:id_pm}',
				array(
					'id_pm' => $id_pm,
				)
			);

			$insertRows = array();
			$to_list = array();
			foreach ($all_to as $to)
			{
				$insertRows[] = array($id_pm, $to, in_array($to, $recipients['bcc']) ? 1 : 0, isset($deletes[$to]) ? 1 : 0, 1);
				if (!in_array($to, $recipients['bcc']))
					$to_list[] = $to;
			}

			$this->db->insert('insert',
				'{db_prefix}pm_recipients',
				array(
					'id_pm' => 'int', 'id_member' => 'int', 'bcc' => 'int', 'deleted' => 'int', 'is_new' => 'int'
				),
				$insertRows,
				array('id_pm', 'id_member')
			);
		}

		$maillist = !empty($modSettings['maillist_enabled']) && !empty($modSettings['pbe_pm_enabled']);

		// If they have post by email enabled, override disallow_sendBody
		if (!$maillist && !empty($modSettings['disallow_sendBody']))
		{
			$message = '';
			$subject = censor($subject);
		}
		else
		{
			require_once(ROOTDIR . '/Mail/Emailpost.subs.php');
			pbe_prepare_text($message, $subject);
		}

		$to_names = array();
		if (count($to_list) > 1)
		{
				$result = $this->elk['members.manager']->getBasicMemberData($to_list);
			foreach ($result as $row)
				$to_names[] = $GLOBALS['elk']['text']->un_htmlspecialchar($row['real_name']);
		}

		$replacements = array(
			'SUBJECT' => $subject,
			'MESSAGE' => $message,
			'SENDER' => un_htmlspecialchars($from['name']),
			'READLINK' => $scripturl . '?action=pm;pmsg=' . $id_pm . '#msg' . $id_pm,
			'REPLYLINK' => $scripturl . '?action=pm;sa=send;f=inbox;pmsg=' . $id_pm . ';quote;u=' . $from['id'],
			'TOLIST' => implode(', ', $to_names),
		);

		// Select the right template
		$email_template = ($maillist && empty($modSettings['disallow_sendBody']) ? 'pbe_' : '') . 'new_pm' . (empty($modSettings['disallow_sendBody']) ? '_body' : '') . (!empty($to_names) ? '_tolist' : '');

		foreach ($notifications as $lang => $notification_list)
		{
			// Using maillist functionality
			if ($maillist)
			{
				$sender_details = query_sender_wrapper($from['id']);
				$from_wrapper = !empty($modSettings['maillist_mail_from']) ? $modSettings['maillist_mail_from'] : (empty($modSettings['maillist_sitename_address']) ? $webmaster_email : $modSettings['maillist_sitename_address']);

				// Add in the signature
				$replacements['SIGNATURE'] = $sender_details['signature'];

				// And off it goes, looking a bit more personal
				$mail = loadEmailTemplate($email_template, $replacements, $lang);
				$reference = !empty($pm_head) ? $pm_head : null;
				sendmail($notification_list, $mail['subject'], $mail['body'], $from['name'], 'p' . $id_pm, false, 2, null, true, $from_wrapper, $reference);
			}
			else
			{
				// Off the notification email goes!
				$mail = loadEmailTemplate($email_template, $replacements, $lang);
				sendmail($notification_list, $mail['subject'], $mail['body'], null, 'p' . $id_pm, false, 2, null, true);
			}
		}

		// Integrated After PMs
		$this->hooks->hook('personal_message_after', array(&$id_pm, &$log, &$recipients, &$from, &$subject, &$message));

		// Back to what we were on before!
		loadLanguage('index+PersonalMessage');

		// Add one to their unread and read message counts.
		foreach ($all_to as $k => $id)
		{
			if (isset($deletes[$id]))
				unset($all_to[$k]);
		}

		if (!empty($all_to))
		{
				$this->elk['members.manager']->updateMemberData($all_to, array('personal_messages' => '+', 'unread_messages' => '+', 'new_pm' => 1));
		}

		return $log;
	}

	/**
	 * Load personal messages.
	 *
	 * This function loads messages considering the options given, an array of:
	 * - 'display_mode' - the PMs display mode (i.e. conversation, all)
	 * - 'is_postgres' - (temporary) boolean to allow choice of PostgreSQL-specific sorting query
	 * - 'sort_by_query' - query to sort by
	 * - 'descending' - whether to sort descending
	 * - 'sort_by' - field to sort by
	 * - 'pmgs' - personal message id (if any). Note: it may not be set.
	 * - 'label_query' - query by labels
	 * - 'start' - start id, if any
	 *
	 * @package PersonalMessage
	 * @param mixed[] $pm_options options for loading
	 * @param int $id_member id member
	 * @return array ($pms, $posters, $recipients, $lastData)
	 */
	function loadPMs($pm_options, $id_member)
	{
		global $options;

		// First work out what messages we need to see - if grouped is a little trickier...
		// Conversation mode
		if ($pm_options['display_mode'] == 2)
		{
			// On a non-default sort, when using PostgreSQL we have to do a harder sort.
			if ($this->db->title() == 'PostgreSQL' && $pm_options['sort_by_query'] != 'pm.id_pm')
			{
				$sub_request = $this->db->query('', '
				SELECT
					MAX({raw:sort}) AS sort_param, pm.id_pm_head
				FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
					LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
					INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
						AND pmr.id_member = {int:current_member}
						AND pmr.deleted = {int:not_deleted}
						' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})') : '') . '
				WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {int:current_member}
					AND pm.deleted_by_sender = {int:not_deleted}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
					AND pm.id_pm = {int:id_pm}') . '
				GROUP BY pm.id_pm_head
				ORDER BY sort_param' . ($pm_options['descending'] ? ' DESC' : ' ASC') . (empty($pm_options['pmsg']) ? '
				LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
					array(
						'current_member' => $id_member,
						'not_deleted' => 0,
						'id_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
						'id_pm' => isset($pm_options['pmsg']) ? $pm_options['pmsg'] : '0',
						'sort' => $pm_options['sort_by_query'],
					)
				);
				$sub_pms = array();
				while ($row = $sub_request->fetchAssoc())
					$sub_pms[$row['id_pm_head']] = $row['sort_param'];
				$sub_request->free();

				// Now we use those results in the next query
				$request = $this->db->query('', '
				SELECT
					pm.id_pm AS id_pm, pm.id_pm_head
				FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
					LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
					INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
						AND pmr.id_member = {int:current_member}
						AND pmr.deleted = {int:not_deleted}
						' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})') : '') . '
				WHERE ' . (empty($sub_pms) ? '0=1' : 'pm.id_pm IN ({array_int:pm_list})') . '
				ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (empty($pm_options['pmsg']) ? '
				LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
					array(
						'current_member' => $id_member,
						'pm_list' => array_keys($sub_pms),
						'not_deleted' => 0,
						'sort' => $pm_options['sort_by_query'],
						'id_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
					)
				);
			}
			// Otherwise we can just use the the pm_conversation_list option
			else
			{
				$request = $this->db->query('pm_conversation_list', '
				SELECT
					MAX(pm.id_pm) AS id_pm, pm.id_pm_head
				FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? ($pm_options['sort_by'] == 'name' ? '
					LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
					INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
						AND pmr.id_member = {int:current_member}
						AND pmr.deleted = {int:deleted_by}
						' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:pm_member})') : '') . '
				WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {int:current_member}
					AND pm.deleted_by_sender = {int:deleted_by}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
					AND pm.id_pm = {int:pmsg}') . '
				GROUP BY pm.id_pm_head
				ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (isset($pm_options['pmsg']) ? '
				LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
					array(
						'current_member' => $id_member,
						'deleted_by' => 0,
						'sort' => $pm_options['sort_by_query'],
						'pm_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
						'pmsg' => isset($pm_options['pmsg']) ? (int) $pm_options['pmsg'] : 0,
					)
				);
			}
		}
		// If not in conversation view, then this is kinda simple!
		else
		{
			// @todo SLOW This query uses a filesort. (inbox only.)
			$request = $this->db->query('', '
			SELECT
				pm.id_pm, pm.id_pm_head, pm.id_member_from
			FROM {db_prefix}personal_messages AS pm' . ($pm_options['folder'] == 'sent' ? '' . ($pm_options['sort_by'] == 'name' ? '
				LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') : '
				INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm
					AND pmr.id_member = {int:current_member}
					AND pmr.deleted = {int:is_deleted}
					' . $pm_options['label_query'] . ')') . ($pm_options['sort_by'] == 'name' ? ('
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:pm_member})') : '') . '
			WHERE ' . ($pm_options['folder'] == 'sent' ? 'pm.id_member_from = {raw:current_member}
				AND pm.deleted_by_sender = {int:is_deleted}' : '1=1') . (empty($pm_options['pmsg']) ? '' : '
				AND pm.id_pm = {int:pmsg}') . '
			ORDER BY ' . ($pm_options['sort_by_query'] == 'pm.id_pm' && $pm_options['folder'] != 'sent' ? 'pmr.id_pm' : '{raw:sort}') . ($pm_options['descending'] ? ' DESC' : ' ASC') . (isset($pm_options['pmsg']) ? '
			LIMIT ' . $pm_options['start'] . ', ' . $pm_options['limit'] : ''),
				array(
					'current_member' => $id_member,
					'is_deleted' => 0,
					'sort' => $pm_options['sort_by_query'],
					'pm_member' => $pm_options['folder'] == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
					'pmsg' => isset($pm_options['pmsg']) ? (int) $pm_options['pmsg'] : 0,
				)
			);
		}
		// Load the id_pms and initialize recipients.
		$pms = array();
		$lastData = array();
		$posters = $pm_options['folder'] == 'sent' ? array($id_member) : array();
		$recipients = array();
		while ($row = $request->fetchAssoc())
		{
			if (!isset($recipients[$row['id_pm']]))
			{
				if (isset($row['id_member_from']))
					$posters[$row['id_pm']] = $row['id_member_from'];

				$pms[$row['id_pm']] = $row['id_pm'];

				$recipients[$row['id_pm']] = array(
					'to' => array(),
					'bcc' => array()
				);
			}

			// Keep track of the last message so we know what the head is without another query!
			if ((empty($pm_options['pmid']) && (empty($options['view_newest_pm_first']) || !isset($lastData))) || empty($lastData) || (!empty($pm_options['pmid']) && $pm_options['pmid'] == $row['id_pm']))
				$lastData = array(
					'id' => $row['id_pm'],
					'head' => $row['id_pm_head'],
				);
		}
		$request->free();

		return array($pms, $posters, $recipients, $lastData);
	}

	/**
	 * How many PMs have you sent lately?
	 *
	 * @package PersonalMessage
	 * @param int $id_member id member
	 * @param int $time time interval (in seconds)
	 */
	function pmCount($id_member, $time)
	{

		$request = $this->db->query('', '
		SELECT
			COUNT(*) AS post_count
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pr ON (pr.id_pm = pm.id_pm)
		WHERE pm.id_member_from = {int:current_member}
			AND pm.msgtime > {int:msgtime}',
			array(
				'current_member' => $id_member,
				'msgtime' => time() - $time,
			)
		);
		list ($pmCount) = $request->fetchRow();
		$request->free();

		return $pmCount;
	}

	/**
	 * This will apply rules to all unread messages.
	 *
	 * - If all_messages is set will, clearly, do it to all!
	 *
	 * @package PersonalMessage
	 * @param bool $all_messages = false
	 */
	function applyRules($all_messages = false)
	{
		global $user_info, $context, $options;

		// Want this - duh!
		$this->loadRules();

		// No rules?
		if (empty($context['rules']))
			return;

		// Just unread ones?
		$ruleQuery = $all_messages ? '' : ' AND pmr.is_new = 1';

		// @todo Apply all should have timeout protection!
		// Get all the messages that match this.
		$request = $this->db->query('', '
		SELECT
			pmr.id_pm, pm.id_member_from, pm.subject, pm.body, mem.id_group, pmr.labels
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
		WHERE pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}
			' . $ruleQuery,
			array(
				'current_member' => $user_info['id'],
				'not_deleted' => 0,
			)
		);
		$actions = array();
		while ($row = $request->fetchAssoc())
		{
			foreach ($context['rules'] as $rule)
			{
				$match = false;

				// Loop through all the criteria hoping to make a match.
				foreach ($rule['criteria'] as $criterium)
				{
					if (($criterium['t'] == 'mid' && $criterium['v'] == $row['id_member_from']) || ($criterium['t'] == 'gid' && $criterium['v'] == $row['id_group']) || ($criterium['t'] == 'sub' && strpos($row['subject'], $criterium['v']) !== false) || ($criterium['t'] == 'msg' && strpos($row['body'], $criterium['v']) !== false))
						$match = true;
					// If we're adding and one criteria don't match then we stop!
					elseif ($rule['logic'] == 'and')
					{
						$match = false;
						break;
					}
				}

				// If we have a match the rule must be true - act!
				if ($match)
				{
					if ($rule['delete'])
						$actions['deletes'][] = $row['id_pm'];
					else
					{
						foreach ($rule['actions'] as $ruleAction)
						{
							if ($ruleAction['t'] == 'lab')
							{
								// Get a basic pot started!
								if (!isset($actions['labels'][$row['id_pm']]))
									$actions['labels'][$row['id_pm']] = empty($row['labels']) ? array() : explode(',', $row['labels']);

								$actions['labels'][$row['id_pm']][] = $ruleAction['v'];
							}
						}
					}
				}
			}
		}
		$request->free();

		// Deletes are easy!
		if (!empty($actions['deletes']))
			deleteMessages($actions['deletes']);

		// Re-label?
		if (!empty($actions['labels']))
		{
			foreach ($actions['labels'] as $pm => $labels)
			{
				// Quickly check each label is valid!
				$realLabels = array();
				foreach ($context['labels'] as $label)
					if (in_array($label['id'], $labels) && ($label['id'] != -1 || empty($options['pm_remove_inbox_label'])))
						$realLabels[] = $label['id'];

				$this->db->query('', '
				UPDATE {db_prefix}pm_recipients
				SET labels = {string:new_labels}
				WHERE id_pm = {int:id_pm}
					AND id_member = {int:current_member}',
					array(
						'current_member' => $user_info['id'],
						'id_pm' => $pm,
						'new_labels' => empty($realLabels) ? '' : implode(',', $realLabels),
					)
				);
			}
		}
	}

	/**
	 * Load up all the rules for the current user.
	 *
	 * @package PersonalMessage
	 * @param bool $reload = false
	 */
	function loadRules($reload = false)
	{
		global $user_info, $context;


		if (isset($context['rules']) && !$reload)
			return;

		// This is just a simple list of "all" known rules
		$context['known_rules'] = array(
			// member_id == "Sender Name"
			'mid',
			// group_id == "Sender's Groups"
			'gid',
			// subject == "Message Subject Contains"
			'sub',
			// message == "Message Body Contains"
			'msg',
			// buddy == "Sender is Buddy"
			'bud',
		);

		$request = $this->db->query('', '
		SELECT
			id_rule, rule_name, criteria, actions, delete_pm, is_or
		FROM {db_prefix}pm_rules
		WHERE id_member = {int:current_member}',
			array(
				'current_member' => $user_info['id'],
			)
		);
		$context['rules'] = array();
		// Simply fill in the data!
		while ($row = $request->fetchAssoc())
		{
			$context['rules'][$row['id_rule']] = array(
				'id' => $row['id_rule'],
				'name' => $row['rule_name'],
				'criteria' => unserialize($row['criteria']),
				'actions' => unserialize($row['actions']),
				'delete' => $row['delete_pm'],
				'logic' => $row['is_or'] ? 'or' : 'and',
			);

			if ($row['delete_pm'])
				$context['rules'][$row['id_rule']]['actions'][] = array('t' => 'del', 'v' => 1);
		}
		$request->free();
	}

	/**
	 * Update PM recipient when they receive or read a new PM
	 *
	 * @package PersonalMessage
	 * @param int $id_member
	 * @param boolean $new = false
	 */
	function toggleNewPM($id_member, $new = false)
	{
		$this->db->query('', '
		UPDATE {db_prefix}pm_recipients
		SET is_new = ' . ($new ? '{int:new}' : '{int:not_new}') . '
		WHERE id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'new' => 1,
				'not_new' => 0
			)
		);
	}

	/**
	 * Load the PM limits for each group or for a specified group
	 *
	 * @package PersonalMessage
	 * @param int|false $id_group (optional) the id of a membergroup
	 * @return array
	 */
	function loadPMLimits($id_group = false)
	{
		$request = $this->db->query('', '
		SELECT
			id_group, group_name, max_messages
		FROM {db_prefix}membergroups' . ($id_group ? '
		WHERE id_group = {int:id_group}' : '
		ORDER BY min_posts, CASE WHEN id_group < {int:newbie_group} THEN id_group ELSE 4 END, group_name'),
			array(
				'id_group' => $id_group,
				'newbie_group' => 4,
			)
		);
		$groups = array();
		while ($row = $request->fetchAssoc())
		{
			if ($row['id_group'] != 1)
				$groups[$row['id_group']] = $row;
		}
		$request->free();

		return $groups;
	}

	/**
	 * Retrieve the discussion one or more PMs belong to
	 *
	 * @package PersonalMessage
	 * @param int[] $id_pms
	 */
	function getDiscussions($id_pms)
	{

		$request = $this->db->query('', '
		SELECT
			id_pm_head, id_pm
		FROM {db_prefix}personal_messages
		WHERE id_pm IN ({array_int:id_pms})',
			array(
				'id_pms' => $id_pms,
			)
		);
		$pm_heads = array();
		while ($row = $request->fetchAssoc())
			$pm_heads[$row['id_pm_head']] = $row['id_pm'];
		$request->free();

		return $pm_heads;
	}

	/**
	 * Return all the PMs belonging to one or more discussions
	 *
	 * @package PersonalMessage
	 * @param int[] $pm_heads array of pm id head nodes
	 */
	function getPmsFromDiscussion($pm_heads)
	{

		$pms = array();
		$request = $this->db->query('', '
		SELECT
			id_pm, id_pm_head
		FROM {db_prefix}personal_messages
		WHERE id_pm_head IN ({array_int:pm_heads})',
			array(
				'pm_heads' => $pm_heads,
			)
		);
		// Copy the action from the single to PM to the others.
		while ($row = $request->fetchAssoc())
			$pms[$row['id_pm']] = $row['id_pm_head'];
		$request->free();

		return $pms;
	}

	/**
	 * Determines the PMs which need an updated label.
	 *
	 * @package PersonalMessage
	 * @param mixed[] $to_label
	 * @param int[] $label_type
	 * @param int $user_id
	 * @return integer|null
	 */
	function changePMLabels($to_label, $label_type, $user_id)
	{
		global $options;


		$to_update = array();

		// Get information about each message...
		$request = $this->db->query('', '
		SELECT
			id_pm, labels
		FROM {db_prefix}pm_recipients
		WHERE id_member = {int:current_member}
			AND id_pm IN ({array_int:to_label})
		LIMIT ' . count($to_label),
			array(
				'current_member' => $user_id,
				'to_label' => array_keys($to_label),
			)
		);
		while ($row = $request->fetchAssoc())
		{
			$labels = $row['labels'] == '' ? array('-1') : explode(',', trim($row['labels']));

			// Already exists?  Then... unset it!
			$id_label = array_search($to_label[$row['id_pm']], $labels);

			if ($id_label !== false && $label_type[$row['id_pm']] !== 'add')
				unset($labels[$id_label]);
			elseif ($label_type[$row['id_pm']] !== 'rem')
				$labels[] = $to_label[$row['id_pm']];

			if (!empty($options['pm_remove_inbox_label']) && $to_label[$row['id_pm']] != '-1' && ($key = array_search('-1', $labels)) !== false)
				unset($labels[$key]);

			$set = implode(',', array_unique($labels));
			if ($set == '')
				$set = '-1';

			$to_update[$row['id_pm']] = $set;
		}
		$request->free();

		if (!empty($to_update))
			return updatePMLabels($to_update, $user_id);
	}

	/**
	 * Detects personal messages which need a new label.
	 *
	 * @package PersonalMessage
	 * @param mixed[] $searchArray
	 * @param mixed[] $new_labels
	 * @param int $user_id
	 * @return integer|null
	 */
	function updateLabelsToPM($searchArray, $new_labels, $user_id)
	{
		// Now find the messages to change.
		$request = $this->db->query('', '
		SELECT
			id_pm, labels
		FROM {db_prefix}pm_recipients
		WHERE FIND_IN_SET({raw:find_label_implode}, labels) != 0
			AND id_member = {int:current_member}',
			array(
				'current_member' => $user_id,
				'find_label_implode' => '\'' . implode('\', labels) != 0 OR FIND_IN_SET(\'', $searchArray) . '\'',
			)
		);
		$to_update = array();
		while ($row = $request->fetchAssoc())
		{
			// Do the long task of updating them...
			$toChange = explode(',', $row['labels']);

			foreach ($toChange as $key => $value)
			{
				if (in_array($value, $searchArray))
				{
					if (isset($new_labels[$value]))
						$toChange[$key] = $new_labels[$value];
					else
						unset($toChange[$key]);
				}
			}

			if (empty($toChange))
				$toChange[] = '-1';

			$to_update[$row['id_pm']] = implode(',', array_unique($toChange));
		}
		$request->free();

		if (!empty($to_update))
			return updatePMLabels($to_update, $user_id);
	}

	/**
	 * Updates PMs with their new label.
	 *
	 * @package PersonalMessage
	 * @param mixed[] $to_update
	 * @param int $user_id
	 * @return int
	 */
	function updatePMLabels($to_update, $user_id)
	{
		$updateErrors = 0;

		foreach ($to_update as $id_pm => $set)
		{
			// Check that this string isn't going to be too large for the database.
			if (strlen($set) > 60)
			{
				$updateErrors++;

				// Make the string as long as possible and update anyway
				$set = substr($set, 0, 60);
				$set = substr($set, 0, strrpos($set, ','));
			}

			$this->db->query('', '
			UPDATE {db_prefix}pm_recipients
			SET labels = {string:labels}
			WHERE id_pm = {int:id_pm}
				AND id_member = {int:current_member}',
				array(
					'current_member' => $user_id,
					'id_pm' => $id_pm,
					'labels' => $set,
				)
			);
		}

		return $updateErrors;
	}

	/**
	 * Gets PMs older than a specific date.
	 *
	 * @package PersonalMessage
	 * @param int $user_id the user's id.
	 * @param int $time timestamp with a specific date
	 * @return array
	 */
	function getPMsOlderThan($user_id, $time)
	{
		// Array to store the IDs in.
		$pm_ids = array();

		// Select all the messages they have sent older than $time.
		$request = $this->db->query('', '
		SELECT
			id_pm
		FROM {db_prefix}personal_messages
		WHERE deleted_by_sender = {int:not_deleted}
			AND id_member_from = {int:current_member}
			AND msgtime < {int:msgtime}',
			array(
				'current_member' => $user_id,
				'not_deleted' => 0,
				'msgtime' => $time,
			)
		);
		while ($row = $request->fetchRow())
			$pm_ids[] = $row[0];
		$request->free();

		// This is the inbox
		$request = $this->db->query('', '
		SELECT
			pmr.id_pm
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
		WHERE pmr.deleted = {int:not_deleted}
			AND pmr.id_member = {int:current_member}
			AND pm.msgtime < {int:msgtime}',
			array(
				'current_member' => $user_id,
				'not_deleted' => 0,
				'msgtime' => $time,
			)
		);
		while ($row = $request->fetchRow())
			$pm_ids[] = $row[0];
		$request->free();

		return $pm_ids;
	}

	/**
	 * Used to delete PM rules from the given member.
	 *
	 * @package PersonalMessage
	 * @param int $id_member
	 * @param int[] $rule_changes
	 */
	function deletePMRules($id_member, $rule_changes)
	{
		$this->db->query('', '
		DELETE FROM {db_prefix}pm_rules
		WHERE id_rule IN ({array_int:rule_list})
		AND id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'rule_list' => $rule_changes,
			)
		);
	}

	/**
	 * Updates a personal messaging rule action for the given member.
	 *
	 * @package PersonalMessage
	 * @param int $id_rule
	 * @param int $id_member
	 * @param mixed[] $actions
	 */
	function updatePMRuleAction($id_rule, $id_member, $actions)
	{
		$this->db->query('', '
		UPDATE {db_prefix}pm_rules
		SET actions = {string:actions}
		WHERE id_rule = {int:id_rule}
			AND id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'id_rule' => $id_rule,
				'actions' => serialize($actions),
			)
		);
	}

	/**
	 * Add a new PM rule to the database.
	 *
	 * @package PersonalMessage
	 * @param int $id_member
	 * @param string $ruleName
	 * @param string $criteria
	 * @param string $actions
	 * @param int $doDelete
	 * @param int $isOr
	 */
	function addPMRule($id_member, $ruleName, $criteria, $actions, $doDelete, $isOr)
	{
		$this->db->insert('',
			'{db_prefix}pm_rules',
			array(
				'id_member' => 'int', 'rule_name' => 'string', 'criteria' => 'string', 'actions' => 'string',
				'delete_pm' => 'int', 'is_or' => 'int',
			),
			array(
				$id_member, $ruleName, $criteria, $actions, $doDelete, $isOr,
			),
			array('id_rule')
		);
	}

	/**
	 * Updates a personal messaging rule for the given member.
	 *
	 * @package PersonalMessage
	 * @param int $id_member
	 * @param int $id_rule
	 * @param string $ruleName
	 * @param string $criteria
	 * @param string $actions
	 * @param int $doDelete
	 * @param int $isOr
	 */
	function updatePMRule($id_member, $id_rule, $ruleName, $criteria, $actions, $doDelete, $isOr)
	{
		$this->db->query('', '
		UPDATE {db_prefix}pm_rules
		SET rule_name = {string:rule_name}, criteria = {string:criteria}, actions = {string:actions},
			delete_pm = {int:delete_pm}, is_or = {int:is_or}
		WHERE id_rule = {int:id_rule}
			AND id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'delete_pm' => $doDelete,
				'is_or' => $isOr,
				'id_rule' => $id_rule,
				'rule_name' => $ruleName,
				'criteria' => $criteria,
				'actions' => $actions,
			)
		);
	}

	/**
	 * Used to set a replied status for a given PM.
	 *
	 * @package PersonalMessage
	 * @param int $id_member
	 * @param int $replied_to
	 */
	function setPMRepliedStatus($id_member, $replied_to)
	{
		$this->db->query('', '
		UPDATE {db_prefix}pm_recipients
		SET is_read = is_read | 2
		WHERE id_pm = {int:replied_to}
			AND id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
				'replied_to' => $replied_to,
			)
		);
	}

	/**
	 * Given the head PM, loads all other PM's that share the same head node
	 *
	 * - Used to load the conversation view of a PM
	 *
	 * @package PersonalMessage
	 * @param int $head id of the head pm of the conversation
	 * @param mixed[] $recipients
	 * @param string $folder the current folder we are working in
	 * @return array
	 */
	function loadConversationList($head, &$recipients, $folder = '')
	{
		global $user_info;

		$request = $this->db->query('', '
		SELECT
			pm.id_pm, pm.id_member_from, pm.deleted_by_sender, pmr.id_member, pmr.deleted
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
		WHERE pm.id_pm_head = {int:id_pm_head}
			AND ((pm.id_member_from = {int:current_member} AND pm.deleted_by_sender = {int:not_deleted})
				OR (pmr.id_member = {int:current_member} AND pmr.deleted = {int:not_deleted}))
		ORDER BY pm.id_pm',
			array(
				'current_member' => $user_info['id'],
				'id_pm_head' => $head,
				'not_deleted' => 0,
			)
		);
		$display_pms = array();
		$posters = array();
		while ($row = $request->fetchAssoc())
		{
			// This is, frankly, a joke. We will put in a workaround for people sending to themselves - yawn!
			if ($folder == 'sent' && $row['id_member_from'] == $user_info['id'] && $row['deleted_by_sender'] == 1)
				continue;
			elseif (($row['id_member'] == $user_info['id']) && $row['deleted'] == 1)
				continue;

			if (!isset($recipients[$row['id_pm']]))
				$recipients[$row['id_pm']] = array(
					'to' => array(),
					'bcc' => array()
				);

			$display_pms[] = $row['id_pm'];
			$posters[$row['id_pm']] = $row['id_member_from'];
		}
		$request->free();

		return array($display_pms, $posters);
	}

	/**
	 * Used to determine if any message in a conversation thread is unread
	 *
	 * - Returns array of keys with the head id and value details of the the newest
	 * unread message.
	 *
	 * @package PersonalMessage
	 * @param int[] $pms array of pm ids to search
	 * @return array
	 */
	function loadConversationUnreadStatus($pms)
	{
		global $user_info;

		// Make it an array if its not
		if (!is_array($pms))
			$pms = array($pms);

		// Find the heads for this group of PM's
		$request = $this->db->query('', '
		SELECT
			id_pm_head, id_pm
		FROM {db_prefix}personal_messages
		WHERE id_pm IN ({array_int:id_pm})',
			array(
				'id_pm' => $pms,
			)
		);
		$head_pms = array();
		while ($row = $request->fetchAssoc())
			$head_pms[$row['id_pm_head']] = $row['id_pm'];
		$request->free();

		// Find any unread PM's this member has under these head pm id's
		$request = $this->db->query('', '
		SELECT
			MAX(pm.id_pm) AS id_pm, pm.id_pm_head
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
		WHERE pm.id_pm_head IN ({array_int:id_pm_head})
			AND (pmr.id_member = {int:current_member} AND pmr.deleted = {int:not_deleted})
			AND (pmr.is_read & 1 = 0)
		GROUP BY pm.id_pm_head',
			array(
				'current_member' => $user_info['id'],
				'id_pm_head' => array_keys($head_pms),
				'not_deleted' => 0,
			)
		);
		$unread_pms = array();
		while ($row = $request->fetchAssoc())
		{
			// Return the results under the original index since thats what we are
			// displaying in the subject list
			$index = $head_pms[$row['id_pm_head']];
			$unread_pms[$index] = $row;
		}
		$request->free();

		return $unread_pms;
	}

	/**
	 * Get all recipients for a given group of PM's, loads some basic member information for each
	 *
	 * - Will not include bcc-recipients for an inbox
	 * - Keeps track if a message has been replied / read
	 * - Tracks any message labels in use
	 * - If optional search parameter is set to true will return message first label, useful for linking
	 *
	 * @package PersonalMessage
	 * @param int[] $all_pms
	 * @param mixed[] $recipients
	 * @param string $folder
	 * @param boolean $search
	 * @return array
	 */
	function loadPMRecipientInfo($all_pms, &$recipients, $folder = '', $search = false)
	{
		global $txt, $user_info, $scripturl, $context;


		// Get the recipients for all these PM's
		$request = $this->db->query('', '
		SELECT
			pmr.id_pm, pmr.bcc, pmr.labels, pmr.is_read,
			mem_to.id_member AS id_member_to, mem_to.real_name AS to_name
		FROM {db_prefix}pm_recipients AS pmr
			LEFT JOIN {db_prefix}members AS mem_to ON (mem_to.id_member = pmr.id_member)
		WHERE pmr.id_pm IN ({array_int:pm_list})',
			array(
				'pm_list' => $all_pms,
			)
		);
		$message_labels = array();
		$message_replied = array();
		$message_unread = array();
		$message_first_label = array();
		while ($row = $request->fetchAssoc())
		{
			// Sent folder recipients
			if ($folder === 'sent' || empty($row['bcc']))
				$recipients[$row['id_pm']][empty($row['bcc']) ? 'to' : 'bcc'][] = empty($row['id_member_to']) ? $txt['guest_title'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member_to'] . '">' . $row['to_name'] . '</a>';

			// Don't include bcc-recipients if its your inbox, you're not supposed to know :P
			if ($row['id_member_to'] == $user_info['id'] && $folder !== 'sent')
			{
				// Read and replied to status for this message
				$message_replied[$row['id_pm']] = $row['is_read'] & 2;
				$message_unread[$row['id_pm']] = $row['is_read'] == 0;

				$row['labels'] = $row['labels'] == '' ? array() : explode(',', $row['labels']);
				foreach ($row['labels'] as $v)
				{
					if (isset($context['labels'][(int) $v]))
						$message_labels[$row['id_pm']][(int) $v] = array('id' => $v, 'name' => $context['labels'][(int) $v]['name']);

					// Here we find the first label on a message - used for linking to posts
					if ($search && (!isset($message_first_label[$row['id_pm']]) && !in_array('-1', $row['labels'])))
						$message_first_label[$row['id_pm']] = (int) $v;
				}
			}
		}
		$request->free();

		return array($message_labels, $message_replied, $message_unread, ($search ? $message_first_label : ''));
	}

	/**
	 * This is used by preparePMContext_callback.
	 *
	 * - That function uses these query results and handles the free_result action as well.
	 *
	 * @package PersonalMessage
	 * @param int[] $pms array of PM ids to fetch
	 * @param string[] $orderBy raw query defining how to order the results
	 * @return ResultInterface
	 */
	function loadPMSubjectRequest($pms, $orderBy)
	{

		// Separate query for these bits!
		$subjects_request = $this->db->query('', '
		SELECT
			pm.id_pm, pm.subject, pm.id_member_from, pm.msgtime, IFNULL(mem.real_name, pm.from_name) AS from_name,
			IFNULL(mem.id_member, 0) AS not_guest
		FROM {db_prefix}personal_messages AS pm
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
		WHERE pm.id_pm IN ({array_int:pm_list})
		ORDER BY ' . implode(', ', $orderBy) . '
		LIMIT ' . count($pms),
			array(
				'pm_list' => $pms,
			)
		);

		return $subjects_request;
	}

	/**
	 * Similar to loadSubjectRequest, this is used by preparePMContext_callback.
	 *
	 * - That function uses these query results and handles the free_result action as well.
	 *
	 * @package PersonalMessage
	 * @param int[] $display_pms list of PM's to fetch
	 * @param string $sort_by_query raw query used in the sorting option
	 * @param string $sort_by used to signal when addition joins are needed
	 * @param boolean $descending if true descending order of display
	 * @param int|string $display_mode how are they being viewed, all, conversation, etc
	 * @param string $folder current pm folder
	 * @return ResultInterface
	 */
	function loadPMMessageRequest($display_pms, $sort_by_query, $sort_by, $descending, $display_mode = '', $folder = '')
	{
		$messages_request = $this->db->query('', '
		SELECT
			pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name
		FROM {db_prefix}personal_messages AS pm' . ($folder == 'sent' ? '
			LEFT JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)' : '') . ($sort_by == 'name' ? '
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = {raw:id_member})' : '') . '
		WHERE pm.id_pm IN ({array_int:display_pms})' . ($folder == 'sent' ? '
		GROUP BY pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name' : '') . '
		ORDER BY ' . ($display_mode == 2 ? 'pm.id_pm' : $sort_by_query) . ($descending ? ' DESC' : ' ASC') . '
		LIMIT ' . count($display_pms),
			array(
				'display_pms' => $display_pms,
				'id_member' => $folder == 'sent' ? 'pmr.id_member' : 'pm.id_member_from',
			)
		);

		return $messages_request;
	}

	/**
	 * Simple function to validate that a PM was sent to the current user
	 *
	 * @package PersonalMessage
	 * @param int $pmsg id of the pm we are checking
	 * @return bool
	 */
	function checkPMReceived($pmsg)
	{
		global $user_info;

		$request = $this->db->query('', '
		SELECT
			id_pm
		FROM {db_prefix}pm_recipients
		WHERE id_pm = {int:id_pm}
			AND id_member = {int:current_member}
		LIMIT 1',
			array(
				'current_member' => $user_info['id'],
				'id_pm' => $pmsg,
			)
		);
		$isReceived = $request->numRows() != 0;
		$request->free();

		return $isReceived;
	}

	/**
	 * Loads a pm by ID for use as a quoted pm in a new message
	 *
	 * @param int $pmsg
	 * @param boolean $isReceived
	 * @return array|false
	 */
	function loadPMQuote($pmsg, $isReceived)
	{
		global $user_info;

		// Get the quoted message (and make sure you're allowed to see this quote!).
		$request = $this->db->query('', '
		SELECT
			pm.id_pm, CASE WHEN pm.id_pm_head = {int:id_pm_head_empty} THEN pm.id_pm ELSE pm.id_pm_head END AS pm_head,
			pm.body, pm.subject, pm.msgtime,
			mem.member_name, IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, pm.from_name) AS real_name
		FROM {db_prefix}personal_messages AS pm' . (!$isReceived ? '' : '
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = {int:id_pm})') . '
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = pm.id_member_from)
		WHERE pm.id_pm = {int:id_pm}' . (!$isReceived ? '
			AND pm.id_member_from = {int:current_member}' : '
			AND pmr.id_member = {int:current_member}') . '
		LIMIT 1',
			array(
				'current_member' => $user_info['id'],
				'id_pm_head_empty' => 0,
				'id_pm' => $pmsg,
			)
		);
		$row_quoted = $request->fetchAssoc();
		$request->free();

		return empty($row_quoted) ? false : $row_quoted;
	}

	/**
	 * For a given PM ID, loads all "other" recipients, (excludes the current member)
	 *
	 * - Will optionally count the number of bcc recipients and return that count
	 *
	 * @package PersonalMessage
	 * @param int $pmsg
	 * @param boolean $bcc_count
	 * @return array
	 */
	function loadPMRecipientsAll($pmsg, $bcc_count = false)
	{
		global $user_info, $scripturl, $txt;

		$request = $this->db->query('', '
		SELECT
			mem.id_member, mem.real_name, pmr.bcc
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = pmr.id_member)
		WHERE pmr.id_pm = {int:id_pm}
			AND pmr.id_member != {int:current_member}' . ($bcc_count === true ? '' : '
			AND pmr.bcc = {int:not_bcc}'),
			array(
				'current_member' => $user_info['id'],
				'id_pm' => $pmsg,
				'not_bcc' => 0,
			)
		);
		$recipients = array();
		$hidden_recipients = 0;
		while ($row = $request->fetchAssoc())
		{
			// If it's hidden we still don't reveal their names
			if ($bcc_count && $row['bcc'])
				$hidden_recipients++;

			$recipients[] = array(
				'id' => $row['id_member'],
				'name' => htmlspecialchars($row['real_name'], ENT_COMPAT, 'UTF-8'),
				'link' => '[url=' . $scripturl . '?action=profile;u=' . $row['id_member'] . ']' . $row['real_name'] . '[/url]',
			);
		}

		// If bcc count was requested, we return the number of bcc members, but not the names
		if ($bcc_count)
		{
			$recipients[] = array(
				'id' => 'bcc',
				'name' => sprintf($txt['pm_report_pm_hidden'], $hidden_recipients),
				'link' => sprintf($txt['pm_report_pm_hidden'], $hidden_recipients)
			);
		}

		$request->free();

		return $recipients;
	}

	/**
	 * Simply loads a personal message by ID
	 *
	 * - Supplied ID must have been sent to the user id requesting it and it must not have been deleted
	 *
	 * @package PersonalMessage
	 * @param int $pm_id
	 */
	function loadPersonalMessage($pm_id)
	{
		global $user_info;
		// First, pull out the message contents, and verify it actually went to them!
		$request = $this->db->query('', '
		SELECT
			pm.subject, pm.body, pm.msgtime, pm.id_member_from,
			IFNULL(m.real_name, pm.from_name) AS sender_name,
			pm.from_name AS poster_name, msgtime
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
			LEFT JOIN {db_prefix}members AS m ON (m.id_member = pm.id_member_from)
		WHERE pm.id_pm = {int:id_pm}
			AND pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}
		LIMIT 1',
			array(
				'current_member' => $user_info['id'],
				'id_pm' => $pm_id,
				'not_deleted' => 0,
			)
		);
		// Can only be a hacker here!
		if ($request->numRows() == 0)
			$this->errors->fatal_lang_error('no_access', false);
		$pm_details = $request->fetchRow();
		$request->free();

		return $pm_details;
	}

	/**
	 * Finds the number of results that a search would produce
	 *
	 * @package PersonalMessage
	 * @param string $userQuery raw query, used if we are searching for specific users
	 * @param string $labelQuery raw query, used if we are searching only specific labels
	 * @param string $timeQuery raw query, used if we are limiting results to time periods
	 * @param string $searchQuery raw query, the actual thing you are searching for in the subject and/or body
	 * @param mixed[] $searchq_parameters value parameters used in the above query
	 * @return integer
	 */
	function numPMSeachResults($userQuery, $labelQuery, $timeQuery, $searchQuery, $searchq_parameters)
	{
		global $context, $user_info;

		// Get the amount of results.
		$request = $this->db->query('', '
		SELECT
			COUNT(*)
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
		WHERE ' . ($context['folder'] == 'inbox' ? '
			pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}' : '
			pm.id_member_from = {int:current_member}
			AND pm.deleted_by_sender = {int:not_deleted}') . '
			' . $userQuery . $labelQuery . $timeQuery . '
			AND (' . $searchQuery . ')',
			array_merge($searchq_parameters, array(
				'current_member' => $user_info['id'],
				'not_deleted' => 0,
			))
		);
		list ($numResults) = $request->fetchRow();
		$request->free();

		return $numResults;
	}

	/**
	 * Gets all the matching message ids, senders and head pm nodes, using standard search only (No caching and the like!)
	 *
	 * @package PersonalMessage
	 * @param string $userQuery raw query, used if we are searching for specific users
	 * @param string $labelQuery raw query, used if we are searching only specific labels
	 * @param string $timeQuery raw query, used if we are limiting results to time periods
	 * @param string $searchQuery raw query, the actual thing you are searching for in the subject and/or body
	 * @param mixed[] $searchq_parameters value parameters used in the above query
	 * @param mixed[] $search_params additional search parameters, like sort and direction
	 * @return array
	 */
	function loadPMSearchMessages($userQuery, $labelQuery, $timeQuery, $searchQuery, $searchq_parameters, $search_params)
	{
		global $context, $modSettings, $user_info;

		$request = $this->db->query('', '
		SELECT
			pm.id_pm, pm.id_pm_head, pm.id_member_from
		FROM {db_prefix}pm_recipients AS pmr
			INNER JOIN {db_prefix}personal_messages AS pm ON (pm.id_pm = pmr.id_pm)
		WHERE ' . ($context['folder'] == 'inbox' ? '
			pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}' : '
			pm.id_member_from = {int:current_member}
			AND pm.deleted_by_sender = {int:not_deleted}') . '
			' . $userQuery . $labelQuery . $timeQuery . '
			AND (' . $searchQuery . ')
		ORDER BY ' . $search_params['sort'] . ' ' . $search_params['sort_dir'] . '
		LIMIT ' . $context['start'] . ', ' . $modSettings['search_results_per_page'],
			array_merge($searchq_parameters, array(
				'current_member' => $user_info['id'],
				'not_deleted' => 0,
			))
		);
		$foundMessages = array();
		$posters = array();
		$head_pms = array();
		while ($row = $request->fetchAssoc())
		{
			$foundMessages[] = $row['id_pm'];
			$posters[] = $row['id_member_from'];
			$head_pms[$row['id_pm']] = $row['id_pm_head'];
		}
		$request->free();

		return array($foundMessages, $posters, $head_pms);
	}

	/**
	 * When we are in conversation view, we need to find the base head pm of the
	 * conversation.  This will set the root head id to each of the node heads
	 *
	 * @package PersonalMessage
	 * @param int[] $head_pms array of pm ids that were found in the id_pm_head col during the initial search
	 * @return array
	 */
	function loadPMSearchHeads($head_pms)
	{
		global $user_info;

		$request = $this->db->query('', '
		SELECT
			MAX(pm.id_pm) AS id_pm, pm.id_pm_head
		FROM {db_prefix}personal_messages AS pm
			INNER JOIN {db_prefix}pm_recipients AS pmr ON (pmr.id_pm = pm.id_pm)
		WHERE pm.id_pm_head IN ({array_int:head_pms})
			AND pmr.id_member = {int:current_member}
			AND pmr.deleted = {int:not_deleted}
		GROUP BY pm.id_pm_head
		LIMIT {int:limit}',
			array(
				'head_pms' => array_unique($head_pms),
				'current_member' => $user_info['id'],
				'not_deleted' => 0,
				'limit' => count($head_pms),
			)
		);
		$real_pm_ids = array();
		while ($row = $request->fetchAssoc())
			$real_pm_ids[$row['id_pm_head']] = $row['id_pm'];
		$request->free();

		return $real_pm_ids;
	}

	/**
	 * Loads the actual details of the PM's that were found during the search stage
	 *
	 * @package PersonalMessage
	 * @param int[] $foundMessages array of found message id's
	 * @param mixed[] $search_params as specified in the form, here used for sorting
	 * @return array
	 */
	function loadPMSearchResults($foundMessages, $search_params)
	{

		// Prepare the query for the callback!
		$request = $this->db->query('', '
		SELECT
			pm.id_pm, pm.subject, pm.id_member_from, pm.body, pm.msgtime, pm.from_name
		FROM {db_prefix}personal_messages AS pm
		WHERE pm.id_pm IN ({array_int:message_list})
		ORDER BY ' . $search_params['sort'] . ' ' . $search_params['sort_dir'] . '
		LIMIT ' . count($foundMessages),
			array(
				'message_list' => $foundMessages,
			)
		);
		$search_results = array();
		while ($row = $request->fetchAssoc())
			$search_results[] = $row;
		$request->free();

		return $search_results;
	}

	/**
	 * Get a personal message for the template. (used to save memory.)
	 *
	 * - This is a callback function that will fetch the actual results, as needed, of a previously run
	 * subject (loadPMSubjectRequest) or message (loadPMMessageRequest) query.
	 *
	 * @param string $type
	 * @param boolean $reset
	 * @return bool
	 */
	function preparePMContext_callback($type = 'subject', $reset = false)
	{
		global $txt, $scripturl, $modSettings, $settings, $context, $memberContext, $recipients, $user_info;
		global $subjects_request, $messages_request;
		static $counter = null, $temp_pm_selected = null;

		// Count the current message number....
		if ($counter === null || $reset)
		{
			$counter = $context['start'];
		}

		if ($temp_pm_selected === null)
		{
			$temp_pm_selected = isset($_SESSION['pm_selected']) ? $_SESSION['pm_selected'] : array();
			$_SESSION['pm_selected'] = array();
		}

		// If we're in non-boring view do something exciting!
		if ($context['display_mode'] != 0 && $subjects_request && $type === 'subject')
		{
			$subject = $subjects_request->fetchAssoc();
			if (!$subject)
			{
				$subjects_request->free();

				return false;
			}

			// Make sure we have a subject
			$subject['subject'] = $subject['subject'] === '' ? $txt['no_subject'] : $subject['subject'];
			$subject['subject'] = censor($subject['subject']);

			$output = array(
				'id' => $subject['id_pm'],
				'member' => array(
					'id' => $subject['id_member_from'],
					'name' => $subject['from_name'],
					'link' => $subject['not_guest'] ? '<a href="' . $scripturl . '?action=profile;u=' . $subject['id_member_from'] . '">' . $subject['from_name'] . '</a>' : $subject['from_name'],
				),
				'recipients' => &$recipients[$subject['id_pm']],
				'subject' => $subject['subject'],
				'time' => standardTime($subject['msgtime']),
				'html_time' => htmlTime($subject['msgtime']),
				'timestamp' => forum_time(true, $subject['msgtime']),
				'number_recipients' => count($recipients[$subject['id_pm']]['to']),
				'labels' => &$context['message_labels'][$subject['id_pm']],
				'fully_labeled' => count($context['message_labels'][$subject['id_pm']]) == count($context['labels']),
				'is_replied_to' => &$context['message_replied'][$subject['id_pm']],
				'is_unread' => &$context['message_unread'][$subject['id_pm']],
				'is_selected' => !empty($temp_pm_selected) && in_array($subject['id_pm'], $temp_pm_selected),
			);

			// In conversation view we need to indicate on the subject listing if any message inside of
			// that conversation is unread, not just if the latest is unread.
			if ($context['display_mode'] == 2 && isset($context['conversation_unread'][$output['id']]))
			{
				$output['is_unread'] = true;
			}

			return $output;
		}

		// Bail if it's false, ie. no messages.
		if ($messages_request == false)
		{
			return false;
		}

		// Reset the data?
		if ($reset === true)
		{
			return $messages_request->dataSeek(0);
		}

		// Get the next one... bail if anything goes wrong.
		$message = $messages_request->fetchAssoc();
		if (!$message)
		{
			if ($type != 'subject')
			{
				$messages_request->free();
			}

			return false;
		}

		// Use '(no subject)' if none was specified.
		$message['subject'] = $message['subject'] === '' ? $txt['no_subject'] : $message['subject'];

		// Load the message's information - if it's not there, load the guest information.
		if (!$GLOBALS['elk']['members.manager']->loadMemberContext($message['id_member_from'], true))
		{
			$memberContext[$message['id_member_from']]['name'] = $message['from_name'];
			$memberContext[$message['id_member_from']]['id'] = 0;

			// Sometimes the forum sends messages itself (Warnings are an example) - in this case don't label it from a guest.
			$memberContext[$message['id_member_from']]['group'] = $message['from_name'] == $context['forum_name'] ? '' : $txt['guest_title'];
			$memberContext[$message['id_member_from']]['link'] = $message['from_name'];
			$memberContext[$message['id_member_from']]['email'] = '';
			$memberContext[$message['id_member_from']]['show_email'] = showEmailAddress(true, 0);
			$memberContext[$message['id_member_from']]['is_guest'] = true;
		}
		else
		{
			$memberContext[$message['id_member_from']]['can_view_profile'] = allowedTo('profile_view_any') || ($message['id_member_from'] == $user_info['id'] && allowedTo('profile_view_own'));
			$memberContext[$message['id_member_from']]['can_see_warning'] = !isset($context['disabled_fields']['warning_status']) && $memberContext[$message['id_member_from']]['warning_status'] && ($context['user']['can_mod'] || (!empty($modSettings['warning_show']) && ($modSettings['warning_show'] > 1 || $message['id_member_from'] == $user_info['id'])));
		}

		$memberContext[$message['id_member_from']]['show_profile_buttons'] = $settings['show_profile_buttons'] && (!empty($memberContext[$message['id_member_from']]['can_view_profile']) || (!empty($memberContext[$message['id_member_from']]['website']['url']) && !isset($context['disabled_fields']['website'])) || (in_array($memberContext[$message['id_member_from']]['show_email'], array('yes', 'yes_permission_override', 'no_through_forum'))) || $context['can_send_pm']);

		// Censor all the important text...
		$message['body'] = censor($message['body']);
		$message['subject'] = censor($message['subject']);

		// Run BBC interpreter on the message.
		$bbc_parser = $GLOBALS['elk']['bbc'];
		$message['body'] = $bbc_parser->parsePM($message['body']);

		// Return the array.
		$output = array(
			'alternate' => $counter % 2,
			'id' => $message['id_pm'],
			'member' => &$memberContext[$message['id_member_from']],
			'subject' => $message['subject'],
			'time' => standardTime($message['msgtime']),
			'html_time' => htmlTime($message['msgtime']),
			'timestamp' => forum_time(true, $message['msgtime']),
			'counter' => $counter,
			'body' => $message['body'],
			'recipients' => &$recipients[$message['id_pm']],
			'number_recipients' => count($recipients[$message['id_pm']]['to']),
			'labels' => &$context['message_labels'][$message['id_pm']],
			'fully_labeled' => count($context['message_labels'][$message['id_pm']]) == count($context['labels']),
			'is_replied_to' => &$context['message_replied'][$message['id_pm']],
			'is_unread' => &$context['message_unread'][$message['id_pm']],
			'is_selected' => !empty($temp_pm_selected) && in_array($message['id_pm'], $temp_pm_selected),
			'is_message_author' => $message['id_member_from'] == $user_info['id'],
			'can_report' => !empty($modSettings['enableReportPM']),
			'can_see_ip' => allowedTo('moderate_forum') || ($message['id_member_from'] == $user_info['id'] && !empty($user_info['id'])),
		);

		$context['additional_pm_drop_buttons'] = array();

		// Can they report this message
		if (!empty($output['can_report']) && $context['folder'] !== 'sent' && $output['member']['id'] != $user_info['id'])
		{
			$context['additional_pm_drop_buttons']['warn_button'] = array(
				'href' => $scripturl . '?action=pm;sa=report;l=' . $context['current_label_id'] . ';pmsg=' . $output['id'] . ';' . $context['session_var'] . '=' . $context['session_id'],
				'text' => $txt['pm_report_to_admin']
			);
		}

		// Or mark it as unread
		if (empty($output['is_unread']) && $context['folder'] !== 'sent' && $output['member']['id'] != $user_info['id'])
		{
			$context['additional_pm_drop_buttons']['restore_button'] = array(
				'href' => $scripturl . '?action=pm;sa=markunread;l=' . $context['current_label_id'] . ';pmsg=' . $output['id'] . ';' . $context['session_var'] . '=' . $context['session_id'],
				'text' => $txt['pm_mark_unread']
			);
		}

		// Or give / take karma for a PM
		if (!empty($output['member']['karma']['allow']))
		{
			$output['member']['karma'] += array(
				'applaud_url' => $scripturl . '?action=karma;sa=applaud;uid=' . $output['member']['id'] . ';f=' . $context['folder'] . ';start=' . $context['start'] . ($context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '') . ';pm=' . $output['id'] . ';' . $context['session_var'] . '=' . $context['session_id'],
				'smite_url' => $scripturl . '?action=karma;sa=smite;uid=' . $output['member']['id'] . ';f=' . $context['folder'] . ';start=' . $context['start'] . ($context['current_label_id'] != -1 ? ';l=' . $context['current_label_id'] : '') . ';pm=' . $output['id'] . ';' . $context['session_var'] . '=' . $context['session_id'],
			);
		}

		$counter++;

		return $output;
	}
}