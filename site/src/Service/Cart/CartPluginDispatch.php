<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Event\Cart\CartTotalsEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\CartViewAssetsEvent as PaymentsCartViewAssetsEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\CartViewEvent as PaymentsCartViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\CalculateShippingCostEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\CartViewAssetsEvent as ShipmentsCartViewAssetsEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\CartViewEvent as ShipmentsCartViewEvent;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CommercialPlugins;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\CartAssetsRequest;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\ContributionLine;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\ContributionRequest;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\MethodViewContribution;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\MethodViewRequest;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\ShippingCostRequest;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceComputationEngine;
use Alfa\Component\Alfa\Site\Service\Shipping\ShipmentPricingContext;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingCharge;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingPriceEntryModeResolver;
use Alfa\Component\Alfa\Site\Service\Shipping\ShippingTaxResolver;
use Closure;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Event\SubscriberInterface;
use Throwable;

defined('_JEXEC') or die;

/**
 * The cart's side of the plugin contract.
 *
 * Three questions the cart asks its plugins, each with the readonly request
 * that question carries: what a carrier charges, what the commercial plugins
 * add to the total, and what each offered method wants shown beside it. The
 * requests are built here from the loaded cart and nothing else; no plugin is
 * ever handed the cart.
 *
 * @since  1.10.0
 */
final class CartPluginDispatch
{
    /**
     * Where the current submission says the order is going, or null when it
     * said nothing. Carried so the cart a plugin reads names the same
     * destination the offer filter was run against.
     *
     * @since  1.10.0
     */
    private ?int $boundPlaceId = null;

    /**
     * @param CMSApplication $app The application the plugins are booted from.
     * @param ShippingPriceEntryModeResolver $entryModes What an entered shipping price means.
     * @param ShippingTaxResolver $shippingTaxes Which tax a shipping charge carries.
     *
     * @since  1.10.0
     */
    public function __construct(
        private readonly CMSApplication $app,
        private readonly ShippingPriceEntryModeResolver $entryModes = new ShippingPriceEntryModeResolver(),
        private readonly ShippingTaxResolver $shippingTaxes = new ShippingTaxResolver(),
    ) {
    }

