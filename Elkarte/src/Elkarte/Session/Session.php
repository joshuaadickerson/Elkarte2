<?php

namespace Elkarte\Elkarte\Session;

use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\Http\Request;
use Elkarte\Elkarte\TokenHash;
use Util;

class Session
{
	protected $request;
	protected $hooks;
	protected $db;

	public function __construct(Request $request, Hooks $hooks, DatabaseInterface $db)
	{
		$this->request = $request;
		$this->hooks = $hooks;
		$this->db = $db;
	}

	/**
	 * Attempt to start the session, unless it already has been.
	 */
	function load()
	{
		global $modSettings, $boardurl, $sc;

		// Attempt to change a few PHP settings.
		@ini_set('session.use_cookies', true);
		@ini_set('session.use_only_cookies', false);
		@ini_set('url_rewriter.tags', '');
		@ini_set('session.use_trans_sid', false);
		@ini_set('arg_separator.output', '&amp;');

		if (!empty($modSettings['globalCookies']))
		{
			$parsed_url = parse_url($boardurl);

			if (preg_match('~^\d{1,3}(\.\d{1,3}){3}$~', $parsed_url['host']) == 0 && preg_match('~(?:[^\.]+\.)?([^\.]{2,}\..+)\z~i', $parsed_url['host'], $parts) == 1)
				@ini_set('session.cookie_domain', '.' . $parts[1]);
		}

		// @todo Set the session cookie path?

		// If it's already been started... probably best to skip this.
		if ((ini_get('session.auto_start') == 1 && !empty($modSettings['databaseSession_enable'])) || session_id() == '')
		{
			// Attempt to end the already-started session.
			if (ini_get('session.auto_start') == 1)
				session_write_close();

			// This is here to stop people from using bad junky PHPSESSIDs.
			if (isset($_REQUEST[session_name()]) && preg_match('~^[A-Za-z0-9,-]{16,64}$~', $_REQUEST[session_name()]) == 0 && !isset($_COOKIE[session_name()]))
			{
				$tokenizer = new TokenHash();
				$session_id = hash('md5', hash('md5', 'elk_sess_' . time()) . $tokenizer->generate_hash(8));
				$_REQUEST[session_name()] = $session_id;
				$_GET[session_name()] = $session_id;
				$_POST[session_name()] = $session_id;
			}

			// Use database sessions?
			if (!empty($modSettings['databaseSession_enable']))
			{
				$handler = new DatabaseSessionHandler($this->db);
				session_set_save_handler($handler, true);
			}
			elseif (ini_get('session.gc_maxlifetime') <= 1440 && !empty($modSettings['databaseSession_lifetime']))
				@ini_set('session.gc_maxlifetime', max($modSettings['databaseSession_lifetime'], 60));

			// Use cache setting sessions?
			if (empty($modSettings['databaseSession_enable']) && !empty($modSettings['cache_enable']) && php_sapi_name() != 'cli')
			{
				$this->hooks->hook('session_handlers');

				// @todo move these to a plugin.
				if (function_exists('mmcache_set_session_handlers'))
					mmcache_set_session_handlers();
				elseif (function_exists('eaccelerator_set_session_handlers'))
					eaccelerator_set_session_handlers();
			}

			// Start the session
			session_start();

			// APC destroys static class members before sessions can be written.  To work around this we
			// explicitly call session_write_close on script end/exit bugs.php.net/bug.php?id=60657
			if (extension_loaded('apc') && ini_get('apc.enabled') && !extension_loaded('apcu'))
				register_shutdown_function('session_write_close');

			// Change it so the cache settings are a little looser than default.
			if (!empty($modSettings['databaseSession_loose']) || (isset($_REQUEST['action']) && $_REQUEST['action'] == 'search'))
				header('Cache-Control: private');
		}

		// Set the randomly generated code.
		if (!isset($_SESSION['session_var']))
		{
			$tokenizer = new TokenHash();
			$_SESSION['session_value'] = $tokenizer->generate_hash(32, session_id());
			$_SESSION['session_var'] = substr(preg_replace('~^\d+~', '', $tokenizer->generate_hash(16, session_id())), 0, rand(7, 12));
		}

		$sc = $_SESSION['session_value'];
	}

