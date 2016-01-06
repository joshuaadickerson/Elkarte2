<?php

/**
 * Handles all the administration settings for topics and posts
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Topics;

use Elkarte\Elkarte\Controller\AbstractController;
use Elkarte\Elkarte\Controller\Action;

/**
 * ManagePosts controller handles all the administration settings for topics and posts.
 */
class ManageTopicsController extends AbstractController
{
	/**
	 * Topic settings form
	 * @var Settings_Form
	 */
	protected $_topicSettings;

	public function __construct(Container $elk, BoardsManager $manager, Hooks $hooks, Errors $errors, TemplateLayers $layers)
	{
		$this->elk = $elk;

		$this->bootstrap();

		$this->hooks = $hooks;
		$this->errors = $errors;
		$this->_layers = $layers;
		$this->manager = $manager;
	}

	/**
	 * Check permissions and forward to the right method.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		global $context, $txt;

		$subActions = array(
			'display' => array(
				'controller' => $this,
				'function' => 'action_topicSettings_display',
				'permission' => 'admin_forum')
		);

		// Control for an action, why not!
		$action = new Action('manage_topics');

		// Only one option I'm afraid, but integrate_sa_manage_topics may add more
		$subAction = $action->initialize($subActions, 'display');

		// Page items for the template
		$context['sub_action'] = $subAction;
		$context['page_title'] = $txt['manageposts_topic_settings'];

		// Set up action/subaction stuff.
		$action->dispatch($subAction);
	}

	/**
	 * Administration page for topics: allows to display and set settings related to topics.
	 *
	 * Requires the admin_forum permission.
	 * Accessed from ?action=Admin;area=postsettings;sa=topics.

	 * @uses Admin template, edit_topic_settings sub-template.
	 */
	public function action_topicSettings_display()
	{
		global $context, $txt, $scripturl;

		// Initialize the form
		$this->_initTopicSettingsForm();

		// Retrieve the current config settings
		$config_vars = $this->_topicSettings->settings();

		// Setup the template.
		$context['sub_template'] = 'show_settings';

		// Are we saving them - are we??
		if (isset($this->_req->query->save))
		{
			// Security checks
			$this->_session->check();

			// Notify addons and integrations of the settings change.
			$GLOBALS['elk']['hooks']->hook('save_topic_settings');

			// Save the result!
			Settings_Form::save_db($config_vars, $this->_req->post);

			// We're done here, pal.
			redirectexit('action=Admin;area=postsettings;sa=topics');
		}

		// Set up the template stuff nicely.
		$context['post_url'] = $scripturl . '?action=Admin;area=postsettings;save;sa=topics';
		$context['settings_title'] = $txt['manageposts_topic_settings'];

		// Prepare the settings
		Settings_Form::prepare_db($config_vars);
	}

	/**
	 * Initialize topicSettings form with the configuration settings for topics.
	 */
	protected function _initTopicSettingsForm()
	{
		// Instantiate the form
		$this->_topicSettings = new Settings_Form();

		// Initialize it with our settings
		$config_vars = $this->_settings();

		return $this->_topicSettings->settings($config_vars);
	}

	/**
	 * Return configuration settings for topics.
	 */
	protected function _settings()
	{
		global $txt;

		// initialize it with our settings
		$config_vars = array(
				// Some simple big bools...
				array('check', 'enableParticipation'),
				array('check', 'enableFollowup'),
				array('check', 'enable_unwatch'),
				array('check', 'pollMode'),
			'',
				// Pagination etc...
				array('int', 'oldTopicDays', 'postinput' => $txt['manageposts_days'], 'subtext' => $txt['oldTopicDays_zero']),
				array('int', 'defaultMaxTopics', 'postinput' => $txt['manageposts_topics']),
				array('int', 'defaultMaxMessages', 'postinput' => $txt['manageposts_posts']),
				array('check', 'disable_print_topic'),
			'',
				// Hot topics (etc)...
				array('int', 'hotTopicPosts', 'postinput' => $txt['manageposts_posts']),
				array('int', 'hotTopicVeryPosts', 'postinput' => $txt['manageposts_posts']),
				array('check', 'useLikesNotViews'),
			'',
				// All, next/prev...
				array('int', 'enableAllMessages', 'postinput' => $txt['manageposts_posts'], 'subtext' => $txt['enableAllMessages_zero']),
				array('check', 'disableCustomPerPage'),
				array('check', 'enablePreviousNext'),
		);

		$GLOBALS['elk']['hooks']->hook('modify_topic_settings', array(&$config_vars));

		return $config_vars;
	}

	/**
	 * Return the topic settings for use in Admin search
	 */
	public function settings_search()
	{
		return $this->_settings();
	}
}