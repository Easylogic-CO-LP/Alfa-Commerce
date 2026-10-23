<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Notifications\NotificationPushedEvent;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Closure;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * Backend notification store for com_alfa: a single, generic place that any code
 * pushes into and the admin UI reads back from. It knows nothing about WHAT it
 * stores: a new order, low stock, the integrity state ({@see SyncHelper}), or a
 * future producer all call {@see self::push()} the same way.
 *
 * The helper writes its rows itself ({@see self::write()}). A notification core
 * raises is not an administrator's save, so it never passes through an admin record
 * model and no generic save event fires for it; plugins that react to notifications
 * listen to {@see NotificationPushedEvent} (onAlfaNotificationPushed), which push()
 * dispatches once the row is written and no transaction of the caller's is open. The
 * payment ledger, while it holds an order's lock, hands its own pushes to
 * {@see PostCommit}: they are written once the lock is released, and a rollback
 * discards them.
 *
 * Dedup is by `dedup_key` (pushing the same key replaces the row). Lifecycle is told
 * entirely by timestamps:
 *   - active   : `dismissed IS NULL` - shown in the badge + quick panel.
 *   - history  : `dismissed` set - kept until `expires`, shown in the history view.
 *   - gone     : `expires <= now` - no list, count or producer probe returns it and no
 *                push reuses it; the row itself is deleted by {@see self::purge()}, at
 *                most once an hour, from the bell's background refresh
 *                ({@see self::purgeThrottled()}).
 *
 * Dismissing/clearing ARCHIVES (sets `dismissed` + an `expires` retention window,
 * default {@see self::DEFAULT_HISTORY_DAYS}) rather than hard-deleting, so a history
 * of what was shown survives. Non-dismissible rows can't be hidden by the user.
 *
 * Every page with the bell pays for it, so the bell reads what it shows, through
 * indexes: the badge and the heading are one aggregate count (idx_active_counts), the
 * panel loads its first {@see self::PANEL_LIMIT} rows (idx_active_order), and who may
 * see what is decided once per distinct view rule (idx_view_access), not once per row.
 *
 * @since  1.0.0
 */
class NotificationHelper
{
    /**
     * Notifications table (the single read/write target).
     *
     * @var string
     * @since  1.0.0
     */
    private const TABLE = '#__alfa_notifications';

    /**
     * How many notifications the bell's panel lists (on the dashboard and every list):
     * the most urgent and newest first. The badge and the panel heading count all of
     * them; the rest are one click away in the Notifications view, which pages them (the
     * panel's "Show all notifications" link).
     *
     * @var int
     * @since  2.1.0
     */
    public const PANEL_LIMIT = 10;

    /**
     * How often, at most, expired rows are deleted ({@see self::purgeThrottled()}).
     * Reads leave them out whatever their age, so this only bounds the table's size.
     *
     * @var int
     * @since  2.1.0
     */
    private const PURGE_MINUTES = 60;

    /**
     * Severity ordering (worst last) for sorting and the badge colour.
     *
     * @var array<string, int>
     * @since  1.0.0
     */
    private const SEVERITY_RANK = ['success' => 0, 'info' => 1, 'warning' => 2, 'danger' => 3];

    /**
     * Default days a dismissed notification is kept as history before purge.
     *
     * @var int
     * @since  1.0.0
     */
    private const DEFAULT_HISTORY_DAYS = 7;

    /**
     * The permission that shows the bell, its panel and the history screen.
     *
     * @var string
     * @since  2.0.0
     */
    public const VIEW_ACTION = 'alfa.notifications.view';

    /**
     * The permission that marks a notification read or dismisses it. Both change the
     * row for every administrator, not only for the one who clicks.
     *
     * @var string
     * @since  2.0.0
     */
    public const STATE_ACTION = 'alfa.notifications.edit.state';

