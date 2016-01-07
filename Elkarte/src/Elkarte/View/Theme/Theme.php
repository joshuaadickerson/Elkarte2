<?php

/**
 * The theme class which holds all of the instances of the templates
 * @todo This would be even better if it used lambda functions for registration so that not all of the templates need to be instantiated at the same time
 * @todo maybe rename this to TemplateManager and have the View call render()
 * 
 * Not shown here is all that is required to make this a singleton.
 * If there was a container class it wouldn't need to be, but that's the way it is setup now
 */
class Theme
{
	const GLOBAL_TEMPLATE = '*';

	/** Template classes */
	protected $templates = array();
	/** Global subtemplates */
	protected $subtemplates = array();
	
	public $template_dirs = array();

	protected $css_files = array('index.css');
	protected $js_files = array();

	public $default_theme_dir;

	protected $included_files = array();
	protected $container;
	

	/**
	 * You are not allowed to name your templates any of these.
	 */
	public $reserved_templates = array(
		'postRegister',
		'__construct',
		'call',
		'register_subtemplate',
		'subtemplate',
		'__call',
	);

	public function __construct($container)
	{
		$this->container = $container;

		// @todo no reason for this to be here or even in this file.
		// We want to be able to figure out any errors...
		@ini_set('track_errors', '1');
	}

	/**
	 * After everything is setup... output it.
	 */
	public function render($header = null, $do_footer = null, $from_fatal_error = false)
	{
		global $context, $settings, $modSettings, $txt;

		static $header_done = false, $footer_done = false, $level = 0, $has_fatal_error = false;

		// Attempt to prevent a recursive loop.
		++$level;
		if ($level > 1 && !$from_fatal_error && !$has_fatal_error)
			exit;
		if ($from_fatal_error)
			$has_fatal_error = true;

		$do_header = $header === null ? !$header_done : $header;
		if ($do_footer === null)
			$do_footer = $do_header;

		// Has the template/header been done yet?
		if ($do_header)
		{
			// Was the page title set last minute? Also update the HTML safe one.
			if (!empty($context['page_title']) && empty($context['page_title_html_safe']))
				$context['page_title_html_safe'] = Util::htmlspecialchars(un_htmlspecialchars($context['page_title'])) . (!empty($context['current_page']) ? ' - ' . $txt['page'] . ' ' . ($context['current_page'] + 1) : '');

			// Start up the session URL fixer.
			ob_start('ob_sessrewrite');

			if (!empty($settings['output_buffers']) && is_string($settings['output_buffers']))
				$buffers = explode(',', $settings['output_buffers']);
			elseif (!empty($settings['output_buffers']))
				$buffers = $settings['output_buffers'];
			else
				$buffers = array();

			if (isset($modSettings['integrate_buffer']))
				$buffers = array_merge(explode(',', $modSettings['integrate_buffer']), $buffers);

			if (!empty($buffers))
			{
				foreach ($buffers as $function)
				{
					$function = trim($function);
					$call = strpos($function, '::') !== false ? explode('::', $function) : $function;

					// Is it valid?
					if (is_callable($call))
						ob_start($call);
				}
			}

			// Display the screen in the logical order.
			template_header();
			$header_done = true;
		}

		if ($do_footer)
		{
			// Show the footer.
			loadSubTemplate(isset($context['sub_template']) ? $context['sub_template'] : 'main');

			// Just so we don't get caught in an endless loop of errors from the footer...
			if (!$footer_done)
			{
				$footer_done = true;
				template_footer();

				// (since this is just debugging... it's okay that it's after </html>.)
				if (!isset($_REQUEST['xml']))
					displayDebug();
			}
		}

		// Need user agent
		$req = request();

		$this->saveLastPage();

		// Hand off the output to the portal, etc. we're integrated with.
		call_integration_hook('theme.render', array($do_footer));
	}

	// @todo move to controller
	public function saveLastPage()
	{
		// Remember this URL in case someone doesn't like sending HTTP_REFERER.
		if (strpos($_SERVER['REQUEST_URL'], 'action=dlattach') === false && strpos($_SERVER['REQUEST_URL'], 'action=viewadminfile') === false)
			$_SESSION['old_url'] = $_SERVER['REQUEST_URL'];

		// For session check verification.... don't switch browsers...
		$_SESSION['USER_AGENT'] = $req->user_agent();
	}

