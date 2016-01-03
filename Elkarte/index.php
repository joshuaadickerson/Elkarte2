<?php

/**
 * This, as you have probably guessed, is the crux for all functions.
 * Everything should start here, so all the setup and security is done
 * properly.
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

$time_start = $_SERVER['REQUEST_TIME_FLOAT'];

// The software version
const FORUM_VERSION = 'ElkArte 1.1';

// First things first, but not necessarily in that order.
const ELK = '1';

// Shortcut for the browser cache stale
const CACHE_STALE = '?R11';

// Report errors but not deprecated ones
// @todo before release turn this back or maybe we should just let the environment decide what to do?
//error_reporting(E_ALL | E_STRICT & ~8192);
error_reporting(E_ALL);

// Directional only script time usage for display
if (function_exists('getrusage'))
	$rusage_start = getrusage();
else
	$rusage_start = array();

// Turn on output buffering if it isn't already on (via php.ini for example)
if (!ob_get_level())
	ob_start();

$db_show_debug = false;

// Where the Settings.php file is located
$settings_loc = '../Settings.php';

// First thing: if the Install dir exists, just send anybody there
if (file_exists('Install'))
{
	if (file_exists($settings_loc))
	{
		require_once($settings_loc);
	}

	// The ignore_install_dir var is for developers only. Do not add it on production sites
	if (empty($GLOBALS['ignore_install_dir']))
	{
		header('Location: http' . (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) == 'on' ? 's' : '') . '://' . (empty($_SERVER['HTTP_HOST']) ? $_SERVER['SERVER_NAME'] . (empty($_SERVER['SERVER_PORT']) || $_SERVER['SERVER_PORT'] == '80' ? '' : ':' . $_SERVER['SERVER_PORT']) : $_SERVER['HTTP_HOST']) . (strtr(dirname($_SERVER['PHP_SELF']), '\\', '/') == '/' ? '' : strtr(dirname($_SERVER['PHP_SELF']), '\\', '/')) . '/Install/Install.php');
		die;
	}
}
else
{
	require_once($settings_loc);
}

// Make sure the paths are correct... at least try to fix them.
if (!file_exists($boarddir) && file_exists('agreement.txt'))
	$boarddir = __DIR__;
if (!file_exists($sourcedir . '/SiteDispatcher.php') && file_exists($boarddir . '/Sources'))
	$sourcedir = $boarddir . '/Sources';

// Check that directories which didn't exist in past releases are initialized.
if ((empty($cachedir) || !file_exists($cachedir)) && file_exists($boarddir . '/cache'))
	$cachedir = $boarddir . '/cache';
if ((empty($extdir) || !file_exists($extdir)) && file_exists($sourcedir . '/ext'))
	$extdir = $sourcedir . '/ext';
if ((empty($languagedir) || !file_exists($languagedir)) && file_exists($boarddir . '/themes/default/languages'))
	$languagedir = $boarddir . '/themes/default/languages';

// Time to forget about variables and go with constants!
DEFINE('ROOTDIR', __DIR__ . '/src');
DEFINE('ELKDIR', ROOTDIR . '/Elkarte');
DEFINE('BOARDDIR', $boarddir);
DEFINE('CACHEDIR', $cachedir);
DEFINE('EXTDIR', $extdir);
DEFINE('LANGUAGEDIR', $languagedir);
DEFINE('SOURCEDIR', $sourcedir);
DEFINE('ADMINDIR', $sourcedir . '/admin');
DEFINE('CONTROLLERDIR', $sourcedir . '/Controllers');
DEFINE('SUBSDIR', $sourcedir . '/subs');
DEFINE('ADDONSDIR', $boarddir . '/addons');
DEFINE('VENDORDIR', $vendordir . '/vendor');
unset($boarddir, $cachedir, $sourcedir, $languagedir, $extdir, $vendordir);

// Files we cannot live without.
require_once(__DIR__ . '/src/Elkarte/Subs.php');
require_once(__DIR__ . '/src/Elkarte/Load.php');
require_once(__DIR__ . '/src/Elkarte/Log/Logging.php');
require_once(__DIR__ . '/src/Elkarte/Security/Security.php');
require_once(__DIR__ . '/src/Elkarte/Cache/Cache.subs.php');

require_once(VENDORDIR . '/autoload.php');
require_once(__DIR__ . '/src/Elkarte/Services.php');


// Show lots of debug information below the page, not for production sites
if ($db_show_debug === true)
	$elk['debug']->rusage('start', $rusage_start);

// Forum in extended maintenance mode? Our trip ends here with a bland message.
if (!empty($maintenance) && $maintenance == 2)
	$elk['errors']->display_maintenance_message();

// Clean the request.
$elk['req']->cleanRequest()->parseRequest();

// Initiate the database connection and define some database functions to use.
if (ELK === 'SSI' && !empty($ssi_db_user) && !empty($ssi_db_passwd))
	loadDatabase($db_persist, $db_server, $ssi_db_user, $ssi_db_passwd, $db_port, $db_type, $db_name, $db_prefix);
else
	loadDatabase($db_persist, $db_server, $db_user, $db_passwd, $db_port, $db_type, $db_name, $db_prefix);

// Let's set up out shiny new hooks handler.
//Hooks::init($elk['db'], $elk['debug']);

// It's time for settings loaded from the database.
reloadSettings();

// Our good ole' contextual array, which will hold everything
$context = array();

// Seed the random generator.
elk_seed_generator();

// Before we get carried away, are we doing a scheduled task? If so save CPU cycles by jumping out!
if (isset($_GET['scheduled']))
{
	// Don't make people wait on us if we can help it.
	if (function_exists('fastcgi_finish_request'))
		fastcgi_finish_request();

	$controller = new ScheduledTasksController();
	$controller->action_autotask();
}

// Check if compressed output is enabled, supported, and not already being done.
if (!empty($modSettings['enableCompressedOutput']) && !headers_sent())
{
	// If zlib is being used, turn off output compression.
	if (ini_get('zlib.output_compression') >= 1 || ini_get('output_handler') == 'ob_gzhandler')
		$modSettings['enableCompressedOutput'] = 0;
	else
	{
		@ob_end_clean();
		ob_start('ob_gzhandler');
	}
}

// Register error & exception handlers.
$elk['errors']->register_handlers();

// Start the session. (assuming it hasn't already been.)
$elk['session']->load();

// Restore post data if we are revalidating OpenID.
if (isset($_GET['openid_restore_post']) && !empty($_SESSION['openid']['saved_data'][$_GET['openid_restore_post']]['post']) && empty($_POST))
{
	$_POST = $_SESSION['openid']['saved_data'][$_GET['openid_restore_post']]['post'];
	unset($_SESSION['openid']['saved_data'][$_GET['openid_restore_post']]);
}

// Pre-dispatch
elk_main($elk);

// Call obExit specially; we're coming from the main area ;).
obExit(null, null, true);

/**
 * The main dispatcher.
 * This delegates to each area.
 */
