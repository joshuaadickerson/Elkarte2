<?php

namespace Elkarte\Elkarte\Security;

use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Http\HttpReq;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\Ips\IP;
use Elkarte\Elkarte\Theme\TemplateLayers;

class BanCheck
{
	const CANNOT_ACCESS 	= 'cannot_access';
	const CANNOT_LOGIN		= 'cannot_login';
	const CANNOT_POST 		= 'cannot_post';
	const CANNOT_REGISTER	= 'cannot_register';

	protected $req;
	protected $db;
	protected $errors;
	protected $hooks;

	public function __construct(HttpReq $req, DatabaseInterface $db, Errors $errors, Hooks $hooks)
	{
		$this->req = $req;
		$this->db = $db;
		$this->errors = $errors;
		$this->hooks = $hooks;

		// @todo I really don't think this class should be adding/removing layers. Let the theme do that
		$this->layers = $GLOBALS['elk']['layers'];
	}

	/**
	 * Apply restrictions for banned users. For example, disallow access.
	 *
	 * What it does:
	 * - If the user is banned, it dies with an error.
	 * - Caches this information for optimization purposes.
	 * - Forces a recheck if force_check is true.
	 *
	 * @param bool $forceCheck = false
	 */
	function isNotBanned($forceCheck = false)
	{
		global $modSettings, $user_info;
		// You cannot be banned if you are an Admin - doesn't help if you log out.
		if ($user_info['is_admin'])
			return;

		// Only check the ban every so often. (to reduce load.)
		if ($forceCheck || !isset($_SESSION['ban']) || empty($modSettings['banLastUpdated']) || ($_SESSION['ban']['last_checked'] < $modSettings['banLastUpdated']) || $_SESSION['ban']['id_member'] != $user_info['id'] || $_SESSION['ban']['ip'] != $user_info['ip'] || $_SESSION['ban']['ip2'] != $user_info['ip2'] || (isset($user_info['email'], $_SESSION['ban']['email']) && $_SESSION['ban']['email'] != $user_info['email']))
		{
			$this->load();
		}

		// There is a ban in a cookie but not in a session (they probably closed their browser)
		// Anyway, reload it from the database.
		if (!$this->hasBan(self::CANNOT_ACCESS) && !empty($_COOKIE[$this->getBanCookiename()]))
		{
			$bans = explode(',', $this->req->getCookie($this->getBanCookiename()));
			foreach ($bans as $key => $value)
			{
				$bans[$key] = (int) $value;
			}

			$this->db->fetchQueryCallback('
				SELECT bi.id_ban, bg.reason
				FROM {db_prefix}ban_items AS bi
					INNER JOIN {db_prefix}ban_groups AS bg ON (bg.id_ban_group = bi.id_ban_group)
				WHERE bi.id_ban IN ({array_int:ban_list})
					AND (bg.expire_time IS NULL OR bg.expire_time > {int:current_time})
					AND bg.cannot_access = {int:cannot_access}
				LIMIT ' . count($bans),
				array(
					'cannot_access' => 1,
					'ban_list' => $bans,
					'current_time' => $_SERVER['REQUEST_TIME'],
				),
				function($row)
				{
					$_SESSION['ban'][self::CANNOT_ACCESS]['ids'][] = $row['id_ban'];
					$_SESSION['ban'][self::CANNOT_ACCESS]['reason'] = $row['reason'];
				}
			);

			// My mistake. Next time better.
			if (!$this->hasBan(self::CANNOT_ACCESS))
			{
				require_once(ELKDIR . '/Security/Auth.subs.php');
				$cookie_url = url_parts(!empty($modSettings['localCookies']), !empty($modSettings['globalCookies']));
				elk_setcookie($this->getBanCookiename(), '', $_SERVER['REQUEST_TIME'] - 3600, $cookie_url[1], $cookie_url[0], false, false);
			}
		}

		// If you're fully banned, it's end of the story for you.
		if ($this->hasBan(self::CANNOT_ACCESS))
		{
			$this->banCannotAccess();
		}
		// You're not allowed to log in but yet you are. Let's fix that.
		elseif ($this->hasBan(self::CANNOT_LOGIN) && !$user_info['is_guest'])
		{
			$this->banCannotLogin();
		}

		// Fix up the banning permissions.
		if (isset($user_info['permissions']))
			$this->banPermissions();
	}

