<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Entity;
use Elkarte\Members\Member;

class Board extends Entity
{
	public function getDefault()
	{
		return [
			'children' => [],
			'moderators' => [],
			'unapproved_user_topics' => 0,
			'children_new' => false,
			'link_moderators' => array(),
			'link_children' => array(),
		];
	}

	public function parentBoards(BoardsManager $manager)
	{
		if (!$this->offsetExists('parent_boards'))
		{
			$this->offsetSet('parent_boards', $manager->getBoardParents($this->offsetGet('id')));
		}

		return $this->offsetGet('parent_boards');
	}

	//public function addModerator($id, $name)
	public function addModerator(Member $member)
	{
		global $scripturl;

		$this->moderators[$member->id] = $member;
		/*array(
			'id' => $id,
			'name' => $name,
			//'href' => $scripturl . '?action=profile;u=' . $id,
			//'link' => '<a href="' . $scripturl . '?action=profile;u=' . $id . '">' . $name . '</a>'
		);*/
	}

	public function isModerator($id)
	{
		return isset($this->moderators[$id]);
	}

	public function isParent()
	{

	}

	public function hasChildren()
	{

	}
}