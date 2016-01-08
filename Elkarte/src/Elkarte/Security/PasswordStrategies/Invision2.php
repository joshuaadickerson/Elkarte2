<?php

namespace Elkarte\Elkarte\Security\PasswordStrategies;

class Invision2 extends AbstractStrategy
{
	public function name()
	{
		return 'Invision2';
	}

	public function setOptions(array $options)
	{
		$this->options = $options;
	}

	public function getHash($password)
	{
		return md5(md5($this->getSalt()) . md5($password));
	}

	public function canMatch()
	{

	}

	public function checkSystemHash($hash)
	{
		return strlen($hash) === 32;
	}
}