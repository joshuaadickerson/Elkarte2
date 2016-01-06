<?php

namespace Elkarte\Members\VerificationControls\Strategy;

/**
 * This class shows an anti spam bot box in the form
 * The proper response is to leave the field empty, bots however will see this
 * much like a session field and populate it with a value.
 *
 * Adding additional catch terms is recommended to keep bots from learning
 */
class EmptyField implements VerificationControlsInterface
{
	/**
	 * Hold the options passed to the class
	 *
	 * @var array
	 */
	private $_options = null;

	/**
	 * If its going to be used or not on a form
	 *
	 * @var boolean
	 */
	private $_empty_field = null;

	/**
	 * Holds a randomly generated field name
	 *
	 * @var string
	 */
	private $_field_name = null;

	/**
	 * If the validation test has been run
	 *
	 * @var boolean
	 */
	private $_tested = false;

	/**
	 * What the user may have entered in the field
	 *
	 * @var string
	 */
	private $_user_value = null;

	/**
	 * Hash value used to generate the field name
	 *
	 * @var string
	 */
	private $_hash = null;

	/**
	 * Array of terms used in building the field name
	 * @var string[]
	 */
	private $_terms = array('gadget', 'device', 'uid', 'gid', 'guid', 'uuid', 'unique', 'identifier', 'bb2');

	/**
	 * Secondary array used to build out the field name
	 * @var string[]
	 */
	private $_second_terms = array('hash', 'cipher', 'code', 'key', 'unlock', 'bit', 'value', 'screener');

	/**
	 * Get things rolling
	 *
	 * @param mixed[]|null $verificationOptions no_empty_field,
	 */
	public function __construct($verificationOptions = null)
	{
		if (!empty($verificationOptions))
			$this->_options = $verificationOptions;
	}

	/**
	 * Returns if we are showing this verification control or not
	 * Build the control if we are
	 *
	 * @param boolean $isNew
	 * @param boolean $force_refresh
	 * @return boolean
	 */
	public function showVerification($isNew, $force_refresh = true)
	{
		global $modSettings;

		$this->_tested = false;

		if ($isNew)
		{
			$this->_empty_field = !empty($this->_options['no_empty_field']) || (!empty($modSettings['enable_emptyfield']) && !isset($this->_options['no_empty_field']));
			$this->_user_value = '';
		}

		if ($isNew || $force_refresh)
			$this->createTest($force_refresh);

		return $this->_empty_field;
	}

	/**
	 * Create the name data for the empty field that will be added to the template
	 *
	 * @param boolean $refresh
	 */
	public function createTest($refresh = true)
	{
		if (!$this->_empty_field)
			return;

		// Building a field with a believable name that will be inserted lives in the template.
		if ($refresh || !isset($_SESSION[$this->_options['id'] . '_vv']['empty_field']))
		{
			$start = mt_rand(0, 27);
			$this->_hash = substr(md5(time()), $start, 6);
			$this->_field_name = $this->_terms[array_rand($this->_terms)] . '-' . $this->_second_terms[array_rand($this->_second_terms)] . '-' . $this->_hash;
			$_SESSION[$this->_options['id'] . '_vv']['empty_field'] = '';
			$_SESSION[$this->_options['id'] . '_vv']['empty_field'] = $this->_field_name;
		}
		else
		{
			$this->_field_name = $_SESSION[$this->_options['id'] . '_vv']['empty_field'];
			$this->_user_value = !empty($_REQUEST[$_SESSION[$this->_options['id'] . '_vv']['empty_field']]) ? $_REQUEST[$_SESSION[$this->_options['id'] . '_vv']['empty_field']] : '';
		}
	}

	/**
	 * Values passed to the template inside of GenericControls
	 * Use the values to adjust how the control does or does not appear
	 */
	public function prepareContext()
	{
		$GLOBALS['elk']['templates']->load('VerificationControls');

		return array(
			'template' => 'emptyfield',
			'values' => array(
				'is_error' => $this->_tested && !$this->_verifyField(),
				// Can be used in the template to show the normally hidden field to add some spice to things
				'show' => !empty($_SESSION[$this->_options['id'] . '_vv']['empty_field']) && (mt_rand(1, 100) > 60),
				'user_value' => $this->_user_value,
				'field_name' => $this->_field_name,
				// Can be used in the template to randomly add a value to the empty field that needs to be removed when show is on
				'clear' => (mt_rand(1, 100) > 60),
			)
		);
	}

	/**
	 * Run the test on the returned value and return pass or fail
	 */
	public function doTest()
	{
		$this->_tested = true;

		if (!$this->_verifyField())
			return 'wrong_verification_answer';

		return true;
	}

	/**
	 * Not used, just returns false for empty field verifications
	 *
	 * @return false
	 */
	public function hasVisibleTemplate()
	{
		return false;
	}

	/**
	 * Test the field, easy, its on, its is set and it is empty
	 */
	protected function _verifyField()
	{
		return $this->_empty_field && !empty($_SESSION[$this->_options['id'] . '_vv']['empty_field']) && empty($_REQUEST[$_SESSION[$this->_options['id'] . '_vv']['empty_field']]);
	}

	/**
	 * Callback for this verification control options, which is on or off
	 */
	public function settings()
	{
		// Empty field verification.
		$config_vars = array(
			array('title', 'configure_emptyfield'),
			array('desc', 'configure_emptyfield_desc'),
			array('check', 'enable_emptyfield'),
		);

		return $config_vars;
	}
}