<?php

namespace Alfa\Module\AlfaFilters\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Layout\FileLayout;
use Joomla\Registry\Registry;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Input\Input;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;


defined('_JEXEC') or die;



class AlfaFiltersHelper
{
    private static $itemsModel = null;

	/**
	 * Get module parameters
	 * Loads from database on first call, returns cached on subsequent calls
	 *
	 * @return Registry
	 */
	private static array $paramsCache = [];

	private static function getParams(): Registry
	{
	    $moduleId = (int) ($_SERVER['HTTP_X_MODULE_ID'] ?? 0);

	    if (isset(self::$paramsCache[$moduleId])) {
	        return self::$paramsCache[$moduleId];
	    }

	    // we do it via HTTP_X_MODULE_ID and Database query cause com_ajax fetch calling
	    // directly this helper file and does not have populated any other module specific data
	    if ($moduleId > 0) {
	        $db = Factory::getContainer()->get('DatabaseDriver');
	        $query = $db->getQuery(true)
	            ->select($db->quoteName('params'))
	            ->from($db->quoteName('#__modules'))
	            ->where($db->quoteName('id') . ' = ' . $moduleId);
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

    public function getFiltersAjax()
	{
		$app = Factory::getApplication();
		$app->getLanguage()->load('com_alfa', JPATH_SITE);
		$app->getLanguage()->load('mod_alfa_filters', JPATH_SITE);

		$model = self::getItemsModel();
		$params = self::getParams();

		$alfa_filters = self::getAvailableFilters($params);
		$alfa_active_filters = self::getActiveFilters();
		$priceRange = self::getMinMaxPrice();
		$priceHistogram = $model->getAvailablePriceHistogram();
		$histogramOptions = $params->get('histogram_options', 'DEN');

		// Read from header — consistent with X-Dynamic-Filtering
    	$moduleId = (int) ($_SERVER['HTTP_X_MODULE_ID'] ?? 0);

		$layout = new FileLayout('default_form_content', JPATH_ROOT . '/modules/mod_alfa_filters/tmpl');

		// Read dynamic_filtering from custom header (avoids polluting the URL)
		// JS sends X-Dynamic-Filtering header with the value set by the Dispatcher on page load
		$dynamic = (bool) ((int) ($_SERVER['HTTP_X_DYNAMIC_FILTERING'] ?? 0));

		$layoutData = [
			'alfa_filters'        => $alfa_filters,
			'alfa_active_filters' => $alfa_active_filters,
			'moduleId'            => $moduleId,
			'minimum_price'       => $priceRange['min'],
			'maximum_price'       => $priceRange['max'],
			'getPriceHistogram'   => $priceHistogram,
			'form_fields'         => self::getFormFields($moduleId),
			'dynamic_filtering'   => $dynamic,
			'histogram_options'   => $histogramOptions,
		];

		$html = $layout->render($layoutData);

		$data = [
			'html'           => $html,
			'priceRange'     => $priceRange,
			'priceHistogram' => $priceHistogram,
			'debug_params'   => $params->toArray(),
		];

		$response = new JsonResponse($data, 'DONE', false);
		echo $response;

		Factory::getApplication()->close();
	}

	public static function getAvailableFilters(Registry $params): array
	{
		$model = self::getItemsModel();

		$subcategory_depth = (int) $params->get('subcatecory_depth', 2);
		$productCount = (bool) $params->get('product_count', 1);

		$allCategories    = CategoryHelper::getCategoryTree(
			maxDepth: $subcategory_depth,
			includeCount: $productCount,
		);

		$allManufacturers = $model->getAvailableManufacturers();

		return [
			'category'     => $allCategories,
			'manufacturer' => $allManufacturers,
		];
	}
	

    public static function getActiveFilters(): array
    {
        $model = self::getItemsModel();

        return $model->getActiveFilters();

    }


    private static function getItemsModel()
    {
        if (self::$itemsModel === null) {
            $component = Factory::getApplication()->bootComponent('com_alfa');
            $mvcFactory = $component->getMVCFactory();

            self::$itemsModel = $mvcFactory->createModel(
                'Items',
                'Site',
                ['ignore_request' => false]
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
	 *
	 * @return array
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
		$orderingHtml           = $filterForm->getField('fullordering', 'list')->input;
		$limitHtml              = $filterForm->getField('limit', 'list')->input;
		$onSaleHtml             = $filterForm->getField('on_sale', 'filter')->input;
		$discountAmountMinHtml  = $filterForm->getField('discount_amount_min', 'filter')->input;
		$discountPercentMinHtml = $filterForm->getField('discount_percent_min', 'filter')->input;

		return [
			'ordering'             => $orderingHtml,
			'limit'                => $limitHtml,
			'on_sale'              => $onSaleHtml,
			'discount_amount_min'  => $discountAmountMinHtml,
			'discount_percent_min' => $discountPercentMinHtml,
		];
	}

	private static ?array $priceRange = null;
	
	public static function getMinMaxPrice(): array
	{
	    if (self::$priceRange === null) {
	        $model = self::getItemsModel();
	        $priceRange = $model->getAvailablePriceRange();

	        self::$priceRange = [
	            'min' => $priceRange['min'] !== null ? floor($priceRange['min']) : null,
	            'max' => $priceRange['max'] !== null ? ceil($priceRange['max'])  : null,
	        ];
	    }

	    return self::$priceRange;
	}


	public static function getPriceHistogram(): array
	{
	    $model = self::getItemsModel();
	    $range = self::getMinMaxPrice();

	    return $model->getAvailablePriceHistogram(20, $range['min'], $range['max']);
	}

}
