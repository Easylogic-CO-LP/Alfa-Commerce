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
 * One stored price index row that changed ({@see PricesChangedEvent}): the row's identity (the index's primary
 * key: the pricing context, the product, the combination and valid_from; the context's currency class, the
 * place it is priced at, its set of pricing groups and its customer beside it), its window's end, and its final price
 * before and after, as the column's exact decimal text. No old price: the row is new, or had no valid price (a NULL
 * row); no new price: the row is removed, or has no valid price now. A price of 0 is a price (the product is free).
 *
 * The index keeps a row for the product (combination 0) and for each combination with price rows of its own; a
 * combination without them is priced from the product's row. So a change of combination 0 is the change of the product
 * and of every combination of it without its own price rows, and a combination gaining or losing its own price rows
 * shows as its row new or removed.
 *
 * @since  2.2.0
 */
final readonly class PriceChange
{
    /**
     * @param int $itemId The product.
     * @param int $combinationId The combination with price rows of its own; 0 for the product's own row, the price of
     *                           every combination without its own price rows.
     * @param int $currencyId The context's currency class; 0 for every currency no price row names.
     * @param int $placeId The place the context is priced at; 0 for none.
     * @param int $groupSetId The context's set of pricing groups (#__alfa_price_group_sets.id).
     * @param string $validFrom When the row's window starts (1000-01-01 00:00:00 for the live window).
     * @param string|null $validUntil When it ends; null when open-ended. The value the row has now (before, for a removed
     *                                row).
     * @param string|null $oldFinalPrice The final price before, as the column's text; null for a new row.
     * @param string|null $newFinalPrice The final price after, as the column's text; null for a removed row.
     * @param int $contextId The pricing context (#__alfa_price_contexts.id).
     * @param int $userId The context's customer; 0 for a context of a set of pricing groups.
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
        public int $contextId = 0,
        public int $userId = 0,
    ) {
    }
}
