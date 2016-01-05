<?php

define('TESTDIR', dirname(__FILE__) . '/');

require_once('simpletest/autorun.php');

// SSI mode should work for most tests. (core and subs)
// For web tester tests, it should not be used.
// For Install/upgrade, if we even can test those, SSI is a no-no.
// Note: SSI mode is our way to work-around limitations in defining a 'unit' of behavior,
// since the code is very tightly coupled. If possible, and where possible, tests should
// not use SSI either.

// Might wanna make two or three different suites.
require_once(TESTDIR . '../Settings.php');
global $test_enabled;

echo "WARNING! Tests may work directly with the local database. DON'T run them on ANY other than test installs!\n";
echo "To run the tests, set test_enabled = 1 in Settings.php file.\n";

if (empty($test_enabled))
	die('Testing disabled.');

/**
 * All tests suite. This suite adds all files/classes/folders currently being tested.
 * Many of the tests are integration tests, strictly speaking, since they use both SSI
 * and database work.
 *
 * @todo set up a testing database, i.e. on sqlite maybe, or mysql, like populate script, at the
 * beginning of the suite, and remove or clean it up completely at the end of it.
 *
 * To run all tests, execute php all_tests.php in tests directory
 * Or, scripturl/tests/all_tests.php
 */
class AllTests extends TestSuite
{
	function AllTests()
	{
		$this->TestSuite('All tests');

		// Controllers (web tests)
		$this->addFile(TESTDIR . 'Sources/Controllers/TestAuth.php');
		$this->addFile(TESTDIR . 'Sources/Controllers/TestRegister.php');

		// Admin Controllers (web tests)
		$this->addFile(TESTDIR . 'Sources/Admin/TestManageBoardsSettings.php');
		$this->addFile(TESTDIR . 'Sources/Admin/TestManagePostsSettings.php');

		// Install
		if (!defined('SKIPINSTALL'))
			$this->addFile(TESTDIR . 'Install/TestInstall.php');
		$this->addFile(TESTDIR . 'Install/TestDatabase.php');

		// data integrity
		$this->addFile(TESTDIR . 'Sources/TestFiles.php');
		$this->addFile(TESTDIR . 'Sources/TestLanguageStrings.php');

		// core Sources
		$this->addFile(TESTDIR . 'Sources/TestLogging.php');
		$this->addFile(TESTDIR . 'Sources/TestDispatcher.php');
		$this->addFile(TESTDIR . 'Sources/TestRequest.php');

		// subs APIs
		$this->addFile(TESTDIR . 'Sources/subs/TestBoards.subs.php');
		$this->addFile(TESTDIR . 'Sources/subs/TestPoll.subs.php');
		$this->addFile(TESTDIR . 'Sources/subs/TestBBC.subs.php');
		$this->addFile(TESTDIR . 'Sources/subs/TestHTML2BBC.subs.php');
		$this->addFile(TESTDIR . 'Sources/subs/TestValidator.subs.php');
		$this->addFile(TESTDIR . 'Sources/subs/TestLike.subs.php');

		// caching
		$this->addFile(TESTDIR . 'Sources/subs/TestCache.class.php');
	}
}
