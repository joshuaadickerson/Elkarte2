<?php

/**
 * This file contains nosey functions so show where a user is active
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Checks, who is viewing a topic or board
 *
 * @param int $id
 * @param string $session
 * @param string $type
 * @return array
 */
function viewers($id, $session, $type = 'topic')
{
	$db = database();

	// Make sure we have a default value
	if (!in_array($type, array('topic', 'board')))
		$type = 'topic';

	$viewers = array();
	$request = $db->query('', '
		SELECT
			lo.id_member, lo.log_time, mem.real_name, mem.member_name, mem.show_online,
			mg.online_color, mg.id_group, mg.group_name
		FROM {db_prefix}log_online AS lo
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lo.id_member)
			LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = CASE WHEN mem.id_group = {int:reg_member_group} THEN mem.id_post_group ELSE mem.id_group END)
		WHERE INSTR(lo.url, {string:in_url_string}) > 0 OR lo.session = {string:session}',
		array(
			'reg_member_group' => 0,
			'in_url_string' => 's:5:"' . $type . '";i:' . $id . ';',
			'session' => $session
		)
	);
	while ($row = $db->fetch_assoc($request))
	{
		$viewers[] = $row;
	}
	$db->free_result($request);

	return $viewers;
}

/**
 * Format viewers list for display, for a topic or board.
 *
 * @param int $id id of the element (topic or board) we're watching
 * @param string $type = 'topic, 'topic' or 'board'
 */
function formatViewers($id, $type)
{
	global $user_info, $context, $scripturl;

	// Lets say there's no one around. (what? could happen!)
	$context['view_members'] = array();
	$context['view_members_list'] = array();
	$context['view_num_hidden'] = 0;
	$context['view_num_guests'] = 0;

	$viewers = viewers($id, $user_info['is_guest'] ? 'ip' . $user_info['ip'] : session_id(), $type);

	foreach ($viewers as $viewer)
	{
		// is this a guest?
		if (empty($viewer['id_member']))
		{
			$context['view_num_guests']++;
			continue;
		}

		// it's a member. We format them with links 'n stuff.
		if (!empty($viewer['online_color']))
			$link = '<a href="' . $scripturl . '?action=profile;u=' . $viewer['id_member'] . '" style="color: ' . $viewer['online_color'] . ';">' . $viewer['real_name'] . '</a>';
		else
			$link = '<a href="' . $scripturl . '?action=profile;u=' . $viewer['id_member'] . '">' . $viewer['real_name'] . '</a>';

		$is_buddy = in_array($viewer['id_member'], $user_info['buddies']);
		if ($is_buddy)
			$link = '<strong>' . $link . '</strong>';

		// fill the summary list
		if (!empty($viewer['show_online']) || allowedTo('moderate_forum'))
			$context['view_members_list'][$viewer['log_time'] . $viewer['member_name']] = empty($viewer['show_online']) ? '<em>' . $link . '</em>' : $link;

		// fill the detailed list
		$context['view_members'][$viewer['log_time'] . $viewer['member_name']] = array(
			'id' => $viewer['id_member'],
			'username' => $viewer['member_name'],
			'name' => $viewer['real_name'],
			'group' => $viewer['id_group'],
			'href' => $scripturl . '?action=profile;u=' . $viewer['id_member'],
			'link' => $link,
			'is_buddy' => $is_buddy,
			'hidden' => empty($viewer['show_online']),
		);

		// add the hidden members to the count (and don't show them in the template)
		if (empty($viewer['show_online']))
			$context['view_num_hidden']++;
	}

	// Sort them out.
	krsort($context['view_members_list']);
	krsort($context['view_members']);
}

