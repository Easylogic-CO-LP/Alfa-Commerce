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
 * onAlfaAdminToolbar: the screen's toolbar, after the component has drawn its own buttons.
 *
 * READ    entity, view, screen (list or edit), user, state, layout, item, token
 * WRITE   toolbar: the live Joomla Toolbar
 *
 * The accumulator is Joomla's own Toolbar rather than a descriptor list, because a toolbar
 * button is already a first-class Joomla object with its own ACL, dropdown and confirm
 * handling; re-describing it would only lose features:
 *
 *     public function onAlfaAdminToolbar(AdminToolbarEvent $event): void
 *     {
 *         if (!$event->isListScreen() || !$event->targets('order')) {
 *             return;
 *         }
 *
 *         $event->getAdminContext()->toolbar
 *             ->standardButton('export', 'PLG_ALFA_EXPORT', 'orders.export')
 *             ->icon('icon-download')
 *             ->listCheck(true);
 *     }
 *
 * Fires LAST, so the component's buttons are already in place and a contribution lands after
 * them. The core buttons are the component's; a plugin adds, it does not remove: taking away
 * Save or Delete leaves the screen with no way to do its job.
 *
 * @since  1.8.0
 */
final class AdminToolbarEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  1.8.0
     */
    public const NAME = 'onAlfaAdminToolbar';
}
