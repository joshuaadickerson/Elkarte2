<?php

/**
 * This file has the primary job of showing and editing people's profiles.
 * It also allows the user to change some of their or another's preferences,
 * and such things
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

namespace Elkarte\Profile;

use Elkarte\Elkarte\Controller\AbstractController;
use Elkarte\Elkarte\Controller\Action;
use Elkarte\Elkarte\Security\OpenID;

/**
 * ProfileOptionsController class. Does the job of showing and editing people's profiles.
 * Interface to buddy list, ignore list, notifications, authentication options, forum profile
 * account settings, etc
 */
class ProfileOptionsController extends AbstractController
{
	/**
	 * Member id for the profile being viewed
	 * @var int
	 */
	private $_memID = 0;

	/**
	 * Called before all other methods when coming from the dispatcher or
	 * action class.
	 *
	 * - If you initiate the class outside of those methods, call this method.
	 * or setup the class yourself else a horrible fate awaits you
	 */
	public function pre_dispatch()
	{
		$this->bootstrap();
		$this->_memID = $this->elk['profile']->currentMemberID();
	}

	/**
	 * Default method, if another action is not called by the menu.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		// action_account() is the first to do
		// these subactions are mostly routed to from the profile
		// menu though.
	}

	/**
	 * Show all the users buddies, as well as a add/delete interface.
	 */
	public function action_editBuddyIgnoreLists()
	{
		global $context, $txt, $modSettings;

		// Do a quick check to ensure people aren't getting here illegally!
		if (!$context['user']['is_owner'] || empty($modSettings['enable_buddylist']))
			$this->errors->fatal_lang_error('no_access', false);

		// Can we email the user direct?
		$context['can_moderate_forum'] = allowedTo('moderate_forum');
		$context['can_send_email'] = allowedTo('send_email_to_members');

		$subActions = array(
			'buddies' => array($this, 'action_editBuddies'),
			'ignore' => array($this, 'action_editIgnoreList'),
		);

		// Set a subaction
		$action = new Action();
		$subAction = $action->initialize($subActions, 'buddies');

		// Create the tabs for the template.
		$context[$context['profile_menu_name']]['tab_data'] = array(
			'title' => $txt['editBuddyIgnoreLists'],
			'description' => $txt['buddy_ignore_desc'],
			'class' => 'profile',
			'tabs' => array(
				'buddies' => array(),
				'ignore' => array(),
			),
		);

		// Pass on to the actual function.
		$action->dispatch($subAction);

		$this->templates->load('ProfileOptions');
	}

	/**
	 * Show all the users buddies, as well as a add/delete interface.
	 *
	 * @uses template_editBuddies()
	 */
	public function action_editBuddies()
	{
		global $context, $user_profile, $memberContext;

		// For making changes!
		$buddiesArray = explode(',', $user_profile[$this->_memID]['buddy_list']);
		foreach ($buddiesArray as $k => $dummy)
		{
			if ($dummy === '')
				unset($buddiesArray[$k]);
		}

		// Removing a buddy?
		if (isset($this->http_req->query->remove))
		{
			$this->session->check('get');

			$GLOBALS['elk']['hooks']->hook('remove_buddy', array($this->_memID));

			// Heh, I'm lazy, do it the easy way...
			foreach ($buddiesArray as $key => $buddy)
			{
				if ($buddy == (int) $this->http_req->query->remove)
					unset($buddiesArray[$key]);
			}

			// Make the changes.
			$user_profile[$this->_memID]['buddy_list'] = implode(',', $buddiesArray);
			$this->elk['members.manager']->updateMemberData($this->_memID, array('buddy_list' => $user_profile[$this->_memID]['buddy_list']));

			// Redirect off the page because we don't like all this ugly query stuff to stick in the history.
			redirectexit('action=profile;area=lists;sa=buddies;u=' . $this->_memID);
		}
		// Or adding a new one
		elseif (isset($this->http_req->post->new_buddy))
		{
			$this->session->check();

			// Prepare the string for extraction...
			$new_buddy = strtr($GLOBALS['elk']['text']->htmlspecialchars($this->http_req->post->new_buddy, ENT_QUOTES), array('&quot;' => '"'));
			preg_match_all('~"([^"]+)"~', $new_buddy, $matches);
			$new_buddies = array_unique(array_merge($matches[1], explode(',', preg_replace('~"[^"]+"~', '', $new_buddy))));

			foreach ($new_buddies as $k => $dummy)
			{
				$new_buddies[$k] = strtr(trim($new_buddies[$k]), array('\'' => '&#039;'));

				if (strlen($new_buddies[$k]) == 0 || in_array($new_buddies[$k], array($user_profile[$this->_memID]['member_name'], $user_profile[$this->_memID]['real_name'])))
					unset($new_buddies[$k]);
			}

			$GLOBALS['elk']['hooks']->hook('add_buddies', array($this->_memID, &$new_buddies));

			if (!empty($new_buddies))
			{
				// Now find out the id_member of the buddy.
				$buddiesArray = $this->elk['profile.options']->getBuddiesID($new_buddies);

				// Now update the current users buddy list.
				$user_profile[$this->_memID]['buddy_list'] = implode(',', $buddiesArray);
				$this->elk['members.manager']->updateMemberData($this->_memID, array('buddy_list' => $user_profile[$this->_memID]['buddy_list']));
			}

			// Back to the buddy list!
			redirectexit('action=profile;area=lists;sa=buddies;u=' . $this->_memID);
		}

		// Get all the users "buddies"...
		$buddies = array();

		if (!empty($buddiesArray))
		{
				$result = $this->elk['members.manager']->getBasicMemberData($buddiesArray, array('sort' => 'real_name', 'limit' => substr_count($user_profile[$this->_memID]['buddy_list'], ',') + 1));
			foreach ($result as $row)
				$buddies[] = $row['id_member'];
		}

		$context['buddy_count'] = count($buddies);

		// Load all the members up.
		$this->elk['members.manager']->loadMemberData($buddies, false, 'profile');

		// Setup the context for each buddy.
		$context['buddies'] = array();
		foreach ($buddies as $buddy)
		{
			$this->elk['members.manager']->loadMemberContext($buddy, true);
			$context['buddies'][$buddy] = $memberContext[$buddy];
		}

		$GLOBALS['elk']['hooks']->hook('view_buddies', array($this->_memID));

		$this->templates->load('ProfileOptions');
		$context['sub_template'] = 'editBuddies';

		// Use suggest to find the right buddies
		loadJavascriptFile('suggest.js', array('defer' => true));
	}

