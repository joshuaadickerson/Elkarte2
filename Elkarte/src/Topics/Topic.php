<?php

namespace Elkarte\Topics;

use Elkarte\Elkarte\Entity;

class Topic extends Entity
{
	public function getDefault()
	{
		return [
			'messages' => [],
		];
	}

	public function addMessage(Message $message)
	{
		$this->pushToArray('messages', $message, $message->id);
		return $this;
	}

	/**
	 * Reorder the messages
	 *
	 * @param array $order
	 * @return array the $messages which aren't already set
	 */
	public function reorderMessages(array $order)
	{
		$messages = $this->offsetGet('messages');

		$new_order = [];
		$not_found = [];
		foreach ($order as $id)
		{
			if (!isset($messages[$id]))
			{
				$not_found[] = $id;
			}
			else
			{
				$new_order[] = $messages[$id];
			}
		}

		$this->offsetSet('messages', $new_order);

		return $not_found;
	}
}