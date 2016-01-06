<?php

/**
 * This file has all the main functions in it that relate to, well, everything.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */


/**
 * Updates the settings table as well as $modSettings... only does one at a time if $update is true.
 *
 * What it does:
 * - Updates both the settings table and $modSettings array.
 * - All of changeArray's indexes and values are assumed to have escaped apostrophes (')!
 * - If a variable is already set to what you want to change it to, that
 *   Variable will be skipped over; it would be unnecessary to reset.
 * - When update is true, UPDATEs will be used instead of REPLACE.
 * - When update is true, the value can be true or false to increment
 *  or decrement it, respectively.
 *
 * @param mixed[] $changeArray An associative array of what we're changing in 'setting' => 'value' format
 * @param bool $update Use an UPDATE query instead of a REPLACE query
 * @param string|null $group The key group
 */
function updateSettings($changeArray, $update = false, $group = null)
{
	global $modSettings;

	$db = $GLOBALS['elk']['db'];
	$cache = $GLOBALS['elk']['cache'];

	if (empty($changeArray) || !is_array($changeArray))
		return;

	$group = $group === null ? 'settings' : $group;

	// In some cases, this may be better and faster, but for large sets we don't want so many UPDATEs.
	if ($update)
	{
		foreach ($changeArray as $variable => $value)
		{
			$db->update('', '
				UPDATE {db_prefix}settings
				SET value = {' . ($value === false || $value === true ? 'raw' : 'string') . ':value}
				WHERE variable = {string:variable}',
				array(
					'value' => $value === true ? 'value + 1' : ($value === false ? 'value - 1' : $value),
					'variable' => $variable,
				)
			);

			$modSettings[$variable] = $value === true ? $modSettings[$variable] + 1 : ($value === false ? $modSettings[$variable] - 1 : $value);
		}

		// Clean out the cache and make sure the cobwebs are gone too.
		$cache->remove('modSettings');

		return;
	}

	$replaceArray = array();
	foreach ($changeArray as $variable => $value)
	{
		// Don't bother if it's already like that ;).
		if (isset($modSettings[$variable]) && $modSettings[$variable] == $value)
			continue;
		// If the variable isn't set, but would only be set to nothing'ness, then don't bother setting it.
		elseif (!isset($modSettings[$variable]) && empty($value))
			continue;

		$replaceArray[] = array($variable, $value, $group);

		$modSettings[$variable] = $value;
	}

	if (empty($replaceArray))
		return;

	$db->insert('replace',
		'{db_prefix}settings',
		array('variable' => 'string-255', 'value' => 'string-65534', 'key_group' => 'string-255'),
		$replaceArray,
		array('variable')
	);

	// Kill the cache - it needs redoing now, but we won't bother ourselves with that here.
	$cache->remove('modSettings');
}

/**
 * Deletes one setting from the settings table and takes care of $modSettings as well
 *
 * @param string|string[] $toRemove the setting or the settings to be removed
 */
function removeSettings($toRemove)
{
	global $modSettings;

	if (empty($toRemove))
		return;

	if (!is_array($toRemove))
		$toRemove = array($toRemove);

	// Remove the setting from the db
	$GLOBALS['elk']['db']->delete('', '
		DELETE FROM {db_prefix}settings
		WHERE variable IN ({array_string:setting_name})',
		array(
			'setting_name' => $toRemove,
		)
	);

	// Remove it from $modSettings now so it does not persist
	foreach ($toRemove as $setting)
	{
		if (isset($modSettings[$setting]))
		{
			unset($modSettings[$setting]);
		}
	}

	// Kill the cache - it needs redoing now, but we won't bother ourselves with that here.
	$GLOBALS['elk']['cache']->remove('modSettings');
}

/**
 * Constructs a page list.
 *
 * What it does:
 * - Builds the page list, e.g. 1 ... 6 7 [8] 9 10 ... 15.
 * - Flexible_start causes it to use "url.page" instead of "url;start=page".
 * - Very importantly, cleans up the start value passed, and forces it to
 *   be a multiple of num_per_page.
 * - Checks that start is not more than max_value.
 * - Base_url should be the URL without any start parameter on it.
 * - Uses the compactTopicPagesEnable and compactTopicPagesContiguous
 *   settings to decide how to display the menu.
 *
 * @example is available near the function definition.
 * @example $pageindex = constructPageIndex($scripturl . '?board=' . $board, $_REQUEST['start'], $num_messages, $maxindex, true);
 *
 * @param string $base_url The base URL to be used for each link.
 * @param int &$start The start position, by reference. If this is not a multiple
 * of the number of items per page, it is sanitized to be so and the value will persist upon the function's return.
 * @param int $max_value The total number of items you are paginating for.
 * @param int $num_per_page The number of items to be displayed on a given page.
 * @param bool $flexible_start = false Use "url.page" instead of "url;start=page"
 * @param mixed[] $show associative array of option => boolean paris
 * @return string
 */
