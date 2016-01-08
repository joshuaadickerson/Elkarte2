<?php

namespace Elkarte\Elkarte\Security;

use Elkarte\Elkarte\Security\PasswordStrategies\PasswordStrategyInterface;
use Symfony\Component\Yaml\Exception\RuntimeException;

/**
 * Class PasswordChecker
 * Check passwords
 * @package Elkarte\Elkarte\Security
 */
class PasswordChecker
{
	/** @var PasswordStrategyInterface[] */
	protected $strategies = [];
	/** @var string */
	protected $strategy_dir = 'PasswordStrategies';
	/** @var array  */
	protected $last_check = [];
	/** @var  PasswordStrategyInterface */
	protected $current_strategy;
	/** @var array  */
	protected $options = [];

	/**
	 * @param PasswordStrategyInterface[] $strategies
	 * @return $this
	 */
	public function setStrategies(array $strategies)
	{
		$this->strategies = [];

		foreach ($strategies as $strategy)
		{
			$this->addStrategy($strategy);
		}

		return $this;
	}

	/**
	 * @param PasswordStrategyInterface $strategy
	 * @return $this
	 */
	public function addStrategy(PasswordStrategyInterface $strategy)
	{
		$this->strategies[$strategy->name()] = $strategy;
		return $this;
	}

	/**
	 * @param string $dir
	 * @param array $allowed_strategies = []
	 * @throws \RuntimeException when $dir is not a directory
	 */
	public function addStrategiesFromDir($dir = 'PasswordStrategies', array $allowed_strategies = [])
	{
		$this->strategy_dir = $dir;

		if (!is_dir($dir))
		{
			throw new \RuntimeException('Unknown strategy directory: ' . $dir);
		}

		$iter = new \DirectoryIterator($dir, \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS);
		foreach ($iter as $path => $file)
		{
			// Skip dirs, 'AbstractStrategy.php', 'PasswordStrategyInterface.php', anything that starts with '.'
			$filename = '';
			$class = '';

			if ($allowed_strategies !== [] && !in_array($class, $allowed_strategies))
			{
				continue;
			}

			try
			{
				$strategy = new $class;
				$this->addStrategy($strategy);
			}
			// What kind of exception?
			catch (\Exception $e)
			{
				// do nothing
			}
		}
	}

	/**
	 * @param string $password
	 * @param string $hash
	 * @return bool
	 * @throws PasswordException
	 */
	public function check($password, $hash)
	{
		$password = (string) $password;
		$pass_len = strlen($password);

		$hash = (string) $hash;
		$hash_len = strlen($hash);

		if ($pass_len === 0)
		{
			throw new PasswordException('Password must not be 0 length');
		}

		if ($hash_len === 0)
		{
			throw new PasswordException('Hash must not be 0 length');
		}

		if (empty($this->strategies))
		{
			throw new RuntimeException('No password strategies loaded');
		}

		$this->options['password_length'] = $pass_len;
		$this->options['hash_length'] = $hash_len;

		return $this->checkStategies($password, $hash);
	}

	/**
	 * @param array $options
	 * @return $this
	 */
	public function setOptions(array $options)
	{
		$this->options = $options;
		return $this;
	}

	/**
	 * @param string $password
	 * @param string $hash
	 * @return bool
	 */
	protected function checkStategies($password, $hash)
	{
		foreach ($this->strategies as $strategy)
		{
			$strategy->setOptions($this->options);

			if ($strategy->checkSystemHash($hash) && $strategy->match($password, $hash))
			{
				$this->current_strategy = $strategy;
				return true;
			}
		}

		return false;
	}
}