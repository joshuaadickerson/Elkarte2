<?php

/**
 * Attachment display.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Attachments;

use \Elkarte\Elkarte\Controller\AbstractController;
use \Elkarte\Elkarte\Controller\Action;

/**
 * AttachmentController class.
 *
 * - Handles the downloading of an attachment or avatar
 * - Handles the uploading of attachments via Ajax
 * - increments the download count where applicable
 *
 * @package Attachments
 */
class AttachmentController extends AbstractController
{
	/**
	 * The default action is to download an attachment.
	 * This allows ?action=attachment to be forwarded to action_dlattach()
	 */
	public function action_index()
	{
		// add an subaction array to act accordingly
		$subActions = array(
			'dlattach' => array($this, 'action_dlattach'),
			'tmpattach' => array($this, 'action_tmpattach'),
			'ulattach' => array($this, 'action_ulattach'),
			'rmattach' => array($this, 'action_rmattach'),
		);

		// Setup the action handler
		$action = new Action();
		$subAction = $action->initialize($subActions, 'dlattach');

		// Call the action
		$action->dispatch($subAction);
	}

	/**
	 * Function to upload attachments via ajax calls
	 *
	 * - Currently called by drag drop attachment functionality
	 * - Pass the form data with session vars
	 * - Responds back with errors or file data
	 */
	public function action_ulattach()
	{
		global $context, $modSettings, $txt;

		$resp_data = array();
		loadLanguage('Errors');
		$context['attachments']['can']['post'] = !empty($modSettings['attachmentEnable']) && $modSettings['attachmentEnable'] == 1 && (allowedTo('post_attachment') || ($modSettings['postmod_active'] && allowedTo('post_unapproved_attachments')));

		// Set up the template details
		$this->_layers->removeAll();
		$this->_templates->load('Json');
		$context['sub_template'] = 'send_json';

		// Make sure the session is still valid
		if ($this->session->check('request', '', false) != '')
		{
			$context['json_data'] = array('result' => false, 'data' => $txt['session_timeout_file_upload']);
			return false;
		}

		// We should have files, otherwise why are we here?
		if (isset($_FILES['attachment']))
		{
			loadLanguage('Post');

			$attach_errors = AttachmentErrorContext::context();
			$attach_errors->activate();

			if ($context['attachments']['can']['post'] && empty($this->http_req->post->from_qr))
			{


				$process = $this->http_req->getPost('msg', 'intval', '');
				processAttachments($process);
			}

			// Any mistakes?
			if ($attach_errors->hasErrors())
			{
				$errors = $attach_errors->prepareErrors();

				// Bad news for you, the attachments did not process, lets tell them why
				foreach ($errors as $key => $error)
					$resp_data[] = $error;

				$context['json_data'] = array('result' => false, 'data' => $resp_data);
			}
			// No errors, lets get the details of what we have for our response back
			else
			{
				foreach ($_SESSION['temp_attachments'] as $attachID => $val)
				{
					// We need to grab the name anyhow
					if (!empty($val['tmp_name']))
					{
						$resp_data = array(
							'name' => $val['name'],
							'attachid' => $attachID,
							'size' => $val['size']
						);
					}
				}

				$context['json_data'] = array('result' => true, 'data' => $resp_data);
			}
		}
		// Could not find the files you claimed to have sent
		else
			$context['json_data'] = array('result' => false, 'data' => $txt['no_files_uploaded']);
	}

	/**
	 * Function to remove attachments which were added via ajax calls
	 *
	 * - Currently called by drag drop attachment functionality
	 * - Requires file name and file path
	 * - Responds back with success or error
	 */
	public function action_rmattach()
	{
		global $context, $txt;

		// Prepare the template so we can respond with json
		$this->_layers->removeAll();
		$this->_templates->load('Json');
		$context['sub_template'] = 'send_json';

		// Make sure the session is valid
		if ($this->session->check('request', '', false) !== '')
		{
			loadLanguage('Errors');
			$context['json_data'] = array('result' => false, 'data' => $txt['session_timeout']);

			return false;
		}

		// We need a filename and path or we are not going any further
		if (isset($this->http_req->post->attachid) && !empty($_SESSION['temp_attachments']))
		{


			$result = removeTempAttachById($this->http_req->post->attachid);
			if ($result === true)
				$context['json_data'] = array('result' => true);
			else
			{
				loadLanguage('Errors');
				$context['json_data'] = array('result' => false, 'data' => $txt[$result]);
			}
		}
		else
		{
			loadLanguage('Errors');
			$context['json_data'] = array('result' => false, 'data' => $txt['attachment_not_found']);
		}
	}

