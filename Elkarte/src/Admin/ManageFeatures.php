<?php

/**
 * This file provides utility functions and db function for the profile functions,
 * notably, but not exclusively, deals with custom profile fields
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Admin;

class ManageFeatures
{
	/**
	 * Loads the signature from 50 members per request
	 * Used in ManageFeatures to apply signature settings to all members
	 *
	 * @todo move to ManageMembers or maybe MemberSignatures?
	 * @param int $start_member
	 * @return array
	 */
	function getSignatureFromMembers($start_member, $count = 49)
	{
		$db = $GLOBALS['elk']['db'];

		$members = array();

		$request = $db->query('', '
			SELECT id_member, signature
			FROM {db_prefix}members
			WHERE id_member BETWEEN ' . $start_member . ' AND ' . $start_member . ' + {int:array_size}
				AND id_group != {int:admin_group}
				AND FIND_IN_SET({int:admin_group}, additional_groups) = 0',
			array(
				'admin_group' => 11,
				'array_size' => $count,
			)
		);
		while ($result = $request->fetchAssoc()) {
			$members[$result['id_member']]['id_member'] = $result['id_member'];
			$members[$result['id_member']]['signature'] = $result['signature'];
		}

		return $members;
	}

	/**
	 * Updates the signature from a given member
	 *
	 * @param int $id_member
	 * @param string $signature
	 */
	function updateSignature($id_member, $signature)
	{
		updateMemberData($id_member, array('signature' => $signature));
	}

	/**
	 * Update all signatures given a new set of constraints
	 */
	function updateAllSignatures($applied_sigs)
	{
		global $context, $sig_start, $modSettings;

		$sig_start = time();

		// This is horrid - but I suppose some people will want the option to do it.
		$done = false;
		$context['max_member'] = maxMemberID();

		// Load all the signature settings.
		list ($sig_limits, $sig_bbc) = explode(':', $modSettings['signature_settings']);
		$sig_limits = explode(',', $sig_limits);
		$disabledTags = !empty($sig_bbc) ? explode(',', $sig_bbc) : array();

		// @todo temporary since it does not work, and seriously why would you do this?
		$disabledTags[] = 'footnote';

		while (!$done) {
			// No changed signatures yet
			$changes = array();

			// Get a group of member signatures, 50 at a clip
			$update_sigs = getSignatureFromMembers($applied_sigs);

			if (empty($update_sigs))
				$done = true;

			foreach ($update_sigs as $row) {
				// Apply all the rules we can realistically do.
				$sig = strtr($row['signature'], array('<br />' => "\n"));

				// Max characters...
				if (!empty($sig_limits[1]))
					$sig = $GLOBALS['elk']['text']->substr($sig, 0, $sig_limits[1]);

				// Max lines...
				if (!empty($sig_limits[2])) {
					$count = 0;
					$str_len = strlen($sig);
					for ($i = 0; $i < $str_len; $i++) {
						if ($sig[$i] == "\n") {
							$count++;
							if ($count >= $sig_limits[2])
								$sig = substr($sig, 0, $i) . strtr(substr($sig, $i), array("\n" => ' '));
						}
					}
				}

				// Max text size
				if (!empty($sig_limits[7]) && preg_match_all('~\[size=([\d\.]+)?(px|pt|em|x-large|larger)?~i', $sig, $matches) !== false && isset($matches[2])) {
					// Same as parse_bbc
					$sizes = array(1 => 0.7, 2 => 1.0, 3 => 1.35, 4 => 1.45, 5 => 2.0, 6 => 2.65, 7 => 3.95);

					foreach ($matches[1] as $ind => $size) {
						$limit_broke = 0;

						// Just specifying as [size=x]?
						if (empty($matches[2][$ind])) {
							$matches[2][$ind] = 'em';
							$size = isset($sizes[(int)$size]) ? $sizes[(int)$size] : 0;
						}

						// Attempt to allow all sizes of abuse, so to speak.
						if ($matches[2][$ind] == 'px' && $size > $sig_limits[7])
							$limit_broke = $sig_limits[7] . 'px';
						elseif ($matches[2][$ind] == 'pt' && $size > ($sig_limits[7] * 0.75))
							$limit_broke = ((int)$sig_limits[7] * 0.75) . 'pt';
						elseif ($matches[2][$ind] == 'em' && $size > ((float)$sig_limits[7] / 16))
							$limit_broke = ((float)$sig_limits[7] / 16) . 'em';
						elseif ($matches[2][$ind] != 'px' && $matches[2][$ind] != 'pt' && $matches[2][$ind] != 'em' && $sig_limits[7] < 18)
							$limit_broke = 'large';

						if ($limit_broke)
							$sig = str_replace($matches[0][$ind], '[size=' . $sig_limits[7] . 'px', $sig);
					}
				}

				// Stupid images - this is stupidly, stupidly challenging.
				if ((!empty($sig_limits[3]) || !empty($sig_limits[5]) || !empty($sig_limits[6]))) {
					$replaces = array();
					$img_count = 0;

					// Get all BBC tags...
					preg_match_all('~\[img(\s+width=([\d]+))?(\s+height=([\d]+))?(\s+width=([\d]+))?\s*\](?:<br />)*([^<">]+?)(?:<br />)*\[/img\]~i', $sig, $matches);

					// ... and all HTML ones.
					preg_match_all('~&lt;img\s+src=(?:&quot;)?((?:http://|ftp://|https://|ftps://).+?)(?:&quot;)?(?:\s+alt=(?:&quot;)?(.*?)(?:&quot;)?)?(?:\s?/)?&gt;~i', $sig, $matches2, PREG_PATTERN_ORDER);

					// And stick the HTML in the BBC.
					if (!empty($matches2)) {
						foreach ($matches2[0] as $ind => $dummy) {
							$matches[0][] = $matches2[0][$ind];
							$matches[1][] = '';
							$matches[2][] = '';
							$matches[3][] = '';
							$matches[4][] = '';
							$matches[5][] = '';
							$matches[6][] = '';
							$matches[7][] = $matches2[1][$ind];
						}
					}

					// Try to find all the images!
					if (!empty($matches)) {
						$image_count_holder = array();
						foreach ($matches[0] as $key => $image) {
							$width = -1;
							$height = -1;
							$img_count++;

							// Too many images?
							if (!empty($sig_limits[3]) && $img_count > $sig_limits[3]) {
								// If we've already had this before we only want to remove the excess.
								if (isset($image_count_holder[$image])) {
									$img_offset = -1;
									$rep_img_count = 0;
									while ($img_offset !== false) {
										$img_offset = strpos($sig, $image, $img_offset + 1);
										$rep_img_count++;
										if ($rep_img_count > $image_count_holder[$image]) {
											// Only replace the excess.
											$sig = substr($sig, 0, $img_offset) . str_replace($image, '', substr($sig, $img_offset));

											// Stop looping.
											$img_offset = false;
										}
									}
								} else
									$replaces[$image] = '';

								continue;
							}

							// Does it have predefined restraints? Width first.
							if ($matches[6][$key])
								$matches[2][$key] = $matches[6][$key];

							if ($matches[2][$key] && $sig_limits[5] && $matches[2][$key] > $sig_limits[5]) {
								$width = $sig_limits[5];
								$matches[4][$key] = $matches[4][$key] * ($width / $matches[2][$key]);
							} elseif ($matches[2][$key])
								$width = $matches[2][$key];

							// ... and height.
							if ($matches[4][$key] && $sig_limits[6] && $matches[4][$key] > $sig_limits[6]) {
								$height = $sig_limits[6];
								if ($width != -1)
									$width = $width * ($height / $matches[4][$key]);
							} elseif ($matches[4][$key])
								$height = $matches[4][$key];

							// If the dimensions are still not fixed - we need to check the actual image.
							if (($width == -1 && $sig_limits[5]) || ($height == -1 && $sig_limits[6]))
							{
								$sizes = url_image_size($matches[7][$key]);
								if (is_array($sizes)) {
									// Too wide?
									if ($sizes[0] > $sig_limits[5] && $sig_limits[5]) {
										$width = $sig_limits[5];
										$sizes[1] = $sizes[1] * ($width / $sizes[0]);
									}

									// Too high?
									if ($sizes[1] > $sig_limits[6] && $sig_limits[6]) {
										$height = $sig_limits[6];
										if ($width == -1)
											$width = $sizes[0];
										$width = $width * ($height / $sizes[1]);
									} elseif ($width != -1)
										$height = $sizes[1];
								}
							}

							// Did we come up with some changes? If so remake the string.
							if ($width != -1 || $height != -1)
								$replaces[$image] = '[img' . ($width != -1 ? ' width=' . round($width) : '') . ($height != -1 ? ' height=' . round($height) : '') . ']' . $matches[7][$key] . '[/img]';

							// Record that we got one.
							$image_count_holder[$image] = isset($image_count_holder[$image]) ? $image_count_holder[$image] + 1 : 1;
						}

						if (!empty($replaces))
							$sig = str_replace(array_keys($replaces), array_values($replaces), $sig);
					}
				}

				// Try to fix disabled tags.
				if (!empty($disabledTags)) {
					$sig = preg_replace('~\[(?:' . implode('|', $disabledTags) . ').+?\]~i', '', $sig);
					$sig = preg_replace('~\[/(?:' . implode('|', $disabledTags) . ')\]~i', '', $sig);
				}

				$sig = strtr($sig, array("\n" => '<br />'));
				$GLOBALS['elk']['hooks']->hook('apply_signature_settings', array(&$sig, $sig_limits, $disabledTags));
				if ($sig != $row['signature'])
					$changes[$row['id_member']] = $sig;
			}

			// Do we need to delete what we have?
			if (!empty($changes)) {
				foreach ($changes as $id => $sig)
					updateSignature($id, $sig);
			}

			$applied_sigs += 50;
			if (!$done)
				pauseSignatureApplySettings($applied_sigs);
		}
	}

	function getNotificationTypes()
	{
		Elk_Autoloader::getInstance()->register(SUBSDIR . '/MentionType', '\\ElkArte\\Sources\\subs\\MentionType');

		$glob = new \GlobIterator(SUBSDIR . '/MentionType/*Mention.php', \FilesystemIterator::SKIP_DOTS);
		$types = array();
		foreach ($glob as $file) {
			$class_name = '\\ElkArte\\Sources\\subs\\MentionType\\' . preg_replace('~([^^])((?<=)[A-Z](?=[a-z]))~', '$1_$2', $file->getBasename('.php'));
			$types[] = $class_name::getType();
		}

		return $types;
	}

	/**
	 * Returns the modules for the given mentions
	 *
	 * What it does:
	 * - Calls each modules static function ::getModules
	 * - Called from ManageFeatures.controller as part of notification settings
	 *
	 * @param string[] $enabled_mentions
	 *
	 * @return array
	 */
	function getMentionsModules($enabled_mentions)
	{
		$modules = array();

		foreach ($enabled_mentions as $mention) {
			$class_name = '\\ElkArte\\Sources\\subs\\MentionType\\' . ucfirst($mention) . '_Mention';
			$modules = $class_name::getModules($modules);
		}

		return $modules;
	}

	function getFrontPageControllers()
	{
		global $txt;

		$classes = array();

		$glob = new \GlobIterator(CONTROLLERDIR . '/*.controller.php', \FilesystemIterator::SKIP_DOTS);
		$classes += scanFileSystemForControllers($glob);

		$glob = new \GlobIterator(ADDONSDIR . '/*/Controllers/*.controller.php', \FilesystemIterator::SKIP_DOTS);
		$classes += scanFileSystemForControllers($glob, '\\ElkArte\\Addon\\');

		$config_vars = array(array('select', 'front_page', $classes));
		array_unshift($config_vars[0][2], $txt['default']);

		foreach (array_keys($classes) as $class_name) {
			$options = $class_name::frontPageOptions();
			if (!empty($options))
				$config_vars = array_merge($config_vars, $options);
		}

		return $config_vars;
	}

	/**
	 * @param GlobIterator $iterator
	 * @param string $namespace
	 *
	 * @return array
	 */
	function scanFileSystemForControllers($iterator, $namespace = '')
	{
		global $txt;

		$types = array();

		foreach ($iterator as $file) {
			$class_name = $namespace . preg_replace('~([^^])((?<=)[A-Z](?=[a-z]))~', '$1_$2', $file->getBasename('.controller.php')) . 'Controller';

			if (!class_exists($class_name)) {
				$class_name = $file->getBasename('.controller.php') . 'Controller';

				if (!class_exists($class_name))
					continue;
			}

			if ($class_name::canFrontPage()) {
				// Temporary
				if (!isset($txt[$class_name]))
					continue;

				$types[$class_name] = $txt[$class_name];
			}
		}

		return $types;
	}
}