	/**
	 * Allows the user to view their ignore list,
	 *
	 * - Provides the option to manage members on it.
	 */
	public function action_editIgnoreList()
	{
		global $context, $user_profile, $memberContext;

		// For making changes!
		$ignoreArray = explode(',', $user_profile[$this->_memID]['pm_ignore_list']);
		foreach ($ignoreArray as $k => $dummy)
		{
			if ($dummy === '')
				unset($ignoreArray[$k]);
		}

		// Removing a member from the ignore list?
		if (isset($this->http_req->query->remove))
		{
			$this->session->check('get');

			// Heh, I'm lazy, do it the easy way...
			foreach ($ignoreArray as $key => $id_remove)
			{
				if ($id_remove == (int) $this->http_req->query->remove)
					unset($ignoreArray[$key]);
			}

			// Make the changes.
			$user_profile[$this->_memID]['pm_ignore_list'] = implode(',', $ignoreArray);
			$this->elk['members.manager']->updateMemberData($this->_memID, array('pm_ignore_list' => $user_profile[$this->_memID]['pm_ignore_list']));

			// Redirect off the page because we don't like all this ugly query stuff to stick in the history.
			redirectexit('action=profile;area=lists;sa=ignore;u=' . $this->_memID);
		}
		elseif (isset($this->http_req->post->new_ignore))
		{
			$this->session->check();

			// Prepare the string for extraction...
			$new_ignore = strtr($GLOBALS['elk']['text']->htmlspecialchars($this->http_req->post->new_ignore, ENT_QUOTES), array('&quot;' => '"'));
			preg_match_all('~"([^"]+)"~', $new_ignore, $matches);
			$new_entries = array_unique(array_merge($matches[1], explode(',', preg_replace('~"[^"]+"~', '', $new_ignore))));

			foreach ($new_entries as $k => $dummy)
			{
				$new_entries[$k] = strtr(trim($new_entries[$k]), array('\'' => '&#039;'));

				if (strlen($new_entries[$k]) == 0 || in_array($new_entries[$k], array($user_profile[$this->_memID]['member_name'], $user_profile[$this->_memID]['real_name'])))
					unset($new_entries[$k]);
			}

			if (!empty($new_entries))
			{
				// Now find out the id_member for the members in question.
				$ignoreArray = $this->elk['profile.options']->getBuddiesID($new_entries, false);

				// Now update the current users buddy list.
				$user_profile[$this->_memID]['pm_ignore_list'] = implode(',', $ignoreArray);
				$this->elk['members.manager']->updateMemberData($this->_memID, array('pm_ignore_list' => $user_profile[$this->_memID]['pm_ignore_list']));
			}

			// Back to the list of pitiful people!
			redirectexit('action=profile;area=lists;sa=ignore;u=' . $this->_memID);
		}

		// Initialise the list of members we're ignoring.
		$ignored = array();

		if (!empty($ignoreArray))
		{
				$result = $this->elk['members.manager']->getBasicMemberData($ignoreArray, array('sort' => 'real_name', 'limit' => substr_count($user_profile[$this->_memID]['pm_ignore_list'], ',') + 1));
			foreach ($result as $row)
				$ignored[] = $row['id_member'];
		}

		$context['ignore_count'] = count($ignored);

		// Load all the members up.
		$this->elk['members.manager']->loadMemberData($ignored, false, 'profile');

		// Setup the context for each buddy.
		$context['ignore_list'] = array();
		foreach ($ignored as $ignore_member)
		{
			$this->elk['members.manager']->loadMemberContext($ignore_member);
			$context['ignore_list'][$ignore_member] = $memberContext[$ignore_member];
		}

		$this->templates->load('ProfileOptions');
		$context['sub_template'] = 'editIgnoreList';
		loadJavascriptFile('suggest.js', array('defer' => true));
	}

