<?php

/**
 * The main purpose of this file is to show a list of all badbehavior entries
 * and allow filtering and deleting them.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Admin;

use Elkarte\Elkarte\Controller\AbstractController;

/**
 * Class to show a list of all badbehavior log entries
 *
 * @package BadBehavior
 */
class BadBehaviorController extends AbstractController
{
	/**
	 * Call the appropriate action method.
	 */
	public function action_index()
	{
		// all we know how to do is...
		$this->action_log();
	}

	/**
	 * View the forum's badbehavior log.
	 *
	 * What it does:
	 * - This function sets all the context up to show the badbehavior log for review.
	 * - It requires the maintain_forum permission.
	 * - It is accessed from ?action=Admin;area=logs;sa=badbehaviorlog.
	 *
	 * @uses the BadBehavior template and badbehavior_log sub template.
	 */
	public function action_log()
	{
		global $scripturl, $txt, $context, $modSettings;

		// Check for the administrative permission to do this.
		isAllowedTo('admin_forum');

		// Templates, etc...
		loadLanguage('BadBehaviorlog');
		$this->_templates->load('BadBehavior');

		// Functions we will need
		require_once(SUBSDIR . '/BadBehavior.subs.php');

		// Set up the filtering...
		$filter = array();
		if (isset($this->http_req->query->value, $this->http_req->query->filter))
			$filter = $this->_setFilter();

		if ($filter === false)
		{
			// Bad filter or something else going on, back to the start you go
			redirectexit('action=Admin;area=logs;sa=badbehaviorlog' . (isset($this->http_req->query->desc) ? ';desc' : ''));
		}

		// Deleting or just doing a little weeding?
		if (isset($this->http_req->post->delall) || isset($this->http_req->post->delete))
			$this->_action_delete($filter);

		// Just how many entries are there?
		$num_errors = getBadBehaviorLogEntryCount($filter);

		// If this filter turns up empty, just return
		if (empty($num_errors) && !empty($filter))
			redirectexit('action=Admin;area=logs;sa=badbehaviorlog' . (isset($this->http_req->query->desc) ? ';desc' : ''));

		// Clean up start.
		$start = $this->http_req->getQuery('start', 'intval', 0);
		$start = $start < 0 ? 0 : $start;

		// Do we want to reverse the listing?
		$sort = isset($this->http_req->query->desc) ? 'down' : 'up';

		// Set the page listing up.
		$context['page_index'] = constructPageIndex($scripturl . '?action=Admin;area=logs;sa=badbehaviorlog' . ($sort == 'down' ? ';desc' : '') . (!empty($filter) ? $filter['href'] : ''), $start, $num_errors, $modSettings['defaultMaxMessages']);

		// Find and sort out the log entries.
		$context['bb_entries'] = getBadBehaviorLogEntries($start, $modSettings['defaultMaxMessages'], $sort, $filter);

		// Load member data if needed
		$this->_prepareMembers();

		// Filtering?
		if (!empty($filter))
			$this->_prepareFilters($filter);

		// And the standard template goodies
		$context['page_title'] = $txt['badbehaviorlog_log'];
		$context['has_filter'] = !empty($filter);
		$context['sub_template'] = 'badbehavior_log';
		$context['sort_direction'] = $sort;
		$context['start'] = $start;

		createToken('Admin-bbl');
	}

	/**
	 * Loads basic member data for any members that are in the log
	 */
	protected function _prepareMembers()
	{
		global $context, $txt, $scripturl;

		$members = array();
		foreach ($context['bb_entries'] as $member)
			$members[] = $member['member']['id'];

		// Load any member data so we have more information available
		if (!empty($members))
		{
				$members = getBasicMemberData($members, array('add_guest' => true));

			// Go through each entry and add the member data.
			foreach ($context['bb_entries'] as $id => $dummy)
			{
				$memID = $context['bb_entries'][$id]['member']['id'];
				$context['bb_entries'][$id]['member']['username'] = $members[$memID]['member_name'];
				$context['bb_entries'][$id]['member']['name'] = $members[$memID]['real_name'];
				$context['bb_entries'][$id]['member']['href'] = empty($memID) ? '' : $scripturl . '?action=profile;u=' . $memID;
				$context['bb_entries'][$id]['member']['link'] = empty($memID) ? $txt['guest_title'] : '<a href="' . $scripturl . '?action=profile;u=' . $memID . '">' . $context['bb_entries'][$id]['member']['name'] . '</a>';
			}
		}
	}

