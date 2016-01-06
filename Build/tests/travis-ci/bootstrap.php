<?php

/**
 * This file is called before PHPUnit runs any tests.  Its purpose is
 * to initiate enough functions so the testcases can run with minimal
 * setup needs.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

// If we are running functional tests as well
if (defined('PHPUNIT_SELENIUM'))
{
	require_once('/var/www/tests/Sources/Controllers/ElkArteWebTest.php');
	PHPUnit_Extensions_Selenium2TestCase::shareSession(true);
}

file_put_contents('/var/www/bootstrapcompleted.lock', '1');