function constructPageIndex($base_url, &$start, $max_value, $num_per_page, $flexible_start = false, $show = array())
{
	global $modSettings, $context, $txt, $settings;

	// Save whether $start was less than 0 or not.
	$start = (int) $start;
	$start_invalid = $start < 0;
	$show_defaults = array(
		'prev_next' => true,
		'all' => false,
	);

	$show = array_merge($show_defaults, $show);

	// Make sure $start is a proper variable - not less than 0.
	if ($start_invalid)
		$start = 0;
	// Not greater than the upper bound.
	elseif ($start >= $max_value)
		$start = max(0, (int) $max_value - (((int) $max_value % (int) $num_per_page) == 0 ? $num_per_page : ((int) $max_value % (int) $num_per_page)));
	// And it has to be a multiple of $num_per_page!
	else
		$start = max(0, (int) $start - ((int) $start % (int) $num_per_page));

	$context['current_page'] = $start / $num_per_page;

	$base_link = str_replace('{base_link}', ($flexible_start ? $base_url : strtr($base_url, array('%' => '%%')) . ';start=%1$d'), $settings['page_index_template']['base_link']);

	// Compact pages is off or on?
	if (empty($modSettings['compactTopicPagesEnable']))
	{
		// Show the left arrow.
		$pageindex = $start == 0 ? ' ' : sprintf($base_link, $start - $num_per_page, str_replace('{prev_txt}', $txt['prev'], $settings['page_index_template']['previous_page']));

		// Show all the pages.
		$display_page = 1;
		for ($counter = 0; $counter < $max_value; $counter += $num_per_page)
			$pageindex .= $start == $counter && !$start_invalid ? sprintf($settings['page_index_template']['current_page'], $display_page++) : sprintf($base_link, $counter, $display_page++);

		// Show the right arrow.
		$display_page = ($start + $num_per_page) > $max_value ? $max_value : ($start + $num_per_page);
		if ($start != $counter - $max_value && !$start_invalid)
			$pageindex .= $display_page > $counter - $num_per_page ? ' ' : sprintf($base_link, $display_page, str_replace('{next_txt}', $txt['next'], $settings['page_index_template']['next_page']));

		// The "all" button
		if ($show['all'])
		{
			if (!empty($show['all_selected']))
				$pageindex .= sprintf($settings['page_index_template']['current_page'], $txt['all']);
			else
				$pageindex .= sprintf(str_replace('.%1$d', '.%1$s', $base_link), '0;all', str_replace('{all_txt}', $txt['all'], $settings['page_index_template']['all']));
		}
	}
	else
	{
		// If they didn't enter an odd value, pretend they did.
		$PageContiguous = (int) ($modSettings['compactTopicPagesContiguous'] - ($modSettings['compactTopicPagesContiguous'] % 2)) / 2;

		// Show the "prev page" link. (>prev page< 1 ... 6 7 [8] 9 10 ... 15 next page)
		if (!empty($start) && $show['prev_next'])
			$pageindex = sprintf($base_link, $start - $num_per_page, str_replace('{prev_txt}', $txt['prev'], $settings['page_index_template']['previous_page']));
		else
			$pageindex = '';

		// Show the first page. (prev page >1< ... 6 7 [8] 9 10 ... 15)
		if ($start > $num_per_page * $PageContiguous)
			$pageindex .= sprintf($base_link, 0, '1');

		// Show the ... after the first page.  (prev page 1 >...< 6 7 [8] 9 10 ... 15 next page)
		if ($start > $num_per_page * ($PageContiguous + 1))
			$pageindex .= str_replace('{custom}', 'data-baseurl="' . htmlspecialchars(JavaScriptEscape(($flexible_start ? $base_url : strtr($base_url, array('%' => '%%')) . ';start=%1$d')), ENT_COMPAT, 'UTF-8') . '" data-perpage="' . $num_per_page . '" data-firstpage="' . $num_per_page . '" data-lastpage="' . ($start - $num_per_page * $PageContiguous) . '"', $settings['page_index_template']['expand_pages']);

		// Show the pages before the current one. (prev page 1 ... >6 7< [8] 9 10 ... 15 next page)
		for ($nCont = $PageContiguous; $nCont >= 1; $nCont--)
			if ($start >= $num_per_page * $nCont)
			{
				$tmpStart = $start - $num_per_page * $nCont;
				$pageindex .= sprintf($base_link, $tmpStart, $tmpStart / $num_per_page + 1);
			}

		// Show the current page. (prev page 1 ... 6 7 >[8]< 9 10 ... 15 next page)
		if (!$start_invalid)
			$pageindex .= sprintf($settings['page_index_template']['current_page'], ($start / $num_per_page + 1));
		else
			$pageindex .= sprintf($base_link, $start, $start / $num_per_page + 1);

		// Show the pages after the current one... (prev page 1 ... 6 7 [8] >9 10< ... 15 next page)
		$tmpMaxPages = (int) (($max_value - 1) / $num_per_page) * $num_per_page;
		for ($nCont = 1; $nCont <= $PageContiguous; $nCont++)
			if ($start + $num_per_page * $nCont <= $tmpMaxPages)
			{
				$tmpStart = $start + $num_per_page * $nCont;
				$pageindex .= sprintf($base_link, $tmpStart, $tmpStart / $num_per_page + 1);
			}

		// Show the '...' part near the end. (prev page 1 ... 6 7 [8] 9 10 >...< 15 next page)
		if ($start + $num_per_page * ($PageContiguous + 1) < $tmpMaxPages)
			$pageindex .= str_replace('{custom}', 'data-baseurl="' . htmlspecialchars(JavaScriptEscape(($flexible_start ? $base_url : strtr($base_url, array('%' => '%%')) . ';start=%1$d')), ENT_COMPAT, 'UTF-8') . '" data-perpage="' . $num_per_page . '" data-firstpage="' . ($start + $num_per_page * ($PageContiguous + 1)) . '" data-lastpage="' . $tmpMaxPages . '"', $settings['page_index_template']['expand_pages']);

		// Show the last number in the list. (prev page 1 ... 6 7 [8] 9 10 ... >15<  next page)
		if ($start + $num_per_page * $PageContiguous < $tmpMaxPages)
			$pageindex .= sprintf($base_link, $tmpMaxPages, $tmpMaxPages / $num_per_page + 1);

		// Show the "next page" link. (prev page 1 ... 6 7 [8] 9 10 ... 15 >next page<)
		if ($start != $tmpMaxPages && $show['prev_next'])
			$pageindex .= sprintf($base_link, $start + $num_per_page, str_replace('{next_txt}', $txt['next'], $settings['page_index_template']['next_page']));

		// The "all" button
		if ($show['all'])
		{
			if (!empty($show['all_selected']))
				$pageindex .= sprintf($settings['page_index_template']['current_page'], $txt['all']);
			else
				$pageindex .= sprintf(str_replace('.%1$d', '.%1$s', $base_link), '0;all', str_replace('{all_txt}', $txt['all'], $settings['page_index_template']['all']));
		}
	}

	return $pageindex;
}

/**
 * Formats a number.
 *
 * What it does:
 * - Uses the format of number_format to decide how to format the number.
 *   for example, it might display "1 234,50".
 * - Caches the formatting data from the setting for optimization.
 *
 * @param float $number The float value to apply comma formatting
 * @param integer|false $override_decimal_count = false or number of decimals
 * @return string A formatted version of number.
 */
function comma_format($number, $override_decimal_count = false)
{
	global $txt;
	static $thousands_separator = null, $decimal_separator = null, $decimal_count = null;

	// Cache these values...
	if ($decimal_separator === null)
	{
		// Not set for whatever reason?
		if (empty($txt['number_format']) || preg_match('~^1([^\d]*)?234([^\d]*)(0*?)$~', $txt['number_format'], $matches) != 1)
			return $number;

		// Cache these each load...
		$thousands_separator = $matches[1];
		$decimal_separator = $matches[2];
		$decimal_count = strlen($matches[3]);
	}

	// Format the string with our friend, number_format.
	return number_format($number, (float) $number === $number ? ($override_decimal_count === false ? $decimal_count : $override_decimal_count) : 0, $decimal_separator, $thousands_separator);
}

/**
 * Format a time to make it look purdy.
 *
 * What it does:
 * - Returns a pretty formatted version of time based on the user's format in $user_info['time_format'].
 * - Applies all necessary time offsets to the timestamp, unless offset_type is set.
 * - If todayMod is set and show_today was not not specified or true, an
 *   alternate format string is used to show the date with something to show it is "today" or "yesterday".
 * - Performs localization (more than just strftime would do alone.)
 *
 * @param int $log_time A unix timestamp
 * @param string|bool $show_today = true show "Today"/"Yesterday" or just a date
 * @param string|bool $offset_type = false If false, uses both user time offset and forum offset.
 *   If 'forum', uses only the forum offset. Otherwise no offset is applied.
 * @return string
 */
