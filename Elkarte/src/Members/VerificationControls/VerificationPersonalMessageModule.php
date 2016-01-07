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

use Elkarte\Elkarte\ModuleInterface;

class VerificationPersonalMessageModule implements ModuleInterface
{
	/**
	 * {@inheritdoc }
	 */
	public static function hooks(EventManager $eventsManager)
	{
		global $user_info, $modSettings;

		if (!$user_info['is_admin'] && !empty($modSettings['pm_posts_verification']) && $user_info['posts'] < $modSettings['pm_posts_verification'])
		{


			return array(
				array('prepare_send_context', array('Verification_PersonalMessage_Module', 'prepare_send_context'), array()),
				array('before_sending', array('Verification_PersonalMessage_Module', 'before_sending'), array('post_errors')),
			);
		}
		else
			return array();
	}

	/**
	 * Prepare $context for the PM page.
	 */
	public function prepare_send_context()
	{
		global $context;

		// Verification control needed for this PM?
		$context['require_verification'] = true;

		$verificationOptions = array(
			'id' => 'pm',
		);
		$context['require_verification'] = create_control_verification($verificationOptions);
		$context['visual_verification_id'] = $verificationOptions['id'];
	}

	/**
	 * Checks the user passed the verifications on the PM page.
	 * @param \ErrorContext $post_errors
	 */
	public function before_sending($post_errors)
	{
		global $context;

		if (isset($_REQUEST['xml']))
			return;

		// Wrong verification code?
		$verificationOptions = array(
			'id' => 'pm',
		);
		$context['require_verification'] = create_control_verification($verificationOptions, true);

		if (is_array($context['require_verification']))
		{
			foreach ($context['require_verification'] as $error)
				$post_errors->addError($error);
		}
	}
}