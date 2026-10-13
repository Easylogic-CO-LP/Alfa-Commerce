<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Items List Prepare Event (onItemsListPrepare)
 *
 * The WHOLE-PAGE hook. Dispatched by the SITE ItemsModel ONCE PER PLUGIN as the
 * LAST step of getItems() (and of the single-card reload), AFTER every card is
 * fully built: fields, resolved variant, price, per-field onItemListPrepare
 * surfaces and the resolved cart, including the core's Choose options decision.
 * Subject = the items array. It is the "extra" hook for cross-item work: a
 * plugin may mutate items directly, and has the last word on them.
 *
 *   public function onItemsListPrepare($event): void
 *   {
 *       if (!$event instanceof ItemsListPrepareEvent) { return; }
 *       foreach ($event->getItems() as $item) { ... }
 *   }
 *
 * The per-item cart region is decorated by onItemListPrepare (singular), not
 * here: this event carries no cart setters; a plugin that must change a card's
 * cart here writes $item->cart itself.
 *
 * @since  2.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Event\ItemFields;

use Joomla\CMS\Event\AbstractImmutableEvent;

defined('_JEXEC') or die;

class ItemsListPrepareEvent extends AbstractImmutableEvent
{
    /**
     * The loaded, hydrated page of items (subject). Each item carries its
     * resolved ->fields (type, definition params, localized value pool).
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    public function getItems(): array
    {
        return $this->arguments['subject'] ?? [];
    }
}
