<?php

namespace Elkarte\Boards;

class BoardsContainer
{
	/** @var Board the current board */
	protected $current;
	/** @var Board[] */
	protected $boards = [];
	/** @var Category[] */
	protected $categories = [];

	public function boardExists($id)
	{
		return isset($this->boards[$id]);
	}

	public function categoryExists($id)
	{
		return isset($this->categories[$id]);
	}

	public function board($id, Board $board = null)
	{
		if (!isset($this->boards[$id]))
		{
			return $board === null ? new Board : $board;
		}

		return $board === null ? $this->boards[$id] : $this->boards[$id]->merge($board);
	}

	public function category($id, Category $category = null)
	{
		if (!isset($this->categories[$id]))
		{
			return $category === null ? new Category : $category;
		}

		return $category === null ? $this->categories[$id] : $this->categories[$id]->merge($category);
	}

	public function boards(array $ids, array $boards = array())
	{
		$return = [];
		foreach ($ids as $id)
		{
			$return[$id] = $this->board($id, isset($boards[$id]) ? $boards[$id] : null);
		}

		return $return;
	}

	public function categories(array $ids, array $categories = array())
	{
		$return = [];
		foreach ($ids as $id)
		{
			$return[$id] = $this->category($id, isset($categories[$id]) ? $categories[$id] : null);
		}

		return $return;
	}

	public function getCurrentBoard()
	{
		return $this->current;
	}

	public function setCurrentBoard(Board $board)
	{
		$this->current = $board;
	}
}