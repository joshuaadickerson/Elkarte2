<?php

namespace Elkarte;

class Config
{
	########## Maintenance ##########
	/**
	 * The maintenance "mode"
	 * Set to 1 to enable Maintenance Mode, 2 to make the forum untouchable. (you'll have to make it 0 again manually!)
	 * 0 is default and disables maintenance mode.
	 * @var int 0, 1, 2
	 * @global int $maintenance
	 */
	public $maintenance = 0;

	/**
	 * Title for the Maintenance Mode message.
	 * @var string
	 * @global int $mtitle
	 */
	public $maintenance_title = 'Maintenance Mode';

	/**
	 * Description of why the forum is in maintenance mode.
	 * @var string
	 * @global string $mmessage
	 */
	public $mmessage = 'Okay faithful users...we\'re attempting to restore an older backup of the database...news will be posted once we\'re back!';

	########## Forum Info ##########
	/**
	 * The name of your forum.
	 * @var string
	 */
	public $mbname = 'My Community';

	/**
	 * The default language file set for the forum.
	 * @var string
	 */
	public $language = 'english';

	/**
	 * URL to your forum's folder. (without the trailing /!)
	 * @var string
	 */
	public $boardurl = 'http://local.dev/Elkarte2/Elkarte/www';

	/**
	 * Email address to send emails from. (like noreply@yourdomain.com.)
	 * @var string
	 */
	public $webmaster_email = 'joshua.a.dickerson@gmail.com';

	/**
	 * Name of the cookie to set for authentication.
	 * @var string
	 */
	public $cookiename = 'ElkArteCookie4';

	########## Database Info ##########
	/**
	 * The database type
	 * Default options: mysql, sqlite, postgresql
	 * @var string
	 */
	public $db_type = 'mysql';

	/**
	 * The server to connect to (or a Unix socket)
	 * @var string
	 */
	public $db_server = '192.168.200.4';

	/**
	 * The port for the database server
	 * @var string
	 */
	public $db_port = '';

	/**
	 * The database name
	 * @var string
	 */
	public $db_name = 'elkarte2';

	/**
	 * Database username
	 * @var string
	 */
	public $db_user = 'dbuser';

	/**
	 * Database password
	 * @var string
	 */
	public $db_passwd = 'password';

	/**
	 * Database user for when connecting with SSI
	 * @var string
	 */
	public $ssi_db_user = '';

	/**
	 * Database password for when connecting with SSI
	 * @var string
	 */
	public $ssi_db_passwd = '';

	/**
	 * A prefix to put in front of your table names.
	 * This helps to prevent conflicts
	 * @var string
	 */
	public $db_prefix = '';

	/**
	 * Use a persistent database connection
	 * @var int|bool
	 */
	public $db_persist = 0;

	/**
	 *
	 * @var int|bool
	 */
	public $db_error_send = 0;

	########## Cache Info ##########
	/**
	 * Select a cache system. You want to leave this up to the cache area of the Admin panel for
	 * proper detection of apc, eaccelerator, memcache, mmcache, output_cache, xcache or filesystem-based
	 * (you can add more with a mod).
	 * @var string
	 */
	public $cache_driver = 'filebased';

	/**
	 * Cache accelerator userid, needed by some engines in order to clear the cache
	 * @var string
	 */
	public $cache_uid = '';

	/**
	 * Cache accelerator password for when connecting to clear the cache
	 * @var string
	 */
	public $cache_password = '';

	/**
	 * The level at which you would like to cache. Between 0 (off) through 3 (cache a lot).
	 * @var int
	 */
	public $cache_enable = false;
	public $cache_level = 0;

	/**
	 * This is only used for memcache / memcached. Should be a string of 'server:port,server:port'
	 * @var array
	 */
	public $cache_memcached = '';

	/**
	 * This is only for the 'filebased' cache system. It is the path to the cache directory.
	 * It is also recommended that you place this in /tmp/ if you are going to use this.
	 * @var string
	 */
	public $cachedir = '/var/www/Elkarte2/Elkarte/cache';

	########## Directories/Files ##########
	# Note: These directories do not have to be changed unless you move things.
	/**
	 * The absolute path to the forum's folder. (not just '.'!)
	 * @var string
	 */
	public $boarddir = '/var/www/Elkarte2/Elkarte';

	/**
	 * Path to the Sources directory.
	 * @var string
	 */
	public $sourcedir = '/var/www/Elkarte2/Elkarte/Sources';

	/**
	 * Path to the external resources directory.
	 * @var string
	 */
	public $extdir = '/var/www/Elkarte2/Elkarte/Sources/ext';

	/**
	 * Path to the vendor library directory
	 * @var string
	 */
	public $vendordir = __DIR__ . '/..';

	public $db_character_set = 'utf8';

	public $ignore_install_dir = true;

	public $db_show_debug = true;

}