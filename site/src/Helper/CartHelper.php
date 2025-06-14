<?php

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use Alfa\Component\Alfa\Administrator\Event\Payments\CartViewEvent as PaymentsCartViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\CartViewEvent as ShipmentsCartViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\CalculateShippingCostEvent as ShipmentsCalculateShippingCostEvent;

class CartHelper
{
    protected $app;
    protected $db;
    protected $user;
    protected $cartId; // access with getCartId() function
    protected $recognizeKey; // access with getRecognizeKey() function
    protected $cart; // access with getData() function

    protected $items_table = '#__alfa_items';
    protected $cart_table = '#__alfa_cart';
    protected $cart_items_table = '#__alfa_cart_items';

    protected $categories = [];
    protected $manufacturers = [];

    protected $payment_methods;
    protected $shipment_methods;

    public function __construct($cartId = 0)
    {
        $this->app = Factory::getApplication();
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->user = $this->app->getIdentity();
        $this->recognizeKey = $this->getRecognizeKey();
        $this->cartId = $cartId;

        $this->cart = $this->getCart(); // Initialize cart object and cartId
        // print_r(var_dump($this->cart));
        // exit;
        // $this->cart->items = [];
        $this->payment_methods = AlfaHelper::getFilteredMethods($this->categories, $this->manufacturers, $this->user->groups, $this->user->id, "payment");
        $this->shipment_methods = AlfaHelper::getFilteredMethods($this->categories, $this->manufacturers, $this->user->groups, $this->user->id, "shipment");
    }

    // Getter and Setter for cartId
    public function getCartId()
    {
        return $this->cartId;
    }

    public function getPaymentMethods()
    {
        return $this->payment_methods;
    }

    public function getShipmentMethods()
    {
        return $this->shipment_methods;
    }

    public function getShipmentMethodData($shipmentMethodId = 0)
    {

        if ($shipmentMethodId == 0) {
            $shipmentMethodId = self::getData()->id_shipment ?? 0;
        }

        if ($shipmentMethodId == 0) {
            return null;
        }

        $shipmentModel = $this->app->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Shipment', 'Administrator', ['ignore_request' => true]);

        return ($shipmentModel->getItem($shipmentMethodId));
    }

    public function setCartId($cartId)
    {
        $this->cartId = $cartId;
        $this->cart = $this->getCart(); // Optionally update the cart after setting the cartId
    }


    public function getData()
    {
        return $this->cart;
    }

    protected function getCart()
    {

        // Creating new cart?
        if ($this->user->id <= 0 && $this->recognizeKey == '' && $this->cartId <= 0) {
            $cart_data = new \stdClass(); //return empty object
            $cart_data->items = [];//$this->getCartItems()
            return;
        }

        $db = $this->db;

        try {

            $query = $db->getQuery(true);
            $query->select('*')
                ->from($db->quoteName($this->cart_table, 'a'));

            if ($this->cartId > 0) {
                $query->where($db->quoteName('id') . ' = ' . $db->quote($this->cartId));
            } else {
                if ($this->user->id > 0) {
                    $query->where($db->quoteName('id_customer') . ' = ' . $db->quote($this->user->id));
                } else {
                    $query->where($db->quoteName('recognize_key') . ' = ' . $db->quote($this->recognizeKey));
                }
            }

            $db->setQuery($query);
            $cart_data = $db->loadObject();

            if ($cart_data) {
                $this->cartId = $cart_data->id;

                // Add shipment costs.
                // TODO: shipmment costs
                // $cart_data->shipment_costs_total = 10;
                // if(!property_exists($cart_data, 'shipment_costs_total')){
                // $cart_data->shipment_costs_total = new \stdClass();

                // }
            } else {
                $cart_data = new \stdClass();
                $this->cartId = 0;
            }

            // if($this->cartId>0) {
            $cart_data->items = $this->getCartItems();
            // }

        } catch (\Exception $e) {
            // if ($e->getCode() == 404) {
            // Need to go through the error handler to allow Redirect to work.
            // throw $e;
            // }
            // $this->setError($e);
            // $this->cart = false;
        }

        // Add user info in case the user is a real user.
        //        if($this->user->id > 0) {
        //            try {
        //
        //                // DEBUGGING.
        //                $user_id = 2;
        //                $cart_data->id_user_info_delivery = 1;
        //
        //                $query = $db->getQuery(true);
        //
        //                $query
        //                    ->select("*")
        //                    ->from("#__alfa_user_info")
        //                    ->where($db->qn("id") . '=' . (int)$cart_data->id_user_info_delivery)
        //                    ->order('id DESC')
        //                    ->setLimit(1);
        //
        //                $db->setQuery($query);
        //                $userInfoDelivery = $db->loadObject();
        //
        //            } catch(\Exception $e){
        //
        //            }
        //        }

        // DEBUGGING.
        //        $cart_data->id_user_info_delivery = 2;

        // Collect user's info.
        if (isset($cart_data->id_user_info_delivery)) {
            $user_info_delivery = self::getUserInfo($cart_data->id_user_info_delivery);
            $cart_data->user_info_delivery = $user_info_delivery;
        }
        if (isset($cart_data->id_user_info_invoice)) {
            $user_info_invoice = self::getUserInfo($cart_data->id_user_info_invoice);
            $cart_data->user_info_invoice = $user_info_invoice;
        }

        //        $cart_data->user_info_delivery = $user_info_delivery;
        //        $cart_data->user_info_invoice = $user_info_invoice;

        return $cart_data;

    }

