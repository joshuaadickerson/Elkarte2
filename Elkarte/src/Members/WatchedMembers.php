<?php

namespace Elkarte\Members;

class WatchedMembers
{
	/**
	 * Returns the number of watched users in the system.
	 * (used by createList() callbacks).
	 *
	 * @param int $warning_watch
	 * @return int
	 */
	function watchedUserCount($warning_watch = 0)
	{
		$db = $GLOBALS['elk']['db'];

		// @todo $approve_query is not used

		$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}members
		WHERE warning >= {int:warning_watch}',
			array(
				'warning_watch' => $warning_watch,
			)
		);
		list ($totalMembers) = $request->fetchRow();
		$request->free();

		return $totalMembers;
	}

	/**
	 * Retrieved the watched users in the system.
	 * (used by createList() callbacks).
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page  The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param string $approve_query
	 * @param string $dummy
	 */
	function watchedUsers($start, $items_per_page, $sort, $approve_query, $dummy)
	{
		global $txt, $modSettings, $user_info;

		$db = $GLOBALS['elk']['db'];
		$request = $db->query('', '
		SELECT id_member, real_name, last_login, posts, warning
		FROM {db_prefix}members
		WHERE warning >= {int:warning_watch}
		ORDER BY {raw:sort}
		LIMIT ' . $start . ', ' . $items_per_page,
			array(
				'warning_watch' => $modSettings['warning_watch'],
				'sort' => $sort,
			)
		);
		$watched_users = array();
		$members = array();
		while ($row = $request->fetchAssoc())
		{
			$watched_users[$row['id_member']] = array(
				'id' => $row['id_member'],
				'name' => $row['real_name'],
				'last_login' => $row['last_login'] ? standardTime($row['last_login']) : $txt['never'],
				'last_post' => $txt['not_applicable'],
				'last_post_id' => 0,
				'warning' => $row['warning'],
				'posts' => $row['posts'],
			);
			$members[] = $row['id_member'];
		}
		$request->free();

		if (!empty($members))
		{
			// First get the latest messages from these users.
			$request = $db->query('', '
			SELECT m.id_member, MAX(m.id_msg) AS last_post_id
			FROM {db_prefix}messages AS m' . ($user_info['query_see_board'] == '1=1' ? '' : '
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})') . '
			WHERE m.id_member IN ({array_int:member_list})' . (!$modSettings['postmod_active'] || allowedTo('approve_posts') ? '' : '
				AND m.approved = {int:is_approved}') . '
			GROUP BY m.id_member',
				array(
					'member_list' => $members,
					'is_approved' => 1,
				)
			);
			$latest_posts = array();
			while ($row = $request->fetchAssoc())
				$latest_posts[$row['id_member']] = $row['last_post_id'];

			if (!empty($latest_posts))
			{
				// Now get the time those messages were posted.
				$request = $db->query('', '
				SELECT id_member, poster_time
				FROM {db_prefix}messages
				WHERE id_msg IN ({array_int:message_list})',
					array(
						'message_list' => $latest_posts,
					)
				);
				while ($row = $request->fetchAssoc())
				{
					$watched_users[$row['id_member']]['last_post'] = standardTime($row['poster_time']);
					$watched_users[$row['id_member']]['last_post_id'] = $latest_posts[$row['id_member']];
				}

				$request->free();
			}

			$request = $db->query('', '
			SELECT MAX(m.poster_time) AS last_post, MAX(m.id_msg) AS last_post_id, m.id_member
			FROM {db_prefix}messages AS m' . ($user_info['query_see_board'] == '1=1' ? '' : '
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})') . '
			WHERE m.id_member IN ({array_int:member_list})' . (!$modSettings['postmod_active'] || allowedTo('approve_posts') ? '' : '
				AND m.approved = {int:is_approved}') . '
			GROUP BY m.id_member',
				array(
					'member_list' => $members,
					'is_approved' => 1,
				)
			);
			while ($row = $request->fetchAssoc())
			{
				$watched_users[$row['id_member']]['last_post'] = standardTime($row['last_post']);
				$watched_users[$row['id_member']]['last_post_id'] = $row['last_post_id'];
			}
			$request->free();
		}

		return $watched_users;
	}

	/**
	 * Count of posts of watched users.
	 * (used by createList() callbacks)
	 *
	 * @param string $approve_query
	 * @param int $warning_watch
	 * @return int
	 */
	function watchedUserPostsCount($approve_query, $warning_watch)
	{
		global $modSettings;

		$db = $GLOBALS['elk']['db'];

		// @todo $approve_query is not used in the function

		$request = $db->query('', '
		SELECT COUNT(*)
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			WHERE mem.warning >= {int:warning_watch}
				AND {query_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
				AND b.id_board != {int:recycle}' : '') .
			$approve_query,
			array(
				'warning_watch' => $warning_watch,
				'recycle' => $modSettings['recycle_board'],
			)
		);
		list ($totalMemberPosts) = $request->fetchRow();
		$request->free();

		return $totalMemberPosts;
	}

	/**
	 * Retrieve the posts of watched users.
	 * (used by createList() callbacks).
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page  The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param string $approve_query
	 * @param int[] $delete_boards
	 */
	function watchedUserPosts($start, $items_per_page, $sort, $approve_query, $delete_boards)
	{
		global $scripturl, $modSettings;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
		SELECT m.id_msg, m.id_topic, m.id_board, m.id_member, m.subject, m.body, m.poster_time,
			m.approved, mem.real_name, m.smileys_enabled
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE mem.warning >= {int:warning_watch}
			AND {query_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
			AND b.id_board != {int:recycle}' : '') .
			$approve_query . '
		ORDER BY m.id_msg DESC
		LIMIT ' . $start . ', ' . $items_per_page,
			array(
				'warning_watch' => $modSettings['warning_watch'],
				'recycle' => $modSettings['recycle_board'],
			)
		);

		$member_posts = array();
		$bbc_parser = $GLOBALS['elk']['bbc'];

		while ($row = $request->fetchAssoc())
		{
			$row['subject'] = censor($row['subject']);
			$row['body'] = censor($row['body']);

			$member_posts[$row['id_msg']] = array(
				'id' => $row['id_msg'],
				'id_topic' => $row['id_topic'],
				'author_link' => '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>',
				'subject' => $row['subject'],
				'body' => $bbc_parser->parseMessage($row['body'], $row['smileys_enabled']),
				'poster_time' => standardTime($row['poster_time']),
				'approved' => $row['approved'],
				'can_delete' => $delete_boards == array(0) || in_array($row['id_board'], $delete_boards),
				'counter' => ++$start,
			);
		}
		$request->free();

		return $member_posts;
	}

	/**
	 * Returns an array of basic info about the most active watched users.
	 */
	function basicWatchedUsers()
	{
		global $modSettings;

		$db = $GLOBALS['elk']['db'];

		if (!$GLOBALS['elk']['cache']->getVar($watched_users, 'recent_user_watches', 240))
		{
			$modSettings['warning_watch'] = empty($modSettings['warning_watch']) ? 1 : $modSettings['warning_watch'];
			$request = $db->query('', '
			SELECT id_member, real_name, last_login
			FROM {db_prefix}members
			WHERE warning >= {int:warning_watch}
			ORDER BY last_login DESC
			LIMIT 10',
				array(
					'warning_watch' => $modSettings['warning_watch'],
				)
			);
			$watched_users = array();
			while ($row = $request->fetchAssoc())
				$watched_users[] = $row;
			$request->free();

			$GLOBALS['elk']['cache']->put('recent_user_watches', $watched_users, 240);
		}

		return $watched_users;
	}

}