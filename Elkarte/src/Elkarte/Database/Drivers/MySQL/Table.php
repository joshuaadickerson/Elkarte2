<?php

/**
 * This class implements functionality related to table structure.
 * Intended in particular for addons to change it to suit their needs.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code covered by:
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Database\Drivers\MySQL;

use Elkarte\Database\Drivers\AbstractTable;

/**
 * Adds MySQL table level functionality,
 * Table creation / dropping, column adding / removing
 * Most often used during Install and Upgrades of the forum and addons
 */
class Table extends AbstractTable
{
	public function create($name, $columns, $indexes = array(), $parameters = array(), $if_exists = 'ignore', $error = 'fatal')
	{
		// Strip out the table name, we might not need it in some cases
		$real_prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $this->_db->prefix, $match) === 1 ? $match[3] : $this->_db->prefix;

		// With or without the database name, the fullname looks like this.
		$full_table_name = str_replace('{db_prefix}', $real_prefix, $name);
		$name = $this->_db->fixPrefix($name);

		// First - no way do we touch our tables.
		if (in_array(strtolower($name), $this->_reservedTables))
			return false;

		// Log that we'll want to remove this on uninstall.
		$this->change_log[] = array('remove_table', $name);

		// Slightly easier on MySQL than the others...
		if ($this->exists($full_table_name))
		{
			// This is a sad day... drop the table? If not, return false (error) by default.
			if ($if_exists == 'overwrite')
				$this->drop($name);
			else
				return $if_exists == 'ignore';
		}

		// Righty - let's do the damn thing!
		$table_query = 'CREATE TABLE ' . $name . "\n" . '(';
		foreach ($columns as $column)
			$table_query .= "\n\t" . $this->_db_create_query_column($column)  . ',';

		// Loop through the indexes next...
		foreach ($indexes as $index)
		{
			$columns = implode(',', $index['columns']);

			// Is it the primary?
			if (isset($index['type']) && $index['type'] == 'primary')
				$table_query .= "\n\t" . 'PRIMARY KEY (' . $columns . '),';
			else
			{
				if (empty($index['name']))
					$index['name'] = implode('_', $index['columns']);
				$table_query .= "\n\t" . (isset($index['type']) && $index['type'] == 'unique' ? 'UNIQUE' : 'KEY') . ' ' . $index['name'] . ' (' . $columns . '),';
			}
		}

		// No trailing commas!
		if (substr($table_query, -1) == ',')
			$table_query = substr($table_query, 0, -1);

		$table_query .= ') ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci';

		// Create the table!
		$this->_db->query('', $table_query,
			array(
				'security_override' => true,
			)
		);

