<?php

/**
 * This file has the very important job of ensuring forum security.
 * This task includes banning and permissions, namely.
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

use Elkarte\Elkarte\TokenHash;

/**
 * Require a user who is logged in. (not a guest.)
 *
 * What it does:
 * - Checks if the user is currently a guest, and if so asks them to login with a message telling them why.
 * - Message is what to tell them when asking them to login.
 *
 * @param string $message = ''
 * @param boolean $is_fatal = true
 * @return bool
 */
function is_not_guest($message = '', $is_fatal = true)
{
	global $user_info, $txt, $context, $scripturl;

	// Luckily, this person isn't a guest.
	if (isset($user_info['is_guest']) && !$user_info['is_guest'])
		return true;

	// People always worry when they see people doing things they aren't actually doing...
	$_GET['action'] = '';
	$_GET['board'] = '';
	$_GET['topic'] = '';
	writeLog(true);

	// Just die.
	if (isset($_REQUEST['xml']) || !$is_fatal)
		obExit(false);

	// Attempt to detect if they came from dlattach.
	if (ELK != 'SSI' && empty($context['theme_loaded']))
		loadTheme();

	// Never redirect to an attachment
	if (strpos($_SERVER['REQUEST_URL'], 'dlattach') === false)
		$_SESSION['login_url'] = $_SERVER['REQUEST_URL'];

	// Load the Login template and language file.
	loadLanguage('Login');

	// Apparently we're not in a position to handle this now. Let's go to a safer location for now.
	if (!$GLOBALS['elk']['layers']->hasLayers())
	{
		$_SESSION['login_url'] = $scripturl . '?' . $_SERVER['QUERY_STRING'];
		redirectexit('action=login');
	}
	elseif (isset($_GET['api']))
		return false;
	else
	{
		$GLOBALS['elk']['templates']->load('Login');
		loadJavascriptFile('sha256.js', array('defer' => true));
		$context['sub_template'] = 'kick_guest';
		$context['robot_no_index'] = true;
	}

	// Use the kick_guest sub template...
	$context['kick_message'] = $message;
	$context['page_title'] = $txt['login'];

	obExit();

	// We should never get to this point, but if we did we wouldn't know the user isn't a guest.
	trigger_error('Hacking attempt...', E_USER_ERROR);
}

/**
 * Lets give you a token of our appreciation.
 *
 * @param string $action The specific site action that a token will be generated for
 * @param string $type = 'post' If the token will be returned via post or get
 * @return string[] array of token var, time, csrf, token
 */
function createToken($action, $type = 'post')
{
	global $context;

	// Generate a new token token_var pair
	$tokenizer = new TokenHash();
	$token_var = $tokenizer->generate_hash(rand(7, 12));
	$token = $tokenizer->generate_hash(32);

	// We need user agent and the client IP
	$req = $GLOBALS['elk']['req'];
	$csrf_hash = hash('sha1', $token . $req->client_ip() . $req->user_agent());

	// Save the session token and make it available to the forms
	$_SESSION['token'][$type . '-' . $action] = array($token_var, $csrf_hash, time(), $token);
	$context[$action . '_token'] = $token;
	$context[$action . '_token_var'] = $token_var;

	return array($action . '_token_var' => $token_var, $action . '_token' => $token);
}

/**
 * Only patrons with valid tokens can ride this ride.
 *
 * @param string $action
 * @param string $type = 'post' (get, request, or post)
 * @param bool $reset = true Reset the token on failure
 * @param bool $fatal if true a fatal_lang_error is issued for invalid tokens, otherwise false is returned
 * @return boolean except for $action == 'login' where the token is returned
 */
