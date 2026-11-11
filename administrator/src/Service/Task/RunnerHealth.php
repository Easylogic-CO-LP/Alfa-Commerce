<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Throwable;

/**
 * The health notice of the background work: standing while due work, a single stranded after its request (pending
 * and never run) or a due job, has waited more than a day and no tick has run for a day (runner.last_tick: a tick
 * that ran is a tick, whether its job finished or not); retired once either is no longer so.
 *
 * A shop with no cron runs its background work when its pages are visited and its administrator is used. The notice
 * says when neither happened for a day, and names the cron command and the web-cron address. It is checked by the
 * bell's refresh and by every tick, at most once every few minutes. Failed and held work keeps its own notice
 * ({@see TaskAttention}). A long job that ticks advance but cannot finish within a day raises nothing.
 *
 * @since  2.2.0
 */
final class RunnerHealth
{
    /**
     * The notice's dedup key.
     *
     * @since  2.2.0
     */
    public const KEY = 'alfa.runner.health';

    /**
     * How long due work waits before the notice stands, in hours.
     *
     * @since  2.2.0
     */
    public const THRESHOLD_HOURS = 24;

    /**
     * Where the notice leads: Tools > Tasks, which shows the cron command and the web-cron address.
     *
     * @since  2.2.0
     */
    public const URL = 'index.php?option=com_alfa&view=tools#tasks';

    /**
     * How often the check runs at most, in minutes.
     *
     * @since  2.2.0
     */
    private const CHECK_MINUTES = 10;

    /**
     * The throttled check, from the bell and from every tick. A failure is logged and never reaches the caller.
     *
     * @since  2.2.0
     */
    public static function sync(): void
    {
        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.tasks',
                    'lifetime' => self::CHECK_MINUTES,
                    'caching' => true,
                ]);

            $cache->get([self::class, 'check'], [], 'health');
        } catch (Throwable) {
            // Cache unavailable: just do it; correct, only less throttled.
            self::check();
        }
    }

    /**
     * Raise, update or retire the notice to match the work that waits.
     *
     * @return bool Always true, for the cache callback.
     *
     * @since  2.2.0
     */
    public static function check(): bool
    {
        try {
            $waiting = self::waiting();
            $lastRun = self::lastRun();

            if ($waiting['count'] === 0 || ($lastRun !== null && $lastRun['recent'])) {
                if (NotificationHelper::isOpen(dedupKey: self::KEY)) {
                    NotificationHelper::clear(dedupKey: self::KEY);
                }

                return true;
            }

            NotificationHelper::push(
                self::KEY,
                NotificationText::of('COM_ALFA_NOTIFY_RUNNER_HEALTH_TITLE'),
                [
                    'group' => NotificationHelper::GROUP_GENERAL,
                    'severity' => 'warning',
                    // Times said in the reader's zone (UX-30).
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_RUNNER_HEALTH_MSG', NotificationText::date($waiting['since']), $waiting['count'], NotificationText::date($lastRun['at'] ?? $waiting['since'])),
                    'dismissible' => false,
                    'constant' => !NotificationHelper::isOpen(dedupKey: self::KEY),
                    'url' => self::URL,
                    'view' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.tools', 'asset' => 'com_alfa'],
                ],
            );
        } catch (Throwable $e) {
            Tasks::log(entry: 'Tasks: the background work notice could not be brought up to date: ' . $e::class . ': ' . $e->getMessage(), priority: Log::WARNING);
        }

        return true;
    }

    /**
     * The last tick that ran (runner.last_tick), when there is one: its time (UTC, said in the reader's zone where it is
     * read) and whether it ran within the threshold.
     *
     * @return array{at: string, recent: bool}|null
     *
     * @since  2.2.0
     */
    public static function lastRun(): ?array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $name = Runner::META_LAST_TICK;
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = :name')
                ->bind(':name', $name),
        )->loadResult();
        $tick = \is_string($row) ? json_decode($row, true) : null;
        $at = \is_array($tick) ? (string) ($tick['at'] ?? '') : '';

        if ($at === '') {
            return null;
        }

        $recent = (int) $db->setQuery('SELECT ' . $db->quote($at) . ' >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . self::THRESHOLD_HOURS . ' HOUR)')->loadResult() === 1;

        return ['at' => substr($at, 0, 19), 'recent' => $recent];
    }

    /**
     * The due work that has waited more than the threshold: how much, and since when (UTC, said in the reader's zone
     * where it is read).
     *
     * @return array{count: int, since: string}
     *
     * @since  2.2.0
     */
    public static function waiting(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $before = 'DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ' . self::THRESHOLD_HOURS . ' HOUR)';
        $row = $db->setQuery(
            'SELECT COUNT(*) AS n, MIN(' . $db->quoteName('waited_from') . ') AS since FROM ('
            . 'SELECT ' . $db->quoteName('run_after') . ' AS waited_from FROM ' . $db->quoteName(Tasks::TABLE)
            . ' WHERE ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING) . ' AND ' . $db->quoteName('run_after') . ' <= ' . $before
            . ' AND (' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB)
            . ' OR (' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE) . ' AND ' . $db->quoteName('failures') . ' = 0))'
            . ' UNION ALL SELECT ' . $db->quoteName('lease_until') . ' FROM ' . $db->quoteName(Tasks::TABLE)
            . ' WHERE ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' AND ' . $db->quoteName('lease_until') . ' <= ' . $before
            . ') AS ' . $db->quoteName('waiting'),
        )->loadAssoc() ?: [];
        $count = (int) ($row['n'] ?? 0);

        return ['count' => $count, 'since' => $count > 0 ? substr((string) $row['since'], 0, 19) : ''];
    }
}
