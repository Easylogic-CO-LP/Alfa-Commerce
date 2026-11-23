<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use RuntimeException;

/**
 * A write of a unit's own prices (a product's, or a combination's) refused because the prices it was made on changed
 * since they were read ({@see PricesState::assertUnchanged()}): nothing of the write is kept, and the writer reads the
 * prices again.
 *
 * @since  2.3.0
 */
final class PricesStateChanged extends RuntimeException
{
    /**
     * @param int $itemId The product.
     * @param int $combinationId The combination whose prices were written; 0 for the product's own.
     *
     * @since  2.3.0
     */
    public function __construct(public readonly int $itemId, public readonly int $combinationId = 0)
    {
        parent::__construct($combinationId > 0
            ? 'prices: the combination\'s prices changed since you read them (prices_state). Read it again. Nothing was saved.'
            : 'prices: the product\'s prices changed since you read them (prices_state). Read it again. Nothing was saved.');
    }
}
