<?php

namespace Elkarte\Elkarte\Security\PasswordStrategies;

abstract class AbstractStrategy implements PasswordStrategyInterface
{
	protected $options = [];

	abstract public function name();

	abstract public function getHash($password);

	public function setOptions(array $options)
	{
		$this->options = $options;
	}

	public function match($password, $hash)
	{
		return $this->getHash($password) === $hash;
	}

	public function getSalt()
	{
		if (!isset($this->options['salt']))
		{
			throw new PasswordException('Password salt not set');
		}

		return $this->options['salt'];
	}
}