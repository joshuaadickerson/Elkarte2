<?php

/**
 * A template comprised of subtemplates
 * @todo include methods like javascript_escape()
 */
abstract class AbstractTemplate
{
	public $context;
	public $settings;
	public $scripurl;
	public $txt;
	public $user_info;
	public $options;
	protected $class;

	protected $theme;
	protected $container;

	// @todo a container should be passed in here containing the Theme class instead of having Theme as static
	public function __construct($theme, $container)
	{
		$this->theme = $theme;
		$this->container = $container;

		// References in case they change
		// Using properties instead of globals allows the theme author to change them without affecting all templates
		$this->context = &$GLOBALS['context'];
		$this->settings = &$GLOBALS['settings'];
		$this->scripurl = &$GLOBALS['scripurl'];
		$this->txt = &$GLOBALS['txt'];
		$this->user_info = &$GLOBALS['user_info'];
		$this->options = &$GLOBALS['options'];

		// Get the class name for hooks
		$class = explode('\\', __CLASS__);
		$this->class = end($class);
	}

	/**
	 * Call a local template. Should always be used to allow events
	 * 
	 * @param string $name
	 */
	protected function call($name)
	{
		$args = func_get_args();
		$name = array_shift($args);
		$hook = 'subtemplate_' . $this->class . '_' . $name;

		call_integration_hook($hook . '_before', $args);

		$return = call_user_func_array(array($this, $name), $args);

		call_integration_hook($hook . '_after', $args);

		return $return;
	}

	/**
	 * Shortcut function to register a subtemplate as a global subtemplate
	 * @param string $name
	 */
	public function registerSubtemplate($name)
	{
		return $this->theme->registerGlobalSubtemplate($name, array($this, $name));
	}

	/**
	 * Shortcut function to subtemplate
	 */
	public function subtemplate($subtemplate, $template = '*')
	{
		return call_user_func_array(array($this->theme, 'subtemplate'), func_get_args());
	}

	/**
	 * Shortcut to get a global subtemplate
	 * 
	 * @param string $name The subtemplate alias
	 * @param array $args Arguments
	 * @return mixed
	 * @throws \Exception when the global subtemplate cannot be found
	 */
	public function __call($name, $args)
	{
		return call_user_func_array(array($this->theme, 'globalSubtemplate'), array_unshift($args, $name));
	}
}