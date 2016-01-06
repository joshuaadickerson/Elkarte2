<?php

namespace Elkarte\OnlineLog;

// @todo implement ArrayAccess and Traversable?
class OnlineLog
{
	/**
	 * How often, in seconds, to run the database garbage collection
	 */
	const DB_GC_TIME = 30;

	/**
	 * @var Database
	 */
	protected $db;
	/**
	 * @var Cache
	 */
	protected $cache;

	/**
	 * Only use the cache (true) or use the database (false)
	 * @var boolean
	 */
	protected $use_cache;

	/**
	 * The length of time, in seconds, that a user is considered "online"
	 * @var int
	 */
	protected $seconds_active;
	/**
	 * The online log
	 * @var array
	 */
	protected $log;

	protected $num_users;
	protected $num_members;
	protected $num_guests;
	protected $num_spiders;
	protected $num_buddies;
	protected $num_hidden;

	// The list can be sorted in several ways.
	protected $allowed_sort_options = array(
		'', // No sorting.
		'log_time',
		'real_name',
		'show_online',
		'online_color',
		'group_name',
	);

	/**
	 * @param Database $database
	 * @param Cache $cache
	 * @param int $seconds_active The length of time, in seconds, that a user is considered "online". $modSettings['lastActive'] is the current setting for this
	 * @param bool $use_cache Only use the cache (true) or use the database (false)
	 */
	public function __construct(Database $database, Cache $cache, $seconds_active = 900, $use_cache = false)
	{
		$this->db = $database;
		$this->cache = $cache;
		$this->use_cache = (bool) $use_cache;
		$this->seconds_active = (int) $seconds_active;
	}

	/**
	 * Log a user as being "online".
	 */
	public function logUser()
	{
		if ($this->use_cache)
		{
			$this->logUser_cache();
		}
		else
		{
			$this->logUser_database();
		}
	}

	protected function logUser_database()
	{
		// Clear out the old ones first
		$this->clearOldDatabaseLogEntries();

		$entry = getCurrentUserLogEntry();

		// Now log the new one
		$this->db->insert('replace',
			'{db_prefix}log_online',
			array('session' => 'string', 'id_member' => 'int', 'id_spider' => 'int', 'log_time' => 'int', 'ip' => 'raw', 'url' => 'string'),
			array($entry['session'], $entry['id_member'], $entry['id_spider'], $entry['log_time'], 'IFNULL(INET_ATON(\'' . $entry['ip'] . '\'), 0)', $entry['url']),
			array('session')
		);
	}

	protected function logUser_cache()
	{
		$log = $this->getLog();

		$this->cache->put('log_online', array_filter($log), $this->seconds_active);
	}

	/**
	 * Clear old log entries from the database
	 */
	protected function clearOldDatabaseLogEntries()
	{
		// Only do garbage collection every so often
		$do_delete = $this->cache->get('log_online-update', self::DB_GC_TIME) < $_SERVER['REQUEST_TIME'] - self::DB_GC_TIME;

		if ($do_delete)
		{
			$this->db->delete('delete_log_online_interval', '
				DELETE FROM {db_prefix}log_online
				WHERE log_time < {int:log_time}',
				array(
					'log_time' => $_SERVER['REQUEST_TIME'] - $this->seconds_active,
				)
			);

			// Cache when we did it last.
			$this->cache->put('log_online-update', $_SERVER['REQUEST_TIME'], self::DB_GC_TIME);
		}
	}

	/**
	 * Clear old log entries from the currently loaded log
	 * Does not make any changes to the database
	 */
	protected function clearOldLogEntries()
	{
		$seconds_active = $this->seconds_active;

		$this->log = array_filter($this->log, function ($entry) use ($seconds_active) {
			return $entry['log_time'] >= $_SERVER['REQUEST_TIME'] - $seconds_active;
		});
	}

	/**
	 * Get the entire online log
	 *
	 * @return array[]
	 */
	public function getLog()
	{
		if (empty($this->log))
		{
			$this->log = $this->use_cache ? $this->getLogFromCache() : $this->getLogFromDatabase();

			// Cleans up anything old
			$this->clearOldLogEntries();

			$user_entry = $this->getCurrentUserLogEntry();
			$this->log[$user_entry['session']] = $user_entry;
		}

		return $this->log;
	}

