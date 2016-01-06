<?php

/**
 * This file, unpredictable as this might be, handles basic administration.
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

namespace Elkarte\Admin;

use Elkarte\Elkarte\Controller\AbstractController;

/**
 * Admin controller class.
 *
 * What it does:
 * - This class handles the first general Admin screens: home,
 * - Handles Admin area search actions and end Admin session.
 *
 * @package Admin
 */

class AdminController extends AbstractController
{
	/**
	 * Pre Dispatch, called before other methods.  Loads integration hooks
	 * and HttpReq instance.
	 */
	public function pre_dispatch()
	{
		$GLOBALS['elk']['hooks']->loadIntegrationsSettings();
	}

	/**
	 * The main Admin handling function.
	 *
	 * What it does:
	 * - It initialises all the basic context required for the Admin center.
	 * - It passes execution onto the relevant Admin section.
	 * - If the passed section is not found it shows the Admin home page.
	 * - Accessed by ?action=Admin.
	 */
	public function action_index()
	{
		global $txt, $context, $scripturl, $modSettings, $settings;

		// Make sure the administrator has a valid session...
		validateSession();

		// Load the language and templates....
		loadLanguage('Admin');
		$this->_templates->load('Admin');
		loadCSSFile('Admin.css');
		loadJavascriptFile('Admin.js', array(), 'admin_script');

		// The Admin functions require Jquery UI ....
		$modSettings['jquery_include_ui'] = true;

		// No indexing evil stuff.
		$context['robot_no_index'] = true;

		// Need these to do much
		require_once(SUBSDIR . '/Menu.subs.php');
		require_once(SUBSDIR . '/Admin.subs.php');

		// Define the menu structure - see subs/Menu.subs.php for details!
		$admin_areas = array(
			'forum' => array(
				'title' => $txt['admin_main'],
				'permission' => array('admin_forum', 'manage_permissions', 'moderate_forum', 'manage_membergroups', 'manage_bans', 'send_mail', 'edit_news', 'manage_boards', 'manage_smileys', 'manage_attachments'),
				'areas' => array(
					'index' => array(
						'label' => $txt['admin_center'],
						'controller' => 'AdminController',
						'function' => 'action_home',
						'icon' => 'transparent.png',
						'class' => 'admin_img_administration',
					),
					'credits' => array(
						'label' => $txt['support_credits_title'],
						'controller' => 'AdminController',
						'function' => 'action_credits',
						'icon' => 'transparent.png',
						'class' => 'admin_img_support',
					),
					'maillist' => array(
						'label' => $txt['mail_center'],
						'controller' => 'ManageMaillistController',
						'function' => 'action_index',
						'icon' => 'mail.png',
						'class' => 'admin_img_mail',
						'permission' => array('approve_emails', 'admin_forum'),
						'enabled' => in_array('pe', $context['admin_features']),
						'subsections' => array(
							'emaillist' => array($txt['mm_emailerror'], 'approve_emails'),
							'emailfilters' => array($txt['mm_emailfilters'], 'admin_forum'),
							'emailparser' => array($txt['mm_emailparsers'], 'admin_forum'),
							'emailtemplates' => array($txt['mm_emailtemplates'], 'approve_emails'),
							'emailsettings' => array($txt['mm_emailsettings'], 'admin_forum'),
						),
					),
					'news' => array(
						'label' => $txt['news_title'],
						'controller' => 'ManageNewsController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_news',
						'permission' => array('edit_news', 'send_mail', 'admin_forum'),
						'subsections' => array(
							'editnews' => array($txt['admin_edit_news'], 'edit_news'),
							'mailingmembers' => array($txt['admin_newsletters'], 'send_mail'),
							'settings' => array($txt['settings'], 'admin_forum'),
						),
					),
					'packages' => array(
						'label' => $txt['package'],
						'controller' => 'PackagesController',
						'function' => 'action_index',
						'permission' => array('admin_forum'),
						'icon' => 'transparent.png',
						'class' => 'admin_img_packages',
						'subsections' => array(
							'browse' => array($txt['browse_packages']),
							'installed' => array($txt['installed_packages']),
							'perms' => array($txt['package_file_perms']),
							'options' => array($txt['package_settings']),
							'servers' => array($txt['download_packages']),
							'upload' => array($txt['upload_packages']),
						),
					),
					'packageservers' => array(
						'label' => $txt['package_servers'],
						'controller' => 'PackageServersController',
						'function' => 'action_index',
						'permission' => array('admin_forum'),
						'icon' => 'transparent.png',
						'class' => 'admin_img_packages',
						'hidden' => true,
					),
					'search' => array(
						'controller' => 'AdminController',
						'function' => 'action_search',
						'permission' => array('admin_forum'),
						'select' => 'index'
					),
					'adminlogoff' => array(
						'controller' => 'AdminController',
						'function' => 'action_endsession',
						'label' => $txt['admin_logoff'],
						'enabled' => empty($modSettings['securityDisable']),
						'icon' => 'transparent.png',
						'class' => 'admin_img_exit',
					),
				),
			),
			'config' => array(
				'title' => $txt['admin_config'],
				'permission' => array('admin_forum'),
				'areas' => array(
					'corefeatures' => array(
						'label' => $txt['core_settings_title'],
						'controller' => 'CoreFeaturesController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_corefeatures',
					),
					'featuresettings' => array(
						'label' => $txt['modSettings_title'],
						'controller' => 'ManageFeaturesController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_features',
						'subsections' => array(
							'basic' => array($txt['mods_cat_features']),
							'layout' => array($txt['mods_cat_layout']),
							'pmsettings' => array($txt['personal_messages']),
							'karma' => array($txt['karma'], 'enabled' => in_array('k', $context['admin_features'])),
							'likes' => array($txt['likes'], 'enabled' => in_array('l', $context['admin_features'])),
							'mention' => array($txt['mention']),
							'sig' => array($txt['signature_settings_short']),
							'profile' => array($txt['custom_profile_shorttitle'], 'enabled' => in_array('cp', $context['admin_features'])),
						),
					),
					'serversettings' => array(
						'label' => $txt['admin_server_settings'],
						'controller' => 'ManageServerController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_server',
						'subsections' => array(
							'general' => array($txt['general_settings']),
							'database' => array($txt['database_paths_settings']),
							'cookie' => array($txt['cookies_sessions_settings']),
							'cache' => array($txt['caching_settings']),
							'loads' => array($txt['load_balancing_settings']),
							'phpinfo' => array($txt['phpinfo_settings']),
						),
					),
					'securitysettings' => array(
						'label' => $txt['admin_security_moderation'],
						'controller' => 'ManageSecurityController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_security',
						'subsections' => array(
							'general' => array($txt['mods_cat_security_general']),
							'spam' => array($txt['antispam_title']),
							'badbehavior' => array($txt['badbehavior_title']),
							'moderation' => array($txt['moderation_settings_short'], 'enabled' => !empty($modSettings['warning_enable'])),
						),
					),
					'theme' => array(
						'label' => $txt['theme_admin'],
						'controller' => 'ManageThemesController',
						'function' => 'action_index',
						'custom_url' => $scripturl . '?action=Admin;area=theme',
						'icon' => 'transparent.png',
						'class' => 'admin_img_themes',
						'subsections' => array(
							'Admin' => array($txt['themeadmin_admin_title']),
							'list' => array($txt['themeadmin_list_title']),
							'reset' => array($txt['themeadmin_reset_title']),
							'themelist' => array($txt['themeadmin_edit_title'], 'active' => array('edit', 'browse')),
							'edit' => array($txt['themeadmin_edit_title'], 'enabled' => false),
							'browse' => array($txt['themeadmin_edit_title'], 'enabled' => false),
						),
					),
					'current_theme' => array(
						'label' => $txt['theme_current_settings'],
						'controller' => 'ManageThemesController',
						'function' => 'action_index',
						'custom_url' => $scripturl . '?action=Admin;area=theme;sa=list;th=' . $settings['theme_id'],
						'icon' => 'transparent.png',
						'class' => 'admin_img_current_theme',
					),
					'languages' => array(
						'label' => $txt['language_configuration'],
						'controller' => 'ManageLanguagesController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_languages',
						'subsections' => array(
							'edit' => array($txt['language_edit']),
// 							'add' => array($txt['language_add']),
							'settings' => array($txt['language_settings']),
						),
					),
					'addonsettings' => array(
						'label' => $txt['admin_modifications'],
						'controller' => 'AddonSettingsController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_modifications',
						'subsections' => array(
							'general' => array($txt['mods_cat_modifications_misc']),
						),
					),
				),
			),
			'layout' => array(
				'title' => $txt['layout_controls'],
				'permission' => array('manage_boards', 'admin_forum', 'manage_smileys', 'manage_attachments', 'moderate_forum'),
				'areas' => array(
					'manageboards' => array(
						'label' => $txt['admin_boards'],
						'controller' => 'ManageBoardsController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_boards',
						'permission' => array('manage_boards'),
						'subsections' => array(
							'main' => array($txt['boardsEdit']),
							'newcat' => array($txt['mboards_new_cat']),
							'settings' => array($txt['settings'], 'admin_forum'),
						),
					),
					'postsettings' => array(
						'label' => $txt['manageposts'],
						'controller' => 'ManagePostsController',
						'function' => 'action_index',
						'permission' => array('admin_forum'),
						'icon' => 'transparent.png',
						'class' => 'admin_img_posts',
						'subsections' => array(
							'posts' => array($txt['manageposts_settings']),
							'bbc' => array($txt['manageposts_bbc_settings']),
							'censor' => array($txt['admin_censored_words']),
							'topics' => array($txt['manageposts_topic_settings']),
						),
					),
					'bbc' => array(
						'label' => $txt['bbc_manage'],
						'controller' => 'ManageBBCController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_smiley',
						'permission' => array('manage_bbc'),
					),
					'smileys' => array(
						'label' => $txt['smileys_manage'],
						'controller' => 'ManageSmileysController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_smiley',
						'permission' => array('manage_smileys'),
						'subsections' => array(
							'editsets' => array($txt['smiley_sets']),
							'addsmiley' => array($txt['smileys_add'], 'enabled' => !empty($modSettings['smiley_enable'])),
							'editsmileys' => array($txt['smileys_edit'], 'enabled' => !empty($modSettings['smiley_enable'])),
							'setorder' => array($txt['smileys_set_order'], 'enabled' => !empty($modSettings['smiley_enable'])),
							'editicons' => array($txt['icons_edit_message_icons'], 'enabled' => !empty($modSettings['messageIcons_enable'])),
							'settings' => array($txt['settings']),
						),
					),
					'manageattachments' => array(
						'label' => $txt['attachments_avatars'],
						'controller' => 'ManageAttachmentsController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_attachment',
						'permission' => array('manage_attachments'),
						'subsections' => array(
							'browse' => array($txt['attachment_manager_browse']),
							'attachments' => array($txt['attachment_manager_settings']),
							'avatars' => array($txt['attachment_manager_avatar_settings']),
							'attachpaths' => array($txt['attach_directories']),
							'maintenance' => array($txt['attachment_manager_maintenance']),
						),
					),
					'managesearch' => array(
						'label' => $txt['manage_search'],
						'controller' => 'ManageSearchController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_search',
						'permission' => array('admin_forum'),
						'subsections' => array(
							'weights' => array($txt['search_weights']),
							'method' => array($txt['search_method']),
							'managesphinx' => array($txt['search_sphinx']),
							'settings' => array($txt['settings']),
						),
					),
				),
			),
			'members' => array(
				'title' => $txt['admin_manage_members'],
				'permission' => array('moderate_forum', 'manage_membergroups', 'manage_bans', 'manage_permissions', 'admin_forum'),
				'areas' => array(
					'viewmembers' => array(
						'label' => $txt['admin_users'],
						'controller' => 'ManageMembersController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_members',
						'permission' => array('moderate_forum'),
						'subsections' => array(
							'all' => array($txt['view_all_members']),
							'search' => array($txt['mlist_search']),
						),
					),
					'membergroups' => array(
						'label' => $txt['admin_groups'],
						'controller' => 'ManageMembergroupsController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_membergroups',
						'permission' => array('manage_membergroups'),
						'subsections' => array(
							'index' => array($txt['membergroups_edit_groups'], 'manage_membergroups'),
							'add' => array($txt['membergroups_new_group'], 'manage_membergroups'),
							'settings' => array($txt['settings'], 'admin_forum'),
						),
					),
					'permissions' => array(
						'label' => $txt['edit_permissions'],
						'controller' => 'ManagePermissionsController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_permissions',
						'permission' => array('manage_permissions'),
						'subsections' => array(
							'index' => array($txt['permissions_groups'], 'manage_permissions'),
							'board' => array($txt['permissions_boards'], 'manage_permissions'),
							'profiles' => array($txt['permissions_profiles'], 'manage_permissions'),
							'postmod' => array($txt['permissions_post_moderation'], 'manage_permissions', 'enabled' => $modSettings['postmod_active']),
							'settings' => array($txt['settings'], 'admin_forum'),
						),
					),
					'ban' => array(
						'label' => $txt['ban_title'],
						'controller' => 'ManageBansController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_ban',
						'permission' => 'manage_bans',
						'subsections' => array(
							'list' => array($txt['ban_edit_list']),
							'add' => array($txt['ban_add_new']),
							'browse' => array($txt['ban_trigger_browse']),
							'log' => array($txt['ban_log']),
						),
					),
					'regcenter' => array(
						'label' => $txt['registration_center'],
						'controller' => 'ManageRegistrationController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_regcenter',
						'permission' => array('admin_forum', 'moderate_forum'),
						'subsections' => array(
							'register' => array($txt['admin_browse_register_new'], 'moderate_forum'),
							'agreement' => array($txt['registration_agreement'], 'admin_forum'),
							'reservednames' => array($txt['admin_reserved_set'], 'admin_forum'),
							'settings' => array($txt['settings'], 'admin_forum'),
						),
					),
					'sengines' => array(
						'label' => $txt['search_engines'],
						'enabled' => in_array('sp', $context['admin_features']),
						'controller' => 'ManageSearchEnginesController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_engines',
						'permission' => 'admin_forum',
						'subsections' => array(
							'stats' => array($txt['spider_stats']),
							'logs' => array($txt['spider_logs']),
							'spiders' => array($txt['spiders']),
							'settings' => array($txt['settings']),
						),
					),
					'paidsubscribe' => array(
						'label' => $txt['paid_subscriptions'],
						'enabled' => in_array('ps', $context['admin_features']),
						'controller' => 'ManagePaidController',
						'icon' => 'transparent.png',
						'class' => 'admin_img_paid',
						'function' => 'action_index',
						'permission' => 'admin_forum',
						'subsections' => array(
							'view' => array($txt['paid_subs_view']),
							'settings' => array($txt['settings']),
						),
					),
				),
			),
			'maintenance' => array(
				'title' => $txt['admin_maintenance'],
				'permission' => array('admin_forum'),
				'areas' => array(
					'maintain' => array(
						'label' => $txt['maintain_title'],
						'controller' => 'MaintenanceController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_maintain',
						'subsections' => array(
							'routine' => array($txt['maintain_sub_routine'], 'admin_forum'),
							'database' => array($txt['maintain_sub_database'], 'admin_forum'),
							'members' => array($txt['maintain_sub_members'], 'admin_forum'),
							'topics' => array($txt['maintain_sub_topics'], 'admin_forum'),
							'hooks' => array($txt['maintain_sub_hooks_list'], 'admin_forum'),
							'attachments' => array($txt['maintain_sub_attachments'], 'admin_forum'),
						),
					),
					'logs' => array(
						'label' => $txt['logs'],
						'controller' => 'AdminLogController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_logs',
						'subsections' => array(
							'errorlog' => array($txt['errlog'], 'admin_forum', 'enabled' => !empty($modSettings['enableErrorLogging']), 'url' => $scripturl . '?action=Admin;area=logs;sa=errorlog;desc'),
							'adminlog' => array($txt['admin_log'], 'admin_forum', 'enabled' => in_array('ml', $context['admin_features'])),
							'modlog' => array($txt['moderation_log'], 'admin_forum', 'enabled' => in_array('ml', $context['admin_features'])),
							'banlog' => array($txt['ban_log'], 'manage_bans'),
							'spiderlog' => array($txt['spider_logs'], 'admin_forum', 'enabled' => in_array('sp', $context['admin_features'])),
							'tasklog' => array($txt['scheduled_log'], 'admin_forum'),
							'badbehaviorlog' => array($txt['badbehavior_log'], 'admin_forum', 'enabled' => !empty($modSettings['badbehavior_enabled']), 'url' => $scripturl . '?action=Admin;area=logs;sa=badbehaviorlog;desc'),
							'pruning' => array($txt['pruning_title'], 'admin_forum'),
						),
					),
					'scheduledtasks' => array(
						'label' => $txt['maintain_tasks'],
						'controller' => 'ManageScheduledTasksController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_scheduled',
						'subsections' => array(
							'tasks' => array($txt['maintain_tasks'], 'admin_forum'),
							'tasklog' => array($txt['scheduled_log'], 'admin_forum'),
						),
					),
					'mailqueue' => array(
						'label' => $txt['mailqueue_title'],
						'controller' => 'ManageMailController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_mail',
						'subsections' => array(
							'browse' => array($txt['mailqueue_browse'], 'admin_forum'),
							'settings' => array($txt['mailqueue_settings'], 'admin_forum'),
						),
					),
					'reports' => array(
						'enabled' => in_array('rg', $context['admin_features']),
						'label' => $txt['generate_reports'],
						'controller' => 'ReportsController',
						'function' => 'action_index',
						'icon' => 'transparent.png',
						'class' => 'admin_img_reports',
					),
					'repairboards' => array(
						'label' => $txt['admin_repair'],
						'controller' => 'RepairBoardsController',
						'function' => 'action_repairboards',
						'select' => 'maintain',
						'hidden' => true,
					),
				),
			),
		);

		$this->_getModulesMenu($admin_areas);

		// Any files to include for administration?
		$GLOBALS['elk']['hooks']->include_hook('admin_include');

		$menuOptions = array('hook' => 'Admin', 'default_include_dir' => ADMINDIR);

		// Actually create the menu!
		$admin_include_data = createMenu($admin_areas, $menuOptions);
		unset($admin_areas);

		// Nothing valid?
		if ($admin_include_data == false)
			$GLOBALS['elk']['errors']->fatal_lang_error('no_access', false);

		// Build the link tree.
		$context['linktree'][] = array(
			'url' => $scripturl . '?action=Admin',
			'name' => $txt['admin_center'],
		);

		if (isset($admin_include_data['current_area']) && $admin_include_data['current_area'] != 'index')
			$context['linktree'][] = array(
				'url' => $scripturl . '?action=Admin;area=' . $admin_include_data['current_area'] . ';' . $context['session_var'] . '=' . $context['session_id'],
				'name' => $admin_include_data['label'],
			);

		if (!empty($admin_include_data['current_subsection']) && $admin_include_data['subsections'][$admin_include_data['current_subsection']][0] != $admin_include_data['label'])
			$context['linktree'][] = array(
				'url' => $scripturl . '?action=Admin;area=' . $admin_include_data['current_area'] . ';sa=' . $admin_include_data['current_subsection'] . ';' . $context['session_var'] . '=' . $context['session_id'],
				'name' => $admin_include_data['subsections'][$admin_include_data['current_subsection']][0],
			);

		// Make a note of the Unique ID for this menu.
		$context['admin_menu_id'] = $context['max_menu_id'];
		$context['admin_menu_name'] = 'menu_data_' . $context['admin_menu_id'];

		// Where in the Admin are we?
		$context['admin_area'] = $admin_include_data['current_area'];

		// Now - finally - call the right place!
		if (isset($admin_include_data['file']))
			require_once($admin_include_data['file']);

		callMenu($admin_include_data);
	}

