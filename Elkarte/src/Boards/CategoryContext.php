<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Theme\AbstractContext;
use Elkarte\Elkarte\Theme\ContextInterface;

class CategoryContext extends AbstractContext implements ContextInterface
{
	protected function setDefault()
	{
		return [

		];
	}

	public function supports($object)
	{
		return $object instanceof Board;
	}
}