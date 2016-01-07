<?php

class CommonTemplate extends AbstractTemplate
{
	protected $jquery_cdn = 'https://ajax.googleapis.com/ajax/libs/jqueryui/';

	public function postRegister($theme)
	{
		global $settings;
		
		// @todo is this needed?
		if (isset($settings['use_default_images']) && $settings['use_default_images'] == 'defaults' && isset($settings['default_template']))
		{
			$settings['theme_url'] = $settings['default_theme_url'];
			$settings['images_url'] = $settings['default_images_url'];
			$settings['theme_dir'] = $settings['default_theme_dir'];
		}
	}

	/**
	 * This is the only template included in the sources.
	 */
	public function rawdata()
	{
		global $context;

		echo $context['raw_data'];
	}

	public function httpHeaders()
	{
		global $context;
		// Print stuff to prevent caching of pages (except on attachment errors, etc.)
		if (empty($context['no_last_modified']))
		{
			header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
			header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

			// Are we debugging the template/html content?
			if ((!isset($_REQUEST['xml']) || !isset($_REQUEST['api'])) && isset($_GET['debug']) && !isBrowser('ie'))
				header('Content-Type: application/xhtml+xml');
			elseif (!isset($_REQUEST['xml']) || !isset($_REQUEST['api']))
				header('Content-Type: text/html; charset=UTF-8');
		}

		// Probably temporary ($_REQUEST['xml'] should be replaced by $_REQUEST['api'])
		if (isset($_REQUEST['api']) && $_REQUEST['api'] == 'json')
			header('Content-Type: application/json; charset=UTF-8');
		elseif (isset($_REQUEST['xml']) || isset($_REQUEST['api']))
			header('Content-Type: text/xml; charset=UTF-8');
		else
			header('Content-Type: text/html; charset=UTF-8');
	}

	/**
	 * The header template
	 */
	public function header()
	{
		$this->call('httpHeaders');

		foreach (Template_Layers::getInstance()->prepareContext() as $layer)
			loadSubTemplate($layer . '_above', 'ignore');
	}

	/**
	 * Show the copyright.
	 */
	public function copyright()
	{
		global $forum_copyright, $forum_version;

		// Don't display copyright for things like SSI.
		if (!isset($forum_version))
			return;

		// Put in the version...
		// @todo - No necessity for inline CSS in the copyright, and better without it.
		$forum_copyright = sprintf($forum_copyright, ucfirst(strtolower($forum_version)));

		echo '
						<span class="smalltext" style="display: inline; visibility: visible; font-family: Verdana, Arial, sans-serif;">', $forum_copyright, '
						</span>';
	}

	/**
	 * The template footer
	 */
	public function footer()
	{
		global $context, $settings, $modSettings, $time_start, $db_count;

		// Show the load time?  (only makes sense for the footer.)
		$context['show_load_time'] = !empty($modSettings['timeLoadPageEnable']);
		$context['load_time'] = round(microtime(true) - $time_start, 3);
		$context['load_queries'] = $db_count;

		if (isset($settings['use_default_images']) && $settings['use_default_images'] == 'defaults' && isset($settings['default_template']))
		{
			$settings['theme_url'] = $settings['actual_theme_url'];
			$settings['images_url'] = $settings['actual_images_url'];
			$settings['theme_dir'] = $settings['actual_theme_dir'];
		}

		foreach (Template_Layers::getInstance()->reverseLayers() as $layer)
			loadSubTemplate($layer . '_below', 'ignore');

	}

