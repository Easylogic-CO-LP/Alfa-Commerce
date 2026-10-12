<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Cart;

use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Joomla\CMS\User\User;
use RuntimeException;

defined('_JEXEC') or die;

/**
 * Who a cart operation is FOR, and who is DOING it.
 *
 * These are two different people, and one property for both costs nothing on
 * the storefront, because the shopper is the only person in the request,
 * which is exactly why it goes unnoticed there. It stops
 * being free the moment anybody operates a cart that is not their own: the cart
 * is then priced, filtered and rule-matched for whoever is holding the
 * keyboard, and the customer's own entitlements are silently gone. That is
 * measurable, and it is measured: the same cart, the same customer, a
 * different person signed in, and a different total.
 *
 * So the two roles are named:
 *
 *   CUSTOMER - whose cart or order this is. Prices, usergroups, addresses,
 *              credit, coupons, cart rules, ownership, and everything a plugin
 *              is handed as "the shopper" follow the customer.
 *
 *   ACTOR    - who is performing this operation right now. Adoption of a
 *              browser's guest cart follows the actor, because it is the actor's
 *              browser that carries the recognition cookie. So does the audit
 *              trail.
 *
 * On the storefront they are the same person and every path through this class
 * collapses to what the code did before. That is the point: the split has to be
 * free where the two coincide, or it could not be introduced at all.
 *
 * The naming follows what the component already ships. FieldsHelper::prepareForm()
 * has taken a separate `$userId` and `$customerId` since custom fields learned to
 * resolve per-customer, and OrderHelper::buildOrderPriceContext() has always
 * derived its subject from the ORDER's customer rather than the request. This
 * class is those two positions, applied to the cart.
 *
 * @since  1.10.0
 */
final class CartSubject
{
    /**
     * @param User $customer Whose cart this is.
     * @param User $actor Who is operating on it.
     * @param int|null $currencyId Money context; null = whatever the request says.
     * @param int|null $placeId Place context; null = whatever the request says.
     *
     * @since  1.10.0
     */
    private function __construct(
        private User $customer,
        private User $actor,
        private ?int $currencyId = null,
        private ?int $placeId = null,
        private bool $ambient = false,
        private bool $operator = false,
    ) {
    }

    /**
     * The storefront: the shopper is the only person in the request.
     *
     * Both roles come from the ambient identity, which is what every caller got
     * implicitly before this class existed. A null identity (the CLI has one)
     * becomes a guest rather than a fatal.
     *
     *
     * @since  1.10.0
     */
    public static function ambient(): self
    {
        $identity = \Joomla\CMS\Factory::getApplication()->getIdentity() ?? new User(0);

        return new self($identity, $identity, null, null, true);
    }

    /**
     * One named person acting for themselves.
     *
     * @param User $who The shopper.
     *
     *
     * @since  1.10.0
     */
    public static function shopper(User $who): self
    {
        return new self($who, $who);
    }

    /**
     * Two named people: an operation performed by one person for another.
     *
     * @param User $customer Whose cart or order this is.
     * @param User $actor Who is performing the operation.
     *
     *
     * @since  1.10.0
     */
    public static function of(User $customer, User $actor): self
    {
        return new self($customer, $actor);
    }

    /**
     * An AUTHORIZED OPERATOR placing an order on a customer's behalf.
     *
     * This is a deliberate, explicit signal and it is the only one. Placement
     * must never work out for itself that it is being driven by staff: not
     * from the application client, not from whether the two identities differ,
     * not from a caller-supplied source string, and not from whether anybody
     * was named. Each of those is either forgeable or true for cases that are
     * not operator placement at all: of() names two people and is not this,
     * shopper() names one and is not this either.
     *
     * Saying it in one place means the rules that hang off it (the payment
     * capability, the money context that may not come from a session, the
     * named-cart requirement, the structured refusals) all key on the same
     * fact, and a caller cannot acquire them by accident.
     *
     * @param User $customer Whose order this is.
     * @param User $actor The member of staff placing it.
     *
     *
     * @since  1.10.0
     */
    public static function operator(User $customer, User $actor): self
    {
        return new self($customer, $actor, null, null, false, true);
    }

    /**
     * The same subject, priced in a named currency and place.
     *
     * Both are optional and both default to the request's own, which is what
     * keeps the storefront unchanged. They exist because an operation performed
     * for somebody else must not inherit the operator's currency or location:
     * that is the other half of "priced for the customer", and it has nowhere
     * else to live.
     *
     * @param int|null $currencyId The currency, or null to keep the request's.
     * @param int|null $placeId The place, or null to keep the request's.
     *
     *
     * @since  1.10.0
     */
    public function withMoneyContext(?int $currencyId, ?int $placeId): self
    {
        return new self($this->customer, $this->actor, $currencyId, $placeId, $this->ambient, $this->operator);
    }

