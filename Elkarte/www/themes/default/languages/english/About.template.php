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

function template_staff()
{
    global $context, $scripturl, $memberContext;

    foreach ($context['staff_list'] as $group)
    {
        echo '<div class="staff_group">
            <h2 id="group', $group['id'], '"', !empty($group['color']) ? ' style="color: ' . $group['color'] . '"' : '', '>', $group['name'], '</h2>';

        if (!empty($group['description']))
        {
            echo '<div class="group_desc">', $group['description'], '</div>';
        }

        echo '<ul class="group_members">';
        foreach ($group['members'] as $id => $dummy)
        {
            $member = $memberContext[$id];

            echo '
                <li class="member">
                    <a href="' . $scripturl . '?action=profile;u=' . $member['id'] . '">', !empty($member['avatar']['image']) ? $member['avatar']['image'] . '<br>' : '',
                    $member['name'], '</a>
                </li>';
        }

        echo '</div>';
    }
}

/**
 * Before showing users a registration form, show them the registration agreement.
 */
function template_registration_agreement()
{
    global $context, $scripturl, $txt;

    echo '
		<form action="', $scripturl, '?action=register" method="post" accept-charset="UTF-8" id="registration">
			<h2 class="category_header">', $txt['registration_agreement'];

    if (!empty($context['languages']))
    {
        if (count($context['languages']) === 1)
            foreach ($context['languages'] as $lang_key => $lang_val)
                echo '
				<input type="hidden" name="lngfile" value="', $lang_key, '" />';
        else
        {
            echo '
				<select onchange="this.form.submit()" class="floatright" name="lngfile">';

            foreach ($context['languages'] as $lang_key => $lang_val)
                echo '
					<option value="', $lang_key, '"', empty($lang_val['selected']) ? '' : ' selected="selected"', '>', $lang_val['name'], '</option>';

            echo '
				</select>';
        }
    }

    echo '
			</h2>
			<div class="well">
				<p>', $context['agreement'], '</p>
			</div>
			<div id="confirm_buttons" class="submitbutton centertext">';

    // Age restriction in effect?
    if ($context['show_coppa'])
        echo '
				<input type="submit" name="accept_agreement" value="', $context['coppa_agree_above'], '" />
				<br /><br />
				<input type="submit" name="accept_agreement_coppa" value="', $context['coppa_agree_below'], '" />';
    else
        echo '
				<input type="submit" name="accept_agreement" value="', $txt['agreement_agree'], '" />';

    if ($context['show_contact_button'])
        echo '
				<br /><br />
				<input type="submit" name="show_contact" value="', $txt['contact'], '" />';

    echo '
			<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
			<input type="hidden" name="', $context['register_token_var'], '" value="', $context['register_token'], '" />
			</div>
			<input type="hidden" name="step" value="1" />
		</form>';
}

/**
 * Interface for contact form.
 */
function template_contact_form()
{
    global $context, $scripturl, $txt;

    echo '
		<h2 class="category_header">', $txt['admin_contact_form'], '</h2>
		<form id="contact_form" class="content" action="', $scripturl, '?action=about;sa=contact" method="post" accept-charset="UTF-8">
			<div class="content">';

    if (!empty($context['errors']))
        echo '
				<div class="errorbox">', $txt['errors_contact_form'], ': <ul><li>', implode('</li><li>', $context['errors']), '</li></ul></div>';

    echo '
				<dl class="settings">
					<dt>
						<label for="emailaddress">', $txt['admin_register_email'], '</label>
					</dt>
					<dd>
						<input type="email" name="emailaddress" id="emailaddress" value="', !empty($context['emailaddress']) ? $context['emailaddress'] : '', '" tabindex="', $context['tabindex']++, '" />
					</dd>
					<dt>
						<label for="contactmessage">', $txt['contact_your_message'], '</label>
					</dt>
					<dd>
						<textarea id="contactmessage" name="contactmessage" cols="50" rows="10" tabindex="', $context['tabindex']++, '">', !empty($context['contactmessage']) ? $context['contactmessage'] : '', '</textarea>
					</dd>';

    if (!empty($context['require_verification']))
    {
        template_verification_controls($context['visual_verification_id'], '
					<dt>
							' . $txt['verification'] . ':
					</dt>
					<dd>
							', '
					</dd>');
    }

    echo '
				</dl>
				<hr />
				<div class="submitbutton" >
					<input type="submit" value="', $txt['sendtopic_send'], '" name="send" tabindex="', $context['tabindex']++, '" />
					<input type="hidden" name="sa" value="reservednames" />
					<input type="hidden" name="', $context['session_var'], '" value="', $context['session_id'], '" />
					<input type="hidden" name="', $context['contact_token_var'], '" value="', $context['contact_token'], '" />
				</div>
			</div>
		</form>';
}

/**
 * Show a success page when contact form is submitted.
 */
function template_contact_form_done()
{
    global $txt;

    echo '
		<h2 class="category_header">', $txt['admin_contact_form'], '</h2>
		<div class="content">', $txt['contact_thankyou'], '</div>';
}