<?php

/**
 * The functions in this file deal with sending topics to a friend or reports to
 * a moderator.
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

namespace Elkarte\Mail;

use Elkarte\Elkarte\Controller\AbstractController;

/**
 * EmailuserController class, allows for sending topics via email
 */
class EmailuserController extends AbstractController
{
	/**
	 * This function initializes or sets up the necessary, for the other actions
	 */
	public function pre_dispatch()
	{
		global $context;

		// Don't index anything here.
		$context['robot_no_index'] = true;

		// Load the template.
		$this->_templates->load('Emailuser');
	}

	/**
	 * Default action handler
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		// just accept we haz a default action: action_sendtopic()
		$this->action_sendtopic();
	}

	/**
	 * Send a topic to a friend.
	 *
	 * What it does:
	 * - Uses the Emailuser template, with the main sub template.
	 * - Requires the send_topic permission.
	 * - Redirects back to the first page of the topic when done.
	 * - Is accessed via ?action=emailuser;sa=sendtopic.
	 */
	public function action_sendtopic()
	{
		global $topic, $txt, $context, $modSettings;

		// Check permissions...
		isAllowedTo('send_topic');

		// We need at least a topic... go away if you don't have one.
		if (empty($topic))
			$this->_errors->fatal_lang_error('not_a_topic', false);


		$row = getTopicInfo($topic, 'message');
		if (empty($row))
			$this->_errors->fatal_lang_error('not_a_topic', false);

		// Can't send topic if its unapproved and using post moderation.
		if ($modSettings['postmod_active'] && !$row['approved'])
			$this->_errors->fatal_lang_error('not_approved_topic', false);

		// Censor the subject....
		$row['subject'] = censor($row['subject']);

		// Sending yet, or just getting prepped?
		if (empty($this->http_req->post->send))
		{
			$context['page_title'] = sprintf($txt['sendtopic_title'], $row['subject']);
			$context['start'] = $this->http_req->query->start;
			$context['sub_template'] = 'send_topic';

			return;
		}

		// Actually send the message...
		$this->session->check();
		spamProtection('sendtopic');

		$result = $this->_sendTopic($row);
		if ($result !== true)
			$context['sendtopic_error'] = $result;

		// Back to the topic!
		redirectexit('topic=' . $topic . '.0');
	}

	/**
	 * Like action_sendtopic, but done via ajax from an API request
	 * @uses Xml Template generic_xml_buttons sub template
	 */
	public function action_sendtopic_api()
	{
		global $topic, $modSettings, $txt, $context, $scripturl;

		$this->_templates->load('Xml');

		$this->_layers->removeAll();
		$context['sub_template'] = 'generic_xml_buttons';

		if (empty($this->http_req->post->send))
			die();

		// We need at least a topic... go away if you don't have one.
		// Guests can't mark things.
		if (empty($topic))
		{
			loadLanguage('Errors');
			$context['xml_data'] = array(
				'error' => 1,
				'text' => $txt['not_a_topic']
			);
			return;
		}

		// Is the session valid?
		if ($this->session->check('post', '', false))
		{
			loadLanguage('Errors');
			$context['xml_data'] = array(
				'error' => 1,
				'url' => $scripturl . '?action=emailuser;sa=sendtopic;topic=' . $topic . '.0',
			);
			return;
		}



		$row = getTopicInfo($topic, 'message');
		if (empty($row))
		{
			loadLanguage('Errors');
			$context['xml_data'] = array(
				'error' => 1,
				'text' => $txt['not_a_topic']
			);
			return;
		}

		// Can't send topic if its unapproved and using post moderation.
		if ($modSettings['postmod_active'] && !$row['approved'])
		{
			loadLanguage('Errors');
			$context['xml_data'] = array(
				'error' => 1,
				'text' => $txt['not_approved_topic']
			);
			return;
		}

		$is_spam = spamProtection('sendtopic', false);
		if ($is_spam !== false)
		{
			loadLanguage('Errors');
			$context['xml_data'] = array(
				'error' => 1,
				'text' => sprintf($txt['sendtopic_WaitTime_broken'], $is_spam)
			);
			return;
		}

		// Censor the subject....
		$row['subject'] = censor($row['subject']);

		// Actually send it off
		$result = $this->_sendTopic($row);
		if ($result !== true)
		{
			loadLanguage('Errors');
			$context['xml_data'] = $result;
			return;
		}

		$context['xml_data'] = array(
			'text' => $txt['topic_sent'],
		);
	}

