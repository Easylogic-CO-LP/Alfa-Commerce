<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Site\Service\CartRules\CartRules;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Closure;
use Joomla\CMS\User\User;

defined('_JEXEC') or die;

/**
 * Everything computed about one loaded cart, for one request.
 *
 * The three computation owners (rules, totals, methods) built once each
 * from explicit providers over the loaded cart, in the order that makes the
 * cycle between totals and rules terminate; the contributions the plugins
 * add, collected once and folded into money; and the two facts that are this
 * request's alone (the delivery place bound from the form, and a carrier
 * assumed for the purpose of evaluation) with the invalidation each of them
 * implies.
 *
 * It decides nothing about money and writes nothing: the memos are the only
 * state, and a write elsewhere tells it what to forget.
 *
 * @since  2.0.0
 */
final class CartComputation
{
    private ?CartRules $rules = null;
    private ?CartTotals $totals = null;
    private ?CartMethods $methods = null;
    private ?array $contributions = null;

    /** @var object|null The rules result the contributions were folded over. @since 2.0.0 */
    private ?object $contributionsBasis = null;
    private ?int $deliveryPlaceId = null;
    private ?int $assumedShipmentId = null;

    /** @var array<int, int[]> Per-override-field destination chains. @since 2.0.0 */
    private array $methodPlaces = [];

    /** @var ShippingQuote|null The chosen carrier's last answer. @since 2.0.0 */
    private ?ShippingQuote $shipmentQuote = null;

    /** @var int|null Which carrier that answer was asked of. @since 2.0.0 */
    private ?int $shipmentQuoteFor = null;

    /**
     * @param CartRepository $repository The rules engine's store.
     * @param CartPluginDispatch $plugins Asks the plugins their three questions.
     * @param Closure $state (): HydratedCart - the cart as currently loaded.
     * @param Closure $touch (): void - stamp the cart's activity.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartPluginDispatch $plugins,
        private readonly Closure $state,
        private readonly Closure $touch,
    ) {
    }

    /** @since 2.0.0 */
    public function rules(): CartRules
    {
        return $this->rules ??= new CartRules(
            $this->repository,
            fn (): int => (int) $this->state()->identity()->cartId,
            fn (): User => $this->state()->subject()->customer(),
            fn (): array => self::ruleSnapshot($this->state()->payableLines()),
            fn (): Currency => $this->state()->currency(),
            fn (): float => $this->totals()->shipmentGross()->getAmount(),
            fn (): float => $this->totals()->shipmentGrossExcl()->getAmount(),
            function (): void {
                ($this->touch)();
            },
        );
    }

    /**
     * Built once; the rules owner is evaluated as a constructor argument so it
     * exists before the totals do. The totals sum the payable lines: a line
     * flagged unavailable is shown and adds no money.
     *
     * @since  2.0.0
     */
    public function totals(): CartTotals
    {
        return $this->totals ??= new CartTotals(
            fn (): array => $this->state()->payableLines(),
            fn (): Currency => $this->state()->currency(),
            $this->rules(),
            fn (): ShippingQuote => $this->shipmentCosts(),
            fn (): array => $this->contributions(),
        );
    }

    /** @since 2.0.0 */
    public function methods(): CartMethods
    {
        return $this->methods ??= new CartMethods(
            fn (): User => $this->state()->subject()->customer(),
            fn (): bool => $this->state()->subject()->isOperatorPlacement(),
            fn (?object $method = null): array => $this->placesForMethod($method),
            fn (): bool => $this->state()->isVirtual(),
            fn (): int => $this->effectiveShipmentId(),
            fn (string $kind): array => $this->state()->lineScopes($kind),
            // One direction only: dropping the offered sets drops the money that
            // depended on them. CartTotals knows nothing about methods.
            function (): void {
                $this->totals()->forget();
            },
        );
    }

