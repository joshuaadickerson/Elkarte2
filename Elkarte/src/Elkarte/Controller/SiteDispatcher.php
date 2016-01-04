<?php

namespace Elkarte\Elkarte\Controller;

/**
 * Primary site dispatch controller, sends the request to the function or method
 * registered to handle it.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */
use Elkarte\Elkarte\Events\EventManager;
use Elkarte\Elkarte\Events\Hooks;

/**
 * Dispatch the request to the function or method registered to handle it.
 *
 * What it does:
 * - Try first the critical functionality (maintenance, no guest access)
 * - Then, in order:
 *     * forum's main actions: board index, message index, display topic
 *       the current/legacy file/functions registered by ElkArte core
 * - Fall back to naming patterns:
 *     * filename=[action].php function=[sa]
 *     * filename=[action].controller.php method=action_[sa]
 *     * filename=[action]-Controller.php method=action_[sa]
 * - An addon files to handle custom actions will be called if they follow
 * any of these patterns.
 */
class SiteDispatcher
{
	/**
	 * @var \Pimple\Container
	 */
	protected $elk;

	/**
	 * Function or method to call
	 * @var string
	 */
	protected $_function_name;

	/**
	 * Class name, for object oriented Controllers
	 * @var string
	 */
	protected $_controller_name;

	/**
	 * The default action data (controller and function)
	 * @var string[]
	 */
	protected $_default_action;

	protected $hooks;

	/**
	 * Create an instance and initialize it.
	 *
	 * This does all the work to figure out which controller and method need
	 * to be called.
	 */
	public function __construct($elk, Hooks $hooks)
	{
		global $board, $topic, $modSettings, $user_info, $maintenance;

		$this->elk = $elk;
		$this->hooks = $hooks;

		// Default action of the forum: board index
		// Every time we don't know what to do, we'll do this :P
		$this->_default_action = array(
			'controller' => 'Elkarte\\Boards\\BoardIndexController',
			'function' => 'action_boardindex'
		);

		// Reminder: hooks need to account for multiple addons setting this hook.
		$this->hooks->hook('action_frontpage', array(&$this->_default_action));

		// Maintenance mode: you're out of here unless you're Admin
		if (!empty($maintenance) && !allowedTo('admin_forum'))
		{
			// You can only login
			if (isset($_GET['action']) && ($_GET['action'] == 'login2' || $_GET['action'] == 'logout'))
			{
				$this->_controller_name = 'AuthController';
				$this->_function_name = $_GET['action'] == 'login2' ? 'action_login2' : 'action_logout';
			}
			// "maintenance mode" page
			else
			{
				$this->_controller_name = 'AuthController';
				$this->_function_name = 'action_maintenance_mode';
			}
		}
		// If guest access is disallowed, a guest is kicked out... politely. :P
		elseif (empty($modSettings['allow_guestAccess']) && $user_info['is_guest'] && (!isset($_GET['action']) || !in_array($_GET['action'], array('login', 'login2', 'register', 'reminder', 'help', 'quickhelp', 'mailq', 'openidreturn'))))
		{
			$this->_controller_name = 'AuthController';
			$this->_function_name = 'action_kickguest';
		}
		elseif (empty($_GET['action']))
		{
			// Home page: board index
			if (empty($board) && empty($topic))
			{
				// Was it, wasn't it....
				if (empty($this->_function_name))
				{
					$this->_controller_name = $this->_default_action['controller'];
					$this->_function_name = $this->_default_action['function'];
				}
			}
			// ?board=b message index
			elseif (empty($topic))
			{
				$this->_controller_name = 'Elkarte\\Topics\\MessageIndexController';
				$this->_function_name = 'action_messageindex';
			}
			// board=b;topic=t topic display
			else
			{
				$this->_controller_name = 'Elkarte\\Messages\\DisplayController';
				$this->_function_name = 'action_display';
			}
		}

		// Now this return won't be cool, but lets do it
		if (!empty($this->_controller_name) && !empty($this->_function_name))
			return;

		// Start with our nice and cozy err... *cough*
		// Format:
		// $_GET['action'] => array($class, $method)
		$actionArray = require_once('Actions.php');

		$adminActions = array('Admin', 'jsoption', 'theme', 'viewadminfile', 'viewquery');

		// Allow to extend or change $actionArray through a hook
		$this->hooks->hook('actions', array(&$actionArray, &$adminActions));

		// Is it in core legacy actions?
		if (isset($actionArray[$_GET['action']]))
		{
			$this->_controller_name = $actionArray[$_GET['action']][0];

			// If the method is coded in, use it
			if (!empty($actionArray[$_GET['action']][1]))
				$this->_function_name = $actionArray[$_GET['action']][1];
			// Otherwise fall back to naming patterns
			elseif (isset($_GET['sa']) && preg_match('~^\w+$~', $_GET['sa']))
				$this->_function_name = 'action_' . $_GET['sa'];
			else
				$this->_function_name = 'action_index';
		}
		// Fall back to naming patterns.
		// addons can use any of them, and it should Just Work (tm).
		elseif (preg_match('~^[a-zA-Z_\\-]+\d*$~', $_GET['action']))
		{
			// Admin files have their own place
			$path = in_array($_GET['action'], $adminActions) ? ADMINDIR : CONTROLLERDIR;

			// action=gallery => Gallery.controller.php
			// sa=upload => action_upload()
			if (file_exists($path . '/' . ucfirst($_GET['action']) . '.php'))
			{
				$this->_controller_name = ucfirst($_GET['action']) . 'Controller';
				if (isset($_GET['sa']) && preg_match('~^\w+$~', $_GET['sa']) && !isset($_GET['area']))
					$this->_function_name = 'action_' . $_GET['sa'];
				else
					$this->_function_name = 'action_index';
			}
		}

		// The file and function weren't found yet?
		if (empty($this->_controller_name) || empty($this->_function_name))
		{
			// We still haven't found what we're looking for...
			$this->_controller_name = $this->_default_action['controller'];
			$this->_function_name = $this->_default_action['function'];
		}

		if (isset($_REQUEST['api']))
			$this->_function_name .= '_api';
	}

