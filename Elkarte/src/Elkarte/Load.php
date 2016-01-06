<?php

/**
 * This file has the hefty job of loading information for the forum.
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

// Not ready to make it autoload but I want to reduce the size of Load.php
require_once(ELKDIR . '/Language/Language.subs.php');

/**
 * Load the $modSettings array and many necessary forum settings.
 *
 * What it does:
 * - load the settings from cache if available, otherwise from the database.
 * - sets the timezone
 * - checks the load average settings if available.
 * - check whether post moderation is enabled.
 * - calls add_integration_function
 * - calls integrate_pre_include, integrate_pre_load,
 *
 * @global array $modSettings is a giant array of all of the forum-wide settings and statistics.
 */
function reloadSettings()
{
	global $modSettings, $elk;

	$db = $elk['db'];
	$cache = $elk['cache'];
	$hooks = $elk['hooks'];

	// Try to load it from the cache first; it'll never get cached if the setting is off.
	if (!$cache->getVar($modSettings, 'modSettings', 90))
	{
		$request = $db->select('', '
			SELECT variable, value
			FROM {db_prefix}settings
			WHERE key_group = {string:key_group}',
			[
				'key_group' => 'settings',
			]
		);
		$modSettings = array();
		if (!$request)
			$GLOBALS['elk']['errors']->display_db_error();

		while ($row = $request->fetchRow())
			$modSettings[$row[0]] = $row[1];

		$request->free();

		// Do a few things to protect against missing settings or settings with invalid values...
		if (empty($modSettings['defaultMaxTopics']) || $modSettings['defaultMaxTopics'] <= 0 || $modSettings['defaultMaxTopics'] > 999)
			$modSettings['defaultMaxTopics'] = 20;
		if (empty($modSettings['defaultMaxMessages']) || $modSettings['defaultMaxMessages'] <= 0 || $modSettings['defaultMaxMessages'] > 999)
			$modSettings['defaultMaxMessages'] = 15;
		if (empty($modSettings['defaultMaxMembers']) || $modSettings['defaultMaxMembers'] <= 0 || $modSettings['defaultMaxMembers'] > 999)
			$modSettings['defaultMaxMembers'] = 30;
		if (empty($modSettings['subject_length']))
			$modSettings['subject_length'] = 24;

		$modSettings['warning_enable'] = $modSettings['warning_settings'][0];

		$cache->put('modSettings', $modSettings, 90);
	}

	$hooks->loadIntegrations();

	// Setting the timezone is a requirement for some functions in PHP >= 5.1.
	if (isset($modSettings['default_timezone']))
		date_default_timezone_set($modSettings['default_timezone']);

	// Check the load averages?
	if (!empty($modSettings['loadavg_enable']))
	{
		loadLoadAverage();
	}
	else
		$context['current_load'] = 0;

	// Is post moderation alive and well?
	$modSettings['postmod_active'] = isset($modSettings['admin_features']) ? in_array('pm', explode(',', $modSettings['admin_features'])) : true;

	// Here to justify the name of this function. :P
	// It should be added to the Install and upgrade scripts.
	// But since the converters need to be updated also. This is easier.
	if (empty($modSettings['currentAttachmentUploadDir']))
	{
		updateSettings(array(
			'attachmentUploadDir' => serialize(array(1 => $modSettings['attachmentUploadDir'])),
			'currentAttachmentUploadDir' => 1,
		));
	}

	// Integration is cool.
	if (defined('ELK_INTEGRATION_SETTINGS'))
	{
		$integration_settings = unserialize(ELK_INTEGRATION_SETTINGS);
		foreach ($integration_settings as $hook => $function)
			$GLOBALS['elk']['hooks']->add($hook, $function);
	}

	// Any files to pre include?
	$GLOBALS['elk']['hooks']->include_hook('pre_include');

	// Call pre load integration functions.
	$GLOBALS['elk']['hooks']->hook('pre_load');
}

/**
 * Check for moderators and see if they have access to the board.
 *
 * What it does:
 * - sets up the $board_info array for current board information.
 * - if cache is enabled, the $board_info array is stored in cache.
 * - redirects to appropriate post if only message id is requested.
 * - is only used when inside a topic or board.
 * - determines the local moderators for the board.
 * - adds group id 3 if the user is a local moderator for the board they are in.
 * - prevents access if user is not in proper group nor a local moderator of the board.
 */
function loadBoard(&$board)
{
	global $elk;

	return $elk['boards.manager']->load($board);
}



/**
 * @param int|0 $id_theme
 * @return int
 */
function getThemeId($id_theme = 0)
{
	global $modSettings, $user_info, $board_info, $ssi_theme;

	// The theme was specified by parameter.
	if (!empty($id_theme))
		$id_theme = (int) $id_theme;
	// The theme was specified by REQUEST.
	elseif (!empty($_REQUEST['theme']) && (!empty($modSettings['theme_allow']) || allowedTo('admin_forum')))
	{
		$id_theme = (int) $_REQUEST['theme'];
		$_SESSION['id_theme'] = $id_theme;
	}
	// The theme was specified by REQUEST... previously.
	elseif (!empty($_SESSION['id_theme']) && (!empty($modSettings['theme_allow']) || allowedTo('admin_forum')))
		$id_theme = (int) $_SESSION['id_theme'];
	// The theme is just the user's choice. (might use ?board=1;theme=0 to force board theme.)
	elseif (!empty($user_info['theme']) && !isset($_REQUEST['theme']) && (!empty($modSettings['theme_allow']) || allowedTo('admin_forum')))
		$id_theme = $user_info['theme'];
	// The theme was specified by the board.
	elseif (!empty($board_info['theme']))
		$id_theme = $board_info['theme'];
	// The theme is the forum's default.
	else
		$id_theme = $modSettings['theme_guests'];

	// Verify the id_theme... no foul play.
	// Always allow the board specific theme, if they are overriding.
	if (!empty($board_info['theme']) && $board_info['override_theme'])
		$id_theme = $board_info['theme'];
	// If they have specified a particular theme to use with SSI allow it to be used.
	elseif (!empty($ssi_theme) && $id_theme == $ssi_theme)
		$id_theme = (int) $id_theme;
	elseif (!empty($modSettings['knownThemes']) && !allowedTo('admin_forum'))
	{
		$themes = explode(',', $modSettings['knownThemes']);
		if (!in_array($id_theme, $themes))
			$id_theme = $modSettings['theme_guests'];
		else
			$id_theme = (int) $id_theme;
	}
	else
		$id_theme = (int) $id_theme;

	return $id_theme;
}

