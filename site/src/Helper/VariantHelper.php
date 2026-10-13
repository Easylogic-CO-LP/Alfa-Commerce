<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\CalculateItemPriceEvent;
use Alfa\Component\Alfa\Administrator\Helper\ItemConfigurationHelper;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContribution;
use Alfa\Component\Alfa\Site\Service\Pricing\VariantSelection;
use Alfa\Component\Alfa\Site\Service\Variant\CombinationResolver;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Registry\Registry;
use Throwable;

/**
 * VariantHelper: the ONE place that turns a loaded item + a buyer's selection
 * into a priced, data-overwritten variant. Used by the product page
 * (ItemModel::getItem) and every catalog card (ItemsModel) so both behave
 * identically: resolve the chosen combination (default when none), price it,
 * then overwrite the item's own data with the combination's where set.
 *
 * The item passed in MUST already carry ->fields and ->combinations (from the
 * shared ItemFieldsHelper cache, loaded once per request).
 *
 * @since  2.0.0
 */
final class VariantHelper
{
    /**
     * Combination scalar columns that overwrite the item's ('' / NULL = inherit).
     *
     * The stock figures are not among them: the item's public stock and
     * available quantity are the active unit's ledger figures, set by resolve().
     *
     * @var string[]
     */
    private const SCALARS = [
        'sku', 'gtin', 'mpn',
        'manage_stock', 'stock_action', 'stock_low',
        'backorder', 'min_qty',
        'weight', 'width', 'height', 'depth',
        'quantity_min', 'quantity_max', 'quantity_step',
    ];

    /**
     * The quantity rules among the SCALARS. A combination value of exactly zero
     * is no rule of its own: the combination inherits the item's, as the cart
     * reads the rules ({@see \Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy::ruleValue()}).
     *
     * @var string[]
     */
    private const QUANTITY_RULES = ['quantity_min', 'quantity_max', 'quantity_step'];

    /**
     * Combination translated columns (from #__alfa_item_combinations_<lang>).
     *
     * @var string[]
     */
    private const TRANSLATABLE = [
        'name', 'short_desc', 'full_desc', 'stock_low_message', 'stock_zero_message',
    ];

    /**
     * Resolve the item's variant for $selection (the resolver's default selection
     * when $selection is empty; see resolve()), price it, and overwrite the
     * item's data with the active combination's. A plain product (no
     * combinations) just gets its base price.
     *
     * @param object $item The item, with ->fields + ->combinations loaded.
     * @param array $selection fieldId => valueId from the request (empty = default).
     * @param PriceContext|null $context Pricing context; session when null.
     * @param PriceCalculator $calculator The pricing service.
     * @param int $quantity Quantity (1 for catalog/detail display).
     * @param int[] $optionValueIds The chosen option value ids.
     * @param array $payloads fieldId => opaque buyer payload (plugin pricing).
     * @param ExplicitPriceInstruction|null $price An operator's explicit price for THIS line. Only a
     *                                             caller that HAS a cart line can have one; a catalogue
     *                                             card and a product page pass null, always.
     * @param int[]|null $shownFieldIds The fields a listing card draws (null = every field; see resolve()).
     *
     * @since  2.0.0
     */
    public static function resolveAndPrice(object $item, array $selection, ?PriceContext $context, PriceCalculator $calculator, int $quantity = 1, array $optionValueIds = [], array $payloads = [], ?ExplicitPriceInstruction $price = null, ?array $shownFieldIds = null): void
    {
        $variantSelection = self::resolve($item, $selection, $optionValueIds, $payloads, $context, $shownFieldIds);
        $item->price = $calculator->calculate((int) $item->id, $quantity, $context, $variantSelection, $price);

        self::overlay($item, $item->variant->activeCombination ?? null);
    }

