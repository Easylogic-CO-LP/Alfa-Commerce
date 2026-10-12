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

namespace Alfa\Component\Alfa\Site\Helper;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Exception;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use stdClass;

defined('_JEXEC') or die;

/**
 * Category Helper
 *
 * Handles all category-related operations with optimized two-level caching:
 * - Level 1: Static cache (per-request, zero overhead)
 * - Level 2: Persistent cache (across requests, when enabled)
 *
 * Product count strategy:
 * - ONE query loads all category->item mappings for the entire tree
 * - PHP walks the tree using array key union ($a + $b) for O(1) dedup
 * - Counts are usergroup-aware and cached per usergroup combination
 * - Memory: ~4MB for 20K products (integer pairs only, not full rows)
 *
 * Depth strategy:
 * - Tree is always built at TREE_MAX_DEPTH internally (one cache entry)
 * - Callers requesting less depth get a trimmed copy (no extra queries)
 * - Avoids duplicate cache entries for depth=3, depth=5, depth=10 etc.
 *
 * @since  1.0.0
 */
class CategoryHelper
{
    /**
     * Internal max depth for all tree builds.
     * Templates control how deep they render, not how deep we cache.
     *
     * @since  1.0.0
     */
    private const TREE_MAX_DEPTH = 10;

    /**
     * Longest time, in seconds, a stored tree WITH product counts is served.
     *
     * Counts follow stock (hide-out-of-stock membership), so a stored count tree
     * is also stamped with the inventory version and rebuilt when it changes. The
     * version alone cannot catch two cases, and this age bounds both: a stock
     * command whose operation id was assigned before the build but committed
     * after it (the version then reads the same), and a stock figure changed
     * outside the inventory service.
     *
     * @since  1.14.0
     */
    private const COUNT_TREE_MAX_AGE = 300;

    /**
     * Static caches (Level 1 - current request only)
     * These are checked FIRST for maximum performance
     */
    private static $categoryPathCache = [];
    private static $categoryDataCache = [];
    private static $categoryTreeCache = [];

    /**
     * Per-parent direct-children rows (Level 1), shared across all tree builds in
     * a request so overlapping builds (full tree / subtree / breadcrumbs) never
     * re-query the same parent.
     */
    private static $childRowsCache = [];

    /**
     * Cache controller (Level 2 - persistent across requests)
     * Lazy initialized only when needed
     */
    private static $cacheController = null;

    /**
     * Component parameters cache
     */
    private static $componentParams = null;

    /**
     * Current user's group IDs (cached per request)
     */

    // Configuration

    /**
     * Get component parameters (cached)
     *
     * @return \Joomla\Registry\Registry
     * @since  1.0.0
     */
    private static function getParams()
    {
        if (self::$componentParams === null) {
            self::$componentParams = ComponentHelper::getParams('com_alfa');
        }

        return self::$componentParams;
    }

    /**
     * Check if component caching is enabled
     *
     * @since  1.0.0
     */
    private static function isCachingEnabled(): bool
    {
        return (bool) self::getParams()->get('enable_cache', 1);
    }

    /**
     * Check if category caching is enabled
     *
     * @since  1.0.0
     */
    private static function isCategoryCachingEnabled(): bool
    {
        if (!self::isCachingEnabled()) {
            return false;
        }

        return (bool) self::getParams()->get('cache_categories', 1);
    }

    /**
     * Get the cache controller instance (lazy initialization)
     *
     * @since  1.0.0
     */
    private static function getCacheController(): ?CallbackController
    {
        if (!self::isCategoryCachingEnabled()) {
            return null;
        }

        if (self::$cacheController === null) {
            try {
                self::$cacheController = Factory::getContainer()
                    ->get(CacheControllerFactoryInterface::class)
                    ->createCacheController('callback', [
                        'defaultgroup' => 'com_alfa.categories',
                        'lifetime' => 525600, // 1 year (manually cleaned in admin)
                        'caching' => true,
                        'storage' => null,
                    ]);
            } catch (Exception $e) {
                return null;
            }
        }

        return self::$cacheController;
    }

