<?php

/**
 * Handle merging of topics
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
 * Original module by Mach8 - We'll never forget you.
 * ETA: Sorry, we did.
 */

namespace Elkarte\Topics;
use Elkarte\Elkarte\Controller\AbstractController;
use Elkarte\Elkarte\Controller\Action;


/**
 * MergeTopicsController class.  Merges two or more topics into a single topic.
 */
class MergeTopicsController extends AbstractController
{
	/**
	 * Merges two or more topics into one topic.
	 *
	 * What it does:
	 * - delegates to the other functions (based on the URL parameter sa).
	 * - loads the MergeTopics template.
	 * - requires the merge_any permission.
	 * - is accessed with ?action=mergetopics.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		global $context;

		// Load the template....
		$this->_templates->load('MergeTopics');

		$subActions = array(
			'done' => array($this, 'action_mergeDone'),
			'execute' => array($this, 'action_mergeExecute'),
			'index' => array($this, 'action_mergeIndex'),
			'options' => array($this, 'action_mergeExecute')
		);

		// ?action=mergetopics;sa=LETSBREAKIT won't work, sorry.
		$action = new Action();
		$subAction = $action->initialize($subActions, 'index');
		$context['sub_action'] = $subAction;
		$action->dispatch($subAction);
	}

	/**
	 * Allows to pick a topic to merge the current topic with.
	 *
	 * What it does:
	 * - is accessed with ?action=mergetopics;sa=index
	 * - default sub action for ?action=mergetopics.
	 * - uses 'merge' sub template of the MergeTopics template.
	 * - allows to set a different target board.
	 */
	public function action_mergeIndex()
	{
		global $txt, $board, $context, $scripturl, $user_info, $modSettings;

		// If we don't know where you are from we know where you go
		$from = $this->http_req->getQuery('from', 'intval', null);
		if (!isset($from))
			$this->_errors->fatal_lang_error('no_access', false);

		$target_board = $this->http_req->getPost('targetboard', 'intval', $board);
		$context['target_board'] = $target_board;

		// Prepare a handy query bit for approval...
		if ($modSettings['postmod_active'])
		{
			$can_approve_boards = !empty($user_info['mod_cache']['ap']) ? $user_info['mod_cache']['ap'] : boardsAllowedTo('approve_posts');
			$onlyApproved = $can_approve_boards !== array(0) && !in_array($target_board, $can_approve_boards);
		}
		else
			$onlyApproved = false;

		// How many topics are on this board?  (used for paging.)

		$topiccount = countTopicsByBoard($target_board, $onlyApproved);

		// Make the page list.
		$context['page_index'] = constructPageIndex($scripturl . '?action=mergetopics;from=' . $from . ';targetboard=' . $target_board . ';board=' . $board . '.%1$d', $this->http_req->query->start, $topiccount, $modSettings['defaultMaxTopics'], true);

		// Get the topic's subject.
		$topic_info = getTopicInfo($from, 'message');

		// @todo review: double check the logic
		if (empty($topic_info) || ($topic_info['id_board'] != $board) || ($onlyApproved && empty($topic_info['approved'])))
			$this->_errors->fatal_lang_error('no_board');

		// Tell the template a few things..
		$context['origin_topic'] = $from;
		$context['origin_subject'] = $topic_info['subject'];
		$context['origin_js_subject'] = addcslashes(addslashes($topic_info['subject']), '/');
		$context['page_title'] = $txt['merge'];

		// Check which boards you have merge permissions on.
		$merge_boards = boardsAllowedTo('merge_any');

		if (empty($merge_boards))
			$this->_errors->fatal_lang_error('cannot_merge_any', 'user');

		// Get a list of boards they can navigate to to merge.

		$boardListOptions = array(
			'not_redirection' => true
		);

		if (!in_array(0, $merge_boards))
			$boardListOptions['included_boards'] = $merge_boards;
		$boards_list = getBoardList($boardListOptions, true);
		$context['boards'] = array();

		foreach ($boards_list as $board)
		{
			$context['boards'][] = array(
				'id' => $board['id_board'],
				'name' => $board['board_name'],
				'category' => $board['cat_name']
			);
		}

		// Get some topics to merge it with.
		$context['topics'] = mergeableTopics($target_board, $from, $onlyApproved, $this->http_req->query->start);

		if (empty($context['topics']) && count($context['boards']) <= 1)
			$this->_errors->fatal_lang_error('merge_need_more_topics');

		$context['sub_template'] = 'merge';
	}

