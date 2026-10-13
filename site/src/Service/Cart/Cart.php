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
use Joomla\CMS\User\User;
use LogicException;

defined('_JEXEC') or die;

/**
 * The cart as a plugin reads it.
 *
 * ONE OBJECT TO LEARN. A carrier or gateway is handed this and needs nothing
 * else: what is in the basket, where it is going, who is buying, and, through
 * {@see AddressView}, any checkout field the plugin was configured to read.
 * Before it, a plugin author had to know three request DTOs, their property
 * names, and which hook carried which; the field they actually wanted was
 * reachable from none of them.
 *
 * READ ONLY, AND HONESTLY SO. There is no edit(), no save(), no setter. A
 * plugin that must CHANGE a cart goes through CartService, which is a
 * deliberate, visible act with its own validation, not something that can
 * happen by accident while a method is drawing its own name. This facade cannot
 * make that mistake possible, and it does not pretend to prevent it either:
 * CartService is a public static and always was.
 *
 * A SNAPSHOT, NOT A LIVE HANDLE. It wraps the cart as it stood when the hook
 * fired. HydratedCart is final with private readonly properties and copy-
 * returning mutators, so a plugin holding this reads the same cart three calls
 * later, which is what makes "rendering must not move money" true by
 * construction. Nothing reachable from here recomputes anything: this is the
 * settled state, never CartComputation.
 *
 * @since  2.0.0
 */
final class Cart
{
    /**
     * @param HydratedCart $state The settled cart.
     * @param array $pendingDelivery Checkout values submitted for the delivery section.
     * @param array $pendingInvoice The same for the invoice section.
     *
     * @since  2.0.0
     */
    private function __construct(
        private readonly HydratedCart $state,
        private readonly array $pendingDelivery = [],
        private readonly array $pendingInvoice = [],
        private readonly ?Money $subtotal = null,
        private readonly ?Money $total = null,
    ) {
    }

    /**
     * Wrap a settled cart, with whatever the checkout form currently holds.
     *
     * The pending values are passed IN rather than fetched: the caller knows
     * which submission is being evaluated, and a facade that read the session
     * for itself could hand one plugin a different cart than its neighbour.
     *
     * @param HydratedCart $state The cart being evaluated.
     * @param array $pendingDelivery Submitted delivery values, by column name.
     * @param array $pendingInvoice Submitted invoice values, by column name.
     * @param Money|null $subtotal The already-settled cart-item amount.
     * @param Money|null $total The final payable amount, where the calculation
     *                          has reached a stage that has one.
     *
     *
     * @since  2.0.0
     */
    public static function of(
        HydratedCart $state,
        array $pendingDelivery = [],
        array $pendingInvoice = [],
        ?Money $subtotal = null,
        ?Money $total = null,
    ): self {
        return new self($state, $pendingDelivery, $pendingInvoice, $subtotal, $total);
    }

    /**
     * The settled cart-item amount: the sum of the line prices, tax included.
     *
     * A GETTER OVER PREPARED DATA. The figure is computed by the cart before
     * any plugin is asked anything and handed to this reader already settled:
     * nothing behind it calls CartComputation, so reading it cannot re-enter a
     * shipping quote or a contribution fold. Its tax, discount, currency and
     * calculation stage are exactly what the cart settled; this only carries it.
     *
     *
     * @throws LogicException When the hook that built this cart supplied none.
     *
     * @since  2.0.0
     */
    public function getSubtotal(): Money
    {
        if ($this->subtotal === null) {
            throw new LogicException(
                'The cart subtotal was not supplied to this reader. '
                . 'Whichever hook built it must pass the settled amount.',
            );
        }

        return $this->subtotal;
    }

    /**
     * The final payable amount, once the whole calculation has produced one.
     *
     * FAILS RATHER THAN GUESSES. During a shipping quote or a contribution fold
     * there IS no final total (carriage and the charges are precisely what is
     * being decided) so this throws instead of handing back a subtotal, a
     * stale figure or a zero. Each of those would read as an answer and be
     * silently wrong at exactly the moments money is decided.
     *
     * A carrier or contributor wants {@see self::getSubtotal()}.
     *
     *
     * @throws LogicException When the calculation has not reached a final total.
     *
     * @since  2.0.0
     */
    public function getTotal(): Money
    {
        if ($this->total === null) {
            throw new LogicException(
                'The final cart total is not available at this stage: the carriage and the '
                . 'cart-level charges are still being decided. Read getSubtotal() instead.',
            );
        }

        return $this->total;
    }

    // what is in it

    /** @return int The cart row id, 0 when it has none yet. @since 2.0.0 */
    public function id(): int
    {
        return (int) $this->state->identity()->cartId;
    }

    /** @return bool @since 2.0.0 */
    public function isEmpty(): bool
    {
        return $this->state->isEmpty();
    }

    /** @return int How many distinct lines. @since 2.0.0 */
    public function itemCount(): int
    {
        return $this->state->itemCount();
    }

    /** @return int Total units across every line. @since 2.0.0 */
    public function quantity(): int
    {
        return $this->state->quantity();
    }

    /**
     * Whether nothing in the cart needs shipping.
     *
     * Read off the products' explicit `is_virtual` flag, never inferred from a
     * zero weight: a heavy thing with no weight recorded is still shipped.
     *
     *
     * @since  2.0.0
     */
    public function isVirtual(): bool
    {
        return $this->state->isVirtual();
    }

    /**
     * What is in the cart, line by line.
     *
     * THE ONLY WAY TO ASK. A carrier used to receive its own line list built by
     * the dispatcher: the same lines, the same quantities, differing only in
     * having the measurements and the virtual flag flattened out of the product
     * on each line. That is formatting, and formatting does not earn a second
     * access path; the flattening lives on {@see CartLine} where everyone gets
     * it.
     *
     * @return CartLine[] In cart order.
     *
     * @since  2.0.0
     */
    public function lines(): array
    {
        return array_map(CartLine::of(...), array_values($this->state->lines()));
    }

    /** @return Currency The currency this cart is priced in. @since 2.0.0 */
    public function currency(): Currency
    {
        return $this->state->currency();
    }

    /** @return User Whose cart it is. @since 2.0.0 */
    public function customer(): User
    {
        return $this->state->subject()->customer();
    }

    /** @return int The chosen carrier's method id, 0 for none. @since 2.0.0 */
    public function shipmentId(): int
    {
        return $this->state->shipmentId();
    }

    /** @return int The chosen gateway's method id, 0 for none. @since 2.0.0 */
    public function paymentId(): int
    {
        return $this->state->paymentId();
    }

    // where it is going, and who to

    /**
     * The delivery address, read by field.
     *
     *     $postcode = $cart->delivery()->field($postcodeFieldId)->value();
     *     $phone    = $cart->delivery()->field($phoneFieldId)->value();
     *     $placeId  = $cart->delivery()->role('country')->int();
     *
     *
     * @since  2.0.0
     */
    public function delivery(): AddressView
    {
        return new AddressView($this->state->deliveryAddress(), $this->pendingDelivery);
    }

    /**
     * The invoice address, read the same way.
     *
     *
     * @since  2.0.0
     */
    public function invoice(): AddressView
    {
        return new AddressView($this->state->invoiceAddress(), $this->pendingInvoice);
    }

    /**
     * The settled state underneath, for core callers that need it.
     *
     * Not part of the plugin-facing contract: a plugin that reaches for this is
     * asking for something the facade should have offered, and that is a gap to
     * close here rather than to route around.
     *
     *
     * @since  2.0.0
     */
    public function state(): HydratedCart
    {
        return $this->state;
    }
}
