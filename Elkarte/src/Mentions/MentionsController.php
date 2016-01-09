<?php

/**
 * Handles all the mentions actions so members are notified of mentionable actions
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Mentions;

use Elkarte\Elkarte\Controller\AbstractController;

/**
 * MentionsController Class:  Add mention notifications for various actions such
 * as liking a post, adding a buddy, @ calling a member in a post
 *
 * @package Mentions
 */
class MentionsController extends AbstractController
{
	/**
	 * Will hold all available mention types
	 *
	 * @var array
	 */
	protected $_known_mentions = array();

	/**
	 * Will hold all available mention status
	 * 'new' => 0, 'read' => 1, 'deleted' => 2, 'unapproved' => 3,
	 *
	 * @var array
	 */
	protected $_known_status = array();

	/**
	 * The type of the mention we are looking at (if empty means all of them)
	 *
	 * @var string
	 */
	protected $_type = '';

	/**
	 * The url of the display mentions button (all, unread, etc)
	 *
	 * @var string
	 */
	protected $_url_param = '';

	/**
	 * Used for pagenation, keeps track of the current start point
	 *
	 * @var int
	 */
	protected $_page = 0;

	/**
	 * Number of items per page
	 *
	 * @var int
	 */
	protected $_items_per_page = 20;

	/**
	 * Default sorting column
	 *
	 * @var string
	 */
	protected $_default_sort = 'log_time';

	/**
	 * User chosen sorting column
	 *
	 * @var string
	 */
	protected $_sort = '';

	/**
	 * The sorting methods we know
	 *
	 * @var string[]
	 */
	protected $_known_sorting = array();

	/**
	 * Determine if we are looking only at unread mentions or any kind of
	 *
	 * @var boolean
	 */
	protected $_all = false;

	/**
	 * Start things up, what else does a constructor do
	 */
	public function __construct($eventManager)
	{
		$this->_known_status = array(
			'new' => Mentioning::MNEW,
			'read' => Mentioning::READ,
			'deleted' => Mentioning::DELETED,
			'unapproved' => Mentioning::UNAPPROVED,
		);

		$this->_known_sorting = array('id_member_from', 'type', 'log_time');

		parent::__construct($eventManager);
	}

	/**
	 * Determines the enabled mention types.
	 *
	 * @global $modSettings
	 * @return string[]
	 */
	protected function _findMentionTypes()
	{
		global $modSettings;

		if (empty($modSettings['enabled_mentions']))
			return array();

		return array_filter(array_unique(explode(',', $modSettings['enabled_mentions'])));
	}

	/**
	 * Set up the data for the mention based on what was requested
	 * This function is called before the flow is redirected to action_index().
	 */
	public function pre_dispatch()
	{
		global $modSettings;

		// I'm not sure this is needed, though better have it. :P
		if (empty($modSettings['mentions_enabled']))
			$this->_errors->fatal_lang_error('no_access', false);

		$this->_known_mentions = $this->_findMentionTypes();
	}

	/**
	 * The default action is to show the list of mentions
	 * This allows ?action=mention to be forwarded to action_list()
	 */
	public function action_index()
	{
		if ($this->http_req->getQuery('sa') === 'fetch')
		{
			$this->action_fetch();
		}
		else
		{
			// default action to execute
			$this->action_list();
		}
	}

