<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

defined('_JEXEC') or die;

/**
 * One line of a cart about to become an order, as a plugin may see it.
 *
 * Three facts, all settled before placement began: which line, which product,
 * how many. Nothing here is a price: a placement validator decides whether
 * the order may proceed, not what it costs, and a validator that could read a
 * total would be reading a number the placement is still assembling.
 *
 * @since  1.10.0
 */
final class PlacementLine
{
    /**
     * @param int $lineId The cart line.
     * @param int $itemId The product on it.
     * @param int $quantity How many.
     * @param int|null $combinationId The variant, when the product has them.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly int $lineId,
        public readonly int $itemId,
        public readonly int $quantity,
        public readonly ?int $combinationId = null,
    ) {
    }
}
