<?php

/**
 * Extension of the default Exception class to handle Controllers redirection.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Controller;

/**
 * In certain cases a module of a controller may want to "redirect" to another
 * controller (e.g. from Calendar to Post).
 * This exception class catches these "redirects", then instantiate a new controller
 * taking into account loading of addons and pre_dispatch and returns.
 */
class ControllerRedirectException extends \Exception
{
	protected $_controller;
	protected $_method;

	/**
	 * Redefine the initialization.
	 * Do note that parent::__construct() is not called.
	 *
	 * @param string $controller Is the name of controller (lowercase and without "Controller",
	 *                 for example 'post', or 'calendar') that should be instantiated
	 * @param string $method The method to call.
	 */
	public function __construct($controller, $method)
	{
		$this->_controller = $controller;
		$this->_method = $method;
	}

	/**
	 * Takes care of doing the redirect to the other controller.
	 *
	 * @param object $source The controller object that called the method
	 *                ($this in the calling class)
	 * @return The return of the method called.
	 */
	public function doRedirect($source)
	{
		if (get_class($source) === $this->_controller)
			return $source->{$this->_method}();

		$controller = new $this->_controller(new EventManager());
		$controller->pre_dispatch();

		return $controller->{$this->_method}();
	}
}