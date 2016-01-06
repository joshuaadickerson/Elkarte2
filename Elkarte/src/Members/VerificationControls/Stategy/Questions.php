<?php

namespace Elkarte\Members\VerificationControls\Strategy;

/**
 * Class to manage, prepare, show, and validate question -> answer verifications
 */
class Questions implements VerificationControlsInterface
{
	/**
	 * Holds any options passed to the class
	 *
	 * @var array
	 */
	private $_options = null;

	/**
	 * array holding all of the available question id
	 * @var int[]
	 */
	private $_questionIDs = null;

	/**
	 * Number of challenge questions to use
	 *
	 * @var int
	 */
	private $_number_questions = null;

	/**
	 * Language the question is in
	 *
	 * @var string
	 */
	private $_questions_language = null;

	/**
	 * Questions that can be used given what available (try's to account for languages)
	 *
	 * @var int[]
	 */
	private $_possible_questions = null;

	/**
	 * Array of question id's that they provided a wrong answer to
	 *
	 * @var int[]
	 */
	private $_incorrectQuestions = null;

	/**
	 * On your mark
	 *
	 * @param mixed[]|null $verificationOptions override_qs,
	 */
	public function __construct($verificationOptions = null)
	{
		if (!empty($verificationOptions))
			$this->_options = $verificationOptions;
	}

	/**
	 * Show the question to the user
	 * Try's to account for languages
	 *
	 * @param boolean $isNew
	 * @param boolean $force_refresh
	 *
	 * @return boolean
	 */
	public function showVerification($isNew, $force_refresh = true)
	{
		global $modSettings, $user_info, $language;

		if ($isNew)
		{
			$this->_number_questions = isset($this->_options['override_qs']) ? $this->_options['override_qs'] : (!empty($modSettings['qa_verification_number']) ? $modSettings['qa_verification_number'] : 0);

			// If we want questions do we have a cache of all the IDs?
			if (!empty($this->_number_questions) && empty($modSettings['question_id_cache']))
				$this->_refreshQuestionsCache();

			// Let's deal with languages
			// First thing we need to know what language the user wants and if there is at least one question
			$this->_questions_language = !empty($_SESSION[$this->_options['id'] . '_vv']['language']) ? $_SESSION[$this->_options['id'] . '_vv']['language'] : (!empty($user_info['language']) ? $user_info['language'] : $language);

			// No questions in the selected language?
			if (empty($modSettings['question_id_cache'][$this->_questions_language]))
			{
				// Not even in the forum default? What the heck are you doing?!
				if (empty($modSettings['question_id_cache'][$language]))
					$this->_number_questions = 0;
				// Fall back to the default
				else
					$this->_questions_language = $language;
			}

			// Do we have enough questions?
			if (!empty($this->_number_questions) && $this->_number_questions <= count($modSettings['question_id_cache'][$this->_questions_language]))
			{
				$this->_possible_questions = $modSettings['question_id_cache'][$this->_questions_language];
				$this->_number_questions = min($this->_number_questions, count($this->_possible_questions));
				$this->_questionIDs = array();

				if ($isNew || $force_refresh)
					$this->createTest($force_refresh);
			}
		}

		return !empty($this->_number_questions);
	}

	/**
	 * Prepare the Q&A test/list for this request
	 *
	 * @param boolean $refresh
	 */
	public function createTest($refresh = true)
	{
		if (empty($this->_number_questions))
			return;

		// Getting some new questions?
		if ($refresh)
		{
			$this->_questionIDs = array();

			// Pick some random IDs
			if ($this->_number_questions == 1)
				$this->_questionIDs[] = $this->_possible_questions[array_rand($this->_possible_questions, $this->_number_questions)];
			else
				foreach (array_rand($this->_possible_questions, $this->_number_questions) as $index)
					$this->_questionIDs[] = $this->_possible_questions[$index];
		}
		// Same questions as before.
		else
			$this->_questionIDs = !empty($_SESSION[$this->_options['id'] . '_vv']['q']) ? $_SESSION[$this->_options['id'] . '_vv']['q'] : array();

		if (empty($this->_questionIDs) && !$refresh)
			$this->createTest(true);
	}