    /**
     * The carrier's own price for this cart.
     *
     * The inputs that cost something (the delivery places and the merchandise
     * total) are asked for only once a carrier is actually chosen.
     *
     * THE CHARGE IS NORMALISED HERE, ONCE. What the carrier answered becomes
     * the canonical net, tax and gross before anything else reads it: a price
     * is read through the shop's entry mode and taxed with the method's
     * shipping tax for the destination; amounts the carrier stated itself are
     * taken as they are.
     *
     * @param int $shipmentId The carrier to price against; 0 for none.
     * @param HydratedCart $cart The cart.
     * @param Closure $merchandiseTotal (): float - what the goods come to.
     * @param Closure $destination (object $method): int[] - where that method's
     *                             shipment is going, nearest first: the chain the
     *                             offer filter and the carrier use.
     *
     * @return ShippingQuote The carrier's answer: a charge, or a refusal.
     *
     * @since  1.10.0
     */
    public function shippingCost(
        int $shipmentId,
        HydratedCart $cart,
        Closure $merchandiseTotal,
        Closure $destination,
    ): ShippingQuote {
        $currentMethod = $this->shipmentRecord($shipmentId);
        $currentType = $currentMethod?->type;

        // NO CARRIER IS NOT A REFUSAL. A cart that has not chosen one yet, or
        // whose method row is gone, is quoted at nothing and stays offerable;
        // refusing here would take every carrier off a cart that simply has not
        // picked one.
        if (!$currentType) {
            return ShippingQuote::free();
        }

        $eventName = 'onCalculateShippingCost';
        // NO SEEDED COST. A zero here is indistinguishable from a carrier
        // quoting free, and seeding one is what made an unanswered hook look
        // like an answer. The event starts with nothing and the carrier has to
        // put something in it.
        $event = new CalculateShippingCostEvent($eventName, [
            'subject' => $this->shippingCostRequest($currentMethod, $cart, $merchandiseTotal()),
        ]);

        // WAS THE CARRIER ACTUALLY ASKED? Silence only means something if a
        // question was put. A plugin that is switched off, or that does not
        // implement the hook, has not been asked and cannot be said to have
        // stayed silent, and the shop already knows exactly what is wrong with
        // it, which is a better diagnosis than "no answer" and is made by
        // OrderPlacement's own withdrawn-plugin guard and by the offer filter
        // that drops the method on the next request. Overwriting that with a
        // generic refusal here would replace a precise cause with a vague one.
        $asked = false;

        if (PluginHelper::isEnabled('alfa-shipments', (string) $currentType)) {
            $plugin = $this->app->bootPlugin($currentType, 'alfa-shipments');

            if (method_exists($plugin, $eventName)) {
                $asked = true;

                try {
                    $plugin->{$eventName}($event);
                } catch (Throwable $e) {
                    // A carrier that throws is a carrier that cannot price
                    //
                    // This runs while a SHOPPER is looking at their cart, so a
                    // stored value the carrier cannot read (a postcode column
                    // holding something that is not a postcode, a payload
                    // written by hand) has to come out as "this method is not
                    // available", the same as any other refusal. Uncaught, it
                    // was a white page over a cart with goods in it.
                    //
                    // NOT the presentation policy, deliberately. That one logs
                    // and carries on, which is right for an optional badge
                    // beside a method and wrong for the price of carrying the
                    // goods: this WITHDRAWS the method instead.
                    $this->quoteFailed($currentType, $e);

                    return ShippingQuote::unavailable('COM_ALFA_SHIPMENT_QUOTE_FAILED');
                }
            }
        }

        // A refusal outranks anything the plugin may also have set: a carrier
        // that says it cannot carry the cart has not quoted a price, and
        // reading one it left behind would charge for a shipment nobody agreed
        // to make.
        if ($event->isUnavailable()) {
            return ShippingQuote::unavailable($event->getUnavailableReason());
        }

        // And silence is not an answer
        // A hook that ran and set neither a price nor a refusal has told the
        // shop nothing, and the shop must not fill that in for it. Reading the
        // unset cost as 0.00 offered the carrier, let it be selected, and froze
        // the order at no carriage, which is exactly how a carrier shipped free
        // for months with its pricing hook commented out. The same silence now
        // takes the carrier off the cart and says why.
        if ($asked && !$event->hasAnswered()) {
            return ShippingQuote::unanswered();
        }

        // A charge that cannot be normalised prices nothing: a chain that cannot
        // carry the price, stated amounts that do not make one charge, a negative
        // price. The method is withdrawn exactly as for a carrier that throws.
        try {
            $charge = $this->chargeFor(
                event: $event,
                method: Method::of($currentMethod, selected: true),
                cart: $cart,
                destination: $destination,
            );
        } catch (Throwable $e) {
            $this->quoteFailed((string) $currentType, $e);

            return ShippingQuote::unavailable('COM_ALFA_SHIPMENT_QUOTE_FAILED');
        }

        return ShippingQuote::of($charge);
    }

    /**
     * The carrier's answer in canonical form.
     *
     * STATED AMOUNTS ARE AUTHORITATIVE. A carrier that split the tax itself has
     * answered the tax question: its figures are read exactly in the cart's
     * currency and bypass the entry mode and the tax rules. Otherwise the price
     * it quoted is the price as configured, read through the entry mode the
     * resolver names and taxed with the chain the method's taxes give for this
     * destination. Both questions are asked of their resolvers, never of the
     * component configuration.
     *
     * @param CalculateShippingCostEvent $event The answered event.
     * @param Method $method The method being priced.
     * @param HydratedCart $cart The cart.
     * @param Closure $destination (object $method): int[] - where it is going.
     *
     * @since  1.14.0
     */
    private function chargeFor(CalculateShippingCostEvent $event, Method $method, HydratedCart $cart, Closure $destination): ShippingCharge
    {
        $currency = $cart->currency();
        $stated = $event->statedAmounts();

        if ($stated !== null) {
            return ShippingCharge::explicit(
                taxExcl: ExactMoney::of($stated['taxExcl'], $currency),
                tax: ExactMoney::of($stated['taxAmount'], $currency),
                taxIncl: ExactMoney::of($stated['taxIncl'], $currency),
                taxRate: (float) $stated['taxRate'],
            );
        }

        $context = new ShipmentPricingContext(
            methodId: $method->id(),
            destination: $destination($method),
            audience: $cart->subject()->priceContext(),
            currency: $currency,
        );
        $chain = $this->shippingTaxes->resolve(context: $context);

        return ShippingCharge::fromEntered(
            entered: Money::of($event->getShippingCost(), $currency),
            mode: $this->entryModes->resolve(context: $context),
            chain: $chain,
            rate: PriceComputationEngine::chainRate(appliedTaxes: $chain),
        );
    }

