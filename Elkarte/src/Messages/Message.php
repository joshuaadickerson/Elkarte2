<?php

namespace Elkarte\Messages;

class Message extends \ArrayObject
{
	public function __construct($input, $flags = 0, $iterator_class = 'ArrayIterator')
	{
		$this->exchangeArray([
			'moderators' => array(),
			'unapproved_user_topics' => 0,
		]);

		parent::__construct($input, $flags & \ArrayObject::ARRAY_AS_PROPS, $iterator_class);
	}
}