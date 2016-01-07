<?php

/**
 * This can be in the theme directory, but one must exist elsewhere in case
 * the theme author forgets it. Also need to have a test to make sure this
 * file is here.
 */
class MissingTemplate extends AbstractTemplate
{
	public function postRegister($theme)
	{
		global $modSettings;
		ob_end_clean();
		if (!empty($modSettings['enableCompressedOutput']))
			@ob_start('ob_gzhandler');
		else
			ob_start();
	}

	public function outputHeaders()
	{
		if (isset($_GET['debug']))
			header('Content-Type: application/xhtml+xml; charset=UTF-8');

		// Don't cache error pages!!
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
		header('Cache-Control: no-cache');
	}

	public function noTemplate($filename)
	{
		global $txt, $scripturl, $boardurl;

		if (!isset($txt['template_parse_error']))
		{
			$txt['template_parse_error'] = 'Template Parse Error!';
			$txt['template_parse_error_message'] = 'It seems something has gone sour on the forum with the template system.  This problem should only be temporary, so please come back later and try again.  If you continue to see this message, please contact the administrator.<br /><br />You can also try <a href="javascript:location.reload();">refreshing this page</a>.';
			$txt['template_parse_error_details'] = 'There was a problem loading the <span style="font-family: monospace;"><strong>%1$s</strong></span> template or language file.  Please check the syntax and try again - remember, single quotes (<span style="font-family: monospace;">\'</span>) often have to be escaped with a slash (<span style="font-family: monospace;">\\</span>).  To see more specific error information from PHP, try <a href="' . $boardurl . '%1$s" class="extern">accessing the file directly</a>.<br /><br />You may want to try to <a href="javascript:location.reload();">refresh this page</a> or <a href="' . $scripturl . '?theme=1">use the default theme</a>.';
		}

		// First, let's get the doctype and language information out of the way.
		echo '<!DOCTYPE html>
			<html ', !empty($GLOBALS['context']['right_to_left']) ? 'dir="rtl"' : '', '>
				<head>
					<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';

					if (!empty($GLOBALS['maintenance']) && !allowedTo('admin_forum'))
						echo '
					<title>', $GLOBALS['mtitle'], '</title>
				</head>
				<body>
					<h3>', $GLOBALS['mtitle'], '</h3>
					', $GLOBALS['mmessage'], '
				</body>
			</html>';
					elseif (!allowedTo('admin_forum'))
						echo '
					<title>', $txt['template_parse_error'], '</title>
				</head>
				<body>
					<h3>', $txt['template_parse_error'], '</h3>
					', $txt['template_parse_error_message'], '
				</body>
			</html>';
		else
		{
			require_once(SUBSDIR . '/Package.subs.php');

			$error = fetch_web_data($boardurl . strtr($filename, array(BOARDDIR => '', strtr(BOARDDIR, '\\', '/') => '')));
			if (empty($error) && ini_get('track_errors'))
				$error = $php_errormsg;

			$error = strtr($error, array('<b>' => '<strong>', '</b>' => '</strong>'));

			echo '
		<title>', $txt['template_parse_error'], '</title>
	</head>
	<body>
		<h3>', $txt['template_parse_error'], '</h3>
		', sprintf($txt['template_parse_error_details'], strtr($filename, array(BOARDDIR => '', strtr(BOARDDIR, '\\', '/') => '')));

			if (!empty($error))
				echo '
		<hr />

		<div style="margin: 0 20px;"><span style="font-family: monospace;">', strtr(strtr($error, array('<strong>' . BOARDDIR => '<strong>...', '<strong>' . strtr(BOARDDIR, '\\', '/') => '<strong>...')), '\\', '/'), '</span></div>';

			// I know, I know... this is VERY COMPLICATED.  Still, it's good.
			if (preg_match('~ <strong>(\d+)</strong><br( /)?' . '>$~i', $error, $match) != 0)
			{
				$data = file($filename);
				$data2 = highlight_php_code(implode('', $data));
				$data2 = preg_split('~\<br( /)?\>~', $data2);

				// Fix the PHP code stuff...
				if (!isBrowser('gecko'))
					$data2 = str_replace("\t", '<span style="white-space: pre;">' . "\t" . '</span>', $data2);
				else
					$data2 = str_replace('<pre style="display: inline;">' . "\t" . '</pre>', "\t", $data2);

				// Now we get to work around a bug in PHP where it doesn't escape <br />s!
				$j = -1;
				foreach ($data as $line)
				{
					$j++;

					if (substr_count($line, '<br />') == 0)
						continue;

					$n = substr_count($line, '<br />');
					for ($i = 0; $i < $n; $i++)
					{
						$data2[$j] .= '&lt;br /&gt;' . $data2[$j + $i + 1];
						unset($data2[$j + $i + 1]);
					}
					$j += $n;
				}
				$data2 = array_values($data2);
				array_unshift($data2, '');

				echo '
		<div style="margin: 2ex 20px; width: 96%; overflow: auto;"><pre style="margin: 0;">';

				// Figure out what the color coding was before...
				$line = max($match[1] - 9, 1);
				$last_line = '';
				for ($line2 = $line - 1; $line2 > 1; $line2--)
					if (strpos($data2[$line2], '<') !== false)
					{
						if (preg_match('~(<[^/>]+>)[^<]*$~', $data2[$line2], $color_match) != 0)
							$last_line = $color_match[1];
						break;
					}

				// Show the relevant lines...
				for ($n = min($match[1] + 4, count($data2) + 1); $line <= $n; $line++)
				{
					if ($line == $match[1])
						echo '</pre><div style="background: #ffb0b5;"><pre style="margin: 0;">';

					echo '<span style="color: black;">', sprintf('%' . strlen($n) . 's', $line), ':</span> ';
					if (isset($data2[$line]) && $data2[$line] != '')
						echo substr($data2[$line], 0, 2) == '</' ? preg_replace('~^</[^>]+>~', '', $data2[$line]) : $last_line . $data2[$line];

					if (isset($data2[$line]) && preg_match('~(<[^/>]+>)[^<]*$~', $data2[$line], $color_match) != 0)
					{
						$last_line = $color_match[1];
						echo '</', substr($last_line, 1, 4), '>';
					}
					elseif ($last_line != '' && strpos($data2[$line], '<') !== false)
						$last_line = '';
					elseif ($last_line != '' && $data2[$line] != '')
						echo '</', substr($last_line, 1, 4), '>';

					if ($line == $match[1])
						echo '</pre></div><pre style="margin: 0;">';
					else
						echo "\n";
				}

				echo '</pre></div>';
			}

			echo '
	</body>
</html>';
		}

		die;
	}
}