    /**
     * The charges the commercial plugins add, as they were contributed:
     * [plugin name, CartContribution] pairs, in dispatch order. Folding them
     * into money is the computation's business, and the pricing engine's.
     *
     * @since  1.10.0
     */
    public function contributions(HydratedCart $cart, User $customer, Money $subtotal): array
    {
        $collected = [];
        $request = $this->contributionRequest($cart, $subtotal);

        foreach (CommercialPlugins::for($customer) as $alfaPlugin) {
            $plugin = $this->app->bootPlugin($alfaPlugin->name, 'alfa');

            if (!method_exists($plugin, 'onCartTotals')) {
                continue;
            }

            $event = new CartTotalsEvent('onCartTotals', ['subject' => $request]);

            try {
                $plugin->onCartTotals($event);

                foreach ($event->getContributions() as $contribution) {
                    $collected[] = [$alfaPlugin->name, $contribution];
                }
            } catch (Throwable $e) {
                $this->pluginFailed('Cart totals contribution failed (' . $alfaPlugin->name . ')', $e);

                continue;
            }
        }

        return $collected;
    }

    /**
     * What each offered method asked to show beside it, keyed by method id.
     *
     * THE CART IS PASSED IN, NOT FETCHED. Every method is drawn against the cart
     * the caller has already settled and is about to snapshot (never against a
     * fresh read of the session) so what a shopper is shown and what they are
     * charged are the same cart, and a plugin cannot be handed a different one
     * than its sibling two lines above.
     *
     * @param string $kind 'payment' or 'shipment'.
     * @param array $offered The offered methods.
     * @param int $chosenId The one the cart records as chosen.
     * @param Money $total The merchandise total.
     * @param HydratedCart $cart The settled cart being offered.
     *
     * @return MethodViewContribution[]
     *
     * @since  1.10.0
     */
    public function methodViews(
        string $kind,
        array $offered,
        int $chosenId,
        Money $total,
        HydratedCart $cart,
        ?Money $payable = null,
    ): array {
        $isPayment = $kind === 'payment';
        $group = $isPayment ? 'alfa-payments' : 'alfa-shipments';
        $subscribed = [];
        $views = [];

        foreach ($offered as $method) {
            $type = (string) ($method->type ?? '');
            $methodId = (int) ($method->id ?? 0);
            $plugin = $this->app->bootPlugin($type, $group);

            if ($plugin instanceof SubscriberInterface && empty($subscribed[$type])) {
                $this->app->getDispatcher()->addSubscriber($plugin);
                $subscribed[$type] = true;
            }

            if (!\is_object($plugin) || !method_exists($plugin, 'onCartView')) {
                continue;
            }

            $selected = $methodId === $chosenId;
            $request = $this->methodViewRequest($method, $selected, $total, $cart, $payable);

            $event = $isPayment
                ? new PaymentsCartViewEvent('onCartView', ['subject' => $request])
                : new ShipmentsCartViewEvent('onCartView', ['subject' => $request]);

            // A METHOD'S DECORATION IS NOT ITS ELIGIBILITY. What a carrier or a
            // gateway wants to draw beside itself is optional; whether the cart
            // may use it was decided before this method was called, by the
            // engine, and is not revisited here. So a plugin that throws while
            // drawing loses its markup and nothing else: the method stays
            // offered, the next plugin still runs, and the page still renders.
            // The alternative is the one this catch exists to prevent: one
            // carrier's template bug taking down every cart in the shop.
            try {
                $plugin->onCartView($event);

                $pluginName = (string) $event->getLayoutPluginName();
                $pluginType = (string) $event->getLayoutPluginType();

                if ($selected) {
                    $pluginName = $pluginName !== '' ? $pluginName : $type;
                    $pluginType = $pluginType !== '' ? $pluginType : $group;
                }

                $views[$methodId] = new MethodViewContribution(
                    methodId: $methodId,
                    layout: (string) $event->getLayout(),
                    layoutData: (array) $event->getLayoutData(),
                    pluginType: $pluginType,
                    pluginName: $pluginName,
                );
            } catch (Throwable $e) {
                $this->pluginFailed(
                    $kind . ' view failed (onCartView, ' . $group . '/' . $type . ', method #' . $methodId . ')',
                    $e,
                );

                continue;
            }
        }

        return $views;
    }