		return true;
	}

	public function drop($name, $parameters = array(), $error = 'fatal')
	{
		// After stripping away the database name, this is what's left.
		$real_prefix = preg_match('~^(`?)(.+?)\\1\\.(.*?)$~', $this->_db->prefix, $match) === 1 ? $match[3] : $this->_db->prefix;

		// Get some aliases.
		$full_table_name = str_replace('{db_prefix}', $real_prefix, $name);
		$name = $this->_db->fixPrefix($name);

		// God no - dropping one of these = bad.
		if (in_array(strtolower($name), $this->_reservedTables))
			return false;

		// Does it exist?
		if ($this->exists($full_table_name))
		{
			$query = 'DROP TABLE ' . $name;
			$this->_db->query('',
				$query,
				array(
					'security_override' => true,
				)
			);

			return true;
		}

		// Otherwise do 'nout.
		return false;
	}

	public function addColumn($name, $column_info, $parameters = array(), $if_exists = 'update', $error = 'fatal')
	{
		$name = $this->_db->fixPrefix($name);

		// Log that we will want to uninstall this!
		$this->change_log[] = array('remove_column', $name, $column_info['name']);

		// Does it exist - if so don't add it again!
		if ($this->_get_column_info($name, $column_info['name']))
		{
			// If we're going to overwrite then use change column.
			if ($if_exists == 'update')
				return $this->db_change_column($name, $column_info['name'], $column_info);
			else
				return false;
		}

		// Now add the thing!
		$this->_alter_table($name, '
			ADD ' . $this->_db_create_query_column($column_info) . (empty($column_info['auto']) ? '' : ' primary key'));

		return true;
	}

	public function removeColumn($name, $column_name, $parameters = array(), $error = 'fatal')
	{
		$name = $this->_db->fixPrefix($name);

		// Does it exist?
		$column = $this->_get_column_info($name, $column_name);
		if ($column !== false)
		{
			$this->_alter_table($name, '
				DROP COLUMN ' . $column_name);

			return true;
		}

		// If here we didn't have to work - joy!
		return false;
	}

	public function changeColumn($name, $old_column, $column_info, $parameters = array(), $error = 'fatal')
	{
		$name = $this->_db->fixPrefix($name);

		// Check it does exist!
		$old_info = $this->_get_column_info($name, $old_column);

		// Nothing?
		if ($old_info === false)
			return false;

		// Get the right bits.
		if (!isset($column_info['name']))
			$column_info['name'] = $old_column;
		if (!isset($column_info['default']))
			$column_info['default'] = $old_info['default'];
		if (!isset($column_info['null']))
			$column_info['null'] = $old_info['null'];
		if (!isset($column_info['auto']))
			$column_info['auto'] = $old_info['auto'];
		if (!isset($column_info['type']))
			$column_info['type'] = $old_info['type'];
		if (!isset($column_info['size']) || !is_numeric($column_info['size']))
			$column_info['size'] = $old_info['size'];
		if (!isset($column_info['unsigned']) || !in_array($column_info['type'], array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')))
			$column_info['unsigned'] = '';

		$this->_alter_table($name, '
			CHANGE COLUMN `' . $old_column . '` ' . $this->_db_create_query_column($column_info));

		return true;
	}

	public function addIndex($name, $index_info, $parameters = array(), $if_exists = 'update', $error = 'fatal')
	{
		$name = $this->_db->fixPrefix($name);

		// No columns = no index.
		if (empty($index_info['columns']))
			return false;
		$columns = implode(',', $index_info['columns']);

		// No name - make it up!
		if (empty($index_info['name']))
		{
			// No need for primary.
			if (isset($index_info['type']) && $index_info['type'] == 'primary')
				$index_info['name'] = '';
			else
				$index_info['name'] = implode('_', $index_info['columns']);
		}

		// Log that we are going to want to remove this!
		$this->change_log[] = array('remove_index', $name, $index_info['name']);

		// Let's get all our indexes.
		$indexes = $this->listIndexes($name, true);

		// Do we already have it?
		foreach ($indexes as $index)
		{
			if ($index['name'] == $index_info['name'] || ($index['type'] == 'primary' && isset($index_info['type']) && $index_info['type'] == 'primary'))
			{
				// If we want to overwrite simply remove the current one then continue.
				if ($if_exists != 'update' || $index['type'] == 'primary')
					return false;
				else
					$this->removeIndex($name, $index_info['name']);
			}
		}

		// If we're here we know we don't have the index - so just add it.
		if (!empty($index_info['type']) && $index_info['type'] == 'primary')
		{
			$this->_alter_table($name, '
				ADD PRIMARY KEY (' . $columns . ')');
		}
		else
		{
			$this->_alter_table($name, '
				ADD ' . (isset($index_info['type']) && $index_info['type'] == 'unique' ? 'UNIQUE' : 'INDEX') . ' ' . $index_info['name'] . ' (' . $columns . ')');
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function removeIndex($name, $index_name, $parameters = array(), $error = 'fatal')
	{
		$name = $this->_db->fixPrefix();

		// Better exist!
		$indexes = $this->listIndexes($name, true);

		foreach ($indexes as $index)
		{
			// If the name is primary we want the primary key!
			if ($index['type'] == 'primary' && $index_name == 'primary')
			{
				// Dropping primary key?
				$this->_alter_table($name, '
					DROP PRIMARY KEY');

				return true;
			}

			if ($index['name'] == $index_name)
			{
				// Drop the bugger...
				$this->_alter_table($name, '
					DROP INDEX ' . $index_name);

				return true;
			}
		}

		// Not to be found ;(
		return false;
	}

	public function calculateType($type_name, $type_size = null, $reverse = false)
	{
		// MySQL is actually the generic baseline.
		return array($type_name, $type_size);
	}

	/**
	 * {@inheritdoc}
	 */
	public function structure($name, $parameters = array())
	{
		$name = $this->_db->fixPrefix($name);

		return array(
			'name' => $name,
			'columns' => $this->db_list_columns($name, true),
			'indexes' => $this->db_list_indexes($name, true),
		);
	}

	public function listColumns($name, $detail = false, $parameters = array())
	{
		$name = $this->_db->fixPrefix($name);

		$result = $this->_db->query('', '
			SHOW FIELDS
			FROM {raw:table_name}',
			array(
				'table_name' => substr($name, 0, 1) == '`' ? $name : '`' . $name . '`',
			)
		);
		$columns = array();
		while ($row = $result->fetchAssoc())
		{
			if (!$detail)
			{
				$columns[] = $row['Field'];
			}
			else
			{
				// Is there an auto_increment?
				$auto = strpos($row['Extra'], 'auto_increment') !== false ? true : false;

				// Can we split out the size?
				if (preg_match('~(.+?)\s*\((\d+)\)(?:(?:\s*)?(unsigned))?~i', $row['Type'], $matches) === 1)
				{
					$type = $matches[1];
					$size = $matches[2];
					if (!empty($matches[3]) && $matches[3] == 'unsigned')
						$unsigned = true;
				}
				else
				{
					$type = $row['Type'];
					$size = null;
				}

				$columns[$row['Field']] = array(
					'name' => $row['Field'],
					'null' => $row['Null'] != 'YES' ? false : true,
					'default' => isset($row['Default']) ? $row['Default'] : null,
					'type' => $type,
					'size' => $size,
					'auto' => $auto,
				);

				if (isset($unsigned))
				{
					$columns[$row['Field']]['unsigned'] = $unsigned;
					unset($unsigned);
				}
			}
		}
		$result->free();

		return $columns;
	}

	public function listIndexes($name, $detail = false, $parameters = array())
	{
		$name = $this->_db->fixPrefix($name);

		$result = $this->_db->query('', '
			SHOW KEYS
			FROM {raw:table_name}',
			array(
				'table_name' => substr($name, 0, 1) == '`' ? $name : '`' . $name . '`',
			)
		);
		$indexes = array();
		while ($row = $result->fetchAssoc())
		{
			if (!$detail)
				$indexes[] = $row['Key_name'];
			else
			{
				// What is the type?
				if ($row['Key_name'] == 'PRIMARY')
					$type = 'primary';
				elseif (empty($row['Non_unique']))
					$type = 'unique';
				elseif (isset($row['Index_type']) && $row['Index_type'] == 'FULLTEXT')
					$type = 'fulltext';
				else
					$type = 'index';

				// This is the first column we've seen?
				if (empty($indexes[$row['Key_name']]))
				{
					$indexes[$row['Key_name']] = array(
						'name' => $row['Key_name'],
						'type' => $type,
						'columns' => array(),
					);
				}

				// Is it a partial index?
				if (!empty($row['Sub_part']))
					$indexes[$row['Key_name']]['columns'][] = $row['Column_name'] . '(' . $row['Sub_part'] . ')';
				else
					$indexes[$row['Key_name']]['columns'][] = $row['Column_name'];
			}
		}
		$result->free();

		return $indexes;
	}

	/**
	 * Creates a query for a column
	 *
	 * @param mixed[] $column
	 * @return string
	 */
	protected function _db_create_query_column($column)
	{
		// Auto increment is easy here!
		if (!empty($column['auto']))
		{
			$default = 'auto_increment';
		}
		elseif (isset($column['default']) && $column['default'] !== null)
			$default = 'default \'' . $this->_db->escapeString($column['default']) . '\'';
		else
			$default = '';

		// Sort out the size... and stuff...
		$column['size'] = isset($column['size']) && is_numeric($column['size']) ? $column['size'] : null;
		list ($type, $size) = $this->calculateType($column['type'], $column['size']);

		// Allow unsigned integers (mysql only)
		$unsigned = in_array($type, array('int', 'tinyint', 'smallint', 'mediumint', 'bigint')) && !empty($column['unsigned']) ? 'unsigned ' : '';

		if ($size !== null)
			$type = $type . '(' . $size . ')';

		// Now just put it together!
		return '`' .$column['name'] . '` ' . $type . ' ' . (!empty($unsigned) ? $unsigned : '') . (!empty($column['null']) ? '' : 'NOT NULL') . ' ' . $default;
	}

	public function optimize($table)
	{
		$this->_db->fixPrefix($table);

		// Get how much overhead there is.
		$request = $this->_db->query('', '
			SHOW TABLE STATUS LIKE {string:table_name}',
			array(
				'table_name' => str_replace('_', '\_', $table),
			)
		);
		$row = $request->fetchAssoc();
		$request->free;

		$data_before = isset($row['Data_free']) ? $row['Data_free'] : 0;
		$request = $this->_db->query('', '
			OPTIMIZE TABLE `{raw:table}`',
			array(
				'table' => $table,
			)
		);
		if (!$request)
			return -1;

		// How much left?
		$request = $this->_db->query('', '
			SHOW TABLE STATUS LIKE {string:table}',
			array(
				'table' => str_replace('_', '\_', $table),
			)
		);
		$row = $request->fetchAssoc();
		$request->free;

		$total_change = isset($row['Data_free']) && $data_before > $row['Data_free'] ? $data_before / 1024 : 0;

		return $total_change;
	}

	/**
	 * {@inheritdoc}
	 */
	public function backup($table_name, $backup_table)
	{
		$table = $this->_db->fixPrefix($table_name);
		// @todo shouldn't $backup_table also do fixPrefix()?

		// First, get rid of the old table.
		$this->drop($backup_table);

		// Can we do this the quick way?
		$result = $this->_db->query('', '
			CREATE TABLE {raw:backup_table} LIKE {raw:table}',
			array(
				'backup_table' => $backup_table,
				'table' => $table
			));

		// If this failed, we go old school.
		if ($result)
		{
			$request = $this->_db->query('', '
				INSERT INTO {raw:backup_table}
				SELECT *
				FROM {raw:table}',
				array(
					'backup_table' => $backup_table,
					'table' => $table
				));

			// Old school or no school?
			if ($request)
				return $request;
		}

		// At this point, the quick method failed.
		$result = $this->_db->query('', '
			SHOW CREATE TABLE {raw:table}',
			array(
				'table' => $table,
			)
		);
		list (, $create) = $result->fetchRow();
		$result->free();

		$create = preg_split('/[\n\r]/', $create);

		$auto_inc = '';

		// Default engine type.
		$engine = 'InnoDB';
		$charset = '';
		$collate = '';

		foreach ($create as $k => $l)
		{
			// Get the name of the auto_increment column.
			if (strpos($l, 'auto_increment'))
				$auto_inc = trim($l);

			// For the engine type, see if we can work out what it is.
			if (strpos($l, 'ENGINE') !== false || strpos($l, 'TYPE') !== false)
			{
				// Extract the engine type.
				preg_match('~(ENGINE|TYPE)=(\w+)(\sDEFAULT)?(\sCHARSET=(\w+))?(\sCOLLATE=(\w+))?~', $l, $match);

				if (!empty($match[1]))
					$engine = $match[1];

				if (!empty($match[2]))
					$engine = $match[2];

				if (!empty($match[5]))
					$charset = $match[5];

				if (!empty($match[7]))
					$collate = $match[7];
			}

			// Skip everything but keys...
			if (strpos($l, 'KEY') === false)
				unset($create[$k]);
		}

		if (!empty($create))
			$create = '(
				' . implode('
				', $create) . ')';
		else
			$create = '';

		$request = $this->_db->query('', '
			CREATE TABLE {raw:backup_table} {raw:create}
			ENGINE={raw:engine}' . (empty($charset) ? '' : ' CHARACTER SET {raw:charset}' . (empty($collate) ? '' : ' COLLATE {raw:collate}')) . '
			SELECT *
			FROM {raw:table}',
			array(
				'backup_table' => $backup_table,
				'table' => $table,
				'create' => $create,
				'engine' => $engine,
				'charset' => empty($charset) ? '' : $charset,
				'collate' => empty($collate) ? '' : $collate,
			)
		);

		if ($auto_inc != '')
		{
			if (preg_match('~\`(.+?)\`\s~', $auto_inc, $match) != 0 && substr($auto_inc, -1, 1) == ',')
				$auto_inc = substr($auto_inc, 0, -1);

			$this->_db->query('', '
				ALTER TABLE {raw:backup_table}
				CHANGE COLUMN {raw:column_detail} {raw:auto_inc}',
				array(
					'backup_table' => $backup_table,
					'column_detail' => $match[1],
					'auto_inc' => $auto_inc,
				)
			);
		}

		return $request;
	}

	/**
	 * This function lists all tables in the database.
	 * The listing could be filtered according to $filter.
	 *
	 * @param string|false $db_name_str string holding the database name, or false, default false
	 * @param string|false $filter string to filter by, or false, default false
	 *
	 * @return string[] an array of table names. (strings)
	 */
	public function listTables($db_name_str = false, $filter = false)
	{
		global $db_name;

		$db_name_str = $db_name_str == false ? $db_name : $db_name_str;
		$db_name_str = trim($db_name_str);
		$filter = $filter == false ? '' : ' LIKE \'' . $filter . '\'';

		$result = $this->_db->query('', '
			SHOW TABLES
			FROM `{raw:db_name_str}`
			{raw:filter}',
			array(
				'db_name_str' => $db_name_str[0] == '`' ? strtr($db_name_str, array('`' => '')) : $db_name_str,
				'filter' => $filter,
			)
		);
		$tables = array();
		while ($row = $result->fetchRow())
			$tables[] = $row[0];

		$result->free();

		return $tables;
	}
}