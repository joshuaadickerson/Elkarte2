<?php

/**
 * 
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\Members\VerificationControls;

class VerificationDisplayModule implements ModuleInterface
{
	/**
	 * {@inheritdoc }
	 */
	public static function hooks(EventManager $eventsManager)
	{
		global $user_info, $modSettings;

		if (!$user_info['is_admin'] && !$user_info['is_moderator'] && !empty($modSettings['posts_require_captcha']) && ($user_info['posts'] < $modSettings['posts_require_captcha'] || ($user_info['is_guest'] && $modSettings['posts_require_captcha'] == -1)))
		{
			return array(
				array('topicinfo', array('Verification_Display_Module', 'topicinfo'), array()),
			);
		}
		else
			return array();
	}

	/**
	 * Prepare $context for the quick reply.
	 */
	public function topicinfo()
	{
		global $context;

		// Do we need to show the visual verification image?

		$verificationOptions = array(
			'id' => 'post',
		);
		$context['require_verification'] = create_control_verification($verificationOptions);
		$context['visual_verification_id'] = $verificationOptions['id'];
	}
}