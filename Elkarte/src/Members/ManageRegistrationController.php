<?php

/**
 * This file helps the administrator setting registration settings and policy
 * as well as allow the administrator to register new members themselves.
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

/**
 * ManageRegistration Admin controller: handles the registration pages
 *
 * - allow admins (or moderators with moderate_forum permission)
 * to register a new member,
 * - to see and edit the registration agreement,
 * - to set up reserved words for forum names.
 *
 * @package Registration
 */
class ManageRegistrationController extends AbstractController
{
	/**
	 * Registration settings form
	 * @var SettingsForm
	 */
	protected $_registerSettings;

	/**
	 * Entrance point for the registration center, it checks permissions and forwards
	 * to the right method based on the subaction.
	 *
	 * - Accessed by ?action=Admin;area=regcenter.
	 * - Requires either the moderate_forum or the admin_forum permission.
	 *
	 * @uses Login language file
	 * @uses Register template.
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		global $context, $txt;

		// Loading, always loading.
		loadLanguage('Login');
		$this->templates->load('Register');
		loadJavascriptFile('register.js');

		$subActions = array(
			'register' => array(
				'controller' => $this,
				'function' => 'action_register',
				'permission' => 'moderate_forum',
			),
			'agreement' => array(
				'controller' => $this,
				'function' => 'action_agreement',
				'permission' => 'admin_forum',
			),
			'reservednames' => array(
				'controller' => $this,
				'function' => 'action_reservednames',
				'permission' => 'admin_forum',
			),
			'settings' => array(
				'controller' => $this,
				'function' => 'action_registerSettings_display',
				'permission' => 'admin_forum',
			),
		);

		// Action controller
		$action = new Action('manage_registrations');

		// Next create the tabs for the template.
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['registration_center'],
			'help' => 'registrations',
			'description' => $txt['admin_settings_desc'],
			'tabs' => array(
				'register' => array(
					'description' => $txt['admin_register_desc'],
				),
				'agreement' => array(
					'description' => $txt['registration_agreement_desc'],
				),
				'reservednames' => array(
					'description' => $txt['admin_reserved_desc'],
				),
				'settings' => array(
					'description' => $txt['admin_settings_desc'],
				)
			)
		);

		// Work out which to call... call integrate_sa_manage_registrations
		$subAction = $action->initialize($subActions, 'register');

		// Final bits
		$context['page_title'] = $txt['maintain_title'];
		$context['sub_action'] = $subAction;

		// Call the right function for this sub-action.
		$action->dispatch($subAction);
	}

	/**
	 * This function allows the Admin to register a new member by hand.
	 *
	 * - It also allows assigning a primary group to the member being registered.
	 * - Accessed by ?action=Admin;area=regcenter;sa=register
	 * - Requires the moderate_forum permission.
	 *
	 * @uses Register template, admin_register sub-template.
	 */
	public function action_register()
	{
		global $txt, $context, $scripturl, $user_info;

		if (!empty($this->http_req->post->regSubmit))
		{
			$this->session->check();
			validateToken('Admin-regc');

			// @todo move this to a filter/sanitation class
			foreach ($this->http_req->post as $key => $value)
				if (!is_array($value))
					$this->http_req->post[$key] = htmltrim__recursive(str_replace(array("\n", "\r"), '', $value));

			// Generate a password
			if (empty($this->http_req->post->password) || !is_string($this->http_req->post->password) || trim($this->http_req->post->password) === '')
			{
				mt_srand(time() + 1277);
				$password = generateValidationCode();
			}
			else
			{
				$password = $this->http_req->post->password;
			}

			$regOptions = array(
				'interface' => 'Admin',
				'username' => $this->http_req->post->user,
				'email' => $this->http_req->post->email,
				'password' => $password,
				'password_check' => $password,
				'check_reserved_name' => true,
				'check_password_strength' => true,
				'check_email_ban' => false,
				'send_welcome_email' => isset($this->http_req->post->emailPassword),
				'require' => isset($this->http_req->post->emailActivate) ? 'activation' : 'nothing',
				'memberGroup' => empty($this->http_req->post->group) || !allowedTo('manage_membergroups') ? 0 : (int) $this->http_req->post->group,
				'ip' => '127.0.0.1',
				'ip2' => '127.0.0.1',
				'auth_method' => 'password',
			);

				$reg_errors = ErrorContext::context('register', 0);
			$memberID = registerMember($regOptions, 'register');

			// If there are "important" errors and you are not an Admin: log the first error
			// Otherwise grab all of them and don't log anything
			$error_severity = $reg_errors->hasErrors(1) && !$user_info['is_admin'] ? 1 : null;
			foreach ($reg_errors->prepareErrors($error_severity) as $error)
				$GLOBALS['elk']['errors']->fatal_error($error, $error_severity === null ? false : 'general');

			if (!empty($memberID))
			{
				$context['new_member'] = array(
					'id' => $memberID,
					'name' => $this->http_req->post->user,
					'href' => $scripturl . '?action=profile;u=' . $memberID,
					'link' => '<a href="' . $scripturl . '?action=profile;u=' . $memberID . '">' . $this->http_req->post->user . '</a>',
				);
				$context['registration_done'] = sprintf($txt['admin_register_done'], $context['new_member']['link']);
			}
		}

		// Load the assignable member groups.
		if (allowedTo('manage_membergroups'))
		{

			if (allowedTo('admin_forum'))
				$includes = array('Admin', 'globalmod', 'member');
			else
				$includes = array('globalmod', 'member', 'custom');

			$groups = array();
			$membergroups = getBasicMembergroupData($includes, array('hidden', 'protected'));
			foreach ($membergroups as $membergroup)
				$groups[$membergroup['id']] = $membergroup['name'];

			$context['member_groups'] = $groups;
		}
		else
			$context['member_groups'] = array();

		// Basic stuff.
		theme()->addInlineJavascript('disableAutoComplete();', true);
		$context['sub_template'] = 'admin_register';
		$context['page_title'] = $txt['registration_center'];
		createToken('Admin-regc');
	}

