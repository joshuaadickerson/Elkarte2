<?php

namespace Elkarte\Topics;

class MergeTopics
{

	/**
	 * Determines topics which can be merged from a specific board.
	 *
	 * @param int $id_board
	 * @param int $id_topic
	 * @param bool $approved
	 * @param int $offset
	 * @return array
	 */
	function mergeableTopics($id_board, $id_topic, $approved, $offset)
	{
		global $modSettings, $scripturl;

		// Get some topics to merge it with.
		$request = $this->db->query('', '
			SELECT t.id_topic, m.subject, m.id_member, IFNULL(mem.real_name, m.poster_name) AS poster_name
			FROM {db_prefix}topics AS t
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			WHERE t.id_board = {int:id_board}
				AND t.id_topic != {int:id_topic}' . (empty($approved) ? '
				AND t.approved = {int:is_approved}' : '') . '
			ORDER BY t.is_sticky DESC, t.id_last_msg DESC
			LIMIT {int:offset}, {int:limit}',
			array(
				'id_board' => $id_board,
				'id_topic' => $id_topic,
				'offset' => $offset,
				'limit' => $modSettings['defaultMaxTopics'],
				'is_approved' => 1,
			)
		);
		$topics = array();
		while ($row = $request->fetchAssoc()) {
			$row['body'] = censor($row['subject']);

			$topics[] = array(
				'id' => $row['id_topic'],
				'poster' => array(
					'id' => $row['id_member'],
					'name' => $row['poster_name'],
					'href' => empty($row['id_member']) ? '' : $scripturl . '?action=profile;u=' . $row['id_member'],
					'link' => empty($row['id_member']) ? $row['poster_name'] : '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '" target="_blank" class="new_win">' . $row['poster_name'] . '</a>'
				),
				'subject' => $row['subject'],
				'js_subject' => addcslashes(addslashes($row['subject']), '/')
			);
		}
		$request->free();

		return $topics;
	}


	/**
	 * Updates all the tables involved when two or more topics are merged
	 *
	 * @param int $first_msg the first message of the new topic
	 * @param int[] $topics ids of all the topics merged
	 * @param int $id_topic id of the merged topic
	 * @param int $target_board id of the target board where the topic will resides
	 * @param string $target_subject subject of the new topic
	 * @param string $enforce_subject if not empty all the messages will be set to the same subject
	 * @param int[] $notifications array of topics with active notifications
	 */
	function fixMergedTopics($first_msg, $topics, $id_topic, $target_board, $target_subject, $enforce_subject, $notifications)
	{
		// Delete the remaining topics.
		$deleted_topics = array_diff($topics, array($id_topic));
		$this->db->query('', '
			DELETE FROM {db_prefix}topics
			WHERE id_topic IN ({array_int:deleted_topics})',
			array(
				'deleted_topics' => $deleted_topics,
			)
		);

		$this->db->query('', '
			DELETE FROM {db_prefix}log_search_subjects
			WHERE id_topic IN ({array_int:deleted_topics})',
			array(
				'deleted_topics' => $deleted_topics,
			)
		);

		// Change the topic IDs of all messages that will be merged.  Also adjust subjects if 'enforce subject' was checked.
		$this->db->query('', '
			UPDATE {db_prefix}messages
			SET
				id_topic = {int:id_topic},
				id_board = {int:target_board}' . (empty($enforce_subject) ? '' : ',
				subject = {string:subject}') . '
			WHERE id_topic IN ({array_int:topic_list})',
			array(
				'topic_list' => $topics,
				'id_topic' => $id_topic,
				'target_board' => $target_board,
				'subject' => response_prefix() . $target_subject,
			)
		);

		// Any reported posts should reflect the new board.
		$this->db->query('', '
			UPDATE {db_prefix}log_reported
			SET
				id_topic = {int:id_topic},
				id_board = {int:target_board}
			WHERE id_topic IN ({array_int:topics_list})',
			array(
				'topics_list' => $topics,
				'id_topic' => $id_topic,
				'target_board' => $target_board,
			)
		);

		// Change the subject of the first message...
		$this->db->query('', '
			UPDATE {db_prefix}messages
			SET subject = {string:target_subject}
			WHERE id_msg = {int:first_msg}',
			array(
				'first_msg' => $first_msg,
				'target_subject' => $target_subject,
			)
		);

		// Adjust all calendar events to point to the new topic.
		$this->db->query('', '
			UPDATE {db_prefix}calendar
			SET
				id_topic = {int:id_topic},
				id_board = {int:target_board}
			WHERE id_topic IN ({array_int:deleted_topics})',
			array(
				'deleted_topics' => $deleted_topics,
				'id_topic' => $id_topic,
				'target_board' => $target_board,
			)
		);

		// Merge log topic entries.
		// The unwatched setting comes from the oldest topic
		$request = $this->db->query('', '
			SELECT id_member, MIN(id_msg) AS new_id_msg, unwatched
			FROM {db_prefix}log_topics
			WHERE id_topic IN ({array_int:topics})
			GROUP BY id_member',
			array(
				'topics' => $topics,
			)
		);

		if ($request->numRows() > 0) {
			$replaceEntries = array();
			while ($row = $request->fetchAssoc())
				$replaceEntries[] = array($row['id_member'], $id_topic, $row['new_id_msg'], $row['unwatched']);

			markTopicsRead($replaceEntries, true);
			unset($replaceEntries);

			// Get rid of the old log entries.
			$this->db->query('', '
				DELETE FROM {db_prefix}log_topics
				WHERE id_topic IN ({array_int:deleted_topics})',
				array(
					'deleted_topics' => $deleted_topics,
				)
			);
		}
		$request->free();

		if (!empty($notifications)) {
			$request = $this->db->query('', '
				SELECT id_member, MAX(sent) AS sent
				FROM {db_prefix}log_notify
				WHERE id_topic IN ({array_int:topics_list})
				GROUP BY id_member',
				array(
					'topics_list' => $notifications,
				)
			);
			if ($request->numRows() > 0) {
				$replaceEntries = array();
				while ($row = $request->fetchAssoc())
					$replaceEntries[] = array($row['id_member'], $id_topic, 0, $row['sent']);

				$this->db->insert('replace',
					'{db_prefix}log_notify',
					array('id_member' => 'int', 'id_topic' => 'int', 'id_board' => 'int', 'sent' => 'int'),
					$replaceEntries,
					array('id_member', 'id_topic', 'id_board')
				);
				unset($replaceEntries);

				$this->db->query('', '
					DELETE FROM {db_prefix}log_topics
					WHERE id_topic IN ({array_int:deleted_topics})',
					array(
						'deleted_topics' => $deleted_topics,
					)
				);
			}
			$request->free();
		}
	}

}