	/**
	 * Get things ready for the template
	 *
	 * @return mixed[]
	 */
	public function prepareContext()
	{
		$GLOBALS['elk']['templates']->load('VerificationControls');

		$_SESSION[$this->_options['id'] . '_vv']['q'] = array();

		$questions = $this->_loadAntispamQuestions(array('type' => 'id_question', 'value' => $this->_questionIDs));
		$asked_questions = array();

		$parser = $GLOBALS['elk']['bbc'];

		foreach ($questions as $row)
		{
			$asked_questions[] = array(
				'id' => $row['id_question'],
				'q' => $parser->parseVerificationControls($row['question']),
				'is_error' => !empty($this->_incorrectQuestions) && in_array($row['id_question'], $this->_incorrectQuestions),
				// Remember a previous submission?
				'a' => isset($_REQUEST[$this->_options['id'] . '_vv'], $_REQUEST[$this->_options['id'] . '_vv']['q'], $_REQUEST[$this->_options['id'] . '_vv']['q'][$row['id_question']]) ? $GLOBALS['elk']['text']->htmlspecialchars($_REQUEST[$this->_options['id'] . '_vv']['q'][$row['id_question']]) : '',
			);
			$_SESSION[$this->_options['id'] . '_vv']['q'][] = $row['id_question'];
		}

		return array(
			'template' => 'questions',
			'values' => $asked_questions,
		);
	}

	/**
	 * Performs the test to see if the answer is correct
	 *
	 * @return string|boolean
	 */
	public function doTest()
	{
		if ($this->_number_questions && (!isset($_SESSION[$this->_options['id'] . '_vv']['q']) || !isset($_REQUEST[$this->_options['id'] . '_vv']['q'])))
			$GLOBALS['elk']['errors']->fatal_lang_error('no_access', false);

		if (!$this->_verifyAnswers())
			return 'wrong_verification_answer';

		return true;
	}

	/**
	 * Required by the interface, returns true for question challenges
	 *
	 * @return true
	 */
	public function hasVisibleTemplate()
	{
		return true;
	}

	/**
	 * Admin panel interface to manage the anti spam question area
	 *
	 * @return mixed[]
	 */
	public function settings()
	{
		global $txt, $context, $language;

		// Load any question and answers!
		$filter = null;
		if (isset($_GET['language']))
		{
			$filter = array(
				'type' => 'language',
				'value' => $_GET['language'],
			);
		}
		$context['question_answers'] = $this->_loadAntispamQuestions($filter);
		$languages = getLanguages();

		// Languages dropdown only if we have more than a lang installed, otherwise is plain useless
		if (count($languages) > 1)
		{
			$context['languages'] = $languages;
			foreach ($context['languages'] as &$lang)
				if ($lang['filename'] === $language)
					$lang['selected'] = true;
		}

		// Saving them?
		if (isset($_GET['save']))
		{
			// Handle verification questions.
			$questionInserts = array();
			$count_questions = 0;

			foreach ($_POST['question'] as $id => $question)
			{
				$question = trim($GLOBALS['elk']['text']->htmlspecialchars($question, ENT_COMPAT));
				$answers = array();
				$question_lang = isset($_POST['language'][$id]) && isset($languages[$_POST['language'][$id]]) ? $_POST['language'][$id] : $language;
				if (!empty($_POST['answer'][$id]))
					foreach ($_POST['answer'][$id] as $answer)
					{
						$answer = trim($GLOBALS['elk']['text']->strtolower($GLOBALS['elk']['text']->htmlspecialchars($answer, ENT_COMPAT)));
						if ($answer != '')
							$answers[] = $answer;
					}

				// Already existed?
				if (isset($context['question_answers'][$id]))
				{
					$count_questions++;

					// Changed?
					if ($question == '' || empty($answers))
					{
						$this->_delete($id);
						$count_questions--;
					}
					else
						$this->_update($id, $question, $answers, $question_lang);
				}
				// It's so shiney and new!
				elseif ($question != '' && !empty($answers))
				{
					$questionInserts[] = array(
						'question' => $question,
						// @todo: remotely possible that the serialized value is longer than 65535 chars breaking the update/insertion
						'answer' => serialize($answers),
						'language' => $question_lang,
					);
					$count_questions++;
				}
			}

			// Any questions to insert?
			if (!empty($questionInserts))
				$this->_insert($questionInserts);

			if (empty($count_questions) || $_POST['qa_verification_number'] > $count_questions)
				$_POST['qa_verification_number'] = $count_questions;

		}

		return array(
			// Clever Thomas, who is looking sheepy now? Not I, the mighty sword swinger did say.
			array('title', 'setup_verification_questions'),
			array('desc', 'setup_verification_questions_desc'),
			array('int', 'qa_verification_number', 'postinput' => $txt['setting_qa_verification_number_desc']),
			array('callback', 'question_answer_list'),
		);
	}

