<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use InvalidArgumentException;
use Joomla\CMS\Component\ComponentHelper;

/**
 * Main Price Calculator Service
 * @since  1.0.0
 */
class PriceCalculator
{
    protected $loader;
    protected $calculator;
    protected $settings;

    public function __construct(
        ?PriceDataLoader $loader = null,
        ?PriceComputationEngine $calculator = null,
    ) {
        $this->loader = $loader ?? new PriceDataLoader();
        $this->calculator = $calculator ?? new PriceComputationEngine();
        $this->settings = ComponentHelper::getParams('com_alfa');
    }

    /**
     * Calculate prices for one or many products, batch-loading their pricing data and computing a result each.
     *
     * A variant selection (a chosen combination and/or options) routes the ONE
     * item through the combination-aware path; without it this is plain base
     * pricing, batchable across many ids. Single entry point either way.
     *
     * @param int|int[] $productIds One product id or an array of product ids.
     * @param int|int[] $quantities A single quantity applied to all, or a per-product-id quantity map.
     * @param PriceContext|null $context The pricing context; resolved from session when null.
     * @param VariantSelection|null $selection A variant selection: prices that single item's combination/options.
     * @param ExplicitPriceInstruction|null $price An operator's explicit price for THIS line; null is ordinary pricing.
     *
     * @return PriceResult|PriceResult[] A single result when a scalar id (or a selection) was passed, otherwise a map keyed by product id.
     * @since  1.0.0
     */
    public function calculate($productIds, $quantities = 1, ?PriceContext $context = null, ?VariantSelection $selection = null, ?ExplicitPriceInstruction $price = null)
    {
        // AN INSTRUCTION IS FOR ONE LINE AND MAY NOT BE BROADCAST.
        //
        // The fence lives here and not only on calculateBatch(), because the
        // catalogue PRICE INDEX reaches this method with an array:
        // PriceIndexSyncService prices a whole (currency, place, usergroup,
        // date) segment with calculate($group['items'], 1, $context). Silently
        // applying one operator's negotiated price to every item of a segment
        // would write it into #__alfa_items_price_index and serve it to every
        // shopper: the leak the custom-price contract forbids by name.
        if ($price !== null && \is_array($productIds)) {
            throw new InvalidArgumentException(
                'An explicit custom price applies to ONE line and cannot be broadcast to an array of items.',
            );
        }

        $context ??= PriceContext::fromSession();

        // A variant selection is single-item by nature: price that one item's
        // combination/options. No selection -> plain base pricing below.
        if ($selection !== null && !$selection->isEmpty()) {
            $itemId = (int) (is_array($productIds) ? reset($productIds) : $productIds);
            $quantity = is_array($quantities) ? (int) ($quantities[$itemId] ?? 1) : (int) $quantities;

            return $this->calculateVariant($itemId, $quantity, $selection, $context, $price);
        }

        $isSingle = !is_array($productIds);
        $productIds = (array) $productIds;
        $quantities = $this->normalizeQuantities($productIds, $quantities);

        $priceData = $this->loader->loadBatch($productIds, $context);

        $results = [];
        foreach ($productIds as $productId) {
            $results[$productId] = $this->calculator->compute(
                $productId,
                $quantities[$productId],
                $priceData->getPricesFor($productId),
                $priceData->getDiscountsFor($productId),
                $priceData->getTaxesFor($productId),
                $context,
                // Reachable only for a scalar id: the array fence above threw first.
                $price,
            );
        }

        return $isSingle ? reset($results) : $results;
    }

