<?php

/**
 * This file contains the database work for languages.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * Removes the given language from all members..
 *
 * @package Languages
 * @param int $lang_id
 */
function removeLanguageFromMember($lang_id)
{
	$db = $GLOBALS['elk']['db'];

	$db->query('', '
		UPDATE {db_prefix}members
		SET lngfile = {string:empty_string}
		WHERE lngfile = {string:current_language}',
		array(
			'empty_string' => '',
			'current_language' => $lang_id,
		)
	);
}

/**
 * How many languages?
 *
 * - Callback for the list in action_edit().
 *
 * @package Languages
 */
function list_getNumLanguages()
{
	return count(getLanguages());
}

/**
 * Fetch the actual language information.
 *
 * What it does:
 * - Callback for $listOptions['get_items']['function'] in action_edit.
 * - Determines which languages are available by looking for the "index.{language}.php" file.
 * - Also figures out how many users are using a particular language.
 *
 * @package Languages
 */
function list_getLanguages()
{
	global $settings, $language, $txt;

	$db = $GLOBALS['elk']['db'];

	$languages = array();
	// Keep our old entries.
	$old_txt = $txt;
	$backup_actual_theme_dir = $settings['actual_theme_dir'];
	$backup_base_theme_dir = !empty($settings['base_theme_dir']) ? $settings['base_theme_dir'] : '';

	// Override these for now.
	$settings['actual_theme_dir'] = $settings['base_theme_dir'] = $settings['default_theme_dir'];
	$all_languages = getLanguages();

	// Put them back.
	$settings['actual_theme_dir'] = $backup_actual_theme_dir;
	if (!empty($backup_base_theme_dir))
		$settings['base_theme_dir'] = $backup_base_theme_dir;
	else
		unset($settings['base_theme_dir']);

	// Get the language files and data...
	foreach ($all_languages as $lang)
	{
		// Load the file to get the character set.
		require($lang['location']);

		$languages[$lang['filename']] = array(
			'id' => $lang['filename'],
			'count' => 0,
			'char_set' => 'UTF-8',
			'default' => $language == $lang['filename'] || ($language == '' && $lang['filename'] == 'english'),
			'locale' => $txt['lang_locale'],
			'name' => $GLOBALS['elk']['text']->ucwords(strtr($lang['filename'], array('_' => ' ', '-utf8' => ''))),
		);
	}

	// Work out how many people are using each language.
	$request = $db->query('', '
		SELECT lngfile, COUNT(*) AS num_users
		FROM {db_prefix}members
		GROUP BY lngfile',
		array(
		)
	);
	while ($row = $request->fetchAssoc())
	{
		// Default?
		if (empty($row['lngfile']) || !isset($languages[$row['lngfile']]))
			$row['lngfile'] = $language;

		if (!isset($languages[$row['lngfile']]) && isset($languages['english']))
			$languages['english']['count'] += $row['num_users'];
		elseif (isset($languages[$row['lngfile']]))
			$languages[$row['lngfile']]['count'] += $row['num_users'];
	}
	$request->free();

	// Restore the current users language.
	$txt = $old_txt;

	// Return how many we have.
	return $languages;
}

/**
 * This function cleans language entries to/from display.
 *
 * @package Languages
 * @param string $string
 * @param boolean $to_display
 */
