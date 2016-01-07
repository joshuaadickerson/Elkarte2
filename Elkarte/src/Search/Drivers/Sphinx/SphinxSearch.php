<?php

/**
 * Used when an Sphinx search daemon is running and access is via the Sphinx
 * native search API (SphinxAPI)
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

namespace Elkarte\Search\API;

/**
 * SearchAPI-Sphinx.class.php, Sphinx API,
 *
 * What it does:
 * - used when a Sphinx search daemon is running
 * - Access is via the Sphinx native search API (SphinxAPI)
 * - sphinxapi.php is part of the Sphinx package, the file must be addded to SOURCEDIR
 *
 * @package Search
 */
class SphinxSearch extends SearchAPI
{
	/**
	 * What databases are supported?
	 * @var array
	 */
	protected $supported_databases = array('MySQL');

	/**
	 * Nothing to do
	 */
	public function __construct()
	{
		global $modSettings;

		// Is this database supported?
		if (!in_array(DB_TYPE, $this->supported_databases))
		{
			$this->is_supported = false;
			return;
		}

		$this->bannedWords = empty($modSettings['search_banned_words']) ? array() : explode(',', $modSettings['search_banned_words']);
	}

	/**
	 * Check whether the method can be performed by this API.
	 *
	 * @param string $methodName The search method
	 * @param mixed[]|null $query_params Parameters for the query
	 * @return bool
	 */
	public function supportsMethod($methodName, $query_params = null)
	{
		switch ($methodName)
		{
			case 'isValid':
			case 'searchSort':
			case 'setExcludedWords':
			case 'prepareIndexes':
				return true;
			break;
			case 'searchQuery':
				// Search can be performed, but not for 'subject only' query.
				return !$query_params['subject_only'];
			default:
				// All other methods, too bad dunno you.
				return false;
		}
	}

	/**
	 * If the settings don't exist we can't continue.
	 */
	public function isValid()
	{
		global $modSettings;

		return !(empty($modSettings['sphinx_searchd_server']) || empty($modSettings['sphinx_searchd_port']));
	}

	/**
	 * Do we have to do some work with the words we are searching for to prepare them?
	 *
	 * @param string Word(s) to index
	 * @param mixed[] $wordsSearch The Search words
	 * @param string[] $wordsExclude Words to exclude
	 * @param boolean $isExcluded
	 */
	public function prepareIndexes($word, array &$wordsSearch, array &$wordsExclude, $isExcluded)
	{
		$subwords = text2words($word, null, false);

		$fulltextWord = count($subwords) === 1 ? $word : '"' . $word . '"';
		$wordsSearch['indexed_words'][] = $fulltextWord;
		if ($isExcluded)
			$wordsExclude[] = $fulltextWord;
	}

