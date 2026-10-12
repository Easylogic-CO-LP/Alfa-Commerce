<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\AddToCartCommand;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartResolution;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartTakeover;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use RuntimeException;

\defined('_JEXEC') or die;

/**
 * A cart named once and then edited, for a caller that holds the id.
 *
 * The storefront's use cases discover the shopper's own cart on every call;
 * an operator building an order names one cart and works it (adds, changes a
 * quantity, applies a coupon, chooses a method, points an address) over a
 * sequence of requests. Rather than a second copy of every use case suffixed
 * "ForNamedCart", this is the one handle a caller receives for a known cart:
 * each method opens that cart under {@see CartResolution::Named} exactly as
 * {@see CartService} opens the shopper's, and delegates to the SAME owners:
 * {@see CartLineWriter}, {@see CartRowWriter}, {@see CartComputation}'s rules.
 * There is no business rule here that is not in those owners, and no request
 * is read: the add takes a built command, so the storefront and the back
 * office share one mutation and differ only in where the command came from.
 *
 * The subject stays the operator-on-behalf-of-customer subject the caller
 * opened with, so every price, rule and eligible method is the customer's,
 * never the operator's.
 *
 * @since  1.10.0
 */
final class NamedCartEditor
{
    /**
     * @param CartSubject $subject Operator acting for the customer.
     * @param CartOpener $opener Opens the named cart per operation.
     * @param CartSnapshotFactory $snapshots Assembles a reading for a caller that re-renders.
     * @param int $cartId The cart this handle edits.
     *
     * @since  1.10.0
     */
    public function __construct(
        private readonly CartSubject $subject,
        private readonly CartOpener $opener,
        private readonly CartSnapshotFactory $snapshots,
        private readonly int $cartId,
    ) {
    }

    /**
     * The cart this handle edits.
     *
     *
     * @since  1.10.0
     */
    public function cartId(): int
    {
        return $this->cartId;
    }

    /**
     * Add a configured product to the cart.
     *
     * The command carries the product, quantity, selection, payloads and files
     * the storefront's request-reader would have produced, built here from the
     * operator's chosen product and configuration, so the line lands through
     * the same {@see CartLineWriter::add()} the storefront uses.
     *
     * @param AddToCartCommand $command The product and its configuration.
     *
     *
     * @since  1.10.0
     */
    public function add(AddToCartCommand $command): CartAddResult
    {
        $cart = $this->cart();
        $lineId = (int) $cart->lines->add($command);

        if ($lineId <= 0) {
            return new CartAddResult(false, 0, null, 0, 0);
        }

        $again = $this->cart();
        $state = $again->hydrator->current();
        $line = $state->line($lineId);

        return new CartAddResult(
            added: $line !== null,
            lineId: $line !== null ? $lineId : 0,
            line: $line,
            itemCount: $state->itemCount(),
            quantity: $state->quantity(),
            grandTotal: $again->computation->totals()->grandTotal(),
        );
    }

    /**
     * Set how many of one line the cart holds.
     *
     * @param int $lineId The line.
     * @param int $quantity What it should become; the cart clamps it and
     *                      drops the line at zero.
     *
     *
     * @since  1.10.0
     */
    public function setQuantity(int $lineId, int $quantity): CartMutationResult
    {
        $cart = $this->cart();
        $done = $cart->lines->setQuantity($lineId, $quantity);

        return new CartMutationResult(
            succeeded: $done,
            cartId: $cart->hydrator->current()->identity()->cartId,
            snapshot: $this->snapshots->from($cart->hydrator->current(), $cart->computation),
        );
    }

    /**
     * SET OR CLEAR A LINE'S STAFF-NEGOTIATED UNIT PRICE.
     *
     * The authority is asked HERE and not only in the controller, so a future
     * internal workflow cannot reach the writer around the one endpoint that
     * checks. It is asked against the cart's OWN source, read from the row
     * rather than from the request: a source that travelled on a request would
     * be an authority the caller could choose.
     *
     * LEAVE IS NOT A MUTATION and is not gated, and it
     * is what lets an operator without the pricing capability edit a quantity
     * on a line that happens to carry a negotiated price.
     *
     * @param int $lineId The line.
     * @param CustomPriceCommand $command Leave, Set(amount) or Clear.
     * @param User $actor The operator answering for the act.
     *
     * @return array{0: CartMutationResult, 1: float|null, 2: float|null}
     *                                                                    The result, and the previous and next stored states for the audit.
     *
     * @throws RuntimeException 403 when this operator may not price this cart.
     *
     * @since  1.11.0
     */
    public function setCustomPrice(int $lineId, CustomPriceCommand $command, User $actor): array
    {
        $cart = $this->cart();
        $cartId = $cart->hydrator->current()->identity()->cartId;

        if ($command->mutates()) {
            $source = CartTakeover::sourceOf($cartId);

            if ($source === null || !CartTakeover::mayPriceCustom(actor: $actor, cartId: $cartId, source: $source)) {
                throw new RuntimeException(Text::_('COM_ALFA_ACCESS_CARTS_CUSTOMPRICE_DENIED'), 403);
            }
        }

        [$done, $previous, $next] = $cart->lines->setCustomPrice($lineId, $command);

        return [
            new CartMutationResult(
                succeeded: $done,
                cartId: $cartId,
                snapshot: $this->snapshots->from($cart->hydrator->current(), $cart->computation),
            ),
            $previous,
            $next,
        ];
    }