function elk_main(\Pimple\Container $elk)
{
	global $modSettings, $user_info, $topic, $board_info, $context, $maintenance;

	// Special case: session keep-alive, output a transparent pixel.
	if ($elk['http_req']->getQuery('action') === 'keepalive') {
		header('Content-Type: image/gif');
		die("\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B");
	}

	// We should set our security headers now.
	frameOptionsHeader();
	securityOptionsHeader();

	// Load the user's cookie (or set as guest) and load their settings.
	loadUserSettings();

	// Load the current board's information.
	loadBoard();

	// Load the current user's permissions.
	loadPermissions();

	// Load BadBehavior before we go much further
	loadBadBehavior();

	// Attachments don't require the entire theme to be loaded.
	if ($elk['http_req']->getQuery('action') === 'dlattach' && (!empty($modSettings['allow_guestAccess']) && $user_info['is_guest']) && (empty($maintenance) || allowedTo('admin_forum')))
	{
		$elk['browser'];
	}
	// Load the current theme.  (note that ?theme=1 will also work, may be used for guest theming.)
	else
		loadTheme();

	// The parser is not a DIC just yet
	loadBBCParsers();

	// Check if the user should be disallowed access.
	$elk['ban_check']->isNotBanned();

	// If we are in a topic and don't have permission to approve it then duck out now.
	if (!empty($topic) && empty($board_info['cur_topic_approved']) && !allowedTo('approve_posts') && ($user_info['id'] != $board_info['cur_topic_starter'] || $user_info['is_guest']))
		$elk['errors']->fatal_lang_error('not_a_topic', false);

	$no_stat_actions = array('dlattach', 'jsoption', 'requestmembers', 'jslocale', 'xmlpreview', 'suggest', '.xml', 'xmlhttp', 'verificationcode', 'viewquery', 'viewadminfile');
	$elk['hooks']->hook('pre_log_stats', array(&$no_stat_actions));

	// Do some logging, unless this is an attachment, avatar, toggle of editor buttons, theme option, XML feed etc.
	if (empty($_REQUEST['action']) || !in_array($_REQUEST['action'], $no_stat_actions)
		|| (!empty($_REQUEST['sa']) && !in_array($_REQUEST['sa'], $no_stat_actions)))
	{
		// I see you!
		writeLog();

		// Track forum statistics and hits...?
		if (!empty($modSettings['hitStats']))
			trackStats(array('hits' => '+'));
	}
	unset($no_stat_actions);

	// What shall we do?
	$dispatcher = $elk['dispatcher'];

	// Show where we came from, and go
	$context['site_action'] = $dispatcher->site_action();
	$context['site_action'] = !empty($context['site_action']) ? $context['site_action'] : $elk['http_req']->getQuery('action', 'trim|strval', '');
	$dispatcher->dispatch();
}