    /**
     * Ask each method plugin what code its panel needs on this page.
     *
     * ONCE PER PLUGIN, FROM THE PUBLISHED LIST. The offer is the wrong list
     * to ask from: it narrows as the shopper types an address or removes a
     * line, and a script cannot be added to a page after its head is
     * compiled: a carrier that becomes eligible after a country change would
     * arrive without its script. So every plugin behind a published method is
     * asked, and the offer decides only which panels are drawn.
     *
     * Called by the HTML renders alone. The JSON tasks never call it, because
     * nothing declared inside them could reach the browser.
     *
     * Same isolation as the views: a plugin that throws loses its assets and
     * nothing else.
     *
     * @param string $kind 'payment' or 'shipment'.
     * @param array $published The published methods of that kind, keyed by id.
     * @param HtmlDocument $document The page being built.
     *
     *
     * @since  1.10.0
     */
    public function methodAssets(string $kind, array $published, HtmlDocument $document): void
    {
        $isPayment = $kind === 'payment';
        $group = $isPayment ? 'alfa-payments' : 'alfa-shipments';
        $byType = [];

        foreach ($published as $method) {
            $type = (string) ($method->type ?? '');

            if ($type === '') {
                continue;
            }

            $byType[$type][(int) ($method->id ?? 0)] = Method::of(record: $method);
        }

        foreach ($byType as $type => $methods) {
            $plugin = $this->app->bootPlugin($type, $group);

            if (!\is_object($plugin) || !method_exists($plugin, 'onCartViewAssets')) {
                continue;
            }

            $request = new CartAssetsRequest(kind: $kind, methods: $methods, document: $document);

            $event = $isPayment
                ? new PaymentsCartViewAssetsEvent('onCartViewAssets', ['subject' => $request])
                : new ShipmentsCartViewAssetsEvent('onCartViewAssets', ['subject' => $request]);

            try {
                $plugin->onCartViewAssets($event);
            } catch (Throwable $e) {
                $this->pluginFailed(
                    $kind . ' assets failed (onCartViewAssets, ' . $group . '/' . $type . ')',
                    $e,
                );
            }
        }
    }

    /**
     * A carrier failed while being asked what it charges.
     *
     * A POLICY OF ITS OWN, BECAUSE PRICING IS NOT PRESENTATION. The
     * contribution policy next door logs and continues, which is right for a
     * badge beside a method and wrong for the carriage: a carrier that could
     * not price the cart has not priced it, so the caller turns this into
     * ShippingQuote::unavailable() and the method leaves the list with a reason
     * the shopper can read. Nothing is charged, nothing is offered, and nothing
     * is shrugged off.
     *
     * Same two audiences as everywhere else: a warning in the log for whoever
     * maintains the shop, and a rethrow under JDEBUG so a developer meets the
     * failure instead of a withdrawn method.
     *
     * @param string $type The shipment plugin that threw.
     * @param Throwable $e What it threw.
     *
     *
     * @since  1.10.0
     */
    private function quoteFailed(string $type, Throwable $e): void
    {
        if (\defined('JDEBUG') && JDEBUG) {
            throw $e;
        }

        Log::add(
            'Shipping quote failed (' . $type . '), method withdrawn: '
            . $e::class . ' - ' . $e->getMessage(),
            Log::WARNING,
            'com_alfa.cart',
        );
    }