	/**
	 * Allows the administrator to edit the registration agreement, and choose whether
	 * it should be shown or not.
	 *
	 * - It writes and saves the agreement to the agreement.txt file.
	 * - Accessed by ?action=Admin;area=regcenter;sa=agreement.
	 * - Requires the admin_forum permission.
	 *
	 * @uses Admin template and the edit_agreement sub template.
	 */
	public function action_agreement()
	{
		// I hereby agree not to be a lazy bum.
		global $txt, $context, $modSettings;

		// By default we look at agreement.txt.
		$context['current_agreement'] = '';

		// Is there more than one to edit?
		$context['editable_agreements'] = array(
			'' => $txt['admin_agreement_default'],
		);

		// Get our languages.
		$languages = getLanguages();

		// Try to figure out if we have more agreements.
		foreach ($languages as $lang)
		{
			if (file_exists(BOARDDIR . '/agreement.' . $lang['filename'] . '.txt'))
			{
				$context['editable_agreements']['.' . $lang['filename']] = $lang['name'];

				// Are we editing this?
				if (isset($this->http_req->post->agree_lang) && $this->http_req->post->agree_lang == '.' . $lang['filename'])
					$context['current_agreement'] = '.' . $lang['filename'];
			}
		}

		if (isset($this->http_req->post->save) && isset($this->http_req->post->agreement))
		{
			$this->session->check();
			validateToken('Admin-rega');

			// Off it goes to the agreement file.
			$fp = fopen(BOARDDIR . '/agreement' . $context['current_agreement'] . '.txt', 'w');
			fwrite($fp, str_replace("\r", '', $this->http_req->post->agreement));
			fclose($fp);

			updateSettings(array('requireAgreement' => !empty($this->http_req->post->requireAgreement), 'checkboxAgreement' => !empty($this->http_req->post->checkboxAgreement)));
		}

		$context['agreement'] = file_exists(BOARDDIR . '/agreement' . $context['current_agreement'] . '.txt') ? htmlspecialchars(file_get_contents(BOARDDIR . '/agreement' . $context['current_agreement'] . '.txt'), ENT_COMPAT, 'UTF-8') : '';
		$context['warning'] = is_writable(BOARDDIR . '/agreement' . $context['current_agreement'] . '.txt') ? '' : $txt['agreement_not_writable'];
		$context['require_agreement'] = !empty($modSettings['requireAgreement']);
		$context['checkbox_agreement'] = !empty($modSettings['checkboxAgreement']);

		$context['sub_template'] = 'edit_agreement';
		$context['page_title'] = $txt['registration_agreement'];
		createToken('Admin-rega');
	}

	/**
	 * Set the names under which users are not allowed to register.
	 *
	 * - Accessed by ?action=Admin;area=regcenter;sa=reservednames.
	 * - Requires the admin_forum permission.
	 *
	 * @uses Register template, reserved_words sub-template.
	 */
	public function action_reservednames()
	{
		global $txt, $context, $modSettings;

		// Submitting new reserved words.
		if (!empty($this->http_req->post->save_reserved_names))
		{
			$this->session->check();
			validateToken('Admin-regr');

			// Set all the options....
			updateSettings(array(
				'reserveWord' => (isset($this->http_req->post->matchword) ? '1' : '0'),
				'reserveCase' => (isset($this->http_req->post->matchcase) ? '1' : '0'),
				'reserveUser' => (isset($this->http_req->post->matchuser) ? '1' : '0'),
				'reserveName' => (isset($this->http_req->post->matchname) ? '1' : '0'),
				'reserveNames' => str_replace("\r", '', $this->http_req->post->reserved)
			));
		}

		// Get the reserved word options and words.
		$modSettings['reserveNames'] = str_replace('\n', "\n", $modSettings['reserveNames']);
		$context['reserved_words'] = explode("\n", $modSettings['reserveNames']);
		$context['reserved_word_options'] = array();
		$context['reserved_word_options']['match_word'] = $modSettings['reserveWord'] == '1';
		$context['reserved_word_options']['match_case'] = $modSettings['reserveCase'] == '1';
		$context['reserved_word_options']['match_user'] = $modSettings['reserveUser'] == '1';
		$context['reserved_word_options']['match_name'] = $modSettings['reserveName'] == '1';

		// Ready the template......
		$context['sub_template'] = 'edit_reserved_words';
		$context['page_title'] = $txt['admin_reserved_set'];
		createToken('Admin-regr');
	}

