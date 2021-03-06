<?php

/**
 * Handles all mark as read options, boards, topics, replies
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * This class handles a part of the actions to mark boards, topics, or replies, as read/unread.
 */
class MarkReadController extends AbstractController
{
	/**
	 * String used to redirect user to the correct boards when marking unread
	 * ajax-ively
	 * @var string
	 */
	private $_querystring_board_limits = '';

	/**
	 * String used to remember user's sorting options when marking unread
	 * ajax-ively
	 * @var string
	 */
	private $_querystring_sort_limits = '';

	/**
	 * This is the main function for markasread file if not using API
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		// These checks have been moved here.
		// Do NOT call the specific handlers directly.

		// Guests can't mark things.
		is_not_guest();

		$this->session->check('get');

		$redir = $this->_dispatch();

		redirectexit($redir);
	}

	/**
	 * This function forwards the request to the appropriate function.
	 *
	 * @return string
	 */
	protected function _dispatch()
	{
		$subAction = $this->http_req->getQuery('sa', 'trim', 'action_markasread');

		switch ($subAction)
		{
			// sa=all action_markboards()
			case 'all':
				$subAction = 'action_markboards';
				break;
			case 'unreadreplies':
				// mark topics from unread
				$subAction = 'action_markreplies';
				break;
			case 'topic':
				// mark a single topic as read
				$subAction = 'action_marktopic';
				break;
			default:
				// the rest, for now...
				$subAction = 'action_markasread';
				break;
		}

		return $this->{$subAction}();
	}

	/**
	 * This is the main method for markasread controller when using APIs.
	 *
	 * @uses Xml template generic_xml_buttons sub template
	 */
	public function action_index_api()
	{
		global $context, $txt, $user_info, $scripturl;

		$this->_templates->load('Xml');

		$this->_layers->removeAll();
		$context['sub_template'] = 'generic_xml_buttons';

		// Guests can't mark things.
		if ($user_info['is_guest'])
		{
			loadLanguage('Errors');
			$context['xml_data'] = array(
				'error' => 1,
				'text' => $txt['not_guests']
			);

			return;
		}

		if ($this->session->check('get', '', false))
		{
			// Again, this is a special case, someone will deal with the others later :P
			if ($this->http_req->getQuery('sa') === 'all')
			{
				loadLanguage('Errors');
				$context['xml_data'] = array(
					'error' => 1,
					'url' => $scripturl . '?action=markasread;sa=all;' . $context['session_var'] . '=' . $context['session_id'],
				);

				return;
			}
			else
				obExit(false);
		}

		$this->_dispatch();

		// For the time being this is a special case, but in BoardIndex no, we don't want it
		if ($this->http_req->getQuery('sa') === 'all' || $this->http_req->getQuery('sa') === 'board' && !isset($this->http_req->query->bi))
		{
			$context['xml_data'] = array(
				'text' => $txt['topic_alert_none'],
				'body' => str_replace('{unread_all_url}', $scripturl . '?action=unread;all' . sprintf($this->_querystring_board_limits, 0) . $this->_querystring_sort_limits, $txt['unread_topics_visit_none']),
			);
		}
		// No need to do anything, just die :'(
		else
			obExit(false);
	}

	/**
	 * Marks boards as read (or unread)
	 *
	 * - Accessed by action=markasread;sa=all
	 */
	public function action_markboards()
	{
		global $modSettings;



		// Find all the boards this user can see.
		$boards = accessibleBoards();

		// Mark boards as read
		if (!empty($boards))
			markBoardsRead($boards, isset($this->http_req->query->unread), true);

		$_SESSION['id_msg_last_visit'] = $modSettings['maxMsgID'];
		if (!empty($_SESSION['old_url']) && strpos($_SESSION['old_url'], 'action=unread') !== false)
			return 'action=unread';

		if (isset($_SESSION['topicseen_cache']))
			$_SESSION['topicseen_cache'] = array();

		return '';
	}

