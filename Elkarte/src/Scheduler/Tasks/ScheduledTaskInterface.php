<?php

/**
 * Interface for scheduled tasks objects
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Scheduler\Tasks;

interface ScheduledTaskInterface
{
	public function run();
}