	function load()
	{
		global $user_info, $user_settings, $modSettings;
		// Innocent until proven guilty.  (but we know you are! :P)
		$_SESSION['ban'] = array(
			'last_checked' => time(),
			'id_member' => $user_info['id'],
			'ip' => $user_info['ip'],
			'ip2' => $user_info['ip2'],
			'email' => $user_info['email'],
		);

		$ban_query = array();
		$ban_query_vars = array('current_time' => time());
		$flag_is_activated = false;

		// Check both IP addresses.
		foreach (array('ip', 'ip2') as $ip_number)
		{
			if ($ip_number == 'ip2' && $user_info['ip2'] == $user_info['ip'])
				continue;

			$ban_query[] = constructBanQueryIP($user_info[$ip_number]);

			// IP was valid, maybe there's also a hostname...
			if (empty($modSettings['disableHostnameLookup']) && $user_info[$ip_number] != 'unknown')
			{
				$hostname = $GLOBALS['elk']['ip.manager']->host_from_ip(new IP($user_info[$ip_number]));
				if (strlen($hostname) > 0)
				{
					$ban_query[] = '({string:hostname} LIKE bi.hostname)';
					$ban_query_vars['hostname'] = $hostname;
				}
			}
		}

		// Is their email address banned?
		if (strlen($user_info['email']) != 0)
		{
			$ban_query[] = '({string:email} LIKE bi.email_address)';
			$ban_query_vars['email'] = $user_info['email'];
		}

		// How about this user?
		if (!$user_info['is_guest'] && !empty($user_info['id']))
		{
			$ban_query[] = 'bi.id_member = {int:id_member}';
			$ban_query_vars['id_member'] = $user_info['id'];
		}

		// Check the ban, if there's information.
		if (!empty($ban_query))
		{
			$restrictions = array(
				self::CANNOT_ACCESS,
				self::CANNOT_LOGIN,
				self::CANNOT_POST,
				self::CANNOT_REGISTER,
			);
			$this->db->fetchQueryCallback('
				SELECT bi.id_ban, bi.email_address, bi.id_member, bg.cannot_access, bg.cannot_register,
					bg.cannot_post, bg.cannot_login, bg.reason, IFNULL(bg.expire_time, 0) AS expire_time
				FROM {db_prefix}ban_items AS bi
					INNER JOIN {db_prefix}ban_groups AS bg ON (bg.id_ban_group = bi.id_ban_group AND (bg.expire_time IS NULL OR bg.expire_time > {int:current_time}))
				WHERE
					(' . implode(' OR ', $ban_query) . ')',
				$ban_query_vars,
				function($row) use($user_info, $restrictions, &$flag_is_activated)
				{
					// Store every type of ban that applies to you in your session.
					foreach ($restrictions as $restriction)
					{
						if (!empty($row[$restriction]))
						{
							$_SESSION['ban'][$restriction]['reason'] = $row['reason'];
							$_SESSION['ban'][$restriction]['ids'][] = $row['id_ban'];
							if (!isset($_SESSION['ban']['expire_time']) || ($_SESSION['ban']['expire_time'] != 0 && ($row['expire_time'] == 0 || $row['expire_time'] > $_SESSION['ban']['expire_time'])))
								$_SESSION['ban']['expire_time'] = $row['expire_time'];

							if (!$user_info['is_guest'] && $restriction == self::CANNOT_ACCESS && ($row['id_member'] == $user_info['id'] || $row['email_address'] == $user_info['email']))
								$flag_is_activated = true;
						}
					}
				}
			);
		}

		// Mark the cannot_access and cannot_post bans as being 'hit'.
		if ($this->hasBan(self::CANNOT_ACCESS) || $this->hasBan(self::CANNOT_POST) || $this->hasBan(self::CANNOT_LOGIN))
			$this->log(array_merge($this->hasBan(self::CANNOT_ACCESS) ? $_SESSION['ban'][self::CANNOT_ACCESS]['ids'] : array(), $this->hasBan(self::CANNOT_POST) ? $_SESSION['ban'][self::CANNOT_POST]['ids'] : array(), $this->hasBan(self::CANNOT_LOGIN) ? $_SESSION['ban'][self::CANNOT_LOGIN]['ids'] : array()));

		// If for whatever reason the is_activated flag seems wrong, do a little work to clear it up.
		if ($user_info['id'] && (($user_settings['is_activated'] >= 10 && !$flag_is_activated)
				|| ($user_settings['is_activated'] < 10 && $flag_is_activated)))
		{

			updateBanMembers();
		}
	}

