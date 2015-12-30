<?php

/**
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
 * This function reads from the database the addons credits,
 * and returns them in an array for display in credits section of the site.
 * The addons copyright, license, title information are those saved from <license>
 * and <credits> tags in package.xml.
 *
 * @return array
 */
function addonsCredits()
{
    global $txt;

    $db = database();

    if (($credits = cache_get_data('addons_credits', 86400)) === null)
    {
        $credits = array();
        $request = $db->query('substring', '
			SELECT version, name, credits
			FROM {db_prefix}log_packages
			WHERE install_state = {int:installed_adds}
				AND credits != {string:empty}
				AND SUBSTRING(filename, 1, 9) != {string:old_patch_name}
				AND SUBSTRING(filename, 1, 9) != {string:patch_name}',
            array(
                'installed_adds' => 1,
                'old_patch_name' => 'smf_patch',
                'patch_name' => 'elk_patch',
                'empty' => '',
            )
        );

        while ($row = $db->fetch_assoc($request))
        {
            $credit_info = unserialize($row['credits']);

            $copyright = empty($credit_info['copyright']) ? '' : $txt['credits_copyright'] . ' &copy; ' . Util::htmlspecialchars($credit_info['copyright']);
            $license = empty($credit_info['license']) ? '' : $txt['credits_license'] . ': ' . Util::htmlspecialchars($credit_info['license']);
            $version = $txt['credits_version'] . '' . $row['version'];
            $title = (empty($credit_info['title']) ? $row['name'] : Util::htmlspecialchars($credit_info['title'])) . ': ' . $version;

            // build this one out and stash it away
            $name = empty($credit_info['url']) ? $title : '<a href="' . $credit_info['url'] . '">' . $title . '</a>';
            $credits[] = $name . (!empty($license) ? ' | ' . $license : '') . (!empty($copyright) ? ' | ' . $copyright : '');
        }
        cache_put_data('addons_credits', $credits, 86400);
    }

    return $credits;
}


/**
 * Prepare credits for display.
 *
 * - This is a helper function, used by admin panel for credits and support page, and by the credits page.
 */
