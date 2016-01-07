<?php

/**
 * Class that centralize the "notification" process.
 * ... or at least tries to.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0.3
 *
 */

namespace Elkarte\Notifications;

use Elkarte\Elkarte\AbstractModel;
use Elkarte\Mentions\Types\MentionTypeInterface;

class Notifications extends AbstractModel
{
	/**
	 * List of notifications to send
	 *
	 * @var NotificationsTask[]
	 */
	protected $_to_send;

	/**
	 * Available notification frequencies
	 *
	 * @var string[]
	 */
	protected $_notification_frequencies;

	/**
	 * Available notification frequencies
	 *
	 * @var string[]
	 */
	protected $_notifiers;

	/**
	 * Disallows to register notification types with id < 5
	 *
	 * @var bool
	 */
	protected $_protect_id = true;

	public function __construct($db)
	{
		parent::__construct($db);

		$this->_protect_id = false;

		// Let's register all the notifications we know by default
		$this->registerDefault();

		$this->_protect_id = true;

		$GLOBALS['elk']['hooks']->hook('notifications_methods', array($this));
	}

	/**
	 * Register the default notifications
	 *
	 * @return $this
	 * @throws ElkException
	 */
	protected function registerDefault()
	{
		$this->register(1, 'notification', array($this, '_send_notification'));
		$this->register(2, 'email', array($this, '_send_email'), array('subject' => 'subject', 'body' => 'body'));
		$this->register(3, 'email_daily', array($this, '_send_daily_email'), array('subject' => 'subject', 'body' => 'snippet'));
		$this->register(4, 'email_weekly', array($this, '_send_weekly_email'), array('subject' => 'subject', 'body' => 'snippet'));

		return $this;
	}
	/**
	 * We hax a new notification to send out!
	 *
	 * @param NotificationsTask $task
	 */
	public function add(NotificationsTask $task)
	{
		$this->_to_send[] = $task;
	}

	/**
	 * Time to notify our beloved members! YAY!
	 */
	public function send()
	{
		$this->_notification_frequencies = array(
			// 0 is for no notifications, so we start from 1 the counting, that saves a +1 later
			1 => 'notification',
			'email',
			'email_daily',
			'email_weekly',
		);

		if (!empty($this->_to_send))
		{
			foreach ($this->_to_send as $task)
				$this->_send_task($task);
		}

		$this->_to_send = array();
	}

	/**
	 * Function to register any new notification method.
	 *
	 * @param int $id This shall be a unique integer representing the
	 *            notification method.
	 *            <b>WARNING for addons developers</b>: please note that this has
	 *            to be unique across addons, so if you develop an addon that
	 *            extends notifications, please verify this id has not been
	 *            "taken" by someone else!
	 * @param int $key The string name identifying the notification method
	 * @param mixed|mixed[] $callback A callable function/array/whatever that
	 *                      will take care of sending the notification
	 * @param null|string[] $lang_data For the moment an array containing at least:
	 *                        - 'subject' => 'something'
	 *                        - 'body' => 'something_else'
	 *                       the two will be used to identify the strings to be
	 *                       used for the subject and the body respectively of
	 *                       the notification.
	 * @throws ElkException
	 */
	public function register($id, $key, $callback, $lang_data = null)
	{
		if ($this->_protect_id && $id < 5)
			throw new ElkException('error_invalid_notification_id');

		$this->_notifiers[$key] = array(
			'id' => $id,
			'key' => $key,
			'callback' => $callback,
			'lang_data' => $lang_data,
		);

		$this->_notification_frequencies[$id] = $key;
	}

	public function getNotifiers()
	{
		return $this->_notification_frequencies;
	}

	/**
	 * Process a certain task in order to send out the notifications.
	 *
	 * @param NotificationsTask $task
	 */
	protected function _send_task(NotificationsTask $task)
	{
		$class = $task->getClass();
		$obj = new $class($this->_db);
		$obj->setTask($task);


		$notification_frequencies = filterNotificationMethods($this->_notification_frequencies, $class::getType());

		// Cleanup the list of members to notify,
		// in certain cases it may differ from the list passed (if any)
		$task->setMembers($obj->getUsersToNotify());
		$notif_prefs = $this->_getNotificationPreferences($notification_frequencies, $task->notification_type, $task->getMembers());

		foreach ($notification_frequencies as $key)
		{
			if (!empty($notif_prefs[$key]))
			{
				$bodies = $obj->getNotificationBody($this->_notifiers[$key]['lang_data'], $notif_prefs[$key]);

				// Just in case...
				if (empty($bodies))
					continue;

				call_user_func_array($this->_notifiers[$key]['callback'], array($obj, $task, $bodies, $this->_db));
			}
		}
	}

