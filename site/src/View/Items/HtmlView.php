<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\View\Items;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\Helper\SlotResolver;
use Alfa\Component\Alfa\Site\Service\Facet\NarrowingFilters;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

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
        if ($input->getCmd('layout') === 'item') {
            // The model reads the buyer's sel[]/opt[]/conf[] selection itself
            // (VariantHelper::requestSelection); the view stays thin.
            $itemsModel = $this->getModel();
            $this->item = $itemsModel->getItem($input->getInt('id', 0));

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
        if ($input->getCmd('tmpl', '') === '' && ($canonical = $itemsModel->getCanonicalMenuUrl())) {
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

        // THE ATTRIBUTION EDGE, read AFTER getItems() because that is what runs the search.
        // Null whenever this listing is not an attributable search (no keyword, a
        // non-recording intent, a free-tier engine, analytics off), and the template must
        // then declare nothing rather than a zero.
        $this->searchEventId = $itemsModel->getSearchEventId();

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

        // Resolve price settings once for all items
        $this->priceSettings = PriceSettings::get();

        $this->_prepareDocument();

        parent::display($tpl);
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
        $this->addBreadcrumbs();
        $this->addMetaTags();
        $this->addCanonicalUrl();
        $this->addRobotsMeta();
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
        $tmpl = $this->app->getInput()->getCmd('tmpl', '');

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