    /**
     * Is this an authorized operator placing an order for somebody else?
     *
     *
     * @since  1.10.0
     */
    public function isOperatorPlacement(): bool
    {
        return $this->operator;
    }

    /**
     * The money context, when one was named.
     *
     * @return array{currency: int|null, place: int|null}
     *
     * @since  1.10.0
     */
    public function moneyContext(): array
    {
        return ['currency' => $this->currencyId, 'place' => $this->placeId];
    }

    /**
     * Did anybody actually NAME these people, or is this just "whoever is
     * signed in"?
     *
     * The distinction decides whether a customer can be checked against a cart
     * row. "Whoever is signed in" is not a claim about whose cart something is,
     * so there is nothing to verify and nothing to refuse; a named customer IS
     * such a claim, and gets verified.
     *
     *
     * @since  1.10.0
     */
    public function isAmbient(): bool
    {
        return $this->ambient;
    }

    /**
     * Whose cart this is.
     *
     *
     * @since  1.10.0
     */
    public function customer(): User
    {
        return $this->customer;
    }

    /**
     * Who is operating on it.
     *
     *
     * @since  1.10.0
     */
    public function actor(): User
    {
        return $this->actor;
    }

    /**
     * The customer's account id, or 0 for a guest.
     *
     *
     * @since  1.10.0
     */
    public function customerId(): int
    {
        return max(0, (int) ($this->customer->id ?? 0));
    }

    /**
     * The actor's account id, or 0 when nobody is signed in.
     *
     *
     * @since  1.10.0
     */
    public function actorId(): int
    {
        return max(0, (int) ($this->actor->id ?? 0));
    }

    /**
     * Is this somebody operating their own cart?
     *
     *
     * @since  1.10.0
     */
    public function isSelfService(): bool
    {
        return $this->customerId() === $this->actorId();
    }

    /**
     * The cart owner this subject implies, given the browser's key.
     *
     * Ownership follows the CUSTOMER: a cart belongs to the person it is for,
     * never to the person operating it. The key is still the actor's browser,
     * which is why it is passed in rather than read here: it only decides
     * anything when the customer is a guest.
     *
     * @param string $recognizeKey The recognition key the browser presented.
     *
     *
     * @since  1.10.0
     */
    public function owner(string $recognizeKey): CartOwner
    {
        return CartOwner::of($this->customerId(), $recognizeKey);
    }

    /**
     * The price context this cart must be computed in.
     *
     * ENVIRONMENT FROM THE REQUEST, SUBJECT FROM THE CUSTOMER. The context has
     * four axes and they do not all belong to the same person: currency and
     * location describe where the shop is being viewed from, while usergroups
     * and user id describe who is being priced for. Only the second pair moves.
     *
     * The environment is taken from PriceContext::fromSession() rather than
     * rebuilt, deliberately. It resolves the session currency, falls back to the
     * store default, and reads the location key; reproducing that here would be
     * a second implementation of the same rule, free to drift from the first.
     * There is one pricing implementation and this is not it.
     *
     * The identity axes are then replaced with the customer's through
     * PriceContext::withCustomer(), the one rule fromSession() applies to the
     * signed-in visitor. When the customer IS the ambient identity, which is
     * every storefront request, the result is therefore identical to what
     * fromSession() returns on its own.
     *
     *
     * @since  1.10.0
     */
    public function priceContext(): PriceContext
    {
        // AN OPERATOR PLACEMENT TAKES NOTHING FROM A SESSION.
        //
        // The environment half of a price context (which currency, which place)
        // is exactly as decisive as the identity half, and on the storefront
        // both belong to the same person so reading the session is right. In the
        // back office the session belongs to the operator, and a cart priced in
        // the operator's currency is a cart priced wrong. There is no fallback
        // here on purpose: the currency must have been established from the cart
        // before anything is priced, and if it has not been then the safe thing
        // is to say so rather than to quietly substitute one.
        if ($this->operator) {
            if ($this->currencyId === null || $this->currencyId <= 0) {
                throw new RuntimeException(
                    'An operator placement must name its currency before pricing; none was established from the cart.',
                );
            }

            return PriceContext::forIndex(currencyId: $this->currencyId, placeId: 0, usergroupId: 0)
                ->withLocation(placeId: $this->placeId !== null && $this->placeId > 0 ? $this->placeId : null)
                ->withCustomer(customer: $this->customer);
        }

        $context = PriceContext::fromSession();

        if ($this->currencyId !== null && $this->currencyId > 0) {
            $context = $context->withCurrency($this->currencyId);
        }

        if ($this->placeId !== null) {
            $context = $context->withLocation($this->placeId > 0 ? $this->placeId : null);
        }

        return $context->withCustomer(customer: $this->customer);
    }
}
