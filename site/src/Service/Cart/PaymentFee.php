<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingCharge;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingPriceEntryMode;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingTaxResolver;
use UnexpectedValueException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The fee a payment method charges on an order.
 *
 * THE ONLY READER OF THE FEE SETTINGS. A payment method states a fixed amount (fee_amount), a percentage of the order
 * total before the fee (fee_percent), what both mean (fee_entry_mode) and the tax rules the fee is taxed with
 * (#__alfa_payment_fee_taxes). The cart's totals and the checkout's method lists ask this; nothing else reads them.
 *
 * ONE CHARGE, AS A CARRIER'S IS. The fixed amount plus the percentage of the total in the entry mode's terms (the
 * gross under tax included, the net under tax excluded), the percentage rounded once at the currency's scale, is the
 * figure entered; it is read through the entry mode and taxed with the chain the method's rules give at the delivery
 * destination, exactly as a shipping method's price is (ShippingCharge::fromEntered()). So the fee's net, tax and
 * gross are one canonical charge with the rate it was taxed at, and it reaches the order as a contribution of its own
 * (code {@see self::CODE}), never through a second total.
 *
 * @since  2.2.2
 */
final class PaymentFee
{
    /**
     * The contribution code of a payment method's fee: the cart's totals line and the order's frozen charge.
     *
     * @since  2.2.2
     */
    public const CODE = 'payment_fee';

    /**
     * The fee a method charges on an order whose total before the fee is the given amount, or null when it charges
     * none: a method with no fee, an order with nothing to pay, a figure that comes to nothing.
     *
     * @param object $method The payment method as the method lists read it (fee_amount, fee_percent, fee_entry_mode,
     *                       and fee_taxes as a comma list of tax rule ids).
     * @param Money $totalIncl The order total before the fee, tax included.
     * @param Money $totalExcl The same, tax excluded.
     * @param int[] $destination The delivery place and every place containing it, nearest first; empty when unknown.
     * @param PriceContext $audience The customer's groups, user and reference date.
     *
     * @return ShippingCharge|null The fee's net, tax and gross, with its rate; null for none.
     *
     * @throws UnexpectedValueException When the method stores an entry mode that is neither.
     *
     * @since  2.2.2
     */
    public static function of(object $method, Money $totalIncl, Money $totalExcl, array $destination, PriceContext $audience): ?ShippingCharge
    {
        $amount = trim((string) ($method->fee_amount ?? ''));
        $percent = trim((string) ($method->fee_percent ?? ''));
        $amount = is_numeric($amount) ? $amount : '0';
        $percent = is_numeric($percent) ? $percent : '0';

        // No fee, or nothing to pay: a free order and a request for an offer are never charged one.
        if (((float) $amount <= 0.0 && (float) $percent <= 0.0) || !$totalIncl->isPositive()) {
            return null;
        }

        $mode = ShippingPriceEntryMode::tryFrom((string) ($method->fee_entry_mode ?? ''))
            ?? throw new UnexpectedValueException(\sprintf(
                'Payment method #%d stores the fee entry mode "%s", which is neither tax included nor tax excluded.',
                (int) ($method->id ?? 0),
                (string) ($method->fee_entry_mode ?? ''),
            ));
        $total = $mode === ShippingPriceEntryMode::TaxIncluded ? $totalIncl : $totalExcl;
        $currency = $total->getCurrency();
        $entered = Money::of($amount, $currency)->add($total->percentage($percent));

        if (!$entered->isPositive()) {
            return null;
        }

        $chain = (new ShippingTaxResolver())->resolveRules(
            taxIds:      array_map('intval', explode(',', (string) ($method->fee_taxes ?? ''))),
            destination: $destination,
            audience:    $audience,
            currency:    $currency,
        );

        return ShippingCharge::fromEntered(
            entered: $entered,
            mode:    $mode,
            chain:   $chain,
            rate:    PriceComputationEngine::chainRate(appliedTaxes: $chain),
        );
    }
}
