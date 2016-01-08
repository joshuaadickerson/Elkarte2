<?php

namespace Elkarte\Attachments;

use Elkarte\Elkarte\Entity;
use Elkarte\Members\Member;

class Attachment extends Entity
{
	public function getDefault()
	{
		return [
			'id' => 0,
		];
	}

	/** @return string */
	public function hash()
	{
		if (!$this->offsetExists('filename'))
		{
			return '';
		}

		// @todo maybe we should increase this size?
		$hash = hash('sha1', $this->offsetGet('filename') . time() . mt_rand());
		$this->offsetSet('hash', $hash);
		$this->offsetSet('new_hash', true);

		return $hash;
	}

	public function canCompress()
	{
		return @filesize($this->filename) <= 4194304 && in_array($this->fileext, array('txt', 'html', 'htm', 'js', 'doc', 'docx', 'rtf', 'css', 'php', 'log', 'xml', 'sql', 'c', 'java'));
	}
}