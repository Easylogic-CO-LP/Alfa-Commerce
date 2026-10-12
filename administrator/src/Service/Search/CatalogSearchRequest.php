<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

/**
 * Immutable description of a STOREFRONT-FACING search across several catalogue entities.
 *
 * Distinct from {@see SearchQuery}, and the distinction is the whole reason this class
 * exists: SearchQuery is what ONE engine is asked for ONE entity, carrying the principals
 * a provider filters by. This is what a SEARCH SURFACE asks for (a dropdown, a results
 * page, a filter box), spanning products, categories and manufacturers, each with its own
 * cap, plus the optional completions. One of these fans out into several of those.
 *
 * THIS DELIBERATELY DOES NOT ACCEPT A MODULE `Registry`. A factory reading
 * `show_categories` / `categories_limit` would move a module's parameter vocabulary into
 * the component, so the component could no longer serve a consumer that has no module
 * behind it, and the storefront filter input is exactly that consumer. Every caller maps
 * its own configuration to these named arguments instead; that mapping is a handful of
 * lines and it keeps the boundary honest.
 *
 * @since  1.6.0
 */
final class CatalogSearchRequest
{
    /**
     * @param string $keyword The keyword as typed, after hygiene; NOT normalised
     *                        or lowercased; {@see QueryNormalizer} owns that and
     *                        runs downstream, and the surface still needs the
     *                        original spelling to echo back.
     * @param string $lang Language tag in display form (e.g. `en-GB`).
     * @param int $productLimit Maximum products to return.
     * @param bool $withCategories Search categories too.
     * @param int $categoryLimit Maximum categories to return.
     * @param bool $withManufacturers Search manufacturers too.
     * @param int $manufacturerLimit Maximum manufacturers to return.
     * @param bool $withSuggestions Ask the active engine for completions. Honoured only
     *                              when the engine implements
     *                              {@see SuggestionProviderInterface}.
     * @param int $suggestionLimit Maximum completions to return.
     * @param bool $withLayouts Render item-field display surfaces (selectors, cart
     *                          layouts) on the products. FALSE suits a type-ahead
     *                          dropdown, which needs data only; a full results page
     *                          that renders add-to-cart controls needs TRUE.
     * @param bool $withImage Load product media so rows can carry a thumbnail.
     *                        FALSE skips the media batch entirely; a dropdown
     *                        that shows no pictures must not pay for them.
     * @param bool $withPrice Run the price batch so rows can carry the shopper's
     *                        own price (per user/group/currency). FALSE skips the
     *                        whole pricing pass, the expensive one.
     *
     * @since  1.6.0
     */
    public function __construct(
        public readonly string $keyword,
        public readonly string $lang,
        public readonly int $productLimit = 50,
        public readonly bool $withCategories = false,
        public readonly int $categoryLimit = 6,
        public readonly bool $withManufacturers = false,
        public readonly int $manufacturerLimit = 6,
        public readonly bool $withSuggestions = true,
        public readonly int $suggestionLimit = 8,
        public readonly bool $withLayouts = false,
        public readonly bool $withImage = false,
        public readonly bool $withPrice = false,
        /**
         * WHY this search is running. Defaults to the non-recording
         * {@see SearchIntent::TYPEAHEAD}: a surface that does not declare cannot create
         * analytics data. A live dropdown leaves this alone; a results page declares
         * {@see SearchIntent::SEARCH}.
         */
        public readonly SearchIntent $intent = SearchIntent::TYPEAHEAD,
    ) {
    }
}
