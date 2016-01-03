<?php

namespace Elkarte\Boards;

class Board extends \ArrayObject
{
	public function __construct($input, $flags = 0, $iterator_class = 'ArrayIterator')
	{
		$this->exchangeArray([
			'moderators' => array(),
			'unapproved_user_topics' => 0,
		]);

		parent::__construct($input, $flags & \ArrayObject::ARRAY_AS_PROPS, $iterator_class);
	}

	public function parentBoards()
	{
		if (!$this->offsetExists('parent_boards'))
		{
			$this->offsetSet('parent_boards', $GLOBALS['elk']['boards.manager']->getBoardParents($this->offsetGet('id')));
		}

		return $this->offsetGet('parent_boards');
	}

	public function addModerator($id, $name)
	{
		global $scripturl;

		$this->moderators[$id] = array(
			'id' => $id,
			'name' => $name,
			'href' => $scripturl . '?action=profile;u=' . $id,
			'link' => '<a href="' . $scripturl . '?action=profile;u=' . $id . '">' . $name . '</a>'
		);
	}

	public function isModerator($id)
	{
		return isset($board_info['moderators'][$id]);
	}
}