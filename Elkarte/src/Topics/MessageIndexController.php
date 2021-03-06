<?php

/**
 * This file is what shows the listing of topics in a board.
 * It's just one or two functions, but don't underestimate it ;).
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
 * @version 1.1 dev
 *
 */

namespace Elkarte\Topics;

use Elkarte\Boards\BoardsList;
use Elkarte\Boards\BoardsManager;
use Elkarte\Elkarte\Controller\AbstractController;
use Elkarte\Elkarte\Controller\FrontpageInterface;
use Pimple\Container;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Theme\TemplateLayers;

/**
 * Message Index Controller
 */
class MessageIndexController extends AbstractController implements FrontpageInterface
{
	public function __construct(Container $elk, Hooks $hooks, Errors $errors, TemplateLayers $layers,
								BoardsManager $boards_manager)
	{
		$this->elk = $elk;

		$this->bootstrap();

		$this->hooks = $hooks;
		$this->errors = $errors;
		$this->_layers = $layers;
		$this->boards_manager = $boards_manager;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function frontPageHook(&$default_action)
	{
		$default_action = array(
			'controller' => 'MessageIndexController',
			'function' => 'action_messageindex_fp'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public static function frontPageOptions()
	{
		theme()->addInlineJavascript('
			$(\'#front_page\').on(\'change\', function() {
				var $base = $(\'#message_index_frontpage\').parent();
				if ($(this).val() == \'MessageIndexController\')
				{
					$base.fadeIn();
					$base.prev().fadeIn();
				}
				else
				{
					$base.fadeOut();
					$base.prev().fadeOut();
				}
			}).change();', true);

		return array(array('select', 'message_index_frontpage', self::_getBoardsList()));
	}

	/**
	 * {@inheritdoc}
	 */
	public static function validateFrontPageOptions($post)
	{
		$boards = self::_getBoardsList();

		if (empty($post->message_index_frontpage) || !isset($boards[$post->message_index_frontpage]))
		{
			$post->front_page = null;

			return false;
		}

		return true;
	}

	/**
	 * Return the board listing for use in this class
	 *
	 * @uses getBoardList()
	 * @return string[] list of boards with key = id and value = cat + name
	 */
	protected static function _getBoardsList()
	{
		// Load the boards list.
		$boards_list = getBoardList(array('override_permissions' => true, 'not_redirection' => true), true);

		$boards = array();
		foreach ($boards_list as $board)
			$boards[$board['id_board']] = $board['cat_name'] . ' - ' . $board['board_name'];

		return $boards;
	}

	/**
	 * Dispatches forward to message index handler.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		loadBoard($GLOBALS['board']);

		// Forward to message index, it's not like we know much more :P
		$this->action_messageindex();
	}

	/**
	 * Show the list of topics in this board, along with any sub-boards.
	 *
	 * @uses template_topic_listing() sub template of the MessageIndex template
	 */
	public function action_messageindex_fp()
	{
		global $modSettings, $board;

		$board = $modSettings['message_index_frontpage'];
		$this->boards_manager->load($board);

		$this->action_messageindex();
	}

	/**
	 * Show the list of topics in this board, along with any sub-boards.
	 *
	 * @uses template_topic_listing() sub template of the MessageIndex template
	 */
	public function action_messageindex()
	{
		global $txt, $scripturl, $board, $modSettings, $context;
		global $options, $settings, $board_info, $user_info;

		// If this is a redirection board head off.
		if ($board_info['redirect'])
		{
			incrementBoard($board, 'num_posts');
			redirectexit($board_info['redirect']);
		}

		$this->_templates->load('MessageIndex');
		loadJavascriptFile('topic.js');

		$bbc = $GLOBALS['elk']['bbc'];

		$context['name'] = $board_info['name'];
		$context['sub_template'] = 'topic_listing';
		$context['description'] = $bbc->parseBoard($board_info['description']);
		$template_layers = $this->_layers;

		// How many topics do we have in total?
		$board_info['total_topics'] = allowedTo('approve_posts') ? $board_info['num_topics'] + $board_info['unapproved_topics'] : $board_info['num_topics'] + $board_info['unapproved_user_topics'];

		// View all the topics, or just a few?
		$context['topics_per_page'] = empty($modSettings['disableCustomPerPage']) && !empty($options['topics_per_page']) ? $options['topics_per_page'] : $modSettings['defaultMaxTopics'];
		$context['messages_per_page'] = empty($modSettings['disableCustomPerPage']) && !empty($options['messages_per_page']) ? $options['messages_per_page'] : $modSettings['defaultMaxMessages'];
		$maxindex = isset($this->http_req->query->all) && !empty($modSettings['enableAllMessages']) ? $board_info['total_topics'] : $context['topics_per_page'];

		// Right, let's only index normal stuff!
		$session_name = session_name();
		foreach ($this->http_req->query as $k => $v)
		{
			// Don't index a sort result etc.
			if (!in_array($k, array('board', 'start', $session_name)))
				$context['robot_no_index'] = true;
		}

		if (!empty($this->http_req->query->start) && (!is_numeric($this->http_req->query->start) || $this->http_req->query->start % $context['messages_per_page'] != 0))
		{
			$context['robot_no_index'] = true;
		}

		// If we can view unapproved messages and there are some build up a list.
		if (allowedTo('approve_posts') && ($board_info['unapproved_topics'] || $board_info['unapproved_posts']))
		{
			$untopics = $board_info['unapproved_topics'] ? '<a href="' . $scripturl . '?action=moderate;area=postmod;sa=topics;brd=' . $board . '">' . $board_info['unapproved_topics'] . '</a>' : 0;
			$unposts = $board_info['unapproved_posts'] ? '<a href="' . $scripturl . '?action=moderate;area=postmod;sa=posts;brd=' . $board . '">' . ($board_info['unapproved_posts'] - $board_info['unapproved_topics']) . '</a>' : 0;
			$context['unapproved_posts_message'] = sprintf($txt['there_are_unapproved_topics'], $untopics, $unposts, $scripturl . '?action=moderate;area=postmod;sa=' . ($board_info['unapproved_topics'] ? 'topics' : 'posts') . ';brd=' . $board);
		}

		// We only know these.
		if (isset($this->http_req->query->sort) && !in_array($this->http_req->query->sort, array('subject', 'starter', 'last_poster', 'replies', 'views', 'likes', 'first_post', 'last_post')))
			$this->http_req->query->sort = 'last_post';

		// Make sure the starting place makes sense and construct the page index.
		if (isset($this->http_req->query->sort))
			$sort_string = ';sort=' . $this->http_req->query->sort . (isset($this->http_req->query->desc) ? ';desc' : '');
		else
			$sort_string = '';
		$context['page_index'] = constructPageIndex($scripturl . '?board=' . $board . '.%1$d' . $sort_string, $this->http_req->query->start, $board_info['total_topics'], $maxindex, true);

		$context['start'] = &$this->http_req->query->start;

		// Set a canonical URL for this page.
		$context['canonical_url'] = $scripturl . '?board=' . $board . '.' . $context['start'];

		$context['links'] += array(
			'prev' => $this->http_req->query->start >= $context['topics_per_page'] ? $scripturl . '?board=' . $board . '.' . ($this->http_req->query->start - $context['topics_per_page']) : '',
			'next' => $this->http_req->query->start + $context['topics_per_page'] < $board_info['total_topics'] ? $scripturl . '?board=' . $board . '.' . ($this->http_req->query->start + $context['topics_per_page']) : '',
		);

		$context['page_info'] = array(
			'current_page' => $this->http_req->query->start / $context['topics_per_page'] + 1,
			'num_pages' => floor(($board_info['total_topics'] - 1) / $context['topics_per_page']) + 1
		);

		if (isset($this->http_req->query->all) && !empty($modSettings['enableAllMessages']) && $maxindex > $modSettings['enableAllMessages'])
		{
			$maxindex = $modSettings['enableAllMessages'];
			$this->http_req->query->start = 0;
		}

		// Build a list of the board's moderators.
		$context['moderators'] = &$board_info['moderators'];
		$context['link_moderators'] = array();
		if (!empty($board_info['moderators']))
		{
			foreach ($board_info['moderators'] as $mod)
				$context['link_moderators'][] = '<a href="' . $scripturl . '?action=profile;u=' . $mod['id'] . '" title="' . $txt['board_moderator'] . '">' . $mod['name'] . '</a>';
		}

		// Mark current and parent boards as seen.
		if (!$user_info['is_guest'])
		{
			// We can't know they read it if we allow prefetches.
			stop_prefetching();

			// Mark the board as read, and its parents.
			if ($board_info->parentBoards($this->elk['boards.manager']) !== array())
			{
				$board_list = array_keys($board_info->parentBoards($this->elk['boards.manager']));
				$board_list[] = $board;
			}
			else
				$board_list = array($board);

			// Mark boards as read. Boards alone, no need for topics.
			$board_readlog = $this->elk['boards.readlog'];
			$this->elk['boards.readlog']->markBoardsRead($board_list, false, false);

			// Clear topicseen cache
			// We've seen all these boards now!
			foreach ($board_info->parentBoards($this->elk['boards.manager']) as $k => $dummy)
			{
				if (isset($_SESSION['topicseen_cache'][$k]))
				{
					unset($_SESSION['topicseen_cache'][$k]);
				}
			}

			if (isset($_SESSION['topicseen_cache'][$board]))
			{
				unset($_SESSION['topicseen_cache'][$board]);
			}

			// From now on, they've seen it. So we reset notifications.
			$context['is_marked_notify'] = $this->elk['notifications.board_manager']->resetSentBoardNotification($user_info['id'], $board);
		}
		else
			$context['is_marked_notify'] = false;

		// 'Print' the header and board info.
		$context['page_title'] = strip_tags($board_info['name']);

		// Set the variables up for the template.
		$context['can_mark_notify'] = allowedTo('mark_notify') && !$user_info['is_guest'];
		$context['can_post_new'] = allowedTo('post_new') || ($modSettings['postmod_active'] && allowedTo('post_unapproved_topics'));
		$context['can_post_poll'] = !empty($modSettings['pollMode']) && allowedTo('poll_post') && $context['can_post_new'];
		$context['can_moderate_forum'] = allowedTo('moderate_forum');
		$context['can_approve_posts'] = allowedTo('approve_posts');

		// Prepare sub-boards for display.
		$boardIndexOptions = array(
			'include_categories' => false,
			'base_level' => $board_info['child_level'] + 1,
			'parent_id' => $board_info['id'],
			'set_latest_post' => false,
			'countChildPosts' => !empty($modSettings['countChildPosts']),
		);
		//$boardlist = new BoardsList($GLOBALS['elk']['db'], $boardIndexOptions);
		$boardlist = $this->elk['boards.list'];
		$boardlist->setOptions($boardIndexOptions);
		$context['boards'] = $boardlist->getBoards();

		// Nosey, nosey - who's viewing this board?
		if (!empty($settings['display_who_viewing']))
		{
			formatViewers($board, 'board');
		}

		// Known sort methods.
		$sort_methods = $this->elk['topics.list']->messageIndexSort();

		// They didn't pick one, default to by last post descending.
		if (!isset($this->http_req->query->sort) || !isset($sort_methods[$this->http_req->query->sort]))
		{
			$context['sort_by'] = 'last_post';
			$ascending = isset($this->http_req->query->asc);
		}
		// Otherwise sort by user selection and default to ascending.
		else
		{
			$context['sort_by'] = $this->http_req->query->sort;
			$ascending = !isset($this->http_req->query->desc);
		}

		$sort_column = $sort_methods[$context['sort_by']];

		$context['sort_direction'] = $ascending ? 'up' : 'down';
		$context['sort_title'] = $ascending ? $txt['sort_desc'] : $txt['sort_asc'];

		// Trick
		$txt['starter'] = $txt['started_by'];

		foreach ($sort_methods as $key => $val)
			$context['topics_headers'][$key] = array(
				'url' => $scripturl . '?board=' . $context['current_board'] . '.' . $context['start'] . ';sort=' . $key . ($context['sort_by'] == $key && $context['sort_direction'] === 'up' ? ';desc' : ''),
				'sort_dir_img' => $context['sort_by'] == $key ? '<img class="sort" src="' . $settings['images_url'] . '/sort_' . $context['sort_direction'] . '.png" alt="" title="' . $context['sort_title'] . '" />' : '',
			);

		// Calculate the fastest way to get the topics.
		$start = (int) $this->http_req->query->start;
		if ($start > ($board_info['total_topics'] - 1) / 2)
		{
			$ascending = !$ascending;
			$fake_ascending = true;
			$maxindex = $board_info['total_topics'] < $start + $maxindex + 1 ? $board_info['total_topics'] - $start : $maxindex;
			$start = $board_info['total_topics'] < $start + $maxindex + 1 ? 0 : $board_info['total_topics'] - $start - $maxindex;
		}
		else
			$fake_ascending = false;

		$context['topics'] = array();

		// Set up the query options
		$indexOptions = array(
			'only_approved' => $modSettings['postmod_active'] && !allowedTo('approve_posts'),
			'previews' => !empty($modSettings['message_index_preview']) ? (empty($modSettings['preview_characters']) ? -1 : $modSettings['preview_characters']) : 0,
			'include_avatars' => $settings['avatars_on_indexes'],
			'ascending' => $ascending,
			'fake_ascending' => $fake_ascending
		);

		// Allow integration to modify / add to the $indexOptions
		$this->hooks->hook('messageindex_topics', array(&$sort_column, &$indexOptions));

		$topics_info = $this->elk['topics.list']->messageIndexTopics($board, $user_info['id'], $start, $maxindex, $context['sort_by'], $sort_column, $indexOptions);

		$topic_util = new TopicUtil;
		$context['topics'] = $topic_util->prepareContext($topics_info, false, !empty($modSettings['preview_characters']) ? $modSettings['preview_characters'] : 128);

		// Allow addons to add to the $context['topics']
		$this->hooks->hook('messageindex_listing', array($topics_info));

		// Fix the sequence of topics if they were retrieved in the wrong order. (for speed reasons...)
		if ($fake_ascending)
			$context['topics'] = array_reverse($context['topics'], true);

		$topic_ids = array_keys($context['topics']);

		if (!empty($modSettings['enableParticipation']) && !$user_info['is_guest'] && !empty($topic_ids))
		{
			$topics_participated_in = $this->elk['topics.manager']->topicsParticipation($user_info['id'], $topic_ids);
			foreach ($topics_participated_in as $participated)
			{
				$context['topics'][$participated['id_topic']]['is_posted_in'] = true;
				$context['topics'][$participated['id_topic']]['class'] = 'my_' . $context['topics'][$participated['id_topic']]['class'];
			}
		}

		$context['jump_to'] = array(
			'label' => addslashes($GLOBALS['elk']['text']->un_htmlspecialchars($txt['jump_to'])),
			'board_name' => htmlspecialchars(strtr(strip_tags($board_info['name']), array('&amp;' => '&')), ENT_COMPAT, 'UTF-8'),
			'child_level' => $board_info['child_level'],
		);

		// Is Quick Moderation active/needed?
		if (!empty($options['display_quick_mod']) && !empty($context['topics']))
		{
			$context['can_markread'] = $context['user']['is_logged'];
			$context['can_lock'] = allowedTo('lock_any');
			$context['can_sticky'] = allowedTo('make_sticky');
			$context['can_move'] = allowedTo('move_any');
			$context['can_remove'] = allowedTo('remove_any');
			$context['can_merge'] = allowedTo('merge_any');

			// Ignore approving own topics as it's unlikely to come up...
			$context['can_approve'] = $modSettings['postmod_active'] && allowedTo('approve_posts') && !empty($board_info['unapproved_topics']);

			// Can we restore topics?
			$context['can_restore'] = allowedTo('move_any') && !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] == $board;

			// Set permissions for all the topics.
			foreach ($context['topics'] as $t => $topic)
			{
				$started = $topic['first_post']['member']['id'] == $user_info['id'];
				$context['topics'][$t]['quick_mod'] = array(
					'lock' => allowedTo('lock_any') || ($started && allowedTo('lock_own')),
					'sticky' => allowedTo('make_sticky'),
					'move' => allowedTo('move_any') || ($started && allowedTo('move_own')),
					'modify' => allowedTo('modify_any') || ($started && allowedTo('modify_own')),
					'remove' => allowedTo('remove_any') || ($started && allowedTo('remove_own')),
					'approve' => $context['can_approve'] && $topic['unapproved_posts']
				);
				$context['can_lock'] |= ($started && allowedTo('lock_own'));
				$context['can_move'] |= ($started && allowedTo('move_own'));
				$context['can_remove'] |= ($started && allowedTo('remove_own'));
			}

			// Can we use quick moderation checkboxes?
			if ($options['display_quick_mod'] == 1)
				$context['can_quick_mod'] = $context['user']['is_logged'] || $context['can_approve'] || $context['can_remove'] || $context['can_lock'] || $context['can_sticky'] || $context['can_move'] || $context['can_merge'] || $context['can_restore'];
			// Or the icons?
			else
				$context['can_quick_mod'] = $context['can_remove'] || $context['can_lock'] || $context['can_sticky'] || $context['can_move'];
		}

		if (!empty($context['can_quick_mod']) && $options['display_quick_mod'] == 1)
		{
			$context['qmod_actions'] = array('approve', 'remove', 'lock', 'sticky', 'move', 'merge', 'restore', 'markread');
			$this->hooks->hook('quick_mod_actions');
		}

		if (!empty($context['boards']) && $context['start'] == 0)
			$template_layers->add('display_child_boards');

		// If there are children, but no topics and no ability to post topics...
		$context['no_topic_listing'] = !empty($context['boards']) && empty($context['topics']) && !$context['can_post_new'];
		$template_layers->add('topic_listing');

		theme()->addJavascriptVar(array('notification_board_notice' => $context['is_marked_notify'] ? $txt['notification_disable_board'] : $txt['notification_enable_board']), true);

		// Build the message index button array.
		$context['normal_buttons'] = array(
			'new_topic' => array('test' => 'can_post_new', 'text' => 'new_topic', 'image' => 'new_topic.png', 'lang' => true, 'url' => $scripturl . '?action=post;board=' . $context['current_board'] . '.0', 'active' => true),
			'notify' => array('test' => 'can_mark_notify', 'text' => $context['is_marked_notify'] ? 'unnotify' : 'notify', 'image' => ($context['is_marked_notify'] ? 'un' : '') . 'notify.png', 'lang' => true, 'custom' => 'onclick="return notifyboardButton(this);"', 'url' => $scripturl . '?action=notifyboard;sa=' . ($context['is_marked_notify'] ? 'off' : 'on') . ';board=' . $context['current_board'] . '.' . $context['start'] . ';' . $context['session_var'] . '=' . $context['session_id']),
		);

		// They can only mark read if they are logged in and it's enabled!
		if (!$user_info['is_guest'] && $settings['show_mark_read'])
			$context['normal_buttons']['markread'] = array('text' => 'mark_read_short', 'image' => 'markread.png', 'lang' => true, 'url' => $scripturl . '?action=markasread;sa=board;board=' . $context['current_board'] . '.0;' . $context['session_var'] . '=' . $context['session_id'], 'custom' => 'onclick="return markboardreadButton(this);"');

		// Allow adding new buttons easily.
		$this->hooks->hook('messageindex_buttons');
	}

	/**
	 * Allows for moderation from the message index.
	 *
	 * @todo refactor this...
	 */
	public function action_quickmod()
	{
		global $board, $user_info, $modSettings, $context;

		// Check the session = get or post.
		$this->session->check('request');

		// Lets go straight to the restore area.
		if ($this->http_req->getPost('qaction') === 'restore' && !empty($this->http_req->post->topics))
			redirectexit('action=restoretopic;topics=' . implode(',', $this->http_req->post->topics) . ';' . $context['session_var'] . '=' . $context['session_id']);

		if (isset($_SESSION['topicseen_cache']))
			$_SESSION['topicseen_cache'] = array();

		// This is going to be needed to send off the notifications and for updateLastMessages().

		require_once(ROOTDIR . '/Notifications/Notification.subs.php');

		// Process process process data.


		// Remember the last board they moved things to.
		if (isset($this->http_req->post->move_to))
		{
			$_SESSION['move_to_topic'] = array(
				'move_to' => $this->http_req->post->move_to,
				// And remember the last expiry period too.
				'redirect_topic' => $this->http_req->getPost('redirect_topic', 'intval', 0),
				'redirect_expires' => $this->http_req->getPost('redirect_expires', 'intval', 0),
			);
		}

		// Only a few possible actions.
		$possibleActions = array();
		$actions = array();

		if (!empty($board))
		{
			$boards_can = array(
				'make_sticky' => allowedTo('make_sticky') ? array($board) : array(),
				'move_any' => allowedTo('move_any') ? array($board) : array(),
				'move_own' => allowedTo('move_own') ? array($board) : array(),
				'remove_any' => allowedTo('remove_any') ? array($board) : array(),
				'remove_own' => allowedTo('remove_own') ? array($board) : array(),
				'lock_any' => allowedTo('lock_any') ? array($board) : array(),
				'lock_own' => allowedTo('lock_own') ? array($board) : array(),
				'merge_any' => allowedTo('merge_any') ? array($board) : array(),
				'approve_posts' => allowedTo('approve_posts') ? array($board) : array(),
			);

			$redirect_url = 'board=' . $board . '.' . $this->http_req->query->start;
		}
		else
		{
			$boards_can = boardsAllowedTo(array('make_sticky', 'move_any', 'move_own', 'remove_any', 'remove_own', 'lock_any', 'lock_own', 'merge_any', 'approve_posts'), true, false);
			$redirect_url = isset($this->http_req->post->redirect_url) ? $this->http_req->post->redirect_url : (isset($_SESSION['old_url']) ? $_SESSION['old_url'] : '');
		}

		if (!$user_info['is_guest'])
			$possibleActions[] = 'markread';
		if (!empty($boards_can['make_sticky']))
			$possibleActions[] = 'sticky';
		if (!empty($boards_can['move_any']) || !empty($boards_can['move_own']))
			$possibleActions[] = 'move';
		if (!empty($boards_can['remove_any']) || !empty($boards_can['remove_own']))
			$possibleActions[] = 'remove';
		if (!empty($boards_can['lock_any']) || !empty($boards_can['lock_own']))
			$possibleActions[] = 'lock';
		if (!empty($boards_can['merge_any']))
			$possibleActions[] = 'merge';

		// Two methods: $_REQUEST['actions'] (id_topic => action), and $_REQUEST['topics'] and $this->http_req->post->qaction.
		// (if action is 'move', $_REQUEST['move_to'] or $_REQUEST['move_tos'][$topic] is used.)
		if (!empty($this->http_req->post->topics))
		{
			// If the action isn't valid, just quit now.
			if (empty($this->http_req->post->qaction) || !in_array($this->http_req->post->qaction, $possibleActions))
				redirectexit($redirect_url);

			// Merge requires all topics as one parameter and can be done at once.
			if ($this->http_req->post->qaction === 'merge')
			{
				// Merge requires at least two topics.
				if (empty($this->http_req->post->topics) || count($this->http_req->post->topics) < 2)
					redirectexit($redirect_url);

				$controller = new MergeTopicsController(new EventManager());
				$controller->pre_dispatch();
				return $controller->action_mergeExecute($this->http_req->post->topics);
			}

			// Just convert to the other method, to make it easier.
			foreach ($this->http_req->post->topics as $topic)
				$actions[(int) $topic] = $this->http_req->post->qaction;
		}

		// Weird... how'd you get here?
		if (empty($actions))
			redirectexit($redirect_url);

		// Validate each action.
		$all_actions = array();
		foreach ($actions as $topic => $action)
		{
			if (in_array($action, $possibleActions))
				$all_actions[(int) $topic] = $action;
		}

		$stickyCache = array();
		$moveCache = array(0 => array(), 1 => array());
		$removeCache = array();
		$lockCache = array();
		$markCache = array();

		if (!empty($all_actions))
		{
			// Find all topics...
			$topics_info = topicsDetails(array_keys($all_actions));

			foreach ($topics_info as $row)
			{
				if (!empty($board))
				{
					if ($row['id_board'] != $board || ($modSettings['postmod_active'] && !$row['approved'] && !allowedTo('approve_posts')))
						continue;
				}
				else
				{
					// Don't allow them to act on unapproved posts they can't see...
					if ($modSettings['postmod_active'] && !$row['approved'] && !in_array(0, $boards_can['approve_posts']) && !in_array($row['id_board'], $boards_can['approve_posts']))
						continue;
					// Goodness, this is fun.  We need to validate the action.
					elseif ($all_actions[$row['id_topic']] === 'sticky' && !in_array(0, $boards_can['make_sticky']) && !in_array($row['id_board'], $boards_can['make_sticky']))
						continue;
					elseif ($all_actions[$row['id_topic']] === 'move' && !in_array(0, $boards_can['move_any']) && !in_array($row['id_board'], $boards_can['move_any']) && ($row['id_member_started'] != $user_info['id'] || (!in_array(0, $boards_can['move_own']) && !in_array($row['id_board'], $boards_can['move_own']))))
						continue;
					elseif ($all_actions[$row['id_topic']] === 'remove' && !in_array(0, $boards_can['remove_any']) && !in_array($row['id_board'], $boards_can['remove_any']) && ($row['id_member_started'] != $user_info['id'] || (!in_array(0, $boards_can['remove_own']) && !in_array($row['id_board'], $boards_can['remove_own']))))
						continue;
					elseif ($all_actions[$row['id_topic']] === 'lock' && !in_array(0, $boards_can['lock_any']) && !in_array($row['id_board'], $boards_can['lock_any']) && ($row['id_member_started'] != $user_info['id'] || $row['locked'] == 1 || (!in_array(0, $boards_can['lock_own']) && !in_array($row['id_board'], $boards_can['lock_own']))))
						continue;
				}

				// Separate the actions.
				switch ($action)
				{
					case 'markread':
						$markCache[] = $row['id_topic'];
						break;
					case 'sticky':
						$stickyCache[] = $row['id_topic'];
						break;
					case 'move':
						if (isset($this->http_req->query->current_board))
							moveTopicConcurrence((int) $this->http_req->query->current_board);

						// $moveCache[0] is the topic, $moveCache[1] is the board to move to.
						$moveCache[1][$row['id_topic']] = (int) (isset($this->http_req->post->move_tos[$row['id_topic']]) ? $this->http_req->post->move_tos[$row['id_topic']] : $this->http_req->post->move_to);

						if (empty($moveCache[1][$row['id_topic']]))
							continue;

						$moveCache[0][] = $row['id_topic'];
						break;
					case 'remove':
						$removeCache[] = $row['id_topic'];
						break;
					case 'lock':
						$lockCache[] = $row['id_topic'];
						break;
				}
			}
		}

		if (empty($board))
			$affectedBoards = array();
		else
			$affectedBoards = array($board => array(0, 0));

		// Do all the stickies...
		if (!empty($stickyCache))
			toggleTopicSticky($stickyCache, true);

		// Move sucka! (this is, by the by, probably the most complicated part....)
		if (!empty($moveCache[0]))
			moveTopicsPermissions($moveCache);

		// Now delete the topics...
		if (!empty($removeCache))
			removeTopicsPermissions($removeCache);

		// And (almost) lastly, lock the topics...
		if (!empty($lockCache))
			toggleTopicsLock($lockCache, true);

		if (!empty($markCache))
		{
			$logged_topics = getLoggedTopics($user_info['id'], $markCache);

			$markArray = array();
			foreach ($markCache as $topic)
				$markArray[] = array($user_info['id'], $topic, $modSettings['maxMsgID'], (int) !empty($logged_topics[$topic]));

			markTopicsRead($markArray, true);
		}

		updateTopicStats();

		updateMessageStats();
		updateSettings(array(
			'calendar_updated' => time(),
		));

		if (!empty($affectedBoards))
			updateLastMessages(array_keys($affectedBoards));

		redirectexit($redirect_url);
	}
}