    /**
     * Resolve the item's variant for $selection (when it is empty, the resolver's
     * default selection: the configured default combination when it can be
     * bought, else the first buyable one in canonical order; see
     * {@see CombinationResolver::defaultSelection()}) WITHOUT pricing: sets
     * $item->variant and returns the pricing selection (combination + chosen
     * option values), or null when nothing is selected (plain base price).
     * The catalog list calls this in a
     * first in-PHP pass (combinations already loaded -> zero queries) so every
     * item's price can then be computed in ONE batched pass
     * (PriceCalculator::calculateBatch); single-item callers go through
     * resolveAndPrice() above. Pair with overlay() after pricing.
     *
     * Options are independent "extras": an item may carry option deltas with no
     * combinations at all, so a selection is returned whenever a combination OR
     * an option value is chosen.
     *
     * A non-empty selection gets its FIXED dimensions (one value across the
     * combinations) filled first, so leaving one out never makes it partial. A
     * listing card passes the fields it draws: a dimension it hides constrains
     * nothing and takes the displayed combination's value
     * ({@see CombinationResolver::resolve()}).
     *
     * It also sets the item's stock verdict and public stock figures from the
     * displayed unit's ledger facts: ->stockStatus (in_stock, backorder or
     * out_of_stock; null while the selection is incomplete), ->stockSalable and
     * ->stockLowAt (tracked units only), ->stock and ->available_quantity, and
     * ->cartState.
     *
     * @param object $item The item, with ->fields + ->combinations loaded.
     * @param array $selection fieldId => valueId from the request (empty = default combination).
     * @param int[] $optionValueIds The chosen option value ids (empty on the default list cards).
     * @param array $payloads fieldId => opaque buyer payload for plugin pricing (empty on default cards).
     * @param PriceContext|null $context Pricing context, passed to onCalculateItemPrice.
     * @param int[]|null $shownFieldIds The fields a listing card draws (null = every field: product page, cart).
     *
     * @return VariantSelection|null The pricing selection, or null (nothing selected -> base price).
     * @since  2.0.0
     */
    public static function resolve(object $item, array $selection, array $optionValueIds = [], array $payloads = [], ?PriceContext $context = null, ?array $shownFieldIds = null): ?VariantSelection
    {
        $optionValueIds = array_values(array_filter(array_map('intval', $optionValueIds)));

        // Expose the chosen option values so each field plugin can mark them
        // selected when it renders its option selector (the variant selection on
        // $item->variant only covers combination fields).
        $item->selectedOptionValueIds = $optionValueIds;

        $combinationId = 0;

        if (!empty($item->combinations)) {
            $selection = $selection
                ? CombinationResolver::withFixedDimensions($item->fields, $item->combinations, $selection)
                : CombinationResolver::defaultSelection($item->fields, $item->combinations);

            $item->variant = CombinationResolver::resolve($item->fields, $item->combinations, $selection, $shownFieldIds);
            $active = $item->variant->activeCombination ?? null;
            $combinationId = $active !== null ? (int) $active->id : 0;
        } else {
            $item->variant = null;
        }

        // The stock verdict and the public stock figures, from the ledger facts of
        // the unit the shopper is looking at (the plain item, or the displayed
        // combination), so the card, its reload, the product page and the cart
        // line all state what the cart enforces. Null while a variant selection is
        // incomplete; out of stock when the item has no sellable unit here (the
        // item level of a product with current combinations, no published
        // combination).
        $unit = self::activeUnitStock(item: $item);
        $status = self::selectionIncomplete(item: $item)
            ? null
            : ($unit !== null ? self::publicStatus(unit: $unit) : InventoryAvailabilityService::STATUS_OUT_OF_STOCK);

        $item->stockStatus = $status;
        $item->stockSalable = $unit !== null && $unit->managed ? $unit->ledgerSalable : null;
        $item->stockLowAt = $unit !== null && $unit->managed ? $unit->stockLowAt : null;

        self::applyPublicFigures(item: $item, unit: $unit);

        // The whole cart-button state is the MODEL's answer, computed once here, so
        // the cart layout only RENDERS it: no stock/policy/required logic in the
        // template, and the product page, its card and the cart all read the same
        // decision.
        $item->cartState = self::buildCartState(item: $item, status: $status);

        // Plugin price contributions: only when a configuration payload exists
        // (the default list cards submit none, so no plugin booting there).
        $contributions = $payloads !== []
            ? self::collectContributions(
                item: $item,
                payloads: $payloads,
                context: $context,
                selections: ['sel' => $selection, 'opt' => $optionValueIds],
            )
            : [];

        if ($combinationId <= 0 && $optionValueIds === [] && $contributions === []) {
            return null;
        }

        return new VariantSelection($combinationId, $optionValueIds, $contributions);
    }

