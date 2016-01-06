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

		$hash = hash('sha1', hash('md5', $this->offsetGet('filename') . time()) . mt_rand());
		$this->offsetSet('hash', $hash);
		$this->offsetSet('new_hash', true);

		return $hash;
	}
}