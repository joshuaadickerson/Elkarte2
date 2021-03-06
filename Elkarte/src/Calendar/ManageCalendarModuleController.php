<?php

/**
 * This file allows you to manage the calendar.
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

namespace Elkarte\Calendar;
use Elkarte\Elkarte\Controller\AbstractController;

/**
 * This class controls execution for actions in the manage calendar area
 * of the Admin panel.
 *
 * @package Calendar
 */
class ManageCalendarModuleController extends AbstractController
{
	/**
	 * Calendar settings form
	 * @var SettingsForm
	 */
	protected $_calendarSettings;

	/**
	 * Used to add the Calendar entry to the Core Features list.
	 *
	 * @param mixed[] $core_features The core features array
	 */
	public static function addCoreFeature(&$core_features)
	{
		$core_features['cd'] = array(
			'url' => 'action=Admin;area=managecalendar',
			'settings' => array(
				'cal_enabled' => 1,
			),
			'setting_callback' => function ($value) {
				if ($value)
				{
					enableModules('calendar', array('post', 'boardindex', 'display'));
				}
				else
				{
					disableModules('calendar', array('post', 'boardindex', 'display'));
				}
			},
		);
	}

	/**
	 * Used to add the Calendar entry to the Admin menu.
	 *
	 * @param mixed[] $admin_areas The Admin menu array
	 */
	public static function addAdminMenu(&$admin_areas)
	{
		global $txt, $context, $modSettings;

		$admin_areas['layout']['areas']['managecalendar'] = array(
			'label' => $txt['manage_calendar'],
			'controller' => 'ManageCalendarModuleController',
			'function' => 'action_index',
			'icon' => 'transparent.png',
			'class' => 'admin_img_calendar',
			'permission' => array('admin_forum'),
			'enabled' => in_array('cd', $context['admin_features']),
			'subsections' => array(
				'holidays' => array($txt['manage_holidays'], 'admin_forum', 'enabled' => !empty($modSettings['cal_enabled'])),
				'settings' => array($txt['calendar_settings'], 'admin_forum'),
			),
		);
	}

	/**
	 * Used to add the Calendar entry to the Admin search.
	 *
	 * @param string[] $language_files
	 * @param string[] $include_files
	 * @param mixed[] $settings_search
	 */
	public static function addAdminSearch(&$language_files, &$include_files, &$settings_search)
	{
		$settings_search[] = array('settings_search', 'area=managecalendar;sa=settings', 'ManageCalendarModuleController');
	}

	/**
	 * The main controlling function doesn't have much to do... yet.
	 * Just check permissions and delegate to the rest.
	 *
	 * @uses ManageCalendar language file.
	 */
	public function action_index()
	{
		global $context, $txt;

		// Everything's gonna need this.
		loadLanguage('ManageCalendar');

		// Default text.
		$context['explain_text'] = $txt['calendar_desc'];

		// Little short on the ground of functions here... but things can and maybe will change...
		$subActions = array(
			'editholiday' => array($this, 'action_editholiday', 'permission' => 'admin_forum'),
			'holidays' => array($this, 'action_holidays', 'permission' => 'admin_forum'),
			'settings' => array($this, 'action_calendarSettings_display', 'permission' => 'admin_forum')
		);

		// Action control
		$action = new Action('manage_calendar');

		// Set up the two tabs here...
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['manage_calendar'],
			'help' => 'calendar',
			'description' => $txt['calendar_settings_desc'],
			'tabs' => array(
				'holidays' => array(
					'description' => $txt['manage_holidays_desc'],
				),
				'settings' => array(
					'description' => $txt['calendar_settings_desc'],
				),
			),
		);

		// Set up the default subaction, call integrate_sa_manage_calendar
		$subAction = $action->initialize($subActions, 'settings');
		$context['sub_action'] = $subAction;