    /**
     * The charges the commercial plugins add, folded into money and ordered.
     *
     * The monetary fold is the pricing engine's; what is decided here is the
     * cart's policy around it: which base a percent is taken of, that a
     * charge quantising to nothing is dropped, whose name a nameless
     * contribution carries, and the order the segments are shown in.
     *
     * A PERCENTAGE IS TAKEN OF WHAT REMAINS CHARGEABLE AFTER THE DISCOUNTS: the
     * goods' net after the product and order rules, plus the shipping's net
     * after the shipping rules. Money a discount already removed is not in the
     * base, so free shipping leaves no shipping in it. A fixed charge does not
     * read the base at all.
     *
     * The fold belongs to the rules result it was made over: a coupon applied
     * or removed, or a carriage that changed, yields a new result and the
     * charges are folded again.
     *
     * @since  2.0.0
     */
    public function contributions(): array
    {
        $basis = $this->rules()->result();

        if ($this->contributions !== null && $this->contributionsBasis === $basis) {
            return $this->contributions;
        }

        $this->contributionsBasis = $basis;
        $state = $this->state();
        $collected = $this->plugins->contributions($state, $state->subject()->customer(), $this->totals()->total());

        if ($collected === []) {
            return $this->contributions = [];
        }

        $totals = $this->totals();
        $baseExcl = $totals->totalExcl()
            ->subtract($this->rules()->totalExcl())
            ->add($totals->shipmentTotalExcl())
            ->getAmount();
        $currency = $state->currency();
        $folded = [];

        foreach ($collected as [$pluginName, $contribution]) {
            ['excl' => $excl, 'tax' => $tax] = PriceComputationEngine::foldCartContribution(
                baseExcl:  $baseExcl,
                operation: $contribution->operation,
                amount:    $contribution->amount,
                taxRate:   $contribution->taxRate,
                currency:  $currency,
            );

            // A charge that quantises to nothing is no charge: it would show
            // the customer a labeled 0.00 segment and freeze a zero row.
            if ($excl <= 0) {
                continue;
            }

            // The gross is the net and the tax added in minor units.
            $incl = Money::of($excl, $currency)->add(Money::of($tax, $currency));

            $folded[] = (object) [
                'code' => $contribution->code,
                'source' => ($contribution->source !== '' ? $contribution->source : $pluginName)
                    . '.' . $contribution->code,
                'label' => $contribution->label,
                'operation' => $contribution->operation,
                'tax_rate' => $contribution->taxRate,
                'amount_tax_excl' => $excl,
                'tax_amount' => $tax,
                'amount_tax_incl' => $incl->getAmount(),
                'amount_tax_incl_money' => $incl,
                'sort_order' => $contribution->sortOrder,
            ];
        }

        usort($folded, static fn (object $a, object $b): int => $a->sort_order <=> $b->sort_order);

        return $this->contributions = $folded;
    }

    /**
     * Where this request says the order is going. The same place again is no
     * change; a different one drops what was offered for the old one.
     *
     * @since  2.0.0
     */
    public function bindDeliveryPlace(?int $placeId): void
    {
        $placeId = $placeId > 0 ? $placeId : null;

        if ($placeId === $this->deliveryPlaceId) {
            return;
        }

        $this->deliveryPlaceId = $placeId;

        // The dispatcher builds the cart a plugin reads, and that cart has to
        // name the destination this submission bound, not the one the row
        // remembers from a previous one.
        $this->plugins->bindDeliveryPlace($placeId);
        $this->forgetMethods();
    }

    /**
     * The delivery place: the one bound for this request, else the one the
     * cart's delivery address records in its place field.
     *
     * @since  2.0.0
     */
    public function deliveryPlaceId(): ?int
    {
        if ($this->deliveryPlaceId !== null) {
            return $this->deliveryPlaceId;
        }

        $state = $this->state();
        $field = FieldsHelper::deliveryPlaceField($state->subject()->customerId());
        $name = (string) ($field->field_name ?? '');
        $placeId = $name !== '' ? (int) ($state->deliveryAddress()->{$name} ?? 0) : 0;

        return $placeId > 0 ? $placeId : null;
    }

