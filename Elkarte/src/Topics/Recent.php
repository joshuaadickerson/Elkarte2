<?php

/**
 * Find and retrieve information about recently posted topics, messages, and the like.
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
 * @version 1.1 dev Release Candidate 1
 *
 */

namespace Elkarte\Topics;
use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Events\Hooks;

/**
 * Recent Post Class, retrieve information about recent posts
 *
 * This is used by RecentController to retrieve the data
 * from the db, in particular by action_recent
 */
class Recent
{
	protected $query_this_board = '';

	protected $messages = array();

	protected $board_ids = array();

	protected $posts = array();

	protected $cache_results = false;

	protected $user_id = 0;

	/** @var  \Elkarte\Elkarte\Database\Drivers\DatabaseInterface */
	protected $db;

	/** @var  \Elkarte\Elkarte\Cache\Cache */
	protected $cache;

	/**
	 * Parameters for the main query.
	 */
	protected $query_parameters = array();

	/**
	 * Constructor
	 *
	 * @param int $user - ID of the user
	 */
	public function __construct($user, DatabaseInterface $db, Cache $cache, Hooks $hooks)
	{
		$this->user_id = (int) $user;

		$this->db = $GLOBALS['elk']['db'];
		$this->cache = $GLOBALS['elk']['cache'];
	}

	/**
	 * Sets the boards the member is looking at
	 *
	 * @param int|int[] $boards - the id of the boards
	 */
	public function setBoards($boards)
	{
		if (is_array($boards))
			$this->query_parameters['boards'] = $boards;
		else
			$this->query_parameters['boards'] = array($boards);

		$this->query_this_board .= 'b.id_board IN ({array_int:boards})';
	}

	/**
	 * Sets the lower message id to be taken in consideration
	 *
	 * @param int $msg_id - id of the earliest message to consider
	 */
	public function setEarliestMsg($msg_id)
	{
		$this->query_this_board .= '
						AND m.id_msg >= {int:max_id_msg}';
		$this->query_parameters['max_id_msg'] = $msg_id;
	}

