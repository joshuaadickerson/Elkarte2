<?php

/**
 * Moderation helper functions.
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

namespace Elkarte\Members;

class Moderator
{
	/**
	 * How many open reports do we have?
	 *  - if flush is true will clear the moderator menu count
	 *  - returns the number of open reports
	 *  - sets $context['open_mod_reports'] for template use
	 *
	 * @param boolean $flush = true if moderator menu count will be cleared
	 * @return int
	 */
	function recountOpenReports($flush = true, $count_pms = false)
	{
		global $user_info, $context;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}log_reported
			WHERE ' . $user_info['mod_cache']['bq'] . '
				AND type IN ({array_string:rep_type})
				AND closed = {int:not_closed}
				AND ignore_all = {int:not_ignored}',
			array(
				'not_closed' => 0,
				'not_ignored' => 0,
				'rep_type' => $count_pms ? array('pm') : array('msg'),
			)
		);
		list ($open_reports) = $request->fetchRow();
		$request->free();

		$_SESSION['rc'] = array(
			'id' => $user_info['id'],
			'time' => time(),
			'reports' => (int) $open_reports,
		);

		$context['open_mod_reports'] = $open_reports;
		if ($flush)
			$GLOBALS['elk']['cache']->remove('num_menu_errors');

		return (int) $open_reports;
	}

	/**
	 * How many unapproved posts and topics do we have?
	 *  - Sets $context['total_unapproved_topics']
	 *  - Sets $context['total_unapproved_posts']
	 *  - approve_query is set to list of boards they can see
	 *
	 * @param string|null $approve_query
	 * @return array of values
	 */
	function recountUnapprovedPosts($approve_query = null)
	{
		global $context;

		$db = $GLOBALS['elk']['db'];

		if ($approve_query === null)
			return array('posts' => 0, 'topics' => 0);

		// Any unapproved posts?
		$request = $db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic AND t.id_first_msg != m.id_msg)
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
			WHERE m.approved = {int:not_approved}
				AND {query_see_board}
				' . $approve_query,
			array(
				'not_approved' => 0,
			)
		);
		list ($unapproved_posts) = $request->fetchRow();
		$request->free();

		// What about topics?
		$request = $db->query('', '
			SELECT COUNT(m.id_topic)
			FROM {db_prefix}topics AS m
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			WHERE m.approved = {int:not_approved}
				AND {query_see_board}
				' . $approve_query,
			array(
				'not_approved' => 0,
			)
		);
		list ($unapproved_topics) = $request->fetchRow();
		$request->free();

		$context['total_unapproved_topics'] = $unapproved_topics;
		$context['total_unapproved_posts'] = $unapproved_posts;
		return array('posts' => $unapproved_posts, 'topics' => $unapproved_topics);
	}

	/**
	 * How many failed emails (that they can see) do we have?
	 *
	 * @param string|null $approve_query
	 * @return int
	 */
	function recountFailedEmails($approve_query = null)
	{
		global $context;

		$db = $GLOBALS['elk']['db'];

		if ($approve_query === null)
			return 0;

		$request = $db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}postby_emails_error AS m
				LEFT JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			WHERE {query_see_board}
				' . $approve_query . '
				OR m.id_board = -1',
			array()
		);
		list ($failed_emails) = $request->fetchRow();
		$request->free();

		$context['failed_emails'] = $failed_emails;
		return $failed_emails;
	}

	/**
	 * How many entries are we viewing?
	 *
	 * @param int $status
	 */
	function totalReports($status = 0, $show_pms = false)
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}log_reported AS lr
			WHERE lr.closed = {int:view_closed}
				AND lr.type IN ({array_string:type})
				AND ' . ($user_info['mod_cache']['bq'] == '1=1' || $user_info['mod_cache']['bq'] == '0=1' ? $user_info['mod_cache']['bq'] : 'lr.' . $user_info['mod_cache']['bq']),
			array(
				'view_closed' => $status,
				'type' => $show_pms ? array('pm') : array('msg'),
			)
		);
		list ($total_reports) = $request->fetchRow();
		$request->free();

		return $total_reports;
	}

	/**
	 * Changes a property of all the reports passed (and the user can see)
	 *
	 * @param int[]|int $reports_id an array of report IDs
	 * @param string $property the property to update ('close' or 'ignore')
	 * @param int $status the status of the property (mainly: 0 or 1)
	 */
	function updateReportsStatus($reports_id, $property = 'close', $status = 0)
	{
		global $user_info;

		if (empty($reports_id))
			return;

		$db = $GLOBALS['elk']['db'];

		$reports_id = is_array($reports_id) ? $reports_id : array($reports_id);

		$db->query('', '
			UPDATE {db_prefix}log_reported
			SET ' . ($property == 'close' ? 'closed' : 'ignore_all') . '= {int:status}
			WHERE id_report IN ({array_int:report_list})
				AND ' . $user_info['mod_cache']['bq'],
			array(
				'report_list' => $reports_id,
				'status' => $status,
			)
		);

		return $db->affected_rows();
	}

	/**
	 * Loads the number of items awaiting moderation attention
	 *  - Only loads the value a given permission level can see
	 *  - If supplied a board number will load the values only for that board
	 *  - Unapproved posts
	 *  - Unapproved topics
	 *  - Unapproved attachments
	 *  - Failed emails
	 *  - Reported posts
	 *  - Members awaiting approval (activation, deletion, group requests)
	 *
	 * @param int|null $brd
	 */
	function loadModeratorMenuCounts($brd = null)
	{
		global $modSettings, $user_info;

		static $menu_errors = array();

		// Work out what boards they can work in!
		$approve_boards = !empty($user_info['mod_cache']['ap']) ? $user_info['mod_cache']['ap'] : boardsAllowedTo('approve_posts');

		// Supplied a specific board to check?
		if (!empty($brd)) {
			$filter_board = array((int)$brd);
			$approve_boards = $approve_boards == array(0) ? $filter_board : array_intersect($approve_boards, $filter_board);
		}

		// Work out the query
		if ($approve_boards == array(0))
			$approve_query = '';
		elseif (!empty($approve_boards))
			$approve_query = ' AND m.id_board IN (' . implode(',', $approve_boards) . ')';
		else
			$approve_query = ' AND 1=0';

		// Set up the cache key for this permissions level
		$cache_key = md5($user_info['query_see_board'] . $approve_query . $user_info['mod_cache']['bq'] . $user_info['mod_cache']['gq'] . $user_info['mod_cache']['mq'] . (int)allowedTo('approve_emails') . '_' . (int)allowedTo('moderate_forum'));

		if (isset($menu_errors[$cache_key]))
			return $menu_errors[$cache_key];

		// If its been cached, guess what, thats right use it!
		$temp = $GLOBALS['elk']['cache']->get('num_menu_errors', 900);
		if ($temp === null || !isset($temp[$cache_key])) {
			// Starting out with nothing is a good start
			$menu_errors[$cache_key] = array(
				'memberreq' => 0,
				'groupreq' => 0,
				'attachments' => 0,
				'reports' => 0,
				'emailmod' => 0,
				'postmod' => 0,
				'topics' => 0,
				'posts' => 0,
			);

			if ($modSettings['postmod_active'] && !empty($approve_boards)) {
				$totals = recountUnapprovedPosts($approve_query);
				$menu_errors[$cache_key]['posts'] = $totals['posts'];
				$menu_errors[$cache_key]['topics'] = $totals['topics'];

				// Totals for the menu item unapproved posts and topics
				$menu_errors[$cache_key]['postmod'] = $menu_errors[$cache_key]['topics'] + $menu_errors[$cache_key]['posts'];
			}

			// Attachments
			if ($modSettings['postmod_active'] && !empty($approve_boards)) {

				$menu_errors[$cache_key]['attachments'] = list_getNumUnapprovedAttachments($approve_query);
			}

			// Reported posts
			if (!empty($user_info['mod_cache']) && $user_info['mod_cache']['bq'] != '0=1')
				$menu_errors[$cache_key]['reports'] = $this->recountOpenReports(false, allowedTo('admin_forum'));

			// Email failures that require attention
			if (!empty($modSettings['maillist_enabled']) && allowedTo('approve_emails'))
				$menu_errors[$cache_key]['emailmod'] = $this->recountFailedEmails($approve_query);

			// Group requests
			if (!empty($user_info['mod_cache']) && $user_info['mod_cache']['gq'] != '0=1')
				$menu_errors[$cache_key]['groupreq'] = count($GLOBALS['elk']['groups.manager']->groupRequests());

			// Member requests
			if (allowedTo('moderate_forum') && ((!empty($modSettings['registration_method']) && $modSettings['registration_method'] == 2) || !empty($modSettings['approveAccountDeletion']))) {
				$awaiting_activation = 0;
				$activation_numbers = $this->countInactiveMembers();

				// 5 = COPPA, 4 = Awaiting Deletion, 3 = Awaiting Approval
				foreach ($activation_numbers as $activation_type => $total_members) {
					if (in_array($activation_type, array(3, 4, 5)))
						$awaiting_activation += $total_members;
				}
				$menu_errors[$cache_key]['memberreq'] = $awaiting_activation;
			}

			// Grand Totals for the top most menus
			$menu_errors[$cache_key]['pt_total'] = $menu_errors[$cache_key]['emailmod'] + $menu_errors[$cache_key]['postmod'] + $menu_errors[$cache_key]['reports'] + $menu_errors[$cache_key]['attachments'];
			$menu_errors[$cache_key]['mg_total'] = $menu_errors[$cache_key]['memberreq'] + $menu_errors[$cache_key]['groupreq'];
			$menu_errors[$cache_key]['grand_total'] = $menu_errors[$cache_key]['pt_total'] + $menu_errors[$cache_key]['mg_total'];

			// Add this key in to the array, technically this resets the cache time for all keys
			// done this way as the entire thing needs to go null once *any* moderation action is taken
			$menu_errors = is_array($temp) ? array_merge($temp, $menu_errors) : $menu_errors;

			// Store it away for a while, not like this should change that often
			$GLOBALS['elk']['cache']->put('num_menu_errors', $menu_errors, 900);
		} else
			$menu_errors = $temp === null ? array() : $temp;

		return $menu_errors[$cache_key];
	}

	/**
	 * Get the report details, need this so we can limit access to a particular board
	 *  - returns false if they are requesting a report they can not see or does not exist
	 *
	 * @param int $id_report
	 * @return false|string[]
	 */
	function modReportDetails($id_report, $show_pms = false)
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT lr.id_report, lr.id_msg, lr.id_topic, lr.id_board, lr.id_member, lr.subject, lr.body,
				lr.time_started, lr.time_updated, lr.num_reports, lr.closed, lr.ignore_all,
				IFNULL(mem.real_name, lr.membername) AS author_name, IFNULL(mem.id_member, 0) AS id_author
			FROM {db_prefix}log_reported AS lr
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lr.id_member)
			WHERE lr.id_report = {int:id_report}
				AND lr.type IN ({array_string:rep_type})
				AND ' . ($user_info['mod_cache']['bq'] == '1=1' || $user_info['mod_cache']['bq'] == '0=1' ? $user_info['mod_cache']['bq'] : 'lr.' . $user_info['mod_cache']['bq']) . '
			LIMIT 1',
			array(
				'id_report' => $id_report,
				'rep_type' => $show_pms ? array('pm') : array('msg'),
			)
		);

		// So did we find anything?
		if (!$request->numRows())
			$row = false;
		else
			$row = $request->fetchAssoc();

		$request->free();

		return $row;
	}

	/**
	 * Get the details for a bunch of open/closed reports
	 *
	 * @param int $status 0 => show open reports, 1 => closed reports
	 * @param int $start starting point
	 * @param int $limit the number of reports
	 *
	 * @todo move to createList?
	 * @return array
	 */
	function getModReports($status = 0, $start = 0, $limit = 10, $show_pms = false)
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
				SELECT lr.id_report, lr.id_msg, lr.id_topic, lr.id_board, lr.id_member, lr.subject, lr.body,
					lr.time_started, lr.time_updated, lr.num_reports, lr.closed, lr.ignore_all,
					IFNULL(mem.real_name, lr.membername) AS author_name, IFNULL(mem.id_member, 0) AS id_author
				FROM {db_prefix}log_reported AS lr
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lr.id_member)
				WHERE lr.closed = {int:view_closed}
					AND lr.type IN ({array_string:rep_type})
					AND ' . ($user_info['mod_cache']['bq'] == '1=1' || $user_info['mod_cache']['bq'] == '0=1' ? $user_info['mod_cache']['bq'] : 'lr.' . $user_info['mod_cache']['bq']) . '
				ORDER BY lr.time_updated DESC
				LIMIT {int:start}, {int:limit}',
			array(
				'view_closed' => $status,
				'start' => $start,
				'limit' => $limit,
				'rep_type' => $show_pms ? array('pm') : array('msg'),
			)
		);

		$reports = array();
		while ($row = $request->fetchAssoc())
			$reports[$row['id_report']] = $row;
		$request->free();

		return $reports;
	}

	/**
	 * Grabs all the comments made by the reporters to a set of reports
	 *
	 * @param int[] $id_reports an array of report ids
	 * @return array
	 */
	function getReportsUserComments($id_reports)
	{
		$db = $GLOBALS['elk']['db'];

		$id_reports = is_array($id_reports) ? $id_reports : array($id_reports);

		$request = $db->query('', '
			SELECT lrc.id_comment, lrc.id_report, lrc.time_sent, lrc.comment, lrc.member_ip,
				IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, lrc.membername) AS reporter
			FROM {db_prefix}log_reported_comments AS lrc
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lrc.id_member)
			WHERE lrc.id_report IN ({array_int:report_list})',
			array(
				'report_list' => $id_reports,
			)
		);

		$comments = array();
		while ($row = $request->fetchAssoc())
			$comments[$row['id_report']][] = $row;

		$request->free();

		return $comments;
	}

	/**
	 * Retrieve all the comments made by the moderators to a certain report
	 *
	 * @param int $id_report the id of a report
	 * @return array
	 */
	function getReportModeratorsComments($id_report)
	{
		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
				SELECT lc.id_comment, lc.id_notice, lc.log_time, lc.body,
					IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, lc.member_name) AS moderator
				FROM {db_prefix}log_comments AS lc
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
				WHERE lc.id_notice = {int:id_report}
					AND lc.comment_type = {string:reportc}',
			array(
				'id_report' => $id_report,
				'reportc' => 'reportc',
			)
		);

		$comments = array();
		while ($row = $request->fetchAssoc())
			$comments[] = $row;

		$request->free();

		return $comments;
	}

	/**
	 * This is a helper function: approve everything unapproved.
	 * Used from moderation panel.
	 */
	function approveAllUnapproved()
	{
		$db = $GLOBALS['elk']['db'];

		// Start with messages and topics.
		$request = $db->query('', '
			SELECT id_msg
			FROM {db_prefix}messages
			WHERE approved = {int:not_approved}',
			array(
				'not_approved' => 0,
			)
		);
		$msgs = array();
		while ($row = $request->fetchRow())
			$msgs[] = $row[0];
		$request->free();

		if (!empty($msgs)) {

			approvePosts($msgs);
			$GLOBALS['elk']['cache']->remove('num_menu_errors');
		}

		// Now do attachments
		$request = $db->query('', '
			SELECT id_attach
			FROM {db_prefix}attachments
			WHERE approved = {int:not_approved}',
			array(
				'not_approved' => 0,
			)
		);
		$attaches = array();
		while ($row = $request->fetchRow())
			$attaches[] = $row[0];
		$request->free();

		if (!empty($attaches)) {
			approveAttachments($attaches);
			$GLOBALS['elk']['cache']->remove('num_menu_errors');
		}
	}

	/**
	 * Returns the most recent reported posts as array
	 *
	 * @return array
	 */
	function reportedPosts($show_pms = false)
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		// Got the info already?
		$cachekey = md5(serialize($user_info['mod_cache']['bq']));

		$reported_posts = null;
		if (!$GLOBALS['elk']['cache']->getVar($reported_posts, 'reported_posts_' . $cachekey, 90)) {
			// By George, that means we in a position to get the reports, jolly good.
			$request = $db->query('', '
				SELECT lr.id_report, lr.id_msg, lr.id_topic, lr.id_board, lr.id_member, lr.subject,
					lr.num_reports, IFNULL(mem.real_name, lr.membername) AS author_name,
					IFNULL(mem.id_member, 0) AS id_author
				FROM {db_prefix}log_reported AS lr
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lr.id_member)
				WHERE ' . ($user_info['mod_cache']['bq'] == '1=1' || $user_info['mod_cache']['bq'] == '0=1' ? $user_info['mod_cache']['bq'] : 'lr.' . $user_info['mod_cache']['bq']) . '
					AND lr.closed = {int:not_closed}
					AND lr.type IN ({array_string:rep_type})
					AND lr.ignore_all = {int:not_ignored}
				ORDER BY lr.time_updated DESC
				LIMIT 10',
				array(
					'not_closed' => 0,
					'not_ignored' => 0,
					'rep_type' => $show_pms ? array('pm') : array('msg'),
				)
			);
			$reported_posts = array();
			while ($row = $request->fetchAssoc())
				$reported_posts[] = $row;
			$request->free();

			// Cache it.
			$GLOBALS['elk']['cache']->put('reported_posts_' . $cachekey, $reported_posts, 90);
		}

		return $reported_posts;
	}

	/**
	 * Remove a moderator note.
	 *
	 * @param int $id_note
	 */
	function removeModeratorNote($id_note)
	{
		$db = $GLOBALS['elk']['db'];

		// Lets delete it.
		$db->query('', '
			DELETE FROM {db_prefix}log_comments
			WHERE id_comment = {int:note}
				AND comment_type = {string:type}',
			array(
				'note' => $id_note,
				'type' => 'modnote',
			)
		);
	}

	/**
	 * Get the number of moderator notes stored on the site.
	 *
	 * @return int
	 */
	function countModeratorNotes()
	{
		$db = $GLOBALS['elk']['db'];

		$moderator_notes_total = null;
		if (!$GLOBALS['elk']['cache']->getVar($moderator_notes_total, 'moderator_notes_total', 240)) {
			$request = $db->query('', '
				SELECT COUNT(*)
				FROM {db_prefix}log_comments AS lc
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
				WHERE lc.comment_type = {string:modnote}',
				array(
					'modnote' => 'modnote',
				)
			);
			list ($moderator_notes_total) = $request->fetchRow();
			$request->free();

			$GLOBALS['elk']['cache']->put('moderator_notes_total', $moderator_notes_total, 240);
		}

		return $moderator_notes_total;
	}

	/**
	 * Adds a moderation note to the moderation center "shoutbox"
	 *
	 * @param int $id_poster who is posting the add
	 * @param string $poster_name a name to show
	 * @param string $contents what they are posting
	 */
	function addModeratorNote($id_poster, $poster_name, $contents)
	{
		$db = $GLOBALS['elk']['db'];

		// Insert it into the database
		$db->insert('',
			'{db_prefix}log_comments',
			array(
				'id_member' => 'int', 'member_name' => 'string', 'comment_type' => 'string', 'recipient_name' => 'string',
				'body' => 'string', 'log_time' => 'int',
			),
			array(
				$id_poster, $poster_name, 'modnote', '', $contents, time(),
			),
			array('id_comment')
		);
	}

	/**
	 * Add a moderation comment to an actual moderation report
	 *
	 * @global int $user_info
	 * @param int $report
	 * @param string $newComment
	 */
	function addReportComment($report, $newComment)
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		// Insert it into the database
		$db->insert('',
			'{db_prefix}log_comments',
			array(
				'id_member' => 'int', 'member_name' => 'string', 'comment_type' => 'string', 'recipient_name' => 'string',
				'id_notice' => 'int', 'body' => 'string', 'log_time' => 'int',
			),
			array(
				$user_info['id'], $user_info['name'], 'reportc', '',
				$report, $newComment, time(),
			),
			array('id_comment')
		);
	}

	/**
	 * Get the list of current notes in the moderation center "shoutbox"
	 *
	 * @param int $offset
	 * @return array
	 */
	function moderatorNotes($offset)
	{
		$db = $GLOBALS['elk']['db'];

		// Grab the current notes.
		// We can only use the cache for the first page of notes.
		$moderator_notes = null;
		if ($offset != 0 || !$GLOBALS['elk']['cache']->getVar($moderator_notes, 'moderator_notes', 240))
		{
			$request = $db->query('', '
				SELECT IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, lc.member_name) AS member_name,
					lc.log_time, lc.body, lc.id_comment AS id_note
				FROM {db_prefix}log_comments AS lc
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
				WHERE lc.comment_type = {string:modnote}
				ORDER BY id_comment DESC
				LIMIT {int:offset}, 10',
				array(
					'modnote' => 'modnote',
					'offset' => $offset,
				)
			);
			$moderator_notes = array();
			while ($row = $request->fetchAssoc())
				$moderator_notes[] = $row;
			$request->free();

			if ($offset == 0)
				$GLOBALS['elk']['cache']->put('moderator_notes', $moderator_notes, 240);
		}

		return $moderator_notes;
	}

	/**
	 * Gets a warning notice by id that was sent to a user.
	 *
	 * @param int $id_notice
	 * @return array
	 */
	function moderatorNotice($id_notice)
	{
		$db = $GLOBALS['elk']['db'];

		// Get the body and subject of this notice
		$request = $db->query('', '
			SELECT body, subject
			FROM {db_prefix}log_member_notices
			WHERE id_notice = {int:id_notice}',
			array(
				'id_notice' => $id_notice,
			)
		);
		if ($request->numRows() == 0)
			return array();
		list ($notice_body, $notice_subject) = $request->fetchRow();
		$request->free();

		// Make it look nice
		$bbc_parser = $GLOBALS['elk']['bbc'];
		$notice_body = $bbc_parser->parseNotice($notice_body);

		return array($notice_body, $notice_subject);
	}

	/**
	 * Make sure the "current user" (uses $user_info) cannot go outside of the limit for the day.
	 *
	 * @param int $member The member we are going to issue the warning to
	 */
	function warningDailyLimit($member)
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT SUM(counter)
			FROM {db_prefix}log_comments
			WHERE id_recipient = {int:selected_member}
				AND id_member = {int:current_member}
				AND comment_type = {string:warning}
				AND log_time > {int:day_time_period}',
			array(
				'current_member' => $user_info['id'],
				'selected_member' => $member,
				'day_time_period' => time() - 86400,
				'warning' => 'warning',
			)
		);
		list ($current_applied) = $request->fetchRow();
		$request->free();

		return $current_applied;
	}

	/**
	 * Make sure the "current user" (uses $user_info) cannot go outside of the limit for the day.
	 *
	 * @param string $approve_query additional condition for the query
	 * @param string $current_view defined whether return the topics (first
	 *                messages) or the messages. If set to 'topics' it returns
	 *                the topics, otherwise the messages
	 * @param mixed[] $boards_allowed array of arrays, it must contain three
	 *                 indexes:
	 *                  - delete_own_boards
	 *                  - delete_any_boards
	 *                  - delete_own_replies
	 *                 each of which must be an array of boards the user is allowed
	 *                 to perform a certain action (return of boardsAllowedTo)
	 * @param int $start start of the query LIMIT
	 * @param int $limit number of elements to return (default 10)
	 */
	function getUnapprovedPosts($approve_query, $current_view, $boards_allowed, $start, $limit = 10)
	{
		global $context, $scripturl, $user_info;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT m.id_msg, m.id_topic, m.id_board, m.subject, m.body, m.id_member,
				IFNULL(mem.real_name, m.poster_name) AS poster_name, m.poster_time, m.smileys_enabled,
				t.id_member_started, t.id_first_msg, b.name AS board_name, c.id_cat, c.name AS cat_name
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
				LEFT JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
			WHERE m.approved = {int:not_approved}
				AND t.id_first_msg ' . ($current_view == 'topics' ? '=' : '!=') . ' m.id_msg
				AND {query_see_board}
				' . $approve_query . '
			LIMIT {int:start}, {int:limit}',
			array(
				'start' => $start,
				'limit' => $limit,
				'not_approved' => 0,
			)
		);

		$unapproved_items = array();
		$bbc_parser = $GLOBALS['elk']['bbc'];

		for ($i = 1; $row = $request->fetchAssoc(); $i++) {
			// Can delete is complicated, let's solve it first... is it their own post?
			if ($row['id_member'] == $user_info['id'] && ($boards_allowed['delete_own_boards'] == array(0) || in_array($row['id_board'], $boards_allowed['delete_own_boards'])))
				$can_delete = true;
			// Is it a reply to their own topic?
			elseif ($row['id_member'] == $row['id_member_started'] && $row['id_msg'] != $row['id_first_msg'] && ($boards_allowed['delete_own_replies'] == array(0) || in_array($row['id_board'], $boards_allowed['delete_own_replies'])))
				$can_delete = true;
			// Someone elses?
			elseif ($row['id_member'] != $user_info['id'] && ($boards_allowed['delete_any_boards'] == array(0) || in_array($row['id_board'], $boards_allowed['delete_any_boards'])))
				$can_delete = true;
			else
				$can_delete = false;

			$unapproved_items[] = array(
				'id' => $row['id_msg'],
				'alternate' => $i % 2,
				'counter' => $context['start'] + $i,
				'href' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg'],
				'link' => '<a href="' . $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['id_msg'] . '#msg' . $row['id_msg'] . '">' . $row['subject'] . '</a>',
				'subject' => $row['subject'],
				'body' => $bbc_parser->parseMessage($row['body'], $row['smileys_enabled']),
				'time' => standardTime($row['poster_time']),
				'html_time' => htmlTime($row['poster_time']),
				'timestamp' => forum_time(true, $row['poster_time']),
				'poster' => array(
					'id' => $row['id_member'],
					'name' => $row['poster_name'],
					'link' => $row['id_member'] ? '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['poster_name'] . '</a>' : $row['poster_name'],
					'href' => $scripturl . '?action=profile;u=' . $row['id_member'],
				),
				'topic' => array(
					'id' => $row['id_topic'],
				),
				'board' => array(
					'id' => $row['id_board'],
					'name' => $row['board_name'],
					'link' => '<a href="' . $scripturl . '?board=' . $row['id_board'] . '.0">' . $row['board_name'] . '</a>',
				),
				'category' => array(
					'id' => $row['id_cat'],
					'name' => $row['cat_name'],
					'link' => '<a href="' . $scripturl . '#c' . $row['id_cat'] . '">' . $row['cat_name'] . '</a>',
				),
				'can_delete' => $can_delete,
			);
		}
		$request->free();

		return $unapproved_items;
	}
}