<?php

/**
 * Check for un-posted attachments is something we can do once in a while :P
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

namespace Elkarte\Scheduler\Tasks;

use FilesystemIterator;
use UnexpectedValueException;

/**
 * Class Remove_Temp_Attachments - Check for un-posted attachments is something we can do once in a while :P
 *
 * - This function uses FilesystemIterator cycling through all the attachments
 *
 * @package ScheduledTasks
 */
class RemoveTempAttachments implements ScheduledTaskInterface
{
	public function run()
	{
		global $context, $txt;

		// We need to know where this thing is going.

		$attach_dirs = attachmentPaths();

		foreach ($attach_dirs as $attach_dir)
		{
			try
			{
				$files = new FilesystemIterator($attach_dir, FilesystemIterator::SKIP_DOTS);
				foreach ($files as $file)
				{
					if (strpos($file->getFilename(), 'post_tmp_') !== false)
					{
						// Temp file is more than 5 hours old!
						if ($file->getMTime() < time() - 18000)
							@unlink($file->getPathname());
					}
				}
			}
			catch (UnexpectedValueException $e)
			{
				loadEssentialThemeData();
				loadLanguage('Post');

				$context['scheduled_errors']['remove_temp_attachments'][] = $txt['cant_access_upload_path'] . ' (' . $attach_dir . ')';
				$GLOBALS['elk']['errors']->log_error($txt['cant_access_upload_path'] . ' (' . $e->getMessage() . ')', 'critical');

				return false;
			}
		}

		return true;
	}
}