<?php

/**
 * This file contains the post integration of mentions.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Mentions;

abstract class MentionsModuleAbstract implements ModuleInterface
{
	/**
	 * Based on the $action returns the enabled mention types to register to the
	 * event manager.
	 *
	 * @param string $action
	 * @param EventManager $eventsManager
	 * @global $modSettings
	 */
	protected static function registerHooks($action, EventManager $eventsManager)
	{
		global $modSettings;

		if (!empty($modSettings['mentions_enabled']))
		{
			Elk_Autoloader::getInstance()->register(SUBSDIR . '/MentionType', '\\ElkArte\\Sources\\subs\\MentionType');

			$mentions = explode(',', $modSettings['enabled_mentions']);

			foreach ($mentions as $mention)
			{
				$class = '\\ElkArte\\Sources\\subs\\MentionType\\' . ucfirst($mention) . '_Mention';
				$hooks = $class::getEvents($action);

				foreach ($hooks as $method => $dependencies)
				{
					$eventsManager->register($method, array($method, array($class, $action . '_' . $method), $dependencies));
				}
			}
		}
	}
}