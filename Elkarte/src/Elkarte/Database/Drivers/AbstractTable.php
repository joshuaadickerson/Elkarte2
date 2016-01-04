<?php

/**
 * This is the base class for DbTable functionality.
 * It contains abstract methods to be implemented for the specific database system,
 * related to a table structure.
 * Add-ons will need this, to change the database for their needs.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Database\Drivers;

/**
 * This is used to create a table without worrying about schema compatibilities
 * across supported database systems.
 */
abstract class AbstractTable implements TableInterface
{
	/**
	 * We need a way to interact with the database
	 * @var DatabaseInterface
	 */
	protected $_db;

	/**
	 * Array of table names we don't allow to be removed by addons.
	 * @var array
	 */
	protected $_reservedTables = [
		'admin_info_files', 'approval_queue', 'attachments', 'ban_groups', 'ban_items',
		'board_permissions', 'boards', 'calendar', 'calendar_holidays', 'categories', 'collapsed_categories',
		'custom_fields', 'group_moderators', 'log_actions', 'log_activity', 'log_banned', 'log_boards',
		'log_digest', 'log_errors', 'log_floodcontrol', 'log_group_requests', 'log_karma', 'log_mark_read',
		'log_notify', 'log_online', 'log_packages', 'log_polls', 'log_reported', 'log_reported_comments',
		'log_scheduled_tasks', 'log_search_messages', 'log_search_results', 'log_search_subjects',
		'log_search_topics', 'log_topics', 'mail_queue', 'membergroups', 'members', 'message_icons',
		'messages', 'moderators', 'package_servers', 'permission_profiles', 'permissions', 'personal_messages',
		'pm_recipients', 'poll_choices', 'polls', 'scheduled_tasks', 'sessions', 'settings', 'smileys',
		'themes', 'topics'
	];

	/**
	 * Keeps a (reverse) log of changes to the table structure, to be undone.
	 * This is used by Packages Admin installation/uninstallation/upgrade.
	 *
	 * @var array
	 */
	public $change_log = [];

	public function __construct(DatabaseInterface $db)
	{
		foreach ($this->_reservedTables as $k => $name)
			$this->_reservedTables[$k] = strtolower($this->_db->prefix . $name);

		// This executes queries and things
		$this->_db = $db;
	}

	/**
	 * {@inheritdoc}
	 */
	abstract public function create($name, $columns, $indexes = array(), $parameters = array(), $if_exists = 'ignore', $error = 'fatal');

	/**
	 * {@inheritdoc}
	 */
	abstract public function drop($name, $parameters = array(), $error = 'fatal');

	/**
	 * {@inheritdoc}
	 */
	abstract public function addColumn($name, $column_info, $parameters = array(), $if_exists = 'update', $error = 'fatal');

	/**
	 * {@inheritdoc}
	 */
	abstract public function removeColumn($name, $column_name, $parameters = array(), $error = 'fatal');

	/**
	 * {@inheritdoc}
	 */
	abstract public function changeColumn($name, $old_column, $column_info, $parameters = array(), $error = 'fatal');

	/**
	 * {@inheritdoc}
	 */
	abstract public function addIndex($name, $index_info, $parameters = array(), $if_exists = 'update', $error = 'fatal');

	/**
	 * {@inheritdoc}
	 */
	abstract public function removeIndex($name, $index_name, $parameters = array(), $error = 'fatal');

	/**
	 * {@inheritdoc}
	 */
	abstract public function calculateType($type_name, $type_size = null, $reverse = false);

	/**
	 * {@inheritdoc}
	 */
	abstract public function structure($name, $parameters = array());

	/**
	 * {@inheritdoc}
	 */
	abstract public function listColumns($name, $detail = false, $parameters = array());

	/**
	 * {@inheritdoc}
	 */
	abstract public function listIndexes($name, $detail = false, $parameters = array());

	/**
	 * {@inheritdoc}
	 */
	abstract public function backup($table_name, $backup_table);

	/**
	 * A very simple wrapper around the ALTER TABLE SQL statement.
	 *
	 * @param string $name
	 * @param string $statement
	 */
	protected function _alter_table($name, $statement)
	{
		return $this->_db->query('', '
			ALTER TABLE ' . $name . '
			' . $statement,
			array(
				'security_override' => true,
			)
		);
	}

	/**
	 * Finds a column by name in a table and returns some info.
	 *
	 * @param string $name
	 * @param string $column_name
	 * @return mixed[]|false
	 */
	protected function _get_column_info($name, $column_name)
	{
		$columns = $this->db_list_columns($name, true);

		foreach ($columns as $column)
		{
			if ($column_name == $column['name'])
			{
				return $column_info;
			}
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists($name)
	{
		$filter = $this->connection->listTables(false, $name);
		return !empty($filter);
	}

	/**
	 * Checks if a column exists in a table
	 *
	 * @param string $name
	 * @return bool
	 */
	public function column_exists($name, $column_name)
	{
		return $this->_get_column_info($name, $column_name) !== false;
	}
}