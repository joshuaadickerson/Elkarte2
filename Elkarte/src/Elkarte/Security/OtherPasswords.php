<?php

namespace Elkarte\Elkarte\Security;

// @todo use a loop instead of adding them all
// @todo add a setting to select which password strategies are tried
// @todo move the strategies to separate functions
class OtherPasswords
{
	public $passwords = [];
	protected $password = '';
	protected $salt = '';
	protected $name = '';

	public function __construct(array $user_settings)
	{
		$this->settings = $user_settings;
	}

	public function add()
	{
		global $modSettings, $sc;

		// What kind of data are we dealing with
		$pw_strlen = strlen($this->settings['passwd']);

		// Start off with none, that's safe
		$other_passwords = array();

		// None of the below cases will be used most of the time (because the salt is normally set.)
		if (!empty($modSettings['enable_password_conversion']) && $this->settings['password_salt'] == '')
		{
			// @todo we really should NOT be trying every password combination possible.
			// @todo figure out what each of these attempts go to which forum.

			// YaBB SE, Discus, MD5 (used a lot), SHA-1 (used some), SMF 1.0.x, IkonBoard, and none at all.
			$other_passwords[] = crypt($_POST['passwrd'], substr($_POST['passwrd'], 0, 2));
			$other_passwords[] = crypt($_POST['passwrd'], substr($this->settings['passwd'], 0, 2));

			if ($pw_strlen == 40)
			{
				$other_passwords[] = sha1($_POST['passwrd']);
			}

			$other_passwords[] = md5_hmac($_POST['passwrd'], strtolower($this->settings['member_name']));

			if ($pw_strlen == 32)
			{
				$other_passwords[] = md5($_POST['passwrd']);
				$other_passwords[] = md5($_POST['passwrd'] . strtolower($this->settings['member_name']));
				$other_passwords[] = md5(md5($_POST['passwrd']));
			}

			$other_passwords[] = $_POST['passwrd'];

			// This one is a strange one... MyPHP, crypt() on the MD5 hash.
			$other_passwords[] = crypt(md5($_POST['passwrd']), md5($_POST['passwrd']));

			// SHA-256
			if ($pw_strlen === 64)
			{
				// Snitz style
				$other_passwords[] = bin2hex(hash('sha256', $_POST['passwrd']));

				// Normal SHA-256
				$other_passwords[] = hash('sha256', $_POST['passwrd']);
			}

			// phpBB3 users new hashing.  We now support it as well ;).
			$other_passwords[] = $this->phpBB3_password_check($_POST['passwrd'], $this->settings['passwd']);

			// APBoard 2 Login Method.
			$other_passwords[] = md5(crypt($_POST['passwrd'], 'CRYPT_MD5'));
		}
		// The hash should be 40 if it's SHA-1, so we're safe with more here too.
		elseif (!empty($modSettings['enable_password_conversion']) && $pw_strlen === 32)
		{
			// vBulletin 3 style hashing?  Let's welcome them with open arms \o/.
			$other_passwords[] = md5(md5($_POST['passwrd']) . stripslashes($this->settings['password_salt']));

			// Hmm.. p'raps it's Invision 2 style?
			$other_passwords[] = md5(md5($this->settings['password_salt']) . md5($_POST['passwrd']));

			// Some common md5 ones.
			$other_passwords[] = md5($this->settings['password_salt'] . $_POST['passwrd']);
			$other_passwords[] = md5($_POST['passwrd'] . $this->settings['password_salt']);
		}
		// The hash is 40 characters, lets try some SHA-1 style auth
		elseif ($pw_strlen === 40)
		{
			// Maybe they are using a hash from before our password upgrade
			$other_passwords[] = sha1(strtolower($this->settings['member_name']) . un_htmlspecialchars($_POST['passwrd']));
			$other_passwords[] = sha1($this->settings['passwd'] . $sc);

			if (!empty($modSettings['enable_password_conversion']))
			{
				// BurningBoard3 style of hashing.
				$other_passwords[] = sha1($this->settings['password_salt'] . sha1($this->settings['password_salt'] . sha1($_POST['passwrd'])));

				// PunBB 1.4 and later
				$other_passwords[] = sha1($this->settings['password_salt'] . sha1($_POST['passwrd']));
			}

			// Perhaps we converted from a non UTF-8 db and have a valid password being hashed differently.
			if (!empty($modSettings['previousCharacterSet']) && $modSettings['previousCharacterSet'] != 'utf8')
			{
				// Try iconv first, for no particular reason.
				if (function_exists('iconv'))
					$other_passwords['iconv'] = sha1(strtolower(iconv('UTF-8', $modSettings['previousCharacterSet'], $this->settings['member_name'])) . un_htmlspecialchars(iconv('UTF-8', $modSettings['previousCharacterSet'], $_POST['passwrd'])));

				// Say it aint so, iconv failed!
				if (empty($other_passwords['iconv']) && function_exists('mb_convert_encoding'))
					$other_passwords[] = sha1(strtolower(mb_convert_encoding($this->settings['member_name'], 'UTF-8', $modSettings['previousCharacterSet'])) . un_htmlspecialchars(mb_convert_encoding($_POST['passwrd'], 'UTF-8', $modSettings['previousCharacterSet'])));
			}
		}
		// SHA-256 will be 64 characters long, lets check some of these possibilities
		elseif (!empty($modSettings['enable_password_conversion']) && $pw_strlen === 64)
		{
			// PHP-Fusion7
			$other_passwords[] = hash_hmac('sha256', $_POST['passwrd'], $this->settings['password_salt']);

			// Plain SHA-256?
			$other_passwords[] = hash('sha256', $_POST['passwrd'] . $this->settings['password_salt']);

			// Xenforo?
			$other_passwords[] = sha1(sha1($_POST['passwrd']) . $this->settings['password_salt']);
			$other_passwords[] = hash('sha256', (hash('sha256', ($_POST['passwrd']) . $this->settings['password_salt'])));
		}

		// ElkArte's sha1 function can give a funny result on Linux (Not our fault!). If we've now got the real one let the old one be valid!
		if (stripos(PHP_OS, 'win') !== 0)
		{
			require_once(ROOTDIR . '/Elkarte/Compat/Compat.subs.php');
			$other_passwords[] = sha1_smf(strtolower($this->settings['member_name']) . un_htmlspecialchars($_POST['passwrd']));
		}

		// Allows mods to easily extend the $other_passwords array
		$GLOBALS['elk']['hooks']->hook('other_passwords', array(&$other_passwords));

		return $other_passwords;
	}

