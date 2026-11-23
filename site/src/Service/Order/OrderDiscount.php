<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;

/**
 * One discount the order froze when it was placed (a cart rule as it applied), read.
 *
 * Its money is the rule's own, frozen at placement: what came off tax included, tax excluded, and its own tax, never
 * re-derived from the items' rates. A gateway that sends the provider order lines with their tax sends each discount
 * as its own line from these figures, instead of one untaxed balancing line.
 *
 *     foreach ($order->discounts() as $discount) {
 *         if ($discount->kind() !== OrderDiscount::KIND_SHIPPING) { ...a line of -$discount->amount(), tax -$discount->tax()... }
 *     }
 *
 * The figures are positive: what came off. A shipping discount (KIND_SHIPPING) reduces the carriage, as the order's
 * totals read it; the others reduce the goods.
 *
 * READ-ONLY. Made by Order::discounts().
 *
 * @since  2.3.0
 */
final class OrderDiscount
{
    /**
     * A discount on products.
     *
     * @since  2.3.0
     */
    public const KIND_PRODUCT = 'product';

    /**
     * A discount on the order.
     *
     * @since  2.3.0
     */
    public const KIND_ORDER = 'order';

    /**
     * A reduction of the carriage (a free or cheaper shipping rule): the order's totals take it off the shipping.
     *
     * @since  2.3.0
     */
    public const KIND_SHIPPING = 'shipping';

    /**
     * @param object $row The `#__alfa_order_cart_rule` record.
     * @param Currency $currency The order's currency.
     *
     * @since  2.3.0
     */
    public function __construct(private readonly object $row, private readonly Currency $currency)
    {
    }

    /** @return int The frozen discount's own id. @since 2.3.0 */
    public function id(): int
    {
        return (int) ($this->row->id_order_cart_rule ?? 0);
    }

    /** @return int The cart rule it was frozen from (the rule may have been edited or deleted since). @since 2.3.0 */
    public function cartRuleId(): int
    {
        return (int) ($this->row->id_cart_rule ?? 0);
    }

    /** @return string The rule's name as it applied. @since 2.3.0 */
    public function name(): string
    {
        return (string) ($this->row->name ?? '');
    }

    /** @return string|null The code the customer entered, as the rule knows it; null for an automatic rule. @since 2.3.0 */
    public function code(): ?string
    {
        $code = $this->row->code ?? null;

        return $code === null || $code === '' ? null : (string) $code;
    }

    /** @return string KIND_PRODUCT, KIND_ORDER or KIND_SHIPPING. @since 2.3.0 */
    public function kind(): string
    {
        return (string) ($this->row->discount_class ?? self::KIND_ORDER);
    }

    /** @return bool Whether the rule granted free shipping. @since 2.3.0 */
    public function freeShipping(): bool
    {
        return (int) ($this->row->free_shipping ?? 0) === 1;
    }

    /**
     * What came off, tax included: the figure the order's totals subtract.
     *
     * A row frozen with no figure tax included (an old row) answers its figure tax excluded, as the order's totals
     * read it, so the lines a gateway builds add up to the order's total.
     *
     *
     * @since  2.3.0
     */
    public function amount(): Money
    {
        $incl = $this->money($this->row->value_tax_incl ?? '0');

        return $incl->isPositive() ? $incl : $this->amountExcl();
    }

    /** @return Money What came off, tax excluded. @since 2.3.0 */
    public function amountExcl(): Money
    {
        return $this->money($this->row->value_tax_excl ?? '0');
    }

    /** @return Money The discount's own tax: amount() less amountExcl(). @since 2.3.0 */
    public function tax(): Money
    {
        return $this->amount()->subtract($this->amountExcl());
    }

    /**
     * A frozen figure in the order's currency, read as the decimal text the column holds.
     *
     * @param mixed $value The column's value.
     *
     *
     * @since  2.3.0
     */
    private function money(mixed $value): Money
    {
        return Money::of(\is_string($value) ? $value : (string) $value, $this->currency);
    }
}