function cleanLangString($string, $to_display = true)
{
	// If going to display we make sure it doesn't have any HTML in it - etc.
	$new_string = '';
	if ($to_display)
	{
		// Are we in a string (0 = no, 1 = single quote, 2 = parsed)
		$in_string = 0;
		$is_escape = false;
		$str_len = strlen($string);
		for ($i = 0; $i < $str_len; $i++)
		{
			// Handle escapes first.
			if ($string[$i] == '\\')
			{
				// Toggle the escape.
				$is_escape = !$is_escape;

				// If we're now escaped don't add this string.
				if ($is_escape)
					continue;
			}
			// Special case - parsed string with line break etc?
			elseif (($string[$i] == 'n' || $string[$i] == 't') && $in_string == 2 && $is_escape)
			{
				// Put the escape back...
				$new_string .= $string[$i] == 'n' ? "\n" : "\t";
				$is_escape = false;
				continue;
			}
			// Have we got a single quote?
			elseif ($string[$i] == '\'')
			{
				// Already in a parsed string, or escaped in a linear string, means we print it - otherwise something special.
				if ($in_string != 2 && ($in_string != 1 || !$is_escape))
				{
					// Is it the end of a single quote string?
					if ($in_string == 1)
						$in_string = 0;
					// Otherwise it's the start!
					else
						$in_string = 1;

					// Don't actually include this character!
					continue;
				}
			}
			// Otherwise a double quote?
			elseif ($string[$i] == '"')
			{
				// Already in a single quote string, or escaped in a parsed string, means we print it - otherwise something special.
				if ($in_string != 1 && ($in_string != 2 || !$is_escape))
				{
					// Is it the end of a double quote string?
					if ($in_string == 2)
						$in_string = 0;
					// Otherwise it's the start!
					else
						$in_string = 2;

					// Don't actually include this character!
					continue;
				}
			}
			// A join/space outside of a string is simply removed.
			elseif ($in_string == 0 && (empty($string[$i]) || $string[$i] == '.'))
				continue;
			// Start of a variable?
			elseif ($in_string == 0 && $string[$i] == '$')
			{
				// Find the whole of it!
				preg_match('~([\$A-Za-z0-9\'\[\]_-]+)~', substr($string, $i), $matches);
				if (!empty($matches[1]))
				{
					// Come up with some pseudo thing to indicate this is a var.
					// @todo Do better than this, please!
					$new_string .= '{%' . $matches[1] . '%}';

					// We're not going to reparse this.
					$i += strlen($matches[1]) - 1;
				}

				continue;
			}
			// Right, if we're outside of a string we have DANGER, DANGER!
			elseif ($in_string == 0)
			{
				continue;
			}

			// Actually add the character to the string!
			$new_string .= $string[$i];

			// If anything was escaped it ain't any longer!
			$is_escape = false;
		}

		// Un-html then re-html the whole thing!
		$new_string = $GLOBALS['elk']['text']->htmlspecialchars($GLOBALS['elk']['text']->un_htmlspecialchars($new_string));
	}
	else
	{
		// Keep track of what we're doing...
		$in_string = 0;

		// This is for deciding whether to HTML a quote.
		$in_html = false;
		$str_len = strlen($string);
		for ($i = 0; $i < $str_len; $i++)
		{
			// We don't do parsed strings apart from for breaks.
			if ($in_string == 2)
			{
				$in_string = 0;
				$new_string .= '"';
			}

			// Not in a string yet?
			if ($in_string != 1)
			{
				$in_string = 1;
				$new_string .= ($new_string ? ' . ' : '') . '\'';
			}

			// Is this a variable?
			if ($string[$i] == '{' && $string[$i + 1] == '%' && $string[$i + 2] == '$')
			{
				// Grab the variable.
				preg_match('~\{%([\$A-Za-z0-9\'\[\]_-]+)%\}~', substr($string, $i), $matches);
				if (!empty($matches[1]))
				{
					if ($in_string == 1)
						$new_string .= '\' . ';
					elseif ($new_string)
						$new_string .= ' . ';

					$new_string .= $matches[1];
					$i += strlen($matches[1]) + 3;
					$in_string = 0;
				}

				continue;
			}
			// Is this a lt sign?
			elseif ($string[$i] == '<')
			{
				// Probably HTML?
				if ($string[$i + 1] != ' ')
					$in_html = true;
				// Assume we need an entity...
				else
				{
					$new_string .= '&lt;';
					continue;
				}
			}
			// What about gt?
			elseif ($string[$i] == '>')
			{
				// Will it be HTML?
				if ($in_html)
					$in_html = false;
				// Otherwise we need an entity...
				else
				{
					$new_string .= '&gt;';
					continue;
				}
			}
			// Is it a slash? If so escape it...
			if ($string[$i] == '\\')
				$new_string .= '\\';
			// The infamous double quote?
			elseif ($string[$i] == '"')
			{
				// If we're in HTML we leave it as a quote - otherwise we entity it.
				if (!$in_html)
				{
					$new_string .= '&quot;';
					continue;
				}
			}
			// A single quote?
			elseif ($string[$i] == '\'')
			{
				// Must be in a string so escape it.
				$new_string .= '\\';
			}

			// Finally add the character to the string!
			$new_string .= $string[$i];
		}

		// If we ended as a string then close it off.
		if ($in_string == 1)
			$new_string .= '\'';
		elseif ($in_string == 2)
			$new_string .= '"';
	}

	return $new_string;
}

/**
 * Gets a list of available languages from the mother ship
 *
 * - Will return a subset if searching, otherwise all available
 *
 * @package Languages
 * @return string
 */
