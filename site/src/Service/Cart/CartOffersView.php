<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\MethodViewContribution;

defined('_JEXEC') or die;

/**
 * What this cart may be shipped and paid by, as of one observation.
 *
 * Read projections, never CartMethods itself, and never the memoised objects
 * the cart-view events graft their layouts onto; a selector that needs those
 * still reads them from the cart, and says so.
 *
 * hasPayments/hasShipments answer "is there a section to render"; the two
 * requirements answer "must one be chosen, and what does its absence mean".
 * They are carried together because placement judges by the second and the
 * back office must state the same verdict rather than a nearby one.
 *
 * @since  1.10.0
 */
final class CartOffersView
{
    /**
     * @param bool $hasPayments Anything can pay for this cart.
     * @param bool $hasShipments Anything can carry it.
     * @param MethodRequirement $paymentRequirement Whether a payment method
     *                                              must be named, and why not when not.
     * @param MethodRequirement $shipmentRequirement The same for a carrier.
     * @param array $payments Offered payment methods, keyed by id.
     * @param array $paymentsIgnoringShipping The same, before the carrier narrows them.
     * @param array $shipments Offered carriers, keyed by id.
     * @param int $selectedPaymentId What the cart has chosen, or 0.
     * @param int $selectedShipmentId What the cart has chosen, or 0.
     * @param array $notices Customer-facing notices, keyed by kind.
     * @param array $paymentViews What each payment method asked to show,
     *                            keyed by method id.
     * @param array $shipmentViews The same for carriers.
     *
     * @since  1.10.0
     */
    public function __construct(
        public readonly bool $hasPayments,
        public readonly bool $hasShipments,
        public readonly MethodRequirement $paymentRequirement,
        public readonly MethodRequirement $shipmentRequirement,
        public readonly array $payments,
        public readonly array $paymentsIgnoringShipping,
        public readonly array $shipments,
        public readonly int $selectedPaymentId,
        public readonly int $selectedShipmentId,
        public readonly array $notices,
        public readonly array $paymentViews = [],
        public readonly array $shipmentViews = [],
    ) {
    }

    /**
     * What a method asked to have rendered beside it.
     *
     * BY ID, never by object identity: the selector looks the contribution up
     * against the method it is rendering, so an equivalent method object
     * renders the same page.
     *
     * @param string $kind 'payment' or 'shipment'.
     * @param int $methodId The method being rendered.
     *
     * @return MethodViewContribution|null Nothing when the method said nothing.
     *
     * @since  1.10.0
     */
    public function viewFor(string $kind, int $methodId): ?MethodViewContribution
    {
        $views = $kind === 'payment' ? $this->paymentViews : $this->shipmentViews;

        return $views[$methodId] ?? null;
    }

    /**
     * @param string $kind 'payment' or 'shipment'.
     *
     * @return string[] What to tell the shopper about that kind.
     *
     * @since  1.10.0
     */
    public function notices(string $kind): array
    {
        return $this->notices[$kind] ?? [];
    }
}
