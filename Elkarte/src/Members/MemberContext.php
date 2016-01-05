<?php

namespace Elkarte\Members;

use Elkarte\Elkarte\Theme\AbstractContext;
use Elkarte\Elkarte\Theme\ContextInterface;

class MemberContext extends AbstractContext implements ContextInterface
{
	protected function setDefault()
	{
		return [

		];
	}

	public function supports($object)
	{
		return $object instanceof Member;
	}
}