	/**
	 * Inserts a new mention in the database (those that appear in the mentions area).
	 *
	 * @param MentionTypeInterface $obj
	 * @param NotificationsTask $task
	 * @param mixed[] $bodies
	 * @global $modSettings - Not sure if actually necessary
	 */
	protected function _send_notification(MentionTypeInterface $obj, NotificationsTask $task, $bodies)
	{
		global $modSettings;

		$mentioning = new Mentioning($this->_db, new DataValidator(), $modSettings['enabled_mentions']);
		foreach ($bodies as $body)
		{
			$mentioning->create($obj, array(
				'id_member_from' => $task['id_member_from'],
				'id_member' => $body['id_member_to'],
				'id_msg' => $task['id_target'],
				'type' => $task['notification_type'],
				'log_time' => $task['log_time'],
				'status' => $task['source_data']['status'],
			));
		}
	}

	/**
	 * Sends an immediate email notification.
	 *
	 * @param MentionTypeInterface $obj
	 * @param NotificationsTask $task
	 * @param mixed[] $bodies
	 */
	protected function _send_email(MentionTypeInterface $obj, NotificationsTask $task, $bodies)
	{
		$last_id = $obj->getLastId();
		foreach ($bodies as $body)
		{
			sendmail($body['email_address'], $body['subject'], $body['body'], null, $last_id);
		}
	}

	/**
	 * Stores data in the database to send a daily digest.
	 *
	 * @param MentionTypeInterface $obj
	 * @param NotificationsTask $task
	 * @param mixed[] $bodies
	 */
	protected function _send_daily_email(MentionTypeInterface $obj, NotificationsTask $task, $bodies)
	{
		foreach ($bodies as $body)
		{
			$this->_insert_delayed(array(
				$task['notification_type'],
				$body['id_member_to'],
				$task['log_time'],
				'd',
				$body['body']
			));
		}
	}

	/**
	 * Stores data in the database to send a weekly digest.
	 *
	 * @param MentionTypeInterface $obj
	 * @param NotificationsTask $task
	 * @param mixed[] $bodies
	 */
	protected function _send_weekly_email(MentionTypeInterface $obj, NotificationsTask $task, $bodies)
	{
		foreach ($bodies as $body)
		{
			$this->_insert_delayed(array(
				$task['notification_type'],
				$body['id_member_to'],
				$task['log_time'],
				'w',
				$body['body']
			));
		}
	}

	/**
	 * Do the insert into the database for daily and weekly digests.
	 *
	 * @param mixed[] $insert_array
	 */
	protected function _insert_delayed($insert_array)
	{
		$this->_db->insert('ignore',
			'{db_prefix}pending_notifications',
			array(
				'notification_type' => 'string-10',
				'id_member' => 'int',
				'log_time' => 'int',
				'frequency' => 'string-1',
				'snippet ' => 'string',
			),
			$insert_array,
			array()
		);
	}

	/**
	 * Loads from the database the notification preferences for a certain type
	 * of mention for a bunch of members.
	 *
	 * @param string[] $notification_frequencies
	 * @param string $notification_type
	 * @param int[] $members
	 */
	protected function _getNotificationPreferences($notification_frequencies, $notification_type, $members)
	{
		$query_members = $members;
		// The member 0 is the "default" setting
		$query_members[] = 0;


		$preferences = getUsersNotificationsPreferences($notification_type, $query_members);

		$notification_types = array();
		foreach ($notification_frequencies as $freq)
			$notification_types[$freq] = array();

		// notification_level can be:
		//    - 0 => no notification
		//    - 1 => only mention
		//    - 2 => mention + immediate email
		//    - 3 => mention + daily email
		//    - 4 => mention + weekly email
		//    - 5+ => usable by addons
		foreach ($members as $member)
		{
			$this_pref = $preferences[$member][$notification_type];
			if ($this_pref === 0)
				continue;

			// In the following two checks the use of the $this->_notification_frequencies
			// is intended, because the numeric id is important and is not preserved
			// in the local $notification_frequencies
			if (isset($this->_notification_frequencies[1]))
				$notification_types[$this->_notification_frequencies[1]][] = $member;

			if ($this_pref > 1)
			{
				if (isset($this->_notification_frequencies[$this_pref]) && isset($notification_types[$this->_notification_frequencies[$this_pref]]))
					$notification_types[$this->_notification_frequencies[$this_pref]][] = $member;
			}
		}

		return $notification_types;
	}

