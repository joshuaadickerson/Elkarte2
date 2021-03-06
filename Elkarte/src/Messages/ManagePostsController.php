<?php

/**
 * Handles all the administration settings for topics and posts.
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

namespace Elkarte\Messages;

/**
 * ManagePosts controller handles all the administration settings for topics and posts.
 *
 * @package Posts
 */
class ManagePostsController extends AbstractController
{
	/**
	 * Posts settings form
	 * @var SettingsForm
	 */
	protected $_postSettings;

	/**
	 * The main entrance point for the 'Posts and topics' screen.
	 *
	 * What it does:
	 * - Like all others, it checks permissions, then forwards to the right function
	 * based on the given sub-action.
	 * - Defaults to sub-action 'posts'.
	 * - Accessed from ?action=Admin;area=postsettings.
	 * - Requires (and checks for) the admin_forum permission.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		global $context, $txt;

		$subActions = array(
			'posts' => array(
				$this, 'action_postSettings_display', 'permission' => 'admin_forum'),
			'bbc' => array(
				'function' => 'action_index',
				'controller' => 'ManageBBCController',
				'permission' => 'admin_forum'),
			'censor' => array(
				$this, 'action_censor', 'permission' => 'admin_forum'),
			'topics' => array(
				'function' => 'action_index',
				'controller' => 'ManageTopicsController',
				'permission' => 'admin_forum'),
		);

		// Good old action handle
		$action = new Action('manage_posts');

		// Tabs for browsing the different post functions.
		$context[$context['admin_menu_name']]['tab_data'] = array(
			'title' => $txt['manageposts_title'],
			'help' => 'posts_and_topics',
			'description' => $txt['manageposts_description'],
			'tabs' => array(
				'posts' => array(
					'description' => $txt['manageposts_settings_description'],
				),
				'bbc' => array(
					'description' => $txt['manageposts_bbc_settings_description'],
				),
				'censor' => array(
					'description' => $txt['admin_censored_desc'],
				),
				'topics' => array(
					'description' => $txt['manageposts_topic_settings_description'],
				),
			),
		);

		// Default the sub-action to 'posts'. call integrate_sa_manage_posts
		$subAction = $action->initialize($subActions, 'posts');

		// Just for the template
		$context['page_title'] = $txt['manageposts_title'];
		$context['sub_action'] = $subAction;

		// Call the right function for this sub-action.
		$action->dispatch($subAction);
	}

	/**
	 * Shows an interface to set and test censored words.
	 *
	 * - It uses the censor_vulgar, censor_proper, censorWholeWord, and
	 * censorIgnoreCase settings.
	 * - Requires the admin_forum permission.
	 * - Accessed from ?action=Admin;area=postsettings;sa=censor.
	 *
	 * @uses the Admin template and the edit_censored sub template.
	 */
	public function action_censor()
	{
		global $txt, $modSettings, $context;

		if (!empty($this->http_req->post->save_censor))
		{
			// Make sure censoring is something they can do.
			$this->session->check();
			validateToken('Admin-censor');

			$censored_vulgar = array();
			$censored_proper = array();

			// Rip it apart, then split it into two arrays.
			if (isset($this->http_req->post->censortext))
			{
				$this->http_req->post->censortext = explode("\n", strtr($this->http_req->post->censortext, array("\r" => '')));

				foreach ($this->http_req->post->censortext as $c)
					list ($censored_vulgar[], $censored_proper[]) = array_pad(explode('=', trim($c)), 2, '');
			}
			elseif (isset($this->http_req->post->censor_vulgar, $this->http_req->post->censor_proper))
			{
				if (is_array($this->http_req->post->censor_vulgar))
				{
					foreach ($this->http_req->post->censor_vulgar as $i => $value)
					{
						if (trim(strtr($value, '*', ' ')) == '')
							unset($this->http_req->post->censor_vulgar[$i], $this->http_req->post->censor_proper[$i]);
					}

					$censored_vulgar = $this->http_req->post->censor_vulgar;
					$censored_proper = $this->http_req->post->censor_proper;
				}
				else
				{
					$censored_vulgar = explode("\n", strtr($this->http_req->post->censor_vulgar, array("\r" => '')));
					$censored_proper = explode("\n", strtr($this->http_req->post->censor_proper, array("\r" => '')));
				}
			}

			// Set the new arrays and settings in the database.
			$updates = array(
				'censor_vulgar' => implode("\n", $censored_vulgar),
				'censor_proper' => implode("\n", $censored_proper),
				'censorWholeWord' => empty($this->http_req->post->censorWholeWord) ? '0' : '1',
				'censorIgnoreCase' => empty($this->http_req->post->censorIgnoreCase) ? '0' : '1',
				'allow_no_censored' => empty($this->http_req->post->allow_no_censored) ? '0' : '1',
			);

			$GLOBALS['elk']['hooks']->hook('save_censors', array(&$updates));

			updateSettings($updates);
		}

		// Testing a word to see how it will be censored?
		if (isset($this->http_req->post->censortest))
		{

			$censorText = htmlspecialchars($this->http_req->post->censortest, ENT_QUOTES, 'UTF-8');
			preparsecode($censorText);
			$pre_censor = $censorText;
			$context['censor_test'] = strtr(censor($censorText), array('"' => '&quot;'));
		}

		// Set everything up for the template to do its thang.
		$censor_vulgar = explode("\n", $modSettings['censor_vulgar']);
		$censor_proper = explode("\n", $modSettings['censor_proper']);

		$context['censored_words'] = array();
		for ($i = 0, $n = count($censor_vulgar); $i < $n; $i++)
		{
			if (empty($censor_vulgar[$i]))
				continue;

			// Skip it, it's either spaces or stars only.
			if (trim(strtr($censor_vulgar[$i], '*', ' ')) == '')
				continue;

			$context['censored_words'][htmlspecialchars(trim($censor_vulgar[$i]))] = isset($censor_proper[$i]) ? htmlspecialchars($censor_proper[$i], ENT_COMPAT, 'UTF-8') : '';
		}

		$GLOBALS['elk']['hooks']->hook('censors');
		createToken('Admin-censor');

		// Using ajax?
		if (isset($this->http_req->query->xml, $this->http_req->post->censortest))
		{
			// Clear the templates
			$this->_layers->removeAll();

			// Send back a response
			$this->_templates->load('Json');
			$context['sub_template'] = 'send_json';
			$context['json_data'] = array(
				'result' => true,
				'censor' => $pre_censor . ' <i class="fa fa-arrow-circle-right"></i> ' . $context['censor_test'],
				'token_val' => $context['Admin-censor_token_var'],
				'token' => $context['Admin-censor_token'],
			);
		}
		else
		{
			$context['sub_template'] = 'edit_censored';
			$context['page_title'] = $txt['admin_censored_words'];
		}
	}