	/**
	 * Set merge options and do the actual merge of two or more topics.
	 *
	 * The merge options screen:
	 * - shows topics to be merged and allows to set some merge options.
	 * - is accessed by ?action=mergetopics;sa=options and can also internally be called by action_quickmod().
	 * - uses 'merge_extra_options' sub template of the MergeTopics template.
	 *
	 * The actual merge:
	 * - is accessed with ?action=mergetopics;sa=execute.
	 * - updates the statistics to reflect the merge.
	 * - logs the action in the moderation log.
	 * - sends a notification is sent to all users monitoring this topic.
	 * - redirects to ?action=mergetopics;sa=done.
	 *
	 * @param int[] $topics = array() of topic ids
	 */
	public function action_mergeExecute($topics = array())
	{
		global $txt, $context;

		// Check the session.
		$this->session->check('request');




		// Handle URLs from action_mergeIndex.
		if (!empty($this->http_req->query->from) && !empty($this->http_req->query->to))
			$topics = array((int) $this->http_req->query->from, (int) $this->http_req->query->to);

		// If we came from a form, the topic IDs came by post.
		if (!empty($this->http_req->post->topics) && is_array($this->http_req->post->topics))
			$topics = $this->http_req->post->topics;

		// There's nothing to merge with just one topic...
		if (empty($topics) || !is_array($topics) || count($topics) == 1)
			$this->_errors->fatal_lang_error('merge_need_more_topics');

		// Send the topics to the TopicsMerge class
		$merger = new TopicsMerge($topics);

		// If we didn't get any topics then they've been messing with unapproved stuff.
		if ($merger->hasErrors())
		{
			$this->_errors->fatal_lang_error($merger->firstError());
		}

		// The parameters of action_mergeExecute were set, so this must've been an internal call.
		if (!empty($topics))
		{
			isAllowedTo('merge_any', $merger->boards);
			$this->_templates->load('MergeTopics');
		}

		// Get the boards a user is allowed to merge in.
		$allowedto_merge_boards = boardsAllowedTo('merge_any');

		// No permissions to merge, your effort ends here
		if (empty($allowedto_merge_boards))
			$this->_errors->fatal_lang_error('cannot_merge_any', 'user');

		// Make sure they can see all boards....
		$query_boards = array('boards' => $merger->boards);

		if (!in_array(0, $allowedto_merge_boards))
			$query_boards['boards'] = array_merge($query_boards['boards'], $allowedto_merge_boards);

		// Saved in a variable to (potentially) save a query later

		$boards_info = fetchBoardsInfo($query_boards);

		$boardListOptions = array(
			'not_redirection' => true,
			'selected_board' => $merger->firstBoard,
		);

		if (!in_array(0, $allowedto_merge_boards))
			$boardListOptions['included_boards'] = $allowedto_merge_boards;

		$context += getBoardList($boardListOptions);

		// This is removed to avoid the board not being selectable.
		$context['current_board'] = null;

		// This happens when a member is moderator of a board he cannot see
		foreach ($merger->boards as $board)
		{
			if (!isset($boards_info[$board]))
			{
				$this->_errors->fatal_lang_error('no_board');
			}
		}

		if (empty($this->http_req->query->sa) || $this->http_req->query->sa === 'options')
		{
			$context['polls'] = $merger->getPolls();
			$context['topics'] = $merger->topic_data;
			$context['target_board'] = $this->http_req->getQuery('board', 'intval', 0);

			foreach ($merger->topic_data as $id => $topic)
				$context['topics'][$id]['selected'] = $topic['id'] == $merger->firstTopic;

			$context['page_title'] = $txt['merge'];
			$context['sub_template'] = 'merge_extra_options';

			return true;
		}

		$result = $merger->doMerge(array(
			'board' => $merger->boards[0],
			'poll' => $this->http_req->getPost('poll', 'intval', 0),
			'subject' => $this->http_req->getPost('subject', 'trim', ''),
			'custom_subject' => $this->http_req->getPost('custom_subject', 'trim', ''),
			'enforce_subject' => $this->http_req->getPost('enforce_subject', 'trim', ''),
			'notifications' => $this->http_req->getPost('notifications', 'trim', ''),
			'accessible_boards' => array_keys($boards_info),
		));

		if ($merger->hasErrors())
		{
			$error = $merger->firstError();
			$this->_errors->fatal_lang_error($error[0], $error[1]);
		}

		// Send them to the all done page.
		redirectexit('action=mergetopics;sa=done;to=' . $result[0] . ';targetboard=' . $result[1]);

		return true;
	}

	/**
	 * Shows a 'merge completed' screen.
	 *
	 * - is accessed with ?action=mergetopics;sa=done.
	 * - uses 'merge_done' sub template of the MergeTopics template.
	 */
	public function action_mergeDone()
	{
		global $txt, $context;

		// Make sure the template knows everything...
		$context['target_board'] = (int) $this->http_req->query->targetboard;
		$context['target_topic'] = (int) $this->http_req->query->to;

		$context['page_title'] = $txt['merge'];
		$context['sub_template'] = 'merge_done';
	}
}