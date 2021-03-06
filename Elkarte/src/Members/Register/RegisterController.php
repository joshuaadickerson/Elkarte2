<?php

/**
 * This file has two main jobs, but they really are one.  It registers new
 * members, and it helps the administrator moderate member registrations.
 * Similarly, it handles account activation as well.
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

namespace Elkarte\Members;

use Elkarte\Elkarte\Controller\AbstractController;
use Elkarte\Elkarte\Controller\Action;
use Elkarte\ErrorContext;

/**
 * Register Controller Class, It registers new members, and it allows
 * the administrator moderate member registration
 *
 * is_activated value key is as follows, for reference again:
 * - > 10 Banned with activation status as value - 10
 * - 5 = Awaiting COPPA consent
 * - 4 = Awaiting Deletion approval
 * - 3 = Awaiting Admin approval
 * - 2 = Awaiting reactivation from email change
 * - 1 = Approved and active
 * - 0 = Not active
 */
class RegisterController extends AbstractController
{
	/**
	 * Holds the results of a findUser() request
	 * @var array
	 */
	private $_row;

	/**
	 * Pre Dispatch, called before other methods.  Loads HttpReq instance.
	 */
	public function pre_dispatch()
	{
		global $modSettings;

		// Check if the administrator has it disabled.
		if (!empty($modSettings['registration_method']) && $modSettings['registration_method'] == '3')
			$this->_errors->fatal_lang_error('registration_disabled', false);
	}

	/**
	 * Intended entry point for this class.
	 *
	 * By default, this is called for action=register
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		// Add an subaction array to act accordingly
		$subActions = array(
			'register' => array($this, 'action_register'),
			'register2' => array($this, 'action_register2'),
			'usernamecheck' => array($this, 'action_registerCheckUsername'),
			'activate' => array($this, 'action_activate'),
			'verificationcode' => array($this, 'action_verificationcode'),
			'coppa' => array($this, 'action_coppa'),
		);

		// Setup the action handler
		$action = new Action();
		$subAction = $action->initialize($subActions, 'register');

		// Call the action
		$action->dispatch($subAction);
	}

	/**
	 * Begin the registration process.
	 *
	 * Accessed by ?action=register
	 *
	 * @uses template_registration_agreement or template_registration_form sub template in Register.template,
	 * @uses Login language file
	 */
	public function action_register()
	{
		global $txt, $context, $modSettings, $user_info, $scripturl;

		// If this user is an Admin - redirect them to the Admin registration page.
		if (allowedTo('moderate_forum') && !$user_info['is_guest'])
			redirectexit('action=Admin;area=regcenter;sa=register');
		// You are not a guest, so you are a member - and members don't get to register twice!
		elseif (empty($user_info['is_guest']))
			redirectexit();

		// Confused and want to contact the admins instead
		if (isset($this->http_req->post->show_contact))
			redirectexit('action=about;sa=contact');

		loadLanguage('Login');
		$this->_templates->load('Register');

		// Do we need them to agree to the registration agreement, first?
		$context['require_agreement'] = !empty($modSettings['requireAgreement']);
		$context['checkbox_agreement'] = !empty($modSettings['checkboxAgreement']);
		$context['registration_passed_agreement'] = !empty($_SESSION['registration_agreed']);
		$context['show_coppa'] = !empty($modSettings['coppaAge']);
		$context['show_contact_button'] = !empty($modSettings['enable_contactform']) && $modSettings['enable_contactform'] === 'registration';

		// Under age restrictions?
		if ($context['show_coppa'])
		{
			$context['skip_coppa'] = false;
			$context['coppa_agree_above'] = sprintf($txt[($context['require_agreement'] ? 'agreement_' : '') . 'agree_coppa_above'], $modSettings['coppaAge']);
			$context['coppa_agree_below'] = sprintf($txt[($context['require_agreement'] ? 'agreement_' : '') . 'agree_coppa_below'], $modSettings['coppaAge']);
		}

		// What step are we at?
		$current_step = isset($this->http_req->post->step) ? (int) $this->http_req->post->step : ($context['require_agreement'] && !$context['checkbox_agreement'] ? 1 : 2);

		// Does this user agree to the registration agreement?
		if ($current_step == 1 && (isset($this->http_req->post->accept_agreement) || isset($this->http_req->post->accept_agreement_coppa)))
		{
			$context['registration_passed_agreement'] = $_SESSION['registration_agreed'] = true;
			$current_step = 2;

			// Skip the coppa procedure if the user says he's old enough.
			if ($context['show_coppa'])
			{
				$_SESSION['skip_coppa'] = !empty($this->http_req->post->accept_agreement);

				// Are they saying they're under age, while under age registration is disabled?
				if (empty($modSettings['coppaType']) && empty($_SESSION['skip_coppa']))
				{
					loadLanguage('Login');
					$this->_errors->fatal_lang_error('under_age_registration_prohibited', false, array($modSettings['coppaAge']));
				}
			}
		}
		// Make sure they don't squeeze through without agreeing.
		elseif ($current_step > 1 && $context['require_agreement'] && !$context['checkbox_agreement'] && !$context['registration_passed_agreement'])
			$current_step = 1;

		// Show the user the right form.
		$context['sub_template'] = $current_step == 1 ? 'registration_agreement' : 'registration_form';
		$context['page_title'] = $current_step == 1 ? $txt['registration_agreement'] : $txt['registration_form'];
		loadJavascriptFile('register.js');
		theme()->addInlineJavascript('disableAutoComplete();', true);

		// Add the register chain to the link tree.
		$context['breadcrumbs'][] = array(
			'url' => $scripturl . '?action=register',
			'name' => $txt['register'],
		);

		// Prepare the time gate! Done like this to allow later steps to reset the limit for any reason
		if (!isset($_SESSION['register']))
			$_SESSION['register'] = array(
				'timenow' => time(),
				// minimum number of seconds required on this page for registration
				'limit' => 8,
			);
		else
			$_SESSION['register']['timenow'] = time();

		// If you have to agree to the agreement, it needs to be fetched from the file.
		$this->_load_require_agreement();

		// If we have language support enabled then they need to be loaded
		$this->_load_language_support();

		// Any custom or standard profile fields we want filled in during registration?
		$this->_load_profile_fields();

		// Trigger the prepare_context event
		$this->_events->trigger('prepare_context', array('current_step' => $current_step));

		// Are they coming from an OpenID login attempt?
		if (!empty($_SESSION['openid']['verified']) && !empty($_SESSION['openid']['openid_uri']) && !empty($_SESSION['openid']['nickname']))
		{
			$context['openid'] = $_SESSION['openid']['openid_uri'];
			$context['username'] = $this->http_req->getPost('user', [$GLOBALS['elk']['text'], 'htmlspecialchars'], $_SESSION['openid']['nickname']);
			$context['email'] = $this->http_req->getPost('email', [$GLOBALS['elk']['text'], 'htmlspecialchars'], $_SESSION['openid']['email']);
		}
		// See whether we have some pre filled values.
		else
		{
			$context['openid'] = $this->http_req->getPost('openid_identifier', 'trim', '');
			$context['username'] = $this->http_req->getPost('user', [$GLOBALS['elk']['text'], 'htmlspecialchars'], '');
			$context['email'] = $this->http_req->getPost('email', [$GLOBALS['elk']['text'], 'htmlspecialchars'], '');
		}

		// Were there any errors?
		$context['registration_errors'] = array();
		$reg_errors = ErrorContext::context('register', 0);
		if ($reg_errors->hasErrors())
			$context['registration_errors'] = $reg_errors->prepareErrors();

		createToken('register');
	}

