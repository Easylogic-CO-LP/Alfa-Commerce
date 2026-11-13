<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Items;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Search\ResultSetRenderEvent;
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Service\Search\ResultAttribution;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\PageCache;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Helper\ResultAttributes;
use Alfa\Component\Alfa\Site\Helper\SlotResolver;
use Alfa\Component\Alfa\Site\Service\Facet\NarrowingFilters;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Throwable;

/**
 * View class for a list of items
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    protected $app;
    protected $items;
    protected $categories;
    protected $pagination;
    protected $state;
    protected $params;
    protected $category;
    protected $showFilters;

    /**
     * @var bool Keep the bar's Filter Options section closed while the listing is filtered
     *           (collapse_items_filters_when_filtered). Public: the searchtools layout reads
     *           it from outside the view's scope.
     */
    public bool $filtersCollapsed = false;

    /** @var bool Whether the listing's subcategory strip renders on THIS request */
    protected $showCategories = true;

    /** @var bool Joomla's page heading (the menu item's Show Page Heading), as Joomla's own components print it */
    protected bool $showPageHeading = false;

    /**
     * @var ResultAttribution|null What the rendered result set carries (2.2.0): its handle (or none), its shown order;
     *                             rendered only through resultSetAttributes() and resultAttributes()
     */
    protected ?ResultAttribution $resultAttribution = null;

    /** @var int|null The position a single card's reload carried (2.2.0), at least 1; null for none */
    protected ?int $cardPosition = null;

    /** @var bool The category's title above the listing (show_category_title: category, then menu item, then global) */
    protected bool $showCategoryTitle = false;

    /**
     * @var bool The category's description above the listing (show_category_description, the same chain), only when it
     *           has text or an image
     */
    protected bool $showCategoryDescription = false;

    /**
     * @var bool Whether the page opens on its results (2.2.0, design 2.20): a later page reached by its link, or a
     *           request that submits a filter. The template marks the results region (data-results-reveal); list-form.js
     *           scrolls it into view and gives it the focus on load.
     */
    protected bool $revealResults = false;

    /**
     * @var string The results region's name (2.2.0, UX-53): the counter Joomla's pagination prints ("1 - 25 / 132
     *             items"), so the focus a filter gives the region announces how many products show; empty for none.
     */
    protected string $resultsCounter = '';

    /** @var string Resolved default card sub-template (the "item" slot) for rows without their own */
    protected $cardTpl = 'item';

    /** @var string Resolved sub-template for the listing's "categories" strip */
    protected $listCategoriesTpl = 'categories';

    /** @var array Category-level layout overrides, decoded once */
    protected $catLayouts = [];

    /** @var array Per-request cache: distinct row card variants -> resolved sub-template */
    private array $cardCache = [];

    /**
     * Constructor
     *
     * @param array $config Configuration settings
     * @since  1.0.0
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->app = Factory::getApplication();
    }

    /**
     * Display the view
     *
     * @param string $tpl Template name
     *
     * @return void
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        $input = $this->app->getInput();

        // Single-card reload (view=items&layout=item): re-render ONE <article>
        // for the given id + buyer selection, swapped in place by the catalog
        // JS: the listing twin of the product page's component re-fetch.
        if (RequestScalar::read($input, 'layout', null, 'cmd') === 'item') {
            // The model reads the buyer's sel[]/opt[]/conf[] selection itself
            // (VariantHelper::requestSelection); the view stays thin.
            $itemsModel = $this->getModel();
            $this->item = $itemsModel->getItem(RequestScalar::int($input, 'id', 0));

            if (!$this->item) {
                throw new Exception(\Joomla\CMS\Language\Text::_('COM_ALFA_ERROR_ITEM_NOT_FOUND'), 404);
            }

            $this->priceSettings = PriceSettings::get();

            // Same card chain as the listing, on the SAME single query: the model
            // joined the routed category's layouts (the reload URL carries
            // category_id): item -> category -> params. The JS swaps the whole
            // [data-item-id] (variant change) or only the inner [data-item-prices]
            // (option change).
            $this->params = $this->app->getParams('com_alfa');
            $this->cardTpl = SlotResolver::resolve(
                value: (string) $this->params->get('items_card_layout', ''),
                view: 'items',
                slot: 'item',
            );

            // THE CARD KEEPS ITS PLACE IN THE RESULT SET (2.2.0): the swap sends the position the card
            // it replaces carried, and the card renders it back through the same call. Only a whole
            // number from 1 counts; anything else renders none. The handle stays on the container.
            $position = trim(RequestScalar::string($input, 'position', ''));
            $this->cardPosition = ctype_digit($position) && (int) $position >= 1 ? (int) $position : null;
            $this->resultAttribution = new ResultAttribution(engine: self::engineKey());
            $this->announceResultSet(kind: ResultSetRenderEvent::KIND_CARD);

            parent::display($this->cardTplFor($this->item));

            return;
        }

        $this->params = $this->app->getParams('com_alfa');

        $itemsModel = $this->getModel();

        // "Always respect the menu items": when the request's filter state is
        // owned by ANOTHER items menu (a preset landing whose exact presets the
        // visitor composed, or the plain listing after deviating from this
        // landing's presets), move to the owner's SEO URL. Document requests
        // only; AJAX partials never reach this path.
        // getCmd returns NULL (not '') for an absent param; compare against an
        // explicit default or the strict check never matches.
        if (RequestScalar::cmd($input, 'tmpl', '') === '' && ($canonical = $itemsModel->getCanonicalMenuUrl())) {
            $this->app->redirect($canonical, 301);

            return;
        }

        // Category first: the same single fetch this view always did (breadcrumbs,
        // meta), just moved up; the model hands layouts over as a ready array.
        $this->category = $itemsModel->getItemsCategory();
        $this->catLayouts = $this->category->layouts ?? [];

        // Alternative main layout: explicit menu/request layout wins, then the
        // category's own, then the menu-merged params (menu item, then global).
        if (
            $this->getLayout() === 'default'
            && ($layout = ($this->catLayouts['layout'] ?? '') ?: $this->params->get('items_layout'))
        ) {
            $this->setLayout($layout);
        }

        // Menu/global default card only: the entity levels (item's own value,
        // viewed/default category) are stamped per row by the model (locked spec).
        $this->cardTpl = SlotResolver::resolve(
            value: (string) $this->params->get('items_card_layout', ''),
            view: 'items',
            slot: 'item',
            layout: $this->getLayout(),
        );

        // Listing "categories" strip: category -> menu -> global, same chain.
        $this->listCategoriesTpl = SlotResolver::resolve(
            value: ($this->catLayouts['list_categories'] ?? '') ?: (string) $this->params->get('items_categories_layout', ''),
            view: 'items',
            slot: 'categories',
            layout: $this->getLayout(),
        );

        // Whether the inline filter panel shows here (global default, overridable per
        // menu item). OFF by default: most storefronts surface filters through
        // mod_alfa_filters, and a listing rendering both would show them twice.
        $this->showFilters = (bool) $this->params->get('show_items_filters', 0);

        // Whether that panel's Filter Options section opens by itself while the listing
        // is filtered. Core searchtools opens it; for an admin the section is the only
        // place the active filters show. A storefront filter usually arrives from
        // elsewhere (a filters module, a landing link, a shared URL), and the component
        // cannot know which module a site pairs it with, nor should it: it knows the
        // listing is filtered and what the merchant asked for. On by default: closed
        // until the visitor opens it with the Filter Options button.
        $this->filtersCollapsed = (bool) $this->params->get('collapse_items_filters_when_filtered', 1);

        // Get items data
        $this->items = $itemsModel->getItems();

        // THE ATTRIBUTION, read AFTER getItems() because that is what runs the search: the
        // provider's handle (or none) and the shown order. With no handle the templates render
        // no attribution metadata at all.
        $this->resultAttribution = $itemsModel->getResultAttribution() ?? new ResultAttribution(engine: self::engineKey());
        $this->keepOutOfPageCache();

        $this->pagination = $itemsModel->getPagination();
        $this->state = $itemsModel->getState();
        $this->filterForm = $itemsModel->getFilterForm();
        $this->activeFilters = $itemsModel->getActiveFilters();

        $this->availableManufacturers = $itemsModel->getAvailableManufacturers();

        // THE SUBCATEGORY STRIP, AND WHETHER THIS REQUEST STILL DESERVES ONE.
        // A tile's href carries ordering and page size and no filter at all
        // (UrlListModel::buildUrlWithListParams), so on a filtered listing every
        // tile is a button that throws the visitor's filters away. Merchants who
        // would rather the strip left decide it here: category context replaced ->
        // no strip. Price, sale and discount refinements never count, because they
        // leave the shopper in the same category (see NarrowingFilters).
        //
        // Decided BEFORE the fetch, not after: a hidden strip must cost no query.
        $this->showCategories = !((bool) $this->params->get('hide_categories_when_filtered', 1)
            && NarrowingFilters::present($this->activeFilters));

        // Get category-related data ($this->category was already fetched above)
        $this->categories = $this->showCategories ? $itemsModel->getItemsCategories() : [];

        // Above the strip: the category's title and its description (the page heading is _prepareDocument()'s).
        $this->decideCategoryBlocks();

        // Resolve price settings once for all items
        $this->priceSettings = PriceSettings::get();

        // THE SHOPPER ASKED FOR THE PRODUCTS (2.2.0, design 2.20): page 2 by its link, or a filter the request submits,
        // opens on the results rather than the page's top. The server decides, from what it already knows (the page the
        // state shows, the filter the request carries), so no address or filter name is parsed in JavaScript. A menu
        // item's preset filters are the page itself, not a submission.
        $this->revealResults = (int) $this->state->get('list.start', 0) > 0 || self::carriesValue(value: $input->get('filter', [], 'array'));

        // The region's name, in the words of the counter Joomla's pagination prints above the list.
        $total = (int) $this->pagination->total;

        if ($total > 0) {
            $first = (int) $this->pagination->limitstart + 1;
            $limit = (int) $this->pagination->limit;
            $this->resultsCounter = Text::sprintf('JLIB_HTML_PAGINATION_NUMBERS', $first, $limit > 0 ? min($first + $limit - 1, $total) : $total, $total);
        }

        $this->_prepareDocument();

        // Every rendered result set is announced, from the view and never from a template, so no override can lose it.
        $this->announceResultSet(kind: \in_array(RequestScalar::cmd($input, 'tmpl', ''), ['', 'index', 'offline'], true) ? ResultSetRenderEvent::KIND_PAGE : ResultSetRenderEvent::KIND_FRAGMENT);

        parent::display($tpl);
    }

    /**
     * The results container's attribution attributes (2.2.0): the handle and the shown order, only when a handle was
     * minted. The listing template's supported override contract, with resultAttributes().
     *
     * @return string The attributes, each preceded by a space; '' for none.
     *
     * @since  2.2.0
     */
    public function resultSetAttributes(): string
    {
        $attribution = $this->resultAttribution;

        if ($attribution === null || !$attribution->hasHandle()) {
            return '';
        }

        return ResultAttributes::render([ResultAttributes::HANDLE => $attribution->handle, ResultAttributes::ORDER => $attribution->order]);
    }

    /**
     * A result's attribution attributes (2.2.0): its global position, only when the result set carries a handle, or,
     * on a single card's reload, the position the reload carried.
     *
     * @param int|null $position The result's global position, from 1.
     *
     * @return string The attributes, each preceded by a space; '' for none.
     *
     * @since  2.2.0
     */
    public function resultAttributes(?int $position = null): string
    {
        if ($this->cardPosition !== null) {
            return ResultAttributes::render([ResultAttributes::POSITION => $this->cardPosition]);
        }

        if ($this->resultAttribution === null || !$this->resultAttribution->hasHandle()) {
            return '';
        }

        return ResultAttributes::render([ResultAttributes::POSITION => $position]);
    }

    /**
     * Announce the rendered result set (onAlfaResultSetRender; mode A, a display phase): the alfa-search group imported
     * first, each listener isolated, so one that fails is logged by the helper, named, and the others and the listing
     * go on. A listener may add its own assets and script options.
     *
     * @param string $kind ResultSetRenderEvent::KIND_PAGE, KIND_FRAGMENT or KIND_CARD.
     *
     * @since  2.2.0
     */
    private function announceResultSet(string $kind): void
    {
        try {
            $plugins = AlfaPluginHelper::get();
            $plugins->importGroup('alfa-search');
            $event = new ResultSetRenderEvent('onAlfaResultSetRender', [
                'subject' => $this,
                'context' => 'com_alfa.items',
                'kind' => $kind,
                'engine' => self::engineKey(),
                'attribution' => $this->resultAttribution ?? new ResultAttribution(engine: self::engineKey()),
                'position' => $this->cardPosition,
            ]);
            $plugins->dispatchIsolated($event);
        } catch (Throwable $e) {
            try {
                Log::add('Items view: a listener of onAlfaResultSetRender failed; the listing renders: ' . $e->getMessage(), Log::WARNING, 'com_alfa.search');
            } catch (Throwable) {
                // A log that cannot be written must not break the listing.
            }
        }
    }

    /**
     * A handle that belongs to this response alone is never shared through Joomla's page cache (2.2.0), through the
     * storefront's one way of saying so ({@see PageCache::excludeResponse()}).
     *
     * @since  2.2.0
     */
    private function keepOutOfPageCache(): void
    {
        if ($this->resultAttribution === null || !$this->resultAttribution->excludesSharing()) {
            return;
        }

        PageCache::excludeResponse();
    }

    /**
     * The configured engine's key (the component option search_engine).
     *
     * @since  2.2.0
     */
    private static function engineKey(): string
    {
        return (string) ComponentHelper::getParams('com_alfa')->get('search_engine', 'database');
    }

    /**
     * Card sub-template for ONE listing row: the model-stamped entity preference
     * (item -> viewed/default category, locked spec), else the menu/global
     * default. Distinct variants touch the filesystem once per request: N rows
     * cost one is_file per distinct value.
     *
     * @param object $item The listing row
     *
     * @return string loadTemplate() argument for this row's card
     * @since  2.0.0
     */
    public function cardTplFor(object $item): string
    {
        $card = (string) ($item->cardLayout ?? '');

        if ($card === '') {
            return $this->cardTpl;
        }

        return $this->cardCache[$card] ??= SlotResolver::resolve(value: $card, view: 'items', slot: 'item', layout: $this->getLayout());
    }

    /**
     * Prepares the document
     * @since  1.0.0
     */
    protected function _prepareDocument(): void
    {
        $this->addPageHeading();
        $this->addBreadcrumbs();
        $this->addMetaTags();
        $this->addCanonicalUrl();
        $this->addRobotsMeta();
    }

    /**
     * The category's title and description above the listing, IN THIS ORDER after Joomla's page heading (when the menu
     * item's option enables it, addPageHeading()): each by Alfa's own setting, never by Show Page Heading; the
     * description only when it has something to show, the title only when it differs from the page heading
     * (addPageHeading() decides that, once the heading's text is known). A listing of every product has no category:
     * only the page heading can show there.
     *
     * @since  2.2.0
     */
    protected function decideCategoryBlocks(): void
    {
        $hasCategory = !empty($this->category->id);

        $this->showCategoryTitle = $hasCategory && $this->categoryShows(name: 'show_category_title');
        $this->showCategoryDescription = $hasCategory && $this->categoryShows(name: 'show_category_description')
            && self::hasContent(html: (string) ($this->category->desc ?? ''));
    }

    /**
     * Whether one of the category's listing blocks shows: the category's own value (its list options) when it set one,
     * else the menu item's, else the global (SiteApplication::getParams() has merged those two, Use Global leaving the
     * global), else shown. Only null and '' are "not set": 0 is a value.
     *
     * @param string $name The option: show_category_title or show_category_description.
     *
     * @return bool True when the block shows.
     *
     * @since  2.2.0
     */
    private function categoryShows(string $name): bool
    {
        $own = $this->category->list_options[$name] ?? null;

        if ($own !== null && $own !== '') {
            return (bool) (int) $own;
        }

        $inherited = $this->params->get($name);

        return $inherited === null || $inherited === '' ? true : (bool) (int) $inherited;
    }

    /**
     * Whether a request value carries anything: a non-empty string, or an array holding one at any depth (a filter
     * form submits its empty controls too).
     *
     * @param mixed $value The value as the request has it.
     *
     * @since  2.2.0
     */
    private static function carriesValue(mixed $value): bool
    {
        if (\is_array($value)) {
            foreach ($value as $inner) {
                if (self::carriesValue(value: $inner)) {
                    return true;
                }
            }

            return false;
        }

        return trim((string) $value) !== '';
    }

    /**
     * Whether a description has anything to show: text once its tags are stripped and its white space trimmed, or an
     * image. An editor's empty paragraph has neither.
     *
     * @param string $html The description as stored.
     *
     * @return bool True when it has text or an image.
     *
     * @since  2.2.0
     */
    private static function hasContent(string $html): bool
    {
        if (preg_match('/<(?:img|picture|svg|video|iframe)\b/i', $html) === 1) {
            return true;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text) !== '';
    }

    /**
     * The text of Joomla's page heading when the menu item gave none, as Joomla's own category listing sets it: on
     * this listing's own menu item (the same category, or every product), the menu item's Page Title, else its title;
     * elsewhere the category's name. A Page Heading the merchant typed always wins (def()).
     *
     * @since  2.2.0
     */
    protected function addPageHeading(): void
    {
        $menu = $this->app->getMenu()->getActive();
        $categoryId = (int) ($this->category->id ?? 0);
        $ownMenu = $menu !== null
            && ($menu->query['option'] ?? '') === 'com_alfa'
            && ($menu->query['view'] ?? '') === 'items'
            && (int) ($menu->query['category_id'] ?? 0) === $categoryId;

        $this->params->def('page_heading', $ownMenu
            ? $this->params->get('page_title', $menu->title)
            : (string) ($this->category->name ?? ''));

        // Shown when the menu item's Show Page Heading says so and there is a heading to show.
        $heading = trim((string) $this->params->get('page_heading', ''));
        $this->showPageHeading = (bool) $this->params->get('show_page_heading', 0) && $heading !== '';

        // NEVER THE SAME HEADING TWICE (2.2.0, UX-52): under a page heading that reads as the category's name, the
        // category's title would repeat it, so it prints only when it says something the heading does not.
        if ($this->showPageHeading && $heading === trim((string) ($this->category->name ?? ''))) {
            $this->showCategoryTitle = false;
        }
    }

    /**
     * Add breadcrumbs for category path
     * @since  1.0.0
     */
    protected function addBreadcrumbs(): void
    {
        if (empty($this->category->id)) {
            return;
        }

        $pathway = $this->app->getPathway();
        $categoryPath = CategoryHelper::getCategoryPath($this->category->id);

        foreach ($categoryPath as $category) {
            $link = Route::_('index.php?option=com_alfa&view=items&category_id=' . $category['id']);

            if (!in_array($category['name'], $pathway->getPathwayNames())) {
                $pathway->addItem($category['name'], $link);
            }
        }
    }

    /**
     * Add meta title and description
     * @since  1.0.0
     */
    protected function addMetaTags(): void
    {
        $document = $this->getDocument();

        // Meta Title
        $metaTitle = $this->params->get('page_title', '');

        if (!empty($this->category->meta_title)) {
            $metaTitle = $this->category->meta_title;
        } elseif (!empty($this->category->name)) {
            $metaTitle = $this->category->name;
        }

        // Add site name to title
        $siteNamePosition = $this->app->get('sitename_pagetitles', 0);
        $siteName = $this->app->get('sitename');

        if ($siteNamePosition == 1) {
            $metaTitle = $siteName . ' - ' . $metaTitle;
        } elseif ($siteNamePosition == 2) {
            $metaTitle = $metaTitle . ' - ' . $siteName;
        }

        // Meta Description
        $metaDescription = $this->params->get('menu-meta_description', '');

        if (!empty($this->category->meta_desc)) {
            $metaDescription = $this->category->meta_desc;
        } elseif (!empty($this->category->desc)) {
            $metaDescription = AlfaHelper::cleanContent(
                html: $this->category->desc,
                removeTags: true,
                removeScripts: true,
                removeIsolatedPunctuation: false,
            );
        }

        $document->setTitle($metaTitle);
        $document->setDescription($metaDescription);
    }

    /**
     * Add canonical URL
     *
     * Canonical points to base category URL + pagination only
     * Removes filters, sorting, and limit from canonical
     * @since  1.0.0
     */
    protected function addCanonicalUrl(): void
    {
        $uri = Uri::getInstance();

        // Base URL (scheme + host + path, no query params)
        $canonical = $uri->toString(['scheme', 'host', 'port', 'path']);

        // Keep pagination if > 0
        $limitstart = (int) $this->state->get('list.start', 0);
        if ($limitstart > 0) {
            $canonical .= '?start=' . $limitstart;
        }

        // NOT addHeadLink(): document head links are keyed by HREF, and on a
        // multilingual site the languagefilter's SELF hreflang alternate has the
        // IDENTICAL href; whichever is added last silently overwrites the other.
        // A custom tag renders independently, so canonical and self-alternate
        // coexist (the correct SEO pair).
        $this->getDocument()->addCustomTag('<link href="' . htmlspecialchars($canonical) . '" rel="canonical">');
    }

    /**
     * Add robots meta tag
     *
     * Filtered/sorted pages get noindex (priority)
     * Otherwise uses category or menu setting
     * @since  1.0.0
     */
    protected function addRobotsMeta(): void
    {
        $document = $this->getDocument();

        // Only a full-page document may be indexed; a chrome-less listing is a duplicate of one.
        // Anything but no tmpl, `index` and `offline` (forced back to index.php while online)
        // renders chrome-less: templates/alfa declares no parent, so an unknown value falls
        // through to templates/system, whose index.php just includes its own component.php.
        // Not a redirect: tmpl is the filter modules' AJAX transport. The card reload
        // (layout=item) never reaches here: display() returns before _prepareDocument().
        $tmpl = RequestScalar::cmd($this->app->getInput(), 'tmpl', '');

        if (!\in_array($tmpl, ['', 'index', 'offline'], true)) {
            $document->setMetaData('robots', 'noindex, follow');

            return;
        }

        // Filtered pages are ALWAYS noindex - takes priority
        if ($this->isFilteredPage()) {
            $document->setMetaData('robots', 'noindex, follow');
            return;
        }

        // For non-filtered pages, use category or menu robots setting
        $metaRobots = $this->params->get('robots', '');

        if (!empty($this->category->meta_data)) {
            $otherMetaData = json_decode($this->category->meta_data, true) ?: [];
            if (!empty($otherMetaData['robots'])) {
                $metaRobots = $otherMetaData['robots'];
            }
        }

        if (!empty($metaRobots)) {
            $document->setMetaData('robots', $metaRobots);
        }
    }

    /**
     * Check if page has filters/sorting/limit applied
     * @since  1.0.0
     */
    protected function isFilteredPage(): bool
    {
        $model = $this->getModel();

        // Has active filters?
        if (!empty($model->getActiveFilters())) {
            return true;
        }

        $defaults = $model->getDefaults();

        // Non-default sort?
        $currentSort = $this->state->get('list.ordering') . ' ' . $this->state->get('list.direction');
        $defaultSort = $defaults['list']['fullordering'] ?? 'a.id ASC';
        if (trim($currentSort) !== trim($defaultSort)) {
            return true;
        }

        // Non-default limit?
        $currentLimit = (int) $this->state->get('list.limit');
        $defaultLimit = (int) ($defaults['list']['limit'] ?? 25);
        if ($currentLimit !== $defaultLimit) {
            return true;
        }

        return false;
    }
}