    /**
     * The COMPLETE state the add-to-cart button renders, resolved in the model so
     * the layout computes nothing. Two independent gates, both decided here:
     *
     *   - STOCK: `salable` (can the shopper add this right now) and `showNotify`
     *     (replace the buy UI with the "notify me" button). `salable` is the unit's
     *     stock verdict read as a yes/no ({@see InventoryAvailabilityService::sellableStatus()}):
     *     the one ledger rule the cart write paths, the catalogue predicate and the
     *     kernel ceiling also apply. No verdict (an incomplete variant selection)
     *     is not salable; the layout reads `->variant->complete` only to word the
     *     hint (out of stock vs choose options). Notify is a plain-item concern only
     *     (a variant's out-of-stock state is a per-value gray-out) and appears only
     *     when genuinely unsalable AND the merchant chose it.
     *
     *   - REQUIRED FIELDS: `required` carries which configuration fields must be
     *     answered before adding ({@see ItemConfigurationHelper::requiredState()}):
     *     the ids/labels the client re-checks live and `ready` for the initial render.
     *
     * @param object $item The resolved item (->variant and ->fields set).
     * @param string|null $status The item's stock verdict, or null while the selection is incomplete.
     *
     * @return object {salable: bool, showNotify: bool, required: object}
     *
     * @since  2.0.0
     */
    private static function buildCartState(object $item, ?string $status): object
    {
        $hasVariants = $item->variant && !empty($item->variant->variantFieldIds);
        $salable = $status !== null && InventoryAvailabilityService::sellableStatus(status: $status);

        $showNotify = !$hasVariants
            && !$salable
            && (new StockVisibilityPolicy())->effectiveAction(stored: $item->stock_action ?? null)
                === StockVisibilityPolicy::ACTION_NOTIFY;

        $required = ItemConfigurationHelper::requiredState(item: $item, payloadLabel: Text::_('COM_ALFA_CART_CONFIGURATION'));
        $required['ready'] = $required['missing'] === [];

        return (object) [
            'salable' => $salable,
            'showNotify' => $showNotify,
            'required' => (object) $required,
        ];
    }

    /**
     * The largest quantity of the item's displayed unit a cart may hold, from
     * the same ledger facts and the same rule as the inventory kernel's
     * purchasable ceiling. No query: the facts were loaded with the item.
     *
     * Null means no stock ceiling (an untracked unit, or backorders accepted).
     * 0.0 while the variant selection is incomplete or the item has no unit here
     * (the item level of a product with current combinations, an unpublished,
     * archived or deleted combination), and while an integrity hold keeps the
     * unit off sale. The merchant's quantity rules are not part of it: the cart
     * applies them to the ceiling.
     *
     * @param object $item The resolved item (->variant and ->combinations set).
     *
     * @return float|null The ceiling, or null for none.
     *
     * @since  2.0.0
     */
    public static function ceilingFor(object $item): ?float
    {
        if (self::selectionIncomplete(item: $item)) {
            return 0.0;
        }

        $unit = self::activeUnitStock(item: $item);

        if ($unit === null) {
            return 0.0;
        }

        return InventoryAvailabilityService::ceilingFrom(
            salable:           $unit->ledgerSalable,
            managed:           $unit->managed,
            ledgerPresent:     $unit->ledgerRows > 0,
            projectionNonZero: $unit->projectionNonZero,
            integrityHold:     $unit->held,
            backorder:         $unit->backorder,
            minQty:            $unit->minQty,
        );
    }

