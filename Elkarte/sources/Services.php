<?php

global $elk;

$elk = new Pimple\Container;

/**
 * @return Errors
 */
$elk['errors'] = function () {
	return Errors::instance();
};

$elk['http_req'] = function () {
	return HttpReq::instance();
};

/**
 * @return Request
 */
$elk['req'] = function () {
	return Request::instance();
};

/**
 * @return Hooks
 */
$elk['hooks'] = function () {
	return Hooks::get();
};

/**
 * @return \Themes\DefaultTheme\Theme
 */
$elk['theme'] = function () {
	return theme();
};

/**
 * @return Template_Layers
 */
$elk['layers'] = function () {
	return Template_Layers::getInstance();
};

$elk['templates'] = function () {
	return Templates::getInstance();
};

/**
 * @return Cache|object
 */
$elk['cache'] = function () {
	return Cache::instance();
};

/**
 * @return Database
 */
$elk['db'] = function () {
	return call_user_func(array('Database_' . DB_TYPE, 'db'));
};

/**
 * @return Browser_Detector
 */
$elk['browser'] = function () {
	return new Browser_Detector;
};

/**
 * @return Site_Dispatcher
 */
$elk['dispatcher'] = function () use ($elk) {
	return new Site_Dispatcher($elk);
};

/**
 * @return Session
 */
$elk['session'] = function () {
	return Session::getInstance();
};

/**
 * @return Debug
 */
$elk['debug'] = function () {
	return Debug::get();
};

/**
 * @return BanCheck
 */
$elk['ban_check'] = function () use ($elk) {
	return new BanCheck($elk['http_req'], $elk['db'], $elk['errors'], $elk['hooks']);
};

/**
 * @return \BBC\ParserWrapper
 */
$elk['bbc'] = function () {
	global $modSettings;

	$bbc = \BBC\ParserWrapper::getInstance();

	// Set the default disabled BBC
	if (!empty($modSettings['disabledBBC']))
	{
		$bbc->setDisabled($modSettings['disabledBBC']);
	}

	return $bbc;
};

/**
 * @return Censor
 */
$elk['censor'] = function () {
	global $modSettings;

	return new Censor($modSettings['censor_vulgar'], $modSettings['censor_proper'], $modSettings);
};

/**
 * The current action
 * @var string
 */
$elk['action'] = function () {
	return isset($_GET['action']) ? $_GET['action'] : '';
};

return $elk;