	/**
	 * This function handles registration settings, and provides a few pretty stats too while it's at it.
	 *
	 * - General registration settings and Coppa compliance settings.
	 * - Accessed by ?action=Admin;area=regcenter;sa=settings.
	 * - Requires the admin_forum permission.
	 */
	public function action_registerSettings_display()
	{
		global $txt, $context, $scripturl, $modSettings;

		// Initialize the form
		$this->_init_registerSettingsForm();

		$config_vars = $this->_registerSettings->settings();

		// Setup the template
		$context['sub_template'] = 'show_settings';
		$context['page_title'] = $txt['registration_center'];

		if (isset($this->http_req->query->save))
		{
			$this->session->check();

			// Are there some contacts missing?
			if (!empty($this->http_req->post->coppaAge) && !empty($this->http_req->post->coppaType) && empty($this->http_req->post->coppaPost) && empty($this->http_req->post->coppaFax))
				$GLOBALS['elk']['errors']->fatal_lang_error('admin_setting_coppa_require_contact');

			// Post needs to take into account line breaks.
			$this->http_req->post->coppaPost = str_replace("\n", '<br />', empty($this->http_req->post->coppaPost) ? '' : $this->http_req->post->coppaPost);

			$GLOBALS['elk']['hooks']->hook('save_registration_settings');

			SettingsForm::save_db($config_vars, $this->http_req->post);

			redirectexit('action=Admin;area=regcenter;sa=settings');
		}

		$context['post_url'] = $scripturl . '?action=Admin;area=regcenter;save;sa=settings';
		$context['settings_title'] = $txt['settings'];

		// Define some javascript for COPPA.
		theme()->addInlineJavascript('
			function checkCoppa()
			{
				var coppaDisabled = document.getElementById(\'coppaAge\').value == 0;
				document.getElementById(\'coppaType\').disabled = coppaDisabled;

				var disableContacts = coppaDisabled || document.getElementById(\'coppaType\').options[document.getElementById(\'coppaType\').selectedIndex].value != 1;
				document.getElementById(\'coppaPost\').disabled = disableContacts;
				document.getElementById(\'coppaFax\').disabled = disableContacts;
				document.getElementById(\'coppaPhone\').disabled = disableContacts;
			}
			checkCoppa();', true);

		// Turn the postal address into something suitable for a textbox.
		$modSettings['coppaPost'] = !empty($modSettings['coppaPost']) ? preg_replace('~<br ?/?' . '>~', "\n", $modSettings['coppaPost']) : '';

		SettingsForm::prepare_db($config_vars);
	}

	/**
	 * Initialize settings form with the configuration settings for new members registration.
	 */
	protected function _init_registerSettingsForm()
	{
		// Instantiate the form
		$this->_registerSettings = new SettingsForm();

		// Initialize it with our settings
		$config_vars = $this->_settings();

		return $this->_registerSettings->settings($config_vars);
	}

	/**
	 * Return configuration settings for new members registration.
	 */
	protected function _settings()
	{
		global $txt;

		$config_vars = array(
				array('select', 'registration_method', array($txt['setting_registration_standard'], $txt['setting_registration_activate'], $txt['setting_registration_approval'], $txt['setting_registration_disabled'])),
				array('check', 'enableOpenID'),
				array('check', 'notify_new_registration'),
				array('check', 'send_welcomeEmail'),
			'',
				array('int', 'coppaAge', 'subtext' => $txt['setting_coppaAge_desc'], 'onchange' => 'checkCoppa();', 'onkeyup' => 'checkCoppa();'),
				array('select', 'coppaType', array($txt['setting_coppaType_reject'], $txt['setting_coppaType_approval']), 'onchange' => 'checkCoppa();'),
				array('large_text', 'coppaPost', 'subtext' => $txt['setting_coppaPost_desc']),
				array('text', 'coppaFax'),
				array('text', 'coppaPhone'),
		);

		// Add new settings with a nice hook, makes them available for Admin settings search as well
		$GLOBALS['elk']['hooks']->hook('modify_registration_settings', array(&$config_vars));

		return $config_vars;
	}

	/**
	 * Return the registration settings for use in Admin search
	 */
	public function settings_search()
	{
		return $this->_settings();
	}
}