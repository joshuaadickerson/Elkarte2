<?php

namespace Elkarte\Members;

class MemberContainer
{
	/** @var Member[] */
	protected $members = [];

	public function member($id, Member $member = null)
	{
		if (!isset($this->members[$id]))
		{
			return $member === null ? new Member : $member;
		}

		return $member === null ? $this->members[$id] : $this->members[$id]->merge($member);
	}

	public function members(array $ids, array $members = array())
	{
		$return = [];
		foreach ($ids as $id)
		{
			$return[$id] = $this->member($id, isset($members[$id]) ? $members[$id] : null);
		}

		return $return;
	}
}