	/**
	 * Prepares the form data and database data for sending in an email format
	 * Does the actual sending of the email if everything checks out as OK
	 *
	 * @param mixed[] $row
	 * @return array|true
	 */
	protected function _sendTopic($row)
	{
		global $scripturl, $topic, $txt;

		// This is needed for sendmail().


		// Time to check and clean what was placed in the form
		$validator = new DataValidator();
		$validator->sanitation_rules(array(
			'y_name' => 'trim',
			'r_name' => 'trim'
		));
		$validator->validation_rules(array(
			'y_name' => 'required|notequal[_]',
			'y_email' => 'required|valid_email',
			'r_name' => 'required|notequal[_]',
			'r_email' => 'required|valid_email'
		));
		$validator->text_replacements(array(
			'y_name' => $txt['sendtopic_sender_name'],
			'y_email' => $txt['sendtopic_sender_email'],
			'r_name' => $txt['sendtopic_receiver_name'],
			'r_email' => $txt['sendtopic_receiver_email']
		));

		// Any errors or are we good to go?
		if (!$validator->validate($this->http_req->post))
		{
			$errors = $validator->validation_errors();

			return array(
				'errors' => $errors,
				'type' => 'minor',
				'title' => $txt['validation_failure'],
				// And something for ajax
				'error' => 1,
				'text' => $errors[0],
			);
		}

		// Emails don't like entities...
		$row['subject'] = $GLOBALS['elk']['text']->un_htmlspecialchar($row['subject']);

		$replacements = array(
			'TOPICSUBJECT' => $row['subject'],
			'SENDERNAME' => $validator->y_name,
			'RECPNAME' => $validator->r_name,
			'TOPICLINK' => $scripturl . '?topic=' . $topic . '.0',
		);

		$emailtemplate = 'send_topic';

		if (!empty($this->http_req->post->comment))
		{
			$emailtemplate .= '_comment';
			$replacements['COMMENT'] = $this->http_req->post->comment;
		}

		$emaildata = loadEmailTemplate($emailtemplate, $replacements);

		// And off we go!
		sendmail($validator->r_email, $emaildata['subject'], $emaildata['body'], $validator->y_email);

		return true;
	}

