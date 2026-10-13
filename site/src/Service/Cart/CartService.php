<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartResolution;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Throwable;

defined('_JEXEC') or die;

/**
 * What an application may ask of a cart.
 *
 * Controllers, models and modules state an intention here (add this, choose
 * that carrier, empty it) and receive an outcome and a reading. They no longer
 * hold the cart, so they cannot mutate it a second time, ask it a question its
 * own last answer has invalidated, or hand it to a layout.
 *
 * EVERY OPERATION NAMES ITS IDENTITY MODE, AND NAMES IT HERE. Whether an act may
 * mint a cart is a property of the act, not of the caller's mood: adding a
 * product may (a first-time visitor is entitled to a basket), reloading the
 * offered methods may not (a visitor who only opened the checkout must leave
 * with nothing). Those pins live on the methods below, so a caller cannot get
 * them wrong and a reader can see them without following the call.
 *
 * WHAT IT IS NOT. It is not a cart with new spelling. It offers use cases, not
 * one method per stored column, and it hands out no repository, no rules
 * engine, no totals calculator and no method list; only values and readings.
 *
 * WHAT IT IS MADE OF. Each use case opens the cart through the opener, which
 * resolves it, adopts what must be adopted and reads it; then it asks the
 * owner that does the thing (the line writer, the row writer, the rules
 * engine, the computation) and takes one reading of what is left.
 *
 * @since  2.0.0
 */
final class CartService
{
    /**
     * @param CartSubject $subject Whose cart, and on whose terms it is priced.
     * @param CartOpener $opener Opens the cart for one operation.
     * @param CartSnapshotFactory $snapshots Takes the settled readings.
     *
     * @since  2.0.0
     */
    private function __construct(
        private readonly CartSubject $subject,
        private readonly CartOpener $opener,
        private readonly CartSnapshotFactory $snapshots,
    ) {
    }

    /**
     * The visitor's own cart, whoever they are.
     *
     *
     * @since  2.0.0
     */
    public static function ambient(): self
    {
        return self::forSubject(CartSubject::ambient());
    }

    /**
     * A cart on stated terms: an operator placing on a customer's behalf, say.
     *
     * @param CartSubject $subject Whose cart, and on whose terms.
     *
     *
     * @since  2.0.0
     */
    public static function forSubject(CartSubject $subject): self
    {
        return new self($subject, new CartOpener(), new CartSnapshotFactory());
    }