	/**
	 * Creates a list of mentions for the user
	 * Allows them to mark them read or unread
	 * Can sort the various forms of mentions, likes or @mentions
	 */
	public function action_list()
	{
		global $context, $txt, $scripturl;

		// Only registered members can be mentioned
		is_not_guest();

		require_once(ROOTDIR . '/Mentions/Mentions.subs.php');
		loadLanguage('Mentions');

		$this->_buildUrl();

		$list_options = array(
			'id' => 'list_mentions',
			'title' => empty($this->_all) ? $txt['my_unread_mentions'] : $txt['my_mentions'],
			'items_per_page' => $this->_items_per_page,
			'base_href' => $scripturl . '?action=mentions;sa=list' . $this->_url_param,
			'default_sort_col' => $this->_default_sort,
			'default_sort_dir' => 'default',
			'no_items_label' => $this->_all ? $txt['no_mentions_yet'] : $txt['no_new_mentions'],
			'get_items' => array(
				'function' => array($this, 'list_loadMentions'),
				'params' => array(
					$this->_all,
					$this->_type,
				),
			),
			'get_count' => array(
				'function' => array($this, 'list_getMentionCount'),
				'params' => array(
					$this->_all,
					$this->_type,
				),
			),
			'columns' => array(
				'id_member_from' => array(
					'header' => array(
						'value' => $txt['mentions_from'],
					),
					'data' => array(
						'function' => function ($row) {
							global $settings, $scripturl;

							if (isset($settings['mentions']['mentioner_template']))
								return str_replace(
									array(
										'{avatar_img}',
										'{mem_url}',
										'{mem_name}',
									),
									array(
										$row['avatar']['image'],
										!empty($row['id_member_from']) ? $scripturl . '?action=profile;u=' . $row['id_member_from'] : '',
										$row['mentioner'],
									),
									$settings['mentions']['mentioner_template']);
						},
					),
					'sort' => array(
						'default' => 'mtn.id_member_from',
						'reverse' => 'mtn.id_member_from DESC',
					),
				),
				'type' => array(
					'header' => array(
						'value' => $txt['mentions_what'],
					),
					'data' => array(
						'db' => 'message',
					),
					'sort' => array(
						'default' => 'mtn.mention_type',
						'reverse' => 'mtn.mention_type DESC',
					),
				),
				'log_time' => array(
					'header' => array(
						'value' => $txt['mentions_when'],
						'class' => 'mention_log_time',
					),
					'data' => array(
						'db' => 'log_time',
						'timeformat' => 'html_time',
						'class' => 'mention_log_time',
					),
					'sort' => array(
						'default' => 'mtn.log_time DESC',
						'reverse' => 'mtn.log_time',
					),
				),
				'action' => array(
					'header' => array(
						'value' => $txt['mentions_action'],
						'class' => 'listaction',
					),
					'data' => array(
						'function' => function ($row) {
							global $txt, $settings, $context, $scripturl;

							$mark = empty($row['status']) ? 'read' : 'unread';
							$opts = '<a href="' . $scripturl . '?action=mentions;sa=updatestatus;mark=' . $mark . ';item=' . $row['id_mention'] . ';' . $context['session_var'] . '=' . $context['session_id'] . ';"><img title="' . $txt['mentions_mark' . $mark] . '" src="' . $settings['images_url'] . '/icons/mark_' . $mark . '.png" alt="*" /></a>&nbsp;';

							return $opts . '<a href="' . $scripturl . '?action=mentions;sa=updatestatus;mark=delete;item=' . $row['id_mention'] . ';' . $context['session_var'] . '=' . $context['session_id'] . ';"><img title="' . $txt['delete'] . '" src="' . $settings['images_url'] . '/icons/delete.png" alt="*" /></a>';
						},
						'class' => 'listaction',
					),
				),
			),
			'list_menu' => array(
				'show_on' => 'top',
				'links' => array(
					array(
						'href' => $scripturl . '?action=mentions' . (!empty($this->_all) ? ';all' : ''),
						'is_selected' => empty($this->_type),
						'label' => $txt['mentions_type_all']
					),
				),
			),
			'additional_rows' => array(
				array(
					'position' => 'top_of_list',
					'value' => '<a class="floatright linkbutton" href="' . $scripturl . '?action=mentions' . (!empty($this->_all) ? '' : ';all') . str_replace(';all', '', $this->_url_param) . '">' . (!empty($this->_all) ? $txt['mentions_unread'] : $txt['mentions_all']) . '</a>',
				),
				array(
					'class' => 'submitbutton',
					'position' => 'bottom_of_list',
					'value' => '<a class="linkbutton" href="' . $scripturl . '?action=mentions;sa=updatestatus;mark=readall' . str_replace(';all', '', $this->_url_param) . ';' . $context['session_var'] . '=' . $context['session_id'] . '">' . $txt['mentions_mark_all_read'] . '</a>',
				),
			),
		);

		foreach ($this->_known_mentions as $mention)
		{
			$list_options['list_menu']['links'][] = array(
				'href' => $scripturl . '?action=mentions;type=' . $mention . (!empty($this->_all) ? ';all' : ''),
				'is_selected' => $this->_type === $mention,
				'label' => $txt['mentions_type_' . $mention]
			);
		}

		createList($list_options);

		$context['page_title'] = $txt['my_mentions'] . (!empty($this->_page) ? ' - ' . sprintf($txt['my_mentions_pages'], $this->_page) : '');
		$context['breadcrumbs'][] = array(
			'url' => $scripturl . '?action=mentions',
			'name' => $txt['my_mentions'],
		);

		if (!empty($this->_type))
			$context['breadcrumbs'][] = array(
				'url' => $scripturl . '?action=mentions;type=' . $this->_type,
				'name' => $txt['mentions_type_' . $this->_type],
			);
	}

