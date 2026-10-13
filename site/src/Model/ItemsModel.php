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

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListPrepareEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemListSurface;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ItemsListPrepareEvent;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\Surfaces;
use Alfa\Component\Alfa\Administrator\Event\ItemFields\ValueFacetGroupsEvent;
use Alfa\Component\Alfa\Administrator\Helper\ItemConfigurationHelper;
use Alfa\Component\Alfa\Administrator\Helper\ListLimitsHelper;
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Catalog\CatalogAccessPolicy;
use Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Administrator\Service\Projection\ItemFieldValueProjection;
use Alfa\Component\Alfa\Administrator\Service\Search\QueryNormalizer;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchEntity;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchIntent;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchProviderFactory;
use Alfa\Component\Alfa\Administrator\Service\Search\SearchQuery;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Helper\SaleTag;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Facet\FacetGroupResolver;
use Alfa\Component\Alfa\Site\Service\FieldState\StateBag;
use Alfa\Component\Alfa\Site\Service\FieldState\StateBagBuilder;
use Alfa\Component\Alfa\Site\Service\Pricing\ActiveDateCondition;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceContext;
use Alfa\Component\Alfa\Site\Service\Pricing\PricingIntent;
use Alfa\Component\Alfa\Site\Service\ShowOn\InitialState;
use Alfa\Component\Alfa\Site\Service\ShowOn\RuleEvaluator;
use Alfa\Component\Alfa\Site\Service\Variant\CombinationResolver;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Throwable;

/**
 * ItemsModel: Site-side catalog list model with price filtering.
 *
 * Price filter
 * The catalog filter reads from #__alfa_items_price_index via a context-aware
 * subquery restricted to the visitor's currency, place, and usergroups.
 * Column names in the index match the com_alfa config fields exactly.
 *
 * Available filter states
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
 * Live prices
 * Displayed prices on listing cards are computed live by PriceCalculator in
 * getItems(). The index is used ONLY for filtering, never for display.
 *
 * @since  1.0.0
 */
class ItemsModel extends UrlListModel
{
    /**
     * Candidate cap for a TYPEAHEAD search: a keystroke needs a ranking window, not the
     * candidate universe.
     *
     * Without it the provider runs at {@see SearchQuery}'s 1000 default on every
     * keystroke, and the enterprise prefix group makes two-character tokens genuinely
     * mass-match: retrieval scores, returns and FIELD()-orders a thousand ids so a
     * dropdown can show eight rows. An expressed SEARCH keeps the full default: a
     * results LISTING pages through its candidates, so truncating them would silently
     * shrink the catalogue's answer.
     *
     * FOUR TIMES THE LARGEST SECTION A CALLER MAY ASK FOR, and the headroom is the whole
     * point rather than caution: these are CANDIDATES, and the catalogue query downstream
     * still removes what the visitor may not see: unpublished, out of stock, outside
     * their access groups. A cap equal to the maximum requestable row count would let
     * that filtering silently underfill the dropdown, and the shopper would read the gap
     * as "no more results" rather than as rows they were never allowed.
     *
     * @var int
     * @since  2.0.0
     */
    private const TYPEAHEAD_CANDIDATE_LIMIT = 200;

    /**
     * Headroom added to the PROVEN pass bound in {@see self::resolveFacetShowon()}.
     *
     * The bound itself is derived, never guessed: a pass either changes the effective
     * field-state key or ends the loop, and pruning may only REMOVE whole field entries,
     * so at most one key-changing pass per selected field is possible. The budget is
     * therefore (selected fields) + this margin: one slot for the final pass that
     * observes no change, one spare. A site with fifty filterable fields gets fifty-two
     * passes, so no valid configuration can reach the guard by being large.
     *
     * @var int
     * @since  2.0.0
     */
    private const SHOWON_PASS_MARGIN = 2;

    /**
     * Field ids hidden by a ShowOn rule, resolved once per request. Null until
     * {@see self::resolveFacetShowon()} has run.
     *
     * @var int[]|null
     * @since  2.0.0
     */
    private ?array $facetShowonHidden = null;

    /**
     * Field ids whose ShowOn rule is INACTIVE because at least one operand it needs is
     * not a rendered facet. Their rule is not evaluated and is not published to the
     * browser, so the client cannot reach a different conclusion than the server did.
     *
     * @var int[]
     * @since  2.0.0
     */
    private array $facetShowonInactive = [];

    /**
     * Re-entrancy latch for the resolution loop; see resolveFacetShowon().
     *
     * @since  2.0.0
     */
    private bool $facetShowonResolving = false;

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

    /**
     * The search engine's candidate ids, IN RELEVANCE ORDER, for the query being built.
     *
     * Set by the search branch of getListQuery() and consumed by its ordering block, which is
     * the only reason it is state rather than a local: the two are far apart in one method and
     * the order must survive the distance. Empty whenever no search is active.
     *
     * @var int[]
     *
     * @since  2.0.0
     */
    protected array $relevanceIds = [];

    /**
     * The analytics handle the current search was recorded under, or null.
     *
     * Carried out of the provider so the SURFACE can declare the attribution edge on the
     * rendered result set. OPAQUE AND ENGINE-ISSUED (`int|string`; see
     * {@see SearchResult::$eventId}): the model carries it, never interprets it. Null
     * whenever the search was not recorded (a TYPEAHEAD lookup, a non-Enterprise
     * engine, or analytics switched off) and null must render no attribution at all
     * rather than a zero.
     *
     *
     * @since  2.0.0
     */
    protected int|string|null $searchEventId = null;

    /**
     * The analytics handle of the search behind the current result set.
     *
     * Read by the view so the template can declare the attribution edge. Only meaningful
     * after {@see self::getItems()} has run.
     *
     * @return int|string|null Engine-issued handle, or null when this result set is not
     *                         attributable.
     *
     * @since  2.0.0
     */
    public function getSearchEventId(): int|string|null
    {
        return $this->searchEventId;
    }

    /**
     * Filter names whose PRESET DEFAULT was applied this request (the visitor
     * has not touched them). Used to bind the seeded values into the panel.
     *
     * @var string[]
     * @since 2.0.0
     */
    protected array $appliedPresets = [];

    /**
     * Per-request memo of decoded #__alfa_categories.list_options, by category id.
     *
     * @var array<int, array<string, string>>
     * @since 2.0.0
     */
    private static array $categoryListOptions = [];

    /** @var PricingIntent|null */
    protected $pricingIntent = null;

    /** @var PriceCalculator|null */
    protected $priceCalculator = null;

    /** @var \Joomla\Registry\Registry|null */
    protected $appSettings = null;

    // Constructor

    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id',       'a.id',
                // Translatable: resolved via the lang-table COALESCE alias.
                'name',
                'ordering', 'a.ordering',
                'sku',      'a.sku',
                'stock',    'a.stock',
                'created',  'a.created',
                // Price-index columns: all three must be listed so that
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

    // State

    /**
     * Populate model state from the request.
     *
     * @param string|null $ordering
     * @param string|null $direction
     * @since  1.0.0
     */
    protected function populateState($ordering = null, $direction = null): void
    {
        parent::populateState($ordering, $direction);

        $this->setState('filter.category_id', $this->categoryId);

        // A REQUEST-DRIVEN LISTING IS AN EXPRESSED SEARCH, but only ONCE. Page 1 is the
        // shopper expressing a query; page 2, a sort change, any re-render of the same
        // results is that SAME search continued, and recording it again double-counts
        // volume and dilutes CTR with denominators that can never be clicked as searches.
        // So pagination declares CONTINUATION (not recorded); only the first page declares
        // SEARCH. A programmatic caller (CatalogSearchService) never sees this at all:
        // under `ignore_request` this method does not run, and the caller's own declared
        // intent is the only one the model carries.
        $this->setState(
            'search.intent',
            $this->app->getInput()->getInt('limitstart', 0) > 0
                ? SearchIntent::CONTINUATION->value
                : SearchIntent::SEARCH->value,
        );

        $this->applyMenuPresets();

        // ONE SHAPE FOR THE AVAILABILITY STATE. The view's radio posts a scalar, the
        // module's checkboxes a list; both then read back what was chosen: a radio
        // handed ['out'] shows nothing selected, and a shopper who ticked "Out of stock"
        // in the sidebar would see the bar above the products say "All". Read off the
        // registry, never getState(): inside populateState() that call recurses.
        $this->setState('filter.stock', self::stockChoice($this->state->get('filter.stock')));
    }

    /**
     * Resolve the listing's page size and sort order for this browsing context:
     * browsed CATEGORY -> active MENU ITEM -> global CONFIG -> the filter form.
     *
     * Only the untouched request is decided here. The visitor's own choice
     * arrives as `list[limit]` / `list[fullordering]` and is applied on top by
     * populateListState(), so a merchant default never takes a control away.
     *
     * The menu and config tiers cost nothing: SiteApplication::getParams()
     * merges the active menu item over the component params, and Registry
     * merging skips empty strings, which is exactly what the inherit option
     * stores. Only the category tier needs a read of its own.
     *
     * @param array $defaults The `list` defaults parsed from filter_items.xml
     *
     * @return array The defaults to use, same shape
     * @since  2.0.0
     */
    protected function contextListDefaults(array $defaults): array
    {
        // A programmatic sub-listing declares its own state; the visitor's
        // browsing context is none of its business.
        if ($this->ignoreRequest) {
            return $defaults;
        }

        $category = $this->getCategoryListOptions();
        $params = $this->app->getParams('com_alfa');

        // Digits-only per tier: the form rules guard saves, but the DB can be
        // written directly, and a garbage value casting to int 0 would MEAN
        // "show the whole catalogue". A corrupt tier falls through instead.
        $limit = self::firstConfigured(
            self::wholeNumberOrNull($category['items_limit'] ?? null),
            self::wholeNumberOrNull($params->get('items_limit')),
        );

        if ($limit !== null) {
            $defaults['limit'] = (string) (int) $limit;
        }

        $ordering = self::firstConfigured($category['items_ordering'] ?? null, $params->get('items_ordering'));

        if ($ordering !== null) {
            $defaults['fullordering'] = (string) $ordering;
        }

        return $defaults;
    }

    /**
     * The first tier of a chain that actually holds a value.
     *
     * "Not set" is null or the empty string ONLY; `'0'` is a legitimate
     * setting (a limit of 0 means "show all") and must never be skipped.
     *
     * @param mixed ...$candidates Tier values, most specific first
     *
     * @return mixed|null The winning value, or null when every tier is empty
     * @since  2.0.0
     */
    private static function firstConfigured(...$candidates)
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The value as a whole-number string, or null when it is anything else,
     * so an unvalidated stored value can only ever act as "a number" or
     * "not set", never as an accidental 0.
     *
     * @param mixed $value A tier's stored value
     *
     * @since  2.0.0
     */
    private static function wholeNumberOrNull($value): ?string
    {
        return \is_scalar($value) && preg_match('/^\d+$/', (string) $value) === 1
            ? (string) $value
            : null;
    }

    /**
     * Enforce the offered page sizes on the REQUEST value.
     *
     * With sizes configured, the merchant's menu is a contract, not a
     * suggestion: a URL value outside it is dropped, falling back to the
     * tier-resolved default. With no sizes configured, any POSITIVE size stays
     * free (the dropdown grafts it in), but "All" (0, and negatives, which
     * MySQL treats identically: no LIMIT clause, the entire catalogue with
     * per-row pricing, media and plugin work off one cheap GET) is requestable
     * ONLY where a merchant offers it via a 0 entry in the sizes. By default
     * no shop offers it. A merchant may still set 0 as a tier DEFAULT: that
     * is config, not a request, and is untouched here.
     *
     * @param array $list List input from URL
     * @param array $defaults Defaults from XML (already chain-resolved)
     *
     * @since  2.0.0
     */
    protected function populateListState(array $list, array $defaults): void
    {
        if (isset($list['limit']) && $list['limit'] !== '') {
            $requested = (int) $list['limit'];
            $limits = $this->getListLimits();

            $allowed = $limits ? \in_array($requested, $limits, true) : $requested > 0;

            if (!$allowed) {
                unset($list['limit']);
            }
        }

        parent::populateListState($list, $defaults);
    }

    /**
     * The browsed category's stored listing defaults.
     *
     * A dedicated one-column read rather than {@see self::getItemsCategory()}:
     * that path stamps the category's link via getListUrlParams(), which calls
     * getState(), and this runs from inside populateState(), so it would
     * re-enter it endlessly. Access-filtered like every other category read
     * (see CategoryHelper's id-oracle note), and memoised for the request
     * because the component view and the filters module each build their own
     * ItemsModel for the same page.
     *
     * @return array<string, string> Decoded list_options; empty when the
     *                               listing has no category context
     * @since  2.0.0
     */
    private function getCategoryListOptions(): array
    {
        if ($this->categoryId <= 0) {
            return [];
        }

        if (isset(self::$categoryListOptions[$this->categoryId])) {
            return self::$categoryListOptions[$this->categoryId];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('a.list_options'))
            ->from($db->quoteName('#__alfa_categories', 'a'))
            ->where($db->quoteName('a.id') . ' = :id')
            ->where($db->quoteName('a.state') . ' = 1')
            ->bind(':id', $this->categoryId, ParameterType::INTEGER);

        (new CatalogAccessPolicy())->applyToCategories($query, 'a');

        $json = $db->setQuery($query)->loadResult();

        return self::$categoryListOptions[$this->categoryId] = (array) (json_decode((string) $json, true) ?: []);
    }

    /**
     * The page sizes the shopper may pick, resolved on the same chain as the
     * default: browsed category -> menu item -> global config. Empty = nothing
     * configured anywhere, and the storefront dropdown keeps its stock sizes.
     *
     * @return int[] Clean ascending sizes, 0 meaning "All"
     * @since  2.0.0
     */
    public function getListLimits(): array
    {
        if ($this->ignoreRequest) {
            return [];
        }

        $tiers = [
            $this->getCategoryListOptions()['items_limits'] ?? null,
            $this->app->getParams('com_alfa')->get('items_limits'),
        ];

        foreach ($tiers as $raw) {
            if ($raw === null || $raw === '' || !\is_scalar($raw)) {
                continue;
            }

            // A tier that stores something but parses to nothing (",,", junk
            // written past the form) is garbage, not a configuration: fall
            // through rather than letting it silently blank the chain.
            if ($sizes = ListLimitsHelper::parse((string) $raw)) {
                return $sizes;
            }
        }

        return [];
    }

    /**
     * Apply the menu item's preset filters as DEFAULTS.
     *
     * Presets are not a separate filtering mechanism: they seed the same
     * pipeline the visitor's URL filters use. They apply ONLY on an untouched
     * request (no filter params at all: the landing's clean URL); as soon as
     * the request carries an explicit filter state, that state is the whole
     * truth and URL ownership is decided by getCanonicalMenuUrl(): the menu
     * items are always respected. XOR guard: while a route category identity
     * exists it owns the category axis, so a stored categories preset is
     * ignored.
     *
     * @since  2.0.0
     */
    private function applyMenuPresets(): void
    {
        // The request carries an explicit filter state: it IS the state.
        if ($this->app->getInput()->get('filter') !== null) {
            return;
        }

        foreach ($this->getMenuPresets() as $name => $value) {
            if ($name === 'category' && $this->categoryId) {
                continue;
            }

            $this->setState('filter.' . $name, $this->normalizeFilterValue($value, $name));
            $this->appliedPresets[] = $name;
        }
    }