function standardTime($log_time, $show_today = true, $offset_type = false)
{
	global $context, $user_info, $txt, $modSettings;
	static $non_twelve_hour;

	// Offset the time.
	if (!$offset_type)
		$time = $log_time + ($user_info['time_offset'] + $modSettings['time_offset']) * 3600;
	// Just the forum offset?
	elseif ($offset_type === 'forum')
		$time = $log_time + $modSettings['time_offset'] * 3600;
	else
		$time = $log_time;

	// We can't have a negative date (on Windows, at least.)
	if ($log_time < 0)
		$log_time = 0;

	// Today and Yesterday?
	if ($modSettings['todayMod'] >= 1 && $show_today === true)
	{
		// Get the current time.
		$nowtime = forum_time();

		$then = @getdate($time);
		$now = @getdate($nowtime);

		// Try to make something of a time format string...
		$s = strpos($user_info['time_format'], '%S') === false ? '' : ':%S';
		if (strpos($user_info['time_format'], '%H') === false && strpos($user_info['time_format'], '%T') === false)
		{
			$h = strpos($user_info['time_format'], '%l') === false ? '%I' : '%l';
			$today_fmt = $h . ':%M' . $s . ' %p';
		}
		else
			$today_fmt = '%H:%M' . $s;

		// Same day of the year, same year.... Today!
		if ($then['yday'] == $now['yday'] && $then['year'] == $now['year'])
			return sprintf($txt['today'], standardTime($log_time, $today_fmt, $offset_type));

		// Day-of-year is one less and same year, or it's the first of the year and that's the last of the year...
		if ($modSettings['todayMod'] == '2' && (($then['yday'] == $now['yday'] - 1 && $then['year'] == $now['year']) || ($now['yday'] == 0 && $then['year'] == $now['year'] - 1) && $then['mon'] == 12 && $then['mday'] == 31))
			return sprintf($txt['yesterday'], standardTime($log_time, $today_fmt, $offset_type));
	}

	$str = !is_bool($show_today) ? $show_today : $user_info['time_format'];

	if (setlocale(LC_TIME, $txt['lang_locale']))
	{
		if (!isset($non_twelve_hour))
			$non_twelve_hour = trim(strftime('%p')) === '';
		if ($non_twelve_hour && strpos($str, '%p') !== false)
			$str = str_replace('%p', (strftime('%H', $time) < 12 ? $txt['time_am'] : $txt['time_pm']), $str);

		foreach (array('%a', '%A', '%b', '%B') as $token)
			if (strpos($str, $token) !== false)
				$str = str_replace($token, !empty($txt['lang_capitalize_dates']) ? $GLOBALS['elk']['text']->ucwords(strftime($token, $time)) : strftime($token, $time), $str);
	}
	else
	{
		// Do-it-yourself time localization.  Fun.
		foreach (array('%a' => 'days_short', '%A' => 'days', '%b' => 'months_short', '%B' => 'months') as $token => $text_label)
			if (strpos($str, $token) !== false)
				$str = str_replace($token, $txt[$text_label][(int) strftime($token === '%a' || $token === '%A' ? '%w' : '%m', $time)], $str);

		if (strpos($str, '%p') !== false)
			$str = str_replace('%p', (strftime('%H', $time) < 12 ? $txt['time_am'] : $txt['time_pm']), $str);
	}

	// Windows doesn't support %e; on some versions, strftime fails altogether if used, so let's prevent that.
	if (serverIs('windows') && strpos($str, '%e') !== false)
		$str = str_replace('%e', ltrim(strftime('%d', $time), '0'), $str);

	// Format any other characters..
	return strftime($str, $time);
}

/**
 * Used to render a timestamp to html5 <time> tag format.
 *
 * @param int $timestamp A unix timestamp
 * @return string
 */
function htmlTime($timestamp)
{
	global $txt, $context;

	if (empty($timestamp))
		return '';

	$timestamp = forum_time(true, $timestamp);
	$time = date('Y-m-d H:i', $timestamp);
	$stdtime = standardTime($timestamp, true, true);

	// @todo maybe htmlspecialchars on the title attribute?
	return '<time title="' . (!empty($context['using_relative_time']) ? $stdtime : $txt['last_post']) . '" datetime="' . $time . '" data-timestamp="' . $timestamp . '">' . $stdtime . '</time>';
}

/**
 * Gets the current time with offset.
 *
 * What it does:
 * - Always applies the offset in the time_offset setting.
 *
 * @param bool $use_user_offset = true if use_user_offset is true, applies the user's offset as well
 * @param int|null $timestamp = null A unix timestamp (null to use current time)
 * @return int seconds since the unix epoch
 */
function forum_time($use_user_offset = true, $timestamp = null)
{
	global $user_info, $modSettings;

	if ($timestamp === null)
		$timestamp = time();
	elseif ($timestamp == 0)
		return 0;

	return $timestamp + ($modSettings['time_offset'] + ($use_user_offset ? $user_info['time_offset'] : 0)) * 3600;
}

/**
 * Lexicographic permutation function.
 *
 * This is a special type of permutation which involves the order of the set. The next
 * lexicographic permutation of '32541' is '34125'. Numerically, it is simply the smallest
 * set larger than the current one.
 *
 * The benefit of this over a recursive solution is that the whole list does NOT need
 * to be held in memory. So it's actually possible to run 30! permutations without
 * causing a memory overflow.
 *
 * Source: O'Reilly PHP Cookbook
 *
 * @param mixed[] $p The array keys to apply permutation
 * @param int $size The size of our permutation array
 *
 * @return mixed[] the next permutation of the passed array $p
 */
function pc_next_permutation($p, $size)
{
	// Slide down the array looking for where we're smaller than the next guy
	for ($i = $size - 1; isset($p[$i]) && $p[$i] >= $p[$i + 1]; --$i)
	{
	}

	// If this doesn't occur, we've finished our permutations
	// the array is reversed: (1, 2, 3, 4) => (4, 3, 2, 1)
	if ($i === -1)
	{
		return false;
	}

	// Slide down the array looking for a bigger number than what we found before
	for ($j = $size; $p[$j] <= $p[$i]; --$j)
	{
	}

	// Swap them
	$tmp = $p[$i];
	$p[$i] = $p[$j];
	$p[$j] = $tmp;

	// Now reverse the elements in between by swapping the ends
	for (++$i, $j = $size; $i < $j; ++$i, --$j)
	{
		$tmp = $p[$i];
		$p[$i] = $p[$j];
		$p[$j] = $tmp;
	}

	return $p;
}

/**
 * Ends execution and redirects the user to a new location
 *
 * What it does:
 * - Makes sure the browser doesn't come back and repost the form data.
 * - Should be used whenever anything is posted.
 * - Calls AddMailQueue to process any mail queue items its can
 * - Calls $GLOBALS['elk']['hooks']->hook integrate_redirect before headers are sent
 * - Diverts final execution to obExit() which means a end to processing and sending of final output
 *
 * @param string $setLocation = '' The URL to redirect to
 * @param bool $refresh = false, enable to send a refresh header, default is a location header
 */
function redirectexit($setLocation = '', $refresh = false)
{
	global $scripturl, $context, $modSettings, $db_show_debug;

	// In case we have mail to send, better do that - as obExit doesn't always quite make it...
	if (!empty($context['flush_mail']))
		// @todo this relies on 'flush_mail' being only set in AddMailQueue itself... :\
		AddMailQueue(true);

	Notifications::getInstance()->send();

	$add = preg_match('~^(ftp|http)[s]?://~', $setLocation) == 0 && substr($setLocation, 0, 6) != 'about:';

	if ($add)
		$setLocation = $scripturl . ($setLocation != '' ? '?' . $setLocation : '');

	// Put the session ID in.
	if (defined('SID') && SID != '')
		$setLocation = preg_replace('/^' . preg_quote($scripturl, '/') . '(?!\?' . preg_quote(SID, '/') . ')\\??/', $scripturl . '?' . SID . ';', $setLocation);
	// Keep that debug in their for template debugging!
	elseif (isset($_GET['debug']))
		$setLocation = preg_replace('/^' . preg_quote($scripturl, '/') . '\\??/', $scripturl . '?debug;', $setLocation);

	if (!empty($modSettings['queryless_urls']) && (!serverIs('cgi') || ini_get('cgi.fix_pathinfo') == 1 || @get_cfg_var('cgi.fix_pathinfo') == 1) && (serverIs('apache') || serverIs('lighttpd') || serverIs('litespeed')))
	{
		if (defined('SID') && SID != '')
			$setLocation = preg_replace_callback('~^' . preg_quote($scripturl, '~') . '\?(?:' . SID . '(?:;|&|&amp;))((?:board|topic)=[^#]+?)(#[^"]*?)?$~', 'redirectexit_callback', $setLocation);
		else
			$setLocation = preg_replace_callback('~^' . preg_quote($scripturl, '~') . '\?((?:board|topic)=[^#"]+?)(#[^"]*?)?$~', 'redirectexit_callback', $setLocation);
	}

	// Maybe integrations want to change where we are heading?
	$GLOBALS['elk']['hooks']->hook('redirect', array(&$setLocation, &$refresh));

	// We send a Refresh header only in special cases because Location looks better. (and is quicker...)
	if ($refresh)
		header('Refresh: 0; URL=' . strtr($setLocation, array(' ' => '%20')));
	else
		header('Location: ' . str_replace(' ', '%20', $setLocation));

	// Debugging.
	if ($db_show_debug === true)
		$_SESSION['debug_redirect'] = $GLOBALS['elk']['debug']->get_db();

	obExit(false);
}

