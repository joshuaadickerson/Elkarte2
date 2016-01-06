<?php

namespace Elkarte\Boards;

use Elkarte\Elkarte\Entity;

class Category extends Entity
{
	public function getDefault()
	{
		return [
			'can_collapse' => false,
			'new' => false,
			'show_unread' => true,
			'boards' => [],
		];
	}

	public function addBoard(Board $board)
	{
		$this->pushToArray('boards', $board, $board->id);
		return $this;
	}

	/**
	 * Reorder the boards
	 *
	 * @param array $order
	 * @return array the $boards which aren't already set
	 */
	public function reorderBoards(array $order)
	{
		$boards = $this->offsetGet('boards');

		$new_order = [];
		$not_found = [];
		foreach ($order as $id)
		{
			if (!isset($boards[$id]))
			{
				$not_found[] = $id;
			}
			else
			{
				$new_order[] = $boards[$id];
			}
		}

		$this->offsetSet('boards', $new_order);

		return $not_found;
	}
}