	/**
	 * Downloads an attachment or avatar, and increments the download count.
	 *
	 * - It requires the view_attachments permission. (not for avatars!)
	 * - It disables the session parser, and clears any previous output.
	 * - It is accessed via the query string ?action=dlattach.
	 * - Views to attachments and avatars do not increase hits and are not logged
	 *   in the "Who's Online" log.
	 */
	public function action_dlattach()
	{
		global $txt, $modSettings, $user_info, $context, $topic;

		// Some defaults that we need.
		$context['no_last_modified'] = true;

		// Make sure some attachment was requested!
		if (!isset($this->http_req->query->attach) && !isset($this->http_req->query->id))
			$this->_errors->fatal_lang_error('no_access', false);

		$id_attach = isset($this->http_req->query->attach)
			? (int) $this->http_req->query->attach
			: (int) $this->http_req->query->id;

		if ($this->http_req->getQuery('type') === 'avatar')
		{
			$attachment = getAvatar($id_attach);

			$is_avatar = true;
			$this->http_req->query->image = true;
		}
		// This is just a regular attachment...
		else
		{
			isAllowedTo('view_attachments');
			$attachment = getAttachmentFromTopic($id_attach, $topic);
		}

		if (empty($attachment))
			$this->_errors->fatal_lang_error('no_access', false);

		list ($id_folder, $real_filename, $file_hash, $file_ext, $id_attach, $attachment_type, $mime_type, $is_approved, $id_member) = $attachment;

		// If it isn't yet approved, do they have permission to view it?
		if (!$is_approved && ($id_member == 0 || $user_info['id'] != $id_member) && ($attachment_type == 0 || $attachment_type == 3))
			isAllowedTo('approve_posts');

		// Update the download counter (unless it's a thumbnail or an avatar).
		if (empty($is_avatar) || $attachment_type != 3)
			increaseDownloadCounter($id_attach);

		$filename = getAttachmentFilename($id_attach, $file_hash, $id_folder);

		// This is done to clear any output that was made before now.
		while (ob_get_level() > 0)
			@ob_end_clean();

		if (!empty($modSettings['enableCompressedOutput']) && @filesize($filename) <= 4194304
			&& in_array($file_ext, array('txt', 'html', 'htm', 'js', 'doc', 'docx', 'rtf', 'css', 'php', 'log', 'xml', 'sql', 'c', 'java')))
		{
			ob_start('ob_gzhandler');
		}
		else
		{
			ob_start();
			header('Content-Encoding: none');
		}

		// No point in a nicer message, because this is supposed to be an attachment anyway...
		if (!file_exists($filename))
		{
			loadLanguage('Errors');

			header((preg_match('~HTTP/1\.[01]~i', $this->http_req->server->SERVER_PROTOCOL) ? $this->http_req->server->SERVER_PROTOCOL : 'HTTP/1.0') . ' 404 Not Found');
			header('Content-Type: text/plain; charset=UTF-8');

			// We need to die like this *before* we send any anti-caching headers as below.
			die('404 - ' . $txt['attachment_not_found']);
		}

		// If it hasn't been modified since the last time this attachment was retrieved, there's no need to display it again.
		if (!empty($this->http_req->server->HTTP_IF_MODIFIED_SINCE))
		{
			list ($modified_since) = explode(';', $this->http_req->server->HTTP_IF_MODIFIED_SINCE);
			if (strtotime($modified_since) >= filemtime($filename))
			{
				@ob_end_clean();

				// Answer the question - no, it hasn't been modified ;).
				header('HTTP/1.1 304 Not Modified');
				exit;
			}
		}

		// Check whether the ETag was sent back, and cache based on that...
		$eTag = '"' . substr($id_attach . $real_filename . filemtime($filename), 0, 64) . '"';
		if (!empty($this->http_req->server->HTTP_IF_NONE_MATCH) && strpos($this->http_req->server->HTTP_IF_NONE_MATCH, $eTag) !== false)
		{
			@ob_end_clean();

			header('HTTP/1.1 304 Not Modified');
			exit;
		}

		// Send the attachment headers.
		header('Pragma: ');
		if (!isBrowser('gecko'))
			header('Content-Transfer-Encoding: binary');
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 525600 * 60) . ' GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($filename)) . ' GMT');
		header('Accept-Ranges: bytes');
		header('Connection: close');
		header('ETag: ' . $eTag);

		// Make sure the mime type warrants an inline display.
		if (isset($this->http_req->query->image) && !empty($mime_type) && strpos($mime_type, 'image/') !== 0)
			unset($this->http_req->query->image);
		// Does this have a mime type?
		elseif (!empty($mime_type) && (isset($this->http_req->query->image) || !in_array($file_ext, array('jpg', 'gif', 'jpeg', 'x-ms-bmp', 'png', 'psd', 'tiff', 'iff'))))
			header('Content-Type: ' . strtr($mime_type, array('image/bmp' => 'image/x-ms-bmp')));
		else
		{
			header('Content-Type: ' . (isBrowser('ie') || isBrowser('opera') ? 'application/octetstream' : 'application/octet-stream'));
			if (isset($this->http_req->query->image))
				unset($this->http_req->query->image);
		}