    /**
     * Calculate the price of one item for a specific variant selection: the
     * chosen combination and/or option values.
     *
     * The selection resolves to an effective NET unit price (a combination price
     * replaces the item base; a combination adjust or option delta shifts it),
     * which is fed as a single synthetic base row to the unchanged engine, so
     * the item's regular taxes and discounts apply exactly as for the plain item.
     * An empty selection is identical to calculate().
     *
     * @param int $itemId The item id.
     * @param int $quantity Quantity ordered (min 1).
     * @param VariantSelection $selection The chosen combination + option values.
     * @param PriceContext|null $context Pricing context; resolved from session when null.
     *
     * @return PriceResult The computed price for this variant.
     * @since  2.0.0
     */
    public function calculateVariant(int $itemId, int $quantity, VariantSelection $selection, ?PriceContext $context = null, ?ExplicitPriceInstruction $price = null): PriceResult
    {
        $context ??= PriceContext::fromSession();
        $quantity = max(1, $quantity);

        $data = $this->loader->loadBatch([$itemId], $context);
        $comboRows = $selection->combinationId > 0
            ? $this->loader->loadCombinationPriceRows($itemId, $selection->combinationId, $context)
            : [];

        return $this->computeVariantFromData(
            $itemId,
            $quantity,
            $selection,
            $data->getPricesFor($itemId),
            $data->getDiscountsFor($itemId),
            $data->getTaxesFor($itemId),
            $comboRows,
            $context,
            $price,
        );
    }

    /**
     * Every quantity above one at which the unit a line of this item is priced
     * from may change: the first quantity of each price row's range and the
     * one after its last, over the item's rows and, for a variant, its
     * combination's price and adjust rows, under the context's terms.
     *
     * Between two of these quantities the same row is selected for the line,
     * so the line's unit is one; nothing else that prices it depends on the
     * quantity. More quantities than the selection ever turns on may be
     * listed. The rows are the ones a pricing of the item under the same
     * context reads.
     *
     * @param int $itemId The item id.
     * @param int|null $combinationId The variant's combination, or null.
     * @param PriceContext $context Whose terms the line is priced under.
     *
     * @return int[] Ascending, without repeats.
     *
     * @since  2.0.0
     */
    public function tierStarts(int $itemId, ?int $combinationId, PriceContext $context): array
    {
        $rows = $this->loader->loadBatch([$itemId], $context)->getPricesFor($itemId);

        if ($combinationId !== null && $combinationId > 0) {
            $rows = array_merge($rows, $this->loader->loadCombinationPriceRows($itemId, $combinationId, $context));
        }

        $starts = [];

        foreach ($rows as $row) {
            // A row covers q when start <= q <= end; a bound left empty is none.
            $start = $row->quantity_start ?? null;
            $end = $row->quantity_end ?? null;

            if ($start !== null && $start !== '') {
                $starts[] = (int) ceil((float) $start);
            }

            if ($end !== null && $end !== '') {
                $starts[] = (int) floor((float) $end) + 1;
            }
        }

        $starts = array_values(array_unique(array_filter($starts, static fn (int $quantity): bool => $quantity > 1)));
        sort($starts);

        return $starts;
    }

