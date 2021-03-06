<?php

/**
 * Utility class for search functionality.
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
 * @version 1.1 dev Release Candidate 1
 *
 */

namespace Elkarte\Search;

use Elkarte\Database\Drivers\SearchInterface;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Text\StringUtil;

/**
 * Actually do the searches
 */
class Search
{
	/**
	 * $search_params will carry all settings that differ from the default search parameters.
	 *
	 * That way, the URLs involved in a search page will be kept as short as possible.
	 */
	protected $search_params = array();

	/**
	 * Holds the words and phrases to be searched on
	 * @var array
	 */
	protected $searchArray = array();

	/**
	 * Holds instance of the search api in use such as ElkArte\Search\API\StandardSearch
	 * @var null|object
	 */
	protected $searchAPI;

	/**
	 * Database instance
	 * @var DatabaseInterface
	 */
	protected $db;

	/**
	 * Search db instance
	 * @var SearchInterface
	 */
	protected $db_search;

	/**
	 * Holds words that will not be search on to inform the user they were skipped
	 * @var array
	 */
	protected $ignored = array();

	/**
	 * Searching for posts from a specific user(s)
	 * @var array
	 */
	protected $memberlist = array();

	/**
	 * Message "age" via ID, given bounds, needed to calculate relevance
	 * @var int
	 */
	protected $recentMsg = 0;

	/**
	 * The minimum message id we will search, needed to calculate relevance
	 * @var int
	 */
	protected $minMsgID = 0;

	/**
	 * Needed to calculate relevance
	 * @var int
	 */
	protected $minMsg = 0;

	/**
	 * The maximum message ID we will search, needed to calculate relevance
	 * @var int
	 */
	protected $maxMsgID = 0;

	/**
	 * If we are performing a boolean or simple search
	 * @var bool
	 */
	protected $no_regexp = false;

	/**
	 * Builds the array of words for use in the db query
	 * @var null|array
	 */
	protected $searchWords;

	/**
	 * Words excluded from indexes
	 * @var array
	 */
	protected $excludedIndexWords = array();

	/**
	 * Words not be be found in the search results (-word)
	 * @var array
	 */
	protected $excludedWords = array();

	/**
	 * Words not be be found in the subject (-word)
	 * @var array
	 */
	protected $excludedSubjectWords = array();

	/**
	 * Phrases not to be found in the search results (-"some phrase")
	 * @var array
	 */
	protected $excludedPhrases = array();

	/**
	 * If search words were found on the blacklist
	 * @var bool
	 */
	protected $foundBlackListedWords = false;

	/**
	 * Words we do not search due to length or common terms
	 * @var array
	 */
	protected $blacklisted_words = ['img', 'url', 'quote', 'www', 'http', 'the', 'is', 'it', 'are', 'if'];

	/**
	 * The db query for brd's
	 * @var string
	 */
	protected $boardQuery = '';

	/**
	 * the db query for members
	 * @var string
	 */
	protected $userQuery = '';

	/**
	 * The weights to associate to various areas for relevancy
	 * @var array
	 */
	protected $weight_factors = array();

	/**
	 * Weighing factor each area, ie frequency, age, sticky, etc
	 * @var array
	 */
	protected $weight = array();

	/**
	 * The sum of the _weight_factors, normally but not always 100
	 * @var int
	 */
	protected $weight_total = 0;

	/**
	 * If we are creating a tmp db table
	 * @var bool
	 */
	protected $createTemporary = true;

	/** @var  StringUtil */
	protected $text;

	/**
	 * Constructor
	 * Easy enough, initialize the database objects (generic db and search db)
	 *
	 * @package Search
	 */
	public function _construct()
	{
		$this->db = $GLOBALS['elk']['db'];
		$this->db_search = db_search();
		$this->text = $GLOBALS['elk']['text'];

		// Remove any temporary search tables that may exist
		$this->db_search->query('drop_tmp_log_search_messages', '
			DROP TABLE IF EXISTS {db_prefix}tmp_log_search_messages',
			array(
				'db_error_skip' => true,
			)
		);

		$this->db_search->query('drop_tmp_log_search_topics', '
			DROP TABLE IF EXISTS {db_prefix}tmp_log_search_topics',
			array(
				'db_error_skip' => true,
			)
		);

		// Create new temporary table(s) (if we can) to store preliminary results in.
		$this->createTemporary = $this->db_search->query('create_tmp_log_search_messages', '
			CREATE TEMPORARY TABLE {db_prefix}tmp_log_search_messages (
				id_msg int(10) unsigned NOT NULL default {string:string_zero},
				PRIMARY KEY (id_msg)
			) ENGINE=MEMORY',
			array(
				'string_zero' => '0',
				'db_error_skip' => true,
			)
		) !== false;

		$this->db_search->query('create_tmp_log_search_topics', '
			CREATE TEMPORARY TABLE {db_prefix}tmp_log_search_topics (
				id_topic mediumint(8) unsigned NOT NULL default {string:string_zero},
				PRIMARY KEY (id_topic)
			) ENGINE=MEMORY',
			array(
				'string_zero' => '0',
				'db_error_skip' => true,
			)
		);
	}

	/**
	 * Creates a search API and returns the object.
	 */
	public function findSearchAPI()
	{
		global $modSettings, $search_versions, $txt;

		// Load up the search API we are going to use.
		if (empty($modSettings['search_index']))
			$modSettings['search_index'] = 'StandardSearch';
		elseif (in_array($modSettings['search_index'], array('custom', 'fulltext')))
			$modSettings['search_index'] = ucfirst($modSettings['search_index']) . 'Search';

		$search_class_name = '\\Elkarte\\Search\\Drivers\\API\\' . $modSettings['search_index'];

		if (!class_exists($search_class_name) || !class_implements($search_class_name, 'SearchInterface'))
		{
			$GLOBALS['elk']['errors']->fatal_lang_error('search_api_missing');
		}

		// Create an instance of the search API and check it is valid for this version of the software.
		// @todo needs a try/catch or this will fail miserably
		$this->searchAPI = new $search_class_name();

		// An invalid Search API? Log the error and set it to use the standard API
		if (!$this->searchAPI)
		{
			// Log the error.
			loadLanguage('Errors');
			$GLOBALS['elk']['errors']->log_error(sprintf($txt['search_api_not_compatible'], $search_class_name), 'critical');

			$this->searchAPI = new \Elkarte\Search\Drivers\API\StandardSearch();
		}

		return $this->searchAPI;
	}

	/**
	 * Returns a search parameter.
	 *
	 * @param string $name - name of the search parameters
	 *
	 * @return bool|mixed - the value of the parameter
	 */
	public function param($name)
	{
		if (isset($this->search_params[$name]))
		{
			return $this->search_params[$name];
		}
		else
		{
			return false;
		}
	}

	/**
	 * Returns all the search parameters.
	 *
	 * @return mixed[]
	 */
	public function getParams()
	{
		return array_merge($this->search_params, array(
			'min_msg_id' => (int) $this->minMsgID,
			'max_msg_id' => (int) $this->maxMsgID,
			'memberlist' => $this->memberlist,
		));
	}

	/**
	 * Returns the ignored words
	 */
	public function getIgnored()
	{
		return $this->ignored;
	}

	/**
	 * Returns words excluded from indexes
	 */
	public function getExcludedIndexWords()
	{
		return $this->excludedIndexWords;
	}

	/**
	 * Set the weight factors
	 *
	 * @param mixed[] $weight_factors
	 * @param mixed[] $weight - weight for each factor
	 * @param int $weight_total - som of all the weights
	 */
	public function setWeights($weight_factors, $weight, $weight_total)
	{
		$this->weight_factors = $weight_factors;

		$this->weight = $weight;

		$this->weight_total = $weight_total;
	}

	/**
	 * If the query uses regexp or not
	 *
	 * @return bool
	 */
	public function noRegexp()
	{
		return $this->no_regexp;
	}

	/**
	 * If any black-listed word has been found
	 *
	 * @return bool
	 */
	public function foundBlackListedWords()
	{
		return $this->foundBlackListedWords;
	}