function getThemeData($id_theme, $member)
{
	global $modSettings;

	$cache = $GLOBALS['elk']['cache'];

	// Do we already have this members theme data and specific options loaded (for aggressive cache settings)
	if ($cache->checkLevel(2) && $cache->getVar($temp, 'theme_settings-' . $id_theme . ':' . $member, 60) && time() - 60 > $modSettings['settings_updated'])
	{
		$themeData = $temp;
		$flag = true;
	}
	// Or do we just have the system wide theme settings cached
	elseif ($cache->getVar($temp, 'theme_settings-' . $id_theme, 90) && time() - 60 > $modSettings['settings_updated'])
		$themeData = $temp + array($member => array());
	// Nothing at all then
	else
		$themeData = array(-1 => array(), 0 => array(), $member => array());

	if (empty($flag))
	{
		$db = $GLOBALS['elk']['db'];

		// Load variables from the current or default theme, global or this user's.
		$result = $db->select('', '
			SELECT variable, value, id_member, id_theme
			FROM {db_prefix}themes
			WHERE id_member' . (empty($themeData[0]) ? ' IN (-1, 0, {int:id_member})' : ' = {int:id_member}') . '
				AND id_theme' . ($id_theme == 1 ? ' = {int:id_theme}' : ' IN ({int:id_theme}, 1)'),
			array(
				'id_theme' => $id_theme,
				'id_member' => $member,
			)
		);

		$immutable_theme_data = array('actual_theme_url', 'actual_images_url', 'base_theme_dir', 'base_theme_url', 'default_images_url', 'default_theme_dir', 'default_theme_url', 'default_template', 'images_url', 'number_recent_posts', 'smiley_sets_default', 'theme_dir', 'theme_id', 'theme_layers', 'theme_templates', 'theme_url');

		// Pick between $settings and $options depending on whose data it is.
		while ($row = $result->fetchAssoc())
		{
			// There are just things we shouldn't be able to change as members.
			if ($row['id_member'] != 0 && in_array($row['variable'], $immutable_theme_data))
				continue;

			// If this is the theme_dir of the default theme, store it.
			if (in_array($row['variable'], array('theme_dir', 'theme_url', 'images_url')) && $row['id_theme'] == '1' && empty($row['id_member']))
				$themeData[0]['default_' . $row['variable']] = $row['value'];

			// If this isn't set yet, is a theme option, or is not the default theme..
			if (!isset($themeData[$row['id_member']][$row['variable']]) || $row['id_theme'] != '1')
				$themeData[$row['id_member']][$row['variable']] = substr($row['variable'], 0, 5) == 'show_' ? $row['value'] == '1' : $row['value'];
		}
		$result->free();

		// Set the defaults if the user has not chosen on their own
		if (!empty($themeData[-1]))
		{
			foreach ($themeData[-1] as $k => $v)
			{
				if (!isset($themeData[$member][$k]))
					$themeData[$member][$k] = $v;
			}
		}

		// If being aggressive we save the site wide and member theme settings
		if ($cache->checkLevel(2))
			$cache->put('theme_settings-' . $id_theme . ':' . $member, $themeData, 60);
		// Only if we didn't already load that part of the cache...
		elseif (!isset($temp))
			$cache->put('theme_settings-' . $id_theme, array(-1 => $themeData[-1], 0 => $themeData[0]), 90);
	}

	return $themeData;
}

function initTheme($id_theme = 0)
{
	global $user_info, $settings, $options, $context;

	$id_theme = getThemeId($id_theme);

	$member = empty($user_info['id']) ? -1 : $user_info['id'];

	$themeData = getThemeData($id_theme, $member);

	$settings = $themeData[0];
	$options = $themeData[$member];

	$settings['theme_id'] = $id_theme;
	$settings['actual_theme_url'] = $settings['theme_url'];
	$settings['actual_images_url'] = $settings['images_url'];
	$settings['actual_theme_dir'] = $settings['theme_dir'];

	// Reload the templates
	$GLOBALS['elk']['templates']->reloadDirectories($settings);

	// Setup the default theme file. In the future, this won't exist and themes will just have to extend it if they want
	require_once($settings['default_theme_dir'] . '/Theme.php');
	$context['default_theme_instance'] = new \Themes\DefaultTheme\Theme(1);

	// Check if there is a Theme file
	if ($id_theme != 1 && !empty($settings['theme_dir']) && file_exists($settings['theme_dir'] . '/Theme.php'))
	{
		require_once($settings['theme_dir'] . '/Theme.php');

		$class = '\\Themes\\' . basename($settings['theme_dir']) . '\\Theme';

		$theme = new $class($id_theme);

		$context['theme_instance'] = $theme;
	}
	else
	{
		$context['theme_instance'] = $context['default_theme_instance'];
	}
}

