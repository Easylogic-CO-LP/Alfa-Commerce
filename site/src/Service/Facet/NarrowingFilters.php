<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Facet;

defined('_JEXEC') or die;

/**
 * WHICH ACTIVE FILTERS MEAN "THE SHOPPER HAS LEFT THE CATEGORY BEHIND".
 *
 * Some filters REFINE the category the visitor is browsing; the rest REPLACE it. That
 * difference is the whole reason this class exists, because one surface has to act on it:
 * the subcategory strip on the items listing ({@see \Alfa\Component\Alfa\Site\View\Items\HtmlView}),
 * which the `hide_categories_when_filtered` param can drop the moment a filter replaces the
 * category context.
 *
 * Why the strip is a problem while filtered
 * The tiles are built by {@see \Alfa\Component\Alfa\Site\Model\ItemsModel::getItemsCategories()},
 * and their hrefs go through {@see \Alfa\Component\Alfa\Site\Model\UrlListModel::buildUrlWithListParams()},
 * which carries `list[fullordering]` and `list[limit]` and NOTHING ELSE. So a tile clicked
 * on a filtered listing silently discards every filter the visitor set. Showing tiles that
 * throw away the shopper's work is the defect; the param is the switch that closes it.
 *
 * A DENY-LIST, DELIBERATELY: THE OPPOSITE CHOICE FROM {@see FilterKeyWhitelist}
 * That class allow-lists, because an unknown key there is an attack surface. Here an unknown
 * key is a FILTER SOMEBODY ADDED, and the merchant's instruction is "every filter except
 * these". An allow-list would mean a colour facet shipped next release quietly stops hiding
 * the strip: a wrong answer nobody notices for months. A deny-list gets the new facet right
 * by default, and the only cost of being wrong is a strip that hides slightly too eagerly,
 * which is visible the first time anyone looks.
 *
 * What is exempt, and why each one
 * The five below all leave "which part of the catalogue am I in" untouched: they trim the
 * SAME category down by price or by discount, so the subcategories of that category are still
 * exactly where the shopper would go next:
 *
 *   price_min / price_max      Price
 *   on_sale                    On Sale (module) / Sale items (`COM_ALFA_FILTER_ON_SALE_LBL`):
 *                              one filter, two labels
 *   discount_amount_min        Minimum Discount Amount
 *   discount_percent_min       Minimum Discount Percentage
 *
 * Ordering and Number Of Products are exempt too, and need no row: they live in the form's
 * `list` group, and {@see \Alfa\Component\Alfa\Site\Model\UrlListModel::getActiveFilters()}
 * only ever walks the `filter` group, so they can never reach this class.
 *
 * PRESETS ARE NOT A SHOPPER'S FILTER. A menu landing's preset filters are applied through
 * `setState()` on a request that carries no `filter` param at all, and getActiveFilters()
 * reads the REQUEST bag, so a preset landing reports no active filters and keeps its strip.
 * That is the intended reading of "when a filter is applied": when the VISITOR applied one.
 * It is also the same line the robots rule already draws, so a page that keeps its tiles is
 * exactly a page that stays indexable.
 *
 * @since  2.0.0
 */
final class NarrowingFilters
{
    /**
     * Active filter keys that do NOT count as narrowing.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    private const EXEMPT = [
        'price_min',
        'price_max',
        'on_sale',
        'discount_amount_min',
        'discount_percent_min',
    ];

    /**
     * Has the visitor applied at least one filter that replaces the category context?
     *
     * @param array<string, mixed> $activeFilters As returned by
     *                                            {@see \Alfa\Component\Alfa\Site\Model\UrlListModel::getActiveFilters()}.
     *
     * @since  2.0.0
     */
    public static function present(array $activeFilters): bool
    {
        return self::only($activeFilters) !== [];
    }

    /**
     * The narrowing subset of an active-filter set, keys intact.
     *
     * @param array<string, mixed> $activeFilters As returned by
     *                                            {@see \Alfa\Component\Alfa\Site\Model\UrlListModel::getActiveFilters()}.
     *
     * @return array<string, mixed> The same set minus {@see self::EXEMPT}.
     *
     * @since  2.0.0
     */
    public static function only(array $activeFilters): array
    {
        return array_diff_key($activeFilters, array_flip(self::EXEMPT));
    }
}