	/**
	 * Builds the search array
	 *
	 * @param bool - Force splitting of strings enclosed in double quotes
	 * @return array
	 */
	public function searchArray($search_simple_fulltext = false)
	{
		// Change non-word characters into spaces.
		$stripped_query = preg_replace('~(?:[\x0B\0\x{A0}\t\r\s\n(){}\\[\\]<>!@$%^*.,:+=`\~\?/\\\\]+|&(?:amp|lt|gt|quot);)+~u', ' ', $this->param('search'));

		// Make the query lower case. It's gonna be case insensitive anyway.
		$stripped_query = $this->text->un_htmlspecialchar($this->text->strtolower($stripped_query));

		// This option will do fulltext searching in the most basic way.
		if ($search_simple_fulltext)
		{
			$stripped_query = strtr($stripped_query, array('"' => ''));
		}

		$this->no_regexp = preg_match('~&#(?:\d{1,7}|x[0-9a-fA-F]{1,6});~', $stripped_query) === 1;

		// Extract phrase parts first (e.g. some words "this is a phrase" some more words.)
		preg_match_all('/(?:^|\s)([-]?)"([^"]+)"(?:$|\s)/', $stripped_query, $matches, PREG_PATTERN_ORDER);
		$phraseArray = $matches[2];

		// Remove the phrase parts and extract the words.
		$wordArray = preg_replace('~(?:^|\s)(?:[-]?)"(?:[^"]+)"(?:$|\s)~u', ' ', $this->param('search'));
		$wordArray = explode(' ', $this->text->htmlspecialchars($this->text->un_htmlspecialchars($wordArray), ENT_QUOTES));

		// A minus sign in front of a word excludes the word.... so...
		// .. first, we check for things like -"some words", but not "-some words".
		$phraseArray = $this->checkExcludePhrase($matches[1], $phraseArray);

		// Now we look for -test, etc.... normaller.
		$wordArray = $this->checkExcludeWord($wordArray);

		// The remaining words and phrases are all included.
		$this->searchArray = array_merge($phraseArray, $wordArray);

		// Trim everything and make sure there are no words that are the same.
		foreach ($this->searchArray as $index => $value)
		{
			// Skip anything practically empty.
			if (($this->searchArray[$index] = trim($value, '-_\' ')) === '')
			{
				unset($this->searchArray[$index]);
			}
			// Skip blacklisted words. Make sure to note we skipped them in case we end up with nothing.
			elseif (in_array($this->searchArray[$index], $this->blacklisted_words))
			{
				$this->foundBlackListedWords = true;
				unset($this->searchArray[$index]);
			}
			// Don't allow very, very short words.
			elseif ($this->text->strlen($value) < 2)
			{
				$this->ignored[] = $value;
				unset($this->searchArray[$index]);
			}
		}

		$this->searchArray = array_slice(array_unique($this->searchArray), 0, 10);

		return $this->searchArray;
	}

	/**
	 * Looks for phrases that should be excluded from results
	 *
	 * - Check for things like -"some words", but not "-some words"
	 * - Prevents redundancy with blacklisted words
	 *
	 * @param string[] $matches
	 * @param string[]  $phraseArray
	 */
	protected function checkExcludePhrase($matches, $phraseArray)
	{
		foreach ($matches as $index => $word)
		{
			if ($word === '-')
			{
				if (($word = trim($phraseArray[$index], '-_\' ')) !== '' && !in_array($word, $this->blacklisted_words))
				{
					$this->excludedWords[] = $word;
				}

				unset($phraseArray[$index]);
			}
		}

		return $phraseArray;
	}

	/**
	 * Looks for words that should be excluded in the results (-word)
	 *
	 * - Look for -test, etc
	 * - Prevents excluding blacklisted words since it is redundant
	 *
	 * @param string[] $wordArray
	 * @return array
	 */
	protected function checkExcludeWord($wordArray)
	{
		foreach ($wordArray as $index => $word)
		{
			if (strpos(trim($word), '-') === 0)
			{
				if (($word = trim($word, '-_\' ')) !== '' && !in_array($word, $this->blacklisted_words))
				{
					$this->excludedWords[] = $word;
				}

				unset($wordArray[$index]);
			}
		}

		return $wordArray;
	}

	/**
	 * Builds the array of words for the query
	 */
	public function searchWords()
	{
		global $modSettings, $context;

		if ($this->searchWords !== null)
		{
			return $this->searchWords;
		}

		$orParts = array();
		$this->searchWords = array();

		// All words/sentences must match.
		if (!empty($this->searchArray) && empty($this->search_params['searchtype']))
		{
			$orParts[0] = $this->searchArray;
		}
		// Any word/sentence must match.
		else
		{
			foreach ($this->searchArray as $index => $value)
				$orParts[$index] = array($value);
		}

		// Make sure the excluded words are in all or-branches.
		foreach ($orParts as $orIndex => $andParts)
		{
			foreach ($this->excludedWords as $word)
			{
				$orParts[$orIndex][] = $word;
			}
		}

		// Determine the or-branches and the fulltext search words.
		foreach ($orParts as $orIndex => $andParts)
		{
			$this->searchWords[$orIndex] = array(
				'indexed_words' => array(),
				'words' => array(),
				'subject_words' => array(),
				'all_words' => array(),
				'complex_words' => array(),
			);

			// Sort the indexed words (large words -> small words -> excluded words).
			if ($this->searchAPI->supportsMethod('searchSort'))
			{
				$this->searchAPI->setExcludedWords($this->excludedWords);
				usort($orParts[$orIndex], array($this->searchAPI, 'searchSort'));
			}

			foreach ($orParts[$orIndex] as $word)
			{
				$is_excluded = in_array($word, $this->excludedWords);
				$this->searchWords[$orIndex]['all_words'][] = $word;
				$subjectWords = text2words($word);

				if (!$is_excluded || count($subjectWords) === 1)
				{
					$this->searchWords[$orIndex]['subject_words'] = array_merge($this->searchWords[$orIndex]['subject_words'], $subjectWords);

					if ($is_excluded)
					{
						$this->excludedSubjectWords = array_merge($this->excludedSubjectWords, $subjectWords);
					}
				}
				else
				{
					$this->excludedPhrases[] = $word;
				}

				// Have we got indexes to prepare?
				if ($this->searchAPI->supportsMethod('prepareIndexes'))
				{
					$this->searchAPI->prepareIndexes($word, $this->searchWords[$orIndex], $this->excludedIndexWords, $is_excluded);
				}
			}

			// Search_force_index requires all AND parts to have at least one fulltext word.
			if (!empty($modSettings['search_force_index']) && empty($this->searchWords[$orIndex]['indexed_words']))
			{
				$context['search_errors']['query_not_specific_enough'] = true;
				break;
			}
			elseif ($this->param('subject_only') && empty($this->searchWords[$orIndex]['subject_words']) && empty($this->excludedSubjectWords))
			{
				$context['search_errors']['query_not_specific_enough'] = true;
				break;
			}
			// Make sure we aren't searching for too many indexed words.
			else
			{
				$this->searchWords[$orIndex]['indexed_words'] = array_slice($this->searchWords[$orIndex]['indexed_words'], 0, 7);
				$this->searchWords[$orIndex]['subject_words'] = array_slice($this->searchWords[$orIndex]['subject_words'], 0, 7);
				$this->searchWords[$orIndex]['words'] = array_slice($this->searchWords[$orIndex]['words'], 0, 4);
			}
		}

		return $this->searchWords;
	}

	/**
	 * Encodes search params ($this->search_params) in an URL-compatible way
	 *
	 * @return string - the encoded string to be appended to the URL
	 */
	public function compileURLparams()
	{
		$temp_params = $this->search_params;
		$encoded = array();

		// *** Encode all search params
		// All search params have been checked, let's compile them to a single string... made less simple by PHP 4.3.9 and below.
		if (isset($temp_params['brd']))
		{
			$temp_params['brd'] = implode(',', $temp_params['brd']);
		}

		foreach ($temp_params as $k => $v)
			$encoded[] = $k . '|\'|' . $v;

		if (!empty($encoded))
		{
			// Due to old IE's 2083 character limit, we have to compress long search strings
			$params = @gzcompress(implode('|"|', $encoded));

			// Gzcompress failed, use try non-gz
			if (empty($params))
			{
				$params = implode('|"|', $encoded);
			}

			// Base64 encode, then replace +/= with uri safe ones that can be reverted
			$encoded = str_replace(array('+', '/', '='), array('-', '_', '.'), base64_encode($params));
		}
		else
		{
			$encoded = '';
		}

		return $encoded;
	}

