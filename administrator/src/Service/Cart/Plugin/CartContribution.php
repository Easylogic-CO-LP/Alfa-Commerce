<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart\Plugin;

use InvalidArgumentException;

defined('_JEXEC') or die;

/**
 * CartContribution: one declarative CART-level charge a feature plugin
 * returns from onCartTotals: the cart-scope twin of PriceContribution (same
 * grammar, different scope). An immutable calculation artifact: the plugin
 * knows nothing about totals, the totals fold knows nothing about
 * subscriptions/COD/wrapping.
 *
 * It lives beside the other cart extension-point types because that is what
 * it is: what a contributor answers with. The fold that turns it into money
 * is the pricing engine's, and holds no reference back here.
 *
 * Unlike an item contribution it carries its OWN tax rate: a sign-up fee, a
 * COD surcharge and gift wrapping each tax independently of any product line.
 * Amounts are tax-EXCLUSIVE: '+' is a net currency amount, '%' is a percent
 * of the cart's net merchandise+shipping base; tax is added on top from
 * taxRate. Every fold result becomes a labeled totals segment and freezes to
 * `#__alfa_order_contributions` at checkout.
 *
 * v1 policy: only ADDITIVE operations ('+', '%'). Reductions belong to the
 * cart rules engine: a negative contribution would bypass the discount
 * accounting. Credits may extend the vocabulary later.
 *
 * @since  2.0.0
 */
final class CartContribution
{
    /**
     * @param string $code Stable id for the line, e.g. 'signup_fee'.
     * @param string $label Human totals label, e.g. 'Sign-up fee'.
     * @param string $operation '+' (net currency amount) or '%' (percent of the
     *                          goods' net after the cart rules plus the
     *                          shipping's net after the shipping rules).
     * @param float $amount Magnitude: currency for '+', percent for '%'.
     * @param float $taxRate The contribution's OWN tax rate (percent).
     * @param int $sortOrder Fold/display order; lower folds first.
     * @param string $source Owning plugin element (the collector fills it when empty).
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $operation,
        public readonly float $amount,
        public readonly float $taxRate = 0.0,
        public readonly int $sortOrder = 0,
        public readonly string $source = '',
    ) {
        if (!\in_array($operation, ['+', '%'], true)) {
            throw new InvalidArgumentException(
                'Cart contributions are additive: operation must be "+" or "%", got "' . $operation . '".'
                . ' Reductions belong to cart rules.',
            );
        }
    }
}
