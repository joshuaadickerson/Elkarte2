<?php


/**
 * Grabs all the files with db definitions and loads them.
 * That's the easy way, in the future we can make it as complex as possible. :P
 */
function load_possible_databases($type = null)
{
	global $databases;

	$files = glob(__DIR__ . '/Db-check-*.php');

	foreach ($files as $file)
	{
		if ($type !== null)
		{
			if (strtolower($file) === strtolower(__DIR__ . '/Db-check-' . $type . '.php'))
				require($file);
		}
		else
			require($file);
	}
}

/**
 * This handy function loads some settings and the like.
 */
function load_database()
{
	global $db_prefix, $db_connection, $db_type, $db_name, $db_user, $db_persist, $db_server, $db_passwd, $db_port;

	// Connect the database.
	if (empty($db_connection))
	{
		if (!defined('SOURCEDIR'))
			define('SOURCEDIR', TMP_BOARDDIR . '/Sources');

		// Need this to check whether we need the database password.
		require(TMP_BOARDDIR . '/Settings.php');

		if (!defined('ELK'))
			define('ELK', 1);

		require_once(SOURCEDIR . '/database/Database.subs.php');
		require_once(SOURCEDIR . '/database/Db.php');
		require_once(SOURCEDIR . '/database/DbTable.php');
		require_once(SOURCEDIR . '/database/Db-abstract.php');
		require_once(SOURCEDIR . '/database/Db-' . $db_type . '.class.php');
		require_once(SOURCEDIR . '/database/DbTable-' . $db_type . '.php');
		require_once(__DIR__ . '/DatabaseCode.php');

		$db_connection = elk_db_initiate($db_server, $db_name, $db_user, $db_passwd, $db_prefix, array('persist' => $db_persist, 'port' => $db_port), $db_type);
	}

	return database();
}

/**
 * The normal DbTable disallows to delete/create "core" tables
 */
function db_table_install()
{
	global $db_type;

	$db = load_database();

	require_once(SOURCEDIR . '/database/DbTable.php');
	require_once(SOURCEDIR . '/database/DbTable-' . $db_type . '.php');

	return call_user_func(array('DbTable_' . DB_TYPE . '_Install', 'db_table'), $db);
}

/**
 * Logs db errors as they happen
 */
function updateLastError()
{
	// Clear out the db_last_error file
	file_put_contents(TMP_BOARDDIR . '/db_last_error.txt', '0');
}

/**
 * Checks the servers database version against our requirements
 */
function db_version_check()
{
	global $db_type, $databases, $db_connection;

	$curver = $databases[$db_type]['version_check']($db_connection);
	$curver = preg_replace('~\-.+?$~', '', $curver);

	return version_compare($databases[$db_type]['version'], $curver, '<=');
}

/**
 * Delete the installer and its additional files.
 * Called by ?delete
 */
function action_deleteInstaller()
{
	global $package_ftp;

	definePaths();
	define('ELK', 'SSI');
	require_once(SUBSDIR . '/Package.subs.php');

	if (isset($_SESSION['installer_temp_ftp']))
	{
		$_SESSION['pack_ftp']['root'] = BOARDDIR;
		$package_ftp = new Ftp_Connection($_SESSION['installer_temp_ftp']['server'], $_SESSION['installer_temp_ftp']['port'], $_SESSION['installer_temp_ftp']['username'], $_SESSION['installer_temp_ftp']['password']);
		$package_ftp->chdir($_SESSION['installer_temp_ftp']['path']);
	}

	deltree(__DIR__);

	if (isset($_SESSION['installer_temp_ftp']))
	{
		$package_ftp->close();

		unset($_SESSION['installer_temp_ftp']);
	}

	// Now just redirect to a blank.png...
	header('Location: http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT']) . dirname($_SERVER['PHP_SELF']) . '/../themes/default/images/blank.png');
	exit;
}

/**
 * Removes flagged settings
 * Appends new settings as passed in $config_vars to the array
 * Writes out a new Settings.php file, overwriting any that may have existed
 *
 * @param array $config_vars
 * @param array $settingsArray
 */
