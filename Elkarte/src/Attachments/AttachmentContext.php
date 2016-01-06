<?php

namespace Elkarte\Attachments;

use Elkarte\Elkarte\Theme\AbstractContext;
use Elkarte\Elkarte\Theme\ContextInterface;

class AttachmentContext extends AbstractContext implements ContextInterface
{
	protected function getDefault()
	{
		return [

		];
	}

	public function name()
	{
		return $this->offsetSet('name', preg_replace('~&amp;#(\\d{1,7}|x[0-9a-fA-F]{1,6});~', '&#\\1;', htmlspecialchars($this->offsetGet('filename'), ENT_COMPAT, 'UTF-8')));
	}

	public function link()
	{
		return '<a href="' . $this->href() . '">' . $this->offsetGet('name') . '</a>';
	}

	public function size()
	{
		global $txt;

		$size = (int) $this->offsetGet('filesize');

		return $this->offsetSet('size', $size < 1024000 ? round($size / 1024, 2) . ' ' . $txt['kilobyte'] : round($size / 1024 / 1024, 2) . ' ' . $txt['megabyte']);
	}

	public function byte_size()
	{
		return $this->offsetGet('filesize');
	}

	public function description()
	{
		// Hopefully someone already parsed it
		if ($this->offsetExists('raw_description'))
		{
			return $this->offsetGet('description');
		}

		$raw = $this->object->offsetGet('description');
		$parsed = $this->elk->offsetGet('boards.bbc_parser')->parseBoard($raw);

		// Cache this in the future
		$this->offsetSet('raw_description', $raw);
		$this->offsetSet('description', $parsed);

		return $parsed;
	}

	// @todo is this really necessary considering how easy url() is?
	public function href()
	{
		return $this->offsetGet('elk')->url(['board' => $this->offsetGet('id')]) . '.0';
	}

	public function supports($object)
	{
		return $object instanceof Attachment;
	}
}