	/**
	 * Extract search params from a string
	 *
	 * @param string $string - the string containing encoded search params
	 */
	public function searchParamsFromString($string)
	{
		// Due to IE's 2083 character limit, we have to compress long search strings
		$temp_params = base64_decode(str_replace(array('-', '_', '.'), array('+', '/', '='), $string));

		// Test for gzuncompress failing
		$temp_params2 = @gzuncompress($temp_params);
		$temp_params = explode('|"|', (!empty($temp_params2) ? $temp_params2 : $temp_params));

		foreach ($temp_params as $i => $data)
		{
			list($k, $v) = array_pad(explode('|\'|', $data), 2, '');
			$this->search_params[$k] = $v;
		}

		if (isset($this->search_params['brd']))
		{
			$this->search_params['brd'] = empty($this->search_params['brd']) ? array() : explode(',', $this->search_params['brd']);
		}
	}

	/**
	 * Merge search params extracted with Search::searchParamsFromString
	 * with those present in the $param array (usually $REQUEST['params'])
	 *
	 * @param mixed[] $params - An array of search parameters
	 * @param int $recentPercentage - A coefficient to calculate the lowest
	 *            message id to start search from
	 * @param int $maxMembersToSearch - The maximum number of members to consider
	 *            when multiple are found
	 */
	public function mergeSearchParams($params, $recentPercentage, $maxMembersToSearch)
	{
		global $user_info, $modSettings, $context;

		// Store whether simple search was used (needed if the user wants to do another query).
		if (!isset($this->search_params['advanced']))
		{
			$this->search_params['advanced'] = empty($params['advanced']) ? 0 : 1;
		}

		// 1 => 'allwords' (default, don't set as param) / 2 => 'anywords'.
		if (!empty($this->search_params['searchtype']) || (!empty($params['searchtype']) && $params['searchtype'] == 2))
		{
			$this->search_params['searchtype'] = 2;
		}

		// Minimum age of messages. Default to zero (don't set param in that case).
		if (!empty($this->search_params['minage']) || (!empty($params['minage']) && $params['minage'] > 0))
		{
			$this->search_params['minage'] = !empty($this->search_params['minage']) ? (int) $this->search_params['minage'] : (int) $params['minage'];
		}

		// Maximum age of messages. Default to infinite (9999 days: param not set).
		if (!empty($this->search_params['maxage']) || (!empty($params['maxage']) && $params['maxage'] < 9999))
		{
			$this->search_params['maxage'] = !empty($this->search_params['maxage']) ? (int) $this->search_params['maxage'] : (int) $params['maxage'];
		}

		// Searching a specific topic?
		if (!empty($params['topic']) || (!empty($params['search_selection']) && $params['search_selection'] === 'topic'))
		{
			$this->search_params['topic'] = empty($params['search_selection']) ? (int) $params['topic'] : (isset($params['sd_topic']) ? (int) $params['sd_topic'] : '');
			$this->search_params['show_complete'] = true;
		}
		elseif (!empty($this->search_params['topic']))
		{
			$this->search_params['topic'] = (int) $this->search_params['topic'];
		}

		if (!empty($this->search_params['minage']) || !empty($this->search_params['maxage']))
		{
			$request = $this->db->query('', '
				SELECT ' . (empty($this->search_params['maxage']) ? '0, ' : 'IFNULL(MIN(id_msg), -1), ') . (empty($this->search_params['minage']) ? '0' : 'IFNULL(MAX(id_msg), -1)') . '
				FROM {db_prefix}messages
				WHERE 1=1' . ($modSettings['postmod_active'] ? '
					AND approved = {int:is_approved_true}' : '') . (empty($this->search_params['minage']) ? '' : '
					AND poster_time <= {int:timestamp_minimum_age}') . (empty($this->search_params['maxage']) ? '' : '
					AND poster_time >= {int:timestamp_maximum_age}'),
				array(
					'timestamp_minimum_age' => empty($this->search_params['minage']) ? 0 : time() - 86400 * $this->search_params['minage'],
					'timestamp_maximum_age' => empty($this->search_params['maxage']) ? 0 : time() - 86400 * $this->search_params['maxage'],
					'is_approved_true' => 1,
				)
			);
			list ($this->minMsgID, $this->maxMsgID) = $request->fetchRow();
			if ($this->minMsgID < 0 || $this->maxMsgID < 0)
			{
				$context['search_errors']['no_messages_in_time_frame'] = true;
			}
			$request->free();
		}

		// Default the user name to a wildcard matching every user (*).
		if (!empty($this->search_params['userspec']) || (!empty($params['userspec']) && $params['userspec'] != '*'))
		{
			$this->search_params['userspec'] = isset($this->search_params['userspec']) ? $this->search_params['userspec'] : $params['userspec'];
		}

		// If there's no specific user, then don't mention it in the main query.
		if (empty($this->search_params['userspec']))
		{
			$this->userQuery = '';
		}
		else
		{
			$userString = strtr($this->text->htmlspecialchars($this->search_params['userspec'], ENT_QUOTES), array('&quot;' => '"'));
			$userString = strtr($userString, array('%' => '\%', '_' => '\_', '*' => '%', '?' => '_'));

			preg_match_all('~"([^"]+)"~', $userString, $matches);
			$possible_users = array_merge($matches[1], explode(',', preg_replace('~"[^"]+"~', '', $userString)));

			for ($k = 0, $n = count($possible_users); $k < $n; $k++)
			{
				$possible_users[$k] = trim($possible_users[$k]);

				if (strlen($possible_users[$k]) == 0)
				{
					unset($possible_users[$k]);
				}
			}

			// Create a list of database-escaped search names.
			$realNameMatches = array();
			foreach ($possible_users as $possible_user)
				$realNameMatches[] = $this->db->quote(
					'{string:possible_user}',
					array(
						'possible_user' => $possible_user
					)
				);

			// Retrieve a list of possible members.
			$request = $this->db->query('', '
				SELECT id_member
				FROM {db_prefix}members
				WHERE {raw:match_possible_users}',
				array(
					'match_possible_users' => 'real_name LIKE ' . implode(' OR real_name LIKE ', $realNameMatches),
				)
			);

			// Simply do nothing if there're too many members matching the criteria.
			if ($request->numRows() > $maxMembersToSearch)
			{
				$this->userQuery = '';
			}
			elseif ($request->numRows() == 0)
			{
				$this->userQuery = $this->db->quote(
					'm.id_member = {int:id_member_guest} AND ({raw:match_possible_guest_names})',
					array(
						'id_member_guest' => 0,
						'match_possible_guest_names' => 'm.poster_name LIKE ' . implode(' OR m.poster_name LIKE ', $realNameMatches),
					)
				);
			}
			else
			{
				while ($row = $request->fetchAssoc())
				{
					$this->memberlist[] = $row['id_member'];
				}

				$this->userQuery = $this->db->quote(
					'(m.id_member IN ({array_int:matched_members}) OR (m.id_member = {int:id_member_guest} AND ({raw:match_possible_guest_names})))',
					array(
						'matched_members' => $this->memberlist,
						'id_member_guest' => 0,
						'match_possible_guest_names' => 'm.poster_name LIKE ' . implode(' OR m.poster_name LIKE ', $realNameMatches),
					)
				);
			}
			$request->free();
		}

		// Ensure that boards are an array of integers (or nothing).
		if (!empty($this->search_params['brd']) && is_array($this->search_params['brd']))
		{
			$query_boards = array_map('intval', $this->search_params['brd']);
		}
		elseif (!empty($params['brd']) && is_array($params['brd']))
		{
			$query_boards = array_map('intval', $params['brd']);
		}
		elseif (!empty($params['brd']))
		{
			$query_boards = array_map('intval', explode(',', $params['brd']));
		}
		elseif (!empty($params['search_selection']) && $params['search_selection'] === 'board' && !empty($params['sd_brd']) && is_array($params['sd_brd']))
		{
			$query_boards = array_map('intval', $params['sd_brd']);
		}
		elseif (!empty($params['search_selection']) && $params['search_selection'] === 'board' && isset($params['sd_brd']) && (int) $params['sd_brd'] !== 0)
		{
			$query_boards = array((int) $params['sd_brd']);
		}
		else
		{
			$query_boards = array();
		}

		// Special case for boards: searching just one topic?
		if (!empty($this->search_params['topic']))
		{
			$request = $this->db->query('', '
				SELECT b.id_board
				FROM {db_prefix}topics AS t
					INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				WHERE t.id_topic = {int:search_topic_id}
					AND {query_see_board}' . ($modSettings['postmod_active'] ? '
					AND t.approved = {int:is_approved_true}' : '') . '
				LIMIT 1',
				array(
					'search_topic_id' => $this->search_params['topic'],
					'is_approved_true' => 1,
				)
			);

			if ($request->numRows() == 0)
			{
				$GLOBALS['elk']['errors']->fatal_lang_error('topic_gone', false);
			}

			$this->search_params['brd'] = array();
			list ($this->search_params['brd'][0]) = $request->fetchRow();
			$request->free();
		}
		// Select all boards you've selected AND are allowed to see.
		elseif ($user_info['is_admin'] && (!empty($this->search_params['advanced']) || !empty($query_boards)))
		{
			$this->search_params['brd'] = $query_boards;
		}
		else
		{

			$this->search_params['brd'] = array_keys(fetchBoardsInfo(array('boards' => $query_boards), array('include_recycle' => false, 'include_redirects' => false, 'wanna_see_board' => empty($this->search_params['advanced']))));

			// This error should pro'bly only happen for hackers.
			if (empty($this->search_params['brd']))
			{
				$context['search_errors']['no_boards_selected'] = true;
			}
		}

		if (count($this->search_params['brd']) != 0)
		{
			foreach ($this->search_params['brd'] as $k => $v)
				$this->search_params['brd'][$k] = (int) $v;

			// If we've selected all boards, this parameter can be left empty.

			$num_boards = countBoards();

			if (count($this->search_params['brd']) == $num_boards)
			{
				$this->boardQuery = '';
			}
			elseif (count($this->search_params['brd']) == $num_boards - 1 && !empty($modSettings['recycle_board']) && !in_array($modSettings['recycle_board'], $this->search_params['brd']))
			{
				$this->boardQuery = '!= ' . $modSettings['recycle_board'];
			}
			else
			{
				$this->boardQuery = 'IN (' . implode(', ', $this->search_params['brd']) . ')';
			}
		}
		else
		{
			$this->boardQuery = '';
		}

		$this->search_params['show_complete'] = !empty($this->search_params['show_complete']) || !empty($params['show_complete']);
		$this->search_params['subject_only'] = !empty($this->search_params['subject_only']) || !empty($params['subject_only']);

		// Get the sorting parameters right. Default to sort by relevance descending.
		$sort_columns = array(
			'relevance',
			'num_replies',
			'id_msg',
		);

		// Allow integration to add additional sort columns
		$GLOBALS['elk']['hooks']->hook('search_sort_columns', array(&$sort_columns));

		if (empty($this->search_params['sort']) && !empty($params['sort']))
		{
			list ($this->search_params['sort'], $this->search_params['sort_dir']) = array_pad(explode('|', $params['sort']), 2, '');
		}

		$this->search_params['sort'] = !empty($this->search_params['sort']) && in_array($this->search_params['sort'], $sort_columns) ? $this->search_params['sort'] : 'relevance';

		if (!empty($this->search_params['topic']) && $this->search_params['sort'] === 'num_replies')
		{
			$this->search_params['sort'] = 'id_msg';
		}

		// Sorting direction: descending unless stated otherwise.
		$this->search_params['sort_dir'] = !empty($this->search_params['sort_dir']) && $this->search_params['sort_dir'] === 'asc' ? 'asc' : 'desc';

		// Determine some values needed to calculate the relevance.
		$this->minMsg = (int) ((1 - $recentPercentage) * $modSettings['maxMsgID']);
		$this->recentMsg = $modSettings['maxMsgID'] - $this->minMsg;

		// *** Parse the search query
		$GLOBALS['elk']['hooks']->hook('search_params', array(&$this->search_params));

		// Unfortunately, searching for words like this is going to be slow, so we're blacklisting them.
		// @todo Setting to add more here?
		// @todo Maybe only blacklist if they are the only word, or "any" is used?
		$this->blacklisted_words = array('img', 'url', 'quote', 'www', 'http', 'the', 'is', 'it', 'are', 'if');
		$GLOBALS['elk']['hooks']->hook('search_blacklisted_words', array(&$this->blacklisted_words));

		// What are we searching for?
		if (empty($this->search_params['search']))
		{
			if (isset($GET['search']))
			{
				$this->search_params['search'] = $this->text->un_htmlspecialchar($GET['search']);
			}
			elseif (isset($POST['search']))
			{
				$this->search_params['search'] = $POST['search'];
			}
			else
			{
				$this->search_params['search'] = '';
			}
		}
	}

