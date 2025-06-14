<?php

namespace Alfa\Module\AlfaSearch\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Component\Content\Administrator\Extension\ContentComponent;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Layout\FileLayout;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Helper for mod_articles_popular
 *
 * @since  4.3.0
 */
class AlfaSearchHelper
{
    public static function getItemsFromDatabase()
    {
        $items = self::getItemsFromComponent();
        return $items;
    }

    protected static function getItemsFromComponent()
    {
        // $app = Factory::getApplication();
        // $component = $app->bootComponent('com_alfa');
        // $model = $component->getMVCFactory()->createModel('Items', 'Site', ['ignore_request' => true]);
        // $items = $model->getItems();

        // return $items;
    }


    public static function getAjax()
    {

        if (!$module = ModuleHelper::getModule('mod_alfa_search')) {
            return;
        }


        $app = Factory::getApplication();

        $input = $app->input;
        $lang  = $app->getLanguage();
        $lang->load('mod_alfa_search');
        
//         $limit        = $input->getInt('limit', 6);
        // $limitstart   = $input->getInt('limitstart', 0);
        $keyword = $input->getString('query', '');
        $params = new Registry($module->params);
        $products = $categories = $manufacturers = [];

        // $showCategory = $params->get('show_category', 1);
        // $showPrice    = $params->get('show_price', 1);
        // $priceType    = $params->get('displayed_price_type', 'salesPrice');
        // $results      = array();
        // $count        = 0;
        // $end          = true;

        $app->getSession()->set('application.queue', $app->getMessageQueue());

        $component = $app->bootComponent('com_alfa');
        $mvcFactory = $component->getMVCFactory();

        // Get items from alfa component
        $itemsModel = $mvcFactory->createModel('Items', 'Site', ['ignore_request' => true]);
        $itemsModel->getState('list.ordering');//we should use get before set the list state fields
        $itemsModel->setState('filter.state', '1');
        $itemsModel->setState('filter.search', $keyword);
//      $itemsModel->setState('list.ordering', $orderBy);
//      $itemsModel->setState('list.direction', $orderDir);
        $products = $itemsModel->getItems();

        // Get categories from alfa component
        $showCategories = $params->get('show_categories', 0);
        if ($showCategories) {
            $categoriesModel = $mvcFactory->createModel('Categories', 'Site', ['ignore_request' => true]);
            $categoriesModel->getState('list.ordering');//we should use get before set the list state fields
            $categoriesModel->setState('filter.state', '1');
            $categoriesModel->setState('filter.search', $keyword);
//            $categoriesModel->setState('list.limit', '0'); //TODO: take limit from form field. Mans too

//      $itemsModel->setState('list.ordering', $orderBy);
//      $itemsModel->setState('list.direction', $orderDir);
            $categories = $categoriesModel->getItems();
        }

        // Get manufacturers from alfa component
        $showManufacturers = $params->get('show_manufacturers', 0);
        if ($showManufacturers) {
            $manufacturersModel = $mvcFactory->createModel('Manufacturers', 'Site', ['ignore_request' => true]);
            $manufacturersModel->getState('list.ordering');//we should use get before set the list state fields
            $manufacturersModel->setState('filter.state', '1');
            $manufacturersModel->setState('filter.search', $keyword);
//      $itemsModel->setState('list.ordering', $orderBy);
//      $itemsModel->setState('list.direction', $orderDir);
            $manufacturers = $manufacturersModel->getItems();
        }
//        if (!empty($products)) {
//            $count = count($products);
//
//             if($count >= $limit)
//             {
//                 $end = false;
//             }

        // get tha filelayout we use for our result of each product
        $layout = new FileLayout('result', JPATH_ROOT . '/modules/mod_alfa_search/tmpl');

        // mallon tha prepei na kanoume for each mesa sto template giati exoume kai kataskeuastes kai anazitisi stis katigories

        // alliws kanoume alles duo loupes kai anti gia results bazoume products[]

        // kai antistoixa manufacturers kai categories wste na to steiloume sto data

        // foreach ($products as $product) {
        //     // ob_start(); //Original way
        //     // require ModuleHelper::getLayoutPath('mod_alfa_search', 'result');
        //     // $results[] = ob_get_clean();

        //     // Joomla way
        //     // Prepare data for each product and render the layout
        $layoutData = [
            'params' => $params,
            'products' => $products,
            'categories' => $categories,
            'manufacturers' => $manufacturers,
        ];//we can use it as $displayData inside layout file
        $results[] = $layout->render($layoutData);
        // }


        $data = array(
            'query' => $keyword,
            'suggestions' => $results,
            // 'limitstart' => $limitstart,
            // 'limit' => $limit,
            // 'count' => $count,
            // 'end' => $end
        );


        // json output
        header('Content-Type: application/json');
        $response = new JsonResponse($data, 'Items fetched succesufully', false);
        echo $response;

        $app->close();
    }


}
