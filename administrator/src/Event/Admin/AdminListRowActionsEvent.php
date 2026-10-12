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
 * onAlfaAdminListRowActions: extra actions on every row of the grid.
 *
 * READ    entity, view, screen (list), user, state, items, ids, rowData, token
 * WRITE   actions: {@see AdminActions}
 *
 * Declared once for the screen, rendered on each row; the descriptor's data resolver is what
 * makes it row-specific (and returning null for a row renders nothing there, so an action can
 * apply to only some rows):
 *
 *     public function onAlfaAdminListRowActions(AdminListRowActionsEvent $event): void
 *     {
 *         if (!$event->targets('order')) {
 *             return;
 *         }
 *
 *         $event->getAdminContext()->actions->action('resend')
 *             ->title('PLG_ALFA_NOTIFY_RESEND')
 *             ->icon('icon-mail')
 *             ->task('orders.resendNotification')
 *             ->access('alfa.notify.resend');
 *     }
 *
 * access() names the permission a user needs to see the action. Without it Alfa works one out, but
 * only for its own tasks and screens: for a task of the plugin's own, like this one, it cannot, and
 * the action is shown to no one until access() is declared.
 *
 * The task submits the grid's own form, so it arrives with the CSRF token the screen already
 * carries, the same write boundary as every native list action. Never use url() to change
 * state: a link is a GET, and a GET that writes is a bug a crawler will find for you.
 *
 * @since  1.8.0
 */
final class AdminListRowActionsEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminListRowActions';
}
