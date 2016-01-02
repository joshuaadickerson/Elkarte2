<?php

/**
 * Abstract base class for Controllers. Holds action_index and pre_dispatch
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Elkarte\Controller;

use \Elkarte\Event_Manager;

use \Pimple\Container;

/**
 * Abstract base class for Controllers.
 *
 * - Requires a default action handler, action_index().
 * - Defines an empty implementation for pre_dispatch() method.
 */
abstract class AbstractController
{
	/**
	 * @var \Pimple\Container
	 */
	protected $elk;

	/**
	 * The event manager.
	 * @var object
	 */
	protected $_events = null;

	/**
	 * The current hook.
	 * @var string
	 */
	protected $_hook = '';

	/**
	 * Holds instance of HttpReq object
	 * @var HttpReq
	 */
	protected $_req;

	/**
	 * The template layers
	 * @var Template_Layers
	 */
	protected $_layers;

	/**
	 * The actual templates
	 * @var Templates
	 */
	protected $_templates;

	/**
	 * Errors
	 * @var Errors
	 */
	protected $_errors;

	/**
	 * @var Session
	 */
	protected $_session;

	/**
	 * @var array
	 */
	protected $context;

	/**
	 * Constructor...
	 * Requires the name of the controller we want to instantiate, lowercase and
	 * without the "_Controller" part.
	 *
	 * @param Container $elk
	 * @param null|Event_Manager $eventManager - The event manager
	 */
	public function __construct(Container $elk, $eventManager = null)
	{
		$this->elk = $elk;

		// @todo inject these in the constructor arguments
		$this->_layers = $elk['layers'];
		$this->_templates = $elk['templates'];
		$this->_errors = $elk['errors'];
		$this->_req = $elk['http_req'];
		$this->_session = $elk['session'];

		// A safety-net to remain backward compatibility
		if ($eventManager === null)
		{
			$eventManager = new Event_Manager();
		}

		$this->_events = $eventManager;

		// Initialize the events associated with this controller
		$this->_initEventManager();
	}

	/**
	 * Tells if the controller can be displayed as front page.
	 *
	 * @return boolean
	 */
	public static function canFrontPage()
	{
		return in_array('\\ElkArte\\Sources\\Frontpage_Interface', class_implements(get_called_class()));
	}

	/**
	 * {@inheritdoc }
	 */
	public static function frontPageOptions()
	{
		return array();
	}

	/**
	 * {@inheritdoc }
	 */
	public static function validateFrontPageOptions($post)
	{
		return true;
	}

	/**
	 * Initialize the event manager for the controller
	 *
	 * Uses the XXX_Controller name to define the set of event hooks to load
	 */
	protected function _initEventManager()
	{
		// Use the base controller name for the hook, ie post
		$this->_hook = str_replace('_Controller', '', get_class($this));

		// Find any module classes associated with this controller
		$classes = $this->_loadModules();

		// Register any module classes => events we found
		$this->_events->registerClasses($classes);

		$this->_events->setSource($this);
	}

	/**
	 * Public function to return the Controllers generic hook name
	 */
	public function getHook()
	{
		return strtolower($this->_hook);
	}

	/**
	 * Finds modules registered to a certain controller
	 *
	 * What it does:
	 * - Uses the Controllers generic hook name to find modules
	 * - Searches for modules registered against the module name
	 * - Example
	 *   - Display_Controller results in searching for modules registered against modules_display
	 *   - $modSettings['modules_display'] returns drafts,calendar,.....
	 *   - Verifies classes Drafts_Display_Module, Calendar_Display_Module, ... exist
	 *
	 * @return string[] Valid Module Classes for this Controller
	 */
	protected function _loadModules()
	{
		global $modSettings;

		$classes = array();
		$hook = str_replace('_Controller', '', $this->_hook);
		$setting_key = 'modules_' . strtolower($hook);

		// For all the modules that have been registered see if we have a class to load for this hook area
		if (!empty($modSettings[$setting_key]))
		{
			$modules = explode(',', $modSettings[$setting_key]);
			foreach ($modules as $module)
			{
				$class = ucfirst($module) . '_' . ucfirst($hook) . '_Module';
				if (class_exists($class))
				{
					$classes[] = $class;
				}
			}
		}

		return $classes;
	}

