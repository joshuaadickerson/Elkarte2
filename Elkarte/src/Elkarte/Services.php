<?php

namespace Elkarte;

use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Security\BansManager;
use Elkarte\Elkarte\Theme\Context;
use Elkarte\Elkarte\Text\StringUtil;
use Elkarte\Elkarte\Theme\TemplateLayers;
use Elkarte\Elkarte\Theme\Templates;
use Elkarte\Elkarte\Text\BBC\ParserWrapper;

global $elk;

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
 * @var DatabaseInterface
 * @return DatabaseInterface
 */
$elk['db'] = function ($elk) use ($db_server, $db_user, $db_passwd, $db_port, $db_type, $db_name, $db_prefix) {
	// Fix the db type
	switch (strtolower($db_type))
	{
		case 'mysql':
			$class_name = 'MySQL';
			break;
		case 'postgresql':
			$class_name = 'PostgreSQL';
			break;
		default:
			$class_name = $db_type;
	}

	$class_name = '\\Elkarte\\Elkarte\\Database\\Drivers\\' . $class_name . '\\Database';
	$class = new $class_name($elk['errors'], $elk['debug'], $elk['hooks']);

	return $class->connect($db_server, $db_name, $db_user, $db_passwd, $db_prefix, $elk['db.options']);
};

$elk['db.options'] = function () use ($db_persist, $db_port) {
	return array(
		'persist' => $db_persist,
		'dont_select_db' => ELK === 'SSI',
		'port' => $db_port
	);
};

/**
 * @var BrowserDetector
 * @return BrowserDetector
 */
$elk['browser'] = function ($elk) {
	$browser = new Elkarte\Http\BrowserDetector($elk['req']);
	$browser->detectBrowser();
	return $browser;
};

/**
 * @var SiteDispatcher
 * @return SiteDispatcher
 */
$elk['dispatcher'] = function () use ($elk) {
	return new Elkarte\Controller\SiteDispatcher($elk, $elk['hooks']);
};

/**
 * @var Session
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
 * @return ParserWrapper
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
 * @return string
 */
$elk['action'] = function () {
	return isset($_GET['action']) ? $_GET['action'] : '';
};

/**
 * @var StringUtil
 * @return StringUtil
 */
$elk['text'] = function () {
	global $modSettings;

	return new StringUtil($modSettings);
};

$elk['context'] = function () {
	return new Context();
};

$elk['bans.manager'] = function () {
	return new BansManager();
};

$elk['ip.manager'] = function () {
	return new Elkarte\Ips\IPs();
};

return $elk;