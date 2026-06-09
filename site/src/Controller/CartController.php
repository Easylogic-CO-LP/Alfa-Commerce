<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use Alfa\Component\Alfa\Site\Helper\CartHelper;
use Alfa\Component\Alfa\Site\Helper\OrderPlaceHelper;
use Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\UserFactoryAwareInterface;
use Joomla\CMS\User\UserFactoryAwareTrait;

/**
 * Item class.
 *
 * @since  1.0.0
 */
class CartController extends FormController implements UserFactoryAwareInterface
{
    use UserFactoryAwareTrait;

    /**
     * Method to get a model object, loading it if required.
     *
     * @param string $name The model name. Optional.
     * @param string $prefix The class prefix. Optional.
     * @param array $config Configuration array for model. Optional.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel The model.
     *
     * @since  1.0.0
     */
    public function getModel($name = 'cart', $prefix = '', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, ['ignore_request' => false]);
    }

    /**
     * Method to display a view.
     *
     *
     * @return \Joomla\CMS\MVC\Controller\BaseController This object to support chaining.
     *
     * @since  1.0.0
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
        $errorOccurred = false;

        $input = $this->app->input;

        $failMessage = 'Recalculate failed';
        $successMessage = 'Recalculate runned successfully';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);
        $userGroupId = 1;
        $currencyId = 1;

        // TODO: cache the item here or in the model, accounting for the varying quantity.
        // Load the full item data.
        $model = $this->getModel('Item');

        // Set state in the model
        $model->setState('quantity', $quantity);
        $item = $model->getItem($itemId);

        //		$categorySettings = AlfaHelper::getCategorySettings();
        // Calculate price
        // $calculator = new PriceCalculator($itemId, $quantity, $userGroupId, $currencyId);
        // $price = $calculator->calculatePrice();

        $priceLayout = LayoutHelper::render(
            'price',
            [
                'item' => $item,
                'category' => [],
            ],
        );

        $stockAvailabilityLayout = LayoutHelper::render(
            'stock_info',
            [
                'item' => $item,
                'quantity' => $quantity,
            ],
        );

        $responseData = [
            'price_layout' => $priceLayout,
            'stock_info_layout' => $stockAvailabilityLayout,
        ];

        $response = new JsonResponse($responseData, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);

        echo $response;
        $this->app->close();
    }

    /**
     * AJAX task: add an item (with quantity) to the cart via CartHelper and return a JSON response.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function addToCart()
    {
        $errorOccurred = true;
        $response_data = [];
        $failMessage = 'Item failed to be added';
        $successMessage = 'Item added successfully';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);

        $userId = Factory::getApplication()->getIdentity()->id;

        $cart = new CartHelper();

        try {
            $errorOccurred = !$cart->addToCart($itemId, $quantity);
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            $errorOccurred = true;
        }

        $response = new JsonResponse($response_data, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);

        echo $response;
        $this->app->close();
    }

    /**
     * AJAX task: clear the cart items via CartHelper and return the rendered empty-cart layout as JSON.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function clearCart()
    {
        $errorOccurred = false;

        $failMessage = 'Cart Failed to be cleared!';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $input = $this->app->input;

        $quantity = $input->getInt('quantity', 1);
        $itemId = $input->getInt('item_id', 0);
        $userId = Factory::getApplication()->getIdentity()->id;
        $response_data = [];

        $cart = new CartHelper();
        $clearOnlyItems = true;
        $errorOccurred = !$cart->clearCart($clearOnlyItems);

        $layout = new FileLayout('default_cart_empty', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
        $result = $layout->render();

        $data = [
            'data' => $result,
        ];

        // json output
        header('Content-Type: application/json');
        $response = new JsonResponse($result, $errorOccurred ? $failMessage : 'Cart cleared successfully!', $errorOccurred);
        echo $response;

        $this->app->close();
    }

    /**
     * AJAX task: set an item's quantity in the cart and, on success, return the re-rendered items layout as JSON.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     * @return void
     *
     * @since  1.0.0
     */
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
        $errorOccurred = false;

        $cart = new CartHelper();

        // $cart->getData()->id_shipment = $this->app->input->getInt('shipment_id');
        $errorOccurred = !$cart->addToCart($itemId, $quantity);

        $result = '';

        if (!$errorOccurred) {
            $response_data = $this->getItemsLayout($cart);
        }

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);
        echo $response;
        $this->app->close();
    }

    /**
     * Validate and submit the checkout form, then place the order through OrderPlaceHelper.
     * Checks the CSRF token and session state, validates the cart form, and on success stores the
     * new order id in the session and redirects to the order-process page; otherwise redirects back
     * to the cart with enqueued error messages.
     *
     * @return bool True when the order is placed, false on validation/session/placement failure.
     *
     * @since  1.0.0
     */
    public function placeOrder()
    {
        $isValid = $this->checkToken();//'post',false  this functions uses the Session:checkToken inside

        $app = $this->app;
        $model = $this->getModel('cart');
        $data = $this->input->post->get('cartform', [], 'array');
        // echo '<pre>';
        // print_r($data['com_alfa']);
        // echo '</pre>';
        // exit;
        $validateSessionCookie = true; // if ($contact->params->get('validate_session', 0)) {
        if ($validateSessionCookie) {
            if ($app->getSession()->getState() !== 'active') {
                $this->app->enqueueMessage(Text::_('JLIB_ENVIRONMENT_SESSION_INVALID'), 'warning');

                // Save the data in the session.
                //			    $this->app->setUserState('com_alfa.cart.data', $data);

                // Redirect back to the cart form.
                $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

                return false;
            }
        }

        $form = $model->getForm();

        if (!$form) {
            throw new Exception($model->getError(), 500);
        }

        if (!$model->validate($form, $data)) {
            $errors = $model->getErrors();

            foreach ($errors as $error) {
                $errorMessage = $error;

                if ($error instanceof Exception) {
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
        try {
            $orderHelper = new OrderPlaceHelper();

            // placeOrder() returns true on success, false on failure.
            $orderPlacedSuccessfully = $orderHelper->placeOrder($data[FieldsHelper::FIELDS_KEY]);

            if ($orderPlacedSuccessfully) {
                $order = $orderHelper->getOrder();

                // Guard against a placed-but-unloadable order.
                if ($order && isset($order->id)) {
                    $orderId = $order->id;

                    // Store order id in the session for the success page.
                    $this->app->setUserState('com_alfa.order_id', $orderId);

                    // Clear cart-related session data.
                    $this->app->setUserState('com_alfa.cart.data', null);

                    // Success message
                    //					$this->app->enqueueMessage(
                    //						Text::sprintf('COM_ALFA_ORDER_PLACED_SUCCESSFULLY', $orderId),
                    //						'success'
                    //					);

                    // Redirect to success/processing page
                    $this->setRedirect(
                        Route::_('index.php?option=com_alfa&view=cart&layout=default_order_process', false),
                    );

                    return true;
                } else {
                    // Order placed but couldn't retrieve it
                    $this->app->enqueueMessage(
                        Text::_('COM_ALFA_ORDER_PLACED_BUT_NOT_LOADED'),
                        'warning',
                    );

                    // Still redirect to success (order was created)
                    $this->setRedirect(
                        Route::_('index.php?option=com_alfa&view=cart&layout=default_order_process', false),
                    );

                    return true;
                }
            } else {
                // Placement failed; OrderPlaceHelper has already enqueued the specific errors.
                // Add a fallback message.
                $this->app->enqueueMessage(
                    Text::_('COM_ALFA_ORDER_PLACEMENT_FAILED'),
                    'error',
                );

                // Redirect back to cart
                $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

                return false;
            }
        } catch (Exception $e) {
            // Unexpected error during placement.
            $this->app->enqueueMessage(
                Text::sprintf('COM_ALFA_ORDER_ERROR', $e->getMessage()),
                'error',
            );

            // Log the error
            \Joomla\CMS\Log\Log::add(
                'Order placement exception: ' . $e->getMessage(),
                \Joomla\CMS\Log\Log::ERROR,
                'com_alfa.orders',
            );

            // Redirect back to cart
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=cart', false));

            return false;
        }
        //		$order = new OrderPlaceHelper();

        //		$placeOrderError = !$order->placeOrder($data['com_alfa']);

        //		$orderId = $order->getOrder()->id;
        //
        //		$this->app->setUserState('com_alfa.order_id', $orderId);
        //
        //		if (!$placeOrderError)
        //		{
        //			$this->setRedirect(Route::_('index.php?option=com_alfa&view=cart&layout=default_order_process'));
        //		}
        //		else
        //		{
        //			$this->setRedirect(Route::_('index.php?option=com_alfa&view=cart'));
        //		}

        return true;
    }

    // Updates the cart's shipment id, re-renders the items layout, and returns it as an answer.
    public function updateShipment()
    {
        $input = $this->app->input;

        $failMessage = 'Shipment could not be updated.';
        $successMessage = 'Shipment updated successfully!';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $shipmentID = $input->getInt('id_shipment', 0);
        $response_data = [];
        $errorOccurred = false;

        $cart = new CartHelper();
        $errorOccurred = !$cart->updateShipment($shipmentID);
        $errorMessage = $errorOccurred ? 'Shipment ID could not be updated.' : '';

        if (!$errorOccurred) {
            $response_data['isEmpty'] = $cart->isEmpty();
            $response_data['items'] = $this->getItemsLayout($cart);
            $response_data['payments'] = $this->getPaymentsLayout($cart);
            $response_data['shipments'] = $this->getShipmentsLayout($cart);
        }

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);
        echo $response;
        $this->app->close();
    }

    /**
     * AJAX task: set the cart's payment method and, on success, return the re-rendered items, payments
     * and shipments layouts (plus the empty flag) as JSON.
     * Requires a valid POST CSRF token; closes the application after emitting JSON.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function updatePayment()
    {
        $input = $this->app->input;

        $failMessage = 'Payment could not be updated.';
        $successMessage = 'Payment updated successfully!';

        self::verifyTokenAndRespondJson('post', $failMessage);

        $paymentID = $input->getInt('id_payment', 0);
        $response_data = [];
        $errorOccurred = false;

        $cart = new CartHelper();
        $errorOccurred = !$cart->updatePayment($paymentID);

        if (!$errorOccurred) {
            $response_data['isEmpty'] = $cart->isEmpty();
            $response_data['items'] = $this->getItemsLayout($cart);
            $response_data['payments'] = $this->getPaymentsLayout($cart);
            $response_data['shipments'] = $this->getShipmentsLayout($cart);
        }

        header('Content-Type: application/json');
        $response = new JsonResponse($response_data, $errorOccurred ? $failMessage : $successMessage, $errorOccurred);
        echo $response;
        $this->app->close();
    }

    /**
     * Render the cart items layout, falling back to the empty-cart layout when the cart has no items.
     *
     * @param CartHelper $cart The cart helper holding the current cart state.
     *
     * @return array ['tmpl' => rendered HTML, 'isEmpty' => bool].
     *
     * @since  1.0.0
     */
    protected function getItemsLayout($cart)
    {
        // TODO : support template ovverides
        $layoutData = [];
        $isEmpty = $cart->isEmpty();

        if ($isEmpty) {
            $layout = new FileLayout('default_cart_empty', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render();
        } else {
            $layout = new FileLayout('default_cart_items', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render($cart);//shown in layout as $displayData
        }

        $layoutData = [
            'tmpl' => $result,
            'isEmpty' => $isEmpty,
        ];

        return $layoutData;
    }

    /**
     * Render the payment-method selection layout, attaching payment plugin events first.
     * Returns an empty template when the cart has no items.
     *
     * @param CartHelper $cart The cart helper holding the current cart state.
     *
     * @return array ['tmpl' => rendered HTML, 'isEmpty' => bool].
     *
     * @since  1.0.0
     */
    protected function getPaymentsLayout($cart)
    {
        $layoutData = [];
        $isEmpty = $cart->isEmpty();

        if ($isEmpty) {
            $tmpl = '';
        } else {
            $cart->addEventsToPayments();// Load shipment methods.
            $layout = new FileLayout('default_select_payment', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render($cart);//shown in layout as $displayData
        }

        $layoutData = [
            'tmpl' => $result,
            'isEmpty' => $isEmpty,
        ];

        return $layoutData;
    }

    /**
     * Render the shipment-method selection layout, attaching shipment plugin events first.
     * Returns an empty template when the cart has no items.
     *
     * @param CartHelper $cart The cart helper holding the current cart state.
     *
     * @return array ['tmpl' => rendered HTML, 'isEmpty' => bool].
     *
     * @since  1.0.0
     */
    protected function getShipmentsLayout($cart)
    {
        $layoutData = [];
        $isEmpty = $cart->isEmpty();

        if ($isEmpty) {
            $tmpl = '';
        } else {
            $cart->addEventsToShipments();// Load shipment methods.
            $layout = new FileLayout('default_select_shipment', JPATH_ROOT . '/components/com_alfa/tmpl/cart');
            $result = $layout->render($cart);//shown in layout as $displayData
        }

        $layoutData = [
            'tmpl' => $result,
            'isEmpty' => $isEmpty,
        ];

        return $layoutData;
    }

    //    public function updateUserInfo()
    //{
    //        $this->checkToken();
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

    protected function verifyTokenAndRespondJson($method = 'post', $invalidMessage = '')
    {
        $isValid = $this->checkToken($method, false);

        if (!$isValid) {
            $this->app->enqueueMessage(Text::_('JINVALID_TOKEN_NOTICE'), 'warning');
            $response = new JsonResponse(null, $invalidMessage, true);
            echo $response;
            $this->app->close();
        }
    }
}
