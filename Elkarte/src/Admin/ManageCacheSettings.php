<?php

namespace Elkarte\Admin;

class ManageCacheSettings
{

	/**
	 * Finds all the caching engines available and loads some details depending on
	 * parameters.
	 *
	 * - Caching engines must follow the naming convention of XyzCache.class.php and
	 * have a class name of Xyz_Cache
	 *
	 * @param bool $supported_only If true, for each engine supported by the server
	 *             an array with 'title' and 'version' is returned.
	 *             If false, for each engine available an array with 'title' (string)
	 *             and 'supported' (bool) is returned.
	 * @return mixed[]
	 */
	function loadCacheEngines($supported_only = true)
	{
		$engines = array();

		$classes = new GlobIterator(SUBSDIR . '/CacheMethod/*.php', FilesystemIterator::SKIP_DOTS);

		foreach ($classes as $file_path)
		{
			// Get the engine name from the file name
			$parts = explode('.', $file_path->getBasename());
			$engine_name = $parts[0];
			$class = '\\ElkArte\\Sources\\subs\\CacheMethod\\' . $parts[0];

			// Validate the class name exists
			if (class_exists($class))
			{
				if ($supported_only && $class::available())
					$engines[strtolower($engine_name)] = $class::details();
				elseif ($supported_only === false)
				{
					$engines[strtolower($engine_name)] = array(
						'title' => $class::title(),
						'supported' => $class::available()
					);
				}
			}
		}

		return $engines;
	}
}