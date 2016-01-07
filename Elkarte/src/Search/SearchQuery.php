<?php

/**
 * First you create a query. The query is the standard to send to the drivers.
 */

namespace Elkarte\Search;

class SearchQuery
{
	protected $blacklisted_words = ['img', 'url', 'quote', 'www', 'http', 'the', 'is', 'it', 'are', 'if'];

	protected $boards = [];

	protected $query = '';

	protected $string = '';

	public function __construct($string)
	{
		$this->string = (string) $string;
	}

	public function setBlacklistedWords(array $words)
	{
		$this->blacklisted_words = $words;

		return $this;
	}

	/**
	 * Builds the search array
	 *
	 * @param bool - Force splitting of strings enclosed in double quotes
	 * @return array
	 */
	protected function searchArray($search_simple_fulltext = false)
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


	public function __toString()
	{
		return $this->query;
	}
}