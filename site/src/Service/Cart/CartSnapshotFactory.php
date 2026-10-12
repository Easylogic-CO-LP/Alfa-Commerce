<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

defined('_JEXEC') or die;

/**
 * Takes one observation of a cart.
 *
 * Assembly only. It decides nothing, writes nothing and dispatches nothing:
 * it asks the loaded cart and its computation the questions a view asks, once
 * each, and freezes the answers into a {@see CartSnapshot}. It receives the
 * two parts it reads and nothing that could write.
 *
 * WHERE IT MAY BE CALLED FROM. Not from inside a totals calculation, a rules
 * evaluation, a shipment-cost dispatch, a contribution collection or a
 * method-discovery callback: those are re-entrant. A snapshot is taken at a
 * settled boundary, after the cart is done being calculated.
 *
 * @since  1.10.0
 */
final class CartSnapshotFactory
{
    /**
     * @param HydratedCart $state The cart as loaded.
     * @param CartComputation $computation What is computed about it.
     * @param array $paymentViews What each payment method asked to show, keyed by id.
     * @param array $shipmentViews The same for carriers.
     *
     * @since  1.10.0
     */
    public function from(HydratedCart $state, CartComputation $computation, array $paymentViews = [], array $shipmentViews = []): CartSnapshot
    {
        $totals = $computation->totals();
        $rules = $computation->rules();
        $methods = $computation->methods();

        return new CartSnapshot(
            cartId: $state->id(),
            // The lines as the cart page reads them. The array is copied so a
            // view cannot lengthen it; the line objects are the same ones the
            // page has always rendered, and nothing persistent hangs off them.
            lines: $state->lines(),
            itemCount: $state->itemCount(),
            quantity: $state->quantity(),
            isVirtual: $state->isVirtual(),
            currency: $state->currency(),
            totals: new CartTotalsView(
                goods: $totals->total(),
                goodsExcl: $totals->totalExcl(),
                beforeDiscount: $totals->totalBeforeDiscount(),
                tax: $totals->taxTotal(),
                discount: $totals->discountTotal(),
                shipment: $totals->shipmentTotal(),
                shipmentGross: $totals->shipmentGross(),
                shippingBenefit: $totals->shippingBenefit(),
                contributions: $computation->contributions(),
                grand: $totals->grandTotal(),
                grandExcl: $totals->grandTotalExcl(),
            ),
            rules: new CartRulesView(
                applied: $rules->applied(),
                codes: $rules->codes(),
                progress: $rules->displayableProgress(),
                total: $rules->total(),
            ),
            offers: new CartOffersView(
                hasPayments: $methods->hasPaymentCandidates(),
                hasShipments: $methods->hasShipmentCandidates(),
                paymentRequirement: $methods->paymentRequirement(),
                shipmentRequirement: $methods->shipmentRequirement(),
                payments: $methods->payments(),
                paymentsIgnoringShipping: $methods->paymentsIgnoringShipping(),
                shipments: $methods->shipments(),
                selectedPaymentId: $state->paymentId(),
                selectedShipmentId: $state->shipmentId(),
                // The reconciliation notices, plus, only where the page can
                // actually act on it, the reason a cart with no known
                // destination is being offered less.
                notices: [
                    'payment' => $methods->notices('payment'),
                    'shipment' => array_values(array_filter(array_merge(
                        $methods->notices('shipment'),
                        [$methods->destinationNotice('shipment')],
                    ))),
                ],
                paymentViews: $paymentViews,
                shipmentViews: $shipmentViews,
            ),
            customer: $state->subject()->customer(),
            placeId: $computation->deliveryPlaceId(),
        );
    }
}