    /**
     * Whether a cart line's unit may be sold now, by the stock verdict the card
     * and the product page show for it.
     *
     * False when the pair is not a sellable unit of the item as loaded: a
     * combination that is unpublished, archived, deleted or another item's, or
     * the bare item of a product with current combinations. Publication is a
     * catalogue gate the inventory kernel does not apply, so a cart write asks
     * this before the kernel ceiling is consulted.
     *
     * @param object $item The item (its facts are loaded with it).
     * @param int|null $combinationId The line's combination, or null for the item itself.
     *
     *
     * @since  2.0.0
     */
    public static function unitSellable(object $item, ?int $combinationId): bool
    {
        $unit = ItemFieldsHelper::unitStockFor(itemId: (int) ($item->id ?? 0), combinationId: (int) ($combinationId ?? 0));

        return $unit !== null && InventoryAvailabilityService::sellableStatus(status: self::unitStatus(unit: $unit));
    }

    /**
     * The stock facts of the unit the item currently displays: the item itself
     * when it offers no combination, else the resolved active combination.
     *
     * @param object $item The resolved item.
     *
     * @return object|null The facts, or null when no unit is displayed or the
     *                     displayed pair is not a unit.
     *
     * @since  2.0.0
     */
    private static function activeUnitStock(object $item): ?object
    {
        $itemId = (int) ($item->id ?? 0);

        if (empty($item->combinations)) {
            return ItemFieldsHelper::unitStockFor(itemId: $itemId, combinationId: 0);
        }

        $active = $item->variant->activeCombination ?? null;

        return $active !== null
            ? ItemFieldsHelper::unitStockFor(itemId: $itemId, combinationId: (int) $active->id)
            : null;
    }

    /**
     * Whether the item offers variant choices and the current selection does not
     * name one combination yet.
     *
     * @param object $item The resolved item.
     *
     *
     * @since  2.0.0
     */
    private static function selectionIncomplete(object $item): bool
    {
        $variant = $item->variant ?? null;

        if (!$variant || empty($variant->variantFieldIds)) {
            return false;
        }

        return empty($variant->complete) || empty($variant->activeCombination);
    }

    /**
     * The shared stock rule's status for a unit's facts.
     *
     * @param object $unit The unit's facts (see ItemFieldsHelper::unitStockFor()).
     *
     * @return string One of the InventoryAvailabilityService STATUS_* constants.
     *
     * @since  2.0.0
     */
    private static function unitStatus(object $unit): string
    {
        return InventoryAvailabilityService::statusFrom(
            salable:           $unit->ledgerSalable,
            managed:           $unit->managed,
            ledgerPresent:     $unit->ledgerRows > 0,
            projectionNonZero: $unit->projectionNonZero,
            integrityHold:     $unit->held,
            backorder:         $unit->backorder,
            minQty:            $unit->minQty,
            saleMinimum:       $unit->saleMinimum,
        );
    }

    /**
     * The status a storefront shows for a unit: the rule's status, with a
     * missing-ledger defect shown as out of stock (the defect itself is an
     * administration matter and is reported there, never on a storefront render).
     *
     * @param object $unit The unit's facts.
     *
     * @return string STATUS_IN_STOCK, STATUS_BACKORDER or STATUS_OUT_OF_STOCK.
     *
     * @since  2.0.0
     */
    private static function publicStatus(object $unit): string
    {
        $status = self::unitStatus(unit: $unit);

        return $status === InventoryAvailabilityService::STATUS_LEDGER_MISSING
            ? InventoryAvailabilityService::STATUS_OUT_OF_STOCK
            : $status;
    }