	/**
	 * Sets boards the user can see
	 * Uses {query_wanna_see_board}
	 *
	 * @param int $msg_id - id of the earliest message to consider
	 * @param int $recycle - id of the recycle board
	 */
	public function setVisibleBoards($msg_id, $recycle)
	{
		$this->query_this_board .= '{query_wanna_see_board}' . (!empty($recycle) ? '
						AND b.id_board != {int:recycle_board}' : '') . '
						AND m.id_msg >= {int:max_id_msg}';

		if (!empty($recycle))
			$this->query_parameters['recycle_board'] = $msg_id;

		$this->query_parameters['max_id_msg'] = $msg_id;
	}

	/**
	 * Find the most recent messages in the forum.
	 *
	 * @param int $start - position to start the query
	 * @param int $limit - number of entries to grab from the database
	 * @return bool
	 */
	public function findRecentMessages($start, $limit = 10)
	{
		$key = 'recent-' . $this->user_id . '-' . md5(serialize(array_diff_key($this->query_parameters, array('max_id_msg' => 0)))) . '-' . $start . '-' . $limit;
		$this->messages = $this->cache->get($key, 120);
		if ($this->cache->isMiss())
		{
			$this->findRecentMessages($start, $limit);

			if (!empty($this->cache_results))
				$this->cache->put($key, $this->messages, 120);
		}

		return !empty($this->messages);
	}

	/**
	 * Find the most recent messages in the forum.
	 *
	 * @param int $start - position to start the query
	 * @param string[]|mixed[] $permissions - An array of boards permissions the members have.
	 *                 Used to define the buttons a member can see next to a message.
	 *                 Format of the array is:
	 *                 array(
	 *                   'own' => array(
	 *                     'permission_name' => 'test_name'
	 *                     ...
	 *                   ),
	 *                   'any' => array(
	 *                     'permission_name' => 'test_name'
	 *                     ...
	 *                   )
	 *                 )
	 * @return array
	 */
	public function getRecentPosts($start, $permissions)
	{
		// Provide an easy way for integration to interact with the recent display items
		$GLOBALS['elk']['hooks']->hook('recent_message_list', array($this->messages, &$permissions));

		$this->_getRecentPosts($start);

		// Now go through all the permissions, looking for boards they can do it on.
		foreach ($permissions as $type => $list)
		{
			foreach ($list as $permission => $allowed)
			{
				// They can do it on these boards...
				$boards = boardsAllowedTo($permission);

				// If 0 is the only thing in the array, they can do it everywhere!
				if (!empty($boards) && $boards[0] == 0)
					$boards = array_keys($this->board_ids[$type]);

				// Go through the boards, and look for posts they can do this on.
				foreach ($boards as $board_id)
				{
					// Hmm, they have permission, but there are no topics from that board on this page.
					if (!isset($this->board_ids[$type][$board_id]))
						continue;

					// Okay, looks like they can do it for these posts.
					foreach ($this->board_ids[$type][$board_id] as $counter)
						if ($type == 'any' || $this->posts[$counter]['poster']['id'] == $this->user_id)
							$this->posts[$counter]['tests'][$allowed] = true;
				}
			}
		}

		return $this->posts;
	}

	/**
	 * Actually executes the query to find the most recent messages in the forum.
	 *
	 * @param int $start - position to start the query
	 * @param int $limit - number of entries to grab
	 */
	protected function _findRecentMessages($start, $limit = 10)
	{
		$done = false;
		while (!$done)
		{
			// Find the 10 most recent messages they can *view*.
			// @todo SLOW This query is really slow still, probably?
			$request = $this->db->query('', '
				SELECT m.id_msg
				FROM {db_prefix}messages AS m
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
				WHERE ' . $this->query_this_board . '
					AND m.approved = {int:is_approved}
				ORDER BY m.id_msg DESC
				LIMIT {int:offset}, {int:limit}',
				array_merge($this->query_parameters, array(
					'is_approved' => 1,
					'offset' => $start,
					'limit' => $limit,
				))
			);
			// If we don't have 10 results, try again with an unoptimized version covering all rows, and cache the result.
			if (isset($this->query_parameters['max_id_msg']) && $request->numRows() < $limit)
			{
				$request->free();
				$this->query_this_board = str_replace('AND m.id_msg >= {int:max_id_msg}', '', $this->query_this_board);
				$this->cache_results = true;
				unset($this->query_parameters['max_id_msg']);
			}
			else
				$done = true;
		}
		$this->messages = array();
		while ($row = $request->fetchAssoc())
			$this->messages[] = $row['id_msg'];

		$request->free();
	}

	/**
	 * For a supplied list of message id's, loads the posting details for each.
	 *  - Intended to get all the most recent posts.
	 *  - Tracks the posts made by this user (from the supplied message list) and
	 *    loads the id's in to the 'own' or 'any' array.
	 *    Reminder The controller needs to check permissions
	 *  - Returns two arrays, one of the posts one of any/own
	 *
	 * @param int $start
	 */
	protected function _getRecentPosts($start)
	{
		// Get all the most recent posts.
		$request = $this->db->query('', '
			SELECT
				m.id_msg, m.subject, m.smileys_enabled, m.poster_time, m.body, m.id_topic, t.id_board, b.id_cat,
				b.name AS bname, c.name AS cname, t.num_replies, m.id_member, m2.id_member AS first_id_member,
				IFNULL(mem2.real_name, m2.poster_name) AS first_display_name, t.id_first_msg,
				IFNULL(mem.real_name, m.poster_name) AS poster_name, t.id_last_msg
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				INNER JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
				INNER JOIN {db_prefix}messages AS m2 ON (m2.id_msg = t.id_first_msg)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
				LEFT JOIN {db_prefix}members AS mem2 ON (mem2.id_member = m2.id_member)
			WHERE m.id_msg IN ({array_int:message_list})
			ORDER BY m.id_msg DESC
			LIMIT ' . count($this->messages),
			array(
				'message_list' => $this->messages,
			)
		);
		$returns = array();
		while ($row = $request->fetchAssoc())
			$returns[] = $row;

		$request->free();

		list ($this->posts, $this->board_ids) = prepareRecentPosts($returns, $start);
	}
}