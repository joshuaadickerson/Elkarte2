<?php

namespace Elkarte\Messages;

class Warnings
{

	/**
	 * Log a warning notice.
	 *
	 * @param string $subject
	 * @param string $body
	 * @return int
	 */
	function logWarningNotice($subject, $body)
	{
		$db = $GLOBALS['elk']['db'];

		// Log warning notice.
		$result = $db->insert('',
			'{db_prefix}log_member_notices',
			array(
				'subject' => 'string-255', 'body' => 'string-65534',
			),
			array(
				$GLOBALS['elk']['text']->htmlspecialchars($subject), $GLOBALS['elk']['text']->htmlspecialchars($body),
			),
			array('id_notice')
		);
		$id_notice = (int) $result->insertId('{db_prefix}log_member_notices', 'id_notice');

		return $id_notice;
	}

	/**
	 * Logs the warning being sent to the user so other moderators can see
	 *
	 * @param int $memberID
	 * @param string $real_name
	 * @param int $id_notice
	 * @param int $level_change
	 * @param string $warn_reason
	 */
	function logWarning($memberID, $real_name, $id_notice, $level_change, $warn_reason)
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		$db->insert('',
			'{db_prefix}log_comments',
			array(
				'id_member' => 'int', 'member_name' => 'string', 'comment_type' => 'string', 'id_recipient' => 'int', 'recipient_name' => 'string-255',
				'log_time' => 'int', 'id_notice' => 'int', 'counter' => 'int', 'body' => 'string-65534',
			),
			array(
				$user_info['id'], $user_info['name'], 'warning', $memberID, $real_name,
				time(), $id_notice, $level_change, $warn_reason,
			),
			array('id_comment')
		);
	}


	/**
	 * Removes a custom moderation center template from log_comments
	 *  - Logs the template removal action for each warning affected
	 *  - Removes the details for all warnings that used the template being removed
	 *
	 * @param int $id_tpl id of the template to remove
	 * @param string $template_type type of template, defaults to warntpl
	 */
	function removeWarningTemplate($id_tpl, $template_type = 'warntpl')
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		// Log the actions.
		$request = $db->query('', '
		SELECT recipient_name
		FROM {db_prefix}log_comments
		WHERE id_comment IN ({array_int:delete_ids})
			AND comment_type = {string:tpltype}
			AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})',
			array(
				'delete_ids' => $id_tpl,
				'tpltype' => $template_type,
				'generic' => 0,
				'current_member' => $user_info['id'],
			)
		);
		while ($row = $request->fetchAssoc())
			logAction('delete_warn_template', array('template' => $row['recipient_name']));
		$request->free();

		// Do the deletes.
		$db->query('', '
		DELETE FROM {db_prefix}log_comments
		WHERE id_comment IN ({array_int:delete_ids})
			AND comment_type = {string:tpltype}
			AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})',
			array(
				'delete_ids' => $id_tpl,
				'tpltype' => $template_type,
				'generic' => 0,
				'current_member' => $user_info['id'],
			)
		);
	}

	/**
	 * Returns all the templates of a type from the system.
	 * (used by createList() callbacks)
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page  The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param string $template_type type of template to load
	 */
	function warningTemplates($start, $items_per_page, $sort, $template_type = 'warntpl')
	{
		global $scripturl, $user_info;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
		SELECT lc.id_comment, IFNULL(mem.id_member, 0) AS id_member,
			IFNULL(mem.real_name, lc.member_name) AS creator_name, recipient_name AS template_title,
			lc.log_time, lc.body
		FROM {db_prefix}log_comments AS lc
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
		WHERE lc.comment_type = {string:tpltype}
			AND (id_recipient = {string:generic} OR id_recipient = {int:current_member})
		ORDER BY ' . $sort . '
		LIMIT ' . $start . ', ' . $items_per_page,
			array(
				'tpltype' => $template_type,
				'generic' => 0,
				'current_member' => $user_info['id'],
			)
		);
		$templates = array();
		while ($row = $request->fetchAssoc())
		{
			$templates[] = array(
				'id_comment' => $row['id_comment'],
				'creator' => $row['id_member'] ? ('<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['creator_name'] . '</a>') : $row['creator_name'],
				'time' => standardTime($row['log_time']),
				'html_time' => htmlTime($row['log_time']),
				'timestamp' => forum_time(true, $row['log_time']),
				'title' => $row['template_title'],
				'body' => $GLOBALS['elk']['text']->htmlspecialchars($row['body']),
			);
		}
		$request->free();

		return $templates;
	}

	/**
	 * Get the number of templates of a type in the system
	 *  - Loads the public and users private templates
	 *  - Loads warning templates by default
	 *  (used by createList() callbacks)
	 *
	 * @param string $template_type
	 */
	function warningTemplateCount($template_type = 'warntpl')
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_comments
		WHERE comment_type = {string:tpltype}
			AND (id_recipient = {string:generic} OR id_recipient = {int:current_member})',
			array(
				'tpltype' => $template_type,
				'generic' => 0,
				'current_member' => $user_info['id'],
			)
		);
		list ($totalWarns) = $request->fetchRow();
		$request->free();

		return $totalWarns;
	}

	/**
	 * Get all issued warnings in the system given the specified query parameters
	 *
	 * Callback for createList() in ModerationCenterController::action_viewWarningLog().
	 *
	 * @param int $start The item to start with (for pagination purposes)
	 * @param int $items_per_page  The number of items to show per page
	 * @param string $sort A string indicating how to sort the results
	 * @param string|null $query_string
	 * @param mixed[] $query_params
	 */
	function warnings($start, $items_per_page, $sort, $query_string = '', $query_params = array())
	{
		global $scripturl;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
		SELECT IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, lc.member_name) AS member_name_col,
			IFNULL(mem2.id_member, 0) AS id_recipient, IFNULL(mem2.real_name, lc.recipient_name) AS recipient_name,
			lc.log_time, lc.body, lc.id_notice, lc.counter
		FROM {db_prefix}log_comments AS lc
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
			LEFT JOIN {db_prefix}members AS mem2 ON (mem2.id_member = lc.id_recipient)
		WHERE lc.comment_type = {string:warning}' . (!empty($query_string) ? '
			AND ' . $query_string : '') . '
		ORDER BY ' . $sort . '
		LIMIT ' . $start . ', ' . $items_per_page,
			array_merge($query_params, array(
				'warning' => 'warning',
			))
		);
		$warnings = array();
		while ($row = $request->fetchAssoc())
		{
			$warnings[] = array(
				'issuer_link' => $row['id_member'] ? ('<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['member_name_col'] . '</a>') : $row['member_name_col'],
				'recipient_link' => $row['id_recipient'] ? ('<a href="' . $scripturl . '?action=profile;u=' . $row['id_recipient'] . '">' . $row['recipient_name'] . '</a>') : $row['recipient_name'],
				'time' => standardTime($row['log_time']),
				'html_time' => htmlTime($row['log_time']),
				'timestamp' => forum_time(true, $row['log_time']),
				'reason' => $row['body'],
				'counter' => $row['counter'] > 0 ? '+' . $row['counter'] : $row['counter'],
				'id_notice' => $row['id_notice'],
			);
		}
		$request->free();

		return $warnings;
	}

	/**
	 * Get the count of all current warnings.
	 *
	 * Callback for createList() in ModerationCenterController::action_viewWarningLog().
	 *
	 * @param string|null $query_string
	 * @param mixed[] $query_params
	 *
	 * @return int
	 */
	function warningCount($query_string = '', $query_params = array())
	{
		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_comments AS lc
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
			LEFT JOIN {db_prefix}members AS mem2 ON (mem2.id_member = lc.id_recipient)
		WHERE comment_type = {string:warning}' . (!empty($query_string) ? '
			AND ' . $query_string : ''),
			array_merge($query_params, array(
				'warning' => 'warning',
			))
		);
		list ($totalWarns) = $request->fetchRow();
		$request->free();

		return $totalWarns;
	}


	/**
	 * Loads a moderation template in to context for use in editing a template
	 *
	 * @param int $id_template
	 * @param string $template_type
	 */
	function modLoadTemplate($id_template, $template_type = 'warntpl')
	{
		global $user_info, $context;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
		SELECT id_member, id_recipient, recipient_name AS template_title, body
		FROM {db_prefix}log_comments
		WHERE id_comment = {int:id}
			AND comment_type = {string:tpltype}
			AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})',
			array(
				'id' => $id_template,
				'tpltype' => $template_type,
				'generic' => 0,
				'current_member' => $user_info['id'],
			)
		);
		while ($row = $request->fetchAssoc())
		{
			$context['template_data'] = array(
				'title' => $row['template_title'],
				'body' => $GLOBALS['elk']['text']->htmlspecialchars($row['body']),
				'personal' => $row['id_recipient'],
				'can_edit_personal' => $row['id_member'] == $user_info['id'],
			);
		}
		$request->free();
	}

	/**
	 * Updates an existing template or adds in a new one to the log comments table
	 *
	 * @param int $recipient_id
	 * @param string $template_title
	 * @param string $template_body
	 * @param int $id_template
	 * @param bool $edit true to update, false to insert a new row
	 * @param string $type
	 */
	function modAddUpdateTemplate($recipient_id, $template_title, $template_body, $id_template, $edit = true, $type = 'warntpl')
	{
		global $user_info;

		$db = $GLOBALS['elk']['db'];

		if ($edit)
		{
			// Simple update...
			$db->query('', '
			UPDATE {db_prefix}log_comments
			SET id_recipient = {int:personal}, recipient_name = {string:title}, body = {string:body}
			WHERE id_comment = {int:id}
				AND comment_type = {string:comment_type}
				AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})'.
				($recipient_id ? ' AND id_member = {int:current_member}' : ''),
				array(
					'personal' => $recipient_id,
					'title' => $template_title,
					'body' => $template_body,
					'id' => $id_template,
					'comment_type' => $type,
					'generic' => 0,
					'current_member' => $user_info['id'],
				)
			);
		}
		// Or inserting a new row
		else
		{
			$db->insert('',
				'{db_prefix}log_comments',
				array(
					'id_member' => 'int', 'member_name' => 'string', 'comment_type' => 'string', 'id_recipient' => 'int',
					'recipient_name' => 'string-255', 'body' => 'string-65535', 'log_time' => 'int',
				),
				array(
					$user_info['id'], $user_info['name'], $type, $recipient_id,
					$template_title, $template_body, time(),
				),
				array('id_comment')
			);
		}
	}

}