<?php

namespace Elkarte\Topics;

use Elkarte\Elkarte\Theme\AbstractContext;
use Elkarte\Elkarte\Theme\ContextInterface;

class TopicContext extends AbstractContext implements ContextInterface
{
	protected function getDefaultCommon()
	{
		$context['real_num_replies'] = $context['num_replies'] = $this->object->offsetGet('num_replies');
		$context['topic_first_message'] = $this->object->offsetGet('id_first_msg');
		$context['topic_last_message'] = $this->object->offsetGet('id_last_msg');
		$context['topic_unwatched'] = $this->object->offsetGet('unwatched') ?: 0;
		$context['user']['started'] = $user_info['id'] == $topicinfo['id_member_started'] && !$user_info['is_guest'];
		$context['topic_starter_id'] = $topicinfo['id_member_started'];
		$topicinfo['subject'] = censor($topicinfo['subject']);
		$context['is_locked'] = $topicinfo['locked'];
		$context['is_sticky'] = $topicinfo['is_sticky'];
		$context['is_very_hot'] = $topicinfo['num_replies'] >= $modSettings['hotTopicVeryPosts'];
		$context['is_hot'] = $topicinfo['num_replies'] >= $modSettings['hotTopicPosts'];
		$context['is_approved'] = $topicinfo['approved'];
		$context['subject'] = $topicinfo['subject'];
		$context['num_views'] = $topicinfo['num_views'];
		$context['num_views_text'] = $context['num_views'] == 1 ? $txt['read_one_time'] : sprintf($txt['read_many_times'], $context['num_views']);
		$context['mark_unread_time'] = !empty($this->_virtual_msg) ? $this->_virtual_msg : $topicinfo['new_from'];
	}

	public function link()
	{
		return '<a href="' . $this->href() . '">' . $this->offsetGet('name') . '</a>';
	}

	public function description()
	{
		// Hopefully someone already parsed it
		if ($this->offsetExists('raw_description'))
		{
			return $this->offsetGet('description');
		}

		$raw = $this->object->offsetGet('description');
		$parsed = $this->elk->offsetGet('topics.bbc_parser')->parseTopic($raw);

		// Cache this in the future
		$this->offsetSet('raw_description', $raw);
		$this->offsetSet('description', $parsed);

		return $parsed;
	}

	// @todo is this really necessary considering how easy url() is?
	public function href()
	{
		global $elk;
		return $elk->url(['topic' => $this->offsetGet('id')]) . '.0';
	}

	public function supports($object)
	{
		return $object instanceof Topic;
	}

	public function permissions($permission)
	{

		// Now set all the wonderful, wonderful permissions... like moderation ones...
		$common_permissions = array(
			'can_approve' => 'approve_posts',
			'can_ban' => 'manage_bans',
			'can_sticky' => 'make_sticky',
			'can_merge' => 'merge_any',
			'can_split' => 'split_any',
			'can_mark_notify' => 'mark_any_notify',
			'can_send_topic' => 'send_topic',
			'can_send_pm' => 'pm_send',
			'can_send_email' => 'send_email_to_members',
			'can_report_moderator' => 'report_any',
			'can_moderate_forum' => 'moderate_forum',
			'can_issue_warning' => 'issue_warning',
			'can_restore_topic' => 'move_any',
			'can_restore_msg' => 'move_any',
		);
		foreach ($common_permissions as $contextual => $perm)
			$context[$contextual] = allowedTo($perm);

		// Permissions with _any/_own versions.  $context[YYY] => ZZZ_any/_own.
		$anyown_permissions = array(
			'can_move' => 'move',
			'can_lock' => 'lock',
			'can_delete' => 'remove',
			'can_reply' => 'post_reply',
			'can_reply_unapproved' => 'post_unapproved_replies',
		);
		foreach ($anyown_permissions as $contextual => $perm)
			$context[$contextual] = allowedTo($perm . '_any') || ($context['user']['started'] && allowedTo($perm . '_own'));

		// Cleanup all the permissions with extra stuff...
		$context['can_mark_notify'] &= !$context['user']['is_guest'];
		$context['can_reply'] &= empty($topicinfo['locked']) || allowedTo('moderate_board');
		$context['can_reply_unapproved'] &= $modSettings['postmod_active'] && (empty($topicinfo['locked']) || allowedTo('moderate_board'));
		$context['can_issue_warning'] &= in_array('w', $context['admin_features']) && !empty($modSettings['warning_enable']);

		// Handle approval flags...
		$context['can_reply_approved'] = $context['can_reply'];
		$context['can_reply'] |= $context['can_reply_unapproved'];
		$context['can_quote'] = $context['can_reply'] && (empty($modSettings['disabledBBC']) || !in_array('quote', explode(',', $modSettings['disabledBBC'])));
		$context['can_mark_unread'] = !$user_info['is_guest'] && $settings['show_mark_read'];
		$context['can_unwatch'] = !$user_info['is_guest'] && $modSettings['enable_unwatch'];
		$context['can_send_topic'] = (!$modSettings['postmod_active'] || $topicinfo['approved']) && allowedTo('send_topic');
		$context['can_print'] = empty($modSettings['disable_print_topic']);

		// Start this off for quick moderation - it will be or'd for each post.
		$context['can_remove_post'] = allowedTo('delete_any') || (allowedTo('delete_replies') && $context['user']['started']);

		// Can restore topic?  That's if the topic is in the recycle board and has a previous restore state.
		$context['can_restore_topic'] &= !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] == $board && !empty($topicinfo['id_previous_board']);
		$context['can_restore_msg'] &= !empty($modSettings['recycle_enable']) && $modSettings['recycle_board'] == $board && !empty($topicinfo['id_previous_topic']);

	}
}