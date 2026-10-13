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
 * onAlfaAdminAfterDelete: the records are gone; clean up your own.
 *
 * READ    entity, view, screen (delete), user, ids
 * WRITE   your own tables
 *
 *     public function onAlfaAdminAfterDelete(AdminAfterDeleteEvent $event): void
 *     {
 *         if (!$event->targets('product')) {
 *             return;
 *         }
 *
 *         $this->dropWarranties($event->getAdminContext()->ids);
 *     }
 *
 * `ids` is the list the model finished on, not the raw selection: a record the user may not delete
 * has already been pruned from it, and a delete the component refused outright never reaches this
 * phase at all. It is still the model's working list rather than a receipt (Joomla stops the loop
 * if a row has vanished mid-delete), so make the cleanup IDEMPOTENT and keyed on the ids, which is
 * what a DELETE ... WHERE IN is anyway.
 *
 * NO HANDLER OWNS THE RESPONSE: the controller is about to redirect or answer with JSON.
 * Contribute side effects only; never echo, redirect or exit. Throwing does not bring the
 * records back either.
 *
 * @since  2.0.0
 */
final class AdminAfterDeleteEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminAfterDelete';
}
