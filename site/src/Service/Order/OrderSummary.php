<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Order;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Summary\SummaryRow;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * A placed order's money totals as labelled rows: the rows the cart page showed, read
 * back from what the order froze.
 *
 * The same SummaryRow values as the cart's own summary (CartSummary::rows()), so the
 * cart.summary layout prints both, and the same meaning for every row: the subtotal
 * is the lines' total with the struck price before their catalogue discounts, the tax
 * row the tax inside the lines, the shipping row the carriage as charged with the
 * carrier's price struck when a shipping rule reduced it and Free when one took it
 * whole. No price is recomputed: every figure is a frozen amount of the order, or the
 * canonical order formula's figure over them (OrderTotalHelper::exactFromArrays()).
 * Rules take no remove control: the order holds no code any more.
 *
 * @since  2.2.0
 */
final class OrderSummary
{
    /**
     * The summary rows in display order: subtotal, tax, discount, shipping, the
     * contributions, the rules, total.
     *
     * @param object $breakdown The order's totals as exact decimal texts (OrderTotalHelper::exactFromArrays()).
     * @param object[] $lines The order's lines, each with its frozen discount_amount_tax_incl.
     * @param object[] $shipments The order's active shipments, each with its shipping_cost_tax_incl.
     * @param object[] $rules The order's frozen cart rules, in the order they were applied.
     * @param object[] $contributions The order's frozen contributions, in their sort order.
     * @param Currency $currency The order's currency at its frozen scale.
     *
     * @return SummaryRow[]
     *
     * @since  2.2.0
     */
    public static function rows(object $breakdown, array $lines, array $shipments, array $rules, array $contributions, Currency $currency): array
    {
        $money = static fn (mixed $amount): Money => $amount instanceof Money ? $amount : Money::of((string) ($amount ?? '0'), $currency);

        $subtotal = $money($breakdown->items_tax_incl);
        $discount = Money::zero($currency);

        foreach ($lines as $line) {
            $discount = $discount->add($money($line->discount_amount_tax_incl ?? '0'));
        }

        $rows = [
            new SummaryRow(
                type: SummaryRow::SUBTOTAL,
                label: Text::_('COM_ALFA_CART_SUMMARY_SUBTOTAL'),
                amount: $subtotal,
                was: $discount->isZero() ? null : $subtotal->add($discount),
            ),
        ];

        // The tax inside the lines, the figure the cart row states; carriage tax is
        // inside the shipping row's own figure.
        $tax = $subtotal->subtract($money($breakdown->items_tax_excl));

        if (!$tax->isZero()) {
            $rows[] = new SummaryRow(
                type: SummaryRow::TAX,
                label: Text::_('COM_ALFA_CART_SUMMARY_TAX'),
                amount: $tax,
            );
        }

        if (!$discount->isZero()) {
            $rows[] = new SummaryRow(
                type: SummaryRow::DISCOUNT,
                label: Text::_('COM_ALFA_CART_SUMMARY_DISCOUNT'),
                amount: $discount,
                reduction: true,
            );
        }

        // The cart's verdict on the shipping row, from the frozen rules: a shipping
        // rule that took something strikes the carrier's price, and one that left
        // nothing to charge prints Free. The net is the formula's, which already
        // took the rules off the carriage.
        $charged = $money($breakdown->shipping_tax_incl);
        $carrier = Money::zero($currency);
        $taken = Money::zero($currency);

        foreach ($shipments as $shipment) {
            $carrier = $carrier->add($money($shipment->shipping_cost_tax_incl ?? '0'));
        }

        foreach ($rules as $rule) {
            if ((string) ($rule->discount_class ?? 'order') === 'shipping') {
                $taken = $taken->add(self::ruleAmount(rule: $rule, currency: $currency));
            }
        }

        $benefit = $taken->isPositive();

        $rows[] = new SummaryRow(
            type: SummaryRow::SHIPPING,
            label: Text::_('COM_ALFA_CART_SHIPPING'),
            amount: $charged,
            was: $benefit ? $carrier : null,
            free: $benefit && $charged->isZero(),
        );

        foreach ($contributions as $contribution) {
            $rows[] = new SummaryRow(
                type: SummaryRow::CONTRIBUTION,
                label: MultilingualHelper::resolveLangMap($contribution->name ?? ''),
                amount: $money($contribution->amount_tax_incl ?? '0'),
            );
        }

        foreach ($rules as $rule) {
            // A shipping rule speaks on the shipping row.
            if ((string) ($rule->discount_class ?? 'order') === 'shipping') {
                continue;
            }

            $name = trim((string) ($rule->name ?? ''));

            $rows[] = new SummaryRow(
                type: SummaryRow::RULE,
                label: $name !== '' ? $name : (string) ($rule->code ?? ''),
                amount: self::ruleAmount(rule: $rule, currency: $currency),
                reduction: true,
            );
        }

        $rows[] = new SummaryRow(
            type: SummaryRow::TOTAL,
            label: Text::_('COM_ALFA_CART_TOTAL'),
            amount: $money($breakdown->grand_total_tax_incl),
        );

        return $rows;
    }

    /**
     * What a frozen rule took off, tax included: its own inclusive figure, or, on a
     * row frozen before that figure existed, its net, as the order formula reads it.
     *
     * @param object $rule The frozen #__alfa_order_cart_rule row.
     * @param Currency $currency The order's currency.
     *
     * @since  2.2.0
     */
    private static function ruleAmount(object $rule, Currency $currency): Money
    {
        $incl = Money::of((string) ($rule->value_tax_incl ?? '0'), $currency);

        return $incl->isPositive() ? $incl : Money::of((string) ($rule->value_tax_excl ?? '0'), $currency);
    }
}
