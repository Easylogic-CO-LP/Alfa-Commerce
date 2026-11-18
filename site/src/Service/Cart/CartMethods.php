<?php

/**
 * What the cart may be paid and shipped with, asked once per request.
 *
 * WHAT THIS OWNS. The offered payment list, the offered shipment list, the
 * unnarrowed candidate lists behind them, and the notices explaining a method
 * that went away. All four are request-scoped memos: one question, one answer,
 * for as long as the cart object lives.
 *
 * WHAT IT DOES NOT OWN, DELIBERATELY. It decides no eligibility of its own.
 * AlfaHelper::getFilteredMethods() applies the catalogue, place, group and
 * line-scope rules AND the plugin-enabled filter; OperatorPaymentPolicy
 * decides what an operator may place with; MethodChoice decides what to do
 * about a selection that is no longer offered. This class asks them and
 * remembers the answer.
 *
 * AND IT DOES NOT WRITE. Choosing a method, persisting it and creating a cart
 * to hold it are the row writer's; replacing the hydrated state is the hydrator's.
 * That is why this object can be handed closures instead of a cart: everything
 * it needs is a value someone else derived.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Plugin\ShipmentsPlugin;
use Alfa\Component\Alfa\Administrator\Service\Cart\OperatorPaymentPolicy;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Closure;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use LogicException;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * @since  2.0.0
 */
final class CartMethods
{
    /**
     * The offered payment methods, or null before the first ask.
     *
     * @since  2.0.0
     */
    private ?array $payments = null;

    /**
     * The offered shipment methods, or null before the first ask.
     *
     * @since  2.0.0
     */
    private ?array $shipments = null;

    /**
     * Candidate lists per kind, before the place and shipping narrowing.
     *
     * @since  2.0.0
     */
    private array $candidates = [];

    /**
     * What to tell the shopper about a method that is no longer theirs.
     *
     * @since  2.0.0
     */
    private array $notices = ['shipment' => [], 'payment' => []];

    /**
     * The carriers the offer read took off the list, by id: each one's answer (2.2.2).
     *
     * @var array<int, ShippingQuote>
     *
     * @since  2.2.2
     */
    private array $withheld = [];

    /**
     * Whether the carriers are being asked right now: a question a carrier's answer puts back to this list gets the
     * list before the carriers were asked, never a second round of questions.
     *
     * @since  2.2.2
     */
    private bool $asking = false;

    /**
     * @param Closure $customer (): User - the customer the money follows. A method limited
     *                          to a customer group is offered by the groups that customer
     *                          is priced with (PriceContext::customerGroups()).
     * @param Closure $isOperatorPlacement (): bool
     * @param Closure $deliveryPlaces (?object $method = null): array - the destination and its
     *                                parents, empty when unknown. Called with each method so
     *                                one reading its destination from a field of its own is
     *                                offered against the chain it will be quoted against;
     *                                called with nothing for the shop's own destination.
     * @param Closure $isVirtual (): bool - nothing here needs shipping.
     * @param Closure $selectedShipmentId (): int - narrows which payments are compatible.
     * @param Closure $lineScopes (string $kind): array - per-line category/manufacturer scopes.
     * @param Closure $onForget (): void - told when the offered sets are dropped.
     * @param Closure|null $carrierAnswer (int $methodId): ShippingQuote - what a carrier answers for this cart as the
     *                                    checkout stands (2.2.2): asked of every carrier the storefront would offer, so
     *                                    none is offered that would refuse the shopper's first click. Null asks none.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Closure $customer,
        private Closure $isOperatorPlacement,
        private Closure $deliveryPlaces,
        private Closure $isVirtual,
        private Closure $selectedShipmentId,
        private Closure $lineScopes,
        private Closure $onForget,
        private ?Closure $carrierAnswer = null,
    ) {
    }

    /**
     * The payment methods this cart may actually be paid with.
     *
     * @return array Keyed by method id.
     * @since  2.0.0
     */
    public function payments(): array
    {
        if ($this->payments === null) {
            $user = ($this->customer)();

            $this->payments = AlfaHelper::getFilteredMethods(
                categories:    [],
                manufacturers: [],
                usergroups:    PriceContext::customerGroups(customer: $user),
                userId:        $user->id,
                baseTable:     'payment',
                placeIds:      $this->deliveryPlaces,
                shipmentId:    ($this->isVirtual)() ? 0 : (int) ($this->selectedShipmentId)(),
                lines:         ($this->lineScopes)('payment'),
            );

            // Operator placement asks a second question of every method, and it
            // is the policy's to answer, not this class's.
            if (($this->isOperatorPlacement)()) {
                $this->payments = OperatorPaymentPolicy::filter($this->payments);
            }
        }

        return $this->payments;
    }