    /**
     * Set the item's public stock figures from the ledger: the displayed unit's
     * physical and salable quantity. A product with current combinations and no
     * displayed combination shows the physical total of its published
     * combinations and the salable total of those that are tracked. Null when
     * there are no facts.
     *
     * @param object $item The resolved item.
     * @param object|null $unit The displayed unit's facts, or null.
     *
     *
     * @since  2.0.0
     */
    private static function applyPublicFigures(object $item, ?object $unit): void
    {
        if ($unit !== null) {
            $item->stock = $unit->ledgerPhysical;
            $item->available_quantity = $unit->ledgerSalable;

            return;
        }

        $physical = null;
        $salable = null;

        foreach ($item->combinations ?? [] as $combination) {
            $facts = ItemFieldsHelper::unitStockFor(itemId: (int) ($item->id ?? 0), combinationId: (int) $combination->id);

            if ($facts === null) {
                continue;
            }

            $physical = ($physical ?? 0.0) + $facts->ledgerPhysical;
            $salable = ($salable ?? 0.0) + ($facts->managed ? $facts->ledgerSalable : 0.0);
        }

        $item->stock = $physical;
        $item->available_quantity = $salable;
    }

    /**
     * Boot each of the item's field plugins (boot-direct per field, the same
     * pattern as onItemView) and gather their declarative price lines from
     * onCalculateItemPrice, given the buyer's per-field payload + the field's
     * config. A plain field's handler is a no-op and adds nothing. Sorted by
     * sortOrder for a stable fold. A failing pricing plugin never breaks the
     * price.
     *
     * @param object $item The item (carries ->fields).
     * @param array $payloads fieldId => opaque buyer payload.
     * @param PriceContext|null $context Pricing context (passed to the event).
     * @param array $selections Sibling selections ['sel' => fieldId=>valueId, 'opt' => valueIds] (HasSelections).
     *
     * @return PriceContribution[] The collected lines, ordered by sortOrder.
     * @since  2.0.0
     */
    private static function collectContributions(object $item, array $payloads, ?PriceContext $context = null, array $selections = []): array
    {
        if (empty($item->fields)) {
            return [];
        }

        $app = Factory::getApplication();
        $contributions = [];

        foreach ($item->fields as $field) {
            try {
                $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');

                if (!$plugin || !method_exists($plugin, 'onCalculateItemPrice')) {
                    continue;
                }

                $event = new CalculateItemPriceEvent('onCalculateItemPrice', [
                    'subject' => $item,
                    'method' => $field,
                    'payload' => $payloads[(int) $field->id] ?? null,
                    // The field's EFFECTIVE per-item config (areas + prices), batch-loaded
                    // onto $field->config, so pricing reads it with no per-item query.
                    'config' => $field->config ?? new Registry($field->params_decoded ?? []),
                    'context' => $context,
                    'selections' => $selections,
                ]);

                $plugin->onCalculateItemPrice($event);

                foreach ($event->getContributions() as $contribution) {
                    // Owner default: the collector knows the invoked plugin;
                    // an unset source becomes the field's plugin element.
                    if ($contribution->source === '') {
                        $contribution = new PriceContribution(
                            code:      $contribution->code,
                            label:     $contribution->label,
                            operation: $contribution->operation,
                            amount:    $contribution->amount,
                            target:    $contribution->target,
                            sortOrder: $contribution->sortOrder,
                            source:    (string) $field->type,
                        );
                    }

                    $contributions[] = $contribution;
                }
            } catch (Throwable $e) {
                // Loud in development (a half-written contribution throws with
                // its code named); in production a failing pricing plugin must
                // not break the price; log and move on.
                if (\defined('JDEBUG') && JDEBUG) {
                    throw $e;
                }

                \Joomla\CMS\Log\Log::add(
                    'Item price contribution failed (' . ($field->type ?? '?') . '): ' . $e->getMessage(),
                    \Joomla\CMS\Log\Log::WARNING,
                    'com_alfa.pricing',
                );
            }
        }

        usort($contributions, static fn ($a, $b) => $a->sortOrder <=> $b->sortOrder);

        return $contributions;
    }

