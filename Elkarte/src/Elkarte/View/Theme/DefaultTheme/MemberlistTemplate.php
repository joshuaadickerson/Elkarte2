<?php

class MemberlistTemplate extends AbstractTemplate
{
	/**
	 * You can add CSS, Javascript, language files, register subtemplates
	 * @param ThemeInterface $theme
	 */
	public function postRegister(ThemeInterface $theme)
	{
		$theme->addInlineJavascript('
			function toggle_mlsearch_opt()
			{
				$(\'body\').on(\'click\', mlsearch_opt_hide);
				$(\'#mlsearch_options\').slideToggle(\'fast\');
			}

			function mlsearch_opt_hide(ev)
			{
				if (ev.target.id === \'mlsearch_options\' || ev.target.id === \'mlsearch_input\')
					return;

				$(\'body\').off(\'click\', mlsearch_opt_hide);
				$(\'#mlsearch_options\').slideToggle(\'fast\');
			}
		');
	}

	public function pages_and_buttons_above()
	{
		$extra = '
		<form id="mlsearch" action="' . $this->scripturl . '?action=memberlist;sa=search" method="post" accept-charset="UTF-8">
			<ul class="floatright">
				<li>
					<input id="mlsearch_input" onfocus="toggle_mlsearch_opt();" type="text" name="search" value="" class="input_text" placeholder="' . $this->txt['search'] . '" />&nbsp;
					<input type="submit" name="search2" value="' . $this->txt['search'] . '" class="button_submit" />
					<ul id="mlsearch_options">';

		foreach ($this->context['search_fields'] as $id => $title)
		{
			$extra .= '
					<li class="mlsearch_option">
						<label for="fields-' . $id . '"><input type="checkbox" name="fields[]" id="fields-' . $id . '" value="' . $id . '" ' . (in_array($id, $this->context['search_defaults']) ? 'checked="checked"' : '') . ' class="input_check floatright" />' . $title . '</label>
					</li>';
		}

		$extra .= '
					</ul>
				</li>
			</ul>
		</form>';
/**
 * !!! Notice that here I use $this->subtemplate('pagesection'), but I could use $this->pagesection()
 * !!! because I am assuming pagesection() would be registered as a global subtemplate
 * !!! Since I know it is in the Index template, I could also do $this->template('Index')->pagesection() or $this->subtemplate('pagesection', 'Index')
 */
		$this->subtemplate('pagesection', 'memberlist_buttons', 'right', array('extra' => $extra));
	}

	/**
	 * Displays a sortable listing of all members registered on the forum.
	 */
	public function memberlist()
	{
		echo '
		<div id="memberlist">
			<h2 class="category_header">
					<span class="floatleft">', $this->txt['members_list'], '</span>';

		if (!isset($this->context['old_search']))
			echo '
					<span class="floatright" letter_links>', $this->context['letter_links'], '</span>';

		echo '
			</h2>
			<table class="table_grid">
				<thead>
					<tr class="table_head">';

		// Display each of the column headers of the table.
		foreach ($this->context['columns'] as $key => $column)
		{
			// This is a selected column, so underline it or some such.
			if ($column['selected'])
				echo '
						<th scope="col"', isset($column['class']) ? ' class="' . $column['class'] . '"' : '', ' style="width: auto; white-space: nowrap"' . (isset($column['colspan']) ? ' colspan="' . $column['colspan'] . '"' : '') . '>
							<a href="' . $column['href'] . '" rel="nofollow">' . $column['label'] . '</a><img class="sort" src="' . $this->settings['images_url'] . '/sort_' . $this->context['sort_direction'] . '.png" alt="" />
						</th>';
			// This is just some column... show the link and be done with it.
			else
				echo '
						<th scope="col" ', isset($column['class']) ? ' class="' . $column['class'] . '"' : '', isset($column['width']) ? ' style="width:' . $column['width'] . '"' : '', isset($column['colspan']) ? ' colspan="' . $column['colspan'] . '"' : '', '>
							', $column['link'], '
						</th>';
		}

		echo '
					</tr>
				</thead>
				<tbody>';

		// Assuming there are members loop through each one displaying their data.
		$alternate = true;
		if (!empty($this->context['members']))
		{
			foreach ($this->context['members'] as $member)
			{
				echo '
					<tr class="', $alternate ? 'alternate_' : 'standard_', 'row"', empty($member['sort_letter']) ? '' : ' id="letter' . $member['sort_letter'] . '"', '>';

				foreach ($this->context['columns'] as $column => $values)
				{
					if (isset($member[$column]))
					{
						if ($column == 'online')
						{
							echo '
							<td>
								', $this->context['can_send_pm'] ? '<a href="' . $member['online']['href'] . '" title="' . $member['online']['text'] . '">' : '', $this->settings['use_image_buttons'] ? '<img src="' . $member['online']['image_href'] . '" alt="' . $member['online']['text'] . '" class="centericon" />' : $member['online']['label'], $this->context['can_send_pm'] ? '</a>' : '', '
							</td>';
							continue;
						}
						elseif ($column == 'email_address')
						{
							echo '
							<td>', $member['show_email'] == 'no' ? '' : '<a href="' . $this->scripturl . '?action=emailuser;sa=email;uid=' . $member['id'] . '" rel="nofollow"><img src="' . $this->settings['images_url'] . '/profile/email_sm.png" alt="' . $this->txt['email'] . '" title="' . $this->txt['email'] . ' ' . $member['name'] . '" /></a>', '</td>';
							continue;
						}
						else
							echo '
							<td>', $member[$column], '</td>';
					}
					// Any custom fields on display?
					elseif (!empty($this->context['custom_profile_fields']['columns']) && isset($this->context['custom_profile_fields']['columns'][$column]))
					{
							echo '
								<td>', $member['options'][substr($column, 5)], '</td>';
					}
				}

				echo '
						</tr>';

				$alternate = !$alternate;
			}
		}
		// No members?
		else
			$this->call('noMembers');

		echo '
				</tbody>
			</table>';
	}

	public function noMembers()
	{
		echo '
					<tr>
						<td colspan="', $this->context['colspan'], '" class="standard_row">', $this->txt['search_no_results'], '</td>
					</tr>';
	}

	public function pages_and_buttons_below()
	{
		// If it is displaying the result of a search show a "search again" link to edit their criteria.
		if (isset($this->context['old_search']))
			$extra = '
				<a class="linkbutton_right" href="' . $this->scripturl . '?action=memberlist;sa=search;search=' . $this->context['old_search_value'] . '">' . $this->txt['mlist_search_again'] . '</a>';
		else
			$extra = '';

		// Show the page numbers again. (makes 'em easier to find!)
		$this->pagesection(false, false, array('extra' => $extra));

		echo '
		</div>';
	}
}