<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\View\AbstractContext;
use Elkarte\Elkarte\View\ContextInterface;

class BoardContext extends AbstractContext implements ContextInterface
{
	public function supports($object)
	{
		return $object instanceof Board;
	}
}