<?php

/**
 * This file contains a standard way of displaying side/drop down menus.
 *
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

namespace Elkarte\Elkarte\Menu;

use Elkarte\Elkarte\Theme\Templates;
use Elkarte\Elkarte\Theme\TemplateLayers;
use Elkarte\Elkarte\Http\HttpReq;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\Events\EventManager;

class Menu
{
	public $id = 1;
	protected $menu = [];

	protected $current_section;
	protected $current_area;
	protected $current_subsection;

	protected $permission_set;
	protected $first_sa;
	protected $last_sa;
	protected $backup_area;
	protected $include_data = [];
	protected $found_section = false;

	protected $layers;
	protected $templates;
	protected $http_req;

	public function __construct(TemplateLayers $layers, Templates $templates, HttpReq $req, Hooks $hooks)
	{
		global $settings;

		$this->layers = $layers;
		$this->templates = $templates;
		$this->http_req = $req;
		$this->hooks = $hooks;

		// Every menu gets a unique ID, these are shown in first in, first out order.
		$context['max_menu_id'] = isset($context['max_menu_id']) ? $context['max_menu_id'] + 1 : 1;
		$this->id = $context['max_menu_id'];
		$context['menu_data_' . $this->id] = array();

		// Work out where we should get our images from.
		$context['menu_image_path'] = file_exists($settings['theme_dir'] . '/images/Admin/change_menu.png') ? $settings['images_url'] . '/Admin' : $settings['default_images_url'] . '/Admin';
	}

	/**
	 * Create a menu.
	 *
	 * @param mixed[] $menuData the menu array
	 * @param mixed[] $menuOptions an array of options that can be used to override some default behaviours.
	 *   It can accept the following indexes:
	 *    - action                    => overrides the default action
	 *    - current_area              => overrides the current area
	 *    - extra_url_parameters      => an array or pairs or parameters to be added to the url
	 *    - disable_url_session_check => (boolean) if true the session var/id are omitted from the url
	 *    - base_url                  => an alternative base url
	 *    - menu_type                 => alternative menu types?
	 *    - can_toggle_drop_down      => (boolean) if the menu can "toggle"
	 *    - template_name             => an alternative template to load (instead of Generic)
	 *    - layer_name                => alternative layer name for the menu
	 * @return mixed[]|false
	 */
	function create($menuData, $menuOptions = array())
	{
		global $context, $settings, $options, $scripturl, $user_info;

		/**
		 * Note menuData is array of form:
		 *
		 * Possible fields:
		 *  For Section:
		 *    string $title:     Section title.
		 *    bool $enabled:     Should section be shown?
		 *    array $areas:      Array of areas within this section.
		 *    array $permission: Permission required to access the whole section.
		 *  For Areas:
		 *    array $permission:  Array of permissions to determine who can access this area.
		 *    string $label:      Optional text string for link (Otherwise $txt[$index] will be used)
		 *    string $file:       Name of source file required for this area.
		 *    string $function:   Function to call when area is selected.
		 *    string $custom_url: URL to use for this menu item.
		 *    string $icon:       File name of an icon to use on the menu, if using the sprite class, set as transparent.png
		 *    string $class:      Class name to apply to the icon img, used to apply a sprite icon
		 *    bool $enabled:      Should this area even be accessible?
		 *    bool $hidden:       Should this area be visible?
		 *    string $select:     If set this item will not be displayed - instead the item indexed here shall be.
		 *    array $subsections: Array of subsections from this area.
		 *
		 *  For Subsections:
		 *    string 0:     Text label for this subsection.
		 *    array 1:      Array of permissions to check for this subsection.
		 *    bool 2:       Is this the default subaction - if not set for any will default to first...
		 *    bool enabled: Bool to say whether this should be enabled or not.
		 *    array active: Set the button active for other subsections.
		 */

		// This will be all the data for this menu - and we'll make a shortcut to it to aid readability here.
		$this->menu = &$context['menu_data_' . $this->id];

		// Allow extend *any* menu with a single hook
		if (!empty($menuOptions['hook']))
			$GLOBALS['elk']['hooks']->hook('' . $menuOptions['hook'] . '_areas', array(&$menuData, &$menuOptions));

		// What is the general action of this menu (i.e. $scripturl?action=XXXX.
		$this->menu['current_action'] = isset($menuOptions['action']) ? $menuOptions['action'] : $context['current_action'];

		// What is the current area selected?
		if (isset($menuOptions['current_area']) || isset($this->http_req->query->area))
			$this->menu['current_area'] = isset($menuOptions['current_area']) ? $menuOptions['current_area'] : $this->http_req->query->area;

		// Build a list of additional parameters that should go in the URL.
		$this->menu['extra_parameters'] = '';
		if (!empty($menuOptions['extra_url_parameters']))
		{
			foreach ($menuOptions['extra_url_parameters'] as $key => $value)
			{
				$this->menu['extra_parameters'] .= ';' . $key . '=' . $value;
			}
		}

		// Only include the session ID in the URL if it's strictly necessary.
		if (empty($menuOptions['disable_url_session_check']))
			$this->menu['extra_parameters'] .= ';' . $context['session_var'] . '=' . $context['session_id'];

		// This is necessary only in profile (at least for the core), but we do it always because it's easier
		$this->permission_set = !empty($context['user']['is_owner']) ? 'own' : 'any';

		// Now setup the context correctly.
		foreach ($menuData as $section_id => $section)
		{
			$this->addSection($section_id, $section);
		}

		// Should we use a custom base url, or use the default?
		$this->menu['base_url'] = isset($menuOptions['base_url']) ? $menuOptions['base_url'] : $scripturl . '?action=' . $this->menu['current_action'];

		// If there are sections quickly goes through all the sections to check if the base menu has an url
		if (!empty($this->menu['current_section']))
		{
			$this->menu['sections'][$this->menu['current_section']]['selected'] = true;
			$this->menu['sections'][$this->menu['current_section']]['areas'][$this->menu['current_area']]['selected'] = true;
			if (!empty($this->menu['sections'][$this->menu['current_section']]['areas'][$this->menu['current_area']]['subsections'][$context['current_subaction']]))
			{
				$this->menu['sections'][$this->menu['current_section']]['areas'][$this->menu['current_area']]['subsections'][$context['current_subaction']]['selected'] = true;
			}

			foreach ($this->menu['sections'] as $section_id => $section)
			{
				foreach ($section['areas'] as $area_id => $area)
				{
					if (!isset($this->menu['sections'][$section_id]['url']))
					{
						$this->menu['sections'][$section_id]['url'] = isset($area['url']) ? $area['url'] : $this->menu['base_url'] . ';area=' . $area_id;
						break;
					}
				}
			}
		}

		// If we didn't find the area we were looking for go to a default one.
		if (isset($this->backup_area) && empty($this->found_section))
			$this->menu['current_area'] = $this->backup_area;

		// If still no data then return - nothing to show!
		if (empty($this->menu['sections'])) {
			// Never happened!
			$context['max_menu_id']--;
			if ($context['max_menu_id'] == 0)
				unset($context['max_menu_id']);

			return false;
		}

		// What type of menu is this?
		if (empty($menuOptions['menu_type'])) {
			$menuOptions['menu_type'] = '_' . (empty($options['use_sidebar_menu']) ? 'dropdown' : 'sidebar');
			$this->menu['can_toggle_drop_down'] = !$user_info['is_guest'] && isset($settings['theme_version']) && $settings['theme_version'] >= 2.0;
		} else
			$this->menu['can_toggle_drop_down'] = !empty($menuOptions['can_toggle_drop_down']);

		// Almost there - load the template and add to the template layers.
		$this->templates->load(isset($menuOptions['template_name']) ? $menuOptions['template_name'] : 'GenericMenu');
		$this->menu['layer_name'] = (isset($menuOptions['layer_name']) ? $menuOptions['layer_name'] : 'generic_menu') . $menuOptions['menu_type'];
		$this->layers->add($this->menu['layer_name']);

		// Check we had something - for sanity sake.
		if (empty($this->include_data))
			return false;

		// Finally - return information on the selected item.
		$this->include_data += array(
			'current_action' => $this->menu['current_action'],
			'current_area' => $this->menu['current_area'],
			'current_section' => $this->menu['current_section'],
			'current_subsection' => !empty($this->menu['current_subsection']) ? $this->menu['current_subsection'] : '',
		);

		return $this->include_data;
	}

	protected function addSection($section_id, array $section)
	{
		// Is this enabled?
		if ((isset($section['enabled']) && $section['enabled'] == false))
		{
			return;
		}

		// Has permission check?
		if (isset($section['permission']))
		{
			// The profile menu has slightly different permissions
			if (is_array($section['permission']) && isset($section['permission']['own'], $section['permission']['any']))
			{
				if (empty($area['permission'][$this->permission_set]) || !allowedTo($section['permission'][$this->permission_set]))
				{
					return;
				}
			}
			elseif (!allowedTo($section['permission']))
			{
				return;
			}
		}

		// Now we cycle through the sections to pick the right area.
		foreach ($section['areas'] as $area_id => $area)
		{
			$this->addArea($area_id, $area, $section_id);
		}
	}

	protected function addArea($area_id, array $area, $section_id)
	{
		global $settings, $context;

		// Can we do this?
		if (!isset($area['enabled']) || $area['enabled'] != false)
		{
			// Has permission check?
			if (!empty($area['permission']))
			{
				// The profile menu has slightly different permissions
				if (is_array($area['permission']) && isset($area['permission']['own'], $area['permission']['any'])) {
					if (empty($area['permission'][$this->permission_set]) || !allowedTo($area['permission'][$this->permission_set]))
						return;
				} elseif (!allowedTo($area['permission']))
					return;
			}

			// Add it to the context... if it has some form of name!
			if (isset($area['label']) || (isset($txt[$area_id]) && !isset($area['select']))) {
				// We may want to include a file, let's find out the path
				if (!empty($area['file']))
					$area['file'] = (!empty($area['dir']) ? $area['dir'] : (!empty($menuOptions['default_include_dir']) ? $menuOptions['default_include_dir'] : CONTROLLERDIR)) . '/' . $area['file'];

				// If we haven't got an area then the first valid one is our choice.
				if (!isset($this->menu['current_area']))
				{
					$this->menu['current_area'] = $area_id;
					$this->include_data = $area;
				}

				// If this is hidden from view don't do the rest.
				if (empty($area['hidden'])) {
					// First time this section?
					if (!isset($this->menu['sections'][$section_id])) {
						if (isset($menuOptions['counters'], $section['counter']) && !empty($menuOptions['counters'][$section['counter']]))
							$section['title'] .= sprintf($settings['menu_numeric_notice'][0], $menuOptions['counters'][$section['counter']]);

						$this->menu['sections'][$section_id]['title'] = $section['title'];
					}

					$this->menu['sections'][$section_id]['areas'][$area_id] = array('label' => isset($area['label']) ? $area['label'] : $txt[$area_id]);
					if (isset($menuOptions['counters'], $area['counter']) && !empty($menuOptions['counters'][$area['counter']]))
						$this->menu['sections'][$section_id]['areas'][$area_id]['label'] .= sprintf($settings['menu_numeric_notice'][1], $menuOptions['counters'][$area['counter']]);

					// We'll need the ID as well...
					$this->menu['sections'][$section_id]['id'] = $section_id;

					// Does it have a custom URL?
					if (isset($area['custom_url']))
						$this->menu['sections'][$section_id]['areas'][$area_id]['url'] = $area['custom_url'];

					// Does this area have its own icon?
					if (isset($area['icon']))
						$this->menu['sections'][$section_id]['areas'][$area_id]['icon'] = '<img ' . (isset($area['class']) ? 'class="' . $area['class'] . '" ' : 'style="background: none"') . ' src="' . $context['menu_image_path'] . '/' . $area['icon'] . '" alt="" />&nbsp;&nbsp;';
					else
						$this->menu['sections'][$section_id]['areas'][$area_id]['icon'] = '';

					// Did it have subsections?
					if (!empty($area['subsections']))
					{
						$this->menu['sections'][$section_id]['areas'][$area_id]['subsections'] = array();
						$first_sa = $last_sa = null;
						foreach ($area['subsections'] as $sa => $sub)
						{
							$this->addSubSection($sa, $sub, $area_id, $section);
						}

						// Set which one is first, last and selected in the group.
						if (!empty($this->menu['sections'][$section_id]['areas'][$area_id]['subsections']))
						{
							$this->menu['sections'][$section_id]['areas'][$area_id]['subsections'][$context['right_to_left'] ? $last_sa : $first_sa]['is_first'] = true;
							$this->menu['sections'][$section_id]['areas'][$area_id]['subsections'][$context['right_to_left'] ? $first_sa : $last_sa]['is_last'] = true;

							if ($this->menu['current_area'] == $area_id && !isset($this->menu['current_subsection']))
								$this->menu['current_subsection'] = $first_sa;
						}
					}
				}
			}

			// Is this the current section?
			// @todo why $this->found_section is not initialized outside one of the loops? (Not sure which one lol)
			if ($this->menu['current_area'] == $area_id && empty($this->found_section))
			{
				// Only do this once?
				$this->found_section = true;

				// Update the context if required - as we can have areas pretending to be others. ;)
				$this->menu['current_section'] = $section_id;
				// @todo 'select' seems useless
				$this->menu['current_area'] = isset($area['select']) ? $area['select'] : $area_id;

				// This will be the data we return.
				$this->include_data = $area;
			} // Make sure we have something in case it's an invalid area.
			elseif (empty($this->found_section) && empty($this->include_data)) {
				$this->menu['current_section'] = $section_id;
				$this->backup_area = isset($area['select']) ? $area['select'] : $area_id;
				$this->include_data = $area;
			}
		}
	}

	protected function addSubSection($sa, array $sub, $area_id, $section_id)
	{
		global $settings;

		if ((empty($sub[1]) || allowedTo($sub[1])) && (!isset($sub['enabled']) || !empty($sub['enabled'])))
		{
			if ($this->first_sa == null)
				$this->first_sa = $sa;

			$this->menu['sections'][$section_id]['areas'][$area_id]['subsections'][$sa] = array('label' => $sub[0]);
			if (isset($menuOptions['counters'], $sub['counter']) && !empty($menuOptions['counters'][$sub['counter']]))
				$this->menu['sections'][$section_id]['areas'][$area_id]['subsections'][$sa]['label'] .= sprintf($settings['menu_numeric_notice'][2], $menuOptions['counters'][$sub['counter']]);

			// Custom URL?
			if (isset($sub['url']))
				$this->menu['sections'][$section_id]['areas'][$area_id]['subsections'][$sa]['url'] = $sub['url'];

			// A bit complicated - but is this set?
			if ($this->menu['current_area'] == $area_id) {
				// Save which is the first...
				if (empty($this->first_sa))
					$this->first_sa = $sa;

				// Is this the current subsection?
				if (isset($this->http_req->query->sa) && $this->http_req->query->sa == $sa)
					$this->menu['current_subsection'] = $sa;

				elseif (isset($sub['active']) && isset($this->http_req->query->sa) && in_array($this->http_req->query->sa, $sub['active']))
					$this->menu['current_subsection'] = $sa;

				// Otherwise is it the default?
				elseif (!isset($this->menu['current_subsection']) && !empty($sub[2]))
					$this->menu['current_subsection'] = $sa;
			}

			// Let's assume this is the last, for now.
			$this->last_sa = $sa;
		} // Mark it as disabled...
		else
			$this->menu['sections'][$section_id]['areas'][$area_id]['subsections'][$sa]['disabled'] = true;
	}

	/**
	 * Delete a menu.
	 *
	 * @param string $menu_id = 'last'
	 * @return false|null
	 */
	function destroy($menu_id = 'last')
	{
		global $context;

		$menu_name = $menu_id == 'last' && isset($context['max_menu_id']) && isset($context['menu_data_' . $context['max_menu_id']]) ? 'menu_data_' . $context['max_menu_id'] : 'menu_data_' . $menu_id;
		if (!isset($context[$menu_name]))
			return false;

		$this->layers->remove($context[$menu_name]['layer_name']);

		unset($context[$menu_name]);
	}

	/**
	 * Call the function or method for the selected menu item.
	 * $selectedMenu is the array of menu information, with the format as retrieved from createMenu()
	 *
	 * If $selectedMenu['controller'] is set, then it is a class, and $selectedMenu['function'] will be a method of it.
	 * If it is not set, then $selectedMenu['function'] is simply a function to call.
	 *
	 * @param array|string $selectedMenu
	 */
	function call($selectedMenu)
	{
		global $elk;

		// We use only $selectedMenu['function'] and
		//  $selectedMenu['controller'] if the latter is set.

		if (!empty($selectedMenu['controller']))
		{
			// 'controller' => 'ManageAttachmentsController'
			// 'function' => 'action_avatars'
			$controller = new $selectedMenu['controller']($elk, new EventManager());

			// always set up the environment
			$controller->pre_dispatch();
			// and go!
			$controller->{$selectedMenu['function']}();
		} else {
			// a single function name... call it over!
			$selectedMenu['function']();
		}
	}
}