/**
 * Load a theme, by ID.
 *
 * What it does:
 * - identify the theme to be loaded.
 * - validate that the theme is valid and that the user has permission to use it
 * - load the users theme settings and site settings into $options.
 * - prepares the list of folders to search for template loading.
 * - identify what smiley set to use.
 * - sets up $context['user']
 * - detects the users browser and sets a mobile friendly environment if needed
 * - loads default JS variables for use in every theme
 * - loads default JS scripts for use in every theme
 *
 * @param int $id_theme = 0
 * @param bool $initialize = true
 */
function loadTheme($id_theme = 0, $initialize = true)
{
	global $user_info, $user_settings;
	global $txt, $scripturl, $mbname, $modSettings;
	global $context, $settings, $options;

	initTheme($id_theme);

	if (!$initialize)
		return;

	loadThemeUrls();

	$GLOBALS['elk']['members.manager']->loadUserContext();

	// Set up some additional interface preference context
	$context['admin_preferences'] = !empty($options['admin_preferences']) ? unserialize($options['admin_preferences']) : array();

	if (!$user_info['is_guest'])
		$context['minmax_preferences'] = !empty($options['minmax_preferences']) ? unserialize($options['minmax_preferences']) : array();
	// Guest may have collapsed the header, check the cookie to prevent collapse jumping
	elseif ($user_info['is_guest'] && isset($_COOKIE['upshrink']))
		$context['minmax_preferences'] = array('upshrink' => $_COOKIE['upshrink']);

	// @todo when are these set before loadTheme(0, true)?
	loadThemeContext();

	// @todo These really don't belong in loadTheme() since they are more general than the theme.
	$context['session_var'] = $_SESSION['session_var'];
	$context['session_id'] = $_SESSION['session_value'];
	$context['forum_name'] = $mbname;
	$context['forum_name_html_safe'] = $context['forum_name'];
	$context['current_action'] = isset($_REQUEST['action']) ? $_REQUEST['action'] : null;
	$context['current_subaction'] = isset($_REQUEST['sa']) ? $_REQUEST['sa'] : null;

	// Set some permission related settings.
	if ($user_info['is_guest'] && !empty($modSettings['enableVBStyleLogin']))
	{
		$context['show_login_bar'] = true;
		$context['theme_header_callbacks'][] = 'login_bar';
		loadJavascriptFile('sha256.js', array('defer' => true));
	}

	// Detect the browser. This is separated out because it's also used in attachment downloads
	$GLOBALS['elk']['browser'];

	// Set the top level linktree up.
	array_unshift($context['linktree'], array(
		'url' => $scripturl,
		'name' => $context['forum_name']
	));

	// Just some mobile-friendly settings
	if ($context['browser_body_id'] == 'mobile')
	{
		// Disable the preview text.
		$modSettings['message_index_preview'] = 0;
		// Force the usage of click menu instead of a hover menu.
		$options['use_click_menu'] = 1;
		// No space left for a sidebar
		$options['use_sidebar_menu'] = false;
		// Disable the search dropdown.
		$modSettings['search_dropdown'] = false;
	}

	if (!isset($txt))
		$txt = array();

	theme()->loadDefaultLayers();

	// Defaults in case of odd things
	$settings['avatars_on_indexes'] = 0;

	// Initialize the theme.
	if (function_exists('template_init'))
		$settings = array_merge($settings, template_init());

	// Call initialization theme integration functions.
	$GLOBALS['elk']['hooks']->hook('init_theme', array($id_theme, &$settings));

	// Guests may still need a name.
	if ($context['user']['is_guest'] && empty($context['user']['name']))
		$context['user']['name'] = $txt['guest_title'];

	// Any theme-related strings that need to be loaded?
	if (!empty($settings['require_theme_strings']))
		loadLanguage('ThemeStrings', '', false);

	// Load font Awesome fonts
	loadCSSFile('font-awesome.min.css');

	// We allow theme variants, because we're cool.
	if (!empty($settings['theme_variants']))
	{
		theme()->loadThemeVariant();
	}

	// A bit lonely maybe, though I think it should be set up *after* the theme variants detection
	$context['header_logo_url_html_safe'] = empty($settings['header_logo_url']) ? $settings['images_url'] . '/' . $context['theme_variant_url'] .  'logo_elk.png' : $GLOBALS['elk']['text']->htmlspecialchars($settings['header_logo_url']);

	// Allow overriding the board wide time/number formats.
	if (empty($user_settings['time_format']) && !empty($txt['time_format']))
		$user_info['time_format'] = $txt['time_format'];

	if (isset($settings['use_default_images']) && $settings['use_default_images'] == 'always')
	{
		$settings['theme_url'] = $settings['default_theme_url'];
		$settings['images_url'] = $settings['default_images_url'];
		$settings['theme_dir'] = $settings['default_theme_dir'];
	}

	// Make a special URL for the language.
	$settings['lang_images_url'] = $settings['images_url'] . '/' . (!empty($txt['image_lang']) ? $txt['image_lang'] : $user_info['language']);

	// RTL languages require an additional stylesheet.
	if ($context['right_to_left'])
		loadCSSFile('rtl.css');

	if (!empty($context['theme_variant']) && $context['right_to_left'])
		loadCSSFile($context['theme_variant'] . '/rtl' . $context['theme_variant'] . '.css');

	// This allows us to change the way things look for the Admin.
	$context['admin_features'] = isset($modSettings['admin_features']) ? explode(',', $modSettings['admin_features']) : array('cd,cp,k,w,rg,ml,pm');

	if (!empty($modSettings['xmlnews_enable']) && (!empty($modSettings['allow_guestAccess']) || $context['user']['is_logged']))
		$context['newsfeed_urls'] = array(
			'rss' => $scripturl . '?action=.xml;type=rss2;limit=' . (!empty($modSettings['xmlnews_limit']) ? $modSettings['xmlnews_limit'] : 5),
			'atom' => $scripturl . '?action=.xml;type=atom;limit=' . (!empty($modSettings['xmlnews_limit']) ? $modSettings['xmlnews_limit'] : 5)
		);

	theme()->loadThemeJavascript();

	$hooks = $GLOBALS['elk']['hooks'];
	$hooks->newPath(array('$themedir' => $settings['theme_dir']));

	// Any files to include at this point?
	$hooks->include_hook('theme_include');

	// Call load theme integration functions.
	$hooks->hook('load_theme');

	// We are ready to go.
	$context['theme_loaded'] = true;
}