    /**
     *  Gets user info based on the entry id provided.
     *  @param $info_id int the id of the user_info entry to be returned.
     *  @return null|object
     */
    protected function getUserInfo($info_id)
    {
        $userInfo = null;

        if ($info_id <= 0) {
            return null;
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query
            ->select("*")
            ->from("#__alfa_user_info")
            ->where($db->qn("id") . '=' . (int) $info_id)
            ->order('id DESC')
            ->setLimit(1);

        try {
            $db->setQuery($query);
            $userInfo = $db->loadObject();

        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
        }

        return $userInfo;

    }


    // Get all items in the cart
    protected function getCartItems()
    {

        if ($this->cartId <= 0) {
            return [];
        }

        $app = $this->app;
        $db = $this->db;
        $query = $db->getQuery(true);


        // Define the query to fetch the cart items with item details and categories
        $query = $db->getQuery(true)
            ->select([
                // 'ci.id_cart as cart_id',
                // 'ci.quantity as quantity',
                // 'ci.date_add as cart_date_added',
                'ci.*',
                'i.*',
                'GROUP_CONCAT(ic.category_id ORDER BY ic.category_id ASC) AS category_ids',
                'GROUP_CONCAT(im.manufacturer_id ORDER BY im.manufacturer_id ASC) AS manufacturer_ids'
            ])
            ->from('#__alfa_cart_items AS ci')
            ->join('INNER', '#__alfa_items AS i ON ci.id_item = i.id')

            ->join('LEFT', '#__alfa_items_categories AS ic ON i.id = ic.item_id')
            ->join('LEFT', '#__alfa_items_manufacturers AS im ON i.id = im.item_id')

            ->where($db->quoteName('ci.id_cart') . ' = ' . $db->quote($this->cartId))

            ->group('ci.id_cart, ci.id_item')
            ->order('ci.id_cart DESC'); // Optional: Order by date_added (change as needed)

        $db->setQuery($query);
        $cart_items = $db->loadObjectList();

        if (empty($cart_items)) {
            return [];
        }

        // TODO: CHECK currency id
        $currencyId = 978;

        foreach ($cart_items as $index => &$cart_item) {

            // save only common categories
            if (!empty($cart_item->category_ids)) {
                $cart_item_categories = explode(',', $cart_item->category_ids); // Split by comma to get an array of categories

                // If $this->categories is empty, initialize it with the current item categories
                if (empty($this->categories)) {
                    $this->categories = $cart_item_categories;
                } else {
                    // Otherwise, find the intersection of categories
                    $this->categories = array_intersect($this->categories, $cart_item_categories);
                }
            }

            // save only common manufacturers
            if (!empty($cart_item->manufacturer_ids)) {
                $cart_item_manufacturers = explode(',', $cart_item->manufacturer_ids); // Split by comma to get an array of categories

                // If $this->categories is empty, initialize it with the current item manufacturers
                if (empty($this->manufacturers)) {
                    $this->manufacturers = $cart_item_manufacturers;
                } else {
                    // Otherwise, find the intersection of manufacturers
                    $this->manufacturers = array_intersect($this->manufacturers, $cart_item_manufacturers);
                }
            }

            $itemPriceCalculator = new PriceCalculator($cart_item->id, $cart_item->quantity, $this->user->groups, $currencyId);
            $cart_item->price = $itemPriceCalculator->calculatePrice();
        }

        return $cart_items;
    }


    // Retrieve or create a cart ID
    public function getRecognizeKey()
    {
        $input = $this->app->input;

        $cookieName = 'recognize_key';
        $rkCookie = $input->cookie->get($cookieName, '');

        // $cookieName = 'recognize_key';

        $rkCookie = $this->app->input->cookie->get($cookieName, '');
        // if ($rkCookie == '') {
        // }

        return $rkCookie;
    }

    protected function createRecognizeKey()
    {
        $cookieName = 'recognize_key';
        $cookieValue = rand();//TODO: something more specific to the user computer to be surely unique

        // Define the cookie parameters
        $expires = time() + 3600 * 24; // Cookie expires in 1 hour
        $path = '/'; // Cookie is available across the entire domain
        $domain = ''; // Leave empty for the current domain
        $secure = true; // Set to true if using HTTPS
        $httponly = true; // Cookie is not accessible via JavaScript
        $samesite = 'Strict'; // Can be 'Strict', 'Lax', or 'None'
        $this->app->input->cookie->set(
            $cookieName,
            $cookieValue,
            [
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite
            ]
        );

        $this->recognizeKey = $cookieValue;
    }

    public function getCategories()
    {
        return $this->categories;
    }

    public function getManufacturers()
    {
        return $this->manufacturers;
    }

    public function getUser()
    {
        return $this->user;
    }

    protected function createCart(): bool
    {
        $currentDate = Factory::getDate('now', 'UTC');

        $cartObject = new \stdClass();
        $cartObject->id_shop_group = 1;
        $cartObject->id_shipment = 1;
        $cartObject->id_lang = 1; //Joomla language id
        $cartObject->id_user_info_delivery = 0; //autoincrement info from user_info table
        $cartObject->id_user_info_invoice = 0; //autoincrement info from user_info table
        $cartObject->id_currency = 1; //currency id
        $cartObject->id_customer = $this->user->id;
        $cartObject->added = $currentDate->toSql(false);
        $cartObject->updated = $currentDate->toSql(false);
        $cartObject->recognize_key = $this->recognizeKey;


        // TODO: if user id is not 0 , by default assign the latest user info delivery and invoice ids

        // Create new user data if none was found.
        //        $cartObject->id_user_info_delivery = self::createNewUserInfo();
        //        $cartObject->id_user_info_invoice = self::createNewUserInfo();

        try {
            $db = $this->db;
            $db->insertObject($this->cart_table, $cartObject, 'id');
            $this->cartId = $db->insertid();//or $cartObject->id cause insert updates this value
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }


        $this->cart = $this->getCart();

        return true;

    }


    // if quantity = 0 it will delete this item
    public function addToCart($itemId, $quantity)
    {

        if ($this->recognizeKey == '') {
            $this->createRecognizeKey();
        }

        $db = $this->db;

        // CREATE CART IF DOESNT EXIST
        if ($this->cartId <= 0) {
            if (!$this->createCart()) {
                return false;
            }
        }

        $itemExistsInCart = false;
        $cartItemData = null;

        // check in cart for the current item
        foreach ($this->cart->items as $cart_item) {
            // print_r($cart_item);
            // exit;
            if ($cart_item->id == $itemId) {
                $cartItemData = $cart_item;
                $itemExistsInCart = true;
                break;  // Exit the loop once the item is found
            }
        }

        // not found in cart so get the item data the same way the cart would get those
        if (!$itemExistsInCart) {
            //Retrieving item data
            $query = $db->getQuery(true);
            $query->select("*")
                ->from($this->items_table)
                ->where($db->qn('id') . " = " . $db->q($itemId));
            $db->setQuery($query);

            $cartItemData = new \stdClass();
            $cartItemData->quantity = 0;
            $cartItemData = $db->loadObject();
        }

        $checkStock = ($cartItemData->stock_action == 1 || $cartItemData->stock_action == 2);

        //In case we need to check for stock availability, we check if stock is set and above 0.
        if ($checkStock && $cartItemData->stock != null && $cartItemData->stock <= 0) {
            $this->app->enqueueMessage('Product is out of stock', 'error');
            return false;
        }

        $itemObject = new \stdClass();
        $itemObject->id_cart     = $this->cartId;
        $itemObject->id_item     = $itemId;
        $itemObject->quantity    = $quantity;
        $itemObject->added    = Factory::getDate('now', 'UTC')->toSql(false);


        if ($itemObject->quantity % $cartItemData->quantity_step != 0) { // Not divisible by step
            $itemObject->quantity = floor($itemObject->quantity / $cartItemData->quantity_step) * $cartItemData->quantity_step; // Find the closest lower value divisible by quantity_step
        }

        // Ensure it respects the quantity_min
        if ($itemObject->quantity < $cartItemData->quantity_min) {
            $itemObject->quantity = $cartItemData->quantity_min;
        }

        // Ensure it respects the quantity_max
        if (isset($cartItemData->quantity_max) && $itemObject->quantity > $cartItemData->quantity_max) {
            $itemObject->quantity = $cartItemData->quantity_max;
        }

        // Ensure the quantity does not exceed stock even after adjustments
        if ($checkStock && $cartItemData->stock != null && $itemObject->quantity > $cartItemData->stock) {
            $itemObject->quantity = $cartItemData->stock;
        }


        try {
            if ($itemExistsInCart) {

                $query = $db->getQuery(true);

                // Fields to update.
                $fields = [
                    $db->quoteName('quantity') . ' = ' . intval($itemObject->quantity),
                    $db->quoteName('added') . ' = ' . $db->quote($itemObject->added)
                ];

                // Conditions for which records should be updated.
                $conditions = [
                    $db->quoteName('id_item') . ' = ' . intval($itemId),
                    $db->quoteName('id_cart') . ' = ' . intval($this->cartId)
                ];


                if ($quantity > 0) {//update the item if quantity given is grater than 0
                    $query->update($db->quoteName($this->cart_items_table))->set($fields)->where($conditions);
                } else {//delete the item if quantity give is 0
                    $query->delete($db->quoteName($this->cart_items_table))->where($conditions);
                }

                // $updateNulls = true;
                // $db->updateObject('#__alfa_cart_items', $itemObject, 'id', $updateNulls);

                // Set the query and execute it
                $db->setQuery($query);
                $db->execute();

            } else { // insert the item if not found in cart
                $db->insertObject($this->cart_items_table, $itemObject);
            }
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }

        // Refresh the cart items after updating the database
        $this->cart->items = $this->getCartItems();

        return true;

    }

    // Clear the cart
    public function clearCart($clearOnlyItems = false)
    {
        $db = $this->db;

        $query = $db->getQuery(true)
            ->delete($this->cart_items_table)
            ->where('id_cart = ' . $this->db->quote($this->cartId));

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }


        if (!$clearOnlyItems) {
            $query = $db->getQuery(true)
                ->delete($this->cart_table)
                ->where('id = ' . $this->db->quote($this->cartId));

            try {
                $db->setQuery($query);
                $db->execute();
            } catch (\Exception $e) {
                $this->app->enqueueMessage($e->getMessage());
                return false;
            }
        }

        // Refresh cart object
        $this->cart->items = [];//or $this->cart = $this->getCartItems(); but it will query the db again
        return true;
    }