	/**
	 * Tell me, do I want to see the full message or just a piece?
	 */
	public function isCompact()
	{
		return empty($this->search_params['show_complete']);
	}

	/**
	 * Setup spellchecking suggestions and load them into the two variable
	 * passed by ref
	 *
	 * @param string $suggestion_display - the string to display in the template
	 * @param string $suggestion_param - a param string to be used in a url
	 * @param string $display_highlight - a template to enclose in each suggested word
	 */
	public function loadSuggestions(&$suggestion_display = '', &$suggestion_param = '', $display_highlight = '')
	{
		global $txt;

		// Windows fix.
		ob_start();
		$old = error_reporting(0);

		pspell_new('en');
		$pspell_link = pspell_new($txt['lang_dictionary'], $txt['lang_spelling'], '', 'utf-8', PSPELL_FAST | PSPELL_RUN_TOGETHER);

		if (!$pspell_link)
		{
			$pspell_link = pspell_new('en', '', '', '', PSPELL_FAST | PSPELL_RUN_TOGETHER);
		}

		error_reporting($old);
		@ob_end_clean();

		$did_you_mean = array('search' => array(), 'display' => array());
		$found_misspelling = false;
		foreach ($this->searchArray as $word)
		{
			if (empty($pspell_link))
			{
				continue;
			}

			// Don't check phrases.
			if (preg_match('~^\w+$~', $word) === 0)
			{
				$did_you_mean['search'][] = '"' . $word . '"';
				$did_you_mean['display'][] = '&quot;' . $this->text->htmlspecialchars($word) . '&quot;';
				continue;
			}
			// For some strange reason spell check can crash PHP on decimals.
			elseif (preg_match('~\d~', $word) === 1)
			{
				$did_you_mean['search'][] = $word;
				$did_you_mean['display'][] = $this->text->htmlspecialchars($word);
				continue;
			}
			elseif (pspell_check($pspell_link, $word))
			{
				$did_you_mean['search'][] = $word;
				$did_you_mean['display'][] = $this->text->htmlspecialchars($word);
				continue;
			}

			$suggestions = pspell_suggest($pspell_link, $word);
			foreach ($suggestions as $i => $s)
			{
				// Search is case insensitive.
				if ($this->text->strtolower($s) == $this->text->strtolower($word))
				{
					unset($suggestions[$i]);
				}
				// Plus, don't suggest something the user thinks is rude!
				elseif ($suggestions[$i] != censor($s))
				{
					unset($suggestions[$i]);
				}
			}

			// Anything found?  If so, correct it!
			if (!empty($suggestions))
			{
				$suggestions = array_values($suggestions);
				$did_you_mean['search'][] = $suggestions[0];
				$did_you_mean['display'][] = str_replace('{word}', $this->text->htmlspecialchars($suggestions[0]), $display_highlight);
				$found_misspelling = true;
			}
			else
			{
				$did_you_mean['search'][] = $word;
				$did_you_mean['display'][] = $this->text->htmlspecialchars($word);
			}
		}

		if ($found_misspelling)
		{
			// Don't spell check excluded words, but add them still...
			$temp_excluded = array('search' => array(), 'display' => array());
			foreach ($this->excludedWords as $word)
			{
				if (preg_match('~^\w+$~', $word) == 0)
				{
					$temp_excluded['search'][] = '-"' . $word . '"';
					$temp_excluded['display'][] = '-&quot;' . $this->text->htmlspecialchars($word) . '&quot;';
				}
				else
				{
					$temp_excluded['search'][] = '-' . $word;
					$temp_excluded['display'][] = '-' . $this->text->htmlspecialchars($word);
				}
			}

			$did_you_mean['search'] = array_merge($did_you_mean['search'], $temp_excluded['search']);
			$did_you_mean['display'] = array_merge($did_you_mean['display'], $temp_excluded['display']);

			$suggestion_param = $this->compileURLparams();
			$suggestion_display = implode(' ', $did_you_mean['display']);
		}
	}

