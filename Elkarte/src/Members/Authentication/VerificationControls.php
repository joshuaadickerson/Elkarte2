<?php

/**
 * This file contains those functions specific to the various verification controls
 * used to challenge users, and hopefully robots as well.
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

if (!defined('ELK'))
	die('No access...');

/**
 * Simple function that loads and returns all the verification controls known to Elk
 */
function loadVerificationControls()
{
	$known_verifications = array(
		'captcha',
		'questions',
		'emptyfield'
	);

	// Let integration add some more controls
	$GLOBALS['elk']['hooks']->hook('control_verification', array(&$known_verifications));

	return $known_verifications;
}

/**
 * Create a anti-bot verification control?
 *
 * @param mixed[] $verificationOptions
 * @param bool $do_test = false If we are validating the input to a verification control
 */
function create_control_verification(&$verificationOptions, $do_test = false)
{
	global $context;

	// We need to remember this because when failing the page is reloaded and the
	// code must remain the same (unless it has to change)
	static $all_instances = array();

	// Always have an ID.
	assert(isset($verificationOptions['id']));
	$isNew = !isset($context['controls']['verification'][$verificationOptions['id']]);

	if ($isNew)
	{
		$context['controls']['verification'][$verificationOptions['id']] = array(
			'id' => $verificationOptions['id'],
			'max_errors' => isset($verificationOptions['max_errors']) ? $verificationOptions['max_errors'] : 3,
			'render' => false,
		);
	}
	$thisVerification = &$context['controls']['verification'][$verificationOptions['id']];

	if (!isset($_SESSION[$verificationOptions['id'] . '_vv']))
		$_SESSION[$verificationOptions['id'] . '_vv'] = array();

	$force_refresh = ((!empty($_SESSION[$verificationOptions['id'] . '_vv']['did_pass']) || empty($_SESSION[$verificationOptions['id'] . '_vv']['count']) || $_SESSION[$verificationOptions['id'] . '_vv']['count'] > 3) && empty($verificationOptions['dont_refresh']));
	if (!isset($all_instances[$verificationOptions['id']]))
	{
		$known_verifications = loadVerificationControls();
		$all_instances[$verificationOptions['id']] = array();

		foreach ($known_verifications as $verification)
		{
			$class_name = 'Verification_Controls_' . ucfirst($verification);
			$current_instance = new $class_name($verificationOptions);

			// If there is anything to show, otherwise forget it
			if ($current_instance->showVerification($isNew, $force_refresh))
				$all_instances[$verificationOptions['id']][$verification] = $current_instance;
		}
	}

	$instances = &$all_instances[$verificationOptions['id']];

	// Is there actually going to be anything?
	if (empty($instances))
		return false;
	elseif (!$isNew && !$do_test)
		return true;

	$verification_errors = ErrorContext::context($verificationOptions['id']);
	$increase_error_count = false;

	// Start with any testing.
	if ($do_test)
	{
		// This cannot happen!
		if (!isset($_SESSION[$verificationOptions['id'] . '_vv']['count']))
			$GLOBALS['elk']['errors']->fatal_lang_error('no_access', false);

		foreach ($instances as $instance)
		{
			$outcome = $instance->doTest();
			if ($outcome !== true)
			{
				$increase_error_count = true;
				$verification_errors->addError($outcome);
			}
		}
	}

	// Any errors means we refresh potentially.
	if ($increase_error_count)
	{
		if (empty($_SESSION[$verificationOptions['id'] . '_vv']['errors']))
			$_SESSION[$verificationOptions['id'] . '_vv']['errors'] = 0;
		// Too many errors?
		elseif ($_SESSION[$verificationOptions['id'] . '_vv']['errors'] > $thisVerification['max_errors'])
			$force_refresh = true;

		// Keep a track of these.
		$_SESSION[$verificationOptions['id'] . '_vv']['errors']++;
	}

	// Are we refreshing then?
	if ($force_refresh)
	{
		// Assume nothing went before.
		$_SESSION[$verificationOptions['id'] . '_vv']['count'] = 0;
		$_SESSION[$verificationOptions['id'] . '_vv']['errors'] = 0;
		$_SESSION[$verificationOptions['id'] . '_vv']['did_pass'] = false;
	}

	foreach ($instances as $test => $instance)
	{
		$instance->createTest($force_refresh);
		$thisVerification['test'][$test] = $instance->prepareContext();
		if ($instance->hasVisibleTemplate())
			$thisVerification['render'] = true;
	}

	$_SESSION[$verificationOptions['id'] . '_vv']['count'] = empty($_SESSION[$verificationOptions['id'] . '_vv']['count']) ? 1 : $_SESSION[$verificationOptions['id'] . '_vv']['count'] + 1;

	// Return errors if we have them.
	if ($verification_errors->hasErrors())
	{
		// @todo temporary until the error class is implemented in register
		$error_codes = array();
		foreach ($verification_errors->getErrors() as $errors)
			foreach ($errors as $error)
				$error_codes[] = $error;

		return $error_codes;
	}
	// If we had a test that one, make a note.
	elseif ($do_test)
		$_SESSION[$verificationOptions['id'] . '_vv']['did_pass'] = true;

	// Say that everything went well chaps.
	return true;
}