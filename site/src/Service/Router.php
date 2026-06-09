<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\Router\RouterView;
use Joomla\CMS\Component\Router\RouterViewConfiguration;
use Joomla\CMS\Component\Router\Rules\MenuRules;
use Joomla\CMS\Component\Router\Rules\NomenuRules;
use Joomla\CMS\Component\Router\Rules\StandardRules;
use Joomla\CMS\Factory;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\Database\DatabaseInterface;

/**
 * Alfa Component Router
 *
 * Handles SEF URL routing for the Alfa component with caching optimization
 *
 * @since  1.0.0
 */
class Router extends RouterView
{
    /**
     * Database object
     *
     * @var DatabaseInterface
     * @since  1.0.0
     */
    private $db;

    /**
     * Cache for complete category paths
     *
     * @var array
     * @since  1.0.0
     */
    private static $categoryPathCache = [];

    /**
     * Cache for item aliases
     *
     * @var array
     * @since  1.0.0
     */
    private static $itemAliasCache = [];

    /**
     * Cache for manufacturer aliases
     *
     * @var array
     * @since  1.0.0
     */
    private static $manufacturerAliasCache = [];

    /**
     * Router constructor
     *
     * @param SiteApplication $app Application object
     * @param AbstractMenu $menu Menu object
     *
     * @since  1.0.0
     */
    public function __construct(SiteApplication $app, AbstractMenu $menu)
    {
        // Initialize database object
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);

        //		$this->app->getInput()->set('category_id',0);
        // Register manufacturers view
        $manufacturers = new RouterViewConfiguration('manufacturers');
        $this->registerView($manufacturers);

        // Register manufacturer view (child of manufacturers)
        $manufacturer = new RouterViewConfiguration('manufacturer');
        $manufacturer->setKey('id')->setParent($manufacturers);
        $this->registerView($manufacturer);

        // Register items view (nestable by category)
        $items = new RouterViewConfiguration('items');
        $items->setKey('category_id')->setNestable();
        $this->registerView($items);

        // Register item view (child of items)
        $item = new RouterViewConfiguration('item');
        $item->setKey('id')->setParent($items, 'category_id');
        $this->registerView($item);

        // Register cart view
        $cart = new RouterViewConfiguration('cart');
        $this->registerView($cart);

        // Register empties view
        $empties = new RouterViewConfiguration('empties');
        $this->registerView($empties);

        parent::__construct($app, $menu);

