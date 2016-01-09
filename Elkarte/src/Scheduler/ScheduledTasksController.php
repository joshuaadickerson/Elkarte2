<?php

/**
 * This handles the execution of scheduled tasks, mail queue scheduling included.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Scheduler;
use Elkarte\Elkarte\Controller\AbstractController;

/**
 * This controller class action handlers are automatically called.
 * It handles execution of scheduled tasks, mail queue scheduling included.
 */
class ScheduledTasksController extends AbstractController
{
	/**
	 * Default method for the class, just forwards to autotask
	 *
	 * @return bool
	 */
	public function action_index()
	{
		return $this->action_autotask();
	}

	/**
	 * This method works out what to run
	 *
	 * What it does:
	 *  - It checks if it's time for the next tasks
	 *  - Runs next tasks
	 *  - Update the database for the next round
	 */
	public function action_autotask()
	{
		// The mail queue is also called from here.
		if ($this->http_req->getQuery('scheduled') === 'mailq')
			$this->action_reducemailqueue();
		else
		{
			$GLOBALS['elk']['hooks']->include_hook('autotask_include');

			// Run tasks based on this time stamp
			$ts = $this->http_req->getQuery('ts', 'intval', 0);
			processNextTasks($ts);

			// Get the timestamp stored for the next task, if any.
			$nextTime = nextTime();

			// If there was none, update with defaults
			if ($nextTime === false)
				updateSettings(array('next_task_time' => time() + 86400));
			else
				updateSettings(array('next_task_time' => $nextTime));
		}

		// Return, if we're not explicitly called.
		// @todo remove?
		if (!isset($this->http_req->query->scheduled))
			return true;

		// Finally, send some bland image
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Content-Type: image/gif');
		die("\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\x00\x00\x00\x21\xF9\x04\x01\x00\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3B");
	}

	/**
	 * Reduce mail queue.
	 */
	public function action_reducemailqueue()
	{
		global $modSettings;

		// Lets not waste our time or resources.
		if (empty($modSettings['mail_queue_use_cron']))
		{
			reduceMailQueue();
		}
	}
}