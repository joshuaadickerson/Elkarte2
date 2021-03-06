<?php

/**
 * Handles sending out of password reminders, as well as the answer / question
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

if (!defined('ELK'))
	die('No access...');

/**
 * Reminder Controller handles sending out reminders, and checking the secret answer and question.
 */
class ReminderController extends AbstractController
{
	/**
	 * This is the pre-dispatch function
	 *
	 * @uses Profile language files and Reminder template
	 */
	public function pre_dispatch()
	{
		global $txt, $context;

		loadLanguage('Profile');
		$this->_templates->load('Reminder');

		$context['page_title'] = $txt['authentication_reminder'];
		$context['robot_no_index'] = true;
	}

	/**
	 * Default action for reminder.
	 *
	 * @uses template_reminder() sub template
	 */
	public function action_index()
	{
		global $context;

		$context['sub_template'] = 'reminder';

		// Nothing to do, the template will ask for an action to pick
		createToken('remind');
	}

	/**
	 * Pick a reminder type.
	 *
	 * Accessed by sa=picktype
	 */
	public function action_picktype()
	{
		global $context, $txt, $scripturl, $user_info, $webmaster_email, $language, $modSettings;

		// Security
		$this->session->check();
		//validateToken('remind');
		createToken('remind');

		require_once(SUBSDIR . '/Auth.subs.php');

		// No where params just yet
		$where_params = array();
		$where = '';

		// Coming with a known ID?
		if (!empty($this->http_req->post->uid))
		{
			$where = 'id_member = {int:id_member}';
			$where_params['id_member'] = (int) $this->http_req->post->uid;
		}
		elseif ($this->http_req->getPost('user') !== '')
		{
			$where = 'member_name = {string:member_name}';
			$where_params['member_name'] = $this->http_req->post->user;
			$where_params['email_address'] = $this->http_req->post->user;
		}

		// You must enter a username/email address.
		if (empty($where))
			$this->_errors->fatal_lang_error('username_no_exist', false);

		// Make sure we are not being slammed
		// Don't call this if you're coming from the "Choose a reminder type" page - otherwise you'll likely get an error
		if (!isset($this->http_req->post->reminder_type) || !in_array($this->http_req->post->reminder_type, array('email', 'secret')))
			spamProtection('remind');

		// Find this member
		$member = findUser($where, $where_params);

		$context['account_type'] = !empty($member['openid_uri']) ? 'openid' : 'password';

		// If the user isn't activated/approved, give them some feedback on what to do next.
		if ($member['is_activated'] != 1)
		{
			// Awaiting approval...
			if (trim($member['validation_code']) === '')
				$this->_errors->fatal_error($txt['registration_not_approved'] . ' <a href="' . $scripturl . '?action=register;sa=activate;user=' . $this->http_req->post->user . '">' . $txt['here'] . '</a>.', false);
			else
				$this->_errors->fatal_error($txt['registration_not_activated'] . ' <a href="' . $scripturl . '?action=register;sa=activate;user=' . $this->http_req->post->user . '">' . $txt['here'] . '</a>.', false);
		}

		// You can't get emailed if you have no email address.
		$member['email_address'] = trim($member['email_address']);
		if ($member['email_address'] === '')
			$this->_errors->fatal_error($txt['no_reminder_email'] . '<br />' . $txt['send_email'] . ' <a href="mailto:' . $webmaster_email . '">webmaster</a> ' . $txt['to_ask_password'] . '.');

		// If they have no secret question then they can only get emailed the item, or they are requesting the email, send them an email.
		if (empty($member['secret_question'])
			|| ($this->http_req->getPost('reminder_type') === 'email'))
		{
			// Randomly generate a new password, with only alpha numeric characters that is a max length of 10 chars.
			$password = generateValidationCode();


			$replacements = array(
				'REALNAME' => $member['real_name'],
				'REMINDLINK' => $scripturl . '?action=reminder;sa=setpassword;u=' . $member['id_member'] . ';code=' . $password,
				'IP' => $user_info['ip'],
				'MEMBERNAME' => $member['member_name'],
				'OPENID' => $member['openid_uri'],
			);

			$emaildata = loadEmailTemplate('forgot_' . $context['account_type'], $replacements, empty($member['lngfile']) || empty($modSettings['userLanguage']) ? $language : $member['lngfile']);
			$context['description'] = $txt['reminder_' . (!empty($member['openid_uri']) ? 'openid_' : '') . 'sent'];

			// If they were using OpenID simply email them their OpenID identity.
			sendmail($member['email_address'], $emaildata['subject'], $emaildata['body'], null, null, false, 1);

			if (empty($member['openid_uri']))
			{
						// Set the password in the database.
				updateMemberData($member['id_member'], array('validation_code' => substr(md5($password), 0, 10)));
			}

			// Set up the template.
			$context['sub_template'] = 'sent';

			// Don't really.
			return;
		}
		// Otherwise are ready to answer the question?
		elseif (isset($this->http_req->post->reminder_type) && $this->http_req->post->reminder_type === 'secret')
			return secretAnswerInput();

		// No we're here setup the context for template number 2!
		$context['sub_template'] = 'reminder_pick';
		$context['current_member'] = array(
			'id' => $member['id_member'],
			'name' => $member['member_name'],
		);
	}