	/**
	 * Allow a user to send an email.
	 *
	 * - Send an email to the user - allow the sender to write the message.
	 * - Can either be passed a user ID as uid or a message id as msg.
	 * - Does not check permissions for a message ID as there is no information disclosed.
	 * - accessed by ?action=emailuser;sa=email from the message list, profile view or message view
	 */
	public function action_email()
	{
		global $context, $user_info, $txt, $scripturl;

		// Can the user even see this information?
		if ($user_info['is_guest'])
			$this->_errors->fatal_lang_error('no_access', false);

		isAllowedTo('send_email_to_members');

		// Are we sending to a user?
		$context['form_hidden_vars'] = array();
		$uid = '';
		$mid = '';
		if (isset($this->http_req->post->uid) || isset($this->http_req->query->uid))
		{

			// Get the latest activated member's display name.
			$uid = $this->http_req->getPost('uid', 'intval', isset($this->http_req->query->uid) ? (int) $this->http_req->query->uid : 0);
			$row = getBasicMemberData($uid);

			$context['form_hidden_vars']['uid'] = $uid;
		}
		elseif (isset($this->http_req->post->msg) || isset($this->http_req->query->msg))
		{

			$mid = $this->http_req->getPost('msg', 'intval', isset($this->http_req->query->msg) ? (int) $this->http_req->query->msg : 0);
			$row = mailFromMessage($mid);

			$context['form_hidden_vars']['msg'] = $mid;
		}

		// Are you sure you got the address or any data?
		if (empty($row['email_address']) || empty($row))
			$this->_errors->fatal_lang_error('cant_find_user_email');

		// Can they actually do this?
		$context['show_email_address'] = showEmailAddress(!empty($row['hide_email']), $row['id_member']);
		if ($context['show_email_address'] === 'no')
			$this->_errors->fatal_lang_error('no_access', false);

		// Does the user want to be contacted at all by you?
		if (!canContact($row['id_member']))
			$this->_errors->fatal_lang_error('no_access', false);

		// Setup the context!
		$context['recipient'] = array(
			'id' => $row['id_member'],
			'name' => $row['real_name'],
			'email' => $row['email_address'],
			'email_link' => ($context['show_email_address'] == 'yes_permission_override' ? '<em>' : '') . '<a href="mailto:' . $row['email_address'] . '">' . $row['email_address'] . '</a>' . ($context['show_email_address'] == 'yes_permission_override' ? '</em>' : ''),
			'link' => $row['id_member'] ? '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>' : $row['real_name'],
		);

		// Can we see this person's email address?
		$context['can_view_recipient_email'] = $context['show_email_address'] == 'yes' || $context['show_email_address'] == 'yes_permission_override';

		// Template
		$context['sub_template'] = 'custom_email';
		$context['page_title'] = $txt['send_email'];

		// Are we actually sending it?
		if (isset($this->http_req->post->send, $this->http_req->post->email_body))
		{
			$this->session->check();

			// Don't let them send too many!
			spamProtection('sendmail');



			// We will need to do some data checking
			$validator = new DataValidator();
			$validator->sanitation_rules(array(
				'y_name' => 'trim',
				'email_body' => 'trim',
				'email_subject' => 'trim'
			));
			$validator->validation_rules(array(
				'y_name' => 'required|notequal[_]',
				'y_email' => 'required|valid_email',
				'email_body' => 'required',
				'email_subject' => 'required'
			));
			$validator->text_replacements(array(
				'y_name' => $txt['sendtopic_sender_name'],
				'y_email' => $txt['sendtopic_sender_email'],
				'email_body' => $txt['message'],
				'email_subject' => $txt['send_email_subject']
			));
			$validator->validate($this->http_req->post);

			// If it's a guest sort out their names.
			if ($user_info['is_guest'])
			{
				$errors = $validator->validation_errors(array('y_name', 'y_email'));
				if ($errors)
				{
					$context['sendemail_error'] = array(
						'errors' => $errors,
						'type' => 'minor',
						'title' => $txt['validation_failure'],
					);
					return;
				}

				$from_name = $validator->y_name;
				$from_email = $validator->y_email;
			}
			else
			{
				$from_name = $user_info['name'];
				$from_email = $user_info['email'];
			}

			// Check we have a body (etc).
			$errors = $validator->validation_errors(array('email_body', 'email_subject'));
			if (!empty($errors))
			{
				$context['sendemail_error'] = array(
					'errors' => $errors,
					'type' => 'minor',
					'title' => $txt['validation_failure'],
				);
				return;
			}

			// We use a template in case they want to customise!
			$replacements = array(
				'EMAILSUBJECT' => $validator->email_subject,
				'EMAILBODY' => $validator->email_body,
				'SENDERNAME' => $from_name,
				'RECPNAME' => $context['recipient']['name'],
			);

			// Get the template and get out!
			$emaildata = loadEmailTemplate('send_email', $replacements);
			sendmail($context['recipient']['email'], $emaildata['subject'], $emaildata['body'], $from_email, null, false, 1, null, true);

			// Now work out where to go!
			if (!empty($uid))
				redirectexit('action=profile;u=' . $uid);
			elseif (!empty($mid))
				redirectexit('msg=' . $mid);
			else
				redirectexit();
		}
	}