	/**
	 * Make sure the user's correct session was passed, and they came from here.
	 *
	 * What it does:
	 * - Checks the current session, verifying that the person is who he or she should be.
	 * - Also checks the referrer to make sure they didn't get sent here.
	 * - Depends on the disableCheckUA setting, which is usually missing.
	 * - Will check GET, POST, or REQUEST depending on the passed type.
	 * - Also optionally checks the referring action if passed. (note that the referring action must be by GET.)
	 *
	 * @param string $type = 'post' (post, get, request)
	 * @param string $from_action = ''
	 * @param bool $is_fatal = true
	 * @return string the error message if is_fatal is false.
	 */
	function check($type = 'post', $from_action = '', $is_fatal = true)
	{
		global $sc, $modSettings, $boardurl;

		// We'll work out user agent checks
		$req = $this->request;

		// Is it in as $_POST['sc']?
		if ($type == 'post')
		{
			$check = isset($_POST[$_SESSION['session_var']]) ? $_POST[$_SESSION['session_var']] : (empty($modSettings['strictSessionCheck']) && isset($_POST['sc']) ? $_POST['sc'] : null);
			if ($check !== $sc)
				$error = 'session_timeout';
		}
		// How about $_GET['sesc']?
		elseif ($type === 'get')
		{
			$check = isset($_GET[$_SESSION['session_var']]) ? $_GET[$_SESSION['session_var']] : (empty($modSettings['strictSessionCheck']) && isset($_GET['sesc']) ? $_GET['sesc'] : null);
			if ($check !== $sc)
				$error = 'session_verify_fail';
		}
		// Or can it be in either?
		elseif ($type == 'request')
		{
			$check = isset($_GET[$_SESSION['session_var']]) ? $_GET[$_SESSION['session_var']] : (empty($modSettings['strictSessionCheck']) && isset($_GET['sesc']) ? $_GET['sesc'] : (isset($_POST[$_SESSION['session_var']]) ? $_POST[$_SESSION['session_var']] : (empty($modSettings['strictSessionCheck']) && isset($_POST['sc']) ? $_POST['sc'] : null)));

			if ($check !== $sc)
				$error = 'session_verify_fail';
		}

		// Verify that they aren't changing user agents on us - that could be bad.
		if ((!isset($_SESSION['USER_AGENT']) || $_SESSION['USER_AGENT'] != $req->user_agent()) && empty($modSettings['disableCheckUA']))
			$error = 'session_verify_fail';

		// Make sure a page with session check requirement is not being prefetched.
		stop_prefetching();

		// Check the referring site - it should be the same server at least!
		if (isset($_SESSION['request_referer']))
			$referrer_url = $_SESSION['request_referer'];
		else
			$referrer_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

		$referrer = @parse_url($referrer_url);

		if (!empty($referrer['host']))
		{
			if (strpos($_SERVER['HTTP_HOST'], ':') !== false)
				$real_host = substr($_SERVER['HTTP_HOST'], 0, strpos($_SERVER['HTTP_HOST'], ':'));
			else
				$real_host = $_SERVER['HTTP_HOST'];

			$parsed_url = parse_url($boardurl);

			// Are global cookies on? If so, let's check them ;).
			if (!empty($modSettings['globalCookies']))
			{
				if (preg_match('~(?:[^\.]+\.)?([^\.]{3,}\..+)\z~i', $parsed_url['host'], $parts) == 1)
					$parsed_url['host'] = $parts[1];

				if (preg_match('~(?:[^\.]+\.)?([^\.]{3,}\..+)\z~i', $referrer['host'], $parts) == 1)
					$referrer['host'] = $parts[1];

				if (preg_match('~(?:[^\.]+\.)?([^\.]{3,}\..+)\z~i', $real_host, $parts) == 1)
					$real_host = $parts[1];
			}

			// Okay: referrer must either match parsed_url or real_host.
			if (isset($parsed_url['host']) && strtolower($referrer['host']) != strtolower($parsed_url['host']) && strtolower($referrer['host']) != strtolower($real_host))
			{
				$error = 'verify_url_fail';
				$log_error = true;
				$sprintf = array($GLOBALS['elk']['text']->htmlspecialchars($referrer_url));
			}
		}

		// Well, first of all, if a from_action is specified you'd better have an old_url.
		if (!empty($from_action) && (!isset($_SESSION['old_url']) || preg_match('~[?;&]action=' . $from_action . '([;&]|$)~', $_SESSION['old_url']) == 0))
		{
			$error = 'verify_url_fail';
			$log_error = true;
			$sprintf = array($GLOBALS['elk']['text']->htmlspecialchars($referrer_url));
		}

		// Everything is ok, return an empty string.
		if (!isset($error))
			return '';
		// A session error occurred, show the error.
		elseif ($is_fatal)
		{
			if (isset($_GET['xml']) || isset($_REQUEST['api']))
			{
				@ob_end_clean();
				header('HTTP/1.1 403 Forbidden - Session timeout');
				die;
			}
			else
				$GLOBALS['elk']['errors']->fatal_lang_error($error, isset($log_error) ? 'user' : false, isset($sprintf) ? $sprintf : array());
		}
		// A session error occurred, return the error to the calling function.
		else
			return $error;

		// We really should never fall through here, for very important reasons.  Let's make sure.
		trigger_error('Hacking attempt...', E_USER_ERROR);
	}

