<?php

/**
 * Support functions and db interface functions for permissions
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Groups;

class Permissions
{
	/**
	 * Set the permission level for a specific profile, group, or group for a profile.
	 *
	 * @package Permissions
	 * @param string $level The level ('restrict', 'standard', etc.)
	 * @param integer|null $group The group to set the permission for
	 * @param integer|null $profile = null, int id of the permissions group or 'null' if we're setting it for a group
	 */
	function setPermissionLevel($level, $group = null, $profile = null)
	{
		global $context;

		$db = $GLOBALS['elk']['db'];

		loadIllegalPermissions();
		loadIllegalGuestPermissions();

		// Levels by group... restrict, standard, moderator, maintenance.
		$groupLevels = array(
			'board' => array('inherit' => array()),
			'group' => array('inherit' => array())
		);
		// Levels by board... standard, publish, free.
		$boardLevels = array('inherit' => array());

		// Restrictive - ie. guests.
		$groupLevels['global']['restrict'] = array(
			'search_posts',
			'calendar_view',
			'view_stats',
			'who_view',
			'profile_view_own',
			'profile_identity_own',
		);
		$groupLevels['board']['restrict'] = array(
			'poll_view',
			'post_new',
			'post_reply_own',
			'post_reply_any',
			'delete_own',
			'modify_own',
			'mark_any_notify',
			'mark_notify',
			'report_any',
			'send_topic',
		);

		// Standard - ie. members.  They can do anything Restrictive can.
		$groupLevels['global']['standard'] = array_merge($groupLevels['global']['restrict'], array(
			'view_mlist',
			'karma_edit',
			'like_posts',
			'like_posts_stats',
			'pm_read',
			'pm_send',
			'send_email_to_members',
			'profile_view_any',
			'profile_extra_own',
			'profile_set_avatar',
			'profile_remove_own',
		));
		$groupLevels['board']['standard'] = array_merge($groupLevels['board']['restrict'], array(
			'poll_vote',
			'poll_edit_own',
			'poll_post',
			'poll_add_own',
			'post_attachment',
			'lock_own',
			'remove_own',
			'view_attachments',
		));

		// Moderator - ie. moderators :P.  They can do what standard can, and more.
		$groupLevels['global']['moderator'] = array_merge($groupLevels['global']['standard'], array(
			'calendar_post',
			'calendar_edit_own',
			'access_mod_center',
			'issue_warning',
		));
		$groupLevels['board']['moderator'] = array_merge($groupLevels['board']['standard'], array(
			'make_sticky',
			'poll_edit_any',
			'delete_any',
			'modify_any',
			'lock_any',
			'remove_any',
			'move_any',
			'merge_any',
			'split_any',
			'poll_lock_any',
			'poll_remove_any',
			'poll_add_any',
			'approve_posts',
			'like_posts',
		));

		// Maintenance - wannabe admins.  They can do almost everything.
		$groupLevels['global']['maintenance'] = array_merge($groupLevels['global']['moderator'], array(
			'manage_attachments',
			'manage_smileys',
			'manage_boards',
			'moderate_forum',
			'manage_membergroups',
			'manage_bans',
			'admin_forum',
			'manage_permissions',
			'edit_news',
			'calendar_edit_any',
			'profile_identity_any',
			'profile_extra_any',
			'profile_title_any',
		));
		$groupLevels['board']['maintenance'] = array_merge($groupLevels['board']['moderator'], array());

		// Standard - nothing above the group permissions. (this SHOULD be empty.)
		$boardLevels['standard'] = array();

		// Locked - just that, you can't post here.
		$boardLevels['locked'] = array(
			'poll_view',
			'mark_notify',
			'report_any',
			'send_topic',
			'view_attachments',
		);

		// Publisher - just a little more...
		$boardLevels['publish'] = array_merge($boardLevels['locked'], array(
			'post_new',
			'post_reply_own',
			'post_reply_any',
			'delete_own',
			'modify_own',
			'mark_any_notify',
			'delete_replies',
			'modify_replies',
			'poll_vote',
			'poll_edit_own',
			'poll_post',
			'poll_add_own',
			'poll_remove_own',
			'post_attachment',
			'lock_own',
			'remove_own',
		));

		// Free for All - Scary.  Just scary.
		$boardLevels['free'] = array_merge($boardLevels['publish'], array(
			'poll_lock_any',
			'poll_edit_any',
			'poll_add_any',
			'poll_remove_any',
			'make_sticky',
			'lock_any',
			'remove_any',
			'delete_any',
			'split_any',
			'merge_any',
			'modify_any',
			'approve_posts',
		));

		// Make sure we're not granting someone too many permissions!
		foreach ($groupLevels['global'][$level] as $k => $permission) {
			if (!empty($context['illegal_permissions']) && in_array($permission, $context['illegal_permissions']))
				unset($groupLevels['global'][$level][$k]);

			if ($group == -1 && in_array($permission, $context['non_guest_permissions']))
				unset($groupLevels['global'][$level][$k]);
		}
		if ($group == -1)
			foreach ($groupLevels['board'][$level] as $k => $permission)
				if (in_array($permission, $context['non_guest_permissions']))
					unset($groupLevels['board'][$level][$k]);

		// Reset all cached permissions.
		updateSettings(array('settings_updated' => time()));

		// Setting group permissions.
		if ($profile === null && $group !== null) {
			$group = (int)$group;

			if (empty($groupLevels['global'][$level]))
				return;

			$db->query('', '
				DELETE FROM {db_prefix}permissions
				WHERE id_group = {int:current_group}
				' . (empty($context['illegal_permissions']) ? '' : ' AND permission NOT IN ({array_string:illegal_permissions})'),
				array(
					'current_group' => $group,
					'illegal_permissions' => !empty($context['illegal_permissions']) ? $context['illegal_permissions'] : array(),
				)
			);
			$db->query('', '
				DELETE FROM {db_prefix}board_permissions
				WHERE id_group = {int:current_group}
					AND id_profile = {int:default_profile}',
				array(
					'current_group' => $group,
					'default_profile' => 1,
				)
			);

			$groupInserts = array();
			foreach ($groupLevels['global'][$level] as $permission)
				$groupInserts[] = array($group, $permission);

			$db->insert('insert',
				'{db_prefix}permissions',
				array('id_group' => 'int', 'permission' => 'string'),
				$groupInserts,
				array('id_group')
			);

			$boardInserts = array();
			foreach ($groupLevels['board'][$level] as $permission)
				$boardInserts[] = array(1, $group, $permission);

			$db->insert('insert',
				'{db_prefix}board_permissions',
				array('id_profile' => 'int', 'id_group' => 'int', 'permission' => 'string'),
				$boardInserts,
				array('id_profile', 'id_group')
			);
		} // Setting profile permissions for a specific group.
		elseif ($profile !== null && $group !== null && ($profile == 1 || $profile > 4)) {
			$group = (int)$group;
			$profile = (int)$profile;

			if (!empty($groupLevels['global'][$level])) {
				$db->query('', '
					DELETE FROM {db_prefix}board_permissions
					WHERE id_group = {int:current_group}
						AND id_profile = {int:current_profile}',
					array(
						'current_group' => $group,
						'current_profile' => $profile,
					)
				);
			}

			if (!empty($groupLevels['board'][$level])) {
				$boardInserts = array();
				foreach ($groupLevels['board'][$level] as $permission)
					$boardInserts[] = array($profile, $group, $permission);

				$db->insert('insert',
					'{db_prefix}board_permissions',
					array('id_profile' => 'int', 'id_group' => 'int', 'permission' => 'string'),
					$boardInserts,
					array('id_profile', 'id_group')
				);
			}
		} // Setting profile permissions for all groups.
		elseif ($profile !== null && $group === null && ($profile == 1 || $profile > 4)) {
			$profile = (int)$profile;

			$db->query('', '
				DELETE FROM {db_prefix}board_permissions
				WHERE id_profile = {int:current_profile}',
				array(
					'current_profile' => $profile,
				)
			);

			if (empty($boardLevels[$level]))
				return;

			// Get all the groups...
			$query = $db->query('', '
				SELECT id_group
				FROM {db_prefix}membergroups
				WHERE id_group > {int:moderator_group}
				ORDER BY min_posts, CASE WHEN id_group < {int:newbie_group} THEN id_group ELSE 4 END, group_name',
				array(
					'moderator_group' => 3,
					'newbie_group' => 4,
				)
			);
			while ($row = $db->fetch_row($query)) {
				$group = $row[0];

				$boardInserts = array();
				foreach ($boardLevels[$level] as $permission)
					$boardInserts[] = array($profile, $group, $permission);

				$db->insert('insert',
					'{db_prefix}board_permissions',
					array('id_profile' => 'int', 'id_group' => 'int', 'permission' => 'string'),
					$boardInserts,
					array('id_profile', 'id_group')
				);
			}
			$query->free();

			// Add permissions for ungrouped members.
			$boardInserts = array();
			foreach ($boardLevels[$level] as $permission)
				$boardInserts[] = array($profile, 0, $permission);

			$db->insert('insert',
				'{db_prefix}board_permissions',
				array('id_profile' => 'int', 'id_group' => 'int', 'permission' => 'string'),
				$boardInserts,
				array('id_profile', 'id_group')
			);
		} // $profile and $group are both null!
		else
			$GLOBALS['elk']['errors']->fatal_lang_error('no_access', false);
	}

	/**
	 * Load permissions profiles.
	 *
	 * @package Permissions
	 */
	function loadPermissionProfiles()
	{
		global $context, $txt;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT id_profile, profile_name
			FROM {db_prefix}permission_profiles
			ORDER BY id_profile',
			array()
		);
		$context['profiles'] = array();
		while ($row = $request->fetchAssoc()) {
			// Format the label nicely.
			if (isset($txt['permissions_profile_' . $row['profile_name']]))
				$name = $txt['permissions_profile_' . $row['profile_name']];
			else
				$name = $row['profile_name'];

			$context['profiles'][$row['id_profile']] = array(
				'id' => $row['id_profile'],
				'name' => $name,
				'can_modify' => $row['id_profile'] == 1 || $row['id_profile'] > 4,
				'unformatted_name' => $row['profile_name'],
			);
		}
		$request->free();
	}

	/**
	 * Load permissions into $context['permissions'].
	 *
	 * @package Permissions
	 */
	function loadAllPermissions()
	{
		global $context, $txt, $modSettings;

		// List of all the groups
		// Note to Mod authors - you don't need to stick your permission group here if you don't mind having it as the last group of the page.
		$permissionGroups = array(
			'membergroup' => array(
				'general',
				'pm',
				'calendar',
				'maintenance',
				'member_admin',
				'profile',
			),
			'board' => array(
				'general_board',
				'topic',
				'post',
				'poll',
				'notification',
				'attachment',
			),
		);

		/*   The format of this list is as follows:
			'membergroup' => array(
				'permissions_inside' => array(has_multiple_options, view_group),
			),
			'board' => array(
				'permissions_inside' => array(has_multiple_options, view_group),
			);
		*/
		$permissionList = [
			'membergroup' => [
				'view_stats' => [false, 'general'],
				'view_mlist' => [false, 'general'],
				'who_view' => [false, 'general'],
				'search_posts' => [false, 'general'],
				'karma_edit' => [false, 'general'],
				'like_posts' => [false, 'general'],
				'like_posts_stats' => [false, 'general'],
				'disable_censor' => [false, 'general'],
				'pm_read' => [false, 'pm'],
				'pm_send' => [false, 'pm'],
				'send_email_to_members' => [false, 'pm'],
				'calendar_view' => [false, 'calendar'],
				'calendar_post' => [false, 'calendar'],
				'calendar_edit' => [true, 'calendar'],
				'admin_forum' => [false, 'maintenance'],
				'manage_boards' => [false, 'maintenance'],
				'manage_attachments' => [false, 'maintenance'],
				'manage_smileys' => [false, 'maintenance'],
				'edit_news' => [false, 'maintenance'],
				'access_mod_center' => [false, 'maintenance'],
				'moderate_forum' => [false, 'member_admin'],
				'manage_membergroups' => [false, 'member_admin'],
				'manage_permissions' => [false, 'member_admin'],
				'manage_bans' => [false, 'member_admin'],
				'send_mail' => [false, 'member_admin'],
				'issue_warning' => [false, 'member_admin'],
				'profile_view' => [true, 'profile'],
				'profile_identity' => [true, 'profile'],
				'profile_extra' => [true, 'profile'],
				'profile_title' => [true, 'profile'],
				'profile_remove' => [true, 'profile'],
				'profile_set_avatar' => [false, 'profile'],
				'approve_emails' => [false, 'member_admin'],
			],
			'board' => [
				'moderate_board' => [false, 'general_board'],
				'approve_posts' => [false, 'general_board'],
				'post_new' => [false, 'topic'],
				'post_unapproved_topics' => [false, 'topic'],
				'post_unapproved_replies' => [true, 'topic'],
				'post_reply' => [true, 'topic'],
				'merge_any' => [false, 'topic'],
				'split_any' => [false, 'topic'],
				'send_topic' => [false, 'topic'],
				'make_sticky' => [false, 'topic'],
				'move' => [true, 'topic'],
				'lock' => [true, 'topic'],
				'remove' => [true, 'topic'],
				'modify_replies' => [false, 'topic'],
				'delete_replies' => [false, 'topic'],
				'announce_topic' => [false, 'topic'],
				'delete' => [true, 'post'],
				'modify' => [true, 'post'],
				'report_any' => [false, 'post'],
				'poll_view' => [false, 'poll'],
				'poll_vote' => [false, 'poll'],
				'poll_post' => [false, 'poll'],
				'poll_add' => [true, 'poll'],
				'poll_edit' => [true, 'poll'],
				'poll_lock' => [true, 'poll'],
				'poll_remove' => [true, 'poll'],
				'mark_any_notify' => [false, 'notification'],
				'mark_notify' => [false, 'notification'],
				'view_attachments' => [false, 'attachment'],
				'post_unapproved_attachments' => [false, 'attachment'],
				'post_attachment' => [false, 'attachment'],
				'postby_email' => [false, 'topic'],
				'like_posts' => [false, 'topic'],
			],
		];

		// All permission groups that will be shown in the left column.
		$leftPermissionGroups = array(
			'general',
			'calendar',
			'maintenance',
			'member_admin',
			'topic',
			'post',
		);

		// we'll need to init illegal permissions.
		require_once(SUBSDIR . '/Permission.subs.php');

		// We need to know what permissions we can't give to guests.
		loadIllegalGuestPermissions();

		// Some permissions are hidden if features are off.
		$hiddenPermissions = array();
		$relabelPermissions = array(); // Permissions to apply a different label to.

		if (!in_array('cd', $context['admin_features'])) {
			$hiddenPermissions[] = 'calendar_view';
			$hiddenPermissions[] = 'calendar_post';
			$hiddenPermissions[] = 'calendar_edit';
		}
		if (!in_array('w', $context['admin_features']))
			$hiddenPermissions[] = 'issue_warning';
		if (!in_array('k', $context['admin_features']))
			$hiddenPermissions[] = 'karma_edit';
		if (!in_array('l', $context['admin_features']))
			$hiddenPermissions[] = 'like_posts';
		if (!in_array('pe', $context['admin_features'])) {
			$hiddenPermissions[] = 'approve_emails';
			$hiddenPermissions[] = 'postby_email';
		}

		// Post moderation?
		if (!$modSettings['postmod_active']) {
			$hiddenPermissions[] = 'approve_posts';
			$hiddenPermissions[] = 'post_unapproved_topics';
			$hiddenPermissions[] = 'post_unapproved_replies';
			$hiddenPermissions[] = 'post_unapproved_attachments';
		} // If we show them on classic view we change the name.
		else {
			// Relabel the topics permissions
			$relabelPermissions['post_new'] = 'auto_approve_topics';

			// Relabel the reply permissions
			$relabelPermissions['post_reply'] = 'auto_approve_replies';

			// Relabel the attachment permissions
			$relabelPermissions['post_attachment'] = 'auto_approve_attachments';
		}

		// Are attachments enabled?
		if (empty($modSettings['attachmentEnable'])) {
			$hiddenPermissions[] = 'manage_attachments';
			$hiddenPermissions[] = 'view_attachments';
			$hiddenPermissions[] = 'post_unapproved_attachments';
			$hiddenPermissions[] = 'post_attachment';
		}

		// Provide a practical way to modify permissions.
		$GLOBALS['elk']['hooks']->hook('load_permissions', array(&$permissionGroups, &$permissionList, &$leftPermissionGroups, &$hiddenPermissions, &$relabelPermissions));

		$context['permissions'] = array();
		$context['hidden_permissions'] = array();
		foreach ($permissionList as $permissionType => $currentPermissionList) {
			$context['permissions'][$permissionType] = array(
				'id' => $permissionType,
				'columns' => array()
			);

			foreach ($currentPermissionList as $permission => $permissionArray) {
				// If this is a guest permission we don't do it if it's the guest group.
				if (isset($context['group']['id']) && $context['group']['id'] == -1 && in_array($permission, $context['non_guest_permissions'])) {
					continue;
				}

				// What groups will this permission be in?
				$own_group = $permissionArray[1];

				// First, Do these groups actually exist - if not add them.
				if (!isset($permissionGroups[$permissionType][$own_group])) {
					$permissionGroups[$permissionType][$own_group] = true;
				}

				// What column should this be located into?
				$position = !in_array($own_group, $leftPermissionGroups) ? 1 : 0;

				// If the groups have not yet been created be sure to create them.
				$bothGroups = array();

				// Guests can have only any, registered users both
				if (!isset($context['group']['id']) || !($context['group']['id'] == -1)) {
					$bothGroups['own'] = $own_group;
				} else {
					$bothGroups['any'] = $own_group;
				}

				foreach ($bothGroups as $group) {
					if (!isset($context['permissions'][$permissionType]['columns'][$position][$group]['type'])) {
						$context['permissions'][$permissionType]['columns'][$position][$group] = array(
							'type' => $permissionType,
							'id' => $group,
							'name' => $txt['permissiongroup_' . $group],
							'icon' => isset($txt['permissionicon_' . $group]) ? $txt['permissionicon_' . $group] : $txt['permissionicon'],
							'help' => isset($txt['permissionhelp_' . $group]) ? $txt['permissionhelp_' . $group] : '',
							'hidden' => false,
							'permissions' => array()
						);
					}
				}

				// This is where we set up the permission.
				$context['permissions'][$permissionType]['columns'][$position][$own_group]['permissions'][$permission] = array(
					'id' => $permission,
					'name' => !isset($relabelPermissions[$permission]) ? $txt['permissionname_' . $permission] : $txt[$relabelPermissions[$permission]],
					'show_help' => isset($txt['permissionhelp_' . $permission]),
					'note' => isset($txt['permissionnote_' . $permission]) ? $txt['permissionnote_' . $permission] : '',
					'has_own_any' => $permissionArray[0],
					'own' => array(
						'id' => $permission . '_own',
						'name' => $permissionArray[0] ? $txt['permissionname_' . $permission . '_own'] : ''
					),
					'any' => array(
						'id' => $permission . '_any',
						'name' => $permissionArray[0] ? $txt['permissionname_' . $permission . '_any'] : ''
					),
					'hidden' => in_array($permission, $hiddenPermissions),
				);

				if (in_array($permission, $hiddenPermissions)) {
					if ($permissionArray[0]) {
						$context['hidden_permissions'][] = $permission . '_own';
						$context['hidden_permissions'][] = $permission . '_any';
					} else {
						$context['hidden_permissions'][] = $permission;
					}
				}
			}
			ksort($context['permissions'][$permissionType]['columns']);

			// Check we don't leave any empty groups - and mark hidden ones as such.
			foreach ($context['permissions'][$permissionType]['columns'] as $column => $groups) {
				foreach ($groups as $id => $group) {
					if (empty($group['permissions'])) {
						unset($context['permissions'][$permissionType]['columns'][$column][$id]);
					} else {
						$foundNonHidden = false;
						foreach ($group['permissions'] as $permission) {
							if (empty($permission['hidden'])) {
								$foundNonHidden = true;
							}
						}

						if (!$foundNonHidden) {
							$context['permissions'][$permissionType]['columns'][$column][$id]['hidden'] = true;
						}
					}
				}
			}
		}
	}

	/**
	 * Counts membergroup permissions.
	 *
	 * @package Permissions
	 * @param int[] $groups the group ids to return permission counts
	 * @param string[]|null $hidden_permissions array of permission names to skip in the count totals
	 *
	 * @return int[] [id_group][num_permissions][denied] = count, [id_group][num_permissions][allowed] = count
	 */
	function countPermissions($groups, $hidden_permissions = null)
	{
		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT id_group, COUNT(*) AS num_permissions, add_deny
			FROM {db_prefix}permissions'
			. (isset($hidden_permissions) ? '' : 'WHERE permission NOT IN ({array_string:hidden_permissions})') . '
			GROUP BY id_group, add_deny',
			array(
				'hidden_permissions' => !isset($hidden_permissions) ? $hidden_permissions : array(),
			)
		);
		while ($row = $request->fetchAssoc()) {
			if (isset($groups[(int)$row['id_group']]) && (!empty($row['add_deny']) || $row['id_group'] != -1)) {
				$groups[$row['id_group']]['num_permissions'][empty($row['add_deny']) ? 'denied' : 'allowed'] = $row['num_permissions'];
			}
		}
		$request->free();

		return $groups;
	}

	/**
	 * Counts board permissions.
	 *
	 * @package Permissions
	 * @param int[] $groups
	 * @param string[]|null $hidden_permissions
	 * @param integer|null $profile_id
	 *
	 * @return int[]
	 */
	function countBoardPermissions($groups, $hidden_permissions = null, $profile_id = null)
	{
		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT id_profile, id_group, COUNT(*) AS num_permissions, add_deny
			FROM {db_prefix}board_permissions
			WHERE 1 = 1'
			. (isset($profile_id) ? ' AND id_profile = {int:current_profile}' : '')
			. (empty($hidden_permissions) ? '' : ' AND permission NOT IN ({array_string:hidden_permissions})') . '
			GROUP BY ' . (isset($profile_id) ? 'id_profile, ' : '') . 'id_group, add_deny',
			array(
				'hidden_permissions' => !empty($hidden_permissions) ? $hidden_permissions : array(),
				'current_profile' => $profile_id,
			)
		);
		while ($row = $request->fetchAssoc())
			if (isset($groups[(int)$row['id_group']]) && (!empty($row['add_deny']) || $row['id_group'] != -1))
				$groups[$row['id_group']]['num_permissions'][empty($row['add_deny']) ? 'denied' : 'allowed'] += $row['num_permissions'];
		$request->free();

		return $groups;
	}

	/**
	 * Used to assign a permission profile to a board.
	 *
	 * @package Permissions
	 * @param int $profile
	 * @param int $board
	 */
	function assignPermissionProfileToBoard($profile, $board)
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			UPDATE {db_prefix}boards
			SET id_profile = {int:current_profile}
			WHERE id_board IN ({array_int:board_list})',
			array(
				'board_list' => $board,
				'current_profile' => $profile,
			)
		);
	}

	/**
	 * Copy a set of permissions from one group to another..
	 *
	 * @package Permissions
	 * @param int $copy_from
	 * @param int[] $groups
	 * @param string[] $illegal_permissions
	 * @param string[] $non_guest_permissions
	 * @todo another function with the same name in Membergroups.subs.php
	 */
	function copyPermission($copy_from, $groups, $illegal_permissions, $non_guest_permissions = array())
	{
		$db = $GLOBALS['elk']['db'];

		// Retrieve current permissions of group.
		$request = $db->query('', '
			SELECT permission, add_deny
			FROM {db_prefix}permissions
			WHERE id_group = {int:copy_from}',
			array(
				'copy_from' => $copy_from,
			)
		);
		$target_perm = array();
		while ($row = $request->fetchAssoc())
			$target_perm[$row['permission']] = $row['add_deny'];
		$request->free();

		$inserts = array();
		foreach ($groups as $group_id)
			foreach ($target_perm as $perm => $add_deny) {
				// No dodgy permissions please!
				if (!empty($illegal_permissions) && in_array($perm, $illegal_permissions))
					continue;
				if ($group_id == -1 && in_array($perm, $non_guest_permissions))
					continue;

				if ($group_id != 1 && $group_id != 3)
					$inserts[] = array($perm, $group_id, $add_deny);
			}

		// Delete the previous permissions...
		$db->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE id_group IN ({array_int:group_list})
				' . (empty($illegal_permissions) ? '' : ' AND permission NOT IN ({array_string:illegal_permissions})'),
			array(
				'group_list' => $groups,
				'illegal_permissions' => !empty($illegal_permissions) ? $illegal_permissions : array(),
			)
		);

		if (!empty($inserts)) {
			// ..and insert the new ones.
			$db->insert('',
				'{db_prefix}permissions',
				array(
					'permission' => 'string', 'id_group' => 'int', 'add_deny' => 'int',
				),
				$inserts,
				array('permission', 'id_group')
			);
		}
	}

	/**
	 * Copy a set of board permissions from one group to another..
	 *
	 * @package Permissions
	 * @param int $copy_from
	 * @param int[] $groups The target groups
	 * @param int $profile_id
	 * @param string[] $non_guest_permissions
	 */
	function copyBoardPermission($copy_from, $groups, $profile_id, $non_guest_permissions)
	{
		$db = $GLOBALS['elk']['db'];

		// Now do the same for the board permissions.
		$request = $db->query('', '
			SELECT permission, add_deny
			FROM {db_prefix}board_permissions
			WHERE id_group = {int:copy_from}
				AND id_profile = {int:current_profile}',
			array(
				'copy_from' => $copy_from,
				'current_profile' => $profile_id,
			)
		);
		$target_perm = array();
		while ($row = $request->fetchAssoc())
			$target_perm[$row['permission']] = $row['add_deny'];
		$request->free();

		$inserts = array();
		foreach ($groups as $group_id) {
			foreach ($target_perm as $perm => $add_deny) {
				// Are these for guests?
				if ($group_id == -1 && in_array($perm, $non_guest_permissions))
					continue;

				$inserts[] = array($perm, $group_id, $profile_id, $add_deny);
			}
		}

		// Delete the previous global board permissions...
		$db->query('', '
			DELETE FROM {db_prefix}board_permissions
			WHERE id_group IN ({array_int:current_group_list})
				AND id_profile = {int:current_profile}',
			array(
				'current_group_list' => $groups,
				'current_profile' => $profile_id,
			)
		);

		// And insert the copied permissions.
		if (!empty($inserts)) {
			// ..and insert the new ones.
			$db->insert('',
				'{db_prefix}board_permissions',
				array('permission' => 'string', 'id_group' => 'int', 'id_profile' => 'int', 'add_deny' => 'int'),
				$inserts,
				array('permission', 'id_group', 'id_profile')
			);
		}
	}

	/**
	 * Deletes membergroup permissions.
	 *
	 * @package Permissions
	 * @param int[] $groups
	 * @param string $permission
	 * @param string[] $illegal_permissions
	 */
	function deletePermission($groups, $permission, $illegal_permissions)
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE id_group IN ({array_int:current_group_list})
				AND permission = {string:current_permission}
				' . (empty($illegal_permissions) ? '' : ' AND permission NOT IN ({array_string:illegal_permissions})'),
			array(
				'current_group_list' => $groups,
				'current_permission' => $permission,
				'illegal_permissions' => !empty($illegal_permissions) ? $illegal_permissions : array(),
			)
		);
	}

	/**
	 * Delete board permissions.
	 *
	 * @package Permissions
	 * @param int[] $group
	 * @param int $profile_id
	 * @param string $permission
	 */
	function deleteBoardPermission($group, $profile_id, $permission)
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			DELETE FROM {db_prefix}board_permissions
			WHERE id_group IN ({array_int:current_group_list})
				AND id_profile = {int:current_profile}
				AND permission = {string:current_permission}',
			array(
				'current_group_list' => $group,
				'current_profile' => $profile_id,
				'current_permission' => $permission,
			)
		);
	}

	/**
	 * Replaces existing membergroup permissions with the given ones.
	 *
	 * @package Permissions
	 * @param mixed[] $permChange associative array permission, id_group, add_deny
	 */
	function replacePermission($permChange)
	{
		$db = $GLOBALS['elk']['db'];

		$db->insert('replace',
			'{db_prefix}permissions',
			array('permission' => 'string', 'id_group' => 'int', 'add_deny' => 'int'),
			$permChange,
			array('permission', 'id_group')
		);
	}

	/**
	 * Replaces existing board permissions with the given ones.
	 *
	 * @package Permissions
	 * @param mixed[] $permChange associative array of 'permission', 'id_group', 'add_deny', 'id_profile'
	 */
	function replaceBoardPermission($permChange)
	{
		$GLOBALS['elk']['db']->insert('replace',
			'{db_prefix}board_permissions',
			array('permission' => 'string', 'id_group' => 'int', 'add_deny' => 'int', 'id_profile' => 'int'),
			$permChange,
			array('permission', 'id_group', 'id_profile')
		);
	}

	/**
	 * Removes the moderator's permissions.
	 *
	 * @package Permissions
	 */
	function removeModeratorPermissions()
	{
		$GLOBALS['elk']['db']->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE id_group = {int:moderator_group}',
			array(
				'moderator_group' => 3,
			)
		);
	}

	/**
	 * Fetches membergroup permissions from the given group.
	 *
	 * @package Permissions
	 * @param int $id_group
	 * @return array
	 */
	function fetchPermissions($id_group)
	{
		$db = $GLOBALS['elk']['db'];

		$permissions = array(
			'allowed' => array(),
			'denied' => array(),
		);

		$result = $db->query('', '
			SELECT permission, add_deny
			FROM {db_prefix}permissions
			WHERE id_group = {int:current_group}',
			array(
				'current_group' => $id_group,
			)
		);
		while ($row = $result->fetchAssoc())
			$permissions[empty($row['add_deny']) ? 'denied' : 'allowed'][] = $row['permission'];
		$result->free();

		return $permissions;
	}

	/**
	 * Fetches board permissions from the given group.
	 *
	 * @package Permissions
	 * @param int $id_group
	 * @param string $permission_type
	 * @param int $profile_id
	 */
	function fetchBoardPermissions($id_group, $permission_type, $profile_id)
	{
		$db = $GLOBALS['elk']['db'];

		$permissions = array(
			'allowed' => array(),
			'denied' => array(),
		);

		$result = $db->query('', '
			SELECT permission, add_deny
			FROM {db_prefix}board_permissions
			WHERE id_group = {int:current_group}
				AND id_profile = {int:current_profile}',
			array(
				'current_group' => $id_group,
				'current_profile' => $permission_type == 'membergroup' ? 1 : $profile_id,
			)
		);
		while ($row = $result->fetchAssoc())
			$permissions[empty($row['add_deny']) ? 'denied' : 'allowed'][] = $row['permission'];
		$result->free();

		return $permissions;
	}

	/**
	 * Deletes invalid permissions for the given group.
	 *
	 * @package Permissions
	 * @param int $id_group
	 * @param string[] $illegal_permissions
	 */
	function deleteInvalidPermissions($id_group, $illegal_permissions)
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE id_group = {int:current_group}
			' . (empty($illegal_permissions) ? '' : ' AND permission NOT IN ({array_string:illegal_permissions})'),
			array(
				'current_group' => $id_group,
				'illegal_permissions' => !empty($illegal_permissions) ? $illegal_permissions : array(),
			)
		);
	}

	/**
	 * Deletes a membergroup's board permissions from a specified permission profile.
	 *
	 * @package Permissions
	 * @param int $id_group
	 * @param integer $id_profile
	 */
	function deleteAllBoardPermissions($id_group, $id_profile)
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			DELETE FROM {db_prefix}board_permissions
			WHERE id_group = {int:current_group}
			AND id_profile = {int:current_profile}',
			array(
				'current_group' => $id_group,
				'current_profile' => $id_profile,
			)
		);
	}

	/**
	 * Deny permissions disabled? We need to clean the permission tables.
	 *
	 * @package Permissions
	 */
	function clearDenyPermissions()
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE add_deny = {int:denied}',
			array(
				'denied' => 0,
			)
		);
		$db->query('', '
			DELETE FROM {db_prefix}board_permissions
			WHERE add_deny = {int:denied}',
			array(
				'denied' => 0,
			)
		);
	}

	/**
	 * Permissions for post based groups disabled? We need to clean the permission
	 * tables, too.
	 *
	 * @package Permissions
	 */
	function clearPostgroupPermissions()
	{
		$db = $GLOBALS['elk']['db'];

		$post_groups = $db->fetchQueryCallback('
			SELECT id_group
			FROM {db_prefix}membergroups
			WHERE min_posts != {int:min_posts}',
			array(
				'min_posts' => -1,
			),
			function ($row) {
				return $row['id_group'];
			}
		);

		// Remove'em.
		$db->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE id_group IN ({array_int:post_group_list})',
			array(
				'post_group_list' => $post_groups,
			)
		);
		$db->query('', '
			DELETE FROM {db_prefix}board_permissions
			WHERE id_group IN ({array_int:post_group_list})',
			array(
				'post_group_list' => $post_groups,
			)
		);
		$db->query('', '
			UPDATE {db_prefix}membergroups
			SET id_parent = {int:not_inherited}
			WHERE id_parent IN ({array_int:post_group_list})',
			array(
				'post_group_list' => $post_groups,
				'not_inherited' => -2,
			)
		);
	}

	/**
	 * Copies a permission profile.
	 *
	 * @package Permissions
	 * @param string $profile_name
	 * @param int $copy_from
	 */
	function copyPermissionProfile($profile_name, $copy_from)
	{
		$db = $GLOBALS['elk']['db'];

		$profile_name = $GLOBALS['elk']['text']->htmlspecialchars($profile_name);
		// Insert the profile itself.
		$result = $db->insert('',
			'{db_prefix}permission_profiles',
			array(
				'profile_name' => 'string',
			),
			array(
				$profile_name,
			),
			array('id_profile')
		);
		$profile_id = $result->insertId('{db_prefix}permission_profiles', 'id_profile');

		// Load the permissions from the one it's being copied from.
		$inserts = $db->fetchQueryCallback('
			SELECT id_group, permission, add_deny
			FROM {db_prefix}board_permissions
			WHERE id_profile = {int:copy_from}',
			array(
				'copy_from' => $copy_from,
			),
			function ($row) use ($profile_id) {
				return array($profile_id, $row['id_group'], $row['permission'], $row['add_deny']);
			}
		);

		if (!empty($inserts)) {
			$db->insert('insert',
				'{db_prefix}board_permissions',
				array('id_profile' => 'int', 'id_group' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
				$inserts,
				array('id_profile', 'id_group', 'permission')
			);
		}
	}

	/**
	 * Rename a permission profile.
	 *
	 * @package Permissions
	 * @param int $id_profile
	 * @param string $name
	 */
	function renamePermissionProfile($id_profile, $name)
	{
		$db = $GLOBALS['elk']['db'];

		$name = $GLOBALS['elk']['text']->htmlspecialchars($name);

		$db->query('', '
			UPDATE {db_prefix}permission_profiles
			SET profile_name = {string:profile_name}
			WHERE id_profile = {int:current_profile}',
			array(
				'current_profile' => $id_profile,
				'profile_name' => $name,
			)
		);
	}

	/**
	 * Delete a permission profile
	 *
	 * @package Permissions
	 * @param int[] $profiles
	 */
	function deletePermissionProfiles($profiles)
	{
		$db = $GLOBALS['elk']['db'];

		// Verify it's not in use...
		$request = $db->query('', '
			SELECT id_board
			FROM {db_prefix}boards
			WHERE id_profile IN ({array_int:profile_list})
			LIMIT 1',
			array(
				'profile_list' => $profiles,
			)
		);
		if ($request->numRows() != 0)
			$GLOBALS['elk']['errors']->fatal_lang_error('no_access', false);
		$request->free();

		// Oh well, delete.
		$db->query('', '
			DELETE FROM {db_prefix}permission_profiles
			WHERE id_profile IN ({array_int:profile_list})',
			array(
				'profile_list' => $profiles,
			)
		);
	}

	/**
	 * Checks, if a permission profile is in use.
	 *
	 * @package Permissions
	 * @param int[] $profiles
	 *
	 * @return int[]
	 */
	function permProfilesInUse($profiles)
	{
		global $txt;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT id_profile, COUNT(id_board) AS board_count
			FROM {db_prefix}boards
			GROUP BY id_profile',
			array()
		);
		while ($row = $request->fetchAssoc())
			if (isset($profiles[$row['id_profile']])) {
				$profiles[$row['id_profile']]['in_use'] = true;
				$profiles[$row['id_profile']]['boards'] = $row['board_count'];
				$profiles[$row['id_profile']]['boards_text'] = $row['board_count'] > 1 ? sprintf($txt['permissions_profile_used_by_many'], $row['board_count']) : $txt['permissions_profile_used_by_' . ($row['board_count'] ? 'one' : 'none')];
			}
		$request->free();

		return $profiles;
	}

	/**
	 * Delete a board permission.
	 *
	 * @package Permissions
	 * @param mixed[] $groups array where the keys are the group id's
	 * @param int[] $profile
	 * @param string[] $permissions
	 */
	function deleteBoardPermissions($groups, $profile, $permissions)
	{
		$db = $GLOBALS['elk']['db'];

		// Start by deleting all the permissions relevant.
		$db->query('', '
			DELETE FROM {db_prefix}board_permissions
			WHERE id_profile = {int:current_profile}
				AND permission IN ({array_string:permissions})
				AND id_group IN ({array_int:profile_group_list})',
			array(
				'profile_group_list' => array_keys($groups),
				'current_profile' => $profile,
				'permissions' => $permissions,
			)
		);
	}

	/**
	 * Adds a new board permission to the board_permissions table.
	 *
	 * @package Permissions
	 * @param mixed[] $new_permissions
	 */
	function insertBoardPermission($new_permissions)
	{
		$db = $GLOBALS['elk']['db'];

		$db->insert('',
			'{db_prefix}board_permissions',
			array('id_profile' => 'int', 'id_group' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
			$new_permissions,
			array('id_profile', 'id_group', 'permission')
		);
	}

	/**
	 * Lists the board permissions.
	 *
	 * @package Permissions
	 * @param int[] $group
	 * @param int $profile
	 * @param string[] $permissions
	 * @return array
	 */
	function getPermission($group, $profile, $permissions)
	{
		$db = $GLOBALS['elk']['db'];

		$groups = array();

		$request = $db->query('', '
			SELECT id_group, permission, add_deny
			FROM {db_prefix}board_permissions
			WHERE id_profile = {int:current_profile}
				AND permission IN ({array_string:permissions})
				AND id_group IN ({array_int:profile_group_list})',
			array(
				'profile_group_list' => $group,
				'current_profile' => $profile,
				'permissions' => $permissions,
			)
		);
		while ($row = $request->fetchAssoc())
			$groups[$row['id_group']][$row['add_deny'] ? 'add' : 'deny'][] = $row['permission'];

		$request->free();

		return $groups;
	}


	/**
	 * Load a few illegal permissions in context.
	 */
	function loadIllegalPermissions()
	{
		global $context;

		$context['illegal_permissions'] = array();
		if (!allowedTo('admin_forum'))
			$context['illegal_permissions'][] = 'admin_forum';
		if (!allowedTo('manage_membergroups'))
			$context['illegal_permissions'][] = 'manage_membergroups';
		if (!allowedTo('manage_permissions'))
			$context['illegal_permissions'][] = 'manage_permissions';

		$GLOBALS['elk']['hooks']->hook('load_illegal_permissions');
	}

	/**
	 * Loads those permissions guests cannot have, into context.
	 */
	function loadIllegalGuestPermissions()
	{
		global $context;

		$context['non_guest_permissions'] = array(
			'delete_replies',
			'karma_edit',
			'poll_add_own',
			'pm_read',
			'pm_send',
			'profile_identity',
			'profile_extra',
			'profile_title',
			'profile_remove',
			'profile_set_avatar',
			'profile_view_own',
			'mark_any_notify',
			'mark_notify',
			'admin_forum',
			'manage_boards',
			'manage_attachments',
			'manage_smileys',
			'edit_news',
			'access_mod_center',
			'moderate_forum',
			'issue_warning',
			'manage_membergroups',
			'manage_permissions',
			'manage_bans',
			'move_own',
			'modify_replies',
			'send_mail',
			'approve_posts',
			'postby_email',
			'approve_emails',
			'like_posts',
		);

		$GLOBALS['elk']['hooks']->hook('load_illegal_guest_permissions');
	}

	/**
	 * This function updates the permissions of any groups based on the given groups.
	 *
	 * @param mixed[]|int $parents (array or int) group or groups whose children are to be updated
	 * @param int|null $profile = null an int or null for the customized profile, if any
	 * @return null|false
	 */
	function updateChildPermissions($parents, $profile = null)
	{
		$db = $GLOBALS['elk']['db'];

		// All the parent groups to sort out.
		if (!is_array($parents))
			$parents = array($parents);

		// Find all the children of this group.
		$request = $db->query('', '
		SELECT id_parent, id_group
		FROM {db_prefix}membergroups
		WHERE id_parent != {int:not_inherited}
			' . (empty($parents) ? '' : 'AND id_parent IN ({array_int:parent_list})'),
			array(
				'parent_list' => $parents,
				'not_inherited' => -2,
			)
		);
		$children = array();
		$parents = array();
		$child_groups = array();
		while ($row = $request->fetchAssoc())
		{
			$children[$row['id_parent']][] = $row['id_group'];
			$child_groups[] = $row['id_group'];
			$parents[] = $row['id_parent'];
		}
		$request->free();

		$parents = array_unique($parents);

		// Not a sausage, or a child?
		if (empty($children))
			return false;

		// First off, are we doing general permissions?
		if ($profile < 1 || $profile === null)
		{
			// Fetch all the parent permissions.
			$request = $db->query('', '
			SELECT id_group, permission, add_deny
			FROM {db_prefix}permissions
			WHERE id_group IN ({array_int:parent_list})',
				array(
					'parent_list' => $parents,
				)
			);
			$permissions = array();
			while ($row = $request->fetchAssoc())
				foreach ($children[$row['id_group']] as $child)
					$permissions[] = array($child, $row['permission'], $row['add_deny']);
			$request->free();

			$db->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE id_group IN ({array_int:child_groups})',
				array(
					'child_groups' => $child_groups,
				)
			);

			// Finally insert.
			if (!empty($permissions))
			{
				$db->insert('insert',
					'{db_prefix}permissions',
					array('id_group' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
					$permissions,
					array('id_group', 'permission')
				);
			}
		}

		// Then, what about board profiles?
		if ($profile != -1)
		{
			$profileQuery = $profile === null ? '' : ' AND id_profile = {int:current_profile}';

			// Again, get all the parent permissions.
			$request = $db->query('', '
			SELECT id_profile, id_group, permission, add_deny
			FROM {db_prefix}board_permissions
			WHERE id_group IN ({array_int:parent_groups})
				' . $profileQuery,
				array(
					'parent_groups' => $parents,
					'current_profile' => $profile !== null && $profile ? $profile : 1,
				)
			);
			$permissions = array();
			while ($row = $request->fetchAssoc())
				foreach ($children[$row['id_group']] as $child)
					$permissions[] = array($child, $row['id_profile'], $row['permission'], $row['add_deny']);
			$request->free();

			$db->query('', '
			DELETE FROM {db_prefix}board_permissions
			WHERE id_group IN ({array_int:child_groups})
				' . $profileQuery,
				array(
					'child_groups' => $child_groups,
					'current_profile' => $profile !== null && $profile ? $profile : 1,
				)
			);

			// Do the insert.
			if (!empty($permissions))
			{
				$db->insert('insert',
					'{db_prefix}board_permissions',
					array('id_group' => 'int', 'id_profile' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
					$permissions,
					array('id_group', 'id_profile', 'permission')
				);
			}
		}
	}

	/**
	 * Show a collapsible box to set a specific permission.
	 * The function is called by templates to show a list of permissions settings.
	 * Calls the template function template_inline_permissions().
	 *
	 * @param string $permission
	 */
	function theme_inline_permissions($permission)
	{
		global $context;

		$context['current_permission'] = $permission;
		$context['member_groups'] = $context[$permission];

		template_inline_permissions();
	}
}