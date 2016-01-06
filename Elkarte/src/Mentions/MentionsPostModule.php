<?php

/**
 * This module is attached to the Post action to enable mentions on it.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Mentions;

class MentionsPostModule extends MentionsModuleAbstract
{
	/**
	 * {@inheritdoc }
	 */
	public static function hooks(EventManager $eventsManager)
	{
		self::registerHooks('post', $eventsManager);

		return array();
	}
}