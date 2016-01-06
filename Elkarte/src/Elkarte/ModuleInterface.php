<?php

/**
 * Interface for modules.
 * Actually is just a way to write the hooks method documentation only once.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Elkarte;

use Elkarte\Elkarte\Events\EventManager;

interface ModuleInterface
{
	/**
	 * The method called by the EventManager to find out which trigger the
	 * module is attached to and which parameters the listener wants to receive.
	 *
	 * @param EventManager $eventsManager an instance of the event manager
	 *
	 * @return mixed[]
	 */
	public static function hooks(EventManager $eventsManager);
}