    /**
     * The same question with the chosen carrier left out of it.
     *
     * Not memoised, and never has been: it exists to answer "would this payment
     * be available if the shipment were not in the way", which is a different
     * question each time the shipment changes.
     *
     * @return array Keyed by method id.
     * @since  2.0.0
     */
    public function paymentsIgnoringShipping(): array
    {
        $user = ($this->customer)();

        $methods = AlfaHelper::getFilteredMethods(
            categories:    [],
            manufacturers: [],
            usergroups:    PriceContext::customerGroups(customer: $user),
            userId:        $user->id,
            baseTable:     'payment',
            placeIds:      $this->deliveryPlaces,
            lines:         ($this->lineScopes)('payment'),
        );

        return ($this->isOperatorPlacement)()
            ? OperatorPaymentPolicy::filter($methods)
            : $methods;
    }

    /**
     * The shipment methods this cart may actually be shipped with.
     *
     * @return array Keyed by method id.
     * @since  2.0.0
     */
    public function shipments(): array
    {
        // Nothing to ship means nothing to offer, and the memo is set so the
        // question is not asked again.
        if (($this->isVirtual)()) {
            return $this->shipments = [];
        }

        if ($this->shipments === null) {
            $user = ($this->customer)();

            $scoped = AlfaHelper::getFilteredMethods(
                categories:    [],
                manufacturers: [],
                usergroups:    PriceContext::customerGroups(customer: $user),
                userId:        $user->id,
                baseTable:     'shipment',
                placeIds:      $this->deliveryPlaces,
                lines:         ($this->lineScopes)('shipment'),
            );

            // A question a carrier's answer puts back to this list while the carriers are being asked.
            if ($this->asking) {
                return $scoped;
            }

            $this->shipments = $this->servable($scoped);
        }

        return $this->shipments;
    }

    /**
     * The carriers of a scoped list that can carry the cart with what the checkout knows so far (2.2.2).
     *
     * A METHOD IS NEVER OFFERED AND THEN REFUSES THE FIRST CLICK. The storefront asks each carrier the question it is
     * asked once chosen, before offering it: one that answers a price is offered; one that refuses, or answers nothing,
     * is withheld with its answer. A carrier that refuses for a fact the checkout has not given yet (the delivery
     * country, the postcode) comes back once the shopper gives it, as the field save already re-renders the lists. An
     * operator placement keeps the scoped list: the operator sets the address in the back office, and the chosen
     * carrier's own quote still answers there.
     *
     * @param array $scoped The methods the catalogue, place, group and line scopes leave, keyed by id.
     *
     * @return array The methods the storefront offers, keyed by id.
     *
     * @since  2.2.2
     */
    private function servable(array $scoped): array
    {
        $this->withheld = [];

        if ($this->carrierAnswer === null || ($this->isOperatorPlacement)()) {
            return $scoped;
        }

        $this->asking = true;

        try {
            foreach ($scoped as $id => $method) {
                $answer = ($this->carrierAnswer)((int) $id);

                if (!$answer->available) {
                    $this->withheld[(int) $id] = $answer;
                    unset($scoped[$id]);
                }
            }
        } finally {
            $this->asking = false;
        }

        return $scoped;
    }

