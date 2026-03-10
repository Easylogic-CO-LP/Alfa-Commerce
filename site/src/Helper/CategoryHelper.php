<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * @package    Alfa Commerce
 * @since      1.0.1
 */

namespace Alfa\Component\Alfa\Site\Helper;

use Exception;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\CallbackController;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Category Helper
 *
 * Handles all category-related operations with optimized two-level caching:
 * - Level 1: Static cache (per-request, zero overhead)
 * - Level 2: Persistent cache (across requests, when enabled)
 *
 * Product count strategy:
 * - ONE query loads all category→item mappings for the entire tree
 * - PHP walks the tree using array key union ($a + $b) for O(1) dedup
 * - Counts are usergroup-aware and cached per usergroup combination
 * - Memory: ~4MB for 20K products (integer pairs only, not full rows)
 *
 * Depth strategy:
 * - Tree is always built at TREE_MAX_DEPTH internally (one cache entry)
 * - Callers requesting less depth get a trimmed copy (no extra queries)
 * - Avoids duplicate cache entries for depth=3, depth=5, depth=10 etc.
 *
 * @since  1.0.1
 */
class CategoryHelper
{
    /**
     * Internal max depth for all tree builds.
     * Templates control how deep they render, not how deep we cache.
     *
     * @since  1.0.1
     */
    private const TREE_MAX_DEPTH = 10;

    /**
     * Static caches (Level 1 - current request only)
     * These are checked FIRST for maximum performance
     */
    private static $categoryPathCache = [];
    private static $categoryDataCache = [];
    private static $categoryTreeCache = [];

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
    private static $currentUserGroupIds = null;

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Get component parameters (cached)
     *
     * @return \Joomla\Registry\Registry
     * @since   1.0.1
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
     * @since   1.0.1
     */
    private static function isCachingEnabled(): bool
    {
        return (bool) self::getParams()->get('enable_cache', 1);
    }

    /**
     * Check if category caching is enabled
     *
     * @since   1.0.1
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
     * @since   1.0.1
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

    // =========================================================================
    // USER CONTEXT
    // =========================================================================

    /**
     * Get current user's usergroup IDs (cached per request)
     *
     * @return array Array of usergroup IDs
     * @since   1.0.1
     */
    private static function getCurrentUserGroupIds(): array
    {
        if (self::$currentUserGroupIds === null) {
            $user = Factory::getApplication()->getIdentity();
            self::$currentUserGroupIds = $user ? array_values($user->getAuthorisedGroups()) : [];
        }

        return self::$currentUserGroupIds;
    }

    /**
     * Get a stable cache key suffix for the current user's groups
     *
     * Sorted and hashed so [2,8] and [8,2] produce the same key
     *
     * @since   1.0.1
     */
    private static function getUserGroupCacheKey(): string
    {
        $groups = self::getCurrentUserGroupIds();
        sort($groups);

        return md5(implode(',', $groups));
    }

    // =========================================================================
    // VISIBILITY FILTER (shared SQL)
    // =========================================================================

    /**
     * Get the item visibility JOIN conditions
     *
     * Used in LEFT/INNER JOINs where conditions must be in the ON clause.
     * Centralised so stock/state logic is defined once.
     *
     * @return string SQL fragment
     * @since   1.0.1
     */
    private static function getItemJoinConditions(): string
    {
        return ' AND i.state = 1 AND NOT (i.stock_action = 2 AND i.stock > 0)';
    }

    /**
     * Apply usergroup visibility filter to a query
     *
     * Items with NO usergroup entries are visible to everyone.
     * Items WITH usergroup entries are only visible to matching groups.
     *
     * @param \Joomla\Database\DatabaseQuery $query Query to modify
     *
     * @since   1.0.1
     */
    private static function applyUserGroupFilter($query): void
    {
        $userGroupIds = self::getCurrentUserGroupIds();

        if (!empty($userGroupIds)) {
            $query->join('LEFT', '#__alfa_items_usergroups AS ug ON ug.item_id = i.id');
            $query->where('(ug.item_id IS NULL OR ug.usergroup_id IN ('
                . implode(',', array_map('intval', $userGroupIds)) . '))');
        }
    }