function saveFileSettings($config_vars, $settingsArray)
{
	if (count($settingsArray) == 1)
		$settingsArray = preg_split('~[\r\n]~', $settingsArray[0]);

	for ($i = 0, $n = count($settingsArray); $i < $n; $i++)
	{
		if (trim($settingsArray[$i]) === '?>')
			$settingsArray[$i] = '';
		// Don't trim or bother with it if it's not a variable.
		if (substr($settingsArray[$i], 0, 1) == '$')
		{
			$settingsArray[$i] = trim($settingsArray[$i]) . "\n";

			foreach ($config_vars as $var => $val)
			{
				if (isset($settingsArray[$i]) && strncasecmp($settingsArray[$i], '$' . $var, 1 + strlen($var)) == 0)
				{
					if ($val === '#remove#')
					{
						unset($settingsArray[$i]);
					}
					else
					{
						$comment = strstr(substr($settingsArray[$i], strpos($settingsArray[$i], ';')), '#');
						$settingsArray[$i] = '$' . $var . ' = \'' . $val . '\';' . ($comment != '' ? "\t\t" . $comment : "\n");
					}

					unset($config_vars[$var]);
				}
			}
		}
	}

	// Add in the new vars we were passed
	if (!empty($config_vars))
	{
		$settingsArray[$i++] = '';
		foreach ($config_vars as $var => $val)
		{
			if ($val != '#remove#')
				$settingsArray[$i++] = "\n$" . $var . ' = \'' . $val . '\';';
		}
	}

	// Blank out the file - done to fix a oddity with some servers.
	$fp = @fopen(TMP_BOARDDIR . '/Settings.php', 'w');
	if (!$fp)
		return false;
	fclose($fp);

	$fp = fopen(TMP_BOARDDIR . '/Settings.php', 'r+');

	// Gotta have one of these ;)
	if (trim($settingsArray[0]) != '<?php')
		fwrite($fp, "<?php\n");

	$lines = count($settingsArray);
	for ($i = 0; $i < $lines; $i++)
	{
		// Don't just write a bunch of blank lines.
		if ($settingsArray[$i] != '' || @$settingsArray[$i - 1] != '')
			fwrite($fp, strtr($settingsArray[$i], "\r", ''));
	}
	fclose($fp);

	if (function_exists('opcache_invalidate'))
		opcache_invalidate(dirname(__FILE__) . '/Settings.php');

	return true;

	// Blank out the file - done to fix a oddity with some servers.
	//file_put_contents(BOARDDIR . '/Settings.php', '', LOCK_EX);
	//file_put_contents(BOARDDIR . '/Settings.php', $settingsArray, LOCK_EX);
}

/**
 * Check files are writable - make them writable if necessary...
 *
 * @param array $files
 */
function makeFilesWritable(&$files)
{
	global $upcontext;

	if (empty($files))
		return true;

	$failure = false;

	// On linux, it's easy - just use is_writable!
	if (substr(__FILE__, 1, 2) != ':\\')
	{
		foreach ($files as $k => $file)
		{
			if (!is_writable($file))
			{
				@chmod($file, 0755);

				// Well, 755 hopefully worked... if not, try 777.
				if (!is_writable($file) && !@chmod($file, 0777))
					$failure = true;
				// Otherwise remove it as it's good!
				else
					unset($files[$k]);
			}
			else
				unset($files[$k]);
		}
	}
	// Windows is trickier.  Let's try opening for r+...
	else
	{
		foreach ($files as $k => $file)
		{
			// Folders can't be opened for write... but the index.php in them can ;).
			if (is_dir($file))
				$file .= '/index.php';

			// Funny enough, chmod actually does do something on windows - it removes the read only attribute.
			@chmod($file, 0777);
			$fp = @fopen($file, 'r+');

			// Hmm, okay, try just for write in that case...
			if (!$fp)
				$fp = @fopen($file, 'w');

			if (!$fp)
				$failure = true;
			else
				unset($files[$k]);
			@fclose($fp);
		}
	}

	if (empty($files))
		return true;

	if (!isset($_SERVER))
		return !$failure;

	// What still needs to be done?
	$upcontext['chmod']['files'] = $files;

	// If it's windows it's a mess...
	if ($failure && substr(__FILE__, 1, 2) == ':\\')
	{
		$upcontext['chmod']['ftp_error'] = 'total_mess';

		return false;
	}
	// We're going to have to use... FTP!
	elseif ($failure)
	{
		// Load any session data we might have...
		if (!isset($_POST['ftp_username']) && isset($_SESSION['installer_temp_ftp']))
		{
			$upcontext['chmod']['server'] = $_SESSION['installer_temp_ftp']['server'];
			$upcontext['chmod']['port'] = $_SESSION['installer_temp_ftp']['port'];
			$upcontext['chmod']['username'] = $_SESSION['installer_temp_ftp']['username'];
			$upcontext['chmod']['password'] = $_SESSION['installer_temp_ftp']['password'];
			$upcontext['chmod']['path'] = $_SESSION['installer_temp_ftp']['path'];
		}
		// Or have we submitted?
		elseif (isset($_POST['ftp_username']))
		{
			$upcontext['chmod']['server'] = $_POST['ftp_server'];
			$upcontext['chmod']['port'] = $_POST['ftp_port'];
			$upcontext['chmod']['username'] = $_POST['ftp_username'];
			$upcontext['chmod']['password'] = $_POST['ftp_password'];
			$upcontext['chmod']['path'] = $_POST['ftp_path'];
		}

		if (isset($upcontext['chmod']['username']))
		{
			$ftp = new Ftp_Connection($upcontext['chmod']['server'], $upcontext['chmod']['port'], $upcontext['chmod']['username'], $upcontext['chmod']['password']);

			if ($ftp->error === false)
			{
				// Try it without /home/abc just in case they messed up.
				if (!$ftp->chdir($upcontext['chmod']['path']))
				{
					$upcontext['chmod']['ftp_error'] = $ftp->last_message;
					$ftp->chdir(preg_replace('~^/home[2]?/[^/]+?~', '', $upcontext['chmod']['path']));
				}
			}
		}

		if (!isset($ftp) || $ftp->error !== false)
		{
			if (!isset($ftp))
				$ftp = new Ftp_Connection(null);
			// Save the error so we can mess with listing...
			elseif ($ftp->error !== false && !isset($upcontext['chmod']['ftp_error']))
				$upcontext['chmod']['ftp_error'] = $ftp->last_message === null ? '' : $ftp->last_message;

			list ($username, $detect_path, $found_path) = $ftp->detect_path(TMP_BOARDDIR);

			if ($found_path || !isset($upcontext['chmod']['path']))
				$upcontext['chmod']['path'] = $detect_path;

			if (!isset($upcontext['chmod']['username']))
				$upcontext['chmod']['username'] = $username;

			return false;
		}
		else
		{
			// We want to do a relative path for FTP.
			if (!in_array($upcontext['chmod']['path'], array('', '/')))
			{
				$ftp_root = strtr(BOARDDIR, array($upcontext['chmod']['path'] => ''));
				if (substr($ftp_root, -1) == '/' && ($upcontext['chmod']['path'] == '' || $upcontext['chmod']['path'][0] === '/'))
				$ftp_root = substr($ftp_root, 0, -1);
			}
			else
				$ftp_root = BOARDDIR;

			// Save the info for next time!
			$_SESSION['installer_temp_ftp'] = array(
				'server' => $upcontext['chmod']['server'],
				'port' => $upcontext['chmod']['port'],
				'username' => $upcontext['chmod']['username'],
				'password' => $upcontext['chmod']['password'],
				'path' => $upcontext['chmod']['path'],
				'root' => $ftp_root,
			);

			foreach ($files as $k => $file)
			{
				if (!is_writable($file))
					$ftp->chmod($file, 0755);
				if (!is_writable($file))
					$ftp->chmod($file, 0777);

				// Assuming that didn't work calculate the path without the boarddir.
				if (!is_writable($file))
				{
					if (strpos($file, BOARDDIR) === 0)
					{
						$ftp_file = strtr($file, array($_SESSION['installer_temp_ftp']['root'] => ''));
						$ftp->chmod($ftp_file, 0755);
						if (!is_writable($file))
							$ftp->chmod($ftp_file, 0777);
						// Sometimes an extra slash can help...
						$ftp_file = '/' . $ftp_file;
						if (!is_writable($file))
							$ftp->chmod($ftp_file, 0755);
						if (!is_writable($file))
							$ftp->chmod($ftp_file, 0777);
					}
				}

				if (is_writable($file))
					unset($files[$k]);
			}

			$ftp->close();
		}
	}

	// What remains?
	$upcontext['chmod']['files'] = $files;

	if (empty($files))
		return true;

	return false;
}

