<?php

namespace Elkarte;

use Elkarte\Boards\BoardsManager;
use Elkarte\Elkarte\Util;
use Elkarte\Elkarte\View\TemplateLayers;
use Elkarte\Elkarte\View\Templates;
use Elkarte\Elkarte\Text\BBC\ParserWrapper;
use \Pimple\Container;

global $elk;

$elk = new Container;

/**
 * @return Errors
 */
$elk['errors'] = function ($elk) {
	return new Elkarte\Errors\Errors($elk);
};

$elk['http_req'] = function () {
	return new Elkarte\Http\HttpReq;
};

/**
 * @return Request
 */
$elk['req'] = function () {
	return new Elkarte\Http\Request;
};

/**
 * @return Elkarte\Events\Hooks
 */
$elk['hooks'] = function ($elk) {
	return new Elkarte\Events\Hooks($elk['debug']);
};

/**
 * @return \Themes\DefaultTheme\Theme
 */
$elk['theme'] = function () {
	return theme();
};

/**
 * @return TemplateLayers
 */
$elk['layers'] = function () {
	return new TemplateLayers();
};

$elk['templates'] = function ($elk) {
	return new Templates($elk['debug']);
};

/**
 * @return Cache|object
 */
$elk['cache'] = function () {
	global $cache_accelerator, $cache_enable, $cache_uid, $cache_password;

	$options = array();
	if ($cache_accelerator === 'xcache')
	{
		$options = array(
			'cache_uid' => $cache_uid,
			'cache_password' => $cache_password,
		);
	}

	return new Elkarte\Cache\Cache($cache_enable, $cache_accelerator, $options);
};

/**
 * @return Database
 */
$elk['db'] = function () use ($elk, $db_persist, $db_server, $db_user, $db_passwd, $db_port, $db_type, $db_name, $db_prefix) {
	//global $db_persist, $db_server, $db_user, $db_passwd, $db_port;
	//global $db_type, $db_name, $ssi_db_user, $ssi_db_passwd, $db_prefix;

	$db = new Elkarte\Database\Database($db_type, $elk['errors'], $elk['debug'], $elk['hooks']);

	$options = array('persist' => $db_persist, 'dont_select_db' => ELK === 'SSI', 'port' => $db_port);

	return $db->connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $options);
};

/**
 * @return BrowserDetector
 */
$elk['browser'] = function ($elk) {
	$browser = new Elkarte\Http\BrowserDetector($elk['req']);
	$browser->detectBrowser();
	return $browser;
};

/**
 * @return SiteDispatcher
 */
$elk['dispatcher'] = function () use ($elk) {
	return new Elkarte\Controller\SiteDispatcher($elk, $elk['hooks']);
};

/**
 * @return Session
 */
$elk['session'] = function ($elk) {
	return new Elkarte\Session\Session($elk['req'], $elk['hooks'], $elk['db']);
};

/**
 * @return Debug
 */
$elk['debug'] = function () {
	return new Elkarte\Debug\Debug;
};

/**
 * @return BanCheck
 */
$elk['ban_check'] = function () use ($elk) {
	return new Elkarte\Security\BanCheck($elk['http_req'], $elk['db'], $elk['errors'], $elk['hooks']);
};

/**
 * @return \BBC\ParserWrapper
 */
$elk['bbc'] = function () {
	global $modSettings;

	$bbc = new ParserWrapper;

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

/**
 * @var Util
 * @return Util
 */
$elk['text'] = function () {
	global $modSettings;

	return new Util($modSettings);
};

/**
 * @return BoardsManager
 */
$elk['boards.manager'] = function ($elk) {
	return new BoardsManager($elk['db'], $elk['cache'], $elk['hooks'], $elk['errors']);
};

return $elk;