        // Attach routing rules
        $this->attachRule(new MenuRules($this));
        $this->attachRule(new StandardRules($this));
        $this->attachRule(new NomenuRules($this));
    }

    // =========================================================================
    // URL PREPROCESSING - Clean SEO URLs
    // =========================================================================

    /**
     * Preprocess the query before building the URL
     *
     * Removes default and empty values for clean SEO-friendly URLs
     *
     * @param array $query Query parameters
     *
     * @return array The cleaned query parameters
     *
     * @since  1.0.0
     */
    public function preprocess($query): array
    {
        // Remove category_id=0 (default)
        if (isset($query['view']) && $query['view'] === 'items' && !isset($query['category_id'])) {
            $query['category_id'] = 0;
        }

        // Call parent to run rules
        $query = parent::preprocess($query);

        // Remove pagination defaults (start=0, limitstart=0)
        foreach (['start', 'limitstart'] as $param) {
            if (isset($query[$param]) && (int) $query[$param] === 0) {
                unset($query[$param]);
            }
        }

        // Clean empty filter values
        if (isset($query['filter']) && is_array($query['filter'])) {
            $query['filter'] = $this->cleanEmptyValues($query['filter']);
            if (empty($query['filter'])) {
                unset($query['filter']);
            }
        }

        // Clean empty list values
        if (isset($query['list']) && is_array($query['list'])) {
            $query['list'] = $this->cleanEmptyValues($query['list']);
            if (empty($query['list'])) {
                unset($query['list']);
            }
        }

        // Remove top-level empty values
        foreach ($query as $key => $value) {
            if ($value === '' || $value === null) {
                unset($query[$key]);
            }
        }

        return $query;
    }

    /**
     * Recursively remove empty values from array
     *
     * @param array $array Input array
     *
     * @return array Cleaned array
     *
     * @since  1.0.0
     */
    protected function cleanEmptyValues(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->cleanEmptyValues($value);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            } elseif ($value === '' || $value === null) {
                unset($array[$key]);
            }
        }

        return $array;
    }
    /**
     * Get the segment(s) for the items view
     *
     * Builds URL segments for the items list view, supporting both categorized and uncategorized listings.
     * This method handles two scenarios:
     *
     * 1. Items within a category: Builds the complete category path hierarchy from parent to child
     *    Example: /categories/electronics/phones
     *
     * 2. All items or search results: Returns only the root segment for menu matching
     *    Example: /items or /all-items
     *
     * The '0:root' segment is always appended to ensure proper menu item activation and SEF routing.
     * This virtual segment acts as an anchor point for Joomla's menu matching system.
     *
     * @param int|null $id Category ID (null or 0 for uncategorized items view)
     * @param array $query An associative array of URL arguments
     *
     * @return array Array of URL segments indexed by category ID, always includes '0:root'
     *
     * @since  1.0.0
     */
    public function getItemsSegment($id, $query): array
    {
        $aliasPath = [];

        // Build category path hierarchy only when a specific category is requested.
        // Pass the target language when building a language-switch URL (&lang) so
        // the whole breadcrumb is resolved in that language; CategoryHelper owns
        // the per-language resolution + caching.
        if (!empty($id) && $id !== 0 && $id !== '0') {
            $pathData = array_reverse(
                CategoryHelper::getCategoryPath($id, $query['lang'] ?? null),
            );

            foreach ($pathData as $category) {
                $aliasPath[$category['id']] = $category['alias'];
            }
        }

        // Add virtual root segment for consistent menu item matching
        // This ensures the router can match both categorized and uncategorized items views
        $aliasPath[0] = '0:root';

        return $aliasPath;
    }

    /**
     * Get the category ID from a URL segment
     *
     * Resolves category alias to ID, verifying parent-child relationship
     *
     * @param string $segment The alias of the category
     * @param array $query An associative array of URL arguments
     *
     * @return int|false Category ID or false if not found
     *
     * @since  1.0.0
     */
    public function getItemsId($segment, $query)
    {
        $dbquery = $this->db->getQuery(true)
            ->select($this->db->quoteName('a.id'))
            ->from($this->db->quoteName('#__alfa_categories', 'a'));

        // Resolve the alias in the active language (current → default) from the
        // per-language tables. HAVING filters on the resulting alias expression.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $dbquery,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_categories',
            langPrimaryColumn: 'id_category',
            fields:            ['alias'],
        );

        $dbquery->having($this->db->quoteName('alias') . ' = ' . $this->db->quote($segment));

        // Verify this segment is actually a child of the parent to prevent infinite nesting
        if (!empty($query['category_id'])) {
            $dbquery->where($this->db->quoteName('a.parent_id') . ' = ' . (int) $query['category_id']);
        }

        $this->db->setQuery($dbquery);
        $result = $this->db->loadResult();

        // Return the result (will be null if no matching child category found)
        return $result ? (int) $result : null;
    }

    /**
     * Get the segment(s) for an item
     *
     * Converts item ID to URL-friendly segment with caching
     *
     * @param string $id ID of the item to retrieve the segments for
     * @param array $query The request that is built right now
     *
     * @return array The segments of this item
     *
     * @since  1.0.0
     */
    public function getItemSegment($id, $query): array
    {
        // If ID doesn't contain alias yet, fetch it
        if (!strpos($id, ':')) {
            // Resolve in the TARGET language when building a language-switch URL
            // (&lang in the query); otherwise the current request language. The
            // cache is keyed by language so one request can build URLs for many.
            $langTag = (string) ($query['lang'] ?? '');
            $cacheKey = $id . '|' . ($langTag !== '' ? $langTag : MultilingualHelper::getCurrentLanguageTag());

            if (isset(self::$itemAliasCache[$cacheKey])) {
                $alias = self::$itemAliasCache[$cacheKey];
            } else {
                $alias = MultilingualHelper::getTranslatedValue(
                    id:                (int) $id,
                    tableName:         '#__alfa_items',
                    primaryColumnName: 'id_item',
                    field:             'alias',
                    langTag:           $langTag,
                );

                self::$itemAliasCache[$cacheKey] = $alias;
            }

            // Fall back to the ID as slug if no translation exists yet.
            $id .= ':' . ($alias ?: $id);
        }

        // Return only the alias for clean SEF URLs
        [$void, $segment] = explode(':', $id, 2);

        return [$void => $segment];
    }

    /**
     * Get the item ID from a URL segment
     *
     * Resolves item alias or numeric ID to database ID
     *
     * @param string $segment Segment of the item to retrieve the ID for
     * @param array $query The request that is parsed right now
     *
     * @return int The id of this item or 0 if not found
     *
     * @since  1.0.0
     */
    public function getItemId($segment, $query)
    {
        $dbquery = $this->db->getQuery(true)
            ->select('i.id')
            ->from($this->db->quoteName('#__alfa_items', 'i'));

        // Resolve the alias in the active language (current → default); HAVING
        // filters on the resulting alias expression.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $dbquery,
            mainAlias:         'i',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            fields:            ['alias'],
        );

        $dbquery->having($this->db->quoteName('alias') . ' = ' . $this->db->quote($segment));

        if (!empty($query['category_id'])) {
            // explicitly set the id cause it may not always exist e.g. on sef urls
            $this->app->getInput()->set('category_id', $query['category_id']);

            $dbquery->join('INNER', $this->db->quoteName('#__alfa_items_categories', 'ic') . ' ON ic.item_id = i.id')
                ->where('ic.category_id = ' . (int) $query['category_id']);
        }

        $this->db->setQuery($dbquery);
        $result = $this->db->loadResult();

        return $result ? (int) $result : null;
    }

    /**
     * Get the segment(s) for a manufacturer
     *
     * Converts manufacturer ID to URL-friendly segment with caching
     *
     * @param string $id ID of the manufacturer to retrieve the segments for
     * @param array $query The request that is built right now
     *
     * @return array The segments of this manufacturer
     *
     * @since  1.0.0
     */
    public function getManufacturerSegment($id, $query): array
    {
        // If ID doesn't contain alias yet, fetch it
        if (!strpos($id, ':')) {
            // Target language for a language-switch URL (&lang), else current.
            // Cache keyed by language so multi-language builds don't collide.
            $langTag = (string) ($query['lang'] ?? '');
            $cacheKey = $id . '|' . ($langTag !== '' ? $langTag : MultilingualHelper::getCurrentLanguageTag());

            if (isset(self::$manufacturerAliasCache[$cacheKey])) {
                $alias = self::$manufacturerAliasCache[$cacheKey];
            } else {
                $alias = MultilingualHelper::getTranslatedValue(
                    id:                (int) $id,
                    tableName:         '#__alfa_manufacturers',
                    primaryColumnName: 'id_manufacturer',
                    field:             'alias',
                    langTag:           $langTag,
                );

                self::$manufacturerAliasCache[$cacheKey] = $alias;
            }

            // Fall back to the ID as slug if no translation exists yet.
            $id .= ':' . ($alias ?: $id);
        }

        // Return only the alias for clean SEF URLs
        [$void, $segment] = explode(':', $id, 2);

        return [$void => $segment];
    }

    /**
     * Get the manufacturer ID from a URL segment
     *
     * Resolves manufacturer alias or numeric ID to database ID
     *
     * @param string $segment Segment of the manufacturer to retrieve the ID for
     * @param array $query The request that is parsed right now
     *
     * @return int The id of this manufacturer or 0 if not found
     *
     * @since  1.0.0
     */
    public function getManufacturerId($segment, $query)
    {
        $dbquery = $this->db->getQuery(true)
            ->select($this->db->quoteName('a.id'))
            ->from($this->db->quoteName('#__alfa_manufacturers', 'a'));

        // Resolve the alias in the active language (current → default); HAVING
        // filters on the resulting alias expression.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $dbquery,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_manufacturers',
            langPrimaryColumn: 'id_manufacturer',
            fields:            ['alias'],
        );

        $dbquery->having($this->db->quoteName('alias') . ' = ' . $this->db->quote($segment));
        $this->db->setQuery($dbquery);
        $result = $this->db->loadResult();

        return $result ? (int) $result : null;
    }
}
