<?php

namespace Elkarte\Elkarte\Security;

/**
 * Class to initialize inline permissions sub-form and save its settings
 */
class InlinePermissionsForm
{
	/**
	 * Save the permissions of a form containing inline permissions.
	 *
	 * @param string[] $permissions
	 */
	public static function save_inline_permissions($permissions)
	{
		global $context;

		$db = $GLOBALS['elk']['db'];

		// No permissions? Not a great deal to do here.
		if (!allowedTo('manage_permissions'))
			return;

		// Almighty session check, verify our ways.
		\Session::getInstance()->check();
		validateToken('Admin-mp');

		// Make sure they can't do certain things,
		// unless they have the right permissions.
		loadIllegalPermissions();

		$insertRows = array();
		foreach ($permissions as $permission)
		{
			if (!isset($_POST[$permission]))
				continue;

			foreach ($_POST[$permission] as $id_group => $value)
			{
				if (in_array($value, array('on', 'deny')) && (empty($context['illegal_permissions']) || !in_array($permission, $context['illegal_permissions'])))
					$insertRows[] = array((int) $id_group, $permission, $value == 'on' ? 1 : 0);
			}
		}

		// Remove the old permissions...
		$db->query('', '
			DELETE FROM {db_prefix}permissions
			WHERE permission IN ({array_string:permissions})
			' . (empty($context['illegal_permissions']) ? '' : ' AND permission NOT IN ({array_string:illegal_permissions})'),
			array(
				'illegal_permissions' => !empty($context['illegal_permissions']) ? $context['illegal_permissions'] : array(),
				'permissions' => $permissions,
			)
		);

		// ...and replace them with new ones.
		if (!empty($insertRows))
			$db->insert('insert',
				'{db_prefix}permissions',
				array('id_group' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
				$insertRows,
				array('id_group', 'permission')
			);

		// Do a full child update.
		updateChildPermissions(array(), -1);

		// Just in case we cached this.
		updateSettings(array('settings_updated' => time()));
	}

	/**
	 * Initialize a form with inline permissions settings.
	 * It loads a context variables for each permission.
	 * This function is used by several settings screens to set specific permissions.
	 *
	 * @param string[] $permissions
	 * @param int[] $excluded_groups = array()
	 *
	 * @uses ManagePermissions language
	 * @uses ManagePermissions template.
	 */
	public static function init_inline_permissions($permissions, $excluded_groups = array())
	{
		global $context, $txt, $modSettings;

		$db = $GLOBALS['elk']['db'];

		loadLanguage('ManagePermissions');
		$GLOBALS['elk']['templates']->load('ManagePermissions');
		$context['can_change_permissions'] = allowedTo('manage_permissions');

		// Nothing to initialize here.
		if (!$context['can_change_permissions'])
			return;

		// Load the permission settings for guests
		foreach ($permissions as $permission)
			$context[$permission] = array(
				-1 => array(
					'id' => -1,
					'name' => $txt['membergroups_guests'],
					'is_postgroup' => false,
					'status' => 'off',
				),
				0 => array(
					'id' => 0,
					'name' => $txt['membergroups_members'],
					'is_postgroup' => false,
					'status' => 'off',
				),
			);

		$request = $db->query('', '
			SELECT id_group, CASE WHEN add_deny = {int:denied} THEN {string:deny} ELSE {string:on} END AS status, permission
			FROM {db_prefix}permissions
			WHERE id_group IN (-1, 0)
				AND permission IN ({array_string:permissions})',
			array(
				'denied' => 0,
				'permissions' => $permissions,
				'deny' => 'deny',
				'on' => 'on',
			)
		);
		while ($row = $request->fetchAssoc())
			$context[$row['permission']][$row['id_group']]['status'] = $row['status'];
		$request->free();

		$request = $db->query('', '
			SELECT mg.id_group, mg.group_name, mg.min_posts, IFNULL(p.add_deny, -1) AS status, p.permission
			FROM {db_prefix}membergroups AS mg
				LEFT JOIN {db_prefix}permissions AS p ON (p.id_group = mg.id_group AND p.permission IN ({array_string:permissions}))
			WHERE mg.id_group NOT IN (1, 3)
				AND mg.id_parent = {int:not_inherited}' . (empty($modSettings['permission_enable_postgroups']) ? '
				AND mg.min_posts = {int:min_posts}' : '') . '
			ORDER BY mg.min_posts, CASE WHEN mg.id_group < {int:newbie_group} THEN mg.id_group ELSE 4 END, mg.group_name',
			array(
				'not_inherited' => -2,
				'min_posts' => -1,
				'newbie_group' => 4,
				'permissions' => $permissions,
			)
		);
		while ($row = $request->fetchAssoc())
		{
			// Initialize each permission as being 'off' until proven otherwise.
			foreach ($permissions as $permission)
				if (!isset($context[$permission][$row['id_group']]))
					$context[$permission][$row['id_group']] = array(
						'id' => $row['id_group'],
						'name' => $row['group_name'],
						'is_postgroup' => $row['min_posts'] != -1,
						'status' => 'off',
					);

			$context[$row['permission']][$row['id_group']]['status'] = empty($row['status']) ? 'deny' : ($row['status'] == 1 ? 'on' : 'off');
		}
		$request->free();

		// Some permissions cannot be given to certain groups. Remove the groups.
		foreach ($excluded_groups as $group)
		{
			foreach ($permissions as $permission)
			{
				if (isset($context[$permission][$group]))
					unset($context[$permission][$group]);
			}
		}
	}
}