	/**
	 * Sends a notification to members who have elected to receive emails
	 * when things happen to a topic, such as replies are posted.
	 * The function automatically finds the subject and its board, and
	 * checks permissions for each member who is "signed up" for notifications.
	 * It will not send 'reply' notifications more than once in a row.
	 *
	 * @param int[]|int $topics - represents the topics the action is happening to.
	 * @param string $type - can be any of reply, sticky, lock, unlock, remove,
	 *                       move, merge, and split.  An appropriate message will be sent for each.
	 * @param int[]|int $exclude = array() - members in the exclude array will not be
	 *                                   processed for the topic with the same key.
	 * @param int[]|int $members_only = array() - are the only ones that will be sent the notification if they have it on.
	 * @param mixed[] $pbe = array() - array containing user_info if this is being run as a result of an email posting
	 * @uses Post language file
	 *
	 */
	function sendNotifications($topics, $type, $exclude = array(), $members_only = array(), $pbe = array())
	{
		global $txt, $scripturl, $language, $user_info, $webmaster_email, $mbname, $modSettings;

		$db = $GLOBALS['elk']['db'];

		// Coming in from emailpost or emailtopic, if so pbe values will be set to the credentials of the emailer
		$user_id = (!empty($pbe['user_info']['id']) && !empty($modSettings['maillist_enabled'])) ? $pbe['user_info']['id'] : $user_info['id'];
		$user_language = (!empty($pbe['user_info']['language']) && !empty($modSettings['maillist_enabled'])) ? $pbe['user_info']['language'] : $user_info['language'];

		// Can't do it if there's no topics.
		if (empty($topics))
			return;

		// It must be an array - it must!
		if (!is_array($topics))
			$topics = array($topics);

		// I hope we are not sending one of those silly moderation notices
		$maillist = !empty($modSettings['maillist_enabled']) && !empty($modSettings['pbe_post_enabled']);
		if ($type !== 'reply' && !empty($maillist) && !empty($modSettings['pbe_no_mod_notices']))
			return;

		// Load in our dependencies
		require_once(ROOTDIR . '/Mail/Emailpost.subs.php');


		// Get the subject, body and basic poster details, number of attachments if any
		$result = $db->query('', '
		SELECT mf.subject, ml.body, ml.id_member, t.id_last_msg, t.id_topic, t.id_board, mem.signature,
			IFNULL(mem.real_name, ml.poster_name) AS poster_name, COUNT(a.id_attach) as num_attach
		FROM {db_prefix}topics AS t
			INNER JOIN {db_prefix}messages AS mf ON (mf.id_msg = t.id_first_msg)
			INNER JOIN {db_prefix}messages AS ml ON (ml.id_msg = t.id_last_msg)
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = ml.id_member)
			LEFT JOIN {db_prefix}attachments AS a ON(a.attachment_type = {int:attachment_type} AND a.id_msg = t.id_last_msg)
		WHERE t.id_topic IN ({array_int:topic_list})
		GROUP BY t.id_topic, mf.subject, ml.body, ml.id_member, mem.signature, mem.real_name, ml.poster_name',
			array(
				'topic_list' => $topics,
				'attachment_type' => 0,
			)
		);
		$topicData = array();
		$boards_index = array();
		while ($row = $result->fetchAssoc())
		{
			// Convert to markdown e.g. text ;) and clean it up
			pbe_prepare_text($row['body'], $row['subject'], $row['signature']);

			// all the boards for these topics, used to find all the members to be notified
			$boards_index[] = $row['id_board'];

			// And the information we are going to tell them about
			$topicData[$row['id_topic']] = array(
				'subject' => $row['subject'],
				'body' => $row['body'],
				'last_id' => $row['id_last_msg'],
				'topic' => $row['id_topic'],
				'board' => $row['id_board'],
				'name' => $type === 'reply' ? $row['poster_name'] : $user_info['name'],
				'exclude' => '',
				'signature' => $row['signature'],
				'attachments' => $row['num_attach'],
			);
		}
		$result->free();

		// Work out any exclusions...
		foreach ($topics as $key => $id)
			if (isset($topicData[$id]) && !empty($exclude[$key]))
				$topicData[$id]['exclude'] = (int) $exclude[$key];

		// Nada?
		if (empty($topicData))
			trigger_error('sendNotifications(): topics not found', E_USER_NOTICE);

		$topics = array_keys($topicData);

		// Just in case they've gone walkies.
		if (empty($topics))
			return;

		// Insert all of these items into the digest log for those who want notifications later.
		$digest_insert = array();
		foreach ($topicData as $id => $data)
			$digest_insert[] = array($data['topic'], $data['last_id'], $type, (int) $data['exclude']);

		$db->insert('',
			'{db_prefix}log_digest',
			array(
				'id_topic' => 'int', 'id_msg' => 'int', 'note_type' => 'string', 'exclude' => 'int',
			),
			$digest_insert,
			array()
		);

		// Are we doing anything here?
		$sent = 0;

