<?php


/**
 * Class to manage, create, show and validate captcha images
 */
class Captcha implements VerificationControlsInterface
{
	/**
	 * Holds the $verificationOptions passed to the constructor
	 *
	 * @var array
	 */
	private $_options = null;

	/**
	 * If we are actually displaying the captcha image
	 *
	 * @var boolean
	 */
	private $_show_captcha = false;

	/**
	 * The string of text that will be used in the image and verification
	 *
	 * @var string
	 */
	private $_text_value = null;

	/**
	 * The number of characters to generate
	 *
	 * @var int
	 */
	private $_num_chars = null;

	/**
	 * The url to the created image
	 *
	 * @var string
	 */
	private $_image_href = null;

	/**
	 * If the response has been tested or not
	 *
	 * @var boolean
	 */
	private $_tested = false;

	/**
	 * If the GD library is available for use
	 *
	 * @var boolean
	 */
	private $_use_graphic_library = false;

	/**
	 * array of allowable characters that can be used in the image
	 *
	 * @var array
	 */
	private $_standard_captcha_range = array();

	/**
	 * Get things started,
	 * set the letters we will use to avoid confusion
	 * set graphics capability
	 *
	 * @param mixed[]|null $verificationOptions override_range, override_visual, id
	 */
	public function __construct($verificationOptions = null)
	{
		global $modSettings;

		$this->_use_graphic_library = in_array('gd', get_loaded_extensions());
		$this->_num_chars = $modSettings['visual_verification_num_chars'];

		// Skip I, J, L, O, Q, S and Z.
		$this->_standard_captcha_range = array_merge(range('A', 'H'), array('K', 'M', 'N', 'P', 'R'), range('T', 'Y'));

		if (!empty($verificationOptions))
			$this->_options = $verificationOptions;
	}

	/**
	 * Show a verification captcha
	 *
	 * @param boolean $isNew
	 * @param boolean $force_refresh
	 */
	public function showVerification($isNew, $force_refresh = true)
	{
		global $context, $modSettings, $scripturl;

		// Some javascript ma'am? (But load it only once)
		if (!empty($this->_options['override_visual']) || (!empty($modSettings['visual_verification_type']) && !isset($this->_options['override_visual'])) && empty($context['captcha_js_loaded']))
		{
			$GLOBALS['elk']['templates']->load('VerificationControls');
			loadJavascriptFile('jquery.captcha.js');
			$context['captcha_js_loaded'] = true;
		}

		$this->_tested = false;

		// Requesting a new challenge, build the image link, seed the JS
		if ($isNew)
		{
			$this->_show_captcha = !empty($this->_options['override_visual']) || (!empty($modSettings['visual_verification_type']) && !isset($this->_options['override_visual']));

			if ($this->_show_captcha)
			{
				$this->_text_value = '';
				$this->_image_href = $scripturl . '?action=register;sa=verificationcode;vid=' . $this->_options['id'] . ';rand=' . md5(mt_rand());
			}
		}

		if ($isNew || $force_refresh)
			$this->createTest($force_refresh);

		return $this->_show_captcha;
	}

	/**
	 * Build the string that will be used to build the captcha
	 *
	 * @param boolean $refresh
	 */
	public function createTest($refresh = true)
	{
		if (!$this->_show_captcha)
			return;

		if ($refresh)
		{
			$_SESSION[$this->_options['id'] . '_vv']['code'] = '';

			// Are we overriding the range?
			$character_range = !empty($this->_options['override_range']) ? $this->_options['override_range'] : $this->_standard_captcha_range;

			for ($i = 0; $i < $this->_num_chars; $i++)
				$_SESSION[$this->_options['id'] . '_vv']['code'] .= $character_range[array_rand($character_range)];
		}
		else
			$this->_text_value = !empty($_REQUEST[$this->_options['id'] . '_vv']['code']) ? $GLOBALS['elk']['text']->htmlspecialchars($_REQUEST[$this->_options['id'] . '_vv']['code']) : '';
	}

	/**
	 * Prepare the captcha for the template
	 */
	public function prepareContext()
	{
		return array(
			'template' => 'captcha',
			'values' => array(
				'image_href' => $this->_image_href,
				'text_value' => $this->_text_value,
				'use_graphic_library' => $this->_use_graphic_library,
				'chars_number' => $this->_num_chars,
				'is_error' => $this->_tested && !$this->_verifyCode(),
			)
		);
	}

	/**
	 * Perform the test, make people do it again and robots pass :P
	 * @return string|boolean
	 */
	public function doTest()
	{
		$this->_tested = true;

		if (!$this->_verifyCode())
			return 'wrong_verification_code';

		return true;
	}

	/**
	 * Required by the interface, returns true for Captcha display
	 * @return true
	 */
	public function hasVisibleTemplate()
	{
		return true;
	}

	/**
	 * Configuration settings for the Admin template
	 *
	 * @return string
	 */
	public function settings()
	{
		global $txt, $scripturl, $modSettings;

		// Generate a sample registration image.
		$verification_image = $scripturl . '?action=register;sa=verificationcode;rand=' . md5(mt_rand());

		// Visual verification.
		$config_vars = array(
			array('title', 'configure_verification_means'),
			array('desc', 'configure_verification_means_desc'),
			array('int', 'visual_verification_num_chars'),
			'vv' => array('select', 'visual_verification_type',
				array($txt['setting_image_verification_off'], $txt['setting_image_verification_vsimple'], $txt['setting_image_verification_simple'], $txt['setting_image_verification_medium'], $txt['setting_image_verification_high'], $txt['setting_image_verification_extreme']),
				'subtext' => $txt['setting_visual_verification_type_desc']),
		);

		// Save it
		if (isset($_GET['save']))
		{
			if (isset($_POST['visual_verification_num_chars']) && $_POST['visual_verification_num_chars'] < 6)
				$_POST['visual_verification_num_chars'] = 5;
		}

		$_SESSION['visual_verification_code'] = '';
		for ($i = 0; $i < $this->_num_chars; $i++)
			$_SESSION['visual_verification_code'] .= $this->_standard_captcha_range[array_rand($this->_standard_captcha_range)];

		// Some javascript for CAPTCHA.
		if ($this->_use_graphic_library)
		{
			loadJavascriptFile('jquery.captcha.js');
			theme()->addInlineJavascript('
		$(\'#visual_verification_type\').Elk_Captcha({
			\'imageURL\': ' . JavaScriptEscape($verification_image) . ',
			\'useLibrary\': true,
			\'letterCount\': ' . $this->_num_chars . ',
			\'refreshevent\': \'change\',
			\'Admin\': true
		});', true);
		}

		// Show the image itself, or text saying we can't.
		if ($this->_use_graphic_library)
			$config_vars['vv']['postinput'] = '<br /><img src="' . $verification_image . ';type=' . (empty($modSettings['visual_verification_type']) ? 0 : $modSettings['visual_verification_type']) . '" alt="' . $txt['setting_image_verification_sample'] . '" id="verification_image" /><br />';
		else
			$config_vars['vv']['postinput'] = '<br /><span class="smalltext">' . $txt['setting_image_verification_nogd'] . '</span>';

		return $config_vars;
	}

	/**
	 * Does what they typed = what was supplied in the image
	 * @return boolean
	 */
	protected function _verifyCode()
	{
		return !$this->_show_captcha || (!empty($_REQUEST[$this->_options['id'] . '_vv']['code']) && !empty($_SESSION[$this->_options['id'] . '_vv']['code']) && strtoupper($_REQUEST[$this->_options['id'] . '_vv']['code']) === $_SESSION[$this->_options['id'] . '_vv']['code']);
	}
}