	/**
	 * Delete logs of previous searches
	 *
	 * @param int $id_search - the id of the search to delete from logs
	 */
	public function clearCacheResults($id_search)
	{
		$this->db_search->query('delete_log_search_results', '
			DELETE FROM {db_prefix}log_search_results
			WHERE id_search = {int:search_id}',
			array(
				'search_id' => $id_search,
			)
		);
	}

	/**
	 * Grabs results when the search is performed only within the subject
	 *
	 * @param int $id_search - the id of the search
	 * @param int $humungousTopicPosts - Message length used to tweak messages
	 *            relevance of the results.
	 *
	 * @return int - number of results otherwise
	 */
	public function getSubjectResults($id_search, $humungousTopicPosts)
	{
		global $modSettings;

		// We do this to try and avoid duplicate keys on databases not supporting INSERT IGNORE.
		foreach ($this->searchWords as $words)
		{
			$subject_query_params = array();
			$subject_query = array(
				'from' => '{db_prefix}topics AS t',
				'inner_join' => array(),
				'left_join' => array(),
				'where' => array(),
			);

			if ($modSettings['postmod_active'])
			{
				$subject_query['where'][] = 't.approved = {int:is_approved}';
			}

			$numTables = 0;
			$prev_join = 0;
			$numSubjectResults = 0;
			foreach ($words['subject_words'] as $subjectWord)
			{
				$numTables++;
				if (in_array($subjectWord, $this->excludedSubjectWords))
				{
					$subject_query['left_join'][] = '{db_prefix}log_search_subjects AS subj' . $numTables . ' ON (subj' . $numTables . '.word ' . (empty($modSettings['search_match_words']) ? 'LIKE {string:subject_words_' . $numTables . '_wild}' : '= {string:subject_words_' . $numTables . '}') . ' AND subj' . $numTables . '.id_topic = t.id_topic)';
					$subject_query['where'][] = '(subj' . $numTables . '.word IS NULL)';
				}
				else
				{
					$subject_query['inner_join'][] = '{db_prefix}log_search_subjects AS subj' . $numTables . ' ON (subj' . $numTables . '.id_topic = ' . ($prev_join === 0 ? 't' : 'subj' . $prev_join) . '.id_topic)';
					$subject_query['where'][] = 'subj' . $numTables . '.word ' . (empty($modSettings['search_match_words']) ? 'LIKE {string:subject_words_' . $numTables . '_wild}' : '= {string:subject_words_' . $numTables . '}');
					$prev_join = $numTables;
				}

				$subject_query_params['subject_words_' . $numTables] = $subjectWord;
				$subject_query_params['subject_words_' . $numTables . '_wild'] = '%' . $subjectWord . '%';
			}

			if (!empty($this->userQuery))
			{
				$subject_query['inner_join'][] = '{db_prefix}messages AS m ON (m.id_topic = t.id_topic)';
				$subject_query['where'][] = $this->userQuery;
			}

			if (!empty($this->search_params['topic']))
			{
				$subject_query['where'][] = 't.id_topic = ' . $this->search_params['topic'];
			}

			if (!empty($this->minMsgID))
			{
				$subject_query['where'][] = 't.id_first_msg >= ' . $this->minMsgID;
			}

			if (!empty($this->maxMsgID))
			{
				$subject_query['where'][] = 't.id_last_msg <= ' . $this->maxMsgID;
			}

			if (!empty($this->boardQuery))
			{
				$subject_query['where'][] = 't.id_board ' . $this->boardQuery;
			}

			if (!empty($this->excludedPhrases))
			{
				$subject_query['inner_join'][] = '{db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)';

				$count = 0;
				foreach ($this->excludedPhrases as $phrase)
				{
					$subject_query['where'][] = 'm.subject NOT ' . (empty($modSettings['search_match_words']) || $this->noRegexp() ? ' LIKE ' : ' RLIKE ') . '{string:excluded_phrases_' . $count . '}';
					$subject_query_params['excluded_phrases_' . $count++] = $this->searchAPI->prepareWord($phrase, $this->noRegexp());
				}
			}

			// Build the search query
			$subject_query['select'] = array(
				'id_search' => '{int:id_search}',
				'id_topic' => 't.id_topic',
				'relevance' => $this->build_relevance(),
				'id_msg' => empty($this->userQuery) ? 't.id_first_msg' : 'm.id_msg',
				'num_matches' => 1,
			);

			$subject_query['parameters'] = array_merge($subject_query_params, array(
				'id_search' => $id_search,
				'min_msg' => $this->minMsg,
				'recent_message' => $this->recentMsg,
				'huge_topic_posts' => $humungousTopicPosts,
				'is_approved' => 1,
				'limit' => empty($modSettings['search_max_results']) ? 0 : $modSettings['search_max_results'] - $numSubjectResults,
			));

			$GLOBALS['elk']['hooks']->hook('subject_only_search_query', array(&$subject_query, &$subject_query_params));

			$numSubjectResults += $this->build_search_results_log($subject_query, 'insert_log_search_results_subject');

			if (!empty($modSettings['search_max_results']) && $numSubjectResults >= $modSettings['search_max_results'])
			{
				break;
			}
		}

		return empty($numSubjectResults) ? 0 : $numSubjectResults;
	}

