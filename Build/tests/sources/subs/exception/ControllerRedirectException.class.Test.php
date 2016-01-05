<?php

/**
 * TestCase class for Controller_Redirect_Exception class.
 */
class TestControllerRedirectException extends PHPUnit_Framework_TestCase
{
	public function testBasicRedirect()
	{
		$exception = new ControllerRedirectException('MockController', 'action_plain');
		$result = $exception->doRedirect($this);

		$this->assertSame($result, 'success');
	}

	public function testPredispatchRedirect()
	{
		$exception = new ControllerRedirectException('MockpreController', 'action_plain');
		$result = $exception->doRedirect($this);

		$this->assertSame($result, 'success');
	}

	public function testSameControllerRedirect()
	{
		$same = new SameController($this);
	}
}

class SameController extends AbstractController
{
	public function __construct($tester)
	{
		$exception = new ControllerRedirectException('SameController', 'action_plain');
		$result = $exception->doRedirect($this);

		$tester->assertSame($result, 'success');
	}

	public function action_index()
	{
	}

	public function action_plain()
	{
		return 'success';
	}
}

class MockController extends AbstractController
{
	public function action_index()
	{
	}

	public function action_plain()
	{
		return 'success';
	}
}

class MockpreController extends AbstractController
{
	protected $_pre_run = false;

	public function pre_dispatch()
	{
		$this->_pre_run = true;
	}

	public function action_index()
	{
	}

	public function action_plain()
	{
		if ($this->_pre_run)
			return 'success';
		else
			return 'fail';
	}
}
