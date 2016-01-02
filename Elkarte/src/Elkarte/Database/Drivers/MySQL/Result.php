<?php

namespace Elkarte\Elkarte\Database\Drivers\MySQL;

use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\ElkArte\Database\Drivers\ResultInterface;

class Result implements ResultInterface
{
	/** @var \mysqli */
	protected $conn;
	/** @var \mysqli_result */
	protected $result;

	/**
	 * Result constructor.
	 * @param DatabaseInterface $db
	 * @param \mysqli_result|bool $result
	 */
	public function __construct(DatabaseInterface $db, $result)
	{
		$this->conn = $db->connection();
		$this->result = $result;
	}

	public function fetchRow()
	{
		return $this->result->fetch_row();
	}

	public function fetchAssoc()
	{
		return $this->result->fetch_assoc();
	}

	public function numRows()
	{
		return $this->result->num_rows;
	}

	public function numAffectedRows()
	{
		return $this->conn->affected_rows;
	}

	public function numFields()
	{
		return $this->result->field_count;
	}

	public function free()
	{
		$this->result->free();
	}

	public function dataSeek($offset)
	{
		return $this->result->data_seek($offset);
	}

	public function insertId($table, $field)
	{
		return $this->conn->insert_id;
	}
}