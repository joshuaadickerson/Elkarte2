<?php

// This would be Index.template.php (I capitalized 'index')
// As you can see, most of the functions are global subtemplates, but not all
// It would make sense to call the news_fader as $this->subtemplate('news_fader', 'index')
// or $this->template('index')->news_fader()
class IndexTemplate extends Template implements TemplateEventsInterface
{
	public function postRegister($theme)
	{
		$this->registerSubtemplate('breadcrumbs');
		$this->registerSubtemplate('menu');
		$this->registerSubtemplate('button_strip');
		$this->registerSubtemplate('show_error');
		$this->registerSubtemplate('pagesection');
	}

	/**
	 * I know this is becoming annoying, though this template
	 * *shall* be present for security reasons, so better it stays here
	 *
	 * @todo rework it and merge into some other kind of general warning-box (e.g. modtask at index.template)
	 */
	public function template_admin_warning_above(array $security_controls = array(), array $warning_controls = array())
	{
		if (!empty($security_controls))
		{
			foreach ($security_controls as $error)
			{
				echo '
		<div class="errorbox">
			<h3>', $error['title'], '</h3>
			<ul>';

				foreach ($error['messages'] as $text)
				{
					echo '
				<li class="listlevel1">', $text, '</li>';
				}

				echo '
			</ul>
		</div>';
			}
		}

		// Any special notices to remind the admin about?
		if (!empty($warning_controls))
		{
			echo '
		<div class="warningbox">
			<ul>
				<li class="listlevel1">', implode('</li><li class="listlevel1">', $context['warning_controls']), '</li>
			</ul>
		</div>';
		}
	}

	/**
	 * Creates an image/text button
	 *
	 * @param string $name
	 * @param string $alt
	 * @param string $label = ''
	 * @param string|boolean $custom = ''
	 * @param boolean $force_use = false
	 * @return string
	 *
	 * @deprecated: this will be removed at some point, do not rely on this function
	 */
	public function create_button($name, $alt, $label = '', $custom = '', $force_use = false)
	{
		global $settings, $txt;

		// Does the current loaded theme have this and we are not forcing the usage of this function?
		if (function_exists('template_create_button') && !$force_use)
			return template_create_button($name, $alt, $label = '', $custom = '');

		if (!$settings['use_image_buttons'])
			return $txt[$alt];
		elseif (!empty($settings['use_buttons']))
			return '<img src="' . $settings['images_url'] . '/buttons/' . $name . '" alt="' . $txt[$alt] . '" ' . $custom . ' />' . ($label != '' ? '&nbsp;<strong>' . $txt[$label] . '</strong>' : '');
		else
			return '<img src="' . $settings['lang_images_url'] . '/' . $name . '" alt="' . $txt[$alt] . '" ' . $custom . ' />';
	}

	/**
	 * Constructs a page list.
	 *
	 * - builds the page list, e.g. 1 ... 6 7 [8] 9 10 ... 15.
	 * - flexible_start causes it to use "url.page" instead of "url;start=page".
	 * - very importantly, cleans up the start value passed, and forces it to
	 *   be a multiple of num_per_page.
	 * - checks that start is not more than max_value.
	 * - base_url should be the URL without any start parameter on it.
	 * - uses the compactTopicPagesEnable and compactTopicPagesContiguous
	 *   settings to decide how to display the menu.
	 *
	 * an example is available near the function definition.
	 * $pageindex = constructPageIndex($scripturl . '?board=' . $board, $_REQUEST['start'], $num_messages, $maxindex, true);
	 *
	 * @param string $base_url
	 * @param int $start
	 * @param int $max_value
	 * @param int $num_per_page
	 * @param bool $flexible_start = false
	 * @param mixed[] $show associative array of option => boolean
	 */
	public function constructPageIndex($base_url, &$start, $max_value, $num_per_page, $flexible_start = false, $show = array())
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
				if ($show['all_selected'])
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
					$pageindex.= sprintf($base_link, $tmpStart, $tmpStart / $num_per_page + 1);
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
				if ($show['all_selected'])
					$pageindex .= sprintf($settings['page_index_template']['current_page'], $txt['all']);
				else
					$pageindex .= sprintf(str_replace('.%1$d', '.%1$s', $base_link), '0;all', str_replace('{all_txt}', $txt['all'], $settings['page_index_template']['all']));
			}
		}

		return $pageindex;
	}
}