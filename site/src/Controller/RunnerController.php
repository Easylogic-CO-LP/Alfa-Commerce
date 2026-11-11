<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\Runner;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerCronKey;
use Alfa\Component\Alfa\Administrator\Service\Task\RunnerTrigger;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\SessionManager;
use Throwable;

/**
 * The storefront's two runner endpoints (design 2.7).
 *
 * runner.kick is the guest endpoint a storefront page's kick script calls after load: POST only, no token, no input
 * read (no job, type, payload or budget is taken from the request), one working call per 5 s whoever calls, the
 * session closed before any work, 204 with no body. runner.cron is the web cron: the shop's secret key in the query
 * string, read as one string (an array or anything else is a wrong key), a wrong one refused with 403 after a
 * constant-time compare, a tick of 20 s, 204. A web-cron call leaves no session and sets no cookie: Joomla starts one
 * for every request before any component runs, so the session a caller without one was given is removed again.
 *
 * @since  2.2.0
 */
class RunnerController extends BaseController
{
    /**
     * The guest endpoint.
     *
     * @since  2.2.0
     */
    public function kick(): void
    {
        if (strtoupper((string) $this->input->getMethod()) !== 'POST') {
            $this->answer(status: '405 Method Not Allowed', allow: 'POST');

            return;
        }

        RunnerTrigger::closeSession();

        if (Runner::openGate(gate: Runner::GATE_ENDPOINT, seconds: Runner::ENDPOINT_GAP_SECONDS)) {
            Runner::tick(trigger: 'frontend', budgetMs: Runner::TICK_MS);
        }

        $this->answer(status: '204 No Content');
    }

    /**
     * The web cron.
     *
     * @since  2.2.0
     */
    public function cron(): void
    {
        $session = self::freshSession();
        // The key is taken raw and must be one string: an array, an object or a value too long to be a key is a
        // wrong key, refused as one.
        $key = $this->input->get('key', null, 'raw');

        if (!\is_string($key) || \strlen($key) > RunnerCronKey::MAX_LENGTH || !RunnerCronKey::matches(key: $key)) {
            $this->forget(session: $session);
            $this->answer(status: '403 Forbidden');

            return;
        }

        RunnerTrigger::closeSession();
        Runner::tick(trigger: 'webcron', budgetMs: Runner::WEBCRON_MS);
        $this->forget(session: $session);

        $this->answer(status: '204 No Content');
    }

    /**
     * The id of the session this request started for a caller who brought none, or '' when the caller has one of
     * its own (a staff member's browser keeps its session). Asked before the session is closed.
     *
     * @since  2.2.0
     */
    private static function freshSession(): string
    {
        try {
            $session = Factory::getApplication()->getSession();

            return $session->isActive() && $session->isNew() ? (string) $session->getId() : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Remove the session this request started (freshSession()) and its cookie, so a web-cron call leaves neither.
     *
     * @param string $session Its id, '' for none.
     *
     * @since  2.2.0
     */
    private function forget(string $session): void
    {
        if ($session === '') {
            return;
        }

        try {
            RunnerTrigger::closeSession();
            Factory::getContainer()->get(SessionManager::class)->destroySession($session);
        } catch (Throwable) {
            // Joomla's own session clean-up removes it in time.
        }

        if (!headers_sent()) {
            header_remove('Set-Cookie');
        }
    }

    /**
     * End the request with a status and no body (the application closes it).
     *
     * @param string $status The status line's code and text.
     * @param string $allow The methods allowed, for a 405.
     *
     * @since  2.2.0
     */
    private function answer(string $status, string $allow = ''): void
    {
        $this->app->setHeader('status', $status, true);
        $this->app->setHeader('Cache-Control', 'no-store', true);

        if ($allow !== '') {
            $this->app->setHeader('Allow', $allow, true);
        }

        $this->app->sendHeaders();
        $this->app->close();
    }
}