function loadThemeUrls()
{
	global $scripturl, $boardurl, $modSettings;

	// Check to see if they're accessing it from the wrong place.
	if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['SERVER_NAME']))
	{
		$detected_url = isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on' ? 'https://' : 'http://';
		$detected_url .= empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] . (empty($_SERVER['SERVER_PORT']) || $_SERVER['SERVER_PORT'] == '80' ? '' : ':' . $_SERVER['SERVER_PORT']) : $_SERVER['HTTP_HOST'];
		$temp = preg_replace('~/' . basename($scripturl) . '(/.+)?$~', '', strtr(dirname($_SERVER['PHP_SELF']), '\\', '/'));
		if ($temp != '/')
			$detected_url .= $temp;
	}

	if (isset($detected_url) && $detected_url != $boardurl)
	{
		// Try #1 - check if it's in a list of alias addresses.
		if (!empty($modSettings['forum_alias_urls']))
		{
			$aliases = explode(',', $modSettings['forum_alias_urls']);
			foreach ($aliases as $alias)
			{
				// Rip off all the boring parts, spaces, etc.
				if ($detected_url == trim($alias) || strtr($detected_url, array('http://' => '', 'https://' => '')) == trim($alias))
					$do_fix = true;
			}
		}

		// Hmm... check #2 - is it just different by a www?  Send them to the correct place!!
		if (empty($do_fix) && strtr($detected_url, array('://' => '://www.')) == $boardurl && (empty($_GET) || count($_GET) == 1) && ELK != 'SSI')
		{
			// Okay, this seems weird, but we don't want an endless loop - this will make $_GET not empty ;).
			if (empty($_GET))
				redirectexit('wwwRedirect');
			else
			{
				list ($k, $v) = each($_GET);
				if ($k != 'wwwRedirect')
					redirectexit('wwwRedirect;' . $k . '=' . $v);
			}
		}

		// #3 is just a check for SSL...
		if (strtr($detected_url, array('https://' => 'http://')) == $boardurl)
			$do_fix = true;

		// Okay, #4 - perhaps it's an IP address?  We're gonna want to use that one, then. (assuming it's the IP or something...)
		if (!empty($do_fix) || preg_match('~^http[s]?://(?:[\d\.:]+|\[[\d:]+\](?::\d+)?)(?:$|/)~', $detected_url) == 1)
		{
			fixThemeUrls($detected_url);
		}
	}
}

function loadThemeContext()
{
	global $context, $settings, $modSettings;

	// Some basic information...
	if (!isset($context['html_headers']))
		$context['html_headers'] = '';
	if (!isset($context['links']))
		$context['links'] = array();
	if (!isset($context['javascript_files']))
		$context['javascript_files'] = array();
	if (!isset($context['css_files']))
		$context['css_files'] = array();
	if (!isset($context['javascript_inline']))
		$context['javascript_inline'] = array('standard' => array(), 'defer' => array());
	if (!isset($context['javascript_vars']))
		$context['javascript_vars'] = array();

	// Set a couple of bits for the template.
	$context['right_to_left'] = !empty($txt['lang_rtl']);
	$context['tabindex'] = 1;

	$context['theme_variant'] = '';
	$context['theme_variant_url'] = '';

	$context['menu_separator'] = !empty($settings['use_image_buttons']) ? ' ' : ' | ';
	$context['can_register'] = empty($modSettings['registration_method']) || $modSettings['registration_method'] != 3;

	foreach (array('theme_header', 'upper_content') as $call)
	{
		if (!isset($context[$call . '_callbacks']))
			$context[$call . '_callbacks'] = array();
	}

	// This allows sticking some HTML on the page output - useful for controls.
	$context['insert_after_template'] = '';
}



function fixThemeUrls($detected_url)
{
	global $boardurl, $scripturl, $settings, $modSettings, $context;

	// Caching is good ;).
	$oldurl = $boardurl;

	// Fix $boardurl and $scripturl.
	$boardurl = $detected_url;
	$scripturl = strtr($scripturl, array($oldurl => $boardurl));
	$_SERVER['REQUEST_URL'] = strtr($_SERVER['REQUEST_URL'], array($oldurl => $boardurl));

	// Fix the theme urls...
	$settings['theme_url'] = strtr($settings['theme_url'], array($oldurl => $boardurl));
	$settings['default_theme_url'] = strtr($settings['default_theme_url'], array($oldurl => $boardurl));
	$settings['actual_theme_url'] = strtr($settings['actual_theme_url'], array($oldurl => $boardurl));
	$settings['images_url'] = strtr($settings['images_url'], array($oldurl => $boardurl));
	$settings['default_images_url'] = strtr($settings['default_images_url'], array($oldurl => $boardurl));
	$settings['actual_images_url'] = strtr($settings['actual_images_url'], array($oldurl => $boardurl));

	// And just a few mod settings :).
	$modSettings['smileys_url'] = strtr($modSettings['smileys_url'], array($oldurl => $boardurl));
	$modSettings['avatar_url'] = strtr($modSettings['avatar_url'], array($oldurl => $boardurl));

	// Clean up after loadBoard().
	if (isset($board_info['moderators']))
	{
		foreach ($board_info['moderators'] as $k => $dummy)
		{
			$board_info['moderators'][$k]['href'] = strtr($dummy['href'], array($oldurl => $boardurl));
			$board_info['moderators'][$k]['link'] = strtr($dummy['link'], array('"' . $oldurl => '"' . $boardurl));
		}
	}

	foreach ($context['linktree'] as $k => $dummy)
		$context['linktree'][$k]['url'] = strtr($dummy['url'], array($oldurl => $boardurl));
}

