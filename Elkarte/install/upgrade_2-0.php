<?php

/**
 * This class is the core of the upgrade system.
 * Methods starting with "__" (double underscore) are not executed.
 * Each method that contains one or more actions is paired with a method
 * with the same name plus "_title", for example:
 *   - my_new_action
 *   - my_new_action_title
 * Methods whose name ends with "_title" are supposed to return a single
 * string representing the title of the step.
 * Methods containing the actions are supposed to return a multidimentional
 * array with the following structure:
 * array(
 *     array(
 *         'debug_title' => 'A string representing a title shown when debugging',
 *         'function' => function($db, $db_table) { // Code },
 *     ),
 *     [...],
 * );
 */
class UpgradeInstructions_upgrade_2_0
{
	protected $db = null;
	protected $table = null;

	public function __construct($db, $table)
	{
		$this->db = $db;
		return $this->table = $table;
	}

	public function settings_groups_title()
	{
		return 'Adding groups to the settings table';
	}

	public function settings_groups()
	{
		return [
			[
				'debug_title' => 'Adding new column "key_groups" to Settings table.',
				'function' => function ($db, $db_table) {
					// Add the column
					$db_table->db_add_column('{db_prefix}settings',
						[
							'name'		=> 'key_groups',
							'type' 		=> 'varchar(255)',
							'default'	=> '\'\'',
						]
					);

					// Add the index
					$db_table->db_add_index('{db_prefix}settings',
						[
							'name' 		=> 'key_groups',
							'columns'	=> ['key_groups'],
							'type' 		=> 'index',
						]
					);

					// Update the key groups
					$db->update('', '
						{db_prefix}settings
						SET key_groups = {string:key_group}',
						[
							'key_group' => 'settings',
						]
					);
				},
			],
		];
	}
}