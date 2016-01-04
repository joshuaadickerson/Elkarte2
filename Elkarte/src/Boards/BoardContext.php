<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Theme\AbstractContext;
use Elkarte\Elkarte\Theme\ContextInterface;

class BoardContext extends AbstractContext implements ContextInterface
{
	public function supports($object)
	{
		return $object instanceof Board;
	}
}