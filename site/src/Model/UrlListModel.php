<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Pagination\AlfaPagination;
use Alfa\Component\Alfa\Site\Service\Facet\FilterKeyWhitelist;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\MVC\Model\ListModel;

/**
 * URL-based List Model
 *
 * URL is the single source of truth for all state.
 * No session persistence - clean, predictable behavior.
 *
 * @since  1.0.0
 */
abstract class UrlListModel extends ListModel
{
    /**
     * Application instance
     *
     * @var \Joomla\CMS\Application\CMSApplication
     */
    protected $app;

    /**
     * Cached defaults from XML form
     */
    protected ?array $defaults = null;

    /**
     * The filter keys the XML form declares `multiple="true"`, i.e. the keys whose value is a
     * LIST OF VALUES rather than one scalar. Filled by {@see self::getDefaults()} in the same
     * simplexml pass that reads the defaults; null until that pass has run.
     *
     * @var string[]|null
     * @since  1.9.0
     */
    protected ?array $listFilters = null;

    /**
     * Fallback defaults if not defined in XML
     */
    protected array $fallbackDefaults = [
        'filter' => [],
        'list' => [
            'fullordering' => 'a.id DESC',
            'limit' => 25,
        ],
    ];

    /**
     * When true (config 'ignore_request'), state comes ONLY from explicit
     * setState() calls; the URL is not read. Used when the model is driven
     * programmatically as a sub-listing inside another view (e.g. ItemsModel
     * building its sub-categories), so the host page's URL filters don't leak in.
     */
    protected bool $ignoreRequest = false;

    /**
     * Constructor
     *
     * @param array $config Configuration array
     * @since  1.0.0
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->app = Factory::getApplication();
        $this->ignoreRequest = !empty($config['ignore_request']);

        // Auto-set filterFormName if not defined
        if (empty($this->filterFormName)) {
            $this->filterFormName = $this->getDefaultFilterFormName();
        }
    }

    /**
     * Get default filter form name based on model class name
     *
     * Examples:
     * - Alfa\Component\Alfa\Site\Model\ItemsModel -> filter_items
     * - Alfa\Component\Alfa\Site\Model\CategoriesModel -> filter_categories
     * @since  1.0.0
     */
    protected function getDefaultFilterFormName(): ?string
    {
        $classNameParts = explode('Model', get_called_class());

        if (count($classNameParts) >= 2) {
            return 'filter_' . str_replace('\\', '', strtolower($classNameParts[1]));
        }

        return null;
    }

    // Form data binding

    /**
     * Get data for filter form binding
     * @since  1.0.0
     */
    protected function loadFormData(): array
    {
        if (empty($this->filterFormName)) {
            return [];
        }

        $this->getState();

        $data = [
            'filter' => [],
            'list' => [],
        ];

        $filters = $this->app->getInput()->get('filter', [], 'array');
        foreach (array_keys($filters) as $name) {
            $data['filter'][$name] = $this->getState('filter.' . $name);
        }

        $data['list'] = [
            'fullordering' => $this->getState('list.ordering') . ' ' . $this->getState('list.direction'),
            'limit' => $this->getState('list.limit'),
        ];

        return $data;
    }

    /**
     * Get the filter form, registering the site component forms path before delegating to the parent.
     *
     * @param array $data Data for the form.
     * @param bool $loadData Whether to load the form data.
     *
     * @return \Joomla\CMS\Form\Form|null The filter form, or null when no filter form is defined.
     *
     * @since  1.0.0
     */
    public function getFilterForm($data = [], $loadData = true)
    {
        \Joomla\CMS\Form\Form::addFormPath(JPATH_SITE . '/components/com_alfa/forms');
        return parent::getFilterForm($data, $loadData);
    }

