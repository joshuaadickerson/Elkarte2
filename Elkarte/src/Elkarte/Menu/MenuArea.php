<?php

class MenuArea
{
	public $permissions = [];
	public $label = '';
	public $file = '';
	public $callback;
	public $url = '';
	public $icon = '';
	public $icon_class = '';
	public $enabled = true;
	public $hidden = false;
	public $select = '';
	public $subsections = [];

	/*
	 * 	  array $permission:  Array of permissions to determine who can access this area.
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
	 */
}