	/**
	 * Marks the selected topics as read.
	 * Accessed by action=markasread;sa=unreadreplies
	 */
	public function action_markreplies()
	{
		global $user_info, $modSettings;

		// Make sure all the topics are integers!
		$topics = array_map('intval', explode('-', $this->http_req->query->topics));


		$logged_topics = getLoggedTopics($user_info['id'], $topics);

		$markRead = array();
		foreach ($topics as $id_topic)
			$markRead[] = array($user_info['id'], (int) $id_topic, $modSettings['maxMsgID'], (int) !empty($logged_topics[$id_topic]));

		markTopicsRead($markRead, true);

		if (isset($_SESSION['topicseen_cache']))
			$_SESSION['topicseen_cache'] = array();

		return 'action=unreadreplies';
	}

	/**
	 * Mark a single topic as unread.
	 * Accessed by action=markasread;sa=topic
	 */
	public function action_marktopic()
	{
		global $board, $topic, $user_info;




		// Mark a topic unread.
		// First, let's figure out what the latest message is.
		$topicinfo = getTopicInfo($topic, 'all');
		$topic_msg_id = $this->http_req->getQuery('t', 'intval');

		if (!empty($topic_msg_id))
		{
			// If they read the whole topic, go back to the beginning.
			if ($topic_msg_id >= $topicinfo['id_last_msg'])
				$earlyMsg = 0;
			// If they want to mark the whole thing read, same.
			elseif ($topic_msg_id <= $topicinfo['id_first_msg'])
				$earlyMsg = 0;
			// Otherwise, get the latest message before the named one.
			else
				$earlyMsg = previousMessage($topic_msg_id, $topic);
		}
		// Marking read from first page?  That's the whole topic.
		elseif ($this->http_req->query->start == 0)
			$earlyMsg = 0;
		else
		{
			list ($earlyMsg) = messageAt((int) $this->http_req->query->start, $topic);
			$earlyMsg--;
		}

		// Blam, unread!
		markTopicsRead(array($user_info['id'], $topic, $earlyMsg, $topicinfo['unwatched']), true);

		return 'board=' . $board . '.0';
	}

	/**
	 * Mark as read: boards, topics, unread replies.
	 * Accessed by action=markasread
	 * Subactions: sa=topic, sa=all, sa=unreadreplies
	 */
	public function action_markasread()
	{
		global $board, $board_info;

		//$this->session->check('get');



		$categories = array();
		$boards = array();

		if (isset($this->http_req->query->c))
		{
			$categories = array_map('intval', explode(',', $this->http_req->query->c));
		}

		if (isset($this->http_req->query->boards))
		{
			$boards = array_map('intval', explode(',', $this->http_req->query->boards));
		}

		if (!empty($board))
			$boards[] = (int) $board;

		if (isset($this->http_req->query->children) && !empty($boards))
		{
			// Mark all children of the boards we got (selected by the user).
			$boards = addChildBoards($boards);
		}

		$boards = array_keys(boardsPosts($boards, $categories));

		if (empty($boards))
			return '';

		// Mark boards as read.
		markBoardsRead($boards, isset($this->http_req->query->unread), true);

		foreach ($boards as $b)
		{
			if (isset($_SESSION['topicseen_cache'][$b]))
				$_SESSION['topicseen_cache'][$b] = array();
		}

		$this->_querystring_board_limits = $this->http_req->getQuery('sa') === 'board' ? ';boards=' . implode(',', $boards) . ';start=%d' : '';

		$sort_methods = array(
			'subject',
			'starter',
			'replies',
			'views',
			'first_post',
			'last_post'
		);

		// The default is the most logical: newest first.
		if (!isset($this->http_req->query->sort) || !in_array($this->http_req->query->sort, $sort_methods))
			$this->_querystring_sort_limits = isset($this->http_req->query->asc) ? ';asc' : '';
		// But, for other methods the default sort is ascending.
		else
			$this->_querystring_sort_limits = ';sort=' . $this->http_req->query->sort . (isset($this->http_req->query->desc) ? ';desc' : '');

		if (!isset($this->http_req->query->unread))
		{
			// Find all boards with the parents in the board list
			$boards_to_add = accessibleBoards(null, $boards);
			if (!empty($boards_to_add))
				markBoardsRead($boards_to_add);

			if (empty($board))
				return '';
			else
				return 'board=' . $board . '.0';
		}
		else
		{
			if (empty($board_info['parent']))
				return '';
			else
				return 'board=' . $board_info['parent'] . '.0';
		}
	}
}