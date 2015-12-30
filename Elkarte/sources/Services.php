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

$elk['nothing'] = 'nothing';