/**
 * URL fixer for redirect exit
 *
 * What it does:
 * - Similar to the callback function used in ob_sessrewrite
 * - Evoked by enabling queryless_urls for systems that support that function
 *
 * @param mixed[] $matches results from the calling preg
 * @return string
 */
function redirectexit_callback($matches)
{
	global $scripturl;

	if (defined('SID') && SID != '')
		return $scripturl . '/' . strtr($matches[1], '&;=', '//,') . '.html?' . SID . (isset($matches[2]) ? $matches[2] : '');
	else
		return $scripturl . '/' . strtr($matches[1], '&;=', '//,') . '.html' . (isset($matches[2]) ? $matches[2] : '');
}

/**
 * Ends execution.
 *
 * What it does:
 * - Takes care of template loading and remembering the previous URL.
 * - Calls ob_start() with ob_sessrewrite to fix URLs if necessary.
 *
 * @param bool|null $header = null Output the header
 * @param bool|null $do_footer = null Output the footer
 * @param bool $from_index = false If we're coming from index.php
 * @param bool $from_fatal_error = false If we are exiting due to a fatal error
 */
function obExit($header = null, $do_footer = null, $from_index = false, $from_fatal_error = false)
{
	global $context, $txt, $db_show_debug;

	static $header_done = false, $footer_done = false, $level = 0, $has_fatal_error = false;

	// Attempt to prevent a recursive loop.
	++$level;
	if ($level > 1 && !$from_fatal_error && !$has_fatal_error)
		exit;

	if ($from_fatal_error)
		$has_fatal_error = true;

	// Clear out the stat cache.
	trackStats();

	// If we have mail to send, send it.
	if (!empty($context['flush_mail']))
		// @todo this relies on 'flush_mail' being only set in AddMailQueue itself... :\
		AddMailQueue(true);

	Elkarte\Notifications\Notifications::getInstance()->send();

	$do_header = $header === null ? !$header_done : $header;
	if ($do_footer === null)
		$do_footer = $do_header;

	// Has the template/header been done yet?
	if ($do_header)
	{
		// Was the page title set last minute? Also update the HTML safe one.
		if (!empty($context['page_title']) && empty($context['page_title_html_safe']))
			$context['page_title_html_safe'] = $GLOBALS['elk']['text']->htmlspecialchars($GLOBALS['elk']['text']->un_htmlspecialchars($context['page_title'])) . (!empty($context['current_page']) ? ' - ' . $txt['page'] . ' ' . ($context['current_page'] + 1) : '');

		// Start up the session URL fixer.
		ob_start('ob_sessrewrite');

		$GLOBALS['elk']['hooks']->buffer_hook();

		// Display the screen in the logical order.
		theme()->template_header();
		$header_done = true;
	}

	if ($do_footer)
	{
		// Show the footer.
		$GLOBALS['elk']['templates']->loadSubTemplate(isset($context['sub_template']) ? $context['sub_template'] : 'main');

		// Just so we don't get caught in an endless loop of errors from the footer...
		if (!$footer_done)
		{
			$footer_done = true;
			theme()->template_footer();

			// Add $db_show_debug = true; to Settings.php if you want to show the debugging information.
			// (since this is just debugging... it's okay that it's after </html>.)
			if ($db_show_debug === true)
				if (!isset($_REQUEST['xml']) && ((!isset($_GET['action']) || $_GET['action'] != 'viewquery') && !isset($_GET['api'])))
					$GLOBALS['elk']['debug']->display();
		}
	}

	// Need user agent
	$req = $GLOBALS['elk']['req'];

	// Remember this URL in case someone doesn't like sending HTTP_REFERER.
	$invalid_old_url = array(
		'action=dlattach',
		'action=jsoption',
		'action=viewadminfile',
		';xml',
		';api',
	);
	$make_old = true;
	foreach ($invalid_old_url as $url)
	{
		if (strpos($_SERVER['REQUEST_URL'], $url) !== false)
		{
			$make_old = false;
			break;
		}
	}
	if ($make_old === true)
		$_SESSION['old_url'] = $_SERVER['REQUEST_URL'];

	// For session check verification.... don't switch browsers...
	$_SESSION['USER_AGENT'] = $req->user_agent();

	// Hand off the output to the portal, etc. we're integrated with.
	$GLOBALS['elk']['hooks']->hook('exit', array($do_footer));

	// Don't exit if we're coming from index.php; that will pass through normally.
	if (!$from_index)
		exit;
}

/**
 * Sets the class of the current topic based on is_very_hot, veryhot, hot, etc
 *
 * @param mixed[] $topic_context array of topic information
 */
function determineTopicClass(&$topic_context)
{
	// Set topic class depending on locked status and number of replies.
	if ($topic_context['is_very_hot'])
		$topic_context['class'] = 'veryhot';
	elseif ($topic_context['is_hot'])
		$topic_context['class'] = 'hot';
	else
		$topic_context['class'] = 'normal';

	$topic_context['class'] .= !empty($topic_context['is_poll']) ? '_poll' : '_post';

	if ($topic_context['is_locked'])
		$topic_context['class'] .= '_locked';

	if ($topic_context['is_sticky'])
		$topic_context['class'] .= '_sticky';
}
/**
 * Sets up the basic theme context stuff.
 *
 * @param bool $forceload = false
 */
function setupThemeContext($forceload = false)
{
	return theme()->setupThemeContext($forceload);
}


/**
 * Helper function to set the system memory to a needed value
 *
 * What it does:
 * - If the needed memory is greater than current, will attempt to get more
 * - If in_use is set to true, will also try to take the current memory usage in to account
 *
 * @param string $needed The amount of memory to request, if needed, like 256M
 * @param bool $in_use Set to true to account for current memory usage of the script
 * @return boolean true if we have at least the needed memory
 */
function setMemoryLimit($needed, $in_use = false)
{
	// Everything in bytes
	$memory_current = memoryReturnBytes(ini_get('memory_limit'));
	$memory_needed = memoryReturnBytes($needed);

	// Should we account for how much is currently being used?
	if ($in_use)
		$memory_needed += memory_get_usage();

	// If more is needed, request it
	if ($memory_current < $memory_needed)
	{
		@ini_set('memory_limit', ceil($memory_needed / 1048576) . 'M');
		$memory_current = memoryReturnBytes(ini_get('memory_limit'));
	}

	$memory_current = max($memory_current, memoryReturnBytes(get_cfg_var('memory_limit')));

	// Return success or not
	return (bool) ($memory_current >= $memory_needed);
}

/**
 * Helper function to convert memory string settings to bytes
 *
 * @param string $val The byte string, like 256M or 1G
 * @return integer The string converted to a proper integer in bytes
 */
function memoryReturnBytes($val)
{
	if (is_integer($val))
		return $val;

	// Separate the number from the designator
	$val = trim($val);
	$num = intval(substr($val, 0, strlen($val) - 1));
	$last = strtolower(substr($val, -1));

	// Convert to bytes
	switch ($last)
	{
		// fall through select g = 1024*1024*1024
		case 'g':
			$num *= 1024;
		// fall through select m = 1024*1024
		case 'm':
			$num *= 1024;
		case 'k':
			$num *= 1024;
	}

	return $num;
}

