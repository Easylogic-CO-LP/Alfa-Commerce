<?php

/**
 * @package    Com_Alfa
 * @subpackage Site.Model
 * @since      1.0.1
 */

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PricingIntent;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Router\Route;

/**
 * ItemsModel — Site-side catalog list model with Architecture-B price filtering.
 *
 * PRICE FILTER (Architecture B)
 * ------------------------------
 * The catalog filter reads from #__alfa_items_price_index via a context-aware
 * subquery restricted to the visitor's currency, place, and usergroups.
 * Column names in the index match the com_alfa config fields exactly.
 *
 * AVAILABLE FILTER STATES
 * -----------------------
 *   filter.price_min           float   Minimum final_price (range slider left)
 *   filter.price_max           float   Maximum final_price (range slider right)
 *   filter.on_sale             bool    Only items with discount_amount > 0
 *   filter.discount_amount_min float   Save at least €X  (e.g. >= 10)
 *   filter.discount_percent_min float  Save at least X%  (e.g. >= 20)
 *   filter.category            int[]   Category ids
 *   filter.manufacturer        int[]   Manufacturer ids
 *   filter.search              string  Search string
 *
 * NOTE: has_discount column was removed. discount_amount > 0 is identical
 * and avoids a redundant column that could drift out of sync.
 *
 * LIVE PRICES
 * -----------
 * Displayed prices on listing cards are computed live by PriceCalculator in
 * getItems(). The index is used ONLY for filtering — never for display.
 *
 * @since 1.0.1
 */
class ItemsModel extends UrlListModel
{
    /** @var string Filter form name */
    protected $filterFormName = 'filter_items';

    /** @var array Default list/filter state values */
    protected array $fallbackDefaults = [
        'filter' => [],
        'list' => [
            'fullordering' => 'a.id DESC',
            'limit' => 25,
        ],
    ];

    /** @var int Navigation category id (from URL) */
    protected int $categoryId = 0;

    /** @var PricingIntent|null */
    protected $pricingIntent = null;

    /** @var PriceCalculator|null */
    protected $priceCalculator = null;

    /** @var \Joomla\Registry\Registry|null */
    protected $appSettings = null;

