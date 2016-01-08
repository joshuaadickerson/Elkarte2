<?php

/**
 * Used to combine css and js files in to a single compressed file
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev
 */

namespace Elkarte\Elkarte;
use Patchwork\JSqueeze;

/**
 * Used to combine css or js files in to a single file
 *
 * What it does:
 * - Checks if the files have changed, and if so rebuilds the amalgamation
 * - Calls minification classes to reduce size of css and js file saving bandwidth
 * - Can creates a .gz file, be would require .htaccess or the like to use
 */
class SiteCombiner
{
	/**
	 * Holds all the files contents that we have joined in to one
	 *
	 * @var array
	 */
	protected $combine_files = array();

	/**
	 * Holds the file name of our newly created file
	 *
	 * @var string
	 */
	protected $archive_name = null;

	/**
	 * Holds the file names of the files in the compilation
	 *
	 * @var string
	 */
	protected $archive_filenames = null;

	/**
	 * Holds the comment line to add at the start of the compressed compilation
	 *
	 * @var string
	 */
	protected $archive_header = null;

	/**
	 * Holds the file data of the combined files
	 *
	 * @var string
	 */
	protected $cache = array();

	/**
	 * Holds the file data of pre minimized files
	 *
	 * @var string
	 */
	protected $min_cache = array();

	/**
	 * Holds the minified data of the combined files
	 *
	 * @var string
	 */
	protected $minified_cache = null;

	/**
	 * The directory where we will save the combined and packed files
	 *
	 * @var string
	 */
	protected $archive_dir = null;

	/**
	 * The url where we will save the combined and packed files
	 *
	 * @var string
	 */
	protected $archive_url = null;

	/**
	 * The stale parameter added to the url
	 *
	 * @var string
	 */
	protected $archive_stale = '';

	/**
	 * All the cache-stale params added to the file urls
	 *
	 * @var string[]
	 */
	protected $stales = array();

	/**
	 * All files that was not possible to combine
	 *
	 * @var string[]
	 */
	protected $spares = array();

	/**
	 * Location of the closure compiler
	 * @var string
	 */
	protected $url = 'http://closure-compiler.appspot.com/compile';

	/**
	 * Base post header to send to the closure compiler
	 * @var string
	 */
	protected $post_header = 'output_info=compiled_code&output_format=text&compilation_level=SIMPLE_OPTIMIZATIONS';

	/**
	 * Nothing much to do but start
	 *
	 * @param string $cachedir
	 * @param string $cacheurl
	 */
	public function _construct($cachedir, $cacheurl)
	{
		// Init
		$this->archive_dir = $cachedir;
		$this->archive_url = $cacheurl;
	}

	/**
	 * Combine javascript files in to a single file to save requests
	 *
	 * @param mixed[] $files array created by loadjavascriptfile function
	 * @param bool $do_defered true when coming from footer area, false for header
	 * @return string
	 */
	public function site_js_combine($files, $do_defered)
	{
		// No files or missing or not writable directory then we are done
		if (empty($files) || !file_exists($this->archive_dir) || !is_writable($this->archive_dir))
			return false;

		$this->spares = array();

		// Get the filename's and last modified time for this batch
		foreach ($files as $id => $file)
		{
			$load = (!$do_defered && empty($file['options']['defer'])) || ($do_defered && !empty($file['options']['defer']));

			// Get the ones that we would load locally so we can merge them
			if ($load && (empty($file['options']['local']) || !$this->addFile($file['options'])))
				$this->spares[$id] = $file;
		}

		// Nothing to do, then we are done
		if (count($this->combine_files) === 0)
			return true;

		// Create the archive name
		$this->buildName('.js');

		// No file, or a stale one, create a new compilation
		if ($this->isStale())
		{
			// Our buddies will be needed for this to work.
			require_once(ROOTDIR . '/Packages/Package.subs.php');

			$this->archive_header = '// ' . $this->archive_filenames . "\n";
			$this->combineFiles('js');

			// Minify these files to save space,
			$this->minified_cache = $this->jsCompiler();

			// And save them for future users
			$this->saveFiles();
		}

		// Return the name for inclusion in the output
		return $this->archive_url . '/' . $this->archive_name . $this->archive_stale;
	}

	/**
	 * Combine css files in to a single file
	 *
	 * @param string[] $files
	 */
	public function site_css_combine($files)
	{
		// No files or missing dir then we are done
		if (empty($files) || !file_exists($this->archive_dir))
			return false;

		// Get the filenames and last modified time for this batch
		foreach ($files as $id => $file)
		{
			// Get the ones that we would load locally so we can merge them
			if (empty($file['options']['local']) || !$this->addFile($file['options']))
				$this->spares[$id] = $file;
		}

		// Nothing to do so return
		if (count($this->combine_files) === 0)
			return;

		// Create the css archive name
		$this->buildName('.css');

		// No file, or a stale one, so we create a new css compilation
		if ($this->isStale())
		{
			$this->archive_header = '/* ' . $this->archive_filenames . " */\n";
			$this->combineFiles('css');

			// CSSmin it to save some space
			$compressor = new \CSSmin($this->cache);
			$this->minified_cache = $compressor->run($this->cache);

			// Combined in any pre minimized to our new minimized string
			$this->minified_cache .= "\n" . $this->min_cache;

			$this->saveFiles();
		}

		// Return the name
		return $this->archive_url . '/' . $this->archive_name . $this->archive_stale;
	}

