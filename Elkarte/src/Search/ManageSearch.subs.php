<?php

/**
 * Support functions for setting up the search features and creating search index's
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */


/**
 * Checks if the message table already has a fulltext index created and returns the key name
 * Determines if a db is capable of creating a fulltext index
 *
 * @package Search
 */
function detectFulltextIndex()
{
	global $context, $db_prefix;

	$db = $GLOBALS['elk']['db'];

	$request = $db->query('', '
		SHOW INDEX
		FROM {db_prefix}messages',
		array(
		)
	);
	$context['fulltext_index'] = '';
	if ($request !== false || $request->numRows() != 0)
	{
		while ($row = $request->fetchAssoc())
			if ($row['Column_name'] == 'body' && (isset($row['Index_type']) && $row['Index_type'] == 'FULLTEXT' || isset($row['Comment']) && $row['Comment'] == 'FULLTEXT'))
				$context['fulltext_index'][] = $row['Key_name'];
		$request->free();

		if (is_array($context['fulltext_index']))
			$context['fulltext_index'] = array_unique($context['fulltext_index']);
	}

	if (preg_match('~^`(.+?)`\.(.+?)$~', $db_prefix, $match) !== 0)
		$request = $db->query('', '
			SHOW TABLE STATUS
			FROM {string:database_name}
			LIKE {string:table_name}',
			array(
				'database_name' => '`' . strtr($match[1], array('`' => '')) . '`',
				'table_name' => str_replace('_', '\_', $match[2]) . 'messages',
			)
		);
	else
		$request = $db->query('', '
			SHOW TABLE STATUS
			LIKE {string:table_name}',
			array(
				'table_name' => str_replace('_', '\_', $db_prefix) . 'messages',
			)
		);

	if ($request !== false)
	{
		while ($row = $request->fetchAssoc())
			if ((isset($row['Type']) && strtolower($row['Type']) != 'myisam') || (isset($row['Engine']) && strtolower($row['Engine']) != 'myisam'))
				$context['cannot_create_fulltext'] = true;

		$request->free();
	}
}


/**
 * Drop one or more indexes from a table and adds them back if specified
 *
 * @package Search
 * @param string $table
 * @param string[]|string $indexes
 * @param boolean $add
 */
function alterFullTextIndex($table, $indexes, $add = false)
{
	$db = $GLOBALS['elk']['db'];

	$indexes = is_array($indexes) ? $indexes : array($indexes);

	// Make sure it's gone before creating it.
	$db->query('', '
		ALTER TABLE ' . $table . '
		DROP INDEX ' . implode(',
		DROP INDEX ', $indexes),
		array(
			'db_error_skip' => true,
		)
	);

	if ($add)
	{
		foreach ($indexes as $index)
			$db->query('', '
				ALTER TABLE ' . $table . '
				ADD FULLTEXT {raw:name} ({raw:name})',
				array(
					'name' => $index
				)
			);
	}
}

/**
 * Creates a custom search index
 *
 * @package Search
 * @param int $start
 * @param int $messages_per_batch
 * @param string $column_size_definition
 * @param mixed[] $index_settings array containing specifics of what to create e.g. bytes per word
 */
