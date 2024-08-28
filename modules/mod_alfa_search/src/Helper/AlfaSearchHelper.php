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
        
        $app = Factory::getApplication();

         $input        = $app->input;
        // $limit        = $input->getInt('limit', 6);
        // $limitstart   = $input->getInt('limitstart', 0);
         $keyword      = $input->getString('query', '');
        // $params       = new JRegistry($module->params);
        // $products     = self::getItemsFromComponent();
        // $currency     = CurrencyDisplay::getInstance();
        // $showCategory = $params->get('show_category', 1);
        // $showPrice    = $params->get('show_price', 1);
        // $priceType    = $params->get('displayed_price_type', 'salesPrice');
        // $results      = array();
        // $count        = 0;
        // $end          = true;

        $app->getSession()->set('application.queue', $app->getMessageQueue());

        $component = $app->bootComponent('com_alfa');
        $model = $component->getMVCFactory()->createModel('Items', 'Site', ['ignore_request' => true]);

//        if (!$model) {
//            return $this->options;
//        }

        $model->setState('filter.state', '1');

        $model->getState('list.ordering');//we should use get before set the list state fields

        $model->setState('filter.search', $keyword);


//        $model->setState('list.ordering', $orderBy);
//        $model->setState('list.ordering', $orderBy);
//        $model->setState('list.direction', $orderDir);

        $products = $model->getItems();

        // if(!empty($products))
        // {
        //     $count = count($products);
            
        //     if($count >= $limit)
        //     {
        //         $end = false;
        //     }

        // get tha filelayout we use for our result of each product
        $layout = new FileLayout('result', JPATH_ROOT . '/modules/mod_alfa_search/tmpl');

        foreach($products as $product){
        //     // ob_start(); //Original way
        //     // require ModuleHelper::getLayoutPath('mod_alfa_search', 'result');
        //     // $results[] = ob_get_clean();

        //     // Joomla way
        //     // Prepare data for each product and render the layout
            $layoutData = ['product' => $product];//we can use it as $displayData inside layout file
            $results[] = $layout->render($layoutData);
        }
        
        $data = array(
            'query' => $keyword,
            'suggestions' => $results,
            'limitstart' => $limitstart,
            'limit' => $limit,
            'count' => $count,
            'end' => $end
        );
        
        
        // json output
        header('Content-Type: application/json');
        $response = new JsonResponse($data,'Items fetched succesufully',false);
        echo $response;

        $app->close();
    }



}