	/**
	 * Report a post to the moderator... ask for a comment.
	 *
	 * what is does:
	 * - Gathers data from the user to report abuse to the moderator(s).
	 * - Uses the ReportToModerator template, main sub template.
	 * - Requires the report_any permission.
	 * - Uses action_reporttm2() if post data was sent.
	 * - Accessed through ?action=reporttm.
	 */
	public function action_reporttm()
	{
		global $txt, $modSettings, $user_info, $context;

		$context['robot_no_index'] = true;

		// You can't use this if it's off or you are not allowed to do it.
		isAllowedTo('report_any');

		// No errors, yet.
		$report_errors = ErrorContext::context('report', 1);

		// ...or maybe some.
		$context['report_error'] = array(
			'errors' => $report_errors->prepareErrors(),
			'type' => $report_errors->getErrorType() == 0 ? 'minor' : 'serious',
		);

		// If they're posting, it should be processed by action_reporttm2.
		if ((isset($this->http_req->post->$context['session_var']) || isset($this->http_req->post->save)) && !$report_errors->hasErrors())
			$this->action_reporttm2();

		// We need a message ID to check!
		if (empty($this->http_req->query->msg) && empty($this->http_req->post->msg))
			$this->_errors->fatal_lang_error('no_access', false);

		// Check the message's ID - don't want anyone reporting a post that does not exist

		$message_id = $this->http_req->getPost('msg', 'intval', isset($this->http_req->query->msg) ? (int) $this->http_req->query->msg : 0);
		if (basicMessageInfo($message_id, true, true) === false)
			$this->_errors->fatal_lang_error('no_board', false);

		// Do we need to show the visual verification image?
		$context['require_verification'] = $user_info['is_guest'] && !empty($modSettings['guests_report_require_captcha']);
		if ($context['require_verification'])
		{

			$verificationOptions = array(
				'id' => 'report',
			);
			$context['require_verification'] = create_control_verification($verificationOptions);
			$context['visual_verification_id'] = $verificationOptions['id'];
		}

		// Show the inputs for the comment, etc.
		loadLanguage('Post');
		loadLanguage('Errors');
		$this->_templates->load('Emailuser');

		theme()->addInlineJavascript('
		error_txts[\'post_too_long\'] = ' . JavaScriptEscape($txt['error_post_too_long']) . ';

		var report_errors = new errorbox_handler({
			self: \'report_errors\',
			error_box_id: \'report_error\',
			error_checks: [{
				code: \'post_too_long\',
				efunction: function(box_value) {
					if (box_value.length > 254)
						return true;
					else
						return false;
				}
			}],
			check_id: "report_comment"
		});', true);

		$context['comment_body'] = $this->http_req->getPost('comment', 'trim', '');
		$context['email_address'] = $this->http_req->getPost('email', 'trim', '');

		// This is here so that the user could, in theory, be redirected back to the topic.
		$context['start'] = $this->http_req->query->start;
		$context['message_id'] = $message_id;
		$context['page_title'] = $txt['report_to_mod'];
		$context['sub_template'] = 'report';
	}

	/**
	 * Send the emails.
	 *
	 * - Sends off emails to all the moderators.
	 * - Sends to administrators and global moderators. (1 and 2)
	 * - Called by action_reporttm(), and thus has the same permission and setting requirements as it does.
	 * - Accessed through ?action=reporttm when posting.
	 */
	public function action_reporttm2()
	{
		global $txt, $scripturl, $topic, $board, $user_info, $modSettings, $language, $context;

		// You must have the proper permissions!
		isAllowedTo('report_any');

		// Make sure they aren't spamming.
		spamProtection('reporttm');



		// No errors, yet.
		$report_errors = ErrorContext::context('report', 1);

		// Check their session.
		if ($this->session->check('post', '', false) != '')
			$report_errors->addError('session_timeout');

		// Make sure we have a comment and it's clean.
		if ($this->http_req->getPost('comment', '$GLOBALS['elk']['text']->htmltrim', '') === '')
			$report_errors->addError('no_comment');
		$poster_comment = strtr($GLOBALS['elk']['text']->htmlspecialchars($this->http_req->post->comment), array("\r" => '', "\t" => ''));

		if ($GLOBALS['elk']['text']->strlen($poster_comment) > 254)
			$report_errors->addError('post_too_long');

		// Guests need to provide their address!
		if ($user_info['is_guest'])
		{
			if (!DataValidator::is_valid($this->http_req->post, array('email' => 'valid_email'), array('email' => 'trim')))
				empty($this->http_req->post->email) ? $report_errors->addError('no_email') : $report_errors->addError('bad_email');

			$this->elk['ban_check']->isBannedEmail($this->http_req->post->email, 'cannot_post', sprintf($txt['you_are_post_banned'], $txt['guest_title']));

			$user_info['email'] = htmlspecialchars($this->http_req->post->email, ENT_COMPAT, 'UTF-8');
		}

		// Could they get the right verification code?
		if ($user_info['is_guest'] && !empty($modSettings['guests_report_require_captcha']))
		{

			$verificationOptions = array(
				'id' => 'report',
			);
			$context['require_verification'] = create_control_verification($verificationOptions, true);

			if (is_array($context['require_verification']))
			{
				foreach ($context['require_verification'] as $error)
					$report_errors->addError($error, 0);
			}
		}

		// Any errors?
		if ($report_errors->hasErrors())
		{
			$this->action_reporttm();
			return true;
		}

		// Get the basic topic information, and make sure they can see it.
		$msg_id = (int) $this->http_req->post->msg;
		$message = posterDetails($msg_id, $topic);

		if (empty($message))
			$this->_errors->fatal_lang_error('no_board', false);

		$poster_name = $GLOBALS['elk']['text']->un_htmlspecialchar($message['real_name']) . ($message['real_name'] != $message['poster_name'] ? ' (' . $message['poster_name'] . ')' : '');
		$reporterName = $GLOBALS['elk']['text']->un_htmlspecialchar($user_info['name']) . ($user_info['name'] != $user_info['username'] && $user_info['username'] != '' ? ' (' . $user_info['username'] . ')' : '');
		$subject = $GLOBALS['elk']['text']->un_htmlspecialchar($message['subject']);

		// Get a list of members with the moderate_board permission.
		$moderators = membersAllowedTo('moderate_board', $board);
		$result = getBasicMemberData($moderators, array('preferences' => true, 'sort' => 'lngfile'));
		$mod_to_notify = array();
		foreach ($result as $row)
		{
			if ($row['notify_types'] != 4)
				$mod_to_notify[] = $row;
		}

		// Check that moderators do exist!
		if (empty($mod_to_notify))
			$this->_errors->fatal_lang_error('no_mods', false);

		// If we get here, I believe we should make a record of this, for historical significance, yabber.
		if (empty($modSettings['disable_log_report']))
		{

			$message['type'] = 'msg';
			$id_report = recordReport($message, $poster_comment);

			// If we're just going to ignore these, then who gives a monkeys...
			if ($id_report === false)
				redirectexit('topic=' . $topic . '.msg' . $msg_id . '#msg' . $msg_id);
		}

		// Find out who the real moderators are - for mod preferences.

		$real_mods = getBoardModerators($board, true);

		// Send every moderator an email.
		foreach ($mod_to_notify as $row)
		{
			// Maybe they don't want to know?!
			if (!empty($row['mod_prefs']))
			{
				list (,, $pref_binary) = explode('|', $row['mod_prefs']);
				if (!($pref_binary & 1) && (!($pref_binary & 2) || !in_array($row['id_member'], $real_mods)))
					continue;
			}

			$replacements = array(
				'TOPICSUBJECT' => $subject,
				'POSTERNAME' => $poster_name,
				'REPORTERNAME' => $reporterName,
				'TOPICLINK' => $scripturl . '?topic=' . $topic . '.msg' . $msg_id . '#msg' . $msg_id,
				'REPORTLINK' => !empty($id_report) ? $scripturl . '?action=moderate;area=reports;report=' . $id_report : '',
				'COMMENT' => $this->http_req->post->comment,
			);

			$emaildata = loadEmailTemplate('report_to_moderator', $replacements, empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile']);

			// Send it to the moderator.
			sendmail($row['email_address'], $emaildata['subject'], $emaildata['body'], $user_info['email'], null, false, 2);
		}

		// Keep track of when the mod reports get updated, that way we know when we need to look again.
		updateSettings(array('last_mod_report_action' => time()));

		// Back to the post we reported!
		redirectexit('reportsent;topic=' . $topic . '.msg' . $msg_id . '#msg' . $msg_id);
	}
}