/**
 * Determine the current user's smiley set
 *
 * @return string
 */
function determineSmileySet($user_smiley_set, $known_smiley_sets)
{
	global $modSettings, $settings;

	if ((!in_array($user_smiley_set, explode(',', $known_smiley_sets)) && $user_smiley_set !== 'none') || empty($modSettings['smiley_sets_enable']))
	{
		$set = !empty($settings['smiley_sets_default']) ? $settings['smiley_sets_default'] : $modSettings['smiley_sets_default'];
	}
	else
	{
		$set = $user_smiley_set;
	}

	return $set;
}

/**
 * This loads the bare minimum data.
 *
 * - Needed by scheduled tasks,
 * - Needed by any other code that needs language files before the forum (the theme) is loaded.
 */
function loadEssentialThemeData()
{
	global $settings, $modSettings, $mbname, $context;

	$db = $GLOBALS['elk']['db'];

	// Get all the default theme variables.
	$db->fetchQueryCallback('
		SELECT id_theme, variable, value
		FROM {db_prefix}themes
		WHERE id_member = {int:no_member}
			AND id_theme IN (1, {int:theme_guests})',
		array(
			'no_member' => 0,
			'theme_guests' => $modSettings['theme_guests'],
		),
		function($row)
		{
			global $settings;

			$settings[$row['variable']] = $row['value'];

			// Is this the default theme?
			if (in_array($row['variable'], array('theme_dir', 'theme_url', 'images_url')) && $row['id_theme'] == '1')
				$settings['default_' . $row['variable']] = $row['value'];
		}
	);

	// Check we have some directories setup.
	if (!$GLOBALS['elk']['templates']->hasDirectories())
	{
		$GLOBALS['elk']['templates']->reloadDirectories($settings);
	}

	// Assume we want this.
	$context['forum_name'] = $mbname;
	$context['forum_name_html_safe'] = $context['forum_name'];

	loadLanguage('index+Addons');
}

/**
 * Add a CSS file for output later
 *
 * @param string[]|string $filenames string or array of filenames to work on
 * @param mixed[] $params = array()
 * Keys are the following:
 * - ['local'] (true/false): define if the file is local
 * - ['fallback'] (true/false): if false  will attempt to load the file
 *   from the default theme if not found in the current theme
 * - ['stale'] (true/false/string): if true or null, use cache stale,
 *   false do not, or used a supplied string
 * @param string $id optional id to use in html id=""
 */
function loadCSSFile($filenames, $params = array(), $id = '')
{
	global $context;

	if (empty($filenames))
		return;

	if (!is_array($filenames))
		$filenames = array($filenames);

	if (in_array('Admin.css', $filenames))
		$filenames[] = $context['theme_variant'] . '/Admin' . $context['theme_variant'] . '.css';

	$params['subdir'] = 'css';
	$params['extension'] = 'css';
	$params['index_name'] = 'css_files';
	$params['debug_index'] = 'sheets';

	loadAssetFile($filenames, $params, $id);
}

/**
 * Add a Javascript file for output later
 *
 * What it does:
 * - Can be passed an array of filenames, all which will have the same
 *   parameters applied,
 * - if you need specific parameters on a per file basis, call it multiple times
 *
 * @param string[]|string $filenames string or array of filenames to work on
 * @param mixed[] $params = array()
 * Keys are the following:
 * - ['local'] (true/false): define if the file is local, if file does not
 *     start with http its assumed local
 * - ['defer'] (true/false): define if the file should load in <head> or before
 *     the closing <html> tag
 * - ['fallback'] (true/false): if true will attempt to load the file from the
 *     default theme if not found in the current this is the default behavior
 *     if this is not supplied
 * - ['async'] (true/false): if the script should be loaded asynchronously (HTML5)
 * - ['stale'] (true/false/string): if true or null, use cache stale, false do
 *     not, or used a supplied string
 * @param string $id = '' optional id to use in html id=""
 */
function loadJavascriptFile($filenames, $params = array(), $id = '')
{
	if (empty($filenames))
		return;

	$params['subdir'] = 'scripts';
	$params['extension'] = 'js';
	$params['index_name'] = 'js_files';
	$params['debug_index'] = 'javascript';

	loadAssetFile($filenames, $params, $id);
}

/**
 * Add an asset (css, js or other) file for output later
 *
 * What it does:
 * - Can be passed an array of filenames, all which will have the same
 *   parameters applied,
 * - If you need specific parameters on a per file basis, call it multiple times
 *
 * @param string[]|string $filenames string or array of filenames to work on
 * @param mixed[] $params = array()
 * Keys are the following:
 * - ['subdir'] (string): the subdirectory of the theme dir the file is in
 * - ['extension'] (string): the extension of the file (e.g. css)
 * - ['index_name'] (string): the $context index that holds the array of loaded
 *     files
 * - ['debug_index'] (string): the index that holds the array of loaded
 *     files for debugging debug
 * - ['local'] (true/false): define if the file is local, if file does not
 *     start with http or // (schema-less URLs) its assumed local.
 *     The parameter is in fact useful only for files whose name starts with
 *     http, in any other case (e.g. passing a local URL) the parameter would
 *     fail in properly adding the file to the list.
 * - ['defer'] (true/false): define if the file should load in <head> or before
 *     the closing <html> tag
 * - ['fallback'] (true/false): if true will attempt to load the file from the
 *     default theme if not found in the current this is the default behavior
 *     if this is not supplied
 * - ['async'] (true/false): if the script should be loaded asynchronously (HTML5)
 * - ['stale'] (true/false/string): if true or null, use cache stale, false do
 *     not, or used a supplied string
 * @param string $id = '' optional id to use in html id=""
 */
