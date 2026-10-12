<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use stdClass;
use Throwable;

/**
 * Backend notification store for com_alfa: a single, generic place that any code
 * pushes into and the admin UI reads back from. It knows nothing about WHAT it
 * stores: a new order, low stock, the integrity state ({@see SyncHelper}), or a
 * future producer all call {@see self::push()} the same way.
 *
 * Dedup is by `dedup_key` (pushing the same key replaces the row). Lifecycle is told
 * entirely by timestamps:
 *   - active   : `dismissed IS NULL` - shown in the badge + quick panel.
 *   - history  : `dismissed` set - kept until `expires`, shown in the history view.
 *   - purged   : `expires <= now` - lazily deleted on read (no cron).
 *
 * Dismissing/clearing ARCHIVES (sets `dismissed` + an `expires` retention window,
 * default {@see self::DEFAULT_HISTORY_DAYS}) rather than hard-deleting, so a history
 * of what was shown survives. Non-dismissible rows can't be hidden by the user.
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
     * @since  1.10.0
     */
    public const VIEW_ACTION = 'alfa.notifications.view';

    /**
     * The permission that marks a notification read or dismisses it. Both change the
     * row for every administrator, not only for the one who clicks.
     *
     * @var string
     * @since  1.10.0
     */
    public const STATE_ACTION = 'alfa.notifications.edit.state';

    /**
     * Create or replace a notification, deduped by $dedupKey. Re-pushing an existing
     * key updates its content but preserves `created`/`readed`/`dismissed`, so a
     * still-true condition never re-nags an already-seen row.
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

        $existingId = self::idForKey(dedupKey: $dedupKey);

        $data = [
            'id' => $existingId,
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
        // unread and reflects the latest check; new rows also get a creation timestamp.
        $isRealert = ($existingId > 0 && !empty($options['constant']));

        if ($existingId === 0 || $isRealert) {
            $data['created'] = Factory::getDate()->toSql();
        }

        $model = self::model();

        if ($model) {
            $model->save($data);
        } else {
            self::write($data);
        }

        // Joomla's Table::store() SKIPS null values, so a re-alert's readed=null would
        // never persist via save(); clear the read state with a direct update instead.
        //
        // `dismissed` and `expires` go with it. A soft clear sets both, and dismissing
        // is how a merchant says "seen, not now"; neither may outlive the condition.
        // Leaving them set was why a dismissed warning could never speak again: the row
        // survived, so push() only ever UPDATED it, and the merchant heard nothing more
        // about that product until it fully recovered and the row was hard-cleared.
        if ($isRealert) {
            $db = self::db();
            $query = $db->getQuery(true)
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('readed') . ' = NULL')
                ->set($db->quoteName('readed_by') . ' = NULL')
                ->set($db->quoteName('dismissed') . ' = NULL')
                ->set($db->quoteName('expires') . ' = NULL')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $existingId, ParameterType::INTEGER);

            $db->setQuery($query)->execute();
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
     * and never un-dismisses it.
     *
     * @param string $dedupKey The key passed to {@see self::push()}.
     *
     * @return bool True when a row carries the key.
     *
     * @since  1.5.0
     */
    public static function exists(string $dedupKey): bool
    {
        return self::idForKey(dedupKey: $dedupKey) > 0;
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
     * @since  1.10.0
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
     * @since  1.9.0
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

        return $db->setQuery($query)->loadColumn() ?: [];
    }

    /**
     * Badge summary: count of UNREAD, still-active notifications + the highest
     * severity among them (drives the badge colour). Purges expired history first.
     *
     * @return array{count: int, severity: string}
     *
     * @since  1.0.0
     */
    public static function summary(): array
    {
        self::purge();

        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['severity', 'view_access', 'view_access_asset']))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('dismissed') . ' IS NULL')
            ->where($db->quoteName('readed') . ' IS NULL');

        $rows = (array) $db->setQuery($query)->loadObjectList();

        $count = 0;
        $rank = -1;
        $worst = 'info';

        foreach ($rows as $row) {
            // Only count what the current user is allowed to SEE.
            if (!self::canSee(notification: $row)) {
                continue;
            }

            $count++;
            $r = self::SEVERITY_RANK[$row->severity] ?? 0;

            if ($r > $rank) {
                $rank = $r;
                $worst = $row->severity;
            }
        }

        return ['count' => $count, 'severity' => $worst];
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * @since  1.10.0
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
     * Render the active-notifications panel (the dropdown contents) as HTML. Used by
     * BOTH the toolbar badge layout (server-rendered on page load; no async needed for
     * this cheap getItems) and the refresh endpoint, so they render identically.
     * View-access filtering is applied here.
     *
     * @return string The rendered `notifications.panel` layout.
     *
     * @since  1.0.0
     */
    public static function renderActivePanel(bool $isOpen = false): string
    {
        $app = Factory::getApplication();
        $model = self::model('Notifications');

        // The panel is chrome: without the list model there is nothing to draw,
        // and a header must never be the reason a page fatals.
        if (!$model) {
            return '';
        }

        $model->getState('list.ordering'); // force populateState()
        $model->setState('filter.scope', 'active');
        $model->setState('list.limit', 0);
        $model->setState('list.start', 0);

        $user = $app->getIdentity();
        $items = array_values(array_filter(
            $model->getItems() ?: [],
            static fn ($item): bool => self::canSee(notification: $item, user: $user),
        ));

        return LayoutHelper::render(
            'notifications.panel',
            ['items' => $items, 'is_open' => $isOpen, 'can_change' => self::canChangeState(user: $user)],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    /**
     * Render the whole badge component, the button (count/severity/label) AND the
     * panel, as one node. This is the SINGLE render path: the toolbar uses it on page
     * load and the panel endpoint uses it on refresh, so the two can never drift. The
     * open state is rendered in (data-open + the panel's `show` class) so a refresh
     * restores it.
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

        return LayoutHelper::render(
            'notifications.badge',
            ['summary' => self::summary(), 'is_open' => $isOpen],
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
     * Delete history rows whose `expires` is in the past (lazy cleanup, no cron).
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
     * Look up an existing notification id by its dedup key.
     *
     * @param string $dedupKey The dedup key.
     *
     * @return int The id, or 0 if none.
     *
     * @since  1.0.0
     */
    private static function idForKey(string $dedupKey): int
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName(self::TABLE))
            ->where($db->quoteName('dedup_key') . ' = :key')
            ->bind(':key', $dedupKey, ParameterType::STRING);

        return (int) $db->setQuery($query)->loadResult();
    }

    /**
     * Boot a com_alfa admin MVC model by name from the component MVCFactory. Defaults
     * to the single-notification item model (the canonical write path: push(), the
     * form controller and the webservices API all save through it); pass 'Notifications'
     * for the read/list model.
     *
     * Returns null when there is no application to boot the component from:
     * cron, the Joomla console, a queue worker. Writers fall back to
     * {@see self::write()}; readers have nothing to show in such a context
     * anyway.
     *
     * @param string $name The model name ('Notification' or 'Notifications').
     *
     * @return object|null
     *
     * @since  1.0.0
     */
    private static function model(string $name = 'Notification')
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
     * Write a notification row with nothing but a database connection.
     *
     * push() goes through the admin model so a web request keeps the
     * component's own save pipeline. Background work has no application to
     * boot that from, and skipping the write there is not a small loss: on
     * one occasion six activity-log failures on this very install wrote their
     * CRITICAL lines and raised nothing, because the raiser ran from the CLI
     * and push() quietly did nothing without a model. The panel is the
     * operator's channel; it must not depend on who is calling.
     *
     * Table is no help here (its constructor reaches for the application's
     * dispatcher), so this writes the row directly. Nulls are written on
     * update because `readed`, `dismissed` and `expires` carry meaning when
     * empty.
     *
     * @param array $data The row assembled by {@see self::push()}.
     *
     *
     * @since  1.0.0
     */
    private static function write(array $data): void
    {
        $db = self::db();
        $id = (int) ($data['id'] ?? 0);
        unset($data['id']);

        $row = new stdClass();

        foreach ($data as $column => $value) {
            $row->{$column} = $value;
        }

        if ($id > 0) {
            $row->id = $id;
            $db->updateObject(self::TABLE, $row, 'id', true);

            return;
        }

        $db->insertObject(self::TABLE, $row);
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