	protected function getCurrentUserLogEntry()
	{
		global $user_info, $context, $modSettings;

		// Guests use 0, members use their session ID.
		$session_id = $user_info['is_guest'] ? 'ip' . $user_info['ip'] : session_id();

		if (!empty($modSettings['who_enabled']))
		{
			$req = Request::instance();
			$serialized = $_GET + array('USER_AGENT' => $req->user_agent());

			// In the case of a dlattach action, session_var may not be set.
			if (!isset($context['session_var']))
				$context['session_var'] = $_SESSION['session_var'];

			unset($serialized['sesc'], $serialized[$context['session_var']]);
			$serialized = serialize($serialized);
		}
		else
			$serialized = '';

		return array(
			'session' => $session_id,
			'log_time' => $_SERVER['REQUEST_TIME'],
			'id_member' => $user_info['id'],
			'ip' => $user_info['ip'],
			'url' => $serialized,
			'id_spider' => empty($_SESSION['id_robot']) ? 0 : $_SESSION['id_robot'],
			'show_online' => $user_info['show_online'],
			'real_name' => $user_info['real_name'],
			'member_name' => $user_info['member_name'],
		);
	}

	protected function getLogFromCache()
	{
		$cached_log = $this->cache->get('log_online');

		if (!$cached_log)
		{
			return array();
		}

		// We filter the input to save memory, so just to keep it consistent with the database, add back in default values
		$default_values = array(
			'id_member' => 0,
			'ip' => 0,
			'id_spider' => 0,
			'show_online' => false,
			'real_name' => '',
			'member_name' => '',
			'online_color' => '',
			'id_group' => 0,
			'group_name' => '',
		);

		foreach ($cached_log as &$entry)
		{
			$entry = array_merge($default_values, $entry);
		}

		return $cached_log;
	}

	protected function getLogFromDatabase()
	{
		// Don't worry about getting old entries. We filter those with PHP
		$request = $this->db->select('', '
			SELECT
				lo.id_member, lo.log_time, lo.id_spider, mem.real_name, mem.member_name, mem.show_online,
				mg.online_color, mg.id_group, mg.group_name
			FROM {db_prefix}log_online AS lo
				LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lo.id_member)
				LEFT JOIN {db_prefix}membergroups AS mg ON (mg.id_group = CASE WHEN mem.id_group = {int:reg_mem_group} THEN mem.id_post_group ELSE mem.id_group END)',
			array(
				'reg_mem_group' => 0,
			)
		);

		$return = array();
		while ($row = $request->fetchAssoc())
		{
			$return[$row['session']] = $row;
		}

