<?php

namespace Elkarte\Notifications;

use Elkarte\Elkarte\AbstractManager;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;

class BoardNotifications extends AbstractManager
{
	public function __construct(DatabaseInterface $db)
	{
		$this->db = $db;
	}

	/**
	 * Retrieve all the boards the user can see and their notification status:
	 *
	 * - if they're subscribed to notifications for new topics in each of them
	 * or they're not.
	 * - (used by createList() callbacks)
	 *
	 * @package Boards
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page  The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param int $memID id_member
	 * @return array
	 */
	function boardNotifications($start, $items_per_page, $sort, $memID)
	{
		global $scripturl, $user_info, $modSettings;

		// All the boards that you have notification enabled
		$notification_boards = $this->db->fetchQueryCallback('
		SELECT b.id_board, b.name, IFNULL(lb.id_msg, 0) AS board_read, b.id_msg_updated
		FROM {db_prefix}log_notify AS ln
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = ln.id_board)
			LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = b.id_board AND lb.id_member = {int:current_member})
		WHERE ln.id_member = {int:selected_member}
			AND {query_see_board}
		ORDER BY ' . $sort,
			array(
				'current_member' => $user_info['id'],
				'selected_member' => $memID,
			),
			function($row) use ($scripturl)
			{
				return array(
					'id' => $row['id_board'],
					'name' => $row['name'],
					'href' => $scripturl . '?board=' . $row['id_board'] . '.0',
					'link' => '<a href="' . $scripturl . '?board=' . $row['id_board'] . '.0"><strong>' . $row['name'] . '</strong></a>',
					'new' => $row['board_read'] < $row['id_msg_updated'],
					'checked' => 'checked="checked"',
				);
			}
		);

		// and all the boards that you can see but don't have notify turned on for
		$request = $this->db->query('', '
		SELECT b.id_board, b.name, IFNULL(lb.id_msg, 0) AS board_read, b.id_msg_updated
		FROM {db_prefix}boards AS b
			LEFT JOIN {db_prefix}log_notify AS ln ON (ln.id_board = b.id_board AND ln.id_member = {int:selected_member})
			LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = b.id_board AND lb.id_member = {int:current_member})
		WHERE {query_see_board}
			AND ln.id_board is null ' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
			AND b.id_board != {int:recycle_board}' : '') . '
		ORDER BY ' . $sort,
			array(
				'selected_member' => $memID,
				'current_member' => $user_info['id'],
				'recycle_board' => $modSettings['recycle_board'],
			)
		);
		while ($row = $request->fetchAssoc())
			$notification_boards[] = array(
				'id' => $row['id_board'],
				'name' => $row['name'],
				'href' => $scripturl . '?board=' . $row['id_board'] . '.0',
				'link' => '<a href="' . $scripturl . '?board=' . $row['id_board'] . '.0">' . $row['name'] . '</a>',
				'new' => $row['board_read'] < $row['id_msg_updated'],
				'checked' => '',
			);
		$request->free();

		return $notification_boards;
	}

	/**
	 * Notifies members who have requested notification for new topics posted on a board of said posts.
	 *
	 * receives data on the topics to send out notifications to by the passed in array.
	 * only sends notifications to those who can *currently* see the topic (it doesn't matter if they could when they requested notification.)
	 * loads the Post language file multiple times for each language if the userLanguage setting is set.
	 *
	 * @param mixed[] $topicData
	 */
	function sendBoardNotifications(&$topicData)
	{
		global $scripturl, $language, $user_info, $modSettings, $webmaster_email;

		$db = $GLOBALS['elk']['db'];

		require_once(ROOTDIR . '/Mail/Mail.subs.php');
		require_once(ROOTDIR . '/Mail/Emailpost.subs.php');

		// Do we have one or lots of topics?
		if (isset($topicData['body']))
			$topicData = array($topicData);

		// Using the post to email functions?
		$maillist = !empty($modSettings['maillist_enabled']) && !empty($modSettings['pbe_post_enabled']);

		// Find out what boards we have... and clear out any rubbish!
		$boards = array();
		foreach ($topicData as $key => $topic)
		{
			if (!empty($topic['board']))
				$boards[$topic['board']][] = $key;
			else
			{
				unset($topic[$key]);
				continue;
			}

			// Convert to markdown markup e.g. styled plain text, while doing the censoring
			pbe_prepare_text($topicData[$key]['body'], $topicData[$key]['subject'], $topicData[$key]['signature']);
		}

		// Just the board numbers.
		$board_index = array_unique(array_keys($boards));
		if (empty($board_index))
			return;

		// Load the actual board names

		$board_names = fetchBoardsInfo(array('boards' => $board_index, 'override_permissions' => true));

		// Yea, we need to add this to the digest queue.
		$digest_insert = array();
		foreach ($topicData as $id => $data)
			$digest_insert[] = array($data['topic'], $data['msg'], 'topic', $user_info['id']);
		$db->insert('',
			'{db_prefix}log_digest',
			array(
				'id_topic' => 'int', 'id_msg' => 'int', 'note_type' => 'string', 'exclude' => 'int',
			),
			$digest_insert,
			array()
		);

		// Find the members with notification on for these boards.
		$members = $db->query('', '
		SELECT
			mem.id_member, mem.email_address, mem.notify_regularity, mem.notify_send_body, mem.lngfile, mem.warning,
			ln.sent, ln.id_board, mem.id_group, mem.additional_groups, b.member_groups, b.id_profile,
			mem.id_post_group
		FROM {db_prefix}log_notify AS ln
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = ln.id_board)
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = ln.id_member)
		WHERE ln.id_board IN ({array_int:board_list})
			AND mem.id_member != {int:current_member}
			AND mem.is_activated = {int:is_activated}
			AND mem.notify_types != {int:notify_types}
			AND mem.notify_regularity < {int:notify_regularity}
		ORDER BY mem.lngfile',
			array(
				'current_member' => $user_info['id'],
				'board_list' => $board_index,
				'is_activated' => 1,
				'notify_types' => 4,
				'notify_regularity' => 2,
			)
		);
		// While we have members with board notifications
		while ($rowmember = $db->fetch_assoc($members))
		{
			$email_perm = true;
			if (validateNotificationAccess($rowmember, $maillist, $email_perm) === false)
				continue;

			$langloaded = loadLanguage('index', empty($rowmember['lngfile']) || empty($modSettings['userLanguage']) ? $language : $rowmember['lngfile'], false);

			// Now loop through all the notifications to send for this board.
			if (empty($boards[$rowmember['id_board']]))
				continue;

			$sentOnceAlready = 0;

			// For each message we need to send (from this board to this member)
			foreach ($boards[$rowmember['id_board']] as $key)
			{
				// Don't notify the guy who started the topic!
				// @todo In this case actually send them a "it's approved hooray" email :P
				if ($topicData[$key]['poster'] == $rowmember['id_member'])
					continue;

				// Setup the string for adding the body to the message, if a user wants it.
				$send_body = $maillist || (empty($modSettings['disallow_sendBody']) && !empty($rowmember['notify_send_body']));

				$replacements = array(
					'TOPICSUBJECT' => $topicData[$key]['subject'],
					'POSTERNAME' => un_htmlspecialchars($topicData[$key]['name']),
					'TOPICLINK' => $scripturl . '?topic=' . $topicData[$key]['topic'] . '.new#new',
					'TOPICLINKNEW' => $scripturl . '?topic=' . $topicData[$key]['topic'] . '.new#new',
					'MESSAGE' => $send_body ? $topicData[$key]['body'] : '',
					'UNSUBSCRIBELINK' => $scripturl . '?action=notifyboard;board=' . $topicData[$key]['board'] . '.0',
					'SIGNATURE' => !empty($topicData[$key]['signature']) ? $topicData[$key]['signature'] : '',
					'BOARDNAME' => $board_names[$topicData[$key]['board']]['name'],
				);

				// Figure out which email to send
				$emailtype = '';

				// Send only if once is off or it's on and it hasn't been sent.
				if (!empty($rowmember['notify_regularity']) && !$sentOnceAlready && empty($rowmember['sent']))
					$emailtype = 'notify_boards_once';
				elseif (empty($rowmember['notify_regularity']))
					$emailtype = 'notify_boards';

				if (!empty($emailtype))
				{
					$emailtype .= $send_body ? '_body' : '';
					$emaildata = loadEmailTemplate((($maillist && $email_perm && $send_body) ? 'pbe_' : '') . $emailtype, $replacements, $langloaded);
					$emailname = (!empty($topicData[$key]['name'])) ? un_htmlspecialchars($topicData[$key]['name']) : null;

					// Maillist style?
					if ($maillist && $email_perm && $send_body)
					{
						// Add in the from wrapper and trigger sendmail to add in a security key
						$from_wrapper = !empty($modSettings['maillist_mail_from']) ? $modSettings['maillist_mail_from'] : (empty($modSettings['maillist_sitename_address']) ? $webmaster_email : $modSettings['maillist_sitename_address']);
						sendmail($rowmember['email_address'], $emaildata['subject'], $emaildata['body'], $emailname, 't' . $topicData[$key]['topic'], false, 3, null, false, $from_wrapper, $topicData[$key]['topic']);
					}
					else
						sendmail($rowmember['email_address'], $emaildata['subject'], $emaildata['body'], null, null, false, 3);
				}

				$sentOnceAlready = 1;
			}
		}
		$db->free_result($members);

		loadLanguage('index', $user_info['language']);

		// Sent!
		$db->query('', '
		UPDATE {db_prefix}log_notify
		SET sent = {int:is_sent}
		WHERE id_board IN ({array_int:board_list})
			AND id_member != {int:current_member}',
			array(
				'current_member' => $user_info['id'],
				'board_list' => $board_index,
				'is_sent' => 1,
			)
		);
	}


	/**
	 * Returns whether this member has notification turned on for the specified board.
	 *
	 * @param int $id_member the member id
	 * @param int $id_board the board to check
	 * @return bool if they have notifications turned on for the board
	 */
	function hasBoardNotification($id_member, $id_board)
	{
		// Find out if they have notification set for this board already.
		$request = $this->db->query('', '
		SELECT id_member
		FROM {db_prefix}log_notify
		WHERE id_member = {int:current_member}
			AND id_board = {int:current_board}
		LIMIT 1',
			array(
				'current_board' => $id_board,
				'current_member' => $id_member,
			)
		);
		$hasNotification = $request->numRows() != 0;
		$request->free();

		return $hasNotification;
	}

	/**
	 * Set board notification on or off for the given member.
	 *
	 * @package Boards
	 * @param int $id_member
	 * @param int $id_board
	 * @param bool $on = false
	 */
	function setBoardNotification($id_member, $id_board, $on = false)
	{
		if ($on)
		{
			// Turn notification on.  (note this just blows smoke if it's already on.)
			$this->db->insert('ignore',
				'{db_prefix}log_notify',
				array('id_member' => 'int', 'id_board' => 'int'),
				array($id_member, $id_board),
				array('id_member', 'id_board')
			);
		}
		else
		{
			// Turn notification off for this board.
			$this->db->query('', '
			DELETE FROM {db_prefix}log_notify
			WHERE id_member = {int:current_member}
				AND id_board = {int:current_board}',
				array(
					'current_board' => $id_board,
					'current_member' => $id_member,
				)
			);
		}
	}

	/**
	 * Reset sent status for board notifications.
	 *
	 * This function returns a boolean equivalent with hasBoardNotification().
	 * This is unexpected, but it's done this way to avoid any extra-query is executed on MessageIndex::action_messageindex().
	 * Just ignore the return value for normal use.
	 *
	 * @package Boards
	 * @param int $id_member
	 * @param int $id_board
	 * @param bool $check = true check if the user has notifications enabled for the board
	 * @return bool if the board was marked for notifications
	 */
	function resetSentBoardNotification($id_member, $id_board, $check = true)
	{
		// Check if notifications are enabled for this user on the board?
		if ($check)
		{
			// check if the member has notifications enabled for this board
			$request = $this->db->query('', '
			SELECT sent
			FROM {db_prefix}log_notify
			WHERE id_board = {int:current_board}
				AND id_member = {int:current_member}
			LIMIT 1',
				array(
					'current_board' => $id_board,
					'current_member' => $id_member,
				)
			);
			// nothing to do
			if ($request->numRows() == 0)
				return false;
			$sent = $request->fetchRow();
			$request->free();

			// not sent already? No need to stay around then
			if (empty($sent))
				return true;
		}

		// Reset 'sent' status.
		$this->db->query('', '
		UPDATE {db_prefix}log_notify
		SET sent = {int:is_sent}
		WHERE id_board = {int:current_board}
			AND id_member = {int:current_member}',
			array(
				'current_board' => $id_board,
				'current_member' => $id_member,
				'is_sent' => 0,
			)
		);
		return true;
	}

	/**
	 * Counts the board notification for a given member.
	 *
	 * @package Boards
	 * @param int $memID
	 * @return int
	 */
	function getBoardNotificationsCount($memID)
	{
		global $user_info;


		// All the boards that you have notification enabled
		$request = $this->db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_notify AS ln
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = ln.id_board)
			LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = b.id_board AND lb.id_member = {int:current_member})
		WHERE ln.id_member = {int:selected_member}
			AND {query_see_board}',
			array(
				'current_member' => $user_info['id'],
				'selected_member' => $memID,
			)
		);
		list ($totalNotifications) = $request->fetchRow();
		$request->free();

		return $totalNotifications;
	}
}