<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Admin;

\defined('_JEXEC') or die;

/**
 * onAlfaAdminListBulkActions: extra actions applied to the CHECKED rows.
 *
 * READ    entity, view, screen (list), user, state, items, ids, token
 * WRITE   bulkActions: {@see AdminActions}
 *
 * Same descriptor as a row action, pointed at the selection instead of one record:
 *
 *     public function onAlfaAdminListBulkActions(AdminListBulkActionsEvent $event): void
 *     {
 *         if (!$event->targets('order')) {
 *             return;
 *         }
 *
 *         $event->getAdminContext()->bulkActions->action('export')
 *             ->title('PLG_ALFA_EXPORT_SELECTED')
 *             ->icon('icon-download')
 *             ->task('orders.exportSelected')
 *             ->access('alfa.export.selected')
 *             ->confirm('PLG_ALFA_EXPORT_CONFIRM');
 *     }
 *
 * As on a row action, access() names the permission the action needs; a task of the plugin's own
 * without it is shown to no one.
 *
 * DECLARING an action is not EXECUTING it. This event only offers the verb; the work happens in
 * the plugin's own controller task, which runs on POST behind Joomla's native token check.
 * Nothing on a display render may write: this event fires while the grid is being drawn.
 *
 * @since  1.8.0
 */
final class AdminListBulkActionsEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminListBulkActions';
}
