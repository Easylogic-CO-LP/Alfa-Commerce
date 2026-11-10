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
 * The staff tick (design 2.7): the kick script of an administrator page outside Alfa Commerce calls it after load,
 * with the session token, when work was due. Each such page kicks once; a tick that finds the work taken returns
 * after one claim attempt. The session is closed before any work, so the member of staff's next page never waits on
 * it.
 *
 * @since  2.2.0
 */
class RunnerController extends BaseController
{
    /**
     * One staff tick of 1,000 ms; 204 with no body.
     *
     * @since  2.2.0
     */
    public function tick(): void
    {
        $user = $this->app->getIdentity();

        if (!Session::checkToken('get') || !$user || $user->guest || !$user->authorise('core.manage', 'com_alfa')) {
            $this->answer(status: '403 Forbidden');

            return;
        }

        RunnerTrigger::closeSession();
        Runner::tick(trigger: 'staff', budgetMs: Runner::TICK_MS);

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