	/**
	 * Handles the registration process for members using ElkArte registration
	 * and not (for example) OpenID.
	 *
	 * What it does:
	 * - Validates all requirements have been filled in properly
	 * - Passes final processing to do_register
	 * - Directs back to register on errors
	 *
	 * Accessed by ?action=register;sa=register2
	 */
	public function action_register2()
	{
		global $modSettings;

		// Start collecting together any errors.
		$reg_errors = ErrorContext::context('register', 0);

		$this->_can_register();

		$this->session->check();
		if (!validateToken('register', 'post', true, false))
			$reg_errors->addError('token_verification');

		// If we're using an agreement checkbox, did they check it?
		if (!empty($modSettings['checkboxAgreement']) && !empty($this->http_req->post->checkbox_agreement))
			$_SESSION['registration_agreed'] = true;

		// Well, if you don't agree, you can't register.
		if (!empty($modSettings['requireAgreement']) && empty($_SESSION['registration_agreed']))
			redirectexit();

		// Make sure they came from *somewhere*, have a session.
		if (!isset($_SESSION['old_url']))
			redirectexit('action=register');

		// If we don't require an agreement, we need a extra check for coppa.
		if (empty($modSettings['requireAgreement']) && !empty($modSettings['coppaAge']))
			$_SESSION['skip_coppa'] = !empty($this->http_req->post->accept_agreement);

		// Are they under age, and under age users are banned?
		if (!empty($modSettings['coppaAge']) && empty($modSettings['coppaType']) && empty($_SESSION['skip_coppa']))
		{
			loadLanguage('Login');
			$this->_errors->fatal_lang_error('under_age_registration_prohibited', false, array($modSettings['coppaAge']));
		}

		// Check the time gate for miscreants. First make sure they came from somewhere that actually set it up.
		if (empty($_SESSION['register']['timenow']) || empty($_SESSION['register']['limit']))
			redirectexit('action=register');

		// Failing that, check the time limit for excessive speed.
		if (time() - $_SESSION['register']['timenow'] < $_SESSION['register']['limit'])
		{
			loadLanguage('Login');
			$reg_errors->addError('too_quickly');
		}

		// Trigger any events required before we complete registration, like captcha verification
		$this->_events->trigger('before_complete_register', array('reg_errors' => $reg_errors));

		$this->do_register(false);
	}