function list_getLanguagesList()
{
	global $context, $txt, $scripturl;

	// We're going to use this URL.
	// @todo no we are not, this needs to be changed - again
	$url = 'http://download.elkarte.net/fetch_language.php?version=' . urlencode(strtr(FORUM_VERSION, array('ElkArte ' => '')));

	// Load the class file and stick it into an array.
	$language_list = new Xml_Array(fetch_web_data($url), true);

	// Check that the site responded and that the language exists.
	if (!$language_list->exists('languages'))
		$context['langfile_error'] = 'no_response';
	elseif (!$language_list->exists('languages/language'))
		$context['langfile_error'] = 'no_files';
	else
	{
		$language_list = $language_list->path('languages[0]');
		$lang_files = $language_list->set('language');
		$languages = array();
		foreach ($lang_files as $file)
		{
			// Were we searching?
			if (!empty($context['elk_search_term']) && strpos($file->fetch('name'), $GLOBALS['elk']['text']->strtolower($context['elk_search_term'])) === false)
				continue;

			$languages[] = array(
				'id' => $file->fetch('id'),
				'name' => $GLOBALS['elk']['text']->ucwords($file->fetch('name')),
				'version' => $file->fetch('version'),
				'utf8' => $txt['yes'],
				'description' => $file->fetch('description'),
				'install_link' => '<a href="' . $scripturl . '?action=Admin;area=languages;sa=downloadlang;did=' . $file->fetch('id') . ';' . $context['session_var'] . '=' . $context['session_id'] . '">' . $txt['add_language_elk_install'] . '</a>',
			);
		}
		if (empty($languages))
			$context['langfile_error'] = 'no_files';
		else
			return $languages;
	}
}

function findPossiblePackages($lang)
{
	$db = $GLOBALS['elk']['db'];

	$request = $db->query('', '
		SELECT id_install, filename
		FROM {db_prefix}log_packages
		WHERE package_id LIKE {string:contains_lang}
			AND install_state = {int:installed}',
		array(
			'contains_lang' => 'elk_' . $lang . '_contribs:elk_' . $lang . '',
			'installed' => 1,
		)
	);

	if ($request->numRows() > 0)
	{
		list ($pid, $file_name) = $request->fetchRow();
	}
	$request->free();

	if (!empty($pid))
		return array($pid, $file_name);
	else
		return false;
}

/**
 * Load a language file.
 *
 * - Tries the current and default themes as well as the user and global languages.
 *
 * @param string $template_name
 * @param string $lang = ''
 * @param bool $fatal = true
 * @param bool $force_reload = false
 * @return string The language actually loaded.
 */
function loadLanguage($template_name, $lang = '', $fatal = true, $force_reload = false)
{
	global $user_info, $language, $settings, $modSettings;
	global $db_show_debug, $txt;
	static $already_loaded = array();

	// Default to the user's language.
	if ($lang == '')
		$lang = isset($user_info['language']) ? $user_info['language'] : $language;

	if (!$force_reload && isset($already_loaded[$template_name]) && $already_loaded[$template_name] == $lang)
		return $lang;

	// Do we want the English version of language file as fallback?
	if (empty($modSettings['disable_language_fallback']) && $lang != 'english')
		loadLanguage($template_name, 'english', false);

	$templates = explode('+', $template_name);

	// Make sure we have $settings - if not we're in trouble and need to find it!
	if (empty($settings['default_theme_dir']))
		loadEssentialThemeData();

	// What theme are we in?
	$theme_name = basename($settings['theme_url']);
	if (empty($theme_name))
		$theme_name = 'unknown';

	$fix_arrays = false;
	// For each file open it up and write it out!
	foreach ($templates as $template)
	{
		if ($template === 'index')
			$fix_arrays = true;

		$attempts = array(
			array(LANGUAGEDIR, $template, $lang),
			array(LANGUAGEDIR, $template, $language),
		);

		// Obviously, the current theme is most important to check.
		$attempts += array(
			array($settings['theme_dir'], $template, $lang),
			array($settings['theme_dir'], $template, $language),
		);

		// Do we have a base theme to worry about?
		if (isset($settings['base_theme_dir']))
		{
			$attempts[] = array($settings['base_theme_dir'], $template, $lang);
			$attempts[] = array($settings['base_theme_dir'], $template, $language);
		}

		// Fall back on the default theme if necessary.
		$attempts[] = array($settings['default_theme_dir'], $template, $lang);
		$attempts[] = array($settings['default_theme_dir'], $template, $language);

		// Fall back on the English language if none of the preferred languages can be found.
		if (!in_array('english', array($lang, $language)))
		{
			$attempts[] = array($settings['theme_dir'], $template, 'english');
			$attempts[] = array($settings['default_theme_dir'], $template, 'english');
		}

		$templates_loader = $GLOBALS['elk']['templates'];

		// Try to find the language file.
		$found = false;
		foreach ($attempts as $k => $file)
		{
			// This is the language dir
			if (file_exists($file[0] . '/' . $file[2] . '/' . $file[1] . '.' . $file[2] . '.php'))
			{
				// Include it!
				$templates_loader->templateInclude($file[0] . '/' . $file[2] . '/' . $file[1] . '.' . $file[2] . '.php');

				// Note that we found it.
				$found = true;

				break;
			}
			elseif (file_exists($file[0] . '/languages/' . $file[2] . '/' . $file[1] . '.' . $file[2] . '.php'))
			{
				// Include it!
				$templates_loader->templateInclude($file[0] . '/languages/' . $file[2] . '/' . $file[1] . '.' . $file[2] . '.php');

				// Note that we found it.
				$found = true;

				break;
			}
		}

		// That couldn't be found!  Log the error, but *try* to continue normally.
		if (!$found && $fatal)
		{
			$GLOBALS['elk']['errors']->log_error(sprintf($txt['theme_language_error'], $template_name . '.' . $lang, 'template'));
			break;
		}
	}

	if ($fix_arrays)
		fix_calendar_text();

	// Keep track of what we're up to soldier.
	if ($db_show_debug === true)
		$GLOBALS['elk']['debug']->add('language_files', $template_name . '.' . $lang . ' (' . $theme_name . ')');

	// Remember what we have loaded, and in which language.
	$already_loaded[$template_name] = $lang;

	// Return the language actually loaded.
	return $lang;
}

