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
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingCharge;
use Closure;
use Joomla\CMS\Language\Text;
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

    /** @var array|null The plugins' charges, folded: the contributions before the payment method's fee. @since 2.3.0 */
    private ?array $charges = null;

    /** @var object|null The rules result the plugins' charges were folded over. @since 2.3.0 */
    private ?object $chargesBasis = null;

    /** @var int|null The payment method the contributions' fee was worked out for. @since 2.3.0 */
    private ?int $contributionsPayment = null;

    /**
     * The plugins the customer's view levels admit that Joomla does not load for the request's user, named when the
     * charges were last collected: their charges are missing, so the cart carries the refusal.
     *
     * @var list<string>
     * @since  2.2.0
     */
    private array $contributionsNotLoaded = [];
    private ?int $assumedShipmentId = null;

    /** @var int|null A payment method assumed for the purpose of evaluation, its fee with it. @since 2.3.0 */
    private ?int $assumedPaymentId = null;

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
     * @param DeliveryBinding $delivery Where the cart is delivered: the one binding, shared with the hydrator.
     * @param Closure $reprice (): void - price the lines again at the delivery place, once it changed.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartPluginDispatch $plugins,
        private readonly Closure $state,
        private readonly Closure $touch,
        private readonly DeliveryBinding $delivery,
        private readonly Closure $reprice,
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
            // Every carrier the storefront would offer is asked what it is asked once chosen, so none is offered that
            // would refuse the shopper's first click. The chosen one answers once per reading, from the same
            // memo the totals read; any other is told it is not the chosen one.
            fn (int $methodId): ShippingQuote => $methodId === $this->effectiveShipmentId()
                ? $this->shipmentQuote()
                : $this->plugins->shippingCost(
                    $methodId,
                    $this->state(),
                    fn (): float => (float) $this->totals()->total()->getAmount(),
                    fn (object $method): array => $this->placesForMethod($method),
                    chosen: false,
                ),
        );
    }

    /**
     * Every charge the order carries beside its goods and its shipping: the commercial plugins' charges, then the
     * chosen payment method's fee ({@see PaymentFee}), as rows of one shape, in the order they are shown and frozen.
     *
     * ONE PATH FOR EVERY CHARGE. The fee is a contribution of core's own (code payment_fee): the totals add it with
     * the plugins' charges, the summary shows it as its own line, and placement freezes it with them. It belongs to the
     * rules result the charges were folded over and to the chosen payment method: choosing another method totals
     * again.
     *
     * @since  2.0.0
     */
    public function contributions(): array
    {
        $charges = $this->charges();
        $basis = $this->chargesBasis;
        $paymentId = $this->assumedPaymentId ?? $this->state()->paymentId();

        if ($this->contributions !== null && $this->contributionsBasis === $basis && $this->contributionsPayment === $paymentId) {
            return $this->contributions;
        }

        $this->contributionsBasis = $basis;
        $this->contributionsPayment = $paymentId;
        $method = $paymentId > 0 ? ($this->methods()->payments()[$paymentId] ?? null) : null;
        $fee = $method !== null ? $this->paymentFee($method) : null;

        if ($fee === null) {
            return $this->contributions = $charges;
        }

        $charges[] = (object) [
            'code' => PaymentFee::CODE,
            'source' => 'alfa-payments.' . (string) ($method->type ?? '') . '.' . PaymentFee::CODE,
            'label' => Text::sprintf('COM_ALFA_PAYMENT_FEE_LABEL', (string) ($method->name ?? '')),
            'operation' => '+',
            'tax_rate' => $fee->taxRate,
            'amount_tax_excl' => $fee->taxExcl->getAmount(),
            'tax_amount' => $fee->tax->getAmount(),
            'amount_tax_incl' => $fee->taxIncl->getAmount(),
            'amount_tax_incl_money' => $fee->taxIncl,
            // After every plugin charge, in every reading and in the frozen order.
            'sort_order' => max([0, ...array_map(static fn (object $row): int => (int) $row->sort_order, $charges)]) + 1,
        ];

        return $this->contributions = $charges;
    }

    /**
     * The fee a payment method charges this cart, or null when it charges none: the figure the checkout shows beside
     * the method before it is chosen, and the one the totals carry once it is.
     *
     * @param object $method The payment method, as the method lists read it.
     *
     * @since  2.3.0
     */
    public function paymentFee(object $method): ?ShippingCharge
    {
        $state = $this->state();
        $totals = $this->totals();
        $currency = $state->currency();

        // The order total before the fee: the goods after their discounts, the shipping after its rules and the
        // plugins' charges.
        $incl = $totals->total()->add($totals->shipmentTotal())->subtract($this->rules()->total());
        $excl = $totals->totalExcl()->add($totals->shipmentTotalExcl())->subtract($this->rules()->totalExcl());

        foreach ($this->charges() as $row) {
            $incl = $incl->add($row->amount_tax_incl_money);
            $excl = $excl->add(Money::of($row->amount_tax_excl, $currency));
        }

        return PaymentFee::of(
            method:      $method,
            totalIncl:   $incl,
            totalExcl:   $excl,
            destination: $this->deliveryPlaces(),
            audience:    $state->subject()->priceContext(),
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
     * @since  2.3.0
     */
    private function charges(): array
    {
        $basis = $this->rules()->result();

        if ($this->charges !== null && $this->chargesBasis === $basis) {
            return $this->charges;
        }

        $this->chargesBasis = $basis;
        $state = $this->state();
        ['charges' => $collected, 'notLoaded' => $this->contributionsNotLoaded] = $this->plugins->contributions($state, $state->subject()->customer(), $this->totals()->total());

        if ($collected === []) {
            return $this->charges = [];
        }

        $totals = $this->totals();
        $baseExcl = $totals->totalExcl()
            ->subtract($this->rules()->totalExcl())
            ->add($totals->shipmentTotalExcl())
            ->getAmount();
        $currency = $state->currency();
        $folded = [];

        foreach ($collected as [$contributor, $contribution]) {
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
                // group.element.code; rows already frozen keep the source they were frozen with.
                'source' => $contributor . '.' . $contribution->code,
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

        return $this->charges = $folded;
    }

    /**
     * The plugins the customer's view levels admit that Joomla does not load for the request's user (staff lacking
     * one of the customer's levels), as group.element: the charges are collected first. Their charges are
     * missing from these totals and the placement refuses; the page shows the refusal with the totals.
     *
     * @return list<string>
     *
     * @since  2.2.0
     */
    public function contributionsNotLoaded(): array
    {
        $this->charges();

        return $this->contributionsNotLoaded;
    }

    /**
     * Where this request says the order is going. The same place again is no
     * change; a different one drops what was offered for the old one, and the
     * goods are priced again at it: goods and carriage follow the one
     * binding.
     *
     * @since  2.0.0
     */
    public function bindDeliveryPlace(?int $placeId): void
    {
        if (!$this->delivery->bind($placeId)) {
            return;
        }

        // The dispatcher builds the cart a plugin reads, and that cart has to
        // name the destination this submission bound, not the one the row
        // remembers from a previous one.
        $this->plugins->bindDeliveryPlace($this->delivery->bound());
        $this->forgetMethods();
        ($this->reprice)();
    }

    /**
     * The delivery place: the one bound for this request, else the one the
     * cart's delivery address records in its place field.
     *
     * @since  2.0.0
     */
    public function deliveryPlaceId(): ?int
    {
        $state = $this->state();

        return $this->delivery->placeOf($state->deliveryAddress(), $state->subject()->customerId());
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
     * Evaluate this cart as though this payment method were chosen, writing nothing: its fee is the one the totals
     * carry. Placement assumes the method the submission names, as it assumes the carrier, so the order is totalled
     * and frozen with the fee of the method it records, whatever the cart row last stored.
     *
     * @param int $paymentMethodId The payment method.
     *
     * @since  2.3.0
     */
    public function assumePayment(int $paymentMethodId): void
    {
        $this->assumedPaymentId = $paymentMethodId;
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
            chosen: true,
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
