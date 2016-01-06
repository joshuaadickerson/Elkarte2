<?php

/**
 * This file contains the integration of mentions into DisplayController.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Mentions;

class MentionsDisplayModule extends MentionsModuleAbstract
{
	/**
	 * {@inheritdoc }
	 */
	public static function hooks(EventManager $eventsManager)
	{
		self::registerHooks('display', $eventsManager);

		return array();
	}
}