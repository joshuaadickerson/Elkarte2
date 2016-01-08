<?php

/**
 * This file contains functions for dealing with topics. Low-level functions,
 * i.e. database operations needed to perform.
 * These functions do NOT make permissions checks. (they assume those were
 * already made).
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

namespace Elkarte\Topics;

// @todo break this down in to smaller classes. This is HUGE!


use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;

class TopicsManager
{
	public function __construct(DatabaseInterface $db, Cache $cache)
	{
		$this->db = $db;
		$this->cache = $cache;
	}

	/**
	 * Removes the passed id_topic's checking for permissions.
	 *
	 * @param int[]|int $topics The topics to remove (can be an id or an array of ids).
	 */
	function removeTopicsPermissions($topics)
	{
		global $board, $user_info;

		// They can only delete their own topics. (we wouldn't be here if they couldn't do that..)
		$possible_remove = $this->topicAttribute($topics, array('id_topic', 'id_board', 'id_member_started'));

		$removeCache = array();
		$removeCacheBoards = array();
		$test_owner = !empty($board) && !allowedTo('remove_any');
		foreach ($possible_remove as $row) {
			// Skip if we have to test the owner *and* the user is not the owner
			if ($test_owner && $row['id_member_started'] != $user_info['id'])
				continue;

			$removeCache[] = $row['id_topic'];
			$removeCacheBoards[$row['id_topic']] = $row['id_board'];
		}

		// Maybe *none* were their own topics.
		if (!empty($removeCache))
			removeTopics($removeCache, true, false, true, $removeCacheBoards);
	}

	/**
	 * Removes the passed id_topic's.
	 * Permissions are NOT checked here because the function is used in a scheduled task
	 *
	 * @param int[]|int $topics The topics to remove (can be an id or an array of ids).
	 * @param bool $decreasePostCount if true users' post count will be reduced
	 * @param bool $ignoreRecycling if true topics are not moved to the recycle board (if it exists).
	 * @param bool $log if true logs the action.
	 * @param int[] $removeCacheBoards an array matching topics and boards.
	 */
	function removeTopics($topics, $decreasePostCount = true, $ignoreRecycling = false, $log = false, $removeCacheBoards = array())
	{
		global $modSettings;

		// Nothing to do?
		if (empty($topics))
			return;

		// Only a single topic.
		if (!is_array($topics))
			$topics = array($topics);

		if ($log) {
			// Gotta send the notifications *first*!
			foreach ($topics as $topic) {
				// Only log the topic ID if it's not in the recycle board.
				logAction('remove', array((empty($modSettings['recycle_enable']) || $modSettings['recycle_board'] != $removeCacheBoards[$topic] ? 'topic' : 'old_topic_id') => $topic, 'board' => $removeCacheBoards[$topic]));
				sendNotifications($topic, 'remove');
			}
		}

		// Decrease the post counts for members.
		if ($decreasePostCount) {
			$requestMembers = $this->db->query('', '
				SELECT m.id_member, COUNT(*) AS posts
				FROM {db_prefix}messages AS m
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
				WHERE m.id_topic IN ({array_int:topics})
					AND m.icon != {string:recycled}
					AND b.count_posts = {int:do_count_posts}
					AND m.approved = {int:is_approved}
				GROUP BY m.id_member',
				array(
					'do_count_posts' => 0,
					'recycled' => 'recycled',
					'topics' => $topics,
					'is_approved' => 1,
				)
			);
			if ($requestMembers->numRows() > 0) {
				while ($rowMembers = $requestMembers->fetchAssoc())
					updateMemberData($rowMembers['id_member'], array('posts' => 'posts - ' . $rowMembers['posts']));
			}
			$requestMembers->free();
		}

		// Recycle topics that aren't in the recycle board...
		if (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 && !$ignoreRecycling) {
			$possible_recycle = $this->topicAttribute($topics, array('id_topic', 'id_board', 'unapproved_posts', 'approved'));

			if (!empty($possible_recycle)) {
				setTimeLimit(300);

				// Get topics that will be recycled.
				$recycleTopics = array();
				foreach ($possible_recycle as $row) {
					// If it's already in the recycle board do nothing
					if ($row['id_board'] == $modSettings['recycle_board'])
						continue;

					$recycleTopics[] = $row['id_topic'];

					// Set the id_previous_board for this topic - and make it not sticky.
					setTopicAttribute($row['id_topic'], array(
						'id_previous_board' => $row['id_board'],
						'is_sticky' => 0,
					));
				}

				if (!empty($recycleTopics)) {
					// Mark recycled topics as recycled.
					$this->db->query('', '
					UPDATE {db_prefix}messages
					SET icon = {string:recycled}
					WHERE id_topic IN ({array_int:recycle_topics})',
						array(
							'recycle_topics' => $recycleTopics,
							'recycled' => 'recycled',
						)
					);

					// Move the topics to the recycle board.
					moveTopics($recycleTopics, $modSettings['recycle_board']);

					// Close reports that are being recycled.
					$this->db->query('', '
					UPDATE {db_prefix}log_reported
					SET closed = {int:is_closed}
					WHERE id_topic IN ({array_int:recycle_topics})',
						array(
							'recycle_topics' => $recycleTopics,
							'is_closed' => 1,
						)
					);

					updateSettings(array('last_mod_report_action' => time()));
					recountOpenReports();

					// Topics that were recycled don't need to be deleted, so subtract them.
					$topics = array_diff($topics, $recycleTopics);
				}
			}
		}

		// Still topics left to delete?
		if (empty($topics))
			return;

		$adjustBoards = array();

		// Find out how many posts we are deleting.
		$request = $this->db->query('', '
			SELECT id_board, approved, COUNT(*) AS num_topics, SUM(unapproved_posts) AS unapproved_posts,
				SUM(num_replies) AS num_replies
			FROM {db_prefix}topics
			WHERE id_topic IN ({array_int:topics})
			GROUP BY id_board, approved',
			array(
				'topics' => $topics,
			)
		);
		while ($row = $request->fetchAssoc()) {
			if (!isset($adjustBoards[$row['id_board']]['num_posts'])) {
				$this->cache->remove('board-' . $row['id_board']);

				$adjustBoards[$row['id_board']] = array(
					'num_posts' => 0,
					'num_topics' => 0,
					'unapproved_posts' => 0,
					'unapproved_topics' => 0,
					'id_board' => $row['id_board']
				);
			}
			// Posts = (num_replies + 1) for each approved topic.
			$adjustBoards[$row['id_board']]['num_posts'] += $row['num_replies'] + ($row['approved'] ? $row['num_topics'] : 0);
			$adjustBoards[$row['id_board']]['unapproved_posts'] += $row['unapproved_posts'];

			// Add the topics to the right type.
			if ($row['approved'])
				$adjustBoards[$row['id_board']]['num_topics'] += $row['num_topics'];
			else
				$adjustBoards[$row['id_board']]['unapproved_topics'] += $row['num_topics'];
		}
		$request->free();

		// Decrease number of posts and topics for each board.
		setTimeLimit(300);
		foreach ($adjustBoards as $stats) {
			$this->db->query('', '
				UPDATE {db_prefix}boards
				SET
					num_posts = CASE WHEN {int:num_posts} > num_posts THEN 0 ELSE num_posts - {int:num_posts} END,
					num_topics = CASE WHEN {int:num_topics} > num_topics THEN 0 ELSE num_topics - {int:num_topics} END,
					unapproved_posts = CASE WHEN {int:unapproved_posts} > unapproved_posts THEN 0 ELSE unapproved_posts - {int:unapproved_posts} END,
					unapproved_topics = CASE WHEN {int:unapproved_topics} > unapproved_topics THEN 0 ELSE unapproved_topics - {int:unapproved_topics} END
				WHERE id_board = {int:id_board}',
				array(
					'id_board' => $stats['id_board'],
					'num_posts' => $stats['num_posts'],
					'num_topics' => $stats['num_topics'],
					'unapproved_posts' => $stats['unapproved_posts'],
					'unapproved_topics' => $stats['unapproved_topics'],
				)
			);
		}

		// Remove polls for these topics.
		$possible_polls = $this->topicAttribute($topics, 'id_poll');
		$polls = array();
		foreach ($possible_polls as $row) {
			if (!empty($row['id_poll']))
				$polls[] = $row['id_poll'];
		}

		if (!empty($polls)) {
			$this->db->delete('', '
				DELETE FROM {db_prefix}polls
				WHERE id_poll IN ({array_int:polls})',
				array(
					'polls' => $polls,
				)
			);
			$this->db->delete('', '
				DELETE FROM {db_prefix}poll_choices
				WHERE id_poll IN ({array_int:polls})',
				array(
					'polls' => $polls,
				)
			);
			$this->db->delete('', '
				DELETE FROM {db_prefix}log_polls
				WHERE id_poll IN ({array_int:polls})',
				array(
					'polls' => $polls,
				)
			);
		}

		// Get rid of the attachment(s).
		$attachmentQuery = array(
			'attachment_type' => 0,
			'id_topic' => $topics,
		);
		removeAttachments($attachmentQuery, 'messages');

		// Delete search index entries.
		if (!empty($modSettings['search_custom_index_config'])) {
			$customIndexSettings = unserialize($modSettings['search_custom_index_config']);

			$request = $this->db->select('', '
				SELECT id_msg, body
				FROM {db_prefix}messages
				WHERE id_topic IN ({array_int:topics})',
				array(
					'topics' => $topics,
				)
			);
			$words = array();
			$messages = array();
			while ($row = $request->fetchAssoc()) {
				setTimeLimit(300);

				$words = array_merge($words, text2words($row['body'], $customIndexSettings['bytes_per_word'], true));
				$messages[] = $row['id_msg'];
			}
			$request->free();
			$words = array_unique($words);

			if (!empty($words) && !empty($messages))
				$this->db->query('', '
					DELETE FROM {db_prefix}log_search_words
					WHERE id_word IN ({array_int:word_list})
						AND id_msg IN ({array_int:message_list})',
					array(
						'word_list' => $words,
						'message_list' => $messages,
					)
				);
		}

		// Reuse the message array if available
		if (empty($messages))
			$messages = messagesInTopics($topics);

		// If there are messages left in this topic
		if (!empty($messages)) {
			// Decrease / Update the member like counts
			decreaseLikeCounts($messages);

			// Remove all likes now that the topic is gone
			$this->db->delete('', '
				DELETE FROM {db_prefix}message_likes
				WHERE id_msg IN ({array_int:messages})',
				array(
					'messages' => $messages,
				)
			);

			// Remove all mentions now that the topic is gone
			$this->db->delete('', '
				DELETE FROM {db_prefix}log_mentions
				WHERE id_target IN ({array_int:messages})
					AND mention_type IN ({array_string:mension_types})',
				array(
					'messages' => $messages,
					'mension_types' => array('mentionmem', 'likemsg', 'rlikemsg'),
				)
			);
		}

		// Delete messages in each topic.
		$this->db->delete('', '
			DELETE FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);

		// Remove linked calendar events.
		// @todo if unlinked events are enabled, wouldn't this be expected to keep them?
		$this->db->delete('', '
			DELETE FROM {db_prefix}calendar
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);

		// Delete log_topics data
		$this->db->delete('', '
			DELETE FROM {db_prefix}log_topics
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);

		// Delete notifications
		$this->db->delete('', '
			DELETE FROM {db_prefix}log_notify
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);

		// Delete the topics themselves
		$this->db->delete('', '
			DELETE FROM {db_prefix}topics
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);

		// Remove data from the subjects for search cache
		$this->db->delete('', '
			DELETE FROM {db_prefix}log_search_subjects
			WHERE id_topic IN ({array_int:topics})',
			array(
				'topics' => $topics,
			)
		);
		removeFollowUpsByTopic($topics);

		foreach ($topics as $topic_id)
			$this->cache->remove('topic_board-' . $topic_id);

		// Maybe there's an addon that wants to delete topic related data of its own
		$GLOBALS['elk']['hooks']->hook('remove_topics', array($topics));

		// Update the totals...

		updateMessageStats();
		updateTopicStats();
		updateSettings(array(
			'calendar_updated' => time(),
		));

		$updates = array();
		foreach ($adjustBoards as $stats)
			$updates[] = $stats['id_board'];
		updateLastMessages($updates);
	}

	/**
	 * Moves lots of topics to a specific board and checks if the user can move them
	 *
	 * @param array $moveCache [0] => int[] is the topic, [1] => int[]  is the board to move to.
	 */
	function moveTopicsPermissions($moveCache)
	{
		global $board, $user_info;

		

		// I know - I just KNOW you're trying to beat the system.  Too bad for you... we CHECK :P.
		$request = $this->db->select('', '
			SELECT t.id_topic, t.id_board, b.count_posts
			FROM {db_prefix}topics AS t
				LEFT JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
			WHERE t.id_topic IN ({array_int:move_topic_ids})' . (!empty($board) && !allowedTo('move_any') ? '
				AND t.id_member_started = {int:current_member}' : '') . '
			LIMIT ' . count($moveCache[0]),
			array(
				'current_member' => $user_info['id'],
				'move_topic_ids' => $moveCache[0],
			)
		);
		$moveTos = array();
		$moveCache2 = array();
		$countPosts = array();
		while ($row = $request->fetchAssoc()) {
			$to = $moveCache[1][$row['id_topic']];

			if (empty($to))
				continue;

			// Does this topic's board count the posts or not?
			$countPosts[$row['id_topic']] = empty($row['count_posts']);

			if (!isset($moveTos[$to]))
				$moveTos[$to] = array();

			$moveTos[$to][] = $row['id_topic'];

			// For reporting...
			$moveCache2[] = array($row['id_topic'], $row['id_board'], $to);
		}
		$request->free();

		// Do the actual moves...
		foreach ($moveTos as $to => $topics)
			moveTopics($topics, $to, true);

		// Does the post counts need to be updated?
		if (!empty($moveTos)) {

			$topicRecounts = array();
			$boards_info = fetchBoardsInfo(array('boards' => array_keys($moveTos)), array('selects' => 'posts'));

			foreach ($boards_info as $row) {
				$cp = empty($row['count_posts']);

				// Go through all the topics that are being moved to this board.
				foreach ($moveTos[$row['id_board']] as $topic) {
					// If both boards have the same value for post counting then no adjustment needs to be made.
					if ($countPosts[$topic] != $cp) {
						// If the board being moved to does count the posts then the other one doesn't so add to their post count.
						$topicRecounts[$topic] = $cp ? 1 : -1;
					}
				}
			}

			if (!empty($topicRecounts))
			{

				// Get all the members who have posted in the moved topics.
				$posters = topicsPosters(array_keys($topicRecounts));
				foreach ($posters as $id_member => $topics) {
					$post_adj = 0;
					foreach ($topics as $id_topic)
						$post_adj += $topicRecounts[$id_topic];

					// And now update that member's post counts
					if (!empty($post_adj)) {
						updateMemberData($id_member, array('posts' => 'posts + ' . $post_adj));
					}
				}
			}
		}
	}

	/**
	 * Moves one or more topics to a specific board.
	 * Determines the source boards for the supplied topics
	 * Handles the moving of mark_read data
	 * Updates the posts count of the affected boards
	 * This function doesn't check permissions.
	 *
	 * @param int[]|int $topics
	 * @param int $toBoard
	 * @param bool $log if true logs the action.
	 */
	function moveTopics($topics, $toBoard, $log = false)
	{
		global $user_info, $modSettings;

		// No topics or no board?
		if (empty($topics) || empty($toBoard))
			return;

		// Only a single topic.
		if (!is_array($topics))
			$topics = array($topics);

		$fromBoards = array();
		$fromCacheBoards = array();

		// Are we moving to the recycle board?
		$isRecycleDest = !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] == $toBoard;

		// Determine the source boards...
		$request = $this->db->query('', '
			SELECT id_topic, id_board, approved, COUNT(*) AS num_topics, SUM(unapproved_posts) AS unapproved_posts,
				SUM(num_replies) AS num_replies
			FROM {db_prefix}topics
			WHERE id_topic IN ({array_int:topics})
			GROUP BY id_board, approved',
			array(
				'topics' => $topics,
			)
		);
		// Num of rows = 0 -> no topics found. Num of rows > 1 -> topics are on multiple boards.
		if ($request->numRows() == 0)
			return;

		while ($row = $request->fetchAssoc()) {
			$fromCacheBoards[$row['id_topic']] = $row['id_board'];
			if (!isset($fromBoards[$row['id_board']]['num_posts'])) {
				$fromBoards[$row['id_board']] = array(
					'num_posts' => 0,
					'num_topics' => 0,
					'unapproved_posts' => 0,
					'unapproved_topics' => 0,
					'id_board' => $row['id_board']
				);
			}
			// Posts = (num_replies + 1) for each approved topic.
			$fromBoards[$row['id_board']]['num_posts'] += $row['num_replies'] + ($row['approved'] ? $row['num_topics'] : 0);
			$fromBoards[$row['id_board']]['unapproved_posts'] += $row['unapproved_posts'];

			// Add the topics to the right type.
			if ($row['approved'])
				$fromBoards[$row['id_board']]['num_topics'] += $row['num_topics'];
			else
				$fromBoards[$row['id_board']]['unapproved_topics'] += $row['num_topics'];
		}
		$request->free();

		// Move over the mark_read data. (because it may be read and now not by some!)
		$SaveAServer = max(0, $modSettings['maxMsgID'] - 50000);
		$request = $this->db->query('', '
			SELECT lmr.id_member, lmr.id_msg, t.id_topic, IFNULL(lt.unwatched, 0) as unwatched
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = t.id_board
					AND lmr.id_msg > t.id_first_msg AND lmr.id_msg > {int:protect_lmr_msg})
				LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = lmr.id_member)
			WHERE t.id_topic IN ({array_int:topics})
				AND lmr.id_msg > IFNULL(lt.id_msg, 0)',
			array(
				'protect_lmr_msg' => $SaveAServer,
				'topics' => $topics,
			)
		);
		$log_topics = array();
		while ($row = $request->fetchAssoc()) {
			$log_topics[] = array($row['id_member'], $row['id_topic'], $row['id_msg'], $row['unwatched']);

			// Prevent queries from getting too big. Taking some steam off.
			if (count($log_topics) > 500) {
				markTopicsRead($log_topics, true);
				$log_topics = array();
			}
		}
		$request->free();

		// Now that we have all the topics that *should* be marked read, and by which members...
		if (!empty($log_topics)) {
			// Insert that information into the database!
			markTopicsRead($log_topics, true);
		}

		// Update the number of posts on each board.
		$totalTopics = 0;
		$totalPosts = 0;
		$totalUnapprovedTopics = 0;
		$totalUnapprovedPosts = 0;
		foreach ($fromBoards as $stats) {
			$this->db->query('', '
				UPDATE {db_prefix}boards
				SET
					num_posts = CASE WHEN {int:num_posts} > num_posts THEN 0 ELSE num_posts - {int:num_posts} END,
					num_topics = CASE WHEN {int:num_topics} > num_topics THEN 0 ELSE num_topics - {int:num_topics} END,
					unapproved_posts = CASE WHEN {int:unapproved_posts} > unapproved_posts THEN 0 ELSE unapproved_posts - {int:unapproved_posts} END,
					unapproved_topics = CASE WHEN {int:unapproved_topics} > unapproved_topics THEN 0 ELSE unapproved_topics - {int:unapproved_topics} END
				WHERE id_board = {int:id_board}',
				array(
					'id_board' => $stats['id_board'],
					'num_posts' => $stats['num_posts'],
					'num_topics' => $stats['num_topics'],
					'unapproved_posts' => $stats['unapproved_posts'],
					'unapproved_topics' => $stats['unapproved_topics'],
				)
			);
			$totalTopics += $stats['num_topics'];
			$totalPosts += $stats['num_posts'];
			$totalUnapprovedTopics += $stats['unapproved_topics'];
			$totalUnapprovedPosts += $stats['unapproved_posts'];
		}
		$this->db->query('', '
			UPDATE {db_prefix}boards
			SET
				num_topics = num_topics + {int:total_topics},
				num_posts = num_posts + {int:total_posts},' . ($isRecycleDest ? '
				unapproved_posts = {int:no_unapproved}, unapproved_topics = {int:no_unapproved}' : '
				unapproved_posts = unapproved_posts + {int:total_unapproved_posts},
				unapproved_topics = unapproved_topics + {int:total_unapproved_topics}') . '
			WHERE id_board = {int:id_board}',
			array(
				'id_board' => $toBoard,
				'total_topics' => $totalTopics,
				'total_posts' => $totalPosts,
				'total_unapproved_topics' => $totalUnapprovedTopics,
				'total_unapproved_posts' => $totalUnapprovedPosts,
				'no_unapproved' => 0,
			)
		);

		if ($isRecycleDest) {
			$attributes = array(
				'id_board' => $toBoard,
				'approved' => 1,
				'unapproved_posts' => 0,
			);
		} else {
			$attributes = array('id_board' => $toBoard);
		}

		// Move the topic.  Done.  :P
		setTopicAttribute($topics, $attributes);

		// If this was going to the recycle bin, check what messages are being recycled, and remove them from the queue.
		if ($isRecycleDest && ($totalUnapprovedTopics || $totalUnapprovedPosts)) {
			$request = $this->db->query('', '
				SELECT id_msg
				FROM {db_prefix}messages
				WHERE id_topic IN ({array_int:topics})
					and approved = {int:not_approved}',
				array(
					'topics' => $topics,
					'not_approved' => 0,
				)
			);
			$approval_msgs = array();
			while ($row = $request->fetchAssoc())
				$approval_msgs[] = $row['id_msg'];
			$request->free();

			// Empty the approval queue for these, as we're going to approve them next.
			if (!empty($approval_msgs))
				$this->db->query('', '
					DELETE FROM {db_prefix}approval_queue
					WHERE id_msg IN ({array_int:message_list})
						AND id_attach = {int:id_attach}',
					array(
						'message_list' => $approval_msgs,
						'id_attach' => 0,
					)
				);

			// Get all the current max and mins.
			$topicAttribute = $this->topicAttribute($topics, array('id_topic', 'id_first_msg', 'id_last_msg'));
			$topicMaxMin = array();
			foreach ($topicAttribute as $row) {
				$topicMaxMin[$row['id_topic']] = array(
					'min' => $row['id_first_msg'],
					'max' => $row['id_last_msg'],
				);
			}

			// Check the MAX and MIN are correct.
			$request = $this->db->query('', '
				SELECT id_topic, MIN(id_msg) AS first_msg, MAX(id_msg) AS last_msg
				FROM {db_prefix}messages
				WHERE id_topic IN ({array_int:topics})
				GROUP BY id_topic',
				array(
					'topics' => $topics,
				)
			);
			while ($row = $request->fetchAssoc()) {
				// If not, update.
				if ($row['first_msg'] != $topicMaxMin[$row['id_topic']]['min'] || $row['last_msg'] != $topicMaxMin[$row['id_topic']]['max'])
					setTopicAttribute($row['id_topic'], array(
						'id_first_msg' => $row['first_msg'],
						'id_last_msg' => $row['last_msg'],
					));
			}
			$request->free();
		}

		$this->db->query('', '
			UPDATE {db_prefix}messages
			SET id_board = {int:id_board}' . ($isRecycleDest ? ',approved = {int:is_approved}' : '') . '
			WHERE id_topic IN ({array_int:topics})',
			array(
				'id_board' => $toBoard,
				'topics' => $topics,
				'is_approved' => 1,
			)
		);
		$this->db->query('', '
			UPDATE {db_prefix}log_reported
			SET id_board = {int:id_board}
			WHERE id_topic IN ({array_int:topics})',
			array(
				'id_board' => $toBoard,
				'topics' => $topics,
			)
		);
		$this->db->query('', '
			UPDATE {db_prefix}calendar
			SET id_board = {int:id_board}
			WHERE id_topic IN ({array_int:topics})',
			array(
				'id_board' => $toBoard,
				'topics' => $topics,
			)
		);

		// Mark target board as seen, if it was already marked as seen before.
		$request = $this->db->query('', '
			SELECT (IFNULL(lb.id_msg, 0) >= b.id_msg_updated) AS isSeen
			FROM {db_prefix}boards AS b
				LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = b.id_board AND lb.id_member = {int:current_member})
			WHERE b.id_board = {int:id_board}',
			array(
				'current_member' => $user_info['id'],
				'id_board' => $toBoard,
			)
		);
		list ($isSeen) = $request->fetchRow();
		$request->free();

		if (!empty($isSeen) && !$user_info['is_guest']) {

			markBoardsRead($toBoard);
		}
		
		// Update the cache?
		foreach ($topics as $topic_id)
			$this->cache->remove('topic_board-' . $topic_id);
		$updates = array_keys($fromBoards);
		$updates[] = $toBoard;

		updateLastMessages(array_unique($updates));

		// Update 'em pesky stats.
		updateTopicStats();

		updateMessageStats();
		updateSettings(array(
			'calendar_updated' => time(),
		));

		if ($log) {
			foreach ($topics as $topic) {
				logAction('move', array('topic' => $topic, 'board_from' => $fromCacheBoards[$topic], 'board_to' => $toBoard));
				sendNotifications($topic, 'move');
			}
		}
	}

	/**
	 * Called after a topic is moved to update $board_link and $topic_link to point
	 * to new location
	 *
	 * @param null|int $move_from The board the topic belongs to
	 * @param null|int $id_board The "current" board
	 * @param null|int $id_topic The topic id
	 * @return true|null
	 */
	function moveTopicConcurrence($move_from = null, $id_board = null, $id_topic = null)
	{
		global $scripturl;

		if (empty($move_from) || empty($id_board) || empty($id_topic))
			return true;

		if ($move_from == $id_board)
			return true;
		else {
			$request = $this->db->query('', '
				SELECT m.subject, b.name
				FROM {db_prefix}topics AS t
					LEFT JOIN {db_prefix}boards AS b ON (t.id_board = b.id_board)
					LEFT JOIN {db_prefix}messages AS m ON (t.id_first_msg = m.id_msg)
				WHERE t.id_topic = {int:topic_id}
				LIMIT 1',
				array(
					'topic_id' => $id_topic,
				)
			);
			list ($topic_subject, $board_name) = $request->fetchRow();
			$request->free();

			$board_link = '<a href="' . $scripturl . '?board=' . $id_board . '.0">' . $board_name . '</a>';
			$topic_link = '<a href="' . $scripturl . '?topic=' . $id_topic . '.0">' . $topic_subject . '</a>';
			$GLOBALS['elk']['errors']->fatal_lang_error('topic_already_moved', false, array($topic_link, $board_link));
		}
	}

	/**
	 * Determine if the topic has already been deleted by another user.
	 *
	 * What it does:
	 *  - If the topic has been removed and resides in the recycle bin, present confirm dialog
	 *  - If recycling is not enabled, or user confirms or topic is not in recycle simply returns
	 */
	function removeDeleteConcurrence()
	{
		global $modSettings, $board, $scripturl, $context;

		$recycled_enabled = !empty($modSettings['recycle_enable']) && !empty($modSettings['recycle_board']);

		if ($recycled_enabled && !empty($board)) {
			// Trying to removed from the recycle bin
			if (!isset($_GET['confirm_delete']) && $modSettings['recycle_board'] == $board) {
				if (isset($_REQUEST['msg'])) {
					$confirm_url = $scripturl . '?action=deletemsg;confirm_delete;topic=' . $context['current_topic'] . '.0;msg=' . $_REQUEST['msg'] . ';' . $context['session_var'] . '=' . $context['session_id'];
				} else {
					$confirm_url = $scripturl . '?action=removetopic2;confirm_delete;topic=' . $context['current_topic'] . '.0;' . $context['session_var'] . '=' . $context['session_id'];
				}

				// Give them a prompt before we remove the message
				$GLOBALS['elk']['errors']->fatal_lang_error('post_already_deleted', false, array($confirm_url));
			}
		}
	}

	/**
	 * Increase the number of views of this topic.
	 *
	 * @param int $id_topic the topic being viewed or whatnot.
	 */
	function increaseViewCounter($id_topic)
	{
		$this->db->query('', '
			UPDATE {db_prefix}topics
			SET num_views = num_views + 1
			WHERE id_topic = {int:current_topic}',
			array(
				'current_topic' => $id_topic,
			)
		);
	}

	/**
	 * Mark topic(s) as read by the given member, at the specified message.
	 *
	 * @param mixed[] $mark_topics array($id_member, $id_topic, $id_msg)
	 * @param bool $was_set = false - whether the topic has been previously read by the user
	 */
	function markTopicsRead($mark_topics, $was_set = false)
	{
		if (!is_array($mark_topics))
			return;

		$this->db->insert($was_set ? 'replace' : 'ignore',
			'{db_prefix}log_topics',
			array(
				'id_member' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'unwatched' => 'int',
			),
			$mark_topics,
			array('id_member', 'id_topic')
		);
	}

	/**
	 * Update user notifications for a topic... or the board it's in.
	 * @todo look at board notification...
	 *
	 * @param int $id_topic
	 * @param int $id_board
	 */
	function updateReadNotificationsFor($id_topic, $id_board)
	{
		global $user_info, $context;

		// Check for notifications on this topic OR board.
		$request = $this->db->query('', '
			SELECT sent, id_topic
			FROM {db_prefix}log_notify
			WHERE (id_topic = {int:current_topic} OR id_board = {int:current_board})
				AND id_member = {int:current_member}
			LIMIT 2',
			array(
				'current_board' => $id_board,
				'current_member' => $user_info['id'],
				'current_topic' => $id_topic,
			)
		);

		while ($row = $request->fetchAssoc()) {
			// Find if this topic is marked for notification...
			if (!empty($row['id_topic']))
				$context['is_marked_notify'] = true;

			// Only do this once, but mark the notifications as "not sent yet" for next time.
			if (!empty($row['sent'])) {
				$this->db->query('', '
					UPDATE {db_prefix}log_notify
					SET sent = {int:is_not_sent}
					WHERE (id_topic = {int:current_topic} OR id_board = {int:current_board})
						AND id_member = {int:current_member}',
					array(
						'current_board' => $id_board,
						'current_member' => $user_info['id'],
						'current_topic' => $id_topic,
						'is_not_sent' => 0,
					)
				);
				break;
			}
		}
		$request->free();
	}

	/**
	 * How many topics are still unread since (last visit)
	 *
	 * @param int $id_board
	 * @param int $id_msg_last_visit
	 * @return int
	 */
	function getUnreadCountSince($id_board, $id_msg_last_visit)
	{
		global $user_info;

		$request = $this->db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}topics AS t
				LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = {int:current_board} AND lb.id_member = {int:current_member})
				LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = {int:current_member})
			WHERE t.id_board = {int:current_board}
				AND t.id_last_msg > IFNULL(lb.id_msg, 0)
				AND t.id_last_msg > IFNULL(lt.id_msg, 0)' .
			(empty($id_msg_last_visit) ? '' : '
				AND t.id_last_msg > {int:id_msg_last_visit}'),
			array(
				'current_board' => $id_board,
				'current_member' => $user_info['id'],
				'id_msg_last_visit' => (int)$id_msg_last_visit,
			)
		);
		list ($unread) = $request->fetchRow();
		$request->free();

		return $unread;
	}

	/**
	 * Returns whether this member has notification turned on for the specified topic.
	 *
	 * @param int $id_member
	 * @param int $id_topic
	 * @return bool
	 */
	function hasTopicNotification($id_member, $id_topic)
	{
		// Find out if they have notification set for this topic already.
		$request = $this->db->query('', '
			SELECT id_member
			FROM {db_prefix}log_notify
			WHERE id_member = {int:current_member}
				AND id_topic = {int:current_topic}
			LIMIT 1',
			array(
				'current_member' => $id_member,
				'current_topic' => $id_topic,
			)
		);
		$hasNotification = $request->numRows() != 0;
		$request->free();

		return $hasNotification;
	}

	/**
	 * Set topic notification on or off for the given member.
	 *
	 * @param int $id_member
	 * @param int $id_topic
	 * @param bool $on
	 */
	function setTopicNotification($id_member, $id_topic, $on = false)
	{
		if ($on) {
			// Attempt to turn notifications on.
			$this->db->insert('ignore',
				'{db_prefix}log_notify',
				array('id_member' => 'int', 'id_topic' => 'int'),
				array($id_member, $id_topic),
				array('id_member', 'id_topic')
			);
		} else {
			// Just turn notifications off.
			$this->db->query('', '
				DELETE FROM {db_prefix}log_notify
				WHERE id_member = {int:current_member}
					AND id_topic = {int:current_topic}',
				array(
					'current_member' => $id_member,
					'current_topic' => $id_topic,
				)
			);
		}
	}

	/**
	 * Get the previous topic from where we are.
	 *
	 * @param int $id_topic origin topic id
	 * @param int $id_board board id
	 * @param int $id_member = 0 member id
	 * @param bool $includeUnapproved = false whether to include unapproved topics
	 * @param bool $includeStickies = true whether to include sticky topics
	 */
	function previousTopic($id_topic, $id_board, $id_member = 0, $includeUnapproved = false, $includeStickies = true)
	{
		return topicPointer($id_topic, $id_board, false, $id_member = 0, $includeUnapproved = false, $includeStickies = true);
	}

	/**
	 * Get the next topic from where we are.
	 *
	 * @param int $id_topic origin topic id
	 * @param int $id_board board id
	 * @param int $id_member = 0 member id
	 * @param bool $includeUnapproved = false whether to include unapproved topics
	 * @param bool $includeStickies = true whether to include sticky topics
	 */
	function nextTopic($id_topic, $id_board, $id_member = 0, $includeUnapproved = false, $includeStickies = true)
	{
		return topicPointer($id_topic, $id_board, true, $id_member = 0, $includeUnapproved = false, $includeStickies = true);
	}

	/**
	 * Advance topic pointer.
	 * (in either direction)
	 * This function is used by previousTopic() and nextTopic()
	 * The boolean parameter $next determines direction.
	 *
	 * @param int $id_topic origin topic id
	 * @param int $id_board board id
	 * @param bool $next = true whether to increase or decrease the pointer
	 * @param int $id_member = 0 member id
	 * @param bool $includeUnapproved = false whether to include unapproved topics
	 * @param bool $includeStickies = true whether to include sticky topics
	 */
	function topicPointer($id_topic, $id_board, $next = true, $id_member = 0, $includeUnapproved = false, $includeStickies = true)
	{
		

		$request = $this->db->query('', '
			SELECT t2.id_topic
			FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}topics AS t2 ON (' .
			(empty($includeStickies) ? '
					t2.id_last_msg {raw:strictly} t.id_last_msg' : '
					(t2.id_last_msg {raw:strictly} t.id_last_msg AND t2.is_sticky {raw:strictly_equal} t.is_sticky) OR t2.is_sticky {raw:strictly} t.is_sticky')
			. ')
			WHERE t.id_topic = {int:current_topic}
				AND t2.id_board = {int:current_board}' .
			($includeUnapproved ? '' : '
					AND (t2.approved = {int:is_approved} OR (t2.id_member_started != {int:id_member_started} AND t2.id_member_started = {int:current_member}))'
			) . '
			ORDER BY' . (
			$includeStickies ? '
					t2.is_sticky {raw:sorting},' :
				'') .
			' t2.id_last_msg {raw:sorting}
			LIMIT 1',
			array(
				'strictly' => $next ? '<' : '>',
				'strictly_equal' => $next ? '<=' : '>=',
				'sorting' => $next ? 'DESC' : '',
				'current_board' => $id_board,
				'current_member' => $id_member,
				'current_topic' => $id_topic,
				'is_approved' => 1,
				'id_member_started' => 0,
			)
		);

		// Was there any?
		if ($request->numRows() == 0) {
			$request->free();

			// Roll over - if we're going prev, get the last - otherwise the first.
			$request = $this->db->query('', '
				SELECT id_topic
				FROM {db_prefix}topics
				WHERE id_board = {int:current_board}' .
				($includeUnapproved ? '' : '
					AND (approved = {int:is_approved} OR (id_member_started != {int:id_member_started} AND id_member_started = {int:current_member}))') . '
				ORDER BY' . (
				$includeStickies ? ' is_sticky {raw:sorting},' : '') .
				' id_last_msg {raw:sorting}
				LIMIT 1',
				array(
					'sorting' => $next ? 'DESC' : '',
					'current_board' => $id_board,
					'current_member' => $id_member,
					'is_approved' => 1,
					'id_member_started' => 0,
				)
			);
		}
		// Now you can be sure $topic is the id_topic to view.
		list ($topic) = $request->fetchRow();
		$request->free();

		return $topic;
	}

	/**
	 * Set off/on unread reply subscription for a topic
	 *
	 * @param int $id_member
	 * @param int $topic
	 * @param bool $on = false
	 */
	function setTopicWatch($id_member, $topic, $on = false)
	{
		global $user_info;

		// find the current entry if it exists that is
		$was_set = getLoggedTopics($user_info['id'], array($topic));

		// Set topic unwatched on/off for this topic.
		$this->db->insert(empty($was_set[$topic]) ? 'ignore' : 'replace',
			'{db_prefix}log_topics',
			array('id_member' => 'int', 'id_topic' => 'int', 'id_msg' => 'int', 'unwatched' => 'int'),
			array($id_member, $topic, !empty($was_set[$topic]['id_msg']) ? $was_set[$topic]['id_msg'] : 0, $on ? 1 : 0),
			array('id_member', 'id_topic')
		);
	}

	/**
	 * Get all the details for a given topic
	 * - returns the basic topic information when $full is false
	 * - returns topic details, subject, last message read, etc when full is true
	 * - uses any integration information (value selects, tables and parameters) if passed and full is true
	 *
	 * @param mixed[]|int $topic_parameters can also accept a int value for a topic
	 * @param string $full defines the values returned by the function:
	 *    - if empty returns only the data from {db_prefix}topics
	 *    - if 'message' returns also information about the message (subject, body, etc.)
	 *    - if 'starter' returns also information about the topic starter (id_member and poster_name)
	 *    - if 'all' returns additional infos about the read/unwatched status
	 * @param string[] $selects (optional from integration)
	 * @param string[] $tables (optional from integration)
	 * @return Topic
	 */
	function getTopicInfo($topic_parameters, $full = '', $selects = array(), $tables = array())
	{
		global $user_info, $modSettings, $board;

		// Nothing to do
		if (empty($topic_parameters))
			return false;

		// Build what we can with what we were given
		if (!is_array($topic_parameters))
			$topic_parameters = array(
				'topic' => $topic_parameters,
				'member' => $user_info['id'],
				'board' => (int)$board,
			);

		$messages_table = $full === 'message' || $full === 'all' || $full === 'starter';
		$members_table = $full === 'starter';
		$logs_table = $full === 'all';

		// Create the query, taking full and integration in to account
		$request = $this->db->query('', '
			SELECT
				t.id_topic, t.is_sticky, t.id_board, t.id_first_msg, t.id_last_msg,
				t.id_member_started, t.id_member_updated, t.id_poll,
				t.num_replies, t.num_views, t.num_likes, t.locked, t.redirect_expires,
				t.id_redirect_topic, t.unapproved_posts, t.approved' . ($messages_table ? ',
				ms.subject, ms.body, ms.id_member, ms.poster_time, ms.approved as msg_approved' : '') . ($members_table ? ',
				IFNULL(mem.real_name, ms.poster_name) AS poster_name' : '') . ($logs_table ? ',
				' . ($user_info['is_guest'] ? 't.id_last_msg + 1' : 'IFNULL(lt.id_msg, IFNULL(lmr.id_msg, -1)) + 1') . ' AS new_from
				' . (!empty($modSettings['recycle_board']) && $modSettings['recycle_board'] == $board ? ', t.id_previous_board, t.id_previous_topic' : '') . '
				' . (!$user_info['is_guest'] ? ', IFNULL(lt.unwatched, 0) as unwatched' : '') : '') .
			(!empty($selects) ? ', ' . implode(', ', $selects) : '') . '
			FROM {db_prefix}topics AS t' . ($messages_table ? '
				INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)' : '') . ($members_table ? '
				LEFT JOIN {db_prefix}members as mem ON (mem.id_member = ms.id_member)' : '') . ($logs_table && !$user_info['is_guest'] ? '
				LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = {int:topic} AND lt.id_member = {int:member})
				LEFT JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = {int:board} AND lmr.id_member = {int:member})' : '') . (!empty($tables) ? '
				' . implode("\n\t\t\t", $tables) : '') . '
			WHERE t.id_topic = {int:topic}
			LIMIT 1',
			$topic_parameters
		);
		$topic_info = array();
		if ($request !== false)
			$topic_info = $request->fetchAssoc();
		$request->free();

		return new Topic($topic_info);
	}

	/**
	 * Get all the details for a given topic and message.
	 * Respects permissions and post moderation
	 *
	 * @param int $topic id of a topic
	 * @param int|null $msg the id of a message, if empty, t.id_first_msg is used
	 * @return Topic
	 */
	function getTopicInfoByMsg($topic, $msg = null)
	{
		global $user_info, $modSettings;

		// Nothing to do
		if (empty($topic))
			return false;

		$request = $this->db->query('', '
			SELECT
				t.locked, t.num_replies, t.id_member_started, t.id_first_msg,
				m.id_msg, m.id_member, m.poster_time, m.subject, m.smileys_enabled, m.body, m.icon,
				m.modified_time, m.modified_name, m.approved
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = {int:current_topic})
			WHERE m.id_msg = {raw:id_msg}
				AND m.id_topic = {int:current_topic}' . (allowedTo('modify_any') || allowedTo('approve_posts') ? '' : (!$modSettings['postmod_active'] ? '
				AND (m.id_member != {int:guest_id} AND m.id_member = {int:current_member})' : '
				AND (m.approved = {int:is_approved} OR (m.id_member != {int:guest_id} AND m.id_member = {int:current_member}))')),
			array(
				'current_member' => $user_info['id'],
				'current_topic' => $topic,
				'id_msg' => empty($msg) ? 't.id_first_msg' : $msg,
				'is_approved' => 1,
				'guest_id' => 0,
			)
		);

		$topic_info = array();
		if ($request !== false)
			$topic_info = $request->fetchAssoc();
		$request->free();

		return new Topic($topic_info);
	}

	/**
	 * So long as you are sure... all old posts will be gone.
	 * Used in Maintenance.controller.php to prune old topics.
	 *
	 * @param int[] $boards
	 * @param string $delete_type
	 * @param boolean $exclude_stickies
	 * @param int $older_than
	 */
	function removeOldTopics(array $boards, $delete_type, $exclude_stickies, $older_than)
	{
		// Custom conditions.
		$condition = '';
		$condition_params = array(
			'boards' => $boards,
			'poster_time' => $older_than,
		);

		// Just moved notice topics?
		if ($delete_type == 'moved') {
			$condition .= '
				AND m.icon = {string:icon}
				AND t.locked = {int:locked}';
			$condition_params['icon'] = 'moved';
			$condition_params['locked'] = 1;
		} // Otherwise, maybe locked topics only?
		elseif ($delete_type == 'locked') {
			$condition .= '
				AND t.locked = {int:locked}';
			$condition_params['locked'] = 1;
		}

		// Exclude stickies?
		if ($exclude_stickies) {
			$condition .= '
				AND t.is_sticky = {int:is_sticky}';
			$condition_params['is_sticky'] = 0;
		}

		// All we're gonna do here is grab the id_topic's and send them to removeTopics().
		$request = $this->db->query('', '
			SELECT t.id_topic
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_last_msg)
			WHERE
				m.poster_time < {int:poster_time}' . $condition . '
				AND t.id_board IN ({array_int:boards})',
			$condition_params
		);
		$topics = array();
		while ($row = $request->fetchAssoc())
			$topics[] = $row['id_topic'];
		$request->free();

		$this->removeTopics($topics, false, true);
	}

	/**
	 * Retrieve all topics started by the given member.
	 *
	 * @param int $memberID
	 * @return int[]
	 */
	function topicsStartedBy($memberID)
	{
		// Fetch all topics started by this user.
		$request = $this->db->query('', '
			SELECT t.id_topic
			FROM {db_prefix}topics AS t
			WHERE t.id_member_started = {int:selected_member}',
			array(
				'selected_member' => $memberID,
			)
		);
		$topicIDs = array();
		while ($row = $request->fetchAssoc())
			$topicIDs[] = $row['id_topic'];
		$request->free();

		return $topicIDs;
	}

	/**
	 * Retrieve the messages of the given topic, that are at or after
	 * a message.
	 * Used by split topics actions.
	 *
	 * @param int $id_topic
	 * @param int $id_msg
	 * @param bool $include_current = false
	 * @param bool $only_approved = false
	 *
	 * @return int[] message ids
	 */
	function messagesSince($id_topic, $id_msg, $include_current = false, $only_approved = false)
	{
		// Fetch the message IDs of the topic that are at or after the message.
		$request = $this->db->query('', '
			SELECT id_msg
			FROM {db_prefix}messages
			WHERE id_topic = {int:current_topic}
				AND id_msg ' . ($include_current ? '>=' : '>') . ' {int:last_msg}' . ($only_approved ? '
				AND approved = {int:approved}' : ''),
			array(
				'current_topic' => $id_topic,
				'last_msg' => $id_msg,
				'approved' => 1,
			)
		);
		$messages = array();
		while ($row = $request->fetchAssoc())
			$messages[] = $row['id_msg'];
		$request->free();

		return $messages;
	}

	/**
	 * This function returns the number of messages in a topic,
	 * posted after $id_msg.
	 *
	 * @param int $id_topic
	 * @param int $id_msg
	 * @param bool $include_current = false
	 * @param bool $only_approved = false
	 *
	 * @return int
	 */
	function countMessagesSince($id_topic, $id_msg, $include_current = false, $only_approved = false)
	{
		// Give us something to work with
		if (empty($id_topic) || empty($id_msg))
			return false;

		$request = $this->db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}messages
			WHERE id_topic = {int:current_topic}
				AND id_msg ' . ($include_current ? '>=' : '>') . ' {int:last_msg}' . ($only_approved ? '
				AND approved = {int:approved}' : '') . '
			LIMIT 1',
			array(
				'current_topic' => $id_topic,
				'last_msg' => $id_msg,
				'approved' => 1,
			)
		);
		list ($count) = $request->fetchRow();
		$request->free();

		return $count;
	}

	/**
	 * Returns how many messages are in a topic before the specified message id.
	 * Used in display to compute the start value for a specific message.
	 *
	 * @param int $id_topic
	 * @param int $id_msg
	 * @param bool $include_current = false
	 * @param bool $only_approved = false
	 * @param bool $include_own = false
	 * @return int
	 */
	function countMessagesBefore($id_topic, $id_msg, $include_current = false, $only_approved = false, $include_own = false)
	{
		global $user_info;

		$request = $this->db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}messages
			WHERE id_msg ' . ($include_current ? '<=' : '<') . ' {int:id_msg}
				AND id_topic = {int:current_topic}' . ($only_approved ? '
				AND (approved = {int:is_approved}' . ($include_own ? '
				OR id_member = {int:current_member}' : '') . ')' : ''),
			array(
				'current_member' => $user_info['id'],
				'current_topic' => $id_topic,
				'id_msg' => $id_msg,
				'is_approved' => 1,
			)
		);
		list ($count) = $request->fetchRow();
		$request->free();

		return $count;
	}

	/**
	 * Select a part of the messages in a topic.
	 *
	 * @param int $topic
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page The number of items to show per page
	 * @param mixed[] $messages
	 * @param bool $only_approved
	 * @return array
	 */
	function selectMessages($topic, $start, $items_per_page, $messages = array(), $only_approved = false)
	{
		// Get the messages and stick them into an array.
		$request = $this->db->query('', '
			SELECT m.subject, IFNULL(mem.real_name, m.poster_name) AS real_name, m.poster_time, m.body, m.id_msg, m.smileys_enabled, m.id_member
			FROM {db_prefix}messages AS m
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			WHERE m.id_topic = {int:current_topic}' . (empty($messages['before']) ? '' : '
				AND m.id_msg < {int:msg_before}') . (empty($messages['after']) ? '' : '
				AND m.id_msg > {int:msg_after}') . (empty($messages['excluded']) ? '' : '
				AND m.id_msg NOT IN ({array_int:no_split_msgs})') . (empty($messages['included']) ? '' : '
				AND m.id_msg IN ({array_int:split_msgs})') . (!$only_approved ? '' : '
				AND approved = {int:is_approved}') . '
			ORDER BY m.id_msg DESC
			LIMIT {int:start}, {int:messages_per_page}',
			array(
				'current_topic' => $topic,
				'no_split_msgs' => !empty($messages['excluded']) ? $messages['excluded'] : array(),
				'split_msgs' => !empty($messages['included']) ? $messages['included'] : array(),
				'is_approved' => 1,
				'start' => $start,
				'messages_per_page' => $items_per_page,
				'msg_before' => !empty($messages['before']) ? (int)$messages['before'] : 0,
				'msg_after' => !empty($messages['after']) ? (int)$messages['after'] : 0,
			)
		);

		$messages = array();
		$parser = $GLOBALS['elk']['bbc'];

		for ($counter = 0; $row = $request->fetchAssoc(); $counter++) {
			$row['body'] = censor($row['subject']);
			$row['body'] = censor($row['body']);

			$row['body'] = $parser->parseMessage($row['body'], (bool)$row['smileys_enabled']);

			$messages[$row['id_msg']] = array(
				'id' => $row['id_msg'],
				'alternate' => $counter % 2,
				'subject' => $row['subject'],
				'time' => standardTime($row['poster_time']),
				'html_time' => htmlTime($row['poster_time']),
				'timestamp' => forum_time(true, $row['poster_time']),
				'body' => $row['body'],
				'poster' => $row['real_name'],
				'id_poster' => $row['id_member'],
			);
		}
		$request->free();

		return $messages;
	}

	/**
	 * Loads all the messages of a topic
	 * Used when printing or other functions that require a topic listing
	 *
	 * @param int $topic
	 * @param string $render defaults to print style rendering for parse_bbc
	 *
	 */
	function topicMessages($topic, $render = 'print')
	{
		global $modSettings, $user_info;

		$request = $this->db->query('', '
			SELECT subject, poster_time, body, IFNULL(mem.real_name, poster_name) AS poster_name, id_msg
			FROM {db_prefix}messages AS m
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			WHERE m.id_topic = {int:current_topic}' . ($modSettings['postmod_active'] && !allowedTo('approve_posts') ? '
				AND (m.approved = {int:is_approved}' . ($user_info['is_guest'] ? '' : ' OR m.id_member = {int:current_member}') . ')' : '') . '
			ORDER BY m.id_msg',
			array(
				'current_topic' => $topic,
				'is_approved' => 1,
				'current_member' => $user_info['id'],
			)
		);

		$posts = array();
		$parser = $GLOBALS['elk']['bbc'];

		if ($render === 'print') {
			$parser->getCodes()->setForPrinting();
		}

		while ($row = $request->fetchAssoc()) {
			// Censor the subject and message.
			$row['body'] = censor($row['subject']);
			$row['body'] = censor($row['body']);

			$posts[$row['id_msg']] = array(
				'subject' => $row['subject'],
				'member' => $row['poster_name'],
				'time' => standardTime($row['poster_time'], false),
				'html_time' => htmlTime($row['poster_time']),
				'timestamp' => forum_time(true, $row['poster_time']),
				'body' => $parser->parseMessage($row['body'], $render !== 'print'),
				'id_msg' => $row['id_msg'],
			);
		}
		$request->free();

		return $posts;
	}

	/**
	 * Load message image attachments for use in the print page function
	 * Returns array of file attachment name along with width/height properties
	 * Will only return approved attachments
	 *
	 * @param int[] $id_messages
	 * @return array
	 */
	function messagesAttachments($id_messages)
	{
		global $modSettings;
		

		$request = $this->db->query('', '
			SELECT
				a.id_attach, a.id_msg, a.approved, a.width, a.height, a.file_hash, a.filename, a.id_folder, a.mime_type
			FROM {db_prefix}attachments AS a
			WHERE a.id_msg IN ({array_int:message_list})
				AND a.attachment_type = {int:attachment_type}',
			array(
				'message_list' => $id_messages,
				'attachment_type' => 0,
				'is_approved' => 1,
			)
		);
		$temp = array();
		$printattach = array();
		while ($row = $request->fetchAssoc()) {
			$temp[$row['id_attach']] = $row;
			if (!isset($printattach[$row['id_msg']]))
				$printattach[$row['id_msg']] = array();
		}
		$request->free();
		ksort($temp);

		// Load them into $context so the template can use them
		foreach ($temp as $row) {
			if (!empty($row['width']) && !empty($row['height'])) {
				if (!empty($modSettings['max_image_width']) && (empty($modSettings['max_image_height']) || $row['height'] * ($modSettings['max_image_width'] / $row['width']) <= $modSettings['max_image_height'])) {
					if ($row['width'] > $modSettings['max_image_width']) {
						$row['height'] = floor($row['height'] * ($modSettings['max_image_width'] / $row['width']));
						$row['width'] = $modSettings['max_image_width'];
					}
				} elseif (!empty($modSettings['max_image_width'])) {
					if ($row['height'] > $modSettings['max_image_height']) {
						$row['width'] = floor($row['width'] * $modSettings['max_image_height'] / $row['height']);
						$row['height'] = $modSettings['max_image_height'];
					}
				}

				$row['filename'] = getAttachmentFilename($row['id_attach'], $row['file_hash'], $row['id_folder']);

				// save for the template
				$printattach[$row['id_msg']][] = $row;
			}
		}

		return $printattach;
	}

	/**
	 * Retrieve unapproved posts of the member
	 * in a specific topic
	 *
	 * @param int $id_topic topic id
	 * @param int $id_member member id
	 */
	function unapprovedPosts($id_topic, $id_member)
	{
		

		// not all guests are the same!
		if (empty($id_member))
			return array();

		$request = $this->db->query('', '
				SELECT COUNT(id_member) AS my_unapproved_posts
				FROM {db_prefix}messages
				WHERE id_topic = {int:current_topic}
					AND id_member = {int:current_member}
					AND approved = 0',
			array(
				'current_topic' => $id_topic,
				'current_member' => $id_member,
			)
		);
		list ($myUnapprovedPosts) = $request->fetchRow();
		$request->free();

		return $myUnapprovedPosts;
	}

	/**
	 * Update topic info after a successful split of a topic.
	 *
	 * @param mixed[] $options
	 * @param int $id_board
	 */
	function updateSplitTopics($options, $id_board)
	{
		

		// Any associated reported posts better follow...
		$this->db->query('', '
			UPDATE {db_prefix}log_reported
			SET id_topic = {int:id_topic}
			WHERE id_msg IN ({array_int:split_msgs})
				AND type = {string:a_message}',
			array(
				'split_msgs' => $options['splitMessages'],
				'id_topic' => $options['split2_ID_TOPIC'],
				'a_message' => 'msg',
			)
		);

		// Mess with the old topic's first, last, and number of messages.
		setTopicAttribute($options['split1_ID_TOPIC'], array(
			'num_replies' => $options['split1_replies'],
			'id_first_msg' => $options['split1_first_msg'],
			'id_last_msg' => $options['split1_last_msg'],
			'id_member_started' => $options['split1_firstMem'],
			'id_member_updated' => $options['split1_lastMem'],
			'unapproved_posts' => $options['split1_unapprovedposts'],
		));

		// Now, put the first/last message back to what they should be.
		setTopicAttribute($options['split2_ID_TOPIC'], array(
			'id_first_msg' => $options['split2_first_msg'],
			'id_last_msg' => $options['split2_last_msg'],
		));

		// If the new topic isn't approved ensure the first message flags
		// this just in case.
		if (!$options['split2_approved'])
			$this->db->query('', '
				UPDATE {db_prefix}messages
				SET approved = {int:approved}
				WHERE id_msg = {int:id_msg}
					AND id_topic = {int:id_topic}',
				array(
					'approved' => 0,
					'id_msg' => $options['split2_first_msg'],
					'id_topic' => $options['split2_ID_TOPIC'],
				)
			);

		// The board has more topics now (Or more unapproved ones!).
		$this->db->query('', '
			UPDATE {db_prefix}boards
			SET ' . ($options['split2_approved'] ? '
				num_topics = num_topics + 1' : '
				unapproved_topics = unapproved_topics + 1') . '
			WHERE id_board = {int:id_board}',
			array(
				'id_board' => $id_board,
			)
		);
	}

	/**
	 * Find out who started a topic, and the lock status
	 *
	 * @param int $topic
	 * @return array with id_member_started and locked
	 */
	function topicStatus($topic)
	{
		// Find out who started the topic, and the lock status.
		$starter = $this->topicAttribute($topic, array('id_member_started', 'locked'));

		return array($starter['id_member_started'], $starter['locked']);
	}

	/**
	 * Set attributes for a topic, i.e. locked, sticky.
	 * Parameter $attributes is an array where the key is the column name of the
	 * attribute to change, and the value is... the new value of the attribute.
	 * It sets the new value for the attribute as passed to it.
	 * <b>It is currently limited to integer values only</b>
	 *
	 * @param int|int[] $topic
	 * @param mixed[] $attributes
	 * @todo limited to integer attributes
	 */
	function setTopicAttribute($topic, $attributes)
	{
		

		$update = array();
		foreach ($attributes as $key => $attr) {
			$attributes[$key] = (int)$attr;
			$update[] = '
					' . $key . ' = {int:' . $key . '}';
		}

		if (empty($update))
			return false;

		$attributes['current_topic'] = (array)$topic;

		$this->db->query('', '
			UPDATE {db_prefix}topics
			SET ' . implode(',', $update) . '
			WHERE id_topic IN ({array_int:current_topic})',
			$attributes
		);

		return $this->db->affected_rows();
	}

	/**
	 * Retrieve the locked or sticky status of a topic.
	 *
	 * @param int|int[] $id_topic topic to get the status for
	 * @param string|string[] $attributes Basically the column names
	 * @return int|int[]
	 */
	function topicAttribute($id_topic, $attributes)
	{
		

		// @todo maybe add a filer for known attributes... or not
		// 	$attributes = array(
		// 		'locked' => 'locked',
		// 		'sticky' => 'is_sticky',
		// 	);

		// check the lock status
		$request = $this->db->query('', '
			SELECT {raw:attribute}
			FROM {db_prefix}topics
			WHERE id_topic IN ({array_int:current_topic})',
			array(
				'current_topic' => (array)$id_topic,
				'attribute' => implode(',', (array)$attributes),
			)
		);

		if (is_array($id_topic)) {
			$status = array();
			while ($row = $request->fetchAssoc())
				$status[] = $row;
		} else {
			$status = $request->fetchAssoc();
		}
		$request->free();

		return $status;
	}

	/**
	 * Retrieve some topic attributes based on the user:
	 *   - locked
	 *   - notify
	 *   - is_sticky
	 *   - id_poll
	 *   - id_last_msg
	 *   - id_member of the first message in the topic
	 *   - id_first_msg
	 *   - subject of the first message in the topic
	 *   - last_post_time that is poster_time if poster_time > modified_time, or
	 *       modified_time otherwise
	 *
	 * @param int $id_topic topic to get the status for
	 * @param int $user a user id
	 * @return mixed[]
	 */
	function topicUserAttributes($id_topic, $user)
	{
		

		$request = $this->db->query('', '
			SELECT
				t.locked, IFNULL(ln.id_topic, 0) AS notify, t.is_sticky, t.id_poll,
				t.id_last_msg, mf.id_member, t.id_first_msg, mf.subject,
				CASE WHEN ml.poster_time > ml.modified_time THEN ml.poster_time ELSE ml.modified_time END AS last_post_time
			FROM {db_prefix}topics AS t
				LEFT JOIN {db_prefix}log_notify AS ln ON (ln.id_topic = t.id_topic AND ln.id_member = {int:current_member})
				LEFT JOIN {db_prefix}messages AS mf ON (mf.id_msg = t.id_first_msg)
				LEFT JOIN {db_prefix}messages AS ml ON (ml.id_msg = t.id_last_msg)
			WHERE t.id_topic = {int:current_topic}
			LIMIT 1',
			array(
				'current_member' => $user,
				'current_topic' => $id_topic,
			)
		);
		$return = $request->fetchAssoc();
		$request->free();

		return $return;
	}

	/**
	 * Retrieve some details about the topic
	 *
	 * @param int[] $topics an array of topic id
	 */
	function topicsDetails($topics)
	{
		$returns = $this->topicAttribute($topics, array('id_topic', 'id_member_started', 'id_board', 'locked', 'approved', 'unapproved_posts'));

		return $returns;
	}

	/**
	 * Toggle sticky status for the passed topics and logs the action.
	 *
	 * @param int[] $topics
	 * @param bool $log If true the action is logged
	 * @return int Number of topics toggled
	 */
	function toggleTopicSticky($topics, $log = false)
	{
		

		$topics = is_array($topics) ? $topics : array($topics);

		$this->db->query('', '
			UPDATE {db_prefix}topics
			SET is_sticky = CASE WHEN is_sticky = 1 THEN 0 ELSE 1 END
			WHERE id_topic IN ({array_int:sticky_topic_ids})',
			array(
				'sticky_topic_ids' => $topics,
			)
		);

		$toggled = $this->db->affected_rows();

		if ($log) {
			// Get the board IDs and Sticky status
			$topicAttributes = $this->topicAttribute($topics, array('id_topic', 'id_board', 'is_sticky'));
			$stickyCacheBoards = array();
			$stickyCacheStatus = array();
			foreach ($topicAttributes as $row) {
				$stickyCacheBoards[$row['id_topic']] = $row['id_board'];
				$stickyCacheStatus[$row['id_topic']] = empty($row['is_sticky']);
			}

			foreach ($topics as $topic) {
				logAction($stickyCacheStatus[$topic] ? 'unsticky' : 'sticky', array('topic' => $topic, 'board' => $stickyCacheBoards[$topic]));
				sendNotifications($topic, 'sticky');
			}
		}

		return $toggled;
	}

	/**
	 * Get topics from the log_topics table belonging to a certain user
	 *
	 * @param int $member a member id
	 * @param int[] $topics an array of topics
	 * @return array an array of topics in the table (key) and its unwatched status (value)
	 *
	 * @todo find a better name
	 */
	function getLoggedTopics($member, $topics)
	{
		

		$request = $this->db->query('', '
			SELECT id_topic, id_msg, unwatched
			FROM {db_prefix}log_topics
			WHERE id_topic IN ({array_int:selected_topics})
				AND id_member = {int:current_user}',
			array(
				'selected_topics' => $topics,
				'current_user' => $member,
			)
		);
		$logged_topics = array();
		while ($row = $request->fetchAssoc())
			$logged_topics[$row['id_topic']] = $row;
		$request->free();

		return $logged_topics;
	}

	/**
	 * Returns a list of topics ids and their subjects
	 *
	 * @param int[] $topic_ids
	 * @return array
	 * @todo combine with another function that does the same thing
	 */
	function topicsList($topic_ids)
	{
		global $modSettings;

		// you have to want *something* from this function
		if (empty($topic_ids))
			return array();

		$topics = array();

		$result = $this->db->query('', '
			SELECT t.id_topic, m.subject
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
			WHERE {query_see_board}
				AND t.id_topic IN ({array_int:topic_list})' . ($modSettings['postmod_active'] ? '
				AND t.approved = {int:is_approved}' : '') . '
			LIMIT {int:limit}',
			array(
				'topic_list' => $topic_ids,
				'is_approved' => 1,
				'limit' => count($topic_ids),
			)
		);
		while ($row = $result->fetchAssoc()) {
			$topics[$row['id_topic']] = array(
				'id_topic' => $row['id_topic'],
				'subject' => censor($row['subject']),
			);
		}
		$result->free();

		return $topics;
	}

	/**
	 * Get each post and poster in this topic and take care of user settings such as
	 * limit or sort direction.
	 *
	 * @param int $topic
	 * @param mixed[] $limit
	 * @param boolean $sort set to false for a desc sort
	 * @return array
	 */
	function getTopicsPostsAndPoster($topic, $limit, $sort)
	{
		global $modSettings, $user_info;

		$topic_details = array(
			'messages' => array(),
			'all_posters' => array(),
		);

		$request = $this->db->query('display_get_post_poster', '
			SELECT id_msg, id_member, approved
			FROM {db_prefix}messages
			WHERE id_topic = {int:current_topic}' . (!$modSettings['postmod_active'] || allowedTo('approve_posts') ? '' : '
			GROUP BY id_msg
			HAVING (approved = {int:is_approved}' . ($user_info['is_guest'] ? '' : ' OR id_member = {int:current_member}') . ')') . '
			ORDER BY id_msg ' . ($sort ? '' : 'DESC') . ($limit['messages_per_page'] == -1 ? '' : '
			LIMIT ' . $limit['start'] . ', ' . $limit['offset']),
			array(
				'current_member' => $user_info['id'],
				'current_topic' => $topic,
				'is_approved' => 1,
				'blank_id_member' => 0,
			)
		);
		while ($row = $request->fetchAssoc())
		{
			if (!empty($row['id_member']))
			{
				$topic_details['all_posters'][$row['id_msg']] = $row['id_member'];
			}

			$topic_details['messages'][] = $row['id_msg'];
		}
		$request->free();

		return $topic_details;
	}

	/**
	 * Remove a batch of messages (or topics)
	 *
	 * @param int[] $messages
	 * @param mixed[] $messageDetails
	 * @param string $type = replies
	 *
	 * @todo either remove messages or topics. Don't do both in a single function
	 */
	function removeMessages($messages, $messageDetails, $type = 'replies')
	{
		global $modSettings;

		// @todo something's not right, removeMessage() does check permissions,
		// removeTopics() doesn't

		$remover = new MessagesDelete($modSettings['recycle_enable'], $modSettings['recycle_board']);
		foreach ($messages as $post) {
			$remover->removeMessage($post);
		}
	}

	/**
	 * Approve a batch of posts (or topics in their own right)
	 *
	 * @param int[] $messages
	 * @param mixed[] $messageDetails
	 * @param string $type = replies
	 */
	function approveMessages($messages, $messageDetails, $type = 'replies')
	{
		if ($type == 'topics') {
			approveTopics($messages, true, true);
		} else {
			approvePosts($messages);

			// and tell the world about it again
			foreach ($messages as $post)
				logAction('approve', array('topic' => $messageDetails[$post]['topic'], 'subject' => $messageDetails[$post]['subject'], 'member' => $messageDetails[$post]['member'], 'board' => $messageDetails[$post]['board']));
		}
	}

	/**
	 * Approve topics, all we got.
	 *
	 * @param int[] $topics array of topics ids
	 * @param bool $approve = true
	 * @param bool $log if true logs the action.
	 */
	function approveTopics($topics, $approve = true, $log = false)
	{
		global $board;

		if (!is_array($topics))
			$topics = array($topics);

		if (empty($topics))
			return false;

		$approve_type = $approve ? 0 : 1;

		if ($log) {
			$log_action = $approve ? 'approve_topic' : 'unapprove_topic';

			// We need unapproved topic ids, their authors and the subjects!
			$request = $this->db->query('', '
				SELECT t.id_topic, t.id_member_started, m.subject
				FROM {db_prefix}topics as t
					LEFT JOIN {db_prefix}messages AS m ON (t.id_first_msg = m.id_msg)
				WHERE t.id_topic IN ({array_int:approve_topic_ids})
					AND t.approved = {int:approve_type}
				LIMIT ' . count($topics),
				array(
					'approve_topic_ids' => $topics,
					'approve_type' => $approve_type,
				)
			);
			while ($row = $request->fetchAssoc()) {
				logAction($log_action, array('topic' => $row['id_topic'], 'subject' => $row['subject'], 'member' => $row['id_member_started'], 'board' => $board));
			}
			$request->free();
		}

		// Just get the messages to be approved and pass through...
		$request = $this->db->query('', '
			SELECT id_msg
			FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topic_list})
				AND approved = {int:approve_type}',
			array(
				'topic_list' => $topics,
				'approve_type' => $approve_type,
			)
		);
		$msgs = array();
		while ($row = $request->fetchAssoc())
			$msgs[] = $row['id_msg'];
		$request->free();

		return approvePosts($msgs, $approve);
	}

	/**
	 * Post a message at the end of the original topic
	 *
	 * @param string $reason the text that will become the message body
	 * @param string $subject the text that will become the message subject
	 * @param mixed[] $board_info some board information (at least id, name, if posts are counted)
	 * @param string $new_topic used to build the url for moving to a new topic
	 */
	function postSplitRedirect($reason, $subject, $board_info, $new_topic)
	{
		global $scripturl, $user_info, $language, $txt, $topic, $board;

		// Should be in the boardwide language.
		if ($user_info['language'] != $language)
			loadLanguage('index', $language);

		preparsecode($reason);

		// Add a URL onto the message.
		$reason = strtr($reason, array(
			$txt['movetopic_auto_board'] => '[url=' . $scripturl . '?board=' . $board_info['id'] . '.0]' . $board_info['name'] . '[/url]',
			$txt['movetopic_auto_topic'] => '[iurl]' . $scripturl . '?topic=' . $new_topic . '.0[/iurl]'
		));

		$msgOptions = array(
			'subject' => $txt['split'] . ': ' . strtr($GLOBALS['elk']['text']->htmltrim($GLOBALS['elk']['text']->htmlspecialchars($subject)), array("\r" => '', "\n" => '', "\t" => '')),
			'body' => $reason,
			'icon' => 'moved',
			'smileys_enabled' => 1,
		);

		$topicOptions = array(
			'id' => $topic,
			'board' => $board,
			'mark_as_read' => true,
		);

		$posterOptions = array(
			'id' => $user_info['id'],
			'update_post_count' => empty($board_info['count_posts']),
		);

		createPost($msgOptions, $topicOptions, $posterOptions);
	}

	/**
	 * Retrieve topic notifications count.
	 * (used by createList() callbacks, amongst others.)
	 *
	 * @param int $memID id_member
	 * @return integer
	 */
	function topicNotificationCount($memID)
	{
		global $user_info, $modSettings;

		$request = $this->db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}log_notify AS ln' . (!$modSettings['postmod_active'] && $user_info['query_see_board'] === '1=1' ? '' : '
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = ln.id_topic)') . ($user_info['query_see_board'] === '1=1' ? '' : '
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)') . '
			WHERE ln.id_member = {int:selected_member}' . ($user_info['query_see_board'] === '1=1' ? '' : '
				AND {query_see_board}') . ($modSettings['postmod_active'] ? '
				AND t.approved = {int:is_approved}' : ''),
			array(
				'selected_member' => $memID,
				'is_approved' => 1,
			)
		);
		list ($totalNotifications) = $request->fetchRow();
		$request->free();

		return (int)$totalNotifications;
	}

	/**
	 * Retrieve all topic notifications for the given user.
	 * (used by createList() callbacks)
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param int $memID id_member
	 * @return array
	 */
	function topicNotifications($start, $items_per_page, $sort, $memID)
	{
		global $scripturl, $user_info, $modSettings;

		// All the topics with notification on...
		$request = $this->db->query('', '
			SELECT
				IFNULL(lt.id_msg, IFNULL(lmr.id_msg, -1)) + 1 AS new_from, b.id_board, b.name,
				t.id_topic, ms.subject, ms.id_member, IFNULL(mem.real_name, ms.poster_name) AS real_name_col,
				ml.id_msg_modified, ml.poster_time, ml.id_member AS id_member_updated,
				IFNULL(mem2.real_name, ml.poster_name) AS last_real_name
			FROM {db_prefix}log_notify AS ln
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = ln.id_topic' . ($modSettings['postmod_active'] ? ' AND t.approved = {int:is_approved}' : '') . ')
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board AND {query_see_board})
				INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)
				INNER JOIN {db_prefix}messages AS ml ON (ml.id_msg = t.id_last_msg)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = ms.id_member)
				LEFT JOIN {db_prefix}members AS mem2 ON (mem2.id_member = ml.id_member)
				LEFT JOIN {db_prefix}log_topics AS lt ON (lt.id_topic = t.id_topic AND lt.id_member = {int:current_member})
				LEFT JOIN {db_prefix}log_mark_read AS lmr ON (lmr.id_board = b.id_board AND lmr.id_member = {int:current_member})
			WHERE ln.id_member = {int:selected_member}
			ORDER BY {raw:sort}
			LIMIT {int:offset}, {int:items_per_page}',
			array(
				'current_member' => $user_info['id'],
				'is_approved' => 1,
				'selected_member' => $memID,
				'sort' => $sort,
				'offset' => $start,
				'items_per_page' => $items_per_page,
			)
		);
		$notification_topics = array();
		while ($row = $request->fetchAssoc()) {
			$row['body'] = censor($row['subject']);

			$notification_topics[] = array(
				'id' => $row['id_topic'],
				'poster_link' => empty($row['id_member']) ? $row['real_name_col'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name_col'] . '</a>',
				'poster_updated_link' => empty($row['id_member_updated']) ? $row['last_real_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member_updated'] . '">' . $row['last_real_name'] . '</a>',
				'subject' => $row['subject'],
				'href' => $scripturl . '?topic=' . $row['id_topic'] . '.0',
				'link' => '<a href="' . $scripturl . '?topic=' . $row['id_topic'] . '.0">' . $row['subject'] . '</a>',
				'new' => $row['new_from'] <= $row['id_msg_modified'],
				'new_from' => $row['new_from'],
				'updated' => standardTime($row['poster_time']),
				'new_href' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['new_from'] . '#new',
				'new_link' => '<a href="' . $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $row['new_from'] . '#new">' . $row['subject'] . '</a>',
				'board_link' => '<a href="' . $scripturl . '?board=' . $row['id_board'] . '.0">' . $row['name'] . '</a>',
			);
		}
		$request->free();

		return $notification_topics;
	}

	/**
	 * Get a list of posters in this topic, and their posts counts in the topic.
	 * Used to update users posts counts when topics are moved or are deleted.
	 *
	 * @param int $id_topic topic id to work with
	 */
	function postersCount($id_topic)
	{
		// We only care about approved topics, the rest don't count.
		$request = $this->db->query('', '
			SELECT id_member
			FROM {db_prefix}messages
			WHERE id_topic = {int:current_topic}
				AND approved = {int:is_approved}',
			array(
				'current_topic' => $id_topic,
				'is_approved' => 1,
			)
		);
		$posters = array();
		while ($row = $request->fetchAssoc()) {
			if (!isset($posters[$row['id_member']]))
				$posters[$row['id_member']] = 0;

			$posters[$row['id_member']]++;
		}
		$request->free();

		return $posters;
	}

	/**
	 * Counts topics from the given id_board.
	 *
	 * @param int $board
	 * @param bool $approved
	 * @return int
	 */
	function countTopicsByBoard($board, $approved = false)
	{
		// How many topics are on this board?  (used for paging.)
		$request = $this->db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}topics AS t
			WHERE t.id_board = {int:id_board}' . (empty($approved) ? '
				AND t.approved = {int:is_approved}' : ''),
			array(
				'id_board' => $board,
				'is_approved' => 1,
			)
		);
		list ($topics) = $request->fetchRow();
		$request->free();

		return $topics;
	}

	/**
	 * Determines all messages from a given array of topics.
	 *
	 * @param int[] $topics integer array of topics to work with
	 * @return array
	 */
	function messagesInTopics($topics)
	{
		// Obtain all the message ids we are going to affect.
		$request = $this->db->query('', '
			SELECT id_msg
			FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topic_list})',
			array(
				'topic_list' => $topics,
			));
		$messages = array();
		while ($row = $request->fetchAssoc())
			$messages[] = $row['id_msg'];
		$request->free();

		return $messages;
	}

	/**
	 * Retrieves the members that posted in a group of topics.
	 *
	 * @param int[] $topics integer array of topics to work with
	 * @return array of topics each member posted in (grouped by members)
	 */
	function topicsPosters($topics)
	{
		// Obtain all the member ids
		$members = array();
		$request = $this->db->query('', '
			SELECT id_member, id_topic
			FROM {db_prefix}messages
			WHERE id_topic IN ({array_int:topic_list})',
			array(
				'topic_list' => $topics,
			));
		while ($row = $request->fetchAssoc())
			$members[$row['id_member']][] = $row['id_topic'];
		$request->free();

		return $members;
	}

	/**
	 * Load the subject from a given topic id.
	 *
	 * @param int $id_topic
	 * @return string
	 */
	function getSubject($id_topic)
	{
		global $modSettings;

		$request = $this->db->query('', '
			SELECT ms.subject
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				INNER JOIN {db_prefix}messages AS ms ON (ms.id_msg = t.id_first_msg)
			WHERE t.id_topic = {int:search_topic_id}
				AND {query_see_board}' . ($modSettings['postmod_active'] ? '
				AND t.approved = {int:is_approved_true}' : '') . '
			LIMIT 1',
			array(
				'is_approved_true' => 1,
				'search_topic_id' => $id_topic,
			)
		);

		if ($request->numRows() == 0)
			$GLOBALS['elk']['errors']->fatal_lang_error('topic_gone', false);

		list ($subject) = $request->fetchRow();
		$request->free();

		return $subject;
	}

	/**
	 * This function updates the total number of topics,
	 * or if parameter $increment is true it simply increments them.
	 *
	 * @param bool|null $increment = null if true, increment + 1 the total topics, otherwise recount all topics
	 */
	function updateTopicStats($increment = null)
	{
		global $modSettings;

		if ($increment === true)
			updateSettings(array('totalTopics' => true), true);
		else {
			// Get the number of topics - a SUM is better for InnoDB tables.
			// We also ignore the recycle bin here because there will probably be a bunch of one-post topics there.
			$request = $this->db->query('', '
				SELECT SUM(num_topics + unapproved_topics) AS total_topics
				FROM {db_prefix}boards' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
				WHERE id_board != {int:recycle_board}' : ''),
				array(
					'recycle_board' => !empty($modSettings['recycle_board']) ? $modSettings['recycle_board'] : 0,
				)
			);
			$row = $request->fetchAssoc();
			$request->free();

			updateSettings(array('totalTopics' => $row['total_topics'] === null ? 0 : $row['total_topics']));
		}
	}

	/**
	 * Toggles the locked status of the passed id_topic's checking for permissions.
	 *
	 * @param int[] $topics The topics to lock (can be an id or an array of ids).
	 * @param bool $log if true logs the action.
	 */
	function toggleTopicsLock($topics, $log = false)
	{
		global $board, $user_info;

		$needs_check = !empty($board) && !allowedTo('lock_any');
		$lockCache = array();

		$topicAttribute = $this->topicAttribute($topics, array('id_topic', 'locked', 'id_board', 'id_member_started'));

		foreach ($topicAttribute as $row) {
			// Skip the entry if it needs to be checked and the user is not the owen and
			// the topic was not locked or locked by someone with more permissions
			if ($needs_check && ($user_info['id'] != $row['id_member_started'] || !in_array($row['locked'], array(0, 2))))
				continue;

			$lockCache[] = $row['id_topic'];

			if ($log) {
				$lockStatus = empty($row['locked']) ? 'lock' : 'unlock';

				logAction($lockStatus, array('topic' => $row['id_topic'], 'board' => $row['id_board']));
				sendNotifications($row['id_topic'], $lockStatus);
			}
		}

		// It could just be that *none* were their own topics...
		if (!empty($lockCache)) {
			// Alternate the locked value.
			$this->db->query('', '
				UPDATE {db_prefix}topics
				SET locked = CASE WHEN locked = {int:is_locked} THEN ' . (allowedTo('lock_any') ? '1' : '2') . ' ELSE 0 END
				WHERE id_topic IN ({array_int:locked_topic_ids})',
				array(
					'locked_topic_ids' => $lockCache,
					'is_locked' => 0,
				)
			);
		}
	}

	/**
	 * This function determines if a user has posted in the list of topics,
	 * and returns the list of those topics they posted in.
	 *
	 * @param int $id_member member to check
	 * @param int[] $topic_ids array of topics ids to check for participation
	 * @return array
	 */
	function topicsParticipation($id_member, $topic_ids)
	{
		$topics = array();

		$result = $this->db->query('', '
		SELECT id_topic
		FROM {db_prefix}messages
		WHERE id_topic IN ({array_int:topic_list})
			AND id_member = {int:current_member}
		GROUP BY id_topic
			LIMIT ' . count($topic_ids),
			array(
				'current_member' => $id_member,
				'topic_list' => $topic_ids,
			)
		);
		while ($row = $result->fetchAssoc())
			$topics[] = $row;

		$result->free();

		return $topics;
	}
}