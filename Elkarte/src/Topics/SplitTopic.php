<?php

namespace Elkarte\Topics;

class SplitTopic
{

	/**
	 * General function to split off a topic.
	 * creates a new topic and moves the messages with the IDs in
	 * array messagesToBeSplit to the new topic.
	 * the subject of the newly created topic is set to 'newSubject'.
	 * marks the newly created message as read for the user splitting it.
	 * updates the statistics to reflect a newly created topic.
	 * logs the action in the moderation log.
	 * a notification is sent to all users monitoring this topic.
	 *
	 * @param int $split1_ID_TOPIC
	 * @param int[] $splitMessages
	 * @param string $new_subject
	 * @return int the topic ID of the new split topic.
	 */
	function splitTopic($split1_ID_TOPIC, $splitMessages, $new_subject)
	{
		global $txt;

		// Nothing to split?
		if (empty($splitMessages))
			$GLOBALS['elk']['errors']->fatal_lang_error('no_posts_selected', false);

		// Get some board info.
		$topicAttribute = $this->topicAttribute($split1_ID_TOPIC, array('id_board', 'approved'));
		$id_board = $topicAttribute['id_board'];
		$split1_approved = $topicAttribute['approved'];

		// Find the new first and last not in the list. (old topic)
		$request = $this->db->query('', '
			SELECT
				MIN(m.id_msg) AS myid_first_msg, MAX(m.id_msg) AS myid_last_msg, COUNT(*) AS message_count, m.approved
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = {int:id_topic})
			WHERE m.id_msg NOT IN ({array_int:no_msg_list})
				AND m.id_topic = {int:id_topic}
			GROUP BY m.approved
			ORDER BY m.approved DESC
			LIMIT 2',
			array(
				'id_topic' => $split1_ID_TOPIC,
				'no_msg_list' => $splitMessages,
			)
		);
		// You can't select ALL the messages!
		if ($request->numRows() == 0)
			$GLOBALS['elk']['errors']->fatal_lang_error('selected_all_posts', false);

		$split1_first_msg = null;
		$split1_last_msg = null;

		while ($row = $request->fetchAssoc()) {
			// Get the right first and last message dependant on approved state...
			if (empty($split1_first_msg) || $row['myid_first_msg'] < $split1_first_msg)
				$split1_first_msg = $row['myid_first_msg'];

			if (empty($split1_last_msg) || $row['approved'])
				$split1_last_msg = $row['myid_last_msg'];

			// Get the counts correct...
			if ($row['approved']) {
				$split1_replies = $row['message_count'] - 1;
				$split1_unapprovedposts = 0;
			} else {
				if (!isset($split1_replies))
					$split1_replies = 0;
				// If the topic isn't approved then num replies must go up by one... as first post wouldn't be counted.
				elseif (!$split1_approved)
					$split1_replies++;

				$split1_unapprovedposts = $row['message_count'];
			}
		}
		$request->free();
		$split1_firstMem = getMsgMemberID($split1_first_msg);
		$split1_lastMem = getMsgMemberID($split1_last_msg);

		// Find the first and last in the list. (new topic)
		$request = $this->db->query('', '
			SELECT MIN(id_msg) AS myid_first_msg, MAX(id_msg) AS myid_last_msg, COUNT(*) AS message_count, approved
			FROM {db_prefix}messages
			WHERE id_msg IN ({array_int:msg_list})
				AND id_topic = {int:id_topic}
			GROUP BY id_topic, approved
			ORDER BY approved DESC
			LIMIT 2',
			array(
				'msg_list' => $splitMessages,
				'id_topic' => $split1_ID_TOPIC,
			)
		);
		while ($row = $request->fetchAssoc()) {
			// As before get the right first and last message dependant on approved state...
			if (empty($split2_first_msg) || $row['myid_first_msg'] < $split2_first_msg)
				$split2_first_msg = $row['myid_first_msg'];

			if (empty($split2_last_msg) || $row['approved'])
				$split2_last_msg = $row['myid_last_msg'];

			// Then do the counts again...
			if ($row['approved']) {
				$split2_approved = true;
				$split2_replies = $row['message_count'] - 1;
				$split2_unapprovedposts = 0;
			} else {
				// Should this one be approved??
				if ($split2_first_msg == $row['myid_first_msg'])
					$split2_approved = false;

				if (!isset($split2_replies))
					$split2_replies = 0;
				// As before, fix number of replies.
				elseif (!$split2_approved)
					$split2_replies++;

				$split2_unapprovedposts = $row['message_count'];
			}
		}
		$request->free();
		$split2_firstMem = getMsgMemberID($split2_first_msg);
		$split2_lastMem = getMsgMemberID($split2_last_msg);

