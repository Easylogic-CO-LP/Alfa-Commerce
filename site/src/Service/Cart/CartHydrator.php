<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Cart;

use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartIdentity;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\ChargeableQuantity;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\PricingIntent;
use Closure;
use Exception;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use LogicException;
use stdClass;
use Throwable;

defined('_JEXEC') or die;

/**
 * The cart, read and kept current.
 *
 * One reading is held per opened cart. It is taken from the row (found by
 * name, with the named cart's ownership checked, or by owner) and its lines,
 * each hydrated from the catalogue, configured as stored and priced under the
 * subject's terms; a line whose product has left the catalogue survives as an
 * unavailable line with no price, and a line whose unit is not sellable is
 * hydrated and priced but marked unavailable as out of stock. A write
 * elsewhere replaces the reading wholesale, or asks for the lines again.
 *
 * A priced line whose quantity is too small to be charged (its unit is above
 * zero and its own money settles at zero) is marked unavailable too, with the
 * sentence that names the smallest quantity that is charged; and a write asks
 * here what the line it is about to store would cost, so that it can refuse
 * such a quantity before anything is written.
 *
 * Identity discovery is not done here. It arrives resolved; what happens here
 * is that the row read points it at the row it found, or at nothing.
 *
 * @since  2.0.0
 */
final class CartHydrator
{
    private ?HydratedCart $current = null;

