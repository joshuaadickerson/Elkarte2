<?php

/**
 * This file contains a class to collect the data needed to
 * show a list of boards for the board index and the message index.
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

namespace Elkarte\Boards;

use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Text\StringUtil;
use Elkarte\Members\Member;
use Elkarte\Members\MemberContainer;

/**
 * This class fetches all the stuff needed to build a list of boards
 */
class BoardsList
{
	/**
	 * All the options
	 * @var mixed[]
	 */
	private $_options = array();

	/**
	 * Some data regarding the current user
	 * @var mixed[]
	 */
	private $_user = array();

	/**
	 * Holds the info about the latest post of the series
	 * @var mixed[]
	 */
	private $_latest_post = array();

	/**
	 * Remembers boards to easily scan the array to add moderators later
	 * @var int[]
	 */
	private $_boards = array();

	/**
	 * An array containing all the data of the categories and boards requested
	 * @var mixed[]
	 */
	private $_categories = array();

	/**
	 * The category/board that is being processed "now"
	 * @var mixed[]
	 */
	private $_current_boards = array();

	/**
	 * The url where to find images
	 * @var string
	 */
	private $_images_url = '';

	/**
	 * The url of the script
	 * @var string
	 */
	private $_scripturl = '';

	/**
	 * A string with session data to be user in urls
	 * @var string
	 */
	private $_session_url = '';

	/**
	 * Cut the subject at this number of chars
	 * @var int
	 */
	private $_subject_length = 24;

	/**
	 * The id of the recycle board (0 for none or not enabled)
	 * @var int
	 */
	private $_recycle_board = 0;

	/**
	 * The database!
	 * @var object
	 */
	private $_db = null;

	protected $board_container;
	protected $mem_container;

	/**
	 * Initialize the class
	 *
	 * @todo add Cache and Text classes to construct
	 * @param DatabaseInterface $db
	 * @param mixed[] $options - Available options and corresponding defaults are:
	 *       'include_categories' => false
	 *       'countChildPosts' => false
	 *       'base_level' => false
	 *       'parent_id' => 0
	 *       'set_latest_post' => false
	 *       'get_moderators' => true
	 */
	public function __construct(DatabaseInterface $db, Cache $cache, StringUtil $text,
								BoardsContainer $board_container, MemberContainer $mem_container)
	{
		$this->_db = $db;
		$this->cache = $cache;
		$this->text = $text;
		$this->board_container = $board_container;
		$this->mem_container = $mem_container;
	}