    /**
     * The active menu item's non-empty preset filters, normalised.
     *
     * @param \Joomla\CMS\Menu\MenuItem|null $item Menu item (default: the active one's params via getParams)
     *
     * @return array<string, mixed> filter name => preset value
     * @since  2.0.0
     */
    private function getMenuPresets($item = null): array
    {
        $presets = $item ? $item->getParams()->get('presets') : $this->app->getParams('com_alfa')->get('presets');

        if (empty($presets)) {
            return [];
        }

        $clean = [];

        foreach ((array) (json_decode(json_encode($presets), true)['filter'] ?? []) as $name => $value) {
            if (\is_array($value)) {
                $value = array_filter($value, static fn ($v) => $v !== '' && $v !== null);
            }

            if ($value === '' || $value === null || $value === []) {
                continue;
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    /**
     * "Always respect the menu items": the menu item owning the CURRENT filter
     * state, as a redirect URL when it is not the active one.
     *
     * Ownership: a preset landing owns a state when every preset matches it
     * exactly; extra non-preset filters ride along as refinement. The most
     * specific owner (largest matching preset set) wins; the plain listing
     * (no presets) owns everything else. Deviating from a landing's preset,
     * changing OR clearing it, therefore moves the visitor to the owner's
     * SEO URL with the full state in the query, and composing a landing's
     * exact presets anywhere moves them to the landing's clean URL.
     *
     * Only plain listing menus participate (identity landings are owned by
     * their ROUTE already); requests without filter params never redirect.
     *
     * @return string|null Route to redirect to, or null when already canonical
     * @since  2.0.0
     */
    public function getCanonicalMenuUrl(): ?string
    {
        $input = $this->app->getInput();

        // Untouched request, or a category route: nothing to re-own.
        if ($input->get('filter') === null || $this->categoryId) {
            return null;
        }

        $active = $this->app->getMenu()->getActive();

        if (!$active || ($active->query['option'] ?? '') !== 'com_alfa' || ($active->query['view'] ?? '') !== 'items') {
            return null;
        }

        $state = [];

        foreach ($input->get('filter', [], 'array') as $name => $value) {
            // Keyed normalisation, same as populateState(): a preset and the request state must be
            // compared in ONE shape or a single-value list filter never matches its own landing.
            $normalized = $this->normalizeFilterValue($value, $name);

            if ($normalized !== '') {
                $state[$name] = $normalized;
            }
        }

        // Candidates: published com_alfa items menus of this language, no identity.
        $language = $this->app->getLanguage()->getTag();
        $best = null;
        $bestSize = -1;

        foreach ($this->app->getMenu()->getMenu() as $item) {
            if (
                ($item->query['option'] ?? '') !== 'com_alfa'
                || ($item->query['view'] ?? '') !== 'items'
                || !empty($item->query['category_id'])
                || !\in_array($item->language, ['*', $language], true)
            ) {
                continue;
            }

            $presets = $this->getMenuPresets($item);
            $matches = true;

            foreach ($presets as $name => $value) {
                $preset = $this->normalizeFilterValue($value, $name);
                $current = $state[$name] ?? '';

                if (json_encode($preset) !== json_encode($current)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches && \count($presets) > $bestSize) {
                $best = $item;
                $bestSize = \count($presets);
            }
        }

        if (!$best || (int) $best->id === (int) $active->id) {
            return null;
        }

        // The owner's presets live in its clean URL; only the rest is query.
        $leftover = array_diff_key($state, $this->getMenuPresets($best));
        $url = 'index.php?option=com_alfa&view=items&Itemid=' . (int) $best->id;

        if ($leftover) {
            $url .= '&' . http_build_query(['filter' => $leftover]);
        }

        return Route::_($url, false);
    }

    /**
     * Bind the seeded preset defaults into the shared filter form so the panel
     * shows them selected: loadFormData() binds URL filters only. Flows into
     * every consumer of this form (component panel and mod_alfa_filters alike);
     * the fields stay fully interactive, presets are just their initial state.
     *
     * @param array $data Data for the form
     * @param bool $loadData True to load the form's own data
     *
     * @return \Joomla\CMS\Form\Form|null The filter form
     * @since  2.0.0
     */
    public function getFilterForm($data = [], $loadData = true)
    {
        $form = parent::getFilterForm($data, $loadData);

        if (!$form) {
            return $form;
        }

        // Ensure populateState ran so appliedPresets is authoritative.
        $this->getState();

        // The item-field facets are the one filter a merchant may want OUT of the
        // inline bar while keeping the rest of it: mod_alfa_filters usually shows
        // them in a sidebar, and the bar would then repeat them. Removing the FORM
        // field affects only this bar: the module renders its facets from
        // getAvailableItemFieldValues(), never from this form.
        //
        // getParams() exists only on SiteApplication and this model also runs
        // headlessly (API catalogue, search service); same fallback the category
        // filter uses above.
        $app = \Joomla\CMS\Factory::getApplication();
        $facetsParams = method_exists($app, 'getParams')
            ? $app->getParams('com_alfa')
            : ComponentHelper::getParams('com_alfa');

        if (!$facetsParams->get('show_items_field_facets', 1)) {
            $form->removeField('fields', 'filter');
        }

        foreach ($this->appliedPresets as $name) {
            $form->setValue($name, 'filter', $this->getState('filter.' . $name));
        }

        // Merchant-configured page sizes -> the dropdown's option list. The
        // field is core-limitbox-shaped, so this is just its `limits` attribute;
        // "All" travels as a 0 entry and becomes the showall flag (core appends
        // its own 0 AFTER the list; passing ours through would double it).
        // Whatever limit is actually IN EFFECT must stay selectable in BOTH
        // branches, or the dropdown misreports the page: the free-number
        // default may be any figure (9 for a 3-across grid), inside or outside
        // the stock/configured sizes.
        $active = (int) $this->getState('list.limit');

        if ($limits = $this->getListLimits()) {
            $sizes = array_values(array_filter($limits));
            $showAll = \in_array(0, $limits, true) || $active === 0;

            if ($active > 0 && !\in_array($active, $sizes, true)) {
                $sizes[] = $active;
                sort($sizes);
            }

            // All-only ("0"): core still needs a non-empty limits attribute to
            // stop it printing its built-in sizes; its 0 dedupes downstream.
            $form->setFieldAttribute('limit', 'limits', implode(',', $sizes ?: [0]), 'list');
            $form->setFieldAttribute('limit', 'showall', $showAll ? 'true' : 'false', 'list');
        } elseif ($active > 0) {
            // No sizes configured: keep the stock dropdown but graft the active
            // value in (core merges `append` into its defaults and de-dupes, so
            // a stock value is harmless; ListLimitboxField supplies the label
            // core has no Jnn key for).
            $form->setFieldAttribute('limit', 'append', (string) $active, 'list');
        } elseif ($active === 0) {
            // A merchant chose "all on one page" as a tier DEFAULT without
            // offering sizes: the state must stay expressible, so this one
            // shop gets the All option back.
            $form->setFieldAttribute('limit', 'showall', 'true', 'list');
        }

        return $form;
    }

    // Pagination

    /**
     * The link base, extended with the navigation category this listing is routed under.
     *
     * The category is ROUTE, not filter (the router folds it back into the alias) so it is
     * declared with the rest of the address rather than appended to the pagination object after
     * it was built. `filter[category]` is a different thing entirely and still travels with the
     * filters, in UrlListModel::getPagination().
     *
     * @return array<string, mixed>
     * @since   1.0.0
     */
    protected function getLinkBase(): array
    {
        $base = parent::getLinkBase();

        if ($this->categoryId) {
            $base['category_id'] = (int) $this->categoryId;
        }

        return $base;
    }

    // Category helpers

    /**
     * Return subcategories of the current navigation category.
     * @since  1.0.0
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
     * @since  1.0.0
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

    // Pricing dependency injection

    /**
     * Override the pricing intent (default: PricingIntent::catalog()).
     *
     * @return $this
     * @since  1.0.0
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
     * @since  1.0.0
     */
    public function setPriceCalculator(PriceCalculator $calculator): self
    {
        $this->priceCalculator = $calculator;
        return $this;
    }

    /**
     * Declare the listing context of a programmatic listing (`ignore_request`):
     * the category whose layouts and links every row inherits, and the category
     * axis of the query. The request path derives it from the route; a caller
     * that scopes a listing to one category (the items module's per-category
     * tabs) declares it here. Descendants follow the include_subcategories
     * setting, exactly as on a category page.
     *
     * @param int $categoryId The category id (0 = no context: a mixed listing).
     *
     * @return $this
     * @since  2.0.0
     */
    public function setCategoryContext(int $categoryId): self
    {
        $this->categoryId = max(0, $categoryId);
        $this->setState('filter.category_id', $this->categoryId);

        return $this;
    }

    /**
     * Lazy-load the price calculator.
     * @since  1.0.0
     */
    protected function getPriceCalculator(): PriceCalculator
    {
        if ($this->priceCalculator === null) {
            $this->priceCalculator = new PriceCalculator();
        }
        return $this->priceCalculator;
    }

    // Query building

    /**
     * Build the main catalog list query with price filter support.
     *
     * PRICE INDEX SUBQUERY (alias: pf)
     * Aggregates the index down to one row per item for the current visitor:
     *   MIN(final_price)              -> best price this visitor can get
     *   MAX(discount_amount)          -> largest absolute saving available
     *   MAX(discount_percent)         -> highest % saving available
     *   MIN(base_price_with_tax)      -> undiscounted "was" price
     *   MIN(base_price_with_discounts)-> ex-VAT net price
     *   MIN(tax_amount)               -> tax amount
     *
     * All supported filter conditions
     *   filter.price_min            -> pf.final_price >= X
     *   filter.price_max            -> pf.final_price <= X
     *   filter.on_sale              -> pf.discount_amount > 0
     *   filter.discount_amount_min  -> pf.discount_amount >= X
     *   filter.discount_percent_min -> pf.discount_percent >= X
     *
     * @return \Joomla\Database\QueryInterface
     * @since  1.0.0
     */
    public function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // DERIVED PER BUILD, NEVER ACCUMULATED. One model instance answers several queries in a
        // request (getItems and getTotal both build one, and the search module reuses the model),
        // so a relevance order left over from an earlier build would be applied to a later query
        // that has no search at all: ordering rows by ids that are not even in its WHERE.
        $this->relevanceIds = [];
        $this->searchEventId = null;

        // Make the ShowOn-pruned selection effective before anything reads
        // filter.fields: a facet hidden by a rule must not filter the results.
        $this->resolveFacetShowon();

        // Select
        // Lightweight: category_ids / manufacturer_ids via the LEFT JOINs below.
        // The item-fields data (fields/options/combinations/informational) is
        // loaded by item id through ItemFieldsHelper in getItems() (same pattern
        // as MediaHelper) so the catalog query stays lean.
        $query->select($this->getState(
            'list.select',
            'DISTINCT a.*,
             GROUP_CONCAT(cat.category_id    ORDER BY cat.category_id    ASC) AS category_ids,
             GROUP_CONCAT(man.manufacturer_id ORDER BY man.manufacturer_id ASC) AS manufacturer_ids',
        ));

        // Base tables
        // The categories a row advertises are filtered to what the visitor may see.
        // `category_ids` is GROUP_CONCAT'd from this join and, in the JSON view, is
        // returned verbatim together with the resolved names, so an unfiltered join
        // publishes the id and name of every restricted category a visible product
        // happens to sit under.
        $visibleCategories = $this->getDatabase()->getQuery(true)
            ->select($this->getDatabase()->quoteName('vc.id'))
            ->from($this->getDatabase()->quoteName('#__alfa_categories', 'vc'))
            ->where($this->getDatabase()->quoteName('vc.state') . ' = 1');
        (new CatalogAccessPolicy())->applyToCategories($visibleCategories, 'vc');

        $query->from('#__alfa_items AS a')
            ->join(
                'LEFT',
                '#__alfa_items_categories    AS cat ON a.id = cat.item_id'
                . ' AND cat.category_id IN (' . (string) $visibleCategories . ')',
            )
            ->join('LEFT', '#__alfa_items_manufacturers AS man ON a.id = man.item_id');

        // Architecture B price index subquery JOIN
        $this->applyPriceIndexJoin($query);

        // MULTILINGUAL: resolve name / alias in the active language
        // 1:1 LEFT JOIN on the PK, so it does not affect the GROUP BY a.id below.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            // `short_desc` joined the LIST projection for the search surfaces: the
            // typed hit's `description` and the dropdown read it off these rows, and
            // without it every product description on the wire was structurally ''.
            fields:            ['name', 'alias', 'short_desc', 'stock_low_message', 'stock_zero_message'],
        );

        // Published items only, within the active publish window
        // Empty publish dates are stored NULL. The moment is Joomla's UTC clock,
        // NOT the database's NOW(): publish_up is stored in UTC and this host's DB
        // session runs in local time, so NOW() put every scheduled product on sale
        // three hours early. The same clock feeds the price index below and the
        // product page, so scheduled/expired items hide consistently everywhere.
        $now = $db->quote(ActiveDateCondition::now());

        $query->where('a.state = 1')
            ->where('(a.publish_up IS NULL OR a.publish_up <= ' . $now . ')')
            ->where('(a.publish_down IS NULL OR a.publish_down >= ' . $now . ')');

        // Who may see this product
        // The merchant's per-product "allowed users / user groups" allowlist. It was
        // settable and stored for a long time while NO listing enforced it, so a
        // restricted product still appeared here; the rule now lives in one policy
        // that every catalogue reader shares.
        (new CatalogAccessPolicy())->applyToItems($query, 'a');

        // Hide-out-of-stock asks the shared stock rule over the ledger. Delegated to
        // the policy because the literal predicate compared the STORED value: an item
        // left on the -1 sentinel ("follow the shop") never matched 2, so a shop set to
        // hide out-of-stock products kept listing every product that inherited that
        // setting.
        //
        // Only the boolean true in the state 'stock_visibility.lifted' lifts it, and
        // only the cart hydrator's second read (ignore_request) sets that: a product
        // already in a cart is still read, so its line shows as out of stock rather
        // than withdrawn. No visitor can lift the rule: the request populates only
        // the filter and list states, and a request value is never a boolean. State,
        // publish window and access above still apply, and the visitor's in/out
        // filter below is unaffected.
        if ($this->getState(property: 'stock_visibility.lifted') !== true) {
            (new StockVisibilityPolicy())->applyToItems($query, 'a');
        }

        // Search
        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                // Two-stage: a SearchProviderInterface implementation (indexed
                // SqlLike, or the name-LIKE fallback while the index is cold)
                // resolves CANDIDATE ids from the search document; the catalog
                // query then filters them by visibility / price / etc. The factory
                // hides which provider is in use, so the model never branches on
                // index availability.
                $keyword = (new QueryNormalizer())->normalize((string) $search);

                // A whitespace-only or too-short keyword is NOT "a search that
                // matched nothing": it is no search at all. Emitting 0 = 1 for it
                // blanks the whole catalog for a stray space or one typed letter.
                if ($keyword !== '') {
                    $intent = SearchIntent::fromDeclared($this->getState('search.intent'));

                    $result = (new SearchProviderFactory())->getCatalogProvider()->search(
                        // Carries the visitor's principals, so an engine that filters
                        // natively resolves visibility itself instead of returning
                        // candidates for the catalogue query below to discard. The
                        // Indexed SQL engine ignores them: that query is the filter.
                        SearchQuery::forCurrentVisitor(
                            $keyword,
                            MultilingualHelper::getCurrentLanguageTag(),
                            limit:  $intent === SearchIntent::TYPEAHEAD
                                ? self::TYPEAHEAD_CANDIDATE_LIMIT
                                : 1000,
                            intent: $intent,
                        ),
                    );

                    // The result must answer in the domain it was asked in: this model
                    // declared ITEM (the query default) and hydrates `a.id` with what
                    // comes back, so ids of any other entity here would silently show
                    // wrong products. A mismatch is a provider bug: treat it as no
                    // results rather than render someone else's rows.
                    $ids = $result->entity === SearchEntity::ITEM ? $result->ids() : [];

                    if ($result->entity !== SearchEntity::ITEM) {
                        Log::add(
                            \sprintf('Search provider answered entity "%s" to an item query.', $result->entity->value),
                            Log::ERROR,
                            'com_alfa',
                        );
                    } else {
                        $this->searchEventId = $result->eventId;
                    }

                    if (empty($ids)) {
                        $query->where('0 = 1');
                    } else {
                        $ids = array_map('intval', $ids);

                        $query->whereIn('a.id', $ids);

                        // ORDERING IS PART OF THE ANSWER, NOT A HINT. The engine returns its
                        // candidates in RELEVANCE ORDER: that is the entire meaning of
                        // SearchResult::$ids, and it is where field weights (a SKU hit above
                        // a title hit above a description hit) and provenance discounts (a
                        // synonym or a corrected spelling counting for less than the word the
                        // customer typed) actually live. Keeping the ids and then sorting the
                        // catalogue by `a.id DESC` discarded all of it: the engine's ranking
                        // decided only WHICH products survived truncation, never the order they
                        // were shown in, so a shop with "weighted ranking" presented its search
                        // results newest-first. This hands the order to the ordering block below.
                        $this->relevanceIds = $ids;
                    }
                }
            }
        }

        // ID filter (wishlist, featured items)
        $filterIds = $this->getState('filter.id', []);
        if (!empty($filterIds)) {
            $query->whereIn('a.id', (array) $filterIds);
        }

        // Category filter
        $categoryId = $this->getState('filter.category_id');
        $filterCategories = $this->getState('filter.category', []);
        // Menu item can override the global default (merged params include the active menu override).
        // getParams() exists only on SiteApplication, and this model is also driven
        // headlessly (API catalogue, search service) where there is no menu to merge
        // - fall back to the component params so those callers do not fatal.
        $app = \Joomla\CMS\Factory::getApplication();
        $includeSubcategories = method_exists($app, 'getParams')
            ? $app->getParams('com_alfa')->get('include_subcategories', 1)
            : ComponentHelper::getParams('com_alfa')->get('include_subcategories', 1);

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

        // Manufacturer filter
        $filterManufacturers = $this->getState('filter.manufacturer', []);
        if (!empty($filterManufacturers)) {
            $query->whereIn('man.manufacturer_id', array_map('intval', (array) $filterManufacturers));
        }

        // Item-field value filter (Size, Color, ...)
        // AND across fields, OR within a field. Index-driven EXISTS: field
        // filtering is enforced ONLY here; the projection collector never
        // re-filters, so there is no double-filtering. Composes with GROUP BY a.id.
        foreach ((array) $this->getState('filter.fields', []) as $fieldId => $valueIds) {
            $query->where($this->itemHasFieldValueSql((int) $fieldId, (array) $valueIds));
        }

        // Facet-group filter: a SECOND axis over the same field
        // A group is expanded to the value ids that belong to it and handed to the very
        // same EXISTS primitive, so the index and its semantics are untouched: a group
        // never reaches the query as anything but value ids, and the component never needs
        // to know what the group MEANS.
        //
        // GROUPING IS ALWAYS A UNION. Every chosen group contributes its member value ids
        // to ONE EXISTS, so several groups read as OR: the same "OR within a field" rule
        // the value axis already obeys, applied one level up. There is deliberately no
        // second mode: an all-groups-must-match variant existed, was configured per module
        // rather than per field, and could only ever narrow a single-valued attribute to
        // nothing. Both axes may still be active on one field and compose as AND, like any
        // two filters.
        foreach ((array) $this->getState('filter.groups', []) as $fieldId => $tokens) {
            $fieldId = (int) $fieldId;
            $tokens = array_values(array_unique(array_filter(array_map('strval', (array) $tokens))));

            if ($fieldId <= 0 || empty($tokens)) {
                continue;
            }

            $map = $this->fieldGroupMap($fieldId);
            $union = [];

            foreach ($tokens as $token) {
                $union = array_merge($union, $map[$token]['valueIds'] ?? []);
            }

            // A group nothing belongs to can never be satisfied: say so, rather than
            // letting an empty id list degrade into "1 = 1" and match everything.
            $query->where(
                empty($union) ? '0 = 1' : $this->itemHasFieldValueSql($fieldId, array_values(array_unique($union))),
            );
        }

        // Price range filter
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
            // A ceiling alone, as a floor or a range: only products with a figure
            // for the visitor's set. One without (no price, or a set not yet
            // prepared) has nothing to compare and is not listed.
            $query->where('pf.final_price <= ' . (float) $priceMax);
        }