    /**
     * Get active filters for display (badges/clear buttons)
     * @since  1.0.0
     */
    public function getActiveFilters(): array
    {
        if (empty($this->filterFormName)) {
            return [];
        }

        $activeFilters = [];
        $filters = $this->app->getInput()->get('filter', [], 'array');

        // This method decides robots/noindex: View\Items\HtmlView::isFilteredPage() calls it, and a
        // non-empty result forces 'noindex, follow'. It takes key NAMES from the raw request, so
        // the same whitelist has to apply here: a refused key must not even be looked up, let
        // alone reach the decision. (It would already resolve to null and be dropped as empty,
        // because populateState() never wrote it; this makes that structural instead of lucky.)
        $allowed = FilterKeyWhitelist::forModel(static::class);

        foreach (array_keys($filters) as $name) {
            if (!FilterKeyWhitelist::accepts($name, $allowed)) {
                continue;
            }

            $value = $this->getState('filter.' . $name);

            if (!$this->isEmpty($value)) {
                $activeFilters[$name] = $value;
            }
        }

        return $activeFilters;
    }

    // Defaults

    /**
     * Get the filter form XML path
     * @since  1.0.0
     */
    protected function getFilterFormPath(): ?string
    {
        if (empty($this->filterFormName)) {
            return null;
        }

        $componentPath = JPATH_SITE . '/components/' . $this->option;

        $formPaths = [
            $componentPath . '/forms/' . $this->filterFormName . '.xml',
        ];

        foreach ($formPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get defaults from XML form
     * @since  1.0.0
     */
    public function getDefaults(): array
    {
        if ($this->defaults !== null) {
            return $this->defaults;
        }

        $this->defaults = $this->fallbackDefaults;
        $this->listFilters = [];

        $formPath = $this->getFilterFormPath();
        $xml = $formPath !== null ? simplexml_load_file($formPath) : false;

        if ($xml) {
            foreach ($xml->fields as $fieldsGroup) {
                $groupName = (string) $fieldsGroup['name'];

                if (!isset($this->defaults[$groupName])) {
                    $this->defaults[$groupName] = [];
                }

                foreach ($fieldsGroup->field as $field) {
                    $name = (string) $field['name'];
                    $default = (string) $field['default'];

                    if ($default !== '') {
                        $this->defaults[$groupName][$name] = $default;
                    }

                    // Same pass, second fact: WHICH FILTERS ARE LISTS. `multiple="true"` is
                    // already this form's declaration that the field holds many values; the
                    // normaliser reads it so the state shape stops depending on how many the
                    // visitor happened to pick. See normalizeFilterValue().
                    if (
                        $groupName === 'filter'
                        && filter_var((string) $field['multiple'], FILTER_VALIDATE_BOOLEAN)
                    ) {
                        $this->listFilters[] = $name;
                    }
                }
            }
        }

        // A listing's page size and sort order can be configured per browsing
        // context. That is resolved into the DEFAULTS themselves, not into the
        // state, so the whole class agrees on one answer: populateListState()
        // seeds from them, and isDefault() keeps a configured value OUT of the
        // pagination URLs: a category set to 50 per page still paginates on a
        // clean `?start=`, exactly as it does on the shop's own default.
        $this->defaults['list'] = $this->contextListDefaults($this->defaults['list']);

        return $this->defaults;
    }

    /**
     * Hook: adjust the XML list defaults for THIS request's browsing context.
     *
     * Runs inside getDefaults(), which populateState() calls first, so an
     * override MUST NOT read model state (getState() would re-enter
     * populateState() while it is still running).
     *
     * @param array $defaults The `list` defaults parsed from the filter form
     *
     * @return array The defaults to use, same shape
     * @since  1.9.0
     */
    protected function contextListDefaults(array $defaults): array
    {
        return $defaults;
    }

    /**
     * Whether this listing fills a card with no picture of its own with the
     * configured placeholder image. Menu item -> global config: SiteApplication
     * ::getParams() merges the active menu item over the component params, so
     * one read covers both tiers (Registry merging skips the empty string the
     * inherit option stores).
     *
     * getParams() exists only on SiteApplication and these models also run
     * headlessly (API catalogue, search service); the component params are the
     * fallback there, exactly as the category/facet reads in ItemsModel do.
     *
     * @param string $param The listing's param name, e.g. `media_placeholder_items`
     *
     * @return bool True to hand MediaHelper the placeholder fallback
     * @since  1.9.0
     */
    protected function usePlaceholder(string $param): bool
    {
        $params = method_exists($this->app, 'getParams')
            ? $this->app->getParams('com_alfa')
            : ComponentHelper::getParams('com_alfa');

        return (bool) $params->get($param, 1);
    }

    // State management

    /**
     * Populate state from URL
     *
     * @param string|null $ordering Unused
     * @param string|null $direction Unused
     * @since  1.0.0
     */
    protected function populateState($ordering = null, $direction = null): void
    {
        $defaults = $this->getDefaults();
        $input = $this->app->getInput();

        // Clear all session state - URL is the only source
        $this->app->setUserState($this->context . '.filter', null);
        $this->app->setUserState($this->context . '.list', null);
        $this->app->setUserState($this->context . '.limitstart', null);

        // List params from URL or defaults (defaults only when ignoring the request,
        // e.g. a programmatic sub-listing; the caller sets state via setState()).
        $list = $this->ignoreRequest ? [] : $input->get('list', [], 'array');
        $this->populateListState($list, $defaults);

        // Pagination
        $limit = $this->state->get('list.limit');
        $limitstart = $this->ignoreRequest ? 0 : $input->getInt('limitstart', 0);
        $limitstart = $limit ? (int) (floor($limitstart / $limit) * $limit) : 0;
        $this->setState('list.start', $limitstart);

        // Filters from URL (skipped when ignoring the request).
        //
        // WHITELISTED, NOT ACCEPTED-THEN-IGNORED. A key this model does not read has no effect on
        // the query, but robots/noindex, hreflang and pagination hrefs all key off a filter's mere
        // PRESENCE, so an invented key used to mint crawlable, deindexed variants of this page
        // from the address bar. Refusing it here keeps it out of state, and every surface that
        // reads state is fixed at once. See {@see FilterKeyWhitelist} for the contract and for why
        // an omission there silently kills a live filter.
        //
        // This gate governs the REQUEST branch only. Programmatic setState() callers (sub-models,
        // menu presets, the cart owners, the API controllers) are unaffected.
        if (!$this->ignoreRequest) {
            $filters = $input->get('filter', [], 'array');
            $allowed = FilterKeyWhitelist::forModel(static::class);

            foreach ($filters as $name => $value) {
                if (!FilterKeyWhitelist::accepts($name, $allowed)) {
                    continue;
                }

                $this->setState('filter.' . $name, $this->normalizeFilterValue($value, $name));
            }
        }

        // Layout
        $this->setState('layout', $this->ignoreRequest ? null : $input->getString('layout'));
    }

    /**
     * Populate list state (ordering, limit)
     *
     * @param array $list List input from URL
     * @param array $defaults Defaults from XML
     * @since  1.0.0
     */
    protected function populateListState(array $list, array $defaults): void
    {
        $defaultLimit = (int) ($defaults['list']['limit'] ?? $this->fallbackDefaults['list']['limit']);
        $defaultFullordering = $defaults['list']['fullordering'] ?? $this->fallbackDefaults['list']['fullordering'];

        // Limit: URL or default
        $limit = isset($list['limit']) && $list['limit'] !== ''
            ? (int) $list['limit']
            : $defaultLimit;

        $this->setState('list.limit', $limit);

        // Ordering: URL or default
        $fullordering = !empty($list['fullordering'])
            ? $list['fullordering']
            : $defaultFullordering;

        [$orderCol, $orderDir] = $this->parseFullordering($fullordering);

        $this->setState('list.ordering', $orderCol);
        $this->setState('list.direction', $orderDir);
    }

    /**
     * Parse fullordering string
     *
     * @param string $fullordering e.g. "a.name ASC"
     * @return array [column, direction]
     * @since  1.0.0
     */
    protected function parseFullordering(string $fullordering): array
    {
        $parts = explode(' ', $fullordering);
        $fallbackParts = explode(' ', $this->fallbackDefaults['list']['fullordering']);

        $orderCol = $parts[0] ?? $fallbackParts[0];
        $orderDir = strtoupper($parts[1] ?? $fallbackParts[1] ?? 'DESC');

        if (!in_array($orderCol, $this->filter_fields)) {
            $orderCol = $fallbackParts[0];
            $orderDir = strtoupper($fallbackParts[1] ?? 'DESC');
        }

        if (!in_array($orderDir, ['ASC', 'DESC'])) {
            $orderDir = 'DESC';
        }

        return [$orderCol, $orderDir];
    }

    // Pagination & URL helpers

    /**
     * Get pagination with clean URLs
     *
     * @return \Alfa\Component\Alfa\Site\Pagination\AlfaPagination
     * @since  1.0.0
     */
    public function getPagination()
    {
        // NOT parent::getPagination(). Core's Pagination constructor copies an allow-list of
        // REQUEST vars (format, option, controller, view, layout, task, template, templateStyle,
        // tmpl, tpl, id, Itemid) into every page link it builds (setUrlParamsFromRequest(),
        // @since 5.2.0). The filter modules re-render this listing at ?tmpl=component, so every
        // page link came back carrying it and one click dropped the shopper on the bare component
        // template. AlfaPagination declares its base instead; see self::getLinkBase().
        //
        // Same store id, same cache slot and the same limit-minus-links arithmetic as
        // ListModel::getPagination(), which offers no seam for the class it constructs.
        $store = $this->getStoreId('getPagination');

        if (!isset($this->cache[$store])) {
            $this->cache[$store] = new AlfaPagination(
                total:      $this->getTotal(),
                limitstart: $this->getStart(),
                limit:      (int) $this->getState('list.limit') - (int) $this->getState('list.links'),
                linkBase:   $this->getLinkBase(),
            );
        }

        $pagination = $this->cache[$store];
        $input = $this->app->getInput();

        // Filters - only whitelisted, non-empty, non-default.
        //
        // BOUNDARY 3 of the one filter-key contract, and the last surface that read the raw request
        // bag. The other two are populateState() above (:315-326, which keeps a refused key out of
        // STATE, and with it out of the query and out of robots via getActiveFilters()) and
        // {@see \Alfa\Component\Alfa\Site\Helper\AssociationHelper::carriedState()} (which keeps it
        // out of the hreflang alternates). This one is independent of both: pagination hrefs are
        // built from `filter[...]` AS IT ARRIVED, never from state, so a key refused everywhere else
        // still rode into every page-link: `?filter[groups_mode]=all` reproduced live as
        // `href="/en/katastema?filter[groups_mode]=all&start=25"`. Those are crawlable links the
        // site publishes about ITSELF, so the refused key went on minting page-2..N variants of the
        // listing however clean state and canonical had become. Same helper, same allow-set: one
        // mechanism, not a second one.
        //
        // WARNING: THE MIRROR FAILURE IS WORSE THAN THE ONE BEING FIXED
        // Gate the wrong thing here and page 2 of a FILTERED listing silently loses its filter and
        // serves the unfiltered catalogue: a shopper-visible wrong result set, with no error. So
        // the gate decides which KEY may be forwarded and nothing else: the value is untouched
        // (normalizeFilterValue()/isNestedFilterMap() still shape it, so filter[fields][<id>][] and
        // filter[groups][<id>][] still reach page 2 whole), and every non-filter pagination param (
        // list[fullordering] and list[limit] below, the `start` Joomla appends itself, and
        // ItemsModel::getPagination()'s `category_id`) sits outside this loop and is untouched.
        //
        // A model with no row in the whitelist gets [] and therefore forwards NO filter param. That
        // is right, not lossy: populateState() refused those same keys, so the listing being
        // paginated is unfiltered and the hrefs now agree with the rows they page through.
        $filters = $input->get('filter', [], 'array');
        $allowed = FilterKeyWhitelist::forModel(static::class);

        foreach ($filters as $name => $value) {
            if (!FilterKeyWhitelist::accepts($name, $allowed)) {
                continue;
            }

            // Normalised with the KEY, exactly as populateState() did, so the href describes the
            // state the next page will actually rebuild.
            $normalized = $this->normalizeFilterValue($value, $name);

            if ($this->isEmpty($normalized) || $this->isDefault('filter', $name, $normalized)) {
                continue;
            }

            if (is_array($normalized)) {
                if ($this->isNestedFilterMap($normalized)) {
                    // One param per branch: filter[name][key]=v1,v2
                    foreach ($normalized as $key => $branch) {
                        $pagination->setAdditionalUrlParam(
                            "filter[{$name}][{$key}]",
                            implode(',', (array) $branch),
                        );
                    }
                } else {
                    $pagination->setAdditionalUrlParam("filter[{$name}]", implode(',', $normalized));
                }
            } else {
                $pagination->setAdditionalUrlParam("filter[{$name}]", $normalized);
            }
        }

        // List params - only if not default
        $currentFullordering = $this->state->get('list.ordering') . ' ' . $this->state->get('list.direction');
        if (!$this->isDefault('list', 'fullordering', $currentFullordering)) {
            $pagination->setAdditionalUrlParam('list[fullordering]', $currentFullordering);
        }

        $currentLimit = $this->state->get('list.limit');
        if (!$this->isDefault('list', 'limit', (string) $currentLimit)) {
            $pagination->setAdditionalUrlParam('list[limit]', $currentLimit);
        }

        return $pagination;
    }

    /**
     * The declared query every page link of this listing is built on.
     *
     * THE ROUTE, AND NOTHING ELSE: the component, the view, the menu item that owns the page and
     * the representation being served. State (filters, ordering, page size) is layered on top
     * by getPagination() and is never part of the base. A model with extra route keys declares
     * them by overriding this.
     *
     * `format` belongs here because it identifies the REPRESENTATION, not the wrapper around it:
     * the JSON listing paginates through this same object ({@see \Alfa\Component\Alfa\Site\View\
     * Items\JsonView}), and without it the next/prev links it advertises would answer in HTML.
     * `tmpl`, `layout`, `task`, `controller`, `template`, `templateStyle` and `tpl` all select a
     * wrapper or a render mode and are deliberately absent; that absence is the whole fix.
     *
     * ABSENT IS NOT FORBIDDEN. A listing that really IS a chrome-less surface (a picker in a
     * modal, a printable page) keeps its own chrome across pages by DECLARING it:
     *
     *     protected function getLinkBase(): array
     *     {
     *         return parent::getLinkBase() + ['tmpl' => 'component'];
     *     }
     *
     * which is what the admin modal grids already do by hand ({@see \Alfa\Component\Alfa\
     * Administrator\View\AlfaHtmlView}, tmpl + modal on the pagination). The difference this class
     * draws is between a view that knows it is a popup and says so, and a listing that inherited
     * the word from whatever request happened to render it.
     *
     * @return array<string, mixed>
     * @since   1.0.0
     */
    protected function getLinkBase(): array
    {
        $base = [
            'option' => 'com_alfa',
            'view' => $this->getName(),
        ];

        // The API reuses these models and has no menu to ask.
        $active = $this->app instanceof SiteApplication ? $this->app->getMenu()->getActive() : null;

        // Only the menu item that OWNS this listing, in a language this request may use, pins the
        // route. Anything else and the Itemid is left out so MenuRules resolves it, exactly as it
        // already does for card links and category tiles. That guard is not theoretical: an
        // explicit ?Itemid= parsed by SiteRouter::parseRawRoute() is never language-checked, and
        // MenuRules keeps a pinned Itemid without validating it.
        if (
            $active
            && ($active->query['option'] ?? '') === 'com_alfa'
            && ($active->query['view'] ?? '') === $base['view']
            && (!Multilanguage::isEnabled()
                || \in_array($active->language, ['*', $this->app->getLanguage()->getTag()], true))
        ) {
            $base['Itemid'] = (int) $active->id;
        }

        $format = $this->app->getInput()->getCmd('format', '');

        if ($format !== '' && $format !== 'html') {
            $base['format'] = $format;
        }

        return $base;
    }

    /**
     * Get current non-default list params for URL building
     * @since  1.0.0
     */
    public function getListUrlParams(): array
    {
        $params = [];

        $currentFullordering = $this->getState('list.ordering') . ' ' . $this->getState('list.direction');
        if (!$this->isDefault('list', 'fullordering', $currentFullordering)) {
            $params['list[fullordering]'] = $currentFullordering;
        }

        $currentLimit = $this->getState('list.limit');
        if (!$this->isDefault('list', 'limit', (string) $currentLimit)) {
            $params['list[limit]'] = $currentLimit;
        }

        return $params;
    }

    /**
     * Build URL with current list params
     *
     * @param string $url Base URL
     * @since  1.0.0
     */
    public function buildUrlWithListParams(string $url): string
    {
        $params = $this->getListUrlParams();

        if (empty($params)) {
            return $url;
        }

        $separator = strpos($url, '?') !== false ? '&' : '?';

        return $url . $separator . http_build_query($params);
    }

    // Filter helpers

    /**
     * Normalize filter value
     *
     * One filter, one state shape, whatever the URL spelling.
     * The same filter reaches this method in three spellings, and every one of them must land on
     * the shape the readers are documented to take ({@see ItemsModel} state map: `filter.category
     * int[]`, `filter.fields  int[][]`):
     *
     *   filter[category][]=337              native array   -> [337]
     *   filter[category]=337,338            comma list     -> [337, 338]
     *   filter[category]=337                ONE value      -> [337]      <- was the scalar "337"
     *
     * The third one is the whole point. getPagination() emits a list as `implode(',', ...)`, so a
     * one-element list is emitted WITHOUT a comma and comes back indistinguishable from a scalar;
     * the string branch below only ever split on a comma, so state held "337" and every reader
     * typed to an array broke on it: `mod_alfa_filters/tmpl/default_filters.php:193` fataled
     * ("Argument #2 ($activeCategories) must be of type array, string given") and :203 the same
     * for `manufacturer`. That URL is not exotic: it is the site's OWN page-2 href after ticking
     * a single category. Shape can never decide this: "337" and "shirt" are the same shape, so
     * the FORM decides it: a field carrying `multiple="true"` is a list, always. See
     * {@see self::isListFilter()}. Scalar filters (search, price_min, on_sale ...) are untouched.
     *
     * @param mixed $value Input value
     * @param string|null $name The filter key, when known; selects the declared value shape.
     *                          Omit only for a value whose key is meaningless (a map branch).
     * @return mixed Normalized value
     * @since  1.0.0
     */
    protected function normalizeFilterValue($value, ?string $name = null)
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        $isList = $name !== null && $this->isListFilter($name);

        if (is_array($value)) {
            // Nested map (filter[name][key][] => list): normalise each branch and
            // PRESERVE the keys. The flat normaliser re-indexes with array_values,
            // which would destroy the keys (e.g. the field ids in
            // filter[fields][<fieldId>][]).
            if ($this->isNestedFilterMap($value, $isList)) {
                return $this->normalizeFilterMap($value);
            }

            return $this->normalizeArrayValue($value);
        }

        if (is_string($value) && strpos($value, ',') !== false) {
            return $this->normalizeArrayValue(explode(',', $value));
        }

        if ($isList) {
            return $this->normalizeArrayValue([$value]);
        }

        return is_string($value) ? trim($value) : $value;
    }

    /**
     * Does the filter form declare this key as holding MANY values?
     *
     * The form is the existing authority: `multiple="true"` on `<field name="category">` is
     * already what makes the panel render a multi-select and emit `filter[category][]`. Reading
     * it here keeps one declaration instead of a second list to forget to update. A model with
     * no filter form (or a key that is URL-only, such as `id`) simply gets false: its value keeps
     * the shape it arrived in, exactly as before.
     *
     * @param string $name Filter key.
     * @since  1.9.0
     */
    protected function isListFilter(string $name): bool
    {
        if ($this->listFilters === null) {
            // Fills $this->listFilters in the same simplexml pass.
            $this->getDefaults();
        }

        return \in_array($name, (array) $this->listFilters, true);
    }

    /**
     * True when the array is a map of lists, i.e. came from a nested filter such as
     * filter[fields][<id>][], in EITHER spelling it can arrive in.
     *
     * Spelling 1, the browser's: `filter[fields][1][]=1` -> [1 => [1]]. A branch is an array.
     *
     * Spelling 2, the one getPagination() emits: it writes each branch as
     * `filter[fields][<id>]=v1,v2`, dropping the trailing `[]`, so page 2 arrives as
     * [1 => '1']: a map whose branches are SCALARS. The old test saw no array branch, called it
     * flat, and handed it to normalizeArrayValue(), whose array_values() DESTROYS THE FIELD IDS:
     * `filter[fields][1]=1` became [0 => 1], field id 0, `1 = 1`: page 2 of a filtered catalogue
     * silently served the UNFILTERED catalogue. Worse, with two branches the ids were re-keyed
     * POSITIONALLY, so `filter[fields][99]=9&filter[fields][1]=1` applied a real but WRONG filter
     * (field 1 = value 1) instead of matching nothing.
     *
     * A keyed map is therefore nested whatever its branches hold. The discriminator is
     * array_is_list(): a genuine flat value always arrives 0-based and gapless (`filter[x][]=a&
     * filter[x][]=b` and explode() on a comma list both produce keys 0..n-1) while a nested map
     * is keyed by entity ids, which start at 1. This is deliberately generic: `filter[groups]`
     * and any nested key added later are fixed by the same line, with no key named anywhere.
     *
     * @param array $value Candidate filter value.
     * @param bool $isList The key is form-declared `multiple="true"`, i.e. a flat list of values.
     *                     Its keys carry no meaning, so only the strict test may apply to it:
     *                     `filter[category][7]=337` is one category, never a map.
     * @since  1.3.0
     */
    private function isNestedFilterMap(array $value, bool $isList = false): bool
    {
        foreach ($value as $branch) {
            if (is_array($branch)) {
                return true;
            }
        }

        return !$isList && !array_is_list($value);
    }

    /**
     * Normalise a nested filter map, keeping its keys and cleaning each branch.
     *
     * Each branch is run through the scalar/array normaliser (so comma strings
     * and arrays both become clean int lists); empty branches are dropped, and
     * an all-empty map collapses to []. Numeric string keys are coerced to int
     * by PHP, so consumers can read either form.
     *
     * @param array $value The raw nested map.
     *
     * @return array<int|string, array> key => normalised list
     * @since  1.3.0
     */
    protected function normalizeFilterMap(array $value): array
    {
        $result = [];

        foreach ($value as $key => $branch) {
            $branch = $this->normalizeFilterValue($branch);
            $branch = $this->normalizeArrayValue($branch === '' ? [] : (array) $branch);

            if (!empty($branch)) {
                $result[$key] = $branch;
            }
        }

        return $result;
    }

    /**
     * Normalize array value
     *
     * @param array $value Input array
     * @return array Normalized array
     * @since  1.0.0
     */
    protected function normalizeArrayValue(array $value): array
    {
        $value = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $value);
        $value = array_filter($value, fn ($v) => $v !== '' && $v !== null);

        if (empty($value)) {
            return [];
        }

        $allNumeric = true;
        foreach ($value as $v) {
            if (!is_numeric($v)) {
                $allNumeric = false;
                break;
            }
        }

        if ($allNumeric) {
            return array_values(array_unique(array_filter(
                array_map('intval', $value),
                fn ($v) => $v > 0,
            )));
        }

        return array_values(array_unique($value));
    }

    /**
     * Check if value is empty
     *
     * @param mixed $value
     * @since  1.0.0
     */
    protected function isEmpty($value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value)) {
            return empty(array_filter($value, fn ($v) => $v !== '' && $v !== null));
        }

        return false;
    }

    /**
     * Check if value equals default
     *
     * @param string $group Group name
     * @param string $name Field name
     * @param mixed $value Value to check
     * @since  1.0.0
     */
    protected function isDefault(string $group, string $name, $value): bool
    {
        $defaults = $this->getDefaults();
        $default = $defaults[$group][$name] ?? '';

        if (is_array($value)) {
            if (is_array($default)) {
                return empty(array_diff($value, $default)) && empty(array_diff($default, $value));
            }
            return empty($value) && ($default === '' || $default === []);
        }

        return (string) $value === (string) $default;
    }
}