function validateToken($action, $type = 'post', $reset = true, $fatal = true)
{
	$type = ($type === 'get' || $type === 'request') ? $type : 'post';
	$token_index = $type . '-' . $action;

	// Logins are special: the token is used to have the password with javascript before POST it
	if ($action == 'login')
	{
		if (isset($_SESSION['token'][$token_index]))
		{
			$return = $_SESSION['token'][$token_index][3];
			unset($_SESSION['token'][$token_index]);

			return $return;
		}
		else
			return '';
	}

	if (!isset($_SESSION['token'][$token_index]))
		return false;

	// This code validates a supplied token.
	// 1. The token exists in session.
	// 2. The {$type} variable should exist.
	// 3. We concatenate the variable we received with the user agent
	// 4. Match that result against what is in the session.
	// 5. If it matches, success, otherwise we fallout.

	// We need the user agent and client IP
	$req = $GLOBALS['elk']['req'];

	// Shortcut
	$passed_token_var = isset($GLOBALS['_' . strtoupper($type)][$_SESSION['token'][$token_index][0]]) ? $GLOBALS['_' . strtoupper($type)][$_SESSION['token'][$token_index][0]] : null;
	$csrf_hash = hash('sha1', $passed_token_var . $req->client_ip() . $req->user_agent());

	// Checked what was passed in combination with the user agent
	if (isset($_SESSION['token'][$token_index], $passed_token_var)
		&& $csrf_hash === $_SESSION['token'][$token_index][1])
	{
		// Consume the token, let them pass
		unset($_SESSION['token'][$token_index]);

		return true;
	}

	// Patrons with invalid tokens get the boot.
	if ($reset)
	{
		// Might as well do some cleanup on this.
		cleanTokens();

		// I'm back baby.
		createToken($action, $type);

		if ($fatal)
			$GLOBALS['elk']['errors']->fatal_lang_error('token_verify_fail', false);
	}
	// You don't get a new token
	else
	{
		// Explicitly remove this token
		unset($_SESSION['token'][$token_index]);

		// Remove older tokens.
		cleanTokens();
	}

	return false;
}

/**
 * Removes old unused tokens from session
 *
 * What it does:
 * - defaults to 3 hours before a token is considered expired
 * - if $complete = true will remove all tokens
 *
 * @param bool $complete = false
 * @param string $suffix = false
 */
function cleanTokens($complete = false, $suffix = '')
{
	// We appreciate cleaning up after yourselves.
	if (!isset($_SESSION['token']))
		return;

	// Clean up tokens, trying to give enough time still.
	foreach ($_SESSION['token'] as $key => $data)
	{
		if (!empty($suffix))
			$force = $complete || strpos($key, $suffix);
		else
			$force = $complete;

		if ($data[2] + 10800 < time() || $force)
			unset($_SESSION['token'][$key]);
	}
}

/**
 * Check whether a form has been submitted twice.
 *
 * What it does:
 * - Registers a sequence number for a form.
 * - Checks whether a submitted sequence number is registered in the current session.
 * - Depending on the value of is_fatal shows an error or returns true or false.
 * - Frees a sequence number from the stack after it's been checked.
 * - Frees a sequence number without checking if action == 'free'.
 *
 * @param string $action
 * @param bool $is_fatal = true
 * @return bool
 */
function checkSubmitOnce($action, $is_fatal = false)
{
	global $context;

	if (!isset($_SESSION['forms']))
		$_SESSION['forms'] = array();

	// Register a form number and store it in the session stack. (use this on the page that has the form.)
	if ($action == 'register')
	{
		$tokenizer = new \Elkarte\Elkarte\TokenHash();
		$context['form_sequence_number'] = '';
		while (empty($context['form_sequence_number']) || in_array($context['form_sequence_number'], $_SESSION['forms']))
			$context['form_sequence_number'] = $tokenizer->generate_hash();
	}
	// Check whether the submitted number can be found in the session.
	elseif ($action == 'check')
	{
		if (!isset($_REQUEST['seqnum']))
			return true;
		elseif (!in_array($_REQUEST['seqnum'], $_SESSION['forms']))
		{
			// Mark this one as used
			$_SESSION['forms'][] = (string) $_REQUEST['seqnum'];
			return true;
		}
		elseif ($is_fatal)
			$GLOBALS['elk']['errors']->fatal_lang_error('error_form_already_submitted', false);
		else
			return false;
	}
	// Don't check, just free the stack number.
	elseif ($action == 'free' && isset($_REQUEST['seqnum']) && in_array($_REQUEST['seqnum'], $_SESSION['forms']))
		$_SESSION['forms'] = array_diff($_SESSION['forms'], array($_REQUEST['seqnum']));
	elseif ($action != 'free')
		trigger_error('checkSubmitOnce(): Invalid action \'' . $action . '\'', E_USER_WARNING);
}

