<?php

namespace Elkarte\Boards;

class Category extends \ArrayObject
{
	public function __construct($input, $flags = 0, $iterator_class = 'ArrayIterator')
	{

		parent::__construct($input, $flags & \ArrayObject::ARRAY_AS_PROPS, $iterator_class);
	}
}