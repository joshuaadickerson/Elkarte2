<?php

namespace Elkarte\Elkarte\Security\PasswordStrategies;

class PHPFusion7 extends AbstractStrategy
{
	public function name()
	{
		return 'PHP-Fusion7';
	}

	public function setOptions(array $options)
	{
		$this->options = $options;
	}

	public function getHash($password)
	{
		return hash_hmac('sha256', $password, $this->getSalt());
	}

	public function canMatch()
	{

	}

	public function checkSystemHash($hash)
	{
		return strlen($hash) === 64;
	}
}