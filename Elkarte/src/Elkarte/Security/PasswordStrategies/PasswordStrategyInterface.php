<?php

namespace Elkarte\Elkarte\Security\PasswordStrategies;

interface PasswordStrategyInterface
{
	/**
	 * The name of the strategy
	 * @return string
	 */
	public function name();

	/**
	 * Set the options like the salt or the length
	 * @param array $options
	 * @return void
	 */
	public function setOptions(array $options);

	/**
	 * Check if the stored password can be one of these types.
	 * Usually just checks the length, but also might check for certain characters or if a salt is set
	 * @param string $hash
	 * @return bool
	 */
	public function checkSystemHash($hash);

	/**
	 * Given the inputted string, get back a hashed password
	 * @param string $password
	 * @return mixed
	 */
	public function getHash($password);

	/**
	 * Check if the password matches the hash
	 *
	 * @param $password
	 * @param $hash
	 * @return mixed
	 */
	public function match($password, $hash);
}