    /**
     * A plugin failed while being asked to CONTRIBUTE something optional.
     *
     * The policy, stated once because two hooks share it: under JDEBUG the
     * failure is raised, because a developer asked to see it; otherwise it is a
     * warning in the cart log and the caller carries on without that plugin's
     * answer. What it deliberately does NOT do is decide anything commercial:
     * a plugin that could not draw its box has not made a method ineligible,
     * and a plugin that could not name a surcharge has not changed the price.
     *
     * THIS IS FOR PRESENTATION AND COLLECTION HOOKS ONLY. Pricing, transactions,
     * placement and persistence keep the boundaries they have: a failure there
     * means the act did not happen, and pretending otherwise is how a shop
     * ships an order it was never paid for.
     *
     * The message names the plugin, the hook and the method so the log is
     * enough to find the fault; the exception's own text is included and its
     * trace is not.
     *
     * @param string $what The context: which hook, which plugin, which method.
     * @param Throwable $e What it threw.
     *
     *
     * @since  1.10.0
     */
    private function pluginFailed(string $what, Throwable $e): void
    {
        if (\defined('JDEBUG') && JDEBUG) {
            throw $e;
        }

        Log::add(
            $what . ': ' . $e::class . ' - ' . $e->getMessage(),
            Log::WARNING,
            'com_alfa.cart',
        );
    }

    /**
     * The carrier's record, whatever its state; null for no carrier.
     *
     * @since  1.10.0
     */
    private function shipmentRecord(int $shipmentId): ?object
    {
        if ($shipmentId == 0) {
            return null;
        }

        $shipmentModel = $this->app->bootComponent('com_alfa')
            ->getMVCFactory()->createModel('Shipment', 'Administrator', ['ignore_request' => true]);

        // getItem() answers FALSE for a row that is not there, and the caller
        // reads this as "no carrier". Returning it unconverted made the
        // declared ?object a lie and turned a deleted method into a TypeError
        // in the middle of pricing a cart.
        $record = $shipmentModel->getItem($shipmentId);

        return \is_object($record) ? $record : null;
    }

    /**
     * What a carrier is asked to quote against.
     *
     * THE DESTINATION IS THE REQUEST'S, NOT THE ROW'S. Where the order is going
     * is settled by the checkout before this is built and handed in, because
     * the cart row records where a previous submission was going and quoting
     * against that charged the wrong zone.
     *
     * @since  1.10.0
     */
    /**
     * The cart as a plugin reads it, carrying what the checkout currently holds.
     *
     * THE PENDING VALUES ARE READ ONCE, HERE. A plugin asking for the postcode
     * mid-checkout must get what the shopper has just typed, not what an
     * address row saved last week, and must get their DELETION of it too. The
     * facade does not fetch them itself: this dispatcher knows which submission
     * is being evaluated, and a facade that read the session on its own could
     * hand one plugin a different cart than the plugin beside it.
     *
     * @param HydratedCart $cart The settled cart.
     * @param Money|null $subtotal The settled cart-item amount the caller already has.
     *
     *
     * @since  1.10.0
     */
    private function reader(HydratedCart $cart, ?Money $subtotal = null, ?Money $payable = null): Cart
    {
        $form = (array) $this->app->getUserState('com_alfa.cart.form_data', []);

        return Cart::of(
            $cart,
            $this->withBoundDestination((array) ($form[FieldsHelper::FIELDS_KEY] ?? [])),
            (array) ($form[FieldsHelper::BILLING_FIELDS_KEY] ?? []),
            $subtotal,
            $payable,
        );
    }

