<?php

/**
 * Creates and outputs the Sphinx configuration file
 *
 * @package Search
 */
function createSphinxConfig()
{
	global $db_server, $db_name, $db_user, $db_passwd, $db_prefix, $modSettings;

	// Set up to output a file to the users browser
	while (ob_get_level() > 0)
		@ob_end_clean();

	header('Content-Encoding: none');
	header('Pragma: ');
	if (!isBrowser('is_gecko'))
		header('Content-Transfer-Encoding: binary');
	header('Connection: close');
	header('Content-Disposition: attachment; filename="sphinx.conf"');
	header('Content-Type: application/octet-stream');

	$weight_factors = array(
		'age',
		'length',
		'first_message',
		'sticky',
		'likes',
	);

	$weight = array();
	$weight_total = 0;
	foreach ($weight_factors as $weight_factor)
	{
		$weight[$weight_factor] = empty($modSettings['search_weight_' . $weight_factor]) ? 0 : (int) $modSettings['search_weight_' . $weight_factor];
		$weight_total += $weight[$weight_factor];
	}

	// Weightless, then use defaults
	if ($weight_total === 0)
	{
		$weight = array(
			'age' => 25,
			'length' => 25,
			'first_message' => 25,
			'sticky' => 15,
			'likes' => 10
		);
		$weight_total = 100;
	}

	// Check paths are set, if not use some defaults
	$modSettings['sphinx_data_path'] = empty($modSettings['sphinx_data_path']) ? '/var/sphinx/data' : $modSettings['sphinx_data_path'];
	$modSettings['sphinx_log_path'] = empty($modSettings['sphinx_log_path']) ? '/var/sphinx/log' : $modSettings['sphinx_log_path'];

	// Output our minimal configuration file to get them started
	echo '#
# Sphinx configuration file (sphinx.conf), configured for ElkArte
#
# This is the minimum needed clean, simple, functional
#
# By default the location of this file would probably be:
# /usr/local/etc/sphinx.conf or /etc/sphinxsearch/sphinx.conf
#

source elkarte_source
{
	type				= mysql
	sql_host			= ', $db_server, '
	sql_user			= ', $db_user, '
	sql_pass			= ', $db_passwd, '
	sql_db				= ', $db_name, '
	sql_port			= 3306
	sql_query_pre		= SET NAMES utf8
	sql_query_pre		= SET SESSION query_cache_type=OFF
	sql_query_pre		= \
		REPLACE INTO ', $db_prefix, 'settings (variable, value) \
		SELECT \'sphinx_indexed_msg_until\', MAX(id_msg) \
		FROM ', $db_prefix, 'messages
	sql_query_range		= \
		SELECT 1, value \
		FROM ', $db_prefix, 'settings \
		WHERE variable	= \'sphinx_indexed_msg_until\'
	sql_range_step		= 1000
	sql_query			= \
		SELECT \
			m.id_msg, m.id_topic, m.id_board, IF(m.id_member = 0, 4294967295, m.id_member) AS id_member, m.poster_time, m.body, m.subject, \
			t.num_replies + 1 AS num_replies, CEILING(1000000 * ( \
				IF(m.id_msg < 0.7 * s.value, 0, (m.id_msg - 0.7 * s.value) / (0.3 * s.value)) * ' . $weight['age'] . ' + \
				IF(t.num_replies < 50, t.num_replies / 50, 1) * ' . $weight['length'] . ' + \
				IF(m.id_msg = t.id_first_msg, 1, 0) * ' . $weight['first_message'] . ' + \
				IF(t.num_likes < 10, t.num_likes / 10, 1) * ' . $weight['likes'] . ' + \
				IF(t.is_sticky = 0, 0, 100) * ' . $weight['sticky'] . ' \
			) / ' . $weight_total . ') AS relevance \
		FROM ', $db_prefix, 'messages AS m, ', $db_prefix, 'topics AS t, ', $db_prefix, 'settings AS s \
		WHERE t.id_topic = m.id_topic \
			AND s.variable = \'maxMsgID\' \
			AND m.id_msg BETWEEN $start AND $end
	sql_attr_uint		= id_topic
	sql_attr_uint		= id_board
	sql_attr_uint		= id_member
	sql_attr_timestamp	= poster_time
	sql_attr_uint		= relevance
	sql_attr_uint		= num_replies
}

source elkarte_delta_source : elkarte_source
{
	sql_query_pre = SET NAMES utf8
	sql_query_pre = SET SESSION query_cache_type=OFF
	sql_query_range	= \
		SELECT s1.value, s2.value \
		FROM ', $db_prefix, 'settings AS s1, ', $db_prefix, 'settings AS s2 \
		WHERE s1.variable = \'sphinx_indexed_msg_until\' \
			AND s2.variable = \'maxMsgID\'
}

index elkarte_base_index
{
	html_strip		= 1
	source			= elkarte_source
	path			= ', $modSettings['sphinx_data_path'], '/elkarte_sphinx_base.index', empty($modSettings['sphinx_stopword_path']) ? '' : '
	stopwords		= ' . $modSettings['sphinx_stopword_path'], '
	min_word_len	= 2
	charset_type	= utf-8
	charset_table	= 0..9, A..Z->a..z, _, a..z, U+451->U+435, U+401->U+435, U+410..U+42F->U+430..U+44F, U+430..U+44F
	ignore_chars	= -, U+AD
}

index elkarte_delta_index : elkarte_base_index
{
	source			= elkarte_delta_source
	path			= ', $modSettings['sphinx_data_path'], '/elkarte_sphinx_delta.index
}

index elkarte_index
{
	type			= distributed
	local			= elkarte_base_index
	local			= elkarte_delta_index
}

indexer
{
	mem_limit		= ', (empty($modSettings['sphinx_indexer_mem']) ? 128 : (int) $modSettings['sphinx_indexer_mem']), 'M
}

searchd
{
	listen					= ', (empty($modSettings['sphinx_searchd_port']) ? 9312 : (int) $modSettings['sphinx_searchd_port']), '
	listen					= ', (empty($modSettings['sphinxql_searchd_port']) ? 9306 : (int) $modSettings['sphinxql_searchd_port']), ':mysql41
	log						= ', $modSettings['sphinx_log_path'], '/searchd.log
	query_log				= ', $modSettings['sphinx_log_path'], '/query.log
	read_timeout			= 5
	max_children			= 30
	pid_file				= ', $modSettings['sphinx_data_path'], '/searchd.pid
	max_matches				= ', (empty($modSettings['sphinx_max_results']) ? 2000 : (int) $modSettings['sphinx_max_results']), '
}
';
	obExit(false, false);
}