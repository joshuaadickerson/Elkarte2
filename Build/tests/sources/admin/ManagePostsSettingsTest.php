<?php

/**
 * TestCase class for manage posts settings
 */
class TestManagePostsSettings extends PHPUnit_Framework_TestCase
{
	/**
	 * Initialize or add whatever necessary for these tests
	 */
	function setUp()
	{
		//
	}

	/**
	 * Test the settings for Admin search
	 */
	public function testSettings()
	{
		// Trick
		loadLanguage('Admin');

		$controller = new ManagePostsController(new EventManager());
		$controller->pre_dispatch();
		$settings = $controller->settings_search();

		// Lets see some hardcoded setting for posts management...
		$this->assertNotNull($settings);
		$this->assertTrue(in_array(array('check', 'removeNestedQuotes'), $settings));
		$this->assertTrue(in_array(array('int', 'spamWaitTime', 'postinput' => 'seconds'), $settings));
	}
}
