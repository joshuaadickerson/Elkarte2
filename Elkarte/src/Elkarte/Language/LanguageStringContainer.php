<?php

namespace Elkarte\Elkarte\Language;


class LanguageStringContainer extends \ArrayObject
{
	public function __construct($input = null, $flags = 0, $iterator_class = 'ArrayIterator')
	{
		parent::__construct($input, \ArrayObject::ARRAY_AS_PROPS | \ArrayObject::STD_PROP_LIST, $iterator_class);
	}
}