<?php

/**
 * Handle credits, license, privacy policy, cookie policy, registration agreement / TOS, contact
 * staff (optional), sitemap (mod?), etc.
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:		BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.1 dev
 *
 */

namespace Elkarte\About;

use Elkarte\Elkarte\Controller\AbstractController;
use Elkarte\Elkarte\Controller\Action;
use Elkarte\Elkarte\DataValidator;
use Pimple\Container;
use Elkarte\Elkarte\Errors\Errors;
use Elkarte\Elkarte\Events\Hooks;
use Elkarte\Elkarte\Util;

/**
 * About Controller
 */
class AboutController extends AbstractController
{
	/** @var Container  */
	protected $elk;
	/** @var Credits  */
	protected $credits;
	/** @var Hooks  */
	protected $hooks;
	/** @var Errors  */
	protected $errors;
	/** @var Util  */
	protected $text;

	public function __construct(Container $elk, Credits $credits, Hooks $hooks, Errors $errors, Util $text)
	{
		$this->elk = $elk;
		$this->credits = $credits;

		$this->hooks = $hooks;
		$this->errors = $errors;
		$this->text = $text;
	}

    /**
     * Default action of this class.
     * Accessed with ?action=about
     */
    public function action_index()
    {
        // Add an subaction array to act accordingly
        $subActions = array(
            'credits' => array($this, 'action_credits'),
            'contact' => array($this, 'action_contact'),
            'coppa' => array($this, 'action_coppa'),
            'staff' => array($this, 'action_staff'),
        );

        // Setup the action handler
        $action = new Action();
        $subAction = $action->initialize($subActions, 'credits');

        // Call the action
        $action->dispatch($subAction);
    }

    public function pre_dispatch()
    {
        $this->_templates->load('About');
        loadLanguage('About');
    }

    /**
     * Shows the contact form for the user to fill out
     *
     * - Functionality needs to be enabled in the ACP for this to be used
     */
    public function action_contact()
    {
        global $context, $txt, $modSettings;

        // Disabled, you cannot enter.
        if (empty($modSettings['enable_contactform']) || $modSettings['enable_contactform'] === 'disabled')
            redirectexit();

        // Submitted the contact form?
        if (isset($this->_req->post->send))
        {
            $this->_session->check('post');
            validateToken('contact');

            // Can't send a lot of these in a row, no sir!
            spamProtection('contact');

            // No errors, yet.
            $context['errors'] = array();
            loadLanguage('Errors');

            // Could they get the right send topic verification code?
            require_once(ROOTDIR . '/Members/Authentication/VerificationControls.php');
            require_once(ROOTDIR . '/Members/Members.subs.php');

            // Form validation
            $validator = new DataValidator();
            $validator->sanitation_rules(array(
                'emailaddress' => 'trim',
                'contactmessage' => 'trim'
            ));
            $validator->validation_rules(array(
                'emailaddress' => 'required|valid_email',
                'contactmessage' => 'required'
            ));
            $validator->text_replacements(array(
                'emailaddress' => $txt['error_email'],
                'contactmessage' => $txt['error_message']
            ));

            // Any form errors
            if (!$validator->validate($this->_req->post))
                $context['errors'] = $validator->validation_errors();

            // Get the clean data
            $this->_req->post = new \ArrayObject($validator->validation_data(), \ArrayObject::ARRAY_AS_PROPS);

            // Trigger the verify contact event for captcha checks
            $this->_events->trigger('verify_contact', array());

            // No errors, then send the PM to the admins
            if (empty($context['errors']))
            {
                $admins = admins();
                if (!empty($admins))
                {
                    $this->elk['pm']->sendpm(array('to' => array_keys($admins), 'bcc' => array()), $txt['contact_subject'], $this->_req->post->contactmessage, false, array('id' => 0, 'name' => $this->_req->post->emailaddress, 'username' => $this->_req->post->emailaddress));
                }

                // Send the PM
                redirectexit('action=about;sa=contact;done');
            }
            else
            {
                $context['emailaddress'] = $this->_req->post->emailaddress;
                $context['contactmessage'] = $this->_req->post->contactmessage;
            }
        }

        // Show the contact done form or the form itself
        if (isset($this->_req->query->done))
            $context['sub_template'] = 'contact_form_done';
        else
        {
            $context['sub_template'] = 'contact_form';
            $context['page_title'] = $txt['admin_contact_form'];

            // Setup any contract form events, like validation
            $this->_events->trigger('setup_contact', array());
        }

        createToken('contact');
    }

    /**
     * It prepares credit and copyright information for the credits page or the Admin page.
     *
     * - Accessed by ?action=about;sa=credits
     *
     * @uses About language file
     * @uses template_credits() sub template in About.template.php,
     */
    public function action_credits()
    {
        global $context, $txt;

        $context += $this->credits->prepareCreditsData();

        $context['sub_template'] = 'credits';
        $context['robot_no_index'] = true;
        $context['page_title'] = $txt['credits'];
    }

    public function action_staff()
    {
        global $context, $modSettings;

        if (empty($modSettings['staff_page']))
            redirectexit();

        $context['sub_template'] = 'staff';

        $staff_groups = empty($modSettings['staff_groups']) ? array(1, 2) : explode(',', $modSettings['staff_groups']);
        $this->hooks->hook('staff_groups', array($staff_groups));

        require_once(ROOTDIR . '/Groups/Membergroups.subs.php');
        loadStaffList($staff_groups);

        loadMemberData($context['staff_ids']);

        foreach ($context['staff_ids'] as $member)
        {
            loadMemberContext($member);
        }
    }
}