<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Inventory;

\defined('_JEXEC') or die;

/**
 * InventoryRestored after-event (onInventoryRestored, 2.2.1): stock was put back on the shelf, after the commit, side
 * effects only, as the other after events. Raised by InventoryService::restore() (an order's cancellation or line cut,
 * a credit note's return) and by InventoryService::reverseRestores() (a credit note voided: what it put back is taken
 * off again, isReversal() true). Named arguments: sourceType and sourceId (the command's source), reversal, movements
 * (one entry per item, combination and location, its signed quantity: positive when stock came back, negative when a
 * reversal took it off again) and result (the command's summary, operation_id included).
 *
 * @since  2.2.1
 */
class InventoryRestoredEvent extends InventoryAfterEvent
{
    /**
     * Whether this took back an earlier restore (a credit note voided): the movements are then negative.
     *
     * @since  2.2.1
     */
    public function isReversal(): bool
    {
        return (bool) ($this->arguments['reversal'] ?? false);
    }

    /**
     * What moved, by item, combination and location.
     *
     * @return list<array{itemId: int, combinationId: int, locationId: int, quantity: float}>
     *
     * @since  2.2.1
     */
    public function movements(): array
    {
        return (array) ($this->arguments['movements'] ?? []);
    }
}