		$disposition = !isset($this->http_req->query->image) ? 'attachment' : 'inline';

		// Different browsers like different standards...
		if (isBrowser('firefox'))
			header('Content-Disposition: ' . $disposition . '; filename*=UTF-8\'\'' . rawurlencode(preg_replace_callback('~&#(\d{3,8});~', 'fixchar__callback', $real_filename)));
		elseif (isBrowser('opera'))
			header('Content-Disposition: ' . $disposition . '; filename="' . preg_replace_callback('~&#(\d{3,8});~', 'fixchar__callback', $real_filename) . '"');
		elseif (isBrowser('ie'))
			header('Content-Disposition: ' . $disposition . '; filename="' . urlencode(preg_replace_callback('~&#(\d{3,8});~', 'fixchar__callback', $real_filename)) . '"');
		else
			header('Content-Disposition: ' . $disposition . '; filename="' . $real_filename . '"');

		// If this has an "image extension" - but isn't actually an image - then ensure it isn't cached cause of silly IE.
		if (!isset($this->http_req->query->image) && in_array($file_ext, array('gif', 'jpg', 'bmp', 'png', 'jpeg', 'tiff')))
			header('Cache-Control: no-cache');
		else
			header('Cache-Control: max-age=' . (525600 * 60) . ', private');

		if (empty($modSettings['enableCompressedOutput']) || filesize($filename) > 4194304)
			header('Content-Length: ' . filesize($filename));

		// Try to buy some time...
		@set_time_limit(600);

		// Recode line endings for text files, if enabled.
		if (!empty($modSettings['attachmentRecodeLineEndings']) && !isset($this->http_req->query->image) && in_array($file_ext, array('txt', 'css', 'htm', 'html', 'php', 'xml')))
		{
			$req = $GLOBALS['elk']['req'];
			if (strpos($req->user_agent(), 'Windows') !== false)
				$callback = function ($buffer) {return preg_replace('~[\r]?\n~', "\r\n", $buffer);};
			elseif (strpos($req->user_agent(), 'Mac') !== false)
				$callback = function ($buffer) {return preg_replace('~[\r]?\n~', "\r", $buffer);};
			else
				$callback = function ($buffer) {return preg_replace('~[\r]?\n~', "\n", $buffer);};
		}

		// Since we don't do output compression for files this large...
		if (filesize($filename) > 4194304)
		{
			// Forcibly end any output buffering going on.
			while (ob_get_level() > 0)
				@ob_end_clean();

			$fp = fopen($filename, 'rb');
			while (!feof($fp))
			{
				if (isset($callback))
					echo $callback(fread($fp, 8192));
				else
					echo fread($fp, 8192);

				flush();
			}
			fclose($fp);
		}
		// On some of the less-bright hosts, readfile() is disabled.  It's just a faster, more byte safe, version of what's in the if.
		elseif (isset($callback) || @readfile($filename) === null)
			echo isset($callback) ? $callback(file_get_contents($filename)) : file_get_contents($filename);

