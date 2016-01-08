<?php

namespace Elkarte\Elkarte\Security\PasswordStrategies;

class Snitz extends AbstractStrategy
{
	public function name()
	{
		return 'Snitz';
	}

	public function setOptions(array $options)
	{
		$this->options = $options;
	}

	public function getHash($password)
	{
		return bin2hex(hash('sha256', $password));
	}

	public function checkSystemHash($hash)
	{
		return strlen($hash) === 64;
	}
}