    /**
     * Take one line out of the cart.
     *
     * @param int $lineId The line.
     *
     * @return bool Whether a line of this cart was removed. A line id that
     *              belongs to another cart, or to none, removes nothing and
     *              says so: the caller has an act to report or not report.
     *
     * @since  1.10.0
     */
    public function remove(int $lineId): bool
    {
        return $this->cart()->lines->remove($lineId);
    }

    /**
     * Enter a coupon code.
     *
     * @param string $code What the operator typed.
     *
     * @return string A CouponOutcome constant.
     *
     * @since  1.10.0
     */
    public function applyCoupon(string $code): string
    {
        return $this->cart()->computation->rules()->apply($code);
    }

    /**
     * Take an entered code back off.
     *
     * @param string $code The code to drop.
     *
     *
     * @since  1.10.0
     */
    public function removeCoupon(string $code): void
    {
        $this->cart()->computation->rules()->remove($code);
    }

    /**
     * Point the cart at saved delivery and, when it differs, invoice details.
     *
     * @param int $deliveryId Where it ships to.
     * @param int|null $invoiceId Where the invoice goes, when it differs.
     *
     *
     * @since  1.10.0
     */
    public function pointAtSavedDetails(int $deliveryId, ?int $invoiceId = null): void
    {
        $this->cart()->rows->pointAtDetails($deliveryId, $invoiceId);
    }

    /**
     * Choose the carrier, then reconcile and read, the same order as the
     * storefront: the delivery place is bound first because every offered
     * method is filtered by it.
     *
     * @param int $shipmentId The carrier.
     * @param int|null $placeId The delivery place.
     *
     *
     * @since  1.10.0
     */
    public function chooseShipment(int $shipmentId, ?int $placeId = null): CartMutationResult
    {
        return $this->choose(kind: 'shipment', id: $shipmentId, placeId: $placeId);
    }

    /**
     * Choose how to pay, in the same order as the carrier.
     *
     * @param int $paymentId The method.
     * @param int|null $placeId The delivery place.
     *
     *
     * @since  1.10.0
     */
    public function choosePayment(int $paymentId, ?int $placeId = null): CartMutationResult
    {
        return $this->choose(kind: 'payment', id: $paymentId, placeId: $placeId);
    }

    /**
     * The cart as it stands: one settled reading with the offered methods and
     * totals the customer's context yields, for the screen that re-renders.
     *
     * @param int|null $placeId The delivery place.
     *
     *
     * @since  1.10.0
     */
    public function snapshot(?int $placeId = null): CartSnapshot
    {
        return (new CheckoutCart($this->cart(), $this->snapshots))->settle($placeId);
    }

    /**
     * The cart as it stands, WITHOUT settling it: no reconciliation, so nothing
     * is written. The reading the back office takes of a cart that is not its
     * workspace: a method that stopped being eligible is not dropped from the
     * row, because the row belongs to the shopper who is still using it.
     *
     * The same shape {@see CartService::read()} answers with, and it carries no
     * method-plugin panels or assets for the same reason it writes nothing:
     * they belong to the settle.
     *
     *
     * @since  1.10.0
     */
    public function reading(): CartSnapshot
    {
        $cart = $this->cart();

        return $this->snapshots->from($cart->hydrator->current(), $cart->computation);
    }

    /**
     * The settled reading for a page that renders it, with the method
     * plugins' assets declared from the SAME opened cart: one open, one
     * settle, exactly as the storefront takes both from one reading. A second
     * open for the assets alone would hydrate and price every line twice.
     *
     * @param HtmlDocument $page The page being built.
     * @param int|null $placeId The delivery place.
     *
     *
     * @since  1.10.0
     */
    public function snapshotForPage(HtmlDocument $page, ?int $placeId = null): CartSnapshot
    {
        $checkout = new CheckoutCart($this->cart(), $this->snapshots);
        $snapshot = $checkout->settle($placeId);

        $checkout->declareAssets($page);

        return $snapshot;
    }

    /**
     * The chosen method, written then reconciled, mirroring
     * {@see CartService::choose()} for a named cart.
     *
     * @param string $kind 'shipment' or 'payment'.
     * @param int $id The method.
     * @param int|null $placeId The delivery place.
     *
     *
     * @since  1.10.0
     */
    private function choose(string $kind, int $id, ?int $placeId): CartMutationResult
    {
        $cart = $this->cart();
        $cart->computation->bindDeliveryPlace($placeId);

        $done = $kind === 'shipment'
            ? $cart->rows->chooseShipment($id)
            : $cart->rows->choosePayment($id);

        if (!$done) {
            return new CartMutationResult(succeeded: false, cartId: $cart->hydrator->current()->identity()->cartId);
        }

        return new CartMutationResult(
            succeeded: true,
            cartId: $cart->hydrator->current()->identity()->cartId,
            snapshot: (new CheckoutCart($cart, $this->snapshots))->settle($placeId),
        );
    }

    /**
     * The cart, opened by name under the operator's subject: the one
     * resolution an editing operation uses, exactly as {@see CartService}
     * opens the shopper's own.
     *
     *
     * @since  1.10.0
     */
    private function cart(): OpenedCart
    {
        return $this->opener->open($this->cartId, $this->subject, CartResolution::Named);
    }
}