    /** @since 2.0.0 */
    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartItemLoader $items,
        private readonly CartLineConfiguration $configuration,
        private readonly CartLinePresenter $presenter,
        private readonly CartMessaging $messaging,
        private readonly MVCFactoryInterface $mvc,
    ) {
    }

    /**
     * Know which cart this is before anything is read: a reading of nothing,
     * carrying the identity, so a write that runs before the first load has a
     * cart id to write against and no row to mirror onto.
     *
     * @since  2.0.0
     */
    public function begin(CartIdentity $identity, CartSubject $subject): void
    {
        $this->current = HydratedCart::none($identity, $subject);
    }

    /** @since 2.0.0 */
    public function current(): HydratedCart
    {
        if ($this->current === null) {
            throw new LogicException('The cart has not been opened.');
        }

        return $this->current;
    }

    /** @since 2.0.0 */
    public function replace(HydratedCart $state): void
    {
        $this->current = $state;
    }

    /**
     * Read the cart again, as it is now, for the identity it has now.
     *
     * @since  2.0.0
     */
    public function reload(): void
    {
        $current = $this->current();
        $this->current = $this->load($current->identity(), $current->subject());
    }

    /**
     * Read the lines again; the row and the addresses stand.
     *
     * @since  2.0.0
     */
    public function reloadLines(): void
    {
        $current = $this->current();
        $this->current = $current->withLines($this->lines($current->identity()->cartId, $current->subject()));
    }

    /**
     * One reading.
     *
     * A visitor nobody can identify, naming no cart, has none; and neither has
     * anyone naming no cart under a mode that does not discover. Otherwise the
     * row is read and the identity pointed at it; the money context is taken
     * from the row before any line is priced; then the lines. All of that is
     * inside one net: a failure leaves an empty cart and one sentence for the
     * shopper. The addresses are read afterwards, outside it.
     */
    private function load(CartIdentity $identity, CartSubject $subject): HydratedCart
    {
        if (!$subject->owner((string) $identity->recognizeKey)->isIdentified() && $identity->cartId <= 0) {
            return HydratedCart::none($identity, $subject);
        }

        // A MODE THAT DOES NOT DISCOVER MUST NOT DISCOVER HERE EITHER. Naming no
        // cart under such a mode means "no cart yet", not "find me one": the
        // owner lookup below would otherwise hand a FRESH creation the very cart
        // it was asked to be new instead of: the customer's existing one, or,
        // for a walk-in whose owner is the operator's own browser key, the
        // operator's storefront cart. The enum has always said Named neither
        // discovers nor adopts; this is where that sentence becomes true.
        if ($identity->cartId <= 0 && !$identity->mode->discovers()) {
            return HydratedCart::none($identity, $subject);
        }

        try {
            $row = $identity->cartId > 0
                ? $this->named($identity->cartId, $subject)
                : $this->repository->loadCartFor($subject->owner((string) $identity->recognizeKey));

            if ($row) {
                $identity = $identity->at((int) $row->id);
                $subject = $this->adoptMoneyContext($subject, $row);
            } else {
                $row = null;
                $identity = $identity->at(0);
            }

            $lines = $this->lines($identity->cartId, $subject);
        } catch (Throwable $e) {
            $this->messaging->failed('getCart', $identity->cartId, $e, 'COM_ALFA_CART_LOAD_FAILED');

            return HydratedCart::none($identity, $subject);
        }

        return HydratedCart::of(
            $identity,
            $subject,
            $row,
            $lines,
            isset($row->id_user_info_delivery) ? $this->address($identity->cartId, (int) $row->id_user_info_delivery) : null,
            isset($row->id_user_info_invoice) ? $this->address($identity->cartId, (int) $row->id_user_info_invoice) : null,
        );
    }

    /**
     * A named cart, if it is this customer's. An ambient subject asserts
     * nothing; a named customer must match the row.
     */
    private function named(int $cartId, CartSubject $subject): ?object
    {
        $cart = $this->repository->loadCart($cartId);

        if (!$cart || $subject->isAmbient()) {
            return $cart ?: null;
        }

        $rowCustomer = (int) ($cart->id_customer ?? 0);
        $customer = $subject->customerId();

        if ($rowCustomer === $customer) {
            return $cart;
        }

        Log::add(
            'Cart #' . $cartId . ' belongs to customer ' . $rowCustomer
                . ' and was named for customer ' . $customer . '; refused.',
            Log::WARNING,
            'com_alfa.orders',
        );

        return null;
    }

    /**
     * An operator placing on a customer's behalf prices in the cart's own
     * currency unless the subject named one.
     */
    private function adoptMoneyContext(CartSubject $subject, object $row): CartSubject
    {
        if (!$subject->isOperatorPlacement()) {
            return $subject;
        }

        $named = $subject->moneyContext();

        if ($named['currency'] !== null && $named['currency'] > 0) {
            return $subject;
        }

        $currency = (int) ($row->id_currency ?? 0);

        return $currency > 0 ? $subject->withMoneyContext($currency, $named['place']) : $subject;
    }

    /**
     * The lines, hydrated and priced. Its own net: a failure to read them
     * yields no lines rather than a broken cart.
     *
     * Products are read through the catalogue's listing model. One the
     * hide-out-of-stock rule keeps off the listing is read once more with only
     * that rule lifted (state, publish window and access still apply), so its
     * line is hydrated and priced like any other; a product neither read returns
     * has left the catalogue. Every hydrated line whose unit is not sellable (as
     * resolved from its configuration, or as the unit the line stores) is marked
     * unavailable as out of stock, keeping its price and summary, and every
     * available line carries quantity_limit, sale_minimum and sale_increment:
     * the canonical maximum, smallest sale and increment the cart steppers
     * render.
     *
     * @param int $cartId The cart.
     * @param CartSubject $subject Whose terms the lines are priced under.
     *
     * @return object[] The hydrated lines.
     *
     * @since  2.0.0
     */
    private function lines(int $cartId, CartSubject $subject): array
    {
        if ($cartId <= 0) {
            return [];
        }

        try {
            $lines = $this->repository->loadLines($cartId);
        } catch (Throwable $e) {
            $this->messaging->failed('getCartItems', $cartId, $e, 'COM_ALFA_CART_LOAD_FAILED');

            return [];
        }

        if (!$lines) {
            return [];
        }

        $itemIds = array_values(array_unique(array_map(static fn ($l) => (int) $l->id_item, $lines)));
        $quantities = [];

        foreach ($lines as $line) {
            $id = (int) $line->id_item;
            $quantities[$id] = max($quantities[$id] ?? 0, max(1, (int) $line->quantity));
        }

        $byId = $this->catalogue(itemIds: $itemIds, quantities: $quantities, stockVisibility: true);
        $hidden = array_values(array_diff($itemIds, array_keys($byId)));

        // A product the hide-out-of-stock rule keeps off the listing is still the
        // product in this cart: read once more with only that rule lifted, its line
        // shows as out of stock with its price rather than as withdrawn.
        if ($hidden !== []) {
            $byId += $this->catalogue(itemIds: $hidden, quantities: $quantities, stockVisibility: false);
        }

        $context = $this->terms(subject: $subject);
        $calculator = new PriceCalculator();
        $cartItems = [];

        foreach ($lines as $line) {
            $base = $byId[(int) $line->id_item] ?? null;

            if (!$base) {
                // THE PRODUCT HAS LEFT THE CATALOGUE. The line survives, named
                // as what it was, unpriced and flagged: the shopper can see it
                // and remove it, and nothing else may be done with it.
                $stranded = $this->items->load((int) $line->id_item);

                if (!$stranded) {
                    continue;
                }

                $stranded->name = $this->items->name((int) $line->id_item);

                $cartItem = new stdClass();
                $cartItem->id = (int) $line->id;
                $cartItem->id_cart = $cartId;
                $cartItem->id_item = (int) $line->id_item;
                $cartItem->combination_id = $line->id_combination !== null ? (int) $line->id_combination : null;
                $cartItem->quantity = (int) $line->quantity;
                $cartItem->data = $stranded;
                $cartItem->fields = [];
                $cartItem->surfaces = [];
                $cartItem->unavailable = true;
                $cartItem->unavailable_reason = Text::_('COM_ALFA_CART_LINE_UNAVAILABLE');

                $cartItems[] = $cartItem;

                continue;
            }

            $item = clone $base;
            [$selection, $optionValueIds, $payloads, $summary] = $this->configuration->of($item, (int) $line->id);

            $this->price(
                item: $item,
                selection: $selection,
                optionValueIds: $optionValueIds,
                payloads: $payloads,
                quantity: max(1, (int) $line->quantity),
                // PRODUCER 1 OF 2. Null for every storefront line, because only
                // the admin builder can ever write that column, and this is
                // the cart the shopper, the totals, the cart rules and the
                // placement all read, so it is where a negotiated price becomes
                // the money everything downstream agrees on.
                instruction: ExplicitPriceInstruction::fromStoredColumn($line->custom_unit_price_tax_incl ?? null),
                context: $context,
                calculator: $calculator,
            );

            $cartItem = new stdClass();
            $cartItem->id = (int) $line->id;
            $cartItem->id_cart = $cartId;
            $cartItem->id_item = (int) $line->id_item;
            $cartItem->combination_id = $line->id_combination !== null ? (int) $line->id_combination : null;
            $cartItem->quantity = (int) $line->quantity;
            $cartItem->data = $item;       // configured + cart-priced
            $cartItem->fields = $summary;  // chosen fields, for the line summary
            $this->presenter->decorate($cartItem, $item, $payloads);

            // A priced line whose quantity is too small to be charged cannot be
            // bought as it stands: it stays in the cart, named and priced, and
            // says which quantity can be.
            $this->markUncharged(
                cartItem: $cartItem,
                product: $base,
                configuration: ['selection' => $selection, 'optionValueIds' => $optionValueIds, 'payloads' => $payloads],
                subject: $subject,
                cartId: $cartId,
            );

            // The unit's stock verdict, as the card and the product page state it:
            // a line that cannot be bought now stays visible, priced and described,
            // and is marked out of stock so checkout refuses it by name. Both the
            // configuration the line resolves to and the line's own stored unit are
            // judged, since a quantity change and checkout act on the stored unit:
            // a line kept from before the product gained combinations, or naming a
            // combination since deleted or archived, is not sellable even when its
            // stored configuration now resolves to a sellable combination.
            $sellable = ($item->cartState->salable ?? false)
                && VariantHelper::unitSellable(item: $item, combinationId: $cartItem->combination_id);

            if (!$sellable) {
                $cartItem->unavailable = true;
                $cartItem->unavailable_reason = Text::_('COM_ALFA_CART_OUT_OF_STOCK');
            }

            $cartItems[] = $cartItem;
        }

        $this->applyQuantityLimits(cartItems: $cartItems);

        return $cartItems;
    }

    /**
     * Whether the catalogue withholds a product only through the
     * hide-out-of-stock rule: the read that lifts that rule alone (state,
     * publish window and access still apply) returns it. It is the read the
     * cart's lines take, so a write refused for such a product is worded as the
     * cart page labels its line: out of stock, not withdrawn.
     *
     * @param int $itemId The product.
     *
     *
     * @since  2.0.0
     */
    public function hiddenForStock(int $itemId): bool
    {
        return $itemId > 0
            && $this->catalogue(itemIds: [$itemId], quantities: [$itemId => 1], stockVisibility: false) !== [];
    }

    /**
     * Read products through the catalogue's listing model, priced for the cart.
     *
     * The hide-out-of-stock rule is lifted through the model state
     * 'stock_visibility.lifted' = true: outside the filter and list namespaces
     * the request populates, and a boolean no request value can equal.
     *
     * @param int[] $itemIds The products.
     * @param array $quantities Item id => the largest line quantity (the cart price tier).
     * @param bool $stockVisibility Whether the hide-out-of-stock rule applies; false only
     *                              for the second read of products that rule hid.
     *
     * @return array<int, object> Item id => the listed product.
     *
     * @since  2.0.0
     */
    private function catalogue(array $itemIds, array $quantities, bool $stockVisibility): array
    {
        $itemsModel = $this->mvc->createModel(name: 'Items', prefix: 'Site', config: ['ignore_request' => true]);
        $itemsModel->setState(property: 'plugin.layouts', value: false);
        $itemsModel->setState(property: 'filter.id', value: $itemIds);
        $itemsModel->setState(property: 'list.limit', value: 0);
        $itemsModel->setState(property: 'list.start', value: 0);
        $itemsModel->setPricingIntent(intent: PricingIntent::cart(quantities: $quantities));

        if (!$stockVisibility) {
            $itemsModel->setState(property: 'stock_visibility.lifted', value: true);
        }

        $byId = [];

        foreach ($itemsModel->getItems() ?: [] as $loaded) {
            $byId[(int) $loaded->id] = $loaded;
        }

        return $byId;
    }

    /**
     * Give every available line the quantities its steppers offer: the largest
     * quantity it may hold (the canonical per-line maximum over its unit's
     * ceiling, read from the facts the line was hydrated with, no query, less
     * what the cart's other lines on the same unit hold), and the smallest sale
     * and whole-unit increment of its unit's quantity rules. Unavailable lines
     * get none of the three.
     *
     * @param object[] $cartItems The hydrated lines; quantity_limit, sale_minimum
     *                            and sale_increment are set on each.
     *
     *
     * @since  2.0.0
     */
    private function applyQuantityLimits(array $cartItems): void
    {
        $unitKey = static fn (object $line): string => $line->id_item . '|' . (int) ($line->combination_id ?? 0);
        $heldByUnit = [];

        foreach ($cartItems as $cartItem) {
            $key = $unitKey($cartItem);
            $heldByUnit[$key] = ($heldByUnit[$key] ?? 0.0) + (float) $cartItem->quantity;
        }

        $policy = new CartQuantityPolicy();

        foreach ($cartItems as $cartItem) {
            if (!empty($cartItem->unavailable)) {
                $cartItem->quantity_limit = null;
                $cartItem->sale_minimum = null;
                $cartItem->sale_increment = null;

                continue;
            }

            $cartItem->quantity_limit = $policy->lineMaximum(
                item:               $cartItem->data,
                combinationId:      $cartItem->combination_id,
                purchasableCeiling: VariantHelper::ceilingFor(item: $cartItem->data),
                pooledDraw:         $heldByUnit[$unitKey($cartItem)] - (float) $cartItem->quantity,
            );

            // The rules' own figures, in whole units, so the steppers never
            // render a raw rule value.
            $bounds = CartQuantityPolicy::bounds(item: $cartItem->data, combinationId: $cartItem->combination_id);

            $cartItem->sale_minimum = $bounds['minimum'];
            $cartItem->sale_increment = $bounds['increment'];
        }
    }

    /**
     * What a line of this cart would cost: a product as the line would hold it,
     * at a quantity, priced on the terms this cart's lines are priced under.
     *
     * A write asks this before it stores a line or changes one, so what it
     * judges is what the next reading shows. The product handed in is left as
     * it is: a copy is configured and priced.
     *
     * @param object $item The product, with its fields, options and combinations.
     * @param array $selection Field id => chosen value id, for the variant dimensions.
     * @param array $optionValueIds The chosen option value ids.
     * @param array $payloads Field id => the buyer's payload.
     * @param int $quantity The quantity the line would hold.
     * @param ExplicitPriceInstruction|null $instruction The negotiated unit price the line would carry, or null.
     *
     *
     * @since  2.0.0
     */
    public function priceAt(object $item, array $selection, array $optionValueIds, array $payloads, int $quantity, ?ExplicitPriceInstruction $instruction): PriceResult
    {
        $candidate = clone $item;

        $this->price(
            item: $candidate,
            selection: $selection,
            optionValueIds: $optionValueIds,
            payloads: $payloads,
            quantity: max(1, $quantity),
            instruction: $instruction,
            context: $this->terms(subject: $this->current()->subject()),
            calculator: new PriceCalculator(),
        );

        return $candidate->price;
    }

    /**
     * The terms a subject's lines are priced under: whose prices, in which
     * currency, delivered where. The one place a cart's lines ask their
     * subject for them, for the reading and for a write that asks what a line
     * would cost.
     *
     * @param CartSubject $subject Whose cart it is.
     *
     *
     * @since  2.0.0
     */
    private function terms(CartSubject $subject): PriceContext
    {
        return $subject->priceContext();
    }

    /**
     * Configure a product as a line holds it and price it: the one place a
     * line of this cart is priced, for the reading and for a write that asks
     * what a line would cost. The product is given its variant, its stock
     * verdict, the active combination's data and its price.
     *
     * @param object $item The product; it is configured and priced in place.
     * @param array $selection Field id => chosen value id, for the variant dimensions.
     * @param array $optionValueIds The chosen option value ids.
     * @param array $payloads Field id => the buyer's payload.
     * @param int $quantity The line's quantity.
     * @param ExplicitPriceInstruction|null $instruction The negotiated unit price of the line, or null.
     * @param PriceContext|null $context Whose terms the line is priced under.
     * @param PriceCalculator $calculator The pricing service.
     *
     *
     * @since  2.0.0
     */
    private function price(object $item, array $selection, array $optionValueIds, array $payloads, int $quantity, ?ExplicitPriceInstruction $instruction, ?PriceContext $context, PriceCalculator $calculator): void
    {
        VariantHelper::resolveAndPrice(
            $item,
            $selection,
            $context,
            $calculator,
            $quantity,
            array_values($optionValueIds),
            $payloads,
            $instruction,
        );
    }

    /**
     * Mark a priced line whose quantity is too small to be charged.
     *
     * Its unit is above zero and its own money settles at zero
     * ({@see ChargeableQuantity}): a price lowered since the line was added, a
     * line an adoption moved in, a line the shelf cut. It is flagged as a line
     * that cannot be bought as it stands, with the sentence a refused quantity
     * says, and it states chargeable_from: the smallest quantity at or above
     * the one it holds that this cart may hold and that is charged at the
     * price of its own quantity tier, null when there is none. Nothing is
     * removed and no quantity is changed.
     *
     * @param object $cartItem The hydrated line; its product, configured and priced, is ->data.
     * @param object $product The product as the catalogue loaded it, before the line configured it.
     * @param array $configuration The line's configuration: selection, optionValueIds, payloads.
     * @param CartSubject $subject Whose terms the line is priced under.
     * @param int $cartId The cart.
     *
     *
     * @since  2.0.0
     */
    private function markUncharged(object $cartItem, object $product, array $configuration, CartSubject $subject, int $cartId): void
    {
        $price = $cartItem->data->price ?? null;

        if (!$price instanceof PriceResult) {
            return;
        }

        // The unit a line is judged by, for the quantity it holds and for every
        // tier the search prices: what the customer pays a piece before
        // discounts, with tax, exactly.
        $unitOf = static fn (PriceResult $priced): string => $priced->getPayableUnitExact();

        if (!ChargeableQuantity::ofUnit(unit: $unitOf($price), currency: $price->getCurrency())->refuses(quantity: $price->getQuantity())) {
            return;
        }

        $cartItem->chargeable_from = $this->chargeableFrom(
            subject: $subject,
            cartId: $cartId,
            item: $cartItem->data,
            product: $product,
            combinationId: $cartItem->combination_id,
            configuration: $configuration,
            instruction: $price->getExplicitPrice(),
            from: $price->getQuantity(),
            unitOf: $unitOf,
            currency: $price->getCurrency(),
            excludeLineId: $cartItem->id,
        );
        $cartItem->unavailable = true;
        $cartItem->unavailable_reason = ChargeableQuantity::sentence(smallest: $cartItem->chargeable_from);
    }

    /**
     * The quantity a refusal names for a line of this cart: the smallest at or
     * above the one asked that this cart may hold and that is charged at the
     * price of its own quantity tier; null when there is none.
     *
     * A write asks this once it has found the quantity it would store not
     * charged, with the accessor it judged that quantity by, so the tiers are
     * judged the same way.
     *
     * @param object $item The product, with its fields, options, combinations and quantity rules.
     * @param int|null $combinationId The unit the line would hold.
     * @param array $configuration The line's configuration: selection, optionValueIds, payloads.
     * @param ExplicitPriceInstruction|null $instruction The negotiated unit price the line would carry, or null.
     * @param int $from The quantity asked for.
     * @param Closure(PriceResult):string $unitOf The unit a priced line is judged by.
     * @param Currency $currency The currency the line is charged in.
     * @param int $excludeLineId The line being changed; 0 for a new one.
     *
     *
     * @since  2.0.0
     */
    public function smallestCharged(object $item, ?int $combinationId, array $configuration, ?ExplicitPriceInstruction $instruction, int $from, Closure $unitOf, Currency $currency, int $excludeLineId = 0): ?int
    {
        $current = $this->current();

        return $this->chargeableFrom(
            subject: $current->subject(),
            cartId: (int) $current->identity()->cartId,
            item: $item,
            product: $item,
            combinationId: $combinationId,
            configuration: $configuration,
            instruction: $instruction,
            from: $from,
            unitOf: $unitOf,
            currency: $currency,
            excludeLineId: $excludeLineId,
        );
    }

    /**
     * The tier search, for the reading and for a write: the line is priced at
     * a quantity through the one place a line of this cart is priced, and the
     * clamp walks the product's quantity tiers with the cart's quantity rule.
     * Asked on a refusal only.
     *
     * @param CartSubject $subject Whose terms the line is priced under.
     * @param int $cartId The cart.
     * @param object $item The product the quantity rules are read from.
     * @param object $product The product as the line is priced from; it is left as it is.
     * @param int|null $combinationId The unit the line holds.
     * @param array $configuration The line's configuration: selection, optionValueIds, payloads.
     * @param ExplicitPriceInstruction|null $instruction The negotiated unit price of the line, or null.
     * @param int $from The quantity asked for.
     * @param Closure(PriceResult):string $unitOf The unit a priced line is judged by.
     * @param Currency $currency The currency the line is charged in.
     * @param int $excludeLineId A line not to count as already held.
     *
     *
     * @since  2.0.0
     */
    private function chargeableFrom(CartSubject $subject, int $cartId, object $item, object $product, ?int $combinationId, array $configuration, ?ExplicitPriceInstruction $instruction, int $from, Closure $unitOf, Currency $currency, int $excludeLineId): ?int
    {
        $context = $this->terms(subject: $subject);
        $calculator = new PriceCalculator();

        $unitAt = function (int $quantity) use ($product, $configuration, $instruction, $context, $calculator, $unitOf): string {
            $candidate = clone $product;

            $this->price(
                item: $candidate,
                selection: $configuration['selection'],
                optionValueIds: $configuration['optionValueIds'],
                payloads: $configuration['payloads'],
                quantity: $quantity,
                instruction: $instruction,
                context: $context,
                calculator: $calculator,
            );

            return $unitOf($candidate->price);
        };

        return (new LineQuantityClamp($this->repository, $this->messaging))->smallestCharged(
            cartId: $cartId,
            item: $item,
            combinationId: $combinationId,
            from: $from,
            tierStarts: $calculator->tierStarts(itemId: (int) $product->id, combinationId: $combinationId, context: $context),
            unitAt: $unitAt,
            currency: $currency,
            excludeLineId: $excludeLineId,
        );
    }

    /**
     * An address row the cart points at. Its own net, narrower than the
     * others: an Exception here is a load failure, an Error is not caught.
     */
    private function address(int $cartId, int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        try {
            return UserInfoHelper::getRowById($id);
        } catch (Exception $e) {
            $this->messaging->failed('getUserInfo', $cartId, $e, 'COM_ALFA_CART_LOAD_FAILED');

            return null;
        }
    }
}
