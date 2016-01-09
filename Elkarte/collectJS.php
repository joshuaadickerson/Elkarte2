<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$dir = __DIR__;

$keys = [];

var_dump('Starting - dir: ' . $dir);
iterate($dir);

$keys = array_flip($keys);
var_dump($keys);

$loaded_txt = [];
findTextFiles(__DIR__ . '/src/Elkarte/Language/languages/english/', $keys);
var_export($loaded_txt);

function iterate($dir)
{
	global $keys;

	$sys = new FilesystemIterator($dir, FilesystemIterator::KEY_AS_PATHNAME & FilesystemIterator::CURRENT_AS_FILEINFO & FilesystemIterator::SKIP_DOTS);

	foreach ($sys as $file)
	{
		if ($file->isDir())
		{
			iterate($file->getPathname());
			continue;
		}

		if (strpos($file->getFilename(), '.php') === false)
		{
			//var_dump('Skipping: ' . $file->getFilename());
			continue;
		}

		$matches = [];
		preg_match_all('~JavaScriptEscape\(\$txt\[\'(.*)\\\'\]\)~U', file_get_contents($file->getPathname()), $matches);
		if (!empty($matches[1]))
		{
			var_dump('Found matches in : ' . $file->getFilename());
			var_dump($matches);
			$keys = array_merge($keys, $matches[1]);
		}
		/*$filename = $file->getPathname();
		$new_name = str_replace('.class', '', $filename);

		if ($filename !== $new_name)
		{
			//var_dump($sys);
			//rename($filename, $new_name);
			var_dump('File: ' . $filename . ' changed to ' . $new_name);
		}*/
	}
}

function JavaScriptEscape($string)
{
	//global $scripturl;

	return strtr($string, array(
		"\r" => '',
		"\n" => '\\n',
		"\t" => '\\t',
		'\\' => '\\\\',
		'\'' => '\\\'',
		'</' => '<\' + \'/',
		'<script' => '<scri\'+\'pt',
		'<body>' => '<bo\'+\'dy>',
		'<a href' => '<a hr\'+\'ef',
		//(string) $scripturl => '\' + elk_scripturl + \'',
	));
}

function findTextFiles($dir, array $keys)
{
	global $loaded_txt;

	$sys = new FilesystemIterator($dir, FilesystemIterator::KEY_AS_PATHNAME & FilesystemIterator::CURRENT_AS_FILEINFO & FilesystemIterator::SKIP_DOTS);

	$preg_keys = implode('|', $keys);

	foreach ($sys as $file)
	{
		$txt = [];

		if ($file->isDir()) {
			iterate($file->getPathname());
			continue;
		}

		if (strpos($file->getFilename(), 'english.php') === false) {
			continue;
		}

		$filecontents = file_get_contents($file->getPathname());

		if (preg_match('~' . $preg_keys . '~', $filecontents))
		{
			var_dump('Found text: ' . $file->getFilename());
			require_once $file->getPathname();
			$found_keys = array_keys(array_intersect_key($keys, $txt));
			var_dump($found_keys);

			foreach ($found_keys as $key)
			{
				$loaded_txt[$key] = JavaScriptEscape($txt[$key]);
			}
		}
	}
}