	/**
	 * Actually register the member.
	 *
	 * - Called from OpenID controller as well as Register controller
	 * - Does the actual registration to the system
	 *
	 * @param bool $verifiedOpenID = false
	 */
	public function do_register($verifiedOpenID = false)
	{
		global $txt, $modSettings, $context, $user_info;

		// Start collecting together any errors.
		$reg_errors = ErrorContext::context('register', 0);

		// Checks already done if coming from the action
		if ($verifiedOpenID)
			$this->_can_register();

		// Clean the form values
		foreach ($this->http_req->post as $key => $value)
		{
			if (!is_array($value))
			{
				$this->http_req->post->$key = htmltrim__recursive(str_replace(array("\n", "\r"), '', $value));
			}
		}

		// A little security to any secret answer ... @todo increase?
		if ($this->http_req->getPost('secret_answer', 'trim', '') !== '')
			$this->http_req->post->secret_answer = md5($this->http_req->post->secret_answer);

		// Needed for isReservedName() and registerMember().

		// Validation... even if we're not a mall.
		if (isset($this->http_req->post->real_name) && (!empty($modSettings['allow_editDisplayName']) || allowedTo('moderate_forum')))
		{
			$this->http_req->post->real_name = trim(preg_replace('~[\t\n\r \x0B\0\x{A0}\x{AD}\x{2000}-\x{200F}\x{201F}\x{202F}\x{3000}\x{FEFF}]+~u', ' ', $this->http_req->post->real_name));
			$has_real_name = true;
		}
		else
			$has_real_name = false;

		// Handle a string as a birth date...
		if ($this->http_req->getPost('birthdate', 'trim', '') !== '')
			$this->http_req->post->birthdate = strftime('%Y-%m-%d', strtotime($this->http_req->post->birthdate));
		// Or birthdate parts...
		elseif (!empty($this->http_req->post->bday1) && !empty($this->http_req->post->bday2))
			$this->http_req->post->birthdate = sprintf('%04d-%02d-%02d', empty($this->http_req->post->bday3) ? 0 : (int) $this->http_req->post->bday3, (int) $this->http_req->post->bday1, (int) $this->http_req->post->bday2);

		// By default assume email is hidden, only show it if we tell it to.
		$this->http_req->post->hide_email = !empty($this->http_req->post->allow_email) ? 0 : 1;

		// Validate the passed language file.
		if (isset($this->http_req->post->lngfile) && !empty($modSettings['userLanguage']))
		{
			// Do we have any languages?
			$context['languages'] = getLanguages();

			// Did we find it?
			if (isset($context['languages'][$this->http_req->post->lngfile]))
				$_SESSION['language'] = $this->http_req->post->lngfile;
			else
				unset($this->http_req->post->lngfile);
		}
		elseif (isset($this->http_req->post->lngfile))
			unset($this->http_req->post->lngfile);

		// Set the options needed for registration.
		$regOptions = array(
			'interface' => 'guest',
			'username' => !empty($this->http_req->post->user) ? $this->http_req->post->user : '',
			'email' => !empty($this->http_req->post->email) ? $this->http_req->post->email : '',
			'password' => !empty($this->http_req->post->passwrd1) ? $this->http_req->post->passwrd1 : '',
			'password_check' => !empty($this->http_req->post->passwrd2) ? $this->http_req->post->passwrd2 : '',
			'openid' => !empty($this->http_req->post->openid_identifier) ? $this->http_req->post->openid_identifier : '',
			'auth_method' => !empty($this->http_req->post->authenticate) ? $this->http_req->post->authenticate : '',
			'check_reserved_name' => true,
			'check_password_strength' => true,
			'check_email_ban' => true,
			'send_welcome_email' => !empty($modSettings['send_welcomeEmail']),
			'require' => !empty($modSettings['coppaAge']) && !$verifiedOpenID && empty($_SESSION['skip_coppa']) ? 'coppa' : (empty($modSettings['registration_method']) ? 'nothing' : ($modSettings['registration_method'] == 1 ? 'activation' : 'approval')),
			'extra_register_vars' => $this->_extra_vars($has_real_name),
			'theme_vars' => array(),
		);

		// Registration options are always default options...
		if (isset($this->http_req->post->default_options))
			$this->http_req->post->options = isset($this->http_req->post->options) ? $this->http_req->post->options + $this->http_req->post->default_options : $this->http_req->post->default_options;

		$regOptions['theme_vars'] = isset($this->http_req->post->options) && is_array($this->http_req->post->options) ? $this->http_req->post->options : array();

		// Make sure they are clean, dammit!
		$regOptions['theme_vars'] = $GLOBALS['elk']['text']->htmlspecialchars__recursive($regOptions['theme_vars']);

		// Check whether we have fields that simply MUST be displayed?
		$this->elk['profile']->loadCustomFields(0, 'register', isset($this->http_req->post->customfield) ? $this->http_req->post->customfield : array());

		foreach ($context['custom_fields'] as $row)
		{
			// Don't allow overriding of the theme variables.
			if (isset($regOptions['theme_vars'][$row['colname']]))
				unset($regOptions['theme_vars'][$row['colname']]);

			// Prepare the value!
			$value = isset($this->http_req->post->customfield[$row['colname']]) ? trim($this->http_req->post->customfield[$row['colname']]) : '';

			// We only care for text fields as the others are valid to be empty.
			if (!in_array($row['field_type'], array('check', 'select', 'radio')))
			{
				$is_valid = isCustomFieldValid($row, $value);
				if ($is_valid !== true)
				{
					$err_params = array($row['name']);
					if ($is_valid === 'custom_field_not_number')
						$err_params[] = $row['field_length'];

					$reg_errors->addError(array($is_valid, $err_params));
				}
			}

			// Is this required but not there?
			if (trim($value) === '' && $row['show_reg'] > 1)
				$reg_errors->addError(array('custom_field_empty', array($row['name'])));
		}

		// Lets check for other errors before trying to register the member.
		if ($reg_errors->hasErrors())
		{
			$this->http_req->post->step = 2;

			// If they've filled in some details but made an error then they need less time to finish
			$_SESSION['register']['limit'] = 4;

			$this->action_register();
			return false;
		}

		// If they're wanting to use OpenID we need to validate them first.
		if (empty($_SESSION['openid']['verified']) && !empty($this->http_req->post->authenticate) && $this->http_req->post->authenticate === 'openid')
		{
			// What do we need to save?
			$save_variables = array();
			foreach ($this->http_req->post as $k => $v)
				if (!in_array($k, array('sc', 'sesc', $context['session_var'], 'passwrd1', 'passwrd2', 'regSubmit')))
					$save_variables[$k] = $v;

			require_once(SUBSDIR . '/OpenID.subs.php');
			$openID = new OpenID();
			$openID->validate($this->http_req->post->openid_identifier, false, $save_variables);
		}
		// If we've come from OpenID set up some default stuff.
		elseif ($verifiedOpenID || ((!empty($this->http_req->post->openid_identifier) || !empty($_SESSION['openid']['openid_uri'])) && $this->http_req->post->authenticate === 'openid'))
		{
			$regOptions['username'] = !empty($this->http_req->post->user) && trim($this->http_req->post->user) != '' ? $this->http_req->post->user : $_SESSION['openid']['nickname'];
			$regOptions['email'] = !empty($this->http_req->post->email) && trim($this->http_req->post->email) != '' ? $this->http_req->post->email : $_SESSION['openid']['email'];
			$regOptions['auth_method'] = 'openid';
			$regOptions['openid'] = !empty($_SESSION['openid']['openid_uri']) ? $_SESSION['openid']['openid_uri'] : (!empty($this->http_req->post->openid_identifier) ? $this->http_req->post->openid_identifier : '');
		}

		// Registration needs to know your IP
		$req = $GLOBALS['elk']['req'];

		$regOptions['ip'] = $user_info['ip'];
		$regOptions['ip2'] = $req->ban_ip();
		$memberID = registerMember($regOptions, 'register');

		// If there are "important" errors and you are not an Admin: log the first error
		// Otherwise grab all of them and don't log anything
		if ($reg_errors->hasErrors(1) && !$user_info['is_admin'])
		{
			foreach ($reg_errors->prepareErrors(1) as $error)
				$this->_errors->fatal_error($error, 'general');
		}

		// Was there actually an error of some kind dear boy?
		if ($reg_errors->hasErrors())
		{
			$this->http_req->post->step = 2;
			$this->action_register();
			return false;
		}

		// Do our spam protection now.
		spamProtection('register');

		// We'll do custom fields after as then we get to use the helper function!
		if (!empty($this->http_req->post->customfield))
		{
			require_once(ROOTDIR . '/Profile/Profile.subs.php');
			makeCustomFieldChanges($memberID, 'register');
		}

		// If COPPA has been selected then things get complicated, setup the template.
		if (!empty($modSettings['coppaAge']) && empty($_SESSION['skip_coppa']))
			redirectexit('action=register;sa=coppa;member=' . $memberID);
		// Basic template variable setup.
		elseif (!empty($modSettings['registration_method']))
		{
			$this->_templates->load('Register');

			$context += array(
				'page_title' => $txt['register'],
				'title' => $txt['registration_successful'],
				'sub_template' => 'after',
				'description' => $modSettings['registration_method'] == 2 ? $txt['approval_after_registration'] : $txt['activate_after_registration']
			);
		}
		else
		{
			$GLOBALS['elk']['hooks']->hook('activate', array($regOptions['username'], 1, 1));

			setLoginCookie(60 * $modSettings['cookieTime'], $memberID, hash('sha256', $GLOBALS['elk']['text']->strtolower($regOptions['username']) . $regOptions['password'] . $regOptions['register_vars']['password_salt']));

			redirectexit('action=auth;sa=check;member=' . $memberID, $context['server']['needs_login_fix']);
		}
	}

