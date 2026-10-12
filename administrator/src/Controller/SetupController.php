<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

/**
 * Async endpoints for the setup checklist card, the same two-endpoint shape the
 * notification badge uses: `card` re-renders the SINGLE render path fresh and returns
 * HTML for an in-place node swap; `state` persists the current user's open/collapsed
 * preference. Backend-only and token-checked. The card and its state need only entry to
 * Alfa, which the dispatcher already enforces (each row links to a screen that guards
 * itself); provisioning writes site configuration and is kept for administrators.
 *
 * @since  1.10.0
 */
class SetupController extends BaseController
{
    /**
     * Re-evaluate the checklist NOW and return the freshly rendered card. This is the
     * "the user asked for truth" path; it bypasses the 6h cache (and, through the
     * evaluation, reconciles the `alfa.setup` notification).
     *
     *
     * @since  1.10.0
     */
    public function card(): void
    {
        if (!Session::checkToken('get')) {
            $this->app->setHeader('status', '403 Forbidden', true);
            $this->app->sendHeaders();
            $this->app->close();
        }

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->sendHeaders();

        echo SetupHelper::renderCard(fresh: true);

        $this->app->close();
    }

    /**
     * Persist the current user's card presentation state ('open' | 'collapsed').
     *
     *
     * @since  1.10.0
     */
    public function state(): void
    {
        $this->checkToken();

        SetupHelper::setCardState($this->input->getCmd('state', 'open'));

        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();

        echo json_encode(['ok' => true]);

        $this->app->close();
    }

    /**
     * Run one check's auto-fix (e.g. create the storefront menu items) and return the
     * re-evaluated card. A failed provision answers 409 with the reason as text; the
     * page keeps its current card.
     *
     *
     * @since  1.10.0
     */
    public function provision(): void
    {
        $this->checkToken();
        $this->assertAdministrator();

        // Answers to the check's own questions (e.g. which menu to create in).
        $answers = [];

        foreach ((array) $this->input->get('answer', [], 'array') as $name => $value) {
            $answers[(string) $name] = (string) InputFilter::getInstance()->clean((string) $value, 'CMD');
        }

        $result = SetupHelper::provision($this->input->getCmd('check', ''), $answers);

        if (!$result['ok']) {
            $this->app->setHeader('status', '409 Conflict', true);
            $this->app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            $this->app->sendHeaders();

            echo $result['error'];

            $this->app->close();
        }

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->sendHeaders();

        echo SetupHelper::renderCard(fresh: true);

        $this->app->close();
    }

    /**
     * Guard: the current user administers com_alfa.
     *
     * Provisioning creates and associates Joomla site menu items (configuration of the
     * site itself, not of one shop section), so it stays with the component's
     * administrators rather than any section permission.
     *
     *
     * @since  1.10.0
     */
    private function assertAdministrator(): void
    {
        if (!$this->app->getIdentity()->authorise('core.admin', 'com_alfa')) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