		// Off we go
		$action->dispatch($subAction);
	}

	/**
	 * The function that handles adding, and deleting holiday data
	 */
	public function action_holidays()
	{
		global $scripturl, $txt, $context;

		// Submitting something...
		if (isset($this->http_req->query->delete) && !empty($this->http_req->query->holiday))
		{
			$this->session->check();
			validateToken('Admin-mc');

			$to_remove = array_map('intval', array_keys($this->http_req->query->holiday));

			// Now the IDs are "safe" do the delete...
			require_once(SUBSDIR . '/Calendar.subs.php');
			removeHolidays($to_remove);
		}

		createToken('Admin-mc');
		$listOptions = array(
			'id' => 'holiday_list',
			'title' => $txt['current_holidays'],
			'items_per_page' => 20,
			'base_href' => $scripturl . '?action=Admin;area=managecalendar;sa=holidays',
			'default_sort_col' => 'name',
			'get_items' => array(
				'file' => SUBSDIR . '/Calendar.subs.php',
				'function' => 'list_getHolidays',
			),
			'get_count' => array(
				'file' => SUBSDIR . '/Calendar.subs.php',
				'function' => 'list_getNumHolidays',
			),
			'no_items_label' => $txt['holidays_no_entries'],
			'columns' => array(
				'name' => array(
					'header' => array(
						'value' => $txt['holidays_title'],
					),
					'data' => array(
						'sprintf' => array(
							'format' => '<a href="' . $scripturl . '?action=Admin;area=managecalendar;sa=editholiday;holiday=%1$d">%2$s</a>',
							'params' => array(
								'id_holiday' => false,
								'title' => false,
							),
						),
					),
					'sort' => array(
						'default' => 'title',
						'reverse' => 'title DESC',
					)
				),
				'date' => array(
					'header' => array(
						'value' => $txt['date'],
					),
					'data' => array(
						'function' => function ($rowData) {
							global $txt;

							// Recurring every year or just a single year?
							$year = $rowData['year'] == '0004' ? sprintf('(%1$s)', $txt['every_year']) : $rowData['year'];

							// Construct the date.
							return sprintf('%1$d %2$s %3$s', $rowData['day'], $txt['months'][(int) $rowData['month']], $year);
						},
					),
					'sort' => array(
						'default' => 'event_date',
						'reverse' => 'event_date DESC',
					),
				),
				'check' => array(
					'header' => array(
						'value' => '<input type="checkbox" onclick="invertAll(this, this.form);" class="input_check" />',
						'class' => 'centertext',
					),
					'data' => array(
						'sprintf' => array(
							'format' => '<input type="checkbox" name="holiday[%1$d]" class="input_check" />',
							'params' => array(
								'id_holiday' => false,
							),

						),
						'class' => 'centertext'
					),
				),
			),
			'form' => array(
				'href' => $scripturl . '?action=Admin;area=managecalendar;sa=holidays',
				'token' => 'Admin-mc',
			),
			'additional_rows' => array(
				array(
					'position' => 'below_table_data',
					'class' => 'submitbutton',
					'value' => '<input type="submit" name="delete" value="' . $txt['quickmod_delete_selected'] . '" class="right_submit" onclick="return confirm(\'' . $txt['holidays_delete_confirm'] . '\');" />
					<a class="linkbutton" href="' . $scripturl . '?action=Admin;area=managecalendar;sa=editholiday">' . $txt['holidays_add'] . '</a>',
				),
			),
		);

		createList($listOptions);

		$context['page_title'] = $txt['manage_holidays'];
	}

	/**
	 * This function is used for adding/editing a specific holiday
	 *
	 * @uses ManageCalendar template, edit_holiday sub template
	 */
	public function action_editholiday()
	{
		global $txt, $context;

		//We need this, really..
		require_once(SUBSDIR . '/Calendar.subs.php');

		$this->_templates->load('ManageCalendar');

		$context['is_new'] = !isset($this->http_req->query->holiday);
		$context['page_title'] = $context['is_new'] ? $txt['holidays_add'] : $txt['holidays_edit'];
		$context['sub_template'] = 'edit_holiday';

		// Cast this for safety...
		$this->http_req->query->holiday = $this->http_req->getQuery('holiday', 'intval');

		// Submitting?

		if (isset($this->http_req->post->$context['session_var']) && (isset($this->http_req->post->delete) || $this->http_req->post->title != ''))
		{
			$this->session->check();

			// Not too long good sir?
			$this->http_req->post->title = $GLOBALS['elk']['text']->substr($this->http_req->post->title, 0, 60);
			$this->http_req->post->holiday = $this->http_req->getPost('holiday', 'intval', 0);

			if (isset($this->http_req->post->delete))
				removeHolidays($this->http_req->post->holiday);
			else
			{
				$date = strftime($this->http_req->post->year <= 4 ? '0004-%m-%d' : '%Y-%m-%d', mktime(0, 0, 0, $this->http_req->post->month, $this->http_req->post->day, $this->http_req->post->year));
				if (isset($this->http_req->post->edit))
					editHoliday($this->http_req->post->holiday, $date, $this->http_req->post->title);
				else
					insertHoliday($date, $this->http_req->post->title);
			}

			redirectexit('action=Admin;area=managecalendar;sa=holidays');
		}

		// Default states...
		if ($context['is_new'])
		{
			$context['holiday'] = array(
				'id' => 0,
				'day' => date('d'),
				'month' => date('m'),
				'year' => '0000',
				'title' => ''
			);
		}
		// If it's not new load the data.
		else
			$context['holiday'] = getHoliday($this->http_req->query->holiday);

		// Last day for the drop down?
		$context['holiday']['last_day'] = (int) strftime('%d', mktime(0, 0, 0, $context['holiday']['month'] == 12 ? 1 : $context['holiday']['month'] + 1, 0, $context['holiday']['month'] == 12 ? $context['holiday']['year'] + 1 : $context['holiday']['year']));
	}

	/**
	 * Show and allow to modify calendar settings.
	 *
	 * - The method uses a SettingsForm to do the work.
	 */
	public function action_calendarSettings_display()
	{
		global $context, $scripturl;

		// Initialize the form
		$this->_initCalendarSettingsForm();

		$config_vars = $this->_calendarSettings->settings();

		// Get the final touches in place.
		$context['post_url'] = $scripturl . '?action=Admin;area=managecalendar;save;sa=settings';
		$context[$context['admin_menu_name']]['current_subsection'] = 'settings';

		// Saving the settings?
		if (isset($this->http_req->query->save))
		{
			$this->session->check();
			$GLOBALS['elk']['hooks']->hook('save_calendar_settings');
			SettingsForm::save_db($config_vars, $this->http_req->post);

			// Update the stats in case.
			updateSettings(array(
				'calendar_updated' => time(),
			));

			redirectexit('action=Admin;area=managecalendar;sa=settings');
		}

		// We need this for the in-line permissions
		createToken('Admin-mp');

		// Prepare the settings...
		SettingsForm::prepare_db($config_vars);
	}

	/**
	 * Retrieve and return all Admin settings for the calendar.
	 */
	protected function _initCalendarSettingsForm()
	{
		global $txt, $context;

		// Instantiate the form
		$this->_calendarSettings = new SettingsForm();

		// Initialize it with our settings
		$config_vars = $this->_settings();

		// Some important context stuff
		$context['page_title'] = $txt['calendar_settings'];
		$context['sub_template'] = 'show_settings';

		// Lets start off with the permission blocks collapsed
		theme()->addInlineJavascript('var legend = $(\'legend\');
			legend.siblings().slideToggle("fast");
			legend.parent().toggleClass("collapsed")', true);

		return $this->_calendarSettings->settings($config_vars);
	}

	/**
	 * Retrieve and return all Admin settings for the calendar.
	 */
	protected function _settings()
	{
		global $txt;

		// Load the boards list.

		$boards_list = getBoardList(array('override_permissions' => true, 'not_redirection' => true), true);
		$boards = array('');
		foreach ($boards_list as $board)
			$boards[$board['id_board']] = $board['cat_name'] . ' - ' . $board['board_name'];

		// Look, all the calendar settings - of which there are many!
		$config_vars = array(
			array('title', 'calendar_settings'),
				// All the permissions:
				array('permissions', 'calendar_view'),
				array('permissions', 'calendar_post'),
				array('permissions', 'calendar_edit_own'),
				array('permissions', 'calendar_edit_any'),
			'',
				// How many days to show on board index, and where to display events etc?
				array('int', 'cal_days_for_index', 6, 'postinput' => $txt['days_word']),
				array('select', 'cal_showholidays', array(0 => $txt['setting_cal_show_never'], 1 => $txt['setting_cal_show_cal'], 3 => $txt['setting_cal_show_index'], 2 => $txt['setting_cal_show_all'])),
				array('select', 'cal_showbdays', array(0 => $txt['setting_cal_show_never'], 1 => $txt['setting_cal_show_cal'], 3 => $txt['setting_cal_show_index'], 2 => $txt['setting_cal_show_all'])),
				array('select', 'cal_showevents', array(0 => $txt['setting_cal_show_never'], 1 => $txt['setting_cal_show_cal'], 3 => $txt['setting_cal_show_index'], 2 => $txt['setting_cal_show_all'])),
				array('check', 'cal_export'),
			'',
				// Linking events etc...
				array('select', 'cal_defaultboard', $boards),
				array('check', 'cal_daysaslink'),
				array('check', 'cal_allow_unlinked'),
				array('check', 'cal_showInTopic'),
			'',
				// Dates of calendar...
				array('int', 'cal_minyear'),
				array('int', 'cal_maxyear'),
			'',
				// Calendar spanning...
				array('check', 'cal_allowspan'),
				array('int', 'cal_maxspan', 6, 'postinput' => $txt['days_word']),
		);

		// Add new settings with a nice hook, makes them available for Admin settings search as well
		$GLOBALS['elk']['hooks']->hook('modify_calendar_settings', array(&$config_vars));

		return $config_vars;
	}

	/**
	 * Return the form settings for use in Admin search
	 */
	public function settings_search()
	{
		return $this->_settings();
	}
}