	/**
	 * Grabs results when the search is performed in subjects and bodies
	 *
	 * @param int $id_search - the id of the search
	 * @param int $humungousTopicPosts - Message length used to tweak messages relevance of the results.
	 * @param int $maxMessageResults - Maximum number of results
	 *
	 * @return bool|int - boolean (false) in case of errors, number of results otherwise
	 */
	public function getResults($id_search, $humungousTopicPosts, $maxMessageResults)
	{
		global $modSettings;

		$num_results = 0;

		$main_query = array(
			'select' => array(
				'id_search' => $id_search,
				'relevance' => '0',
			),
			'weights' => array(),
			'from' => '{db_prefix}topics AS t',
			'inner_join' => array(
				'{db_prefix}messages AS m ON (m.id_topic = t.id_topic)'
			),
			'left_join' => array(),
			'where' => array(),
			'group_by' => array(),
			'parameters' => array(
				'min_msg' => $this->minMsg,
				'recent_message' => $this->recentMsg,
				'huge_topic_posts' => $humungousTopicPosts,
				'is_approved' => 1,
				'limit' => $modSettings['search_max_results'],
			),
		);

		if (empty($this->search_params['topic']) && empty($this->search_params['show_complete']))
		{
			$main_query['select']['id_topic'] = 't.id_topic';
			$main_query['select']['id_msg'] = 'MAX(m.id_msg) AS id_msg';
			$main_query['select']['num_matches'] = 'COUNT(*) AS num_matches';
			$main_query['weights'] = $this->weight_factors;
			$main_query['group_by'][] = 't.id_topic';
		}
		else
		{
			// This is outrageous!
			$main_query['select']['id_topic'] = 'm.id_msg AS id_topic';
			$main_query['select']['id_msg'] = 'm.id_msg';
			$main_query['select']['num_matches'] = '1 AS num_matches';

			$main_query['weights'] = array(
				'age' => array(
					'search' => '((m.id_msg - t.id_first_msg) / CASE WHEN t.id_last_msg = t.id_first_msg THEN 1 ELSE t.id_last_msg - t.id_first_msg END)',
				),
				'first_message' => array(
					'search' => 'CASE WHEN m.id_msg = t.id_first_msg THEN 1 ELSE 0 END',
				),
			);

			if (!empty($this->search_params['topic']))
			{
				$main_query['where'][] = 't.id_topic = {int:topic}';
				$main_query['parameters']['topic'] = $this->param('topic');
			}

			if (!empty($this->search_params['show_complete']))
			{
				$main_query['group_by'][] = 'm.id_msg, t.id_first_msg, t.id_last_msg';
			}
		}

		// *** Get the subject results.
		$numSubjectResults = $this->log_search_subjects($id_search);

		if ($numSubjectResults !== 0)
		{
			$main_query['weights']['subject']['search'] = 'CASE WHEN MAX(lst.id_topic) IS NULL THEN 0 ELSE 1 END';
			$main_query['left_join'][] = '{db_prefix}' . ($this->createTemporary ? 'tmp_' : '') . 'log_search_topics AS lst ON (' . ($this->createTemporary ? '' : 'lst.id_search = {int:id_search} AND ') . 'lst.id_topic = t.id_topic)';
			if (!$this->createTemporary)
			{
				$main_query['parameters']['id_search'] = $id_search;
			}
		}

		// We building an index?
		if ($this->searchAPI->supportsMethod('indexedWordQuery', $this->getParams()))
		{
			$indexedResults = $this->prepare_word_index($id_search, $maxMessageResults);

			if (empty($indexedResults) && empty($numSubjectResults) && !empty($modSettings['search_force_index']))
			{
				return false;
			}
			elseif (!empty($indexedResults))
			{
				$main_query['inner_join'][] = '{db_prefix}' . ($this->createTemporary ? 'tmp_' : '') . 'log_search_messages AS lsm ON (lsm.id_msg = m.id_msg)';

				if (!$this->createTemporary)
				{
					$main_query['where'][] = 'lsm.id_search = {int:id_search}';
					$main_query['parameters']['id_search'] = $id_search;
				}
			}
		}
		// Not using an index? All conditions have to be carried over.
		else
		{
			$orWhere = array();
			$count = 0;
			foreach ($this->searchWords as $words)
			{
				$where = array();
				foreach ($words['all_words'] as $regularWord)
				{
					$where[] = 'm.body' . (in_array($regularWord, $this->excludedWords) ? ' NOT' : '') . (empty($modSettings['search_match_words']) || $this->noRegexp() ? ' LIKE ' : ' RLIKE ') . '{string:all_word_body_' . $count . '}';
					if (in_array($regularWord, $this->excludedWords))
					{
						$where[] = 'm.subject NOT' . (empty($modSettings['search_match_words']) || $this->noRegexp() ? ' LIKE ' : ' RLIKE ') . '{string:all_word_body_' . $count . '}';
					}
					$main_query['parameters']['all_word_body_' . $count++] = $this->searchAPI->prepareWord($regularWord, $this->noRegexp());
				}

				if (!empty($where))
				{
					$orWhere[] = count($where) > 1 ? '(' . implode(' AND ', $where) . ')' : $where[0];
				}
			}

			if (!empty($orWhere))
			{
				$main_query['where'][] = count($orWhere) > 1 ? '(' . implode(' OR ', $orWhere) . ')' : $orWhere[0];
			}

			if (!empty($this->userQuery))
			{
				$main_query['where'][] = '{raw:user_query}';
				$main_query['parameters']['user_query'] = $this->userQuery;
			}

			if (!empty($this->search_params['topic']))
			{
				$main_query['where'][] = 'm.id_topic = {int:topic}';
				$main_query['parameters']['topic'] = $this->param('topic');
			}

			if (!empty($this->minMsgID))
			{
				$main_query['where'][] = 'm.id_msg >= {int:min_msg_id}';
				$main_query['parameters']['min_msg_id'] = $this->minMsgID;
			}

			if (!empty($this->maxMsgID))
			{
				$main_query['where'][] = 'm.id_msg <= {int:max_msg_id}';
				$main_query['parameters']['max_msg_id'] = $this->maxMsgID;
			}

			if (!empty($this->boardQuery))
			{
				$main_query['where'][] = 'm.id_board {raw:board_query}';
				$main_query['parameters']['board_query'] = $this->boardQuery;
			}
		}
		$GLOBALS['elk']['hooks']->hook('main_search_query', array(&$main_query));

		// Did we either get some indexed results, or otherwise did not do an indexed query?
		if (!empty($indexedResults) || !$this->searchAPI->supportsMethod('indexedWordQuery', $this->getParams()))
		{
			$relevance = $this->build_relevance($main_query['weights']);
			$main_query['select']['relevance'] = $relevance;
			$num_results += $this->build_search_results_log($main_query, 'insert_log_search_results_no_index');
		}

		// Insert subject-only matches.
		if ($num_results < $modSettings['search_max_results'] && $numSubjectResults !== 0)
		{
			$subject_query = array(
				'select' => array(
					'id_search' => '{int:id_search}',
					'id_topic' => 't.id_topic',
					'relevance' => $this->build_relevance(),
					'id_msg' => 't.id_first_msg',
					'num_matches' => 1,
				),
				'from' => '{db_prefix}topics AS t',
				'inner_join' => array(
					'{db_prefix}' . ($this->createTemporary ? 'tmp_' : '') . 'log_search_topics AS lst ON (lst.id_topic = t.id_topic)'
				),
				'where' => array(
					$this->createTemporary ? '1=1' : 'lst.id_search = {int:id_search}',
				),
				'parameters' => array(
					'id_search' => $id_search,
					'min_msg' => $this->minMsg,
					'recent_message' => $this->recentMsg,
					'huge_topic_posts' => $humungousTopicPosts,
					'limit' => empty($modSettings['search_max_results']) ? 0 : $modSettings['search_max_results'] - $num_results,
				),
			);

			$num_results += $this->build_search_results_log($subject_query, 'insert_log_search_results_sub_only', true);
		}
		elseif ($num_results == -1)
		{
			$num_results = 0;
		}

		return $num_results;
	}

	/**
	 * Determines and add the relevance to the results
	 *
	 * @param mixed[] $topics - The search results (passed by reference)
	 * @param int $id_search - the id of the search
	 * @param int $start - Results are shown starting from here
	 * @param int $limit - No more results than this
	 *
	 * @return bool[]
	 */
	public function addRelevance(&$topics, $id_search, $start, $limit)
	{
		// *** Retrieve the results to be shown on the page
		$participants = array();
		$request = $this->db_search->query('', '
			SELECT ' . (empty($this->search_params['topic']) ? 'lsr.id_topic' : $this->param('topic') . ' AS id_topic') . ', lsr.id_msg, lsr.relevance, lsr.num_matches
			FROM {db_prefix}log_search_results AS lsr' . ($this->param('sort') === 'num_replies' ? '
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = lsr.id_topic)' : '') . '
			WHERE lsr.id_search = {int:id_search}
			ORDER BY {raw:sort} {raw:sort_dir}
			LIMIT {int:start}, {int:limit}',
			array(
				'id_search' => $id_search,
				'sort' => $this->param('sort'),
				'sort_dir' => $this->param('sort_dir'),
				'start' => $start,
				'limit' => $limit,
			)
		);
		while ($row = $request->fetchAssoc())
		{
			$topics[$row['id_msg']] = array(
				'relevance' => round($row['relevance'] / 10, 1) . '%',
				'num_matches' => $row['num_matches'],
				'matches' => array(),
			);
			// By default they didn't participate in the topic!
			$participants[$row['id_topic']] = false;
		}
		$request->free();

