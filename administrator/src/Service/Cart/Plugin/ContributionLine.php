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
 * One line of a cart, as something that may attract a charge.
 *
 * A contribution plugin decides whether this line brings a charge with it and
 * at what tax rate. It is told which line, which product, and the rate that
 * line is taxed at, which is all any first-party contributor has ever read.
 *
 * The rate is a number, not a price object: a contributor that could reach the
 * price could reach the discounts, the currency and the catalogue behind it,
 * and none of that is what an extension point is for.
 *
 * @since  1.10.0
 */
final class ContributionLine
{
    /**
     * @param int $lineId The cart line.
     * @param int $itemId The product on it.
     * @param float $taxRate The line's effective tax rate; 0.0 when the line
     *                       carries no price, which is the case for a product
     *                       withdrawn from the catalogue.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly int $lineId,
        public readonly int $itemId,
        public readonly float $taxRate,
    ) {
    }
}