    // Get total price of the cart
    public function getShipmentTotal()
    {
        $cost = 0;

        $shipmentID = $this->cart->id_shipment;
        $currentMethod = $this->shipment_methods[$shipmentID] ?? null;
        $currentType = $currentMethod ? $currentMethod->type : null;

        // Get shipment cost from selected plugin if it's a valid one.
        if (!empty($currentType)) {
            $onShowShippingCostEventName = "onCalculateShippingCost";
            $showShippingCostEvent = new ShipmentsCalculateShippingCostEvent($onShowShippingCostEventName, [
                "subject" => $this,
                "shippingCost" => 0
            ]);

            $this->app->bootPlugin($currentType, "alfa-shipments")->{$onShowShippingCostEventName}($showShippingCostEvent);
            $cost = $showShippingCostEvent->getShippingCost();
        }

        return $cost;
    }

    // Get total price of the cart
    public function getTotal()
    {
        $total = 0;
        // $cartItems = $this->getCartItems();

        foreach ($this->cart->items as $item) {
            $total += $item->price['final_price'];
        }

        // TODO: add the shipments costs based on shipment selected and items in cart
        // if(property_exists($this->cart, 'shipment_costs_total'))
        // $total += $this->cart->shipment_costs_total??0;

        return $total;
    }