		return $participants;
	}

	/**
	 * Finds the posters of the messages
	 *
	 * @param int[] $msg_list - All the messages we want to find the posters
	 * @param int $limit - There are only so much topics
	 *
	 * @return int[] - array of members id
	 */
	public function loadPosters($msg_list, $limit)
	{
		// Load the posters...
		$request = $this->db->query('', '
			SELECT id_member
			FROM {db_prefix}messages
			WHERE id_member != {int:no_member}
				AND id_msg IN ({array_int:message_list})
			LIMIT {int:limit}',
			array(
				'message_list' => $msg_list,
				'limit' => $limit,
				'no_member' => 0,
			)
		);
		$posters = array();
		while ($row = $request->fetchAssoc())
			$posters[] = $row['id_member'];
		$request->free();

		return $posters;
	}

	/**
	 * Finds the posters of the messages
	 *
	 * @param int[] $msg_list - All the messages we want to find the posters
	 * @param int $limit - There are only so much topics
	 *
	 * @return resource
	 */
	public function loadMessagesRequest($msg_list, $limit)
	{
		global $modSettings;

		$request = $this->db->query('', '
			SELECT
				m.id_msg, m.subject, m.poster_name, m.poster_email, m.poster_time, m.id_member,
				m.icon, m.poster_ip, m.body, m.smileys_enabled, m.modified_time, m.modified_name,
				first_m.id_msg AS first_msg, first_m.subject AS first_subject, first_m.icon AS first_icon, first_m.poster_time AS first_poster_time,
				first_mem.id_member AS first_member_id, IFNULL(first_mem.real_name, first_m.poster_name) AS first_member_name,
				last_m.id_msg AS last_msg, last_m.poster_time AS last_poster_time, last_mem.id_member AS last_member_id,
				IFNULL(last_mem.real_name, last_m.poster_name) AS last_member_name, last_m.icon AS last_icon, last_m.subject AS last_subject,
				t.id_topic, t.is_sticky, t.locked, t.id_poll, t.num_replies, t.num_views, t.num_likes,
				b.id_board, b.name AS board_name, c.id_cat, c.name AS cat_name
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic)
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
				INNER JOIN {db_prefix}categories AS c ON (c.id_cat = b.id_cat)
				INNER JOIN {db_prefix}messages AS first_m ON (first_m.id_msg = t.id_first_msg)
				INNER JOIN {db_prefix}messages AS last_m ON (last_m.id_msg = t.id_last_msg)
				LEFT JOIN {db_prefix}members AS first_mem ON (first_mem.id_member = first_m.id_member)
				LEFT JOIN {db_prefix}members AS last_mem ON (last_mem.id_member = first_m.id_member)
			WHERE m.id_msg IN ({array_int:message_list})' . ($modSettings['postmod_active'] ? '
				AND m.approved = {int:is_approved}' : '') . '
			ORDER BY FIND_IN_SET(m.id_msg, {string:message_list_in_set})
			LIMIT {int:limit}',
			array(
				'message_list' => $msg_list,
				'is_approved' => 1,
				'message_list_in_set' => implode(',', $msg_list),
				'limit' => $limit,
			)
		);

		return $request;
	}

	/**
	 * Did the user find any message at all?
	 *
	 * @param resource $messages_request holds a query result
	 *
	 * @return boolean
	 */
	public function noMessages($messages_request)
	{
		return $this->db->num_rows($messages_request) == 0;
	}

	/**
	 * If searching in topics only (?), inserts results in log_search_topics
	 *
	 * @param int $id_search - the id of the search to delete from logs
	 *
	 * @return array - the number of search results and if a temporary table has been created
	 */
	protected function log_search_subjects($id_search)
	{
		global $modSettings;

		if (empty($this->search_params['topic']))
		{
			return array(0, false);
		}

		$inserts = array();
		$numSubjectResults = 0;

		// Clean up some previous cache.
		if (!$this->createTemporary)
		{
			$this->db_search->query('delete_log_search_topics', '
				DELETE FROM {db_prefix}log_search_topics
				WHERE id_search = {int:search_id}',
				array(
					'search_id' => $id_search,
				)
			);
		}

		foreach ($this->searchWords as $words)
		{
			$subject_query = array(
				'from' => '{db_prefix}topics AS t',
				'inner_join' => array(),
				'left_join' => array(),
				'where' => array(),
				'params' => array(),
			);

			$numTables = 0;
			$prev_join = 0;
			$count = 0;
			foreach ($words['subject_words'] as $subjectWord)
			{
				$numTables++;
				if (in_array($subjectWord, $this->excludedSubjectWords))
				{
					$subject_query['inner_join'][] = '{db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)';
					$subject_query['left_join'][] = '{db_prefix}log_search_subjects AS subj' . $numTables . ' ON (subj' . $numTables . '.word ' . (empty($modSettings['search_match_words']) ? 'LIKE {string:subject_not_' . $count . '}' : '= {string:subject_not_' . $count . '}') . ' AND subj' . $numTables . '.id_topic = t.id_topic)';
					$subject_query['params']['subject_not_' . $count] = empty($modSettings['search_match_words']) ? '%' . $subjectWord . '%' : $subjectWord;

					$subject_query['where'][] = '(subj' . $numTables . '.word IS NULL)';
					$subject_query['where'][] = 'm.body NOT ' . (empty($modSettings['search_match_words']) || $this->noRegexp() ? ' LIKE ' : ' RLIKE ') . '{string:body_not_' . $count . '}';
					$subject_query['params']['body_not_' . $count++] = $this->searchAPI->prepareWord($subjectWord, $this->noRegexp());
				}
				else
				{
					$subject_query['inner_join'][] = '{db_prefix}log_search_subjects AS subj' . $numTables . ' ON (subj' . $numTables . '.id_topic = ' . ($prev_join === 0 ? 't' : 'subj' . $prev_join) . '.id_topic)';
					$subject_query['where'][] = 'subj' . $numTables . '.word LIKE {string:subject_like_' . $count . '}';
					$subject_query['params']['subject_like_' . $count++] = empty($modSettings['search_match_words']) ? '%' . $subjectWord . '%' : $subjectWord;
					$prev_join = $numTables;
				}
			}

			if (!empty($this->userQuery))
			{
				$subject_query['inner_join'][] = '{db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)';
				$subject_query['where'][] = '{raw:user_query}';
				$subject_query['params']['user_query'] = $this->userQuery;
			}

			if (!empty($this->search_params['topic']))
			{
				$subject_query['where'][] = 't.id_topic = {int:topic}';
				$subject_query['params']['topic'] = $this->param('topic');
			}

			if (!empty($this->minMsgID))
			{
				$subject_query['where'][] = 't.id_first_msg >= {int:min_msg_id}';
				$subject_query['params']['min_msg_id'] = $this->minMsgID;
			}

			if (!empty($this->maxMsgID))
			{
				$subject_query['where'][] = 't.id_last_msg <= {int:max_msg_id}';
				$subject_query['params']['max_msg_id'] = $this->maxMsgID;
			}

			if (!empty($this->boardQuery))
			{
				$subject_query['where'][] = 't.id_board {raw:board_query}';
				$subject_query['params']['board_query'] = $this->boardQuery;
			}

			if (!empty($this->excludedPhrases))
			{
				$subject_query['inner_join'][] = '{db_prefix}messages AS m ON (m.id_msg = t.id_first_msg)';
				$count = 0;
				foreach ($this->excludedPhrases as $phrase)
				{
					$subject_query['where'][] = 'm.subject NOT ' . (empty($modSettings['search_match_words']) || $this->noRegexp() ? ' LIKE ' : ' RLIKE ') . '{string:exclude_phrase_' . $count . '}';
					$subject_query['where'][] = 'm.body NOT ' . (empty($modSettings['search_match_words']) || $this->noRegexp() ? ' LIKE ' : ' RLIKE ') . '{string:exclude_phrase_' . $count . '}';
					$subject_query['params']['exclude_phrase_' . $count++] = $this->searchAPI->prepareWord($phrase, $this->noRegexp());
				}
			}

			$GLOBALS['elk']['hooks']->hook('subject_search_query', array(&$subject_query));

			// Nothing to search for?
			if (empty($subject_query['where']))
			{
				continue;
			}

			$ignoreRequest = $this->db_search->query('insert_log_search_topics', ($this->db->support_ignore() ? ('
				INSERT IGNORE INTO {db_prefix}' . ($this->createTemporary ? 'tmp_' : '') . 'log_search_topics
					(' . ($this->createTemporary ? '' : 'id_search, ') . 'id_topic)') : '') . '
				SELECT ' . ($this->createTemporary ? '' : $id_search . ', ') . 't.id_topic
				FROM ' . $subject_query['from'] . (empty($subject_query['inner_join']) ? '' : '
					INNER JOIN ' . implode('
					INNER JOIN ', array_unique($subject_query['inner_join']))) . (empty($subject_query['left_join']) ? '' : '
					LEFT JOIN ' . implode('
					LEFT JOIN ', array_unique($subject_query['left_join']))) . '
				WHERE ' . implode('
					AND ', array_unique($subject_query['where'])) . (empty($modSettings['search_max_results']) ? '' : '
				LIMIT ' . ($modSettings['search_max_results'] - $numSubjectResults)),
				$subject_query['params']
			);

			// Don't do INSERT IGNORE? Manually fix this up!
			if (!$this->db->support_ignore())
			{
				while ($row = $ignoreRequest->fetchRow())
				{
					$ind = $this->createTemporary ? 0 : 1;

					// No duplicates!
					if (isset($inserts[$row[$ind]]))
					{
						continue;
					}

					$inserts[$row[$ind]] = $row;
				}
				$ignoreRequest->free();
				$numSubjectResults = count($inserts);
			}
			else
			{
				$numSubjectResults += $ignoreRequest->numAffectedRows();
			}

			if (!empty($modSettings['search_max_results']) && $numSubjectResults >= $modSettings['search_max_results'])
			{
				break;
			}
		}

		// Got some non-MySQL data to plonk in?
		if (!empty($inserts))
		{
			$this->db->insert('',
				('{db_prefix}' . ($this->createTemporary ? 'tmp_' : '') . 'log_search_topics'),
				$this->createTemporary ? array('id_topic' => 'int') : array('id_search' => 'int', 'id_topic' => 'int'),
				$inserts,
				$this->createTemporary ? array('id_topic') : array('id_search', 'id_topic')
			);
		}

		return $numSubjectResults;
	}

	/**
	 * Populates log_search_messages
	 *
	 * @param int $id_search - the id of the search to delete from logs
	 * @param int $maxMessageResults - the maximum number of messages to index
	 *
	 * @return int - the number of indexed results
	 */
	protected function prepare_word_index($id_search, $maxMessageResults)
	{
		$indexedResults = 0;
		$inserts = array();

		// Clear, all clear!
		if (!$this->createTemporary)
		{
			$this->db_search->query('delete_log_search_messages', '
				DELETE FROM {db_prefix}log_search_messages
				WHERE id_search = {int:id_search}',
				array(
					'id_search' => $id_search,
				)
			);
		}

		foreach ($this->searchWords as $words)
		{
			// Search for this word, assuming we have some words!
			if (!empty($words['indexed_words']))
			{
				// Variables required for the search.
				$search_data = array(
					'insert_into' => ($this->createTemporary ? 'tmp_' : '') . 'log_search_messages',
					'no_regexp' => $this->noRegexp(),
					'max_results' => $maxMessageResults,
					'indexed_results' => $indexedResults,
					'params' => array(
						'id_search' => !$this->createTemporary ? $id_search : 0,
						'excluded_words' => $this->excludedWords,
						'user_query' => !empty($this->userQuery) ? $this->userQuery : '',
						'board_query' => !empty($this->boardQuery) ? $this->boardQuery : '',
						'topic' => !empty($this->search_params['topic']) ? $this->param('topic') : 0,
						'min_msg_id' => (int) $this->minMsgID,
						'max_msg_id' => (int) $this->maxMsgID,
						'excluded_phrases' => $this->excludedPhrases,
						'excluded_index_words' => $this->excludedIndexWords,
						'excluded_subject_words' => $this->excludedSubjectWords,
					),
				);

				$ignoreRequest = $this->searchAPI->indexedWordQuery($words, $search_data);

				if (!$this->db->support_ignore())
				{
					while ($row = $ignoreRequest->fetchRow())
					{
						// No duplicates!
						if (isset($inserts[$row[0]]))
						{
							continue;
						}

						$inserts[$row[0]] = $row;
					}
					$ignoreRequest->free();
					$indexedResults = count($inserts);
				}
				else
				{
					$indexedResults += $ignoreRequest->numAffectedRows();
				}

				if (!empty($maxMessageResults) && $indexedResults >= $maxMessageResults)
				{
					break;
				}
			}
		}

		// More non-MySQL stuff needed?
		if (!empty($inserts))
		{
			$this->db->insert('',
				'{db_prefix}' . ($this->createTemporary ? 'tmp_' : '') . 'log_search_messages',
				$this->createTemporary ? array('id_msg' => 'int') : array('id_msg' => 'int', 'id_search' => 'int'),
				$inserts,
				$this->createTemporary ? array('id_msg') : array('id_msg', 'id_search')
			);
		}

		return $indexedResults;
	}

	/**
	 * Build the search relevance query
	 *
	 * @param null|int[] $factors - is factors are specified that array will
	 * be used to build the relevance value, otherwise the function will use
	 * $this->weight_factors
	 *
	 * @return string
	 */
	protected function build_relevance($factors = null)
	{
		$relevance = '1000 * (';

		if ($factors !== null && is_array($factors))
		{
			$weight_total = 0;
			foreach ($factors as $type => $value)
			{
				$relevance .= $this->weight[$type];
				if (!empty($value['search']))
				{
					$relevance .= ' * ' . $value['search'];
				}

				$relevance .= ' + ';
				$weight_total += $this->weight[$type];
			}
		}
		else
		{
			$weight_total = $this->weight_total;
			foreach ($this->weight_factors as $type => $value)
			{
				if (isset($value['results']))
				{
					$relevance .= $this->weight[$type];
					if (!empty($value['results']))
					{
						$relevance .= ' * ' . $value['results'];
					}

					$relevance .= ' + ';
				}
			}
		}

		$relevance = substr($relevance, 0, -3) . ') / ' . $weight_total . ' AS relevance';

		return $relevance;
	}

	/**
	 * Inserts the data into log_search_results
	 *
	 * @param mixed[] $main_query - An array holding all the query parts.
	 *   Structure:
	 * 		'select' => string[] - the select columns
	 * 		'from' => string - the table for the FROM clause
	 * 		'inner_join' => string[] - any INNER JOIN
	 * 		'left_join' => string[] - any LEFT JOIN
	 * 		'where' => string[] - the conditions
	 * 		'group_by' => string[] - the fields to group by
	 * 		'parameters' => mixed[] - any parameter required by the query
	 * @param string $query_identifier - a string to identify the query
	 * @param bool $use_old_ids - if true the topic ids retrieved by a previous
	 * call to this function will be used to identify duplicates
	 *
	 * @return int - the number of rows affected by the query
	 */
	protected function build_search_results_log($main_query, $query_identifier, $use_old_ids = false)
	{
		static $usedIDs;

		$ignoreRequest = $this->db_search->query($query_identifier, ($this->db->support_ignore() ? ('
			INSERT IGNORE INTO {db_prefix}log_search_results
				(' . implode(', ', array_keys($main_query['select'])) . ')') : '') . '
			SELECT
				' . implode(',
				', $main_query['select']) . '
			FROM ' . $main_query['from'] . (!empty($main_query['inner_join']) ? '
				INNER JOIN ' . implode('
				INNER JOIN ', array_unique($main_query['inner_join'])) : '') . (!empty($main_query['left_join']) ? '
				LEFT JOIN ' . implode('
				LEFT JOIN ', array_unique($main_query['left_join'])) : '') . (!empty($main_query['where']) ? '
			WHERE ' : '') . implode('
				AND ', array_unique($main_query['where'])) . (!empty($main_query['group_by']) ? '
			GROUP BY ' . implode(', ', array_unique($main_query['group_by'])) : '') . (!empty($main_query['parameters']['limit']) ? '
			LIMIT {int:limit}' : ''),
			$main_query['parameters']
		);

		// If the database doesn't support IGNORE to make this fast we need to do some tracking.
		if (!$this->db->support_ignore())
		{
			$inserts = array();

			while ($row = $ignoreRequest->fetchAssoc())
			{
				// No duplicates!
				if ($use_old_ids)
				{
					if (isset($usedIDs[$row['id_topic']]))
					{
						continue;
					}
				}
				else
				{
					if (isset($inserts[$row['id_topic']]))
					{
						continue;
					}
				}

				$usedIDs[$row['id_topic']] = true;
				foreach ($row as $key => $value)
					$inserts[$row['id_topic']][] = (int) $row[$key];
			}
			$ignoreRequest->free();

			// Now put them in!
			if (!empty($inserts))
			{
				$query_columns = array();
				foreach ($main_query['select'] as $k => $v)
					$query_columns[$k] = 'int';

				$this->db->insert('',
					'{db_prefix}log_search_results',
					$query_columns,
					$inserts,
					array('id_search', 'id_topic')
				);
			}
			$num_results = count($inserts);
		}
		else
		{
			$num_results = $ignoreRequest->numAffectedRows();
		}

		return $num_results;
	}
}