    /**
     * The destination THIS submission bound, written into the delivery values
     * the reader is built from.
     *
     * WITHOUT THIS THE CART API AND THE CORE WOULD ANSWER DIFFERENTLY, and the
     * difference is not hypothetical. The bound place is resolved from the
     * checkout FORM (typed values, else the shopper's sole saved address, else
     * the field's own default) while the raw user-state array holds only what
     * was actually typed. A guest with one saved address and nothing typed
     * gives a bound place and an empty pending array, so a carrier asking the
     * cart where the order was going would have been told "nobody knows" while
     * the offer filter was already using the country.
     *
     * Overlaid as a PENDING value because that is exactly what it is: this
     * request's answer, which the address view already ranks above the saved
     * row. Nothing is bound when nothing was submitted, and then the reader
     * falls through to the typed values and the row as before.
     *
     * @param array $delivery The submitted delivery values, by column name.
     *
     * @return array The same, with this request's destination in it.
     *
     * @since  1.10.0
     */
    private function withBoundDestination(array $delivery): array
    {
        if ($this->boundPlaceId === null) {
            return $delivery;
        }

        $column = (string) (FieldsHelper::deliveryPlaceField()->field_name ?? '');

        // No mapped destination field is a shop that cannot say where anything
        // is going. There is no column to write the bound place into, and
        // inventing one would be the guess the resolver exists to refuse.
        if ($column === '') {
            return $delivery;
        }

        $delivery[$column] = (string) $this->boundPlaceId;

        return $delivery;
    }

    /**
     * Tell this dispatcher where the current submission says the order is going.
     *
     * Called by the computation, which owns the binding; the dispatcher only
     * has to carry it into the reader so that everything asking the cart gets
     * the same destination the offer filter used.
     *
     * @param int|null $placeId The bound place, or null when none was submitted.
     *
     *
     * @since  1.10.0
     */
    public function bindDeliveryPlace(?int $placeId): void
    {
        $this->boundPlaceId = $placeId !== null && $placeId > 0 ? $placeId : null;
    }

    /**
     * The delivery address as a plugin would read it: pending submission
     * first, saved row behind it.
     *
     * Exposed because the CART needs the same reading when it resolves a
     * method's own destination field: a carrier overriding the destination must
     * be filtered, quoted and placed against what the shopper has just typed,
     * exactly as the postcode it prices by already is. Building a second reader
     * elsewhere would be a second answer to "what did they type".
     *
     * @param HydratedCart $cart The settled cart.
     *
     *
     * @since  1.10.0
     */
    public function deliveryAddress(HydratedCart $cart): AddressView
    {
        return $this->reader($cart)->delivery();
    }

    private function shippingCostRequest(
        object $method,
        HydratedCart $cart,
        float $merchandiseTotal,
    ): ShippingCostRequest {
        // THE CARRIER IS ASKED ABOUT WHAT IS BOUGHT. A line flagged
        // unavailable stays in the cart and on the page, but nobody pays for it
        // and nothing ships it: its units, weight and size are not the
        // parcel's, so the carrier reads the payable lines only.
        $payable = $cart->withLines($cart->payableLines());

        return new ShippingCostRequest(
            // Selected, because the carrier being quoted is by definition the
            // one this cart will ship with: chosen by the shopper, or assumed
            // while placement tests the method it was submitted with.
            method: Method::of($method, selected: true),
            cart: $this->reader($payable, Money::of($merchandiseTotal, $cart->currency())),
        );
    }

    private function contributionRequest(HydratedCart $cart, Money $subtotal): ContributionRequest
    {
        // THE PLUGINS CHARGE FOR WHAT IS BOUGHT. A line flagged unavailable is
        // shown but not paid for, and a per-line charge such as a
        // subscription sign-up fee does not depend on the line's price, so the
        // line list and the cart the plugins read hold the payable lines only.
        $payable = $cart->withLines($cart->payableLines());
        $lines = [];

        foreach ($payable->lines() as $line) {
            $rate = 0.0;

            if (isset($line->data->price) && \is_object($line->data->price)) {
                $rate = (float) $line->data->price->getTaxes()->getEffectiveRate();
            }

            $lines[] = new ContributionLine(
                lineId: (int) ($line->id ?? 0),
                itemId: (int) ($line->id_item ?? 0),
                taxRate: $rate,
            );
        }

        return new ContributionRequest(lines: $lines, cart: $this->reader($payable, $subtotal));
    }

    private function methodViewRequest(
        object $method,
        bool $selected,
        Money $total,
        HydratedCart $cart,
        ?Money $payable = null,
    ): MethodViewRequest {
        return new MethodViewRequest(
            method: Method::of($method, $selected),
            cart: $this->reader($cart, $total, $payable),
        );
    }
}