/**
 * This function determines the actions of the members passed in urls.
 *
 * Adding actions to the Who's Online list:
 * Adding actions to this list is actually relatively easy...
 * - for actions anyone should be able to see, just add a string named whoall_ACTION.
 *   (where ACTION is the action used in index.php.)
 * - for actions that have a subaction which should be represented differently, use whoall_ACTION_SUBACTION.
 * - for actions that include a topic, and should be restricted, use whotopic_ACTION.
 * - for actions that use a message, by msg or quote, use whopost_ACTION.
 * - for administrator-only actions, use whoadmin_ACTION.
 * - for actions that should be viewable only with certain permissions, use whoallow_ACTION and
 * add a list of possible permissions to the $allowedActions array, using ACTION as the key.
 *
 * @param mixed[]|string $urls a single url (string) or an array of arrays, each inner array being (serialized request data, id_member)
 * @param string|false $preferred_prefix = false
 * @return mixed[]|string an array of descriptions if you passed an array, otherwise the string describing their current location.
 */
function determineActions($urls, $preferred_prefix = false)
{
	global $txt, $user_info, $modSettings, $scripturl;

	$db = database();

	if (!allowedTo('who_view'))
		return array();

	loadLanguage('Who');

	// Actions that require a specific permission level.
	$allowedActions = array(
		'admin' => array('moderate_forum', 'manage_membergroups', 'manage_bans', 'admin_forum', 'manage_permissions', 'send_mail', 'manage_attachments', 'manage_smileys', 'manage_boards', 'edit_news'),
		'ban' => array('manage_bans'),
		'boardrecount' => array('admin_forum'),
		'calendar' => array('calendar_view'),
		'editnews' => array('edit_news'),
		'mailing' => array('send_mail'),
		'maintain' => array('admin_forum'),
		'manageattachments' => array('manage_attachments'),
		'manageboards' => array('manage_boards'),
		'memberlist' => array('view_mlist'),
		'moderate' => array('access_mod_center', 'moderate_forum', 'manage_membergroups'),
		'optimizetables' => array('admin_forum'),
		'repairboards' => array('admin_forum'),
		'search' => array('search_posts'),
		'setcensor' => array('moderate_forum'),
		'setreserve' => array('moderate_forum'),
		'stats' => array('view_stats'),
		'viewErrorLog' => array('admin_forum'),
		'viewmembers' => array('moderate_forum'),
	);

	// Provide integration a way to add to the allowed action array
	call_integration_hook('integrate_whos_online_allowed', array(&$allowedActions));

	if (!is_array($urls))
		$url_list = array(array($urls, $user_info['id']));
	else
		$url_list = $urls;

	// These are done to query these in large chunks. (instead of one by one.)
	$topic_ids = array();
	$profile_ids = array();
	$board_ids = array();

	$data = array();
	foreach ($url_list as $k => $url)
	{
		// Get the request parameters..
		$actions = @unserialize($url[0]);
		if ($actions === false)
			continue;

		// If it's the admin or moderation center, and there is an area set, use that instead.
		if (isset($actions['action']) && ($actions['action'] == 'admin' || $actions['action'] == 'moderate') && isset($actions['area']))
			$actions['action'] = $actions['area'];

		// Check if there was no action or the action is display.
		if (!isset($actions['action']) || $actions['action'] == 'display')
		{
			// It's a topic!  Must be!
			if (isset($actions['topic']))
			{
				// Assume they can't view it, and queue it up for later.
				$data[$k] = $txt['who_hidden'];
				$topic_ids[(int) $actions['topic']][$k] = $txt['who_topic'];
			}
			// It's a board!
			elseif (isset($actions['board']))
			{
				// Hide first, show later.
				$data[$k] = $txt['who_hidden'];
				$board_ids[$actions['board']][$k] = $txt['who_board'];
			}
			// It's the board index!!  It must be!
			else
				$data[$k] = replaceBasicActionUrl($txt['who_index']);
		}
		// Probably an error or some goon?
		elseif ($actions['action'] == '')
			$data[$k] = replaceBasicActionUrl($txt['who_index']);
		// Some other normal action...?
		else
		{
			// Viewing/editing a profile.
			if ($actions['action'] == 'profile')
			{
				// Whose?  Their own?
				if (empty($actions['u']))
				{
					require_once(SUBSDIR . '/Profile.subs.php');
					$memID = currentMemberID();

					if ($memID == $user_info['id'])
						$actions['u'] = $url[1];
					else
						$actions['u'] = $memID;
				}

				$data[$k] = $txt['who_hidden'];
				$profile_ids[(int) $actions['u']][$k] = $actions['action'] == 'profile' ? $txt['who_viewprofile'] : $txt['who_profile'];
			}
			// Trying to post
			elseif (($actions['action'] == 'post' || $actions['action'] == 'post2' || $actions['action'] == 'topicbyemail') && empty($actions['topic']) && isset($actions['board']))
			{
				$data[$k] = $txt['who_hidden'];
				if ($actions['action'] == 'topicbyemail')
					$board_ids[(int) $actions['board']][$k] = $txt['who_topicbyemail'];
				else
					$board_ids[(int) $actions['board']][$k] = isset($actions['poll']) ? $txt['who_poll'] : $txt['who_post'];
			}
			// A subaction anyone can view... if the language string is there, show it.
			elseif (isset($actions['sa']) && isset($txt['whoall_' . $actions['action'] . '_' . $actions['sa']]))
				$data[$k] = $preferred_prefix && isset($txt[$preferred_prefix . $actions['action'] . '_' . $actions['sa']]) ? $txt[$preferred_prefix . $actions['action'] . '_' . $actions['sa']] : $txt['whoall_' . $actions['action'] . '_' . $actions['sa']];
			// An action any old fellow can look at. (if $txt['whoall_' . $action] exists, we know everyone can see it.)
			elseif (isset($txt['whoall_' . $actions['action']]))
				$data[$k] = $preferred_prefix && isset($txt[$preferred_prefix . $actions['action']]) ? $txt[$preferred_prefix . $actions['action']] : replaceBasicActionUrl($txt['whoall_' . $actions['action']]);
			// Viewable if and only if they can see the board...
			elseif (isset($txt['whotopic_' . $actions['action']]))
			{
				// Find out what topic they are accessing.
				$topic = (int) (isset($actions['topic']) ? $actions['topic'] : (isset($actions['from']) ? $actions['from'] : 0));

				$data[$k] = $txt['who_hidden'];
				$topic_ids[$topic][$k] = $txt['whotopic_' . $actions['action']];
			}
			// Viewable if and only if they can see the board...
			elseif (isset($actions['sa']) && isset($txt['whotopic_' . $actions['action'] . '_' . $actions['sa']]))
			{
				// Find out what topic they are accessing.
				$topic = (int) (isset($actions['topic']) ? $actions['topic'] : (isset($actions['from']) ? $actions['from'] : 0));

				$data[$k] = $txt['who_hidden'];
				$topic_ids[$topic][$k] = $txt['whotopic_' . $actions['action'] . '_' . $actions['sa']];
			}
			elseif (isset($txt['whopost_' . $actions['action']]))
			{
				// Find out what message they are accessing.
				$msgid = (int) (isset($actions['msg']) ? $actions['msg'] : (isset($actions['quote']) ? $actions['quote'] : 0));

				$result = $db->query('', '
					SELECT m.id_topic, m.subject
					FROM {db_prefix}messages AS m
						INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
						INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic' . ($modSettings['postmod_active'] ? ' AND t.approved = {int:is_approved}' : '') . ')
					WHERE m.id_msg = {int:id_msg}
						AND {query_see_board}' . ($modSettings['postmod_active'] ? '
						AND m.approved = {int:is_approved}' : '') . '
					LIMIT 1',
					array(
						'is_approved' => 1,
						'id_msg' => $msgid,
					)
				);
				list ($id_topic, $subject) = $db->fetch_row($result);
				$data[$k] = sprintf($txt['whopost_' . $actions['action']], $scripturl . '?topic=' . $id_topic . '.0', $subject);
				$db->free_result($result);

				if (empty($id_topic))
					$data[$k] = $txt['who_hidden'];
			}
			// Viewable only by administrators.. (if it starts with whoadmin, it's admin only!)
			elseif (allowedTo('moderate_forum') && isset($txt['whoadmin_' . $actions['action']]))
				$data[$k] = $txt['whoadmin_' . $actions['action']];
			// Viewable by permission level.
			elseif (isset($allowedActions[$actions['action']]))
			{
				if (allowedTo($allowedActions[$actions['action']]))
				{
					if (isset($actions['sa']) && isset($txt['whoallow_' . $actions['action'] . '_' . $actions['sa']]))
						$data[$k] = replaceBasicActionUrl($txt['whoallow_' . $actions['action'] . '_' . $actions['sa']]);
					else
						$data[$k] = replaceBasicActionUrl($txt['whoallow_' . $actions['action']]);
				}
				elseif (in_array('moderate_forum', $allowedActions[$actions['action']]))
					$data[$k] = $txt['who_moderate'];
				elseif (in_array('admin_forum', $allowedActions[$actions['action']]))
					$data[$k] = $txt['who_admin'];
				else
					$data[$k] = $txt['who_hidden'];
			}
			// Something we don't have details about, but it is an action, maybe an addon
			elseif (!empty($actions['action']))
				$data[$k] = sprintf($txt['who_generic'], $actions['action']);
			// We just don't know
			else
				$data[$k] = $txt['who_unknown'];
		}

		// Maybe the action is integrated into another system?
		if (count($integrate_actions = call_integration_hook('integrate_whos_online', array($actions))) > 0)
		{
			// Try each integration hook with this url and see if they can fill in the details
			foreach ($integrate_actions as $integrate_action)
			{
				if (!empty($integrate_action))
				{
					// Found it, all done then
					$data[$k] = $integrate_action;
					break;
				}
			}
		}
	}

	// Load topic names.
	if (!empty($topic_ids))
	{
		require_once(SUBSDIR . '/Topic.subs.php');
		$topics_data = topicsList(array_keys($topic_ids));

		foreach ($topics_data as $topic)
		{
			// Show the topic's subject for each of the members looking at this...
			foreach ($topic_ids[$topic['id_topic']] as $k => $session_text)
				$data[$k] = sprintf($session_text, $scripturl . '?topic=' . $topic['id_topic'] . '.0', $topic['subject']);
		}
	}

	// Load board names.
	if (!empty($board_ids))
	{
		require_once(SUBSDIR . '/Boards.subs.php');

		$boards_list = getBoardList(array('included_boards' => array_keys($board_ids)), true);
		foreach ($boards_list as $board)
		{
			// Put the board name into the string for each member...
			foreach ($board_ids[$board['id_board']] as $k => $session_text)
				$data[$k] = sprintf($session_text, $scripturl . '?board=' . $board['id_board'] . '.0', $board['board_name']);
		}
	}

	// Load member names for the profile.
	if (!empty($profile_ids) && (allowedTo('profile_view_any') || allowedTo('profile_view_own')))
	{
		require_once(SUBSDIR . '/Members.subs.php');
		$result = getBasicMemberData(array_keys($profile_ids));
		foreach ($result as $row)
		{
			// If they aren't allowed to view this person's profile, skip it.
			if (!allowedTo('profile_view_any') && $user_info['id'] != $row['id_member'])
				continue;

			// Set their action on each - session/text to sprintf.
			foreach ($profile_ids[$row['id_member']] as $k => $session_text)
				$data[$k] = sprintf($session_text, $scripturl . '?action=profile;u=' . $row['id_member'], $row['real_name']);
		}
	}

	if (!is_array($urls))
		return isset($data[0]) ? $data[0] : false;
	else
		return $data;
}