	/**
	 * Checks if registrations are enabled and the user didn't just register
	 */
	protected function _can_register()
	{
		global $modSettings;

		// You can't register if it's disabled.
		if (!empty($modSettings['registration_method']) && $modSettings['registration_method'] == 3)
			$this->_errors->fatal_lang_error('registration_disabled', false);

		// Make sure they didn't just register with this session.
		if (!empty($_SESSION['just_registered']) && empty($modSettings['disableRegisterCheck']))
			$this->_errors->fatal_lang_error('register_only_once', false);
	}

	/**
	 * Collect all extra registration fields someone might have filled in.
	 *
	 * What it does:
	 * - Classifies variables as possible string, int, float or bool
	 * - Casts all posted data to the proper type (string, float, etc)
	 * - Drops fields that we specially exclude during registration
	 *
	 * @param bool $has_real_name - if true adds 'real_name' as well
	 */
	protected function _extra_vars($has_real_name)
	{
		global $modSettings;

		// Define the fields that may be enabled for registration
		$possible_strings = array(
			'birthdate',
			'time_format',
			'buddy_list',
			'pm_ignore_list',
			'smiley_set',
			'personal_text', 'avatar',
			'lngfile', 'location',
			'secret_question', 'secret_answer',
			'website_url', 'website_title',
		);

		$possible_ints = array(
			'pm_email_notify',
			'notify_types',
			'id_theme',
			'gender',
		);

		$possible_floats = array(
			'time_offset',
		);

		$possible_bools = array(
			'notify_announcements', 'notify_regularity', 'notify_send_body',
			'hide_email', 'show_online',
		);

		if ($has_real_name && trim($this->http_req->post->real_name) != '' && !isReservedName($this->http_req->post->real_name) && $GLOBALS['elk']['text']->strlen($this->http_req->post->real_name) < 60)
			$possible_strings[] = 'real_name';

		// Some of these fields we may not want.
		if (!empty($modSettings['registration_fields']))
		{
			// But we might want some of them if the Admin asks for them.
			$standard_fields = array('location', 'gender');
			$reg_fields = explode(',', $modSettings['registration_fields']);

			$exclude_fields = array_diff($standard_fields, $reg_fields);

			// Website is a little different
			if (!in_array('website', $reg_fields))
				$exclude_fields = array_merge($exclude_fields, array('website_url', 'website_title'));

			// We used to accept signature on registration but it's being abused by spammers these days, so no more.
			$exclude_fields[] = 'signature';
		}
		else
			$exclude_fields = array('signature', 'location', 'gender', 'website_url', 'website_title');

		$possible_strings = array_diff($possible_strings, $exclude_fields);
		$possible_ints = array_diff($possible_ints, $exclude_fields);
		$possible_floats = array_diff($possible_floats, $exclude_fields);
		$possible_bools = array_diff($possible_bools, $exclude_fields);

		$extra_register_vars = array();

		// Include the additional options that might have been filled in.
		foreach ($possible_strings as $var)
			if (isset($this->http_req->post->$var))
				$extra_register_vars[$var] = $GLOBALS['elk']['text']->htmlspecialchars($this->http_req->post->$var, ENT_QUOTES);

		foreach ($possible_ints as $var)
			if (isset($this->http_req->post->$var))
				$extra_register_vars[$var] = (int) $this->http_req->post->$var;

		foreach ($possible_floats as $var)
			if (isset($this->http_req->post->$var))
				$extra_register_vars[$var] = (float) $this->http_req->post->$var;

		foreach ($possible_bools as $var)
			if (isset($this->http_req->post->$var))
				$extra_register_vars[$var] = empty($this->http_req->post->$var) ? 0 : 1;

		return $extra_register_vars;
	}

