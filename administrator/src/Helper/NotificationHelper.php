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

/**
 * Backend notification store for com_alfa — a single, generic place that any code
 * pushes into and the admin UI reads back from. It knows nothing about WHAT it
 * stores: a new order, low stock, the integrity state ({@see SyncHelper}), or a
 * future producer all call {@see self::push()} the same way.
 *
 * Dedup is by `dedup_key` (pushing the same key replaces the row). Lifecycle is told
 * entirely by timestamps:
 *   - active   : `dismissed IS NULL` — shown in the badge + quick panel.
 *   - history  : `dismissed` set — kept until `expires`, shown in the history view.
 *   - purged   : `expires <= now` — lazily deleted on read (no cron).
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
     * Create or replace a notification, deduped by $dedupKey. Re-pushing an existing
     * key updates its content but preserves `created`/`readed`/`dismissed`, so a
     * still-true condition never re-nags an already-seen row.
     *
     * @param string $dedupKey Stable identity, e.g. "order:123" or "alfa.integrity".
     * @param string $title Short headline.
     * @param array $options group|severity|message|url|dismissible(bool)|expires(sql)|
     *                       constant(bool — re-alert on re-push: clears read state so a
     *                       persistent condition like integrity resurfaces each cycle)|
     *                       view(['action'=>..,'asset'=>..] — who may SEE it; null = all managers)|
     *                       link(['action'=>..,'asset'=>..] — who may use the LINK; null = anyone who sees it).
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
        }

        // Joomla's Table::store() SKIPS null values, so a re-alert's readed=null would
        // never persist via save() — clear the read state with a direct update instead.
        if ($isRealert) {
            $db = self::db();
            $query = $db->getQuery(true)
                ->update($db->quoteName(self::TABLE))
                ->set($db->quoteName('readed') . ' = NULL')
                ->set($db->quoteName('readed_by') . ' = NULL')
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
     * Dismiss a notification → ARCHIVE it to history (set `dismissed` now, `expires`
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
     * for history; with $hard = true it is deleted outright — used for fluctuating
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
     * Whether the given user (default: current) may SEE a notification — true when it
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
     * false, the notification is still shown — just without its clickable target.
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
     * Normalise + safety-check a URL for an href. Decodes HTML entities first so the
     * value is the single canonical raw form (e.g. `&amp;` → `&`) — call it on store
     * (so the DB holds clean URLs) AND on output (so legacy `&amp;` rows are fixed and
     * never double-encoded). Returns the URL only for safe schemes — relative, or
     * absolute http/https; anything else (javascript:, data:, vbscript:, file: …)
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
            return $url; // relative — fine
        }

        return \in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '';
    }

    /**
     * Render the active-notifications panel (the dropdown contents) as HTML. Used by
     * BOTH the toolbar badge layout (server-rendered on page load — no async needed for
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
            ['items' => $items, 'is_open' => $isOpen],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    /**
     * Render the whole badge component — the button (count/severity/label) AND the
     * panel — as one node. This is the SINGLE render path: the toolbar uses it on page
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
        return LayoutHelper::render(
            'notifications.badge',
            ['summary' => self::summary(), 'is_open' => $isOpen],
            JPATH_ADMINISTRATOR . '/components/com_alfa/layouts',
        );
    }

    /**
     * Append the notification badge as a custom toolbar button. Called from a list
     * view's addToolbar(). The badge LAYOUT (rendered here) owns the JS/CSS loading —
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

        // The component owns the assets + script options (page-load only — the AJAX
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
     * Delete history rows whose `expires` is in the past (lazy cleanup — no cron).
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
     * to the single-notification item model (the canonical write path — push(), the
     * form controller and the webservices API all save through it); pass 'Notifications'
     * for the read/list model.
     *
     * @param string $name The model name ('Notification' or 'Notifications').
     *
     * @return object|false
     *
     * @since  1.0.0
     */
    private static function model(string $name = 'Notification')
    {
        return Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel($name, 'Administrator', ['ignore_request' => true]);
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
