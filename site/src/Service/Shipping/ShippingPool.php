<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Shipping;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * A shipping charge whose money is fixed, taken in parts: the net of each part.
 *
 * ONE RULE FOR EVERY PART OF A FIXED SHIPPING CHARGE. A shipping rule in the
 * cart (and so the rule row the order freezes), the shipping discount on an
 * invoice, and a credit note's part of a shipping line or of its discount all
 * take their net here. A full invoice therefore states the order's frozen
 * figures, and the parts of a charge add up to its net exactly.
 *
 * The net of everything taken so far is the charge's net times the gross taken
 * over the charge's gross, rounded once, half away from zero; a part's net is
 * that figure after the part less that figure before it. Every part keeps the
 * charge's own net/gross ratio, which a rate cannot reproduce once the amounts
 * are rounded: of 10.00 = 8.06 + 1.94 at 24%, a part of 0.80 takes 0.64 of the
 * net (0.80 x 8.06 / 10.00), never 0.80 / 1.24 = 0.65.
 *
 * @since  1.14.0
 */
final class ShippingPool
{
    /**
     * @param Money $gross The charge's gross.
     * @param Money $net The charge's net, in the same currency, between nothing and the gross.
     *
     * @throws InvalidArgumentException When the two figures are not one charge.
     *
     * @since  1.14.0
     */
    public function __construct(
        public readonly Money $gross,
        public readonly Money $net,
    ) {
        if (!$net->getCurrency()->equals($gross->getCurrency())) {
            throw new InvalidArgumentException('A shipping charge is stated in one currency.');
        }

        if ($net->isNegative() || $net->greaterThan($gross)) {
            throw new InvalidArgumentException(sprintf(
                'A shipping charge\'s net %s is between nothing and its gross %s.',
                $net->getAmount(),
                $gross->getAmount(),
            ));
        }
    }

    /**
     * The net of a gross taken from the charge so far.
     *
     * @param Money $taken The gross taken, between nothing and the charge's gross.
     *
     * @throws InvalidArgumentException When more is taken than the charge holds, or less than nothing.
     *
     * @since  1.14.0
     */
    public function netTaken(Money $taken): Money
    {
        if ($taken->isNegative() || $taken->greaterThan($this->gross)) {
            throw new InvalidArgumentException(sprintf(
                'A part of a shipping charge takes between nothing and its gross %s, not %s.',
                $this->gross->getAmount(),
                $taken->getAmount(),
            ));
        }

        if (!$this->gross->isPositive()) {
            return $this->net;
        }

        return $this->net->timesRatio(numerator: $taken->getMinorUnits(), denominator: $this->gross->getMinorUnits());
    }

    /**
     * The net of one part of the charge, after the parts taken before it.
     *
     * @param Money $part The part's gross.
     * @param Money $before The gross the parts before it took.
     *
     * @throws InvalidArgumentException When the parts take more than the charge holds.
     *
     * @since  1.14.0
     */
    public function netOf(Money $part, Money $before): Money
    {
        return $this->netTaken($before->add($part))->subtract($this->netTaken($before));
    }
}