    /**
     * Create or replace a notification, deduped by $dedupKey. Re-pushing an existing
     * key updates its content but preserves `created`/`readed`/`dismissed`, so a
     * still-true condition never re-nags an already-seen row.
     *
     * The row is written at once, by the helper itself ({@see self::write()}), and then
     * onAlfaNotificationPushed is dispatched ({@see NotificationPushedEvent}): at once with
     * no transaction open, else once the outermost one has committed, and never when a
     * rollback discarded the row ({@see PostCommit}). The payment ledger, while it holds
     * an order's lock, hands its own pushes to PostCommit, so they are written once the
     * lock is released. First pushes of one key at once land on one row: each later one is
     * a push of the row the first wrote. A failure to write reaches the caller, as it did
     * before 2.1.1 (each producer decides whether its work stands without the notice); a
     * listener's failure never does.
     *
     * @param string $dedupKey Stable identity, e.g. "order:123" or "alfa.integrity".
     * @param string $title Short headline.
     * @param array $options group|severity|message|url|dismissible(bool)|expires(sql)|
     *                       constant(bool, re-alert on re-push: clears read state so a
     *                       persistent condition like integrity resurfaces each cycle)|
     *                       view(['action'=>..,'asset'=>..]: who may SEE it; null = all managers)|
     *                       link(['action'=>..,'asset'=>..]: who may use the LINK; null = anyone who sees it).
     *
     *
     * @since  1.0.0
     */
    public static function push(string $dedupKey, string $title, array $options = []): void
    {
        $severity = \in_array($options['severity'] ?? '', ['success', 'info', 'warning', 'danger'], true)
            ? $options['severity']
            : 'info';

        // An expired row under this key is gone: the key raised again is a new notice,
        // never an update of the dead row (which would keep its old dismissed/expires).
        $existingId = self::liveIdForKey(dedupKey: $dedupKey);

        $data = [
            'dedup_key' => $dedupKey,
            'notify_group' => (string) ($options['group'] ?? Text::_('COM_ALFA_NOTIFY_GROUP_GENERAL')),
            'severity' => $severity,
            'title' => $title,
            'message' => (string) ($options['message'] ?? ''),
            'url' => self::safeUrl((string) ($options['url'] ?? '')),
            'dismissible' => (($options['dismissible'] ?? true) ? 1 : 0),
            'view_access' => $options['view']['action'] ?? null,
            'view_access_asset' => $options['view']['asset'] ?? null,
            'url_access' => $options['link']['action'] ?? null,
            'url_access_asset' => $options['link']['asset'] ?? null,
            'expires' => $options['expires'] ?? null,
        ];

        // A constant re-alert (e.g. integrity, while the condition holds) resurfaces as
        // unread and reflects the latest check.
        [$id, $change] = self::write(id: $existingId, data: $data, constant: !empty($options['constant']));

        // Plugins hear it with no transaction of the caller's open: at once, or once the outermost one has committed
        // (no listener runs under the caller's locks), and never when a rollback discarded the row.
        PostCommit::defer(
            db: self::driver(),
            label: 'NotificationHelper: ' . NotificationPushedEvent::NAME . ' of ' . $dedupKey,
            work: static function () use ($id, $data, $change): void {
                self::announce(id: $id, data: $data, change: $change);
            },
        );
    }

    /**
     * Tell plugins that a notification was written: onAlfaNotificationPushed, on the
     * container's dispatcher (the application's), with the `alfa` plugin group loaded.
     * Each listener runs on its own: a failure is logged with the listener's name and
     * never reaches the push, and the next listener still hears it. With no application
     * no plugin can be loaded (Joomla reads the plugin list for a user); the event then
     * reaches only the listeners already registered on that dispatcher, and one DEBUG
     * line says so.
     *
     * @param int $id The row written.
     * @param array $data The columns written (as {@see self::push()} assembled them).
     * @param string $change What the push did (NotificationPushedEvent::CHANGE_*).
     *
     *
     * @since  2.1.1
     */
    private static function announce(int $id, array $data, string $change): void
    {
        $key = (string) $data['dedup_key'];

        try {
            $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);

            if (self::hasApplication()) {
                PluginHelper::importPlugin(type: 'alfa', plugin: null, autocreate: true, dispatcher: $dispatcher);
            } else {
                self::log(entry: 'NotificationHelper: no application, so no plugin is loaded for '
                    . NotificationPushedEvent::NAME . " of '{$key}'; only listeners already registered hear it.", priority: Log::DEBUG);
            }

            $event = new NotificationPushedEvent(NotificationPushedEvent::NAME, [
                'id' => $id,
                'dedupKey' => $key,
                'group' => (string) $data['notify_group'],
                'severity' => (string) $data['severity'],
                'title' => (string) $data['title'],
                'message' => (string) $data['message'],
                'url' => (string) $data['url'],
                'change' => $change,
            ]);
            $listeners = $dispatcher->getListeners(NotificationPushedEvent::NAME);
        } catch (Throwable $e) {
            self::log(entry: 'NotificationHelper: ' . NotificationPushedEvent::NAME . " of '{$key}' could not be dispatched: "
                . $e::class . ': ' . $e->getMessage(), priority: Log::ERROR);

            return;
        }

