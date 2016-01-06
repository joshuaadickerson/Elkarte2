<?php

/**
 * This file/class handles known scheduled tasks
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

namespace Elkarte\Scheduler\Tasks;

if (!defined('ELK'))
	die('No access...');

/**
 * Class Remove_Topic_Redirect - This class handles known scheduled tasks.
 *
 * - Each method implements a task, and
 * - it's called automatically for the task to run.
 *
 * @package ScheduledTasks
 */
class RemoveTopicRedirect implements ScheduledTaskInterface
{
	public function run()
	{
		$db = $GLOBALS['elk']['db'];

		// Init
		$topics = array();

		// We will need this for language files
		loadEssentialThemeData();

		// Find all of the old MOVE topic notices that were set to expire
		$request = $db->query('', '
			SELECT id_topic
			FROM {db_prefix}topics
			WHERE redirect_expires <= {int:redirect_expires}
				AND redirect_expires <> 0',
			array(
				'redirect_expires' => time(),
			)
		);

		while ($row = $request->fetchRow())
			$topics[] = $row[0];
		$request->free();

		// Zap, you're gone
		if (count($topics) > 0)
		{

			removeTopics($topics, false, true);
		}

		return true;
	}
}