<?php

/**
 * Check for followups from removed topics and remove them from the table
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Scheduler\Tasks;

/**
 * Check for followups from removed topics and remove them from the table
 *
 * @package ScheduledTasks
 */
class RemoveOldFollowups implements ScheduledTaskInterface
{
	public function run()
	{
		global $modSettings;

		if (empty($modSettings['enableFollowup']))
			return false;

		$db = $GLOBALS['elk']['db'];

		$request = $db->query('', '
			SELECT fu.derived_from
			FROM {db_prefix}follow_ups AS fu
				LEFT JOIN {db_prefix}messages AS m ON (fu.derived_from = m.id_msg)
			WHERE m.id_msg IS NULL
			LIMIT {int:limit}',
			array(
				'limit' => 100,
			)
		);

		$remove = array();
		while ($row = $request->fetchAssoc())
			$remove[] = $row['derived_from'];
		$request->free();

		if (empty($remove))
			return true;

		require_once(SUBSDIR . '/FollowUps.subs.php');
		removeFollowUpsByMessage($remove);

		return true;
	}
}