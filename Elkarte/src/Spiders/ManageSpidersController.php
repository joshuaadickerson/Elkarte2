<?php

/**
 * This file contains all the screens that relate to search engines.
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

namespace Elkarte\Spiders;

use Elkarte\Elkarte\Controller\AbstractController;
use Elkarte\Elkarte\Controller\Action;
use Elkarte\Elkarte\SettingsForm;

/**
 * ManageSearchEngines Admin controller. This class handles all search engines
 * pages in Admin panel, forwards to display and allows to change options.
 *
 * @package SearchEngines
 */
class ManageSearchEnginesController extends AbstractController
{
	/** @var  Spiders */
	protected $spiders;
	/**
	 * Search engines settings form
	 * @var SettingsForm
	 */
	protected $_engineSettings;

	/**
	 * Entry point for this section.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		global $context, $txt;

		loadLanguage('Search');
		$this->_templates->load('ManageSearch');

		$subActions = array(
			'editspiders' => array($this, 'action_editspiders', 'permission' => 'admin_forum'),
			'logs' => array($this, 'action_logs', 'permission' => 'admin_forum'),
			'settings' => array($this, 'action_engineSettings_display', 'permission' => 'admin_forum'),
			'spiders' => array($this, 'action_spiders', 'permission' => 'admin_forum'),
			'stats' => array($this, 'action_stats', 'permission' => 'admin_forum'),
		);

		// Control
		$action = new Action('manage_search_engines');

		// Some more tab data.
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['search_engines'],
			'description' => $txt['search_engines_description'],
		);

		// Ensure we have a valid subaction. call integrate_sa_manage_search_engines
		$subAction = $action->initialize($subActions, 'stats');

		// Some contextual data for the template.
		$context['sub_action'] = $subAction;
		$context['page_title'] = $txt['search_engines'];

		// Call the right function for this sub-action.
		$action->dispatch($subAction);
	}

	/**
	 * This is the Admin settings page for search engines.
	 */
	public function action_engineSettings_display()
	{
		global $context, $txt, $scripturl;

		// Initialize the form
		$this->_initEngineSettingsForm();

		$config_vars = $this->_engineSettings->settings();

		// Set up a message.
		$context['settings_message'] = sprintf($txt['spider_settings_desc'], $scripturl . '?action=Admin;area=logs;sa=pruning;' . $context['session_var'] . '=' . $context['session_id']);

		$groups = $this->elk['groups.manager']->getBasicMembergroupData(array('globalmod', 'postgroups', 'protected', 'member'));
		foreach ($groups as $row)
		{
			// Unfortunately, regular members have to be 1 because 0 is for disabled.
			if ($row['id'] == 0)
				$config_vars['spider_group'][2][1] = $row['name'];
			else
				$config_vars['spider_group'][2][$row['id']] = $row['name'];
		}

		// Make sure it's valid - note that regular members are given id_group = 1 which is reversed in Load.php - no admins here!
		if (isset($this->http_req->post->spider_group) && !isset($config_vars['spider_group'][2][$this->http_req->post->spider_group]))
			$this->http_req->post->spider_group = 0;

		// Setup the template.
		$context['page_title'] = $txt['settings'];
		$context['sub_template'] = 'show_settings';

		// Are we saving them - are we??
		if (isset($this->http_req->query->save))
		{
			// security checks
			$this->session->check();

			// notify the interested addons or integrations
			$GLOBALS['elk']['hooks']->hook('save_search_engine_settings');

			// save the results!
			SettingsForm::save_db($config_vars);

			// make sure to rebuild the cache with updated results
			$this->spiders->recacheSpiderNames();

			// We're done with this.
			redirectexit('action=Admin;area=sengines;sa=settings');
		}

		// Set up some details for the template.
		$context['post_url'] = $scripturl . '?action=Admin;area=sengines;save;sa=settings';
		$context['settings_title'] = $txt['settings'];

		// Do some javascript.
		$javascript_function = '
			function disableFields()
			{
				disabledState = document.getElementById(\'spider_mode\').value == 0;';

		foreach ($config_vars as $variable)
		{
			if ($variable[1] != 'spider_mode')
				$javascript_function .= '
				if (document.getElementById(\'' . $variable[1] . '\'))
					document.getElementById(\'' . $variable[1] . '\').disabled = disabledState;';
		}

		$javascript_function .= '
			}
			disableFields();';
		theme()->addInlineJavascript($javascript_function, true);

		// Prepare the settings...
		SettingsForm::prepare_db($config_vars);
	}

	/**
	 * Initialize the form with configuration settings for search engines
	 */
	protected function _initEngineSettingsForm()
	{
		// Instantiate the form
		$this->_engineSettings = new SettingsForm();

		// Initialize it with our settings
		$config_vars = $this->_settings();

		return $this->_engineSettings->settings($config_vars);
	}

	/**
	 * Return configuration settings for search engines
	 */
	protected function _settings()
	{
		global $txt;

		$config_vars = array(
			// How much detail?
			array('select', 'spider_mode', 'subtext' => $txt['spider_mode_note'], array($txt['spider_mode_off'], $txt['spider_mode_standard'], $txt['spider_mode_high'], $txt['spider_mode_vhigh']), 'onchange' => 'disableFields();'),
			'spider_group' => array('select', 'spider_group', 'subtext' => $txt['spider_group_note'], array($txt['spider_group_none'])),
			array('select', 'show_spider_online', array($txt['show_spider_online_no'], $txt['show_spider_online_summary'], $txt['show_spider_online_detail'], $txt['show_spider_online_detail_admin'])),
		);

		// Notify the integration that we're preparing to mess up with search engine settings...
		$this->hooks->hook('modify_search_engine_settings', array(&$config_vars));

		return $config_vars;
	}

	/**
	 * Return the search engine settings for use in Admin search
	 */
	public function settings_search()
	{
		return $this->_settings();
	}

	/**
	 * View a list of all the spiders we know about.
	 */
	public function action_spiders()
	{
		global $context, $txt, $scripturl;

		if (!isset($_SESSION['spider_stat']) || $_SESSION['spider_stat'] < time() - 60)
		{
			$this->spiders->consolidateSpiderStats();
			$_SESSION['spider_stat'] = time();
		}

		// Are we adding a new one?
		if (!empty($this->http_req->post->addSpider))
			return $this->action_editspiders();
		// User pressed the 'remove selection button'.
		elseif (!empty($this->http_req->post->removeSpiders) && !empty($this->http_req->post->remove) && is_array($this->http_req->post->remove))
		{
			$this->session->check();
			validateToken('Admin-ser');

			// Make sure every entry is a proper integer.
			$toRemove = array_map('intval', $this->http_req->post->remove);

			// Delete them all!
			$this->spiders->removeSpiders($toRemove);

			$this->cache->remove('spider_search');
			$this->spiders->recacheSpiderNames();
		}

		// Get the last seen's.
		$context['spider_last_seen'] = $this->spiders->spidersLastSeen();

		// Token for the ride
		createToken('Admin-ser');

		// Build the list
		$listOptions = array(
			'id' => 'spider_list',
			'title' => $txt['spiders'],
			'items_per_page' => 20,
			'base_href' => $scripturl . '?action=Admin;area=sengines;sa=spiders',
			'default_sort_col' => 'name',
			'get_items' => array(
				'function' => 'getSpiders',
			),
			'get_count' => array(
				'function' => 'getNumSpiders',
				'file' => SUBSDIR . '/SearchEngines.subs.php',
			),
			'no_items_label' => $txt['spiders_no_entries'],
			'columns' => array(
				'name' => array(
					'header' => array(
						'value' => $txt['spider_name'],
					),
					'data' => array(
						'function' => function ($rowData) {
							global $scripturl;

							return sprintf('<a href="%1$s?action=Admin;area=sengines;sa=editspiders;sid=%2$d">%3$s</a>', $scripturl, $rowData['id_spider'], htmlspecialchars($rowData['spider_name'], ENT_COMPAT, 'UTF-8'));
						},
					),
					'sort' => array(
						'default' => 'spider_name',
						'reverse' => 'spider_name DESC',
					),
				),
				'last_seen' => array(
					'header' => array(
						'value' => $txt['spider_last_seen'],
					),
					'data' => array(
						'function' => function ($rowData) {
							global $context, $txt;

							return isset($context['spider_last_seen'][$rowData['id_spider']]) ? standardTime($context['spider_last_seen'][$rowData['id_spider']]) : $txt['spider_last_never'];
						},
					),
				),
				'user_agent' => array(
					'header' => array(
						'value' => $txt['spider_agent'],
					),
					'data' => array(
						'db_htmlsafe' => 'user_agent',
					),
					'sort' => array(
						'default' => 'user_agent',
						'reverse' => 'user_agent DESC',
					),
				),
				'ip_info' => array(
					'header' => array(
						'value' => $txt['spider_ip_info'],
					),
					'data' => array(
						'db_htmlsafe' => 'ip_info',
						'class' => 'smalltext',
					),
					'sort' => array(
						'default' => 'ip_info',
						'reverse' => 'ip_info DESC',
					),
				),
				'check' => array(
					'header' => array(
						'value' => '<input type="checkbox" onclick="invertAll(this, this.form);" class="input_check" />',
						'class' => 'centertext',
					),
					'data' => array(
						'sprintf' => array(
							'format' => '<input type="checkbox" name="remove[]" value="%1$d" class="input_check" />',
							'params' => array(
								'id_spider' => false,
							),
						),
						'class' => 'centertext',
					),
				),
			),
			'form' => array(
				'href' => $scripturl . '?action=Admin;area=sengines;sa=spiders',
				'token' => 'Admin-ser',
			),
			'additional_rows' => array(
				array(
					'class' => 'submitbutton',
					'position' => 'bottom_of_list',
					'value' => '
						<input type="submit" name="removeSpiders" value="' . $txt['spiders_remove_selected'] . '" onclick="return confirm(\'' . $txt['spider_remove_selected_confirm'] . '\');" />
						<input type="submit" name="addSpider" value="' . $txt['spiders_add'] . '" class="right_submit" />
					',
				),
			),
		);

		createList($listOptions);

		$context['sub_template'] = 'show_list';
		$context['default_list'] = 'spider_list';
	}

	/**
	 * Here we can add, and edit, spider info!
	 */
	public function action_editspiders()
	{
		global $context, $txt;

		// Some standard stuff.
		$context['id_spider'] = $this->http_req->getQuery('sid', 'intval', 0);
		$context['page_title'] = $context['id_spider'] ? $txt['spiders_edit'] : $txt['spiders_add'];
		$context['sub_template'] = 'spider_edit';

		// Are we saving?
		if (!empty($this->http_req->post->save))
		{
			$this->session->check();
			validateToken('Admin-ses');

			// Check the IP range is valid.
			$ips = array();
			$ip_sets = explode(',', $this->http_req->post->spider_ip);
			foreach ($ip_sets as $set)
			{
				$test = ip2range(trim($set));
				if (!empty($test))
					$ips[] = $set;
			}
			$ips = implode(',', $ips);

			// Goes in as it is...
			$this->spiders->updateSpider($context['id_spider'], $this->http_req->post->spider_name, $this->http_req->post->spider_agent, $ips);

			// Order by user agent length.
			$this->spiders->sortSpiderTable();

			$GLOBALS['elk']['cache']->remove('spider_search');
			$this->spiders->recacheSpiderNames();

			redirectexit('action=Admin;area=sengines;sa=spiders');
		}

		// The default is new.
		$context['spider'] = array(
			'id' => 0,
			'name' => '',
			'agent' => '',
			'ip_info' => '',
		);

		// An edit?
		if ($context['id_spider'])
			$context['spider'] = $this->spiders->getSpiderDetails($context['id_spider']);

		createToken('Admin-ses');
	}

	/**
	 * See what spiders have been up to.
	 */
	public function action_logs()
	{
		global $context, $txt, $scripturl, $modSettings;

		// Load the template and language just incase.
		loadLanguage('Search');
		$this->_templates->load('ManageSearch');

		// Did they want to delete some or all entries?
		if ((!empty($this->http_req->post->delete_entries) && isset($this->http_req->post->older)) || !empty($this->http_req->post->removeAll))
		{
			$this->session->check();
			validateToken('Admin-sl');

			$since = $this->http_req->getPost('older', 'intval', 0);
			$deleteTime = time() - ($since * 24 * 60 * 60);

			// Delete the entries.
			$this->spiders->removeSpiderOldLogs($deleteTime);
		}

		// Build out the spider log list
		$listOptions = array(
			'id' => 'spider_logs',
			'items_per_page' => 20,
			'title' => $txt['spider_logs'],
			'no_items_label' => $txt['spider_logs_empty'],
			'base_href' => $context['admin_area'] == 'sengines' ? $scripturl . '?action=Admin;area=sengines;sa=logs' : $scripturl . '?action=Admin;area=logs;sa=spiderlog',
			'default_sort_col' => 'log_time',
			'get_items' => array(
				'function' => 'getSpiderLogs',
			),
			'get_count' => array(
				'function' => 'getNumSpiderLogs',
				'file' => SUBSDIR . '/SearchEngines.subs.php',
			),
			'columns' => array(
				'name' => array(
					'header' => array(
						'value' => $txt['spider'],
					),
					'data' => array(
						'db' => 'spider_name',
					),
					'sort' => array(
						'default' => 's.spider_name',
						'reverse' => 's.spider_name DESC',
					),
				),
				'log_time' => array(
					'header' => array(
						'value' => $txt['spider_time'],
					),
					'data' => array(
						'function' => function ($rowData) {
							return standardTime($rowData['log_time']);
						},
					),
					'sort' => array(
						'default' => 'sl.id_hit DESC',
						'reverse' => 'sl.id_hit',
					),
				),
				'viewing' => array(
					'header' => array(
						'value' => $txt['spider_viewing'],
					),
					'data' => array(
						'db' => 'url',
					),
				),
			),
			'form' => array(
				'token' => 'Admin-sl',
				'href' => $scripturl . '?action=Admin;area=sengines;sa=logs',
			),
			'additional_rows' => array(
				array(
					'position' => 'after_title',
					'value' => $txt['spider_logs_info'],
				),
				array(
					'position' => 'below_table_data',
					'value' => '<input type="submit" name="removeAll" value="' . $txt['spider_log_empty_log'] . '" onclick="return confirm(\'' . $txt['spider_log_empty_log_confirm'] . '\');" class="right_submit" />',
				),
			),
		);

		createToken('Admin-sl');

		createList($listOptions);

		// Now determine the actions of the URLs.
		if (!empty($context['spider_logs']['rows']))
		{
			$urls = array();
			// Grab the current /url.
			foreach ($context['spider_logs']['rows'] as $k => $row)
			{
				// Feature disabled?
				if (empty($row['data']['viewing']['value']) && isset($modSettings['spider_mode']) && $modSettings['spider_mode'] < 3)
					$context['spider_logs']['rows'][$k]['data']['viewing']['value'] = '<em>' . $txt['spider_disabled'] . '</em>';
				else
					$urls[$k] = array($row['data']['viewing']['value'], -1);
			}

			// Now stick in the new URLs.

			$urls = determineActions($urls, 'whospider_');
			foreach ($urls as $k => $new_url)
				$context['spider_logs']['rows'][$k]['data']['viewing']['value'] = $new_url;
		}

		$context['page_title'] = $txt['spider_logs'];
		$context['sub_template'] = 'show_spider_logs';
	}

	/**
	 * Show the spider statistics.
	 */
	public function action_stats()
	{
		global $context, $txt, $scripturl;

		// Force an update of the stats every 60 seconds.
		if (!isset($_SESSION['spider_stat']) || $_SESSION['spider_stat'] < time() - 60)
		{
			$this->spiders->consolidateSpiderStats();
			$_SESSION['spider_stat'] = time();
		}

		// Are we cleaning up some old stats?
		if (!empty($this->http_req->post->delete_entries) && isset($this->http_req->post->older))
		{
			$this->session->check();
			validateToken('Admin-ss');

			$deleteTime = time() - (((int) $this->http_req->post->older) * 24 * 60 * 60);

			// Delete the entries.
			$this->spiders->removeSpiderOldStats($deleteTime);
		}

		// Prepare the dates for the drop down.
		$date_choices = $this->spiders->spidersStatsDates();
		end($date_choices);
		$max_date = key($date_choices);

		// What are we currently viewing?
		$current_date = isset($this->http_req->post->new_date) && isset($date_choices[$this->http_req->post->new_date]) ? $this->http_req->post->new_date : $max_date;

		// Prepare the HTML.
		$date_select = '
			' . $txt['spider_stats_select_month'] . ':
			<select name="new_date" onchange="document.spider_stat_list.submit();">';

		if (empty($date_choices))
			$date_select .= '
				<option></option>';
		else
			foreach ($date_choices as $id => $text)
				$date_select .= '
				<option value="' . $id . '"' . ($current_date == $id ? ' selected="selected"' : '') . '>' . $text . '</option>';

		$date_select .= '
			</select>
			<noscript>
				<input type="submit" name="go" value="' . $txt['go'] . '" class="right_submit" />
			</noscript>';

		// If we manually jumped to a date work out the offset.
		if (isset($this->http_req->post->new_date))
		{
			$date_query = sprintf('%04d-%02d-01', substr($current_date, 0, 4), substr($current_date, 4));

			$_REQUEST['start'] = $this->spiders->getNumSpiderStats($date_query);
		}

		$listOptions = array(
			'id' => 'spider_stat_list',
			'title' => $txt['spider'] . ' ' . $txt['spider_stats'],
			'items_per_page' => 20,
			'base_href' => $scripturl . '?action=Admin;area=sengines;sa=stats',
			'default_sort_col' => 'stat_date',
			'get_items' => array(
				'function' => 'getSpiderStats',
			),
			'get_count' => array(
				'function' => 'getNumSpiderStats',
				'file' => SUBSDIR . '/SearchEngines.subs.php',
			),
			'no_items_label' => $txt['spider_stats_no_entries'],
			'columns' => array(
				'stat_date' => array(
					'header' => array(
						'value' => $txt['date'],
					),
					'data' => array(
						'db' => 'stat_date',
					),
					'sort' => array(
						'default' => 'stat_date',
						'reverse' => 'stat_date DESC',
					),
				),
				'name' => array(
					'header' => array(
						'value' => $txt['spider_name'],
					),
					'data' => array(
						'db' => 'spider_name',
					),
					'sort' => array(
						'default' => 's.spider_name',
						'reverse' => 's.spider_name DESC',
					),
				),
				'page_hits' => array(
					'header' => array(
						'value' => $txt['spider_stats_page_hits'],
					),
					'data' => array(
						'db' => 'page_hits',
					),
					'sort' => array(
						'default' => 'ss.page_hits',
						'reverse' => 'ss.page_hits DESC',
					),
				),
			),
			'form' => array(
				'href' => $scripturl . '?action=Admin;area=sengines;sa=stats',
				'name' => 'spider_stat_list',
			),
			'additional_rows' => array(
				array(
					'position' => 'below_table_data',
					'value' => $date_select,
					'style' => 'text-align: right;',
				),
			),
		);

		createToken('Admin-ss');

		createList($listOptions);

		$context['sub_template'] = 'show_spider_stats';
	}
}