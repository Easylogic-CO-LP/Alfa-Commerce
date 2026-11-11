<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerTrigger;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;

/**
 * The staff tick (design 2.7, amended by 9.44): the kick script of an administrator page calls it after load, when
 * work was due, by POST with the session token in the body (never a GET with the token in the address, which servers
 * and proxies log), and again after a short pause for as long as the answer says work remains and the page is shown.
 * A tick that finds the work taken returns after one claim attempt. The session is closed before any work, so the
 * member of staff's next page never waits on it.
 *
 * @since  2.2.0
 */
class RunnerController extends BaseController
{
    /**
     * One staff tick of 1,000 ms; 204 with no body, its RunnerTrigger::REMAINS_HEADER saying whether work remains
     * (Runner::remains(); "0" when the tick was refused, the runner paused for an update or the site in maintenance,
     * so the page stops asking). A request that is not a POST carrying the session token in its body, or not of a
     * member of staff who manages Alfa Commerce, is answered 403.
     *
     * @since  2.2.0
     */
    public function tick(): void
    {
        $user = $this->app->getIdentity();

        if (!Session::checkToken('post') || !$user || $user->guest || !$user->authorise('core.manage', 'com_alfa')) {
            $this->answer(status: '403 Forbidden');

            return;
        }

        RunnerTrigger::closeSession();
        $tick = Runner::tick(trigger: 'staff', budgetMs: Runner::TICK_MS);

        $remains = $tick->refused === '' && Runner::remains();
        $this->app->setHeader(RunnerTrigger::REMAINS_HEADER, $remains ? '1' : '0', true);
        $this->answer(status: '204 No Content');
    }

    /**
     * End the request with a status and no body (the application closes it).
     *
     * @param string $status The status line's code and text.
     *
     * @since  2.2.0
     */
    private function answer(string $status): void
    {
        $this->app->setHeader('status', $status, true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->sendHeaders();
        $this->app->close();
    }
}