function loadAssetFile($filenames, $params = array(), $id = '')
{
	global $settings, $context, $db_show_debug;

	if (empty($filenames))
		return;

	$cache = $GLOBALS['elk']['cache'];

	if (!is_array($filenames))
		$filenames = array($filenames);

	// Static values for all these settings
	if (!isset($params['stale']) || $params['stale'] === true)
		$staler_string = CACHE_STALE;
	elseif (is_string($params['stale']))
		$staler_string = ($params['stale'][0] === '?' ? $params['stale'] : '?' . $params['stale']);
	else
		$staler_string = '';

	$fallback = (!empty($params['fallback']) && ($params['fallback'] === false)) ? false : true;
	$dir = '/' . $params['subdir'] . '/';

	// Whoa ... we've done this before yes?
	$cache_name = 'load_' . $params['extension'] . '_' . hash('md5', $settings['theme_dir'] . implode('_', $filenames));
	if ($cache->getVar($temp, $cache_name, 600))
	{
		if (empty($context[$params['index_name']]))
			$context[$params['index_name']] = array();

		$context[$params['index_name']] += $temp;

		if ($db_show_debug === true)
		{
			foreach ($temp as $temp_params)
			{
				$context['debug'][$params['debug_index']][] = $temp_params['options']['basename'] . '(' . (!empty($temp_params['options']['local']) ? (!empty($temp_params['options']['url']) ? basename($temp_params['options']['url']) : basename($temp_params['options']['dir'])) : '') . ')';
			}
		}
	}
	else
	{
		$this_build = array();

		// All the files in this group use the above parameters
		foreach ($filenames as $filename)
		{
			// Account for shorthand like Admin.ext?xyz11 filenames
			$has_cache_staler = strpos($filename, '.' . $params['extension'] . '?');
			if ($has_cache_staler)
			{
				$cache_staler = $staler_string;
				$params['basename'] = substr($filename, 0, $has_cache_staler + strlen($params['extension']) + 1);
			}
			else
			{
				$cache_staler = '';
				$params['basename'] = $filename;
			}
			$this_id = empty($id) ? strtr(basename($filename), '?', '_') : $id;

			// Is this a local file?
			if (!empty($params['local']) || (substr($filename, 0, 4) !== 'http' && substr($filename, 0, 2) !== '//'))
			{
				$params['local'] = true;
				$params['dir'] = $settings['theme_dir'] . $dir;
				$params['url'] = $settings['theme_url'];

				// Fallback if we are not already in the default theme
				if ($fallback && ($settings['theme_dir'] !== $settings['default_theme_dir']) && !file_exists($settings['theme_dir'] . $dir . $params['basename']))
				{
					// Can't find it in this theme, how about the default?
					if (file_exists($settings['default_theme_dir'] . $dir . $params['basename']))
					{
						$filename = $settings['default_theme_url'] . $dir . $params['basename'] . $cache_staler;
						$params['dir'] = $settings['default_theme_dir'] . $dir;
						$params['url'] = $settings['default_theme_url'];
					}
					else
						$filename = false;
				}
				else
					$filename = $settings['theme_url'] . $dir . $params['basename'] . $cache_staler;
			}

			// Add it to the array for use in the template
			if (!empty($filename))
			{
				$this_build[$this_id] = $context[$params['index_name']][$this_id] = array('filename' => $filename, 'options' => $params);

				if ($db_show_debug === true)
					$GLOBALS['elk']['debug']->add($params['debug_index'], $params['basename'] . '(' . (!empty($params['local']) ? (!empty($params['url']) ? basename($params['url']) : basename($params['dir'])) : '') . ')');
			}

			// Save it so we don't have to build this so often
			$cache->put($cache_name, $this_build, 600);
		}
	}
}

/**
 * Initialize a database connection.
 */
function loadDatabase($db_persist, $db_server, $db_user, $db_passwd, $db_port, $db_type, $db_name, $db_prefix)
{
	global $elk;

	return $elk['db'];
}

/**
 * Determine the user's avatar type and return the information as an array
 *
 * @todo this function seems more useful than expected, it should be improved. :P
 * @todo move to MemberContext
 *
 * @param mixed[] $profile array containing the users profile data
 * @return mixed[] $avatar
 */