/**
 * Wrapper function for set_time_limit
 *
 * When called, attempts to restart the timeout counter from zero.
 *
 * This sets the maximum time in seconds a script is allowed to run before it is terminated by the parser.
 * You can not change this setting with ini_set() when running in safe mode.
 * Your web server can have other timeout configurations that may also interrupt PHP execution.
 * Apache has a Timeout directive and IIS has a CGI timeout function.
 * Security extension may also disable this function, such as Suhosin
 * Hosts may add this to the disabled_functions list in php.ini
 *
 * If the current time limit is not unlimited it is possible to decrease the
 * total time limit if the sum of the new time limit and the current time spent
 * running the script is inferior to the original time limit. It is inherent to
 * the way set_time_limit() works, it should rather be called with an
 * appropriate value every time you need to allocate a certain amount of time
 * to execute a task than only once at the beginning of the script.
 *
 * Before calling set_time_limit(), we check if this function is available
 *
 * @param int $time_limit The time limit
 * @param bool $server_reset whether to reset the server timer or not
 * @return string
 */
function setTimeLimit($time_limit, $server_reset = true)
{
	// Make sure the function exists, it may be in the ini disable_functions list
	if (function_exists('set_time_limit'))
	{
		$current = (int) ini_get('max_execution_time');

		// Do not set a limit if it is currently unlimited.
		if ($current !== 0)
		{
			// Set it to the maximum that we can, not more, not less
			$time_limit = min($current, max($time_limit, $current));

			// Still need error suppression as some security addons many prevent this action
			@set_time_limit($time_limit);
		}
	}

	// Don't let apache close the connection
	if ($server_reset && function_exists('apache_reset_timeout'))
		@apache_reset_timeout();

	return ini_get('max_execution_time');
}

/**
 * Convert a single IP to a ranged IP.
 *
 * - Internal function used to convert a user-readable format to a format suitable for the database.
 *
 * @param string $fullip A full dot notation IP address
 * @return array|string 'unknown' if the ip in the input was '255.255.255.255'
 */
function ip2range($fullip)
{
	// If its IPv6, validate it first.
	if (isValidIPv6($fullip) !== false)
	{
		$ip_parts = explode(':', expandIPv6($fullip, false));
		$ip_array = array();

		if (count($ip_parts) != 8)
			return array();

		for ($i = 0; $i < 8; $i++)
		{
			if ($ip_parts[$i] == '*')
				$ip_array[$i] = array('low' => '0', 'high' => hexdec('ffff'));
			elseif (preg_match('/^([0-9A-Fa-f]{1,4})\-([0-9A-Fa-f]{1,4})$/', $ip_parts[$i], $range) == 1)
				$ip_array[$i] = array('low' => hexdec($range[1]), 'high' => hexdec($range[2]));
			elseif (is_numeric(hexdec($ip_parts[$i])))
				$ip_array[$i] = array('low' => hexdec($ip_parts[$i]), 'high' => hexdec($ip_parts[$i]));
		}

		return $ip_array;
	}

	// Pretend that 'unknown' is 255.255.255.255. (since that can't be an IP anyway.)
	if ($fullip == 'unknown')
		$fullip = '255.255.255.255';

	$ip_parts = explode('.', $fullip);
	$ip_array = array();

	if (count($ip_parts) != 4)
		return array();

	for ($i = 0; $i < 4; $i++)
	{
		if ($ip_parts[$i] == '*')
			$ip_array[$i] = array('low' => '0', 'high' => '255');
		elseif (preg_match('/^(\d{1,3})\-(\d{1,3})$/', $ip_parts[$i], $range) == 1)
			$ip_array[$i] = array('low' => $range[1], 'high' => $range[2]);
		elseif (is_numeric($ip_parts[$i]))
			$ip_array[$i] = array('low' => $ip_parts[$i], 'high' => $ip_parts[$i]);
	}

	// Makes it simpler to work with.
	$ip_array[4] = array('low' => 0, 'high' => 0);
	$ip_array[5] = array('low' => 0, 'high' => 0);
	$ip_array[6] = array('low' => 0, 'high' => 0);
	$ip_array[7] = array('low' => 0, 'high' => 0);

	return $ip_array;
}

/**
 * Lookup an IP; try shell_exec first because we can do a timeout on it.
 *
 * @param string $ip A full dot notation IP address
 * @return string
 */
function host_from_ip($ip)
{
	global $modSettings;

	$cache = $GLOBALS['elk']['cache'];

	if ($cache->getVar($host = null, 'hostlookup-' . $ip, 600) || empty($ip))
		return $host;

	$t = microtime(true);

	// Try the Linux host command, perhaps?
	if (!isset($host) && (strpos(strtolower(PHP_OS), 'win') === false || strpos(strtolower(PHP_OS), 'darwin') !== false) && mt_rand(0, 1) == 1)
	{
		if (!isset($modSettings['host_to_dis']))
			$test = @shell_exec('host -W 1 ' . @escapeshellarg($ip));
		else
			$test = @shell_exec('host ' . @escapeshellarg($ip));

		// Did host say it didn't find anything?
		if (strpos($test, 'not found') !== false)
			$host = '';
		// Invalid server option?
		elseif ((strpos($test, 'invalid option') || strpos($test, 'Invalid query name 1')) && !isset($modSettings['host_to_dis']))
			updateSettings(array('host_to_dis' => 1));
		// Maybe it found something, after all?
		elseif (preg_match('~\s([^\s]+?)\.\s~', $test, $match) == 1)
			$host = $match[1];
	}

	// This is nslookup; usually only Windows, but possibly some Unix?
	if (!isset($host) && stripos(PHP_OS, 'win') !== false && strpos(strtolower(PHP_OS), 'darwin') === false && mt_rand(0, 1) == 1)
	{
		$test = @shell_exec('nslookup -timeout=1 ' . @escapeshellarg($ip));

		if (strpos($test, 'Non-existent domain') !== false)
			$host = '';
		elseif (preg_match('~Name:\s+([^\s]+)~', $test, $match) == 1)
			$host = $match[1];
	}

	// This is the last try :/.
	if (!isset($host) || $host === false)
		$host = @gethostbyaddr($ip);

	// It took a long time, so let's cache it!
	if (microtime(true) - $t > 0.5)
		$cache->put('hostlookup-' . $ip, $host, 600);

	return $host;
}

/**
 * Chops a string into words and prepares them to be inserted into (or searched from) the database.
 *
 * @param string $text The string to process
 * @param int|null $max_chars = 20
 *     - if encrypt = true this is the maximum number of bytes to use in integer hashes (for searching)
 *     - if encrypt = false this is the maximum number of letters in each word
 * @param bool $encrypt = false Used for custom search indexes to return an int[] array representing the words
 * @return array
 */