	/**
	 * Fetches a list of boards and (optional) categories including
	 * statistical information, sub-boards and moderators.
	 *  - Used by both the board index (main data) and the message index (child
	 * boards).
	 *  - Depending on the include_categories setting returns an associative
	 * array with categories->boards->child_boards or an associative array
	 * with boards->child_boards.
	 *
	 * @return array
	 */
	public function getBoards()
	{
		global $txt;

		// Find all boards and categories, as well as related information.
		$result = $this->_db->query('boardindex_fetch_boards', '
			SELECT' . ($this->_options['include_categories'] ? '
				c.id_cat, c.name AS cat_name,' : '') . '
				b.id_board, b.name AS board_name, b.description,
				CASE WHEN b.redirect != {string:blank_string} THEN 1 ELSE 0 END AS is_redirect,
				b.num_posts, b.num_topics, b.unapproved_posts, b.unapproved_topics, b.id_parent,
				IFNULL(m.poster_time, 0) AS poster_time, IFNULL(mem.member_name, m.poster_name) AS poster_name,
				m.subject, m.id_topic, IFNULL(mem.real_name, m.poster_name) AS real_name,
				' . ($this->_user['is_guest'] ? ' 1 AS is_read, 0 AS new_from,' : '
				(IFNULL(lb.id_msg, 0) >= b.id_msg_updated) AS is_read, IFNULL(lb.id_msg, -1) + 1 AS new_from,' . ($this->_options['include_categories'] ? '
				c.can_collapse, IFNULL(cc.id_member, 0) AS is_collapsed,' : '')) . '
				IFNULL(mem.id_member, 0) AS id_member, mem.avatar, m.id_msg' . ($this->_options['avatars_on_indexes'] ? ',
				IFNULL(a.id_attach, 0) AS id_attach, a.filename, a.attachment_type, mem.email_address' : '') . '
			FROM {db_prefix}boards AS b' . ($this->_options['include_categories'] ? '
				LEFT JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)' : '') . '
				LEFT JOIN {db_prefix}messages AS m ON (m.id_msg = b.id_last_msg)
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)' . ($this->_user['is_guest'] ? '' : '
				LEFT JOIN {db_prefix}log_boards AS lb ON (lb.id_board = b.id_board AND lb.id_member = {int:current_member})' . ($this->_options['include_categories'] ? '
				LEFT JOIN {db_prefix}collapsed_categories AS cc ON (cc.id_cat = c.id_cat AND cc.id_member = {int:current_member})' : '')) . ($this->_options['avatars_on_indexes'] ? '
				LEFT JOIN {db_prefix}attachments AS a ON (a.id_member = m.id_member AND a.id_member != 0)' : '') . '
			WHERE {query_see_board}' . (empty($this->_options['countChildPosts']) ? (empty($this->_options['base_level']) ? '' : '
				AND b.child_level >= {int:child_level}') : '
				AND b.child_level BETWEEN ' . $this->_options['base_level'] . ' AND ' . ($this->_options['base_level'] + 1)) . '
			ORDER BY' . ($this->_options['include_categories'] ? ' c.cat_order,' : '') . ' b.board_order',
			array(
				'current_member' => $this->_user['id'],
				'child_level' => $this->_options['base_level'],
				'blank_string' => '',
			)
		);

		$bbc_parser = $GLOBALS['elk']['bbc'];

		$boards = [];
		$categories = [];
		$parent_map = [];

		// Run through the categories and boards (or only boards)....
		while ($row = $result->fetchAssoc())
		{
			// Perhaps we are ignoring this board?
			$ignoreThisBoard = in_array($row['id_board'], $this->_user['ignoreboards']);
			$row['is_read'] = !empty($row['is_read']) || $ignoreThisBoard ? '1' : '0';
			// Not a child.
			$isChild = false;

			if ($this->_options['include_categories'])
			{
				// Haven't set this category yet.
				if (!isset($categories[$row['id_cat']]))
				{
					$cat_name = $row['cat_name'];
					$category = new Category([
						'id' => $row['id_cat'],
						'name' => $row['cat_name'],
						'is_collapsed' => isset($row['can_collapse']) && $row['can_collapse'] == 1 && $row['is_collapsed'] > 0,
						'can_collapse' => isset($row['can_collapse']) && $row['can_collapse'] == 1,
						'collapse_href' => isset($row['can_collapse']) ? $this->_scripturl . '?action=collapse;c=' . $row['id_cat'] . ';sa=' . ($row['is_collapsed'] > 0 ? 'expand;' : 'collapse;') . $this->_session_url . '#c' . $row['id_cat'] : '',
						'collapse_image' => isset($row['can_collapse']) ? '<img src="' . $this->_images_url . ($row['is_collapsed'] > 0 ? 'expand.png" alt="+"' : 'collapse.png" alt="-"') . ' />' : '',
						'href' => $this->_scripturl . '#c' . $row['id_cat'],
						'new' => false
					]);

					$this->_categories[$row['id_cat']] = $category;
					$categories[$row['id_cat']] = $category;

					$this->_categories[$row['id_cat']]['link'] = '<a id="c' . $row['id_cat'] . '"></a>' . (!$this->_user['is_guest']
							? '<a href="' . $this->_scripturl . '?action=unread;c=' . $row['id_cat'] . '" title="' . sprintf($txt['new_posts_in_category'], strip_tags($row['cat_name'])) . '">' . $cat_name . '</a>'
							: $cat_name);
				}

				// If this board has new posts in it (and isn't the recycle bin!) then the category is new.
				if ($this->_recycle_board != $row['id_board'])
					$this->_categories[$row['id_cat']]['new'] |= empty($row['is_read']) && $row['poster_name'] != '';

				// Avoid showing category unread link where it only has redirection boards.
				$this->_categories[$row['id_cat']]['show_unread'] = !empty($this->_categories[$row['id_cat']]['show_unread']) ? 1 : !$row['is_redirect'];

				// Collapsed category - don't do any of this.
				if ($this->_categories[$row['id_cat']]['is_collapsed'])
					continue;

				// Let's save some typing.  Climbing the array might be slower, anyhow.
				$this->_current_boards = &$this->_categories[$row['id_cat']]['boards'];
			}

			// It's a new board
			if (!isset($boards[$row['id_board']]))
			{
				$board = $this->board_container->board($row['id_board'], new Board([
					'id' => $row['id_board'],
					'name' => $row['board_name'],
					// @todo change to description_bbc
					//'description' => $bbc_parser->parseBoard($row['description']),
					'description' => $row['description'],
					'new' => empty($row['is_read']),
					'topics' => (int) $row['num_topics'],
					'posts' => (int) $row['num_posts'],
					'is_redirect' => (bool) $row['is_redirect'],
					'unapproved_topics' => (int) $row['unapproved_topics'],
					'unapproved_posts' => $row['unapproved_posts'] - $row['unapproved_topics'],
					'can_approve_posts' => $this->_user['mod_cache_ap'] == array(0) || in_array($row['id_board'], $this->_user['mod_cache_ap']),
				]));

				$boards[$board->id] = $board;
			}
			else
			{
				$board = $boards[$row['id_board']];
			}

			// This is a parent board.
			if ($row['id_parent'] == $this->_options['parent_id'])
			{
				// Is this a new board, or just another moderator?
				if (!isset($this->_current_boards[$board->id]))
				{
					$this->_current_boards[$board->id] = $board;
				}

				$this->_boards[$board->id] = $this->_options['include_categories'] ? $row['id_cat'] : 0;
			}
			// Found a sub-board.... make sure we've found its parent and the child hasn't been set already.
			elseif (isset($this->_current_boards[$row['id_parent']]['children']) && !isset($this->_current_boards[$row['id_parent']]['children'][$row['id_board']]))
			{
				// A valid child!
				$isChild = true;

				// @todo I don't think this is supposed to be different
				$board['new'] = empty($row['is_read']) && $row['poster_name'] != '';

				// Counting sub-board posts is... slow :/.
				if (!empty($this->_options['countChildPosts']) && !$row['is_redirect'])
				{
					$this->_current_boards[$row['id_parent']]['posts'] += $row['num_posts'];
					$this->_current_boards[$row['id_parent']]['topics'] += $row['num_topics'];
				}

				// Does this board contain new boards?
				$this->_current_boards[$row['id_parent']]['children_new'] |= empty($row['is_read']);

				// This is easier to use in many cases for the theme....
				$this->_current_boards[$row['id_parent']]['link_children'][] = &$this->_current_boards[$row['id_parent']]['children'][$row['id_board']]['link'];
			}
			// Child of a child... just add it on...
			elseif (!empty($this->_options['countChildPosts']))
			{
				if (!isset($parent_map[$row['id_parent']]))
					foreach ($this->_current_boards as $id => $board)
					{
						if (!isset($board['children'][$row['id_parent']]))
							continue;

						$parent_map[$row['id_parent']] = array(&$this->_current_boards[$id], &$this->_current_boards[$id]['children'][$row['id_parent']]);
						$parent_map[$row['id_board']] = array(&$this->_current_boards[$id], &$this->_current_boards[$id]['children'][$row['id_parent']]);

						break;
					}

				if (isset($parent_map[$row['id_parent']]) && !$row['is_redirect'])
				{
					$parent_map[$row['id_parent']][0]['posts'] += $row['num_posts'];
					$parent_map[$row['id_parent']][0]['topics'] += $row['num_topics'];
					$parent_map[$row['id_parent']][1]['posts'] += $row['num_posts'];
					$parent_map[$row['id_parent']][1]['topics'] += $row['num_topics'];

					continue;
				}

				continue;
			}
			// Found a child of a child - skip.
			else
				continue;

			// Prepare the subject, and make sure it's not too long.
			$row['subject'] = censor($row['subject']);
			$row['short_subject'] = $this->text->shorten_text($row['subject'], $this->_subject_length);
			$this_last_post = array(
				'id' => $row['id_msg'],
				'time' => $row['poster_time'] > 0 ? standardTime($row['poster_time']) : $txt['not_applicable'],
				'html_time' => $row['poster_time'] > 0 ? htmlTime($row['poster_time']) : $txt['not_applicable'],
				'timestamp' => forum_time(true, $row['poster_time']),
				'subject' => $row['short_subject'],
				'member' => $this->mem_container->member($row['id_member'], new Member([
					'id' => $row['id_member'],
					'name' => $row['real_name'],
					// @todo move to context
					'username' => $row['poster_name'] != '' ? $row['poster_name'] : $txt['not_applicable'],
					'href' => $row['poster_name'] != '' && !empty($row['id_member']) ? $this->_scripturl . '?action=profile;u=' . $row['id_member'] : '',
					'link' => $row['poster_name'] != '' ? (!empty($row['id_member']) ? '<a href="' . $this->_scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>' : $row['real_name']) : $txt['not_applicable'],
				])),
				'start' => 'msg' . $row['new_from'],
				'topic' => $row['id_topic']
			);

			if ($this->_options['avatars_on_indexes'])
				$this_last_post['member']['avatar'] = determineAvatar($row);

			// Provide the href and link.
			if ($row['subject'] != '')
			{
				$this_last_post['href'] = $this->_scripturl . '?topic=' . $row['id_topic'] . '.msg' . ($this->_user['is_guest'] ? $row['id_msg'] : $row['new_from']) . (empty($row['is_read']) ? ';boardseen' : '') . '#new';
				$this_last_post['link'] = '<a href="' . $this_last_post['href'] . '" title="' . $row['subject'] . '">' . $row['short_subject'] . '</a>';
				/* The board's and children's 'last_post's have:
				time, timestamp (a number that represents the time.), id (of the post), topic (topic id.),
				link, href, subject, start (where they should go for the first unread post.),
				and member. (which has id, name, link, href, username in it.) */
				$this_last_post['last_post_message'] = sprintf($txt['last_post_message'], $this_last_post['member']['link'], $this_last_post['link'], $this_last_post['html_time']);
			}
			else
			{
				$this_last_post['href'] = '';
				$this_last_post['link'] = $txt['not_applicable'];
				$this_last_post['last_post_message'] = '';
			}

			// Set the last post in the parent board.
			if ($row['id_parent'] == $this->_options['parent_id'] || ($isChild && !empty($row['poster_time']) && $this->_current_boards[$row['id_parent']]['last_post']['timestamp'] < forum_time(true, $row['poster_time'])))
				$this->_current_boards[$isChild ? $row['id_parent'] : $row['id_board']]['last_post'] = $this_last_post;
			// Just in the child...?
			if ($isChild)
			{
				$this->_current_boards[$row['id_parent']]['children'][$row['id_board']]['last_post'] = $this_last_post;

				// If there are no posts in this board, it really can't be new...
				$this->_current_boards[$row['id_parent']]['children'][$row['id_board']]['new'] &= $row['poster_name'] != '';
			}
			// No last post for this board?  It's not new then, is it..?
			elseif ($row['poster_name'] == '')
				$this->_current_boards[$row['id_board']]['new'] = false;

			// Determine a global most recent topic.
			if ($this->_options['set_latest_post'] && !empty($row['poster_time']) && $row['poster_time'] > $this->_latest_post['timestamp'] && !$ignoreThisBoard)
				$this->_latest_post = &$this->_current_boards[$isChild ? $row['id_parent'] : $row['id_board']]['last_post'];
		}
		$result->free();

		if ($this->_options['get_moderators'] && !empty($this->_boards))
			$this->_getBoardModerators();

		return $this->_options['include_categories'] ? $this->_categories : $this->_current_boards;
	}

	/**
	 * Returns the array containing the "latest post" information
	 *
	 * @return array
	 */
	public function getLatestPost()
	{
		if (empty($this->_latest_post) || empty($this->_latest_post['link']))
			return array();
		else
			return $this->_latest_post;
	}

	/**
	 * Fetches and adds to the results the board moderators for the current boards
	 */
	protected function _getBoardModerators()
	{
		global $txt;

		$boards = array_keys($this->_boards);

		$mod_cached = null;
		if (!$this->cache->getVar($mod_cached, 'localmods_' . md5(implode(',', $boards)), 3600))
		{
			$mod_cached = $this->_db->fetchQuery('
				SELECT mods.id_board, IFNULL(mods_mem.id_member, 0) AS id_moderator, mods_mem.real_name AS mod_real_name
				FROM {db_prefix}moderators AS mods
					LEFT JOIN {db_prefix}members AS mods_mem ON (mods_mem.id_member = mods.id_member)
				WHERE mods.id_board IN ({array_int:id_boards})',
				array(
					'id_boards' => $boards,
				)
			);

			$this->cache->put('localmods_' . md5(implode(',', $boards)), $mod_cached, 3600);
		}

		foreach ($mod_cached as $row)
		{
			if ($this->_options['include_categories'])
				$this->_current_boards = &$this->_categories[$this->_boards[$row['id_board']]]['boards'];

			$this->_current_boards[$row['id_board']]['moderators'][$row['id_moderator']] = $this->mem_container->member($row['id_member'], new Member([
				'id' => $row['id_moderator'],
				'name' => $row['mod_real_name'],
				'href' => $this->_scripturl . '?action=profile;u=' . $row['id_moderator'],
				'link' => '<a href="' . $this->_scripturl . '?action=profile;u=' . $row['id_moderator'] . '" title="' . $txt['board_moderator'] . '">' . $row['mod_real_name'] . '</a>'
			]));
			$this->_current_boards[$row['id_board']]['link_moderators'][] = '<a href="' . $this->_scripturl . '?action=profile;u=' . $row['id_moderator'] . '" title="' . $txt['board_moderator'] . '">' . $row['mod_real_name'] . '</a>';
		}
	}

	public function setOptions(array $options)
	{
		global $user_info, $settings, $context, $scripturl, $modSettings;

		$this->_options = array_merge(array(
			'include_categories' => false,
			'countChildPosts' => false,
			'base_level' => false,
			'parent_id' => 0,
			'set_latest_post' => false,
			'get_moderators' => true,
		), $options);

		$this->_options['avatars_on_indexes'] = !empty($settings['avatars_on_indexes']) && $settings['avatars_on_indexes'] !== 2;

		$this->_images_url = $settings['images_url'] . '/' . $context['theme_variant_url'];
		$this->_scripturl = $scripturl;
		$this->_session_url = $context['session_var'] . '=' . $context['session_id'];

		$this->_subject_length = $modSettings['subject_length'];

		$this->_user = array(
			'id' => $user_info['id'],
			'is_guest' => $user_info['is_guest'],
			'ignoreboards' => $user_info['ignoreboards'],
			'mod_cache_ap' => !empty($user_info['mod_cache']['ap']) ? $user_info['mod_cache']['ap'] : array(),
		);

		// Start with an empty array.
		if ($this->_options['include_categories'])
			$this->_categories = array();
		else
			$this->_current_boards = array();

		// For performance, track the latest post while going through the boards.
		if (!empty($this->_options['set_latest_post']))
			$this->_latest_post = array('timestamp' => 0);

		if (!empty($modSettings['recycle_enable']))
			$this->_recycle_board = $modSettings['recycle_board'];
	}
}