	/**
	 * Check if the user is who he/she says he is.
	 *
	 * What it does:
	 * - This function makes sure the user is who they claim to be by requiring a
	 * password to be typed in every hour.
	 * - This check can be turned on and off by the securityDisable setting.
	 * - Uses the adminLogin() function of subs/Auth.subs.php if they need to login,
	 * which saves all request (POST and GET) data.
	 *
	 * @param string $type = Admin
	 */
	function validate($type = 'Admin')
	{
		global $modSettings, $user_info, $user_settings;

		// Guests are not welcome here.
		is_not_guest();

		// Validate what type of session check this is.
		$types = array();
		$GLOBALS['elk']['hooks']->hook('validateSession', array(&$types));
		$type = in_array($type, $types) || $type == 'moderate' ? $type : 'Admin';

		// Set the lifetime for our Admin session. Default is ten minutes.
		$refreshTime = 10;

		if (isset($modSettings['admin_session_lifetime']))
		{
			// Maybe someone is paranoid or mistakenly misconfigured the param? Give them at least 5 minutes.
			if ($modSettings['admin_session_lifetime'] < 5)
				$refreshTime = 5;

			// A whole day should be more than enough..
			elseif ($modSettings['admin_session_lifetime'] > 14400)
				$refreshTime = 14400;

			// We are between our internal min and max. Let's keep the board owner's value.
			else
				$refreshTime = $modSettings['admin_session_lifetime'];
		}

		// If we're using XML give an additional ten minutes grace as an Admin can't log on in XML mode.
		if (isset($_GET['xml']))
			$refreshTime += 10;

		$refreshTime = $refreshTime * 60;

		// Is the security option off?
		// @todo remove the exception (means update the db as well)
		if (!empty($modSettings['securityDisable' . ($type != 'Admin' ? '_' . $type : '')]))
			return true;

		// If their Admin or moderator session hasn't expired yet, let it pass, let the Admin session trump a moderation one as well
		if ((!empty($_SESSION[$type . '_time']) && $_SESSION[$type . '_time'] + $refreshTime >= time()) || (!empty($_SESSION['admin_time']) && $_SESSION['admin_time'] + $refreshTime >= time()))
			return true;

		require_once(SUBSDIR . '/Auth.subs.php');

		// Coming from the login screen
		if (isset($_POST[$type . '_pass']) || isset($_POST[$type . '_hash_pass']))
		{
			$this->check();
			validateToken('Admin-login');

			// Hashed password, ahoy!
			if (isset($_POST[$type . '_hash_pass']) && strlen($_POST[$type . '_hash_pass']) === 64)
			{
				// Allow integration to verify the password
				$good_password = in_array(true, $GLOBALS['elk']['hooks']->hook('verify_password', array($user_info['username'], $_POST[$type . '_hash_pass'], true)), true);

				$password = $_POST[$type . '_hash_pass'];
				if ($good_password || validateLoginPassword($password, $user_info['passwd']))
				{
					$_SESSION[$type . '_time'] = time();
					unset($_SESSION['request_referer']);

					return true;
				}
			}

			// Posting the password... check it.
			if (isset($_POST[$type . '_pass']) && str_replace('*', '', $_POST[$type. '_pass']) !== '')
			{
				// Give integrated systems a chance to verify this password
				$good_password = in_array(true, $GLOBALS['elk']['hooks']->hook('verify_password', array($user_info['username'], $_POST[$type . '_pass'], false)), true);

				// Password correct?
				$password = $_POST[$type . '_pass'];
				if ($good_password || validateLoginPassword($password, $user_info['passwd'], $user_info['username']))
				{
					$_SESSION[$type . '_time'] = time();
					unset($_SESSION['request_referer']);

					return true;
				}
			}
		}

		// OpenID?
		if (!empty($user_settings['openid_uri']))
		{
			require_once(SUBSDIR . '/OpenID.subs.php');
			$openID = new OpenID();
			$openID->revalidate();

			$_SESSION[$type . '_time'] = time();
			unset($_SESSION['request_referer']);

			return true;
		}

		// Better be sure to remember the real referer
		if (empty($_SESSION['request_referer']))
			$_SESSION['request_referer'] = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
		elseif (empty($_POST))
			unset($_SESSION['request_referer']);

		// Need to type in a password for that, man.
		if (!isset($_GET['xml']))
			adminLogin($type);

		return 'session_verify_fail';
	}
}