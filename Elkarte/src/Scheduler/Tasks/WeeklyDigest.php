<?php

/**
 * Sends out email notifications for new/updated topics.
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
 * Class Weekly_Digest
 * Sends out email notifications for new/updated topics.
 *
 * - Like the daily stuff - just seven times less regular ;)
 * - This method forwards to daily_digest()
 *
 * @package ScheduledTasks
 */
class WeeklyDigest implements ScheduledTaskInterface
{
	public function run()
	{
		$digest = new DailyDigest();

		return $digest->runDigest(true);
	}
}