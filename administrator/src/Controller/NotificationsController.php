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
use Joomla\CMS\MVC\Controller\AdminController;

/**
 * Notifications list controller — list CRUD plus the async toolbar endpoints the
 * backend badge/panel use: `panel` (render the active list as JSON+HTML), `markRead`
 * and `dismiss`. All are backend-only and ACL-gated; reads go through
 * {@see \Alfa\Component\Alfa\Administrator\Model\NotificationsModel}, writes through
 * {@see NotificationHelper}.
 *
 * @since  1.0.5
 */
class NotificationsController extends AdminController
{
    /**
     * Proxy for getModel (defaults to the singular item model).
     *
     * @param string $name Model name.
     * @param string $prefix Class prefix.
     * @param array $config Configuration.
     *
     *
     * @since   1.0.5
     */
    public function getModel($name = 'Notification', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }

    /**
     * Async endpoint for the toolbar badge. Returns the rendered badge COMPONENT (button
     * + panel) as HTML — the same `renderBadge()` the toolbar uses on page load, so the
     * JS just swaps the node in place (no client-side building). `&open=1` preserves the
     * open state across the refresh.
     *
     *
     * @since   1.0.5
     */
    public function panel(): void
    {
        $this->assertManage();

        // This async fetch (fired after page load by integrity-check.js) is where the
        // 24h-cached integrity check runs — never during page render. Then it returns the
        // freshly-rendered component so the badge reloads with any change.
        SyncHelper::syncIntegrity();

        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->sendHeaders();

        echo NotificationHelper::renderBadge((bool) $this->input->getInt('open', 0));

        $this->app->close();
    }

    /**
     * Mark a notification read (records the current user).
     *
     *
     * @since   1.0.5
     */
    public function markRead(): void
    {
        $this->checkToken();
        $this->assertManage();

        $id = (int) $this->input->getInt('id', 0);

        if ($id > 0) {
            NotificationHelper::markRead(id: $id, userId: (int) $this->app->getIdentity()->id);
        }

        $this->respondJson(['ok' => true]);
    }

    /**
     * Dismiss a notification → archive it to history.
     *
     *
     * @since   1.0.5
     */
    public function dismiss(): void
    {
        // 'request' so both the badge POST and the history-view GET link work (token still required).
        $this->checkToken('request');
        $this->assertManage();

        $id = (int) $this->input->getInt('id', 0);

        if ($id > 0) {
            NotificationHelper::dismiss(id: $id);
        }

        $this->respondJson(['ok' => true]);
    }

    /**
     * Guard: the current user may manage com_alfa.
     *
     *
     * @since   1.0.5
     */
    private function assertManage(): void
    {
        if (!$this->app->getIdentity()->authorise('core.manage', 'com_alfa')) {
            throw new \Joomla\CMS\Access\Exception\NotAllowed(\Joomla\CMS\Language\Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Emit a JSON response and end the request.
     *
     * @param array $payload The data to encode.
     *
     *
     * @since   1.0.5
     */
    private function respondJson(array $payload): void
    {
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->sendHeaders();

        echo json_encode($payload);

        $this->app->close();
    }
}
