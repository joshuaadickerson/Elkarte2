<?php

/**
 * Abstract class that defines the methods search APIs shall implement
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.1 dev Release Candidate 1
 */

namespace Elkarte\Search\API;

use Elkarte\Search\Drivers\SearchInterface;

/**
 * Abstract class that defines the methods any search API shall implement
 * to properly work with ElkArte
 *
 * @package Search
 */
abstract class SearchAPI implements SearchInterface
{
	/**
	 * What words are banned?
	 * @var array
	 */
	protected $bannedWords = [];

	/**
	 * Any word excluded from the search?
	 * @var array
	 */
	protected $excludedWords = [];

	/**
	 * Method to check whether the method can be performed by the API.
	 *
	 * @param string $methodName
	 * @param string|null $query_params
	 * @return boolean
	 */
	abstract public function supportsMethod($methodName, $query_params = null);

	/**
	 * If the settings don't exist we can't continue.
	 */
	public function isValid()
	{
		// Always fall back to the standard search method.
		return true;
	}

	/**
	 * Escape words passed by the client
	 *
	 * @param string $phrase - The string to escape
	 * @param bool $no_regexp - If true or $modSettings['search_match_words']
	 *              is empty, uses % at the beginning and end of the string,
	 *              otherwise returns a regular expression
	 * @return string
	 */
	public function prepareWord($phrase, $no_regexp)
	{
		global $modSettings;

		return empty($modSettings['search_match_words']) || $no_regexp ? '%' . strtr($phrase, array('_' => '\\_', '%' => '\\%')) . '%' : '[[:<:]]' . addcslashes(preg_replace(array('/([\[\]$.+*?|{}()])/'), array('[$1]'), $phrase), '\\\'') . '[[:>:]]';
	}

	/**
	 * Adds the excluded words list
	 *
	 * @param string[] $words An array of words
	 */
	public function setExcludedWords(array $words)
	{
		$this->excludedWords = $words;
	}

	/**
	 * Callback function for usort used to sort the results.
	 *
	 * - The order of sorting is: large words, small words, large words that
	 * are excluded from the search, small words that are excluded.
	 *
	 * @param string $a Word A
	 * @param string $b Word B
	 * @return int An integer indicating how the words should be sorted (-1, 0 1)
	 */
	public function searchSort($a, $b)
	{
		$x = strlen($a) - (in_array($a, $this->excludedWords) ? 1000 : 0);
		$y = strlen($b) - (in_array($b, $this->excludedWords) ? 1000 : 0);

		return $x < $y ? 1 : ($x > $y ? -1 : 0);
	}
}