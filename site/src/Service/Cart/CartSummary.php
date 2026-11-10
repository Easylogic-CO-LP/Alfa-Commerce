<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Summary\SummaryRow;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * The cart's money totals as labelled rows.
 *
 * Decides which rows exist and what each one is called; computes nothing. Every
 * figure is a Money the reading already holds, so the summary cannot disagree with
 * the totals it describes. The cart.summary layout only prints the rows.
 *
 * @since  2.2.0
 */
final class CartSummary
{
    /**
     * The summary rows in display order: subtotal, tax, discount, shipping, the
     * contributions, the rules, total.
     *
     * @param CartTotalsView $totals What the cart costs.
     * @param CartRulesView $rules What the cart rules concluded.
     * @param Currency $currency What the cart is priced in.
     *
     * @return SummaryRow[]
     *
     * @since  2.2.0
     */
    public static function rows(CartTotalsView $totals, CartRulesView $rules, Currency $currency): array
    {
        $hasDiscount = !$totals->discount->isZero();

        $rows = [
            new SummaryRow(
                type: SummaryRow::SUBTOTAL,
                label: Text::_('COM_ALFA_CART_SUMMARY_SUBTOTAL'),
                amount: $totals->goods,
                was: $hasDiscount ? $totals->beforeDiscount : null,
            ),
        ];

        // Tax and catalogue discounts are already inside the subtotal; each row
        // appears only when there is something to state. The tax is the goods tax,
        // the figure inside the subtotal it sits under; carriage tax is inside the
        // shipping row's own figure.
        if (!$totals->tax->isZero()) {
            $rows[] = new SummaryRow(
                type: SummaryRow::TAX,
                label: Text::_('COM_ALFA_CART_SUMMARY_TAX'),
                amount: $totals->tax,
            );
        }

        if ($hasDiscount) {
            $rows[] = new SummaryRow(
                type: SummaryRow::DISCOUNT,
                label: Text::_('COM_ALFA_CART_SUMMARY_DISCOUNT'),
                amount: $totals->discount,
                reduction: true,
            );
        }

        // Free shipping is a benefit the engine classified; the row renders that
        // verdict and compares no numbers. It is always shown.
        $shipping = $totals->shippingBenefit;

        $rows[] = new SummaryRow(
            type: SummaryRow::SHIPPING,
            label: Text::_('COM_ALFA_CART_SHIPPING'),
            amount: $totals->shipment,
            was: \in_array($shipping->benefit, ['free_shipping', 'partial'], true)
                ? Money::of($shipping->cost, $currency)
                : null,
            free: $shipping->benefit === 'free_shipping',
        );

        foreach ($totals->contributions as $contribution) {
            $rows[] = new SummaryRow(
                type: SummaryRow::CONTRIBUTION,
                label: (string) $contribution->label,
                amount: $contribution->amount_tax_incl_money,
            );
        }

        foreach ($rules->applied as $rule) {
            // A shipping rule speaks on the shipping row.
            if ($rule->discountClass === 'shipping') {
                continue;
            }

            // Kept even when it takes nothing off: a coded rule's row carries the
            // only control that removes its code.
            $rows[] = new SummaryRow(
                type: SummaryRow::RULE,
                label: $rule->name,
                amount: $rule->discountTaxInclMoney($currency),
                reduction: true,
                removeCode: $rule->code,
            );
        }

        $rows[] = new SummaryRow(
            type: SummaryRow::TOTAL,
            label: Text::_('COM_ALFA_CART_TOTAL'),
            amount: $totals->grand,
        );

        return $rows;
    }
}