    /**
     * The answer of a carrier the offer read withheld, or null when it was not withheld by its own answer (2.2.2).
     *
     * @param int $id The method id.
     *
     * @since  2.2.2
     */
    public function withheld(int $id): ?ShippingQuote
    {
        $this->shipments();

        return $this->withheld[$id] ?? null;
    }

    /**
     * What the checkout has not given yet that a withheld carrier needs, in the order a shopper fills them (2.2.2):
     * 'country' and 'postcode'. The delivery country is also named when the place scopes alone keep carriers off a cart
     * with no destination, and the shop has a field the shopper can fill. Read by the lists only when nothing is left to
     * choose; empty when nothing is waiting for the shopper.
     *
     * @return list<string> The facts, 'country' before 'postcode'.
     *
     * @since  2.2.2
     */
    public function shipmentNeeds(): array
    {
        if (($this->isVirtual)()) {
            return [];
        }

        $this->shipments();
        $needs = [];
        $state = FieldsHelper::deliveryPlaceFieldState();
        $countryAskable = $state === FieldsHelper::DESTINATION_CONFIGURED || $state === FieldsHelper::DESTINATION_INFERRED;

        foreach ($this->withheld as $answer) {
            if ($answer->reasonKey === ShipmentsPlugin::REFUSAL_NEEDS_PLACE && $countryAskable) {
                $needs['country'] = true;
            } elseif ($answer->reasonKey === ShipmentsPlugin::REFUSAL_NEEDS_POSTCODE) {
                $needs['postcode'] = true;
            }
        }

        if ($countryAskable && ($this->deliveryPlaces)() === [] && $this->restrictsByPlace('shipment')) {
            $needs['country'] = true;
        }

        return array_values(array_filter(['country', 'postcode'], static fn (string $fact): bool => isset($needs[$fact])));
    }

    /**
     * Whether a withheld carrier waits for a fact the checkout has not given yet (the country, the postcode), rather
     * than refusing what it was given (2.2.2).
     *
     * @param ShippingQuote $answer The carrier's answer.
     *
     * @since  2.2.2
     */
    public static function waitsForFact(ShippingQuote $answer): bool
    {
        return \in_array($answer->reasonKey, [ShipmentsPlugin::REFUSAL_NEEDS_PLACE, ShipmentsPlugin::REFUSAL_NEEDS_POSTCODE], true);
    }

    /**
     * @return bool Whether anything could pay for this cart at all.
     * @since  2.0.0
     */
    public function hasPaymentCandidates(): bool
    {
        return $this->candidates('payment') !== [];
    }

    /**
     * @return bool Whether anything could ship it.
     * @since  2.0.0
     */
    public function hasShipmentCandidates(): bool
    {
        return !($this->isVirtual)() && $this->candidates('shipment') !== [];
    }

    /**
     * Whether this cart must name a carrier, and why.
     *
     * APPLICABILITY IS A PROPERTY OF THE GOODS, not of the method list. A cart
     * of downloads is not shipped however many carriers the shop publishes, and
     * a cart of boxes is shipped however few it publishes. Deriving the first
     * question from the second is what let a physical cart place with no
     * carrier the moment its last eligible one went away.
     *
     * So virtuality decides applicability, and the OFFERED list (the same one
     * the checkout showed, narrowed by destination and line scope) decides
     * whether anything can serve it. No second engine is asked.
     *
     * @since  2.0.0
     */
    public function shipmentRequirement(): MethodRequirement
    {
        if (($this->isVirtual)()) {
            return MethodRequirement::NotApplicable;
        }

        return $this->shipments() === []
            ? MethodRequirement::Unavailable
            : MethodRequirement::Required;
    }