function text2words($text, $max_chars = 20, $encrypt = false)
{
	// Step 1: Remove entities/things we don't consider words:
	$words = preg_replace('~(?:[\x0B\0\x{A0}\t\r\s\n(){}\\[\\]<>!@$%^*.,:+=`\~\?/\\\\]+|&(?:amp|lt|gt|quot);)+~u', ' ', strtr($text, array('<br />' => ' ')));

	// Step 2: Entities we left to letters, where applicable, lowercase.
	$words = $GLOBALS['elk']['text']->un_htmlspecialchar($GLOBALS['elk']['text']->strtolower($words));

	// Step 3: Ready to split apart and index!
	$words = explode(' ', $words);

	if ($encrypt)
	{
		// Range of characters that crypt will produce (0-9, a-z, A-Z .)
		$possible_chars = array_flip(array_merge(range(46, 57), range(65, 90), range(97, 122)));
		$returned_ints = array();
		foreach ($words as $word)
		{
			if (($word = trim($word, '-_\'')) !== '')
			{
				// Get a crypt representation of this work
				$encrypted = substr(crypt($word, 'uk'), 2, $max_chars);
				$total = 0;

				// Create an integer representation
				for ($i = 0; $i < $max_chars; $i++)
					$total += $possible_chars[ord($encrypted{$i})] * pow(63, $i);

				// Return the value
				$returned_ints[] = $max_chars == 4 ? min($total, 16777215) : $total;
			}
		}
		return array_unique($returned_ints);
	}
	else
	{
		// Trim characters before and after and add slashes for database insertion.
		$returned_words = array();
		foreach ($words as $word)
			if (($word = trim($word, '-_\'')) !== '')
				$returned_words[] = $max_chars === null ? $word : substr($word, 0, $max_chars);

		// Filter out all words that occur more than once.
		return array_unique($returned_words);
	}
}

/**
 * Generate a random seed and ensure it's stored in settings.
 */
function elk_seed_generator()
{
	global $modSettings;

	// Change the seed.
	if (mt_rand(1, 250) == 69 || empty($modSettings['rand_seed']))
		updateSettings(array('rand_seed' => mt_rand()));
}

/**
 * Microsoft uses their own character set Code Page 1252 (CP1252), which is a
 * superset of ISO 8859-1, defining several characters between DEC 128 and 159
 * that are not normally displayable.  This converts the popular ones that
 * appear from a cut and paste from windows.
 *
 * @param string|false $string The string to sanitize
 * @return string $string
 */
function sanitizeMSCutPaste($string)
{
	if (empty($string))
		return $string;

	// UTF-8 occurrences of MS special characters
	$findchars_utf8 = array(
		"\xe2\x80\x9a", // single low-9 quotation mark
		"\xe2\x80\x9e", // double low-9 quotation mark
		"\xe2\x80\xa6", // horizontal ellipsis
		"\xe2\x80\x98", // left single curly quote
		"\xe2\x80\x99", // right single curly quote
		"\xe2\x80\x9c", // left double curly quote
		"\xe2\x80\x9d", // right double curly quote
		"\xe2\x80\x93", // en dash
		"\xe2\x80\x94", // em dash
	);

	// safe replacements
	$replacechars = array(
		',',   // &sbquo;
		',,',  // &bdquo;
		'...', // &hellip;
		"'",   // &lsquo;
		"'",   // &rsquo;
		'"',   // &ldquo;
		'"',   // &rdquo;
		'-',   // &ndash;
		'--',  // &mdash;
	);

	$string = str_replace($findchars_utf8, $replacechars, $string);

	return $string;
}

/**
 * Decode numeric html entities to their UTF8 equivalent character.
 *
 * What it does:
 * - Callback function for preg_replace_callback in subs-members
 * - Uses capture group 2 in the supplied array
 * - Does basic scan to ensure characters are inside a valid range
 *
 * @param mixed[] $matches matches from a preg_match_all
 * @return string $string
 */
function replaceEntities__callback($matches)
{
	if (!isset($matches[2]))
		return '';

	$num = $matches[2][0] === 'x' ? hexdec(substr($matches[2], 1)) : (int) $matches[2];

	// remove left to right / right to left overrides
	if ($num === 0x202D || $num === 0x202E)
		return '';

	// Quote, Ampersand, Apostrophe, Less/Greater Than get html replaced
	if (in_array($num, array(0x22, 0x26, 0x27, 0x3C, 0x3E)))
		return '&#' . $num . ';';

	// <0x20 are control characters, 0x20 is a space, > 0x10FFFF is past the end of the utf8 character set
	// 0xD800 >= $num <= 0xDFFF are surrogate markers (not valid for utf8 text)
	if ($num < 0x20 || $num > 0x10FFFF || ($num >= 0xD800 && $num <= 0xDFFF))
		return '';
	// <0x80 (or less than 128) are standard ascii characters a-z A-Z 0-9 and punctuation
	elseif ($num < 0x80)
		return chr($num);
	// <0x800 (2048)
	elseif ($num < 0x800)
		return chr(($num >> 6) + 192) . chr(($num & 63) + 128);
	// < 0x10000 (65536)
	elseif ($num < 0x10000)
		return chr(($num >> 12) + 224) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
	// <= 0x10FFFF (1114111)
	else
		return chr(($num >> 18) + 240) . chr((($num >> 12) & 63) + 128) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
}

/**
 * Converts html entities to utf8 equivalents
 *
 * What it does:
 * - Callback function for preg_replace_callback
 * - Uses capture group 1 in the supplied array
 * - Does basic checks to keep characters inside a viewable range.
 *
 * @param mixed[] $matches array of matches as output from preg_match_all
 * @return string $string
 */
function fixchar__callback($matches)
{
	if (!isset($matches[1]))
		return '';

	$num = $matches[1][0] === 'x' ? hexdec(substr($matches[1], 1)) : (int) $matches[1];

	// <0x20 are control characters, > 0x10FFFF is past the end of the utf8 character set
	// 0xD800 >= $num <= 0xDFFF are surrogate markers (not valid for utf8 text), 0x202D-E are left to right overrides
	if ($num < 0x20 || $num > 0x10FFFF || ($num >= 0xD800 && $num <= 0xDFFF) || $num === 0x202D || $num === 0x202E)
		return '';
	// <0x80 (or less than 128) are standard ascii characters a-z A-Z 0-9 and punctuation
	elseif ($num < 0x80)
		return chr($num);
	// <0x800 (2048)
	elseif ($num < 0x800)
		return chr(($num >> 6) + 192) . chr(($num & 63) + 128);
	// < 0x10000 (65536)
	elseif ($num < 0x10000)
		return chr(($num >> 12) + 224) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
	// <= 0x10FFFF (1114111)
	else
		return chr(($num >> 18) + 240) . chr((($num >> 12) & 63) + 128) . chr((($num >> 6) & 63) + 128) . chr(($num & 63) + 128);
}

/**
 * Strips out invalid html entities, replaces others with html style &#123; codes
 *
 * What it does:
 * - Callback function used of preg_replace_callback in various $ent_checks,
 * - For example strpos, strlen, substr etc
 *
 * @param mixed[] $matches array of matches for a preg_match_all
 * @return string
 */
function entity_fix__callback($matches)
{
	if (!isset($matches[2]))
		return '';

	$num = $matches[2][0] === 'x' ? hexdec(substr($matches[2], 1)) : (int) $matches[2];

	// We don't allow control characters, characters out of range, byte markers, etc
	if ($num < 0x20 || $num > 0x10FFFF || ($num >= 0xD800 && $num <= 0xDFFF) || $num == 0x202D || $num == 0x202E)
		return '';
	else
		return '&#' . $num . ';';
}

/**
 * Retrieve additional search engines, if there are any, as an array.
 *
 * @return mixed[] array of engines
 */
function prepareSearchEngines()
{
	global $modSettings;

	$engines = array();
	if (!empty($modSettings['additional_search_engines']))
	{
		$search_engines = unserialize($modSettings['additional_search_engines']);
		foreach ($search_engines as $engine)
			$engines[strtolower(preg_replace('~[^A-Za-z0-9 ]~', '', $engine['name']))] = $engine;
	}

	return $engines;
}

/**
 * This function receives a request handle and attempts to retrieve the next result.
 *
 * What it does:
 * - It is used by the controller callbacks from the template, such as
 * posts in topic display page, posts search results page, or personal messages.
 *
 * @param resource $messages_request holds a query result
 * @param bool $reset
 * @return integer|boolean
 */
