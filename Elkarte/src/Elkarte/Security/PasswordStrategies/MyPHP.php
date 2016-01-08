<?php

namespace Elkarte\Elkarte\Security\PasswordStrategies;

class MyPHP extends AbstractStrategy
{
	public function name()
	{
		return 'MyPHP';
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
}