    /**
     * Calculate prices for MANY items in ONE batched pass, each with its own
     * (already-resolved) variant selection: the efficient catalog-list path.
     *
     * The base data (prices/discounts/taxes) loads in a single batch and the
     * combination price rows for every selected combination load in a single
     * query (loadCombinationPriceRowsBatch), so the whole page is priced with a
     * fixed handful of queries instead of one batch + N per-item reloads. Each
     * item is then computed in PHP from that preloaded data: a non-empty
     * selection routes through the same combination-aware logic as
     * calculateVariant(), a plain/empty one gets the base price.
     *
     * @param int[] $itemIds The item ids to price.
     * @param int|int[] $quantities A single quantity for all, or a per-id map.
     * @param PriceContext|null $context Pricing context; session when null.
     * @param array<int,VariantSelection> $selections Map itemId => selection (absent/empty = base).
     *
     * @return PriceResult[] Results keyed by item id.
     * @since  2.0.0
     */
    public function calculateBatch(array $itemIds, $quantities = 1, ?PriceContext $context = null, array $selections = []): array
    {
        $context ??= PriceContext::fromSession();
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));

        if ($itemIds === []) {
            return [];
        }

        $quantities = $this->normalizeQuantities($itemIds, $quantities);
        $data = $this->loader->loadBatch($itemIds, $context);

        // One query for every selected combination's price rows.
        $comboPairs = [];

        foreach ($selections as $itemId => $selection) {
            if ($selection instanceof VariantSelection && $selection->combinationId > 0) {
                $comboPairs[(int) $itemId] = $selection->combinationId;
            }
        }

        $comboRowsByItem = $comboPairs !== []
            ? $this->loader->loadCombinationPriceRowsBatch($comboPairs, $context)
            : [];

        $results = [];

        foreach ($itemIds as $itemId) {
            $quantity = max(1, (int) ($quantities[$itemId] ?? 1));
            $selection = $selections[$itemId] ?? null;

            if ($selection instanceof VariantSelection && !$selection->isEmpty()) {
                $results[$itemId] = $this->computeVariantFromData(
                    $itemId,
                    $quantity,
                    $selection,
                    $data->getPricesFor($itemId),
                    $data->getDiscountsFor($itemId),
                    $data->getTaxesFor($itemId),
                    $comboRowsByItem[$itemId] ?? [],
                    $context,
                );

                continue;
            }

            $results[$itemId] = $this->calculator->compute(
                $itemId,
                $quantity,
                $data->getPricesFor($itemId),
                $data->getDiscountsFor($itemId),
                $data->getTaxesFor($itemId),
                $context,
            );
        }

        return $results;
    }

    /**
     * Compute one item's variant price from ALREADY-LOADED data: the shared core
     * of calculateVariant() (single, self-loading) and calculateBatch() (batched,
     * preloaded). Splits the combination rows into 'price' (replace base) and
     * 'adjust' (shift base), stacks the selected option deltas, and feeds the
     * engine: untouched base rows when nothing shifts the price (so a combination's
     * OWN sale/override renders like the main product), else one synthetic net row.
     *
     * @param int $itemId The item id.
     * @param int $quantity Quantity ordered (already >= 1).
     * @param VariantSelection $selection The chosen combination + option values.
     * @param object[] $itemPrices The item's base price rows.
     * @param object[] $discounts The item's discount rows.
     * @param object[] $taxes The item's tax rows.
     * @param object[] $comboRows The selected combination's price rows.
     * @param PriceContext $context Pricing context.
     *
     * @return PriceResult The computed price for this variant.
     * @since  2.0.0
     */
    protected function computeVariantFromData(int $itemId, int $quantity, VariantSelection $selection, array $itemPrices, array $discounts, array $taxes, array $comboRows, PriceContext $context, ?ExplicitPriceInstruction $price = null): PriceResult
    {
        if ($selection->isEmpty()) {
            return $this->calculator->compute($itemId, $quantity, $itemPrices, $discounts, $taxes, $context, $price);
        }

        // A combination's 'price' rows replace the item base; 'adjust' rows shift it.
        $comboPriceRows = [];
        $comboAdjustRows = [];

        foreach ($comboRows as $row) {
            if (($row->mode ?? 'price') === 'adjust') {
                $comboAdjustRows[] = $row;
            } else {
                $comboPriceRows[] = $row;
            }
        }

        $baseRows = $comboPriceRows !== [] ? $comboPriceRows : $itemPrices;
        $adjustRow = $comboPriceRows === [] ? $this->calculator->selectBaseRow($comboAdjustRows, $quantity, $context->groupDistances()) : null;
        $optionDeltas = $this->loader->loadOptionDeltas($itemId, $selection->optionValueIds);
        $contributions = $selection->contributions;

        // Nothing shifts the price -> feed the base rows straight to the engine, so
        // a combination's (or the item's) OWN sale/override renders exactly like
        // the main product: strikethrough base, - sale amount, savings percent.
        if ($adjustRow === null && $optionDeltas === [] && $contributions === []) {
            return $this->calculator->compute($itemId, $quantity, $baseRows, $discounts, $taxes, $context, $price);
        }

        // A delta applies -> resolve to one effective net unit and feed a synthetic
        // row. (A combination's own sale folds into that net here.) The unit is
        // exact, a decimal of at most eight places carried as text, and compute()
        // extends it by the quantity and rounds once. A chain whose last step
        // targets the gross also reports that gross unit, which compute() extends
        // the same way and lands the line on.
        $grossTarget = null;
        $net = $this->resolveVariantNet($baseRows, $adjustRow, $optionDeltas, $contributions, $taxes, $quantity, grossTarget: $grossTarget, groupDistances: $context->groupDistances());

        // A SALE MUST SURVIVE THE DELTA. The engine's rule is that "a row with
        // override_value set is a SALE price: it replaces every regular
        // discount with ONE custom discount": compute() never processes the
        // regular rules of a sale row.
        // This row used to be built with override_value = null, so a sale item
        // dropped onto the REGULAR discount path the moment anything shifted
        // the price, and those discounts were then applied on top of a net
        // that had already folded the sale in. Measured: a 27.79 sale priced
        // 26.40 as soon as a zero-value option was chosen, i.e. the customer
        // paid less than the sale for picking a variant.
        //
        // The list figure is the SAME delta applied to the un-discounted base,
        // so the sale still renders as it does without a delta (strikethrough
        // base, minus the sale amount) rather than silently losing it.
        $winningRow = $this->calculator->selectBaseRow($baseRows, $quantity, $context->groupDistances());
        $wasSale = $winningRow !== null
            && isset($winningRow->override_value)
            && $winningRow->override_value !== null
            && $winningRow->override_value !== '';

        $listNet = $net;

        if ($wasSale) {
            $listRows = array_map(
                static function (object $r): object {
                    $copy = clone $r;
                    $copy->override_value = null;

                    return $copy;
                },
                $baseRows,
            );

            $listNet = $this->resolveVariantNet($listRows, $adjustRow, $optionDeltas, $contributions, $taxes, $quantity, groupDistances: $context->groupDistances());
        }

        $row = (object) [
            'value' => $wasSale ? $listNet : $net,
            'quantity_start' => null,
            'quantity_end' => null,
            'override_value' => $wasSale ? $net : null,
            'override_type' => $wasSale ? 'start' : null,
            'publish_up' => null,
            'publish_down' => null,
            // Not a stored price: the unit is computed for this variant.
            'computed' => true,
        ];

        // The gross unit crosses to compute() as the exact decimal text it was
        // derived as: a float cannot carry eight decimals of a large unit, and
        // the one it holds instead can sit on a tie the unit is under.
        return $this->calculator->compute($itemId, $quantity, [$row], $discounts, $taxes, $context, $price, grossTarget: $grossTarget);
    }

    /**
     * Resolve the variant's effective net unit price when a delta is in play.
     *
     * Takes the base unit (a combination price row, else the item base; its
     * sale honoured), applies the combination's +/- adjust, then stacks each
     * option delta. Every adjustment lands on net or gross per its own target,
     * defaulting to the component's 'combination_adjust_target'.
     *
     * THE ARITHMETIC IS EXACT. A unit is a decimal of at most eight places,
     * held here as a count of the smallest unit and never as a float: an
     * amount is added exactly, and a percentage or a tax factor multiplies
     * exactly and is rounded once, half away from zero, at the eighth place.
     * The floats in the analytical breakdown are read off those units for
     * display; no price is computed from them.
     *
     * @param object[] $baseRows The base price rows (combination or item).
     * @param object|null $adjustRow The combination adjust row, or null.
     * @param object[] $optionDeltas The selected option delta rows.
     * @param PriceContribution[] $contributions Plugin-computed declarative lines.
     * @param array $taxes Raw tax rows (for the gross-target chain).
     * @param int $quantity Quantity ordered.
     * @param array|null $steps Filled with the analytical breakdown when passed.
     * @param string|null $grossTarget Set to the unit gross, as a canonical unit, when the last
     *                                 applied step targets the gross and a tax applies, else null.
     * @param array<int,int> $groupDistances How far each group is from the nearest group the customer is placed in.
     *
     * @return string The effective net unit price, as a canonical unit (never negative).
     * @since  2.0.0
     */
    protected function resolveVariantNet(array $baseRows, ?object $adjustRow, array $optionDeltas, array $contributions, array $taxes, int $quantity, ?array &$steps = null, ?string &$grossTarget = null, array $groupDistances = []): string
    {
        // The float factor says whether the chain can carry a gross at all, and is
        // what the breakdown reports; the units themselves go through the exact chain.
        $taxFactor = $this->calculator->taxFactor($taxes);
        $taxed = $taxFactor > 0.0;
        $factors = $taxed ? $this->calculator->taxFactors($taxes) : [];

        $defaultTarget = (string) $this->settings->get('combination_adjust_target', 'final');
        $defaultTarget = in_array($defaultTarget, ['start', 'final'], true) ? $defaultTarget : 'final';

        $baseRow = $this->calculator->selectBaseRow($baseRows, $quantity, $groupDistances);
        [$net, $gross] = $this->rowUnit($baseRow, $taxed, $factors);

        // Whether the last applied step stated the gross.
        $lastOnGross = false;

        // Per-step analytical breakdown (only when a caller passes $steps): each
        // line's net impact is the running net's change at that step. base_sale
        // says whether the winning base row carries its own sale.
        if ($steps !== null) {
            $steps = ['base_net' => (float) PriceComputationEngine::unitText(count: $net), 'base_sale' => $baseRow !== null && isset($baseRow->override_value) && $baseRow->override_value !== '', 'tax_factor' => $taxFactor, 'lines' => []];
        }

        // The combination's adjust, each option delta, then the plugin contributions
        // (onCalculateItemPrice, in sortOrder): ONE primitive, each step on the net
        // ('start') or on the gross ('final').
        $moves = [];

        if ($adjustRow !== null) {
            $moves[] = [$adjustRow->operation ?? '+', $adjustRow->value, $adjustRow->override_type ?? '', ['kind' => 'combination']];
        }

        foreach ($optionDeltas as $option) {
            $moves[] = [$option->operation, $option->amount, $option->apply_on ?? '', ['kind' => 'option', 'value_id' => (int) ($option->value_id ?? 0)]];
        }

        foreach ($contributions as $contribution) {
            $moves[] = [$contribution->operation, $contribution->amount, $contribution->target, ['kind' => 'contribution', 'code' => $contribution->code, 'label' => $contribution->label, 'source' => $contribution->source]];
        }

        foreach ($moves as [$operation, $stored, $target, $line]) {
            $target = in_array($target, ['start', 'final'], true) ? $target : $defaultTarget;
            $amount = PriceComputationEngine::catalogueUnit(value: $stored);
            $before = $net;

            $lastOnGross = $target === 'final' && $taxed;
            [$net, $gross] = PriceComputationEngine::applyVariantAdjust(
                net: $net,
                gross: $gross,
                operation: $operation,
                amount: PriceComputationEngine::unitCount(unit: $amount),
                onGross: $lastOnGross,
                factors: $factors,
            );

            if ($steps !== null) {
                // The step's impact is the exact difference of the two nets.
                [$impact] = PriceComputationEngine::applyVariantAdjust(net: $net, gross: null, operation: '-', amount: $before, onGross: false, factors: []);
                $steps['lines'][] = $line + ['operation' => $operation, 'amount' => (float) $amount, 'apply_on' => $target, 'impact_net' => (float) PriceComputationEngine::unitText(count: $impact)];
            }
        }

        // Never negative: a unit below zero is a unit of zero, on either axis.
        if ($net < 0 || ($gross !== null && $gross < 0)) {
            $net = 0;
            $gross = $gross === null ? null : 0;
        }

        // A last step on the gross states the unit gross; compute() extends it by
        // the quantity, rounds once, and lands the line on it only when a tax applies.
        $grossTarget = $lastOnGross ? PriceComputationEngine::unitText(count: $gross) : null;

        return PriceComputationEngine::unitText(count: $net);
    }

    /**
     * Analytical price breakdown for a resolved variant: the base net plus one
     * line per combination adjust / option delta / plugin contribution, each with
     * its rule (operation/amount/apply_on) and its exact net impact. Reuses the
     * SAME sequential engine as pricing, so the frozen order snapshot matches the
     * live price exactly. Impacts are net; the caller applies tax via `tax_factor`.
     * `base_sale` is true when the winning base row carries its own sale.
     *
     * @param int $itemId The item id.
     * @param int $quantity Quantity ordered.
     * @param VariantSelection $selection The resolved selection.
     * @param PriceContext|null $context Pricing context; session when null.
     *
     * @return array{base_net: float, base_sale: bool, tax_factor: float, lines: array<int, array>}
     * @since  2.0.0
     */
    public function breakdownVariant(int $itemId, int $quantity, VariantSelection $selection, ?PriceContext $context = null): array
    {
        $context ??= PriceContext::fromSession();
        $quantity = max(1, $quantity);

        $data = $this->loader->loadBatch([$itemId], $context);
        $comboRows = $selection->combinationId > 0
            ? $this->loader->loadCombinationPriceRows($itemId, $selection->combinationId, $context)
            : [];

        $comboPriceRows = [];
        $comboAdjustRows = [];

        foreach ($comboRows as $row) {
            if (($row->mode ?? 'price') === 'adjust') {
                $comboAdjustRows[] = $row;
            } else {
                $comboPriceRows[] = $row;
            }
        }

        $itemPrices = $data->getPricesFor($itemId);
        $baseRows = $comboPriceRows !== [] ? $comboPriceRows : $itemPrices;
        $adjustRow = $comboPriceRows === [] ? $this->calculator->selectBaseRow($comboAdjustRows, $quantity, $context->groupDistances()) : null;
        $optionDeltas = $this->loader->loadOptionDeltas($itemId, $selection->optionValueIds);

        $steps = [];
        $this->resolveVariantNet($baseRows, $adjustRow, $optionDeltas, $selection->contributions, $data->getTaxesFor($itemId), $quantity, $steps, groupDistances: $context->groupDistances());

        return $steps;
    }

    /**
     * The unit of a winning base row, exactly: its value, or its sale. A sale on
     * the net ('start') is the net itself; a sale typed as a final price on a
     * taxed item is the gross it states, and its net is that gross divided back
     * through the tax chain, held at eight places.
     *
     * @param object|null $row The selected price row, or null.
     * @param bool $taxed Whether the item's tax chain can carry a gross.
     * @param array[] $factors The exact tax chain, from PriceComputationEngine::taxFactors().
     *
     * @return array{0: int, 1: int|null} [the net unit, the gross unit the row states or null], as counts of the smallest unit.
     * @since  2.0.0
     */
    protected function rowUnit(?object $row, bool $taxed, array $factors): array
    {
        if ($row === null) {
            return [0, null];
        }

        $sale = $row->override_value ?? null;

        if ($sale !== null && $sale !== '') {
            $sale = PriceComputationEngine::unitCount(unit: PriceComputationEngine::catalogueUnit(value: $sale));

            return (($row->override_type ?? 'start') === 'final' && $taxed)
                ? PriceComputationEngine::applyVariantAdjust(net: 0, gross: $sale, operation: null, amount: 0, onGross: true, factors: $factors)
                : [$sale, null];
        }

        return [PriceComputationEngine::unitCount(unit: PriceComputationEngine::catalogueUnit(value: $row->value ?? null)), null];
    }

    /**
     * Expand a quantity argument into a per-product-id quantity map.
     *
     * @param int[] $productIds The product ids.
     * @param int|int[] $quantities A single quantity for all ids, or an existing per-id map (returned as-is).
     *
     * @return array The quantity map keyed by product id.
     * @since  1.0.0
     */
    protected function normalizeQuantities(array $productIds, $quantities): array
    {
        if (is_array($quantities)) {
            return $quantities;
        }

        return array_fill_keys($productIds, $quantities);
    }
}
