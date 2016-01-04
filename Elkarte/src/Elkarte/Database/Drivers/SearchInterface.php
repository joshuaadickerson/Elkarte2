<?php

/**
 * This interface is meant to be implemented by classes which offer database search extra-facilities.
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
 * Interface methods for database searches
 */
interface SearchInterface
{
	/**
	 * Execute the appropriate query for the search.
	 *
	 * @param string $identifier
	 * @param string $db_string
	 * @param mixed[] $db_values
	 * @param resource|null $connection
	 * @return resource
	 */
	public function query($identifier, $db_string, $db_values = array());

	/**
	 * This method will tell you whether this database type supports this search type.
	 *
	 * @param string $search_type
	 * @return boolean
	 */
	public function search_support($search_type);

	/**
	 * Method for the custom word index table.
	 *
	 * @param string $size
	 * @return void
	 */
	public function create_word_search($size);
}