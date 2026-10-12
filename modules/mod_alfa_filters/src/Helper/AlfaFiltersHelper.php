<?php

namespace Alfa\Module\AlfaFilters\Site\Helper;

use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Alfa\Module\AlfaFilters\Site\Section\FilterSections;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

class AlfaFiltersHelper
{
    private static $itemsModel = null;
    private static array $paramsCache = [];

    /**
     * Get module parameters
     * Loads from database on first call, returns cached on subsequent calls
     */
    private static function getParams(): Registry
    {
        $moduleId = (int) ($_SERVER['HTTP_X_MODULE_ID'] ?? 0);

        if (isset(self::$paramsCache[$moduleId])) {
            return self::$paramsCache[$moduleId];
        }

        // com_ajax calls this helper directly, so no module context is populated;
        // the instance id arrives in the X-Module-Id header. Scope the lookup to a
        // published site instance of this module so an arbitrary id cannot read
        // another module's params.
        if ($moduleId > 0) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__modules'))
                ->where($db->quoteName('id') . ' = ' . $moduleId)
                ->where($db->quoteName('module') . ' = ' . $db->quote('mod_alfa_filters'))
                ->where($db->quoteName('published') . ' = 1')
                ->where($db->quoteName('client_id') . ' = 0');
            $db->setQuery($query);
            $paramsJson = $db->loadResult();

            if ($paramsJson) {
                return self::$paramsCache[$moduleId] = new Registry($paramsJson);
            }
        }