		// Using the posting email function in either group or list mode
		if ($maillist)
		{
			// Find the members with *board* notifications on.
			$members = $db->query('', '
			SELECT
				mem.id_member, mem.email_address, mem.notify_regularity, mem.notify_types, mem.notify_send_body, mem.lngfile, mem.warning,
				ln.sent, mem.id_group, mem.additional_groups, b.member_groups, mem.id_post_group, b.name, b.id_profile,
				ln.id_board
			FROM {db_prefix}log_notify AS ln
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = ln.id_board)
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = ln.id_member)
			WHERE ln.id_board IN ({array_int:board_list})
				AND mem.notify_types != {int:notify_types}
				AND mem.notify_regularity < {int:notify_regularity}
				AND mem.is_activated = {int:is_activated}
				AND ln.id_member != {int:current_member}' .
				(empty($members_only) ? '' : ' AND ln.id_member IN ({array_int:members_only})') . '
			ORDER BY mem.lngfile',
				array(
					'current_member' => $user_id,
					'board_list' => $boards_index,
					'notify_types' => $type === 'reply' ? 4 : 3,
					'notify_regularity' => 2,
					'is_activated' => 1,
					'members_only' => is_array($members_only) ? $members_only : array($members_only),
				)
			);
			$boards = array();
			while ($row = $db->fetch_assoc($members))
			{
				// If they are not the poster do they want to know?
				// @todo maybe if they posted via email?
				if ($type !== 'reply' && $row['notify_types'] == 2)
					continue;

				// for this member/board, loop through the topics and see if we should send it
				foreach ($topicData as $id => $data)
				{
					// Don't send it if its not from the right board
					if ($data['board'] !== $row['id_board'])
						continue;
					else
						$data['board_name'] = $row['name'];

					// Don't do the excluded...
					if ($data['exclude'] === $row['id_member'])
						continue;

					$email_perm = true;
					if (validateNotificationAccess($row, $maillist, $email_perm) === false)
						continue;

					$needed_language = empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile'];
					if (empty($current_language) || $current_language != $needed_language)
						$current_language = loadLanguage('Post', $needed_language, false);

					$message_type = 'notification_' . $type;
					$replacements = array(
						'TOPICSUBJECT' => $data['subject'],
						'POSTERNAME' => un_htmlspecialchars($data['name']),
						'TOPICLINKNEW' => $scripturl . '?topic=' . $id . '.new;topicseen#new',
						'TOPICLINK' => $scripturl . '?topic=' . $id . '.msg' . $data['last_id'] . '#msg' . $data['last_id'],
						'UNSUBSCRIBELINK' => $scripturl . '?action=notifyboard;board=' . $data['board'] . '.0',
						'SIGNATURE' => $data['signature'],
						'BOARDNAME' => $data['board_name'],
					);

					if ($type === 'remove')
						unset($replacements['TOPICLINK'], $replacements['UNSUBSCRIBELINK']);

					// Do they want the body of the message sent too?
					if (!empty($row['notify_send_body']) && $type === 'reply')
					{
						$message_type .= '_body';
						$replacements['MESSAGE'] = $data['body'];

						// Any attachments? if so lets make a big deal about them!
						if ($data['attachments'] != 0)
							$replacements['MESSAGE'] .= "\n\n" . sprintf($txt['message_attachments'], $data['attachments'], $replacements['TOPICLINK']);
					}

					if (!empty($row['notify_regularity']) && $type === 'reply')
						$message_type .= '_once';

					// Give them a way to add in their own replacements
					$GLOBALS['elk']['hooks']->hook('notification_replacements', array(&$replacements, $row, $type, $current_language));

					// Send only if once is off or it's on and it hasn't been sent.
					if ($type !== 'reply' || empty($row['notify_regularity']) || empty($row['sent']))
					{
						$emaildata = loadEmailTemplate((($maillist && $email_perm && $type === 'reply' && !empty($row['notify_send_body'])) ? 'pbe_' : '') . $message_type, $replacements, $needed_language);

						// If using the maillist functions, we adjust who this is coming from
						if ($maillist && $email_perm && $type === 'reply' && !empty($row['notify_send_body']))
						{
							// In group mode like google group or yahoo group, the mail is from the poster
							// Otherwise in maillist mode, it is from the site
							$emailfrom = !empty($modSettings['maillist_group_mode']) ? un_htmlspecialchars($data['name']) : (!empty($modSettings['maillist_sitename']) ? un_htmlspecialchars($modSettings['maillist_sitename']) : $mbname);

							// The email address of the sender, irrespective of the envelope name above
							$from_wrapper = !empty($modSettings['maillist_mail_from']) ? $modSettings['maillist_mail_from'] : (empty($modSettings['maillist_sitename_address']) ? $webmaster_email : $modSettings['maillist_sitename_address']);
							sendmail($row['email_address'], $emaildata['subject'], $emaildata['body'], $emailfrom, 'm' . $data['last_id'], false, 3, null, false, $from_wrapper, $id);
						}
						else
							sendmail($row['email_address'], $emaildata['subject'], $emaildata['body'], null, 'm' . $data['last_id']);

						$sent++;

						// Make a note that this member was sent this topic
						$boards[$row['id_member']][$id] = 1;
					}
				}
			}
			$db->free_result($members);
		}

		// Find the members with notification on for this topic.
		$members = $db->query('', '
		SELECT
			mem.id_member, mem.email_address, mem.notify_regularity, mem.notify_types, mem.warning,
			mem.notify_send_body, mem.lngfile, mem.id_group, mem.additional_groups,mem.id_post_group,
			t.id_member_started, b.member_groups, b.name, b.id_profile, b.id_board,
			ln.id_topic, ln.sent
		FROM {db_prefix}log_notify AS ln
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = ln.id_member)
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = ln.id_topic)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
		WHERE ln.id_topic IN ({array_int:topic_list})
			AND mem.notify_types < {int:notify_types}
			AND mem.notify_regularity < {int:notify_regularity}
			AND mem.is_activated = {int:is_activated}
			AND ln.id_member != {int:current_member}' .
			(empty($members_only) ? '' : ' AND ln.id_member IN ({array_int:members_only})') . '
		ORDER BY mem.lngfile',
			array(
				'current_member' => $user_id,
				'topic_list' => $topics,
				'notify_types' => $type == 'reply' ? 4 : 3,
				'notify_regularity' => 2,
				'is_activated' => 1,
				'members_only' => is_array($members_only) ? $members_only : array($members_only),
			)
		);
		while ($row = $db->fetch_assoc($members))
		{
			// Don't do the excluded...
			if ($topicData[$row['id_topic']]['exclude'] == $row['id_member'])
				continue;

			// Don't do the ones that were sent via board notification, you only get one notice
			if (isset($boards[$row['id_member']][$row['id_topic']]))
				continue;

			// Easier to check this here... if they aren't the topic poster do they really want to know?
			// @todo prehaps just if they posted by email?
			if ($type != 'reply' && $row['notify_types'] == 2 && $row['id_member'] != $row['id_member_started'])
				continue;

			$email_perm = true;
			if (validateNotificationAccess($row, $maillist, $email_perm) === false)
				continue;

			$needed_language = empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile'];
			if (empty($current_language) || $current_language != $needed_language)
				$current_language = loadLanguage('Post', $needed_language, false);

			$message_type = 'notification_' . $type;
			$replacements = array(
				'TOPICSUBJECT' => $topicData[$row['id_topic']]['subject'],
				'POSTERNAME' => un_htmlspecialchars($topicData[$row['id_topic']]['name']),
				'TOPICLINKNEW' => $scripturl . '?topic=' . $row['id_topic'] . '.new;topicseen#new',
				'TOPICLINK' => $scripturl . '?topic=' . $row['id_topic'] . '.msg' . $data['last_id'] . '#msg' . $data['last_id'],
				'UNSUBSCRIBELINK' => $scripturl . '?action=notify;topic=' . $row['id_topic'] . '.0',
				'SIGNATURE' => $topicData[$row['id_topic']]['signature'],
				'BOARDNAME' => $row['name'],
			);

			if ($type == 'remove')
				unset($replacements['TOPICLINK'], $replacements['UNSUBSCRIBELINK']);

			// Do they want the body of the message sent too?
			if (!empty($row['notify_send_body']) && $type == 'reply')
			{
				$message_type .= '_body';
				$replacements['MESSAGE'] = $topicData[$row['id_topic']]['body'];
			}
			if (!empty($row['notify_regularity']) && $type == 'reply')
				$message_type .= '_once';

			// Send only if once is off or it's on and it hasn't been sent.
			if ($type != 'reply' || empty($row['notify_regularity']) || empty($row['sent']))
			{
				$emaildata = loadEmailTemplate((($maillist && $email_perm && $type === 'reply' && !empty($row['notify_send_body'])) ? 'pbe_' : '') . $message_type, $replacements, $needed_language);

				// Using the maillist functions? Then adjust the from wrapper
				if ($maillist && $email_perm && $type === 'reply' && !empty($row['notify_send_body']))
				{
					// Set the from name base on group or maillist mode
					$emailfrom = !empty($modSettings['maillist_group_mode']) ? un_htmlspecialchars($topicData[$row['id_topic']]['name']) : un_htmlspecialchars($modSettings['maillist_sitename']);
					$from_wrapper = !empty($modSettings['maillist_mail_from']) ? $modSettings['maillist_mail_from'] : (empty($modSettings['maillist_sitename_address']) ? $webmaster_email : $modSettings['maillist_sitename_address']);
					sendmail($row['email_address'], $emaildata['subject'], $emaildata['body'], $emailfrom, 'm' . $data['last_id'], false, 3, null, false, $from_wrapper, $row['id_topic']);
				}
				else
					sendmail($row['email_address'], $emaildata['subject'], $emaildata['body'], null, 'm' . $topicData[$row['id_topic']]['last_id']);

				$sent++;
			}
		}
		$db->free_result($members);

		if (isset($current_language) && $current_language != $user_language)
			loadLanguage('Post');

		// Sent!
		if ($type == 'reply' && !empty($sent))
			$db->query('', '
			UPDATE {db_prefix}log_notify
			SET sent = {int:is_sent}
			WHERE id_topic IN ({array_int:topic_list})
				AND id_member != {int:current_member}',
				array(
					'current_member' => $user_id,
					'topic_list' => $topics,
					'is_sent' => 1,
				)
			);

		// For approvals we need to unsend the exclusions (This *is* the quickest way!)
		if (!empty($sent) && !empty($exclude))
		{
			foreach ($topicData as $id => $data)
			{
				if ($data['exclude'])
				{
					$db->query('', '
					UPDATE {db_prefix}log_notify
					SET sent = {int:not_sent}
					WHERE id_topic = {int:id_topic}
						AND id_member = {int:id_member}',
						array(
							'not_sent' => 0,
							'id_topic' => $id,
							'id_member' => $data['exclude'],
						)
					);
				}
			}
		}
	}

	/**
	 * A special function for handling the hell which is sending approval notifications.
	 *
	 * @param mixed[] $topicData
	 */
	function sendApprovalNotifications(&$topicData)
	{
		global $scripturl, $language, $user_info, $modSettings;

		$db = $GLOBALS['elk']['db'];

		// Clean up the data...
		if (!is_array($topicData) || empty($topicData))
			return;

		// Email ahoy

		require_once(ROOTDIR . '/Mail/Emailpost.subs.php');

		$topics = array();
		$digest_insert = array();
		foreach ($topicData as $topic => $msgs)
		{
			foreach ($msgs as $msgKey => $msg)
			{
				// Convert it to markdown for sending, censor is done as well
				pbe_prepare_text($topicData[$topic][$msgKey]['body'], $topicData[$topic][$msgKey]['subject']);

				$topics[] = $msg['id'];
				$digest_insert[] = array($msg['topic'], $msg['id'], 'reply', $user_info['id']);
			}
		}

		// These need to go into the digest too...
		$db->insert('',
			'{db_prefix}log_digest',
			array(
				'id_topic' => 'int', 'id_msg' => 'int', 'note_type' => 'string', 'exclude' => 'int',
			),
			$digest_insert,
			array()
		);

		// Find everyone who needs to know about this.
		$members = $db->query('', '
		SELECT
			mem.id_member, mem.email_address, mem.notify_regularity, mem.notify_types, mem.notify_send_body, mem.lngfile,
			ln.sent, mem.id_group, mem.additional_groups, b.member_groups, mem.id_post_group, t.id_member_started,
			ln.id_topic
		FROM {db_prefix}log_notify AS ln
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = ln.id_member)
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = ln.id_topic)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
		WHERE ln.id_topic IN ({array_int:topic_list})
			AND mem.is_activated = {int:is_activated}
			AND mem.notify_types < {int:notify_types}
			AND mem.notify_regularity < {int:notify_regularity}
		GROUP BY mem.id_member, ln.id_topic, mem.email_address, mem.notify_regularity, mem.notify_types, mem.notify_send_body, mem.lngfile, ln.sent, mem.id_group, mem.additional_groups, b.member_groups, mem.id_post_group, t.id_member_started
		ORDER BY mem.lngfile',
			array(
				'topic_list' => $topics,
				'is_activated' => 1,
				'notify_types' => 4,
				'notify_regularity' => 2,
			)
		);
		$sent = 0;
		$current_language = $user_info['language'];
		while ($row = $db->fetch_assoc($members))
		{
			if ($row['id_group'] != 1)
			{
				$allowed = explode(',', $row['member_groups']);
				$row['additional_groups'] = explode(',', $row['additional_groups']);
				$row['additional_groups'][] = $row['id_group'];
				$row['additional_groups'][] = $row['id_post_group'];

				if (count(array_intersect($allowed, $row['additional_groups'])) == 0)
					continue;
			}

			$needed_language = empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile'];
			if (empty($current_language) || $current_language != $needed_language)
				$current_language = loadLanguage('Post', $needed_language, false);

			$sent_this_time = false;
			$replacements = array(
				'TOPICLINK' => $scripturl . '?topic=' . $row['id_topic'] . '.new;topicseen#new',
				'UNSUBSCRIBELINK' => $scripturl . '?action=notify;topic=' . $row['id_topic'] . '.0',
			);

			// Now loop through all the messages to send.
			foreach ($topicData[$row['id_topic']] as $msg)
			{
				$replacements += array(
					'TOPICSUBJECT' => $msg['subject'],
					'POSTERNAME' => un_htmlspecialchars($msg['name']),
				);

				$message_type = 'notification_reply';

				// Do they want the body of the message sent too?
				if (!empty($row['notify_send_body']) && empty($modSettings['disallow_sendBody']))
				{
					$message_type .= '_body';
					$replacements['MESSAGE'] = $msg['body'];
				}

				if (!empty($row['notify_regularity']))
					$message_type .= '_once';

				// Send only if once is off or it's on and it hasn't been sent.
				if (empty($row['notify_regularity']) || (empty($row['sent']) && !$sent_this_time))
				{
					$emaildata = loadEmailTemplate($message_type, $replacements, $needed_language);
					sendmail($row['email_address'], $emaildata['subject'], $emaildata['body'], null, 'm' . $msg['last_id']);
					$sent++;
				}

				$sent_this_time = true;
			}
		}
		$db->free_result($members);

		if (isset($current_language) && $current_language != $user_info['language'])
			loadLanguage('Post');

		// Sent!
		if (!empty($sent))
			$db->query('', '
			UPDATE {db_prefix}log_notify
			SET sent = {int:is_sent}
			WHERE id_topic IN ({array_int:topic_list})
				AND id_member != {int:current_member}',
				array(
					'current_member' => $user_info['id'],
					'topic_list' => $topics,
					'is_sent' => 1,
				)
			);
	}

	/**
	 * This simple function gets a list of all administrators and sends them an email
	 * to let them know a new member has joined.
	 * Called by registerMember() function in subs/Members.subs.php.
	 * Email is sent to all groups that have the moderate_forum permission.
	 * The language set by each member is being used (if available).
	 *
	 * @param string $type types supported are 'approval', 'activation', and 'standard'.
	 * @param int $memberID
	 * @param string|null $member_name = null
	 * @uses the Login language file.
	 */
	function sendAdminNotifications($type, $memberID, $member_name = null)
	{
		global $modSettings, $language, $scripturl, $user_info;

		$db = $GLOBALS['elk']['db'];

		// If the setting isn't enabled then just exit.
		if (empty($modSettings['notify_new_registration']))
			return;

		// Needed to notify admins, or anyone


		if ($member_name == null)
		{

			// Get the new user's name....
			$member_info = getBasicMemberData($memberID);
			$member_name = $member_info['real_name'];
		}

		$groups = array();

		// All membergroups who can approve members.
		$request = $db->query('', '
		SELECT id_group
		FROM {db_prefix}permissions
		WHERE permission = {string:moderate_forum}
			AND add_deny = {int:add_deny}
			AND id_group != {int:id_group}',
			array(
				'add_deny' => 1,
				'id_group' => 0,
				'moderate_forum' => 'moderate_forum',
			)
		);
		while ($row = $request->fetchAssoc())
			$groups[] = $row['id_group'];
		$request->free();

		// Add administrators too...
		$groups[] = 1;
		$groups = array_unique($groups);

		// Get a list of all members who have ability to approve accounts - these are the people who we inform.
		$request = $db->query('', '
		SELECT id_member, lngfile, email_address
		FROM {db_prefix}members
		WHERE (id_group IN ({array_int:group_list}) OR FIND_IN_SET({raw:group_array_implode}, additional_groups) != 0)
			AND notify_types != {int:notify_types}
		ORDER BY lngfile',
			array(
				'group_list' => $groups,
				'notify_types' => 4,
				'group_array_implode' => implode(', additional_groups) != 0 OR FIND_IN_SET(', $groups),
			)
		);

		$current_language = $user_info['language'];
		while ($row = $request->fetchAssoc())
		{
			$replacements = array(
				'USERNAME' => $member_name,
				'PROFILELINK' => $scripturl . '?action=profile;u=' . $memberID
			);
			$emailtype = 'admin_notify';

			// If they need to be approved add more info...
			if ($type == 'approval')
			{
				$replacements['APPROVALLINK'] = $scripturl . '?action=Admin;area=viewmembers;sa=browse;type=approve';
				$emailtype .= '_approval';
			}

			$emaildata = loadEmailTemplate($emailtype, $replacements, empty($row['lngfile']) || empty($modSettings['userLanguage']) ? $language : $row['lngfile']);

			// And do the actual sending...
			sendmail($row['email_address'], $emaildata['subject'], $emaildata['body'], null, null, false, 0);
		}
		$request->free();

		if (isset($current_language) && $current_language != $user_info['language'])
			loadLanguage('Login');
	}

	/**
	 * Checks if a user has the correct access to get notifications
	 * - validates they have proper group access to a board
	 * - if using the maillist, checks if they should get a reply-able message
	 *     - not muted
	 *     - has postby_email permission on the board
	 *
	 * Returns false if they do not have the proper group access to a board
	 * Sets email_perm to false if they should not get a reply-able message
	 *
	 * @param mixed[] $row
	 * @param boolean $maillist
	 * @param boolean $email_perm
	 * @return bool
	 */
	function validateNotificationAccess($row, $maillist, &$email_perm = true)
	{
		global $modSettings;

		static $board_profile = array();

		$allowed = explode(',', $row['member_groups']);
		$row['additional_groups'] = !empty($row['additional_groups']) ? explode(',', $row['additional_groups']) : array();
		$row['additional_groups'][] = $row['id_group'];
		$row['additional_groups'][] = $row['id_post_group'];

		// No need to check for you ;)
		if ($row['id_group'] == 1 || in_array('1', $row['additional_groups']))
			return $email_perm;

		// They do have access to this board?
		if (count(array_intersect($allowed, $row['additional_groups'])) === 0)
			return false;

		// If using maillist, see if they should get a reply-able message
		if ($maillist)
		{
			// Perhaps they don't require a security key in the message
			if (!empty($modSettings['postmod_active']) && !empty($modSettings['warning_mute']) && $modSettings['warning_mute'] <= $row['warning'])
				$email_perm = false;
			else
			{
				if (!isset($board_profile[$row['id_board']]))
				{
					$board_profile[$row['id_board']] = groupsAllowedTo('postby_email', $row['id_board']);
				}

				// In a group that has email posting permissions on this board
				if (count(array_intersect($board_profile[$row['id_board']]['allowed'], $row['additional_groups'])) === 0)
					$email_perm = false;

				// And not specifically denied?
				if ($email_perm && !empty($modSettings['permission_enable_deny'])
					&& count(array_intersect($row['additional_groups'], $board_profile[$row['id_board']]['denied'])) !== 0)
					$email_perm = false;
			}
		}

		return $email_perm;
	}

	/**
	 * Queries the database for notification preferences of a set of members.
	 *
	 * @param string[]|string $notification_types
	 * @param int[]|int $members
	 */
	function getUsersNotificationsPreferences($notification_types, $members)
	{
		$db = $GLOBALS['elk']['db'];

		$notification_types = (array) $notification_types;
		$query_members = (array) $members;
		$query_members[] = 0;

		$request = $db->query('', '
		SELECT id_member, notification_level, mention_type
		FROM {db_prefix}notifications_pref
		WHERE id_member IN ({array_int:members_to})
			AND mention_type IN ({array_string:mention_types})',
			array(
				'members_to' => $query_members,
				'mention_types' => $notification_types,
			)
		);
		$results = array();

		while ($row = $request->fetchAssoc())
		{
			if (!isset($results[$row['id_member']]))
				$results[$row['id_member']] = array();

			$results[$row['id_member']][$row['mention_type']] = (int) $row['notification_level'];
		}

		$request->free();

		$defaults = array();
		foreach ($notification_types as $val)
		{
			$defaults[$val] = 0;
		}
		if (isset($results[0]))
		{
			$defaults = array_merge($defaults, $results[0]);
		}

		$preferences = array();
		foreach ((array) $members as $member)
		{
			$preferences[$member] = $defaults;
			if (isset($results[$member]))
				$preferences[$member] = array_merge($preferences[$member], $results[$member]);
		}

		return $preferences;
	}

	/**
	 * Saves into the database the notification preferences of a certain member.
	 *
	 * @param int $member The member id
	 * @param int[] $notification_types The array of notifications ('type' => 'level')
	 */
	function saveUserNotificationsPreferences($member, $notification_data)
	{
		$db = $GLOBALS['elk']['db'];

		// First drop the existing settings
		$db->query('', '
		DELETE FROM {db_prefix}notifications_pref
		WHERE id_member = {int:member}
			AND mention_type IN ({array_string:mention_types})',
			array(
				'member' => $member,
				'mention_types' => array_keys($notification_data),
			)
		);

		$inserts = array();
		foreach ($notification_data as $type => $level)
		{
			$inserts[] = array(
				$member,
				$type,
				$level,
			);
		}

		if (empty($inserts))
			return;

		$db->insert('',
			'{db_prefix}notifications_pref',
			array(
				'id_member' => 'int',
				'mention_type' => 'string-12',
				'notification_level' => 'int',
			),
			$inserts,
			array('id_member', 'mention_type')
		);
	}

	/**
	 * From the list of all possible notification methods available, only those
	 * enabled are returned.
	 *
	 * @param string[] $possible_methods The array of notifications ('type' => 'level')
	 * @param string $type The type of notification (mentionmem, likemsg, etc.)
	 */
	function filterNotificationMethods($possible_methods, $type)
	{
		$unserialized = getConfiguredNotificationMethods($type);

		if (empty($unserialized))
			return array();

		$allowed = array();
		foreach ($possible_methods as $key => $val)
		{
			if (isset($unserialized[$val]))
				$allowed[$key] = $val;
		}

		return $allowed;
	}

	/**
	 * Returns all the enabled methods of notification for a specific
	 * type of notification.
	 *
	 * @param string $type The type of notification (mentionmem, likemsg, etc.)
	 * @return array
	 */
	function getConfiguredNotificationMethods($type)
	{
		global $modSettings;
		static $unserialized = null;

		if ($unserialized === null)
			$unserialized = unserialize($modSettings['notification_methods']);

		if (isset($unserialized[$type]))
		{
			return $unserialized[$type];
		}
		else
		{
			return array();
		}
	}
}