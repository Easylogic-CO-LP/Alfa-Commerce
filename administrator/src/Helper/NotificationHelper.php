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
use Alfa\Component\Alfa\Administrator\Service\Task\NoticeTask;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
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
 * dispatches once the row is written and no transaction of the caller's is open.
 *
 * Inside a transaction no row is written (2.1.4): push() and clear() record the write
 * in the transaction's notice journal (a notice single, {@see Tasks::notice()}), a
 * rollback discards it, and it is written once the outermost transaction has
 * committed, before any other work, even while Alfa's named locks are held (s71);
 * onAlfaNotificationPushed still waits for their release. Until a notice is written
 * every reader counts it ({@see self::exists()}, {@see self::isOpen()},
 * {@see self::keys()}): its own transaction, and any other request once that
 * transaction has committed. A key's notices are written in the order raised, each
 * together with the older ones of the key still owed, from any request, and a write
 * with no transaction open joins those of its key still owed. Every write sets
 * `modified` to when it was raised (a journalled write's single was made then, any
 * other write is raised as it is made), and a journalled write is skipped when its row
 * holds a write raised after it ({@see self::applyJournal()}).
 *
 * A title and a message are a language key and its parameters (2.1.4,
 * {@see NotificationText}): the key, the parameters and the text domain are stored as
 * the notification, translated when shown in each reader's language; `title` and
 * `message` keep a snapshot in the shop's default administrator language, written with
 * them and never rendered again. `notify_group` holds a group key. Literal text is kept
 * for third parties and older rows, and shown as written; core uses keys only.
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
     * The groups core lists its notifications under: language keys, stored as they are and translated when shown
     * (2.1.4). A third party may name a group key of its own, or literal text.
     *
     * @var string
     * @since  2.1.4
     */
    public const GROUP_GENERAL = 'COM_ALFA_NOTIFY_GROUP_GENERAL';

    /** @since 2.1.4 */
    public const GROUP_ORDERS = 'COM_ALFA_NOTIFY_GROUP_ORDERS';

    /** @since 2.1.4 */
    public const GROUP_INVENTORY = 'COM_ALFA_NOTIFY_GROUP_INVENTORY';

    /** @since 2.1.4 */
    public const GROUP_DOCUMENTS = 'COM_ALFA_NOTIFY_GROUP_DOCUMENTS';

    /** @since 2.1.4 */
    public const GROUP_PAYMENTS = 'COM_ALFA_NOTIFY_GROUP_PAYMENTS';

    /** @since 2.1.4 */
    public const GROUP_SHIPPING = 'COM_ALFA_NOTIFY_GROUP_SHIPPING';

    /** @since 2.1.4 */
    public const GROUP_SETUP = 'COM_ALFA_NOTIFY_GROUP_SETUP';

    /** @since 2.1.4 */
    public const GROUP_SECURITY = 'COM_ALFA_NOTIFY_GROUP_SECURITY';

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
     * With no transaction open the row is written at once, by the helper itself
     * ({@see self::write()}), and then onAlfaNotificationPushed is dispatched
     * ({@see NotificationPushedEvent}). Inside a transaction the push joins the notice
     * journal: written once the outermost transaction has committed, and dispatched once
     * no named lock is held either; a rollback discards it. With no transaction open it
     * also joins the journal while notices of its key are still owed, so it is written
     * after them ({@see self::journal()}). First pushes of one key
     * at once land on one row: each later one is a push of the row the first wrote. A
     * failure to write (the row, or the journal's single) reaches the caller, as it did
     * before 2.1.1 (each producer decides whether its work stands without the notice); a
     * listener's failure never does.
     *
     * @param string $dedupKey Stable identity, e.g. "order:123" or "alfa.integrity".
     * @param NotificationText|string $title Short headline: a key and its parameters, or literal text.
     * @param array $options group (a group key, e.g. COM_ALFA_NOTIFY_GROUP_ORDERS)|severity|
     *                       message (a NotificationText or literal text)|url|dismissible(bool)|expires(sql)|
     *                       constant(bool, re-alert on re-push: clears read state so a
     *                       persistent condition like integrity resurfaces each cycle)|
     *                       view(['action'=>..,'asset'=>..]: who may SEE it; null = all managers)|
     *                       link(['action'=>..,'asset'=>..]: who may use the LINK; null = anyone who sees it).
     *
     *
     * @since  1.0.0
     */
    public static function push(string $dedupKey, NotificationText|string $title, array $options = []): void
    {
        $db = self::driver();

        if (TransactionState::isOpen($db) || self::owed(dedupKey: $dedupKey)) {
            // A text is kept as its key and parameters; it is rendered when it is written.
            if (isset($options['message'])) {
                $options['message'] = NotificationText::pack(text: $options['message']);
            }

            self::journal(db: $db, op: ['op' => 'push', 'key' => $dedupKey, 'title' => NotificationText::pack(text: $title), 'options' => $options]);

            return;
        }

        self::pushNow(dedupKey: $dedupKey, title: $title, options: $options);
    }

    /**
     * Record a write in the notice journal: in the open transaction, or with none open (notices of its key are still
     * owed, so it is written after them) in a transaction of its own, written at its commit.
     *
     * @param DatabaseDriver $db The connection.
     * @param array<string, mixed> $op The write.
     *
     * @since  2.1.4
     */
    private static function journal(DatabaseDriver $db, array $op): void
    {
        if (TransactionState::isOpen($db)) {
            Tasks::notice(db: $db, op: $op);

            return;
        }

        $db->transactionStart(true);

        try {
            Tasks::notice(db: $db, op: $op);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        TransactionState::commitLevel($db);
    }

    /**
     * Whether notices of a key raised inside transactions are still owed: pending or running notice singles, of any
     * request.
     *
     * @param string $dedupKey The key.
     *
     * @since  2.1.4
     */
    private static function owed(string $dedupKey): bool
    {
        $db = self::db();

        foreach ($db->setQuery(self::owedSelect(patterns: [Tasks::noticePattern(key: $dedupKey)]))->loadAssocList() ?: [] as $row) {
            if (self::opOf(payload: $row['payload'])['key'] === $dedupKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a push now: the row, then onAlfaNotificationPushed once no transaction is open. A text's snapshot is
     * rendered here, in the shop's default administrator language, with its key and parameters.
     *
     * @param string $dedupKey The key.
     * @param NotificationText|string $title The headline.
     * @param array $options As {@see self::push()} takes them.
     * @param string|null $raised When a journalled push was raised (its row's `modified`); null: now.
     *
     * @since  2.1.4
     */
    private static function pushNow(string $dedupKey, NotificationText|string $title, array $options, ?string $raised = null): void
    {
        $message = $options['message'] ?? '';
        $texts = NotificationText::columns(title: $title, message: $message instanceof NotificationText ? $message : (string) $message);

        $severity = \in_array($options['severity'] ?? '', ['success', 'info', 'warning', 'danger'], true)
            ? $options['severity']
            : 'info';

        // An expired row under this key is gone: the key raised again is a new notice,
        // never an update of the dead row (which would keep its old dismissed/expires).
        $existingId = self::liveIdForKey(dedupKey: $dedupKey);

        $data = [
            'dedup_key' => $dedupKey,
            'notify_group' => (string) ($options['group'] ?? self::GROUP_GENERAL),
            'text_domain' => $texts['text_domain'],
            'severity' => $severity,
            'title' => $texts['title'],
            'title_key' => $texts['title_key'],
            'message' => $texts['message'],
            'message_key' => $texts['message_key'],
            'text_args' => $texts['text_args'],
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
        [$id, $change] = self::write(id: $existingId, data: $data, constant: !empty($options['constant']), raised: $raised);

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
                'textDomain' => (string) ($data['text_domain'] ?? ''),
                'titleKey' => (string) ($data['title_key'] ?? ''),
                'messageKey' => (string) ($data['message_key'] ?? ''),
                'textArgs' => NotificationText::arguments(row: (object) ['text_args' => $data['text_args'] ?? null]),
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
        $now = Factory::getDate()->toSql();
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('readed') . ' = :now')
            ->set($db->quoteName('readed_by') . ' = ' . ($userId > 0 ? (int) $userId : 'NULL'))
            ->set($db->quoteName('modified') . ' = UTC_TIMESTAMP(6)')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':now', $now)
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
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
            ->set($db->quoteName('modified') . ' = UTC_TIMESTAMP(6)')
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
     * Inside a transaction, or while notices of its key are still owed, it joins the notice
     * journal, as a push does ({@see self::push()}).
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
        $db = self::driver();

        if (TransactionState::isOpen($db) || self::owed(dedupKey: $dedupKey)) {
            self::journal(db: $db, op: ['op' => 'clear', 'key' => $dedupKey, 'hard' => $hard, 'days' => $historyDays]);

            return;
        }

        self::clearNow(dedupKey: $dedupKey, hard: $hard, historyDays: $historyDays);
    }

    /**
     * Write a clear now.
     *
     * @param string $dedupKey The key.
     * @param bool $hard Delete instead of archiving.
     * @param int|null $historyDays Retention when archiving.
     * @param string|null $raised When a journalled clear was raised (an archived row's `modified`); null: now.
     *
     * @since  2.1.4
     */
    private static function clearNow(string $dedupKey, bool $hard, ?int $historyDays, ?string $raised = null): void
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
            ->set($db->quoteName('modified') . ' = ' . self::stamp(raised: $raised))
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
     * The answer counts the notices of the key still owed ({@see self::push()}): this
     * transaction's, and those of other requests that have committed, in one statement.
     *
     * @param string $dedupKey The key passed to {@see self::push()}.
     *
     * @return bool True when a row carries the key.
     *
     * @since  2.0.0
     */
    public static function exists(string $dedupKey): bool
    {
        return self::standing(dedupKey: $dedupKey)['exists'];
    }

    /**
     * Whether a notice with this key is standing: raised and not dismissed or cleared.
     *
     * {@see self::exists()} answers whether the row is there at all, which stays true for
     * an archived notice; a producer deciding whether a warning is still being shown
     * needs this one.
     *
     * The answer counts the notices of the key still owed, as {@see self::exists()} does.
     *
     * @param string $dedupKey The producer's key.
     *
     *
     * @since  2.0.0
     */
    public static function isOpen(string $dedupKey): bool
    {
        return self::standing(dedupKey: $dedupKey)['open'];
    }

    /**
     * Every dedup key stored under a namespace prefix: how a producer that owns a
     * key family (e.g. "subscription:") finds the rows IT raised, so it can retire
     * the ones whose subject is gone. Storage only: the caller decides which keys
     * still mean something, because only it knows its own key grammar.
     *
     * Archived (dismissed) rows are included: a key worth retiring is worth
     * retiring whether or not a merchant already waved it away. The notices still owed
     * count, as for {@see self::exists()}: a push makes its key appear, a hard clear
     * removes it.
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
        $rows = $db->setQuery(
            'SELECT 0 AS ' . $db->quoteName('task') . ', ' . $db->quoteName('dedup_key') . ' AS ' . $db->quoteName('k')
            . ', ' . $db->quoteName('modified') . ' AS ' . $db->quoteName('at') . ', NULL AS ' . $db->quoteName('payload')
            . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('dedup_key') . ' LIKE ' . $db->quote($pattern) . ' AND ' . self::unexpired(now: Factory::getDate()->toSql())
            . ' UNION ALL ' . self::owedSelect(patterns: [Tasks::noticePattern(key: $prefix, prefix: true)]),
        )->loadAssocList() ?: [];
        $found = [];
        $modified = [];
        $owed = [];

        foreach ($rows as $row) {
            if ((int) $row['task'] === 0) {
                $found[(string) $row['k']] = true;
                $modified[(string) $row['k']] = (string) ($row['at'] ?? '');

                continue;
            }

            $op = self::opOf(payload: $row['payload']);

            if (str_starts_with($op['key'], $prefix)) {
                $owed[(int) $row['task']] = ['raised' => (string) $row['at'], 'op' => $op];
            }
        }

        // The writes still owed under the prefix, in the order raised, as they will be written: a push makes its key
        // appear, a hard clear removes it, one its row was written after is skipped.
        ksort($owed);

        foreach ($owed as $entry) {
            $key = $entry['op']['key'];

            if (self::skips(modified: $modified[$key] ?? '', raised: $entry['raised'])) {
                continue;
            }

            if ($entry['op']['op'] === 'push') {
                $found[$key] = true;
            } elseif (!empty($entry['op']['hard'])) {
                unset($found[$key]);
            }
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * Write notices raised inside transactions that have committed, the notice journal's singles: what the tasks
     * service runs inside its own transaction, which has deleted them first ({@see Tasks::applyJournal()}).
     *
     * A key's notices are written only together with every older notice of the key still owed (REVIEW-C3 MAJOR-1):
     * when the tasks table still holds one, of any request, nothing is written and those singles are answered, for the
     * caller to take them over and write again. Otherwise the writes are made in the order raised, across keys, as
     * their listeners then hear them. Each key's `modified` (when its last write was raised) is read once, before any of
     * them, and a write raised before it is skipped (the same server clock), so a notice written a second time changes
     * nothing and none overwrites a newer write (a later evaluation, or staff marking it read or dismissing it); a write
     * made here records when it was raised, so an older notice written late never makes a newer one look older. A
     * skipped write is logged at DEBUG.
     *
     * @param list<array{task: int, raised: string, op: array<string, mixed>}> $ops The writes.
     *
     * @return list<int> The older notice singles of their keys still owed, oldest first; empty when they were written.
     *
     * @since  2.1.4
     */
    public static function applyJournal(array $ops): array
    {
        $last = [];

        foreach ($ops as $entry) {
            $key = (string) ($entry['op']['key'] ?? '');

            if ($key !== '') {
                $last[$key] = max($last[$key] ?? 0, (int) $entry['task']);
            }
        }

        if ($last === []) {
            return [];
        }

        $db = self::db();
        $rows = $db->setQuery(
            'SELECT 0 AS ' . $db->quoteName('task') . ', ' . $db->quoteName('dedup_key') . ' AS ' . $db->quoteName('k')
            . ', ' . $db->quoteName('modified') . ' AS ' . $db->quoteName('at') . ', NULL AS ' . $db->quoteName('payload')
            . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('dedup_key') . ' IN (' . implode(', ', array_map(static fn (string $key): string => $db->quote($key), array_map('strval', array_keys($last)))) . ')'
            . ' AND ' . self::unexpired(now: Factory::getDate()->toSql())
            . ' UNION ALL ' . self::owedSelect(patterns: array_map(static fn (string $key): string => Tasks::noticePattern(key: $key), array_map('strval', array_keys($last))))
            . ' AND ' . $db->quoteName('id') . ' < ' . max($last),
        )->loadAssocList() ?: [];
        $modified = [];
        $older = [];

        foreach ($rows as $row) {
            if ((int) $row['task'] === 0) {
                $modified[(string) $row['k']] = (string) ($row['at'] ?? '');

                continue;
            }

            $key = self::opOf(payload: $row['payload'])['key'];

            if (isset($last[$key]) && (int) $row['task'] < $last[$key]) {
                $older[] = (int) $row['task'];
            }
        }

        if ($older !== []) {
            sort($older);

            return $older;
        }

        usort($ops, static fn (array $a, array $b): int => (int) $a['task'] <=> (int) $b['task']);

        foreach ($ops as $entry) {
            $op = $entry['op'];
            $key = (string) ($op['key'] ?? '');

            if ($key === '') {
                continue;
            }

            if (self::skips(modified: $modified[$key] ?? '', raised: (string) $entry['raised'])) {
                self::log(entry: "NotificationHelper: the notice write #{$entry['task']} of '{$key}' raised at " . $entry['raised']
                    . ' was skipped: its row holds a write raised after it, at ' . $modified[$key] . '.', priority: Log::DEBUG);

                continue;
            }

            if (($op['op'] ?? '') === 'push') {
                $options = (array) ($op['options'] ?? []);

                if (\array_key_exists('message', $options)) {
                    $options['message'] = NotificationText::unpack(packed: $options['message']);
                }

                self::pushNow(dedupKey: $key, title: NotificationText::unpack(packed: $op['title'] ?? ''), options: $options, raised: (string) $entry['raised']);
            } elseif (($op['op'] ?? '') === 'clear') {
                self::clearNow(dedupKey: $key, hard: !empty($op['hard']), historyDays: isset($op['days']) ? (int) $op['days'] : null, raised: (string) $entry['raised']);
            }
        }

        return [];
    }

    /**
     * Whether a write raised at a moment is skipped because its row holds a write raised after it: `modified` and the
     * single's creation, server UTC with microseconds, compared as the strings both are.
     *
     * @param string $modified The row's `modified` before the write, '' for no row or none recorded.
     * @param string $raised When the write was raised.
     *
     * @since  2.1.4
     */
    private static function skips(string $modified, string $raised): bool
    {
        return $modified !== '' && strcmp($modified, $raised) > 0;
    }

    /**
     * What a write sets `modified` to: when it was raised, server UTC with microseconds.
     *
     * @param string|null $raised When a journalled write was raised; null: a write raised now.
     *
     * @since  2.1.4
     */
    private static function stamp(?string $raised): string
    {
        return $raised === null ? 'UTC_TIMESTAMP(6)' : self::db()->quote($raised);
    }

    /**
     * The notice singles still owed whose payload matches one of the patterns, for a UNION ALL after a read of the
     * notifications table with the same four columns: their id, NULL, their creation and their payload. Pending or
     * running, of any request; this connection's own uncommitted ones included, one a rollback removed not. Read through
     * idx_handler_state (a plain read, no lock), whatever else the statement narrows by.
     *
     * @param list<string> $patterns LIKE patterns ({@see Tasks::noticePattern()}).
     *
     * @since  2.1.4
     */
    private static function owedSelect(array $patterns): string
    {
        $db = self::db();

        return 'SELECT ' . $db->quoteName('id') . ', NULL, ' . $db->quoteName('created_at') . ', ' . $db->quoteName('payload')
            . ' FROM ' . $db->quoteName(Tasks::TABLE) . ' FORCE INDEX (' . $db->quoteName('idx_handler_state') . ')'
            . ' WHERE ' . $db->quoteName('handler') . ' = ' . $db->quote(NoticeTask::NAME)
            . ' AND ' . $db->quoteName('state') . ' IN (' . $db->quote(Tasks::STATE_PENDING) . ', ' . $db->quote(Tasks::STATE_RUNNING) . ')'
            . ' AND (' . implode(' OR ', array_map(static fn (string $pattern): string => $db->quoteName('payload') . ' LIKE ' . $db->quote($pattern), $patterns)) . ')';
    }

    /**
     * A notice single's write as its payload holds it, with its key and op as strings.
     *
     * @param mixed $payload The stored JSON.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.4
     */
    private static function opOf(mixed $payload): array
    {
        $op = json_decode((string) $payload, true);
        $op = \is_array($op) ? $op : [];

        return ['key' => (string) ($op['key'] ?? ''), 'op' => (string) ($op['op'] ?? '')] + $op;
    }

    /**
     * A key's notification as every reader sees it: the stored row, with the writes of the key still owed replayed over
     * it in the order raised, each one the write will skip skipped. One statement.
     *
     * @param string $dedupKey The key.
     *
     * @return array{exists: bool, open: bool}
     *
     * @since  2.1.4
     */
    private static function standing(string $dedupKey): array
    {
        $db = self::db();
        $rows = $db->setQuery(
            'SELECT 0 AS ' . $db->quoteName('task') . ', (' . $db->quoteName('dismissed') . ' IS NULL) AS ' . $db->quoteName('open')
            . ', ' . $db->quoteName('modified') . ' AS ' . $db->quoteName('at') . ', NULL AS ' . $db->quoteName('payload')
            . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('dedup_key') . ' = ' . $db->quote($dedupKey) . ' AND ' . self::unexpired(now: Factory::getDate()->toSql())
            . ' UNION ALL ' . self::owedSelect(patterns: [Tasks::noticePattern(key: $dedupKey)]),
        )->loadAssocList() ?: [];
        $state = ['exists' => false, 'open' => false];
        $modified = '';
        $owed = [];

        foreach ($rows as $row) {
            if ((int) $row['task'] === 0) {
                $state = ['exists' => true, 'open' => (int) $row['open'] === 1];
                $modified = (string) ($row['at'] ?? '');

                continue;
            }

            $op = self::opOf(payload: $row['payload']);

            if ($op['key'] === $dedupKey) {
                $owed[(int) $row['task']] = ['raised' => (string) $row['at'], 'op' => $op];
            }
        }

        ksort($owed);

        // As the table will hold it: a push makes a missing row live and open, and re-opens a dismissed one only when
        // constant; a soft clear dismisses an open row; a hard clear removes it.
        foreach ($owed as $entry) {
            $op = $entry['op'];

            if (self::skips(modified: $modified, raised: $entry['raised'])) {
                continue;
            }

            if ($op['op'] === 'push') {
                $state = [
                    'exists' => true,
                    'open' => !$state['exists'] || $state['open'] || !empty($op['options']['constant']),
                ];
            } elseif (!empty($op['hard'])) {
                $state = ['exists' => false, 'open' => false];
            } else {
                $state['open'] = false;
            }
        }

        return $state;
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
     * @param string|null $raised When a journalled push was raised; null: now.
     *
     * @return array{0: int, 1: string} The row's id and what the push did (NotificationPushedEvent::CHANGE_*).
     *
     * @throws RuntimeException When the write reports no row id.
     *
     * @since  1.0.0
     */
    private static function write(int $id, array $data, bool $constant, ?string $raised = null): array
    {
        $now = Factory::getDate()->toSql();

        if ($id === 0) {
            return self::firstWrite(data: $data, constant: $constant, now: $now, raised: $raised);
        }

        self::update(id: $id, data: $constant ? $data + ['created' => $now] : $data, realert: $constant, raised: $raised);

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
     * @param string|null $raised When a journalled push was raised; null: now.
     *
     * @return array{0: int, 1: string} The row's id and what the push did (NotificationPushedEvent::CHANGE_*).
     *
     * @throws RuntimeException When the statement reports no row id.
     *
     * @since  2.1.1
     */
    private static function firstWrite(array $data, bool $constant, string $now, ?string $raised = null): array
    {
        $db = self::db();
        $values = array_filter($data + ['created' => $now], static fn ($value): bool => $value !== null);
        $assignments = [$db->quoteName('id') . ' = LAST_INSERT_ID(' . $db->quoteName('id') . ')', $db->quoteName('modified') . ' = ' . self::stamp(raised: $raised)];

        // On the duplicate: what update() writes for a push of an existing key.
        foreach (array_filter($data, static fn ($value): bool => $value !== null) as $column => $value) {
            if (!$constant || $column !== 'expires') {
                $assignments[] = $db->quoteName($column) . ' = ' . $db->quote((string) $value);
            }
        }

        // A push of literal text clears the parameters a keyed push left.
        if (\array_key_exists('text_args', $data) && $data['text_args'] === null) {
            $assignments[] = $db->quoteName('text_args') . ' = NULL';
        }

        if ($constant) {
            $assignments[] = $db->quoteName('created') . ' = ' . $db->quote($now);

            foreach (['readed', 'readed_by', 'dismissed', 'expires'] as $column) {
                $assignments[] = $db->quoteName($column) . ' = NULL';
            }
        }

        $db->setQuery(
            'INSERT INTO ' . $db->quoteName(self::TABLE) . ' (' . implode(', ', $db->quoteName([...array_keys($values), 'modified'])) . ')'
            . ' VALUES (' . implode(', ', array_map(static fn ($value): string => $db->quote((string) $value), $values)) . ', ' . self::stamp(raised: $raised) . ')'
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
     * @param string|null $raised When a journalled push was raised; null: now.
     *
     *
     * @since  2.1.1
     */
    private static function update(int $id, array $data, bool $realert, ?string $raised = null): void
    {
        $db = self::db();
        $values = array_filter($data, static fn ($value): bool => $value !== null);
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::TABLE))
            ->set($db->quoteName('modified') . ' = ' . self::stamp(raised: $raised))
            ->where($db->quoteName('id') . ' = ' . $id);

        foreach ($values as $column => $value) {
            if ($realert && $column === 'expires') {
                continue;
            }

            $query->set($db->quoteName($column) . ' = ' . $db->quote((string) $value));
        }

        // A push of literal text clears the parameters a keyed push left.
        if (\array_key_exists('text_args', $data) && $data['text_args'] === null) {
            $query->set($db->quoteName('text_args') . ' = NULL');
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