/**
 * Loads / Sets arrays for use in date display
 */
function fix_calendar_text()
{
	global $txt;

	$txt['days'] = array(
		$txt['sunday'],
		$txt['monday'],
		$txt['tuesday'],
		$txt['wednesday'],
		$txt['thursday'],
		$txt['friday'],
		$txt['saturday'],
	);
	$txt['days_short'] = array(
		$txt['sunday_short'],
		$txt['monday_short'],
		$txt['tuesday_short'],
		$txt['wednesday_short'],
		$txt['thursday_short'],
		$txt['friday_short'],
		$txt['saturday_short'],
	);
	$txt['months'] = array(
		1 => $txt['january'],
		$txt['february'],
		$txt['march'],
		$txt['april'],
		$txt['may'],
		$txt['june'],
		$txt['july'],
		$txt['august'],
		$txt['september'],
		$txt['october'],
		$txt['november'],
		$txt['december'],
	);
	$txt['months_titles'] = array(
		1 => $txt['january_titles'],
		$txt['february_titles'],
		$txt['march_titles'],
		$txt['april_titles'],
		$txt['may_titles'],
		$txt['june_titles'],
		$txt['july_titles'],
		$txt['august_titles'],
		$txt['september_titles'],
		$txt['october_titles'],
		$txt['november_titles'],
		$txt['december_titles'],
	);
	$txt['months_short'] = array(
		1 => $txt['january_short'],
		$txt['february_short'],
		$txt['march_short'],
		$txt['april_short'],
		$txt['may_short'],
		$txt['june_short'],
		$txt['july_short'],
		$txt['august_short'],
		$txt['september_short'],
		$txt['october_short'],
		$txt['november_short'],
		$txt['december_short'],
	);
}

/**
 * Attempt to reload our known languages.
 *
 * @param bool $use_cache = true
 * @return array[]
 */
function getLanguages($use_cache = true)
{
	global $settings;

	$cache = $GLOBALS['elk']['cache'];

	// Either we don't use the cache, or its expired.
	$languages = '';

	if (!$use_cache || !$cache->getVar($languages, 'known_languages', !$cache->checkLevel(1) ? 86400 : 3600))
	{
		// If we don't have our theme information yet, lets get it.
		if (empty($settings['default_theme_dir']))
			loadTheme(0, false);

		// Default language directories to try.
		$language_directories = array(
			$settings['default_theme_dir'] . '/languages',
			$settings['actual_theme_dir'] . '/languages',
		);

		// We possibly have a base theme directory.
		if (!empty($settings['base_theme_dir']))
			$language_directories[] = $settings['base_theme_dir'] . '/languages';

		// Remove any duplicates.
		$language_directories = array_unique($language_directories);

		foreach ($language_directories as $language_dir)
		{
			// Can't look in here... doesn't exist!
			if (!file_exists($language_dir))
				continue;

			$dir = dir($language_dir);
			while ($entry = $dir->read())
			{
				// Only directories are interesting
				if ($entry == '..' || !is_dir($dir->path . '/' . $entry))
					continue;

				// @todo at some point we may want to simplify that stuff (I mean scanning all the files just for index)
				$file_dir = dir($dir->path . '/' . $entry);
				while ($file_entry = $file_dir->read())
				{
					// Look for the index language file....
					if (!preg_match('~^index\.(.+)\.php$~', $file_entry, $matches))
						continue;

					$languages[$matches[1]] = array(
						'name' => $GLOBALS['elk']['text']->ucwords(strtr($matches[1], array('_' => ' '))),
						'selected' => false,
						'filename' => $matches[1],
						'location' => $language_dir . '/' . $entry . '/index.' . $matches[1] . '.php',
					);
				}
				$file_dir->close();
			}
			$dir->close();
		}

		// Lets cash in on this deal.
		$cache->put('known_languages', $languages, $cache->isEnabled() && !$GLOBALS['elk']['cache']->checkLevel(1) ? 86400 : 3600);
	}

	return $languages;
}