    /**
     * Put a product in the cart.
     *
     * Mutable: a shopper adding their first product is entitled to a cart, and
     * this is the only operation here that may mint one.
     *
     * THE REQUEST BECOMES A COMMAND HERE, INSIDE THE NET. The chosen options,
     * the uploads and the "am I editing a line" pointer are read once, into a
     * command, and a malformed request answers with the same one sentence as a
     * failed write. The writer beneath knows nothing of a request.
     *
     * @param int $itemId The product.
     * @param int|float $quantity How many.
     *
     * @return CartAddResult The line it landed on, and what the cart comes to.
     *
     * @since  2.0.0
     */
    public function add(int $itemId, int|float $quantity): CartAddResult
    {
        $cart = $this->cart(CartResolution::Mutable);

        // READING THE REQUEST IS INSIDE THE NET. A malformed request answers
        // with the same one sentence as a failed write, never with a page of
        // the framework's words.
        try {
            $command = AddToCartRequest::command(Factory::getApplication()->getInput(), $itemId, $quantity);
        } catch (Throwable $e) {
            $cart->messaging->failed('addToCart (reading the request)', $cart->hydrator->current()->identity()->cartId, $e, 'COM_ALFA_CART_ADD_FAILED');

            return new CartAddResult(false, 0, null, 0, 0);
        }

        $lineId = (int) $cart->lines->add($command);

        if ($lineId <= 0) {
            return new CartAddResult(false, 0, null, 0, 0);
        }

        // A SECOND READING, DELIBERATELY. The cart that performed the write
        // priced its lines before the new one existed; the confirmation shows
        // the basket as the drawer and the cart page will show it, so it is
        // read again rather than patched.
        $again = $this->cart(CartResolution::Current);
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
     * Set how many of one line the shopper wants.
     *
     * Current: a line of a cart that does not exist cannot be changed, so this
     * never mints one. The quantity itself is not decided here; the cart
     * clamps it against stock and the product's own rules, and removes the line
     * when it reaches zero.
     *
     * The reading is the plain one: the mini-cart, which asks for this, shows
     * lines and totals and no methods. The checkout asks
     * {@see setLineQuantityAtCheckout()} instead.
     *
     * @param int $lineId The line.
     * @param int $quantity What it should become.
     *
     * @return CartMutationResult With a reading, because both callers re-render.
     *
     * @since  2.0.0
     */
    public function setLineQuantity(int $lineId, int $quantity): CartMutationResult
    {
        $cart = $this->cart(CartResolution::Current);
        $done = $cart->lines->setQuantity($lineId, $quantity);

        return new CartMutationResult(
            succeeded: $done,
            cartId: $cart->hydrator->current()->identity()->cartId,
            snapshot: $this->snapshots->from($cart->hydrator->current(), $cart->computation),
        );
    }

    /**
     * The checkout's quantity change: the same write, then the SETTLED reading.
     *
     * A quantity is a fact the offered methods are conditioned on (a carrier's
     * parcel limits, a payment method's ceiling) so the reading the checkout
     * redraws from reconciles the chosen methods against what is still offered,
     * exactly as a method change does, rather than leaving the method panels
     * standing on the cart as it was. A refused write settles nothing: there
     * is no change to redraw from.
     *
     * @param int $lineId The line.
     * @param int $quantity What it should become.
     * @param int|null $placeId Where the checkout form says it is going.
     *
     * @return CartMutationResult With a settled reading when the write took.
     *
     * @since  2.0.0
     */
    public function setLineQuantityAtCheckout(int $lineId, int $quantity, ?int $placeId): CartMutationResult
    {
        $cart = $this->cart(CartResolution::Current);
        $done = $cart->lines->setQuantity($lineId, $quantity);
        $cartId = $cart->hydrator->current()->identity()->cartId;

        if (!$done) {
            return new CartMutationResult(succeeded: false, cartId: $cartId);
        }

        return new CartMutationResult(
            succeeded: true,
            cartId: $cartId,
            snapshot: (new CheckoutCart($cart, $this->snapshots))->settle($placeId),
        );
    }

    /**
     * Take everything out, and keep the cart.
     *
     * Current: emptying a cart nobody has is nothing. No reading is taken;
     * the caller renders the empty layout, which asks the cart for nothing.
     *
     * @return bool Whether the operation completed without a storage failure.
     *
     * @since  2.0.0
     */
    public function clear(): bool
    {
        return $this->cart(CartResolution::Current)->rows->clear();
    }

    /**
     * Enter a coupon code.
     *
     * Current: a discount on a cart that does not exist is meaningless.
     *
     * @param string $code What the shopper typed.
     *
     * @return string A CouponOutcome constant: which of the several ways this
     *                could go actually happened.
     *
     * @since  2.0.0
     */
    public function applyCoupon(string $code): string
    {
        return $this->cart(CartResolution::Current)->computation->rules()->apply($code);
    }

    /**
     * Take an entered code back off.
     *
     * Current, and void: the operation beneath answers nothing and logs its own
     * storage failures rather than reporting them.
     *
     * @param string $code The code to drop.
     *
     *
     * @since  2.0.0
     */
    public function removeCoupon(string $code): void
    {
        $this->cart(CartResolution::Current)->computation->rules()->remove($code);
    }

    /**
     * Point the cart at a saved address.
     *
     * Current, and void, for the same reason as the coupon removal: the
     * operation beneath returns nothing and swallows its own storage failure
     * with a log line.
     *
     * @param int $deliveryId Where it ships to.
     * @param int|null $invoiceId Where the invoice goes, when it differs.
     *
     *
     * @since  2.0.0
     */
    public function pointAtSavedDetails(int $deliveryId, ?int $invoiceId = null): void
    {
        $this->cart(CartResolution::Current)->rows->pointAtDetails($deliveryId, $invoiceId);
    }

    /**
     * Choose the carrier.
     *
     * Mutable: choosing one is a change, and a first-time visitor making it is
     * entitled to a cart.
     *
     * THE ORDER IS THE CONTRACT. The delivery place is bound first, because
     * every offered method is filtered by it; the choice is written; and only a
     * write that succeeded is reconciled, because reconciliation is itself a
     * write and a refused choice must not provoke one.
     *
     * @param int $shipmentId The carrier.
     * @param int|null $placeId Where the checkout form says it is going.
     *
     * @return CartMutationResult With the reading the method panels render from.
     *
     * @since  2.0.0
     */
    public function chooseShipment(int $shipmentId, ?int $placeId): CartMutationResult
    {
        return $this->choose('shipment', $shipmentId, $placeId);
    }

    /**
     * Choose how to pay.
     *
     * Mutable, and the same order as the carrier.
     *
     * @param int $paymentId The method.
     * @param int|null $placeId Where the checkout form says it is going.
     *
     * @return CartMutationResult With the reading the method panels render from.
     *
     * @since  2.0.0
     */
    public function choosePayment(int $paymentId, ?int $placeId): CartMutationResult
    {
        return $this->choose('payment', $paymentId, $placeId);
    }

    /**
     * The checkout as it stands, with the offered methods settled.
     *
     * Current, AND THIS IS LOAD-BEARING. Reloading the method list is a look,
     * not a change: a visitor who has only ever opened the checkout must leave
     * with no cart row and no recognition cookie.
     *
     * @param int|null $placeId Where the checkout form says it is going.
     *
     * @return CartSnapshot One settled reading, carrying the reconcile notices.
     *
     * @since  2.0.0
     */
    public function checkout(?int $placeId): CartSnapshot
    {
        return $this->openCheckout()->settle($placeId);
    }

    /**
     * The checkout page's look at the cart: first whether there is anything in
     * it, then, for a cart with something in it, the settled reading.
     *
     * Current, for the same reason as {@see checkout()}: opening the checkout
     * is a look, and leaves a first-time visitor with nothing.
     *
     *
     * @since  2.0.0
     */
    public function openCheckout(): CheckoutCart
    {
        return new CheckoutCart($this->cart(CartResolution::Current), $this->snapshots);
    }

    /**
     * A NEW cart for this subject's customer, and the handle to edit it.
     *
     * MINT, NOT FIND. The back office says "new cart" and means one: this opens
     * FRESH, which neither discovers nor adopts, so what comes back is a row
     * that did not exist a moment ago. The previous behaviour opened MUTABLE and
     * created only if nothing turned up, which made "new cart" mean "whatever
     * cart this browser or this account already reaches", and the browser is
     * the OPERATOR's. Three things followed from that, all of them wrong: the
     * operator's own storefront guest cart could be claimed or merged into the
     * customer's; a walk-in cart became reachable through the operator's
     * recognition cookie; and a second walk-in re-opened the first.
     *
     * Editing is the other operation and stays separate: {@see self::editCart()}
     * binds to an id somebody named and takes the customer from that row.
     *
     * @return NamedCartEditor Bound to the new cart, or to 0 if it could not be minted.
     *
     * @since  2.0.0
     */
    public function createNamedCart(): NamedCartEditor
    {
        $cart = $this->cart(CartResolution::Fresh);

        // FRESH resolves to nothing by construction, so this always mints. A
        // refusal leaves the identity at 0 and the caller is handed an editor
        // bound to no cart, which the endpoint checks rather than reporting a
        // cart it did not create.
        $cart->rows->create();

        return $this->editCart((int) $cart->hydrator->current()->identity()->cartId);
    }

    /**
     * A handle to edit a cart the caller already knows by id, under this
     * service's subject.
     *
     * @param int $cartId The cart to edit.
     *
     *
     * @since  2.0.0
     */
    public function editCart(int $cartId): NamedCartEditor
    {
        return new NamedCartEditor($this->subject, $this->opener, $this->snapshots, $cartId);
    }

    /**
     * A reading of a cart, changing nothing about it.
     *
     * The mode is the caller's, because this is the one use case where callers
     * genuinely differ: a storefront reader takes the visitor's own cart, an
     * operator screen takes one by name. Mutable is refused: a look does not
     * leave a row behind.
     *
     * NOT A PURE FUNCTION, AND NOT PRETENDING TO BE. Resolving a cart under a
     * discovering mode is what performs guest adoption, and hydrating its lines
     * is what re-clamps a quantity that has since gone out of stock. Both write.
     * That is today's behaviour on every page that renders a cart, and this
     * method inherits it rather than introducing it.
     *
     * @param CartResolution $mode How to find it.
     * @param int $cartId Which one, when naming it.
     *
     *
     * @throws InvalidArgumentException When asked to read under a creating mode.
     *
     * @since  2.0.0
     */
    public function read(CartResolution $mode, int $cartId = 0): CartSnapshot
    {
        if ($mode === CartResolution::Mutable) {
            throw new InvalidArgumentException('A cart is not created in order to be read.');
        }

        $cart = $this->cart($mode, $cartId);

        return $this->snapshots->from($cart->hydrator->current(), $cart->computation);
    }

    /**
     * How one line of this cart is configured.
     *
     * Current, and a read: it answers what the shopper chose on a line they
     * own, so the product page can re-open it. A line belonging to someone
     * else's cart answers the same as one that does not exist.
     *
     * @param object $item The product being re-opened.
     * @param int $lineId The line to read.
     *
     * @return array|null The stored selection, or null when it is not theirs.
     *
     * @since  2.0.0
     */
    public function lineConfiguration(object $item, int $lineId): ?array
    {
        $cart = $this->cart(CartResolution::Current);

        return $cart->configuration->edit($cart->hydrator->current()->identity()->cartId, $item, $lineId);
    }

    /**
     * Write one method choice, then settle the cart around it.
     *
     * @param string $kind 'shipment' or 'payment'.
     * @param int $id The chosen method.
     * @param int|null $placeId Where the checkout form says it is going.
     *
     *
     * @since  2.0.0
     */
    private function choose(string $kind, int $id, ?int $placeId): CartMutationResult
    {
        $cart = $this->cart(CartResolution::Mutable);
        $cart->computation->bindDeliveryPlace($placeId);

        // Which of the two is written matters beyond the column. A carrier
        // decides which payment methods remain offered, so writing one drops
        // the memoised method lists and the carriage they priced; a payment
        // method decides nothing else, so writing one drops nothing. That
        // asymmetry belongs to the write and is not restated here.
        $done = $kind === 'shipment'
            ? $cart->rows->chooseShipment($id)
            : $cart->rows->choosePayment($id);

        if (!$done) {
            return new CartMutationResult(succeeded: false, cartId: $cart->hydrator->current()->identity()->cartId);
        }

        // Settling re-binds the same place, which the cart treats as no change,
        // then reconciles and takes the one reading: the same steps, in the
        // same order, as the checkout page's own look.
        return new CartMutationResult(
            succeeded: true,
            cartId: $cart->hydrator->current()->identity()->cartId,
            snapshot: (new CheckoutCart($cart, $this->snapshots))->settle($placeId),
        );
    }

    /**
     * The cart implementation for one operation.
     *
     * @param CartResolution $mode The identity mode the operation declared.
     * @param int $cartId A named cart, when the operation names one.
     *
     *
     * @since  2.0.0
     */
    private function cart(CartResolution $mode, int $cartId = 0): OpenedCart
    {
        return $this->opener->open($cartId, $this->subject, $mode);
    }
}