	/**
	 * Used to combine JS, CSS, and sprite images
	 * @param $asset_manager
	 */
	public function setAssetManager($asset_manager)
	{
		// @todo this is what it should do
		if (!empty($modSettings['minify_css_js']))
		{
			require_once(SOURCEDIR . '/Combine.class.php');
			$combiner = new Site_Combiner(CACHEDIR, $boardurl . '/cache');
			$combine_name = $combiner->site_css_combine($context['css_files']);
		}
		if (!empty($modSettings['minify_css_js']))
		{
			require_once(SOURCEDIR . '/Combine.class.php');
			$combiner = new Site_Combiner(CACHEDIR, $boardurl . '/cache');
			$combine_name = $combiner->site_js_combine($context['javascript_files'], $do_defered);
		}
	}

	/**
	 * Find a template file
	 * If it can't be found, give the admin a warning
	 * 
	 * @param string $template
	 * @return string|boolean
	 */
	public function findTemplateFile($template)
	{
		global $settings, $context, $scripturl, $txt;

        foreach ($this->template_dirs as $template_dir)
        {
			$filename = $template_dir . '/' . $template . '.template.php';
			if (file_exists($filename))
			{
				return $filename;
			}
        }

		// Hmmm... doesn't exist?!  I don't suppose the directory is wrong, is it?
		if (!file_exists($settings['default_theme_dir']) && file_exists(BOARDDIR . '/themes/default'))
        {
			$settings['default_theme_dir'] = BOARDDIR . '/themes/default';
			$this->template_dirs[] = $settings['default_theme_dir'];

			// Ruh roh, Shaggy, we can't find the template!
			if (!empty($context['user']['is_admin']) && !isset($_GET['th']))
			{
				loadLanguage('Errors');
				$context['security_controls']['files']['theme_dir'] = '<a href="' . $scripturl . '?action=admin;area=theme;sa=list;th=1;' . $context['session_var'] . '=' . $context['session_id'] . '">' . $txt['theme_dir_wrong'] . '</a>';
				;
			}

			// Go recursive
			return $this->findTemplateFile($template);
		}

		return false;
	}

	public function loadTemplate($template_name, $fatal = true)
	{
		global $context, $txt, $db_show_debug;

		// Allow the class name to be different from the template name
		$class_name = $template_name . 'Template';

		// Give you the opportunity to overload the class
		call_integration_hook('template.load', array($template_name, &$class_name));

		$filename = $this->findTemplateFile($template_name);
		if ($filename)
		{
			$this->template_include($filename, true);

			// @todo each class should register itself, not like this
			if ($db_show_debug === true)
				$context['debug']['templates'][] = $template_name . ' (' . basename($filename) . ')';

			// Register the new template and instatiate the class
			$this->registerTemplate($template_name, new $class_name($this));
        }
        // Cause an error otherwise.
        elseif ($template_name !== 'Errors' && $template_name !== 'index' && $fatal)
		{
			fatal_lang_error('theme_template_error', 'template', array((string) $template_name));
		}
        elseif ($fatal)
		{
			die(log_error(sprintf(isset($txt['theme_template_error']) ? $txt['theme_template_error'] : 'Unable to load themes/default/%s.template.php!', (string) $template_name), 'template'));
		}
        else
		{
			return false;
		}
	}

	/**
	 * Load the template/language file using eval or require? (with eval we can show an error message!)
	 *  - loads the template or language file specified by filename.
	 *  - uses eval unless disableTemplateEval is enabled.
	 *  - outputs a parse error if the file did not exist or contained errors.
	 *  - attempts to detect the error and line, and show detailed information.
	 *
	 * @param string $filename
	 * @param bool $once = false, if true only includes the file once (like include_once)
	 */
	public function template_include($filename, $once = false)
	{
		global $settings, $modSettings;

		// Don't include the file more than once, if $once is true.
		if ($once && in_array($filename, $this->included_files))
			return;
		// Add this file to the include list, whether $once is true or not.
		else
			$this->included_files[] = $filename;

		$file_found = file_exists($filename);

		// No file... bad news bears.
		if ($file_found !== true)
		{
			// @todo I just want to get this out of this file.
			require_once __DIR__ . '/MissingTemplate.php';
			$missing_template = new MissingTemplate;
			$missing_template->noTemplate();
		}

		// Are we going to use eval?
		if (empty($modSettings['disableTemplateEval']))
		{
			$file_found = $this->templateEval(file_get_contents($filename)) !== false;
			$settings['current_include_filename'] = $filename;
		}
		else
		{
			if ($once)
				require_once($filename);
			else
				require($filename);
		}
	}

