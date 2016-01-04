<?php

namespace ElkArte;

use \Elkarte\Database\Drivers\DatabaseInterface;

class Settings implements \ArrayAccess
{
	protected $data = [];
	protected $group_keys = array('settings' => []);

	public function __construct(DatabaseInterface $db, \Cache $cache, \Errors $errors)
	{
		$this->db = $db;
		$this->cache = $cache;
		$this->errors = $errors;

		return $this;
	}

	// @see reloadSettings()
	public function reload()
	{
		$this->data['postmod_active'] = !empty($this->data['admin_features']) ? in_array('pm', explode(',', $this->data['admin_features'])) : true;
	}

	protected function loadDefaults()
	{
		$defaults = array(
			'defaultMaxTopics'   			=> 20,
			'defaultMaxMessages' 			=> 15,
			'defaultMaxMembers'				=> 30,
			'subject_length' 				=> 24,
			'currentAttachmentUploadDir' 	=> 1,
		);

		foreach ($defaults as $key => $value)
		{
			if (!isset($this->data[$key]))
			{
				$this->data[$key] = $value;
			}
		}
	}

	public function isAutoloaded($key)
	{
		return isset($this->group_keys['settings'][$key]);
	}

	public function getByGroup($group, $no_cache = false)
	{
		$group = (string) $group;

		if (!isset($this->group_keys[$group]))
		{
			if (!$no_cache)
			{
				$this->loadFromCache($group);
			}
			else
			{
				$this->loadFromDatabase($group);
			}
		}

		$return = array();
		foreach ($this->group_keys[$group] as $key)
		{
			$return[$key] = $this->data[$key];
		}

		return $return;
	}

	protected function loadFromDatabase($group = '')
	{
		$request = $this->db->select('', '
			SELECT variable, value
			FROM {db_prefix}settings
			WHERE key_group = {string:key_group}',
			[
				'key_group' => $group,
			]
		);

		if (!$request)
			$this->errors->display_db_error();

		while ($row = $this->db->fetch_row($request))
		{
			$this->data[$row[0]] = $row[1];

			$this->group_keys[$group] = $row[0];
		}

		$this->db->free_result($request);

		$this->group_keys[$group] = array_unique($this->group_keys);
	}

	protected function loadFromCache($group = '')
	{
		$result = $this->cache->get('settings-' . $group);

		if ($this->cache->isMiss())
		{
			$result = $this->loadFromDatabase($group);
			$this->cache->put('settings-' . $group, $this->data[$group]);
		}

		return $result;
	}


	/**
	 * Setter
	 *
	 * @param string|int $key
	 * @param string|int|bool|null|object $val
	 */
	public function __set($key, $val)
	{

	}

	/**
	 * Getter
	 *
	 * @param string|int $key
	 * @return string|int|bool|null|object
	 */
	public function __get($key)
	{
		if (isset($this->data[$key]))
			return $this->data[$key];
		else
			return null;
	}

	/**
	 * Tests if the key is set.
	 *
	 * @param string|int $key
	 * @return bool
	 */
	public function __isset($key)
	{
		return isset($this->data[$key]);
	}

	/**
	 * Assigns a value to a certain offset.
	 *
	 * @param mixed|mixed[] $offset
	 */
	public function offsetSet($offset, $value)
	{

	}

	/**
	 * Tests if an offset key is set.
	 *
	 * @param string|int $offset
	 * @return bool
	 */
	public function offsetExists($offset)
	{
		return isset($this->data[$offset]);
	}

	/**
	 * Unsets a certain offset key.
	 *
	 * @param string|int $offset
	 */
	public function offsetUnset($offset)
	{

	}

	/**
	 * Returns the value associated to a certain offset.
	 *
	 * @param string|int $offset
	 * @return mixed|mixed[]
	 */
	public function offsetGet($offset)
	{
		return isset($this->data[$offset]) ? $this->data[$offset] : null;
	}
}