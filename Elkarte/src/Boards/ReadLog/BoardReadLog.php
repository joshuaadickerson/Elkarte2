<?php

namespace Elkarte\Boards\ReadLog;

use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\StringUtil;

class BoardReadLog
{
	/** @var DatabaseInterface  */
	protected $db;
	/** @var Cache  */
	protected $cache;
	/** @var Hooks  */
	protected $hooks;
	/** @var Errors  */
	protected $errors;
	/** @var StringUtil  */
	protected $text;

	public function __construct(DatabaseInterface $db, Cache $cache, Hooks $hooks, Errors $errors, StringUtil $text)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->hooks = $hooks;
		$this->errors = $errors;
		$this->text = $text;
	}

	public function markBoardsUnread(array $boards)
	{
		global $user_info;
		// Clear out all the places where this lovely info is stored.
		// @todo Maybe not log_mark_read?
		$this->db->query('', '
			DELETE FROM {db_prefix}log_mark_read
			WHERE id_board IN ({array_int:board_list})
				AND id_member = {int:current_member}',
			array(
				'current_member' => $user_info['id'],
				'board_list' => $boards,
			)
		);
		$this->db->query('', '
			DELETE FROM {db_prefix}log_boards
			WHERE id_board IN ({array_int:board_list})
				AND id_member = {int:current_member}',
			array(
				'current_member' => $user_info['id'],
				'board_list' => $boards,
			)
		);

		return $this;
	}

	/**
	 * Mark a board or multiple boards read.
	 *
	 * @package Boards
	 * @param int[]|int $boards
	 * @param bool $unread = false
	 * @param bool $resetTopics = false
	 */
	function markBoardsRead(array $boards, $unread = false, $resetTopics = false)
	{
		global $user_info, $modSettings;

		$boards = array_unique($boards);

		// No boards, nothing to mark as read.
		if (empty($boards))
			return;

		// Allow the user to mark a board as unread.
		if ($unread)
		{
			$this->markBoardsUnread($boards);
		}
		// Otherwise mark the board as read.
		else
		{
			$markRead = array();
			foreach ($boards as $board)
				$markRead[] = array($modSettings['maxMsgID'], $user_info['id'], $board);

			$this->db->insert('replace',
				'{db_prefix}log_boards',
				array('id_msg' => 'int', 'id_member' => 'int', 'id_board' => 'int'),
				$markRead,
				array('id_board', 'id_member')
			);
		}

		// Get rid of useless log_topics data, because log_mark_read is better for it - even if marking unread - I think so...
		// @todo look at this...
		// The call to markBoardsRead() in Display() used to be simply
		// marking log_boards (the previous query only)
		// I'm adding a bool to control the processing of log_topics. We might want to just dissociate it from boards,
		// and call the log_topics clear-up only from the controller that needs it..

		// Notes (for read/unread rework)
		// MessageIndex::action_messageindex() does not update log_topics at all (only the above).
		// Display controller needed only to update log_boards.

		if ($resetTopics)
		{
			// Update log_mark_read and log_boards.
			// @todo check this condition <= I think I did, but better double check
			if (!$unread && !empty($markRead))
				$this->db->insert('replace',
					'{db_prefix}log_mark_read',
					array('id_msg' => 'int', 'id_member' => 'int', 'id_board' => 'int'),
					$markRead,
					array('id_board', 'id_member')
				);

			$result = $this->db->query('', '
			SELECT MIN(id_topic)
			FROM {db_prefix}log_topics
			WHERE id_member = {int:current_member}',
				array(
					'current_member' => $user_info['id'],
				)
			);
			list ($lowest_topic) = $result->fetchRow();
			$result->free();

			if (empty($lowest_topic))
				return;

			// @todo SLOW This query seems to eat it sometimes.
			$delete_topics = array();
			$update_topics = array();
			$this->db->fetchQueryCallback('
			SELECT lt.id_topic, lt.unwatched
			FROM {db_prefix}log_topics AS lt
				INNER JOIN {db_prefix}topics AS t /*!40000 USE INDEX (PRIMARY) */ ON (t.id_topic = lt.id_topic
					AND t.id_board IN ({array_int:board_list}))
			WHERE lt.id_member = {int:current_member}
				AND lt.id_topic >= {int:lowest_topic}',
				array(
					'current_member' => $user_info['id'],
					'board_list' => $boards,
					'lowest_topic' => $lowest_topic,
				),
				function($row) use (&$delete_topics, &$update_topics, $user_info, $modSettings)
				{
					if (!empty($row['unwatched']))
						$update_topics[] = array(
							$user_info['id'],
							$modSettings['maxMsgID'],
							$row['id_topic'],
							1,
						);
					else
						$delete_topics[] = $row['id_topic'];
				}
			);

			if (!empty($update_topics))
				$this->db->insert('replace',
					'{db_prefix}log_topics',
					array(
						'id_member' => 'int',
						'id_msg' => 'int',
						'id_topic' => 'int',
						'unwatched' => 'int'
					),
					$update_topics,
					array('id_topic', 'id_member')
				);

			if (!empty($delete_topics))
				$this->db->query('', '
				DELETE FROM {db_prefix}log_topics
				WHERE id_member = {int:current_member}
					AND id_topic IN ({array_int:topic_list})',
					array(
						'current_member' => $user_info['id'],
						'topic_list' => $delete_topics,
					)
				);
		}
	}
}