	/**
	 * Modify any setting related to posts and posting.
	 *
	 * - Requires the admin_forum permission.
	 * - Accessed from ?action=Admin;area=postsettings;sa=posts.
	 *
	 * @uses Admin template, edit_post_settings sub-template.
	 */
	public function action_postSettings_display()
	{
		global $context, $txt, $modSettings, $scripturl;

		// Initialize the form
		$this->_initPostSettingsForm();

		$config_vars = $this->_postSettings->settings();

		// Setup the template.
		$context['page_title'] = $txt['manageposts_settings'];
		$context['sub_template'] = 'show_settings';

		// Are we saving them - are we??
		if (isset($this->http_req->query->save))
		{
			$this->session->check();

			// If we're changing the message length (and we are using MySQL) let's check the column is big enough.
			if (isset($this->http_req->post->max_messageLength) && $this->http_req->post->max_messageLength != $modSettings['max_messageLength'] && DB_TYPE === 'MySQL')
			{
				require_once(SUBSDIR . '/Maintenance.subs.php');
				$colData = getMessageTableColumns();
				foreach ($colData as $column)
				{
					if ($column['name'] == 'body')
						$body_type = $column['type'];
				}

				if (isset($body_type) && ($this->http_req->post->max_messageLength > 65535 || $this->http_req->post->max_messageLength == 0) && $body_type == 'text')
					$GLOBALS['elk']['errors']->fatal_lang_error('convert_to_mediumtext', false, array($scripturl . '?action=Admin;area=maintain;sa=database'));

			}

			// If we're changing the post preview length let's check its valid
			if (!empty($this->http_req->post->preview_characters))
				$this->http_req->post->preview_characters = (int) min(max(0, $this->http_req->post->preview_characters), 512);

			$GLOBALS['elk']['hooks']->hook('save_post_settings');

			SettingsForm::save_db($config_vars, $this->http_req->post);
			redirectexit('action=Admin;area=postsettings;sa=posts');
		}

		// Final settings...
		$context['post_url'] = $scripturl . '?action=Admin;area=postsettings;save;sa=posts';
		$context['settings_title'] = $txt['manageposts_settings'];

		// Prepare the settings...
		SettingsForm::prepare_db($config_vars);
	}

	/**
	 * Initialize postSettings form with Admin configuration settings for posts.
	 */
	protected function _initPostSettingsForm()
	{
		// Instantiate the form
		$this->_postSettings = new SettingsForm();

		// Initialize it with our settings
		$config_vars = $this->_settings();

		return $this->_postSettings->settings($config_vars);
	}

	/**
	 * Return Admin configuration settings for posts.
	 */
	protected function _settings()
	{
		global $txt;

		// Initialize it with our settings
		$config_vars = array(
				// Simple post options...
				array('check', 'removeNestedQuotes'),
				array('check', 'enableVideoEmbeding'),
				array('check', 'enableCodePrettify'),
				// Note show the warning as read if pspell not installed!
				array('check', 'enableSpellChecking', 'postinput' => (function_exists('pspell_new') ? $txt['enableSpellChecking_warning'] : '<span class="error">' . $txt['enableSpellChecking_error'] . '</span>')),
			'',
				// Posting limits...
				array('int', 'max_messageLength', 'subtext' => $txt['max_messageLength_zero'], 'postinput' => $txt['manageposts_characters']),
				array('int', 'topicSummaryPosts', 'postinput' => $txt['manageposts_posts']),
			'',
				// Posting time limits...
				array('int', 'spamWaitTime', 'postinput' => $txt['manageposts_seconds']),
				array('int', 'edit_wait_time', 'postinput' => $txt['manageposts_seconds']),
				array('int', 'edit_disable_time', 'subtext' => $txt['edit_disable_time_zero'], 'postinput' => $txt['manageposts_minutes']),
			'',
				// First & Last message preview lengths
				array('select', 'message_index_preview', array($txt['message_index_preview_off'], $txt['message_index_preview_first'], $txt['message_index_preview_last'])),
				array('int', 'preview_characters', 'subtext' => $txt['preview_characters_zero'], 'postinput' => $txt['preview_characters_units']),
		);

		// Add new settings with a nice hook, makes them available for Admin settings search as well
		$GLOBALS['elk']['hooks']->hook('modify_post_settings', array(&$config_vars));

		return $config_vars;
	}

	/**
	 * Return the post settings for use in Admin search
	 */
	public function settings_search()
	{
		return $this->_settings();
	}
}