    // User context

    /**
     * Stable cache-key suffix for everything that can make one visitor's category
     * data differ from another's, or from the same visitor's a moment later.
     *
     * TWO axes, both mandatory:
     *   - WHO is asking: {@see CatalogAccessPolicy::cacheKey()} (user + sorted
     *     groups, so [2,8] and [8,2] collapse to one entry);
     *   - HOW the shop treats out-of-stock products:
     *     {@see StockVisibilityPolicy::cacheKey()}, because the counts below are
     *     computed THROUGH that predicate.
     *
     * Hashed so the caller gets a fixed-length fragment whatever the two policies
     * decide to put in theirs.
     *
     * @return string Fixed-length key fragment.
     *
     * @since  1.0.0
     */
    private static function getUserGroupCacheKey(): string
    {
        // The stock policy is folded in because counts are computed through its
        // predicate: without it, flipping the shop-wide stock action appears to do
        // nothing until these long-lived caches expire.
        return md5(
            (new CatalogAccessPolicy())->cacheKey()
            . '|' . (new StockVisibilityPolicy())->cacheKey(),
        );
    }

    // VISIBILITY FILTER (shared SQL)

    /**
     * Get the item visibility JOIN conditions
     *
     * Used in LEFT/INNER JOINs where conditions must be in the ON clause.
     * The stock half is delegated to {@see StockVisibilityPolicy} so a count badge
     * can never disagree with the listing it labels: this fragment used to read
     * `NOT (i.stock_action = 2 AND i.stock > 0)`, which is the rule INVERTED (it
     * dropped the products that were in stock and counted the ones that were not),
     * against the physical column instead of the salable projection, blind to the
     * "From configuration" sentinel and NULL-unsafe.
     *
     * @return string SQL fragment
     * @since  1.0.0
     */
    private static function getItemJoinConditions(): string
    {
        return ' AND i.state = 1 AND ' . (new StockVisibilityPolicy())->condition(alias: 'i');
    }

    /**
     * Restrict an item count query to the products this visitor may see.
     *
     * Delegates to {@see CatalogAccessPolicy} so a badge can never disagree with the
     * listing it labels. The previous implementation lived here and diverged in three
     * ways: it ignored the per-USER allowlist entirely, it silently did nothing when
     * the visitor had no groups, and it filtered with a LEFT JOIN, which multiplies a
     * row per matching group and therefore inflates the very COUNT it exists to
     * protect. EXISTS cannot do that.
     *
     * @param \Joomla\Database\QueryInterface $query Query to modify; items aliased `i`.
     *
     *
     * @since  1.0.0
     */
    private static function applyUserGroupFilter($query): void
    {
        (new CatalogAccessPolicy())->applyToItems($query, 'i');
    }

    // Single category

    /**
     * Normalise an optional language tag to the snake-case form used in cache
     * keys and lang-table names, defaulting to the current request language.
     *
     * @param string|null $langTag lang_code/snake tag, or null for current.
     *
     * @return string e.g. "en_gb"
     *
     * @since  1.0.0
     */
    private static function resolveLangTag(?string $langTag): string
    {
        return ($langTag !== null && $langTag !== '')
            ? strtolower(str_replace('-', '_', $langTag))
            : MultilingualHelper::getCurrentLanguageTag();
    }

    /**
     * Load a single category from the database
     *
     * PUBLIC because it's called by cache controller via callback
     *
     * @param int $categoryId Category ID
     * @param string|null $langTag Resolve name/alias in THIS language; null = current.
     *
     * @return array|false Category data or false if not found
     * @since  1.0.0
     */
    public static function loadCategoryFromDatabase(int $categoryId, ?string $langTag = null)
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.parent_id']))
            ->from($db->quoteName('#__alfa_categories', 'a'))
            ->where($db->quoteName('a.id') . ' = :id')
            ->where($db->quoteName('a.state') . ' = 1')
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        // This loader answers "what is category N called?" for ANY id, and its
        // callers hand it unvalidated request input (`catid`). Without the allowlist
        // it is an id -> name oracle over the whole category table: incrementing
        // catid on any public product page prints the name of every restricted
        // category. Both cache layers below are visitor-scoped for the same reason:
        // filtering here without doing that would store one visitor's answer under a
        // shared key and serve it to everyone else for the cache lifetime.
        (new CatalogAccessPolicy())->applyToCategories($query, 'a');