	/**
	 * Returns the info of the files that were not combined
	 *
	 * @return string[]
	 */
	public function getSpares()
	{
		return $this->spares;
	}

	/**
	 * Add all the file parameters to the $combine_files array
	 *
	 * What it does:
	 * - If the file has a 'stale' option defined it will be added to the
	 *   $stales array as well to be used later
	 * - Tags any files that are pre-minimized by filename matching .min.js
	 *
	 * @param string[] $options An array with all the passed file options:
	 * - dir
	 * - basename
	 * - file
	 * - url
	 * - stale (optional)
	 */
	protected function addFile($options)
	{
		if (isset($options['dir']))
		{
			$filename = $options['dir'] . $options['basename'];
			$this->combine_files[$options['basename']] = array(
				'file' => $filename,
				'basename' => $options['basename'],
				'url' => $options['url'],
				'filemtime' => filemtime($filename),
				'minimized' => (bool) strpos($options['basename'], '.min.js') !== false || strpos($options['basename'], '.min.css') !== false,
			);

			$this->stales[] = $this->combine_files[$options['basename']]['filemtime'];

			return true;
		}
		return false;
	}

	/**
	 * Determines if the existing combined file is stale
	 *
	 * - If any date of the files that make up the archive are newer than the archive, its considered stale
	 */
	protected function isStale()
	{
		// If any files in the archive are newer than the archive file itself, then the archive is stale
		$filemtime = file_exists($this->archive_dir . '/' . $this->archive_name) ? filemtime($this->archive_dir . '/' . $this->archive_name) : 0;

		foreach ($this->combine_files as $file)
		{
			if ($file['filemtime'] > $filemtime)
				return true;
		}

		return false;
	}

	/**
	 * Creates a new archive name
	 *
	 * @param string $type - should be one of '.js' or '.css'
	 */
	protected function buildName($type)
	{
		global $settings;

		// Create this groups archive name
		foreach ($this->combine_files as $file)
			$this->archive_filenames .= $file['basename'] . ' ';

		// Add in the actual theme url to make the sha1 unique to this hive
		$this->archive_filenames = $settings['actual_theme_url'] . '/' . trim($this->archive_filenames);

		// Save the hive, or a nest, or a conglomeration. Like it was grown
		$this->archive_name = 'hive-' . sha1($this->archive_filenames) . $type;

		// Create a unique cache stale for his hive ?12345
		if (!empty($this->stales))
			$this->archive_stale = '?' . hash('crc32', implode(' ', $this->stales));
	}

	/**
	 * Reads each files contents in to the _combine_files array
	 *
	 * What it does:
	 * - For each file, loads its contents in to the content key
	 * - If the file is CSS will convert some common relative links to the
	 * location of the hive
	 *
	 * @param string $type one of css or js
	 */
	protected function combineFiles($type)
	{
		// Remove any old cache file(s)
		@unlink($this->archive_dir . '/' . $this->archive_name);
		@unlink($this->archive_dir . '/' . $this->archive_name . '.gz');

		$cache = array();
		$min_cache = array();

		// Read in all the data so we can process
		foreach ($this->combine_files as $key => $file)
		{
			$tempfile = trim(file_get_contents($file['file']));
			$tempfile = (substr($tempfile, -3) === '}()') ? $tempfile . ';' : $tempfile;
			$this->combine_files[$key]['content'] = $tempfile;

			// CSS needs relative locations converted for the moved hive to work
			if ($type === 'css')
			{
				$tempfile = str_replace(array('../../images', '../images'), $file['url'] . '/images', $tempfile);
				$tempfile = str_replace(array('../../webfonts', '../webfonts'), $file['url'] . '/webfonts', $tempfile);
				$tempfile = str_replace(array('../../scripts', '../scripts'), $file['url'] . '/scripts', $tempfile);
			}

			// Add the file to the correct array for processing
			if ($file['minimized'] === false)
				$cache[] = $tempfile;
			else
				$min_cache[] = $tempfile;
		}

		// Build out our combined file strings
		$this->cache = implode("\n", $cache);
		$this->min_cache = implode("\n", $min_cache);
		unset($cache, $min_cache);
	}

	/**
	 * Save a compilation as text and optionally a compressed .gz file
	 */
	protected function saveFiles()
	{
		// Add in the file header if available
		if (!empty($this->archive_header))
			$this->minified_cache = $this->archive_header . $this->minified_cache;

		// First the plain text version
		file_put_contents($this->archive_dir . '/' . $this->archive_name, $this->minified_cache, LOCK_EX);

		// And now the compressed version, just uncomment the below
		/*
		$fp = gzopen($this->archive_dir . '/' . $this->archive_name . '.gz', 'w9');
		gzwrite ($fp, $this->minified_cache);
		gzclose($fp);
		*/
	}

