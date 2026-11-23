<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

/**
 * The unit a SKU names ({@see SkuIndex::unit()}): a product, or one of its combinations.
 *
 * @since  2.3.0
 */
final class SkuUnit
{
    /**
     * @param int $itemId The product.
     * @param int $combinationId The combination; 0 for the product itself.
     *
     * @since  2.3.0
     */
    public function __construct(
        public readonly int $itemId,
        public readonly int $combinationId,
    ) {
    }
}