	/**
	 * Searches the ADMINDIR looking for module managers and load the corresponding
	 * Admin menu entry.
	 *
	 * @param mixed[] $admin_areas The Admin menu array
	 */
	protected function _getModulesMenu(&$admin_areas)
	{
		$glob = new GlobIterator(ADMINDIR . '/Manage*Module.controller.php', FilesystemIterator::SKIP_DOTS);

		foreach ($glob as $file)
		{
			$name = $file->getBasename('.controller.php');
			$class = $name . 'Controller';
			$module = strtolower(substr($name, 6, -6));

			if (isModuleEnabled($module) && method_exists($class, 'addAdminMenu'))
				$class::addAdminMenu($admin_areas);
		}
	}

	/**
	 * The main administration section.
	 *
	 * What it does:
	 * - It prepares all the data necessary for the administration front page.
	 * - It uses the Admin template along with the Admin sub template.
	 * - It requires the moderate_forum, manage_membergroups, manage_bans,
	 * admin_forum, manage_permissions, manage_attachments, manage_smileys,
	 * manage_boards, edit_news, or send_mail permission.
	 * - It uses the index administrative area.
	 * - Accessed by ?action=Admin.
	 */
	public function action_home()
	{
		global $txt, $scripturl, $context, $user_info, $settings;

		// We need a little help


		// You have to be able to do at least one of the below to see this page.
		isAllowedTo(array('admin_forum', 'manage_permissions', 'moderate_forum', 'manage_membergroups', 'manage_bans', 'send_mail', 'edit_news', 'manage_boards', 'manage_smileys', 'manage_attachments'));

		// Find all of this forum's administrators...
		if (listMembergroupMembers_Href($context['administrators'], 1, 32) && allowedTo('manage_membergroups'))
		{
			// Add a 'more'-link if there are more than 32.
			$context['more_admins_link'] = '<a href="' . $scripturl . '?action=moderate;area=viewgroups;sa=members;group=1">' . $txt['more'] . '</a>';
		}

		// This makes it easier to get the latest news with your time format.
		$context['time_format'] = urlencode($user_info['time_format']);
		$context['forum_version'] = FORUM_VERSION;

		// Get a list of current server versions.
		$checkFor = array(
			'gd',
			'imagick',
			'db_server',
			'mmcache',
			'eaccelerator',
			'zend',
			'apc',
			'memcache',
			'xcache',
			'opcache',
			'php',
			'server',
		);
		$context['current_versions'] = getServerVersions($checkFor);

		$context['can_admin'] = allowedTo('admin_forum');
		$context['sub_template'] = 'Admin';
		$context['page_title'] = $txt['admin_center'];
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['admin_center'],
			'help' => '',
			'description' => '
				<strong>' . $txt['hello_guest'] . ' ' . $context['user']['name'] . '!</strong>
				' . sprintf($txt['admin_main_welcome'], $txt['admin_center'], $txt['help'], $settings['images_url']),
		);

