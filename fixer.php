<?php

$dir = __DIR__ . '/Elkarte';

var_dump('Starting - dir: ' . $dir);
iterate($dir);

function iterate($dir)
{
	//var_dump('New dir ' . $dir);
	$sys = new FilesystemIterator($dir, FilesystemIterator::KEY_AS_PATHNAME & FilesystemIterator::CURRENT_AS_FILEINFO & FilesystemIterator::SKIP_DOTS);

	foreach ($sys as $file)
	{
		if ($file->isDir())
		{
			iterate($file->getPathname());
			continue;
		}

		if (strpos($file->getFilename(), '.class') === false)
			continue;

		$filename = $file->getPathname();
		$new_name = str_replace('.class', '', $filename);

		if ($filename !== $new_name)
		{
			//var_dump($sys);
			//rename($filename, $new_name);
			var_dump('File: ' . $filename . ' changed to ' . $new_name);
		}
	}
}