		return $return;
	}

	public function getNumUsers()
	{
		if ($this->num_users === null)
		{
			$this->num_users = count($this->getLog());
		}

		return $this->num_users;
	}

	public function getNumMembers()
	{
		if ($this->num_members === null)
		{
			$this->getMembers();
		}

		return $this->num_members;
	}

	public function getMembers()
	{
		$log = $this->getLog();

		$this->num_members = 0;
		$members = array();
		foreach ($log as $entry)
		{
			if (!empty($entry['id_member']))
			{
				$this->num_members++;

				if (empty($entry['show_hidden']))
				{
					$this->num_hidden++;
				}

				$members[$entry['session']] = $entry;
			}
		}

		return $members;
	}

	public function getNumHidden()
	{
		if ($this->num_hidden === null)
		{
			$this->getMembers();
		}

		return $this->num_hidden;
	}

	public function getNumBuddies(array $buddies)
	{
		if ($this->num_buddies === null)
		{
			$this->getBuddies($buddies);
		}

		return $this->num_buddies;
	}

	public function getBuddies(array $buddies)
	{
		$log = $this->getLog();

		$buddies_online = array();
		$this->num_buddies = 0;
		foreach ($log as $entry)
		{
			if (!empty($entry['id_member']) && in_array($entry['id_member'], $buddies))
			{
				$this->num_buddies++;
				$buddies_online[$entry['session']] = $entry;
			}
		}

		return $buddies_online;
	}

	public function getNumGuests()
	{
		if ($this->num_guests === null)
		{
			$this->getGuests();
		}

		return $this->num_guests;
	}

	public function getGuests()
	{
		$log = $this->getLog();

		$guests = array();
		$this->num_guests = 0;
		foreach ($log as $entry)
		{
			if (empty($entry['id_member']))
			{
				$this->num_guests++;
				$guests[$entry['session']] = $entry;
			}
		}

		return $guests;
	}

	public function getNumSpiders()
	{
		if ($this->num_spiders === null)
		{
			$this->getSpiders();
		}

		return $this->num_spiders;
	}

	/**
	 * Get the spiders that are online
	 *
	 * @param array|null $spider_names
	 * @return array
	 */
	public function getSpiders(array $spider_names = null)
	{
		global $modSettings;

		$log = $this->getLog();

		$spider_names = $spider_names === null ? unserialize($modSettings['spider_name_cache']) : $spider_names;

		$spiders = array();
		$this->num_spiders = 0;
		foreach ($log as $entry)
		{
			if (!empty($entry['id_spider']))
			{
				$this->num_spiders++;
				$entry['name'] = isset($spider_names[$entry['id_spider']]) ? $spider_names[$entry['id_spider']] : '';
				$spiders[$entry['session']] = $entry;
			}
		}

		return $spiders;
	}

	/**
	 * @return array The groups that are online
	 */
	public function getGroups()
	{
		$log = $this->getLog();

		$groups = array();
		foreach ($log as $entry)
		{
			if (!empty($entry['id_group']))
			{
				$groups[$entry['id_group']] = $entry;
			}
		}

		return $groups;
	}

	public function sortLog($sort = 'log_time', $dir = 1)
	{
		if (!in_array($sort, $this->allowed_sort_options))
		{
			trigger_error('Sort method for OnlineLog::sortLog() is not allowed', E_USER_NOTICE);
		}

		// Load the log
		$this->getLog();

		if (empty($sort) || empty($this->log))
		{
			return $this->log;
		}

		uasort($this->log, function ($a, $b) use($sort, $dir) {
			if ($a[$sort] == $b[$sort])
			{
				return 0;
			}

			if ($dir == 1)
			{
				return $a < $b ? -1 : 1;
			}
			else
			{
				return $a > $b ? -1 : 1;
			}
		});

		return $this->log;
	}

	public function getOnlineLogForDisplay($membersOnlineOptions)
	{
		global $user_info, $modSettings, $txt;

		// Initialize the array that'll be returned later on.
		$membersOnlineStats = array(
			'users_online' => $this->getOnlineMembersContext(),
			'list_users_online' => array(),
			'online_groups' => array(),
			'num_guests' => $this->getNumGuests(),
			'num_spiders' => 0,
			'num_buddies' => $this->getNumBuddies($user_info['buddies']),
			'num_users_hidden' => $this->getNumHidden(),
			'num_users_online' => 0,
		);

		// Sort the log appropriately
		if (!isset($membersOnlineOptions['sort']))
		{
			$membersOnlineOptions['sort'] = 'log_time';
			$membersOnlineOptions['reverse_sort'] = true;
		}
		$this->sortLog($membersOnlineOptions['sort'], $membersOnlineOptions['reverse_sort']);

		// Get any spiders if enabled.
		$spiders = array();
		if (!empty($modSettings['show_spider_online']) && ($modSettings['show_spider_online'] < 3 || allowedTo('admin_forum')) && !empty($modSettings['spider_name_cache']))
		{
			$spiders = $this->getSpiders();
			$membersOnlineStats['num_spiders'] = $this->getNumSpiders();
		}

	}

	public function getOnlineMembersContext()
	{
		global $scripturl, $user_info;

		$log = $this->getMembers();
		$members = array();

		foreach ($log as $row)
		{
			// Some basic color coding...
			if (!empty($row['online_color']))
				$link = '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '" style="color: ' . $row['online_color'] . ';">' . $row['real_name'] . '</a>';
			else
				$link = '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>';

			// Buddies get counted and highlighted.
			$is_buddy = in_array($row['id_member'], $user_info['buddies']);
			if ($is_buddy)
			{
				$link = '<strong>' . $link . '</strong>';
			}

			$members[$row['id_member']] = array(
				'id' => $row['id_member'],
				'username' => $row['member_name'],
				'name' => $row['real_name'],
				'group' => $row['id_group'],
				'href' => $scripturl . '?action=profile;u=' . $row['id_member'],
				'link' => $link,
				'is_buddy' => $is_buddy,
				'hidden' => empty($row['show_online']),
				'is_last' => false,
			);
		}

		return $members;
	}
}