	/**
	 * Checks if an the answers to anti-spam questions are correct
	 *
	 * @return boolean
	 */
	protected function _verifyAnswers()
	{
		// Get the answers and see if they are all right!
		$questions = $this->_loadAntispamQuestions(array('type' => 'id_question', 'value' => $_SESSION[$this->_options['id'] . '_vv']['q']));
		$this->_incorrectQuestions = array();
		foreach ($questions as $row)
		{
			// Everything lowercase
			$answers = array();
			foreach ($row['answer'] as $answer)
				$answers[] = $GLOBALS['elk']['text']->strtolower($answer);

			if (!isset($_REQUEST[$this->_options['id'] . '_vv']['q'][$row['id_question']]) || trim($_REQUEST[$this->_options['id'] . '_vv']['q'][$row['id_question']]) == '' || !in_array(trim($GLOBALS['elk']['text']->htmlspecialchars($GLOBALS['elk']['text']->strtolower($_REQUEST[$this->_options['id'] . '_vv']['q'][$row['id_question']]))), $answers))
				$this->_incorrectQuestions[] = $row['id_question'];
		}

		return empty($this->_incorrectQuestions);
	}

	/**
	 * Updates the cache of questions IDs
	 */
	protected function _refreshQuestionsCache()
	{
		global $modSettings;

		$db = $GLOBALS['elk']['db'];
		$cache = $GLOBALS['elk']['cache'];

		if (!$cache->getVar($modSettings['question_id_cache'], 'verificationQuestionIds', 300) || !$modSettings['question_id_cache'])
		{
			$request = $db->query('', '
				SELECT id_question, language
				FROM {db_prefix}antispam_questions',
				array()
			);
			$modSettings['question_id_cache'] = array();
			while ($row = $request->fetchAssoc())
				$modSettings['question_id_cache'][$row['language']][] = $row['id_question'];
			$request->free();

			$cache->put('verificationQuestionIds', $modSettings['question_id_cache'], 300);
		}
	}

	/**
	 * Loads all the available antispam questions, or a subset based on a filter
	 *
	 * @param mixed[]|null $filter if specified it myst be an array with two indexes:
	 *              - 'type' => a valid filter, it can be 'language' or 'id_question'
	 *              - 'value' => the value of the filter (i.e. the language)
	 */
	protected function _loadAntispamQuestions($filter = null)
	{
		$db = $GLOBALS['elk']['db'];

		$available_filters = array(
			'language' => 'language = {string:current_filter}',
			'id_question' => 'id_question IN ({array_int:current_filter})',
		);

		// Load any question and answers!
		$question_answers = array();
		$request = $db->query('', '
			SELECT id_question, question, answer, language
			FROM {db_prefix}antispam_questions' . ($filter === null || !isset($available_filters[$filter['type']]) ? '' : '
			WHERE ' . $available_filters[$filter['type']]),
			array(
				'current_filter' => $filter['value'],
			)
		);
		while ($row = $request->fetchAssoc())
		{
			$question_answers[$row['id_question']] = array(
				'id_question' => $row['id_question'],
				'question' => $row['question'],
				'answer' => unserialize($row['answer']),
				'language' => $row['language'],
			);
		}
		$request->free();

		return $question_answers;
	}

	/**
	 * Remove a question by id
	 *
	 * @param int $id
	 */
	protected function _delete($id)
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			DELETE FROM {db_prefix}antispam_questions
			WHERE id_question = {int:id}',
			array(
				'id' => $id,
			)
		);
	}

	/**
	 * Update an existing question
	 *
	 * @param int $id
	 * @param string $question
	 * @param string $answers
	 * @param string $language
	 */
	protected function _update($id, $question, $answers, $language)
	{
		$db = $GLOBALS['elk']['db'];

		$db->query('', '
			UPDATE {db_prefix}antispam_questions
			SET
				question = {string:question},
				answer = {string:answer},
				language = {string:language}
			WHERE id_question = {int:id}',
			array(
				'id' => $id,
				'question' => $question,
				// @todo: remotely possible that the serialized value is longer than 65535 chars breaking the update/insertion
				'answer' => serialize($answers),
				'language' => $language,
			)
		);
	}

	/**
	 * Adds the questions to the db
	 *
	 * @param mixed[] $questions
	 */
	protected function _insert($questions)
	{
		$db = $GLOBALS['elk']['db'];

		$db->insert('',
			'{db_prefix}antispam_questions',
			array('question' => 'string-65535', 'answer' => 'string-65535', 'language' => 'string-50'),
			$questions,
			array('id_question')
		);
	}
}