	/**
	 * Set your new password after you asked for a reset link
	 *
	 * sa=setpassword
	 */
	public function action_setpassword()
	{
		global $txt, $context;

		loadLanguage('Login');

		// You need a code!
		if (!isset($this->http_req->query->code))
			$this->_errors->fatal_lang_error('no_access', false);

		// Fill the context array.
		$context += array(
			'page_title' => $txt['reminder_set_password'],
			'sub_template' => 'set_password',
			'code' => $this->http_req->query->code,
			'memID' => (int) $this->http_req->query->u
		);

		// Some extra js is needed
		loadJavascriptFile('register.js');

		// Tokens!
		createToken('remind-sp');
	}

	/**
	 * Handle the password change.
	 *
	 * sa=setpassword2
	 */
	public function action_setpassword2()
	{
		global $context, $txt;

		$this->session->check();
		validateToken('remind-sp');

		if (empty($this->http_req->post->u) || !isset($this->http_req->post->passwrd1) || !isset($this->http_req->post->passwrd2))
			$this->_errors->fatal_lang_error('no_access', false);

		$this->http_req->post->u = (int) $this->http_req->post->u;

		if ($this->http_req->post->passwrd1 != $this->http_req->post->passwrd2)
			$this->_errors->fatal_lang_error('passwords_dont_match', false);

		if ($this->http_req->post->passwrd1 === '')
			$this->_errors->fatal_lang_error('no_password', false);

		loadLanguage('Login');

		// Get the code as it should be from the database.
		$member = getBasicMemberData((int) $this->http_req->post->u, array('authentication' => true));

		// Does this user exist at all? Is he activated? Does he have a validation code?
		if (empty($member) || $member['is_activated'] != 1 || $member['validation_code'] === '')
			$this->_errors->fatal_lang_error('invalid_userid', false);

		// Is the password actually valid?
		require_once(SUBSDIR . '/Auth.subs.php');
		$passwordError = validatePassword($this->http_req->post->passwrd1, $member['member_name'], array($member['email_address']));

		// What - it's not?
		if ($passwordError != null)
			$this->_errors->fatal_lang_error('profile_error_password_' . $passwordError, false);

		// Quit if this code is not right.
		if (empty($this->http_req->post->code) || substr($member['validation_code'], 0, 10) !== substr(md5($this->http_req->post->code), 0, 10))
		{
			// Stop brute force attacks like this.
			validatePasswordFlood($this->http_req->post->u, $member['passwd_flood'], false);

			$this->_errors->fatal_error($txt['invalid_activation_code'], false);
		}

		// Just in case, flood control.
		validatePasswordFlood($this->http_req->post->u, $member['passwd_flood'], true);

		// User validated.  Update the database!
		require_once(SUBSDIR . '/Auth.subs.php');
		$sha_passwd = $this->http_req->post->passwrd1;
		if (isset($this->http_req->post->otp))
			updateMemberData($this->http_req->post->u, array('validation_code' => '', 'passwd' => validateLoginPassword($sha_passwd, '', $member['member_name'], true), 'enable_otp' => 0));
		else
			updateMemberData($this->http_req->post->u, array('validation_code' => '', 'passwd' => validateLoginPassword($sha_passwd, '', $member['member_name'], true)));

		$GLOBALS['elk']['hooks']->hook('reset_pass', array($member['member_name'], $member['member_name'], $this->http_req->post->passwrd1));

		$this->_templates->load('Login');
		loadJavascriptFile('sha256.js', array('defer' => true));
		$context += array(
			'page_title' => $txt['reminder_password_set'],
			'sub_template' => 'login',
			'default_username' => $member['member_name'],
			'default_password' => $this->http_req->post->passwrd1,
			'never_expire' => false,
			'description' => $txt['reminder_password_set']
		);
		createToken('login');
	}