    // Check if the cart is empty
    public function getTotalItems()
    {
        $totalItems = 0;

        if (isset($this->cart->items)) {
            $totalItems = count($this->cart->items);
        }

        return $totalItems;
    }

    // Get total price of the cart
    public function getTotalQuantity()
    {
        $quantity = 0;
        // $cartItems = $this->getCartItems();

        if (isset($this->cart->items)) {
            foreach ($this->cart->items as $item) {
                $quantity += $item->quantity;
            }
        }

        return $quantity;
    }

    // Check if the cart is empty
    public function isEmpty()
    {
        return count($this->cart->items) === 0;
    }

    // Remove item from cart
    public function removeItem($itemId)
    {
        return $this->updateQuantity(0, $itemId);
    }

    public function updateUserInfo()
    {
        // if ($this->cartId <= 0)
        //     if(!$this->createCart())
        //         return false;

        // $db = $this->db;
        // $query = $db->getQuery(true);
        // $query
        //     ->update($this->cart_table)
        //     ->set("id_shipment" . '=' . $id)
        //     ->where('id=' . $this->cartId);

        // try {
        //     $db->setQuery($query);
        //     $result = $db->execute();
        // } catch (\Exception $e) {
        //     $this->app->enqueueMessage($e->getMessage());
        //     return false;
        // }

        // // self::getData()->id_shipment = $id;
        // if(!empty($result))
        //     $this->cart->id_shipment = $id;

        // return $result;
    }