    /**
     * Whether this cart must name a payment method, and why.
     *
     * PAYMENT HAS NO CART-SIDE EXEMPTION. Nothing about the goods, the total or
     * the customer's credit makes it optional: a zero-total order still names a
     * method, and credit is spent after the order is committed. The one shop
     * that requires none is the shop that offers none, which is the
     * unnarrowed candidate question, asked without the destination, the carrier
     * or the operator policy, and stated as such in the back office ("No
     * payment method is set up").
     *
     * Everything narrower than that (no gateway for the chosen carrier, none
     * an operator may use) is UNAVAILABLE, and unavailable is a refusal.
     *
     * @since  2.0.0
     */
    public function paymentRequirement(): MethodRequirement
    {
        if ($this->candidates('payment') === []) {
            return MethodRequirement::NotApplicable;
        }

        return $this->payments() === []
            ? MethodRequirement::Unavailable
            : MethodRequirement::Required;
    }

    /**
     * What to tell the shopper about a cart with no known destination.
     *
     * WHO CAN FIX IT DECIDES WHO IS TOLD. A shopper who has simply not reached
     * the address step can answer the question, so they are asked: once,
     * beside the methods, and only when the shop actually restricts by place;
     * a single-country shop that scopes nothing has nothing to explain. On an
     * operator placement the operator sets the address, so the operator is
     * asked, in the admin catalogue's words; the storefront's stay the shopper's.
     *
     * A destination that cannot be resolved AT ALL (no place field, a mapping
     * to a field that has been deleted, or several candidates and no mapping)
     * is not the shopper's to fix, and telling them to enter an address they
     * have already entered would be a lie. That goes to the merchant, in the
     * log and in the administrator's own message queue.
     *
     * ASKED, NOT PUSHED. It is returned rather than added to the notice bucket
     * because the bucket belongs to RECONCILIATION ("the carrier you chose is
     * gone") and a prompt to enter an address is not that. Adding it inside
     * shipments() put it in front of every caller that merely listed methods.
     *
     * @param string $kind 'shipment' or 'payment'.
     *
     * @return string The shopper-facing message, '' when there is nothing to say.
     *
     * @since  2.0.0
     */
    public function destinationNotice(string $kind): string
    {
        if (($this->deliveryPlaces)() !== [] || !$this->restrictsByPlace($kind)) {
            return '';
        }

        $state = FieldsHelper::deliveryPlaceFieldState();

        if ($state === FieldsHelper::DESTINATION_CONFIGURED || $state === FieldsHelper::DESTINATION_INFERRED) {
            // The storefront's list says what it needs itself, and only when nothing is left to choose (2.2.2):
            // shipmentNeeds(). The operator is told here; a distinct admin key, since the builder loads the site
            // catalogue after the admin one and a shared key would come back in the shopper's words.
            return ($this->isOperatorPlacement)() ? Text::_('COM_ALFA_CART_CHECKOUT_DESTINATION_NEEDED') : '';
        }

        // Not the shopper's to fix. Telling them to enter an address they may
        // already have entered would be a lie, so this goes where the person
        // who CAN fix it will read it, with the reason in words.
        $message = Text::sprintf('COM_ALFA_CART_DESTINATION_NOT_RESOLVABLE', Text::_(self::destinationStateKey(state: $state)));
        Log::add($message, Log::WARNING, 'com_alfa.cart');

        $app = Factory::getApplication();

        if ($app->isClient('administrator')) {
            $app->enqueueMessage($message, 'warning');
        }

        return '';
    }

    /**
     * The language key that says why the delivery destination cannot be resolved.
     *
     * Only the three states no shopper can fix reach here: a resolvable destination
     * is answered before this is asked.
     *
     * @param string $state FieldsHelper::DESTINATION_MISSING, DESTINATION_AMBIGUOUS or DESTINATION_UNMAPPED.
     *
     * @return string The language key.
     *
     * @throws LogicException For any other state.
     *
     * @since  2.1.1
     */
    private static function destinationStateKey(string $state): string
    {
        return match ($state) {
            FieldsHelper::DESTINATION_MISSING => 'COM_ALFA_CART_DESTINATION_STATE_MISSING',
            FieldsHelper::DESTINATION_AMBIGUOUS => 'COM_ALFA_CART_DESTINATION_STATE_AMBIGUOUS',
            FieldsHelper::DESTINATION_UNMAPPED => 'COM_ALFA_CART_DESTINATION_STATE_UNMAPPED',
            default => throw new LogicException('The delivery destination state "' . $state . '" is not one that cannot be resolved.'),
        };
    }