        // On sale filter
        // discount_amount > 0 is the canonical test; no separate has_discount column needed.
        if ($this->getState('filter.on_sale')) {
            $query->where('pf.discount_amount > 0');
        }

        // Availability
        // The visitor's own "in stock / out of stock", judged by the very sellability
        // rule hide-out-of-stock uses, so the two never disagree about a product.
        // Both picked (or neither) is no restriction: the facet's usual OR.
        $stock = self::stockChoice($this->getState('filter.stock'));

        if ($stock !== '') {
            $sellable = (new StockVisibilityPolicy())->sellableExpression('a');
            $query->where($stock === 'in' ? $sellable : 'NOT ' . $sellable);
        }

        // Minimum discount amount filter
        // "Show items where the visitor saves at least €X."
        $discountAmountMin = $this->getState('filter.discount_amount_min');
        if (is_numeric($discountAmountMin) && (float) $discountAmountMin > 0) {
            $query->where('pf.discount_amount >= ' . (float) $discountAmountMin);
        }

        // Minimum discount percentage filter
        // "Show items discounted by at least X%."
        $discountPercentMin = $this->getState('filter.discount_percent_min');
        if (is_numeric($discountPercentMin) && (float) $discountPercentMin > 0) {
            $query->where('pf.discount_percent >= ' . (float) $discountPercentMin);
        }

        // Ordering
        $orderCol = $this->state->get('list.ordering', 'id');
        $orderDirn = $this->state->get('list.direction', 'DESC');

        // RELEVANCE WINS ONLY WHERE NOTHING ELSE WAS ASKED FOR. A search is active and the
        // visitor has not chosen a sort, so the best answer to "show me these" is the engine's
        // order. An EXPLICIT choice always wins: someone who picked "price, low to high" means
        // it, and isDefault() is what tells the two apart honestly (a sort carried in the URL or
        // remembered in state is not the fallback). No "Relevance" sort option is introduced
        // here: making relevance a selectable mode raises questions (is it the default, does
        // leaving it disable merchandising, how does personalisation override it) that belong to
        // the ranking layer, not to a fix restoring an order that was already being computed.
        // TWO TESTS, because neither alone is honest. isDefault() cannot tell an explicit
        // "newest first" from the fallback (they are the same string, `a.id DESC`) so a
        // shopper who deliberately chose it would silently get relevance instead. The request
        // settles it: searchtools submits list[fullordering] whenever a sort is actually chosen,
        // so its PRESENCE means "asked for", whatever the value. Comparing to the default still
        // matters as well, for a sort remembered in state from an earlier page.
        $requestedList = (array) $this->app->getInput()->get('list', [], 'array');
        $explicitlySorted = ($requestedList['fullordering'] ?? '') !== '';

        // EMPTY STATE COUNTS AS "NOBODY ASKED", and that arm exists for the SERVICE path:
        // a programmatic caller (`ignore_request`: CatalogSearchService, the dropdowns, the
        // API) never populates `list.fullordering`, so the state is null there and comparing
        // it to the request-path default made this gate silently FALSE: the engine's order
        // was computed and then discarded in favour of the model's `a.id DESC` fallback on
        // every service search since the extraction. A caller that DOES want a specific
        // order still gets it by setting the state, which is non-empty and non-default.
        $stateOrdering = (string) $this->getState('list.fullordering', '');

        $relevanceOrder = $this->relevanceIds !== []
            && !$explicitlySorted
            && ($stateOrdering === '' || $this->isDefault('list', 'fullordering', $orderCol . ' ' . $orderDirn));