/**
 * This function checks whether the user is allowed to do permission. (ie. post_new.)
 *
 * What it does:
 * - If boards parameter is specified, checks those boards instead of the current one (if applicable).
 * - Always returns true if the user is an administrator.
 *
 * @param string[]|string $permission permission
 * @param int[]|int|null $boards array of board IDs, a single id or null
 * @return boolean if the user can do the permission
 */
function allowedTo($permission, $boards = null)
{
	global $user_info;

	$db = $GLOBALS['elk']['db'];

	// You're always allowed to do nothing. (unless you're a working man, MR. LAZY :P!)
	if (empty($permission))
		return true;

	// You're never allowed to do something if your data hasn't been loaded yet!
	if (empty($user_info))
		return false;

	// Administrators are supermen :P.
	if ($user_info['is_admin'])
		return true;

	// Make sure permission is a valid array
	if (!is_array($permission))
		$permission = array($permission);

	// Are we checking the _current_ board, or some other boards?
	if ($boards === null)
	{
		if (empty($user_info['permissions']))
			return false;

		// Check if they can do it, you aren't allowed, by default.
		return count(array_intersect($permission, $user_info['permissions'])) !== 0 ? true : false;
	}

	if (!is_array($boards))
		$boards = array($boards);

	if (empty($user_info['groups']))
		return false;

	$request = $db->query('', '
		SELECT MIN(bp.add_deny) AS add_deny
		FROM {db_prefix}boards AS b
			INNER JOIN {db_prefix}board_permissions AS bp ON (bp.id_profile = b.id_profile)
			LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = b.id_board AND mods.id_member = {int:current_member})
		WHERE b.id_board IN ({array_int:board_list})
			AND bp.id_group IN ({array_int:group_list}, {int:moderator_group})
			AND bp.permission IN ({array_string:permission_list})
			AND (mods.id_member IS NOT NULL OR bp.id_group != {int:moderator_group})
		GROUP BY b.id_board',
		array(
			'current_member' => $user_info['id'],
			'board_list' => $boards,
			'group_list' => $user_info['groups'],
			'moderator_group' => 3,
			'permission_list' => $permission,
		)
	);

	// Make sure they can do it on all of the boards.
	if ($request->numRows() != count($boards))
		return false;

	$result = true;
	while ($row = $request->fetchAssoc())
		$result &= !empty($row['add_deny']);
	$request->free();

	// If the query returned 1, they can do it... otherwise, they can't.
	return $result;
}

/**
 * This function returns fatal error if the user doesn't have the respective permission.
 *
 * What it does:
 * - Uses allowedTo() to check if the user is allowed to do permission.
 * - Checks the passed boards or current board for the permission.
 * - If they are not, it loads the Errors language file and shows an error using $txt['cannot_' . $permission].
 * - If they are a guest and cannot do it, this calls is_not_guest().
 *
 * @param string[]|string $permission array of or single string, of permissions to check
 * @param int[]|null $boards = null
 */
