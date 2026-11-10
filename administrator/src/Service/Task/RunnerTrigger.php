<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * What the request-bound triggers share (design 2.7): the session closed before any work, and the response finished
 * first where the server can end the request before the tick (PHP-FPM, LiteSpeed's LSAPI). The triggers own nothing:
 * every one calls Runner::tick() with its budget.
 *
 * @since  2.2.0
 */
final class RunnerTrigger
{
    /**
     * The most output buffers finishResponse() flushes.
     *
     * @since  2.2.0
     */
    public const MAX_BUFFERS = 16;

    /**
     * Close the request's session, so a tick never holds the session lock a member of staff's next page waits on.
     *
     * @since  2.2.0
     */
    public static function closeSession(): void
    {
        try {
            Factory::getApplication()->getSession()->close();
        } catch (Throwable) {
            // No session to close.
        }
    }

    /**
     * Send what the request has answered and end it where the server allows: true when the client has its response
     * and the work after it delays nobody. At most MAX_BUFFERS output buffers are flushed: one that cannot be removed
     * (started without the removable flag) stops the flushing there.
     *
     * @since  2.2.0
     */
    public static function finishResponse(): bool
    {
        for ($level = 0; $level < self::MAX_BUFFERS && ob_get_level() > 0; $level++) {
            if (!@ob_end_flush()) {
                break;
            }
        }

        flush();

        if (\function_exists('fastcgi_finish_request')) {
            return fastcgi_finish_request();
        }

        if (\function_exists('litespeed_finish_request')) {
            return litespeed_finish_request();
        }

        return false;
    }

    /**
     * The tick after a response: the session closed, the response finished where it can be, then a tick with the
     * budget, or with the inline budget where the client still waits.
     *
     * @param string $trigger The trigger's name.
     * @param int $budgetMs The budget once the response is finished.
     * @param int $inlineMs The budget while the client still waits.
     *
     * @since  2.2.0
     */
    public static function afterResponse(string $trigger, int $budgetMs, int $inlineMs): void
    {
        self::closeSession();
        $finished = self::finishResponse();

        try {
            Runner::tick(trigger: $trigger, budgetMs: $finished ? $budgetMs : $inlineMs);
        } catch (Throwable $e) {
            Tasks::log(entry: 'Runner: the ' . $trigger . ' tick failed: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }
    }
}