		// No database changes yet, so let's double check to see if everything makes at least a little sense.
		if ($split1_first_msg <= 0 || $split1_last_msg <= 0 || $split2_first_msg <= 0 || $split2_last_msg <= 0 || $split1_replies < 0 || $split2_replies < 0 || $split1_unapprovedposts < 0 || $split2_unapprovedposts < 0 || !isset($split1_approved) || !isset($split2_approved))
			$GLOBALS['elk']['errors']->fatal_lang_error('cant_find_messages');

		// You cannot split off the first message of a topic.
		if ($split1_first_msg > $split2_first_msg)
			$GLOBALS['elk']['errors']->fatal_lang_error('split_first_post', false);

		// We're off to insert the new topic!  Use 0 for now to avoid UNIQUE errors.
		$result = $this->db->insert('',
			'{db_prefix}topics',
			array(
				'id_board' => 'int',
				'id_member_started' => 'int',
				'id_member_updated' => 'int',
				'id_first_msg' => 'int',
				'id_last_msg' => 'int',
				'num_replies' => 'int',
				'unapproved_posts' => 'int',
				'approved' => 'int',
				'is_sticky' => 'int',
			),
			array(
				(int)$id_board, $split2_firstMem, $split2_lastMem, 0,
				0, $split2_replies, $split2_unapprovedposts, (int)$split2_approved, 0,
			),
			array('id_topic')
		);
		$split2_ID_TOPIC = $result->insertId('{db_prefix}topics', 'id_topic');
		if ($split2_ID_TOPIC <= 0)
			$GLOBALS['elk']['errors']->fatal_lang_error('cant_insert_topic');

		// Move the messages over to the other topic.
		$new_subject = strtr($GLOBALS['elk']['text']->htmltrim($GLOBALS['elk']['text']->htmlspecialchars($new_subject)), array("\r" => '', "\n" => '', "\t" => ''));

		// Check the subject length.
		if ($GLOBALS['elk']['text']->strlen($new_subject) > 100)
			$new_subject = $GLOBALS['elk']['text']->substr($new_subject, 0, 100);

		// Valid subject?
		if ($new_subject != '') {
			$this->db->query('', '
				UPDATE {db_prefix}messages
				SET
					id_topic = {int:id_topic},
					subject = CASE WHEN id_msg = {int:split_first_msg} THEN {string:new_subject} ELSE {string:new_subject_replies} END
				WHERE id_msg IN ({array_int:split_msgs})',
				array(
					'split_msgs' => $splitMessages,
					'id_topic' => $split2_ID_TOPIC,
					'new_subject' => $new_subject,
					'split_first_msg' => $split2_first_msg,
					'new_subject_replies' => $txt['response_prefix'] . $new_subject,
				)
			);

			// Cache the new topics subject... we can do it now as all the subjects are the same!

			updateSubjectStats($split2_ID_TOPIC, $new_subject);
		}

		// Any associated reported posts better follow...
		updateSplitTopics(array(
			'splitMessages' => $splitMessages,
			'split1_replies' => $split1_replies,
			'split1_first_msg' => $split1_first_msg,
			'split1_last_msg' => $split1_last_msg,
			'split1_firstMem' => $split1_firstMem,
			'split1_lastMem' => $split1_lastMem,
			'split1_unapprovedposts' => $split1_unapprovedposts,
			'split1_ID_TOPIC' => $split1_ID_TOPIC,
			'split2_first_msg' => $split2_first_msg,
			'split2_last_msg' => $split2_last_msg,
			'split2_ID_TOPIC' => $split2_ID_TOPIC,
			'split2_approved' => $split2_approved,
		), $id_board);
		// Let's see if we can create a stronger bridge between the two topics
		// @todo not sure what message from the oldest topic I should link to the new one, so I'll go with the first
		linkMessages($split1_first_msg, $split2_ID_TOPIC);

