<?php

/**
 * This class handles database search. (MySQL)
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Database\Drivers\MySQL;

use Elkarte\Database\Drivers\SearchInterface;
use Elkarte\ElkArte\Database\Drivers\ResultInterface;

/**
 * MySQL implementation of DbSearch
 */
class Search implements SearchInterface
{
	/**
	 * This instance of the search
	 * @var Search
	 */
	private static $_search = null;

	/**
	 * This method will tell you whether this database type supports this search type.
	 *
	 * @param string $search_type
	 * @return bool
	 */
	public function search_support($search_type)
	{
		$supported_types = array('fulltext');

		return in_array($search_type, $supported_types);
	}

	/**
	 * Execute the appropriate query for the search.
	 *
	 * @param string $identifier
	 * @param string $db_string
	 * @param mixed[] $db_values
	 * @param resource|null $connection
	 * @return ResultInterface
	 */
	public function query($identifier, $db_string, $db_values = array(), $connection = null)
	{
		$db = $GLOBALS['elk']['db'];

		// Simply delegate to the database adapter method.
		return $db->query($identifier, $db_string, $db_values, $connection);
	}

	/**
	 * Returns some basic info about the {db_prefix}messages table
	 * Used in ManageSearch.controller.php in the page to select the index method
	 */
	public function messagesTableInfo()
	{
		global $db_prefix;

		$db = $GLOBALS['elk']['db'];

		$table_info = array();

		if (preg_match('~^`(.+?)`\.(.+?)$~', $db_prefix, $match) !== 0)
			$request = $db->query('', '
				SHOW TABLE STATUS
				FROM {string:database_name}
				LIKE {string:table_name}',
				array(
					'database_name' => '`' . strtr($match[1], array('`' => '')) . '`',
					'table_name' => str_replace('_', '\_', $match[2]) . 'messages',
				)
			);
		else
			$request = $db->query('', '
				SHOW TABLE STATUS
				LIKE {string:table_name}',
				array(
					'table_name' => str_replace('_', '\_', $db_prefix) . 'messages',
				)
			);

		if ($request !== false && $request->numRows() == 1)
		{
			// Only do this if the user has permission to execute this query.
			$row = $request->fetchAssoc();
			$table_info['data_length'] = $row['Data_length'];
			$table_info['index_length'] = $row['Index_length'];
			$table_info['fulltext_length'] = $row['Index_length'];
			$request->free();
		}

		// Now check the custom index table, if it exists at all.
		if (preg_match('~^`(.+?)`\.(.+?)$~', $db_prefix, $match) !== 0)
			$request = $db->query('', '
				SHOW TABLE STATUS
				FROM {string:database_name}
				LIKE {string:table_name}',
				array(
					'database_name' => '`' . strtr($match[1], array('`' => '')) . '`',
					'table_name' => str_replace('_', '\_', $match[2]) . 'log_search_words',
				)
			);
		else
			$request = $db->query('', '
				SHOW TABLE STATUS
				LIKE {string:table_name}',
				array(
					'table_name' => str_replace('_', '\_', $db_prefix) . 'log_search_words',
				)
			);

		if ($request !== false && $request->numRows() == 1)
		{
			// Only do this if the user has permission to execute this query.
			$row = $request->fetchAssoc();
			$table_info['index_length'] += $row['Data_length'] + $row['Index_length'];
			$table_info['custom_index_length'] = $row['Data_length'] + $row['Index_length'];
			$request->free();
		}

		return $table_info;
	}

	/**
	 * Method for the custom word index table.
	 *
	 * @param string $size
	 */
	public function create_word_search($size)
	{
		$db = $GLOBALS['elk']['db'];

		if ($size == 'small')
			$size = 'smallint(5)';
		elseif ($size == 'medium')
			$size = 'mediumint(8)';
		else
			$size = 'int(10)';

		$db->query('', '
			CREATE TABLE {db_prefix}log_search_words (
				id_word {raw:size} unsigned NOT NULL default {string:string_zero},
				id_msg int(10) unsigned NOT NULL default {string:string_zero},
				PRIMARY KEY (id_word, id_msg)
			) ENGINE=InnoDB',
			array(
				'string_zero' => '0',
				'size' => $size,
			)
		);
	}

	/**
	 * Static method that allows to retrieve or create an instance of this class.
	 */
	public static function db_search()
	{
		if (is_null(self::$_search))
		{
			self::$_search = new self();
		}

		return self::$_search;
	}
}