        // Resolve name / alias from the per-language tables, in $langTag when
        // given (language switch), otherwise the current request language.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_categories',
            langPrimaryColumn: 'id_category',
            fields:            ['name', 'alias'],
            langTag:           $langTag,
        );

        return $db->setQuery($query)->loadAssoc();
    }

    /**
     * Get a single category with two-level caching
     *
     * @param int $categoryId Category ID
     *
     * @return array|false Category data or false if not found
     * @since  1.0.0
     */
    private static function getCategory(int $categoryId, ?string $langTag = null)
    {
        // Effective language tag: explicit (language switch) or current request.
        // It scopes every cache key: name / alias are language-resolved, so a
        // language-blind key would serve one language's data to another.
        $lang = self::resolveLangTag($langTag);
        $visitor = self::getUserGroupCacheKey();
        $memoKey = $categoryId . '_' . $lang . '_' . $visitor;

        if (isset(self::$categoryDataCache[$memoKey])) {
            return self::$categoryDataCache[$memoKey];
        }

        $cache = self::getCacheController();

        if ($cache !== null) {
            $category = $cache->get(
                [self::class, 'loadCategoryFromDatabase'],
                [$categoryId, $langTag],
                'cat_' . $lang . '_' . $visitor . '_' . $categoryId,
            );
        } else {
            $category = self::loadCategoryFromDatabase($categoryId, $langTag);
        }

        if ($category) {
            self::$categoryDataCache[$memoKey] = $category;
        }

        return $category;
    }

    // CATEGORY PATH (Breadcrumbs)

    /**
     * Get category path from root to current category
     *
     * @param int $categoryId Category ID
     * @param string|null $langTag Resolve aliases in THIS language (language
     *                             switch); null = current request language.
     *
     * @return array Array of categories from root to current
     * @since  1.0.0
     */
    public static function getCategoryPath(int $categoryId, ?string $langTag = null): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        // Effective language scopes every cache key; the path carries
        // per-language aliases used in SEF URLs.
        $lang = self::resolveLangTag($langTag);
        $visitor = self::getUserGroupCacheKey();
        $memoKey = $categoryId . '_' . $lang . '_' . $visitor;

        if (isset(self::$categoryPathCache[$memoKey])) {
            return self::$categoryPathCache[$memoKey];
        }

        $cache = self::getCacheController();

        if ($cache !== null) {
            $path = $cache->get(
                [self::class, 'buildCategoryPath'],
                [$categoryId, $langTag],
                'path_' . $lang . '_' . $visitor . '_' . $categoryId,
            );
        } else {
            $path = self::buildCategoryPath($categoryId, $langTag);
        }

        self::$categoryPathCache[$memoKey] = $path;

        return $path;
    }

    /**
     * Build category path from database
     *
     * PUBLIC because it's called by cache controller via callback
     *
     * @param int $categoryId Category ID
     * @param string|null $langTag Resolve aliases in THIS language; null = current.
     *
     * @return array Array of categories from root to current
     * @since  1.0.0
     */
    public static function buildCategoryPath(int $categoryId, ?string $langTag = null): array
    {
        $path = [];
        $currentId = $categoryId;
        $visited = [];
        $maxDepth = 100;
        $iterations = 0;

        while ($currentId > 0 && $iterations < $maxDepth) {
            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            $iterations++;

            $category = self::getCategory($currentId, $langTag);

            if (!$category) {
                break;
            }

            $parentId = (int) $category['parent_id'];

            if ($currentId === $parentId) {
                break;
            }

            array_unshift($path, $category);
            $currentId = $parentId;
        }

        return $path;
    }

    // Category tree

    /**
     * Get category tree (hierarchical structure)
     *
     * Always builds at TREE_MAX_DEPTH internally (one cache entry).
     * If maxDepth is less, returns a trimmed copy without modifying cache.
     *
     * When includeCount is true, each category node will have:
     * - direct_product_count: items assigned directly to this category only
     * - product_count: unique items in this category + all descendants
     *   (deduplicated: items in multiple subcategories counted once)
     *
     * Counts are usergroup-aware and cached per usergroup combination.
     * Category structure (without counts) is NOT usergroup-dependent
     * and uses a shared cache entry.
     *
     * Counts also follow stock: under hide-out-of-stock a product sold out
     * leaves the count. A stored count tree therefore carries the inventory
     * version read BEFORE it was built and the time it was built; it is served
     * only while the version is still current and it is at most
     * COUNT_TREE_MAX_AGE seconds old, otherwise it is rebuilt and stored again
     * under the same key (the number of entries does not grow). The age bounds
     * what the version cannot see: a stock command committed after the build
     * with an operation id assigned before it, and stock figures changed outside
     * the inventory service. Trees without counts are cached as before.
     *
     * @param int $parentId Parent category ID (0 for root)
     * @param int $maxDepth Maximum depth to return (default: 10)
     * @param bool $includeCount Include product count (default: false)
     *
     * @return array Nested category tree
     * @since  1.0.0
     */
    public static function getCategoryTree(int $parentId = 0, int $maxDepth = 10, bool $includeCount = false): array
    {
        // Build at max of constant and requested depth: one cache entry
        // serves all smaller depth requests, larger requests get their own
        $buildDepth = max(self::TREE_MAX_DEPTH, $maxDepth);

        // The visitor is ALWAYS part of the key. It used to be included only when
        // counts were requested, on the reasoning that "category structure is the
        // same for all users"; that stopped being true the moment categories gained
        // a per-user/per-group allowlist. Since level 2 is a PERSISTENT cache, a tree
        // built for a privileged visitor would otherwise be stored under a key with
        // no visitor in it and later served to a guest.
        $ugKey = '_' . self::getUserGroupCacheKey();
        $cacheKey = "{$parentId}_{$buildDepth}_" . ($includeCount ? '1' : '0') . $ugKey;

        // Counts: the inventory version, read before anything is built, so a tree
        // is never stamped with a version newer than the stock it counted.
        $version = $includeCount
            ? InventoryAvailabilityService::inventoryVersion(db: Factory::getContainer()->get(DatabaseInterface::class))
            : null;
        $memoKey = $version === null ? $cacheKey : $cacheKey . '_v' . $version;

        // Level 1: Static cache
        if (isset(self::$categoryTreeCache[$memoKey])) {
            $tree = self::$categoryTreeCache[$memoKey];

            return ($maxDepth < $buildDepth) ? self::trimTreeDepth($tree, $maxDepth) : $tree;
        }

        // Level 2: Persistent cache
        $cache = self::getCacheController();

        if ($cache !== null) {
            // Language-aware key: the tree carries per-language name / alias.
            $lang = MultilingualHelper::getCurrentLanguageTag();
            $id = 'tree_' . md5($lang . '_' . $cacheKey);

            $tree = $version !== null
                ? self::cachedCountTree(cache: $cache, id: $id, version: $version, parentId: $parentId, buildDepth: $buildDepth)
                : $cache->get(
                    function ($parent, $depth, $count) {
                        return self::buildCategoryTree($parent, $depth, $count);
                    },
                    [$parentId, $buildDepth, $includeCount],
                    $id,
                );
        } else {
            $tree = self::buildCategoryTree($parentId, $buildDepth, $includeCount);
        }

        // Store full-depth tree in static cache
        self::$categoryTreeCache[$memoKey] = $tree;

        // Return trimmed if caller wants less depth
        return ($maxDepth < $buildDepth) ? self::trimTreeDepth($tree, $maxDepth) : $tree;
    }

    /**
     * A tree with product counts from the persistent cache, or built and stored.
     *
     * The stored entry is ['version' => int, 'built_at' => int, 'tree' => array].
     * It is served only when its version equals $version and it is between 0 and
     * COUNT_TREE_MAX_AGE seconds old; anything else (another version, too old, an
     * entry in any other shape) is rebuilt and stored again under the same id,
     * stamped with $version, which the caller read before this build.
     *
     * @param CallbackController $cache The category cache controller.
     * @param string $id The entry id (language and visitor scoped).
     * @param int $version The inventory version read before building.
     * @param int $parentId Parent category id.
     * @param int $buildDepth Depth the tree is built at.
     *
     * @return array The tree.
     *
     * @since  1.14.0
     */
    private static function cachedCountTree(CallbackController $cache, string $id, int $version, int $parentId, int $buildDepth): array
    {
        $stored = $cache->cache->get(id: $id);
        $entry = \is_string($stored)
            ? @unserialize(trim($stored), ['allowed_classes' => [stdClass::class]])
            : false;

        if (
            \is_array($entry)
            && isset($entry['version'], $entry['built_at'], $entry['tree'])
            && \is_array($entry['tree'])
            && (int) $entry['version'] === $version
        ) {
            $age = time() - (int) $entry['built_at'];

            if ($age >= 0 && $age <= self::COUNT_TREE_MAX_AGE) {
                return $entry['tree'];
            }
        }

        $tree = self::buildCategoryTree(parentId: $parentId, maxDepth: $buildDepth, includeCount: true);

        $cache->store(data: ['version' => $version, 'built_at' => time(), 'tree' => $tree], id: $id);

        return $tree;
    }

    /**
     * Trim a tree to a maximum depth
     *
     * Returns a shallow clone with children removed beyond maxDepth.
     * Does NOT modify the cached tree; safe to call repeatedly.
     *
     * @param array $categories Tree nodes
     * @param int $maxDepth Maximum depth to keep
     * @param int $currentDepth Current depth (internal)
     *
     * @return array Trimmed tree
     * @since  1.0.0
     */
    private static function trimTreeDepth(array $categories, int $maxDepth, int $currentDepth = 0): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $result = [];

        foreach ($categories as $category) {
            // Clone to avoid modifying the cached object
            $trimmed = clone $category;
            $trimmed->children = !empty($category->children)
                ? self::trimTreeDepth($category->children, $maxDepth, $currentDepth + 1)
                : [];
            $result[] = $trimmed;
        }

        return $result;
    }

    /**
     * Build category tree recursively
     *
     * Builds the category hierarchy with clean category-only queries.
     * Product counts are computed in a single pass at root level via
     * computeAllCounts() after the full tree structure is built.
     *
     * @param int $parentId Parent category ID
     * @param int $maxDepth Maximum depth
     * @param bool $includeCount Include product count
     * @param int $currentDepth Current recursion depth
     * @param array $visited Track visited IDs to prevent infinite loops
     *
     * @return array Category tree
     * @since  1.0.0
     */
    private static function buildCategoryTree(
        int $parentId,
        int $maxDepth,
        bool $includeCount = false,
        int $currentDepth = 0,
        array $visited = [],
    ): array {
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        if (isset($visited[$parentId])) {
            return [];
        }

        $visited[$parentId] = true;

        // Per-request memo: a given parent's direct children are fetched ONCE and
        // shared across all tree builds (full tree / subtree / breadcrumbs), keyed
        // by parent + language. Without this the same parent's children query
        // repeats whenever builds overlap (visible when the Level-2 cache is off).
        $childKey = $parentId . '|' . MultilingualHelper::getCurrentLanguageTag()
            . '|' . self::getUserGroupCacheKey();

        if (isset(self::$childRowsCache[$childKey])) {
            $rows = self::$childRowsCache[$childKey];
        } else {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            // Clean category query: no count joins
            $query = $db->getQuery(true)
                ->select('c.id, c.parent_id, c.ordering')
                ->from('#__alfa_categories AS c')
                ->where('c.parent_id = :parent')
                ->where('c.state = 1')
                ->order('c.ordering ASC')
                ->bind(':parent', $parentId, ParameterType::INTEGER);

            // The navigation tree is a listing too: a restricted category must not
            // appear in it, or the restriction is only cosmetic.
            (new CatalogAccessPolicy())->applyToCategories($query, 'c');

            // Resolve name / alias in the active language from the per-language tables.
            MultilingualHelper::addMultilingualJoinToQuery(
                query:             $query,
                mainAlias:         'c',
                mainPrimaryColumn: 'id',
                langTableBase:     '#__alfa_categories',
                langPrimaryColumn: 'id_category',
                fields:            ['name', 'alias'],
            );

            $rows = $db->setQuery($query)->loadObjectList() ?: [];
            self::$childRowsCache[$childKey] = $rows;
        }

        // Clone so THIS build owns its objects; it attaches ->children below (and
        // counts may be written onto them) without mutating the shared cache.
        $categories = array_map(static fn ($row) => clone $row, $rows);

        // Build children recursively
        foreach ($categories as $category) {
            $category->children = self::buildCategoryTree(
                $category->id,
                $maxDepth,
                $includeCount,
                $currentDepth + 1,
                $visited,
            );
        }

        // Compute ALL counts at root level in ONE pass
        // after the entire tree structure is built
        if ($includeCount && $currentDepth === 0) {
            self::computeAllCounts($categories);
        }

        return $categories;
    }

    // PRODUCT COUNTS (single-query approach)

    /**
     * Compute direct + aggregated product counts for the entire tree
     *
     * Strategy:
     * 1. Collect ALL category IDs in the tree
     * 2. ONE query: load all (category_id, item_id) pairs for visible items
     * 3. Build map: category_id -> { item_id: true } (keys for O(1) dedup)
     * 4. Walk tree bottom-up using PHP array union ($a + $b) for dedup
     *
     * Why array keys instead of values:
     * - PHP's + operator on keyed arrays = automatic dedup, O(n)
     * - No array_unique() needed (that's O(n log n))
     * - count() on result gives deduplicated count
     *
     * Memory: ~4MB for 20K products x 3 categories average.
     * All cached after first load, so this runs once per usergroup.
     *
     * @param array $categories Root-level tree nodes (with children built)
     *
     * @since  1.0.0
     */
    private static function computeAllCounts(array $categories): void
    {
        // Collect every category ID in the tree
        $allCategoryIds = [];
        self::collectIds($categories, $allCategoryIds);

        if (empty($allCategoryIds)) {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // ONE query: get all visible (category_id, item_id) pairs
        $query = $db->getQuery(true)
            ->select('ic.category_id, ic.item_id')
            ->from($db->quoteName('#__alfa_items_categories', 'ic'))
            ->join('INNER', $db->quoteName('#__alfa_items', 'i')
                . ' ON i.id = ic.item_id'
                . self::getItemJoinConditions())
            ->whereIn('ic.category_id', array_map('intval', $allCategoryIds));

        // Usergroup visibility
        self::applyUserGroupFilter($query);

        $rows = $db->setQuery($query)->loadObjectList();

        // Build map: category_id -> [item_id => true, ...]
        // Using item_id as KEY (not value) enables O(1) dedup via array union
        $categoryItemSets = [];
        foreach ($rows as $row) {
            $categoryItemSets[(int) $row->category_id][(int) $row->item_id] = true;
        }

        // Free rows: only the map is needed from here
        unset($rows);

        // Walk tree and assign counts bottom-up
        self::assignCountsFromMap($categories, $categoryItemSets);
    }

    /**
     * Recursively assign counts using pre-loaded category->item map
     *
     * Walks bottom-up: children are processed first, then their item sets
     * are merged into the parent using array union (+) for dedup.
     *
     * Returns the merged item set for this branch so the parent
     * can merge without re-traversing the entire subtree.
     *
     * @param array $categories Tree nodes
     * @param array $categoryItemSets Map of category_id -> [item_id => true]
     *
     * @return array Merged item ID set for this level [item_id => true]
     * @since  1.0.0
     */
    private static function assignCountsFromMap(array $categories, array $categoryItemSets): array
    {
        $levelItems = [];

        foreach ($categories as $category) {
            $catId = (int) $category->id;
            $directItems = $categoryItemSets[$catId] ?? [];

            // Direct count: items assigned to THIS category only
            $category->direct_product_count = count($directItems);

            if (empty($category->children)) {
                // Leaf: aggregated = direct, no overlap possible
                $category->product_count = $category->direct_product_count;
                $branchItems = $directItems;
            } else {
                // Parent: merge children's items with own
                // Children return their complete branch item sets
                $childItems = self::assignCountsFromMap($category->children, $categoryItemSets);

                // Array union on keys = automatic dedup, O(n)
                // Item 1 in both cat 2 and cat 3 -> counted once
                $branchItems = $directItems + $childItems;

                $category->product_count = count($branchItems);
            }

            // Pass this branch's items up to parent level
            $levelItems += $branchItems;
        }

        return $levelItems;
    }

    // Descendants

    /**
     * Get all descendant category IDs for a given parent
     *
     * Uses getCategoryTree() which is already cached at both levels.
     *
     * @param int $parentId Parent category ID
     * @param int $maxDepth Maximum depth (default: 10)
     *
     * @return array Flat array of descendant category IDs (does NOT include parent)
     * @since  1.0.0
     */
    public static function getDescendantIds(int $parentId, int $maxDepth = 10): array
    {
        $tree = self::getCategoryTree($parentId, $maxDepth, false);
        $ids = [];
        self::collectIds($tree, $ids);

        return $ids;
    }

    /**
     * Recursively collect IDs from a category tree
     *
     * @param array $categories Category tree nodes
     * @param array &$ids Collected IDs (by reference)
     *
     * @since  1.0.0
     */
    private static function collectIds(array $categories, array &$ids): void
    {
        foreach ($categories as $category) {
            $ids[] = (int) $category->id;

            if (!empty($category->children)) {
                self::collectIds($category->children, $ids);
            }
        }
    }

    // Cache management

    /**
     * Clear category cache
     *
     * @param int|null $categoryId Specific category ID, or null to clear all
     *
     * @since  1.0.0
     */
    public static function clearCache(?int $categoryId = null): void
    {
        // The in-memory memos are language-keyed (`<id>_<lang>`), so a per-id
        // unset can't target them; reset them wholesale (cheap, per-request).
        // The persistent group-clean below invalidates every language variant
        // regardless, so a targeted vs full clear differs only in the controller reset.
        self::$categoryPathCache = [];
        self::$categoryDataCache = [];
        self::$categoryTreeCache = [];
        self::$childRowsCache = [];

        $cache = self::getCacheController();

        if ($cache !== null) {
            // Persistent keys are language- AND usergroup-scoped
            // (cat_<lang>_<id>, path_<lang>_<id>, tree_<lang>_<hash>). A targeted
            // per-id remove cannot cover all those variants, so clean the whole
            // group; this invalidates every language / usergroup entry at once.
            $cache->cache->clean('com_alfa.categories');

            if ($categoryId === null) {
                self::$cacheController = null;
            }
        }
    }

    /**
     * Recursively clear cache for category and all descendants
     *
     * @param int $categoryId Parent category ID
     *
     * @since  1.0.0
     */
    public static function clearCacheRecursive(int $categoryId): void
    {
        self::clearCache($categoryId);

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_categories'))
            ->where($db->quoteName('parent_id') . ' = :parentId')
            ->bind(':parentId', $categoryId, ParameterType::INTEGER);

        $children = $db->setQuery($query)->loadColumn();

        foreach ($children as $childId) {
            self::clearCacheRecursive((int) $childId);
        }
    }
}
