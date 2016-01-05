<?php

namespace Elkarte\Database\Drivers;

interface TableInterface
{
	public function __construct(DatabaseInterface $db);

	/**
	 * Checks if a table exists
	 *
	 * @param string $name
	 * @return bool
	 */
	public function exists($name);

	/**
	 * This function can be used to create a table without worrying about schema
	 *  compatibilities across supported database systems.
	 *  - If the table exists will, by default, do nothing.
	 *  - Builds table with columns as passed to it - at least one column must be sent.
	 *  The columns array should have one sub-array for each column - these sub arrays contain:
	 *    'name' = Column name
	 *    'type' = Type of column - values from (smallint, mediumint, int, text, varchar, char, tinytext, mediumtext, largetext)
	 *    'size' => Size of column (If applicable) - for example 255 for a large varchar, 10 for an int etc.
	 *      If not set it will pick a size.
	 *    - 'default' = Default value - do not set if no default required.
	 *    - 'null' => Can it be null (true or false) - if not set default will be false.
	 *    - 'auto' => Set to true to make it an auto incrementing column. Set to a numerical value to set from what
	 *      it should begin counting.
	 *  - Adds indexes as specified within indexes parameter. Each index should be a member of $indexes. Values are:
	 *    - 'name' => Index name (If left empty it will be generated).
	 *    - 'type' => Type of index. Choose from 'primary', 'unique' or 'index'. If not set will default to 'index'.
	 *    - 'columns' => Array containing columns that form part of key - in the order the index is to be created.
	 *  - parameters: (None yet)
	 *  - if_exists values:
	 *    - 'ignore' will do nothing if the table exists. (And will return true)
	 *    - 'overwrite' will drop any existing table of the same name.
	 *    - 'error' will return false if the table already exists.
	 *
	 * @param string $name
	 * @param mixed[] $columns in the format specified.
	 * @param mixed[] $indexes default array(), in the format specified.
	 * @param mixed[] $parameters default array()
	 * @param string $if_exists default 'ignore'
	 * @param string $error default 'fatal'
	 */
	public function create($name, $columns, $indexes = array(), $parameters = array(), $if_exists = 'ignore', $error = 'fatal');

	/**
	 * Drop a table.
	 *
	 * @param string $table_name
	 * @param mixed[] $parameters default array()
	 * @param string $error default 'fatal'
	 * @return bool
	 */
	public function drop($name, $parameters = array(), $error = 'fatal');

	/**
	 * This function adds a column.
	 *
	 * @param string $name the name of the table
	 * @param mixed[] $column_info with column information
	 * @param mixed[] $parameters default array()
	 * @param string $if_exists default 'update'
	 * @param string $error default 'fatal'
	 * @return bool
	 */
	public function addColumn($name, $column_info, $parameters = array(), $if_exists = 'update', $error = 'fatal');

	/**
	 * Removes a column.
	 *
	 * @param string $name
	 * @param string $column_name
	 * @param mixed[] $parameters default array()
	 * @param string $error default 'fatal'
	 * @return bool
	 */
	public function removeColumn($name, $column_name, $parameters = array(), $error = 'fatal');

	/**
	 * Change a column.
	 *
	 * @param string $name
	 * @param string $old_column
	 * @param mixed[] $column_info
	 * @param mixed[] $parameters default array()
	 * @param string $error default 'fatal'
	 * @return bool
	 */
	public function changeColumn($name, $old_column, $column_info, $parameters = array(), $error = 'fatal');

	/**
	 * Return column information for a table.
	 *
	 * @param string $name
	 * @param bool $detail
	 * @param mixed[] $parameters default array()
	 * @return mixed
	 */
	public function listColumns($name, $detail = false, $parameters = array());

	/**
	 * Add an index.
	 *
	 * @param string $name
	 * @param mixed[] $index_info
	 * @param mixed[] $parameters default array()
	 * @param string $if_exists default 'update'
	 * @param string $error default 'fatal'
	 * @return void
	 */
	public function addIndex($name, $index_info, $parameters = array(), $if_exists = 'update', $error = 'fatal');

	/**
	 * Remove an index.
	 *
	 * @param string $name
	 * @param string $index_name
	 * @param mixed[] $parameters default array()
	 * @param string $error default 'fatal'
	 * @return bool
	 */
	public function removeIndex($name, $index_name, $parameters = array(), $error = 'fatal');

	/**
	 * Get index information.
	 *
	 * @param string $name
	 * @param bool $detail
	 * @param mixed[] $parameters
	 * @return mixed
	 */
	public function listIndexes($name, $detail = false, $parameters = array());

	/**
	 * Get the schema formatted name for a type.
	 *
	 * @param string $type_name
	 * @param int|null $type_size
	 * @param boolean $reverse
	 * return array
	 */
	public function calculateType($type_name, $type_size = null, $reverse = false);

	/**
	 * Get table structure.
	 *
	 * @param string $name
	 * @param mixed[] $parameters default array()
	 * @return array
	 */
	public function structure($name, $parameters = array());

	/**
	 * This function optimizes a table.
	 *
	 * @param string $table - the table to be optimized
	 *
	 * @return int how much it was gained
	 */
	public function optimize($table);

	/**
	 * Backup $table_name to $backup_table.
	 *
	 * @param string $table_name
	 * @param string $backup_table
	 *
	 * @return resource - the request handle to the table creation query
	 */
	public function backup($table_name, $backup_table);
}