        if ($relevanceOrder) {
            // FIELD() maps each id to its position in the engine's list. Ids are integers cast
            // at the point they were captured, so the expression is injection-safe by type, and
            // the list is bounded by the engine's own candidate limit.
            $query->order('FIELD(' . $db->quoteName('a.id') . ',' . implode(',', $this->relevanceIds) . ')');
        } elseif ($orderCol && $orderDirn) {
            if ($orderCol === 'price' || $orderCol === 'pf.price') {
                $orderCol = 'pf.final_price';
            }

            // By a figure of the price index: a product with none for the visitor's
            // set (no price, or a set not yet prepared) comes after every product
            // that has one, in either direction; those, and equal figures, by id.
            if (str_starts_with($orderCol, 'pf.')) {
                $query->order($db->quoteName($orderCol) . ' IS NULL ASC');
                $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));
                $query->order($db->quoteName('a.id') . ' ASC');
            } else {
                $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));
            }
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
     * @since  1.0.0
     */
    protected function applyPriceIndexJoin($query): void
    {
        // Same UTC clock as the publish window in getListQuery(): the index's
        // valid_from / valid_until are written by PriceIndexSyncService with
        // Factory::getDate()->toSql(), so a promo segment must open on that
        // clock, not on the DB's local-time NOW().
        $now = Factory::getContainer()->get('DatabaseDriver')->quote(ActiveDateCondition::now());

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

        // Groups: the slots of the visitor's own set of pricing groups, priced
        // with exactly the groups of theirs that change a price; a set whose
        // preparation has not finished is not read at all.
        $setJoin = PriceIndexSyncService::slotSetJoin(context: $context, alias: 'pi');

        // Subquery: all column aliases match index column names.
        //
        // Per (item x combination) the visitor may match SEVERAL context
        // slots (his place AND any place, his currency AND any). The displayed
        // card price comes from the live engine with his FULL context, which
        // corresponds to the slot of his own set of pricing groups and of his
        // own currency and place. The index says how its slots are ranked:
        // taking the ranking from there keeps the filter and the card telling
        // the same number. Across combinations the outer MIN() stands: an item
        // matches the slider when ANY variant does.
        $ranking = PriceIndexSyncService::slotRanking(alias: 'pi');

        $subquery = "
            (
                SELECT
                    ranked.item_id,
                    MIN(ranked.final_price)                  AS final_price,
                    MAX(ranked.discount_amount)              AS discount_amount,
                    MAX(ranked.discount_percent)             AS discount_percent,
                    MIN(ranked.base_price_with_tax)          AS base_price_with_tax,
                    MIN(ranked.base_price_with_discounts)    AS base_price_with_discounts,
                    MIN(ranked.tax_amount)                   AS tax_amount
                FROM (
                    SELECT
                        pi.item_id, pi.final_price, pi.discount_amount,
                        pi.discount_percent, pi.base_price_with_tax,
                        pi.base_price_with_discounts, pi.tax_amount,
                        ROW_NUMBER() OVER (
                            PARTITION BY pi.item_id, pi.combination_id
                            ORDER BY {$ranking}
                        ) AS context_rank
                    FROM #__alfa_items_price_index pi
                    {$setJoin}
                    WHERE {$currencyCond}
                      AND {$placeCond}
                      AND pi.valid_from <= {$now}
                      AND (pi.valid_until IS NULL OR pi.valid_until > {$now})
                ) AS ranked
                WHERE ranked.context_rank = 1
                GROUP BY ranked.item_id
            ) AS pf";

        $query->join('LEFT', $subquery . ' ON a.id = pf.item_id');
    }

    /**
     * Build the EXISTS fragment matching items that carry any of the given
     * values for one item-field (the catalog filter's per-field test).
     *
     * Sanitisation lives here so call sites stay trivial and never reimplement
     * it. Operands are integer-cast before interpolation, so the fragment is
     * injection-safe; an empty value set degrades to a harmless no-op so filter
     * removal (which can yield an empty array) never produces invalid SQL.
     *
     * @param int $fieldId The field to constrain.
     * @param int[] $valueIds The accepted value ids (OR within the field).
     *
     * @return string A boolean SQL expression for QueryInterface::where().
     * @since  2.0.0
     */
    private function itemHasFieldValueSql(int $fieldId, array $valueIds): string
    {
        $valueIds = array_values(array_filter(array_map('intval', $valueIds)));

        if ($fieldId <= 0 || empty($valueIds)) {
            return '1 = 1';
        }

        // The projection owns both sides of its index: it writes the rows and it
        // answers questions about them, so the admin grid filters on exactly the
        // same expression this page does.
        return ItemFieldValueProjection::existsSql(itemAlias: 'a', fieldId: $fieldId, valueIds: $valueIds);
    }

    // Get items with live prices

    /**
     * Return paginated items with live-computed prices and enriched associations.
     *
     * Prices are always computed LIVE by PriceCalculator; the index is used
     * only for filtering. This guarantees 100% accurate display prices.
     *
     * @return array
     * @since  1.0.0
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        // Batch DB lookups
        $allCategoryIds = [];
        $allManufacturerIds = [];

        foreach ($items as $item) {
            $allCategoryIds = array_merge($allCategoryIds, $this->extractIds($item->category_ids));
            $allManufacturerIds = array_merge($allManufacturerIds, $this->extractIds($item->manufacturer_ids));
        }

        $categoriesMapping = $this->getRecordsByIds($allCategoryIds, '#__alfa_categories', ['name'], mainFields: ['layouts']);
        $manufacturersMapping = $this->getRecordsByIds($allManufacturerIds, '#__alfa_manufacturers', ['name']);

        // Decode each category's layout overrides once; rows only reference them.
        foreach ($categoriesMapping as &$categoryRecord) {
            $categoryRecord['layouts'] = json_decode(($categoryRecord['layouts'] ?? '') ?: '{}', true) ?: [];
        }

        unset($categoryRecord);

        // LOCKED SPEC. Card source is deterministic, no configuration:
        //   category page  -> the VIEWED category decides for every row;
        //   mixed listing  -> each row's own DEFAULT category decides;
        //   an item's own value always wins; '' falls to menu/global in the view.
        $viewedCategoryLayouts = $this->categoryId ? ($this->getItemsCategory()->layouts ?? []) : [];

        // Batch live-price calculation
        $settings = ComponentHelper::getParams('com_alfa');
        $intent = $this->pricingIntent ?? PricingIntent::catalog();
        $productIds = [];
        $quantities = [];

        foreach ($items as $item) {
            $productIds[] = $item->id;
            $quantities[$item->id] = $intent->getQuantityForItem($item);
        }

        $context = PriceContext::fromSession();

        // Batch media (one query for ALL items, grouped by id; avoids N+1).
        //    Opt-out via setState('list.media', false) for consumers that render no
        //    pictures (typeahead search); the list view never sets it and keeps TRUE.
        $mediaByItem = $this->getState('list.media', true)
            ? MediaHelper::getMediaData(
                origin:         'item',
                itemIDs:        $productIds,
                usePlaceHolder: $this->usePlaceholder('media_placeholder_items'),
            )
            : [];

        // ITEM FIELDS: load the page's field data once (by item id, cached),
        //    then assign + decorate in the enrich loop below.
        $fieldData = ItemFieldsHelper::getDataForItems($productIds);

        // Pass 1 asks every card with fields which of them are required, and the
        // item-level overrides that decide it were read one card at a time.
        ItemConfigurationHelper::warmRequiredOverrides(array_keys(array_filter(
            $fieldData,
            static fn (object $data): bool => !empty($data->fields),
        )));

        // Pass 1: enrich + resolve each item's DEFAULT variant in-PHP (no
        //    queries: combinations are already loaded), collecting the selections
        //    so the whole page can be priced in ONE batched pass afterwards
        $selections = [];

        foreach ($items as &$item) {
            $item->categories = $this->mapIdsToNames($item->category_ids, $categoriesMapping);
            $item->manufacturers = $this->mapIdsToNames($item->manufacturer_ids, $manufacturersMapping);

            // Layout overrides decoded here once; the view receives a ready array.
            $item->layouts = json_decode($item->layouts ?: '{}', true) ?: [];

            // Which card THIS row uses (entity levels only, locked spec above).
            $item->cardLayout = ($item->layouts['card'] ?? '')
                ?: ($this->categoryId
                    ? ($viewedCategoryLayouts['card'] ?? '')
                    : (($categoriesMapping[$item->id_category_default ?? 0]['layouts'] ?? [])['card'] ?? ''));

            if ($item->stock_action == -1) {
                $item->stock_action = $settings->get('stock_action');
                $item->stock_low_message = $settings->get('stock_low_message');
                $item->stock_zero_message = $settings->get('stock_zero_message');
            }
            $item->stock_low_message = $item->stock_low_message ?: $settings->get('stock_low_message');
            $item->stock_zero_message = $item->stock_zero_message ?: $settings->get('stock_zero_message');

            $item->medias = $mediaByItem[$item->id] ?? [];

            $urlCategoryId = $this->categoryId ?: ($item->id_category_default ?? 0);
            $item->link = $this->buildRoute(
                'index.php?option=com_alfa&view=item&id=' . (int) $item->id
                . '&category_id=' . (int) $urlCategoryId,
            );

            // Item-fields data for this card (from the cached helper).
            $data = $fieldData[$item->id] ?? null;
            $item->fields = $data->fields ?? [];
            $item->options = $data->options ?? [];
            $item->combinations = $data->combinations ?? [];
            $item->informational = $data->informational ?? [];

            // The fields this card draws, decided BEFORE the variant resolves: a
            // dimension the card hides must not constrain the ones it shows.
            // Data-only consumers draw nothing and resolve every dimension.
            $shownFieldIds = null;

            if ($this->getState('plugin.layouts', true)) {
                $item->listingFieldIds = ItemFieldsHelper::listingFieldIds($item);
                $shownFieldIds = $item->listingFieldIds;
            }

            // Resolve the default combination (sets $item->variant); its selection
            // feeds the batched price pass below.
            $selection = VariantHelper::resolve($item, [], shownFieldIds: $shownFieldIds);

            // Card chrome: buy URLs (the cart endpoint always, the reload URL only when
            // there are variants or option extras to re-render), default cart region, and
            // the field plugins' card selectors (onItemListPrepare).
            $this->buildVariantUrls($item);

            // Field display surfaces (card selectors + cart slot, Choose options when
            // the card hides what the buyer must answer); opt-out via
            // setState('plugin.layouts', false) for data-only consumers (search).
            if ($this->getState('plugin.layouts', true)) {
                $drawn = $this->drawCard($item, []);
                $readOnly = $this->readOnlyDimensions($item, $drawn);

                // A dimension the plugin drew without a selector posts nothing, so
                // the card resolves again with it hidden and draws again.
                if ($readOnly !== []) {
                    $item->listingFieldIds = ItemFieldsHelper::listingFieldIds($item, $readOnly);
                    $selection = VariantHelper::resolve($item, [], shownFieldIds: array_values(array_diff($item->listingFieldIds, $readOnly)));
                    $drawn = $this->drawCard($item, []);
                }

                $this->chooseOptionsCart($item, $drawn);
            }

            if ($selection !== null) {
                $selections[$item->id] = $selection;
            }
        }
        unset($item);

        // ONE batched price pass for the whole page: base prices + every
        //    resolved combination's price, from a single data load (no per-item
        //    reloads). Plain items get their base; variant items their default.
        //    Opt-out via setState('list.price', false): per-user pricing is the
        //    expensive pass, and a price-less dropdown must not run it per keystroke.
        $prices = $this->getState('list.price', true)
            ? $this->getPriceCalculator()->calculateBatch($productIds, $quantities, $context, $selections)
            : [];

        // One batched overlay pass for the page, for the same reason the three
        //    passes above are batched: the per-card overlay needs the combination's
        //    translation and its own gallery, and fetching those one card at a time
        //    made the storefront's busiest query grow with the number of variant
        //    cards on the page. The active combinations are already resolved by
        //    now, so warming costs one query each for the whole page.
        $activeCombinationIds = [];

        foreach ($items as $item) {
            if (!empty($item->combinations) && !empty($item->variant->activeCombination->id)) {
                $activeCombinationIds[] = (int) $item->variant->activeCombination->id;
            }
        }

        if ($activeCombinationIds !== []) {
            VariantHelper::warmOverlay($activeCombinationIds);
        }

        // Pass 2: assign each price, then overlay the active combination's data
        //    (sku/stock/name/media) onto the variant cards.
        foreach ($items as &$item) {
            $item->price = $prices[$item->id] ?? null;

            if (!empty($item->combinations)) {
                VariantHelper::overlay($item, $item->variant->activeCombination ?? null);
            }

            // Sale-tag badges onto the card slots: this is the only point where BOTH
            // the price/discounts and the harvested surfaces exist. Guarded like the
            // surface harvest above, so data-only listings (search) stay untouched.
            if ($this->getState('plugin.layouts', true)) {
                SaleTag::contribute($item, true);
            }
        }
        unset($item);

        // Whole-page onItemsListPrepare: engage the plugins on the dispatcher and
        // let them adjust across the fully-built page (cross-item pass).
        $this->dispatchItemsListPrepare($items);

        return $items;
    }

    /**
     * Resolve, price and decorate ONE card: the single-card reload's (getItem)
     * twin of the list loop. For a variant item it resolves the selection
     * (default when empty) through the SAME VariantHelper the product page uses
     * (price + data overlay), with the dimensions the card hides left out of the
     * constraint exactly as the list loop does, and builds the card's
     * reload/add-to-cart URLs; a plain item keeps the base price the caller
     * already set and is only resolved for its cart state. Then it draws the card
     * (options link, default cart region, onItemListPrepare to the fields it
     * draws), resolves and draws once more when a plugin drew a dimension without
     * a selector, and turns the cart into Choose options when a field the buyer
     * must answer drew none.
     *
     * @param object $item The card item (fields/combinations loaded).
     * @param PriceContext $context The pricing context.
     * @param array $selection fieldId => valueId (empty = default variant).
     * @param int[] $optionValueIds The chosen option value ids (empty = none).
     *
     * @since  2.0.0
     */
    protected function finishItemCard(object $item, PriceContext $context, array $selection, array $optionValueIds = [], array $payloads = []): void
    {
        $shownFieldIds = null;

        if ($this->getState('plugin.layouts', true)) {
            $item->listingFieldIds = ItemFieldsHelper::listingFieldIds($item);
            $shownFieldIds = $item->listingFieldIds;
        }

        if (!empty($item->combinations) || $optionValueIds !== [] || $payloads !== []) {
            VariantHelper::resolveAndPrice($item, $selection, $context, $this->getPriceCalculator(), 1, $optionValueIds, $payloads, shownFieldIds: $shownFieldIds);
        } else {
            // Priced by the caller; resolved here only so the card carries its cart
            // state, exactly as the list loop's cards do.
            VariantHelper::resolve($item, []);
        }

        $this->buildVariantUrls($item);

        if ($this->getState('plugin.layouts', true)) {
            $drawn = $this->drawCard($item, $selection);
            $readOnly = $this->readOnlyDimensions($item, $drawn);

            // Same as the list loop: a dimension drawn without a selector is hidden
            // to the resolver, and the card is drawn again.
            if ($readOnly !== []) {
                $item->listingFieldIds = ItemFieldsHelper::listingFieldIds($item, $readOnly);
                VariantHelper::resolveAndPrice($item, $selection, $context, $this->getPriceCalculator(), 1, $optionValueIds, $payloads, shownFieldIds: array_values(array_diff($item->listingFieldIds, $readOnly)));
                $drawn = $this->drawCard($item, $selection);
            }

            $this->chooseOptionsCart($item, $drawn);

            // Sale-tag badges for the single-card reload path (the list loop uses a
            // different path, so no double badge).
            SaleTag::contribute($item, true);
        }
    }

    /**
     * Route a storefront URL, or '' when the application cannot build one.
     *
     * These catalogue models are also driven headlessly: the API catalogue
     * tree and CatalogSearchService both create them with ignore_request. In
     * the API application ApiRouter extends the framework router, which has no
     * build(), and Route::_() catches only RuntimeException, so the resulting
     * Error would escape uncaught. A storefront link is meaningless there
     * anyway, so it degrades to an empty string instead of fataling.
     *
     * @param string $url Internal non-SEF URL.
     * @param bool $xhtml Escape ampersands (Route::_'s second argument).
     *
     *
     * @since  2.0.0
     */
    protected function buildRoute(string $url, bool $xhtml = true): string
    {
        if (Factory::getApplication()->isClient('api')) {
            return '';
        }

        return Route::_($url, $xhtml);
    }

    /**
     * Build a variant card's buy URLs: the reload endpoint (id + sel[]), the
     * listing twin of the product page's reloadUrl, where changing a variant
     * re-fetches the card and changing an option re-prices just its
     * [data-item-prices]. layout=item renders default_item.php (see Items\HtmlView).
     *
     * @param object $item The variant item being decorated.
     *
     * @since  2.0.0
     */
    protected function buildVariantUrls(object $item): void
    {
        // The form's action, always: without it the card's buy form posts to the current page
        // and a shopper without JavaScript adds nothing at all.
        $item->addToCartUrl = $this->buildRoute('index.php?option=com_alfa&task=cart.addToCart');

        // The reload URL re-renders the card for a new selection, so it is empty unless there
        // is a selection to make: the card template always prints it as the buy form's
        // data-action, and variant.js reads an empty one as nothing to reload.
        $item->reloadUrl = '';

        if (empty($item->combinations) && empty($item->options)) {
            return;
        }

        $urlCategoryId = $this->categoryId ?: ($item->id_category_default ?? 0);

        $item->reloadUrl = $this->buildRoute(
            'index.php?option=com_alfa&view=items&layout=item&tmpl=component&id=' . (int) $item->id
            . '&category_id=' . (int) $urlCategoryId,
            false,
        );
    }

    /**
     * Set the card's default cart region: the standard add-to-cart (a plain
     * component layout, no plugin owner). dispatchItemListPrepare may later
     * replace it, and chooseOptionsCart may turn it into cart.options. The
     * template renders $item->cart through Layout::render().
     *
     * @param object $item The item being decorated.
     *
     * @since  2.0.0
     */
    protected function setDefaultCart(object $item): void
    {
        $item->cart = [
            'layout' => 'cart.add',
            'layoutData' => [],
            'layoutPluginType' => '',
            'layoutPluginName' => '',
        ];

        $item->cartChildren = [];
        $item->surfaces = [];
    }

    /**
     * Draw the card from scratch: the options link, the default cart region (which
     * also clears the surfaces of an earlier draw) and onItemListPrepare to the
     * fields it lists.
     *
     * @param object $item The resolved card (->listingFieldIds set).
     * @param array $selection fieldId => valueId from the request ([] on first paint).
     *
     * @return int[] The fields that drew a buy-form selector.
     *
     * @since  2.0.0
     */
    protected function drawCard(object $item, array $selection): array
    {
        $item->optionsLink = $this->buildOptionsLink($item, $selection);
        $this->setDefaultCart($item);

        return $this->dispatchItemListPrepare($item);
    }

    /**
     * The variant dimensions the card lists but drew no selector for: the plugin
     * showed them read-only (Choice's listing_render_as text) or drew nothing.
     * They post nothing, so the card treats them as hidden to its resolver, and
     * no other field's ShowOn rule may read them.
     *
     * @param object $item The drawn card (->listingFieldIds, ->variant).
     * @param int[] $drawnFieldIds The fields that drew a buy-form selector.
     *
     * @return int[] Field ids, in listing order ([] = none).
     *
     * @since  2.0.0
     */
    protected function readOnlyDimensions(object $item, array $drawnFieldIds): array
    {
        $dimensions = array_flip($item->variant?->variantFieldIds ?? []);
        $drawn = array_flip($drawnFieldIds);
        $readOnly = [];

        foreach ($item->listingFieldIds as $fieldId) {
            if (isset($dimensions[$fieldId]) && !isset($drawn[$fieldId])) {
                $readOnly[] = $fieldId;
            }
        }

        return $readOnly;
    }

    /**
     * The card's way to the product page for choosing what it does not draw.
     * Bare on first paint, so the page opens on its own default. Once the shopper
     * picked on the card (the single-card reload), it carries sel[fieldId]=valueId
     * for every dimension of the combination the card DISPLAYS (the picks plus the
     * hidden dimensions' values the card resolved them to) so the page opens on
     * that same combination, complete and buyable, instead of asking for the rest.
     * Never opt[] or conf[]. The link is routed with escaped ampersands, so it is
     * unescaped before the query is set: a plain URL, escaped where it is printed.
     *
     * @param object $item The resolved card (->link, ->listingFieldIds, ->variant).
     * @param array $selection fieldId => valueId from the request ([] on first paint).
     *
     * @return string The URL ('' when the application builds no links).
     *
     * @since  2.0.0
     */
    protected function buildOptionsLink(object $item, array $selection): string
    {
        $link = (string) $item->link;

        if ($link === '') {
            return '';
        }

        $uri = new Uri(htmlspecialchars_decode($link, ENT_COMPAT));
        $shown = array_flip($item->listingFieldIds);
        $dimensions = $item->variant?->variantFieldIds ?? [];
        $picked = false;

        foreach ($selection as $fieldId => $valueId) {
            if ((int) $valueId > 0 && isset($shown[(int) $fieldId]) && \in_array((int) $fieldId, $dimensions, true)) {
                $picked = true;
                break;
            }
        }

        // The displayed combination, as the card resolved it (hidden dimensions filled).
        $carried = [];

        if ($picked) {
            foreach ($dimensions as $fieldId) {
                $valueId = (int) ($item->variant->selection[$fieldId] ?? 0);

                if ($valueId > 0) {
                    $carried[$fieldId] = $valueId;
                }
            }
        }

        if ($carried !== []) {
            $uri->setVar('sel', $carried);
        }

        return $uri->toString();
    }

    /**
     * Turn the card's core add-to-cart into cart.options (a Choose options link
     * to the product page) when the card did not draw a field the buyer must
     * answer: a variant dimension with two or more values, or a required field
     * that is not informational. Drawn means a buy-form selector was declared, so
     * a field shown only as text still counts as not drawn. A fixed dimension
     * (one value) is filled on add and never needed.
     *
     * Left alone: a MAIN a plugin set (never overridden), and a card with nothing
     * to sell (a plain item not salable, a variant item with no combination in
     * stock), whose add-to-cart keeps showing Out of stock / Notify me. Runs
     * before the whole-page onItemsListPrepare, which keeps the last word.
     *
     * @param object $item The decorated card (->cart, ->fields, ->cartState).
     * @param int[] $drawnFieldIds The fields that drew a buy-form selector.
     *
     *
     * @since  2.0.0
     */
    protected function chooseOptionsCart(object $item, array $drawnFieldIds): void
    {
        $cart = $item->cart;

        if ($cart['layout'] !== 'cart.add' || $cart['layoutPluginType'] !== '' || $cart['layoutPluginName'] !== '') {
            return;
        }

        if (!empty($item->combinations)) {
            $salable = false;

            foreach ($item->combinations as $combination) {
                if (!empty($combination->in_stock)) {
                    $salable = true;

                    break;
                }
            }
        } else {
            $salable = (bool) $item->cartState->salable;
        }

        if (!$salable) {
            return;
        }

        $drawn = array_flip($drawnFieldIds);
        $dimensions = CombinationResolver::dimensionValues($item->fields, $item->combinations);

        foreach ($item->fields as $field) {
            $fieldId = (int) $field->id;

            if (isset($drawn[$fieldId]) || !empty($field->informational)) {
                continue;
            }

            $needed = isset($dimensions[$fieldId])
                ? \count($dimensions[$fieldId]) > 1
                : ItemConfigurationHelper::isFieldRequired(itemId: (int) $item->id, field: $field);

            if ($needed) {
                $item->cart = [
                    'layout' => 'cart.options',
                    'layoutData' => [],
                    'layoutPluginType' => '',
                    'layoutPluginName' => '',
                ];

                return;
            }
        }
    }

    /**
     * Build ONE fully-decorated catalog card for the given item + buyer
     * selection: the single-card counterpart of getItems used by the card
     * reload endpoint (view=items&layout=item). Fetches the item (translated in
     * the active language), loads its field data + media, prices it and runs the
     * SAME finishItemCard() tail the list loop runs, so the swapped card is
     * identical to a freshly-listed one.
     *
     * @param int $id The item id.
     *
     * @return object|null The decorated card item, or null when not found/unpublished.
     * @since  2.0.0
     */
    public function getItem(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('a.*')
            ->from($db->quoteName('#__alfa_items', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . $id)
            ->where($db->quoteName('a.state') . ' = 1');

        // Same allowlist as the listing. This method builds its OWN query rather
        // than reusing getListQuery(), so it does not inherit that filter, and it
        // is reachable anonymously as view=items&layout=item&id=N, which means a
        // restricted product could be rendered in full simply by guessing an id.
        (new CatalogAccessPolicy())->applyToItems($query, 'a');

        // The routed category's layout overrides ride the SAME query (the reload
        // URL carries category_id): no second query for the card chain.
        $routedCategoryId = (int) $this->categoryId;
        $query->select($db->quoteName('c.layouts', 'category_layouts'))
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_categories', 'c'),
                $db->quoteName('c.id') . ' = :routedCategoryId',
            )
            ->bind(':routedCategoryId', $routedCategoryId, ParameterType::INTEGER);

        // Resolve translatable fields in the active language (current -> default
        // -> '') from the per-language tables; same set as the product page.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            fields:            ['name', 'alias', 'short_desc', 'full_desc', 'meta_title', 'meta_desc', 'stock_low_message', 'stock_zero_message'],
        );

        $db->setQuery($query);
        $item = $db->loadObject();

        if (!$item) {
            return null;
        }

        // Layout overrides decoded here once; the view receives ready arrays.
        $item->layouts = json_decode($item->layouts ?: '{}', true) ?: [];
        $item->category_layouts = json_decode($item->category_layouts ?: '{}', true) ?: [];

        // LOCKED SPEC parity with the listing loop: the reload URL's category_id
        // is the viewed category on category pages and the item's default one on
        // mixed listings, so the swapped card matches its page exactly.
        $item->cardLayout = ($item->layouts['card'] ?? '') ?: ($item->category_layouts['card'] ?? '');

        // Stock-action defaults: mirror the list loop.
        $settings = ComponentHelper::getParams('com_alfa');

        if ((int) $item->stock_action === -1) {
            $item->stock_action = $settings->get('stock_action');
            $item->stock_low_message = $settings->get('stock_low_message');
            $item->stock_zero_message = $settings->get('stock_zero_message');
        }

        $item->stock_low_message = $item->stock_low_message ?: $settings->get('stock_low_message');
        $item->stock_zero_message = $item->stock_zero_message ?: $settings->get('stock_zero_message');

        // Field data + media (single-item equivalents of the list batch).
        $fieldData = ItemFieldsHelper::getDataForItem($id);
        $item->fields = $fieldData->fields;
        $item->options = $fieldData->options;
        $item->combinations = $fieldData->combinations;
        $item->informational = $fieldData->informational;

        $item->medias = MediaHelper::getMediaData(
            origin:         'item',
            itemIDs:        $id,
            usePlaceHolder: $this->usePlaceholder('media_placeholder_items'),
        );

        $context = PriceContext::fromSession();

        // Base price (kept as-is for a plain item; finishItemCard overwrites it
        // through VariantHelper for a variant item).
        $item->price = $this->getPriceCalculator()->calculate($id, 1, $context);

        $urlCategoryId = $this->categoryId ?: ($item->id_category_default ?? 0);
        $item->link = $this->buildRoute(
            'index.php?option=com_alfa&view=item&id=' . (int) $item->id
            . '&category_id=' . (int) $urlCategoryId,
        );

        // Only what the product itself offers is resolved and priced, as the cart stores it.
        $request = VariantHelper::offeredSelection(item: $item, request: VariantHelper::requestSelection());
        $this->finishItemCard($item, $context, $request['selection'], $request['optionValueIds'], $request['payloads']);

        // Engage the plugins on the dispatcher once (parity with the list path).
        $this->dispatchItemsListPrepare([$item]);

        return $item;
    }

    /**
     * Whole-page onItemsListPrepare pass: each enabled item-fields plugin is
     * booted once, engaged on the dispatcher (addSubscriber, like
     * payments/shipments) and given the fully-built page (subject = items) so it
     * can make cross-item adjustments. Runs AFTER the per-item loop, so every
     * item already carries its fields/options/combinations and resolved cart,
     * including the core's Choose options decision, which a plugin here may
     * still overwrite (the whole-page pass has the last word).
     *
     * @param object[] $items The hydrated page of items.
     *
     *
     * @since  2.0.0
     */
    protected function dispatchItemsListPrepare(array $items): void
    {
        $app = Factory::getApplication();
        $dispatcher = $app->getDispatcher();

        foreach (PluginHelper::getPlugin('alfa-item-fields') as $pluginInfo) {
            try {
                $plugin = $app->bootPlugin((string) $pluginInfo->name, 'alfa-item-fields');

                if (!$plugin) {
                    continue;
                }

                // Engage the plugin on the dispatcher once for the request (same
                // as payments/shipments), so it participates in the event system
                // for whatever fires next. The per-field decoration already ran
                // via direct calls, which need no prior subscription.
                if ($plugin instanceof SubscriberInterface) {
                    $dispatcher->addSubscriber($plugin);
                }

                if (method_exists($plugin, 'onItemsListPrepare')) {
                    $plugin->onItemsListPrepare(
                        new ItemsListPrepareEvent('onItemsListPrepare', ['subject' => $items]),
                    );
                }
            } catch (Throwable $e) {
                // Degrading is right: the listing renders without this plugin
                // - but doing it silently meant a contribution could vanish
                // from every product card with nothing anywhere saying why.
                Log::add(
                    'onItemsListPrepare failed in plugin "' . (string) $pluginInfo->name . '", listing rendered without it: '
                    . $e->getMessage(),
                    Log::ERROR,
                    'com_alfa',
                );
            }
        }
    }

    /**
     * Per (item x drawn field): dispatch onItemListPrepare to the field's OWN
     * plugin (bootPlugin($field->type), memoised) via a direct call. Only the
     * fields the card draws ($item->listingFieldIds: Show on listings, closed
     * over ShowOn) are dispatched; any other field contributes nothing to the
     * card, neither selector, nor spec, nor cart. The plugin may set card
     * SURFACES (aggregated onto $item->surfaces, each descriptor naming its
     * field, so the card renders the same buyer-facing selector the product page
     * does) and/or overwrite the card's CART region. We fill each layout's owner
     * from the field's type. Plugin failures never take the card down. (The
     * dispatcher engagement happens once in dispatchItemsListPrepare; a direct
     * call needs no prior subscription.)
     *
     * @param object $item The item being decorated.
     *
     * @return int[] The fields that drew a buy-form selector (sel[] / opt[]).
     *
     * @since  2.0.0
     */
    protected function dispatchItemListPrepare(object $item): array
    {
        $app = Factory::getApplication();
        $drawnFieldIds = [];
        $listed = array_flip($item->listingFieldIds);

        // Card initial visibility painted server-side (no FOUC), same rule + state as JS.
        $showonBag = StateBagBuilder::fromItem($item);

        foreach ($item->fields as $field) {
            if (!isset($listed[(int) $field->id])) {
                continue;
            }

            try {
                $plugin = $app->bootPlugin((string) $field->type, 'alfa-item-fields');

                if (!$plugin || !method_exists($plugin, 'onItemListPrepare')) {
                    continue;
                }

                $event = new ItemListPrepareEvent(
                    'onItemListPrepare',
                    ['subject' => $item, 'method' => $field],
                );
                $plugin->onItemListPrepare($event);

                // Contract guard: selector kind must match the field's combination
                // role (variant->sel[]/item, option->opt[]/price). Loud in dev.
                Surfaces::assertSelectorRole(
                    $event->getSurfaces(),
                    \in_array((int) $field->id, $item->variant?->variantFieldIds ?? [], true),
                    (int) $field->id,
                    (string) $field->type,
                );

                // Aggregate this field's card surfaces onto the page slot map
                // (insertion order). Legacy setLayout() bridges to BuyForm.
                Surfaces::appendTo(
                    $item->surfaces,
                    $event,
                    'alfa-item-fields',
                    (string) $field->type,
                    ItemListSurface::BuyForm->value,
                    Surfaces::showonAttr($field) . InitialState::hiddenAttr($field, $showonBag),
                    field: $field,
                );

                // Drawn = a buy-form input the card posts; a read-only rendering
                // (text, spec) leaves the field to the product page.
                $buyForm = $event->getSurfaces()[ItemListSurface::BuyForm->value] ?? null;

                if ($buyForm !== null && Surfaces::declaresSelector($buyForm)) {
                    $drawnFieldIds[] = (int) $field->id;
                }

                // Cart MAIN slot: last field that sets one wins (REPLACE).
                if ($event->hasCartLayout()) {
                    $item->cart = [
                        'layout' => $event->getCartLayout(),
                        'layoutData' => $event->getCartLayoutData(),
                        'layoutPluginType' => $event->getCartLayoutPluginType() ?: 'alfa-item-fields',
                        'layoutPluginName' => $event->getCartLayoutPluginName() ?: (string) $field->type,
                    ];
                }

                // Cart CHILD slot: same granular contract as MAIN, but APPEND (sibling).
                if ($event->hasCartChild()) {
                    $item->cartChildren[] = [
                        'layout' => $event->getCartChildLayout(),
                        'layoutData' => $event->getCartChildLayoutData(),
                        'layoutPluginType' => $event->getCartChildLayoutPluginType() ?: 'alfa-item-fields',
                        'layoutPluginName' => $event->getCartChildLayoutPluginName() ?: (string) $field->type,
                    ];
                }
            } catch (Throwable $e) {
                // Same rule: the card keeps its default cart layout, but the
                // plugin that failed to supply one is named.
                Log::add(
                    'onItemListPrepare failed for field type "' . (string) $field->type . '", card kept its default cart layout: '
                    . $e->getMessage(),
                    Log::ERROR,
                    'com_alfa',
                );
            }
        }

        return $drawnFieldIds;
    }

    // Filter option helpers

    /**
     * Return the min/max final_price for the current non-price filters.
     *
     * Used by the price slider to determine its endpoints. All price-related
     * filter states are excluded so the slider always shows the full extent
     * of prices visible under the other active filters (search, category, etc.).
     *
     * @return array{min: float|null, max: float|null}
     * @since  1.0.0
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

        // The filter module asks for this facet more than once per render; the
        // heavy price-index query is identical for the same filter state, so memo
        // by the built statement and the values bound to it.
        static $cache = [];
        $key = self::statementKey(query: $query);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $result = $this->getDatabase()->setQuery($query)->loadObject();

        return $cache[$key] = [
            'min' => $result && $result->price_min !== null ? (float) $result->price_min : null,
            'max' => $result && $result->price_max !== null ? (float) $result->price_max : null,
        ];
    }

    /**
     * Return available manufacturers for the current non-manufacturer filters.
     *
     * @return array Keyed by id: [1 => ['id' => 1, 'name' => 'Nike'], ...]
     * @since  1.0.0
     */
    /**
     * How many of the products under the other active filters are sellable right now,
     * and how many are not: the availability facet's two counts. Asked with the
     * stock filter itself removed, as every facet is, so the counts describe what
     * picking each option would leave.
     *
     * @return array{in: int, out: int}
     *
     * @since  2.0.0
     */
    public function getAvailableStockCounts(): array
    {
        $model = $this->createFilterSubModel(['filter.stock']);
        $sellable = (new StockVisibilityPolicy())->sellableExpression('a');
        $out = [];

        foreach (['in' => $sellable, 'out' => 'NOT ' . $sellable] as $key => $predicate) {
            $query = $model->getListQuery();
            $query->clear('select')
                ->clear('order')
                ->clear('group')
                ->select('COUNT(DISTINCT a.id)')
                ->where($predicate);

            $out[$key] = (int) $this->getDatabase()->setQuery($query)->loadResult();
        }

        return $out;
    }

    /**
     * The one availability the state asks for: `in`, `out`, or '' when it asks for
     * both, neither, or nonsense. A scalar (the view's own control) and a list (the
     * module's checkboxes) read the same.
     *
     * @param mixed $state The `filter.stock` state.
     *
     *
     * @since  2.0.0
     */
    public static function stockChoice(mixed $state): string
    {
        $picked = array_values(array_intersect(['in', 'out'], array_map('strval', array_filter((array) $state, 'is_scalar'))));

        return \count($picked) === 1 ? $picked[0] : '';
    }

    public function getAvailableManufacturers(): array
    {
        $model = $this->createFilterSubModel(['filter.manufacturer']);

        $query = $model->getListQuery();
        $query->clear('select')
            ->clear('order')
            ->clear('group')
            ->select('DISTINCT man.manufacturer_id')
            ->where('man.manufacturer_id IS NOT NULL');

        // Same facet, asked more than once per render: memo by the built
        // statement and the values bound to it.
        static $cache = [];
        $key = self::statementKey(query: $query);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $ids = $this->getDatabase()->setQuery($query)->loadColumn();

        return $cache[$key] = empty($ids)
            ? []
            : $this->getRecordsByIds(array_map('intval', $ids), '#__alfa_manufacturers', ['id', 'name']);
    }

    /**
     * Return available categories for the current non-category filters.
     *
     * @return array Keyed by id: [2 => ['id' => 2, 'name' => 'Shoes'], ...]
     * @since  1.0.0
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

    /**
     * Return the available item-field values for the current filters, grouped by
     * field, for the filters module facets.
     *
     * SELF-EXCLUSION IS AXIS-LOCAL. A value facet for field F drops ONLY
     * `filter.fields[F]` (so picking Color=Red still lists every Color) while
     * category / manufacturer / price / search and the OTHER fields' selections
     * stay applied. Field F's own GROUP selection deliberately stays applied to its
     * own value facet as well: the two axes are different questions, and the value
     * list answers "which colours can I still add, given everything else I have
     * asked for, INCLUDING the colour family I picked". Dropping the group axis here
     * would offer values that the group chip above them excludes. The mirror rule for
     * the group surface lives on {@see self::getFacetGroupCounts()}.
     *
     * The candidate set is narrowed by the same getListQuery the catalog uses, then
     * the filter index is joined to enumerate and count the reachable values per field.
     *
     * COUNTING IS ONE SHARED AGGREGATE plus one pass per SELECTED field. Self-exclusion
     * is a no-op for a field the shopper has not picked a value from, so every such field
     * is served by a single pass grouped by (field_id, value_id); only a field whose own
     * value selection has to come off needs a query to itself. Earlier there was one
     * query per candidate field, and they did NOT collapse in the SQL memo even with no
     * field filter active: `ifx.field_id = N` is written into the query TEXT and the
     * memo keys on that text, so N candidates cost N executions.
     *
     * @return array<int, array{id: int, label: string, type: string, render_as: string, values: array<int, array{id: int, name: string, count: int, params: array}>}>
     * @since  2.0.0
     */
    public function getAvailableItemFieldValues(): array
    {
        $hidden = $this->resolveFacetShowon();
        $facets = $this->buildFacetValues();

        foreach ($hidden as $fieldId) {
            unset($facets[$fieldId]);
        }

        // An INACTIVE rule is dropped from the published facet, not merely ignored here.
        // The browser engine evaluates whatever `data-showon-rule` it is given, and its
        // shared evaluator treats a missing operand as a NEUTRAL LEAF rather than an
        // inactive rule, so publishing the rule would let the client hide a facet the
        // server decided to show. Withholding the rule is what keeps the two in step
        // without forking the shared evaluator or the shared engine.
        foreach ($this->facetShowonInactive as $fieldId) {
            if (isset($facets[$fieldId])) {
                $facets[$fieldId]['showon'] = null;
            }
        }

        return $facets;
    }

    /**
     * Every CANDIDATE facet (filterable, published, with at least one reachable value)
     * before ShowOn is applied. Split out of {@see self::getAvailableItemFieldValues()}
     * because the ShowOn resolver needs to know what WOULD render in order to decide
     * what may be hidden, and calling the public method there would recurse.
     *
     * @return array<int, array{id: int, name: string, label: string, type: string, render_as: string, showon: array|null, showon_effect: string, values: array<int, array{id: int, name: string, count: int, params: array}>}>
     * @since  2.0.0
     */
    private function buildFacetValues(): array
    {
        // Per-request memo shared across model INSTANCES: the View (inline filter
        // bar) and the mod_alfa_filters module each boot their own Items model but
        // read the SAME request, so one computation serves both.
        static $resultCache = [];

        $db = $this->getDatabase();

        // THE FIELD DEFINITIONS ARE READ BEFORE THE MEMO IS CONSULTED, ON PURPOSE.
        // They are an INPUT to the facets (which fields are filterable, in what order,
        // and with which ShowOn rule and render mode) so a key that omitted them would
        // hand back facets built under a different configuration the moment anything
        // rewrote a field within the same process. That is not hypothetical: it is
        // exactly how a configuration-changing test was served stale facets. One narrow
        // read of the field table per call is the price of a key that is complete; the
        // expensive part (one facet count query per field) is what the memo protects.
        // FACET ORDER IS A CONTRACT: `ordering` ASC, then `id` ASC. The tie-breaker is not
        // cosmetic. ShowOn is evaluated by walking the facets in exactly this order and
        // filling a StateBag as it goes ({@see self::facetShowonVerdict()}), so two fields
        // sharing an `ordering` value decide, between them, whether one can read the
        // other's selection at all. Left to MySQL that would be incidental row order:
        // stable enough to look correct in testing and free to change with the plan, the
        // storage engine or an unrelated write. This is the ONE place facet order is
        // produced; every consumer preserves the array order it is handed.
        $fieldsQuery = $db->getQuery(true)
            ->select($db->quoteName(['id', 'field_name', 'type', 'params', 'ordering']))
            ->from($db->quoteName('#__alfa_item_fields'))
            ->where($db->quoteName('filterable') . ' = 1')
            ->where($db->quoteName('state') . ' = 1')
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');

        $fields = $db->setQuery($fieldsQuery)->loadObjectList('id') ?: [];

        if (empty($fields)) {
            return [];
        }

        // VALUE params are an input too: the colour identities that drive grouping live
        // there, but the value rows are only read further down, once the facet query has
        // said which ones are reachable. One narrow aggregate over the candidate fields'
        // values gives the key something that changes whenever any of them is edited,
        // without pulling the rows themselves up here.
        //
        // `ordering` IS PART OF THAT FINGERPRINT, because the merchant's value order is
        // now part of the ANSWER (see the projection in the field loop). Left out, a
        // process that reorders values and asks again would be served the previous order
        // out of the memo: the same trap the field definitions above were already
        // guarding against, one table down.
        $fingerprintQuery = $db->getQuery(true)
            ->select([
                'COUNT(*) AS n',
                'COALESCE(SUM(CRC32(CONCAT(' . $db->quoteName('id') . ', ' . $db->quote(':')
                    . ', ' . $db->quoteName('ordering') . ', ' . $db->quote(':')
                    . ', COALESCE(' . $db->quoteName('params') . ', ' . $db->quote('') . ')))), 0) AS fp',
            ])
            ->from($db->quoteName('#__alfa_item_field_values'))
            ->whereIn($db->quoteName('item_field_id'), array_map('intval', array_keys($fields)));

        $valueFingerprint = $db->setQuery($fingerprintQuery)->loadAssoc() ?: [];

        $stateKey = md5(serialize([
            // The statement every facet is counted over, with the values bound to
            // it: each filter, the products asked for, the stock choice, the search
            // and its intent, and the visitor's prices are all in it, so a question
            // that differs in any of them is not handed another one's answer.
            self::statementKey(query: $this->createFilterSubModel([])->getListQuery()),
            MultilingualHelper::getCurrentLanguageTag(),
            // The configuration fingerprint; see above.
            $fields,
            $valueFingerprint,
        ]));

        if (isset($resultCache[$stateKey])) {
            return $resultCache[$stateKey];
        }

        // Translated field labels (the per-language #__alfa_item_fields_<tag>.name).
        $fieldLabels = $this->getRecordsByIds(array_map('intval', array_keys($fields)), '#__alfa_item_fields', ['name']);

        // VALUE ORDER IS THE MERCHANT'S, not the aggregate's: one narrow read of the
        // definition rows for every candidate field, up front. See the projection below.
        $valueOrder = $this->fieldValueOrder(array_map('intval', array_keys($fields)));

        // Same aggregate asked more than once per render: memo by the built
        // statement and the values bound to it.
        static $cache = [];

        $fieldIds = array_map('intval', array_keys($fields));

        // THE PARTITION. Self-exclusion is a NO-OP for a field that has no value
        // selection of its own, so every such field is answered by ONE shared aggregate
        // grouped by (field_id, value_id); only a field that HAS a selection needs a
        // pass of its own, with that selection dropped. The cost is 1 + (fields with a
        // value selection) instead of one query per candidate field.
        //
        // A selection that is empty once the ids are filtered (`['']` from a cleared
        // request) constrains NOTHING (itemHasFieldValueSql() degrades it to '1 = 1')
        // so its field is NOT counted as selected and rides the shared pass, which is
        // exactly the query the per-field build produced for it.
        //
        // RECOMPUTED ON EVERY CALL, never carried across passes: ShowOn pruning is
        // precisely what moves a field from selected to unselected, and each resolver
        // pass calls this method again with a narrower state.
        $selected = [];

        foreach ((array) $this->getState('filter.fields', []) as $selectedFieldId => $selectedValueIds) {
            $selectedFieldId = (int) $selectedFieldId;

            // A selection on a field that is not a CANDIDATE (unpublished, or no longer
            // filterable) still constrains the catalogue: it is applied by getListQuery
            // like any other, but it has no facet to draw, so it buys no query here.
            if (!isset($fields[$selectedFieldId])) {
                continue;
            }

            if (array_filter(array_map('intval', (array) $selectedValueIds))) {
                $selected[$selectedFieldId] = true;
            }
        }

        // One aggregate over every candidate field, or one self-excluded aggregate for a
        // single field. GROUP BY (field_id, value_id) ALWAYS, even in the single-field
        // shape: the projection's PRIMARY KEY is (item_id, field_id, value_id) and
        // nothing makes a value id unique ACROSS fields, so value_id alone could fuse
        // two fields' counts the day two fields share one.
        $aggregate = function (?int $only) use ($db, $fieldIds, &$cache): array {
            $model = $this->createFilterSubModel([]);

            if ($only !== null) {
                // Self-exclusion, AXIS-LOCAL: drop ONLY this field's own VALUE selection.
                // Every other field's values, and this field's own GROUP selection, stay;
                // see the class contract on getAvailableItemFieldValues().
                $active = (array) $model->getState('filter.fields', []);
                unset($active[$only]);
                $model->setState('filter.fields', $active ?: null);
            }

            $query = $model->getListQuery();
            $query->clear('select')
                ->clear('order')
                ->clear('group')
                ->clear('having')
                ->join('INNER', $db->quoteName('#__alfa_item_field_value_index', 'ifx') . ' ON ifx.item_id = a.id')
                ->select('ifx.field_id AS field_id, ifx.value_id AS value_id, COUNT(DISTINCT a.id) AS cnt')
                ->group('ifx.field_id, ifx.value_id');

            // Never widen past the candidate fields: the projection also carries rows
            // for fields that are not filterable or not published.
            if ($only === null) {
                $query->where('ifx.field_id IN (' . implode(', ', $fieldIds) . ')');
            } else {
                $query->where('ifx.field_id = ' . $only);
            }

            $key = self::statementKey(query: $query);

            return $cache[$key] ?? ($cache[$key] = $db->setQuery($query)->loadObjectList() ?: []);
        };

        // field id => value id => count. NOTHING TAKES ORDER FROM THIS MAP: it is a
        // lookup table, and the emission order is the merchant's, read from $valueOrder
        // in the projection below.
        $counts = [];

        foreach ($aggregate(null) as $row) {
            $counts[(int) $row->field_id][(int) $row->value_id] = (int) $row->cnt;
        }

        // A field with a selection is counted against everything EXCEPT that selection,
        // so its shared-pass rows are REPLACED, not merged: the self-excluded pass is the
        // whole truth for that field, and a value its own selection had eliminated must
        // come back at its real count rather than keep the narrower one.
        foreach (array_keys($selected) as $selectedFieldId) {
            $counts[$selectedFieldId] = [];

            foreach ($aggregate($selectedFieldId) as $row) {
                $counts[$selectedFieldId][(int) $row->value_id] = (int) $row->cnt;
            }
        }

        $result = [];

        foreach ($fields as $fieldId => $field) {
            $fieldId = (int) $fieldId;

            // Nothing reachable for this field under the current filters: the facet
            // drops out entirely, exactly as it did when its own count query came back
            // with no rows.
            if (empty($counts[$fieldId])) {
                continue;
            }

            $valueIds = array_keys($counts[$fieldId]);

            // 'params' rides along from the MAIN table (it is never translated) so the
            // swatch/button render modes can draw each value's chip (swatch_colors /
            // swatch_image, the same keys the item-page surfaces read) without a
            // second query per field.
            $labels = $this->getRecordsByIds($valueIds, '#__alfa_item_field_values', ['name'], 'id', ['params']);

            $values = [];

            foreach ($counts[$fieldId] as $valueId => $count) {
                $valueId = (int) $valueId;
                $valueParams = json_decode((string) ($labels[$valueId]['params'] ?? ''), true);

                $values[$valueId] = [
                    'id' => $valueId,
                    'name' => $labels[$valueId]['name'] ?? '',
                    'count' => (int) $count,
                    // Handed to the type so it can answer from its own shape; nothing
                    // outside the type interprets it.
                    'params' => \is_array($valueParams) ? $valueParams : [],
                ];
            }

            // THE UI ORDER IS THE MERCHANT'S, THE SQL ORDER IS NOBODY'S. The aggregate
            // above returns rows in whatever order the group-by happened to produce; the
            // storefront must emit values in the order the merchant arranged them in the
            // backend. So the counts are PROJECTED onto the definition order rather than
            // re-sorted, and no ORDER BY is added to the facet query. That separation is
            // what let the per-field queries collapse into one shared aggregate at all:
            // a single pass spanning every field could not express a per-field value
            // order in its ORDER BY, and one that only held while there was a query per
            // field would have quietly stopped holding the moment they merged.
            //
            // A value that is in the projection but has no definition row any more (the
            // index outliving a deleted value) keeps its place at the END, in ascending id
            // order, rather than disappearing from a panel that still counts it.
            $ordered = [];

            foreach ($valueOrder[$fieldId] ?? [] as $definedId) {
                if (isset($values[$definedId])) {
                    $ordered[$definedId] = $values[$definedId];
                }
            }

            $leftover = array_diff_key($values, $ordered);
            ksort($leftover);
            $values = $ordered + $leftover;

            $reported = $this->fieldValueGroups($field, $values);

            // How each value draws itself on a plain chip: the type's layout, carried
            // through untouched. The component does not look inside it.
            foreach ($values as $valueId => $value) {
                $values[$valueId]['layout'] = (string) ($reported['valueLayouts'][$valueId]['layout'] ?? '');
                $values[$valueId]['layoutData'] = (array) ($reported['valueLayouts'][$valueId]['layoutData'] ?? []);
                unset($values[$valueId]['params']);
            }

            // render_as: the choice-style plugin reads its own param; default
            // checkboxes. The plugin filter surface (Phase 3b) may override this.
            $fieldParams = new \Joomla\Registry\Registry($field->params ?? '');

            // valueId => reported memberships, in MERCHANT VALUE ORDER. Built by walking
            // $values rather than unioning with `+`, because `+` would put whatever order
            // the type happened to report in FIRST and the merchant's order after it.
            // Anything the type reported for a value that is not in this facet still
            // survives, appended at the end, so nothing is silently dropped.
            $groupsByValue = [];

            foreach (array_keys($values) as $valueId) {
                $groupsByValue[$valueId] = $reported['groups'][$valueId] ?? [];
            }

            $groupsByValue += (array) ($reported['groups'] ?? []);

            $result[$fieldId] = [
                'id' => $fieldId,
                // The machine name is the ShowOn operand prefix (`size.value`) and the
                // `data-showon-name` the browser engine reads its state from.
                'name' => (string) $field->field_name,
                'label' => $fieldLabels[$fieldId]['name'] ?? $field->field_name,
                'type' => $field->type,
                'render_as' => (string) $fieldParams->get('filter_render_as', 'checkboxes'),
                'showon' => self::decodeShowonRule($fieldParams->get('showon')),
                'showon_effect' => (string) $fieldParams->get('showon_effect', 'hide'),
                'values' => $values,
                // WHETHER TO GROUP IS THE FIELD'S DECISION, and only the field's. The type
                // answers it from the field's own params; nothing downstream may override
                // it, so this is carried through and never interpreted.
                'group_default' => (bool) ($reported['groupingDefault'] ?? false),
                // Colour identity comes from the TYPE, not from its params: one dispatch
                // per field, no query. Counts per group are NOT computed here: they need
                // their own aggregate, and a panel with grouping off must not pay for one.
                //
                // EVERY value id is offered to the resolver, not just the ones that
                // reported a membership: a type only answers for values that HAVE one, so
                // the resolver would otherwise never learn which values are UNGROUPED, and
                // an ungrouped value would vanish the moment grouping was switched on. The
                // walk is in $values order (i.e. the merchant's) so the resolver's
                // valueIds and ungrouped lists come back in that same order.
                'groups' => FacetGroupResolver::resolve($groupsByValue),
            ];
        }

        return $resultCache[$stateKey] = $result;
    }

    /**
     * The merchant's configured VALUE ORDER for one or more item fields: the value ids
     * of each field in `ordering` ASC, then `id` ASC.
     *
     * THE DEFINITION TABLE IS THE ORDER OF RECORD, not the facet aggregate. A facet
     * counts values; it does not get to arrange them. Reading the order separately is
     * what lets the counting query stay free of an ORDER BY, which matters because that
     * query IS one shared aggregate over every field at once, where a per-field ORDER BY
     * could not express this at all. The result is the "already-sorted definition map"
     * the aggregate is walked against.
     *
     * NO `state` FILTER, deliberately: this decides ORDER, not membership. Whichever
     * values the facet build found reachable are the ones that render, and an unpublished
     * definition row that is still in the projection must keep whatever position the
     * merchant gave it rather than being pushed to the end by an unrelated flag.
     *
     * ONE query for all the fields asked about, and deliberately NO static memo of its
     * own. It is called once per real facet build, and the facet build is already behind
     * a memo whose key now includes value `ordering`, so a second static here could only
     * ever add a way to be stale (a process that reorders values and asks again would get
     * the previous order back), never a query saving that the outer memo does not already
     * make.
     *
     * @param int[] $fieldIds The item fields to read.
     *
     * @return array<int, int[]> Field id => ordered value ids. A field with no values at
     *                           all still gets an entry, so callers can index safely.
     *
     * @since  2.0.0
     */
    private function fieldValueOrder(array $fieldIds): array
    {
        $fieldIds = array_values(array_unique(array_filter(array_map('intval', $fieldIds))));
        $out = array_fill_keys($fieldIds, []);

        if (empty($fieldIds)) {
            return $out;
        }

        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'item_field_id']))
            ->from($db->quoteName('#__alfa_item_field_values'))
            ->whereIn($db->quoteName('item_field_id'), $fieldIds)
            ->order(
                $db->quoteName('item_field_id') . ' ASC, '
                . $db->quoteName('ordering') . ' ASC, '
                . $db->quoteName('id') . ' ASC',
            );

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $out[(int) $row->item_field_id][] = (int) $row->id;
        }

        return $out;
    }

    /**
     * The active filters, always reported from the EFFECTIVE state.
     *
     * The parent reads `filter.*` straight out of the model state, which is only the
     * pruned state once {@see self::resolveFacetShowon()} has run. Relying on a caller
     * to have asked for the facets first made correctness depend on statement order,
     * so the resolution is triggered here instead, and the invariant holds for every
     * caller. The latch inside the resolver makes this safe to call from anywhere,
     * including from within the resolution itself.
     *
     * @return array The active filters, with ShowOn-hidden facet selections removed.
     *
     * @since  2.0.0
     */
    public function getActiveFilters(): array
    {
        $this->resolveFacetShowon();

        return parent::getActiveFilters();
    }

    // Colour groups

    /**
     * Ask a field's TYPE which facet groups its values belong to.
     *
     * The component never reads a type's per-value params. It asks, once per field, and the
     * type answers from a shape only it knows ({@see ValueFacetGroupsEvent}), already
     * normalised into `(type, id)` pairs. Everything downstream (bucketing, the WHERE
     * clause, the counts, the chip) treats those as opaque strings and would work
     * identically for a field type that does not exist yet.
     *
     * A type that does not implement the event simply contributes no groups, which is an
     * ordinary state: its values stay filterable individually. A type that THROWS must not
     * take the catalogue down with it, so the failure is logged and read as "no groups":
     * \Throwable, because a TypeError from a third-party plugin is exactly the case that
     * must not escape.
     *
     * @param object $field The field definition (needs id and type).
     * @param array $values Value rows keyed by value id.
     *
     * @return array{groups: array<int, array>, valueLayouts: array<int, array>, groupingDefault: bool}
     *
     * @since  2.0.0
     */
    private function fieldValueGroups(object $field, array $values): array
    {
        static $cache = [];

        // ORDER-SENSITIVE ON PURPOSE, and safe now that the order is a contract. The key
        // hashes the value ids AS ORDERED, because the answer is order-sensitive too: the
        // memberships come back per value and the resolver emits them in the order it was
        // handed. Within one request the facet build hands over exactly one order (the
        // merchant's), so the key is stable and the memo still hits. It cannot collide
        // with the OTHER caller either: fieldGroupMap() asks about the field's full
        // published value set, a different list of ids, hence a different digest, and a
        // reordering is a genuinely different question, so being a miss is correct.
        $key = (int) $field->id . ':' . md5(serialize(array_keys($values)));

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $type = (string) ($field->type ?? '');

        $empty = ['groups' => [], 'valueLayouts' => [], 'groupingDefault' => false];

        if ($type === '' || empty($values)) {
            return $cache[$key] = $empty;
        }

        try {
            $plugin = $this->app->bootPlugin($type, 'alfa-item-fields');

            if (!$plugin || !method_exists($plugin, 'onItemFieldValueFacetGroups')) {
                return $cache[$key] = $empty;
            }

            // Every other item-field event hands the type a field carrying
            // params_decoded, so this one does too: decoding JSON is not knowledge of
            // what the params MEAN, and the alternative is each type inventing its own
            // accessor for the shape it already reads elsewhere.
            if (!isset($field->params_decoded)) {
                $decoded = json_decode((string) ($field->params ?? ''), true);
                $field->params_decoded = \is_array($decoded) ? $decoded : [];
            }

            $event = new ValueFacetGroupsEvent(
                'onItemFieldValueFacetGroups',
                ['subject' => $field, 'valueRows' => $values],
            );

            $plugin->onItemFieldValueFacetGroups($event);

            return $cache[$key] = [
                'groups' => $event->getGroups(),
                'valueLayouts' => $event->getValueLayouts(),
                'groupingDefault' => $event->getFieldGrouping(),
            ];
        } catch (Throwable $e) {
            Log::add(
                'onItemFieldValueFacetGroups failed for field type "' . $type . '": ' . $e->getMessage(),
                Log::WARNING,
                'com_alfa',
            );

            return $cache[$key] = $empty;
        }
    }

    /**
     * token => group descriptor (including its member value ids), for one field.
     *
     * Deliberately NOT taken from the facet build: this is a property of the field's
     * configuration, not of the current filter state, so reading it directly keeps it
     * usable from getListQuery() (which the facet build itself calls) without looping
     * back through it. One narrow query per field, one dispatch to the field's own type,
     * memoised for the request.
     *
     * @param int $fieldId The item field.
     *
     * @return array<string, array> Group descriptors keyed by token.
     *
     * @since  2.0.0
     */
    private function fieldGroupMap(int $fieldId): array
    {
        static $cache = [];

        if (isset($cache[$fieldId])) {
            return $cache[$fieldId];
        }

        $db = $this->getDatabase();

        $field = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type']))
                ->from($db->quoteName('#__alfa_item_fields'))
                ->where($db->quoteName('id') . ' = ' . $fieldId),
        )->loadObject();

        if (!$field) {
            return $cache[$fieldId] = [];
        }

        // Merchant order here too: the group descriptors this builds carry member value
        // id LISTS, and those lists must not be an incidental row order either.
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'params']))
            ->from($db->quoteName('#__alfa_item_field_values'))
            ->where($db->quoteName('item_field_id') . ' = ' . $fieldId)
            ->where($db->quoteName('state') . ' = 1')
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');

        $values = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $params = json_decode((string) ($row->params ?? ''), true);
            $values[(int) $row->id] = ['id' => (int) $row->id, 'params' => \is_array($params) ? $params : []];
        }

        $resolved = FacetGroupResolver::resolve($this->fieldValueGroups($field, $values)['groups']);

        return $cache[$fieldId] = $resolved['groups'];
    }

    /**
     * Item counts per facet group for one field.
     *
     * Its own aggregate, run only when a caller actually needs grouped counts, so a panel
     * with grouping switched off pays nothing for the feature. The value -> group pairs are
     * joined in as a derived table rather than a CASE, because a value belongs to EVERY
     * group it reported and a CASE could only ever place it in one; COUNT(DISTINCT) then
     * keeps a two-group item counted once in each, never twice in either.
     *
     * SELF-EXCLUSION IS AXIS-LOCAL, AND THIS SURFACE IS THE GROUP AXIS. Only
     * `filter.groups[F]` is dropped. Every other constraint stays, including field F's
     * own VALUE selection, `filter.fields[F]`, which is emphatically NOT dropped here.
     * The number under a group chip answers exactly one question: "if I changed THIS
     * field's group selection, what would be left, under everything else I have asked
     * for, including the individual values I ticked on this same field?" Dropping the
     * value axis as well answered a different and larger question, and the answer
     * over-promised: the chip offered a count the shopper could not reach by clicking
     * it, because their own value selection was still narrowing the results underneath.
     * The value surface obeys the mirror rule ({@see self::getAvailableItemFieldValues()}):
     * it drops only `filter.fields[F]` and keeps `filter.groups[F]`.
     *
     * @param int $fieldId The item field.
     *
     * @return array<string, int> Group token => distinct item count.
     *
     * @since  2.0.0
     */
    public function getFacetGroupCounts(int $fieldId): array
    {
        static $cache = [];

        $map = $this->fieldGroupMap($fieldId);

        if (empty($map)) {
            return [];
        }

        $model = $this->createFilterSubModel([]);

        // Axis-local: ONLY the group axis of this field. filter.fields[$fieldId] stays.
        $activeGroups = (array) $model->getState('filter.groups', []);
        unset($activeGroups[$fieldId]);
        $model->setState('filter.groups', $activeGroups ?: null);

        $db = $this->getDatabase();
        $pairs = [];

        foreach ($map as $token => $group) {
            foreach ($group['valueIds'] as $valueId) {
                $pairs[] = 'SELECT ' . (int) $valueId . ' AS value_id, ' . $db->quote($token) . ' AS grp';
            }
        }

        if (empty($pairs)) {
            return [];
        }

        $query = $model->getListQuery();
        $query->clear('select')
            ->clear('order')
            ->clear('group')
            ->clear('having')
            ->join('INNER', $db->quoteName('#__alfa_item_field_value_index', 'ifx') . ' ON ifx.item_id = a.id')
            ->join('INNER', '(' . implode(' UNION ALL ', $pairs) . ') AS gmap ON gmap.value_id = ifx.value_id')
            ->where('ifx.field_id = ' . $fieldId)
            ->select('gmap.grp AS grp, COUNT(DISTINCT a.id) AS cnt')
            ->group('gmap.grp');

        // Memo by the built statement and the values bound to it.
        $key = self::statementKey(query: $query);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $counts = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $counts[(string) $row->grp] = (int) $row->cnt;
        }

        return $cache[$key] = $counts;
    }

    // Facet ShowOn

    /**
     * Resolve global ShowOn for the filter facets and make the surviving selection the
     * model's EFFECTIVE state.
     *
     * A field definition may carry `params.showon`, the same rule the item page uses.
     * On the catalogue the operands are the OTHER facets in the panel, and a facet's
     * state is the value ids the shopper has ticked. So `size.value = 2` reads as "the
     * shopper selected size 2", which is exactly what the shared evaluator already does
     * with an array (`=` is a containment test).
     *
     * Two rules make this well-defined:
     *
     *  - Only facets that ACTUALLY RENDER enter the state bag. A rule whose operand is
     *    absent is neutral to {@see RuleEvaluator}, and a neutral rule resolves to
     *    VISIBLE, so an unreachable operand disables the rule instead of hiding the
     *    target on a condition that can never be met.
     *  - A hidden facet's selections are REMOVED: from `filter.fields` AND from
     *    `filter.groups`, by the same visibility decision, because a facet has TWO
     *    surfaces and hiding it takes both off the page. Hiding only the UI would leave
     *    an invisible filter narrowing the results with no way to clear it, and a group
     *    chip is exactly as invisible and exactly as narrowing as a value chip. The
     *    pruned state is what the list query and the facet counts both read.
     *
     * Deciding what may be hidden needs to know what renders, and what renders depends
     * on the state, so this iterates. Pruning may only ever REMOVE selections, so each
     * pass can only widen the result set and the sequence converges; the loop stops as
     * soon as a pass leaves the effective filter key unchanged. That key spans BOTH
     * pruned axes ({@see self::effectiveFilterKey()}): a pass that removed only a group
     * selection still changed the candidate set, which can change which values are
     * reachable, which can change which facets render, which can change the verdict, so
     * a fields-only key would have declared convergence one pass early and returned a
     * hidden set computed against a state that no longer held. When nothing is hidden
     * that is the FIRST pass, and the facet memo makes it free.
     *
     * THE RAW REQUEST IS NOT THE STATE. A bookmarked or shared URL may well carry a
     * selection for a facet a rule now hides, and on the first render the address bar
     * still shows it: the URL is rewritten by the filter panel from its own form, so it
     * catches up on the first AJAX cycle, and no redirect is issued for it. That is
     * deliberate: rewriting the URL during the initial render would mean a redirect on
     * every bookmarked filter link, for a parameter that is already inert. The invariant
     * that actually matters is narrower and is enforced here: the stale parameter may
     * sit in the request, but nothing downstream consumes it. `filter.fields` and
     * `filter.groups` are both pruned before {@see self::getListQuery()} reads them,
     * before the facet counts are built,
     * and {@see self::getActiveFilters()} reports the pruned state to every caller, so
     * results, counts and rendered markup cannot see the hidden selection.
     *
     * @return int[] Field ids hidden by a rule (sorted, so the result is deterministic).
     * @since  2.0.0
     */
    private function resolveFacetShowon(): array
    {
        // A facet sub-model is handed the parent's already-effective state; only the
        // request-driven model resolves, which is also what stops the recursion
        // (buildFacetValues runs sub-model queries).
        if ($this->ignoreRequest) {
            return [];
        }

        if ($this->facetShowonHidden !== null) {
            return $this->facetShowonHidden;
        }

        // Re-entrancy: getListQuery() asks for the resolution too, and the resolution
        // runs queries. Mid-pass the state is not effective yet, so an inner caller must
        // read "nothing hidden" rather than recurse into a half-resolved answer.
        if ($this->facetShowonResolving) {
            return [];
        }

        $this->facetShowonResolving = true;

        try {
            $hidden = [];
            $stateKey = $this->effectiveFilterKey();

            // PROVEN bound, not a magic number: only a pass that REMOVES at least one
            // whole entry from one of the two pruned axes can change the key, so the
            // number of key-changing passes cannot exceed the number of entries the
            // request arrived with, counted across BOTH axes.
            $maxPasses = \count((array) $this->getState('filter.fields', []))
                + \count((array) $this->getState('filter.groups', []))
                + self::SHOWON_PASS_MARGIN;

            for ($pass = 0; $pass < $maxPasses; $pass++) {
                $verdict = $this->facetShowonVerdict($this->buildFacetValues());
                $hidden = $verdict['hidden'];
                $this->facetShowonInactive = $verdict['inactive'];

                $prunedFields = (array) $this->getState('filter.fields', []);
                $prunedGroups = (array) $this->getState('filter.groups', []);

                // REMOVE ONLY, and BOTH AXES by the one visibility decision. Nothing here
                // may add or rewrite another facet's selection.
                foreach ($hidden as $fieldId) {
                    unset($prunedFields[$fieldId], $prunedGroups[$fieldId]);
                }

                $this->setState('filter.fields', $prunedFields ?: null);
                $this->setState('filter.groups', $prunedGroups ?: null);

                $newKey = $this->effectiveFilterKey();

                if ($newKey === $stateKey) {
                    return $this->facetShowonHidden = $hidden;
                }

                $stateKey = $newKey;
            }

            // Unreachable while pruning only removes, so if we get here the invariant
            // is broken and the state below is not trustworthy. Say so loudly.
            Log::add(
                sprintf(
                    'Facet ShowOn exceeded its proven bound of %d passes; the effective filter state may be inconsistent. Hidden fields: %s',
                    $maxPasses,
                    implode(', ', $hidden) ?: 'none',
                ),
                Log::ERROR,
                'com_alfa',
            );

            return $this->facetShowonHidden = $hidden;
        } finally {
            $this->facetShowonResolving = false;
        }
    }

    /**
     * Decide, in RENDER ORDER, which facets a ShowOn rule hides and which rules are
     * inactive for want of a rendered operand.
     *
     * ORDERING IS THE CONTRACT, NOT AN IMPLEMENTATION DETAIL. A rule may only read a
     * facet already decided visible: the bag fills as this walks, so a facet that
     * renders later (or one hidden a moment ago) is simply absent. That is the same
     * backward-only contract the rest of the product already enforces:
     *
     *  - the rule BUILDER only offers lower-ranked fields as operands, so a forward
     *    reference cannot be authored through the UI at all;
     *  - the item page drops forward-reference rules at runtime
     *    ({@see \Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper});
     *  - the admin badges such a rule INVALID.
     *
     * So a forward reference is INERT by design, and the field `ordering` column must
     * not be read as a dependency graph that could be re-sorted into one: reordering
     * fields legitimately changes which rules can fire. Evaluating in dependency order
     * instead would also reintroduce oscillation (hiding A disables the rule that hid
     * B, which re-renders B, which re-hides A) which is precisely why this walks once,
     * forward, and never revisits.
     *
     * INACTIVE beats false. If any operand the rule needs is not a rendered facet, the
     * WHOLE rule is inactive and the target stays visible: a multi-operand rule must
     * never silently decay from "A = 1 AND B = 2" into "A = 1" because B is absent. The
     * shared {@see RuleEvaluator} deliberately treats a missing operand as a neutral
     * LEAF (correct on the item page, where every field is in one form), so the
     * distinction is drawn HERE, before the rule is handed over, leaving the shared
     * evaluator untouched.
     *
     * @param array $facets Candidate facets from {@see self::buildFacetValues()}, in render order.
     *
     * @return array{hidden: int[], inactive: int[]} Sorted field ids.
     * @since  2.0.0
     */
    private function facetShowonVerdict(array $facets): array
    {
        $active = (array) $this->getState('filter.fields', []);
        $evaluator = new RuleEvaluator();
        $bag = new StateBag();
        $hidden = [];
        $inactive = [];

        foreach ($facets as $fieldId => $facet) {
            $rule = $facet['showon'] ?? null;

            if (!empty($rule)) {
                if (!$this->ruleOperandsRendered($rule, $bag)) {
                    $inactive[] = (int) $fieldId;
                } elseif (!$evaluator->isVisible($rule, $bag)) {
                    $hidden[] = (int) $fieldId;

                    // Deliberately NOT added to the bag: a hidden facet is not on the
                    // page, so nothing downstream may read a selection from it.
                    continue;
                }
            }

            $selected = array_values(array_unique(array_map('intval', (array) ($active[$fieldId] ?? []))));

            $bag->put((string) $facet['name'], [
                // Mirror the browser StateReader exactly (none -> null, one -> scalar,
                // many -> array) so the server prune and the client engine can never
                // reach different conclusions from the same selection.
                'value' => match (\count($selected)) {
                    0 => null,
                    1 => $selected[0],
                    default => $selected,
                },
            ]);
        }

        sort($hidden);
        sort($inactive);

        return ['hidden' => $hidden, 'inactive' => $inactive];
    }

    /**
     * Whether EVERY operand address a rule reads is present in the bag, i.e. belongs to
     * a facet that renders and has already been decided visible.
     *
     * @param array $rule The decoded rule.
     * @param StateBag $bag The state built so far, in render order.
     *
     * @return bool False when the rule must be treated as inactive.
     * @since  2.0.0
     */
    private function ruleOperandsRendered(array $rule, StateBag $bag): bool
    {
        foreach (self::ruleOperandAddresses($rule) as $address) {
            if (!$bag->has($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every distinct operand ADDRESS (`name.facet`) a rule reads, walking nested groups.
     *
     * Addresses, not bare field names: a rule asking for a facet this surface does not
     * publish (the filter panel publishes only `.value`) is just as unreadable as one
     * naming a field that is not there, and both must make the rule inactive. Mirrors
     * the private collectors in ItemFieldsHelper / ItemFieldsAdminHelper, which walk the
     * same shape for the storefront and the admin badge.
     *
     * @param array $rule The decoded rule.
     *
     * @return string[]
     * @since  2.0.0
     */
    private static function ruleOperandAddresses(array $rule): array
    {
        $addresses = [];
        $walk = static function (array $node) use (&$walk, &$addresses): void {
            foreach ($node['rules'] ?? [] as $child) {
                if (!\is_array($child)) {
                    continue;
                }

                if (isset($child['rules'])) {
                    $walk($child);

                    continue;
                }

                $operand = trim((string) ($child['operand'] ?? ''));

                if ($operand !== '') {
                    $addresses[$operand] = true;
                }
            }
        };

        $walk($rule);

        return array_keys($addresses);
    }

    /**
     * A stable fingerprint of the effective item-field selection on BOTH pruned axes:
     * the convergence signal for {@see self::resolveFacetShowon()}.
     *
     * BOTH AXES, because both are pruned. A key over `filter.fields` alone would report
     * "unchanged" for a pass that removed only a hidden facet's GROUP selection, ending
     * the loop while the candidate set had in fact just widened, and a wider candidate
     * set can make a value reachable, make a facet render, and change the very verdict
     * being returned. The two axes are hashed separately (and labelled) so a value id and
     * a group token can never be mistaken for one another.
     *
     * Order-insensitive within a field, so a pass that only reshuffles is correctly read
     * as "no change".
     *
     * @since  2.0.0
     */
    private function effectiveFilterKey(): string
    {
        $fields = (array) $this->getState('filter.fields', []);

        foreach ($fields as $fieldId => $valueIds) {
            $ids = array_values(array_unique(array_map('intval', (array) $valueIds)));
            sort($ids);
            unset($fields[$fieldId]);
            $fields[(int) $fieldId] = $ids;
        }

        ksort($fields);

        $groups = (array) $this->getState('filter.groups', []);

        foreach ($groups as $fieldId => $tokens) {
            // Group tokens are opaque STRINGS, so they sort as strings; int-casting them
            // the way value ids are cast would collapse every token to 0.
            $set = array_values(array_unique(array_map('strval', (array) $tokens)));
            sort($set, SORT_STRING);
            unset($groups[$fieldId]);
            $groups[(int) $fieldId] = $set;
        }

        ksort($groups);

        return md5(serialize(['fields' => $fields, 'groups' => $groups]));
    }

    /**
     * Decode a stored ShowOn rule. Params hold it as a JSON STRING (the builder writes
     * it that way), and an empty string means "no rule".
     *
     * @param mixed $raw The raw `params.showon` value.
     *
     * @return array|null The decoded rule, or null when there is nothing to evaluate.
     * @since  2.0.0
     */
    private static function decodeShowonRule(mixed $raw): ?array
    {
        if (\is_array($raw)) {
            return empty($raw['rules']) ? null : $raw;
        }

        if (!\is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) && !empty($decoded['rules']) ? $decoded : null;
    }

    // Helpers

    /**
     * The key a facet answer is memoised under: the statement and the values
     * bound to it.
     *
     * A filter value (a category, a manufacturer, a product id) is bound to the
     * statement, not written into its text, so two questions that differ only
     * in such a value share that text. Keyed by the text alone, the second
     * question would be handed the first one's answer.
     *
     * @param \Joomla\Database\QueryInterface $query The built statement.
     *
     *
     * @since  2.0.0
     */
    private static function statementKey($query): string
    {
        $bound = [];

        foreach ($query->getBounded() as $name => $parameter) {
            $bound[$name] = $parameter->value;
        }

        return md5((string) $query . "\n" . serialize($bound));
    }

    /**
     * Create a sub-model for facet queries with selected states excluded.
     *
     * Copies all current filter states to a fresh ignore_request model, then
     * clears the states in $excludeKeys. Implements "exclude the filter being
     * faceted" for accurate option availability.
     *
     * @param string[] $excludeKeys Filter state keys to exclude
     * @since  1.0.0
     */
    public function createFilterSubModel(array $excludeKeys): self
    {
        $component = $this->app->bootComponent('com_alfa');

        /** @var self $model */
        $model = $component->getMVCFactory()->createModel('Items', 'Site', ['ignore_request' => true]);

        foreach ([
            'filter.id',
            'filter.search',
            // THE INTENT TRAVELS WITH THE SEARCH, and it is not optional here: a facet
            // sub-model runs the SAME search the listing ran, and the intent now selects
            // retrieval WIDTH and the candidate cap, not merely whether analytics record
            // it. Left unset, this model declares nothing, the fail-closed default reads
            // TYPEAHEAD, and the facets are then computed from a prefix-widened, tightly
            // capped candidate set while the listing beside them used another: filter
            // options that do not match the products shown.
            'search.intent',
            'filter.category_id',
            'filter.category',
            'filter.manufacturer',
            'filter.fields',
            'filter.groups',
            'filter.price_min',
            'filter.price_max',
            'filter.on_sale',
            'filter.discount_amount_min',
            'filter.discount_percent_min',
            'filter.stock',
        ] as $key) {
            $model->setState($key, $this->getState($key));
        }

        foreach ($excludeKeys as $key) {
            $model->setState($key, null);
        }

        // A FACET QUERY IS THE SAME SEARCH, RE-READ, so it must retrieve like the
        // listing and be counted like a re-read, which is exactly what CONTINUATION
        // means. Inheriting SEARCH verbatim would retrieve correctly and then RECORD:
        // every facet sub-model runs the provider again, and the recorder mints an event
        // for every intent that declares itself expressed, so one shopper's search
        // becomes a dozen rows, inflating volume and diluting every CTR denominator the
        // reports divide by. The downgrade changes nothing about retrieval (both are
        // non-TYPEAHEAD: same candidate cap, no prefix widening), only about who is
        // counted.
        if ($model->getState('search.intent') === SearchIntent::SEARCH->value) {
            $model->setState('search.intent', SearchIntent::CONTINUATION->value);
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
     * @since  1.0.0
     */
    public function getRecordsByIds(
        array $ids,
        string $table,
        array $selectFields = ['name'],
        string $idFieldName = 'id',
        array $mainFields = [],
    ): array {
        if (empty($ids)) {
            return [];
        }

        // Translatable tables: the listed fields (name, ...) no longer live in the
        // main table; resolve them in the active language via the per-language
        // tables. Only tables that have been migrated are listed here.
        $langPkMap = [
            '#__alfa_categories' => 'id_category',
            '#__alfa_manufacturers' => 'id_manufacturer',
            '#__alfa_item_fields' => 'id_item_field',
            '#__alfa_item_field_values' => 'id_item_field_value',
        ];

        if (isset($langPkMap[$table])) {
            $langFields = array_values(array_filter(
                $selectFields,
                static fn ($field) => $field !== $idFieldName,
            )) ?: ['name'];

            return MultilingualHelper::getRecordsByIds(
                db:                $this->getDatabase(),
                ids:               array_map('intval', $ids),
                table:             $table,
                idColumn:          $idFieldName,
                fields:            $mainFields,
                langTableBase:     $table,
                langPrimaryColumn: $langPkMap[$table],
                langFields:        $langFields,
            );
        }

        $db = $this->getDatabase();
        $selectFields = array_merge($selectFields, $mainFields);

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
     * @since  1.0.0
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
     * @since  1.0.0
     */
    private function extractIds(?string $ids): array
    {
        if ($ids === null || $ids === '') {
            return [];
        }
        return array_map('trim', explode(',', $ids));
    }
}