function determineAvatar($profile)
{
	global $modSettings, $scripturl, $settings, $context;
	static $added_once = false;

	if (empty($profile))
		return array();

	// @todo compatibility setting for migration
	if (!isset($modSettings['avatar_max_height']))
		$modSettings['avatar_max_height'] = $modSettings['avatar_max_height_external'];
	if (!isset($modSettings['avatar_max_width']))
		$modSettings['avatar_max_width'] = $modSettings['avatar_max_width_external'];

	// @todo move this to the theme init
	// Since it's nice to have avatars all of the same size, and in some cases the size detection may fail,
	// let's add the css in any case
	if (!$added_once)
	{
		if (!isset($context['html_headers']))
			$context['html_headers'] = '';

		if (!empty($modSettings['avatar_max_width']) || !empty($modSettings['avatar_max_height']))
		{
			$context['html_headers'] .= '
	<style>
		.avatarresize {' . (!empty($modSettings['avatar_max_width']) ? '
			max-width:' . $modSettings['avatar_max_width'] . 'px;' : '') . (!empty($modSettings['avatar_max_height']) ? '
			max-height:' . $modSettings['avatar_max_height'] . 'px;' : '') . '
		}
	</style>';
		}
		$added_once = true;
	}

	$avatar_protocol = substr(strtolower($profile['avatar']), 0, 7);

	// uploaded avatar?
	if ($profile['id_attach'] > 0 && empty($profile['avatar']))
	{
		// where are those pesky avatars?
		$avatar_url = empty($profile['attachment_type']) ? $scripturl . '?action=dlattach;attach=' . $profile['id_attach'] . ';type=avatar' : $modSettings['custom_avatar_url'] . '/' . $profile['filename'];

		$avatar = array(
			'name' => $profile['avatar'],
			'image' => '<img class="avatar avatarresize" src="' . $avatar_url . '" alt="" />',
			'href' => $avatar_url,
			'url' => '',
		);
	}
	// remote avatar?
	elseif ($avatar_protocol === 'http://' || $avatar_protocol === 'https:/')
	{
		$avatar = array(
			'name' => $profile['avatar'],
			'image' => '<img class="avatar avatarresize" src="' . $profile['avatar'] . '" alt="" />',
			'href' => $profile['avatar'],
			'url' => $profile['avatar'],
		);
	}
	// Gravatar instead?
	elseif (!empty($profile['avatar']) && $profile['avatar'] === 'gravatar')
	{
		// Gravatars URL.
		$gravatar_url = '//www.gravatar.com/avatar/' . hash('md5', strtolower($profile['email_address'])) . '?s=' . $modSettings['avatar_max_height'] . (!empty($modSettings['gravatar_rating']) ? ('&amp;r=' . $modSettings['gravatar_rating']) : '');

		$avatar = array(
			'name' => $profile['avatar'],
			'image' => '<img class="avatar avatarresize" src="' . $gravatar_url . '" alt="" />',
			'href' => $gravatar_url,
			'url' => $gravatar_url,
		);
	}
	// an avatar from the gallery?
	elseif (!empty($profile['avatar']) && !($avatar_protocol === 'http://' || $avatar_protocol === 'https:/'))
	{
		$avatar = array(
			'name' => $profile['avatar'],
			'image' => '<img class="avatar avatarresize" src="' . $modSettings['avatar_url'] . '/' . $profile['avatar'] . '" alt="" />',
			'href' => $modSettings['avatar_url'] . '/' . $profile['avatar'],
			'url' => $modSettings['avatar_url'] . '/' . $profile['avatar'],
		);
	}
	// no custom avatar found yet, maybe a default avatar?
	elseif (!empty($modSettings['avatar_default']) && empty($profile['avatar']) && empty($profile['filename']))
	{
		// $settings not initialized? We can't do anything further..
		if (empty($settings))
			return array();

		// Let's proceed with the default avatar.
		$avatar = array(
			'name' => '',
			'image' => '<img class="avatar avatarresize" src="' . $settings['images_url'] . '/default_avatar.png" alt="" />',
			'href' => $settings['images_url'] . '/default_avatar.png',
			'url' => 'http://',
		);
	}
	// finally ...
	else
		$avatar = array(
			'name' => '',
			'image' => '',
			'href' => '',
			'url' => ''
		);

	// Make sure there's a preview for gravatars available.
	$avatar['gravatar_preview'] = '//www.gravatar.com/avatar/' . hash('md5', strtolower($profile['email_address'])) . '?s=' . $modSettings['avatar_max_height'] . (!empty($modSettings['gravatar_rating']) ? ('&amp;r=' . $modSettings['gravatar_rating']) : '');

	$GLOBALS['elk']['hooks']->hook('avatar', array(&$avatar));

	return $avatar;
}

function serverIs($server)
{
	switch ($server)
	{
		case 'apache':
			return isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false;
		case 'cgi':
			return isset($_SERVER['SERVER_SOFTWARE']) && strpos(php_sapi_name(), 'cgi') !== false;
		case 'iis':
			return isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'Microsoft-IIS') !== false;
		case 'iso_case_folding':
			return ord(strtolower(chr(138))) === 154;
		case 'lighttpd':
			return isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'lighttpd') !== false;
		case 'litespeed':
			return isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false;
		case 'needs_login_fix':
			return serverIs('cgi') && serverIs('iis');
		case 'nginx':
			return isset($_SERVER['SERVER_SOFTWARE']) && strpos($_SERVER['SERVER_SOFTWARE'], 'nginx') !== false;
		case 'windows':
			return strpos(PHP_OS, 'WIN') === 0;
	}
}

/**
 * Do some important security checks:
 *
 * What it does:
 * - checks the existence of critical files e.g. Install.php
 * - checks for an active Admin session.
 * - checks cache directory is writable.
 * - calls secureDirectory to protect attachments & cache.
 * - checks if the forum is in maintenance mode.
 */