function currentContext(\Elkarte\ElkArte\Database\Drivers\ResultInterface $messages_request, $reset = false)
{
	// Start from the beginning...
	if ($reset)
		return $messages_request->dataSeek(0);

	// If the query has already returned false, get out of here
	if ($messages_request == false)
		return false;

	// Attempt to get the next message.
	$message = $messages_request->fetchAssoc();
	if (!$message)
	{
		$messages_request->free();
		return false;
	}

	return $message;
}

/**
 * Helper function to insert an array in to an existing array
 *
 * What it does:
 * - Intended for addon use to allow such things as
 * - Adding in a new menu item to an existing menu array
 *
 * @param mixed[] $input the array we will insert to
 * @param string $key the key in the array that we are looking to find for the insert action
 * @param mixed[] $insert the actual data to insert before or after the key
 * @param string $where adding before or after
 * @param bool $assoc if the array is a assoc array with named keys or a basic index array
 * @param bool $strict search for identical elements, this means it will also check the types of the needle.
 * @return array
 */
function elk_array_insert($input, $key, $insert, $where = 'before', $assoc = true, $strict = false)
{
	// Search for key names or values
	if ($assoc)
		$position = array_search($key, array_keys($input), $strict);
	else
		$position = array_search($key, $input, $strict);

	// If the key is not found, just insert it at the end
	if ($position === false)
		return array_merge($input, $insert);

	if ($where === 'after')
		$position += 1;

	// Insert as first
	if ($position === 0)
		$input = array_merge($insert, $input);
	else
		$input = array_merge(array_slice($input, 0, $position), $insert, array_slice($input, $position));

	return $input;
}

/**
 * Helper function to replace commonly used urls in text strings
 *
 * @param string $string the string to inject URLs into
 * @return string the input string with the place-holders replaced with
 *           the correct URLs
 */
function replaceBasicActionUrl($string)
{
	global $scripturl, $context, $boardurl;
	static $find = null, $replace = null;

	if ($find === null)
	{
		$find = array(
			'{forum_name}',
			'{forum_name_html_safe}',
			'{forum_name_html_unsafe}',
			'{script_url}',
			'{board_url}',
			'{login_url}',
			'{register_url}',
			'{activate_url}',
			'{help_url}',
			'{admin_url}',
			'{moderate_url}',
			'{recent_url}',
			'{search_url}',
			'{who_url}',
			'{credits_url}',
			'{calendar_url}',
			'{memberlist_url}',
			'{stats_url}',
		);
		$replace = array(
			$context['forum_name'],
			$context['forum_name_html_safe'],
			$GLOBALS['elk']['text']->un_htmlspecialchars($context['forum_name_html_safe']),
			$scripturl,
			$boardurl,
			$scripturl . '?action=login',
			$scripturl . '?action=register',
			$scripturl . '?action=register;sa=activate',
			$scripturl . '?action=help',
			$scripturl . '?action=Admin',
			$scripturl . '?action=moderate',
			$scripturl . '?action=recent',
			$scripturl . '?action=search',
			$scripturl . '?action=who',
			$scripturl . '?action=who;sa=credits',
			$scripturl . '?action=calendar',
			$scripturl . '?action=memberlist',
			$scripturl . '?action=stats',
		);
		$GLOBALS['elk']['hooks']->hook('basic_url_replacement', array(&$find, &$replace));
	}

	return str_replace($find, $replace, $string);
}

/**
 * This function creates a new GenericList from all the passed options.
 *
 * What it does:
 * - Calls integration hook integrate_list_"unique_list_id" to allow easy modifying
 *
 * @param mixed[] $listOptions associative array of option => value
 */
function createList($listOptions)
{
	$GLOBALS['elk']['hooks']->hook('list_' . $listOptions['id'], array(&$listOptions));

	$list = new Generic_List($listOptions);

	$list->buildList();
}

/**
 * Meant to replace any usage of $db_last_error.
 *
 * What it does:
 * - Reads the file db_last_error.txt, if a time() is present returns it,
 * otherwise returns 0.
 */
function db_last_error()
{
	$time = trim(file_get_contents(BOARDDIR . '/db_last_error.txt'));
	if (preg_match('~^\d{10}$~', $time) === 1)
		return $time;
	else
		return 0;
}

/**
 * This function has the only task to retrieve the correct prefix to be used
 * in responses.
 *
 * @return string - The prefix in the default language of the forum
 */
function response_prefix()
{
	global $language, $user_info, $txt;
	static $response_prefix = null;

	$cache = $GLOBALS['elk']['cache'];

	// Get a response prefix, but in the forum's default language.
	if ($response_prefix === null && (!$cache->getVar($response_prefix, 'response_prefix') || !$response_prefix))
	{
		if ($language === $user_info['language'])
			$response_prefix = $txt['response_prefix'];
		else
		{
			loadLanguage('index', $language, false);
			$response_prefix = $txt['response_prefix'];
			loadLanguage('index');
		}

		$cache->put('response_prefix', $response_prefix, 600);
	}

	return $response_prefix;
}

/**
 * A very simple function to determine if an email address is "valid" for Elkarte.
 *
 * - A valid email for ElkArte is something that resembles an email (filter_var) and
 * is less than 255 characters (for database limits)
 *
 * @param string $value - The string to evaluate as valid email
 * @return bool|string - The email if valid, false if not a valid email
 */
function isValidEmail($value)
{
	$value = trim($value);
	if (filter_var($value, FILTER_VALIDATE_EMAIL) && $GLOBALS['elk']['text']->strlen($value) < 255)
		return $value;
	else
		return false;
}

/**
 * Adds a protocol (http/s, ftp/mailto) to the beginning of an url if missing
 *
 * @param string $url - The url
 * @param string[] $protocols - A list of protocols to check, the first is
 *                 added if none is found (optional, default array('http://', 'https://'))
 * @return string - The url with the protocol
 */
function addProtocol($url, $protocols = array('http://', 'https://'))
{
	foreach ($protocols as $protocol)
	{
		if (substr($url, 0, strlen($protocol)) === $protocol)
			return $url;
	}

	return $protocols[0] . $url;
}

/**
 * Removes nested quotes from a text string.
 *
 * @param string $text - The body we want to remove nested quotes from
 * @return string - The same body, just without nested quotes
 */
function removeNestedQuotes($text)
{
	global $modSettings;

	// Remove any nested quotes, if necessary.
	if (!empty($modSettings['removeNestedQuotes']))
	{
		return preg_replace(array('~\n?\[quote.*?\].+?\[/quote\]\n?~is', '~^\n~', '~\[/quote\]~'), '', $text);
	}
	else
	{
		return $text;
	}
}

/**
 * Change a \t to a span that will show a tab
 * @param $string
 * @return mixed
 */
function tabToHtmlTab($string)
{
	return str_replace("\t", "<span class=\"tab\">\t</span>", $string);
}

/**
 * Remove <br />
 *
 * @param $string
 * @return mixed
 */
function removeBr($string)
{
	return str_replace('<br />', '', $string);
}

/**
 * Are we using this browser?
 *
 * - Wrapper function for detectBrowser
 *
 * @param string $browser  the browser we are checking for.
 * @return bool
 */
function isBrowser($browser)
{
	global $context;

	// Don't know any browser!
	if (empty($context['browser']))
	{
		$GLOBALS['elk']['browser'];
	}

	return !empty($context['browser'][$browser]) || !empty($context['browser']['is_' . $browser]) ? true : false;
}

