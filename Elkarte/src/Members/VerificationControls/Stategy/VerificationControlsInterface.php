<?php

namespace Elkarte\Members\VerificationControls\Strategy;

/**
 * A simple interface that defines all the methods any "Control_Verification"
 * class MUST have because they are used in the process of creating the verification
 */
interface VerificationControls
{
	/**
	 * Used to build the control and return if it should be shown or not
	 *
	 * @param boolean $isNew
	 * @param boolean $force_refresh
	 *
	 * @return boolean
	 */
	public function showVerification($isNew, $force_refresh = true);

	/**
	 * Create the actual test that will be used
	 *
	 * @param boolean $refresh
	 *
	 * @return void
	 */
	public function createTest($refresh = true);

	/**
	 * Prepare the context for use in the template
	 *
	 * @return void
	 */
	public function prepareContext();

	/**
	 * Run the test, return if it passed or not
	 *
	 * @return string|boolean
	 */
	public function doTest();

	/**
	 * If the control has a visible location on the template or if its hidden
	 *
	 * @return boolean
	 */
	public function hasVisibleTemplate();

	/**
	 * Handles the ACP for the control
	 *
	 * @return void
	 */
	public function settings();
}