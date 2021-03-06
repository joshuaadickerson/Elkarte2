<?php

/**
 * Handles openID verification
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This file contains code by:
 * copyright:	2012 Simple Machines Forum contributors (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

if (!defined('ELK'))
	die('No access...');

/**
 * OpenID controller.
 */
class OpenIDController extends AbstractController
{
	/**
	 * Can't say, you see, its a secret
	 * @var string
	 */
	private $_secret = '';

	/**
	 * Forward to the right action.
	 *
	 * @see AbstractController::action_index()
	 */
	public function action_index()
	{
		// We only know one thing and know it well :P
		$this->action_openidreturn();
	}

	/**
	 * Callback action handler for OpenID
	 */
	public function action_openidreturn()
	{
		global $modSettings, $context, $user_settings;

		// Is OpenID even enabled?
		if (empty($modSettings['enableOpenID']))
			$this->_errors->fatal_lang_error('no_access', false);

		// The OpenID provider did not respond with the OpenID mode? Throw an error..
		if (!isset($this->http_req->query->openid_mode))
			$this->_errors->fatal_lang_error('openid_return_no_mode', false);

		// @todo Check for error status!
		if ($this->http_req->query->openid_mode !== 'id_res')
			$this->_errors->fatal_lang_error('openid_not_resolved');

		// We'll need our subs.
		require_once(SUBSDIR . '/OpenID.subs.php');

		// This has annoying habit of removing the + from the base64 encoding.  So lets put them back.
		foreach (array('openid_assoc_handle', 'openid_invalidate_handle', 'openid_sig', 'sf') as $key)
		{
			if (isset($this->http_req->query->$key))
			{
				$this->http_req->query->$key = str_replace(' ', '+', $this->http_req->query->$key);
			}
		}

		$openID = new OpenID();

		// Did they tell us to remove any associations?
		if (!empty($this->http_req->query->openid_invalidate_handle))
			$openID->removeAssociation($this->http_req->query->openid_invalidate_handle);

		// Get the OpenID server info.
		$server_info = $openID->getServerInfo($this->http_req->query->openid_identity);

		// Get the association data.
		$assoc = $openID->getAssociation($server_info['server'], $this->http_req->query->openid_assoc_handle, true);
		if ($assoc === null)
			$this->_errors->fatal_lang_error('openid_no_assoc');

		// Verify the OpenID signature.
		if (!$this->_verify_string($assoc['secret']))
			$this->_errors->fatal_lang_error('openid_sig_invalid', 'critical');

		if (!isset($_SESSION['openid']['saved_data'][$this->http_req->query->t]))
			$this->_errors->fatal_lang_error('openid_load_data');

		$openid_uri = $_SESSION['openid']['saved_data'][$this->http_req->query->t]['openid_uri'];
		$modSettings['cookieTime'] = $_SESSION['openid']['saved_data'][$this->http_req->query->t]['cookieTime'];

		if (empty($openid_uri))
			$this->_errors->fatal_lang_error('openid_load_data');

		// Any save fields to restore?
		$openid_save_fields = isset($this->http_req->query->sf) ? unserialize(base64_decode($this->http_req->query->sf)) : array();
		$context['openid_claimed_id'] = $this->http_req->query->openid_claimed_id;

		// Is there a user with this OpenID_uri?
		$member_found = memberByOpenID($context['openid_claimed_id']);

		if (empty($member_found) && $this->http_req->getQuery('sa') === 'change_uri' && !empty($_SESSION['new_openid_uri']) && $_SESSION['new_openid_uri'] == $context['openid_claimed_id'])
		{
			// Update the member.
				updateMemberData($user_settings['id_member'], array('openid_uri' => $context['openid_claimed_id']));

			unset($_SESSION['new_openid_uri']);
			$_SESSION['openid'] = array(
				'verified' => true,
				'openid_uri' => $context['openid_claimed_id'],
			);

			// Send them back to profile.
			redirectexit('action=profile;area=authentication;updated');
		}
		elseif (empty($member_found))
		{
			// Store the received openid info for the user when returned to the registration page.
			$_SESSION['openid'] = array(
				'verified' => true,
				'openid_uri' => $context['openid_claimed_id'],
			);

			if (isset($this->http_req->query->openid_sreg_nickname))
				$_SESSION['openid']['nickname'] = $this->http_req->query->openid_sreg_nickname;
			if (isset($this->http_req->query->openid_sreg_email))
				$_SESSION['openid']['email'] = $this->http_req->query->openid_sreg_email;
			if (isset($this->http_req->query->openid_sreg_dob))
				$_SESSION['openid']['dob'] = $this->http_req->query->openid_sreg_dob;
			if (isset($this->http_req->query->openid_sreg_gender))
				$_SESSION['openid']['gender'] = $this->http_req->query->openid_sreg_gender;

			// Were we just verifying the registration state?
			if (isset($this->http_req->query->sa) && $this->http_req->query->sa === 'register2')
			{
				// Did we save some open ID fields?
				if (!empty($openid_save_fields))
				{
					foreach ($openid_save_fields as $id => $value)
						$this->http_req->post->$id = $value;
				}

				$controller = new RegisterController(new EventManager());
				$controller->pre_dispatch();
				return $controller->do_register(true);
			}
			else
				redirectexit('action=register');
		}
		elseif (isset($this->http_req->query->sa) && $this->http_req->query->sa === 'revalidate' && $user_settings['openid_uri'] == $openid_uri)
		{
			$_SESSION['openid_revalidate_time'] = time();

			// Restore the get data.
			require_once(SUBSDIR . '/Auth.subs.php');
			$_SESSION['openid']['saved_data'][$this->http_req->query->t]['get']['openid_restore_post'] = $this->http_req->query->t;
			$query_string = construct_query_string($_SESSION['openid']['saved_data'][$this->http_req->query->t]['get']);

			redirectexit($query_string);
		}
		else
		{
			$user_settings = $member_found;

			// Generate an ElkArte hash for the db to protect this account
			$user_settings['passwd'] = validateLoginPassword($this->_secret, '', $user_settings['member_name'], true);

			$tokenizer = new TokenHash();
			$user_settings['password_salt'] = $tokenizer->generate_hash(4);

				updateMemberData($user_settings['id_member'], array('passwd' => $user_settings['passwd'], 'password_salt' => $user_settings['password_salt']));

			// Cleanup on Aisle 5.
			$_SESSION['openid'] = array(
				'verified' => true,
				'openid_uri' => $context['openid_claimed_id'],
			);

			// Activation required?
			if (!checkActivation())
				return false;

			// Finally do the login.
			doLogin();
		}
	}