    /**
     * Parse the buyer's field selection from the request: the ONE place that
     * reads the sel[] (combination), opt[] (option values) and conf[] (opaque
     * plugin payloads) inputs, so both the product page (ItemModel) and the card
     * reload (ItemsModel) parse them identically and the views stay thin.
     *
     * @return array{selection: array, optionValueIds: int[], payloads: array}
     * @since  2.0.0
     */
    public static function requestSelection(): array
    {
        $input = Factory::getApplication()->getInput();

        $selection = [];

        foreach ((array) $input->get('sel', [], 'array') as $fieldId => $valueId) {
            $selection[(int) $fieldId] = (int) $valueId;
        }

        $payloads = [];

        foreach ((array) $input->get('conf', [], 'array') as $fieldId => $payload) {
            $payloads[(int) $fieldId] = $payload;
        }

        return [
            'selection' => $selection,
            'optionValueIds' => array_map('intval', (array) $input->get('opt', [], 'array')),
            'payloads' => $payloads,
        ];
    }

    /**
     * A parsed selection reduced to what the product itself offers.
     *
     * A request names fields and values by id; what is resolved, priced and
     * stored for a product is its own configuration only. Kept are: a variant
     * pair when its field is a dimension of the product's combinations (the
     * value stays as posted: one that is not the dimension's resolves no
     * combination, so nothing is chosen on the buyer's behalf); an option pair
     * when the product offers the value as an option and the pair names that
     * value's own field, which is no dimension, so an option field holds at
     * most the one value chosen for it; a payload when its field takes one
     * ({@see self::payloadFields()}). Everything else is dropped. A request
     * the storefront's own controls post comes back as it was.
     *
     * @param object $item The product, with its fields, options and combinations.
     * @param array $request The selection, as requestSelection() returns it.
     *
     * @return array{selection: array<int, int>, optionValueIds: array<int, int>, payloads: array}
     *
     * @since  2.0.0
     */
    public static function offeredSelection(object $item, array $request): array
    {
        $fields = (array) ($item->fields ?? []);
        $dimensions = CombinationResolver::dimensionValues(fields: $fields, combinations: (array) ($item->combinations ?? []));
        $fieldOf = [];

        foreach ($fields as $field) {
            foreach ((array) ($field->values ?? []) as $value) {
                $fieldOf[(int) $value->id] = (int) $field->id;
            }
        }

        $offered = [];

        foreach ((array) ($item->options ?? []) as $option) {
            $offered[(int) $option->value_id] = true;
        }

        $selection = [];

        foreach ((array) ($request['selection'] ?? []) as $fieldId => $valueId) {
            if (isset($dimensions[(int) $fieldId])) {
                $selection[(int) $fieldId] = (int) $valueId;
            }
        }

        $optionValueIds = [];

        foreach ((array) ($request['optionValueIds'] ?? []) as $fieldId => $valueId) {
            $fieldId = (int) $fieldId;
            $valueId = (int) $valueId;

            if (isset($offered[$valueId]) && ($fieldOf[$valueId] ?? null) === $fieldId && !isset($dimensions[$fieldId])) {
                $optionValueIds[$fieldId] = $valueId;
            }
        }

        return [
            'selection' => $selection,
            'optionValueIds' => $optionValueIds,
            'payloads' => array_intersect_key((array) ($request['payloads'] ?? []), self::payloadFields(item: $item)),
        ];
    }

    /**
     * The product's fields a buyer answers with a composition (a payload, its
     * full form, its uploads), as field id => true.
     *
     * The core rule, {@see ItemConfigurationHelper::payloadFields()}: a field
     * takes one only when its field plugin implements the payload handler, and
     * a dimension that offers a choice takes none. Its type decides nothing.
     * Reducing a selection drops a composition posted for any other field; an
     * add refuses one ({@see ItemConfigurationHelper::compositionRefusal()}).
     *
     * @param object $item The product, with its fields and combinations.
     *
     * @return array<int, true>
     *
     * @since  2.0.0
     */
    public static function payloadFields(object $item): array
    {
        return ItemConfigurationHelper::payloadFields(item: $item);
    }

