<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Checkout;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartResolution;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\PlacementEventContext;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\PlacementLine;
use Alfa\Component\Alfa\Site\Service\Cart\CartOpener;
use Alfa\Component\Alfa\Site\Service\Cart\MethodRequirement;
use Alfa\Component\Alfa\Site\Service\Cart\OpenedCart;
use Alfa\Component\Alfa\Site\Service\Cart\ShippingQuote;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingChargeBasis;

defined('_JEXEC') or die;

/**
 * The cart, as a placement needs it.
 *
 * Placement reads a cart's identity, lines, money, rules, contributions and
 * offered methods; it assumes a carrier for the purpose of pricing and
 * eligibility; it freezes the address ids the order will record; and at the
 * end it discards the cart. That list is this class, and nothing else is:
 * there is no generic data object to reach through and no service to borrow.
 *
 * It composes the cart's own owners (the loaded reading, the computation,
 * the row writer, the line configuration) and exposes only what placement
 * reads and does. It is not a plugin API and not the application API.
 *
 * @since  1.10.0
 */
final class PlacementCart
{
    /** @var int The delivery address the order will record; 0 until frozen. */
    private int $frozenDeliveryAddressId = 0;

    /** @var int The invoice address the order will record; 0 until frozen. */
    private int $frozenInvoiceAddressId = 0;

    /**
     * @param OpenedCart $cart The cart, opened, held privately.
     *
     * @since  1.10.0
     */
    private function __construct(private readonly OpenedCart $cart)
    {
    }

    /**
     * The visitor's own cart: the storefront checkout.
     *
     * Current: a placement never mints a cart; a visitor with nothing to convert
     * is told so.
     *
     * @param CartSubject $subject Whose cart, and on whose terms it is priced.
     *
     *
     * @since  1.10.0
     */
    public static function current(CartSubject $subject): self
    {
        return new self((new CartOpener())->open(0, $subject, CartResolution::Current));
    }

    /**
     * One exact cart: an operator placing on a customer's behalf.
     *
     * Named: no discovery, no adoption, no creation. A cart that is not the one
     * named, or not this customer's, resolves to none.
     *
     * @param int $cartId The cart.
     * @param CartSubject $subject Whose it must be, and who is acting.
     *
     *
     * @since  1.10.0
     */
    public static function named(int $cartId, CartSubject $subject): self
    {
        return new self((new CartOpener())->open($cartId, $subject, CartResolution::Named));
    }

    // identity

    /**
     * @return int The cart's id; 0 when there is none.
     *
     * @since  1.10.0
     */
    public function cartId(): int
    {
        return (int) $this->cart->hydrator->current()->identity()->cartId;
    }

    /**
     * Whether this is the exact cart that was named, rather than one discovered.
     *
     *
     * @since  1.10.0
     */
    public function isNamed(): bool
    {
        return $this->cart->hydrator->current()->identity()->isNamed();
    }

    /**
     * The currency the cart was opened in: the row's own.
     *
     * The one fact about the money that belongs to this order rather than to
     * whoever happens to be signed in; the order is stamped with it so it can
     * be re-priced later in the currency it was actually placed in.
     *
     *
     * @since  1.10.0
     */
    public function currencyId(): int
    {
        return $this->cart->hydrator->current()->currencyId();
    }

    // placement-time context

    /**
     * Where this submission says the order is going.
     *
     * The offered methods are filtered by it, so it is bound before any of
     * them is asked for.
     *
     * @param int|null $placeId The delivery place, or null when the form has none.
     *
     *
     * @since  1.10.0
     */
    public function bindDeliveryPlace(?int $placeId): void
    {
        $this->cart->computation->bindDeliveryPlace($placeId);
    }

    /**
     * Evaluate this placement as though this carrier were chosen.
     *
     * The payment methods offered and the carriage charged both follow the
     * carrier THIS submission names, not the one the cart row last recorded,
     * and the row is not written, because a placement that is refused must
     * leave the cart exactly as it found it. The assumption drops the
     * memoised method lists and the carriage they priced, so what is read
     * next is computed against the assumed carrier rather than served from
     * before it.
     *
     * @param int $shipmentMethodId The carrier this submission chose.
     *
     *
     * @since  1.10.0
     */
    public function assumeShipment(int $shipmentMethodId): void
    {
        $this->cart->computation->assumeShipment($shipmentMethodId);
    }

    /**
     * The address ids the order will record.
     *
     * Placement-local on purpose: the order freezes its own copies of these,
     * and the cart is discarded before the transaction commits, so the cart
     * neither learns nor stores them.
     *
     * @param int $deliveryId The delivery address just saved.
     * @param int $invoiceId The invoice address, which may be the same one.
     *
     *
     * @since  1.10.0
     */
    public function freezeAddresses(int $deliveryId, int $invoiceId): void
    {
        $this->frozenDeliveryAddressId = $deliveryId;
        $this->frozenInvoiceAddressId = $invoiceId;
    }

    /**
     * @return int The frozen delivery address id; 0 until frozen.
     *
     * @since  1.10.0
     */
    public function frozenDeliveryAddressId(): int
    {
        return $this->frozenDeliveryAddressId;
    }

    /**
     * @return int The frozen invoice address id; 0 until frozen.
     *
     * @since  1.10.0
     */
    public function frozenInvoiceAddressId(): int
    {
        return $this->frozenInvoiceAddressId;
    }

    // lines

    /**
     * The cart's lines, hydrated and priced, in cart order.
     *
     * @return object[]
     *
     * @since  1.10.0
     */
    public function lines(): array
    {
        return $this->cart->hydrator->current()->lines();
    }