		obExit(false);
	}

	/**
	 * Simplified version of action_dlattach to send out thumbnails while creating
	 * or editing a message.
	 */
	public function action_tmpattach()
	{
		global $txt, $modSettings, $user_info, $topic, $settings;

		// Make sure some attachment was requested!
		if (!isset($this->http_req->query->attach))
			$this->_errors->fatal_lang_error('no_access', false);

		// We need to do some work on attachments and avatars.

		require_once(SUBSDIR . '/Graphics.subs.php');

		try
		{
			if (empty($topic) || (string) (int) $this->http_req->query->attach !== (string) $this->http_req->query->attach)
			{
				$attach_data = getTempAttachById($this->http_req->query->attach);
				$file_ext = pathinfo($attach_data['name'], PATHINFO_EXTENSION);
				$filename = $attach_data['tmp_name'];
				$id_attach = $attach_data['attachid'];
				$real_filename = $attach_data['name'];
				$mime_type = $attach_data['type'];
			}
			else
			{
				$id_attach = $this->http_req->getQuery('attach', 'intval', -1);

				isAllowedTo('view_attachments');
				$attachment = getAttachmentFromTopic($id_attach, $topic);

				if (empty($attachment))
					$this->_errors->fatal_lang_error('no_access', false);
				list ($id_folder, $real_filename, $file_hash, $file_ext, $id_attach, $attachment_type, $mime_type, $is_approved, $id_member) = $attachment;

				// If it isn't yet approved, do they have permission to view it?
				if (!$is_approved && ($id_member == 0 || $user_info['id'] != $id_member) && ($attachment_type == 0 || $attachment_type == 3))
					isAllowedTo('approve_posts');

				$filename = getAttachmentFilename($id_attach, $file_hash, $id_folder);
			}
		}
		catch (\Exception $e)
		{
			$this->_errors->fatal_lang_error($e->getMessage(), false);
		}
		$resize = true;

		// This is done to clear any output that was made before now.
		while (ob_get_level() > 0)
			@ob_end_clean();

		if (in_array($file_ext, array(
			'c', 'cpp', 'css', 'csv', 'doc', 'docx', 'flv', 'html', 'htm', 'java', 'js', 'log', 'mp3',
			'mp4', 'mgp', 'pdf', 'php', 'ppt', 'rtf', 'sql', 'tgz', 'txt', 'wav', 'xls', 'xml', 'zip'
		)))
		{
			$mime_type = 'image/png';
			$resize = false;

			// Show the mine thumbnail if it exists or just the default
			$filename = $settings['theme_dir'] . '/images/mime_images/' . $file_ext . '.png';
			if (!file_exists($filename))
				$filename = $settings['theme_dir'] . '/images/mime_images/default.png';
		}

		ob_start();
		header('Content-Encoding: none');

		// No point in a nicer message, because this is supposed to be an attachment anyway...
		if (!file_exists($filename))
		{
			loadLanguage('Errors');

			header((preg_match('~HTTP/1\.[01]~i', $this->http_req->server->SERVER_PROTOCOL) ? $this->http_req->server->SERVER_PROTOCOL : 'HTTP/1.0') . ' 404 Not Found');
			header('Content-Type: text/plain; charset=UTF-8');

			// We need to die like this *before* we send any anti-caching headers as below.
			die('404 - ' . $txt['attachment_not_found']);
		}

		// If it hasn't been modified since the last time this attachment was retrieved, there's no need to display it again.
		if (!empty($this->http_req->server->HTTP_IF_MODIFIED_SINCE))
		{
			list ($modified_since) = explode(';', $this->http_req->server->HTTP_IF_MODIFIED_SINCE);
			if (strtotime($modified_since) >= filemtime($filename))
			{
				@ob_end_clean();

				// Answer the question - no, it hasn't been modified ;).
				header('HTTP/1.1 304 Not Modified');
				exit;
			}
		}

		// Check whether the ETag was sent back, and cache based on that...
		$eTag = '"' . substr($id_attach . $real_filename . filemtime($filename), 0, 64) . '"';
		if (!empty($this->http_req->server->HTTP_IF_NONE_MATCH) && strpos($this->http_req->server->HTTP_IF_NONE_MATCH, $eTag) !== false)
		{
			@ob_end_clean();

			header('HTTP/1.1 304 Not Modified');
			exit;
		}

		// Send the attachment headers.
		header('Pragma: ');
		if (!isBrowser('gecko'))
			header('Content-Transfer-Encoding: binary');
		header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 525600 * 60) . ' GMT');
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($filename)) . ' GMT');
		header('Accept-Ranges: bytes');
		header('Connection: close');
		header('ETag: ' . $eTag);

		header('Content-Type: ' . strtr($mime_type, array('image/bmp' => 'image/x-ms-bmp')));

		// Different browsers like different standards...
		if (isBrowser('firefox'))
			header('Content-Disposition: inline; filename*=UTF-8\'\'' . rawurlencode(preg_replace_callback('~&#(\d{3,8});~', 'fixchar__callback', $real_filename)));
		elseif (isBrowser('opera'))
			header('Content-Disposition: inline; filename="' . preg_replace_callback('~&#(\d{3,8});~', 'fixchar__callback', $real_filename) . '"');
		elseif (isBrowser('ie'))
			header('Content-Disposition: inline; filename="' . urlencode(preg_replace_callback('~&#(\d{3,8});~', 'fixchar__callback', $real_filename)) . '"');
		else
			header('Content-Disposition: inline; filename="' . $real_filename . '"');

		header('Cache-Control: max-age=' . (525600 * 60) . ', private');

		if (empty($modSettings['enableCompressedOutput']) || filesize($filename) > 4194304)
			header('Content-Length: ' . filesize($filename));

		// Try to buy some time...
		@set_time_limit(600);

		if ($resize && resizeImageFile($filename, $filename . '_thumb', 100, 100))
			$filename = $filename . '_thumb';

		if (isset($callback) || @readfile($filename) === null)
			echo isset($callback) ? $callback(file_get_contents($filename)) : file_get_contents($filename);

		obExit(false);
	}
}