    // =========================================================================
    // SINGLE CATEGORY
    // =========================================================================

    /**
     * Load a single category from the database
     *
     * PUBLIC because it's called by cache controller via callback
     *
     * @param int $categoryId Category ID
     *
     * @return array|false Category data or false if not found
     * @since   1.0.1
     */
    public static function loadCategoryFromDatabase(int $categoryId)
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'alias', 'parent_id']))
            ->from($db->quoteName('#__alfa_categories'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('state') . ' = 1')
            ->bind(':id', $categoryId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadAssoc();
    }

    /**
     * Get a single category with two-level caching
     *
     * @param int $categoryId Category ID
     *
     * @return array|false Category data or false if not found
     * @since   1.0.1
     */
    private static function getCategory(int $categoryId)
    {
        if (isset(self::$categoryDataCache[$categoryId])) {
            return self::$categoryDataCache[$categoryId];
        }

        $cache = self::getCacheController();

        if ($cache !== null) {
            $category = $cache->get(
                [self::class, 'loadCategoryFromDatabase'],
                [$categoryId],
                'cat_' . $categoryId,
            );
        } else {
            $category = self::loadCategoryFromDatabase($categoryId);
        }

        if ($category) {
            self::$categoryDataCache[$categoryId] = $category;
        }

        return $category;
    }

    // =========================================================================
    // CATEGORY PATH (Breadcrumbs)
    // =========================================================================

    /**
     * Get category path from root to current category
     *
     * @param int $categoryId Category ID
     *
     * @return array Array of categories from root to current
     * @since   1.0.1
     */
    public static function getCategoryPath(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        if (isset(self::$categoryPathCache[$categoryId])) {
            return self::$categoryPathCache[$categoryId];
        }

        $cache = self::getCacheController();

        if ($cache !== null) {
            $path = $cache->get(
                [self::class, 'buildCategoryPath'],
                [$categoryId],
                'path_' . $categoryId,
            );
        } else {
            $path = self::buildCategoryPath($categoryId);
        }

        self::$categoryPathCache[$categoryId] = $path;

        return $path;
    }

    /**
     * Build category path from database
     *
     * PUBLIC because it's called by cache controller via callback
     *
     * @param int $categoryId Category ID
     *
     * @return array Array of categories from root to current
     * @since   1.0.1
     */
    public static function buildCategoryPath(int $categoryId): array
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

            $category = self::getCategory($currentId);

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

    // =========================================================================
    // CATEGORY TREE
    // =========================================================================

    /**
     * Get category tree (hierarchical structure)
     *
     * Always builds at TREE_MAX_DEPTH internally (one cache entry).
     * If maxDepth is less, returns a trimmed copy without modifying cache.
     *
     * When includeCount is true, each category node will have:
     * - direct_product_count: items assigned directly to this category only
     * - product_count: unique items in this category + all descendants
     *   (deduplicated — items in multiple subcategories counted once)
     *
     * Counts are usergroup-aware and cached per usergroup combination.
     * Category structure (without counts) is NOT usergroup-dependent
     * and uses a shared cache entry.
     *
     * @param int $parentId Parent category ID (0 for root)
     * @param int $maxDepth Maximum depth to return (default: 10)
     * @param bool $includeCount Include product count (default: false)
     *
     * @return array Nested category tree
     * @since   1.0.1
     */
    public static function getCategoryTree(int $parentId = 0, int $maxDepth = 10, bool $includeCount = false): array
    {
        // Build at max of constant and requested depth — one cache entry
        // serves all smaller depth requests, larger requests get their own
        $buildDepth = max(self::TREE_MAX_DEPTH, $maxDepth);

        // Include usergroup in cache key ONLY when counts are requested
        // (category structure is the same for all users)
        $ugKey = $includeCount ? '_' . self::getUserGroupCacheKey() : '';
        $cacheKey = "{$parentId}_{$buildDepth}_" . ($includeCount ? '1' : '0') . $ugKey;

        // Level 1: Static cache
        if (isset(self::$categoryTreeCache[$cacheKey])) {
            $tree = self::$categoryTreeCache[$cacheKey];

            return ($maxDepth < $buildDepth) ? self::trimTreeDepth($tree, $maxDepth) : $tree;
        }

        // Level 2: Persistent cache
        $cache = self::getCacheController();

        if ($cache !== null) {
            $tree = $cache->get(
                function ($parent, $depth, $count) {
                    return self::buildCategoryTree($parent, $depth, $count);
                },
                [$parentId, $buildDepth, $includeCount],
                'tree_' . md5($cacheKey),
            );
        } else {
            $tree = self::buildCategoryTree($parentId, $buildDepth, $includeCount);
        }

        // Store full-depth tree in static cache
        self::$categoryTreeCache[$cacheKey] = $tree;

        // Return trimmed if caller wants less depth
        return ($maxDepth < $buildDepth) ? self::trimTreeDepth($tree, $maxDepth) : $tree;
    }

    /**
     * Trim a tree to a maximum depth
     *
     * Returns a shallow clone with children removed beyond maxDepth.
     * Does NOT modify the cached tree — safe to call repeatedly.
     *
     * @param array $categories Tree nodes
     * @param int $maxDepth Maximum depth to keep
     * @param int $currentDepth Current depth (internal)
     *
     * @return array Trimmed tree
     * @since   1.0.1
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
     * @since   1.0.1
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

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        // Clean category query — no count joins
        $query = $db->getQuery(true)
            ->select('c.id, c.name, c.alias, c.parent_id, c.ordering')
            ->from('#__alfa_categories AS c')
            ->where('c.parent_id = :parent')
            ->where('c.state = 1')
            ->order('c.ordering ASC')
            ->bind(':parent', $parentId, ParameterType::INTEGER);

        $categories = $db->setQuery($query)->loadObjectList();

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

    // =========================================================================
    // PRODUCT COUNTS (single-query approach)
    // =========================================================================

    /**
     * Compute direct + aggregated product counts for the entire tree
     *
     * Strategy:
     * 1. Collect ALL category IDs in the tree
     * 2. ONE query: load all (category_id, item_id) pairs for visible items
     * 3. Build map: category_id → { item_id: true } (keys for O(1) dedup)
     * 4. Walk tree bottom-up using PHP array union ($a + $b) for dedup
     *
     * Why array keys instead of values:
     * - PHP's + operator on keyed arrays = automatic dedup, O(n)
     * - No array_unique() needed (that's O(n log n))
     * - count() on result gives deduplicated count
     *
     * Memory: ~4MB for 20K products × 3 categories average.
     * All cached after first load, so this runs once per usergroup.
     *
     * @param array $categories Root-level tree nodes (with children built)
     *
     * @since   1.0.1
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

        // Build map: category_id → [item_id => true, ...]
        // Using item_id as KEY (not value) enables O(1) dedup via array union
        $categoryItemSets = [];
        foreach ($rows as $row) {
            $categoryItemSets[(int) $row->category_id][(int) $row->item_id] = true;
        }

        // Free rows — only the map is needed from here
        unset($rows);

        // Walk tree and assign counts bottom-up
        self::assignCountsFromMap($categories, $categoryItemSets);
    }

    /**
     * Recursively assign counts using pre-loaded category→item map
     *
     * Walks bottom-up: children are processed first, then their item sets
     * are merged into the parent using array union (+) for dedup.
     *
     * Returns the merged item set for this branch so the parent
     * can merge without re-traversing the entire subtree.
     *
     * @param array $categories Tree nodes
     * @param array $categoryItemSets Map of category_id → [item_id => true]
     *
     * @return array Merged item ID set for this level [item_id => true]
     * @since   1.0.1
     */
    private static function assignCountsFromMap(array $categories, array $categoryItemSets): array
    {
        $levelItems = [];

        foreach ($categories as $category) {
            $catId = (int) $category->id;
            $directItems = $categoryItemSets[$catId] ?? [];

            // Direct count — items assigned to THIS category only
            $category->direct_product_count = count($directItems);

            if (empty($category->children)) {
                // Leaf — aggregated = direct, no overlap possible
                $category->product_count = $category->direct_product_count;
                $branchItems = $directItems;
            } else {
                // Parent — merge children's items with own
                // Children return their complete branch item sets
                $childItems = self::assignCountsFromMap($category->children, $categoryItemSets);

                // Array union on keys = automatic dedup, O(n)
                // Item 1 in both cat 2 and cat 3 → counted once
                $branchItems = $directItems + $childItems;

                $category->product_count = count($branchItems);
            }

            // Pass this branch's items up to parent level
            $levelItems += $branchItems;
        }

        return $levelItems;
    }

    // =========================================================================
    // DESCENDANTS
    // =========================================================================

    /**
     * Get all descendant category IDs for a given parent
     *
     * Uses getCategoryTree() which is already cached at both levels.
     *
     * @param int $parentId Parent category ID
     * @param int $maxDepth Maximum depth (default: 10)
     *
     * @return array Flat array of descendant category IDs (does NOT include parent)
     * @since   1.0.1
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
     * @since   1.0.1
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

    // =========================================================================
    // STANDALONE PRODUCT COUNT
    // =========================================================================

    /**
     * Get product count for a specific category
     *
     * Standalone method for when you need a count without the full tree.
     * Uses a single COUNT(DISTINCT) query — no PHP array processing.
     *
     * Use cases: category page header, breadcrumb badges.
     *
     * If you already have the tree from getCategoryTree(), use
     * product_count / direct_product_count from there instead —
     * that data is already cached.
     *
     * @param int $categoryId Category ID
     * @param bool $includeSubcategories Include descendant categories
     *
     * @return int Number of visible products
     * @since   1.0.1
     */
    public static function getProductCount(int $categoryId, bool $includeSubcategories = false): int
    {
        $categoryIds = [$categoryId];

        if ($includeSubcategories) {
            $categoryIds = array_merge($categoryIds, self::getDescendantIds($categoryId));
        }

        return self::countVisibleItems($categoryIds);
    }

    /**
     * Count distinct visible items across multiple categories
     *
     * Single COUNT(DISTINCT) query with all visibility filters.
     *
     * @param array $categoryIds Array of category IDs to count across
     *
     * @return int Number of unique visible items
     * @since   1.0.1
     */
    private static function countVisibleItems(array $categoryIds): int
    {
        if (empty($categoryIds)) {
            return 0;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->select('COUNT(DISTINCT i.id)')
            ->from($db->quoteName('#__alfa_items_categories', 'ic'))
            ->join('INNER', $db->quoteName('#__alfa_items', 'i')
                . ' ON i.id = ic.item_id'
                . self::getItemJoinConditions())
            ->whereIn('ic.category_id', array_map('intval', $categoryIds));

        self::applyUserGroupFilter($query);

        return (int) $db->setQuery($query)->loadResult();
    }

    // =========================================================================
    // CACHE MANAGEMENT
    // =========================================================================

    /**
     * Clear category cache
     *
     * @param int|null $categoryId Specific category ID, or null to clear all
     *
     * @since   1.0.1
     */
    public static function clearCache(?int $categoryId = null): void
    {
        if ($categoryId !== null) {
            unset(
                self::$categoryPathCache[$categoryId],
                self::$categoryDataCache[$categoryId],
            );

            self::$categoryTreeCache = [];
        } else {
            self::$categoryPathCache = [];
            self::$categoryDataCache = [];
            self::$categoryTreeCache = [];
        }

        $cache = self::getCacheController();

        if ($cache !== null) {
            if ($categoryId !== null) {
                $cache->cache->remove('path_' . $categoryId, 'com_alfa.categories');
                $cache->cache->remove('cat_' . $categoryId, 'com_alfa.categories');

                // Tree caches include usergroup variants — clear entire group
                // to ensure all usergroup-specific trees are invalidated
                $cache->cache->clean('com_alfa.categories');
            } else {
                $cache->cache->clean('com_alfa.categories');
                self::$cacheController = null;
            }
        }
    }

    /**
     * Recursively clear cache for category and all descendants
     *
     * @param int $categoryId Parent category ID
     *
     * @since   1.0.1
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
