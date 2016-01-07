<?php

class TableTemplate extends AbstractTemplate
{
	protected $array;

	public function setArray(array $array)
	{
		$this->array = $array;
		return $this;
	}

	public function render(array $options = array())
	{
		
	}
}