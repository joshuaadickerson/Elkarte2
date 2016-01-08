<?php

namespace Elkarte\Elkarte\Security\PasswordStrategies;

class PhpBB3 extends AbstractStrategy
{
	public function name()
	{
		return 'phpBB3';
	}

	public function setOptions(array $options)
	{
		$this->options = $options;
	}

	public function getHash($password)
	{
		return crypt(md5($password), md5($password));
	}

	public function canMatch()
	{

	}

	public function checkSystemHash($hash)
	{
		return strlen($hash) === 32;
	}

	/**
	 * Custom encryption for phpBB3 based passwords.
	 *
	 * @package Authorization
	 * @param string $passwd
	 * @param string $passwd_hash
	 * @return string
	 */
	function phpBB3_password_check($passwd, $passwd_hash)
	{
		// Too long or too short?
		if (strlen($passwd_hash) != 34)
			return;

		// Range of characters allowed.
		$range = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

		// Tests
		$strpos = strpos($range, $passwd_hash[3]);
		$count = 1 << $strpos;
		$salt = substr($passwd_hash, 4, 8);

		$hash = md5($salt . $passwd, true);
		for (; $count != 0; --$count)
			$hash = md5($hash . $passwd, true);

		$output = substr($passwd_hash, 0, 12);
		$i = 0;
		while ($i < 16)
		{
			$value = ord($hash[$i++]);
			$output .= $range[$value & 0x3f];

			if ($i < 16)
				$value |= ord($hash[$i]) << 8;

			$output .= $range[($value >> 6) & 0x3f];

			if ($i++ >= 16)
				break;

			if ($i < 16)
				$value |= ord($hash[$i]) << 16;

			$output .= $range[($value >> 12) & 0x3f];

			if ($i++ >= 16)
				break;

			$output .= $range[($value >> 18) & 0x3f];
		}

		// Return now.
		return $output;
	}
}