        // Fallback to first matching module
        $module = ModuleHelper::getModule('mod_alfa_filters');
        return self::$paramsCache[0] = new Registry($module->params);
    }

    /**
     * AJAX endpoint: render the filter form content (available/active filters, price range,
     * form fields) and return it as JSON. Reads the module id and dynamic-filtering flag from request
     * headers, then closes the application.
     *
     * @return void
     */
    public function getFiltersAjax()
    {
        $app = Factory::getApplication();
        $app->getLanguage()->load('com_alfa', JPATH_SITE);
        $app->getLanguage()->load('mod_alfa_filters', JPATH_SITE);

        $params = self::getParams();

        $alfa_filters = self::getAvailableFilters($params);
        $alfa_active_filters = self::getActiveFilters();
        $priceRange = self::getMinMaxPrice();

        // Read from header, consistent with X-Dynamic-Filtering
        $moduleId = (int) ($_SERVER['HTTP_X_MODULE_ID'] ?? 0);

        $layout = new FileLayout('default_form_content', JPATH_ROOT . '/modules/mod_alfa_filters/tmpl');

        // Read dynamic_filtering from custom header (avoids polluting the URL)
        // JS sends X-Dynamic-Filtering header with the value set by the Dispatcher on page load
        $dynamic = (bool) ((int) ($_SERVER['HTTP_X_DYNAMIC_FILTERING'] ?? 0));

        $layoutData = [
            'alfa_filters' => $alfa_filters,
            'alfa_active_filters' => $alfa_active_filters,
            'moduleId' => $moduleId,
            'minimum_price' => $priceRange['min'],
            'maximum_price' => $priceRange['max'],
            'form_fields' => self::getFormFields($moduleId),
            'dynamic_filtering' => $dynamic,
            'sectionOrder' => self::getSectionOrder($params, $alfa_filters),
        ];

        $html = $layout->render($layoutData);

        $data = [
            'html' => $html,
            'priceRange' => $priceRange,
        ];

        $response = new JsonResponse($data, 'DONE', false);
        echo $response;

        Factory::getApplication()->close();
    }

    /**
     * Build the available filter options: the category tree (depth/product-count from params) and the
     * manufacturers available under the current item filters.
     *
     * @param Registry $params The module parameters.
     *
     * @return array ['category' => category tree, 'manufacturer' => available manufacturers].
     */
    public static function getAvailableFilters(Registry $params): array
    {
        $model = self::getItemsModel();

        $subcategory_depth = (int) $params->get('subcatecory_depth', 2);
        $productCount = (bool) $params->get('product_count', 1);

        // category_filter_subtree: when on AND we're browsing inside a category,
        // scope the category facet to THAT category's subtree (its descendants),
        // not the whole tree (a drill-down aid). Off (or at the catalog root) it
        // builds from the root as before.
        $treeRoot = 0;

        if ((int) ComponentHelper::getParams('com_alfa')->get('category_filter_subtree', 0) === 1) {
            $currentCategoryId = (int) self::getItemsModel()->getState('filter.category_id');

            if ($currentCategoryId > 0) {
                $treeRoot = $currentCategoryId;
            }
        }

        $allCategories = CategoryHelper::getCategoryTree(
            parentId: $treeRoot,
            maxDepth: $subcategory_depth,
            includeCount: $productCount,
        );

        $allManufacturers = $model->getAvailableManufacturers();

        // Item-field value facets (Size, Color, ...), grouped per filterable field.
        $itemFields = $model->getAvailableItemFieldValues();

        // WHETHER A FIELD GROUPS IS THE FIELD'S OWN DECISION, made once in its type
        // settings. There is deliberately no module-level override: a blanket switch here
        // would stomp every field's setting at once (turning grouping on for fields a
        // merchant had explicitly left ungrouped), which is worse than having no override
        // at all. If per-module variation is ever needed it belongs per FIELD, not blanket.
        //
        // The group COUNTS are the one extra query the feature costs, so they are fetched
        // only for the fields that actually group. A catalogue with grouping off nowhere
        // queries exactly what it did before the feature existed.
        foreach ($itemFields as $fieldId => $facet) {
            $itemFields[$fieldId]['group_active'] = (bool) ($facet['group_default'] ?? false);

            if (empty($itemFields[$fieldId]['group_active']) || empty($facet['groups']['groups'])) {
                continue;
            }

            $counts = $model->getFacetGroupCounts((int) $fieldId);

            foreach ($itemFields[$fieldId]['groups']['groups'] as $token => $group) {
                $itemFields[$fieldId]['groups']['groups'][$token]['count'] = (int) ($counts[$token] ?? 0);
            }
        }

        return [
            'category' => $allCategories,
            'manufacturer' => $allManufacturers,
            'fields' => $itemFields,
            // Availability: how many sellable / not, under the other active filters.
            // Two counts, so only when the merchant shows counts at all.
            'stock' => $productCount ? $model->getAvailableStockCounts() : [],
        ];
    }

    /**
     * The sections this module renders, in the order it renders them.
     *
     * ONE resolution for both render paths (the dispatcher on a page load and the com_ajax
     * refresh) because a panel that reordered itself the moment a shopper ticked a box
     * would be a bug nobody could reproduce from the configuration alone.
     *
     * The natural order comes from the item fields themselves: the facets arrive already
     * sorted by their backend `ordering`, and that is the sequence the panel follows unless
     * this module was explicitly rearranged. {@see FilterSections::resolve()} for how an
     * override is reconciled rather than obeyed.
     *
     * @param Registry $params The module parameters.
     * @param array $filters The available filters, as getAvailableFilters() built them.
     *
     * @return string[] Section keys in render order.
     */
    public static function getSectionOrder(Registry $params, array $filters): array
    {
        $fieldIds = array_map('intval', array_keys((array) ($filters['fields'] ?? [])));

        return FilterSections::resolve(
            FilterSections::natural($fieldIds),
            $params->get('filter_sections', ''),
        );
    }

    /**
     * Get the currently active filters (those set in the request/state) from the items model.
     *
     * @return array The active filters.
     */
    public static function getActiveFilters(): array
    {
        $model = self::getItemsModel();

        return $model->getActiveFilters();
    }

    /**
     * Get the shared com_alfa Items model instance, instantiating and caching it on first call.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel The Items model.
     */
    private static function getItemsModel()
    {
        if (self::$itemsModel === null) {
            $component = Factory::getApplication()->bootComponent('com_alfa');
            $mvcFactory = $component->getMVCFactory();

            self::$itemsModel = $mvcFactory->createModel(
                'Items',
                'Site',
                ['ignore_request' => false],
            );
        }

        return self::$itemsModel;
    }

    /**
     * Get rendered HTML for list and filter fields
     * Strips onchange attribute to prevent page reload
     * since the module uses AJAX filtering instead
     *
     * @param int $moduleId - The module's id
     */
    public static function getFormFields(int $moduleId): array
    {
        $model = self::getItemsModel();
        $filterForm = $model->getFilterForm();

        // 1. Remove onchange to prevent page reload
        $filterForm->setFieldAttribute('fullordering', 'onchange', '', 'list');
        $filterForm->setFieldAttribute('limit', 'onchange', '', 'list');
        $filterForm->setFieldAttribute('on_sale', 'onchange', '', 'filter');

        // 2. Set unique IDs using the module ID
        $filterForm->setFieldAttribute('fullordering', 'id', 'alfa_filters_ordering_' . $moduleId, 'list');
        $filterForm->setFieldAttribute('limit', 'id', 'alfa_filters_limit_' . $moduleId, 'list');
        $filterForm->setFieldAttribute('on_sale', 'id', 'alfa_filters_on_sale_' . $moduleId, 'filter');
        $filterForm->setFieldAttribute('discount_amount_min', 'id', 'alfa_filters_discount_amount_min_' . $moduleId, 'filter');
        $filterForm->setFieldAttribute('discount_percent_min', 'id', 'alfa_filters_discount_percent_min_' . $moduleId, 'filter');

        // 3. Render the fields
        $orderingHtml = $filterForm->getField('fullordering', 'list')->input;
        $limitHtml = $filterForm->getField('limit', 'list')->input;
        $onSaleHtml = $filterForm->getField('on_sale', 'filter')->input;
        $discountAmountMinHtml = $filterForm->getField('discount_amount_min', 'filter')->input;
        $discountPercentMinHtml = $filterForm->getField('discount_percent_min', 'filter')->input;

        return [
            'ordering' => $orderingHtml,
            'limit' => $limitHtml,
            'on_sale' => $onSaleHtml,
            'discount_amount_min' => $discountAmountMinHtml,
            'discount_percent_min' => $discountPercentMinHtml,
        ];
    }

    private static ?array $priceRange = null;

    /**
     * Get the available price range (floored min / ceiled max) for the current filters, cached per request.
     *
     * @return array ['min' => float|null, 'max' => float|null].
     */
    public static function getMinMaxPrice(): array
    {
        if (self::$priceRange === null) {
            $model = self::getItemsModel();
            $priceRange = $model->getAvailablePriceRange();

            self::$priceRange = [
                'min' => $priceRange['min'] !== null ? floor($priceRange['min']) : null,
                'max' => $priceRange['max'] !== null ? ceil($priceRange['max']) : null,
            ];
        }

        return self::$priceRange;
    }
}
