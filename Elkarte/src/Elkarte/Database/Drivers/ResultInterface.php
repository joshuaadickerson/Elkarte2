<?php

namespace Elkarte\ElkArte\Database\Drivers;

interface ResultInterface
{
	public function fetchRow();

	public function fetchAssoc();

	public function numRows();

	public function numAffectedRows();

	public function numFields();

	public function free();

	public function dataSeek($offset);

	public function insertId($table, $field);
}