function isAllowedTo($permission, $boards = null)
{
	global $user_info, $txt;

	static $heavy_permissions = array(
		'admin_forum',
		'manage_attachments',
		'manage_smileys',
		'manage_boards',
		'edit_news',
		'moderate_forum',
		'manage_bans',
		'manage_membergroups',
		'manage_permissions',
	);

	// Make it an array, even if a string was passed.
	$permission = is_array($permission) ? $permission : array($permission);

	// Check the permission and return an error...
	if (!allowedTo($permission, $boards))
	{
		// Pick the last array entry as the permission shown as the error.
		$error_permission = array_shift($permission);

		// If they are a guest, show a login. (because the error might be gone if they do!)
		if ($user_info['is_guest'])
		{
			loadLanguage('Errors');
			is_not_guest($txt['cannot_' . $error_permission]);
		}

		// Clear the action because they aren't really doing that!
		$_GET['action'] = '';
		$_GET['board'] = '';
		$_GET['topic'] = '';
		writeLog(true);

		$GLOBALS['elk']['errors']->fatal_lang_error('cannot_' . $error_permission, false);

		// Getting this far is a really big problem, but let's try our best to prevent any cases...
		trigger_error('Hacking attempt...', E_USER_ERROR);
	}

	// If you're doing something on behalf of some "heavy" permissions, validate your session.
	// (take out the heavy permissions, and if you can't do anything but those, you need a validated session.)
	if (!allowedTo(array_diff($permission, $heavy_permissions), $boards))
		validateSession();
}

/**
 * Return the boards a user has a certain (board) permission on. (array(0) if all.)
 *
 * What it does:
 * - returns a list of boards on which the user is allowed to do the specified permission.
 * - returns an array with only a 0 in it if the user has permission to do this on every board.
 * - returns an empty array if he or she cannot do this on any board.
 * - If check_access is true will also make sure the group has proper access to that board.
 *
 * @param string[]|string $permissions array of permission names to check access against
 * @param bool $check_access = true
 * @param bool $simple = true
 */
function boardsAllowedTo($permissions, $check_access = true, $simple = true)
{
	global $user_info;

	$db = $GLOBALS['elk']['db'];

	// Arrays are nice, most of the time.
	if (!is_array($permissions))
		$permissions = array($permissions);

	/*
	 * Set $simple to true to use this function in compatibility mode
	 * Otherwise, the resultant array becomes split into the multiple
	 * permissions that were passed. Other than that, it's just the normal
	 * state of play that you're used to.
	 */

	// I am the master, the master of the universe!
	if ($user_info['is_admin'])
	{
		if ($simple)
			return array(0);
		else
		{
			$boards = array();
			foreach ($permissions as $permission)
				$boards[$permission] = array(0);

			return $boards;
		}
	}

	// All groups the user is in except 'moderator'.
	$groups = array_diff($user_info['groups'], array(3));

	$request = $db->query('', '
		SELECT b.id_board, bp.add_deny' . ($simple ? '' : ', bp.permission') . '
		FROM {db_prefix}board_permissions AS bp
			INNER JOIN {db_prefix}boards AS b ON (b.id_profile = bp.id_profile)
			LEFT JOIN {db_prefix}moderators AS mods ON (mods.id_board = b.id_board AND mods.id_member = {int:current_member})
		WHERE bp.id_group IN ({array_int:group_list}, {int:moderator_group})
			AND bp.permission IN ({array_string:permissions})
			AND (mods.id_member IS NOT NULL OR bp.id_group != {int:moderator_group})' .
			($check_access ? ' AND {query_see_board}' : ''),
		array(
			'current_member' => $user_info['id'],
			'group_list' => $groups,
			'moderator_group' => 3,
			'permissions' => $permissions,
		)
	);
	$boards = array();
	$deny_boards = array();
	while ($row = $request->fetchAssoc())
	{
		if ($simple)
		{
			if (empty($row['add_deny']))
				$deny_boards[] = $row['id_board'];
			else
				$boards[] = $row['id_board'];
		}
		else
		{
			if (empty($row['add_deny']))
				$deny_boards[$row['permission']][] = $row['id_board'];
			else
				$boards[$row['permission']][] = $row['id_board'];
		}
	}
	$request->free();

	if ($simple)
		$boards = array_unique(array_values(array_diff($boards, $deny_boards)));
	else
	{
		foreach ($permissions as $permission)
		{
			// Never had it to start with
			if (empty($boards[$permission]))
				$boards[$permission] = array();
			else
			{
				// Or it may have been removed
				$deny_boards[$permission] = isset($deny_boards[$permission]) ? $deny_boards[$permission] : array();
				$boards[$permission] = array_unique(array_values(array_diff($boards[$permission], $deny_boards[$permission])));
			}
		}
	}

	return $boards;
}

