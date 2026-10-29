<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Setup\CollationCheck;
use Alfa\Component\Alfa\Administrator\Service\Setup\ContentLanguageNotice;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

/**
 * Notifications controller, the async endpoints behind the backend bell: `panel`
 * (re-render the badge component), `markRead` and `dismiss`. The history screen is a
 * plain display and needs no task of its own.
 *
 * A BaseController on purpose: an AdminController would also answer delete, publish,
 * check-in and reordering tasks that this store has no use for, guarded only by the
 * core permissions. Reading needs alfa.notifications.view; marking read and dismissing
 * need alfa.notifications.edit.state and a row the user is allowed to see. Writes go
 * through {@see NotificationHelper}.
 *
 * @since  1.0.0
 */
class NotificationsController extends BaseController
{
    /**
     * Async endpoint for the toolbar badge. Returns the rendered badge COMPONENT (button
     * + panel) as HTML: the same `renderBadge()` the toolbar uses on page load, so the
     * JS just swaps the node in place (no client-side building). `&open=1` preserves the
     * open state across the refresh.
     *
     *
     * @since  1.0.0
     */
    public function panel(): void
    {
        // State-touching async endpoint (runs the cached integrity sync): require the token.
        if (!Session::checkToken('get')) {
            $this->app->setHeader('status', '403 Forbidden', true);
            $this->app->sendHeaders();
            $this->app->close();
        }

        $this->assertCanView();

        // This async fetch (fired after page load by integrity-check.js) is where the
        // 24h-cached integrity check runs, never during page render. Then it returns the
        // freshly-rendered component so the badge reloads with any change.
        SyncHelper::syncIntegrity();

        // Same throttled pattern for stock movements still owed: a request that was cut
        // off cannot warn about itself, so the bell fetch notices it; no scheduler needed.
        InventoryEffectOutbox::syncNotice();

        // And for work a save owes and its request did not finish: recovery every few minutes, never the normal path;
        // finished tasks are pruned at most once an hour.
        Tasks::recoverThrottled();
        Tasks::pruneThrottled();

        // And for expired notifications: deleted here at most once an hour, never on a
        // page render (every read already leaves them out).
        NotificationHelper::purgeThrottled();

        // A site language with no published content language is said here too.
        ContentLanguageNotice::sync();

        // And an Alfa table an update left in another collation (raised or cleared, never converted).
        CollationCheck::syncNotice();

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->sendHeaders();

        echo NotificationHelper::renderBadge((bool) $this->input->getInt('open', 0));

        $this->app->close();
    }

    /**
     * Mark a notification read (records the current user).
     *
     *
     * @since  1.0.0
     */
    public function markRead(): void
    {
        $this->checkToken();

        $row = NotificationHelper::find(id: (int) $this->input->getInt('id', 0));

        $this->assertCanChange(row: $row);

        if ($row !== null) {
            NotificationHelper::markRead(id: (int) $row->id, userId: (int) $this->app->getIdentity()->id);
        }

        $this->respondJson(['ok' => true]);
    }

    /**
     * Dismiss a notification -> archive it to history.
     *
     *
     * @since  1.0.0
     */
    public function dismiss(): void
    {
        // 'request' so both the badge POST and the history-view GET link work (token still required).
        $this->checkToken('request');

        $row = NotificationHelper::find(id: (int) $this->input->getInt('id', 0));

        $this->assertCanChange(row: $row);

        if ($row !== null) {
            NotificationHelper::dismiss(id: (int) $row->id);
        }

        // The bell panel calls this over XHR and wants JSON; the history list
        // links to it with a plain anchor, and a browser landing on a JSON body
        // is a dead end; send that caller back to the list it came from.
        if ($this->wantsJson()) {
            $this->respondJson(['ok' => true]);
        }

        $this->app->enqueueMessage(Text::_('COM_ALFA_NOTIFY_DISMISSED_MSG'), 'message');
        $this->setRedirect(Route::_('index.php?option=com_alfa&view=notifications', false));
    }

    /**
     * Does this caller expect JSON (an explicit format, or a fetch/XHR request)?
     *
     *
     * @since  1.0.0
     */
    private function wantsJson(): bool
    {
        if (\in_array($this->input->getWord('format', ''), ['json', 'raw'], true)) {
            return true;
        }

        $server = $this->input->server;

        return strtolower((string) $server->getString('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest'
            || str_contains((string) $server->getString('HTTP_ACCEPT', ''), 'application/json');
    }

    /**
     * Guard: the current user may see the notification centre.
     *
     *
     * @throws NotAllowed When alfa.notifications.view is not granted.
     *
     * @since  2.0.0
     */
    private function assertCanView(): void
    {
        if (!NotificationHelper::canView()) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Guard: the current user may mark read and dismiss, and may see the row, when
     * there is one. The id is only a number in the request; without the row check,
     * anyone holding the permission could retire a notice meant for another group.
     *
     * @param object|null $row The notification from {@see NotificationHelper::find()}, or null.
     *
     *
     * @throws NotAllowed When the permission is missing or the row is hidden from the user.
     *
     * @since  2.0.0
     */
    private function assertCanChange(?object $row): void
    {
        if (!NotificationHelper::canChangeState() || ($row !== null && !NotificationHelper::canSee(notification: $row))) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Emit a JSON response and end the request.
     *
     * @param array $payload The data to encode.
     *
     *
     * @since  1.0.0
     */
    private function respondJson(array $payload): void
    {
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();

        echo json_encode($payload);

        $this->app->close();
    }
}