    /**
     * Whether this shop restricts any method of a kind to particular places.
     *
     * Read off the candidates the cart already has, so it costs no query. A
     * shop that scopes nothing geographically is told nothing about geography.
     *
     * @param string $kind 'shipment' or 'payment'.
     *
     *
     * @since  2.0.0
     */
    private function restrictsByPlace(string $kind): bool
    {
        foreach ($this->candidates($kind) as $method) {
            foreach (explode(',', (string) ($method->places ?? '')) as $placeId) {
                if ((int) $placeId > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every published method of one kind, before any narrowing: the plugins
     * the cart page must ask for assets.
     *
     * CONTEXT-FREE ON PURPOSE. Audience, destination, lines and carrier all
     * change over AJAX; a script cannot follow them onto a page that is
     * already built. This list changes only when the merchant publishes a
     * method, so what it names is on the page from the first render.
     *
     * @param string $kind 'payment' or 'shipment'.
     *
     * @return array Keyed by method id.
     * @since  2.0.0
     */
    public function published(string $kind): array
    {
        return AlfaHelper::getPublishedMethods(baseTable: $kind);
    }

    /**
     * Every method of a kind the cart's lines allow, before place or shipping
     * narrowing: the list a name can still be read from once a method has
     * stopped being offered.
     *
     * @param string $kind 'payment' or 'shipment'.
     *
     * @return array Keyed by method id.
     * @since  2.0.0
     */
    public function candidates(string $kind): array
    {
        if (!\array_key_exists($kind, $this->candidates)) {
            $user = ($this->customer)();

            $this->candidates[$kind] = AlfaHelper::getFilteredMethods(
                categories:    [],
                manufacturers: [],
                usergroups:    PriceContext::customerGroups(customer: $user),
                userId:        $user->id,
                baseTable:     $kind,
                lines:         ($this->lineScopes)($kind),
            );
        }

        return $this->candidates[$kind];
    }

    /**
     * A method's name for a message, or its id when even the candidates have
     * forgotten it.
     *
     * @param string $kind 'payment' or 'shipment'.
     * @param int $id The method id.
     *
     * @since  2.0.0
     */
    public function name(string $kind, int $id): string
    {
        return (string) ($this->candidates($kind)[$id]->name ?? ('#' . $id));
    }

    /**
     * What the shopper should be told about one kind.
     *
     * @param string $kind 'payment' or 'shipment'.
     *
     * @return string[]
     * @since  2.0.0
     */
    public function notices(string $kind): array
    {
        return $this->notices[$kind] ?? [];
    }

    /**
     * Both buckets, in the order they have always been returned.
     *
     * @since  2.0.0
     */
    public function allNotices(): array
    {
        return $this->notices;
    }

    /**
     * Start a reconciliation with nothing to say.
     *
     * Called at the top of every reconcile, which is what stops notices
     * accumulating across two passes in one request.
     *
     * @since  2.0.0
     */
    public function clearNotices(): void
    {
        $this->notices = ['shipment' => [], 'payment' => []];
    }

    /**
     * Record one thing to tell the shopper.
     *
     * The decision belongs to the caller: reconciliation reads the choice, the
     * selection and the carrier, and none of those is this object's to make.
     *
     * @param string $kind 'payment' or 'shipment'.
     * @param string $message The already-translated sentence.
     *
     * @since  2.0.0
     */
    public function addNotice(string $kind, string $message): void
    {
        $this->notices[$kind][] = $message;
    }

    /**
     * Drop the offered sets, so the next ask reads them again.
     *
     * The candidate lists are NOT dropped: they are the unnarrowed answer to a
     * question the place and the carrier do not change, and a name still has to
     * be readable for a method that has just stopped being offered.
     *
     * @since  2.0.0
     */
    public function forget(): void
    {
        $this->payments = null;
        $this->shipments = null;
        $this->withheld = [];

        ($this->onForget)();
    }
}
