<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Theme\AbstractContext;
use Elkarte\Elkarte\Theme\ContextInterface;

class BoardContext extends AbstractContext implements ContextInterface
{
	protected function setDefault()
	{
		return [
			'moderators' => array(),
			'link_moderators' => array(),
			'children' => array(),
			'link_children' => array(),
			'children_new' => false,
		];
	}

	public function supports($object)
	{
		return $object instanceof Board;
	}
}