	/**
	 * Allows the user to see or change their account info.
	 */
	public function action_account()
	{
		global $context, $txt;

		$this->templates->load('ProfileOptions');
		$this->loadThemeOptions();

		if (allowedTo(array('profile_identity_own', 'profile_identity_any')))
			$this->elk['profile']->loadCustomFields($this->_memID, 'account');

		$context['sub_template'] = 'edit_options';
		$context['page_desc'] = $txt['account_info'];

		$this->elk['profile']->setupProfileContext(
			array(
				'member_name', 'real_name', 'date_registered', 'posts', 'lngfile', 'hr',
				'id_group', 'hr',
				'email_address', 'hide_email', 'show_online', 'hr',
				'passwrd1', 'passwrd2', 'hr',
				'secret_question', 'secret_answer', 'hr',
				'enable_otp', 'otp_secret', 'hr'
			),
			'account'
		);

		loadJavascriptFile('qrcode.js');
		theme()->addInlineJavascript('
			var secret = document.getElementById("otp_secret").value;

			if (secret)
			{
				var qrcode = new QRCode("qrcode", {
					text: "otpauth://totp/' . $context['forum_name'] . '?secret=" + secret,
					width: 80,
					height: 80,
					colorDark : "#000000",
					colorLight : "#ffffff",
				});
			}

			/**
			 * Generate a secret key for Google Authenticator
			 */
			function generateSecret() {
				var text = "",
					possible = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567",
					qr = document.getElementById("qrcode");

				for (var i = 0; i < 16; i++)
					text += possible.charAt(Math.floor(Math.random() * possible.length));

				document.getElementById("otp_secret").value = text;

				while (qr.firstChild) {
					qr.removeChild(qr.firstChild);
				}

				var qrcode = new QRCode("qrcode", {
					text: "otpauth://totp/' . $context['forum_name'] . '?secret=" + text,
					width: 80,
					height: 80,
					colorDark: "#000000",
					colorLight: "#ffffff",
				});
			}', true);
	}

	/**
	 * Allow the user to change the forum options in their profile.
	 */
	public function action_forumProfile()
	{
		global $context, $txt;

		$this->loadThemeOptions();

		if (allowedTo(array('profile_extra_own', 'profile_extra_any')))
			$this->elk['profile']->loadCustomFields($this->_memID, 'forumprofile');

		$context['page_desc'] = replaceBasicActionUrl($txt['forumProfile_info']);
		$context['show_preview_button'] = true;

		$this->elk['profile']->setupProfileContext(
			array(
				'avatar_choice', 'hr', 'personal_text', 'hr',
				'bday1', 'location', 'gender', 'hr',
				'usertitle', 'signature', 'hr',
				'karma_good', 'hr',
				'website_title', 'website_url',
			),
			'forum'
		);

		$this->templates->load('ProfileOptions');
		$context['sub_template'] = 'edit_options';
	}

	/**
	 * Allow the edit of *someone else's* personal message settings.
	 */
	public function action_pmprefs()
	{
		global $context, $txt;

		$this->loadThemeOptions();
		$this->elk['profile']->loadCustomFields($this->_memID, 'pmprefs');
		$this->templates->load('ProfileOptions');

		$context['sub_template'] = 'edit_options';
		$context['page_desc'] = $txt['pm_settings_desc'];

		// Setup the profile context and call the 'integrate_pmprefs_profile_fields' hook
		$this->elk['profile']->setupProfileContext(
			array(
				'receive_from',
				'hr',
				'pm_settings',
			),
			'pmprefs'
		);
	}

	/**
	 * Allow the user to pick a theme.
	 *
	 */
	public function action_themepick()
	{
		global $txt, $context;

		$this->loadThemeOptions();

		if (allowedTo(array('profile_extra_own', 'profile_extra_any')))
			$this->elk['profile']->loadCustomFields($this->_memID, 'theme');

		$context['page_desc'] = $txt['theme_info'];

		$this->elk['profile']->setupProfileContext(
			array(
				'id_theme', 'smiley_set', 'hr',
				'time_format', 'time_offset', 'hr',
				'theme_settings',
			),
			'themepick'
		);

		$this->templates->load('ProfileOptions');
		$context['sub_template'] = 'edit_options';
	}

	/**
	 * Changing authentication method?
	 * Only appropriate for people using OpenID.
	 *
	 * @param bool $saving = false
	 * @return true|null
	 */
	public function action_authentication($saving = false)
	{
		global $context, $cur_profile, $post_errors, $modSettings;

		loadLanguage('Login');
		$this->_templates->load('ProfileOptions');

		// We are saving?
		if ($saving)
		{
			// Moving to password passed authentication?
			if ($this->http_req->post->authenticate === 'passwd')
			{
				// Didn't enter anything?
				if ($this->http_req->post->passwrd1 === '')
					$post_errors[] = 'no_password';
				// Do the two entries for the password even match?
				elseif (!isset($this->http_req->post->passwrd2) || $this->http_req->post->passwrd1 != $this->http_req->post->passwrd2)
					$post_errors[] = 'bad_new_password';
				// Is it valid?
				else
				{
					$passwordErrors = validatePassword($this->http_req->post->passwrd1, $cur_profile['member_name'], array($cur_profile['real_name'], $cur_profile['email_address']));

					// Were there errors?
					if ($passwordErrors != null)
						$post_errors[] = 'password_' . $passwordErrors;
				}

				if (empty($post_errors))
				{
					// Integration?
					$this->hooks->hook('reset_pass', array($cur_profile['member_name'], $cur_profile['member_name'], $this->http_req->post->passwrd1));

					// Go then.
					$new_pass = $this->http_req->post->passwrd1;
					$passwd = validateLoginPassword($new_pass, '', $cur_profile['member_name'], true);

					// Do the important bits.
					$this->elk['members.manager']->updateMemberData($this->_memID, array('openid_uri' => '', 'passwd' => $passwd));
					if ($context['user']['is_owner'])
					{
						setLoginCookie(60 * $modSettings['cookieTime'], $this->_memID, hash('sha256', $new_pass . $cur_profile['password_salt']));
						redirectexit('action=profile;area=authentication;updated');
					}
					else
						redirectexit('action=profile;u=' . $this->_memID);
				}

				return true;
			}
			// Not right yet!
			elseif ($this->http_req->post->authenticate === 'openid' && !empty($this->http_req->post->openid_identifier))
			{
				$openID = new OpenID();
				$this->http_req->post->openid_identifier = $openID->canonize($this->http_req->post->openid_identifier);

				if ($this->elk['members.manager']->memberExists($this->http_req->post->openid_identifier))
					$post_errors[] = 'openid_in_use';
				elseif (empty($post_errors))
				{
					// Authenticate using the new OpenID URI first to make sure they didn't make a mistake.
					if ($context['user']['is_owner'])
					{
						$_SESSION['new_openid_uri'] = $this->http_req->post->openid_identifier;
						$openID->validate($this->http_req->post->openid_identifier, false, null, 'change_uri');
					}
					else
						$this->elk['members.manager']->updateMemberData($this->_memID, array('openid_uri' => $this->http_req->post->openid_identifier));
				}
			}
		}

		// Some stuff for the template
		$context['member']['openid_uri'] = $cur_profile['openid_uri'];
		$context['auth_method'] = empty($cur_profile['openid_uri']) ? 'password' : 'openid';
		$context['sub_template'] = 'authentication_method';
		loadJavascriptFile('register.js');
	}

	/**
	 * Display the notifications and settings for changes.
	 */
	public function action_notification()
	{
		global $txt, $scripturl, $user_profile, $context, $modSettings;

		$context['mention_types'] = getMemberNotificationsProfile($this->_memID);

		// Fine, start with the board list.
		$listOptions = array(
			'id' => 'board_notification_list',
			'width' => '100%',
			'no_items_label' => $txt['notifications_boards_none'] . '<br /><br />' . $txt['notifications_boards_howto'],
			'no_items_align' => 'left',
			'base_href' => $scripturl . '?action=profile;u=' . $this->_memID . ';area=notification',
			'default_sort_col' => 'board_name',
			'get_items' => array(
				'function' => array($this, 'list_getBoardNotifications'),
				'params' => array(
					$this->_memID,
				),
			),
			'columns' => array(
				'board_name' => array(
					'header' => array(
						'value' => $txt['notifications_boards'],
						'class' => 'lefttext',
					),
					'data' => array(
						'function' => function ($board) {
							global $txt;

							$link = $board['link'];

							if ($board['new'])
								$link .= ' <a href="' . $board['href'] . '"><span class="new_posts">' . $txt['new'] . '</span></a>';

							return $link;
						},
					),
					'sort' => array(
						'default' => 'name',
						'reverse' => 'name DESC',
					),
				),
				'delete' => array(
					'header' => array(
						'value' => '<input type="checkbox" onclick="invertAll(this, this.form);" />',
						'class' => 'centertext',
						'style' => 'width:4%;',
					),
					'data' => array(
						'sprintf' => array(
							'format' => '<input type="checkbox" name="notify_boards[]" value="%1$d" %2$s />',
							'params' => array(
								'id' => false,
								'checked' => false,
							),
						),
						'class' => 'centertext',
					),
				),
			),
			'form' => array(
				'href' => $scripturl . '?action=profile;area=notification',
				'include_sort' => true,
				'include_start' => true,
				'hidden_fields' => array(
					'u' => $this->_memID,
					'sa' => $context['menu_item_selected'],
					$context['session_var'] => $context['session_id'],
				),
				'token' => $context['token_check'],
			),
			'additional_rows' => array(
				array(
					'class' => 'submitbutton',
					'position' => 'bottom_of_list',
					'value' => '
						<input type="submit" name="edit_notify_boards" value="' . $txt['notifications_boards_update'] . '" />
						<input type="hidden" name="save" value="save" />',
				),
				array(
					'position' => 'after_title',
					'value' => getBoardNotificationsCount($this->_memID) == 0 ? $txt['notifications_boards_none'] . '<br />' . $txt['notifications_boards_howto'] : $txt['notifications_boards_current'],
				),
			),
		);

		// Create the board notification list.
		createList($listOptions);

		// Now do the topic notifications.
		$listOptions = array(
			'id' => 'topic_notification_list',
			'width' => '100%',
			'items_per_page' => $modSettings['defaultMaxMessages'],
			'no_items_label' => $txt['notifications_topics_none'] . '<br /><br />' . $txt['notifications_topics_howto'],
			'no_items_align' => 'left',
			'base_href' => $scripturl . '?action=profile;u=' . $this->_memID . ';area=notification',
			'default_sort_col' => 'last_post',
			'get_items' => array(
				'function' => array($this, 'list_getTopicNotifications'),
				'params' => array(
					$this->_memID,
				),
			),
			'get_count' => array(
				'function' => array($this, 'list_getTopicNotificationCount'),
				'params' => array(
					$this->_memID,
				),
			),
			'columns' => array(
				'subject' => array(
					'header' => array(
						'value' => $txt['notifications_topics'],
						'class' => 'lefttext',
					),
					'data' => array(
						'function' => function ($topic) {
							global $txt;

							$link = $topic['link'];

							if ($topic['new'])
								$link .= ' <a href="' . $topic['new_href'] . '"><span class="new_posts">' . $txt['new'] . '</span></a>';

							$link .= '<br /><span class="smalltext"><em>' . $txt['in'] . ' ' . $topic['board_link'] . '</em></span>';

							return $link;
						},
					),
					'sort' => array(
						'default' => 'ms.subject',
						'reverse' => 'ms.subject DESC',
					),
				),
				'started_by' => array(
					'header' => array(
						'value' => $txt['started_by'],
						'class' => 'lefttext',
					),
					'data' => array(
						'db' => 'poster_link',
					),
					'sort' => array(
						'default' => 'real_name_col',
						'reverse' => 'real_name_col DESC',
					),
				),
				'last_post' => array(
					'header' => array(
						'value' => $txt['last_post'],
							'class' => 'lefttext',
					),
					'data' => array(
						'sprintf' => array(
							'format' => '<span class="smalltext">%1$s<br />' . $txt['by'] . ' %2$s</span>',
							'params' => array(
								'updated' => false,
								'poster_updated_link' => false,
							),
						),
					),
					'sort' => array(
						'default' => 'ml.id_msg DESC',
						'reverse' => 'ml.id_msg',
					),
				),
				'delete' => array(
					'header' => array(
						'value' => '<input type="checkbox" onclick="invertAll(this, this.form);" />',
						'class' => 'centertext',
						'style' => 'width:4%;',
					),
					'data' => array(
						'sprintf' => array(
							'format' => '<input type="checkbox" name="notify_topics[]" value="%1$d" />',
							'params' => array(
								'id' => false,
							),
						),
						'class' => 'centertext',
					),
				),
			),
			'form' => array(
				'href' => $scripturl . '?action=profile;area=notification',
				'include_sort' => true,
				'include_start' => true,
				'hidden_fields' => array(
					'u' => $this->_memID,
					'sa' => $context['menu_item_selected'],
					$context['session_var'] => $context['session_id'],
				),
				'token' => $context['token_check'],
			),
			'additional_rows' => array(
				array(
					'class' => 'submitbutton',
					'position' => 'bottom_of_list',
					'value' => '
						<input type="submit" name="edit_notify_topics" value="' . $txt['notifications_update'] . '" />
						<input type="hidden" name="save" value="save" />',
				),
			),
		);

		// Create the notification list.
		createList($listOptions);

		// What options are set?
		$context['member'] += array(
			'notify_announcements' => $user_profile[$this->_memID]['notify_announcements'],
			'notify_send_body' => $user_profile[$this->_memID]['notify_send_body'],
			'notify_types' => $user_profile[$this->_memID]['notify_types'],
			'notify_regularity' => $user_profile[$this->_memID]['notify_regularity'],
		);

		$this->loadThemeOptions();

		$this->templates->load('ProfileOptions');
	}

	/**
	 * Callback for createList() in action_notification()
	 *
	 * - Retrieve topic notifications count.
	 *
	 * @param int $memID id_member the id of the member who's notifications we are loading
	 * @return integer
	 */
	public function list_getTopicNotificationCount($memID)
	{
		// Topic notifications count, for the list
		return topicNotificationCount($memID);
	}

	/**
	 * Callback for createList() in action_notification()
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page  The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param int $memID id_member
	 * @return mixed array of topic notifications
	 */
	public function list_getTopicNotifications($start, $items_per_page, $sort, $memID)
	{
		// Topic notifications, for the list
		return topicNotifications($start, $items_per_page, $sort, $memID);
	}

	/**
	 * Callback for createList() in action_notification()
	 *
	 * @uses template_ignoreboards()
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page  The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param int $memID id_member
	 * @return mixed[] array of board notifications
	 */
	public function list_getBoardNotifications($start, $items_per_page, $sort, $memID)
	{
		// Return boards you see and their notification status for the list
		return boardNotifications($start, $items_per_page, $sort, $memID);
	}

	/**
	 * Allows the user to see the list of their ignored boards.
	 * (and un-ignore them)
	 */
	public function action_ignoreboards()
	{
		global $context, $modSettings, $cur_profile;

		// Have the admins enabled this option?
		if (empty($modSettings['allow_ignore_boards']))
			$this->_errors->fatal_lang_error('ignoreboards_disallowed', 'user');

		$context += getBoardList(array('not_redirection' => true, 'ignore' => !empty($cur_profile['ignore_boards']) ? explode(',', $cur_profile['ignore_boards']) : array()));

		// Include a list of boards per category for easy toggling.
		foreach ($context['categories'] as $cat => &$category)
		{
			$context['boards_in_category'][$cat] = count($category['boards']);
			$category['child_ids'] = array_keys($category['boards']);
		}

		$this->loadThemeOptions();

		$this->templates->load('ProfileOptions');
		$context['sub_template'] = 'ignoreboards';
	}

	/**
	 * Function to allow the user to choose group membership etc...
	 */
	public function action_groupMembership()
	{
		global $txt, $user_profile, $context;

		$curMember = $user_profile[$this->_memID];
		$context['primary_group'] = $curMember['id_group'];

		// Can they manage groups?
		$context['can_manage_membergroups'] = allowedTo('manage_membergroups');
		$context['can_manage_protected'] = allowedTo('admin_forum');
		$context['can_edit_primary'] = $context['can_manage_protected'];
		$context['update_message'] = isset($this->http_req->query->msg) && isset($txt['group_membership_msg_' . $this->http_req->query->msg]) ? $txt['group_membership_msg_' . $this->http_req->query->msg] : '';

		// Get all the groups this user is a member of.
		$groups = explode(',', $curMember['additional_groups']);
		$groups[] = $curMember['id_group'];

		// Ensure the query doesn't croak!
		if (empty($groups))
			$groups = array(0);

		// Just to be sure...
		$groups = array_map('intval', $groups);

		// Get all the membergroups they can join.
		$context['groups'] = loadMembergroupsJoin($groups, $this->_memID);

		// Add registered members on the end.
		$context['groups']['member'][0] = array(
			'id' => 0,
			'name' => $txt['regular_members'],
			'desc' => $txt['regular_members_desc'],
			'type' => 0,
			'is_primary' => $context['primary_group'] == 0 ? true : false,
			'can_be_primary' => true,
			'can_leave' => 0,
		);

		// No changing primary one unless you have enough groups!
		if (count($context['groups']['member']) < 2)
			$context['can_edit_primary'] = false;

		// In the special case that someone is requesting membership of a group, setup some special context vars.
		if (isset($this->http_req->query->request)
			&& isset($context['groups']['available'][(int) $this->http_req->query->request])
			&& $context['groups']['available'][(int) $this->http_req->query->request]['type'] == 2)
		{
			$context['group_request'] = $context['groups']['available'][(int) $this->http_req->query->request];
		}

		$this->templates->load('ProfileOptions');
		$context['sub_template'] = 'groupMembership';
	}

	/**
	 * This function actually makes all the group changes
	 *
	 * @return string
	 */
	public function action_groupMembership2()
	{
		global $context, $user_profile, $modSettings, $scripturl, $language;

		// Let's be extra cautious...
		if (!$context['user']['is_owner'] || empty($modSettings['show_group_membership']))
			isAllowedTo('manage_membergroups');

		$group_id = $this->http_req->getPost('gid', 'intval', $this->http_req->getQuery('gid', 'intval', null));

		if (!isset($group_id) && !isset($this->http_req->post->primary))
			$this->_errors->fatal_lang_error('no_access', false);

		// GID may be from a link or a form
		$this->session->check(isset($this->http_req->query->gid) ? 'get' : 'post');

		$old_profile = &$user_profile[$this->_memID];
		$context['can_manage_membergroups'] = allowedTo('manage_membergroups');
		$context['can_manage_protected'] = allowedTo('admin_forum');

		// By default the new primary is the old one.
		$newPrimary = $old_profile['id_group'];
		$addGroups = array_flip(explode(',', $old_profile['additional_groups']));
		$canChangePrimary = $old_profile['id_group'] == 0 ? 1 : 0;
		$changeType = isset($this->http_req->post->primary) ? 'primary' : (isset($this->http_req->post->req) ? 'request' : 'free');

		// One way or another, we have a target group in mind...
		$group_id = isset($group_id) ? $group_id : (int) $this->http_req->post->primary;
		$foundTarget = $changeType === 'primary' && $group_id == 0 ? true : false;

		// Sanity check!!
		if ($group_id == 1)
			isAllowedTo('admin_forum');

		// What ever we are doing, we need to determine if changing primary is possible!
		$groups_details = membergroupsById(array($group_id, $old_profile['id_group']), 0, true);

		// Protected groups require proper permissions!
		if ($group_id != 1 && $groups_details[$group_id]['group_type'] == 1)
			isAllowedTo('admin_forum');

		foreach ($groups_details as $key => $row)
		{
			// Is this the new group?
			if ($row['id_group'] == $group_id)
			{
				$foundTarget = true;
				$group_name = $row['group_name'];

				// Does the group type match what we're doing - are we trying to request a non-requestable group?
				if ($changeType === 'request' && $row['group_type'] != 2)
					$this->_errors->fatal_lang_error('no_access', false);
				// What about leaving a requestable group we are not a member of?
				elseif ($changeType === 'free' && $row['group_type'] == 2 && $old_profile['id_group'] != $row['id_group'] && !isset($addGroups[$row['id_group']]))
					$this->_errors->fatal_lang_error('no_access', false);
				elseif ($changeType === 'free' && $row['group_type'] != 3 && $row['group_type'] != 2)
					$this->_errors->fatal_lang_error('no_access', false);

				// We can't change the primary group if this is hidden!
				if ($row['hidden'] == 2)
					$canChangePrimary = false;
			}

			// If this is their old primary, can we change it?
			if ($row['id_group'] == $old_profile['id_group'] && ($row['group_type'] > 1 || $context['can_manage_membergroups']) && $canChangePrimary !== false)
				$canChangePrimary = 1;

			// If we are not doing a force primary move, don't do it automatically if current primary is not 0.
			if ($changeType != 'primary' && $old_profile['id_group'] != 0)
				$canChangePrimary = false;

			// If this is the one we are acting on, can we even act?
			if ((!$context['can_manage_protected'] && $row['group_type'] == 1) || (!$context['can_manage_membergroups'] && $row['group_type'] == 0))
				$canChangePrimary = false;
		}

		// Didn't find the target?
		if (!$foundTarget)
			$this->_errors->fatal_lang_error('no_access', false);

		// Final security check, don't allow users to promote themselves to Admin.
		if ($context['can_manage_membergroups'] && !allowedTo('admin_forum'))
		{
			$disallow = checkMembergroupChange($group_id);
			if ($disallow)
				isAllowedTo('admin_forum');
		}

		// If we're requesting, add the note then return.
		if ($changeType === 'request')
		{
			if (logMembergroupRequest($group_id, $this->_memID))
				$this->_errors->fatal_lang_error('profile_error_already_requested_group');

			// Send an email to all group moderators etc.


			// Do we have any group moderators?

			$moderators = array_keys(getGroupModerators($group_id));

			// Otherwise this is the backup!
			if (empty($moderators))
			{
						$moderators = membersAllowedTo('manage_membergroups');
			}

			if (!empty($moderators))
			{
						$members = getBasicMemberData($moderators, array('preferences' => true, 'sort' => 'lngfile'));

				foreach ($members as $member)
				{
					if ($member['notify_types'] != 4)
						continue;

					// Check whether they are interested.
					if (!empty($member['mod_prefs']))
					{
						list (,, $pref_binary) = explode('|', $member['mod_prefs']);
						if (!($pref_binary & 4))
							continue;
					}

					$replacements = array(
						'RECPNAME' => $member['member_name'],
						'APPYNAME' => $old_profile['member_name'],
						'GROUPNAME' => $group_name,
						'REASON' => $this->http_req->post->reason,
						'MODLINK' => $scripturl . '?action=moderate;area=groups;sa=requests',
					);

					$emaildata = loadEmailTemplate('request_membership', $replacements, empty($member['lngfile']) || empty($modSettings['userLanguage']) ? $language : $member['lngfile']);
					sendmail($member['email_address'], $emaildata['subject'], $emaildata['body'], null, null, false, 2);
				}
			}

			return $changeType;
		}
		// Otherwise we are leaving/joining a group.
		elseif ($changeType === 'free')
		{
			// Are we leaving?
			if ($old_profile['id_group'] == $group_id || isset($addGroups[$group_id]))
			{
				if ($old_profile['id_group'] == $group_id)
					$newPrimary = 0;
				else
					unset($addGroups[$group_id]);
			}
			// ... if not, must be joining.
			else
			{
				// Can we change the primary, and do we want to?
				if ($canChangePrimary)
				{
					if ($old_profile['id_group'] != 0)
						$addGroups[$old_profile['id_group']] = -1;
					$newPrimary = $group_id;
				}
				// Otherwise it's an additional group...
				else
					$addGroups[$group_id] = -1;
			}
		}
		// Finally, we must be setting the primary.
		elseif ($canChangePrimary)
		{
			if ($old_profile['id_group'] != 0)
				$addGroups[$old_profile['id_group']] = -1;
			if (isset($addGroups[$group_id]))
				unset($addGroups[$group_id]);
			$newPrimary = $group_id;
		}

		// Finally, we can make the changes!
		foreach ($addGroups as $id => $dummy)
		{
			if (empty($id))
				unset($addGroups[$id]);
		}
		$addGroups = implode(',', array_flip($addGroups));

		// Ensure that we don't cache permissions if the group is changing.
		if ($context['user']['is_owner'])
			$_SESSION['mc']['time'] = 0;
		else
			updateSettings(array('settings_updated' => time()));

		updateMemberData($this->_memID, array('id_group' => $newPrimary, 'additional_groups' => $addGroups));

		return $changeType;
	}

	/**
	 * Load the options for an user.
	 */
	public function loadThemeOptions()
	{
		global $context, $options, $cur_profile;

		if (isset($this->http_req->post->default_options))
			$this->http_req->post->options = isset($this->http_req->post->options) ? $this->http_req->post->options + $this->http_req->post->default_options : $this->http_req->post->default_options;

		if ($context['user']['is_owner'])
		{
			$context['member']['options'] = $options;

			if (isset($this->http_req->post->options) && is_array($this->http_req->post->options))
			{
				foreach ($this->http_req->post->options as $k => $v)
					$context['member']['options'][$k] = $v;
			}
		}
		else
		{
			$context['member']['options'] = $this->loadThemeOptionsInto(array(1, (int) $cur_profile['id_theme']), array(-1, $this->_memID), $context['member']['options']);

			if (isset($this->http_req->post->options))
			{
				foreach ($this->http_req->post->options as $var => $val)
					$context['member']['options'][$var] = $val;
			}
		}
	}
}