function definePaths()
{
	global $boarddir, $cachedir, $extdir, $languagedir, $sourcedir;

	// Make sure the paths are correct... at least try to fix them.
	if (!file_exists($boarddir) && file_exists(TMP_BOARDDIR . '/agreement.txt'))
		$boarddir = TMP_BOARDDIR;
	if (!file_exists($sourcedir . '/SiteDispatcher.php') && file_exists($boarddir . '/Sources'))
		$sourcedir = $boarddir . '/Sources';

	// Check that directories which didn't exist in past releases are initialized.
	if ((empty($cachedir) || !file_exists($cachedir)) && file_exists($boarddir . '/cache'))
		$cachedir = $boarddir . '/cache';
	if ((empty($extdir) || !file_exists($extdir)) && file_exists($sourcedir . '/ext'))
		$extdir = $sourcedir . '/ext';
	if ((empty($languagedir) || !file_exists($languagedir)) && file_exists($boarddir . '/themes/default/languages'))
		$languagedir = $boarddir . '/themes/default/languages';

	if (!DEFINED('BOARDDIR'))
		DEFINE('BOARDDIR', $boarddir);
	if (!DEFINED('CACHEDIR'))
		DEFINE('CACHEDIR', $cachedir);
	if (!DEFINED('EXTDIR'))
		DEFINE('EXTDIR', $extdir);
	if (!DEFINED('LANGUAGEDIR'))
		DEFINE('LANGUAGEDIR', $languagedir);
	if (!DEFINED('ADDONSDIR'))
		DEFINE('ADDONSDIR', $boarddir . '/addons');
	if (!DEFINED('SOURCEDIR'))
		DEFINE('SOURCEDIR', $sourcedir);
	if (!DEFINED('ADMINDIR'))
		DEFINE('ADMINDIR', $sourcedir . '/Admin');
	if (!DEFINED('CONTROLLERDIR'))
		DEFINE('CONTROLLERDIR', $sourcedir . '/Controllers');
	if (!DEFINED('SUBSDIR'))
		DEFINE('SUBSDIR', $sourcedir . '/subs');
}