	/**
	 * Loads the registration agreement in the users language
	 *
	 * What it does:
	 * - Opens and loads the registration agreement file
	 * - If one is available in the users language loads that version as well
	 * - If none is found and it is required, ends the registration process and logs the error
	 * for the Admin to investigate.
	 */
	protected function _load_require_agreement()
	{
		global $context, $user_info, $txt;

		if ($context['require_agreement'])
		{
			$bbc_parser = $GLOBALS['elk']['bbc'];

			// Have we got a localized one?
			if (file_exists(BOARDDIR . '/agreement.' . $user_info['language'] . '.txt'))
				$context['agreement'] = $bbc_parser->parseAgreement(file_get_contents(BOARDDIR . '/agreement.' . $user_info['language'] . '.txt'));
			elseif (file_exists(BOARDDIR . '/agreement.txt'))
				$context['agreement'] = $bbc_parser->parseAgreement(file_get_contents(BOARDDIR . '/agreement.txt'));
			else
				$context['agreement'] = '';

			// Nothing to show, lets disable registration and inform the Admin of this error
			if (empty($context['agreement']))
			{
				// No file found or a blank file, log the error so the Admin knows there is a problem!
				$this->_errors->log_error($txt['registration_agreement_missing'], 'critical');
				$this->_errors->fatal_lang_error('registration_disabled', false);
			}
		}
	}

	/**
	 * Sets the users language file
	 *
	 * What it does:
	 * - If language support is enabled, loads whats available
	 * - Verifies the users choice is available
	 * - Sets in in context / session
	 */
	protected function _load_language_support()
	{
		global $context, $modSettings, $language;

		// Language support enabled
		if (!empty($modSettings['userLanguage']))
		{
			// Do we have any languages?
			$languages = getLanguages();

			if (isset($this->http_req->post->lngfile) && isset($languages[$this->http_req->post->lngfile]))
			{
				$_SESSION['language'] = $this->http_req->post->lngfile;
			}

			// No selected, or not found, use the site default
			$selectedLanguage = empty($_SESSION['language']) ? $language : $_SESSION['language'];

			// Try to find our selected language.
			foreach ($languages as $key => $lang)
			{
				$context['languages'][$key]['name'] = $lang['name'];

				// Found it!
				if ($selectedLanguage == $lang['filename'])
				{
					$context['languages'][$key]['selected'] = true;
				}
			}
		}
	}

