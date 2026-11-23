<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;
use Joomla\CMS\Factory;

/**
 * Price Data Loader
 *
 * Loads prices, discounts, and taxes for products with automatic batching.
 * Handles 1 to 100,000+ products efficiently by chunking large requests.
 *
 * What this class does
 * Given a list of product ids and a PriceContext (who is asking, from where,
 * in which currency), it queries the database and returns:
 *
 *   - Prices   - the raw unit price rows for those items, filtered to the
 *                visitor's currency, usergroup, user, and place.
 *
 *   - Discounts - all active discounts that apply to those items based on
 *                 category, manufacturer, place, usergroup, and user scope.
 *
 *   - Taxes     - all active taxes that apply to those items based on
 *                 category, manufacturer, place, usergroup, and user scope.
 *
 * HOW SCOPE MATCHING WORKS (discounts and taxes)
 * Each discount/tax has five scope tables (categories, manufacturers, places,
 * usergroups, users). A row with value 0 in any scope table means "applies
 * to ALL" for that dimension. NULL means no restriction row was inserted.
 *
 * For each scope dimension the filter is:
 *   "no restriction row exists  OR  value = 0 (all)  OR  value matches visitor"
 *
 * All five dimensions must match simultaneously for a discount/tax to apply.
 *
 * Place: a rule scoped to a place reaches every place inside it (2.2.1): the
 * visitor's place and every place above it (PlaceTree::placesFor()) are
 * matched, and the matched rules are put in one order (RulePrecedence). A
 * product's price rows stay exact to their place.
 *
 * Usergroup filter: important
 * Discounts and taxes are filtered by the VISITOR's Joomla usergroups from
 * PriceContext, NOT by #__alfa_items_usergroups (which controls item access).
 * Using the item-access table for this filter is a common mistake that causes
 * discounts to never apply (or to apply to the wrong items).
 *
 * @package    Alfa Commerce
 * @since  1.0.0
 */
class PriceDataLoader
{
    /**
     * Optimal chunk size for MySQL JOIN queries.
     * Balances query complexity vs. DB round-trips.
     */
    public const CHUNK_SIZE = 200;

    /** @var \Joomla\Database\DatabaseInterface */
    protected $db;

    /** @var ActiveDateCondition */
    protected $dateHelper;

    /**
     * The rows loadBatch() loaded in this process, by product set and pricing context.
     *
     * @var array<string, array{0: array, 1: array, 2: array}>
     * @since  2.0.0
     */
    private static array $loaded = [];

    /**
     * The published price rows of each product read in this process, its own rows and its combinations' alike, in id
     * order: a product's rows are read once and matched here to each pricing context, so pricing one product in many
     * contexts (the price index writes every context of a product a save touched) reads them once. Forgotten with
     * forgetLoaded(), which every writer of the rows calls.
     *
     * @var array<int, list<object>>
     * @since  2.3.0
     */
    private static array $rowsOf = [];