	/**
	 * Output the Javascript files
	 *  - tabbing in this public function is to make the HTML source look proper
	 *  - if defered is set public function will output all JS (source & inline) set to load at page end
	 *  - if the admin option to combine files is set, will use Combiner.class
	 *
	 * @param bool $do_defered = false
	 *
	 */
	public function javascript($do_defered = false)
	{
		global $context, $modSettings, $settings, $boardurl;

		// First up, load jQuery and jQuery UI
		if (isset($modSettings['jquery_source']) && !$do_defered)
		{
			// Using a specified version of jquery or what was shipped 1.10.2 and 1.10.3
			$jquery_version = (!empty($modSettings['jquery_default']) && !empty($modSettings['jquery_version'])) ? $modSettings['jquery_version'] : '1.10.2';
			$jqueryui_version = (!empty($modSettings['jqueryui_default']) && !empty($modSettings['jqueryui_version'])) ? $modSettings['jqueryui_version'] : '1.10.3';

			switch ($modSettings['jquery_source'])
			{
				// Only getting the files from the CDN?
				case 'cdn':
					echo '
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/' . $jquery_version . '/jquery.min.js" id="jquery"></script>',
		(!empty($modSettings['jquery_include_ui']) ? '
		<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/' . $jqueryui_version . '/jquery-ui.min.js" id="jqueryui"></script>' : '');
					break;
				// Just use the local file
				case 'local':
					echo '
		<script src="', $settings['default_theme_url'], '/scripts/jquery-' . $jquery_version . '.min.js" id="jquery"></script>',
		(!empty($modSettings['jquery_include_ui']) ? '
		<script src="' . $settings['default_theme_url'] . '/scripts/jqueryui-' . $jqueryui_version . '.min.js" id="jqueryui"></script>' : '');
					break;
				// CDN with local fallback
				case 'auto':
					echo '
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/' . $jquery_version . '/jquery.min.js" id="jquery"></script>',
		(!empty($modSettings['jquery_include_ui']) ? '
		<script src="https://ajax.googleapis.com/ajax/libs/jqueryui/' . $jqueryui_version . '/jquery-ui.min.js" id="jqueryui"></script>' : '');
					echo '
		<script>
			window.jQuery || document.write(\'<script src="', $settings['default_theme_url'], '/scripts/jquery-' . $jquery_version . '.min.js"><\/script>\');',
			(!empty($modSettings['jquery_include_ui']) ? '
			window.jQuery.ui || document.write(\'<script src="' . $settings['default_theme_url'] . '/scripts/jqueryui-' . $jqueryui_version . '.min.js"><\/script>\')' : ''), '
		</script>';
					break;
			}
		}

		// Use this hook to work with Javascript files and vars pre output
		call_integration_hook('pre_javascript_output');

		// Combine and minify javascript source files to save bandwidth and requests
		if (!empty($context['javascript_files']))
		{
			if (!empty($modSettings['minify_css_js']))
			{
				require_once(SOURCEDIR . '/Combine.class.php');
				$combiner = new Site_Combiner(CACHEDIR, $boardurl . '/cache');
				$combine_name = $combiner->site_js_combine($context['javascript_files'], $do_defered);
			}

			if (!empty($combine_name))
				echo '
		<script src="', $combine_name, '" id="jscombined', $do_defered ? 'bottom' : 'top', '"></script>';
			else
			{
				// While we have Javascript files to place in the template
				foreach ($context['javascript_files'] as $id => $js_file)
				{
					if ((!$do_defered && empty($js_file['options']['defer'])) || ($do_defered && !empty($js_file['options']['defer'])))
						echo '
		<script src="', $js_file['filename'], '" id="', $id, '"', !empty($js_file['options']['async']) ? ' async="async"' : '', '></script>';
				}
			}
		}

		// Build the declared Javascript variables script
		$js_vars = array();
		if (!empty($context['javascript_vars']) && !$do_defered)
		{
			foreach ($context['javascript_vars'] as $var => $value)
				$js_vars[] = $var . ' = ' . $value;

			// nNewlines and tabs are here to make it look nice in the page source view, stripped if minimized though
			$context['javascript_inline']['standard'][] = 'var ' . implode(",\n\t\t\t", $js_vars) . ';';
		}

		// Inline JavaScript - Actually useful some times!
		if (!empty($context['javascript_inline']))
		{
			// Defered output waits until we are defering !
			if (!empty($context['javascript_inline']['defer']) && $do_defered)
			{
				// Combine them all in to one output
				$context['javascript_inline']['defer'] = array_map('trim', $context['javascript_inline']['defer']);
				$inline_defered_code = implode("\n\t\t", $context['javascript_inline']['defer']);

				// Output the defered script
				echo '
		<script>
			', $inline_defered_code, '
		</script>';
			}

			// Standard output, and our javascript vars, get output when we are not on a defered call
			if (!empty($context['javascript_inline']['standard']) && !$do_defered)
			{
				$context['javascript_inline']['standard'] = array_map('trim', $context['javascript_inline']['standard']);

				// And output the js vars and standard scripts to the page
				echo '
		<script>
			', implode("\n\t\t", $context['javascript_inline']['standard']), '
		</script>';
			}
		}
	}

	/**
	 * Output the CSS files
	 *  - if the admin option to combine files is set, will use Combiner.class
	 */
	public function css(array $css_files = array())
	{
		global $context, $modSettings, $boardurl;

		// Use this hook to work with CSS files pre output
		call_integration_hook('pre_css_output');

		// Combine and minify the CSS files to save bandwidth and requests?
		if (!empty($css_files))
		{
			if (!empty($modSettings['minify_css_js']))
			{
				require_once(SOURCEDIR . '/Combine.class.php');
				$combiner = new Site_Combiner(CACHEDIR, $boardurl . '/cache');
				$combine_name = $combiner->site_css_combine($context['css_files']);
			}

			if (!empty($combine_name))
				echo '
		<link rel="stylesheet" href="', $combine_name, '" id="csscombined" />';
			else
			{
				foreach ($css_files as $id => $file)
					echo '
		<link rel="stylesheet" href="', $file['filename'], '" id="', $id,'" />';
			}
		}
	}
}