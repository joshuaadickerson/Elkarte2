<?php

/**
 * This does the job of handling user errors in their many forms
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte;

/**
 *  This class is an experiment for the job of handling errors.
 */
class ErrorContext
{
	/**
	 * Holds the unique identifier of the error (a name).
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * An array that holds all the errors occurred separated by severity.
	 *
	 * @var array
	 */
	protected $errors;

	/**
	 * The default severity code.
	 *
	 * @var mixed
	 */
	protected $default_severity = 0;

	/**
	 * A list of all severity code from the less important to the most serious.
	 *
	 * @var array|mixed
	 */
	protected $severity_levels = [0];

	/**
	 * Certain errors may need some specific language file...
	 *
	 * @var array
	 */
	protected $language_files = [];

	/**
	 * Multiton. This is an array of instances of error_context.
	 * All callers use an error context ('post', 'attach', or 'default' if none chosen).
	 *
	 * @var array of error_context
	 */
	protected static $contexts;

	const MINOR = 0;
	const SERIOUS = 1;

	/**
	 * Create and initialize an instance of the class
	 *
	 * @param string $id the error identifier
	 * @param int|null $default_severity the default error severity level
	 */
	protected function __construct($id = 'default', $default_severity = null)
	{
		if (!empty($id))
			$this->name = $id;

		// Initialize severity levels... waiting for details!
		$this->severity_levels = array(ErrorContext::MINOR, ErrorContext::SERIOUS);

		// Initialize default severity (not sure this is needed)
		if ($default_severity === null || !in_array($default_severity, $this->severity_levels))
			$this->default_severity = ErrorContext::MINOR;
		else
			$this->default_severity = $default_severity;

		$this->errors = array();
	}

	/**
	 * Add an error to the list
	 *
	 * @param string[]|string $error error code
	 * @param string|int|null $severity error severity
	 * @param string|null $lang_file lang_file
	 */
	public function addError($error, $severity = null, $lang_file = null)
	{
		$severity = $severity !== null && in_array($severity, $this->severity_levels) ? $severity : $this->default_severity;

		if (!empty($error))
		{
			if (is_array($error))
				$this->errors[$severity][$error[0]] = $error;
			else
				$this->errors[$severity][$error] = $error;
		}

		if (!empty($lang_file) && !isset($this->language_files[$lang_file]))
			$this->language_files[$lang_file] = false;
	}

	/**
	 * Remove an error from the list
	 *
	 * @param string $error error code
	 */
	public function removeError($error)
	{
		if (!empty($error))
		{
			if (is_array($error))
				$error = $error[0];

			foreach ($this->errors as $severity => $errors)
			{
				if (array_key_exists($error, $errors))
					unset($this->errors[$severity][$error]);
				if (empty($this->errors[$severity]))
					unset($this->errors[$severity]);
			}
		}
	}

	/**
	 * Return an array of errors of a certain severity.
	 *
	 * @todo is it needed at all?
	 * @param string|int|null $severity the severity level wanted. If null returns all the errors
	 * @return false|array
	 */
	public function getErrors($severity = null)
	{
		if ($severity !== null && in_array($severity, $this->severity_levels) && !empty($this->errors[$severity]))
			return $this->errors[$severity];
		elseif ($severity === null && !empty($this->errors))
			return $this->errors;
		else
			return false;
	}

	/**
	 * Return an error based on the id of the error set when adding the error itself.
	 *
	 * @param null|string $error the id of the error
	 * @return null|mixed whatever the error is (string, object, array), noll if not found
	 */
	public function getError($error = null)
	{
		if (isset($this->errors[$error]))
			return $this->errors[$error];
	}

	/**
	 * Returns if there are errors or not.
	 *
	 * @param string|null $severity the severity level wanted. If null returns all the errors
	 * @return bool
	 */
	public function hasErrors($severity = null)
	{
		if ($severity !== null && in_array($severity, $this->severity_levels))
			return !empty($this->errors[$severity]);
		elseif ($severity === null)
			return !empty($this->errors);
		else
			return false;
	}

	/**
	 * Check if a particular error exists.
	 *
	 * @param string $errors the error
	 * @return bool
	 */
	public function hasError($errors)
	{
		if (empty($errors))
			return false;
		else
		{
			$errors = is_array($errors) ? $errors : array($errors);
			foreach ($errors as $error)
			{
				foreach ($this->errors as $current_errors)
					if (isset($current_errors[$error]))
						return true;
			}
		}
		return false;
	}

	/**
	 * Return the code of the highest error level encountered
	 */
	public function getErrorType()
	{
		$levels = array_reverse($this->severity_levels);
		$level = null;

		foreach ($levels as $level)
		{
			if (!empty($this->errors[$level]))
				return $level;
		}

		return $level;
	}

	/**
	 * Return an array containing the error strings
	 *
	 * - If severity is null the function returns all the errors
	 *
	 * @param string|null $severity the severity level wanted
	 */
	public function prepareErrors($severity = null)
	{
		global $txt;

		if (empty($this->errors))
			return array();

		$this->loadLang();

		$GLOBALS['elk']['hooks']->hook('' . $this->name . '_errors', array(&$this->errors, &$this->severity_levels));

		$errors = array();
		$returns = array();
		if ($severity === null)
		{
			foreach ($this->errors as $err)
				$errors = array_merge($errors, $err);
		}
		elseif (in_array($severity, $this->severity_levels) && !empty($this->errors[$severity]))
			$errors = $this->errors[$severity];

		foreach ($errors as $error_val)
		{
			if (is_array($error_val))
				$returns[$error_val[0]] = vsprintf(isset($txt['error_' . $error_val[0]]) ? $txt['error_' . $error_val[0]] : (isset($txt[$error_val[0]]) ? $txt[$error_val[0]] : $error_val[0]), $error_val[1]);
			elseif (is_object($error_val))
				continue;
			else
				$returns[$error_val] = isset($txt['error_' . $error_val]) ? $txt['error_' . $error_val] : (isset($txt[$error_val]) ? $txt[$error_val] : $error_val);
		}

		return $returns;
	}

	/**
	 * Load the default error language and any other language file needed
	 */
	protected function loadLang()
	{
		// Errors is always needed
		loadLanguage('Errors');

		// Any custom one?
		if (!empty($this->language_files))
			foreach ($this->language_files as $language => $loaded)
				if (!$loaded)
				{
					loadLanguage($language);

					// Remember this file has been loaded already
					$this->language_files[$language] = true;
				}
	}

	/**
	 * Find and return error_context instance if it exists,
	 * or create a new instance for $id if it didn't already exist.
	 *
	 * @param string $id
	 * @param int|null $default_severity
	 * @return ErrorContext
	 */
	public static function context($id = 'default', $default_severity = null)
	{
		if (self::$contexts === null)
			self::$contexts = array();

		if (!array_key_exists($id, self::$contexts))
			self::$contexts[$id] = new ErrorContext($id, $default_severity);

		return self::$contexts[$id];
	}
}