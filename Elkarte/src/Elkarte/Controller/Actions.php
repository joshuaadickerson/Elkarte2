<?php

return [
	'attachapprove' 		=> ['Elkarte\\Attachments\\ModerateAttachmentsController', 'action_attachapprove'],
	'buddy' 				=> ['Elkarte\\Members\\MembersController', 'action_buddy'],
	'collapse' 				=> ['Elkarte\\Boards\\BoardIndexController', 'action_collapse'],
	'deletemsg' 			=> ['Elkarte\\Topics\\RemoveTopicController', 'action_deletemsg'],
	// @todo: move this to attachment action also
	'dlattach' 				=> ['Elkarte\\Attachments\\AttachmentController', 'action_index'],
	'unwatchtopic' 			=> ['Elkarte\\Notifications\\NotifyController', 'action_unwatchtopic'],
	'editpoll' 				=> ['Elkarte\\PollController', 'action_editpoll'],
	'editpoll2' 			=> ['Elkarte\\PollController', 'action_editpoll2'],
	'quickhelp' 			=> ['Elkarte\\HelpController', 'action_quickhelp'],
	'jsmodify' 				=> ['Elkarte\\PostController', 'action_jsmodify'],
	'jsoption' 				=> ['Elkarte\\ManageThemesController', 'action_jsoption'],
	'lockvoting' 			=> ['Elkarte\\Polls\\PollController', 'action_lockvoting'],
	'login' 				=> ['Elkarte\\AuthController', 'action_login'],
	'login2' 				=> ['Elkarte\\AuthController', 'action_login2'],
	'logout' 				=> ['Elkarte\\AuthController', 'action_logout'],
	'markasread' 			=> ['Elkarte\\MarkReadController', 'action_index'],
	'mergetopics' 			=> ['Elkarte\\MergeTopicsController', 'action_index'],
	'moderate' 				=> ['Elkarte\\ModerationCenterController', 'action_index'],
	'movetopic' 			=> ['Elkarte\\Topics\\MoveTopicController', 'action_movetopic'],
	'movetopic2' 			=> ['Elkarte\\Topics\\MoveTopicController', 'action_movetopic2'],
	'notify' 				=> ['Elkarte\\Notifications\\NotifyController', 'action_notify'],
	'notifyboard' 			=> ['Elkarte\\Notifications\\NotifyController', 'action_notifyboard'],
	'openidreturn' 			=> ['Elkarte\\OpenIDController', 'action_openidreturn'],
	'xrds' 					=> ['Elkarte\\OpenIDController', 'action_xrds'],
	'pm' 					=> ['Elkarte\\PersonalMessages\\PersonalMessageController', 'action_index'],
	'post2' 				=> ['Elkarte\\PostController', 'action_post2'],
	'quotefast' 			=> ['Elkarte\\PostController', 'action_quotefast'],
	'quickmod' 				=> ['Elkarte\\MessageIndexController', 'action_quickmod'],
	'quickmod2' 			=> ['Elkarte\\DisplayController', 'action_quickmod2'],
	'removetopic2' 			=> ['Elkarte\\Topics\\RemoveTopicController', 'action_removetopic2'],
	'reporttm' 				=> ['Elkarte\\EmailuserController', 'action_reporttm'],
	'restoretopic' 			=> ['Elkarte\\Topics\\RemoveTopicController', 'action_restoretopic'],
	'spellcheck' 			=> ['Elkarte\\PostController', 'action_spellcheck'],
	'splittopics' 			=> ['Elkarte\\Topics\\SplitTopicsController', 'action_splittopics'],
	'theme' 				=> ['Elkarte\\ManageThemesController', 'action_thememain'],
	'trackip' 				=> ['Elkarte\\Profile\\ProfileHistoryController', 'action_trackip'],
	'unreadreplies' 		=> ['Elkarte\\UnreadController', 'action_unreadreplies'],
	'profile' 				=> ['Elkarte\\Profile\\ProfileController', 'action_index'],
	'viewquery' 			=> ['Elkarte\\AdminDebugController', 'action_viewquery'],
	'viewadminfile' 		=> ['Elkarte\\AdminDebugController', 'action_viewadminfile'],
	'.xml' 					=> ['Elkarte\\NewsController', 'action_showfeed'],
	'xmlhttp' 				=> ['Elkarte\\XmlController', 'action_index'],
	'xmlpreview' 			=> ['Elkarte\\XmlPreviewController', 'action_index'],
];