	/**
	 * Prepares the filter index of the $context variable
	 *
	 * @param string[] $filter - an array describing the current filter
	 */
	protected function _prepareFilters($filter)
	{
		global $context, $scripturl, $user_profile;

		$context['filter'] = $filter;

		// Set the filtering context.
		switch ($filter['variable'])
		{
			case 'id_member':
				$id = $filter['value']['sql'];
				loadMemberData($id, false, 'minimal');
				$context['filter']['value']['html'] = '<a href="' . $scripturl . '?action=profile;u=' . $id . '">' . $user_profile[$id]['real_name'] . '</a>';
				break;
			case 'url':
				$context['filter']['value']['html'] = '\'' . strtr(htmlspecialchars((substr($filter['value']['sql'], 0, 1) === '?' ? $scripturl : '') . $filter['value']['sql'], ENT_COMPAT, 'UTF-8'), array('\_' => '_')) . '\'';
				break;
			case 'headers':
				$context['filter']['value']['html'] = '\'' . strtr(htmlspecialchars($filter['value']['sql'], ENT_COMPAT, 'UTF-8'), array("\n" => '<br />', '&lt;br /&gt;' => '<br />', "\t" => '&nbsp;&nbsp;&nbsp;', '\_' => '_', '\\%' => '%', '\\\\' => '\\')) . '\'';
				$context['filter']['value']['html'] = preg_replace('~&amp;lt;span class=&amp;quot;remove&amp;quot;&amp;gt;(.+?)&amp;lt;/span&amp;gt;~', '$1', $context['filter']['value']['html']);
				break;
			default:
				$context['filter']['value']['html'] = $filter['value']['sql'];
		}
	}

	/**
	 * Populates the $filter array with data from $_GET
	 */
	protected function _setFilter()
	{
		global $txt;

		$db = $GLOBALS['elk']['db'];

		// You can filter by any of the following columns:
		$filters = array(
			'id_member' => $txt['badbehaviorlog_username'],
			'ip' => $txt['badbehaviorlog_ip'],
			'session' => $txt['badbehaviorlog_session'],
			'valid' => $txt['badbehaviorlog_key'],
			'request_uri' => $txt['badbehaviorlog_request'],
			'user_agent' => $txt['badbehaviorlog_agent'],
		);

		if (!isset($filters[$this->http_req->query->filter]))
			return false;

		return array(
			'variable' => $this->http_req->query->filter == 'useragent' ? 'user_agent' : $this->http_req->query->filter,
			'value' => array(
				'sql' => in_array($this->http_req->query->filter, array('request_uri', 'user_agent')) ? base64_decode(strtr($this->http_req->query->value, array(' ' => '+'))) : $db->escape_wildcard_string($this->http_req->query->value),
			),
			'href' => ';filter=' . $this->http_req->query->filter . ';value=' . $this->http_req->query->value,
			'entity' => $filters[$this->http_req->query->filter]
		);
	}

	/**
	 * Performs the removal of one or multiple log entries
	 *
	 * @param string[] $filter - an array describing the current filter
	 */
	protected function _action_delete($filter)
	{
		$type = isset($this->http_req->post->delall) ? 'delall' : 'delete';

		// Make sure the session exists and the token is correct
		$this->session->check();
		validateToken('Admin-bbl');

		$redirect = deleteBadBehavior($type, $filter);
		$redirect_path = 'action=Admin;area=logs;sa=badbehaviorlog' . (isset($this->http_req->query->desc) ? ';desc' : '');

		if ($redirect === 'delete')
		{
			$start = $this->http_req->getQuery('start', 'intval', 0);

			// Go back to where we were.
			redirectexit($redirect_path . ';start=' . $start . (!empty($filter) ? $filter['href'] : ''));
		}
		redirectexit($redirect_path);
	}
}