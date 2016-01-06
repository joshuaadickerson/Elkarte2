<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\StringUtil;
use Elkarte\Members\MemberContainer;

class BoardsManager
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
	/** @var BoardsContainer  */
	protected $boards_container;
	/** @var MemberContainer  */
	protected $mem_container;

	public function __construct(DatabaseInterface $db, Cache $cache, Hooks $hooks, Errors $errors, StringUtil $text,
								BoardsContainer $boards_container, MemberContainer $mem_container)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->hooks = $hooks;
		$this->errors = $errors;
		$this->text = $text;
		$this->boards_container = $boards_container;
		$this->mem_container = $mem_container;
	}

	public function load()
	{
		global $txt, $scripturl, $context, $modSettings;
		global $board_info, $topic, $user_info;

		$board_id = &$GLOBALS['board'];

		// Assume they are not a moderator.
		$user_info['is_mod'] = false;
		$context['user']['is_mod'] = &$user_info['is_mod'];
		// @since 1.0.5 - is_mod takes into account only local (board) moderators,
		// and not global moderators, is_moderator is meant to take into account both.
		$user_info['is_moderator'] = false;
		$context['user']['is_moderator'] = &$user_info['is_moderator'];

		// Start the linktree off empty..
		$context['linktree'] = array();

		// Have they by chance specified a message id but nothing else?
		if (empty($_REQUEST['action']) && empty($topic) && empty($board_id) && !empty($_REQUEST['msg']))
		{
			// Make sure the message id is really an int.
			$_REQUEST['msg'] = (int) $_REQUEST['msg'];

			// Looking through the message table can be slow, so try using the cache first.
			if (!$this->cache->getVar($topic, 'msg_topic-' . $_REQUEST['msg'], 120))
			{
				require_once(ROOTDIR . '/Messages/Messages.subs.php');
				$topic = associatedTopic($_REQUEST['msg']);

				// So did it find anything?
				if ($topic !== false)
				{
					// Save save save.
					$this->cache->put('msg_topic-' . $_REQUEST['msg'], $topic, 120);
				}
			}

			// Remember redirection is the key to avoiding fallout from your bosses.
			if (!empty($topic))
				redirectexit('topic=' . $topic . '.msg' . $_REQUEST['msg'] . '#msg' . $_REQUEST['msg']);
			else
			{
				loadPermissions();
				loadTheme();
				$this->errors->fatal_lang_error('topic_gone', false);
			}
		}

		// Load this board only if it is specified.
		if (empty($board_id) && empty($topic))
		{
			$board_info = new Board;
			return;
		}

		if ($this->cache->isEnabled() && (empty($topic) || $this->cache->checkLevel(3)))
		{
			// @todo SLOW?
			if (!empty($topic))
				$temp = $this->cache->get('topic_board-' . $topic, 120);
			else
				$temp = $this->cache->get('board-' . $board_id, 120);

			if (!empty($temp))
			{
				$board_info = new Board($temp);
				$GLOBALS['board'] = $board_info['id'];
			}
		}

		if (empty($temp))
		{
			$select_columns = array();
			$select_tables = array();

			// Wanna grab something more from the boards table or another table at all?
			$this->hooks->hook('load_board_query', array(&$select_columns, &$select_tables));

			$request = $this->db->select('', '
			SELECT
				c.id_cat, b.name AS bname, b.description, b.num_topics, b.member_groups, b.deny_member_groups,
				b.id_parent, c.name AS cname, IFNULL(mem.id_member, 0) AS id_moderator,
				mem.real_name' . (!empty($topic) ? ', b.id_board' : '') . ', b.child_level,
				b.id_theme, b.override_theme, b.count_posts, b.id_profile, b.redirect,
				b.unapproved_topics, b.unapproved_posts' . (!empty($topic) ? ', t.approved, t.id_member_started' : '') . (!empty($select_columns) ? ', ' . implode(', ', $select_columns) : '') . '
			FROM {db_prefix}boards AS b' . (!empty($topic) ? '
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = {int:current_topic})' : '') . (!empty($select_tables) ? '
				' . implode("\n\t\t\t\t", $select_tables) : '') . '
				LEFT JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
				LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = {raw:board_link})
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
			WHERE b.id_board = {raw:board_link}',
				array(
					'current_topic' => $topic,
					'board_link' => empty($topic) ? $this->db->quote('{int:current_board}', array('current_board' => $board_id)) : 't.id_board',
				)
			);
			// If there aren't any, skip.
			if ($request->numRows() > 0)
			{
				$row = $request->fetchAssoc();

				// Set the current board.
				if (!empty($row['id_board']))
					$GLOBALS['board'] = $row['id_board'];

				$category = new Category([
					'id' => $row['id_cat'],
					'name' => $row['cname'],
					'boards' => [$board_id],
				]);

				// Basic operating information. (globals... :/)
				$board_info = new Board([
					'id' => $board_id,
					'cat' => $category,
					'name' => $row['bname'],
					'raw_description' => $row['description'],
					'description' => $row['description'],
					'num_topics' => $row['num_topics'],
					'unapproved_topics' => $row['unapproved_topics'],
					'unapproved_posts' => $row['unapproved_posts'],
					'parent' => $row['id_parent'],
					'child_level' => $row['child_level'],
					'theme' => $row['id_theme'],
					'override_theme' => !empty($row['override_theme']),
					'profile' => $row['id_profile'],
					'redirect' => $row['redirect'],
					'posts_count' => empty($row['count_posts']),
					'cur_topic_approved' => empty($topic) || $row['approved'],
					'cur_topic_starter' => empty($topic) ? 0 : $row['id_member_started'],
				]);

				$category->addBoard($board_info);

				// Load the membergroups allowed, and check permissions.
				$board_info['groups'] = $row['member_groups'] == '' ? array() : explode(',', $row['member_groups']);
				$board_info['deny_groups'] = $row['deny_member_groups'] == '' ? array() : explode(',', $row['deny_member_groups']);

				do
				{
					if (!empty($row['id_moderator']))
					{
						$board_info->addModerator($row['id_moderator'], $row['real_name']);
					}
				}
				while ($row = $request->fetchAssoc());

				// If the board only contains unapproved posts and the user can't approve then they can't see any topics.
				// If that is the case do an additional check to see if they have any topics waiting to be approved.
				if ($board_info['num_topics'] == 0 && $modSettings['postmod_active'] && !allowedTo('approve_posts'))
				{
					// Free the previous result
					$request->free();

					// @todo why is this using id_topic?
					// @todo Can this get cached?
					$request = $this->db->select('', '
					SELECT COUNT(id_topic)
					FROM {db_prefix}topics
					WHERE id_member_started={int:id_member}
						AND approved = {int:unapproved}
						AND id_board = {int:board}',
						array(
							'id_member' => $user_info['id'],
							'unapproved' => 0,
							'board' => $board_id,
						)
					);

					list ($board_info['unapproved_user_topics']) = $request->fetchRow();
				}

				$this->hooks->hook('loaded_board', array(&$board_info, &$row));

				if ($this->cache->isEnabled() && (empty($topic) || $this->cache->checkLevel(3)))
				{
					// @todo SLOW?
					if (!empty($topic))
						$this->cache->put('topic_board-' . $topic, $board_info, 120);
					$this->cache->put('board-' . $board_id, $board_info, 120);
				}
			}
			else
			{
				// Otherwise the topic is invalid, there are no moderators, etc.
				$board_info = new Board([
					'error' => 'exist'
				]);
				$topic = null;
				$GLOBALS['board'] = 0;
			}
			$request->free();
		}

		if (!empty($topic))
			$_GET['board'] = (int) $board_id;

		if (!empty($board_id))
		{
			// Now check if the user is a moderator.
			$user_info['is_mod'] = isset($board_info['moderators'][$user_info['id']]);
			$user_info['is_moderator'] = $user_info['is_mod'] || allowedTo('moderate_board');

			if (count(array_intersect($user_info['groups'], $board_info['groups'])) == 0 && !$user_info['is_admin'])
				$board_info['error'] = 'access';
			if (!empty($modSettings['deny_boards_access']) && count(array_intersect($user_info['groups'], $board_info['deny_groups'])) != 0 && !$user_info['is_admin'])
				$board_info['error'] = 'access';

			// Build up the linktree.
			$context['linktree'] = array_merge(
				$context['linktree'],
				array(array(
					'url' => $scripturl . '#c' . $board_info['cat']['id'],
					'name' => $board_info['cat']['name']
				)),
				array_reverse($board_info->parentBoards($this)),
				array(array(
					'url' => $scripturl . '?board=' . $board_id . '.0',
					'name' => $board_info['name']
				))
			);
		}

		// Set the template contextual information.
		$context['user']['is_mod'] = &$user_info['is_mod'];
		$context['user']['is_moderator'] = &$user_info['is_moderator'];
		$context['current_topic'] = $topic;
		$context['current_board'] = $board_id;

		// Hacker... you can't see this topic, I'll tell you that. (but moderators can!)
		if (!empty($board_info['error']) && (!empty($modSettings['deny_boards_access']) || $board_info['error'] != 'access' || !$user_info['is_moderator']))
		{
			// The permissions and theme need loading, just to make sure everything goes smoothly.
			loadPermissions();
			loadTheme();

			$_GET['board'] = '';
			$_GET['topic'] = '';

			// The linktree should not give the game away mate!
			$context['linktree'] = array(
				array(
					'url' => $scripturl,
					'name' => $context['forum_name_html_safe']
				)
			);

			// If it's a prefetching agent, stop it
			stop_prefetching();

			// If we're requesting an attachment.
			if (!empty($_REQUEST['action']) && $_REQUEST['action'] === 'dlattach')
			{
				ob_end_clean();
				header('HTTP/1.1 403 Forbidden');
				exit;
			}
			elseif ($user_info['is_guest'])
			{
				loadLanguage('Errors');
				is_not_guest($txt['topic_gone']);
			}
			else
				$this->errors->fatal_lang_error('topic_gone', false);
		}

		if ($user_info['is_mod'])
			$user_info['groups'][] = 3;
	}

	/**
	 * Get all parent boards (requires first parent as parameter)
	 *
	 * What it does:
	 * - It finds all the parents of id_parent, and that board itself.
	 * - Additionally, it detects the moderators of said boards.
	 * - Returns an array of information about the boards found.
	 *
	 * @param int $id_parent
	 * @return array
	 */
	function getBoardParents($id_parent)
	{
		global $scripturl;

		$boards = array();

		// First check if we have this cached already.
		if (!$this->cache->getVar($boards, 'board_parents-' . $id_parent, 480))
		{
			$original_parent = $id_parent;

			// Loop while the parent is non-zero.
			while ($id_parent != 0)
			{
				$result = $this->db->query('', '
				SELECT
					b.id_parent, b.name, {int:board_parent} AS id_board, IFNULL(mem.id_member, 0) AS id_moderator,
					mem.real_name, b.child_level
				FROM {db_prefix}boards AS b
					LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = b.id_board)
					LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
				WHERE b.id_board = {int:board_parent}',
					array(
						'board_parent' => $id_parent,
					)
				);
				// In the EXTREMELY unlikely event this happens, give an error message.
				if ($result->numRows() == 0)
					$this->errors->fatal_lang_error('parent_not_found', 'critical');
				while ($row = $result->fetchAssoc())
				{
					if (!isset($boards[$row['id_board']]))
					{
						$id_parent = $row['id_parent'];
						$boards[$row['id_board']] = array(
							'url' => $scripturl . '?board=' . $row['id_board'] . '.0',
							'name' => $row['name'],
							'level' => $row['child_level'],
							'moderators' => array()
						);
					}
					// If a moderator exists for this board, add that moderator for all children too.
					if (!empty($row['id_moderator']))
						foreach ($boards as $id => $dummy)
						{
							$boards[$id]['moderators'][$row['id_moderator']] = array(
								'id' => $row['id_moderator'],
								'name' => $row['real_name'],
								'href' => $scripturl . '?action=profile;u=' . $row['id_moderator'],
								'link' => '<a href="' . $scripturl . '?action=profile;u=' . $row['id_moderator'] . '">' . $row['real_name'] . '</a>'
							);
						}
				}
				$result->free();
			}

			$this->cache->put('board_parents-' . $original_parent, $boards, 480);
		}

		return $boards;
	}


	/**
	 * Modify the settings and position of a board.
	 *
	 * - Used by ManageBoards.controller.php to change the settings of a board.
	 *
	 * @package Boards
	 * @param int $board_id
	 * @param mixed[] $boardOptions
	 * @throws \Exception
	 */
	function modifyBoard($board_id, &$boardOptions)
	{
		global $cat_tree, $boards;

		// Get some basic information about all boards and categories.
		$this->getBoardTree();

		// Make sure given boards and categories exist.
		if (!isset($boards[$board_id]) || (isset($boardOptions['target_board']) && !isset($boards[$boardOptions['target_board']])) || (isset($boardOptions['target_category']) && !isset($cat_tree[$boardOptions['target_category']])))
			$this->errors->fatal_lang_error('no_board');

		// All things that will be updated in the database will be in $boardUpdates.
		$boardUpdates = array();
		$boardUpdateParameters = array();

		// In case the board has to be moved
		if (isset($boardOptions['move_to']))
		{
			// Move the board to the top of a given category.
			if ($boardOptions['move_to'] == 'top')
			{
				$id_cat = $boardOptions['target_category'];
				$child_level = 0;
				$id_parent = 0;
				$after = $cat_tree[$id_cat]['last_board_order'];
			}

			// Move the board to the bottom of a given category.
			elseif ($boardOptions['move_to'] == 'bottom')
			{
				$id_cat = $boardOptions['target_category'];
				$child_level = 0;
				$id_parent = 0;
				$after = 0;
				foreach ($cat_tree[$id_cat]['children'] as $id_board => $dummy)
					$after = max($after, $boards[$id_board]['order']);
			}

			// Make the board a child of a given board.
			elseif ($boardOptions['move_to'] == 'child')
			{
				$id_cat = $boards[$boardOptions['target_board']]['category'];
				$child_level = $boards[$boardOptions['target_board']]['level'] + 1;
				$id_parent = $boardOptions['target_board'];

				// People can be creative, in many ways...
				if ($this->isChildOf($id_parent, $board_id))
					$this->errors->fatal_lang_error('mboards_parent_own_child_error', false);
				elseif ($id_parent == $board_id)
					$this->errors->fatal_lang_error('mboards_board_own_child_error', false);

				$after = $boards[$boardOptions['target_board']]['order'];

				// Check if there are already children and (if so) get the max board order.
				if (!empty($boards[$id_parent]['tree']['children']) && empty($boardOptions['move_first_child']))
					foreach ($boards[$id_parent]['tree']['children'] as $childBoard_id => $dummy)
						$after = max($after, $boards[$childBoard_id]['order']);
			}

			// Place a board before or after another board, on the same child level.
			elseif (in_array($boardOptions['move_to'], array('before', 'after')))
			{
				$id_cat = $boards[$boardOptions['target_board']]['category'];
				$child_level = $boards[$boardOptions['target_board']]['level'];
				$id_parent = $boards[$boardOptions['target_board']]['parent'];
				$after = $boards[$boardOptions['target_board']]['order'] - ($boardOptions['move_to'] == 'before' ? 1 : 0);
			}

			// Oops...?
			else
				throw new \Exception('modifyBoard(): The move_to value \'' . $boardOptions['move_to'] . '\' is incorrect', E_USER_ERROR);

			// Get a list of children of this board.
			$childList = array();
			$this->recursiveBoards($childList, $boards[$board_id]['tree']);

			// See if there are changes that affect children.
			$childUpdates = array();
			$levelDiff = $child_level - $boards[$board_id]['level'];
			if ($levelDiff != 0)
				$childUpdates[] = 'child_level = child_level ' . ($levelDiff > 0 ? '+ ' : '') . '{int:level_diff}';
			if ($id_cat != $boards[$board_id]['category'])
				$childUpdates[] = 'id_cat = {int:category}';

			// Fix the children of this board.
			if (!empty($childList) && !empty($childUpdates))
				$this->db->query('', '
				UPDATE {db_prefix}boards
				SET ' . implode(',
					', $childUpdates) . '
				WHERE id_board IN ({array_int:board_list})',
					array(
						'board_list' => $childList,
						'category' => $id_cat,
						'level_diff' => $levelDiff,
					)
				);

			// Make some room for this spot.
			$this->db->query('', '
			UPDATE {db_prefix}boards
			SET board_order = board_order + {int:new_order}
			WHERE board_order > {int:insert_after}
				AND id_board != {int:selected_board}',
				array(
					'insert_after' => $after,
					'selected_board' => $board_id,
					'new_order' => 1 + count($childList),
				)
			);

			$boardUpdates[] = 'id_cat = {int:id_cat}';
			$boardUpdates[] = 'id_parent = {int:id_parent}';
			$boardUpdates[] = 'child_level = {int:child_level}';
			$boardUpdates[] = 'board_order = {int:board_order}';
			$boardUpdateParameters += array(
				'id_cat' => $id_cat,
				'id_parent' => $id_parent,
				'child_level' => $child_level,
				'board_order' => $after + 1,
			);
		}

		// This setting is a little twisted in the database...
		if (isset($boardOptions['posts_count']))
		{
			$boardUpdates[] = 'count_posts = {int:count_posts}';
			$boardUpdateParameters['count_posts'] = $boardOptions['posts_count'] ? 0 : 1;
		}

		// Set the theme for this board.
		if (isset($boardOptions['board_theme']))
		{
			$boardUpdates[] = 'id_theme = {int:id_theme}';
			$boardUpdateParameters['id_theme'] = (int) $boardOptions['board_theme'];
		}

		// Should the board theme override the user preferred theme?
		if (isset($boardOptions['override_theme']))
		{
			$boardUpdates[] = 'override_theme = {int:override_theme}';
			$boardUpdateParameters['override_theme'] = $boardOptions['override_theme'] ? 1 : 0;
		}

		// Who's allowed to access this board.
		if (isset($boardOptions['access_groups']))
		{
			$boardUpdates[] = 'member_groups = {string:member_groups}';
			$boardUpdateParameters['member_groups'] = implode(',', $boardOptions['access_groups']);
		}

		// And who isn't.
		if (isset($boardOptions['deny_groups']))
		{
			$boardUpdates[] = 'deny_member_groups = {string:deny_groups}';
			$boardUpdateParameters['deny_groups'] = implode(',', $boardOptions['deny_groups']);
		}

		if (isset($boardOptions['board_name']))
		{
			$boardUpdates[] = 'name = {string:board_name}';
			$boardUpdateParameters['board_name'] = $boardOptions['board_name'];
		}

		if (isset($boardOptions['board_description']))
		{
			$boardUpdates[] = 'description = {string:board_description}';
			$boardUpdateParameters['board_description'] = $boardOptions['board_description'];
		}

		if (isset($boardOptions['profile']))
		{
			$boardUpdates[] = 'id_profile = {int:profile}';
			$boardUpdateParameters['profile'] = (int) $boardOptions['profile'];
		}

		if (isset($boardOptions['redirect']))
		{
			$boardUpdates[] = 'redirect = {string:redirect}';
			$boardUpdateParameters['redirect'] = $boardOptions['redirect'];
		}

		if (isset($boardOptions['num_posts']))
		{
			$boardUpdates[] = 'num_posts = {int:num_posts}';
			$boardUpdateParameters['num_posts'] = (int) $boardOptions['num_posts'];
		}

		$this->hooks->hook('modify_board', array($board_id, $boardOptions, &$boardUpdates, &$boardUpdateParameters));

		// Do the updates (if any).
		if (!empty($boardUpdates))
			$this->db->query('', '
			UPDATE {db_prefix}boards
			SET
				' . implode(',
				', $boardUpdates) . '
			WHERE id_board = {int:selected_board}',
				array_merge($boardUpdateParameters, array(
					'selected_board' => $board_id,
				))
			);

		// Set moderators of this board.
		if (isset($boardOptions['moderators']) || isset($boardOptions['moderator_string']))
		{
			// Reset current moderators for this board - if there are any!
			$this->db->query('', '
			DELETE FROM {db_prefix}moderators
			WHERE id_board = {int:board_list}',
				array(
					'board_list' => $board_id,
				)
			);

			// Validate and get the IDs of the new moderators.
			if (isset($boardOptions['moderator_string']) && trim($boardOptions['moderator_string']) != '')
			{
				// Divvy out the usernames, remove extra space.
				$moderator_string = strtr($this->text->htmlspecialchars($boardOptions['moderator_string'], ENT_QUOTES), array('&quot;' => '"'));
				preg_match_all('~"([^"]+)"~', $moderator_string, $matches);
				$moderators = array_merge($matches[1], explode(',', preg_replace('~"[^"]+"~', '', $moderator_string)));
				for ($k = 0, $n = count($moderators); $k < $n; $k++)
				{
					$moderators[$k] = trim($moderators[$k]);

					if (strlen($moderators[$k]) == 0)
						unset($moderators[$k]);
				}

				// Find all the id_member's for the member_name's in the list.
				if (empty($boardOptions['moderators']))
					$boardOptions['moderators'] = array();
				if (!empty($moderators))
				{
					$boardOptions['moderators'] = $this->db->fetchQueryCallback('
					SELECT id_member
					FROM {db_prefix}members
					WHERE member_name IN ({array_string:moderator_list}) OR real_name IN ({array_string:moderator_list})
					LIMIT ' . count($moderators),
						array(
							'moderator_list' => $moderators,
						),
						function($row)
						{
							return $row['id_member'];
						}
					);
				}
			}

			// Add the moderators to the board.
			if (!empty($boardOptions['moderators']))
			{
				$inserts = array();
				foreach ($boardOptions['moderators'] as $moderator)
					$inserts[] = array($board_id, $moderator);

				$this->db->insert('insert',
					'{db_prefix}moderators',
					array('id_board' => 'int', 'id_member' => 'int'),
					$inserts,
					array('id_board', 'id_member')
				);
			}

			// Note that caches can now be wrong!
			updateSettings(array('settings_updated' => time()));
		}

		clean_cache('data');

		if (empty($boardOptions['dont_log']))
			logAction('edit_board', array('board' => $board_id), 'Admin');
	}

	/**
	 * Create a new board and set its properties and position.
	 *
	 * - Allows (almost) the same options as the modifyBoard() function.
	 * - With the option inherit_permissions set, the parent board permissions
	 * will be inherited.
	 *
	 * @package Boards
	 * @param mixed[] $boardOptions
	 * @return int The new board id
	 */
	function createBoard($boardOptions)
	{
		global $boards;
		// Trigger an error if one of the required values is not set.
		if (!isset($boardOptions['board_name']) || trim($boardOptions['board_name']) == '' || !isset($boardOptions['move_to']) || !isset($boardOptions['target_category']))
			trigger_error('createBoard(): One or more of the required options is not set', E_USER_ERROR);

		if (in_array($boardOptions['move_to'], array('child', 'before', 'after')) && !isset($boardOptions['target_board']))
			trigger_error('createBoard(): Target board is not set', E_USER_ERROR);

		// Set every optional value to its default value.
		$boardOptions += array(
			'posts_count' => true,
			'override_theme' => false,
			'board_theme' => 0,
			'access_groups' => array(),
			'board_description' => '',
			'profile' => 1,
			'moderators' => '',
			'inherit_permissions' => true,
			'dont_log' => true,
		);
		$board_columns = array(
			'id_cat' => 'int', 'name' => 'string-255', 'description' => 'string', 'board_order' => 'int',
			'member_groups' => 'string', 'redirect' => 'string',
		);
		$board_parameters = array(
			$boardOptions['target_category'], $boardOptions['board_name'], '', 0,
			'-1,0', '',
		);

		// Insert a board, the settings are dealt with later.
		$result = $this->db->insert('',
			'{db_prefix}boards',
			$board_columns,
			$board_parameters,
			array('id_board')
		);
		$board_id = $result->insertId('{db_prefix}boards', 'id_board');

		if (empty($board_id))
			return 0;

		// Change the board according to the given specifications.
		$this->modifyBoard($board_id, $boardOptions);

		// Do we want the parent permissions to be inherited?
		if ($boardOptions['inherit_permissions'])
		{
			$this->getBoardTree();

			if (!empty($boards[$board_id]['parent']))
			{
				$board_data = $this->fetchBoardsInfo(array('boards' => $boards[$board_id]['parent']), array('selects' => 'permissions'));

				$this->db->query('', '
				UPDATE {db_prefix}boards
				SET id_profile = {int:new_profile}
				WHERE id_board = {int:current_board}',
					array(
						'new_profile' => $board_data[$boards[$board_id]['parent']]['id_profile'],
						'current_board' => $board_id,
					)
				);
			}
		}

		// Clean the data cache.
		clean_cache('data');

		// Created it.
		logAction('add_board', array('board' => $board_id), 'Admin');

		// Here you are, a new board, ready to be spammed.
		return $board_id;
	}

	/**
	 * Remove one or more boards.
	 *
	 * - Allows to move the children of the board before deleting it
	 * - if moveChildrenTo is set to null, the sub-boards will be deleted.
	 * - Deletes:
	 *   - all topics that are on the given boards;
	 *   - all information that's associated with the given boards;
	 * - updates the statistics to reflect the new situation.
	 *
	 * @package Boards
	 * @param int[] $boards_to_remove
	 * @param int|null $moveChildrenTo = null
	 */
	function deleteBoards($boards_to_remove, $moveChildrenTo = null)
	{
		global $boards;
		// No boards to delete? Return!
		if (empty($boards_to_remove))
			return;

		$this->getBoardTree();

		$this->hooks->hook('delete_board', array($boards_to_remove, &$moveChildrenTo));

		// If $moveChildrenTo is set to null, include the children in the removal.
		if ($moveChildrenTo === null)
		{
			// Get a list of the sub-boards that will also be removed.
			$child_boards_to_remove = array();
			foreach ($boards_to_remove as $board_to_remove)
				$this->recursiveBoards($child_boards_to_remove, $boards[$board_to_remove]['tree']);

			// Merge the children with their parents.
			if (!empty($child_boards_to_remove))
				$boards_to_remove = array_unique(array_merge($boards_to_remove, $child_boards_to_remove));
		}
		// Move the children to a safe home.
		else
		{
			foreach ($boards_to_remove as $id_board)
			{
				// @todo Separate category?
				if ($moveChildrenTo === 0)
					$this->fixChildren($id_board, 0, 0);
				else
					$this->fixChildren($id_board, $boards[$moveChildrenTo]['level'] + 1, $moveChildrenTo);
			}
		}

		// Delete ALL topics in the selected boards (done first so topics can't be marooned.)
		$topics = $this->db->fetchQuery('
		SELECT id_topic
		FROM {db_prefix}topics
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);


		removeTopics($topics, false);

		// Delete the board's logs.
		$this->db->query('', '
		DELETE FROM {db_prefix}log_mark_read
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);
		$this->db->query('', '
		DELETE FROM {db_prefix}log_boards
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);
		$this->db->query('', '
		DELETE FROM {db_prefix}log_notify
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);

		// Delete this board's moderators.
		$this->db->query('', '
		DELETE FROM {db_prefix}moderators
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);

		// Delete any extra events in the calendar.
		$this->db->query('', '
		DELETE FROM {db_prefix}calendar
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);

		// Delete any message icons that only appear on these boards.
		$this->db->query('', '
		DELETE FROM {db_prefix}message_icons
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);

		// Delete the boards.
		$this->db->query('', '
		DELETE FROM {db_prefix}boards
		WHERE id_board IN ({array_int:boards_to_remove})',
			array(
				'boards_to_remove' => $boards_to_remove,
			)
		);

		// Latest message/topic might not be there anymore.
		require_once(ROOTDIR . '/Messages/Messages.subs.php');
		updateMessageStats();

		updateTopicStats();
		updateSettings(array(
			'calendar_updated' => time(),
		));

		// Plus reset the cache to stop people getting odd results.
		updateSettings(array('settings_updated' => time()));

		// Clean the cache as well.
		clean_cache('data');

		// Let's do some serious logging.
		foreach ($boards_to_remove as $id_board)
			logAction('delete_board', array('boardname' => $boards[$id_board]['name']), 'Admin');
	}

	/**
	 * Fixes the children of a board by setting their child_levels to new values.
	 *
	 * - Used when a board is deleted or moved, to affect its children.
	 *
	 * @package Boards
	 * @param int $parent
	 * @param int $newLevel
	 * @param int $newParent
	 */
	function fixChildren($parent, $newLevel, $newParent)
	{
		// Grab all children of $parent...
		$children = $this->db->fetchQueryCallback('
		SELECT id_board
		FROM {db_prefix}boards
		WHERE id_parent = {int:parent_board}',
			array(
				'parent_board' => $parent,
			),
			function($row)
			{
				return $row['id_board'];
			}
		);

		// ...and set it to a new parent and child_level.
		$this->db->query('', '
		UPDATE {db_prefix}boards
		SET id_parent = {int:new_parent}, child_level = {int:new_child_level}
		WHERE id_parent = {int:parent_board}',
			array(
				'new_parent' => $newParent,
				'new_child_level' => $newLevel,
				'parent_board' => $parent,
			)
		);

		// Recursively fix the children of the children.
		foreach ($children as $child)
			$this->fixChildren($child, $newLevel + 1, $child);
	}

	/**
	 * Load a lot of useful information regarding the boards and categories.
	 *
	 * - The information retrieved is stored in globals:
	 *   $boards:    properties of each board.
	 *   $boardList: a list of boards grouped by category ID.
	 *   $cat_tree:  properties of each category.
	 *
	 * @package Boards
	 */
	function getBoardTree()
	{
		global $cat_tree, $boards, $boardList;

		// Getting all the board and category information you'd ever wanted.
		$request = $this->db->query('', '
		SELECT
			IFNULL(b.id_board, 0) AS id_board, b.id_parent, b.name AS board_name, b.description, b.child_level,
			b.board_order, b.count_posts, b.member_groups, b.id_theme, b.override_theme, b.id_profile, b.redirect,
			b.num_posts, b.num_topics, b.deny_member_groups, c.id_cat, c.name AS cat_name, c.cat_order, c.can_collapse
		FROM {db_prefix}categories AS c
			LEFT JOIN {db_prefix}boards AS b ON (b.id_cat = c.id_cat)
		ORDER BY c.cat_order, b.child_level, b.board_order',
			array(
			)
		);
		$cat_tree = array();
		$boards = array();
		$last_board_order = 0;
		$prevBoard = null;
		$curLevel = null;

		while ($row = $request->fetchAssoc())
		{
			if (!isset($cat_tree[$row['id_cat']]))
			{
				$cat_tree[$row['id_cat']] = array(
					'node' => array(
						'id' => $row['id_cat'],
						'name' => $row['cat_name'],
						'order' => $row['cat_order'],
						'can_collapse' => $row['can_collapse']
					),
					'is_first' => empty($cat_tree),
					'last_board_order' => $last_board_order,
					'children' => array()
				);
				$prevBoard = 0;
				$curLevel = 0;
			}

			if (!empty($row['id_board']))
			{
				if ($row['child_level'] != $curLevel)
					$prevBoard = 0;

				$boards[$row['id_board']] = array(
					'id' => $row['id_board'],
					'category' => $row['id_cat'],
					'parent' => $row['id_parent'],
					'level' => $row['child_level'],
					'order' => $row['board_order'],
					'name' => $row['board_name'],
					'member_groups' => explode(',', $row['member_groups']),
					'deny_groups' => explode(',', $row['deny_member_groups']),
					'description' => $row['description'],
					'count_posts' => empty($row['count_posts']),
					'posts' => $row['num_posts'],
					'topics' => $row['num_topics'],
					'theme' => $row['id_theme'],
					'override_theme' => $row['override_theme'],
					'profile' => $row['id_profile'],
					'redirect' => $row['redirect'],
					'prev_board' => $prevBoard
				);
				$prevBoard = $row['id_board'];
				$last_board_order = $row['board_order'];

				if (empty($row['child_level']))
				{
					$cat_tree[$row['id_cat']]['children'][$row['id_board']] = array(
						'node' => &$boards[$row['id_board']],
						'is_first' => empty($cat_tree[$row['id_cat']]['children']),
						'children' => array()
					);
					$boards[$row['id_board']]['tree'] = &$cat_tree[$row['id_cat']]['children'][$row['id_board']];
				}
				else
				{
					// Parent doesn't exist!
					if (!isset($boards[$row['id_parent']]['tree']))
						$this->errors->fatal_lang_error('no_valid_parent', false, array($row['board_name']));

					// Wrong childlevel...we can silently fix this...
					if ($boards[$row['id_parent']]['tree']['node']['level'] != $row['child_level'] - 1)
						$this->db->query('', '
						UPDATE {db_prefix}boards
						SET child_level = {int:new_child_level}
						WHERE id_board = {int:selected_board}',
							array(
								'new_child_level' => $boards[$row['id_parent']]['tree']['node']['level'] + 1,
								'selected_board' => $row['id_board'],
							)
						);

					$boards[$row['id_parent']]['tree']['children'][$row['id_board']] = array(
						'node' => &$boards[$row['id_board']],
						'is_first' => empty($boards[$row['id_parent']]['tree']['children']),
						'children' => array()
					);
					$boards[$row['id_board']]['tree'] = &$boards[$row['id_parent']]['tree']['children'][$row['id_board']];
				}
			}
		}
		$request->free();

		// Get a list of all the boards in each category (using recursion).
		$boardList = array();
		foreach ($cat_tree as $catID => $node)
		{
			$boardList[$catID] = array();
			$this->recursiveBoards($boardList[$catID], $node);
		}
	}

	/**
	 * Generates the query to determine the list of available boards for a user
	 *
	 * - Executes the query and returns the list
	 *
	 * @package Boards
	 * @param mixed[] $boardListOptions
	 * @param boolean $simple if true a simple array is returned containing some basic
	 *                information regarding the board (id_board, board_name, child_level, id_cat, cat_name)
	 *                if false the boards are returned in an array subdivided by categories including also
	 *                additional data like the number of boards
	 * @return array An array of boards sorted according to the normal boards order
	 */
	function getBoardList($boardListOptions = array(), $simple = false)
	{
		global $modSettings;


		if ((isset($boardListOptions['excluded_boards']) || isset($boardListOptions['allowed_to'])) && isset($boardListOptions['included_boards']))
			trigger_error('getBoardList(): Setting both excluded_boards and included_boards is not allowed.', E_USER_ERROR);

		$where = array();
		$join = array();
		$select = '';
		$where_parameters = array();

		// Any boards to exclude
		if (isset($boardListOptions['excluded_boards']))
		{
			$where[] = 'b.id_board NOT IN ({array_int:excluded_boards})';
			$where_parameters['excluded_boards'] = $boardListOptions['excluded_boards'];
		}

		// Get list of boards to which they have specific permissions
		if (isset($boardListOptions['allowed_to']))
		{
			$boardListOptions['included_boards'] = boardsAllowedTo($boardListOptions['allowed_to']);
			if (in_array(0, $boardListOptions['included_boards']))
				unset($boardListOptions['included_boards']);
		}

		// Just want to include certain boards in the query
		if (isset($boardListOptions['included_boards']))
		{
			$where[] = 'b.id_board IN ({array_int:included_boards})';
			$where_parameters['included_boards'] = $boardListOptions['included_boards'];
		}

		// Determine if they can access a given board and return yea or nay in the results array
		if (isset($boardListOptions['access']))
		{
			$select .= ',
			FIND_IN_SET({string:current_group}, b.member_groups) != 0 AS can_access,
			FIND_IN_SET({string:current_group}, b.deny_member_groups) != 0 AS cannot_access';
			$where_parameters['current_group'] = $boardListOptions['access'];
		}

		// Leave out the boards that the user may be ignoring
		if (isset($boardListOptions['ignore']))
		{
			$select .= ',' . (!empty($boardListOptions['ignore']) ? 'b.id_board IN ({array_int:ignore_boards})' : '0') . ' AS is_ignored';
			$where_parameters['ignore_boards'] = $boardListOptions['ignore'];
		}

		// Want to check if the member is a moderators for any boards
		if (isset($boardListOptions['moderator']))
		{
			$join[] = '
			LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = b.id_board AND mods.id_member = {int:current_member})';
			$select .= ', b.id_profile, b.member_groups, IFNULL(mods.id_member, 0) AS is_mod';
			$where_parameters['current_member'] = $boardListOptions['moderator'];
		}

		if (!empty($boardListOptions['ignore_boards']) && empty($boardListOptions['override_permissions']))
			$where[] = '{query_wanna_see_board}';

		elseif (empty($boardListOptions['override_permissions']))
			$where[] = '{query_see_board}';

		if (!empty($boardListOptions['not_redirection']))
		{
			$where[] = 'b.redirect = {string:blank_redirect}';
			$where_parameters['blank_redirect'] = '';
		}

		// Bring all the options together and make the query
		$request = $this->db->query('', '
		SELECT c.name AS cat_name, c.id_cat, b.id_board, b.name AS board_name, b.child_level' . $select . '
		FROM {db_prefix}boards AS b
			LEFT JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)' . (empty($join) ? '' : implode(' ', $join)) . (empty($where) ? '' : '
		WHERE ' . implode('
			AND ', $where)) . '
		ORDER BY c.cat_order, b.board_order',
			$where_parameters
		);

		// Build our output arrays, simple or complete
		if ($simple)
		{
			$return_value = array();
			while ($row = $request->fetchAssoc())
			{
				$return_value[$row['id_board']] = array(
					'id_cat' => $row['id_cat'],
					'cat_name' => $row['cat_name'],
					'id_board' => $row['id_board'],
					'board_name' => $row['board_name'],
					'child_level' => $row['child_level'],
				);

				// Do we want access information?
				if (isset($boardListOptions['access']) && $boardListOptions['access'] !== false)
				{
					$return_value[$row['id_board']]['allow'] = !(empty($row['can_access']) || $row['can_access'] == 'f');
					$return_value[$row['id_board']]['deny'] = !(empty($row['cannot_access']) || $row['cannot_access'] == 'f');
				}

				// Do we want moderation information?
				if (!empty($boardListOptions['moderator']))
				{
					$return_value[$row['id_board']] += array(
						'id_profile' => $row['id_profile'],
						'member_groups' => $row['member_groups'],
						'is_mod' => $row['is_mod'],
					);
				}
			}
		}
		else
		{
			$return_value = array(
				'num_boards' => $request->numRows(),
				'boards_check_all' => true,
				'boards_current_disabled' => true,
				'categories' => array(),
			);
			while ($row = $request->fetchAssoc())
			{
				// This category hasn't been set up yet..
				if (!isset($return_value['categories'][$row['id_cat']]))
					$return_value['categories'][$row['id_cat']] = array(
						'id' => $row['id_cat'],
						'name' => $row['cat_name'],
						'boards' => array(),
					);

				// Shortcuts are useful to keep things simple
				$this_cat = &$return_value['categories'][$row['id_cat']];

				$this_cat['boards'][$row['id_board']] = array(
					'id' => $row['id_board'],
					'name' => $row['board_name'],
					'child_level' => $row['child_level'],
					'allow' => false,
					'deny' => false,
					'selected' => isset($boardListOptions['selected_board']) && $boardListOptions['selected_board'] == $row['id_board'],
				);
				// Do we want access information?

				if (!empty($boardListOptions['access']))
				{
					$this_cat['boards'][$row['id_board']]['allow'] = !(empty($row['can_access']) || $row['can_access'] == 'f');
					$this_cat['boards'][$row['id_board']]['deny'] = !(empty($row['cannot_access']) || $row['cannot_access'] == 'f');
				}

				// If is_ignored is set, it means we could have to deselect a board
				if (isset($row['is_ignored']))
				{
					$this_cat['boards'][$row['id_board']]['selected'] = $row['is_ignored'];

					// If a board wasn't checked that probably should have been ensure the board selection is selected, yo!
					if (!empty($this_cat['boards'][$row['id_board']]['selected']) && (empty($modSettings['recycle_enable']) || $row['id_board'] != $modSettings['recycle_board']))
						$return_value['boards_check_all'] = false;
				}

				// Do we want moderation information?
				if (!empty($boardListOptions['moderator']))
				{
					$this_cat['boards'][$row['id_board']] += array(
						'id_profile' => $row['id_profile'],
						'member_groups' => $row['member_groups'],
						'is_mod' => $row['is_mod'],
					);
				}
			}
		}

		$request->free();

		return $return_value;
	}

	/**
	 * Recursively get a list of boards.
	 *
	 * - Used by getBoardTree
	 *
	 * @package Boards
	 * @param int[] $_boardList The board list
	 * @param array $_tree the board tree
	 */
	function recursiveBoards(&$_boardList, &$_tree)
	{
		if (empty($_tree['children']))
			return;

		foreach ($_tree['children'] as $id => $node)
		{
			$_boardList[] = $id;
			$this->recursiveBoards($_boardList, $node);
		}
	}

	/**
	 * Returns whether the sub-board id is actually a child of the parent (recursive).
	 *
	 * @package Boards
	 * @param int $child The ID of the child board
	 * @param int $parent The ID of a parent board
	 *
	 * @return boolean if the specified child board is a child of the specified parent board.
	 */
	function isChildOf($child, $parent)
	{
		global $boards;

		if (empty($boards[$child]['parent']))
			return false;

		if ($boards[$child]['parent'] == $parent)
			return true;

		return $this->isChildOf($boards[$child]['parent'], $parent);
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

	/**
	 * Returns all the boards accessible to the current user.
	 *
	 * - If $id_parents is given, return only the sub-boards of those boards.
	 * - If $id_boards is given, filters the boards to only those accessible.
	 * - The function doesn't guarantee the boards are properly sorted
	 *
	 * @package Boards
	 * @param int[]|null $id_parents array of ints representing board ids
	 * @param int[]|null $id_boards
	 * @return int[]
	 */
	function accessibleBoards($id_boards = null, $id_parents = null)
	{
		$boards = array();
		if (!empty($id_parents))
		{
			// Find all boards down from $id_parent
			$request = $this->db->query('', '
			SELECT b.id_board
			FROM {db_prefix}boards AS b
			WHERE b.id_parent IN ({array_int:parent_list})
				AND {query_see_board}',
				array(
					'parent_list' => $id_parents,
				)
			);
		}
		elseif (!empty($id_boards))
		{
			// Find all the boards this user can see between those selected
			$request = $this->db->query('', '
			SELECT b.id_board
			FROM {db_prefix}boards AS b
			WHERE b.id_board IN ({array_int:board_list})
				AND {query_see_board}',
				array(
					'board_list' => $id_boards,
				)
			);
		}
		else
		{
			// Find all the boards this user can see.
			$request = $this->db->query('', '
			SELECT b.id_board
			FROM {db_prefix}boards AS b
			WHERE {query_see_board}',
				array(
				)
			);
		}

		while ($row = $request->fetchAssoc())
			$boards[] = $row['id_board'];
		$request->free();

		return $boards;
	}

	/**
	 * Returns the boards the current user wants to see.
	 *
	 * @package Boards
	 * @param string $see_board either 'query_see_board' or 'query_wanna_see_board'
	 * @param bool $hide_recycle is tru the recycle bin is not returned
	 * @return int[]
	 */
	function wantedBoards($see_board, $hide_recycle = true)
	{
		global $modSettings, $user_info;

		$allowed_see = array(
			'query_see_board',
			'query_wanna_see_board'
		);

		// Find all boards down from $id_parent
		return $this->db->fetchQueryCallback('
		SELECT b.id_board
		FROM {db_prefix}boards AS b
		WHERE ' . $user_info[in_array($see_board, $allowed_see) ? $see_board : $allowed_see[0]] . ($hide_recycle && !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
			AND b.id_board != {int:recycle_board}' : ''),
			array(
				'recycle_board' => (int) $modSettings['recycle_board'],
			),
			function($row)
			{
				return $row['id_board'];
			}
		);
	}

	/**
	 * Returns the post count and name of a board
	 *
	 * - if supplied a topic id will also return the message subject
	 * - honors query_see_board to ensure a user can see the information
	 *
	 * @package Boards
	 * @param int $board_id
	 * @param int|null $topic_id
	 */
	function boardInfo($board_id, $topic_id = null)
	{

		if (!empty($topic_id))
		{
			$request = $this->db->query('', '
			SELECT b.count_posts, b.name, m.subject
			FROM {db_prefix}boards AS b
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = {int:current_topic})
				INNER JOIN {db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)
			WHERE {query_see_board}
				AND b.id_board = {int:board}
				AND b.redirect = {string:blank_redirect}
			LIMIT 1',
				array(
					'current_topic' => $topic_id,
					'board' => $board_id,
					'blank_redirect' => '',
				)
			);
		}
		else
		{
			$request = $this->db->query('', '
			SELECT b.count_posts, b.name
			FROM {db_prefix}boards AS b
			WHERE {query_see_board}
				AND b.id_board = {int:board}
				AND b.redirect = {string:blank_redirect}
			LIMIT 1',
				array(
					'board' => $board_id,
					'blank_redirect' => '',
				)
			);
		}

		$returns = $request->fetchAssoc();
		$request->free();

		return $returns;
	}

	/**
	 * Loads properties from non-standard groups
	 *
	 * @package Boards
	 * @param int $curBoard
	 * @param boolean $new_board = false Whether this is a new board
	 * @return array
	 */
	function getOtherGroups($curBoard, $new_board = false)
	{

		$groups = array();

		// Load membergroups.
		$request = $this->db->query('', '
		SELECT group_name, id_group, min_posts
		FROM {db_prefix}membergroups
		WHERE id_group > {int:moderator_group} OR id_group = {int:global_moderator}
		ORDER BY min_posts, id_group != {int:global_moderator}, group_name',
			array(
				'moderator_group' => 3,
				'global_moderator' => 2,
			)
		);

		while ($row = $request->fetchAssoc())
		{
			if ($new_board && $row['min_posts'] == -1)
				$curBoard['member_groups'][] = $row['id_group'];

			$groups[(int) $row['id_group']] = array(
				'id' => $row['id_group'],
				'name' => trim($row['group_name']),
				'allow' => in_array($row['id_group'], $curBoard['member_groups']),
				'deny' => in_array($row['id_group'], $curBoard['deny_groups']),
				'is_post_group' => $row['min_posts'] != -1,
			);
		}
		$request->free();

		return $groups;
	}

	/**
	 * Get a list of moderators from a specific board
	 *
	 * @package Boards
	 * @param int $idboard
	 * @param bool $only_id return only the id of the moderators instead of id and name (default false)
	 * @return array
	 */
	function getBoardModerators($idboard, $only_id = false)
	{

		$moderators = array();

		if ($only_id)
		{
			$request = $this->db->query('', '
			SELECT id_member
			FROM {db_prefix}moderators
			WHERE id_board = {int:current_board}',
				array(
					'current_board' => $idboard,
				)
			);
			while ($row = $request->fetchAssoc())
				$moderators[] = $row['id_member'];
		}
		else
		{
			$request = $this->db->query('', '
			SELECT mem.id_member, mem.real_name
			FROM {db_prefix}moderators AS mods
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)
			WHERE mods.id_board = {int:current_board}',
				array(
					'current_board' => $idboard,
				)
			);

			while ($row = $request->fetchAssoc())
				$moderators[$row['id_member']] = $row['real_name'];
		}
		$request->free();

		return $moderators;
	}

	/**
	 * Get a list of all the board moderators (every board)
	 *
	 * @package Boards
	 * @param bool $only_id return array with key of id_member of the moderator(s)
	 * otherwise array with key of id_board id (default false)
	 * @return array
	 */
	function allBoardModerators($only_id = false)
	{
		$moderators = array();

		if ($only_id)
			$request = $this->db->query('', '
			SELECT id_board, id_member
			FROM {db_prefix}moderators',
				array(
				)
			);
		else
			$request = $this->db->query('', '
			SELECT mods.id_board, mods.id_member, mem.real_name
			FROM {db_prefix}moderators AS mods
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = mods.id_member)',
				array(
				)
			);

		while ($row = $request->fetchAssoc())
		{
			if ($only_id)
				$moderators[$row['id_member']][] = $row;
			else
				$moderators[$row['id_board']][] = $row;
		}
		$request->free();

		return $moderators;
	}

	/**
	 * Get a list of all the board moderated by a certain user
	 *
	 * @package Boards
	 * @param int $id_member the id of a member
	 * @return array
	 */
	function boardsModerated($id_member)
	{
		return $this->db->fetchQueryCallback('
		SELECT id_board
		FROM {db_prefix}moderators
		WHERE id_member = {int:current_member}',
			array(
				'current_member' => $id_member,
			),
			function($row)
			{
				return $row['id_board'];
			}
		);
	}

	/**
	 * Gets redirect infos and post count from a selected board.
	 *
	 * @package Boards
	 * @param int $idboard
	 * @return array
	 */
	function getBoardProperties($idboard)
	{
		$properties = array();

		$request = $this->db->query('', '
		SELECT redirect, num_posts
		FROM {db_prefix}boards
		WHERE id_board = {int:current_board}',
			array(
				'current_board' => $idboard,
			)
		);
		list ($properties['oldRedirect'], $properties['numPosts']) = $request->fetchRow();
		$request->free();

		return $properties;
	}

	/**
	 * Fetch the number of posts in an array of boards based on board IDs or category IDs
	 *
	 * @package Boards
	 * @param int[]|null $boards an array of board IDs
	 * @param int[]|null $categories an array of category IDs
	 * @param bool $wanna_see_board if true uses {query_wanna_see_board}, otherwise {query_see_board}
	 * @param bool $include_recycle if false excludes any results from the recycle board (if enabled)
	 * @return int[]|array()
	 */
	function boardsPosts($boards, $categories, $wanna_see_board = false, $include_recycle = true)
	{
		global $modSettings;

		$clauses = array();
		$removals = array();
		$clauseParameters = array();

		if (!empty($categories))
		{
			$clauses[] = 'id_cat IN ({array_int:category_list})';
			$clauseParameters['category_list'] = $categories;
		}

		if (!empty($boards))
		{
			$clauses[] = 'id_board IN ({array_int:board_list})';
			$clauseParameters['board_list'] = $boards;
		}

		if (empty($include_recycle) && (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0))
		{
			$removals[] = 'id_board != {int:recycle_board}';
			$clauseParameters['recycle_board'] = (int) $modSettings['recycle_board'];
		}

		if (empty($clauses))
			return array();

		$request = $this->db->query('', '
		SELECT b.id_board, b.num_posts
		FROM {db_prefix}boards AS b
		WHERE ' . ($wanna_see_board ? '{query_wanna_see_board}' : '{query_see_board}') . '
			AND b.' . implode(' OR b.', $clauses) . (!empty($removals) ? '
			AND b.' . implode(' AND b.', $removals) : ''),
			$clauseParameters
		);
		$return = array();
		while ($row = $request->fetchAssoc())
			$return[$row['id_board']] = $row['num_posts'];
		$request->free();

		return $return;
	}

	/**
	 * Returns the total sum of posts in the boards defined by query_wanna_see_board
	 * Excludes the count of any boards defined as a recycle board from the sum
	 * @return array
	 */
	function sumRecentPosts()
	{

		global $modSettings;

		$request = $this->db->query('', '
		SELECT IFNULL(SUM(num_posts), 0)
		FROM {db_prefix}boards as b
		WHERE {query_wanna_see_board}' . (!empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] > 0 ? '
			AND b.id_board != {int:recycle_board}' : ''),
			array(
				'recycle_board' => $modSettings['recycle_board']
			)
		);
		list ($result) = $request->fetchRow();
		$request->free();

		return $result;
	}

	/**
	 * Returns information of a set of boards based on board IDs or category IDs
	 *
	 * @package Boards
	 * @param mixed[]|string $conditions is an associative array that holds the board or the cat IDs
	 *              'categories' => an array of category IDs (it accepts a single ID too)
	 *              'boards' => an array of board IDs (it accepts a single ID too)
	 *              if conditions is set to 'all' (not an array) all the boards are queried
	 * @param mixed[] $params is an optional array that allows to control the results returned:
	 *              'sort_by' => (string) defines the sorting of the results (allowed: id_board, name)
	 *              'selects' => (string) determines what information are retrieved and returned
	 *                           Allowed values: 'name', 'posts', 'detailed', 'permissions', 'reports';
	 *                           default: 'name';
	 *                           see the function for details on the fields associated to each value
	 *              'override_permissions' => (bool) if true doesn't use neither {query_wanna_see_board} nor {query_see_board} (default false)
	 *              'wanna_see_board' => (bool) if true uses {query_wanna_see_board}, otherwise {query_see_board}
	 *              'include_recycle' => (bool) recycle board is included (default true)
	 *              'include_redirects' => (bool) redirects are included (default true)
	 *
	 * @todo unify the two queries?
	 * @return array[]
	 */
	function fetchBoardsInfo($conditions = 'all', $params = array())
	{
		global $modSettings;


		// Ensure default values are set
		$params = array_merge(array('override_permissions' => false, 'wanna_see_board' => false, 'include_recycle' => true, 'include_redirects' => true), $params);

		$clauses = array();
		$clauseParameters = array();
		$allowed_sort = array(
			'id_board',
			'name'
		);

		if (!empty($params['sort_by']) && in_array($params['sort_by'], $allowed_sort))
			$sort_by = 'ORDER BY ' . $params['sort_by'];
		else
			$sort_by = '';

		// @todo: memos for optimization
		/*
			id_board    => MergeTopic + MergeTopic + MessageIndex + Search + ScheduledTasks
			name        => MergeTopic + ScheduledTasks + News
			count_posts => MessageIndex
			num_posts   => News
		*/
		$known_selects = array(
			'name' => 'b.id_board, b.name',
			'posts' => 'b.id_board, b.count_posts, b.num_posts',
			'detailed' => 'b.id_board, b.name, b.count_posts, b.num_posts',
			'permissions' => 'b.id_board, b.name, b.member_groups, b.id_profile',
			'reports' => 'b.id_board, b.name, b.member_groups, b.id_profile, b.deny_member_groups',
		);

		$select = $known_selects[empty($params['selects']) || !isset($known_selects[$params['selects']]) ? 'name' : $params['selects']];

		// If $conditions wasn't set or is 'all', get all boards
		if (!is_array($conditions) && $conditions == 'all')
		{
			// id_board, name, id_profile => used in Admin/Reports.controller.php
			$request = $this->db->query('', '
			SELECT ' . $select . '
			FROM {db_prefix}boards AS b
			' . $sort_by,
				array()
			);
		}
		else
		{
			// Only some categories?
			if (!empty($conditions['categories']))
			{
				$clauses[] = 'id_cat IN ({array_int:category_list})';
				$clauseParameters['category_list'] = is_array($conditions['categories']) ? $conditions['categories'] : array($conditions['categories']);
			}

			// Only a few boards, perhaps!
			if (!empty($conditions['boards']))
			{
				$clauses[] = 'id_board IN ({array_int:board_list})';
				$clauseParameters['board_list'] = is_array($conditions['boards']) ? $conditions['boards'] : array($conditions['boards']);
			}

			if ($params['override_permissions'])
				$security = '1=1';
			else
				$security = $params['wanna_see_board'] ? '{query_wanna_see_board}' : '{query_see_board}';

			$request = $this->db->query('', '
			SELECT ' . $select . '
			FROM {db_prefix}boards AS b
			WHERE ' . $security . (!empty($clauses) ? '
				AND b.' . implode(' OR b.', $clauses) : '') . ($params['include_recycle'] ? '' : '
				AND b.id_board != {int:recycle_board}') . ($params['include_redirects'] ? '' : '
				AND b.redirect = {string:empty_string}
			' . $sort_by),
				array_merge($clauseParameters, array(
					'recycle_board' => !empty($modSettings['recycle_board']) ? $modSettings['recycle_board'] : 0,
					'empty_string' => '',
				))
			);
		}
		$return = array();
		while ($row = $request->fetchAssoc())
			$return[$row['id_board']] = $row;

		$request->free();

		return $return;
	}

	/**
	 * Retrieve the all the sub-boards of an array of boards and add the ids to the same array
	 *
	 * @package Boards
	 * @param int[]|int $boards an array of board IDs (it accepts a single board too).
	 * @deprecated since 1.1 - The param is passed by ref in 1.0 and the result
	 *                         is returned through the param itself, starting from
	 *                         1.1 the expected behaviour is that the result is
	 *                         returned.
	 *                         The pass-by-ref is kept for backward compatibility.
	 * @return int[]
	 */
	function addChildBoards(&$boards)
	{
		if (empty($boards))
			return false;

		if (!is_array($boards))
			$boards = array($boards);

		$request = $this->db->query('', '
		SELECT b.id_board, b.id_parent
		FROM {db_prefix}boards AS b
		WHERE {query_see_board}
			AND b.child_level > {int:no_parents}
			AND b.id_board NOT IN ({array_int:board_list})
		ORDER BY child_level ASC
		',
			array(
				'no_parents' => 0,
				'board_list' => $boards,
			)
		);
		while ($row = $request->fetchAssoc())
			if (in_array($row['id_parent'], $boards))
				$boards[] = $row['id_board'];
		$request->free();

		return $boards;
	}

	/**
	 * Increment a board stat field, for example num_posts.
	 *
	 * @package Boards
	 * @param int $id_board
	 * @param mixed[]|string $values an array of index => value of a string representing the index to increment
	 */
	function incrementBoard($id_board, $values)
	{

		$knownInts = array(
			'child_level', 'board_order', 'num_topics', 'num_posts', 'count_posts',
			'unapproved_posts', 'unapproved_topics'
		);

		$this->hooks->hook('board_fields', array(&$knownInts));

		$set = array();
		$params = array('id_board' => $id_board);
		$values = is_array($values) ? $values : array($values => 1);

		foreach ($values as $key => $val)
		{
			if (in_array($key, $knownInts))
			{
				$set[] = $key . ' = ' . $key . ' + {int:' . $key . '}';
				$params[$key] = $val;
			}
		}

		if (empty($set))
			return;

		$this->db->query('', '
		UPDATE {db_prefix}boards
		SET
			' . implode(',
			', $set) . '
		WHERE id_board = {int:id_board}',
			$params
		);
	}

	/**
	 * Decrement a board stat field, for example num_posts.
	 *
	 * @package Boards
	 * @param int $id_board
	 * @param mixed[]|string $values an array of index => value of a string representing the index to decrement
	 */
	function decrementBoard($id_board, $values)
	{

		$knownInts = array(
			'child_level', 'board_order', 'num_topics', 'num_posts', 'count_posts',
			'unapproved_posts', 'unapproved_topics'
		);

		$this->hooks->hook('board_fields', array(&$knownInts));

		$set = array();
		$params = array('id_board' => $id_board);
		$values = is_array($values) ? $values : array($values => 1);

		foreach ($values as $key => $val)
		{
			if (in_array($key, $knownInts))
			{
				$set[] = $key . ' = CASE WHEN {int:' . $key . '} > ' . $key . ' THEN 0 ELSE ' . $key . ' - {int:' . $key . '} END';
				$params[$key] = $val;
			}
		}

		if (empty($set))
			return;

		$this->db->query('', '
		UPDATE {db_prefix}boards
		SET
			' . implode(',
			', $set) . '
		WHERE id_board = {int:id_board}',
			$params
		);
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
	 * Count boards all or specific depending on argument, redirect boards excluded by default.
	 *
	 * @package Boards
	 * @param mixed[]|string $conditions is an associative array that holds the board or the cat IDs
	 *              'categories' => an array of category IDs (it accepts a single ID too)
	 *              'boards' => an array of board IDs (it accepts a single ID too)
	 *              if conditions is set to 'all' (not an array) all the boards are queried
	 * @param mixed[]|null $params is an optional array that allows to control the results returned if $conditions is not set to 'all':
	 *              'wanna_see_board' => (bool) if true uses {query_wanna_see_board}, otherwise {query_see_board}
	 *              'include_recycle' => (bool) recycle board is included (default true)
	 *              'include_redirects' => (bool) redirects are included (default true)
	 * @return int
	 */
	function countBoards($conditions = 'all', $params = array())
	{
		global $modSettings;


		// Ensure default values are set
		$params = array_merge(array('wanna_see_board' => false, 'include_recycle' => true, 'include_redirects' => true), $params);

		$clauses = array();
		$clauseParameters = array();

		// if $conditions wasn't set or is 'all', get all boards
		if (!is_array($conditions) && $conditions == 'all')
		{
			// id_board, name, id_profile => used in Admin/Reports.controller.php
			$request = $this->db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}boards AS b',
				array()
			);
		}
		else
		{
			// only some categories?
			if (!empty($conditions['categories']))
			{
				$clauses[] = 'id_cat IN ({array_int:category_list})';
				$clauseParameters['category_list'] = is_array($conditions['categories']) ? $conditions['categories'] : array($conditions['categories']);
			}

			// only a few boards, perhaps!
			if (!empty($conditions['boards']))
			{
				$clauses[] = 'id_board IN ({array_int:board_list})';
				$clauseParameters['board_list'] = is_array($conditions['boards']) ? $conditions['boards'] : array($conditions['boards']);
			}

			$request = $this->db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}boards AS b
			WHERE ' . ($params['wanna_see_board'] ? '{query_wanna_see_board}' : '{query_see_board}') . (!empty($clauses) ? '
				AND b.' . implode(' OR b.', $clauses) : '') . ($params['include_recycle'] ? '' : '
				AND b.id_board != {int:recycle_board}') . ($params['include_redirects'] ? '' : '
				AND b.redirect = {string:empty_string}'),
				array_merge($clauseParameters, array(
					'recycle_board' => !empty($modSettings['recycle_board']) ? $modSettings['recycle_board'] : 0,
					'empty_string' => '',
				))
			);
		}

		list ($num_boards) = $request->fetchRow();
		$request->free();

		return $num_boards;
	}
}