function doSecurityChecks()
{
	global $modSettings, $context, $maintenance, $user_info, $txt, $scripturl, $user_settings, $options;

	$show_warnings = false;

	$cache = $GLOBALS['elk']['cache'];

	if (allowedTo('admin_forum') && !$user_info['is_guest'])
	{
		// If agreement is enabled, at least the english version shall exists
		if ($modSettings['requireAgreement'] && !file_exists(BOARDDIR . '/agreement.txt'))
		{
			$context['security_controls_files']['title'] = $txt['generic_warning'];
			$context['security_controls_files']['errors']['agreement'] = $txt['agreement_missing'];
			$show_warnings = true;
		}

		// Cache directory writable?
		if ($cache->isEnabled() && !is_writable(CACHEDIR))
		{
			$context['security_controls_files']['title'] = $txt['generic_warning'];
			$context['security_controls_files']['errors']['cache'] = $txt['cache_writable'];
			$show_warnings = true;
		}

		if (checkSecurityFiles())
			$show_warnings = true;

		// We are already checking so many files...just few more doesn't make any difference! :P
		$attachments = new \Elkarte\Attachments\Attachments;
		$path = $attachments->getAttachmentPath();
		secureDirectory($path, true);
		secureDirectory(CACHEDIR);

		// Active Admin session?
		if (isAdminSessionActive())
			$context['warning_controls']['admin_session'] = sprintf($txt['admin_session_active'], ($scripturl . '?action=Admin;area=adminlogoff;redir;' . $context['session_var'] . '=' . $context['session_id']));

		// Maintenance mode enabled?
		if (!empty($maintenance))
			$context['warning_controls']['maintenance'] = sprintf($txt['admin_maintenance_active'], ($scripturl . '?action=Admin;area=serversettings;' . $context['session_var'] . '=' . $context['session_id']));

		// New updates
		if (defined('FORUM_VERSION'))
		{
			$index = 'new_in_' . str_replace(array('ElkArte ', '.'), array('', '_'), FORUM_VERSION);
			if (!empty($modSettings[$index]) && empty($options['dismissed_' . $index]))
			{
				$show_warnings = true;
				$context['new_version_updates'] = array(
					'title' => $txt['new_version_updates'],
					'errors' => array(replaceBasicActionUrl($txt['new_version_updates_text'])),
				);
			}
		}
	}

	// Check for database errors.
	if (!empty($_SESSION['query_command_denied']))
	{
		if ($user_info['is_admin'])
		{
			$context['security_controls_query']['title'] = $txt['query_command_denied'];
			$show_warnings = true;
			foreach ($_SESSION['query_command_denied'] as $command => $error)
				$context['security_controls_query']['errors'][$command] = '<pre>' . $GLOBALS['elk']['text']->htmlspecialchars($error) . '</pre>';
		}
		else
		{
			$context['security_controls_query']['title'] = $txt['query_command_denied_guests'];
			foreach ($_SESSION['query_command_denied'] as $command => $error)
				$context['security_controls_query']['errors'][$command] = '<pre>' . sprintf($txt['query_command_denied_guests_msg'], $GLOBALS['elk']['text']->htmlspecialchars($command)) . '</pre>';
		}
	}

	// Are there any members waiting for approval?
	if (allowedTo('moderate_forum') && ((!empty($modSettings['registration_method']) && $modSettings['registration_method'] == 2) || !empty($modSettings['approveAccountDeletion'])) && !empty($modSettings['unapprovedMembers']))
		$context['warning_controls']['unapproved_members'] = sprintf($txt[$modSettings['unapprovedMembers'] == 1 ? 'approve_one_member_waiting' : 'approve_many_members_waiting'], $scripturl . '?action=Admin;area=viewmembers;sa=browse;type=approve', $modSettings['unapprovedMembers']);

	if (!empty($context['open_mod_reports']) && (empty($user_settings['mod_prefs']) || $user_settings['mod_prefs'][0] == 1))
		$context['warning_controls']['open_mod_reports'] = '<a href="' . $scripturl . '?action=moderate;area=reports">' . sprintf($txt['mod_reports_waiting'], $context['open_mod_reports']) . '</a>';

	if (isset($_SESSION['ban']['cannot_post']))
	{
		// An Admin cannot be banned (technically he could), and if it is better he knows.
		$context['security_controls_ban']['title'] = sprintf($txt['you_are_post_banned'], $user_info['is_guest'] ? $txt['guest_title'] : $user_info['name']);
		$show_warnings = true;

		$context['security_controls_ban']['errors']['reason'] = '';

		if (!empty($_SESSION['ban']['cannot_post']['reason']))
			$context['security_controls_ban']['errors']['reason'] = $_SESSION['ban']['cannot_post']['reason'];

		if (!empty($_SESSION['ban']['expire_time']))
			$context['security_controls_ban']['errors']['reason'] .= '<span class="smalltext">' . sprintf($txt['your_ban_expires'], standardTime($_SESSION['ban']['expire_time'], false)) . '</span>';
		else
			$context['security_controls_ban']['errors']['reason'] .= '<span class="smalltext">' . $txt['your_ban_expires_never'] . '</span>';
	}

	// Finally, let's show the layer.
	if ($show_warnings || !empty($context['warning_controls']))
		$GLOBALS['elk']['layers']->addAfter('admin_warning', 'body');
}

/**
 * Load everything necessary for the BBC parsers
 */
function loadBBCParsers()
{
	global $modSettings;

	// Set the default disabled BBC
	if (!empty($modSettings['disabledBBC']))
	{
		$GLOBALS['elk']['bbc']->setDisabled($modSettings['disabledBBC']);
	}
}

function loadLoadAverage()
{
	global $modSettings, $context;

	$cache = $GLOBALS['elk']['cache'];

	if (($context['load_average'] = $cache->get('loadavg', 90)) == null)
	{
		require_once(ELKDIR . '/Server/Server.subs.php');
		$context['load_average'] = detectServerLoad();

		$cache->put('loadavg', $context['load_average'], 90);
	}

	if ($context['load_average'] !== false)
		$GLOBALS['elk']['hooks']->hook('load_average', array($context['load_average']));

	// Let's have at least a zero
	if (empty($modSettings['loadavg_forum']) || $context['load_average'] === false)
		$context['current_load'] = 0;
	else
		$context['current_load'] = $context['load_average'];

	if (checkLoad('forum'))
		$GLOBALS['elk']['errors']->display_loadavg_error();
}

/**
 * Check if the load is higher than a threshold
 *
 * @param string $setting Part of the $modSettings key "loadavg_$setting"
 * @return bool If the current load is over the threshold
 */
function checkLoad($setting)
{
	return !empty($context['current_load'])
		&& !empty($modSettings['loadavg_' . $setting])
		&& $context['current_load'] >= $modSettings['loadavg_' . $setting];
}

