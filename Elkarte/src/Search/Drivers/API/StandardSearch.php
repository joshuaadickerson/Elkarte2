<?php

/**
 * Standard non full index, non custom index search
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

namespace Elkarte\Search\Drivers\API;


/**
 * SearchAPI-Standard.class.php, Standard non full index, non custom index search
 *
 * @package Search
 */
class StandardSearch extends SearchAPI
{
	/**
	 * Method to check whether the method can be performed by the API.
	 *
	 * @param string $methodName
	 * @param string|null $query_params
	 * @return boolean
	 */
	public function supportsMethod($methodName, $query_params = null)
	{
		// Always fall back to the standard search method.
		return false;
	}
}