function createSearchIndex($start, $messages_per_batch, $column_size_definition, $index_settings)
{
	global $modSettings;

	$db = $GLOBALS['elk']['db'];
	$db_search = db_search();
	$step = 1;

	// Starting a new index we set up for the run
	if ($start === 0)
	{
		drop_log_search_words();

		$db_search->create_word_search($column_size_definition);

		// Temporarily switch back to not using a search index.
		if (!empty($modSettings['search_index']) && $modSettings['search_index'] == 'custom')
			updateSettings(array('search_index' => ''));

		// Don't let simultaneous processes be updating the search index.
		if (!empty($modSettings['search_custom_index_config']))
			updateSettings(array('search_custom_index_config' => ''));
	}

	$num_messages = array(
		'done' => 0,
		'todo' => 0,
	);

	$request = $db->query('', '
		SELECT id_msg >= {int:starting_id} AS todo, COUNT(*) AS num_messages
		FROM {db_prefix}messages
		GROUP BY todo',
		array(
			'starting_id' => $start,
		)
	);
	while ($row = $request->fetchAssoc())
		$num_messages[empty($row['todo']) ? 'done' : 'todo'] = $row['num_messages'];

	// Done with indexing the messages, on to the next step
	if (empty($num_messages['todo']))
	{
		$step = 2;
		$percentage = 80;
		$start = 0;
	}
	// Still on step one, inserting all the indexed words.
	else
	{
		// Number of seconds before the next step.
		$stop = time() + 3;
		while (time() < $stop)
		{
			$inserts = array();
			$request = $db->query('', '
				SELECT id_msg, body
				FROM {db_prefix}messages
				WHERE id_msg BETWEEN {int:starting_id} AND {int:ending_id}
				LIMIT {int:limit}',
				array(
					'starting_id' => $start,
					'ending_id' => $start + $messages_per_batch - 1,
					'limit' => $messages_per_batch,
				)
			);
			$forced_break = false;
			$number_processed = 0;
			while ($row = $request->fetchAssoc())
			{
				// In theory it's possible for one of these to take friggin ages so add more timeout protection.
				if ($stop < time())
				{
					$forced_break = true;
					break;
				}

				$number_processed++;
				foreach (text2words($row['body'], $index_settings['bytes_per_word'], true) as $id_word)
					$inserts[] = array($id_word, $row['id_msg']);
			}
			$num_messages['done'] += $number_processed;
			$num_messages['todo'] -= $number_processed;
			$request->free();

			$start += $forced_break ? $number_processed : $messages_per_batch;

			if (!empty($inserts))
				$db->insert('ignore',
					'{db_prefix}log_search_words',
					array('id_word' => 'int', 'id_msg' => 'int'),
					$inserts,
					array('id_word', 'id_msg')
				);

			// Done then set up for the next step, set up for the next loop.
			if ($num_messages['todo'] === 0)
			{
				$step = 2;
				$start = 0;
				break;
			}
			else
				updateSettings(array('search_custom_index_resume' => serialize(array_merge($index_settings, array('resume_at' => $start)))));
		}

		// Since there are still steps to go, 80% is the maximum here.
		$percentage = round($num_messages['done'] / ($num_messages['done'] + $num_messages['todo']), 3) * 80;
	}

	return array($start, $step, $percentage);
}

/**
 * Removes common stop words from the index as they inhibit search performance
 *
 * @package Search
 * @param int $start
 * @param mixed[] $column_definition
 */
function removeCommonWordsFromIndex($start, $column_definition)
{
	global $modSettings;

	$db = $GLOBALS['elk']['db'];

	$stop_words = $start === 0 || empty($modSettings['search_stopwords']) ? array() : explode(',', $modSettings['search_stopwords']);
	$stop = time() + 3;
	$max_messages = ceil(60 * $modSettings['totalMessages'] / 100);
	$complete = false;

	while (time() < $stop)
	{
		$request = $db->query('', '
			SELECT id_word, COUNT(id_word) AS num_words
			FROM {db_prefix}log_search_words
			WHERE id_word BETWEEN {int:starting_id} AND {int:ending_id}
			GROUP BY id_word
			HAVING COUNT(id_word) > {int:minimum_messages}',
			array(
				'starting_id' => $start,
				'ending_id' => $start + $column_definition['step_size'] - 1,
				'minimum_messages' => $max_messages,
			)
		);
		while ($row = $request->fetchAssoc())
			$stop_words[] = $row['id_word'];
		$request->free();

		updateSettings(array('search_stopwords' => implode(',', $stop_words)));

		if (!empty($stop_words))
			$db->query('', '
				DELETE FROM {db_prefix}log_search_words
				WHERE id_word in ({array_int:stop_words})',
				array(
					'stop_words' => $stop_words,
				)
			);

		$start += $column_definition['step_size'];
		if ($start > $column_definition['max_size'])
		{
			$complete = true;
			break;
		}
	}

	return array($start, $complete);
}

/**
 * Drops the log search words table(s)
 *
 * @package Search
 */
function drop_log_search_words()
{
	$db_table = db_table();

	$db_table->db_drop_table('{db_prefix}log_search_words');
}