	protected function templateEval($contents)
	{
		return eval('?' . '>' . rtrim($contents));
	}

	/**
	 * Add a CSS file for output later
	 *
	 * @param string $filename
	 * @param array $params = array()
	 * Keys are the following:
	 *  - ['local'] (true/false): define if the file is local
	 *  - ['fallback'] (true/false): if false  will attempt to load the file from the default theme if not found in the current theme
	 *  - ['stale'] (true/false/string): if true or null, use cache stale, false do not, or used a supplied string
	 *
	 * @param string $id = ''
	 */
	public function loadCSSFile($filenames, $params = array(), $id = '')
	{
		global $settings, $context, $db_show_debug;

		if (empty($filenames))
			return;

		if (!is_array($filenames))
			$filenames = array($filenames);

		// static values for all these settings
		$params['stale'] = (!isset($params['stale']) || $params['stale'] === true) ? '?beta10' : (is_string($params['stale']) ? ($params['stale'] = $params['stale'][0] === '?' ? $params['stale'] : '?' . $params['stale']) : '');
		$params['fallback'] = (!empty($params['fallback']) && ($params['fallback'] === false)) ? false : true;

		// Whoa ... we've done this before yes?
		$cache_name = 'load_css_' . md5($settings['theme_dir'] . implode('_', $filenames));
		if (($temp = cache_get_data($cache_name, 600)) !== null)
		{
			if (empty($this->css_files))
				$this->css_files = array();
			$this->css_files += $temp;
		}
		else
		{
			// All the files in this group use the parameters as defined above
			foreach ($filenames as $filename)
			{
				// account for shorthand like admin.css?xyz11 filenames
				$has_cache_staler = strpos($filename, '.css?');
				$params['basename'] = $has_cache_staler ? substr($filename, 0, $has_cache_staler + 4) : $filename;
				$this_id = empty($id) ? strtr(basename($filename), '?', '_') : $id;

				// Is this a local file?
				if (substr($filename, 0, 4) !== 'http' || !empty($params['local']))
				{
					$params['local'] = true;
					$params['dir'] = $settings['theme_dir'] . '/css/';
					$params['url'] = $settings['theme_url'];

					// Fallback if needed?
					if ($params['fallback'] && ($settings['theme_dir'] !== $settings['default_theme_dir']) && !file_exists($settings['theme_dir'] . '/css/' . $filename))
					{
						// Fallback if we are not already in the default theme
						if (file_exists($settings['default_theme_dir'] . '/css/' . $filename))
						{
							$filename = $settings['default_theme_url'] . '/css/' . $filename . ($has_cache_staler ? '' : $params['stale']);
							$params['dir'] = $settings['default_theme_dir'] . '/css/';
							$params['url'] = $settings['default_theme_url'];
						}
						else
							$filename = false;
					}
					else
						$filename = $settings['theme_url'] . '/css/' . $filename . ($has_cache_staler ? '' : $params['stale']);
				}

				// Add it to the array for use in the template
				if (!empty($filename))
					$this->css_files[$this_id] = array('filename' => $filename, 'options' => $params);

				if ($db_show_debug === true)
					$context['debug']['sheets'][] = $params['basename'] . (!empty($params['url']) ? '(' . basename($params['url']) . ')' : '');
			}

			// Save this build
			cache_put_data($cache_name, $this->css_files, 600);
		}
	}

