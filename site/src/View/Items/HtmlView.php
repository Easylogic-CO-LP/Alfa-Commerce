<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\View\Items;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Alfa\Component\Alfa\Site\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * View class for a list of items
 *
 * @since  1.0.1
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

    /**
     * Constructor
     *
     * @param array $config Configuration settings
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
     */
    public function display($tpl = null)
    {
        $this->params = $this->app->getParams('com_alfa');

        $itemsModel = $this->getModel();

        // Get items data
        $this->items = $itemsModel->getItems();
        $this->pagination = $itemsModel->getPagination();
        $this->state = $itemsModel->getState();
        $this->filterForm = $itemsModel->getFilterForm();
        $this->activeFilters = $itemsModel->getActiveFilters();

        $this->availableManufacturers = $itemsModel->getAvailableManufacturers();

        // Get category-related data
        $this->categories = $itemsModel->getItemsCategories();
        $this->category = $itemsModel->getItemsCategory();

        // Resolve price settings once for all items
        $this->priceSettings = PriceSettings::get();

        $this->_prepareDocument();

        parent::display($tpl);
    }

    /**
     * Prepares the document
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

        $this->getDocument()->addHeadLink($canonical, 'canonical');
    }

    /**
     * Add robots meta tag
     *
     * Filtered/sorted pages get noindex (priority)
     * Otherwise uses category or menu setting
     */
    protected function addRobotsMeta(): void
    {
        $document = $this->getDocument();

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
