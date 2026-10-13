<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\Facet;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Model\CategoriesModel;
use Alfa\Component\Alfa\Site\Model\ItemsModel;
use Alfa\Component\Alfa\Site\Model\ManufacturersModel;

/**
 * THE list of filter keys a REQUEST is allowed to set. One file, read by every boundary.
 *
 * Why a whitelist and not "ignore it downstream"
 * `filter[<anything>]` used to be accepted verbatim. A key nobody reads has no effect on the
 * QUERY, so it looks harmless, and it is not, because three surfaces key off the mere PRESENCE
 * of a filter, not off its meaning:
 *
 *   - robots: {@see \Alfa\Component\Alfa\Site\View\Items\HtmlView::isFilteredPage()} asks
 *     {@see \Alfa\Component\Alfa\Site\Model\UrlListModel::getActiveFilters()}, so an invented key
 *     turned an indexable listing into `noindex, follow`;
 *   - hreflang: {@see \Alfa\Component\Alfa\Site\Helper\AssociationHelper::carriedState()}
 *     republishes the key into every `<link rel="alternate">`, so the invented key is handed to
 *     search engines as a real URL;
 *   - pagination hrefs, likewise built from the raw request bag.
 *
 * Anyone could therefore mint unlimited crawlable, self-referencing, deindexed variants of the
 * same page from the address bar. Ignoring an unknown key downstream cannot fix that: each new
 * surface would have to remember to ignore it again, and one that forgets reopens the hole
 * silently. REFUSING the key at the boundary fixes every surface at once and keeps fixing the
 * ones not written yet: the key never enters the system, so nothing downstream can leak it.
 *
 * Why the list is keyed on the model class
 * Never on the view and never on the route. The AJAX facet endpoint
 * (`modules/mod_alfa_filters/src/Helper/AlfaFiltersHelper.php:233-237`) builds the site
 * ItemsModel with `ignore_request => false` on a URL that is `option=com_ajax`, has NO view at
 * all, and carries an Itemid belonging to a different menu. A view-keyed or route-keyed list
 * would silently kill every AJAX facet refresh on the site.
 *
 * Per-model rather than one global set, because a global set leaks across listings: allowing
 * `manufacturers` (a ManufacturersModel key) everywhere lets `?filter[manufacturers][]=1` on the
 * items listing pass the gate, do nothing to the query, and still flip robots and hreflang:
 * exactly the defect this class exists to close.
 *
 * Warning: omitting a key silently kills a live filter
 * {@see \Alfa\Component\Alfa\Site\Model\UrlListModel} is the shared base of EVERY storefront list
 * model. A key missing from the row below is not rejected loudly; it is dropped, the listing
 * renders unfiltered, and the only symptom is a facet that quietly stops narrowing. So:
 *
 *   - a NEW filter key must be added here in the same commit that adds its reader;
 *   - a NEW UrlListModel subclass must get its own row here, or it gets NOTHING
 *     ({@see self::forModel()} defaults to deny). Subclasses of a listed model inherit its row.
 *
 * The list below is NOT derived from `getDefaults()`. That records only fields carrying a
 * non-empty `default` attribute, and zero filter fields in any of the three site filter forms
 * have one: a defaults-derived list would be empty and would kill the whole filter panel. It is
 * built from the `<field name="...">` definitions in `components/com_alfa/forms/filter_*.xml` plus
 * the URL-only keys that have a real `getState('filter....')` reader, each corroborated by a live
 * request. The audit that produced it, with the source and the live proof for every single row,
 * is `C1-WHITELIST.md` / `C1-whitelist.json`.
 *
 * Deliberate exclusions
 *   - `groups_mode`: grouping is permanently ANY/OR. See
 *     {@see \Alfa\Component\Alfa\Site\Model\ItemsModel::getListQuery()} ("GROUPING IS ALWAYS A
 *     UNION ... there is deliberately no second mode"). It has no reader anywhere, so its only
 *     effect was to flip robots and pollute hreflang. Refused, permanently.
 *   - `category_id`: read by ItemsModel, but never FROM `filter[]`: ItemsModel::populateState()
 *     overwrites it from the TOP-LEVEL `category_id` request var immediately after
 *     `parent::populateState()`. It stays a fully working INTERNAL state key; only the URL form
 *     is refused. This list governs the request branch only, never programmatic `setState()`.
 *
 * @since  2.0.0
 */