	/**
	 * Default action handler.
	 *
	 * What it does:
	 * - This will be called by the dispatcher in many cases.
	 * - It may set up a menu, sub-dispatch at its turn to the method matching ?sa= parameter
	 * or simply forward the request to a known default method.
	 */
	abstract public function action_index();

	/**
	 * Called before any other action method in this class.
	 *
	 * - Allows for initializations, such as default values or
	 * loading templates or language files.
	 */
	public function pre_dispatch()
	{
		// By default, do nothing.
		// Sub-classes may implement their prerequisite loading,
		// such as load the template, load the language(s) file(s)
	}

	/**
	 * An odd function that allows events to request dependencies from properties
	 * of the class.
	 *
	 * @param string $dep - The name of the property the even wants
	 * @param mixed[] $dependencies - the array that will be filled with the
	 *                                references to the dependencies
	 */
	public function provideDependencies($dep, &$dependencies)
	{
		if (property_exists($this, $dep))
		{
			$dependencies[$dep] = &$this->$dep;
		}
		elseif (property_exists($this, '_' . $dep))
		{
			$dependencies[$dep] = &$this->{'_' . $dep};
		}
		elseif (array_key_exists($dep, $GLOBALS))
		{
			$dependencies[$dep] = &$GLOBALS[$dep];
		}
	}

	/**
	 * Shortcut to register an array of names as events triggered at a certain
	 * position in the code.
	 *
	 * @param string $name - Name of the trigger where the events will be executed.
	 * @param string $method - The method that will be executed.
	 * @param string[] $to_register - An array of classes to register.
	 */
	protected function _registerEvent($name, $method, $to_register)
	{
		foreach ($to_register as $class)
		{
			$this->_events->register($name, array($name, array($class, $method, 0)));
		}
	}

	// @todo move is_not_guest() to here
	protected function isNotGuest($message = '', $is_fatal = true)
	{
		global $user_info, $txt, $context, $scripturl;

		// Luckily, this person isn't a guest.
		if (isset($user_info['is_guest']) && !$user_info['is_guest'])
			return true;

		// People always worry when they see people doing things they aren't actually doing...
		$_GET['action'] = '';
		$_GET['board'] = '';
		$_GET['topic'] = '';
		writeLog(true);

		// Just die.
		if (isset($_REQUEST['xml']) || !$is_fatal)
			obExit(false);

		// Attempt to detect if they came from dlattach.
		if (ELK != 'SSI' && empty($context['theme_loaded']))
			loadTheme();

		// Never redirect to an attachment
		if (strpos($_SERVER['REQUEST_URL'], 'dlattach') === false)
			$_SESSION['login_url'] = $_SERVER['REQUEST_URL'];

		// Load the Login template and language file.
		loadLanguage('Login');

		// Apparently we're not in a position to handle this now. Let's go to a safer location for now.
		if (!$this->_layers->hasLayers())
		{
			$_SESSION['login_url'] = $scripturl . '?' . $_SERVER['QUERY_STRING'];
			redirectexit('action=login');
		}
		elseif (isset($_GET['api']))
			return false;
		else
		{
			$this->_templates->load('Login');
			loadJavascriptFile('sha256.js', array('defer' => true));
			$context['sub_template'] = 'kick_guest';
			$context['robot_no_index'] = true;
		}

		// Use the kick_guest sub template...
		$context['kick_message'] = $message;
		$context['page_title'] = $txt['login'];

		obExit();

		// We should never get to this point, but if we did we wouldn't know the user isn't a guest.
		trigger_error('Hacking attempt...', E_USER_ERROR);
	}

	public function db()
	{
		return $GLOBALS['elk']['db'];
	}
}