    public function updateShipment($id)
    {
        if ($this->cartId <= 0) {
            if (!$this->createCart()) {
                return false;
            }
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query
            ->update($this->cart_table)
            ->set("id_shipment" . '=' . $id)
            ->where('id=' . $this->cartId);

        try {
            $db->setQuery($query);
            $result = $db->execute();
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }

        // self::getData()->id_shipment = $id;
        if (!empty($result)) {
            $this->cart->id_shipment = $id;
        }

        return $result;
    }

    public function updatePayment($id)
    {
        if ($this->cartId <= 0) {
            if (!$this->createCart()) {
                return false;
            }
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query
            ->update($this->cart_table)
            ->set("id_payment" . '=' . $id)
            ->where('id=' . $this->cartId);

        try {
            $db->setQuery($query);
            $result = $db->execute();
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }

        // self::getData()->id_payment = $id;
        if ($result) {
            $this->cart->id_payment = $id;
        }

        return $result;
    }


    public function addEventsToShipments()
    {

        // Load payment methods.
        foreach (self::getShipmentMethods() as &$shipmentMethod) {

            $isSelected = (self::getData()->id_shipment == $shipmentMethod->id);

            $onCartViewEventName = 'onCartView';

            // Create event.
            $shipmentEvent = new ShipmentsCartViewEvent($onCartViewEventName, [
                'subject' => $this,
                'method'  => $shipmentMethod
            ]);

            $this->app->bootPlugin($shipmentMethod->type, "alfa-shipments")->{$onCartViewEventName}($shipmentEvent);

            // if not selected we do not set layout name and type or boot the plugin
            if ($isSelected) {

                if (empty($shipmentEvent->getLayoutPluginName())) {
                    $shipmentEvent->setLayoutPluginName($shipmentMethod->type);
                }
                if (empty($shipmentEvent->getLayoutPluginType())) {
                    $shipmentEvent->setLayoutPluginType("alfa-shipments");
                }
                if ($shipmentEvent->hasRedirect()) {
                    $this->app->redirect(
                        $shipmentEvent->getRedirectUrl(),
                        $shipmentEvent->getRedirectCode() ?? 303
                    );

                    return;
                }
            }

            $shipmentMethod->events = new \stdClass();
            $shipmentMethod->events->{$onCartViewEventName} = $shipmentEvent;

        }

    }

    public function addEventsToPayments()
    {

        // Load payment methods.
        foreach (self::getPaymentMethods() as &$paymentMethod) {

            $isSelected = (self::getData()->id_payment == $paymentMethod->id);
            $onCartViewEventName = 'onCartView';

            // Create event.
            $paymentEvent = new PaymentsCartViewEvent($onCartViewEventName, [
                'subject'   => $this,
                'method'    => $paymentMethod
            ]);

            $this->app->bootPlugin($paymentMethod->type, "alfa-payments")->{$onCartViewEventName}($paymentEvent);

            // if not selected we do not set layout name and type or boot the plugin
            if ($isSelected) {

                if (empty($paymentEvent->getLayoutPluginName())) {
                    $paymentEvent->setLayoutPluginName($paymentMethod->type);
                }
                if (empty($paymentEvent->getLayoutPluginType())) {
                    $paymentEvent->setLayoutPluginType("alfa-payments");
                }
                if ($paymentEvent->hasRedirect()) {
                    $this->app->redirect(
                        $paymentEvent->getRedirectUrl(),
                        $paymentEvent->getRedirectCode() ?? 303
                    );

                    return;
                }
            }

            $paymentMethod->events = new \stdClass();
            $paymentMethod->events->{$onCartViewEventName} = $paymentEvent;

        }
    }


    //	TODO: WILL CHANGE WHEN USERS INCLUDED IN THE APP
    //    public function updateUserData($column, $value){
    //
    //        $db = $this->db;
    //        $cartData = self::getData();
    //
    //        // If we create new user_info entries, we'll need to update the cart to refer to them.
    //        $needToUpdateCart = false;
    //
    //        // Create user info if no user info exists
    //	    // Every cart has assigned user info . if there is 0 then we should create a new entry for it
    //        if($cartData->id_user_info_delivery == 0 || $cartData->id_user_info_invoice==0) {   // 0 for none.
    //            $cartData->id_user_info_delivery = $cartData->id_user_info_invoice = $createUserInfoResult = self::createNewUserInfo(["id_user" => $this->user->id]);
    //	        if($createUserInfoResult==0){
    //				return false;
    //	        }
    //			$needToUpdateCart = true;
    //        }
    //
    //        // if($cartData->id_user_info_invoice == 0){
    //        //     $cartData->id_user_info_invoice = self::createNewUserInfo(["id_user" => $this->user->id]);
    //        //     $needToUpdateCart = true;
    //        // }
    //
    //
    //        try {   // Currently only updating delivery user info. TODO: Determine what should happen here.
    //            $query = $db->getQuery(true);
    //            $query
    //                ->update("#__alfa_user_info")
    //                ->set($db->qn($column) . ' = ' . $db->q($value))
    //                ->where($db->qn("id") . " = " . (int) $cartData->id_user_info_delivery);
    //
    //            $db->setQuery($query);
    //            $db->execute();
    //
    //        } catch (\Exception $e) {
    //            $this->app->enqueueMessage($e->getMessage());
    //            return false;
    //        }
    //
    //        // Update data in the user info IDs in the database.
    //        if($needToUpdateCart) {
    //            $updateArray = [
    //                "id" => $cartData->id,
    //                "id_user_info_delivery" => $cartData->id_user_info_delivery,
    //                "id_user_info_invoice" => $cartData->id_user_info_invoice
    //            ];
    //            self::updateCartData($updateArray);
    //        }
    //
    //        return true;
    //    }

    /**
     *  Inserts a new entry on the user info table to be used.
     *  @return int the id of the newly inserted entry.
     */
    //	protected function createNewUserInfo($insertObject = null){
    //
    //		$db = $this->db;
    //		if(is_array($insertObject))
    //			$insertObject = json_decode(json_encode($insertObject));
    //
    //		try {
    //			$db->insertObject("#__alfa_user_info", $insertObject, "id");
    //		} catch (\Exception $e) {
    //			$this->app->enqueueMessage($e->getMessage());
    //			return 0;
    //		}
    //
    //		return $insertObject->id;
    //		// return $db->insertid();
    //	}
    //
    //    protected function updateCartData($dataObject){
    //
    //        // Invalid input.
    //        if(empty($dataObject))
    //            return false;
    //
    //        if(is_array($dataObject))
    //            $dataObject = json_decode(json_encode($dataObject));
    //
    //        $db = $this->db;
    //        $db->updateObject("#__alfa_cart", $dataObject, "id");
    //
    //        return true;
    //    }


}