	/**
	 * Relay control to the respective function or method.
	 */
	public function dispatch()
	{
		if (!empty($this->_controller_name))
		{
			//$controller_class = 'Elkarte\\Controllers\\' . $this->_controller_name;
			$controller_class = $this->_controller_name;

			if (!class_exists($controller_class))
			{
				throw new \Exception('SiteDispatcher controller not found: ' . $controller_class);
			}

			// 3, 2, ... and go
			if (is_callable(array($controller_class, $this->_function_name)))
			{
				$method = $this->_function_name;
			}
			elseif (is_callable(array($controller_class, 'action_index')))
			{
				$method = 'action_index';
			}
			// This should never happen, that's why its here :P
			else
			{
				$this->_controller_name = $this->_default_action['controller'];
				$this->_function_name = $this->_default_action['function'];

				return $this->dispatch();
			}

			// Initialize this controller with its event manager
			$controller = new $controller_class($this->elk, new EventManager());

			// Fetch Controllers generic hook name from the action controller
			$hook = $controller->getHook();

			// Call the Controllers pre dispatch method
			$controller->pre_dispatch();

			// Call integrate_action_XYZ_before -> XYZ_controller -> integrate_action_XYZ_after
			$this->hooks->hook('action_' . $hook . '_before', array($this->_function_name));

			$result = $controller->$method();

			$this->hooks->hook('action_' . $hook . '_after', array($this->_function_name));

			return $result;
		}
		// Things went pretty bad, huh?
		else
		{
			// default action :P
			$this->_controller_name = $this->_default_action['controller'];
			$this->_function_name = $this->_default_action['function'];

			return $this->dispatch();
		}
	}

	/**
	 * Returns the current action for the system
	 *
	 * @return string
	 */
	public function site_action()
	{
		if (!empty($this->_controller_name))
		{
			$action  = strtolower(str_replace('Controller', '', $this->_controller_name));
			$action = substr($action, -1) == 2 ? substr($action, 0, -1) : $action;
		}

		return isset($action) ? $action : '';
	}
}