	/**
	 * @param string|null $type One of the CANNOT_* constants or null to check if any ban exists
	 * @return bool
	 */
	public function hasBan($type = null)
	{
		return $type === null ? !empty($_SESSION['ban']) : !empty($_SESSION['ban'][$type]);
	}

	/**
	 * Fix permissions according to ban status.
	 *
	 * What it does:
	 * - Applies any states of banning by removing permissions the user cannot have.
	 * @package Bans
	 */
	function banPermissions()
	{
		global $user_info, $modSettings, $context;

		// Somehow they got here, at least take away all permissions...
		if ($this->hasBan(self::CANNOT_ACCESS))
		{
			$user_info['permissions'] = array();
		}
		// Okay, well, you can watch, but don't touch a thing.
		elseif ($this->hasBan(self::CANNOT_POST) || (!empty($modSettings['warning_mute']) && $modSettings['warning_mute'] <= $user_info['warning']))
		{
			$denied_permissions = array(
				'pm_send',
				'calendar_post', 'calendar_edit_own', 'calendar_edit_any',
				'poll_post',
				'poll_add_own', 'poll_add_any',
				'poll_edit_own', 'poll_edit_any',
				'poll_lock_own', 'poll_lock_any',
				'poll_remove_own', 'poll_remove_any',
				'manage_attachments', 'manage_smileys', 'manage_boards', 'admin_forum', 'manage_permissions',
				'moderate_forum', 'manage_membergroups', 'manage_bans', 'send_mail', 'edit_news',
				'profile_identity_any', 'profile_extra_any', 'profile_title_any',
				'post_new', 'post_reply_own', 'post_reply_any',
				'delete_own', 'delete_any', 'delete_replies',
				'make_sticky',
				'merge_any', 'split_any',
				'modify_own', 'modify_any', 'modify_replies',
				'move_any',
				'send_topic',
				'lock_own', 'lock_any',
				'remove_own', 'remove_any',
				'post_unapproved_topics', 'post_unapproved_replies_own', 'post_unapproved_replies_any',
			);
			$this->layers->addAfter('admin_warning', 'body');

			$this->hooks->hook('post_ban_permissions', array(&$denied_permissions));
			$user_info['permissions'] = array_diff($user_info['permissions'], $denied_permissions);
		}
		// Are they absolutely under moderation?
		elseif (!empty($modSettings['warning_moderate']) && $modSettings['warning_moderate'] <= $user_info['warning'])
		{
			// Work out what permissions should change...
			$permission_change = array(
				'post_new' => 'post_unapproved_topics',
				'post_reply_own' => 'post_unapproved_replies_own',
				'post_reply_any' => 'post_unapproved_replies_any',
				'post_attachment' => 'post_unapproved_attachments',
			);
			$this->hooks->hook('warn_permissions', array(&$permission_change));
			foreach ($permission_change as $old => $new)
			{
				if (!in_array($old, $user_info['permissions']))
					unset($permission_change[$old]);
				else
					$user_info['permissions'][] = $new;
			}
			$user_info['permissions'] = array_diff($user_info['permissions'], array_keys($permission_change));
		}

		// @todo Find a better place to call this? Needs to be after permissions loaded!
		// Finally, some bits we cache in the session because it saves queries.
		if (isset($_SESSION['mc']) && $_SESSION['mc']['time'] > $modSettings['settings_updated'] && $_SESSION['mc']['id'] == $user_info['id'])
			$user_info['mod_cache'] = $_SESSION['mc'];
		else
		{
			require_once(ELKDIR . '/Security/Auth.subs.php');
			rebuildModCache();
		}

		// Now that we have the mod cache taken care of lets setup a cache for the number of mod reports still open
		if (isset($_SESSION['rc']) && $_SESSION['rc']['time'] > $modSettings['last_mod_report_action'] && $_SESSION['rc']['id'] == $user_info['id'])
			$context['open_mod_reports'] = $_SESSION['rc']['reports'];
		elseif ($_SESSION['mc']['bq'] != '0=1')
		{
			$GLOBALS['elk']['members.moderator']->recountOpenReports(true, allowedTo('admin_forum'));
		}
		else
			$context['open_mod_reports'] = 0;
	}