	/**
	 * Verify the answer to the secret question.
	 * Accessed with sa=secret2
	 */
	public function action_secret2()
	{
		global $txt, $context;

		$this->session->check();
		validateToken('remind-sai');

		// Hacker?  How did you get this far without an email or username?
		if (empty($this->http_req->post->uid))
			$this->_errors->fatal_lang_error('username_no_exist', false);

		loadLanguage('Login');

		// Get the information from the database.
		$member = getBasicMemberData((int) $this->http_req->post->uid, array('authentication' => true));
		if (empty($member))
			$this->_errors->fatal_lang_error('username_no_exist', false);

		// Check if the secret answer is correct.
		if ($member['secret_question'] === '' || $member['secret_answer'] === '' || md5($this->http_req->post->secret_answer) !== $member['secret_answer'])
		{
			$this->_errors->log_error(sprintf($txt['reminder_error'], $member['member_name']), 'user');
			$this->_errors->fatal_lang_error('incorrect_answer', false);
		}

		// If it's OpenID this is where the music ends.
		if (!empty($member['openid_uri']))
		{
			$context['sub_template'] = 'sent';
			$context['description'] = sprintf($txt['reminder_openid_is'], $member['openid_uri']);
			return;
		}

		// You can't use a blank one!
		if (strlen(trim($this->http_req->post->passwrd1)) === 0)
			$this->_errors->fatal_lang_error('no_password', false);

		// They have to be the same too.
		if ($this->http_req->post->passwrd1 != $this->http_req->post->passwrd2)
			$this->_errors->fatal_lang_error('passwords_dont_match', false);

		// Make sure they have a strong enough password.
		require_once(SUBSDIR . '/Auth.subs.php');
		$passwordError = validatePassword($this->http_req->post->passwrd1, $member['member_name'], array($member['email_address']));

		// Invalid?
		if ($passwordError != null)
			$this->_errors->fatal_lang_error('profile_error_password_' . $passwordError, false);

		// Alright, so long as 'yer sure.
		require_once(SUBSDIR . '/Auth.subs.php');
		$sha_passwd = $this->http_req->post->passwrd1;
		updateMemberData($member['id_member'], array('passwd' => validateLoginPassword($sha_passwd, '', $member['member_name'], true)));

		$GLOBALS['elk']['hooks']->hook('reset_pass', array($member['member_name'], $member['member_name'], $this->http_req->post->passwrd1));

		// Tell them it went fine.
		$this->_templates->load('Login');
		loadJavascriptFile('sha256.js', array('defer' => true));
		$context += array(
			'page_title' => $txt['reminder_password_set'],
			'sub_template' => 'login',
			'default_username' => $member['member_name'],
			'default_password' => $this->http_req->post->passwrd1,
			'never_expire' => false,
			'description' => $txt['reminder_password_set']
		);

		createToken('login');
	}
}

/**
 * Get the secret answer.
 */
function secretAnswerInput()
{
	global $context;

	$this->session->check();

	// Strings for the register auto javascript clever stuffy wuffy.
	loadLanguage('Login');

	// Check they entered something...
	if (empty($this->http_req->post->uid))
		$this->_errors->fatal_lang_error('username_no_exist', false);

	// Get the stuff....

	$member = getBasicMemberData((int) $this->http_req->post->uid, array('authentication' => true));
	if (empty($member))
		$this->_errors->fatal_lang_error('username_no_exist', false);

	$context['account_type'] = !empty($member['openid_uri']) ? 'openid' : 'password';

	// If there is NO secret question - then throw an error.
	if (trim($member['secret_question']) === '')
		$this->_errors->fatal_lang_error('registration_no_secret_question', false);

	// Ask for the answer...
	$context['remind_user'] = $member['id_member'];
	$context['remind_type'] = '';
	$context['secret_question'] = $member['secret_question'];
	$context['sub_template'] = 'ask';

	loadJavascriptFile('register.js');

	createToken('remind-sai');
}