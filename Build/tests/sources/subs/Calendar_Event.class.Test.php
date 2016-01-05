<?php

/**
 * TestCase class for the Calendar_Event class.
 */
class TestCalendar_Event extends PHPUnit_Framework_TestCase
{
	/**
	 * To avoid duplicated function declarations, we need an empty Calendar.subs.php
	 */
	public static function setUpBeforeClass()
	{
		rename(SUBSDIR . '/Calendar.subs.php', SUBSDIR . '/Calendar_tmp.subs.php');
		touch(SUBSDIR . '/Calendar.subs.php');
	}

	/**
	 * Better restore it before leaving
	 */
	public static function tearDownAfterClass()
	{
		unlink(SUBSDIR . '/Calendar.subs.php');
		rename(SUBSDIR . '/Calendar_tmp.subs.php', SUBSDIR . '/Calendar.subs.php');
	}

	public function setUp()
	{
		global $context, $user_info;

		$context['linktree'] = array();
		// Faking an Admin
		$user_info['is_admin'] = true;
	}

	/**
	 * null or -1 means new event, any other number, means existing one
	 */
	public function testNew()
	{
		$event = new Calendar_Event(null, array());
		$this->assertTrue($event->isNew());

		$event = new Calendar_Event(-1, array());
		$this->assertTrue($event->isNew());

		$event = new Calendar_Event(1, array());
		$this->assertFalse($event->isNew());
	}

	public function testStarter()
	{
		$event = new Calendar_Event(1, array());

		// Guest means not the starter
		$this->assertFalse($event->isStarter(0));

		// According to the fake getEventPoster below, the poster is 1
		$this->assertTrue($event->isStarter(1));
		$this->assertFalse($event->isStarter(2));
	}

	/**
	 * @expectedException Exception
	 * @expectedExceptionMessage removeEvent called with id = 1
	 */
	public function testRemove()
	{
		$event = new Calendar_Event(1, array());
		$event->remove();
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage The span feature is currently disabled.
	 */
	public function testValidateNoSpan()
	{
		$event = new Calendar_Event(1, array());
		$event->validate(array('span' => 1));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid number of days to span.
	 */
	public function testValidateInvalidSpan1()
	{
		$event = new Calendar_Event(1, array('cal_allowspan' => 1, 'cal_maxspan' => 3));
		$event->validate(array('span' => -1));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid number of days to span.
	 */
	public function testValidateInvalidSpan2()
	{
		$event = new Calendar_Event(1, array('cal_allowspan' => 1, 'cal_maxspan' => 3));
		$event->validate(array('span' => 5));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Event month is missing.
	 */
	public function testValidateNotDelete1()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// No month passed => Elk_Exception
		$event->validate(array());
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Event year is missing.
	 */
	public function testValidateNotDelete2()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// No year passed => Elk_Exception
		$event->validate(array('month' => 1));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid month value.
	 */
	public function testValidateNotDelete3()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// Negative months are not allowed
		$event->validate(array('month' => -1, 'year' => 2013));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid month value.
	 */
	public function testValidateNotDelete4()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// Zero is not a month
		$event->validate(array('month' => 0, 'year' => 2013));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid month value.
	 */
	public function testValidateNotDelete5()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// An years haz only 12 months...
		$event->validate(array('month' => 13, 'year' => 2013));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid year value.
	 */
	public function testValidateNotDelete6()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// Too low year
		$event->validate(array('month' => 1, 'year' => 2011));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid year value.
	 */
	public function testValidateNotDelete7()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// Too far away in the future
		$event->validate(array('month' => 1, 'year' => 2017));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Event day is missing.
	 */
	public function testValidateNotDelete8()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// No day => Elk_Exception
		$event->validate(array('month' => 1, 'year' => 2013));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Event title is missing.
	 */
	public function testValidateNotDelete9()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// No title => Elk_Exception
		$event->validate(array('month' => 1, 'year' => 2013, 'day' => 1));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage Invalid date.
	 */
	public function testValidateNotDelete10()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// No need to test the PHP checkdata function, so just one single bad date
		$event->validate(array('month' => 2, 'year' => 2013, 'day' => 30, 'evtitle' => 'string', 'subject' => 'string'));
	}

	/**
	 * @expectedException ElkException
	 * @expectedExceptionMessage No event title was entered.
	 */
	public function testValidateNotDelete11()
	{
		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));
		// A evtitle made up of spaces should be trimmed and result in an empty string
		$event->validate(array('month' => 2, 'year' => 2013, 'day' => 1, 'evtitle' => '    ', 'subject' => 'string'));
	}

	/**
	 * Testing (ev)title/subject trimming and adjustments.
	 */
	public function testValidateTitle()
	{
		$input = array('month' => 1, 'year' => 2013, 'day' => 1, 'subject' => 'string');

		$event = new Calendar_Event(1, array('cal_minyear' => 2012, 'cal_maxyear' => 2015));

		// If no evtitle, but subject present, then evtitle == subject
		$result = $event->validate($input);
		$this->assertSame($input['subject'], $result['evtitle']);

		$input['evtitle'] = 'This must be an evtitle longer than one hundred characters, so that it is cut to less than one hundred characters';
		// Long titles are cut
		$result = $event->validate($input);
		$this->assertSame('This must be an evtitle longer than one hundred characters, so that it is cut to less than one hundr', $result['evtitle']);

		$input['evtitle'] = 'Not sure why, but ; are removed from evtitles';
		// Semi-colons are stripped from evtitles
		$result = $event->validate($input);
		$this->assertSame('Not sure why, but  are removed from evtitles', $result['evtitle']);
	}
}

/**
 * Redefine some of the functions used by the class
 */
function insertEvent($array)
{

}

/**
 * Mock of the real removeEvent function
 * (Calendar.subs.php)
 * throws an Exception with message:
 *   'removeEvent called with id = ' . $id
 */
function removeEvent($id)
{
	throw new Exception('removeEvent called with id = ' . $id);
}

function getEventProperties($event, $calendar_only)
{

}

function modifyEvent($event, $options)
{

}

/**
 * Mock of the real getEventPoster function
 * (Calendar.subs.php)
 * always returns 1
 */
function getEventPoster($event)
{
	return 1;
}