	/**
	 * This has it's own custom search.
	 *
	 * @param mixed[] $search_params
	 * @param mixed[] $search_words
	 * @param string[] $excluded_words
	 * @param int[] $participants
	 * @param string[] $search_results
	 */
	public function searchQuery($search_params, $search_words, $excluded_words, &$participants, &$search_results)
	{
		global $user_info, $context, $modSettings;

		// Only request the results if they haven't been cached yet.
		if (!$GLOBALS['elk']['cache']->getVar($cached_results, 'search_results_' . md5($user_info['query_see_board'] . '_' . $context['params'])))
		{
			// The API communicating with the search daemon.
			require_once('sphinxapi.php');

			// Create an instance of the sphinx client and set a few options.
			$mySphinx = new \SphinxClient();
			$mySphinx->SetServer($modSettings['sphinx_searchd_server'], (int) $modSettings['sphinx_searchd_port']);
			$mySphinx->SetLimits(0, (int) $modSettings['sphinx_max_results'], (int) $modSettings['sphinx_max_results']);

			// Put together a sort string; besides the main column sort (relevance, id_topic, or num_replies),
			$search_params['sort_dir'] = strtoupper($search_params['sort_dir']);
			$sphinx_sort = $search_params['sort'] === 'id_msg' ? 'id_topic' : $search_params['sort'];

			// Add secondary sorting based on relevance value (if not the main sort method) and age
			$sphinx_sort .= ' ' . $search_params['sort_dir'] . ($search_params['sort'] === 'relevance' ? '' : ', relevance DESC') . ', poster_time DESC';

			// Include the engines weight values in the group sort
			$sphinx_sort = str_replace('relevance ', '@weight ' . $search_params['sort_dir'] . ', relevance ', $sphinx_sort);

			// Grouping by topic id makes it return only one result per topic, so don't set that for in-topic searches
			if (empty($search_params['topic']))
				$mySphinx->SetGroupBy('id_topic', SPH_GROUPBY_ATTR, $sphinx_sort);

			// Set up the sort expression
			$mySphinx->SetSortMode(SPH_SORT_EXPR, '(@weight + (relevance / 1000))');

			// Update the field weights for subject vs body
			$mySphinx->SetFieldWeights(array('subject' => !empty($modSettings['search_weight_subject']) ? $modSettings['search_weight_subject'] * 200 : 1000, 'body' => 1000));

			// Set the limits based on the search parameters.
			if (!empty($search_params['min_msg_id']) || !empty($search_params['max_msg_id']))
				$mySphinx->SetIDRange($search_params['min_msg_id'], empty($search_params['max_msg_id']) ? (int) $modSettings['maxMsgID'] : $search_params['max_msg_id']);

			if (!empty($search_params['topic']))
				$mySphinx->SetFilter('id_topic', array((int) $search_params['topic']));

			if (!empty($search_params['brd']))
				$mySphinx->SetFilter('id_board', $search_params['brd']);

			if (!empty($search_params['memberlist']))
				$mySphinx->SetFilter('id_member', $search_params['memberlist']);

			// Construct the (binary mode & |) query while accounting for excluded words
			$orResults = array();
			$inc_words = array();
			foreach ($search_words as $orIndex => $words)
			{
				$inc_words = array_merge($inc_words, $words['indexed_words']);
				$andResult = '';
				foreach ($words['indexed_words'] as $sphinxWord)
					$andResult .= (in_array($sphinxWord, $excluded_words) ? '-' : '') . $this->_cleanWordSphinx($sphinxWord, $mySphinx) . ' & ';
				$orResults[] = substr($andResult, 0, -3);
			}

			// If no search terms are left after comparing against excluded words (i.e. "test -test" or "test last -test -last"),
			// sending that to Sphinx would result in a fatal error
			if (!count(array_diff($inc_words, $excluded_words)))
				// Instead, fail gracefully (return "no results")
				return 0;

			$query = count($orResults) === 1 ? $orResults[0] : '(' . implode(') | (', $orResults) . ')';

			// Subject only searches need to be specified.
			if ($search_params['subject_only'])
				$query = '@(subject) ' . $query;

			// Choose an appropriate matching mode
			$mode = SPH_MATCH_ALL;

			// Over two words and searching for any (since we build a binary string, this will never get set)
			if (substr_count($query, ' ') > 1 && (!empty($search_params['searchtype']) && $search_params['searchtype'] == 2))
				   $mode = SPH_MATCH_ANY;
			// Binary search?
			if (preg_match('~[\|\(\)\^\$\?"\/=-]~', $query))
				$mode = SPH_MATCH_EXTENDED;

			// Set the matching mode
			$mySphinx->SetMatchMode($mode);

			// Execute the search query.
			$request = $mySphinx->Query($query, 'elkarte_index');

			// Can a connection to the daemon be made?
			if ($request === false)
			{
				// Just log the error.
				if ($mySphinx->GetLastError())
					$GLOBALS['elk']['errors']->log_error($mySphinx->GetLastError());

				$GLOBALS['elk']['errors']->fatal_lang_error('error_no_search_daemon');
			}

			// Get the relevant information from the search results.
			$cached_results = array(
				'matches' => array(),
				'num_results' => $request['total'],
			);

			if (isset($request['matches']))
			{
				foreach ($request['matches'] as $msgID => $match)
					$cached_results['matches'][$msgID] = array(
						'id' => $match['attrs']['id_topic'],
						'relevance' => round($match['attrs']['@count'] + $match['attrs']['relevance'] / 10000, 1) . '%',
						'num_matches' => empty($search_params['topic']) ? $match['attrs']['@count'] : 0,
						'matches' => array(),
					);
			}

			// Store the search results in the cache.
			$GLOBALS['elk']['cache']->put('search_results_' . md5($user_info['query_see_board'] . '_' . $context['params']), $cached_results, 600);
		}

		$participants = array();
		foreach (array_slice(array_keys($cached_results['matches']), $_REQUEST['start'], $modSettings['search_results_per_page']) as $msgID)
		{
			$context['topics'][$msgID] = $cached_results['matches'][$msgID];
			$participants[$cached_results['matches'][$msgID]['id']] = false;
		}

		// Sentences need to be broken up in words for proper highlighting.
		$search_results = array();
		foreach ($search_words as $orIndex => $words)
			$search_results = array_merge($search_results, $search_words[$orIndex]['subject_words']);

		return $cached_results['num_results'];
	}

	/**
	 * Clean up a search word/phrase/term for Sphinx.
	 *
	 * @param string $sphinx_term
	 * @param SphinxClient $sphinx_client
	 */
	protected function _cleanWordSphinx($sphinx_term, $sphinx_client)
	{
		// Multiple quotation marks in a row can cause fatal errors, so handle them
		$sphinx_term = preg_replace('/""+/', '"', $sphinx_term);

		// Unmatched (i.e. odd number of) quotation marks also cause fatal errors, so handle them
		if (substr_count($sphinx_term, '"') % 2)

		// Using preg_replace since it supports limiting the number of replacements
		$sphinx_term = preg_replace('/"/', '', $sphinx_term, 1);

		// Use the Sphinx API's built-in EscapeString function to escape special characters
		$sphinx_term = $sphinx_client->EscapeString($sphinx_term);

		// Since it escapes quotation marks and we don't want that, unescape them
		$sphinx_term = str_replace('\"', '"', $sphinx_term);

		return $sphinx_term;
	}
}