	/**
	 * Log a ban in the database.
	 *
	 * What it does:
	 * - Log the current user in the ban logs.
	 * - Increment the hit counters for the specified ban ID's (if any.)
	 *
	 * @param int[] $ban_ids = array()
	 * @param string|null $email = null
	 */
	function log(array $ban_ids = array(), $email = null)
	{
		global $user_info;
		// Don't log web accelerators, it's very confusing...
		if (isset($_SERVER['HTTP_X_MOZ']) && $_SERVER['HTTP_X_MOZ'] == 'prefetch')
			return;

		$this->db->insert('',
			'{db_prefix}log_banned',
			array('id_member' => 'int', 'ip' => 'string-16', 'email' => 'string', 'log_time' => 'int'),
			array($user_info['id'], $user_info['ip'], ($email === null ? ($user_info['is_guest'] ? '' : $user_info['email']) : $email), time()),
			array('id_ban_log')
		);

		// One extra point for these bans.
		if (!empty($ban_ids))
			$this->db->query('', '
			UPDATE {db_prefix}ban_items
			SET hits = hits + 1
			WHERE id_ban IN ({array_int:ban_ids})',
				array(
					'ban_ids' => $ban_ids,
				)
			);
	}

	/**
	 * Checks if a given email address might be banned.
	 *
	 * What it does:
	 * - Check if a given email is banned.
	 * - Performs an immediate ban if the turns turns out positive.
	 *
	 * @param string $email
	 * @param string $restriction
	 * @param string $error
	 */
	public function isBannedEmail($email, $restriction, $error)
	{
		global $txt;
		// Can't ban an empty email
		if (empty($email) || trim($email) == '')
			return;

		// Let's start with the bans based on your IP/hostname/memberID...
		$ban_ids = isset($_SESSION['ban'][$restriction]) ? $_SESSION['ban'][$restriction]['ids'] : array();
		$ban_reason = isset($_SESSION['ban'][$restriction]) ? $_SESSION['ban'][$restriction]['reason'] : '';

		// ...and add to that the email address you're trying to register.
		$request = $this->db->query('', '
		SELECT bi.id_ban, bg.' . $restriction . ', bg.cannot_access, bg.reason
		FROM {db_prefix}ban_items AS bi
			INNER JOIN {db_prefix}ban_groups AS bg ON (bg.id_ban_group = bi.id_ban_group)
		WHERE {string:email} LIKE bi.email_address
			AND (bg.' . $restriction . ' = {int:cannot_access} OR bg.cannot_access = {int:cannot_access})
			AND (bg.expire_time IS NULL OR bg.expire_time >= {int:now})',
			array(
				'email' => $email,
				'cannot_access' => 1,
				'now' => time(),
			)
		);
		while ($row = $request->fetchAssoc())
		{
			if (!empty($row[self::CANNOT_ACCESS]))
			{
				$_SESSION['ban'][self::CANNOT_ACCESS]['ids'][] = $row['id_ban'];
				$_SESSION['ban'][self::CANNOT_ACCESS]['reason'] = $row['reason'];
			}
			if (!empty($row[$restriction]))
			{
				$ban_ids[] = $row['id_ban'];
				$ban_reason = $row['reason'];
			}
		}
		$request->free();

		// You're in biiig trouble.  Banned for the rest of this session!
		if ($this->hasBan(self::CANNOT_ACCESS))
		{
			$this->log($_SESSION['ban'][self::CANNOT_ACCESS]['ids']);
			$_SESSION['ban']['last_checked'] = $_SERVER['REQUEST_TIME'];

			$this->errors->fatal_error(sprintf($txt['your_ban'], $txt['guest_title']) . $_SESSION['ban'][self::CANNOT_ACCESS]['reason'], false);
		}

		if (!empty($ban_ids))
		{
			// Log this ban for future reference.
			$this->log($ban_ids, $email);
			$this->errors->fatal_error($error . $ban_reason, false);
		}
	}