	/**
	 * Fetches number of notifications and number of recently added ones for use
	 * in favicon and desktop notifications.
	 * @todo probably should be placed somewhere else.
	 */
	public function action_fetch()
	{
		global $user_info, $context, $txt, $modSettings;

		if (empty($modSettings['usernotif_favicon_enable']) && empty($modSettings['usernotif_desktop_enable']))
			die();

		$this->_templates->load('Json');
		$context['sub_template'] = 'send_json';
		$this->_layers->removeAll();
		require_once(ROOTDIR . '/Mentions/Mentions.subs.php');

		$lastsent = isset($_GET['lastsent']) ? (int) $_GET['lastsent'] : 0;
		if (empty($lastsent) && !empty($_SESSION['notifications_lastseen']))
			$lastsent = (int) $_SESSION['notifications_lastseen'];

		// We only know AJAX for this particular action
		$context['json_data'] = array(
			'timelast' => getTimeLastMention($user_info['id'])
		);

		if (!empty($modSettings['usernotif_favicon_enable']))
		{
			$context['json_data']['mentions'] = !empty($user_info['mentions']) ? $user_info['mentions'] : 0;
		}

		if (!empty($modSettings['usernotif_desktop_enable']))
		{
			$context['json_data']['desktop_notifications'] = array(
				'new_from_last' => getNewMentions($user_info['id'], $lastsent),
				'title' => sprintf($txt['forum_notification'], $context['forum_name']),
			);
			$context['json_data']['desktop_notifications']['message'] = sprintf($txt[$lastsent == 0 ? 'unread_notifications' : 'new_from_last_notifications'], $context['json_data']['desktop_notifications']['new_from_last']);
		}

		$_SESSION['notifications_lastseen'] = $context['json_data']['timelast'];
	}

	/**
	 * Callback for createList(),
	 * Returns the number of mentions of $type that a member has
	 *
	 * @param bool $all : if true counts all the mentions, otherwise only the unread
	 * @param string $type : the type of mention
	 */
	public function list_getMentionCount($all, $type)
	{
		return countUserMentions($all, $type);
	}

	/**
	 * Callback for createList(),
	 * Returns the mentions of a give type (like/mention) & (unread or all)
	 *
	 * @param int $start start list number
	 * @param int $limit how many to show on a page
	 * @param string $sort which direction are we showing this
	 * @param bool $all : if true load all the mentions or type, otherwise only the unread
	 * @param string $type : the type of mention
	 */
	public function list_loadMentions($start, $limit, $sort, $all, $type)
	{
		$totalMentions = countUserMentions($all, $type);
		$mentions = array();
		$round = 0;

		$this->_registerEvents($type);

		while ($round < 2)
		{
			$possible_mentions = getUserMentions($start, $limit, $sort, $all, $type);
			$count_possible = count($possible_mentions);

			$this->_events->trigger('view_mentions', array($type, &$possible_mentions));

			foreach ($possible_mentions as $mention)
			{
				if (count($mentions) < $limit)
					$mentions[] = $mention;
				else
					break;
			}
			$round++;

			// If nothing has been removed OR there are not enough
			if (count($mentions) != $count_possible || count($mentions) == $limit || ($totalMentions - $start < $limit))
				break;

			// Let's start a bit further into the list
			$start += $limit;
		}

		if ($round !== 0)
			countUserMentions();

		return $mentions;
	}

	/**
	 * Register the listeners for a mention type or for all the mentions.
	 *
	 * @param string|null $type Specific mention type
	 */
	protected function _registerEvents($type = null)
	{
		if (!empty($type))
		{
			$to_register = array(ucfirst($type) . 'Mention');
		}
		else
		{
			$to_register = array_map(function($name) {
				return '\\ElkArte\\Mentions\\Types\\' . ucfirst($name) . 'Mention';
			}, $this->_known_mentions);
		}

		$this->_registerEvent('view_mentions', 'view', $to_register);
	}

	/**
	 * Builds the link back so you return to the right list of mentions
	 */
	protected function _buildUrl()
	{
		$this->_all = isset($_REQUEST['all']);
		$this->_sort = isset($_REQUEST['sort']) && in_array($_REQUEST['sort'], $this->_known_sorting) ? $_REQUEST['sort'] : $this->_default_sort;
		$this->_type = isset($_REQUEST['type']) && in_array($_REQUEST['type'], $this->_known_mentions) ? $_REQUEST['type'] : '';
		$this->_page = isset($_REQUEST['start']) ? $_REQUEST['start'] : '';

		$this->_url_param = ($this->_all ? ';all' : '') . (!empty($this->_type) ? ';type=' . $this->_type : '') . (isset($_REQUEST['start']) ? ';start=' . $_REQUEST['start'] : '');
	}
}