	/**
	 * Custom encryption for phpBB3 based passwords.
	 *
	 * @package Authorization
	 * @param string $passwd
	 * @param string $passwd_hash
	 * @return string
	 */
	function phpBB3_password_check($passwd, $passwd_hash)
	{
		// Too long or too short?
		if (strlen($passwd_hash) != 34)
			return;

		// Range of characters allowed.
		$range = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

		// Tests
		$strpos = strpos($range, $passwd_hash[3]);
		$count = 1 << $strpos;
		$salt = substr($passwd_hash, 4, 8);

		$hash = md5($salt . $passwd, true);
		for (; $count != 0; --$count)
			$hash = md5($hash . $passwd, true);

		$output = substr($passwd_hash, 0, 12);
		$i = 0;
		while ($i < 16)
		{
			$value = ord($hash[$i++]);
			$output .= $range[$value & 0x3f];

			if ($i < 16)
				$value |= ord($hash[$i]) << 8;

			$output .= $range[($value >> 6) & 0x3f];

			if ($i++ >= 16)
				break;

			if ($i < 16)
				$value |= ord($hash[$i]) << 16;

			$output .= $range[($value >> 12) & 0x3f];

			if ($i++ >= 16)
				break;

			$output .= $range[($value >> 18) & 0x3f];
		}

		// Return now.
		return $output;
	}
}