<?php

/**
 * Most of the "models" require some common stuff (like a constructor).
 * Here it is.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Elkarte\Controller;

/**
 * Implementing this interface will make Controllers usable as a front page
 * replacing the classic board index.
 */
interface FrontpageInterface
{
	/**
	 * Used to attach integrate_action_frontpage hook, to change the default action.
	 *
	 * - e.g. $GLOBALS['elk']['hooks']->add('action_frontpage', 'ControllerName::frontPageHook');
	 *
	 * @param string[] $default_action
	 */
	public static function frontPageHook(&$default_action);

	/**
	 * Used to define the parameters the controller may need for the front page
	 * action to work
	 *
	 * - e.g. specify a topic ID or a board listing
	 *
	 * @return mixed[]
	 */
	public static function frontPageOptions();

	/**
	 * Used to define the parameters the controller may need for the front page
	 * action to work
	 *
	 * - e.g. specify a topic ID
	 * - should return true or false based on if its able to show the front page
	 *
	 * @param Object $post
	 */
	public static function validateFrontPageOptions($post);
}