/**
 * Returns whether an email address should be shown and how.
 *
 * Possible outcomes are:
 * - 'yes': show the full email address
 * - 'yes_permission_override': show the full email address, either you
 * are a moderator or it's your own email address.
 * - 'no_through_forum': don't show the email address, but do allow
 * things to be mailed using the built-in forum mailer.
 * - 'no': keep the email address hidden.
 *
 * @param bool $userProfile_hideEmail
 * @param int $userProfile_id
 * @return string (yes, yes_permission_override, no_through_forum, no)
 */
function showEmailAddress($userProfile_hideEmail, $userProfile_id)
{
	global $user_info;

	// Should this user's email address be shown?
	// If you're guest: no.
	// If the user is post-banned: no.
	// If it's your own profile and you've not set your address hidden: yes_permission_override.
	// If you're a moderator with sufficient permissions: yes_permission_override.
	// If the user has set their profile to do not email me: no.
	// Otherwise: no_through_forum. (don't show it but allow emailing the member)

	if ($user_info['is_guest'] || isset($_SESSION['ban']['cannot_post']))
		return 'no';
	elseif ((!$user_info['is_guest'] && $user_info['id'] == $userProfile_id && !$userProfile_hideEmail))
		return 'yes_permission_override';
	elseif (allowedTo('moderate_forum'))
		return 'yes_permission_override';
	elseif ($userProfile_hideEmail)
		return 'no';
	else
		return 'no_through_forum';
}

/**
 * This function attempts to protect from carrying out specific actions repeatedly.
 *
 * What it does:
 * - Checks if a user is trying specific actions faster than a given minimum wait threshold.
 * - The time taken depends on error_type - generally uses the modSetting.
 * - Generates a fatal message when triggered, suspending execution.
 *
 * @param string $error_type used also as a $txt index. (not an actual string.)
 * @param boolean $fatal is the spam check a fatal error on failure
 */
function spamProtection($error_type, $fatal = true)
{
	global $modSettings, $user_info;

	$db = $GLOBALS['elk']['db'];

	// Certain types take less/more time.
	$timeOverrides = array(
		'login' => 2,
		'register' => 2,
		'remind' => 30,
		'contact' => 30,
		'sendtopic' => $modSettings['spamWaitTime'] * 4,
		'sendmail' => $modSettings['spamWaitTime'] * 5,
		'reporttm' => $modSettings['spamWaitTime'] * 4,
		'search' => !empty($modSettings['search_floodcontrol_time']) ? $modSettings['search_floodcontrol_time'] : 1,
	);
	$GLOBALS['elk']['hooks']->hook('spam_protection', array(&$timeOverrides));

	// Moderators are free...
	if (!allowedTo('moderate_board'))
		$timeLimit = isset($timeOverrides[$error_type]) ? $timeOverrides[$error_type] : $modSettings['spamWaitTime'];
	else
		$timeLimit = 2;

	// Delete old entries...
	$db->query('', '
		DELETE FROM {db_prefix}log_floodcontrol
		WHERE log_time < {int:log_time}
			AND log_type = {string:log_type}',
		array(
			'log_time' => time() - $timeLimit,
			'log_type' => $error_type,
		)
	);

	// Add a new entry, deleting the old if necessary.
	$db->insert('replace',
		'{db_prefix}log_floodcontrol',
		array('ip' => 'string-16', 'log_time' => 'int', 'log_type' => 'string'),
		array($user_info['ip'], time(), $error_type),
		array('ip', 'log_type')
	);

	// If affected is 0 or 2, it was there already.
	if ($db->affected_rows() != 1)
	{
		// Spammer!  You only have to wait a *few* seconds!
		if ($fatal)
		{
			$GLOBALS['elk']['errors']->fatal_lang_error($error_type . '_WaitTime_broken', false, array($timeLimit));
			return true;
		}
		else
			return $timeLimit;
	}

	// They haven't posted within the limit.
	return false;
}