	/**
	 * Takes a js file and compresses it to save space, will try several methods
	 * to minimize the code
	 *
	 * What it does:
	 * - Attempt to use the closure-compiler API using code_url
	 * - Failing that will use JSqueeze
	 * - Failing that it will use the closure-compiler API using js_code
	 *    a) single block if it can or
	 *    b) as multiple calls
	 * - Failing that will return original uncompressed file
	 */
	protected function jsCompiler()
	{
		global $context;

		// First try the closure request using code_url param
		$fetch_data = $this->closure_code_url();

		// Nothing returned or an error, try our internal JSqueeze minimizer
		if ($fetch_data === false || trim($fetch_data) == '' || preg_match('/^Error\(\d{1,2}\):\s/m', $fetch_data))
		{
			// To prevent a stack overflow segmentation fault, which silently kills Apache, we need to limit
			// recursion on windows.  This may cause JSqueeze to fail, but at least its then catchable.
			if (serverIs('windows'))
				@ini_set('pcre.recursion_limit', '524');

			$jsqueeze = new JSqueeze();
			$fetch_data = $jsqueeze->squeeze($this->cache);
		}

		// If we still have no data, then try the post js_code method to the closure compiler
		if ($fetch_data === false || trim($fetch_data) == '')
			$fetch_data = $this->closure_js_code();

		// If we have nothing to return, use the original data
		$fetch_data = ($fetch_data === false || trim($fetch_data) == '') ? $this->cache : $fetch_data;

		// Return a combined pre minimized + our minimized string
		return $this->min_cache . "\n" . $fetch_data;
	}

	/**
	 * Makes a request to the closure compiler using the code_url syntax
	 *
	 * What it does:
	 * - Allows us to make a single request and let the compiler fetch the files from us
	 * - Best option if its available (closure can see the files)
	 */
	protected function closure_code_url()
	{
		$post_data = '';

		// Build the closure request using code_url param, this allows us to do a single request
		foreach ($this->combine_files as $file)
		{
			if ($file['minimized'] === false)
				$post_data .= '&code_url=' . urlencode($file['url'] . '/scripts/' . $file['basename'] . $this->archive_stale);
		}

		return fetch_web_data($this->url, $this->post_header . $post_data);
	}

	/**
	 * Makes a request to the closure compiler using the js_code syntax
	 *
	 * What it does:
	 * - If our combined file size allows, this is done as a single post to the compiler
	 * - If the combined string is to large, then it is processed as chunks done
	 * to minimize the number of posts required
	 */
	protected function closure_js_code()
	{
		// As long as we are below 200000 in post data size we can do this in one request
		if ($GLOBALS['elk']['text']->strlen(urlencode($this->post_header . $this->cache)) <= 200000)
		{
			$post_data = '&js_code=' . urlencode($this->cache);
			$fetch_data = fetch_web_data($this->url, $this->post_header . $post_data);
		}
		// Simply to much data for a single post so break it down in to as few as possible
		else
			$fetch_data = $this->closure_js_code_chunks();

		return $fetch_data;
	}

	/**
	 * Combine files in to <200k chunks and make closure compiler requests
	 *
	 * What it does:
	 * - Loads as many files as it can in to a single post request while
	 * keeping the post size within the limits accepted by the service
	 * - Will do multiple requests until done, combining the results
	 * - Returns the compressed string or the original if an error occurs
	 */
	protected function closure_js_code_chunks()
	{
		$fetch_data = '';
		$combine_files = array_values($this->combine_files);

		for ($i = 0, $filecount = count($combine_files); $i < $filecount; $i++)
		{
			// New post request, start off empty
			$post_len = 0;
			$post_data = '';
			$post_data_raw = '';

			// Combine data in to chunks of < 200k to minimize http posts
			while ($i < $filecount)
			{
				// Get the details for this file
				$file = $combine_files[$i];

				// Skip over minimized ones
				if ($file['minimized'] === true)
				{
					$i++;
					continue;
				}

				// Prepare the data for posting
				$data = urlencode($file['content']);
				$data_len = $GLOBALS['elk']['text']->strlen($data);

				// While we can add data to the post and not accede the post size allowed by the service
				if ($data_len + $post_len < 200000)
				{
					$post_data .= $data;
					$post_data_raw .= $file['content'];
					$post_len = $data_len + $post_len;
					$i++;
				}
				// No more room in this request, so back up and make the request
				else
				{
					$i--;
					break;
				}
			}

			// Send it off and get the results
			$post_data = '&js_code=' . $post_data;
			$data = fetch_web_data($this->url, $this->post_header . $post_data);

			// Use the results or the raw data if an error is detected
			$fetch_data .= ($data === false || trim($data) == '' || preg_match('/^Error\(\d{1,2}\):\s/m', $data)) ? $post_data_raw : $data;
		}

		return $fetch_data;
	}
}