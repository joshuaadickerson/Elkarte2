<?php

class MenuSection
{
	public $title = '';
	public $enabled = true;
	public $permissions = [];
	public $areas = [];

	public function __construct($title)
	{

	}

	public function areas(array $areas)
	{
		foreach ($areas as $area)
		{
			$this->addArea($area);
		}

		return $this;
	}

	public function addArea(MenuArea $area)
	{
		$this->areas[] = $area;
		return $this;
	}
}