        foreach ($listeners as $listener) {
            if ($event->isStopped()) {
                break;
            }

            try {
                $listener($event);
            } catch (Throwable $e) {
                self::log(entry: 'NotificationHelper: ' . self::listenerName(listener: $listener) . ' failed on '
                    . NotificationPushedEvent::NAME . " of '{$key}' (#{$id}): " . $e::class . ': ' . $e->getMessage()
                    . ' (' . $e->getFile() . ':' . $e->getLine() . ')', priority: Log::ERROR);
            }
        }
    }

    /**
     * A listener's name for the log: the class and method it runs.
     *
     * @param callable $listener The listener as the dispatcher holds it.
     *
     *
     * @since  2.1.1
     */
    private static function listenerName(callable $listener): string
    {
        if (\is_array($listener)) {
            return (\is_object($listener[0]) ? $listener[0]::class : (string) $listener[0]) . '::' . (string) $listener[1];
        }

        if ($listener instanceof Closure) {
            $owner = (new ReflectionFunction($listener))->getClosureThis();

            return $owner === null ? 'a closure listener' : $owner::class . ' (closure)';
        }

        if (\is_string($listener)) {
            return $listener;
        }

        return \is_object($listener) ? $listener::class : 'a listener';
    }

    /**
     * Whether an application runs this request (the web, the Joomla console, the scheduler).
     *
     *
     * @since  2.1.1
     */
    private static function hasApplication(): bool
    {
        try {
            Factory::getApplication();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Write one line of the component's log; a log that cannot be written changes nothing. With no application,
     * Joomla's text logger cannot ask one for the log folder, so the line goes to the same file through a logger
     * given the folder from the site configuration.
     *
     * @param string $entry The line.
     * @param int $priority The Log priority.
     *
     *
     * @since  2.1.1
     */
    private static function log(string $entry, int $priority): void
    {
        try {
            if (self::hasApplication()) {
                AlfaLog::register();
            } else {
                Log::addLogger(
                    [
                        'text_file' => AlfaLog::categories()['com_alfa'],
                        'text_file_path' => (string) Factory::getContainer()->get('config')->get('log_path', JPATH_ADMINISTRATOR . '/logs'),
                    ],
                    Log::ALL,
                    ['com_alfa'],
                );
            }

            Log::add(entry: $entry, priority: $priority, category: 'com_alfa');
        } catch (Throwable) {
            // An unwritable log changes nothing that already happened.
        }
    }

    /**
     * Mark a notification read, recording who read it.
     *
     * @param int $id Notification id.
     * @param int $userId The reading admin's id (0 = unknown).
     *
     *
     * @since  1.0.0
     */
    public static function markRead(int $id, int $userId = 0): void
    {
        $db = self::db();
        $row = (object) [
            'id' => $id,
            'readed' => Factory::getDate()->toSql(),
            'readed_by' => $userId > 0 ? $userId : null,
        ];

        $db->updateObject(self::TABLE, $row, 'id');
    }

    /**
     * Dismiss a notification -> ARCHIVE it to history (set `dismissed` now, `expires`
     * a retention window ahead). Only affects dismissible, still-active rows.
     *
     * @param int $id Notification id.
     * @param int|null $historyDays Days to keep as history (null = default).
     *
     *
     * @since  1.0.0
     */
    public static function dismiss(int $id, ?int $historyDays = null): void
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('dismissed') . ' = :now')
            ->set($db->quoteName('expires') . ' = :exp')
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('dismissible') . ' = 1')
            ->where($db->quoteName('dismissed') . ' IS NULL')
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':exp', $exp, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        $now = Factory::getDate()->toSql();
        $exp = self::historyExpiry(historyDays: $historyDays);
        $query->where(self::unexpired(now: $now));

        $db->setQuery($query)->execute();
    }

    /**
     * Remove a notification by key. By default ARCHIVES it (like {@see self::dismiss})
     * for history; with $hard = true it is deleted outright, used for fluctuating
     * live states (e.g. integrity) that shouldn't spam history.
     *
     * @param string $dedupKey The key passed to {@see self::push()}.
     * @param bool $hard True = delete; false = archive to history.
     * @param int|null $historyDays Retention when archiving (null = default).
     *
     *
     * @since  1.0.0
     */
    public static function clear(string $dedupKey, bool $hard = false, ?int $historyDays = null): void
    {
        $db = self::db();

        if ($hard) {
            $query = $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('dedup_key') . ' = :key')
                ->bind(':key', $dedupKey, ParameterType::STRING);

            $db->setQuery($query)->execute();

            return;
        }

        $now = Factory::getDate()->toSql();
        $exp = self::historyExpiry(historyDays: $historyDays);
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('dismissed') . ' = :now')
            ->set($db->quoteName('expires') . ' = :exp')
            ->where($db->quoteName('dedup_key') . ' = :key')
            ->where($db->quoteName('dismissed') . ' IS NULL')
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':exp', $exp, ParameterType::STRING)
            ->bind(':key', $dedupKey, ParameterType::STRING);
        $query->where(self::unexpired(now: $now));

        $db->setQuery($query)->execute();
    }

    /**
     * Whether ANY row currently carries a dedup key: the cheap probe a producer runs
     * BEFORE {@see self::clear()}, so a condition that was never raised costs one point
     * read on the `dedup_key` unique index instead of a write on every evaluation.
     *
     * Archived (dismissed) rows count: for a hard clear they are just as much
     * "something to remove", and for a producer that re-raises a fluctuating state they
     * are exactly what must go: {@see self::push()} updates a dismissed row in place
     * and never un-dismisses it. An expired row does not count: it is gone.
     *
     * @param string $dedupKey The key passed to {@see self::push()}.
     *
     * @return bool True when a row carries the key.
     *
     * @since  2.0.0
     */
    public static function exists(string $dedupKey): bool
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('dedup_key') . ' = :key')
            ->bind(':key', $dedupKey, ParameterType::STRING);
        $query->where(self::unexpired(now: Factory::getDate()->toSql()));

        return (bool) $db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * Whether a notice with this key is standing: raised and not dismissed or cleared.
     *
     * {@see self::exists()} answers whether the row is there at all, which stays true for
     * an archived notice; a producer deciding whether a warning is still being shown
     * needs this one.
     *
     * @param string $dedupKey The producer's key.
     *
     *
     * @since  2.0.0
     */
    public static function isOpen(string $dedupKey): bool
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('dedup_key') . ' = :key')
            ->where($db->quoteName('dismissed') . ' IS NULL')
            ->bind(':key', $dedupKey, ParameterType::STRING);
        $query->where(self::unexpired(now: Factory::getDate()->toSql()));

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Every dedup key stored under a namespace prefix: how a producer that owns a
     * key family (e.g. "subscription:") finds the rows IT raised, so it can retire
     * the ones whose subject is gone. Storage only: the caller decides which keys
     * still mean something, because only it knows its own key grammar.
     *
     * Archived (dismissed) rows are included: a key worth retiring is worth
     * retiring whether or not a merchant already waved it away.
     *
     * @param string $prefix Key namespace, e.g. "subscription:". Empty matches nothing.
     *
     * @return string[] The matching dedup keys.
     *
     * @since  2.0.0
     */
    public static function keys(string $prefix): array
    {
        if ($prefix === '') {
            return [];
        }

        // The prefix is a literal, never a pattern: neutralise LIKE wildcards so a
        // key namespace containing % or _ cannot widen the match.
        $pattern = addcslashes($prefix, '%_\\') . '%';

        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName('dedup_key'))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('dedup_key') . ' LIKE :prefix')
            ->bind(':prefix', $pattern, ParameterType::STRING);
        $query->where(self::unexpired(now: Factory::getDate()->toSql()));

        return $db->setQuery($query)->loadColumn() ?: [];
    }

    /**
     * Badge summary: count of UNREAD, still-active notifications the current user may
     * see + the highest severity among them (drives the badge colour).
     *
     * @return array{count: int, severity: string}
     *
     * @since  1.0.0
     */
    public static function summary(): array
    {
        $counts = self::activeCounts(pairs: self::visiblePairs(user: Factory::getApplication()->getIdentity()));

        return ['count' => $counts['unread'], 'severity' => $counts['severity']];
    }

    /**
     * The condition that keeps the rows that have not expired. An expired row is gone
     * whether or not {@see self::purge()} has deleted it yet, so every read applies this.
     *
     * @param string $now The current time, SQL format (UTC).
     * @param string $column The expires column, qualified as the query names it.
     *
     *
     * @since  2.1.0
     */
    public static function unexpired(string $now, string $column = 'expires'): string
    {
        $db = self::db();

        return '(' . $db->quoteName($column) . ' IS NULL OR ' . $db->quoteName($column) . ' > ' . $db->quote($now) . ')';
    }

    /**
     * The view rules a user may see, among those stored: null when the user may see every
     * one of them (nothing to filter), else the visible ones, possibly none. One read of
     * the distinct rules (idx_view_access) and one {@see self::canSee()} per rule, so the
     * cost follows the handful of rules producers declare, not the number of rows.
     *
     * Each rule is the stored pair as it is: [view_access, view_access_asset], either
     * possibly null.
     *
     * @param mixed $user A user object, or null for the current identity.
     *
     * @return array<int, array{0: string|null, 1: string|null}>|null
     *
     * @since  2.1.0
     */
    public static function visiblePairs($user = null): ?array
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('view_access') . ', ' . $db->quoteName('view_access_asset'))
            ->from($db->quoteName(self::TABLE));

        $visible = [];
        $all = true;

        foreach ((array) $db->setQuery($query)->loadRowList() as [$action, $asset]) {
            if (self::canSee(notification: (object) ['view_access' => $action, 'view_access_asset' => $asset], user: $user)) {
                $visible[] = [$action, $asset];
            } else {
                $all = false;
            }
        }

        return $all ? null : $visible;
    }

    /**
     * The SQL condition that keeps the rows whose view rule is one of $pairs (from
     * {@see self::visiblePairs()}, never empty here): the stored values compared as they
     * are, a null as IS NULL.
     *
     * @param array<int, array{0: string|null, 1: string|null}> $pairs The visible rules.
     * @param string $alias The table alias the query uses, '' for none.
     *
     *
     * @since  2.1.0
     */
    public static function visibilityCondition(array $pairs, string $alias = ''): string
    {
        $db = self::db();
        $prefix = $alias !== '' ? $alias . '.' : '';
        $same = static fn (string $column, ?string $value): string => $value === null
            ? $db->quoteName($prefix . $column) . ' IS NULL'
            : $db->quoteName($prefix . $column) . ' = ' . $db->quote($value);
        $any = [];

        foreach ($pairs as [$action, $asset]) {
            $any[] = '(' . $same('view_access', $action) . ' AND ' . $same('view_access_asset', $asset) . ')';
        }

        return '(' . implode(' OR ', $any) . ')';
    }

    /**
     * Counts of the active, unexpired notifications a set of view rules shows, in ONE
     * aggregate query: all of them (the panel heading), the unread ones (the badge) and
     * the worst severity among the unread (the badge colour, 'info' when none is unread).
     *
     * @param array<int, array{0: string|null, 1: string|null}>|null $pairs The visible rules ({@see self::visiblePairs()}).
     *
     * @return array{total: int, unread: int, severity: string}
     *
     * @since  2.1.0
     */
    private static function activeCounts(?array $pairs): array
    {
        if ($pairs === []) {
            return ['total' => 0, 'unread' => 0, 'severity' => 'info'];
        }

        $db = self::db();
        $rank = 'CASE ' . $db->quoteName('severity');

        foreach (self::SEVERITY_RANK as $severity => $value) {
            $rank .= ' WHEN ' . $db->quote($severity) . ' THEN ' . (int) $value;
        }

        // An unknown severity ranks like success, as SEVERITY_RANK's lookups default.
        $rank .= ' ELSE 0 END';
        $unread = $db->quoteName('readed') . ' IS NULL';

        $query = $db->getQuery(true)
            ->select('COUNT(*) AS ' . $db->quoteName('total'))
            ->select('COALESCE(SUM(CASE WHEN ' . $unread . ' THEN 1 ELSE 0 END), 0) AS ' . $db->quoteName('unread'))
            ->select('MAX(CASE WHEN ' . $unread . ' THEN ' . $rank . ' END) AS ' . $db->quoteName('worst'))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('dismissed') . ' IS NULL');
        $query->where(self::unexpired(now: Factory::getDate()->toSql()));

        if ($pairs !== null) {
            $query->where(self::visibilityCondition(pairs: $pairs));
        }

        $row = $db->setQuery($query)->loadObject();
        $worst = $row->worst ?? null;

        return [
            'total' => (int) ($row->total ?? 0),
            'unread' => (int) ($row->unread ?? 0),
            'severity' => $worst === null ? 'info' : (string) array_search((int) $worst, self::SEVERITY_RANK, true),
        ];
    }

    /**
     * Whether the given user (default: current) may SEE a notification: true when it
     * declares no view access, or the user is authorised for it.
     *
     * @param object $notification Row carrying `view_access` / `view_access_asset`.
     * @param mixed $user A user object, or null for the current identity.
     *
     *
     * @since  1.0.0
     */
    public static function canSee(object $notification, $user = null): bool
    {
        return self::authorised(
            action: (string) ($notification->view_access ?? ''),
            asset: (string) ($notification->view_access_asset ?? ''),
            user: $user,
        );
    }

    /**
     * Whether the given user (default: current) may follow a notification's LINK. If
     * false, the notification is still shown, just without its clickable target.
     *
     * @param object $notification Row carrying `url_access` / `url_access_asset`.
     * @param mixed $user A user object, or null for the current identity.
     *
     *
     * @since  1.0.0
     */
    public static function canUseLink(object $notification, $user = null): bool
    {
        return self::authorised(
            action: (string) ($notification->url_access ?? ''),
            asset: (string) ($notification->url_access_asset ?? ''),
            user: $user,
        );
    }

    /**
     * Whether the given user (default: current) may see the notification centre at
     * all: the bell, its panel and the history screen. Per-row view access still
     * applies inside it ({@see self::canSee()}).
     *
     * @param mixed $user A user object, or null for the current identity.
     *
     *
     * @since  2.0.0
     */
    public static function canView($user = null): bool
    {
        return self::authorised(action: self::VIEW_ACTION, asset: 'com_alfa', user: $user);
    }

    /**
     * Whether the given user (default: current) may mark notifications read and
     * dismiss them. View is required too: a control the user cannot reach is no grant.
     *
     * @param mixed $user A user object, or null for the current identity.
     *
     *
     * @since  2.0.0
     */
    public static function canChangeState($user = null): bool
    {
        return self::canView(user: $user)
            && self::authorised(action: self::STATE_ACTION, asset: 'com_alfa', user: $user);
    }

    /**
     * One notification by id, with the columns an access decision needs, or null when
     * there is no such row.
     *
     * @param int $id The notification id.
     *
     *
     * @since  2.0.0
     */
    public static function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'view_access', 'view_access_asset', 'dismissible', 'dismissed', 'readed']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * Normalise + safety-check a URL for an href. Decodes HTML entities first so the
     * value is the single canonical raw form (e.g. `&amp;` -> `&`); call it on store
     * (so the DB holds clean URLs) AND on output (so legacy `&amp;` rows are fixed and
     * never double-encoded). Returns the URL only for safe schemes: relative, or
     * absolute http/https; anything else (javascript:, data:, vbscript:, file: ...)
     * becomes '' to prevent XSS. Still HTML-escape the result when printing.
     *
     * @param string $url The candidate URL.
     *
     * @return string The normalised URL, or '' if its scheme isn't allowed.
     *
     * @since  1.0.0
     */
    public static function safeUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

        if ($url === '') {
            return '';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false) {
            return $url; // relative: fine
        }

        return \in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '';
    }

    /**
     * Render the active-notifications panel (the dropdown contents) as HTML: the first
     * {@see self::PANEL_LIMIT} notifications the user may see, most urgent and newest
     * first, under a heading that counts all of them. View access is part of the query,
     * so the rows loaded are exactly the rows shown.
     *
     * @param bool $isOpen Whether the panel renders open.
     * @param mixed $user The user the panel is for.
     * @param array<int, array{0: string|null, 1: string|null}>|null $pairs The rules the user may see ({@see self::visiblePairs()}).
     * @param int $total How many active notifications those rules show.
     *
     * @return string The rendered `notifications.panel` layout.
     *
     * @since  2.1.0
     */
    private static function renderPanel(bool $isOpen, $user, ?array $pairs, int $total): string
    {
        $items = [];

        if ($total > 0) {
            $model = self::model('Notifications');

            // The panel is chrome: without the list model there is nothing to draw,
            // and a header must never be the reason a page fatals.
            if (!$model) {
                return '';
            }

            $model->setState('filter.scope', 'active');
            $model->setState('filter.view_pairs', $pairs === null ? null : array_map('json_encode', $pairs));
            $model->setState('list.limit', self::PANEL_LIMIT);
            $model->setState('list.start', 0);
            $model->setState('list.first_page', true);
            $items = $model->getItems() ?: [];
        }

        return LayoutHelper::render(
            'notifications.panel',
            ['items' => $items, 'total' => $total, 'is_open' => $isOpen, 'can_change' => self::canChangeState(user: $user)],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    /**
     * Render the whole badge component, the button (count/severity/label) AND the
     * panel, as one node. This is the SINGLE render path: the toolbar uses it on page
     * load and the panel endpoint uses it on refresh, so the two can never drift. The
     * open state is rendered in (data-open + the panel's `show` class) so a refresh
     * restores it. The badge and the panel share one reading of who sees what and one
     * count.
     *
     * @param bool $isOpen Whether the panel should render open (preserved across refresh).
     *
     * @return string The rendered `notifications.badge` layout.
     *
     * @since  1.0.0
     */
    public static function renderBadge(bool $isOpen = false): string
    {
        // No view permission, no bell; never an empty one.
        if (!self::canView()) {
            return '';
        }

        $user = Factory::getApplication()->getIdentity();
        $pairs = self::visiblePairs(user: $user);
        $counts = self::activeCounts(pairs: $pairs);

        return LayoutHelper::render(
            'notifications.badge',
            [
                'summary' => ['count' => $counts['unread'], 'severity' => $counts['severity']],
                'is_open' => $isOpen,
                'panel' => self::renderPanel(isOpen: $isOpen, user: $user, pairs: $pairs, total: $counts['total']),
            ],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    /**
     * Append the notification badge as a custom toolbar button. Called from a list
     * view's addToolbar(). The badge LAYOUT (rendered here) owns the JS/CSS loading;
     * this just places the button in the toolbar.
     *
     * @param mixed $toolbar The view's Toolbar instance ($this->getDocument()->getToolbar()).
     *
     *
     * @since  1.0.0
     */
    public static function toolbarBadge($toolbar): void
    {
        if (!$toolbar) {
            return;
        }

        // Without the view permission there is no bell at all: no button, no assets and
        // no endpoint URLs published into the page.
        if (!self::canView()) {
            return;
        }

        // The component owns the assets + script options (page-load only; the AJAX
        // refresh returns clean markup with no asset/scriptOptions noise).
        $doc = Factory::getApplication()->getDocument();

        $doc->getWebAssetManager()
            ->useStyle('com_alfa.notifications')
            ->useScript('com_alfa.notifications')
            ->useScript('com_alfa.integrity-check');

        $doc->addScriptOptions('com_alfa.notifications', [
            'panelUrl' => Route::_('index.php?option=com_alfa&task=notifications.panel', false),
            'markUrl' => Route::_('index.php?option=com_alfa&task=notifications.markRead', false),
            'dismissUrl' => Route::_('index.php?option=com_alfa&task=notifications.dismiss', false),
            'token' => Session::getFormToken(),
            'poll' => 0,
        ]);

        $toolbar->appendButton('Custom', self::renderBadge(), 'alfa-notification-badge');
    }

    /**
     * ACL gate: empty action = allowed; otherwise authorise the action on the asset
     * (defaulting to com_alfa).
     *
     * @param string $action The ACL action, or '' for "no restriction".
     * @param string $asset The asset name, or '' to default to com_alfa.
     * @param mixed $user A user object, or null for the current identity.
     *
     *
     * @since  1.0.0
     */
    private static function authorised(string $action, string $asset, $user = null): bool
    {
        if ($action === '') {
            return true;
        }

        $user ??= Factory::getApplication()->getIdentity();

        return (bool) $user->authorise($action, $asset !== '' ? $asset : 'com_alfa');
    }

    /**
     * Delete the rows whose `expires` is in the past. Reads already leave them out
     * ({@see self::unexpired()}); this only keeps the table small. Run through
     * {@see self::purgeThrottled()}, never on a page render.
     *
     *
     * @since  1.0.0
     */
    public static function purge(): void
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::TABLE))
            ->where($db->quoteName('expires') . ' IS NOT NULL')
            ->where($db->quoteName('expires') . ' <= :now')
            ->bind(':now', $now, ParameterType::STRING);

        $db->setQuery($query)->execute();
    }

    /**
     * Compute an SQL datetime $historyDays from now (default retention if null).
     *
     * @param int|null $historyDays Days ahead.
     *
     * @return string SQL datetime.
     *
     * @since  1.0.0
     */
    private static function historyExpiry(?int $historyDays): string
    {
        $days = $historyDays
            ?? (int) ComponentHelper::getParams('com_alfa')->get('notifications_history_days', self::DEFAULT_HISTORY_DAYS);

        return Factory::getDate('+' . max(1, $days) . ' days')->toSql();
    }

    /**
     * Delete expired rows at most once every {@see self::PURGE_MINUTES} minutes: the
     * bell's background refresh calls this, so a busy backend does the delete about
     * once an hour instead of on every page. The same throttle as the stock-effects
     * notice ({@see \Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox::syncNotice()}).
     *
     *
     * @since  2.1.0
     */
    public static function purgeThrottled(): void
    {
        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.notifications',
                    'lifetime' => self::PURGE_MINUTES,
                    'caching' => true,
                ]);

            $cache->get([self::class, 'purge'], [], 'purge');
        } catch (Throwable) {
            // Cache unavailable: just do it; correct, only less throttled.
            self::purge();
        }
    }

    /**
     * The id of the live (unexpired) notification carrying a dedup key, or 0. An
     * expired row under the key is deleted here: it is gone, and the UNIQUE key would
     * otherwise refuse the new row that replaces it.
     *
     * @param string $dedupKey The dedup key.
     *
     * @return int The id, or 0 if none.
     *
     * @since  2.1.0
     */
    private static function liveIdForKey(string $dedupKey): int
    {
        $db = self::db();
        $now = Factory::getDate()->toSql();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->select('(' . $db->quoteName('expires') . ' IS NOT NULL AND ' . $db->quoteName('expires') . ' <= :now) AS ' . $db->quoteName('expired'))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('dedup_key') . ' = :key')
            ->bind(':now', $now, ParameterType::STRING)
            ->bind(':key', $dedupKey, ParameterType::STRING);

        $row = $db->setQuery($query)->loadObject();

        if (!$row) {
            return 0;
        }

        $id = (int) $row->id;

        if ((int) $row->expired === 1) {
            $delete = $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $db->setQuery($delete)->execute();

            return 0;
        }

        return $id;
    }

    /**
     * Boot a com_alfa admin MVC model by name from the component MVCFactory: the
     * Notifications list model the panel reads with. Nothing writes through a model:
     * push() writes its rows itself ({@see self::write()}).
     *
     * Returns null when there is no application to boot the component from: a script
     * with no application, a queue worker. Readers have nothing to show in such a
     * context anyway.
     *
     * @param string $name The model name.
     *
     * @return object|null
     *
     * @since  1.0.0
     */
    private static function model(string $name)
    {
        try {
            return Factory::getApplication()
                ->bootComponent('com_alfa')
                ->getMVCFactory()
                ->createModel($name, 'Administrator', ['ignore_request' => true]) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Write a notification row: the store's one write path, whoever pushes and from
     * wherever (a web request, the Joomla console, a scheduled task, a script with no
     * application at all). No admin record model and no Table: a notification core
     * raises is not an administrator's save, so no save event fires for it.
     *
     * A new row gets every column that has a value and a creation time. An existing row
     * gets the columns that have one; a null option keeps what the row holds (its access
     * rules, a dismissed row's retention). A constant push re-alerts it: a new creation
     * time, and `readed`, `readed_by`, `dismissed` and `expires` cleared in the same
     * statement: dismissing is how a merchant says "seen, not now", and neither may
     * outlive a condition that is raised again.
     *
     * A key no live row carries is written by one statement ({@see self::firstWrite()}),
     * so first pushes of one key at once land on one row, never on a duplicate key or a
     * deadlock. Every failure of the store reaches the caller.
     *
     * @param int $id The live row the key names, or 0 for a new one.
     * @param array $data The columns assembled by {@see self::push()}.
     * @param bool $constant A push that re-alerts an existing row.
     *
     * @return array{0: int, 1: string} The row's id and what the push did (NotificationPushedEvent::CHANGE_*).
     *
     * @throws RuntimeException When the write reports no row id.
     *
     * @since  1.0.0
     */
    private static function write(int $id, array $data, bool $constant): array
    {
        $now = Factory::getDate()->toSql();

        if ($id === 0) {
            return self::firstWrite(data: $data, constant: $constant, now: $now);
        }

        self::update(id: $id, data: $constant ? $data + ['created' => $now] : $data, realert: $constant);

        return [$id, $constant ? NotificationPushedEvent::CHANGE_REALERTED : NotificationPushedEvent::CHANGE_UPDATED];
    }

    /**
     * The write of a key no live row carries yet: one INSERT .. ON DUPLICATE KEY UPDATE.
     * First pushes of one key at once queue on its row: the first inserts it, each later
     * one takes the row's exclusive lock and writes itself as a push of an existing key
     * (the columns that have a value; a constant push re-alerts it). No shared lock of a
     * duplicate key is ever upgraded, so they cannot deadlock however many meet (REVIEW-R1
     * RV-6). The SQL is the same on MariaDB 10.6 and MySQL 8.0: every value a literal, no
     * VALUES() function (deprecated in MySQL 8.0) and no row alias (MySQL only).
     *
     * What it did is read from the affected-rows value, as both servers define it for this
     * statement when the client does not ask for found rows, which Joomla's mysqli and
     * PDO MySQL drivers do not (no MYSQLI_CLIENT_FOUND_ROWS, no PDO::MYSQL_ATTR_FOUND_ROWS):
     * 1 a new row, 2 an existing row it changed, 0 an existing row that already held these
     * values. LAST_INSERT_ID(id) makes the driver's insert id the row's id in every case.
     *
     * @param array $data The columns assembled by {@see self::push()}.
     * @param bool $constant A push that re-alerts an existing row.
     * @param string $now The creation time of a new row, or of a re-alert.
     *
     * @return array{0: int, 1: string} The row's id and what the push did (NotificationPushedEvent::CHANGE_*).
     *
     * @throws RuntimeException When the statement reports no row id.
     *
     * @since  2.1.1
     */
    private static function firstWrite(array $data, bool $constant, string $now): array
    {
        $db = self::db();
        $values = array_filter($data + ['created' => $now], static fn ($value): bool => $value !== null);
        $assignments = [$db->quoteName('id') . ' = LAST_INSERT_ID(' . $db->quoteName('id') . ')'];

        // On the duplicate: what update() writes for a push of an existing key.
        foreach (array_filter($data, static fn ($value): bool => $value !== null) as $column => $value) {
            if (!$constant || $column !== 'expires') {
                $assignments[] = $db->quoteName($column) . ' = ' . $db->quote((string) $value);
            }
        }

        if ($constant) {
            $assignments[] = $db->quoteName('created') . ' = ' . $db->quote($now);

            foreach (['readed', 'readed_by', 'dismissed', 'expires'] as $column) {
                $assignments[] = $db->quoteName($column) . ' = NULL';
            }
        }

        $db->setQuery(
            'INSERT INTO ' . $db->quoteName(self::TABLE) . ' (' . implode(', ', $db->quoteName(array_keys($values))) . ')'
            . ' VALUES (' . implode(', ', array_map(static fn ($value): string => $db->quote((string) $value), $values)) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments),
        )->execute();
        $affected = (int) $db->getAffectedRows();
        $rowId = (int) $db->insertid();

        if ($rowId <= 0) {
            throw new RuntimeException("The notification '{$data['dedup_key']}' was written but reported no row id.");
        }

        return [$rowId, match (true) {
            $affected === 1 => NotificationPushedEvent::CHANGE_NEW,
            $constant => NotificationPushedEvent::CHANGE_REALERTED,
            default => NotificationPushedEvent::CHANGE_UPDATED,
        }];
    }

    /**
     * The UPDATE of an existing notification row: the columns that have a value; a
     * re-alert also clears its read, dismissed and expiry state.
     *
     * @param int $id The row.
     * @param array $data The columns.
     * @param bool $realert The push raises the row again.
     *
     *
     * @since  2.1.1
     */
    private static function update(int $id, array $data, bool $realert): void
    {
        $db = self::db();
        $values = array_filter($data, static fn ($value): bool => $value !== null);
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->where($db->quoteName('id') . ' = ' . $id);

        foreach ($values as $column => $value) {
            if ($realert && $column === 'expires') {
                continue;
            }

            $query->set($db->quoteName($column) . ' = ' . $db->quote((string) $value));
        }

        if ($realert) {
            foreach (['readed', 'readed_by', 'dismissed', 'expires'] as $column) {
                $query->set($db->quoteName($column) . ' = NULL');
            }
        }

        $db->setQuery($query)->execute();
    }

    /**
     * The component database driver as a driver: what TransactionState takes.
     *
     *
     * @throws RuntimeException When the container holds another kind of database object.
     *
     * @since  2.1.1
     */
    private static function driver(): DatabaseDriver
    {
        $db = self::db();

        if (!$db instanceof DatabaseDriver) {
            throw new RuntimeException('The notification store needs a database driver; the container holds ' . $db::class . '.');
        }

        return $db;
    }

    /**
     * The component database driver.
     *
     *
     * @since  1.0.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