/**
 * Replace all vulgar words with respective proper words. (substring or whole words..)
 *
 * What it does:
 * - it censors the passed string.
 * - if the Admin setting allow_no_censored is on it does not censor unless force is also set.
 * - if the Admin setting allow_no_censored is off will censor words unless the user has set
 * it to not censor in their profile and force is off
 * - it caches the list of censored words to reduce parsing.
 * - Returns the censored text
 *
 * @param string $text
 * @param bool $force = false
 */
function censor($text, $force = false)
{
	global $modSettings;
	static $censor = null;

	if ($censor === null)
	{
		$censor = new \Elkarte\Elkarte\Text\Censor(explode("\n", $modSettings['censor_vulgar']), explode("\n", $modSettings['censor_proper']), $modSettings);
	}

	return $censor->censor($text, $force);
}

/**
 * Helper function able to determine if the current member can see at least
 * one button of a button strip.
 *
 * @param mixed[] $button_strip
 * @return bool
 */
function can_see_button_strip($button_strip)
{
	global $context;

	foreach ($button_strip as $key => $value)
	{
		if (!isset($value['test']) || !empty($context[$value['test']]))
			return true;
	}

	return false;
}
/**
 * @return Themes\DefaultTheme\Theme
 */
function theme()
{
	return $GLOBALS['context']['theme_instance'];
}

/**
 * Validates a IPv6 address. returns true if it is ipv6.
 *
 * @param string $ip ip address to be validated
 * @return boolean true|false
 */
function isValidIPv6($ip)
{
	return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

/**
 * Converts IPv6s to numbers.  This makes ban checks much easier.
 *
 * @param string $ip ip address to be converted
 * @return int[] array
 */
function convertIPv6toInts($ip)
{
	static $expanded = array();

	// Check if we have done this already.
	if (isset($expanded[$ip]))
		return $expanded[$ip];

	// Expand the IP out.
	$expanded_ip = explode(':', expandIPv6($ip));

	$new_ip = array();
	foreach ($expanded_ip as $int)
		$new_ip[] = hexdec($int);

	// Save this in case of repeated use.
	$expanded[$ip] = $new_ip;

	return $expanded[$ip];
}

/**
 * Expands a IPv6 address to its full form.
 *
 * @param string $addr ipv6 address string
 * @param boolean $strict_check checks length to expanded address for compliance
 * @return boolean|string expanded ipv6 address.
 */
function expandIPv6($addr, $strict_check = true)
{
	static $converted = array();

	// Check if we have done this already.
	if (isset($converted[$addr]))
		return $converted[$addr];

	// Check if there are segments missing, insert if necessary.
	if (strpos($addr, '::') !== false)
	{
		$part = explode('::', $addr);
		$part[0] = explode(':', $part[0]);
		$part[1] = explode(':', $part[1]);
		$missing = array();

		// Looks like this is an IPv4 address
		if (isset($part[1][1]) && strpos($part[1][1], '.') !== false)
		{
			$ipoct = explode('.', $part[1][1]);
			$p1 = dechex($ipoct[0]) . dechex($ipoct[1]);
			$p2 = dechex($ipoct[2]) . dechex($ipoct[3]);

			$part[1] = array(
				$part[1][0],
				$p1,
				$p2
			);
		}

		$limit = count($part[0]) + count($part[1]);
		for ($i = 0; $i < (8 - $limit); $i++)
			array_push($missing, '0000');

		$part = array_merge($part[0], $missing, $part[1]);
	}
	else
		$part = explode(':', $addr);

	// Pad each segment until it has 4 digits.
	foreach ($part as &$p)
		while (strlen($p) < 4)
			$p = '0' . $p;

	unset($p);

	// Join segments.
	$result = implode(':', $part);

	// Save this in case of repeated use.
	$converted[$addr] = $result;

	// Quick check to make sure the length is as expected.
	if (!$strict_check || strlen($result) == 39)
		return $result;
	else
		return false;
}

/**
 * Clean up the XML to make sure it doesn't contain invalid characters.
 *
 * What it does:
 * - Removes invalid XML characters to assure the input string being
 * parsed properly.
 *
 * @param string $string The string to clean
 * @return string The clean string
 */
function cleanXml($string)
{
	// http://www.w3.org/TR/2000/REC-xml-20001006#NT-Char
	return preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x19\x{FFFE}\x{FFFF}]~u', '', $string);
}

/**
 * Escapes (replaces) characters in strings to make them safe for use in javascript
 *
 * @param string $string The string to escape
 * @return string The escaped string
 */
function JavaScriptEscape($string)
{
	global $scripturl;

	return '\'' . strtr($string, array(
		"\r" => '',
		"\n" => '\\n',
		"\t" => '\\t',
		'\\' => '\\\\',
		'\'' => '\\\'',
		'</' => '<\' + \'/',
		'<script' => '<scri\'+\'pt',
		'<body>' => '<bo\'+\'dy>',
		'<a href' => '<a hr\'+\'ef',
		(string) $scripturl => '\' + elk_scripturl + \'',
	)) . '\'';
}

/**
 * Rewrite URLs to include the session ID.
 *
 * What it does:
 * - Rewrites the URLs outputted to have the session ID, if the user
 *   is not accepting cookies and is using a standard web browser.
 * - Handles rewriting URLs for the queryless URLs option.
 * - Can be turned off entirely by setting $scripturl to an empty
 *   string, ''. (it would not work well like that anyway.)
 *
 * @param string $buffer The unmodified output buffer
 * @return string The modified output buffer
 */
function ob_sessrewrite($buffer)
{
	global $scripturl, $modSettings, $context;

	// If $scripturl is set to nothing, or the SID is not defined (SSI?) just quit.
	if ($scripturl == '' || !defined('SID'))
		return $buffer;

	// Do nothing if the session is cookied, or they are a crawler - guests are caught by redirectexit().
	if (empty($_COOKIE) && SID != '' && !isBrowser('possibly_robot'))
		$buffer = preg_replace('/(?<!<link rel="canonical" href=)"' . preg_quote($scripturl, '/') . '(?!\?' . preg_quote(SID, '/') . ')\\??/', '"' . $scripturl . '?' . SID . '&amp;', $buffer);

	// Debugging templates, are we?
	elseif (isset($_GET['debug']))
		$buffer = preg_replace('/(?<!<link rel="canonical" href=)"' . preg_quote($scripturl, '/') . '\\??/', '"' . $scripturl . '?debug;', $buffer);

	// This should work even in 4.2.x, just not CGI without cgi.fix_pathinfo.
	if (!empty($modSettings['queryless_urls']) && (!serverIs('cgi') || ini_get('cgi.fix_pathinfo') == 1 || @get_cfg_var('cgi.fix_pathinfo') == 1) && (serverIs('apache') || serverIs('lighttpd') || serverIs('litespeed')))
	{
		// Let's do something special for session ids!
		$buffer = preg_replace_callback('~"' . preg_quote($scripturl, '~') . '\?((?:board|topic)=[^#"]+?)(#[^"]*?)?"~', 'buffer_callback', $buffer);
	}

	// Return the changed buffer.
	return $buffer;
}

/**
 * Callback function for the Rewrite URLs preg_replace_callback
 *
 * @param mixed[] $matches
 * @return string
 */
function buffer_callback($matches)
{
	global $scripturl;

	if (defined('SID') && SID != '')
		return '"' . $scripturl . '/' . strtr($matches[1], '&;=', '//,') . '.html?' . SID . (isset($matches[2]) ? $matches[2] : '') . '"';
	else
		return '"' . $scripturl . '/' . strtr($matches[1], '&;=', '//,') . '.html' . (isset($matches[2]) ? $matches[2] : '') . '"';
}