    /**
     * Where the order is going, as the cart's own delivery API answers it.
     *
     * EMPTY MEANS UNKNOWN, AND UNKNOWN RESTRICTS. No mapped field, a shopper
     * who has not answered, and one who cleared the box are three different
     * problems for the merchant; FieldsHelper's destination state tells them
     * apart, but they are one answer to "where is this going": nobody knows.
     * An empty list admits methods and zones that name no place and refuses
     * every one that does, which is what the offer filter and the carrier's
     * own zone matching both need to say.
     *
     * Passing null here USED to mean "skip the place filter", so an unknown
     * destination offered every place-restricted carrier and then watched it
     * refuse at the quote. The two halves now agree.
     *
     * RESOLVED IN ONE PLACE, WHICH IS NOT THIS ONE. The chain is built by
     * {@see AddressView::places()} (the same call a plugin makes) so the
     * component cannot come to a different conclusion than the cart API it
     * publishes. What this class still owns is the BINDING: which destination
     * belongs to this submission, and dropping what was offered for the last one.
     *
     * @return int[] Nearest first; empty when the destination is unknown.
     *
     * @since  2.0.0
     */
    public function deliveryPlaces(): array
    {
        return $this->deliveryAddress()->places();
    }

    /**
     * Where ONE method is being asked about, for the offer filter and the quote.
     *
     * THE SAME RULE A CARRIER APPLIES, in the same two steps: the method's
     * configured destination field decides WHICH value to read, the cart's
     * delivery API reads it, and the geographic helper expands it. A carrier
     * writes those two lines itself against its own setting; this is core's
     * copy of the same rule, and it is the answer the carrier will be quoted
     * against and placement will re-check.
     *
     * AN OVERRIDE MOVES NOTHING ELSE. Only the method holding the setting reads
     * it; its neighbours keep the shop's destination. The shipping tax is
     * resolved against THIS chain (the destination decides it); the tax of the
     * goods keeps its own address policy, which is not routed through this.
     *
     * WHAT THIS ADDS IS THE BINDING AND THE MEMO. The destination this
     * SUBMISSION named outranks the row for the shop's own field, and the offer
     * filter asks this of every published method (several of which will name
     * the same field) so the answer is kept for the request.
     *
     * @param object|null $method The method row, or the cart's Method reader.
     *
     * @return int[] The destination and everything containing it, nearest first.
     *
     * @since  2.0.0
     */
    public function placesForMethod(?object $method): array
    {
        // WHICH FIELD, WHICH IS CONFIGURATION: the one rule every stage
        // shares, so a method is offered, quoted and placed against the same
        // field. Nothing about a METHOD travels past this line.
        $fieldId = FieldsHelper::methodDestinationFieldId(method: $method);

        // Configuring none is inheritance, and the shop's destination is the
        // one this class binds (a submitted place outranks the saved row) so
        // it is answered here rather than by the address alone.
        if ($fieldId <= 0) {
            return $this->deliveryPlaces();
        }

        // Otherwise it is the cart's own delivery API, given the FIELD this
        // method names: the same one-line call a carrier makes, so a method
        // cannot be offered against one destination and priced against another.
        // An unfilled, cleared or deleted field yields nothing, never the shop.
        return $this->methodPlaces[$fieldId]
            ??= $this->deliveryAddress()->places(fieldId: $fieldId);
    }

    /**
     * The cart's delivery address, read exactly as a plugin reads it.
     *
     * Built by the dispatcher because that is where the submitted values and
     * the bound destination are already known; asking for it here rather than
     * assembling a second one is what keeps the core and the published cart API
     * from answering the same question differently.
     *
     *
     * @since  2.0.0
     */
    private function deliveryAddress(): AddressView
    {
        return $this->plugins->deliveryAddress($this->state());
    }

    /**
     * Evaluate this cart as though this carrier were chosen, writing nothing.
     *
     * The assumption is this request's alone, and it drops the offered method
     * lists and the carriage they priced so that what is read next is computed
     * against it.
     *
     * @since  2.0.0
     */
    public function assumeShipment(int $shipmentMethodId): void
    {
        // THE SAME CARRIER AGAIN IS NOT A CHANGE. Placement assumes the
        // submitted carrier to test it and again to price it; an unguarded
        // forget between the two would discard the quote and ask an outside
        // network a second time on the checkout's critical path.
        if ($this->assumedShipmentId === $shipmentMethodId) {
            return;
        }

        $this->assumedShipmentId = $shipmentMethodId;
        $this->forgetMethods();
    }

