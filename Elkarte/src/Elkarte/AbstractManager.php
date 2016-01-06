<?php

namespace Elkarte\Elkarte;

use Elkarte\Boards\BoardsContainer;
use Elkarte\Boards\BoardsManager;
use Elkarte\Elkarte;
use Elkarte\Elkarte\Cache\Cache;
use Elkarte\Elkarte\Database\Drivers\DatabaseInterface;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\Text\StringUtil;
use Elkarte\Members\MemberContainer;
use Elkarte\Members\MembersManager;
use Elkarte\Messages\MessageContainer;
use Elkarte\Messages\Messages;
use Elkarte\Topics\TopicContainer;
use Elkarte\Topics\TopicsManager;

/**
 * Class AbstractManager
 * Really the only purpose for this is to reduce the amount of boilerplate code at the tops of classes
 * @package Elkarte\Elkarte
 */
abstract class AbstractManager
{
	/** @var  Elkarte */
	protected $elk;
	/** @var  DatabaseInterface */
	protected $db;
	/** @var  Cache */
	protected $cache;
	/** @var  Hooks */
	protected $hooks;
	/** @var  Errors */
	protected $errors;
	/** @var  StringUtil */
	protected $text;
	/** @var  MembersManager */
	protected $mem_manager;
	/** @var  MemberContainer */
	protected $mem_container;
	/** @var  BoardsManager */
	protected $boards_manager;
	/** @var  BoardsContainer */
	protected $boards_container;
	/** @var  TopicsManager */
	protected $topics_manager;
	/** @var  TopicContainer */
	protected $topic_container;
	/** @var  Messages */
	protected $msg_manager;
	/** @var  MessageContainer */
	protected $msg_container;
}