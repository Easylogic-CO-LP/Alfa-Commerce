<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\CartRules;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use InvalidArgumentException;

/**
 * One applied cart rule: the engine's immutable result row. Carries the
 * customer-facing identity (name/code), the stacking class it consumed, the
 * computed discount in both tax spaces and, for product-class rules, the
 * cart line ids it claimed (one product discount per line; hard invariant).
 *
 * The discount is the Money the engine computed, in the cart's currency: a
 * float carries no more than fifteen of its digits, and the frozen row is
 * handed the exact decimal of it.
 *
 * Frozen to `#__alfa_order_cart_rule` at placement; displayed one row per
 * rule on the cart totals.
 *
 * @since  2.0.0
 */
final class AppliedCartRule
{
    /**
     * @param int $id Rule PK.
     * @param string $name Rule name (totals row label).
     * @param string|null $code The activating code, null for automatic rules.
     * @param string $discountClass 'product' | 'order' | 'shipping'.
     * @param string $action The action type that produced the discount.
     * @param Money $discountTaxIncl Discount amount, tax inclusive.
     * @param Money $discountTaxExcl Discount amount, tax exclusive.
     * @param bool $freeShipping Whether this rule zeroes the shipping.
     * @param int[] $lineIds Cart line ids claimed (product class).
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $code,
        public readonly string $discountClass,
        public readonly string $action,
        public readonly Money $discountTaxIncl,
        public readonly Money $discountTaxExcl,
        public readonly bool $freeShipping = false,
        public readonly array $lineIds = [],
    ) {
    }

    /**
     * The tax-inclusive discount, as the Money of the cart it was computed in.
     *
     * @param Currency $currency The cart's currency.
     *
     *
     * @throws InvalidArgumentException When the cart's currency is not the discount's.
     *
     * @since  2.0.0
     */
    public function discountTaxInclMoney(Currency $currency): Money
    {
        if (!$this->discountTaxIncl->getCurrency()->equals($currency)) {
            throw new InvalidArgumentException(sprintf(
                'The discount of rule %d is in %s, not %s.',
                $this->id,
                $this->discountTaxIncl->getCurrency()->getCode(),
                $currency->getCode(),
            ));
        }

        return $this->discountTaxIncl;
    }
}