	protected function banCannotAccess()
	{
		global $user_info, $txt, $context, $modSettings;

		require_once(SUBSDIR . '/Auth.subs.php');

		// We don't wanna see you!
		if (!$user_info['is_guest'])
		{
			$controller = new AuthController();
			$controller->action_logout(true, false);
		}

		// 'Log' the user out.  Can't have any funny business... (save the name!)
		$old_name = isset($user_info['name']) && $user_info['name'] != '' ? $user_info['name'] : $txt['guest_title'];
		$user_info['name'] = '';
		$user_info['username'] = '';
		$user_info['is_guest'] = true;
		$user_info['is_admin'] = false;
		$user_info['permissions'] = array();
		$user_info['id'] = 0;
		$context['user'] = array(
			'id' => 0,
			'username' => '',
			'name' => $txt['guest_title'],
			'is_guest' => true,
			'is_logged' => false,
			'is_admin' => false,
			'is_mod' => false,
			'is_moderator' => false,
			'can_mod' => false,
			'language' => $user_info['language'],
		);

		// A goodbye present.
		$cookie_url = url_parts(!empty($modSettings['localCookies']), !empty($modSettings['globalCookies']));
		elk_setcookie($this->getBanCookiename(), implode(',', $_SESSION['ban'][self::CANNOT_ACCESS]['ids']), time() + 3153600, $cookie_url[1], $cookie_url[0], false, false);

		// Don't scare anyone, now.
		$_GET['action'] = '';
		$_GET['board'] = '';
		$_GET['topic'] = '';
		writeLog(true);

		// @todo this is a controller function. We can throw an exception but a fatal error is wrong here
		// You banned, sucka!
		$this->errors->fatal_error(sprintf($txt['your_ban'], $old_name) . (empty($_SESSION['ban'][self::CANNOT_ACCESS]['reason']) ? '' : '<br />' . $_SESSION['ban'][self::CANNOT_ACCESS]['reason']) . '<br />' . (!empty($_SESSION['ban']['expire_time']) ? sprintf($txt['your_ban_expires'], standardTime($_SESSION['ban']['expire_time'], false)) : $txt['your_ban_expires_never']), 'user');

		// If we get here, something's gone wrong.... but let's try anyway.
		trigger_error('Hacking attempt...', E_USER_ERROR);
	}

	protected function banCannotLogin()
	{
		global $user_info, $context, $txt;

		// We don't wanna see you!
		require_once(ROOTDIR . '/Logging/Logging.subs.php');
		deleteMemberLogOnline();

		// 'Log' the user out.  Can't have any funny business... (save the name!)
		$old_name = isset($user_info['name']) && $user_info['name'] != '' ? $user_info['name'] : $txt['guest_title'];
		$user_info['name'] = '';
		$user_info['username'] = '';
		$user_info['is_guest'] = true;
		$user_info['is_admin'] = false;
		$user_info['permissions'] = array();
		$user_info['id'] = 0;
		$context['user'] = array(
			'id' => 0,
			'username' => '',
			'name' => $txt['guest_title'],
			'is_guest' => true,
			'is_logged' => false,
			'is_admin' => false,
			'is_mod' => false,
			'is_moderator' => false,
			'can_mod' => false,
			'language' => $user_info['language'],
		);

		// Wipe 'n Clean(r) erases all traces.
		$_GET['action'] = '';
		$_GET['board'] = '';
		$_GET['topic'] = '';
		writeLog(true);

		// Log them out
		$controller = new AuthController();
		$controller->action_logout(true, false);

		// Tell them thanks
		$this->errors->fatal_error(sprintf($txt['your_ban'], $old_name) . (empty($_SESSION['ban'][self::CANNOT_LOGIN]['reason']) ? '' : '<br />' . $_SESSION['ban'][self::CANNOT_LOGIN]['reason']) . '<br />' . (!empty($_SESSION['ban']['expire_time']) ? sprintf($txt['your_ban_expires'], standardTime($_SESSION['ban']['expire_time'], false)) : $txt['your_ban_expires_never']) . '<br />' . $txt['ban_continue_browse'], 'user');
	}

	/**
	 * Get the name of the ban cookie.
	 * @param string $cookiename
	 * @return string
	 */
	protected function getBanCookiename()
	{
		global $cookiename;
		return $cookiename . '_';
	}
}