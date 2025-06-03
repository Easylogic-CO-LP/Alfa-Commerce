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
use Joomla\CMS\MVC\Controller\FormController;
use \Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use \Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryAwareInterface;
use Joomla\CMS\User\UserFactoryAwareTrait;
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
class CartController extends FormController implements UserFactoryAwareInterface
{

	use UserFactoryAwareTrait;

	/**
	 * Method to get a model object, loading it if required.
	 *
	 * @param   string  $name    The model name. Optional.
	 * @param   string  $prefix  The class prefix. Optional.
	 * @param   array   $config  Configuration array for model. Optional.
	 *
	 * @return  \Joomla\CMS\MVC\Model\BaseDatabaseModel  The model.
	 *
	 * @since   1.6.4
	 */
	public function getModel($name = 'cart', $prefix = '', $config = ['ignore_request' => true])
	{
		return parent::getModel($name, $prefix, ['ignore_request' => false]);
	}

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

        $failMessage = 'Recalculate failed';
        $successMessage = 'Recalculate runned successfully';

        self::verifyTokenAndRespondJson('post', $failMessage);

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

        $response = new JsonResponse($responseData, $errorOccured ? $failMessage : $successMessage, $errorOccured);

        echo $response;
        $this->app->close();
    }


    public function addToCart()
    {

        $errorOccured = true;
        $response_data = [];
        $failMessage = 'Item failed to be added';
        $successMessage = 'Item added successfully';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);

        $userId = Factory::getApplication()->getIdentity()->id;
        

        $cart = new CartHelper();

        // try{
        $errorOccured = !$cart->addToCart($itemId, $quantity);
        // } catch (Exception $e) {
        //     $this->app->enqueueMessage($e->getMessage(),'error');
        //     $errorOccured = true;
        // }

        $response = new JsonResponse($response_data, $errorOccured ? $failMessage : $successMessage, $errorOccured);

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
        $input = $this->app->input;

        $failMessage = 'Item Failed to be updated!';
        $successMessage = 'Item successfully updated!';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $itemId = $input->getInt('id_item', 0);
        $quantity = $input->getInt('quantity', 1);
        $userId = Factory::getApplication()->getIdentity()->id;

        $response_data = [];
        $errorOccured = false;

        $cart = new CartHelper();
        
        // $cart->getData()->id_shipment = $this->app->input->getInt('shipment_id');
        $errorOccured = !$cart->addToCart($itemId,$quantity);
        
        $result = '';

        if(!$errorOccured)
            $response_data = $this->getItemsLayout($cart);

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccured ? $failMessage : $successMessage , $errorOccured);
        echo $response;
        $this->app->close();
    }

    public function placeOrder() {



		$isValid = $this->checkToken();//'post',false  this functions uses the Session:checkToken inside

		$app = $this->app;
	    $model  = $this->getModel('cart');
	    $data = $this->input->post->get('cartform', [], 'array');
        // echo '<pre>';
	    // print_r($data['com_alfa']);
        // echo '</pre>';
		// exit;
		$validateSessionCookie = true; // if ($contact->params->get('validate_session', 0)) {
	    if($validateSessionCookie){
		    if ($app->getSession()->getState() !== 'active') {
			    $this->app->enqueueMessage(Text::_('JLIB_ENVIRONMENT_SESSION_INVALID'), 'warning');

			    // Save the data in the session.
//			    $this->app->setUserState('com_alfa.cart.data', $data);

			    // Redirect back to the contact form.
			    $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

			    return false;
		    }
	    }

	    $form = $model->getForm();

	    if (!$form) {
		    throw new \Exception($model->getError(), 500);
	    }

	    if (!$model->validate($form, $data)) {
		    $errors = $model->getErrors();

		    foreach ($errors as $error) {
			    $errorMessage = $error;

			    if ($error instanceof \Exception) {
				    $errorMessage = $error->getMessage();
			    }

			    $app->enqueueMessage($errorMessage, 'error');
		    }
//		    $data = $this->input->post->get('cartform', [], 'array');
////		    $app->setUserState('com_dianemo.cart.data', $data);

		    $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

		    return false;
	    }

        // $userId = $this->app->getIdentity()->id;

        $order = new OrderPlaceHelper();

	    $placeOrderError = !$order->placeOrder($data['com_alfa']);

        $orderId = $order->getOrder()->id;

		$this->app->setUserState('com_alfa.order_id', $orderId);

        if(!$placeOrderError){
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart&layout=default_order_process'));
        }else{
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart'));
        }

        return true;
    }


    // Updates the cart's shipment id, re-renders the items layout, and returns it as an answer.
    public function updateShipment(){
        $input = $this->app->input;

        $failMessage = 'Shipment could not be updated.';
        $successMessage = 'Shipment updated successfully!';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $shipmentID = $input->getInt('id_shipment', 0);
        $response_data = [];
        $errorOccured = false;

        $cart = new CartHelper();
        $errorOccurred = !$cart->updateShipment($shipmentID);
        $errorMessage = $errorOccurred ? "Shipment ID could not be updated." : "";

        if(!$errorOccured){
            $response_data['isEmpty'] = $cart->isEmpty();
            $response_data['items'] = $this->getItemsLayout($cart);
            $response_data['payments'] = $this->getPaymentsLayout($cart);
            $response_data['shipments'] = $this->getShipmentsLayout($cart);
        }

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccured ? $failMessage : $successMessage ,$errorOccured);
        echo $response;
        $this->app->close();
    }
    
    public function updatePayment(){
        $input = $this->app->input;

        $failMessage = 'Payment could not be updated.';
        $successMessage = 'Payment updated successfully!';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $paymentID = $input->getInt('id_payment', 0);
        $response_data = [];
        $errorOccured = false;

        $cart = new CartHelper();
        $errorOccurred = !$cart->updatePayment($paymentID);

        if(!$errorOccured){
            $response_data['isEmpty'] = $cart->isEmpty();
            $response_data['items'] = $this->getItemsLayout($cart);
            $response_data['payments'] = $this->getPaymentsLayout($cart);
            $response_data['shipments'] = $this->getShipmentsLayout($cart);
        }

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccured ? $failMessage : $successMessage ,$errorOccured);
        echo $response;
        $this->app->close();
    }

    protected function getItemsLayout($cart){
        // TODO : support template ovverides
        $layoutData = [];
        $isEmpty = $cart->isEmpty();

        if($isEmpty){
            $layout = new FileLayout('default_cart_empty', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render();
        }else{
            $layout = new FileLayout('default_cart_items', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render($cart);//shown in layout as $displayData
        }

        $layoutData = array(
            'tmpl' => $result,
            'isEmpty' => $isEmpty
        );

        return $layoutData;
    }

    protected function getPaymentsLayout($cart){

        $layoutData = [];
        $isEmpty = $cart->isEmpty();

        if($isEmpty){
            $tmpl="";
        }else{
            
            $cart->addEventsToPayments();// Load shipment methods.
            $layout = new FileLayout('default_select_payment', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render($cart);//shown in layout as $displayData
        }

        $layoutData = array(
            'tmpl' => $result,
            'isEmpty' => $isEmpty
        );

        return $layoutData;
    }

    protected function getShipmentsLayout($cart){

        $layoutData = [];
        $isEmpty = $cart->isEmpty();

        if($isEmpty){
            $tmpl="";
        }else{
            
            $cart->addEventsToShipments();// Load shipment methods.
            $layout = new FileLayout('default_select_shipment', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render($cart);//shown in layout as $displayData
        }

        $layoutData = array(
            'tmpl' => $result,
            'isEmpty' => $isEmpty
        );

        return $layoutData;
    }



//    public function updateUserInfo(){
//
//        $input = $this->app->input;
//        $failMessage = 'Field failed to be updated';
//        $successMessage = 'Field updated successfully!';
//
//        self::verifyTokenAndRespondJson('post', $failMessage);
//
//
//	    $data = $this->input->post->get('cartform', [], 'array');
//	    $this->app->setUserState('com_dianemo.cart.data', $data);
//
//		print_r($data);
//		exit;
//
//        // TODO: Add CSRF token verification.
//
//        // $data = $this->input->get('data', json_decode($this->input->json->getRaw(), true), 'array');
//        // print_r($this->input);
//        // exit;
//        // print_r($this->input->json->getArray());
//        // print_r($this->input->json->getRaw());
//
//        $data = $this->input->get('data', json_decode($this->input->json->getRaw(), true), 'array');
//
//        $column = $data['fieldName'];
//
//		// clear the column field to get only the database name of it
//		// e.g. cartform[com_alfa][first-name] turns to first-name
//	    if (preg_match('/\[([^\]]+)\]$/', $column, $matches)) {
//		    $fieldName = $matches[1]; // first-name
//		    $column = $fieldName;
//	    }
//
//        $value = $data['fieldValue'];
//
//        $cart = new CartHelper();
//
//        $updateCartResponse = $cart->updateUserData($column, $value);
//
//        $errorOccurred = !$updateCartResponse;
//
//        $response = new JsonResponse(null, $errorOccurred ? $failMessage : $successMessage , $errorOccurred);
//
//        echo $response;
//
//        $this->app->close();
//
//    }


    protected function verifyTokenAndRespondJson($method = 'post', $invalidMessage = ''){

        $isValid = $this->checkToken($method,false);

        if(!$isValid){
            $this->app->enqueueMessage(Text::_('JINVALID_TOKEN_NOTICE'),'warning');
            $response = new JsonResponse(null, $invalidMessage, true);
            echo $response;
            $this->app->close();
        }

    }




}