    // =========================================================================
    // Constructor
    // =========================================================================

    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id',       'a.id',
                'name',     'a.name',
                'ordering', 'a.ordering',
                'sku',      'a.sku',
                'stock',    'a.stock',
                'created',  'a.created',
                // Price-index columns — all three must be listed so that
                // parseFullordering() in UrlListModel accepts them as valid
                // ordering targets (it does in_array($orderCol, $filter_fields)).
                'price',               'pf.final_price',
                'pf.discount_amount',  // Sort by biggest absolute saving
                'pf.discount_percent', // Sort by biggest % saving
            ];
        }

        parent::__construct($config);

        $this->appSettings = ComponentHelper::getParams('com_alfa');
        $this->categoryId = $this->app->getInput()->getInt('category_id', 0);
    }

    // =========================================================================
    // State
    // =========================================================================

    /**
     * Populate model state from the request.
     *
     * @param string|null $ordering
     * @param string|null $direction
     */
    protected function populateState($ordering = null, $direction = null): void
    {
        parent::populateState($ordering, $direction);

        $this->setState('filter.category_id', $this->categoryId);
    }

    // =========================================================================
    // Pagination
    // =========================================================================

    /**
     * Get pagination object, appending the navigation category id to page URLs.
     *
     * @return \Joomla\CMS\Pagination\Pagination
     */
    public function getPagination()
    {
        $pagination = parent::getPagination();

        if ($this->categoryId) {
            $pagination->setAdditionalUrlParam('category_id', $this->categoryId);
        }

        return $pagination;
    }

    // =========================================================================
    // Category helpers
    // =========================================================================

    /**
     * Return subcategories of the current navigation category.
     */
    public function getItemsCategories(): array
    {
        $component = $this->app->bootComponent('com_alfa');
        $mvcFactory = $component->getMVCFactory();

        $categoriesModel = $mvcFactory->createModel('Categories', 'Site', ['ignore_request' => true]);
        $categoriesModel->getState('list.ordering');
        $categoriesModel->setState('filter.search', '');
        $categoriesModel->setState('list.limit', 0);
        $categoriesModel->setState('filter.parent_id', $this->categoryId);

        $items = $categoriesModel->getItems();

        if (!empty($items)) {
            foreach ($items as $category) {
                $category->link = $this->buildUrlWithListParams($category->link);
            }
        }

        return $items;
    }

    /**
     * Return the current navigation category, or null when browsing all.
     */
    public function getItemsCategory(): ?object
    {
        if ($this->categoryId === 0) {
            return null;
        }

        $component = $this->app->bootComponent('com_alfa');
        $mvcFactory = $component->getMVCFactory();
        $model = $mvcFactory->createModel('Category', 'Site', ['ignore_request' => true]);
        $category = $model->getItem($this->categoryId);

        if ($category && isset($category->link)) {
            $category->link = $this->buildUrlWithListParams($category->link);
        }

        return $category;
    }

    // =========================================================================
    // Pricing dependency injection
    // =========================================================================

    /**
     * Override the pricing intent (default: PricingIntent::catalog()).
     *
     * @return $this
     */
    public function setPricingIntent(PricingIntent $intent): self
    {
        $this->pricingIntent = $intent;
        return $this;
    }

    /**
     * Override the price calculator (primarily used in unit tests).
     *
     * @return $this
     */
    public function setPriceCalculator(PriceCalculator $calculator): self
    {
        $this->priceCalculator = $calculator;
        return $this;
    }

    /**
     * Lazy-load the price calculator.
     */
    protected function getPriceCalculator(): PriceCalculator
    {
        if ($this->priceCalculator === null) {
            $this->priceCalculator = new PriceCalculator();
        }
        return $this->priceCalculator;
    }

    // =========================================================================
    // Query building
    // =========================================================================

    /**
     * Build the main catalog list query with Architecture-B price filter support.
     *
     * PRICE INDEX SUBQUERY (alias: pf)
     * ---------------------------------
     * Aggregates the index down to one row per item for the current visitor:
     *   MIN(final_price)              → best price this visitor can get
     *   MAX(discount_amount)          → largest absolute saving available
     *   MAX(discount_percent)         → highest % saving available
     *   MIN(base_price_with_tax)      → undiscounted "was" price
     *   MIN(base_price_with_discounts)→ ex-VAT net price
     *   MIN(tax_amount)               → tax amount
     *
     * ALL SUPPORTED FILTER CONDITIONS
     * --------------------------------
     *   filter.price_min            → pf.final_price >= X
     *   filter.price_max            → pf.final_price <= X
     *   filter.on_sale              → pf.discount_amount > 0
     *   filter.discount_amount_min  → pf.discount_amount >= X
     *   filter.discount_percent_min → pf.discount_percent >= X
     *
     * @return \Joomla\Database\QueryInterface
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // ── SELECT ────────────────────────────────────────────────────────────
        $query->select($this->getState(
            'list.select',
            'DISTINCT a.*,
             GROUP_CONCAT(cat.category_id    ORDER BY cat.category_id    ASC) AS category_ids,
             GROUP_CONCAT(man.manufacturer_id ORDER BY man.manufacturer_id ASC) AS manufacturer_ids',
        ));

        // ── Base tables ───────────────────────────────────────────────────────
        $query->from('#__alfa_items AS a')
            ->join('LEFT', '#__alfa_items_categories    AS cat ON a.id = cat.item_id')
            ->join('LEFT', '#__alfa_items_manufacturers AS man ON a.id = man.item_id');

        // ── Architecture B price index subquery JOIN ──────────────────────────
        $this->applyPriceIndexJoin($query);

        // ── Published items only ──────────────────────────────────────────────
        $query->where('a.state = 1');
        $query->where('NOT (a.stock_action = 2 AND a.stock > 0)');

        // ── Search ────────────────────────────────────────────────────────────
        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $query->where('a.name LIKE ' . $db->quote('%' . $db->escape($search, true) . '%'));
            }
        }

        // ── ID filter (wishlist, featured items) ──────────────────────────────
        $filterIds = $this->getState('filter.id', []);
        if (!empty($filterIds)) {
            $query->whereIn('a.id', (array) $filterIds);
        }

        // ── Category filter ───────────────────────────────────────────────────
        $categoryId = $this->getState('filter.category_id');
        $filterCategories = $this->getState('filter.category', []);
        $includeSubcategories = $this->appSettings->get('include_subcategories', 1);

        if (!empty($filterCategories)) {
            $categoryIds = array_map('intval', (array) $filterCategories);

            if ($includeSubcategories) {
                $expanded = $categoryIds;
                foreach ($categoryIds as $catId) {
                    $expanded = array_merge($expanded, CategoryHelper::getDescendantIds($catId));
                }
                $categoryIds = array_unique($expanded);
            }

            $query->whereIn('cat.category_id', $categoryIds);
        } elseif (!empty($categoryId)) {
            if ($includeSubcategories) {
                $descendantIds = CategoryHelper::getDescendantIds((int) $categoryId);
                $descendantIds[] = (int) $categoryId;
                $query->whereIn('cat.category_id', $descendantIds);
            } else {
                $query->where('cat.category_id = ' . (int) $categoryId);
            }
        }

        // ── Manufacturer filter ───────────────────────────────────────────────
        $filterManufacturers = $this->getState('filter.manufacturer', []);
        if (!empty($filterManufacturers)) {
            $query->whereIn('man.manufacturer_id', array_map('intval', (array) $filterManufacturers));
        }

        // ── Price range filter ────────────────────────────────────────────────
        $priceMin = $this->getState('filter.price_min');
        $priceMax = $this->getState('filter.price_max');
        $hasMin = is_numeric($priceMin) && $priceMin !== '' && $priceMin !== null;
        $hasMax = is_numeric($priceMax) && $priceMax !== '' && $priceMax !== null;

        if ($hasMin && $hasMax) {
            // Strict inclusive range; unpriced items (NULL) naturally excluded
            $query->where('pf.final_price >= ' . (float) $priceMin);
            $query->where('pf.final_price <= ' . (float) $priceMax);
        } elseif ($hasMin) {
            $query->where('pf.final_price >= ' . (float) $priceMin);
        } elseif ($hasMax) {
            // Max only — include unpriced items (they cost nothing)
            $query->where('(pf.final_price IS NULL OR pf.final_price <= ' . (float) $priceMax . ')');
        }

        // ── On sale filter ────────────────────────────────────────────────────
        // discount_amount > 0 is the canonical test — no separate has_discount column needed.
        if ($this->getState('filter.on_sale')) {
            $query->where('pf.discount_amount > 0');
        }

        // ── Minimum discount amount filter ────────────────────────────────────
        // "Show items where the visitor saves at least €X."
        $discountAmountMin = $this->getState('filter.discount_amount_min');
        if (is_numeric($discountAmountMin) && (float) $discountAmountMin > 0) {
            $query->where('pf.discount_amount >= ' . (float) $discountAmountMin);
        }

        // ── Minimum discount percentage filter ────────────────────────────────
        // "Show items discounted by at least X%."
        $discountPercentMin = $this->getState('filter.discount_percent_min');
        if (is_numeric($discountPercentMin) && (float) $discountPercentMin > 0) {
            $query->where('pf.discount_percent >= ' . (float) $discountPercentMin);
        }

        // ── Ordering ──────────────────────────────────────────────────────────
        $orderCol = $this->state->get('list.ordering', 'id');
        $orderDirn = $this->state->get('list.direction', 'DESC');

        if ($orderCol && $orderDirn) {
            if ($orderCol === 'price' || $orderCol === 'pf.price') {
                $orderCol = 'pf.final_price';
            }
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }

        $query->group('a.id');

        return $query;
    }

    /**
     * Append the Architecture-B price index subquery JOIN to the main query.
     *
     * The subquery (alias pf) reduces the index to one row per item for the
     * current visitor's currency, place, and usergroups.
     *
     * @param \Joomla\Database\QueryInterface $query Mutated in place
     */
    protected function applyPriceIndexJoin($query): void
    {
        $context = PriceContext::fromSession();

        // Currency: match this currency OR the universal sentinel (0)
        $currencyId = $context->getCurrencyId();
        $currencyCond = $currencyId > 0
            ? "pi.currency_id IN ({$currencyId}, 0)"
            : 'pi.currency_id = 0';

        // Place: match this place OR the universal sentinel (0)
        $placeId = (int) ($context->getLocationId() ?? 0);
        $placeCond = $placeId > 0
            ? "pi.place_id IN ({$placeId}, 0)"
            : 'pi.place_id = 0';

        // Usergroups: match any of the visitor's groups (0 is always included)
        $groups = implode(',', array_unique(array_map('intval', $context->getUserGroups())));
        $groupCond = "pi.usergroup_id IN ({$groups})";

        // Subquery: all column aliases match index column names
        $subquery = "
            (
                SELECT
                    pi.item_id,
                    MIN(pi.final_price)                  AS final_price,
                    MAX(pi.discount_amount)              AS discount_amount,
                    MAX(pi.discount_percent)             AS discount_percent,
                    MIN(pi.base_price_with_tax)          AS base_price_with_tax,
                    MIN(pi.base_price_with_discounts)    AS base_price_with_discounts,
                    MIN(pi.tax_amount)                   AS tax_amount
                FROM #__alfa_items_price_index pi
                WHERE {$currencyCond}
                  AND {$placeCond}
                  AND {$groupCond}
                GROUP BY pi.item_id
            ) AS pf";

        $query->join('LEFT', $subquery . ' ON a.id = pf.item_id');
    }

    // =========================================================================
    // Get items with live prices
    // =========================================================================

    /**
     * Return paginated items with live-computed prices and enriched associations.
     *
     * Prices are always computed LIVE by PriceCalculator — the index is used
     * only for filtering. This guarantees 100% accurate display prices.
     *
     * @return array
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        // ── Batch DB lookups ───────────────────────────────────────────────────
        $allCategoryIds = [];
        $allManufacturerIds = [];

        foreach ($items as $item) {
            $allCategoryIds = array_merge($allCategoryIds, $this->extractIds($item->category_ids));
            $allManufacturerIds = array_merge($allManufacturerIds, $this->extractIds($item->manufacturer_ids));
        }

        $categoriesMapping = $this->getRecordsByIds($allCategoryIds, '#__alfa_categories', ['name']);
        $manufacturersMapping = $this->getRecordsByIds($allManufacturerIds, '#__alfa_manufacturers', ['name']);

        // ── Batch live-price calculation ───────────────────────────────────────
        $settings = ComponentHelper::getParams('com_alfa');
        $intent = $this->pricingIntent ?? PricingIntent::catalog();
        $productIds = [];
        $quantities = [];

        foreach ($items as $item) {
            $productIds[] = $item->id;
            $quantities[$item->id] = $intent->getQuantityForItem($item);
        }

        $prices = $this->getPriceCalculator()->calculate($productIds, $quantities, PriceContext::fromSession());

        // ── Enrich each item ────────────────────────────────────────────────────
        foreach ($items as &$item) {
            $item->price = $prices[$item->id];
            $item->categories = $this->mapIdsToNames($item->category_ids, $categoriesMapping);
            $item->manufacturers = $this->mapIdsToNames($item->manufacturer_ids, $manufacturersMapping);

            if ($item->stock_action == -1) {
                $item->stock_action = $settings->get('stock_action');
                $item->stock_low_message = $settings->get('stock_low_message');
                $item->stock_zero_message = $settings->get('stock_zero_message');
            }
            $item->stock_low_message = $item->stock_low_message ?: $settings->get('stock_low_message');
            $item->stock_zero_message = $item->stock_zero_message ?: $settings->get('stock_zero_message');

            $urlCategoryId = $this->categoryId ?: ($item->id_category_default ?? 0);
            $item->link = Route::_(
                'index.php?option=com_alfa&view=item&id=' . (int) $item->id
                . '&category_id=' . (int) $urlCategoryId,
            );
        }

        return $items;
    }

    // =========================================================================
    // Filter option helpers
    // =========================================================================

    /**
     * Return the min/max final_price for the current non-price filters.
     *
     * Used by the price slider to determine its endpoints. All price-related
     * filter states are excluded so the slider always shows the full extent
     * of prices visible under the other active filters (search, category, etc.).
     *
     * @return array{min: float|null, max: float|null}
     */
    public function getAvailablePriceRange(): array
    {
        $model = $this->createFilterSubModel([
            'filter.price_min',
            'filter.price_max',
            'filter.on_sale',
            'filter.discount_amount_min',
            'filter.discount_percent_min',
        ]);

        $query = $model->getListQuery();
        $query->clear('select')
            ->clear('order')
            ->clear('group')
            ->select([
                'MIN(pf.final_price) AS price_min',
                'MAX(pf.final_price) AS price_max',
            ])
            ->where('pf.final_price IS NOT NULL')
            ->where('pf.final_price > 0');

        $result = $this->getDatabase()->setQuery($query)->loadObject();

        return [
            'min' => $result && $result->price_min !== null ? (float) $result->price_min : null,
            'max' => $result && $result->price_max !== null ? (float) $result->price_max : null,
        ];
    }

    /**
     * Return available manufacturers for the current non-manufacturer filters.
     *
     * @return array Keyed by id: [1 => ['id' => 1, 'name' => 'Nike'], ...]
     */
    public function getAvailableManufacturers(): array
    {
        $model = $this->createFilterSubModel(['filter.manufacturer']);

        $query = $model->getListQuery();
        $query->clear('select')
            ->clear('order')
            ->clear('group')
            ->select('DISTINCT man.manufacturer_id')
            ->where('man.manufacturer_id IS NOT NULL');

        $ids = $this->getDatabase()->setQuery($query)->loadColumn();

        return empty($ids)
            ? []
            : $this->getRecordsByIds(array_map('intval', $ids), '#__alfa_manufacturers', ['id', 'name']);
    }

    /**
     * Return available categories for the current non-category filters.
     *
     * @return array Keyed by id: [2 => ['id' => 2, 'name' => 'Shoes'], ...]
     */
    public function getAvailableCategories(): array
    {
        $model = $this->createFilterSubModel(['filter.category']);

        $query = $model->getListQuery();
        $query->clear('select')
            ->clear('order')
            ->clear('group')
            ->select('DISTINCT cat.category_id')
            ->where('cat.category_id IS NOT NULL');

        $ids = $this->getDatabase()->setQuery($query)->loadColumn();

        return empty($ids)
            ? []
            : $this->getRecordsByIds(array_map('intval', $ids), '#__alfa_categories', ['id', 'name']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Create a sub-model for facet queries with selected states excluded.
     *
     * Copies all current filter states to a fresh ignore_request model, then
     * clears the states in $excludeKeys. Implements "exclude the filter being
     * faceted" for accurate option availability.
     *
     * @param string[] $excludeKeys Filter state keys to exclude
     */
    private function createFilterSubModel(array $excludeKeys): self
    {
        $component = $this->app->bootComponent('com_alfa');

        /** @var self $model */
        $model = $component->getMVCFactory()->createModel('Items', 'Site', ['ignore_request' => true]);

        foreach ([
            'filter.search',
            'filter.category_id',
            'filter.category',
            'filter.manufacturer',
            'filter.price_min',
            'filter.price_max',
            'filter.on_sale',
            'filter.discount_amount_min',
            'filter.discount_percent_min',
        ] as $key) {
            $model->setState($key, $this->getState($key));
        }

        foreach ($excludeKeys as $key) {
            $model->setState($key, null);
        }

        $model->setState('list.limit', 0);
        $model->setState('list.ordering', 'a.id');
        $model->setState('list.direction', 'ASC');

        return $model;
    }

    /**
     * Fetch records from any table by a list of ids in one query (no N+1).
     *
     * @param int[] $ids
     * @param string $table e.g. '#__alfa_manufacturers'
     * @param array $selectFields Columns to select (id auto-added)
     * @param string $idFieldName Name of the id column
     * @return array Keyed by id
     */
    public function getRecordsByIds(
        array $ids,
        string $table,
        array $selectFields = ['name'],
        string $idFieldName = 'id',
    ): array {
        if (empty($ids)) {
            return [];
        }

        $db = $this->getDatabase();

        if (!in_array($idFieldName, $selectFields)) {
            array_unshift($selectFields, $idFieldName);
        }

        $query = $db->getQuery(true)
            ->select(implode(', ', array_map([$db, 'quoteName'], $selectFields)))
            ->from($db->quoteName($table))
            ->whereIn($idFieldName, array_unique($ids));

        return $db->setQuery($query)->loadAssocList($idFieldName);
    }

    /**
     * Map a GROUP_CONCAT id string to enriched records using a pre-loaded mapping.
     *
     * @param string|null $ids Comma-separated ids from GROUP_CONCAT
     * @param array $mapping Pre-loaded data keyed by id
     */
    private function mapIdsToNames(?string $ids, array $mapping): array
    {
        $result = [];
        foreach ($this->extractIds($ids) as $id) {
            $result[$id] = array_merge(['id' => $id], $mapping[$id] ?? []);
        }
        return $result;
    }

    /**
     * Convert a nullable comma-separated id string into an array.
     *
     * @param string|null $ids e.g. "1,2,3" from GROUP_CONCAT
     * @return string[]
     */
    private function extractIds(?string $ids): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }
        return array_map('trim', explode(',', $ids));
    }
}