	/**
	 * Load standard and custom registration profile fields
	 *
	 * @uses loadCustomFields() Loads standard fields in to context
	 * @uses setupProfileContext() Loads supplied fields in to context
	 */
	protected function _load_profile_fields()
	{
		global $context, $modSettings, $user_info, $cur_profile;

		// Any custom fields to load?
		$this->elk['profile']->loadCustomFields(0, 'register');

		// Or any standard ones?
		if (!empty($modSettings['registration_fields']))
		{
			// Setup some important context.
			loadLanguage('Profile');
			$this->_templates->load('Profile');

			$context['user']['is_owner'] = true;

			// Here, and here only, emulate the permissions the user would have to do this.
			$user_info['permissions'] = array_merge($user_info['permissions'], array('profile_account_own', 'profile_extra_own'));
			$reg_fields = explode(',', $modSettings['registration_fields']);

			// We might have had some submissions on this front - go check.
			foreach ($reg_fields as $field)
			{
				if (isset($this->http_req->post->$field))
				{
					$cur_profile[$field] = $GLOBALS['elk']['text']->htmlspecialchars($this->http_req->post->$field);
				}
			}

			// Load all the fields in question.
			setupProfileContext($reg_fields, 'registration');
		}
	}

	/**
	 * Verify the activation code, and activate the user if correct.
	 *
	 * What it does:
	 * - Accessed by ?action=register;sa=activate
	 * - Processes activation code requests
	 * - Checks if the user is already activate and if so does nothing
	 * - Prevents a user from using an existing email
	 */
	public function action_activate()
	{
		global $context, $txt, $modSettings, $user_info;

		require_once(SUBSDIR . '/Auth.subs.php');

		// Logged in users should not bother to activate their accounts
		if (!empty($user_info['id']))
			redirectexit();

		loadLanguage('Login');
		$this->_templates->load('Login');
		loadJavascriptFile('sha256.js', array('defer' => true));

		// Need a user id to activate
		if (empty($this->http_req->query->u) && empty($this->http_req->post->user))
		{
			// Immediate 0 or disabled 3 means no need to try and activate
			if (empty($modSettings['registration_method']) || $modSettings['registration_method'] == '3')
				$this->_errors->fatal_lang_error('no_access', false);

			// Otherwise its simply invalid
			$context['member_id'] = 0;
			$context['sub_template'] = 'resend';
			$context['page_title'] = $txt['invalid_activation_resend'];
			$context['can_activate'] = empty($modSettings['registration_method']) || $modSettings['registration_method'] == '1';
			$context['default_username'] = $this->http_req->getPost('user', 'trim', '');

			return;
		}

		// Get the user from the database...
		$this->_row = findUser(empty($this->http_req->query->u) ? '
			member_name = {string:email_address} OR email_address = {string:email_address}' : '
			id_member = {int:id_member}', array(
				'id_member' => $this->http_req->getQuery('u', 'intval', 0),
				'email_address' => $this->http_req->getPost('user', 'trim', ''),
			), false
		);

		// Does this user exist at all?
		if (empty($this->_row))
		{
			$context['sub_template'] = 'retry_activate';
			$context['page_title'] = $txt['invalid_userid'];
			$context['member_id'] = 0;

			return;
		}

		// Change their email address if not active 0 or awaiting reactivation 2? ( they probably tried a fake one first :P )
		$email_change = $this->_activate_change_email();

		// Resend the password, but only if the account wasn't activated yet (0 or 2)
		$this->_activate_resend($email_change);

		// Quit if this code is not right.
		if ($this->_activate_validate_code() === false)
			return;

		// Validation complete - update the database!
		approveMembers(array('members' => array($this->_row['id_member']), 'activated_status' => $this->_row['is_activated']));

		// Also do a proper member stat re-evaluation.
		updateMemberStats();

		if (!isset($this->http_req->post->new_email) && empty($this->_row['is_activated']))
		{

			sendAdminNotifications('activation', $this->_row['id_member'], $this->_row['member_name']);
		}