    /**
     * The product as its item form holds it, or null: a loader made by
     * forUnsavedItem() answers for that product alone, under its id.
     *
     * @since  2.1.0
     */
    private ?UnsavedItem $unsaved = null;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->dateHelper = new ActiveDateCondition();
    }

    /**
     * A loader for a product as its item form holds it before it is saved.
     *
     * It runs the same queries as for a saved product, with the product's
     * category and manufacturer links taken from the form in place of the two
     * link tables, so the taxes and discounts that apply are matched to its
     * memberships exactly as they will be once it is saved. Its price rows are
     * read as stored: a product not saved yet (id 0) has none. Ask it for the
     * product's id ($item->id()).
     *
     * @param UnsavedItem $item The product as its item form holds it.
     *
     * @since  2.1.0
     */
    public static function forUnsavedItem(UnsavedItem $item): self
    {
        $loader = new self();
        $loader->unsaved = $item;

        return $loader;
    }

    /**
     * Load pricing data for multiple products.
     *
     * Automatically chunks large batches for optimal performance.
     *
     * @param array $productIds Product IDs to load
     * @param PriceContext $context Pricing context (currency, user, location)
     * @since  1.0.0
     */
    public function loadBatch(array $productIds, PriceContext $context): PriceDataCollection
    {
        if (empty($productIds)) {
            return new PriceDataCollection();
        }

        // Request-level memo keyed by the exact product set + the FULL pricing
        // context (PriceContext::toCacheKey = currency/user/groups/location/date),
        // so it is correct for the same visitor within one request: the same item
        // is priced in several render passes (product page + cart). Sharing the
        // cached rows is safe: the engine ONLY READS them (it builds new AppliedTax/
        // AppliedDiscount/Money objects) and the single usort acts on a copy-on-write
        // copy: verified no `$row->x = ...` anywhere in the Pricing service. So no
        // clone (it would copy the whole structure on every hit for no reason); if a
        // future change starts mutating a loaded row, deep-clone on return instead.
        // Whoever changes the rows and then prices again in the same process forgets
        // them first (forgetLoaded()).
        $ids = array_map('intval', $productIds);
        sort($ids);
        $cacheKey = implode(',', $ids) . '|' . $context->toCacheKey()
            . ($this->unsaved === null ? '' : '|' . $this->unsaved->cacheKey());

        if (isset(self::$loaded[$cacheKey])) {
            [$prices, $discounts, $taxes] = self::$loaded[$cacheKey];

            return new PriceDataCollection($prices, $discounts, $taxes);
        }

        $prices = $this->executeBatched(
            fn ($chunk) => $this->fetchPrices($chunk, $context),
            $productIds,
        );

        $discounts = $this->executeBatched(
            fn ($chunk) => $this->fetchDiscounts($chunk, $context),
            $productIds,
        );

        $taxes = $this->executeBatched(
            fn ($chunk) => $this->fetchTaxes($chunk, $context),
            $productIds,
        );

        self::$loaded[$cacheKey] = [$prices, $discounts, $taxes];

        return new PriceDataCollection($prices, $discounts, $taxes);
    }

    /**
     * Forget every price, discount and tax row loadBatch() loaded in this process.
     *
     * The next loadBatch() reads the database again. For a caller that writes
     * prices down for later readers, such as the price index, which must start
     * from what the database holds now and not from rows an earlier pricing in
     * the same process loaded before they were changed.
     *
     *
     * @since  2.0.0
     */
    public static function forgetLoaded(): void
    {
        self::$loaded = [];
        self::$rowsOf = [];
    }

    /**
     * The published price rows of the products, read once a process for each product (see self::$rowsOf).
     *
     * @param int[] $itemIds The products.
     *
     * @return array<int, list<object>> The rows by product, in id order.
     *
     * @since  2.3.0
     */
    private function publishedRowsOf(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        $missing = array_values(array_filter($itemIds, static fn (int $id): bool => $id > 0 && !isset(self::$rowsOf[$id])));

        foreach (array_chunk($missing, self::CHUNK_SIZE) as $chunk) {
            $rows = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select('*')
                    ->from($this->db->qn('#__alfa_items_prices'))
                    ->whereIn($this->db->qn('item_id'), $chunk)
                    ->where($this->db->qn('state') . ' = 1')
                    ->order($this->db->qn('id') . ' ASC'),
            )->loadObjectList() ?: [];

            foreach ($chunk as $id) {
                self::$rowsOf[$id] = [];
            }

            foreach ($rows as $row) {
                self::$rowsOf[(int) $row->item_id][] = $row;
            }
        }

        $rowsOf = [];

        foreach ($itemIds as $id) {
            $rowsOf[$id] = self::$rowsOf[$id] ?? [];
        }

        return $rowsOf;
    }

    /**
     * The rows of one product that apply in a context, most specific first: its own rows (no combination) or the rows
     * of the combinations named; in their period at the context's moment (no start, or the empty date, has begun; no
     * end, or the empty date, has not ended); of the context's currency or of any (0); of one of its groups, the "any
     * group" 0 among them; of its user or of any (0), only of any for a guest or the index; of its place or of any (0),
     * only of any when the place is not known. A row whose dimension is empty (NULL) applies nowhere. Ordered by
     * currency, group, user and place, each the more specific first, then the lowest quantity first.
     *
     * @param list<object> $rows The product's published rows.
     * @param PriceContext $context The context.
     * @param int[]|null $combinationIds The combinations whose rows are asked, or null for the product's own rows.
     *
     * @return list<object> The rows that apply.
     *
     * @since  2.3.0
     */
    private function applying(array $rows, PriceContext $context, ?array $combinationIds): array
    {
        $moment = $context->getReferenceDate() ?? ActiveDateCondition::now();
        $none = '0000-00-00 00:00:00';
        $currencyId = $context->getCurrencyId();
        $currencies = $currencyId > 0 ? [0, $currencyId] : [0];
        $groups = array_map('intval', explode(',', $context->getUserGroupsSql()));
        $userId = $context->getUserId();
        $users = $userId !== null && $userId > 0 ? [0, $userId] : [0];
        $placeId = $context->getLocationId();
        $places = $placeId !== null && $placeId > 0 ? [0, $placeId] : [0];
        $combinations = $combinationIds === null ? null : array_flip(array_map('intval', $combinationIds));
        $in = static fn (mixed $value, array $allowed): bool => $value !== null && \in_array((int) $value, $allowed, true);
        $applying = [];

        foreach ($rows as $row) {
            $own = $row->combination_id === null;

            if ($combinations === null ? !$own : ($own || !isset($combinations[(int) $row->combination_id]))) {
                continue;
            }

            // A start that has not come, or an end that has passed, takes the row out; none, or the empty date, does not.
            $up = $row->publish_up;
            $down = $row->publish_down;

            if (($up !== null && $up !== $none && $moment < (string) $up) || ($down !== null && $down !== $none && $moment > (string) $down)) {
                continue;
            }

            if ($in($row->currency_id, $currencies) && $in($row->usergroup_id, $groups) && $in($row->user_id, $users) && $in($row->country_id, $places)) {
                $applying[] = $row;
            }
        }

        // No quantity start comes before any, as the database sorts an empty value first.
        usort($applying, static fn (object $a, object $b): int => ([(int) $b->currency_id, (int) $b->usergroup_id, (int) $b->user_id, (int) $b->country_id]
            <=> [(int) $a->currency_id, (int) $a->usergroup_id, (int) $a->user_id, (int) $a->country_id])
            ?: ([$a->quantity_start !== null, (float) $a->quantity_start] <=> [$b->quantity_start !== null, (float) $b->quantity_start]));

        return $applying;
    }

    /**
     * Execute fetch function with automatic chunking.
     *
     * Splits large batches into optimal chunks and merges results.
     * For small batches (<= CHUNK_SIZE), executes directly without chunking.
     *
     * @param callable $fetchFunction Function to execute per chunk
     * @param array $productIds All product IDs
     *
     * @return array Merged results grouped by product ID
     * @since  1.0.0
     */
    protected function executeBatched(callable $fetchFunction, array $productIds): array
    {
        if (count($productIds) <= self::CHUNK_SIZE) {
            return $fetchFunction($productIds);
        }

        $chunks = array_chunk($productIds, self::CHUNK_SIZE);
        $allResults = [];

        foreach ($chunks as $chunk) {
            $chunkResults = $fetchFunction($chunk);

            foreach ($chunkResults as $productId => $items) {
                if (!isset($allResults[$productId])) {
                    $allResults[$productId] = [];
                }

                $allResults[$productId] = array_merge($allResults[$productId], $items);
            }
        }

        return $allResults;
    }

    // Price fetching

    /**
     * Fetch prices for the given products, filtered by the visitor's context.
     *
     * Filtering rules for #__alfa_items_prices:
     *   currency_id  - 0 = any currency, or must match visitor's currency
     *   usergroup_id - 0 = any group,    or must be in visitor's groups
     *   user_id      - 0 = any user,     or must match visitor's user id
     *   country_id   - 0 = any place,    or must match visitor's location
     *
     * Prices are ordered most-specific first so PriceComputationEngine picks
     * the best-matching row (currency > usergroup > user > place > quantity).
     *
     * @param array $productIds Product IDs
     * @param PriceContext $context Pricing context
     *
     * @return array Prices grouped by product ID
     * @since  1.0.0
     */
    protected function fetchPrices(array $productIds, PriceContext $context): array
    {
        // A product that has not been saved yet has no stored price row.
        if ($this->unsaved !== null && $this->unsaved->id() <= 0) {
            return [];
        }

        // Item-level rows only: combination rows are the variants' own prices and must never win as the item's base
        // price; the combination-aware stage resolves them separately. Most specific first, for the engine.
        $grouped = [];

        foreach ($this->publishedRowsOf(itemIds: $productIds) as $itemId => $rows) {
            $applying = $this->applying(rows: $rows, context: $context, combinationIds: null);

            if ($applying !== []) {
                $grouped[$itemId] = $applying;
            }
        }

        return $grouped;
    }

    /**
     * Fetch a single combination's price rows (both 'price' and 'adjust' modes),
     * filtered by the visitor's context exactly like the item base rows.
     *
     * A combination row carries its own context/quantity dimensions and override,
     * so the same selectBaseRow() logic picks the best-matching one. Ordered
     * most-specific first to match fetchPrices().
     *
     * @param int $itemId The item id.
     * @param int $combinationId The chosen combination id.
     * @param PriceContext $context The visitor pricing context.
     *
     * @return object[] The matching combination price rows (may be empty).
     *
     * @since  2.0.0
     */
    public function loadCombinationPriceRows(int $itemId, int $combinationId, PriceContext $context): array
    {
        if ($itemId <= 0 || $combinationId <= 0) {
            return [];
        }

        return $this->applying(rows: $this->publishedRowsOf(itemIds: [$itemId])[$itemId], context: $context, combinationIds: [$combinationId]);
    }

    /**
     * Batch-load combination price rows for many items at once: the batched twin
     * of loadCombinationPriceRows(), used by PriceCalculator::calculateBatch() so a
     * whole catalog page resolves its variant prices in ONE query instead of one
     * per item. Combination ids are globally unique, so a single IN() on
     * combination_id (scoped by item_id for safety) returns every row; results are
     * grouped by item id and ordered most-specific-first like fetchPrices().
     *
     * @param array $itemToCombination Map of itemId => combinationId.
     * @param PriceContext $context The visitor pricing context.
     *
     * @return array Combination price rows grouped by item id (itemId => object[]).
     *
     * @since  2.0.0
     */
    public function loadCombinationPriceRowsBatch(array $itemToCombination, PriceContext $context): array
    {
        $itemIds = array_values(array_filter(array_map('intval', array_keys($itemToCombination))));
        $combinationIds = array_values(array_filter(array_map('intval', $itemToCombination)));

        if ($itemIds === [] || $combinationIds === []) {
            return [];
        }

        $grouped = [];

        foreach ($this->publishedRowsOf(itemIds: $itemIds) as $itemId => $rows) {
            $applying = $this->applying(rows: $rows, context: $context, combinationIds: $combinationIds);

            if ($applying !== []) {
                $grouped[$itemId] = $applying;
            }
        }

        return $grouped;
    }

    /**
     * The price rows of many combinations in one statement (the price index prices every published combination
     * of a chunk from one load), under the same terms as {@see self::loadCombinationPriceRowsBatch()}, grouped by
     * combination.
     *
     * @param int[] $itemIds The products.
     * @param int[] $combinationIds The combinations, of those products.
     * @param PriceContext $context Whose terms the rows are read under.
     *
     * @return array<int, object[]> The rows by combination id.
     *
     * @since  2.2.2
     */
    public function loadCombinationPriceRowsFor(array $itemIds, array $combinationIds, PriceContext $context): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $combinationIds = array_values(array_unique(array_filter(array_map('intval', $combinationIds))));

        if ($itemIds === [] || $combinationIds === []) {
            return [];
        }

        $byCombination = [];

        foreach ($this->publishedRowsOf(itemIds: $itemIds) as $rows) {
            foreach ($this->applying(rows: $rows, context: $context, combinationIds: $combinationIds) as $row) {
                $byCombination[(int) $row->combination_id][] = $row;
            }
        }

        return $byCombination;
    }

    /**
     * Fetch the price deltas of the selected option values (free options, those
     * with no operation, are skipped). Each delta carries operation, amount and
     * apply_on (net 'start' / gross 'final'; NULL = follow the global default).
     *
     * @param int $itemId The item id.
     * @param int[] $valueIds The selected option value ids.
     *
     * @return object[] The option delta rows (may be empty).
     *
     * @since  2.0.0
     */
    public function loadOptionDeltas(int $itemId, array $valueIds): array
    {
        $valueIds = array_values(array_filter(array_map('intval', $valueIds)));

        if ($itemId <= 0 || $valueIds === []) {
            return [];
        }

        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->qn(['value_id', 'operation', 'amount', 'apply_on']))
            ->from($db->qn('#__alfa_item_option_values'))
            ->where($db->qn('item_id') . ' = ' . $itemId)
            ->whereIn($db->qn('value_id'), $valueIds)
            ->where($db->qn('operation') . ' IS NOT NULL');

        $db->setQuery($query);

        return $db->loadObjectList() ?: [];
    }

    // Discount fetching

    /**
     * Fetch active discounts for the given products, matched to the visitor's context.
     *
     * A discount applies to a product when ALL five scope dimensions match:
     *
     *   1. Category scope    - discount targets all categories (category_id=0/NULL)
     *                          or a category this product belongs to
     *   2. Manufacturer scope - discount targets all manufacturers (0/NULL)
     *                          or one of this product's manufacturers; a
     *                          product with no manufacturer gets only the rules
     *                          that target all manufacturers
     *   3. Place scope        - discount targets all places (place_id=0/NULL)
     *                          or the visitor's place or a place above it
     *                          (2.2.1; RulePrecedence orders what matched)
     *   4. Usergroup scope    - discount targets all groups (usergroup_id=0/NULL)
     *                          or one of the VISITOR'S Joomla usergroups
     *   5. User scope         - discount targets all users (user_id=0/NULL)
     *                          or the currently logged-in user specifically
     *
     * IMPORTANT: usergroup filter uses VISITOR context, NOT item access tables.
     * #__alfa_items_usergroups controls which groups can VIEW an item;
     * it has nothing to do with which groups qualify for a discount.
     *
     * @param array $productIds Product IDs
     * @param PriceContext $context Pricing context
     *
     * @return array Discounts grouped by product ID
     * @since  1.0.0
     */
    protected function fetchDiscounts(array $productIds, PriceContext $context): array
    {
        $db = $this->db;
        $productIdList = implode(',', array_map('intval', $productIds));

        // Visitor's usergroup ids as SQL IN() list, e.g. "0" or "0,8,25"
        // getUserGroupsSql() always includes 0 (public), so public discounts always match.
        $userGroupsSql = $context->getUserGroupsSql();

        // The visitor's place and every place above it, nearest first; empty for an unknown place.
        $chain = PlaceTree::placesFor(placeId: (int) ($context->getLocationId() ?? 0));

        // Visitor's user id; 0 means guest / anonymous
        $userId = (int) ($context->getUserId() ?? 0);

        $query = $db->getQuery(true)
            // `d.ordering` IS SELECTED BECAUSE IT IS ORDERED BY.
            // A DISTINCT projection that sorts on a column it does not select is
            // rejected outright under ONLY_FULL_GROUP_BY, which is MySQL's default
            // from 5.7 on; it survives here only because this deployment does not
            // set that mode. Adding the column costs nothing and removes a
            // portability trap that would surface as a fatal on a customer's host.
            // The place of the scope row that matched rides along, for the order (RulePrecedence): a rule that matches
            // through two places of the chain comes twice and is kept once, at its nearest place.
            ->select('DISTINCT d.id, d.ordering, d.value, d.is_amount, d.behavior, d.apply_before_tax, d.tag_list_show, d.tag_list_slot, d.tag_item_show, d.tag_item_slot, d.tag_bg, d.tag_color, d.tag_class, ic.item_id, dp.place_id AS matched_place')
            ->from('#__alfa_discounts AS d')

            // Scope 1: Category
            // LEFT JOIN so discounts with NO category rows are still returned.
            // The WHERE clause then allows: no row (NULL), any-category (0),
            // or a category this item actually belongs to.
            ->join('LEFT', '#__alfa_discount_categories AS dc ON dc.discount_id = d.id')
            // INVARIANT: every item has >=1 category, so this INNER JOIN never
            // drops a row. An uncategorised item would be excluded here -> it
            // would receive NO discounts (and, in the tax query, NO taxes: an
            // untaxed sale). A category is required at item save; do not relax
            // this join to compensate for a missing-category data defect.
            ->join('INNER', $this->categoryLinks() . ' AS ic ON ic.item_id IN (' . $productIdList . ')')
            ->where('(dc.discount_id IS NULL OR dc.category_id = 0 OR dc.category_id = ic.category_id)')

            // Scope 2: Manufacturer
            // LEFT JOIN the discount's manufacturer scope and the item's manufacturers.
            // Match when: no manufacturer restriction, applies-to-all (0), or one
            // of the item's manufacturers. An item with no manufacturer matches
            // only the first two, as an item outside a category does.
            ->join('LEFT', '#__alfa_discount_manufacturers AS dm ON dm.discount_id = d.id')
            ->join('LEFT', $this->manufacturerLinks() . ' AS im ON im.item_id = ic.item_id')
            ->where('(dm.discount_id IS NULL OR dm.manufacturer_id = 0 OR dm.manufacturer_id = im.manufacturer_id)')

            // Scope 3: Place
            // Matches when: no place restriction exists, applies-to-all-places (0),
            // or the discount targets the visitor's place or a place above it.
            ->join('LEFT', '#__alfa_discount_places AS dp ON dp.discount_id = d.id')
            ->where(self::placeMatch(alias: 'dp', foreignKey: 'discount_id', chain: $chain))

            // Scope 4: Usergroup
            // Filter by the VISITOR'S Joomla usergroups from PriceContext.
            // getUserGroupsSql() returns e.g. "0,8,25" so this matches both
            // public discounts (usergroup_id=0) and group-specific ones.
            //
            // DO NOT JOIN #__alfa_items_usergroups here: that table controls
            // which groups can ACCESS the item, not who qualifies for discounts.
            ->join('LEFT', '#__alfa_discount_usergroups AS du ON du.discount_id = d.id')
            ->where('(du.discount_id IS NULL OR du.usergroup_id = 0 OR du.usergroup_id IN (' . $userGroupsSql . '))')

            // Scope 5: User
            // Filter by the visitor's specific user id from PriceContext.
            // user_id=0 in the scope table means "applies to all users".
            ->join('LEFT', '#__alfa_discount_users AS dgu ON dgu.discount_id = d.id')
            ->where('(dgu.discount_id IS NULL OR dgu.user_id = 0 OR dgu.user_id = ' . $userId . ')')

            ->where($this->dateHelper->getActiveCondition('d.publish_up', 'd.publish_down', $context->getReferenceDate()))
            ->where('d.state = 1')
            // ORDER IS PART OF THE PRICE, so it cannot be left to the database.
            // `ordering` is `DEFAULT 0` and nothing ever assigns it, so on a real
            // shop every rule ties and the surviving order is whatever the engine
            // happens to return. That decides which "Only this" rule wins the whole
            // line, and the order a mixed fixed/percentage sequential chain
            // compounds in, both of which move money. The id tiebreak makes the
            // answer the same on every host and every run.
            ->order('d.ordering ASC, d.id ASC');

        // Resolve the discount labels in the visitor's language (they live in the
        // per-language tables, not on the main table): the internal name plus the
        // optional customer-facing name_customer. The join emits '' for a column
        // that does not exist yet, so a shop that has not saved a discount since the
        // upgrade still loads (name_customer then falls back to name downstream).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'd',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_discounts',
            langPrimaryColumn: 'id_discount',
            fields:            ['name', 'name_customer'],
        );

        $db->setQuery($query);

        return $this->inPrecedence(grouped: $this->groupByProductId($db->loadObjectList() ?: [], 'item_id'), chain: $chain);
    }

    // Tax fetching

    /**
     * Fetch active taxes for the given products, matched to the visitor's context.
     *
     * Scope matching follows the same five-dimension logic as fetchDiscounts().
     * See that method's doc block for the full explanation.
     *
     * @param array $productIds Product IDs
     * @param PriceContext $context Pricing context
     *
     * @return array Taxes grouped by product ID
     * @since  1.0.0
     */
    protected function fetchTaxes(array $productIds, PriceContext $context): array
    {
        $db = $this->db;
        $productIdList = implode(',', array_map('intval', $productIds));

        // Visitor's usergroup ids as SQL IN() list: always includes 0
        $userGroupsSql = $context->getUserGroupsSql();

        // The visitor's place and every place above it, nearest first; empty for an unknown place.
        $chain = PlaceTree::placesFor(placeId: (int) ($context->getLocationId() ?? 0));

        // Visitor's user id; 0 means guest / anonymous
        $userId = (int) ($context->getUserId() ?? 0);

        $query = $db->getQuery(true)
            // Same reasoning as the discount query above: the sort column is
            // selected, and the id breaks the tie.
            // The matched place rides along for the order, as for discounts.
            ->select('DISTINCT t.id, t.ordering, t.value, t.behavior, ic.item_id, tp.place_id AS matched_place')
            ->from('#__alfa_taxes AS t')

            // Scope 1: Category
            ->join('LEFT', '#__alfa_tax_categories AS tc ON tc.tax_id = t.id')
            // INVARIANT: every item has >=1 category (see the discount query).
            // An uncategorised item is dropped by this INNER JOIN and would be
            // sold with NO tax; a category is required at item save.
            ->join('INNER', $this->categoryLinks() . ' AS ic ON ic.item_id IN (' . $productIdList . ')')
            ->where('(tc.tax_id IS NULL OR tc.category_id = 0 OR tc.category_id = ic.category_id)')

            // Scope 2: Manufacturer
            // As for discounts: an item with no manufacturer matches only a tax
            // without a manufacturer restriction or with the applies-to-all (0) row.
            ->join('LEFT', '#__alfa_tax_manufacturers AS tm ON tm.tax_id = t.id')
            ->join('LEFT', $this->manufacturerLinks() . ' AS im ON im.item_id = ic.item_id')
            ->where('(tm.tax_id IS NULL OR tm.manufacturer_id = 0 OR tm.manufacturer_id = im.manufacturer_id)')

            // Scope 3: Place: the visitor's place or a place above it (2.2.1)
            ->join('LEFT', '#__alfa_tax_places AS tp ON tp.tax_id = t.id')
            ->where(self::placeMatch(alias: 'tp', foreignKey: 'tax_id', chain: $chain))

            // Scope 4: Usergroup
            // Filter by VISITOR'S usergroups from PriceContext, not item-access table.
            ->join('LEFT', '#__alfa_tax_usergroups AS tu ON tu.tax_id = t.id')
            ->where('(tu.tax_id IS NULL OR tu.usergroup_id = 0 OR tu.usergroup_id IN (' . $userGroupsSql . '))')

            // Scope 5: User
            ->join('LEFT', '#__alfa_tax_users AS tgu ON tgu.tax_id = t.id')
            ->where('(tgu.tax_id IS NULL OR tgu.user_id = 0 OR tgu.user_id = ' . $userId . ')')

            ->where($this->dateHelper->getActiveCondition('t.publish_up', 't.publish_down', $context->getReferenceDate()))
            ->where('t.state = 1')
            // Tax ordering decides which "only this tax" rule wins and the order
            // sequential rates compound in; a fiscal figure must not depend on
            // what the database happened to return.
            ->order('t.ordering ASC, t.id ASC');

        // Resolve the tax name in the visitor's language (name lives in the
        // per-language tables, not on the main table).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         't',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_taxes',
            langPrimaryColumn: 'id_tax',
            fields:            ['name'],
        );

        $db->setQuery($query);

        return $this->inPrecedence(grouped: $this->groupByProductId($db->loadObjectList() ?: [], 'item_id'), chain: $chain);
    }

    // Helpers

    /**
     * The place condition of a discount's or a tax's scope (2.2.1): no place row, the "every place" row (0), or a row
     * naming the buyer's place or a place above it. The same words as the shipping taxes (ShippingTaxResolver).
     *
     * @param string $alias The scope table's alias.
     * @param string $foreignKey Its rule column.
     * @param int[] $chain The buyer's place and every place above it; empty for an unknown place.
     *
     * @since  2.2.1
     */
    public static function placeMatch(string $alias, string $foreignKey, array $chain): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $chain = array_values(array_filter(array_map('intval', $chain), static fn (int $place): bool => $place > 0));
        $rule = $db->quoteName($alias . '.' . $foreignKey);
        $place = $db->quoteName($alias . '.place_id');

        return '(' . $rule . ' IS NULL OR ' . $place . ' = 0'
            . ($chain === [] ? '' : ' OR ' . $place . ' IN (' . implode(',', $chain) . ')') . ')';
    }

    /**
     * Each product's matched rules once, in the one order they apply in (RulePrecedence).
     *
     * @param array<int|string, object[]> $grouped The matched rows by product.
     * @param int[] $chain The buyer's place and every place above it.
     *
     * @return array<int|string, object[]>
     *
     * @since  2.2.1
     */
    private function inPrecedence(array $grouped, array $chain): array
    {
        foreach ($grouped as $productId => $rows) {
            $grouped[$productId] = RulePrecedence::order(rows: $rows, chain: $chain);
        }

        return $grouped;
    }

    /**
     * The product-to-category links the tax and discount queries match against:
     * the link table, or for a product as its form holds it the form's
     * categories as rows of the same shape (item_id, category_id).
     *
     * @since  2.1.0
     */
    protected function categoryLinks(): string
    {
        return $this->unsaved === null
            ? '#__alfa_items_categories'
            : $this->unsavedLinks(column: 'category_id', ids: $this->unsaved->categoryIds());
    }

    /**
     * The product-to-manufacturer links the tax and discount queries match
     * against: the link table, or for a product as its form holds it the form's
     * manufacturers as rows of the same shape (item_id, manufacturer_id).
     *
     * @since  2.1.0
     */
    protected function manufacturerLinks(): string
    {
        return $this->unsaved === null
            ? '#__alfa_items_manufacturers'
            : $this->unsavedLinks(column: 'manufacturer_id', ids: $this->unsaved->manufacturerIds());
    }

    /**
     * The form's links as a derived table of the link table's shape: one row
     * (the product's id, id) per id, or no row at all.
     *
     * @param string $column The link column (category_id or manufacturer_id).
     * @param int[] $ids The linked ids.
     *
     * @since  2.1.0
     */
    private function unsavedLinks(string $column, array $ids): string
    {
        $item = (int) $this->unsaved?->id();

        if ($ids === []) {
            return '(SELECT ' . $item . ' AS item_id, 0 AS ' . $column . ' FROM DUAL WHERE 1 = 0)';
        }

        $rows = [];

        foreach (array_values($ids) as $index => $id) {
            $rows[] = $index === 0
                ? 'SELECT ' . $item . ' AS item_id, ' . (int) $id . ' AS ' . $column
                : 'SELECT ' . $item . ', ' . (int) $id;
        }

        return '(' . implode(' UNION ALL ', $rows) . ')';
    }

    /**
     * Group database result rows by product ID.
     *
     * Produces an array keyed by product id, where each value is an array
     * of rows for that product. This is the input format expected by
     * PriceComputationEngine::compute() and PriceDataCollection.
     *
     * @param array $results Database result rows (objects)
     * @param string $idField Field name containing the product ID
     *
     * @return array Results grouped by product ID
     * @since  1.0.0
     */
    protected function groupByProductId(array $results, string $idField): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $id = $result->{$idField};

            if (!isset($grouped[$id])) {
                $grouped[$id] = [];
            }

            $grouped[$id][] = $result;
        }

        return $grouped;
    }
}
