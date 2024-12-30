<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Exception;
use \Joomla\CMS\Application\SiteApplication;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Multilanguage;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\MVC\Controller\BaseController;
use \Joomla\CMS\Router\Route;
use \Joomla\CMS\Uri\Uri;
use \Joomla\CMS\User\UserFactoryInterface;
use \Joomla\Utilities\ArrayHelper;
use \Joomla\CMS\Response\JsonResponse;
use \Joomla\CMS\Layout\LayoutHelper;
use \Joomla\CMS\Layout\FileLayout;

use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use \Alfa\Component\Alfa\Site\Helper\CartHelper;
use \Alfa\Component\Alfa\Site\Helper\OrderPlaceHelper;


/**
 * Item class.
 *
 * @since  1.6.0
 */
class CartController extends BaseController
{


    /**
     * Method to display a view.
     *
     * @param   boolean  $cachable   If true, the view output will be cached.
     * @param   boolean  $urlparams  An array of safe URL parameters and their variable types, for valid values see {@link InputFilter::clean()}.
     *
     * @return  \Joomla\CMS\MVC\Controller\BaseController  This object to support chaining.
     *
     * @since   1.0.0
     */
    // public function display($cachable = false, $urlparams = false)
    // {

    //     $view = $this->input->getCmd('view', 'cart');
    //     // $view = $view == "featured" ? 'coupons' : $view;
    //     $this->input->set('view', $view);
    //     $this->input->set('layout', $this->input->get('layout','default'));

    //     parent::display($cachable, $urlparams);
    //     return $this;
    // }

//     $app = Factory::getApplication();


//         $limit        = $input->getInt('limit', 6);
    // $limitstart   = $input->getInt('limitstart', 0);
    // $keyword = $input->getString('query', '');
    public function recalculate()
    {

        $errorOccured = false;

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);
        $userGroupId = 1;
        $currencyId = 1;


        // TODO: somewhow cache the item here or inside the model but the quantity changes so we should take this in count
        // get all the item data
        $model = $this->getModel('Item');
        
        // Set state in the model
        $model->setState('quantity', $quantity);
        $item = $model->getItem($itemId);

        $categorySettings = AlfaHelper::getCategorySettings();
        // Calculate price
        // $calculator = new PriceCalculator($itemId, $quantity, $userGroupId, $currencyId);
        // $price = $calculator->calculatePrice();

        $priceLayout = LayoutHelper::render('price', 
                                                    [
                                                        'item'=>$item,
                                                        'settings'=>$categorySettings,
                                                    ]
                                                );


        $stockAvailabilityLayout = LayoutHelper::render('stock_info', 
                                                            [
                                                                'item'=>$item,
                                                                'quantity'=>$quantity,
                                                            ]
                                                        );

        $responseData = [
                            'price_layout'=>$priceLayout,
                            'stock_info_layout'=>$stockAvailabilityLayout,
                        ];

        $response = new JsonResponse($responseData, 'Recalculate runned successfully', $errorOccured);

        echo $response;
        $this->app->close();
    }


    public function addToCart()
    {
        $errorOccured = false;

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);

        $userId = Factory::getApplication()->getIdentity()->id;
        $response_data = [];

        $cart = new CartHelper();

        // try{
        $errorOccured = !$cart->addToCart($itemId, $quantity);
        // } catch (Exception $e) {
        //     $this->app->enqueueMessage($e->getMessage(),'error');
        //     $errorOccured = true;
        // }

        $response = new JsonResponse($response_data, $errorOccured ? 'Item failed to be added' : 'Item added successfully', $errorOccured);

        echo $response;
        $this->app->close();
    }


    public function clearCart()
    {
         $errorOccured = false;

         $input = $this->app->input;

         $quantity = $input->getInt('quantity', 1);
         $itemId = $input->getInt('item_id', 0);
         $userId = Factory::getApplication()->getIdentity()->id;
         $response_data = [];

        $cart = new CartHelper();
        $clearOnlyItems = true;
        $errorOccured = !$cart->clearCart($clearOnlyItems);

        $layout = new FileLayout('default_cart_empty', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
        $result = $layout->render();

         $data = array(
             'data' => $result,
         );

        // json output
        header('Content-Type: application/json');
        $response = new JsonResponse($result, $errorOccured ? 'Cart Failed to be cleared!' : 'Cart cleared successfully!', $errorOccured);
        echo $response;

        $this->app->close();
    }

    public function updateQuantity()
    {
        $errorOccured = false;

        $input = $this->app->input;

        $itemId = $input->getInt('id_item', 0);
        $quantity = $input->getInt('quantity', 1);
        $userId = Factory::getApplication()->getIdentity()->id;

        $response_data = [];

        $cart = new CartHelper();
        
        $errorOccured = !$cart->updateQuantity($quantity,$itemId);

        $result = '';

        $isEmpty = $cart->isEmpty();

        if($isEmpty){
            $layout = new FileLayout('default_cart_empty', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render();
        }else{
            $layout = new FileLayout('default_cart_items', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render($cart);//shown in layout as $displayData
        }


        $response_data = array(
            'tmpl' => $result,
            'isEmpty' => $isEmpty
        );

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccured ? 'Item Failed to be updated!' : 'Item successfully updated!', $errorOccured);
        echo $response;

        $this->app->close();
    }

    public function placeOrder() {

        $errorOccured = false;

        // $input = $this->app->input;

        // $userId = $this->app->getIdentity()->id;

        $order = new OrderPlaceHelper();

        $errorOccured = !$order->placeOrder();

        // Get the view and layout
        // $view = $this->getView('cart', 'html');

        if(!$errorOccured){
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart&layout=default_order_completed'));
            // $this->app->redirect(Route::_('index.php?option=com_alfa&view=cart&layout=default_order_completed'));
        }else{
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart'));
        }

        // $this->app->close();

        return true;
    }
}
