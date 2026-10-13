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
 * onAlfaAdminAfterSave: the record is written; do your own side of it.
 *
 * READ    entity, view, screen (save), user, item (the record as STORED), ids, isNew, data
 * WRITE   your own tables
 *
 * The record now has an id (`item`, and `ids` for convenience), which is what a plugin needs to
 * attach its own rows to it:
 *
 *     public function onAlfaAdminAfterSave(AdminAfterSaveEvent $event): void
 *     {
 *         if (!$event->targets('product')) {
 *             return;
 *         }
 *
 *         $context = $event->getAdminContext();
 *         $id      = (int) ($context->ids[0] ?? 0);
 *
 *         if ($id === 0) {
 *             return;
 *         }
 *
 *         $this->storeWarranty($id, (int) ($context->data['params']['warranty_months'] ?? 0));
 *     }
 *
 * NO HANDLER OWNS THE RESPONSE. The save has already succeeded and the controller is about to
 * redirect or answer with JSON; echoing, redirecting, or exiting here hijacks a reply that is
 * not yours and breaks every caller that expected the component's. Contribute side effects
 * only, and report a problem by logging or enqueueing a message.
 *
 * `item` is the row re-read from the database, so it is null in the rare case the write landed but
 * the row cannot be loaded back; `ids` always carries the saved id. Prefer `ids` for the key.
 *
 * Throwing does not undo the save either: the record is committed by the time this fires. Work
 * that must be all-or-nothing with the record belongs in the model, not in a plugin.
 *
 * @since  2.0.0
 */
final class AdminAfterSaveEvent extends AlfaAdminEvent
{
    /**
     * @var string
     * @since  2.0.0
     */
    public const NAME = 'onAlfaAdminAfterSave';
}