    /**
     * The per-line tax breakdown the order freezes.
     *
     * @param object $item The configured product on the line.
     * @param int $lineId The line.
     * @param int $quantity How many.
     *
     *
     * @since  1.10.0
     */
    public function lineBreakdown(object $item, int $lineId, int $quantity): array
    {
        return $this->cart->configuration->breakdown($item, $lineId, $quantity, $this->cart->hydrator->current()->subject()->priceContext());
    }

    // money, rules, contributions

    /** @return Money What the carrier charged, before any shipping rule. @since 1.10.0 */
    public function shipmentGross(): Money
    {
        return $this->cart->computation->totals()->shipmentGross();
    }

    /** @return Money The same, before tax. @since 1.10.0 */
    public function shipmentGrossExcl(): Money
    {
        return $this->cart->computation->totals()->shipmentGrossExcl();
    }

    /** @return float The rate the carrier's charge was taxed at, in percent; 0.0 untaxed. @since 1.14.0 */
    public function shipmentTaxRate(): float
    {
        return $this->cart->computation->totals()->shipmentTaxRate();
    }

    /** @return ShippingChargeBasis|null How the carrier's charge was formed; null when nothing is charged. @since 1.14.0 */
    public function shipmentChargeBasis(): ?ShippingChargeBasis
    {
        return $this->cart->computation->totals()->shipmentChargeBasis();
    }

    /** @return string[] The rates the carrier's charge was calculated with, in order, as exact decimal texts. @since 1.14.0 */
    public function shipmentTaxChain(): array
    {
        return $this->cart->computation->totals()->shipmentTaxChain();
    }

    /** @return Money What the purchase comes to. @since 1.10.0 */
    public function grandTotal(): Money
    {
        return $this->cart->computation->totals()->grandTotal();
    }

    /** @return Money The same, before tax. @since 1.10.0 */
    public function grandTotalExcl(): Money
    {
        return $this->cart->computation->totals()->grandTotalExcl();
    }

    /** @return object[] The cart rules that applied. @since 1.10.0 */
    public function appliedRules(): array
    {
        return $this->cart->computation->rules()->applied();
    }

    /** @return array The charges the commercial plugins contributed. @since 1.10.0 */
    public function contributions(): array
    {
        return $this->cart->computation->contributions();
    }

    // offered methods

    /** @return bool Whether any payment method could be offered at all. @since 1.10.0 */
    public function hasPaymentCandidates(): bool
    {
        return $this->cart->computation->methods()->hasPaymentCandidates();
    }

    /**
     * Whether this cart must name a payment method: the same verdict the
     * checkout offers were built from, not a count taken of them here.
     *
     * @since  1.10.0
     */
    public function paymentRequirement(): MethodRequirement
    {
        return $this->cart->computation->methods()->paymentRequirement();
    }

    /** @return array The payment methods offered, keyed by id. @since 1.10.0 */
    public function paymentMethods(): array
    {
        return $this->cart->computation->methods()->payments();
    }

    /** @return array The same, ignoring the carrier's restrictions. @since 1.10.0 */
    public function paymentMethodsIgnoringShipping(): array
    {
        return $this->cart->computation->methods()->paymentsIgnoringShipping();
    }

    /** @return bool Whether any carrier could be offered at all. @since 1.10.0 */
    public function hasShipmentCandidates(): bool
    {
        return $this->cart->computation->methods()->hasShipmentCandidates();
    }

    /**
     * Whether this cart must name a carrier: the same verdict, for the same
     * reason as its payment twin.
     *
     * @since  1.10.0
     */
    public function shipmentRequirement(): MethodRequirement
    {
        return $this->cart->computation->methods()->shipmentRequirement();
    }

    /** @return array The carriers offered, keyed by id. @since 1.10.0 */
    public function shipmentMethods(): array
    {
        return $this->cart->computation->methods()->shipments();
    }

    /**
     * What the chosen carrier answered when asked to price this cart.
     *
     * Being offered and being able to carry are different questions, and only
     * the carrier can answer the second. Placement asks it before the gateway
     * is reached, so a cart the carrier has refused is declined while the
     * shopper still has their money.
     *
     * @since  1.10.0
     */
    public function shipmentQuote(): ShippingQuote
    {
        return $this->cart->computation->shipmentQuote();
    }

    // what a plugin may see

    /**
     * The cart as a placement plugin is told about it: its id, its lines, the
     * customer it is for and the carrier it is placed with, as values. The
     * customer and the carrier are passed through and not kept: whose order
     * this is, and by which method, were never the cart's to say.
     *
     * @param int $customerId The customer the order is for; 0 for a guest.
     * @param int $shipmentMethodId The shipment method the placement chose; 0 for none.
     *
     *
     * @since  1.10.0
     */
    public function eventContext(int $customerId = 0, int $shipmentMethodId = 0): PlacementEventContext
    {
        $lines = [];

        foreach ($this->lines() as $line) {
            $lines[] = new PlacementLine(
                lineId: (int) ($line->id ?? 0),
                itemId: (int) ($line->id_item ?? 0),
                quantity: (int) ($line->quantity ?? 0),
                combinationId: isset($line->combination_id) ? (int) $line->combination_id : null,
            );
        }

        return new PlacementEventContext(
            cartId: $this->cartId(),
            lines: $lines,
            customerId: $customerId,
            shipmentMethodId: $shipmentMethodId,
        );
    }

    // the end of the cart

    /**
     * The last step of conversion: the cart ceases to exist.
     *
     * Inside the placement transaction, after every total the order needs has
     * been read; what this leaves behind reads as an empty cart.
     *
     * @return bool Whether the cart went.
     *
     * @since  1.10.0
     */
    public function discard(): bool
    {
        return $this->cart->rows->discard();
    }
}