	/**
	 * Compares the association with the signatures received from the server
	 *
	 * @param string $raw_secret - The association stored in the database
	 */
	protected function _verify_string($raw_secret)
	{
		$this->_secret = base64_decode($raw_secret);
		$signed = explode(',', $this->http_req->query->openid_signed);
		$verify_str = '';

		foreach ($signed as $sign)
		{
			$sign_key = 'openid_' . str_replace('.', '_', $sign);
			$verify_str .= $sign . ':' . strtr($this->http_req->query->$sign_key, array('&amp;' => '&')) . "\n";
		}

		$verify_str = base64_encode(hash_hmac('sha1', $verify_str, $this->_secret, true));

		// Verify the OpenID signature.
		return $verify_str == $this->http_req->query->openid_sig;
	}

	/**
	 * Generate the XRDS data for an OpenID 2.0, YADIS discovery
	 */
	public function action_xrds()
	{
		global $scripturl, $modSettings;

		@ob_end_clean();
		if (!empty($modSettings['enableCompressedOutput']))
			ob_start('ob_gzhandler');
		else
			ob_start();

		header('Content-Type: application/xrds+xml');
		echo '<?xml version="1.0" encoding="UTF-8"?' . '>';
		// Generate the XRDS data for OpenID 2.0, YADIS discovery..
		echo '
<xrds:XRDS xmlns:xrds="xri://$xrds" xmlns="xri://$xrd*($v*2.0)">
	<XRD>
		<Service>
			<Type>http://specs.openid.net/auth/2.0/return_to</Type>
			<URI>', $scripturl, '?action=openidreturn</URI>
	</Service>
	</XRD>
</xrds:XRDS>';

	obExit(false);
	}
}