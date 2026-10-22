<?php

/**
 * Shipping cost calculation event.
 *
 * Fired by CartPluginDispatch::shippingCost() to let the active
 * shipment plugin provide shipping cost for the current cart.
 *
 * A plugin answers with ONE of:
 *   - setShippingCost($price): the price as the merchant configured it. What
 *     it means (tax inside or on top) is the method's shipping price entry mode,
 *     and its tax is the method's shipping tax for the destination; core
 *     applies both and states the charge as net, tax and gross;
 *   - setShippingAmounts($net, $tax, $gross, $rate): a carrier that splits
 *     the tax itself states the canonical amounts, which are taken as they are;
 *   - setUnavailable($reasonKey): the carrier will not carry this cart.
 * A refusal outranks both answers, and stated amounts outrank a price.
 *
 * Usage in plugin:
 *   public function onCalculateShippingCost($event): void
 *   {
 *       $method = $event->getMethod();
 *
 *       $cart = $event->getCart();
 *
 *       // Where it is going, in two steps. Only some checkout fields hold a
 *       // place, so read the one this method names and expand THAT; a method
 *       // that names none inherits the shop's destination.
 *       $fieldId = (int) $method->param('delivery_place_field');
 *       $places  = $fieldId > 0
 *           ? $this->placesFor((int) $cart->delivery()->field($fieldId)->int())
 *           : $cart->delivery()->places();
 *
 *       $cost = $this->myRateLookup($cart->lines(), $places, $method->params());
 *
 *       // A carrier that cannot carry the cart says so instead of pricing it.
 *       if ($cost === null) {
 *           $event->setUnavailable('MY_PLUGIN_TOO_HEAVY');
 *
 *           return;
 *       }
 *
 *       // The configured price; core applies the entry mode and the tax.
 *       $event->setShippingCost($cost);
 *   }
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Event\Shipments;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\ShippingCostRequest;
use Alfa\Component\Alfa\Site\Service\Cart\Cart;
use Alfa\Component\Alfa\Site\Service\Cart\Method;

class CalculateShippingCostEvent extends ShipmentsEvent
{
    /**
     * What this carrier is being asked to price.
     *
     * Settled facts: the lines to carry, where to, what the merchandise comes
     * to, and the cart, for the carrier that must measure the basket before it
     * can name a price.
     *
     * The old prohibition was on asking the cart for a GRAND TOTAL, because a
     * carrier reading a number its own answer decides is a question that never
     * settles. That still holds and is still enforced, by what is handed over:
     * Cart is a reader over the settled state and has no totals to give. The cart is
     * safe here; CartComputation would not have been.
     *
     *
     * @since  2.0.0
     */
    public function getRequest(): ShippingCostRequest
    {
        return $this->getSubject();
    }

    /**
     * The method being rendered, entire.
     *
     * Its id, type, name, description, configuration and anything else the
     * record carries; see {@see Method}. One accessor rather than five,
     * because a plugin should never be blocked from reading its own method by
     * a getter the component has not written yet.
     *
     *
     * @since  2.0.0
     */
    public function getMethod(): Method
    {
        return $this->getRequest()->method;
    }

    /**
     * The cart being quoted.
     *
     * A convenience over `getRequest()->cart`, for carriers whose price depends
     * on facts the named fields do not carry: a locker network that must know
     * whether the basket fits its largest compartment, a courier that prices by
     * destination address rather than by zone.
     *
     * THE REQUEST'S SCOPE STILL GOVERNS THE QUOTE. Where `lines` is narrower
     * than the cart, `lines` is what is being priced; the cart is context, not
     * a licence to quote for goods this request did not ask about.
     *
     *
     * @since  2.0.0
     */
    public function getCart(): Cart
    {
        return $this->getRequest()->cart;
    }

    //  Refusal
    //
    //  "This costs nothing" and "I cannot carry this" are different answers,
    //  and until 1.10 a carrier had only one way to give either: the float
    //  0.00. A carrier whose bands matched nothing therefore quoted zero, the
    //  method stayed selectable, and the order froze at 0.00 carriage: the
    //  shop shipped for free rather than declining the sale.
    //
    //  A carrier that cannot carry the cart calls setUnavailable() and does
    //  NOT call setShippingCost(). Whatever cost was set before is discarded:
    //  a refusal is not a price.

    /**
     * Refuse the cart: this carrier will not carry it at any price.
     *
     * The reason is shown to the shopper when the selection is dropped and
     * again if placement is attempted, so it must be a language KEY (a Greek
     * shopper reads this), and it should say what would make the cart
     * carriable ("too heavy for a locker"), not merely that it failed.
     *
     * NOTHING MAY EVER SEED AN ARGUMENT NAMED `unavailable` INTO THIS EVENT.
     * Joomla's AbstractEvent re-sets every constructor-seeded argument through
     * setArgument(), which dispatches to onSet<Ucfirst> or, failing that, to
     * set<Ucfirst>, so a seed by that name would call THIS method during
     * construction and refuse the cart before a plugin was booted. That is not
     * hypothetical: seeding `shippingCost` did exactly that to the answer flag,
     * which is why the dispatcher now seeds nothing at all.
     *
     * @param string $reasonKey Language key explaining the refusal.
     *
     *
     * @since  2.0.0
     */
    public function setUnavailable(string $reasonKey = ''): void
    {
        $this->arguments['shippingUnavailable'] = true;
        $this->arguments['shippingUnavailableReason'] = $reasonKey;
        $this->arguments['shippingAnswered'] = true;
    }

    /**
     * Whether the carrier refused the cart.
     *
     *
     * @since  2.0.0
     */
    public function isUnavailable(): bool
    {
        return (bool) ($this->arguments['shippingUnavailable'] ?? false);
    }

    /**
     * The language key explaining a refusal, or '' when none was given.
     *
     *
     * @since  2.0.0
     */
    public function getUnavailableReason(): string
    {
        return (string) ($this->arguments['shippingUnavailableReason'] ?? '');
    }

    // The price, as the merchant configured it

    /**
     * Answer with the price as the merchant configured it.
     *
     * Its meaning is the method's shipping price entry mode, never the plugin's:
     * core reads it as the gross or as the net and taxes it with the method's
     * shipping tax for the destination.
     *
     * A carrier that cannot carry the cart calls setUnavailable() instead;
     * setting 0.00 here means FREE SHIPPING and is offered as such.
     *
     * @param float $cost The configured price.
     * @since  1.0.0
     */
    public function setShippingCost(float $cost): void
    {
        $this->arguments['shippingCost'] = $cost;
        $this->arguments['shippingAnswered'] = true;
    }

    /**
     * Joomla event setter hook: delegates to setShippingCost().
     *
     * @param float $cost The configured price.
     * @since  1.0.0
     */
    public function onSetShippingCost(float $cost): float
    {
        // MUST return the value: AbstractEvent::setArgument stores the RETURN of an
        // onSet<Arg> hook, so a void return would overwrite the constructor-seeded
        // shippingCost with null (silently zeroing any non-zero base cost).
        $this->setShippingCost($cost);

        return $cost;
    }

    /**
     * The configured price the carrier quoted.
     * @since  1.0.0
     */
    public function getShippingCost(): float
    {
        return (float) ($this->arguments['shippingCost'] ?? 0.0);
    }

    /**
     * Whether the carrier answered at all: with a price, amounts or a refusal.
     *
     * SILENCE IS NOT FREE, AND THIS IS HOW THE DISPATCHER KNOWS. getShippingCost()
     * cannot tell the two apart: a carrier quoting 0.00 and a carrier that never
     * ran a line of pricing code both read as the float 0. So the answering
     * setters mark this, and nothing else does; a hook that returns without
     * calling one leaves it false and the quote fails closed.
     *
     * Deliberately NOT satisfied by the event's construction. The dispatcher
     * seeds no cost, so there is no argument-seeding path that could mark a
     * carrier as having answered before it was even booted.
     *
     *
     * @since  2.0.0
     */
    public function hasAnswered(): bool
    {
        return (bool) ($this->arguments['shippingAnswered'] ?? false);
    }

    // STATED AMOUNTS: a carrier that splits the tax itself
    //
    // Kept under 'shippingStated'. No accessor may be named after that key
    // (see setUnavailable()'s warning): Joomla routes set<Key>/get<Key> through
    // setArgument/getArgument, and the dispatcher seeds nothing.

    /**
     * Answer with amounts this carrier split itself.
     *
     * They are authoritative: no entry mode and no tax resolution is applied.
     * Each amount is read exactly in the cart's currency (finer than it holds is
     * refused, never rounded), the net and the tax must make the gross, and a
     * charge that carries tax names its rate; otherwise the method is withdrawn
     * for this cart.
     *
     * @param string|int|float $taxExcl The net charge.
     * @param string|int|float $taxAmount The tax on it.
     * @param string|int|float $taxIncl The gross charge.
     * @param float $taxRate The rate the tax was charged at, in percent.
     *
     *
     * @since  2.0.0
     */
    public function setShippingAmounts(string|int|float $taxExcl, string|int|float $taxAmount, string|int|float $taxIncl, float $taxRate): void
    {
        $this->arguments['shippingStated'] = [
            'taxExcl' => $taxExcl,
            'taxAmount' => $taxAmount,
            'taxIncl' => $taxIncl,
            'taxRate' => $taxRate,
        ];
        $this->arguments['shippingAnswered'] = true;
    }

    /**
     * The amounts a carrier stated, or null when it quoted a price instead.
     *
     * @return array{taxExcl: string|int|float, taxAmount: string|int|float, taxIncl: string|int|float, taxRate: float}|null
     *
     * @since  2.0.0
     */
    public function statedAmounts(): ?array
    {
        return $this->arguments['shippingStated'] ?? null;
    }
}