    /**
     * A write changed what is offered: drop the method lists, and through them
     * the carriage.
     *
     * @since  2.0.0
     */
    public function forgetMethods(): void
    {
        // The carriage was quoted for a destination and a carrier that may both
        // have just changed, and that answer now decides selectability, not
        // just a price.
        $this->shipmentQuote = null;
        $this->shipmentQuoteFor = null;

        // AND EVERYTHING FOLDED OVER THAT CARRIAGE. The rules engine decides
        // what the shopper is actually charged for shipping: a "free over 50"
        // benefit is computed FROM the quote, and its answer is memoised. The
        // offered-set forget below reaches CartTotals, but CartTotals reads the
        // carriage the shopper pays out of the RULES result, so dropping the
        // quote without dropping that left a grand total still carrying
        // carriage nobody was charging any more.
        //
        // Generic on purpose: this says "the carriage changed", not "the
        // postcode changed". The component has no postcode concept to hook.
        $this->methodPlaces = [];
        $this->rules()->forget();

        $this->methods()->forget();
    }

    /**
     * The carrier eligibility and carriage are computed against: the assumed
     * one while one is assumed, the row's otherwise.
     */
    private function effectiveShipmentId(): int
    {
        return $this->assumedShipmentId ?? $this->state()->shipmentId();
    }

    /**
     * The chosen carrier's answer: its price, or its refusal to carry at all.
     *
     * MEMOISED PER EFFECTIVE CARRIER: the totals want the money, reconciliation
     * and placement want the availability, and neither may cause a second
     * dispatch to an outside network.
     *
     * ASKS NOTHING OF THE OFFERED LIST, which is what keeps quoting and
     * filtering from calling each other.
     *
     * @since  2.0.0
     */
    public function shipmentQuote(): ShippingQuote
    {
        $shipmentId = $this->effectiveShipmentId();

        if ($this->shipmentQuoteFor === $shipmentId && $this->shipmentQuote !== null) {
            return $this->shipmentQuote;
        }

        $this->shipmentQuoteFor = $shipmentId;

        return $this->shipmentQuote = $this->plugins->shippingCost(
            $shipmentId,
            $this->state(),
            fn (): float => (float) $this->totals()->total()->getAmount(),
            // The tax follows the same destination the carrier is quoted and the
            // method offered against.
            fn (object $method): array => $this->placesForMethod($method),
        );
    }

    private function shipmentCosts(): ShippingQuote
    {
        return $this->shipmentQuote();
    }

    private function state(): HydratedCart
    {
        return ($this->state)();
    }

    /**
     * The rules engine's view of the lines: payable, priced ones only, as
     * numbers. The caller hands in the payable lines, so a line flagged
     * unavailable reaches no coupon threshold.
     */
    private static function ruleSnapshot(array $lines): array
    {
        $snapshot = [];

        foreach ($lines as $cartItem) {
            $price = $cartItem->data->price ?? null;

            if (!$price || !\is_object($price)) {
                continue;
            }

            $incl = (float) $price->getTotal()->getAmount();

            $snapshot[] = (object) [
                'id' => (int) $cartItem->id,
                'id_item' => (int) $cartItem->id_item,
                'quantity' => (int) $cartItem->quantity,
                'line_tax_incl' => $incl,
                // The engine's own net for this line: it IS the net of
                // what the customer pays, so dividing the gross back here would be a
                // second implementation of the same derivation with its own rounding.
                'line_tax_excl' => (float) $price->getSubtotal()->getAmount(),
                'has_catalogue_discount' => $price->hasDiscount(),
                // THE SECOND FACT, AND IT IS INDEPENDENT OF THE FIRST: not
                // derived from it, and never a stand-in for it. On an
                // overridden line hasDiscount() is FALSE, because the override
                // replaced the catalogue's discount rather than stacking on it;
                // the override's own axis is this one.
                'has_custom_price' => $price->hasExplicitPrice(),
            ];
        }

        return $snapshot;
    }
}
