<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;

class BoardsManager
{
	protected $db;
	protected $cache;
	protected $hooks;
	protected $errors;

	public function __construct(DatabaseInterface $db, Cache $cache, Hooks $hooks, Errors $errors)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->hooks = $hooks;
		$this->errors = $errors;
	}

	public function load()
	{
		global $txt, $scripturl, $context, $modSettings;
		global $board_info, $board, $topic, $user_info;

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
		if (empty($_REQUEST['action']) && empty($topic) && empty($board) && !empty($_REQUEST['msg']))
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
		if (empty($board) && empty($topic))
		{
			$board_info = array('moderators' => array());
			return;
		}

		if ($this->cache->isEnabled() && (empty($topic) || $this->cache->checkLevel(3)))
		{
			// @todo SLOW?
			if (!empty($topic))
				$temp = $this->cache->get('topic_board-' . $topic, 120);
			else
				$temp = $this->cache->get('board-' . $board, 120);

			if (!empty($temp))
			{
				$board_info = $temp;
				$board = $board_info['id'];
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
					'board_link' => empty($topic) ? $this->db->quote('{int:current_board}', array('current_board' => $board)) : 't.id_board',
				)
			);
			// If there aren't any, skip.
			if ($request->numRows() > 0)
			{
				$row = $request->fetchAssoc();

				// Set the current board.
				if (!empty($row['id_board']))
					$board = $row['id_board'];

				$category = new Category([
					'id' => $row['id_cat'],
					'name' => $row['cname'],
					'boards' => [$board],
				]);

				// Basic operating information. (globals... :/)
				$board_info = new Board([
					'id' => $board,
					'moderators' => array(),
					'cat' => $category,
					'name' => $row['bname'],
					'raw_description' => $row['description'],
					'description' => $row['description'],
					'num_topics' => $row['num_topics'],
					'unapproved_topics' => $row['unapproved_topics'],
					'unapproved_posts' => $row['unapproved_posts'],
					'unapproved_user_topics' => 0,
					//'parent_boards' => getBoardParents($row['id_parent']),
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
							'board' => $board,
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
					$this->cache->put('board-' . $board, $board_info, 120);
				}
			}
			else
			{
				// Otherwise the topic is invalid, there are no moderators, etc.
				$board_info = array(
					'error' => 'exist'
				);
				$topic = null;
				$board = 0;
			}
			$request->free();
		}

		if (!empty($topic))
			$_GET['board'] = (int) $board;

		if (!empty($board))
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
				array_reverse($board_info->parentBoards()),
				array(array(
					'url' => $scripturl . '?board=' . $board . '.0',
					'name' => $board_info['name']
				))
			);
		}

		// Set the template contextual information.
		$context['user']['is_mod'] = &$user_info['is_mod'];
		$context['user']['is_moderator'] = &$user_info['is_moderator'];
		$context['current_topic'] = $topic;
		$context['current_board'] = $board;

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

		$db = $GLOBALS['elk']['db'];
		$cache = $GLOBALS['elk']['cache'];

		// First check if we have this cached already.
		if (!$cache->getVar($boards, 'board_parents-' . $id_parent, 480))
		{
			$boards = array();
			$original_parent = $id_parent;

			// Loop while the parent is non-zero.
			while ($id_parent != 0)
			{
				$result = $db->query('', '
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
					$GLOBALS['elk']['errors']->fatal_lang_error('parent_not_found', 'critical');
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

			$cache->put('board_parents-' . $original_parent, $boards, 480);
		}

		return $boards;
	}
}