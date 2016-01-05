<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Theme\AbstractContext;
use Elkarte\Elkarte\Theme\ContextInterface;

class BoardContext extends AbstractContext implements ContextInterface
{
	protected function getDefault()
	{
		return [
			'moderators' => array(),
			'link_moderators' => array(),
			'children' => array(),
			'link_children' => array(),
			'children_new' => false,
		];
	}

	public function link()
	{
		return '<a href="' . $this->href() . '">' . $this->offsetGet('name') . '</a>';
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
		return $object instanceof Board;
	}
}