    /**
     * Overlay lookups already resolved for this request.
     *
     * `$overlayTranslations` is keyed "lang|combinationId", `$overlayMedia` by
     * combination id. Both were per-call memos inside {@see self::overlay()};
     * they are class-level so {@see self::warmOverlay()} can fill them for a whole
     * page in one query each.
     *
     * @since  2.0.0
     */
    private static array $overlayTranslations = [];

    /** @since 2.0.0 */
    private static array $overlayMedia = [];

    /**
     * Resolve the overlay lookups for a whole page of variant cards at once.
     *
     * WHY THIS EXISTS. {@see self::overlay()} needs two things per card (the
     * combination's translated fields and its own gallery) and both were fetched
     * one card at a time. The listing model batches every OTHER data kind for the
     * page (media, item fields, prices) and then undid that three lines later, so
     * the storefront's highest-traffic query grew with the number of variant cards
     * on the page. MEASURED on the demo, ordering by id so the variant products
     * land together: 1 card 26 queries, 2 cards 29, 4 cards 37, 5 cards 44, and
     * flat at 44 once the page ran out of variant cards, which is the shape of an
     * N+1 rather than of a bigger page.
     *
     * Calling this is an OPTIMISATION, never a requirement: overlay() still falls
     * back to its own single-row read for any combination it was not warmed with,
     * so a caller that does not warm is slower and never wrong.
     *
     * @param int[] $combinationIds The active combinations about to be overlaid.
     *
     *
     * @since  2.0.0
     */
    public static function warmOverlay(array $combinationIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $combinationIds), static fn (int $i): bool => $i > 0)));

        if ($ids === []) {
            return;
        }

        $langSuffix = self::overlayLangSuffix();
        $missing = array_values(array_filter(
            $ids,
            static fn (int $id): bool => !isset(self::$overlayTranslations[$langSuffix . '|' . $id]),
        ));

        if ($missing !== []) {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // Seed every requested id with an empty row FIRST: a combination with
            // no translation must be remembered as "looked up and has none", or it
            // would fall through to a per-card query on every render pass: the
            // very thing this method exists to prevent.
            foreach ($missing as $id) {
                self::$overlayTranslations[$langSuffix . '|' . $id] = [];
            }

            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName(array_merge(['id_item_combination'], self::TRANSLATABLE)))
                        ->from($db->quoteName('#__alfa_item_combinations_' . $langSuffix))
                        ->whereIn($db->quoteName('id_item_combination'), $missing),
                );

                foreach ($db->loadAssocList() ?: [] as $row) {
                    $id = (int) ($row['id_item_combination'] ?? 0);
                    unset($row['id_item_combination']);
                    self::$overlayTranslations[$langSuffix . '|' . $id] = $row;
                }
            } catch (Throwable $e) {
                // A missing per-language table means "no translations", exactly as
                // the per-card read has always treated it. The empty seeds stand.
            }
        }

        $needMedia = array_values(array_filter($ids, static fn (int $id): bool => !isset(self::$overlayMedia[$id])));

        if ($needMedia !== []) {
            // One grouped read for the page. getMediaData() returns
            // [itemId => [media, ...]] for an array argument.
            $grouped = MediaHelper::getMediaData(origin: 'combination', itemIDs: $needMedia);

            foreach ($needMedia as $id) {
                self::$overlayMedia[$id] = $grouped[$id] ?? [];
            }
        }
    }

    /**
     * The visitor's language as the per-language table suffix.
     *
     * @since  2.0.0
     */
    private static function overlayLangSuffix(): string
    {
        return strtolower(str_replace('-', '_', Factory::getApplication()->getLanguage()->getTag()));
    }

    /**
     * Overwrite the item's data (scalars + current-language text + gallery) with
     * the active combination's, where the combination sets them.
     *
     * RE-ENTRANT: the first call memorises the item's PRISTINE catalog values
     * and every later call restores them before applying, so overlaying a
     * different combination (a cloned cart line, a variant reload) can never
     * inherit a PREVIOUS combination's values. A combination that does not set
     * a field genuinely inherits the ITEM's own value, not the default
     * combination's. A quantity rule the combination stores as exactly zero
     * counts as not set, as the cart reads it.
     *
     * @param object $item The item being assembled.
     * @param object|null $active The resolved combination, or null.
     *
     * @since  2.0.0
     */
    public static function overlay(object $item, ?object $active): void
    {
        if (!isset($item->_pristineOverlayBase)) {
            $pristine = [];

            foreach (array_merge(self::SCALARS, self::TRANSLATABLE) as $field) {
                $pristine[$field] = $item->$field ?? null;
            }

            $item->_pristineOverlayBase = $pristine;
        } else {
            foreach ($item->_pristineOverlayBase as $field => $value) {
                $item->$field = $value;
            }
        }

        if ($active !== null) {
            foreach (self::SCALARS as $field) {
                $value = $active->$field ?? null;

                // NULL is always "not overridden": the combination inherits.
                if ($value === null) {
                    continue;
                }

                // EMPTY IS "NOT OVERRIDDEN" FOR EVERY SCALAR BUT ONE.
                //
                // For sku, dimensions, quantities and the rest, a blank column
                // means the variant said nothing, and treating it as an
                // override would erase the product's value.
                //
                // `backorder` is the exception, and the schema says so: NULL
                // inherits the item, '' means "skip the item and follow the
                // shop default", and anything else is that exact policy. It is
                // the only column carrying that sentinel; min_qty's comment
                // contrasts itself with it explicitly ("Two-level by design:
                // no skip-the-item sentinel").
                //
                // Folding '' in with NULL discarded a decision the merchant
                // had made about this variant, and it only showed when the
                // product's own policy differed from the shop's: a variant set
                // to follow a shop that DENIES backorders inherited the
                // product's "allow" and offered the customer a backorder the
                // shop does not accept.
                if ($value === '' && $field !== 'backorder') {
                    continue;
                }

                // A quantity rule of exactly zero is inherited, as the cart
                // reads it: writing it over the item's would leave the
                // displayed unit with no rule where the product has one.
                if (\in_array(needle: $field, haystack: self::QUANTITY_RULES, strict: true)
                    && QuantityDecimal::isZero(a: QuantityDecimal::canonical(value: $value))) {
                    continue;
                }

                $item->$field = $value;
            }
        }

        // stock_action is the one SCALAR carrying a sentinel: a combination may store
        // -1 ("from configuration"), and the pristine restore above puts the item's own
        // raw value back on every later pass. Resolved here, at the single point both
        // the overridden and the un-overridden path go through, so nothing downstream
        // ever compares an unresolved -1.
        $item->stock_action = (new StockVisibilityPolicy())->effectiveAction(stored: $item->stock_action ?? null);

        if ($active === null) {
            return;
        }

        // Translated overrides in the visitor's current language; a missing
        // row/table simply inherits the item. Memo per (lang, combination): the
        // same active combination is resolved in several render passes per page.
        $langSuffix = self::overlayLangSuffix();
        $transKey = $langSuffix . '|' . (int) $active->id;

        if (isset(self::$overlayTranslations[$transKey])) {
            $translation = self::$overlayTranslations[$transKey];
        } else {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $translation = [];

            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName(self::TRANSLATABLE))
                        ->from($db->quoteName('#__alfa_item_combinations_' . $langSuffix))
                        ->where($db->quoteName('id_item_combination') . ' = ' . (int) $active->id),
                );
                $translation = (array) ($db->loadAssoc() ?? []);
            } catch (Throwable $e) {
                $translation = [];
            }

            self::$overlayTranslations[$transKey] = $translation;
        }

        foreach (self::TRANSLATABLE as $field) {
            if (($translation[$field] ?? '') !== '') {
                $item->$field = $translation[$field];
            }
        }

        // The combination's own gallery replaces the item's when it has one.
        $media = self::$overlayMedia[(int) $active->id]
            ?? MediaHelper::getMediaData(origin: 'combination', itemIDs: (int) $active->id);

        if ($media) {
            $item->medias = $media;
        }
    }
}