/**
 * A generic function to create a pair of index.php and .htaccess files in a directory
 *
 * @param string $path the (absolute) directory path
 * @param boolean $attachments if the directory is an attachments directory or not
 * @return string|boolean on success error string if anything fails
 */
function secureDirectory($path, $attachments = false)
{
	if (empty($path))
		return 'empty_path';

	if (!is_writable($path))
		return 'path_not_writable';

	$directoryname = basename($path);

	$errors = array();
	$close = empty($attachments) ? '
</Files>' : '
	Allow from localhost
</Files>

RemoveHandler .php .php3 .phtml .cgi .fcgi .pl .fpl .shtml';

	if (file_exists($path . '/.htaccess'))
		$errors[] = 'htaccess_exists';
	else
	{
		$fh = @fopen($path . '/.htaccess', 'w');
		if ($fh)
		{
			fwrite($fh, '<Files *>
	Order Deny,Allow
	Deny from all' . $close);
			fclose($fh);
		}
		$errors[] = 'htaccess_cannot_create_file';
	}

	if (file_exists($path . '/index.php'))
		$errors[] = 'index-php_exists';
	else
	{
		$fh = @fopen($path . '/index.php', 'w');
		if ($fh)
		{
			fwrite($fh, '<?php

/**
 * This file is here solely to protect your ' . $directoryname . ' directory.
 */

// Look for Settings.php....
if (file_exists(dirname(dirname(__FILE__)) . \'/Settings.php\'))
{
	// Found it!
	require(dirname(dirname(__FILE__)) . \'/Settings.php\');
	header(\'Location: \' . $boardurl);
}
// Can\'t find it... just forget it.
else
	exit;');
			fclose($fh);
		}
		$errors[] = 'index-php_cannot_create_file';
	}

	if (!empty($errors))
		return $errors;
	else
		return true;
}

/**
 * Helper function that puts together a ban query for a given ip
 *
 * - Builds the query for ipv6, ipv4 or 255.255.255.255 depending on whats supplied
 *
 * @param string $fullip An IP address either IPv6 or not
 * @return string A SQL condition
 */
function constructBanQueryIP($fullip)
{
	// First attempt a IPv6 address.
	if (isValidIPv6($fullip))
	{
		$ip_parts = convertIPv6toInts($fullip);

		$ban_query = '((' . $ip_parts[0] . ' BETWEEN bi.ip_low1 AND bi.ip_high1)
			AND (' . $ip_parts[1] . ' BETWEEN bi.ip_low2 AND bi.ip_high2)
			AND (' . $ip_parts[2] . ' BETWEEN bi.ip_low3 AND bi.ip_high3)
			AND (' . $ip_parts[3] . ' BETWEEN bi.ip_low4 AND bi.ip_high4)
			AND (' . $ip_parts[4] . ' BETWEEN bi.ip_low5 AND bi.ip_high5)
			AND (' . $ip_parts[5] . ' BETWEEN bi.ip_low6 AND bi.ip_high6)
			AND (' . $ip_parts[6] . ' BETWEEN bi.ip_low7 AND bi.ip_high7)
			AND (' . $ip_parts[7] . ' BETWEEN bi.ip_low8 AND bi.ip_high8))';
	}
	// Check if we have a valid IPv4 address.
	elseif (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $fullip, $ip_parts) == 1)
		$ban_query = '((' . $ip_parts[1] . ' BETWEEN bi.ip_low1 AND bi.ip_high1)
			AND (' . $ip_parts[2] . ' BETWEEN bi.ip_low2 AND bi.ip_high2)
			AND (' . $ip_parts[3] . ' BETWEEN bi.ip_low3 AND bi.ip_high3)
			AND (' . $ip_parts[4] . ' BETWEEN bi.ip_low4 AND bi.ip_high4))';
	// We use '255.255.255.255' for 'unknown' since it's not valid anyway.
	else
		$ban_query = '(bi.ip_low1 = 255 AND bi.ip_high1 = 255
			AND bi.ip_low2 = 255 AND bi.ip_high2 = 255
			AND bi.ip_low3 = 255 AND bi.ip_high3 = 255
			AND bi.ip_low4 = 255 AND bi.ip_high4 = 255)';

	return $ban_query;
}

/**
 * Decide if we are going to enable bad behavior scanning for this user
 *
 * What it does:
 * - Admins and Moderators get a free pass
 * - Optionally existing users with post counts over a limit are bypassed
 * - Others get a humane frisking
 */
function loadBadBehavior()
{
	global $modSettings, $user_info, $bb2_results;

	// Bad Behavior Enabled?
	if (!empty($modSettings['badbehavior_enabled']))
	{
		require_once(EXTDIR . '/bad-behavior/badbehavior-plugin.php');
		$bb_run = true;

		// We may want to give some folks a hallway pass
		if (!$user_info['is_guest'])
		{
			if (!empty($user_info['is_moderator']) || !empty($user_info['is_admin']))
				$bb_run = false;
			elseif (!empty($modSettings['badbehavior_postcount_wl']) && $modSettings['badbehavior_postcount_wl'] < 0)
				$bb_run = false;
			elseif (!empty($modSettings['badbehavior_postcount_wl']) && $modSettings['badbehavior_postcount_wl'] > 0 && ($user_info['posts'] > $modSettings['badbehavior_postcount_wl']))
				$bb_run = false;
		}

		// Put on the sanitary gloves, its time for a patdown !
		if ($bb_run === true)
		{
			$bb2_results = bb2_start(bb2_read_settings());
			theme()->addInlineJavascript(bb2_insert_head());
		}
	}
}

/**
 * This protects against brute force attacks on a member's password.
 *
 * - Importantly, even if the password was right we DON'T TELL THEM!
 *
 * @param int $id_member
 * @param string|false $password_flood_value = false or string joined on |'s
 * @param boolean $was_correct = false
 */
function validatePasswordFlood($id_member, $password_flood_value = false, $was_correct = false)
{
	global $cookiename;

	// As this is only brute protection, we allow 5 attempts every 10 seconds.

	// Destroy any session or cookie data about this member, as they validated wrong.
	require_once(SUBSDIR . '/Auth.subs.php');
	setLoginCookie(-3600, 0);

	if (isset($_SESSION['login_' . $cookiename]))
		unset($_SESSION['login_' . $cookiename]);

	// We need a member!
	if (!$id_member)
	{
		// Redirect back!
		redirectexit();

		// Probably not needed, but still make sure...
		$GLOBALS['elk']['errors']->fatal_lang_error('no_access', false);
	}

	// Let's just initialize to something (and 0 is better than nothing)
	$time_stamp = 0;
	$number_tries = 0;

	// Right, have we got a flood value?
	if ($password_flood_value !== false)
		@list ($time_stamp, $number_tries) = explode('|', $password_flood_value);

	// Timestamp invalid or non-existent?
	if (empty($number_tries) || $time_stamp < (time() - 10))
	{
		// If it wasn't *that* long ago, don't give them another five goes.
		$number_tries = !empty($number_tries) && $time_stamp < (time() - 20) ? 2 : $number_tries;
		$time_stamp = time();
	}

	$number_tries++;

	// Broken the law?
	if ($number_tries > 5)
		$GLOBALS['elk']['errors']->fatal_lang_error('login_threshold_brute_fail', 'critical');

	// Otherwise set the members data. If they correct on their first attempt then we actually clear it, otherwise we set it!
	require_once(ROOTDIR . '/Members/Members.subs.php');
	updateMemberData($id_member, array('passwd_flood' => $was_correct && $number_tries == 1 ? '' : $time_stamp . '|' . $number_tries));
}

/**
 * This sets the X-Frame-Options header.
 *
 * @param string|null $override the frame option, defaults to deny.
 */
function frameOptionsHeader($override = null)
{
	global $modSettings;

	$option = 'SAMEORIGIN';

	if (is_null($override) && !empty($modSettings['frame_security']))
		$option = $modSettings['frame_security'];
	elseif (in_array($override, array('SAMEORIGIN', 'DENY')))
		$option = $override;

	// Don't bother setting the header if we have disabled it.
	if ($option == 'DISABLE')
		return;

	// Finally set it.
	header('X-Frame-Options: ' . $option);
}

/**
 * This adds additional security headers that may prevent browsers from doing something they should not
 *
 * - X-XSS-Protection header - This header enables the Cross-site scripting (XSS) filter
 * built into most recent web browsers. It's usually enabled by default, so the role of this
 * header is to re-enable the filter for this particular website if it was disabled by the user.
 * - X-Content-Type-Options header - It prevents the browser from doing MIME-type sniffing,
 * only IE and Chrome are honouring this header. This reduces exposure to drive-by download attacks
 * and sites serving user uploaded content that could be treated as executable or dynamic HTML files.
 *
 * @param boolean|null $override
 */
function securityOptionsHeader($override = null)
{
	if ($override !== true)
	{
		header('X-XSS-Protection: 1');
		header('X-Content-Type-Options: nosniff');
	}
}

/**
 * Stop some browsers pre fetching activity to reduce server load
 */
function stop_prefetching()
{
	if (isset($_SERVER["HTTP_X_PURPOSE"]) && in_array($_SERVER["HTTP_X_PURPOSE"], array("preview", "instant"))
		|| (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] === "prefetch"))
	{
		@ob_end_clean();
		header('HTTP/1.1 403 Forbidden');
		die;
	}
}

/**
 * Check if the Admin's session is active
 *
 * @return bool
 */
function isAdminSessionActive()
{
	global $modSettings;

	return empty($modSettings['securityDisable']) && (isset($_SESSION['admin_time']) && $_SESSION['admin_time'] + ($modSettings['admin_session_lifetime'] * 60) > time());
}

/**
 * Check if security files exist
 * If files are found, populate $context['security_controls_files']:
 * * 'title'	- $txt['security_risk']
 * * 'errors'	- An array of strings with the key being the filename and the value an error with the filename in it
 *
 * @return bool
 */
function checkSecurityFiles()
{
	global $txt;

	$has_files = false;

	$securityFiles = array('Install.php', 'upgrade.php', 'convert.php', 'repair_paths.php', 'repair_settings.php', 'Settings.php~', 'Settings_bak.php~');
	$GLOBALS['elk']['hooks']->hook('security_files', array(&$securityFiles));

	foreach ($securityFiles as $securityFile)
	{
		if (file_exists(BOARDDIR . '/' . $securityFile))
		{
			$has_files = true;

			$context['security_controls_files']['title'] = $txt['security_risk'];
			$context['security_controls_files']['errors'][$securityFile] = sprintf($txt['not_removed'], $securityFile);

			if ($securityFile == 'Settings.php~' || $securityFile == 'Settings_bak.php~')
			{
				$context['security_controls_files']['errors'][$securityFile] .= '<span class="smalltext">' . sprintf($txt['not_removed_extra'], $securityFile, substr($securityFile, 0, -1)) . '</span>';
			}
		}
	}

	return $has_files;
}