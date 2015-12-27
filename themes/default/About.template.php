<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

/**
 * Display the credits page.
 */
function template_credits()
{
    global $context, $txt;

    // The most important part - the credits :P.
    echo '
	<div id="credits">
		<h2 class="category_header">', $txt['credits'], '</h2>';

    foreach ($context['credits'] as $section)
    {
        if (isset($section['pretext']))
            echo '
		<div class="content">
			', $section['pretext'], '
		</div>';

        if (isset($section['title']))
            echo '
			<h2 class="category_header">', $section['title'], '</h2>';

        echo '
		<div class="content">
			<dl>';

        foreach ($section['groups'] as $group)
        {
            if (isset($group['title']))
                echo '
				<dt>
					<strong>', $group['title'], '</strong>
				</dt>
				<dd>';

            // Try to make this read nicely.
            if (count($group['members']) <= 2)
                echo implode(' ' . $txt['credits_and'] . ' ', $group['members']);
            else
            {
                $last_peep = array_pop($group['members']);
                echo implode(', ', $group['members']), ' ', $txt['credits_and'], ' ', $last_peep;
            }

            echo '
				</dd>';
        }

        echo '
			</dl>';

        if (isset($section['posttext']))
            echo '
			<p><em>', $section['posttext'], '</em></p>';

        echo '
		</div>';
    }

    // Other software and graphics
    if (!empty($context['credits_software_graphics']))
    {
        echo '
		<h2 class="category_header">', $txt['credits_software_graphics'], '</h2>
		<div class="content">';

        foreach ($context['credits_software_graphics'] as $section => $credits)
            echo '
			<dl>
				<dt>
					<strong>', $txt['credits_' . $section], '</strong>
				</dt>
				<dd>', implode('</dd><dd>', $credits), '</dd>
			</dl>';

        echo '
		</div>';
    }

    // Addons credits, copyright, license
    if (!empty($context['credits_addons']))
    {
        echo '
		<h2 class="category_header">', $txt['credits_addons'], '</h2>
		<div class="content">';

        echo '
			<dl>
				<dt>
					<strong>', $txt['credits_addons'], '</strong>
				</dt>
				<dd>', implode('</dd><dd>', $context['credits_addons']), '</dd>
			</dl>';

        echo '
		</div>';
    }

    // ElkArte !
    echo '
		<h2 class="category_header">', $txt['credits_copyright'], '</h2>
		<div class="content">
			<dl>
				<dt>
					<strong>', $txt['credits_forum'], '</strong>
				</dt>
				<dd>', $context['copyrights']['elkarte'];

    echo '
				</dd>
			</dl>';

    if (!empty($context['copyrights']['addons']))
    {
        echo '
			<dl>
				<dt>
					<strong>', $txt['credits_addons'], '</strong>
				</dt>
				<dd>', implode('</dd><dd>', $context['copyrights']['addons']), '</dd>
			</dl>';
    }

    echo '
		</div>
	</div>';
}