		// Load in the Admin quick tasks
		$context['quick_admin_tasks'] = getQuickAdminTasks();
	}

	/**
	 * The credits section in Admin panel.
	 *
	 * What it does:
	 * - Determines the current level of support functions from the server, such as
	 * current level of caching engine or graphics library's installed.
	 * - Accessed by ?action=Admin;area=credits
	 */
	public function action_credits()
	{
		global $txt, $scripturl, $context, $user_info;

		// We need a little help from our friends
		require_once(SUBSDIR . '/Admin.subs.php');

		// You have to be able to do at least one of the below to see this page.
		isAllowedTo(array('admin_forum', 'manage_permissions', 'moderate_forum', 'manage_membergroups', 'manage_bans', 'send_mail', 'edit_news', 'manage_boards', 'manage_smileys', 'manage_attachments'));

		// Find all of this forum's administrators...
		if (listMembergroupMembers_Href($context['administrators'], 1, 32) && allowedTo('manage_membergroups'))
		{
			// Add a 'more'-link if there are more than 32.
			$context['more_admins_link'] = '<a href="' . $scripturl . '?action=moderate;area=viewgroups;sa=members;group=1">' . $txt['more'] . '</a>';
		}

		// Load credits.
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['support_credits_title'],
			'help' => '',
			'description' => '',
		);
		loadLanguage('Who');
		$context += prepareCreditsData();

		// This makes it easier to get the latest news with your time format.
		$context['time_format'] = urlencode($user_info['time_format']);
		$context['forum_version'] = FORUM_VERSION;

		// Get a list of current server versions.
		$checkFor = array(
			'gd',
			'imagick',
			'db_server',
			'mmcache',
			'eaccelerator',
			'zend',
			'apc',
			'memcache',
			'xcache',
			'opcache',
			'php',
			'server',
		);
		$context['current_versions'] = getServerVersions($checkFor);

		$context['can_admin'] = allowedTo('admin_forum');
		$context['sub_template'] = 'credits';
		$context['page_title'] = $txt['support_credits_title'];

		// Load in the Admin quick tasks
		$context['quick_admin_tasks'] = getQuickAdminTasks();

		$index = 'new_in_' . str_replace(array('ElkArte ', '.'), array('', '_'), FORUM_VERSION);
		if (isset($txt[$index]))
		{
			$context['latest_updates'] = replaceBasicActionUrl($txt[$index]);
			require_once(SUBSDIR . '/Themes.subs.php');

			updateThemeOptions(array(1, $user_info['id'], 'dismissed_' . $index, 1));
		}
	}

	/**
	 * This function allocates out all the search stuff.
	 *
	 * What it does:
	 * - Accessed with /index.php?action=Admin;area=search[;search_type=x]
	 * - Sets up an array of applicable sub-actions (search types) and the function that goes with each
	 * - Search type specified by "search_type" request variable (either from a
	 * form or from the query string) Defaults to 'internal'
	 * 	Calls the appropriate sub action based on the search_type
	 */
	public function action_search()
	{
		global $txt, $context;

		// What can we search for?
		$subActions = array(
			'internal' => array($this, 'action_search_internal', 'permission' => 'admin_forum'),
			'online' => array($this, 'action_search_doc', 'permission' => 'admin_forum'),
			'member' => array($this, 'action_search_member', 'permission' => 'admin_forum'),
		);

		// Set the subaction
		$action = new Action();
		$subAction = $action->initialize($subActions, 'internal');

		// Keep track of what the Admin wants in terms of advanced or not
		if (empty($context['admin_preferences']['sb']) || $context['admin_preferences']['sb'] != $subAction)
		{
			$context['admin_preferences']['sb'] = $subAction;

			// Update the preferences.
			require_once(SUBSDIR . '/Admin.subs.php');
			updateAdminPreferences();
		}

		// Setup for the template
		$context['search_type'] = $subAction;
		//$context['search_term'] = $this->_req->getPost('search_term', 'trim|$GLOBALS['elk']['text']->htmlspecialchars[ENT_QUOTES]');
		//$context['search_term'] = $this->_req->getPost('search_term', 'trim|$GLOBALS['elk']['text']->htmlspecialchars[ENT_QUOTES]');
		$context['sub_template'] = 'admin_search_results';
		$context['page_title'] = $txt['admin_search_results'];

		// You did remember to enter something to search for, otherwise its easy
		if ($context['search_term'] === '')
			$context['search_results'] = array();
		else
			$action->dispatch($subAction);
	}

	/**
	 * A complicated but relatively quick internal search.
	 *
	 * What it does:
	 * - Can be accessed with /index.php?action=Admin;sa=search;search_term=x) or
	 * from the Admin search form ("Task/Setting" option)
	 * - Polls the Controllers for their configuration settings
	 * - Calls integrate_admin_search to allow addons to add search configs
	 * - Loads up the "Help" language file and all of the "Manage" language files
	 * - Loads up information about each item it found for the template
	 */
	public function action_search_internal()
	{
		global $context, $txt;

		// Try to get some more memory.
		setMemoryLimit('128M');

		// Load a lot of language files.
		$language_files = array(
			'Help', 'ManageMail', 'ManageSettings', 'ManageBoards', 'ManagePaid', 'ManagePermissions', 'Search',
			'Login', 'ManageSmileys', 'Maillist', 'Mentions'
		);

		// All the files we need to include.
		$include_files = array(
			'AddonSettings.controller', 'AdminLog.controller', 'CoreFeatures.controller',
			'ManageAttachments.controller', 'ManageAvatars.controller', 'ManageBBC.controller',
			'ManageBoards.controller',
			'ManageFeatures.controller', 'ManageLanguages.controller', 'ManageMail.controller',
			'ManageNews.controller', 'ManagePaid.controller', 'ManagePermissions.controller',
			'ManagePosts.controller', 'ManageRegistration.controller', 'ManageSearch.controller',
			'ManageSearchEngines.controller', 'ManageSecurity.controller', 'ManageServer.controller',
			'ManageSmileys.controller', 'ManageTopics.controller', 'ManageMaillist.controller',
			'ManageMembergroups.controller'
		);

		// This is a special array of functions that contain setting data
		// - we query all these to simply pull all setting bits!
		$settings_search = array(
			array('settings_search', 'area=logs;sa=pruning', 'AdminLogController'),
			array('config_vars', 'area=corefeatures', 'CoreFeaturesController'),
			array('basicSettings_search', 'area=featuresettings;sa=basic', 'ManageFeaturesController'),
			array('layoutSettings_search', 'area=featuresettings;sa=layout', 'ManageFeaturesController'),
			array('karmaSettings_search', 'area=featuresettings;sa=karma', 'ManageFeaturesController'),
			array('likesSettings_search', 'area=featuresettings;sa=likes', 'ManageFeaturesController'),
			array('mentionSettings_search', 'area=featuresettings;sa=mention', 'ManageFeaturesController'),
			array('signatureSettings_search', 'area=featuresettings;sa=sig', 'ManageFeaturesController'),
			array('settings_search', 'area=addonsettings;sa=general', 'AddonSettingsController'),
			array('settings_search', 'area=manageattachments;sa=attachments', 'ManageAttachmentsController'),
			array('settings_search', 'area=manageattachments;sa=avatars', 'ManageAvatarsController'),
			array('settings_search', 'area=postsettings;sa=bbc', 'ManageBBCController'),
			array('settings_search', 'area=manageboards;sa=settings', 'ManageBoardsController'),
			array('settings_search', 'area=languages;sa=settings', 'ManageLanguagesController'),
			array('settings_search', 'area=mailqueue;sa=settings', 'ManageMailController'),
			array('settings_search', 'area=maillist;sa=emailsettings', 'ManageMaillistController'),
			array('settings_search', 'area=membergroups;sa=settings', 'ManageMembergroupsController'),
			array('settings_search', 'area=news;sa=settings', 'ManageNewsController'),
			array('settings_search', 'area=paidsubscribe;sa=settings', 'ManagePaidController'),
			array('settings_search', 'area=permissions;sa=settings', 'ManagePermissionsController'),
			array('settings_search', 'area=postsettings;sa=posts', 'ManagePostsController'),
			array('settings_search', 'area=regcenter;sa=settings', 'ManageRegistrationController'),
			array('settings_search', 'area=managesearch;sa=settings', 'ManageSearchController'),
			array('settings_search', 'area=sengines;sa=settings', 'ManageSearchEnginesController'),
			array('securitySettings_search', 'area=securitysettings;sa=general', 'ManageSecurityController'),
			array('spamSettings_search', 'area=securitysettings;sa=spam', 'ManageSecurityController'),
			array('moderationSettings_search', 'area=securitysettings;sa=moderation', 'ManageSecurityController'),
			array('bbSettings_search', 'area=securitysettings;sa=badbehavior', 'ManageSecurityController'),
			array('generalSettings_search', 'area=serversettings;sa=general', 'ManageServerController'),
			array('databaseSettings_search', 'area=serversettings;sa=database', 'ManageServerController'),
			array('cookieSettings_search', 'area=serversettings;sa=cookie', 'ManageServerController'),
			array('cacheSettings_search', 'area=serversettings;sa=cache', 'ManageServerController'),
			array('balancingSettings_search', 'area=serversettings;sa=loads', 'ManageServerController'),
			array('settings_search', 'area=smileys;sa=settings', 'ManageSmileysController'),
			array('settings_search', 'area=postsettings;sa=topics', 'ManageTopicsController'),
		);

		$GLOBALS['elk']['hooks']->hook('admin_search', array(&$language_files, &$include_files, &$settings_search));

		// Go through all the search data trying to find this text!
		$search_term = strtolower($GLOBALS['elk']['text']->un_htmlspecialchars($context['search_term']));

		$search = new AdminSettings_Search($language_files, $include_files, $settings_search);
		$search->initSearch($context['admin_menu_name'], array(
			array('COPPA', 'area=regcenter;sa=settings'),
			array('CAPTCHA', 'area=securitysettings;sa=spam'),
		));

		$context['page_title'] = $txt['admin_search_results'];
		$context['search_results'] = $search->doSearch($search_term);
	}

	/**
	 * All this does is pass through to manage members.
	 */
	public function action_search_member()
	{
		global $context;

		// @todo once Action.class is changed
		$_REQUEST['sa'] = 'query';

		// Set the query values
		$this->_req->post->sa = 'query';
		$this->_req->post->membername = $GLOBALS['elk']['text']->un_htmlspecialchar($context['search_term']);
		$this->_req->post->types = '';

		//$managemembers = new ManageMembersController(new \Elkarte\Elkarte\Events\EventManager()anager());
		//$managemembers->pre_dispatch();
		//$managemembers->action_index();
	}

	/**
	 * This file allows the user to search the wiki documentation
	 * for a little help.
	 */
	public function action_search_doc()
	{
		global $context;

		$context['doc_apiurl'] = 'https://github.com/elkarte/Elkarte/wiki/api.php';
		$context['doc_scripturl'] = 'https://github.com/elkarte/Elkarte/wiki/';

		// Set all the parameters search might expect.
		$postVars = explode(' ', $context['search_term']);

		// Encode the search data.
		foreach ($postVars as $k => $v)
			$postVars[$k] = urlencode($v);

		// This is what we will send.
		$postVars = implode('+', $postVars);

		// Get the results from the doc site.
		require_once(ROOTDIR . '/Packages/Package.subs.php');
		// Demo URL:
		// https://github.com/elkarte/Elkarte/wiki/api.php?action=query&list=search&srprop=timestamp|snippet&format=xml&srwhat=text&srsearch=template+eval
		$search_results = fetch_web_data($context['doc_apiurl'] . '?action=query&list=search&srprop=timestamp|snippet&format=xml&srwhat=text&srsearch=' . $postVars);

		// If we didn't get any xml back we are in trouble - perhaps the doc site is overloaded?
		if (!$search_results || preg_match('~<' . '\?xml\sversion="\d+\.\d+"\?' . '>\s*(<api>.+?</api>)~is', $search_results, $matches) !== 1)
			$GLOBALS['elk']['errors']->fatal_lang_error('cannot_connect_doc_site');

		$search_results = !empty($matches[1]) ? $matches[1] : '';

		// Otherwise we simply walk through the XML and stick it in context for display.
		$context['search_results'] = array();

		// Get the results loaded into an array for processing!
		$results = new Xml_Array($search_results, false);

		// Move through the api layer.
		if (!$results->exists('api'))
			$GLOBALS['elk']['errors']->fatal_lang_error('cannot_connect_doc_site');

		// Are there actually some results?
		if ($results->exists('api/query/search/p'))
		{
			$relevance = 0;
			foreach ($results->set('api/query/search/p') as $result)
			{
				$context['search_results'][$result->fetch('@title')] = array(
					'title' => $result->fetch('@title'),
					'relevance' => $relevance++,
					'snippet' => str_replace('class=\'searchmatch\'', 'class="highlight"', un_htmlspecialchars($result->fetch('@snippet'))),
				);
			}
		}
	}

	/**
	 * This ends a Admin session, requiring authentication to access the ACP again.
	 */
	public function action_endsession()
	{
		// This is so easy!
		unset($_SESSION['admin_time']);

		// Clean any Admin tokens as well.
		cleanTokens(false, '-Admin');

		if (isset($this->_req->query->redir, $this->_req->server->HTTP_REFERER))
			redirectexit($_SERVER['HTTP_REFERER']);
		else
			redirectexit();
	}
}