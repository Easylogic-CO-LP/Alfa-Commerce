<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Alfa Commerce
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Site\Service\Pricing;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
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

        $db = $this->db;

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->qn('#__alfa_items_prices'))
            ->whereIn($db->qn('item_id'), $productIds)
            // Item-level rows ONLY: combination rows (combination_id set) are
            // the variants' own prices and must never win as the item's base
            // price. The combination-aware stage resolves them separately.
            ->where($db->qn('combination_id') . ' IS NULL')
            ->where('state = 1')
            ->where($this->dateHelper->getActiveCondition('publish_up', 'publish_down', $context->getReferenceDate()));

        // Apply currency / usergroup / user / location filters from the visitor context.
        // This was previously commented out; it MUST be active so the engine receives
        // only the price rows relevant to this specific visitor / index slot.
        $this->applyPriceContextFilters($query, $context);

        // Most specific prices first (for selectBasePrice in the engine)
        $query->order([
            'currency_id DESC',
            'usergroup_id DESC',
            'user_id DESC',
            'country_id DESC',
            'quantity_start ASC',
        ]);

        $db->setQuery($query);
        $results = $db->loadObjectList();

        return $this->groupByProductId($results, 'item_id');
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

        // Per-request memo (static, like loadBatch): the product page resolves the
        // same default-variant combination price from several render paths. Cache by
        // item + combination + visitor context; the rows are read-only, so sharing
        // them is safe.
        static $cache = [];
        $cacheKey = $itemId . '|' . $combinationId . '|' . $context->toCacheKey();

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $db = $this->db;
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->qn('#__alfa_items_prices'))
            ->where($db->qn('item_id') . ' = ' . $itemId)
            ->where($db->qn('combination_id') . ' = ' . $combinationId)
            ->where('state = 1')
            ->where($this->dateHelper->getActiveCondition('publish_up', 'publish_down', $context->getReferenceDate()));

        $this->applyPriceContextFilters($query, $context);

        $query->order([
            'currency_id DESC',
            'usergroup_id DESC',
            'user_id DESC',
            'country_id DESC',
            'quantity_start ASC',
        ]);

        $db->setQuery($query);

        return $cache[$cacheKey] = $db->loadObjectList() ?: [];
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

        $db = $this->db;
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->qn('#__alfa_items_prices'))
            ->whereIn($db->qn('item_id'), $itemIds)
            ->whereIn($db->qn('combination_id'), $combinationIds)
            ->where('state = 1')
            ->where($this->dateHelper->getActiveCondition('publish_up', 'publish_down', $context->getReferenceDate()));

        $this->applyPriceContextFilters($query, $context);

        $query->order([
            'currency_id DESC',
            'usergroup_id DESC',
            'user_id DESC',
            'country_id DESC',
            'quantity_start ASC',
        ]);

        $db->setQuery($query);

        return $this->groupByProductId($db->loadObjectList() ?: [], 'item_id');
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

    /**
     * Apply context-based filters to a #__alfa_items_prices query.
     *
     * Every dimension uses the "0 = applies to all" convention so a price
     * row with currency_id=0 is returned for every currency, and so on.
     *
     * NOTE: All properties of PriceContext are private: always use the
     * public getters (getCurrencyId(), getUserGroups() etc.).
     * Direct property access ($context->currencyId) is a PHP fatal error.
     *
     * @param mixed $query Joomla database query object
     * @param PriceContext $context Pricing context
     * @since  1.0.0
     */
    protected function applyPriceContextFilters($query, PriceContext $context): void
    {
        // Currency
        // 0 in the DB means "applies to any currency".
        $currencyId = $context->getCurrencyId();

        if ($currencyId > 0) {
            $query->where('(currency_id = 0 OR currency_id = ' . (int) $currencyId . ')');
        } else {
            // No specific currency in context: return only universal prices
            $query->where('currency_id = 0');
        }

        // Usergroups
        // getUserGroups() always includes 0 (the "any group" sentinel), so
        // using IN() here naturally matches both public and group-specific rows.
        $query->where('usergroup_id IN (' . $context->getUserGroupsSql() . ')');

        // User
        // 0 in the DB means "applies to any user" (public / not user-specific).
        $userId = $context->getUserId();

        if ($userId !== null && $userId > 0) {
            $query->where('(user_id = 0 OR user_id = ' . (int) $userId . ')');
        } else {
            // Guest or index context: return only universal (non-user-specific) rows
            $query->where('user_id = 0');
        }

        // Location / place
        // country_id in #__alfa_items_prices stores a #__alfa_places id.
        // 0 means "applies to any place".
        $locationId = $context->getLocationId();

        if ($locationId !== null && $locationId > 0) {
            $query->where('(country_id = 0 OR country_id = ' . (int) $locationId . ')');
        } else {
            // Unknown location: return only universal (non-place-specific) rows
            $query->where('country_id = 0');
        }
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
     *                          or the visitor's detected location
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

        // Visitor's location id; 0 means unknown / not set
        $locationId = (int) ($context->getLocationId() ?? 0);

        // Visitor's user id; 0 means guest / anonymous
        $userId = (int) ($context->getUserId() ?? 0);

        $query = $db->getQuery(true)
            // `d.ordering` IS SELECTED BECAUSE IT IS ORDERED BY.
            // A DISTINCT projection that sorts on a column it does not select is
            // rejected outright under ONLY_FULL_GROUP_BY, which is MySQL's default
            // from 5.7 on; it survives here only because this deployment does not
            // set that mode. Adding the column costs nothing and removes a
            // portability trap that would surface as a fatal on a customer's host.
            ->select('DISTINCT d.id, d.ordering, d.value, d.is_amount, d.behavior, d.apply_before_tax, d.tag_list_show, d.tag_list_slot, d.tag_item_show, d.tag_item_slot, d.tag_bg, d.tag_color, d.tag_class, ic.item_id')
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
            // or the discount targets the visitor's specific location.
            ->join('LEFT', '#__alfa_discount_places AS dp ON dp.discount_id = d.id')
            ->where('(dp.discount_id IS NULL OR dp.place_id = 0 OR dp.place_id = ' . $locationId . ')')

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
        $results = $db->loadObjectList();

        return $this->groupByProductId($results, 'item_id');
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

        // Visitor's location id; 0 means unknown / not set
        $locationId = (int) ($context->getLocationId() ?? 0);

        // Visitor's user id; 0 means guest / anonymous
        $userId = (int) ($context->getUserId() ?? 0);

        $query = $db->getQuery(true)
            // Same reasoning as the discount query above: the sort column is
            // selected, and the id breaks the tie.
            ->select('DISTINCT t.id, t.ordering, t.value, t.behavior, ic.item_id')
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

            // Scope 3: Place
            ->join('LEFT', '#__alfa_tax_places AS tp ON tp.tax_id = t.id')
            ->where('(tp.tax_id IS NULL OR tp.place_id = 0 OR tp.place_id = ' . $locationId . ')')

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
        $results = $db->loadObjectList();

        return $this->groupByProductId($results, 'item_id');
    }

    // Helpers

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