final class FilterKeyWhitelist
{
    /**
     * Model class => the filter keys that class accepts from the request.
     *
     * Every row is source-proven and live-proven in `C1-WHITELIST.md`. Keep the two in step.
     *
     * @var array<class-string, string[]>
     *
     * @since  2.0.0
     */
    private const KEYS = [
        ItemsModel::class => [
            // Form-declared (components/com_alfa/forms/filter_items.xml).
            'search',
            'category',
            'manufacturer',
            'price_min',
            'price_max',
            'on_sale',
            'discount_amount_min',
            'discount_percent_min',
            'stock',    // in / out: the view's radio, the module's checkboxes
            // URL-only: no form field, but a real reader in ItemsModel::getListQuery().
            'fields',   // nested map: filter[fields][<fieldId>][]=<valueId>
            'groups',   // nested map: filter[groups][<fieldId>][]=<token>
            'id',
        ],
        ManufacturersModel::class => [
            'search',         // forms/filter_manufacturers.xml
            'manufacturers',  // URL-only; whereIn a.id
        ],
        CategoriesModel::class => [
            'search',         // forms/filter_categories.xml
            'categories',     // URL-only; whereIn a.id, structural twin of 'manufacturers'
            'parent_id',      // URL-only; a.parent_id = (int) value
        ],
    ];

    /**
     * The view names that reach
     * {@see \Alfa\Component\Alfa\Site\Helper\AssociationHelper::carriedState()}, and the list
     * model whose keys are meaningful on them.
     *
     * `items` IS the ItemsModel listing. `item` and `manufacturer` are detail pages with no list
     * model of their own; the filter state carried off them belongs to the listing the visitor
     * came from, which is always the items listing. Every other view returns [] from
     * {@see \Alfa\Component\Alfa\Site\Helper\AssociationHelper::getAssociations()} before
     * carriedState() is ever reached (the manufacturers and categories listings among them) so
     * their keys can never need carrying.
     *
     * @var array<string, class-string>
     *
     * @since  2.0.0
     */
    private const CARRIED_STATE_VIEWS = [
        'items' => ItemsModel::class,
        'item' => ItemsModel::class,
        'manufacturer' => ItemsModel::class,
    ];

    /**
     * The keys the given list model accepts from the request.
     *
     * Walks up the inheritance chain so a subclass inherits its parent's row (the API's
     * CatalogitemsModel extends the site ItemsModel). An unlisted class gets NOTHING: default
     * deny is the whole point, and a new list model is expected to declare itself above.
     *
     * @param string $modelClass Fully-qualified model class name (pass `static::class`).
     *
     * @return string[] Allowed filter keys; [] when the class is not listed.
     *
     * @since  2.0.0
     */
    public static function forModel(string $modelClass): array
    {
        for ($class = $modelClass; $class !== false && $class !== ''; $class = get_parent_class($class)) {
            if (isset(self::KEYS[$class])) {
                return self::KEYS[$class];
            }
        }

        return [];
    }

    /**
     * The keys that may be carried into the language-switcher (hreflang) URLs of the given view.
     *
     * @param string $view The view name being associated.
     *
     * @return string[] Allowed filter keys; [] for a view that carries no filter state.
     *
     * @since  2.0.0
     */
    public static function forView(string $view): array
    {
        $modelClass = self::CARRIED_STATE_VIEWS[$view] ?? null;

        return $modelClass === null ? [] : self::forModel($modelClass);
    }

    /**
     * Is this request key accepted against that allow-set?
     *
     * Compares the DECODED key: `filter%5Bsearch%5D` and `filter[search]` are the same key by the
     * time PHP has parsed the query string, and the type-ahead palette emits the encoded form.
     * Numeric keys (`filter[0]=x`) arrive as int and must not match by coercion, hence the cast
     * plus strict comparison.
     *
     * @param int|string $key The key as it came out of the request bag.
     * @param string[] $allowed The allow-set from {@see self::forModel()} / {@see self::forView()}.
     *
     * @since  2.0.0
     */
    public static function accepts(int|string $key, array $allowed): bool
    {
        return \in_array((string) $key, $allowed, true);
    }
}