	/**
	 * Add a Javascript file for output later
	 *
	 * Can be passed an array of filenames, all which will have the same parameters applied, if you
	 * need specific parameters on a per file basis, call it multiple times
	 *
	 * @param array $filenames
	 * @param array $params = array()
	 * Keys are the following:
	 *  - ['local'] (true/false): define if the file is local
	 *  - ['defer'] (true/false): define if the file should load in <head> or before the closing <html> tag
	 *  - ['fallback'] (true/false): if true will attempt to load the file from the default theme if not found in the current
	 *  - ['async'] (true/false): if the script should be loaded asynchronously (HTML5)
	 *  - ['stale'] (true/false/string): if true or null, use cache stale, false do not, or used a supplied string
	 *
	 * @param string $id = ''
	 */
	public function loadJavascriptFile($filenames, $params = array(), $id = '')
	{
		global $settings, $context, $db_show_debug;

		if (empty($filenames))
			return;

		if (!is_array($filenames))
			$filenames = array($filenames);

		// static values for all these files
		$params['stale'] = (!isset($params['stale']) || $params['stale'] === true) ? '?beta10' : (is_string($params['stale']) ? ($params['stale'] = $params['stale'][0] === '?' ? $params['stale'] : '?' . $params['stale']) : '');
		$params['fallback'] = (!empty($params['fallback']) && ($params['fallback'] === false)) ? false : true;

		// dejvu?
		$cache_name = 'load_js_' . md5($settings['theme_dir'] . implode('_', $filenames));
		if (($temp = cache_get_data($cache_name, 600)) !== null)
		{
			if (empty($this->js_files))
				$this->js_files = array();
			$this->js_files += $temp;
		}
		else
		{
			// All the files in this group use the above parameters
			foreach ($filenames as $filename)
			{
				// account for shorthand like admin.js?xyz11 filenames
				$has_cache_staler = strpos($filename, '.js?');
				$params['basename'] = $has_cache_staler ? substr($filename, 0, $has_cache_staler + 3) : $filename;
				$this_id = empty($id) ? strtr(basename($filename), '?', '_') : $id;

				// Is this a local file?
				if (substr($filename, 0, 4) !== 'http' || !empty($params['local']))
				{
					$params['local'] = true;
					$params['dir'] = $settings['theme_dir'] . '/scripts/';
					$params['url'] = $settings['theme_url'];

					// Fallback if we are not already in the default theme
					if ($params['fallback'] && ($settings['theme_dir'] !== $settings['default_theme_dir']) && !file_exists($settings['theme_dir'] . '/scripts/' . $filename))
					{
						// can't find it in this theme, how about the default?
						if (file_exists($settings['default_theme_dir'] . '/scripts/' . $filename))
						{
							$filename = $settings['default_theme_url'] . '/scripts/' . $filename . ($has_cache_staler ? '' : $params['stale']);
							$params['dir'] = $settings['default_theme_dir'] . '/scripts/';
							$params['url'] = $settings['default_theme_url'];
						}
						else
							$filename = false;
					}
					else
						$filename = $settings['theme_url'] . '/scripts/' . $filename . ($has_cache_staler ? '' : $params['stale']);
				}

				// Add it to the array for use in the template
				if (!empty($filename))
				{
					$this->js_files[$this_id] = array('filename' => $filename, 'options' => $params);

					if ($db_show_debug === true)
						$context['debug']['javascript'][] = $params['basename'] . '(' . (!empty($params['local']) ? (!empty($params['url']) ? basename($params['url']) : basename($params['dir'])) : '') . ')';
				}
			}

			// Save it so we don't have to build this so often
			cache_put_data($cache_name, $this->js_files, 600);
		}

		return $this;
	}

	/**
	 * Add a Javascript variable for output later (for feeding text strings and similar to JS)
	 * Cleaner and easier (for modders) than to use the function below.
	 *
	 * @param string $key
	 * @param string $value
	 * @param bool $escape = false, whether or not to escape the value
	 */
	public function addJavascriptVar($vars, $escape = false)
	{
		global $context;

		if (empty($vars) || !is_array($vars))
			return;

		foreach ($vars as $key => $value)
			$context['javascript_vars'][$key] = !empty($escape) ? JavaScriptEscape($value) : $value;

		return $this;
	}

	/**
	 * Add a block of inline Javascript code to be executed later
	 *
	 * - only use this if you have to, generally external JS files are better, but for very small scripts
	 *   or for scripts that require help from PHP/whatever, this can be useful.
	 * - all code added with this function is added to the same <script> tag so do make sure your JS is clean!
	 *
	 * @param string $javascript
	 * @param bool $defer = false, define if the script should load in <head> or before the closing <html> tag
	 */
	public function addInlineJavascript($javascript, $defer = false)
	{
		global $context;

		if (!empty($javascript))
			$context['javascript_inline'][(!empty($defer) ? 'defer' : 'standard')][] = $javascript;
	}