function prepareCreditsData()
{
    global $txt;

    $credits = array();

    // Don't blink. Don't even blink. Blink and you're dead.
    $credits['credits'] = array(
        array(
            'pretext' => $txt['credits_intro'],
            'title' => $txt['credits_contributors'],
            'groups' => array(
                array(
                    'title' => $txt['credits_groups_contrib'],
                    'members' => array(
                        $txt['credits_contrib_list'],
                    ),
                ),
                array(
                    'title' => $txt['credits_groups_translators'],
                    'members' => array(
                        $txt['credits_translators_message'],
                    ),
                ),
            ),
        ),
    );

    // Give credit to any graphic library's, software library's, plugins etc
    $credits['credits_software_graphics'] = array(
        'graphics' => array(
            '<a href="http://p.yusukekamiyamane.com/">Fugue Icons</a> | &copy; 2012 Yusuke Kamiyamane | These icons are licensed under a Creative Commons Attribution 3.0 License',
            '<a href="http://www.oxygen-icons.org/">Oxygen Icons</a> | These icons are licensed under <a href="http://creativecommons.org/licenses/by-sa/3.0/">CC BY-SA 3.0</a>',
        ),
        'fonts' => array(
            '<a href="http://openfontlibrary.org/en/font/architect-s-daughter">Architect\'s Daughter</a> | &copy; 2010 <a href="http://kimberlygeswein.com/">Kimberly Geswein</a> | This font is licensed under the SIL Open Font License, Version 1.1',
            '<a href="http://openfontlibrary.org/en/font/klaudia-and-berenika">Berenika</a> | &copy; 2011 wmk69 | This font is licensed under the SIL Open Font License, Version 1.1',
            '<a href="http://openfontlibrary.org/en/font/dotrice">Dotrice</a> | &copy; 2010 <a href="http://hisdeedsaredust.com/">Paul Flo Williams</a> | This font is licensed under the SIL Open Font License, Version 1.1',
            '<a href="http://fontawesome.io/">Font Awesome</a> | Created by Dave Gandy | This font is licensed under the SIL Open Font License, Version 1.1',
            '<a href="http://openfontlibrary.org/en/font/press-start-2p">Press Start 2P</a> | &copy; 2012 Cody "CodeMan38" Boisclair | This font is licensed under the SIL Open Font License, Version 1.1',
            '<a href="http://openfontlibrary.org/en/font/vds">VDS</a> | &copy; 2012 <a href="http://www.wix.com/artmake1/artmaker">artmaker</a> | This font is licensed under the SIL Open Font License, Version 1.1',
            '<a href="http://openfontlibrary.org/en/font/vshexagonica-v1-0-1">vSHexagonica</a> | &copy; 2012 T.B. von Strong | This font is licensed under the SIL Open Font License, Version 1.1',
        ),
        'software' => array(
            '<a href="http://ichord.github.com/At.js">At.js</a> | &copy; Chord Luo | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="http://bad-behavior.ioerror.us/">Bad Behavior</a> | &copy; Michael Hampton | Licensed under <a href="http://opensource.org/licenses/LGPL-3.0">GNU Lesser General Public License</a>',
            '<a href="https://code.google.com/p/google-code-prettify/">Google Code Prettify</a> | Licensed under <a href="http://opensource.org/licenses/Apache-2.0">Apache License, Version 2.0</a>',
            '<a href="http://cherne.net/brian/resources/jquery.hoverIntent.html">hoverIntent</a> | &copy; Brian Cherne | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="http://pajhome.org.uk/crypt/md5">Javascript Crypt</a> | &copy; Angel Marin, Paul Johnston | Licensed under <a href="http://opensource.org/licenses/BSD-3-Clause">The BSD License</a>',
            '<a href="http://jquery.org/">JQuery</a> | &copy; jQuery Foundation and other contributors | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="http://jqueryui.com/">JQuery UI</a> | &copy; jQuery Foundation and other contributors | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="https://github.com/tchwork/jsqueeze">Jsqueeze</a> &copy Nicolas Grekas| Licensed under <a href="http://www.apache.org/licenses/LICENSE-2.0">Apache License, Version 2.0</a>',
            '<a href="http://www.openwall.com/phpass/">PH Pass</a> | Author: Solar Designer | Placed in the public domain</a>',
            '<a href="http://www.sceditor.com/">SCEditor</a> | &copy; Sam Clarke | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="http://sourceforge.net/projects/simplehtmldom/">Simple HTML DOM</a> | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="http://www.simplemachines.org/">Simple Machines</a> | &copy; Simple Machines | Licensed under <a href="http://www.simplemachines.org/about/smf/license.php">The BSD License</a>',
            '<a href="http://users.tpg.com.au/j_birch/plugins/superfish/">Superfish</a> | &copy; Joel Birch | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="https://github.com/tubalmartin/YUI-CSS-compressor-PHP-port">YUI-CSS compressor (PHP port)</a> | &copy; Yahoo! Inc | Licensed under <a href="http://opensource.org/licenses/BSD-3-Clause">The BSD License</a>',
            '<a href="http://lab.ejci.net/favico.js/">favico.js</a> | &copy; Miroslav Magda | Licensed under <a href="http://opensource.org/licenses/MIT">The MIT License (MIT)</a>',
            '<a href="https://github.com/ttsvetko/HTML5-Desktop-Notifications">HTML5 Desktop Notifications</a> | &copy; Tsvetan Tsvetkov | Licensed under <a href="http://www.apache.org/licenses/LICENSE-2.0">Apache License, Version 2.0</a>',
        ),
    );

    // Add-ons authors: to add credits, the simpler and better way is to add in your package.xml the <credits> <license> tags.
    // Support for addons that use the <credits> tag via the package manager
    $credits['credits_addons'] = addonsCredits();

    // An alternative for addons credits is to use a hook.
    call_integration_hook('integrate_credits', array(&$credits));

    // Copyright information
    $credits['copyrights']['elkarte'] = '&copy; 2012 - 2014 ElkArte Forum contributors';
    return $credits;
}

function loadStaffList(array $groups)
{
    global $context;
    require_once(SUBSDIR . '/Membergroups.subs.php');

    $members = array();
    $temp = getMembersByGroups($groups);

    // Sort it
    foreach ($groups as $group)
    {
        if (isset($temp[$group]))
        {
            $context['staff_list'][$group] = $temp[$group];
        }
    }

    // Get the unique member ids from it
    foreach($context['staff_list'] as $group)
    {
        foreach($group['members'] as $id => $member)
        {
            $members[$id] = $member;
        }
    }

    $context['staff_ids'] = array_keys($members);
}