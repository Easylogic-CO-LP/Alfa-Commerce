<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Catalog;

\defined('_JEXEC') or die;

/**
 * One stored price index row that changed (2.2.0, {@see PricesChangedEvent}): the row's identity (the index's primary
 * key: item, combination, currency, place, set of pricing groups, valid_from), its window's end, and its final price
 * before and after, as the column's exact decimal text. A new row has no old price; a removed row has no new one.
 *
 * @since  2.2.0
 */
final readonly class PriceChange
{
    /**
     * @param int $itemId The product.
     * @param int $combinationId The combination; 0 for the product's own row.
     * @param int $currencyId The currency; 0 for any.
     * @param int $placeId The place; 0 for any.
     * @param int $groupSetId The set of pricing groups (#__alfa_price_group_sets.id).
     * @param string $validFrom When the row's window starts (1000-01-01 00:00:00 for the live window).
     * @param string|null $validUntil When it ends; null when open-ended. The value the row has now (before, for a removed
     *                                row).
     * @param string|null $oldFinalPrice The final price before, as the column's text; null for a new row.
     * @param string|null $newFinalPrice The final price after, as the column's text; null for a removed row.
     *
     * @since  2.2.0
     */
    public function __construct(
        public int $itemId,
        public int $combinationId,
        public int $currencyId,
        public int $placeId,
        public int $groupSetId,
        public string $validFrom,
        public ?string $validUntil,
        public ?string $oldFinalPrice,
        public ?string $newFinalPrice,
    ) {
    }
}