	/**
	 * @param string $alias What it will be referred to as
	 * @param callable $class An instance of the class
	 */
	public function registerTemplate($alias, TemplateInstance $class)
	{
		$this->templates[$alias] = $class;

		// Don't crowd the constructor, but make it possible to do things after registering
		if (is_callable(array($class, 'postRegister')))
		{
			$class->postRegister($this);
		}
	}

	/**
	 * Removes a template from the list
	 * @param string $aliases
	 */
	public function unregisterTemplate($aliases)
	{
		call_integration_hook('template.unregister_before', array($aliases));
		if ($aliases === '*')
		{
			$this->templates = array();
		}

		$alias = is_array($alias) ? $alias : array($alias);
		foreach ($aliases as $alias)
		{
			if (isset($this->templates[$alias]))
			{
				unset($this->templates[$alias]);
			}
		}

		call_integration_hook('template.unregister_after', array($aliases));

		return $this;
	}

	/**
	 * Get all of the subtemplates of a template
	 * @param string $name Template alias
	 * @return array
	 */
	public function getSubtemplates($name)
	{
		if ($name === self::GLOBAL_TEMPLATE)
			return $this->subtemplates;

		// The template must be registered first
		if (!isset($this->templates[$name]))
			throw new Exception('Template "' . $name . '" not registered');

		// If the template has a method for this, use that
		if (is_callable($this->templates[$name]->getSubtemplates()))
			$templates = $this->templates[$name]->getSubtemplates();
		else
			$templates = get_class_methods($this->templates[$name]);

		// Not all methods are subtemplates
		return array_diff($templates, $this->reserved_templates);
	}

	public function getGlobalSubtemplates()
	{
		return $this->subtemplates;
	}

	/**
	 * Get an instance of the template
	 * @param string $class The class alias
	 * @return Template|false
	 */
	public function template($class)
	{
		if ($class === self::GLOBAL_TEMPLATE)
			return false;

		return isset($this->templates[$class]) ? $this->templates[$class] : false;
	}

	/**
	 * Call a subtemplate
	 * @param string $subtemplate The subtemplate alias/name
	 * @param string $template = '*' The template or * for global subtemplates
	 * @return mixed
	 */
	public function subtemplate($subtemplate, $template = self::GLOBAL_TEMPLATE)
	{
		$args = func_get_args();
		$subtemplate = array_shift($args);
		$template = array_shift($args);

		// The base name of the hook (+ before/after)
		$hook = 'subtemplate_' . $template . '_' . $subtemplate;

		// Add templates before a subtemplate call
		call_integration_hook($hook . '_before', $args);

		// A global subtemplate request
		if ($template === self::GLOBAL_TEMPLATE)
		{
			if (!isset($this->subtemplates[$subtemplate]))
			{
				throw new Exception('Global subtemplate "' . $subtemplate . '" not registered');
			}

			$return = call_user_func_array($this->subtemplates[$subtemplate], $args);
		}
		else
		{
			// The template must be registered first
			if (!isset($this->templates[$template]))
				throw new Exception('Template "' . $template . '" not registered');

			$return = call_user_func_array(array($this->templates[$template], $subtemplate), $args);
		}

		// Add templates after a subtemplate call
		call_integration_hook($hook . '_after', $args);

		return $return;
	}

	/**
	 * Calls subtemplate with the global template
	 * @param string $subtemplate
	 * @return mixed
	 */
	public function globalSubtemplate($subtemplate)
	{
		$args = func_get_args();
		$subtemplate = array_shift($args);

		if (!isset($this->subtemplates[$subtemplate]))
			throw new Exception('Global subtemplate "' . $subtemplate . '" not registered');

		return call_user_func_array($this->subtemplates[$subtemplate], $args);
	}

	/**
	 * Registers a global subtemplate
	 * @param string $name
	 * @param callable $callable The method callback
	 */
	public function registerGlobalSubtemplate($name, $callable)
	{
		if (!is_callable($callable))
			throw new Exception('Global subtemplate must be callable');

		$this->subtemplates[$name] = $callable;
	}
}