		$context += array(
			'page_title' => $txt['registration_successful'],
			'sub_template' => 'login',
			'default_username' => $this->_row['member_name'],
			'default_password' => '',
			'never_expire' => false,
			'description' => $txt['activate_success']
		);
	}

	/**
	 * Change their email address if not active
	 *
	 * What it does:
	 * - Requires the user enter the id/password for the account
	 * - The account must not be active 0 or awaiting reactivation 2
	 */
	protected function _activate_change_email()
	{
		global $modSettings, $txt;

		if (isset($this->http_req->post->new_email, $this->http_req->post->passwd)
			&& validateLoginPassword($this->http_req->post->passwd, $this->_row['passwd'], $this->_row['member_name'], true)
			&& ($this->_row['is_activated'] == 0 || $this->_row['is_activated'] == 2))
		{
			if (empty($modSettings['registration_method']) || $modSettings['registration_method'] == 3)
			{
				$this->_errors->fatal_lang_error('no_access', false);
			}

			// @todo Separate the sprintf?
			if (!DataValidator::is_valid($this->http_req->post, array('new_email' => 'valid_email|required|max_length[255]'), array('new_email' => 'trim')))
			{
				$this->_errors->fatal_error(sprintf($txt['valid_email_needed'], htmlspecialchars($this->http_req->post->new_email, ENT_COMPAT, 'UTF-8')), false);
			}

			// Make sure their email isn't banned.
			$this->elk['ban_check']->isBannedEmail($this->http_req->post->new_email, 'cannot_register', $txt['ban_register_prohibited']);

			// Ummm... don't take someone else's email during the change
			// @todo Separate the sprintf?
			if (userByEmail($this->http_req->post->new_email))
			{
				$this->_errors->fatal_lang_error('email_in_use', false, array(htmlspecialchars($this->http_req->post->new_email, ENT_COMPAT, 'UTF-8')));
			}

				updateMemberData($this->_row['id_member'], array('email_address' => $this->http_req->post->new_email));
			$this->_row['email_address'] = $this->http_req->post->new_email;

			return true;
		}

		return false;
	}

	/**
	 * Resend an activation code to a user
	 *
	 * What it does:
	 * - Called with action=register;sa=activate;resend
	 * - Will resend an activation code to non-active account
	 *
	 * @param bool $email_change if the email was changed or not
	 */
	protected function _activate_resend($email_change)
	{
		global $scripturl, $modSettings, $language, $txt, $context;

		if (isset($this->http_req->query->resend)
			&& ($this->_row['is_activated'] == 0 || $this->_row['is_activated'] == 2)
			&& $this->http_req->getPost('code', 'trim', '') === '')
		{


			$replacements = array(
				'REALNAME' => $this->_row['real_name'],
				'USERNAME' => $this->_row['member_name'],
				'ACTIVATIONLINK' => $scripturl . '?action=register;sa=activate;u=' . $this->_row['id_member'] . ';code=' . $this->_row['validation_code'],
				'ACTIVATIONLINKWITHOUTCODE' => $scripturl . '?action=register;sa=activate;u=' . $this->_row['id_member'],
				'ACTIVATIONCODE' => $this->_row['validation_code'],
				'FORGOTPASSWORDLINK' => $scripturl . '?action=reminder',
			);

			$emaildata = loadEmailTemplate('resend_activate_message', $replacements, empty($this->_row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $this->_row['lngfile']);
			sendmail($this->_row['email_address'], $emaildata['subject'], $emaildata['body'], null, null, false, 0);

			$context['page_title'] = $txt['invalid_activation_resend'];

			// This will ensure we don't actually get an error message if it works!
			$context['error_title'] = '';
			$this->_errors->fatal_lang_error(!empty($email_change) ? 'change_email_success' : 'resend_email_success', false);
		}
	}

	/**
	 * Validates a supplied activation code is valid
	 */
	protected function _activate_validate_code()
	{
		global $txt, $scripturl, $context;

		if ($this->http_req->getQuery('code', 'trim', '') != $this->_row['validation_code'])
		{
			if (!empty($this->_row['is_activated']) && $this->_row['is_activated'] == 1)
			{
				$this->_errors->fatal_lang_error('already_activated', false);
			}
			elseif ($this->_row['validation_code'] === '')
			{
				loadLanguage('Profile');
				$this->_errors->fatal_error($txt['registration_not_approved'] . ' <a href="' . $scripturl . '?action=register;sa=activate;user=' . $this->_row['member_name'] . '">' . $txt['here'] . '</a>.', false);
			}

			$context['sub_template'] = 'retry_activate';
			$context['page_title'] = $txt['invalid_activation_code'];
			$context['member_id'] = $this->_row['id_member'];

			return false;
		}

		return true;
	}

	/**
	 * Show the verification code or let it hear.
	 *
	 * - Accessed by ?action=register;sa=verificationcode
	 */
	public function action_verificationcode()
	{
		global $context, $scripturl;
	//	vid=register;rand=ef746ef2ee7ad37a35ce512cf9aa43d2;sound

		$verification_id = isset($this->http_req->query->vid) ? $this->http_req->query->vid : '';
		$code = $verification_id && isset($_SESSION[$verification_id . '_vv']) ? $_SESSION[$verification_id . '_vv']['code'] : (isset($_SESSION['visual_verification_code']) ? $_SESSION['visual_verification_code'] : '');

		// Somehow no code was generated or the session was lost.
		if (empty($code))
		{
			header('Content-Type: image/gif');
			die("\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B");
		}
		// Show a window that will play the verification code (play sound)
		elseif (isset($this->http_req->query->sound))
		{
			loadLanguage('Login');
			$this->_templates->load('Register');

			$context['verification_sound_href'] = $scripturl . '?action=register;sa=verificationcode;rand=' . md5(mt_rand()) . ($verification_id ? ';vid=' . $verification_id : '') . ';format=.wav';
			$context['sub_template'] = 'verification_sound';
			$this->_layers->removeAll();

			obExit();
		}
		// If we have GD, try the nice code. (new image)
		elseif (empty($this->http_req->query->format))
		{
			require_once(SUBSDIR . '/Graphics.subs.php');

			if (in_array('gd', get_loaded_extensions()) && !showCodeImage($code))
				header('HTTP/1.1 400 Bad Request');
			// Otherwise just show a pre-defined letter.
			elseif (isset($this->http_req->query->letter))
			{
				$this->http_req->query->letter = (int) $this->http_req->query->letter;
				if ($this->http_req->query->letter > 0 && $this->http_req->query->letter <= strlen($code) && !showLetterImage(strtolower($code{$this->http_req->query->letter - 1})))
				{
					header('Content-Type: image/gif');
					die("\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B");
				}
			}
			// You must be up to no good.
			else
			{
				header('Content-Type: image/gif');
				die("\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B");
			}
		}
		// Or direct link to the sound
		elseif ($this->http_req->query->format === '.wav')
		{
			require_once(SUBSDIR . '/Sound.subs.php');

			if (!createWaveFile($code))
				header('HTTP/1.1 400 Bad Request');
		}

		// Why die when we can exit to live another day...
		exit();
	}

	/**
	 * See if a username already exists.
	 *
	 * - Used by registration template via xml request
	 */
	public function action_registerCheckUsername()
	{
		global $context;

		// This is XML!
		$this->_templates->load('Xml');
		$context['sub_template'] = 'check_username';
		$context['checked_username'] = isset($this->http_req->query->username) ? un_htmlspecialchars($this->http_req->query->username) : '';
		$context['valid_username'] = true;

		// Clean it up like mother would.
		$context['checked_username'] = preg_replace('~[\t\n\r \x0B\0\x{A0}\x{AD}\x{2000}-\x{200F}\x{201F}\x{202F}\x{3000}\x{FEFF}]+~u', ' ', $context['checked_username']);

		$errors = ErrorContext::context('valid_username', 0);

		require_once(SUBSDIR . '/Auth.subs.php');
		validateUsername(0, $context['checked_username'], 'valid_username', true, false);

		$context['valid_username'] = !$errors->hasErrors();
	}


	/**
	 * This function will display the contact information for the forum, as well a form to fill in.
	 *
	 * - Accessed by action=register;sa=coppa
	 */
	public function action_coppa()
	{
		global $context, $modSettings, $txt;

		loadLanguage('Login');
		$this->_templates->load('Register');

		// No User ID??
		if (!isset($this->http_req->query->member))
			$this->_errors->fatal_lang_error('no_access', false);

		// Get the user details...
		$member = getBasicMemberData((int) $this->http_req->query->member, array('authentication' => true));

		// If doesn't exist or not pending coppa
		if (empty($member) || $member['is_activated'] != 5)
			$this->_errors->fatal_lang_error('no_access', false);

		if (isset($this->http_req->query->form))
		{
			// Some simple contact stuff for the forum.
			$context['forum_contacts'] = (!empty($modSettings['coppaPost']) ? $modSettings['coppaPost'] . '<br /><br />' : '') . (!empty($modSettings['coppaFax']) ? $modSettings['coppaFax'] . '<br />' : '');
			$context['forum_contacts'] = !empty($context['forum_contacts']) ? $context['forum_name_html_safe'] . '<br />' . $context['forum_contacts'] : '';

			// Showing template?
			if (!isset($this->http_req->query->dl))
			{
				// Shortcut for producing underlines.
				$context['ul'] = '<span class="underline">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>';
				$this->_layers->removeAll();
				$context['sub_template'] = 'coppa_form';
				$context['page_title'] = replaceBasicActionUrl($txt['coppa_form_title']);
				$context['coppa_body'] = str_replace(array('{PARENT_NAME}', '{CHILD_NAME}', '{USER_NAME}'), array($context['ul'], $context['ul'], $member['member_name']), replaceBasicActionUrl($txt['coppa_form_body']));
			}
			// Downloading.
			else
			{
				// The data.
				$ul = '                ';
				$crlf = "\r\n";
				$data = $context['forum_contacts'] . $crlf . $txt['coppa_form_address'] . ':' . $crlf . $txt['coppa_form_date'] . ':' . $crlf . $crlf . $crlf . replaceBasicActionUrl($txt['coppa_form_body']);
				$data = str_replace(array('{PARENT_NAME}', '{CHILD_NAME}', '{USER_NAME}', '<br>', '<br />'), array($ul, $ul, $member['member_name'], $crlf, $crlf), $data);

				// Send the headers.
				header('Connection: close');
				header('Content-Disposition: attachment; filename="approval.txt"');
				header('Content-Type: ' . (isBrowser('ie') || isBrowser('opera') ? 'application/octetstream' : 'application/octet-stream'));
				header('Content-Length: ' . count($data));

				echo $data;
				obExit(false);
			}
		}
		else
		{
			$context += array(
				'page_title' => $txt['coppa_title'],
				'sub_template' => 'coppa',
			);

			$context['coppa'] = array(
				'body' => str_replace('{MINIMUM_AGE}', $modSettings['coppaAge'], replaceBasicActionUrl($txt['coppa_after_registration'])),
				'many_options' => !empty($modSettings['coppaPost']) && !empty($modSettings['coppaFax']),
				'post' => empty($modSettings['coppaPost']) ? '' : $modSettings['coppaPost'],
				'fax' => empty($modSettings['coppaFax']) ? '' : $modSettings['coppaFax'],
				'phone' => empty($modSettings['coppaPhone']) ? '' : str_replace('{PHONE_NUMBER}', $modSettings['coppaPhone'], $txt['coppa_send_by_phone']),
				'id' => $this->http_req->query->member,
			);
		}
	}
}