		// Copy log topic entries.
		// @todo This should really be chunked.
		$request = $this->db->query('', '
			SELECT id_member, id_msg, unwatched
			FROM {db_prefix}log_topics
			WHERE id_topic = {int:id_topic}',
			array(
				'id_topic' => (int)$split1_ID_TOPIC,
			)
		);
		if ($request->numRows() > 0) {
			$replaceEntries = array();
			while ($row = $request->fetchAssoc())
				$replaceEntries[] = array($row['id_member'], $split2_ID_TOPIC, $row['id_msg'], $row['unwatched']);

			markTopicsRead($replaceEntries, false);
			unset($replaceEntries);
		}
		$request->free();

		// Housekeeping.
		updateTopicStats();
		updateLastMessages($id_board);

		logAction('split', array('topic' => $split1_ID_TOPIC, 'new_topic' => $split2_ID_TOPIC, 'board' => $id_board));

		// Notify people that this topic has been split?
		sendNotifications($split1_ID_TOPIC, 'split');

		// If there's a search index that needs updating, update it...
		$searchAPI = findSearchAPI();
		if (is_callable(array($searchAPI, 'topicSplit')))
			$searchAPI->topicSplit($split2_ID_TOPIC, $splitMessages);

		// Return the ID of the newly created topic.
		return $split2_ID_TOPIC;
	}

	/**
	 * If we are also moving the topic somewhere else, let's try do to it
	 * Includes checks for permissions move_own/any, etc.
	 *
	 * @param mixed[] $boards an array containing basic info of the origin and destination boards (from splitDestinationBoard)
	 * @param int $totopic id of the destination topic
	 */
	function splitAttemptMove($boards, $totopic)
	{
		global $board, $user_info;

		// If the starting and final boards are different we have to check some permissions and stuff
		if ($boards['destination']['id'] != $board) {
			$doMove = false;
			if (allowedTo('move_any'))
				$doMove = true;
			else {
				$new_topic = getTopicInfo($totopic);
				if ($new_topic['id_member_started'] == $user_info['id'] && allowedTo('move_own'))
					$doMove = true;
			}

			if ($doMove) {
				// Update member statistics if needed
				// @todo this should probably go into a function...
				if ($boards['destination']['count_posts'] != $boards['current']['count_posts']) {
					$request = $this->db->query('', '
						SELECT id_member
						FROM {db_prefix}messages
						WHERE id_topic = {int:current_topic}
							AND approved = {int:is_approved}',
						array(
							'current_topic' => $totopic,
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

					foreach ($posters as $id_member => $posts) {
						// The board we're moving from counted posts, but not to.
						if (empty($boards['current']['count_posts']))
							updateMemberData($id_member, array('posts' => 'posts - ' . $posts));
						// The reverse: from didn't, to did.
						else
							updateMemberData($id_member, array('posts' => 'posts + ' . $posts));
					}
				}

				// And finally move it!
				moveTopics($totopic, $boards['destination']['id']);
			} else
				$boards['destination'] = $boards['current'];
		}
	}

	/**
	 * Retrieves information of the current and destination board of a split topic
	 *
	 * @return array
	 */
	function splitDestinationBoard($toboard = 0)
	{
		global $board, $topic;

		$current_board = boardInfo($board, $topic);
		if (empty($current_board))
			$GLOBALS['elk']['errors']->fatal_lang_error('no_board');

		if (!empty($toboard) && $board !== $toboard) {
			$destination_board = boardInfo($toboard);
			if (empty($destination_board))
				$GLOBALS['elk']['errors']->fatal_lang_error('no_board');
		}

		if (!isset($destination_board))
			$destination_board = array_merge($current_board, array('id' => $board));
		else
			$destination_board['id'] = $toboard;

		return array('current' => $current_board, 'destination' => $destination_board);
	}

}