<?php

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;

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

    public function __construct($cartId = 0)
    {
        $this->app = Factory::getApplication();
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->user = $this->app->getIdentity();
        $this->recognizeKey = $this->getRecognizeKey();
        $this->cartId = $cartId;

        $this->cart = $this->getCart(); // Initialize cart object and cartId

        // this->cart->items
        $this->payment_methods = AlfaHelper::getFilteredMethods($this->categories,$this->manufacturers,$this->user->groups,$this->user->id);
    }

    // Getter and Setter for cartId
    public function getCartId()
    {
        return $this->cartId;
    }

    public function getPaymentMethods(){
        return $this->payment_methods;
    }

    public function setCartId($cartId)
    {
        $this->cartId = $cartId;
        $this->cart = $this->getCart(); // Optionally update the cart after setting the cartId
    }

    public function getData(){
        return $this->cart;
    }

    protected function getCart(){
        if ($this->user->id <= 0 && $this->recognizeKey == '' && $this->cartId <= 0) {
            return false;
        }
        
        try {
            $db = $this->db;
            $query = $db->getQuery(true);
            $query->select('*')
                ->from($db->quoteName($this->cart_table, 'a'));

            if($this->cartId>0){
                $query->where($db->quoteName('id') . ' = ' . $db->quote($this->cartId));
            }else{
                if ($this->user->id > 0) {
                    $query->where($db->quoteName('id_customer') . ' = ' . $db->quote($this->user->id));
                } else {
                    $query->where($db->quoteName('recognize_key') . ' = ' . $db->quote($this->recognizeKey));
                }
            }

            $db->setQuery($query);
            $cart_data = $db->loadObject();

            if($cart_data){
                $this->cartId = $cart_data->id;    
            }else{
                $this->cartId = 0;
            }

            if($this->cartId>0) {
                $cart_data->items = $this->getCartItems();
            }
        } catch (\Exception $e) {
            // if ($e->getCode() == 404) {
                // Need to go through the error handler to allow Redirect to work.
                // throw $e;
            // }
            // $this->setError($e);
            // $this->cart = false;
        }

        return $cart_data;

    }

    // Get all items in the cart
    protected function getCartItems()
    {
    // return [];


        if($this->cartId<=0){
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

        if(empty($cart_items)) {
            return [];
        }

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


    protected function createRecognizeKey(){
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
            ]);
        
        $this->recognizeKey = $cookieValue;
    }

    public function getCategories(){
        return $this->categories;
    }

    public function getManufacturers(){
        return $this->manufacturers;
    }

    public function getUser(){
        return $this->user;
    }

    protected function createCart(): bool{

        $cartObject = new \stdClass();
        $cartObject->id_shop_group = 1;
        $cartObject->id_carrier = 1;
        $cartObject->delivery_option = 'walking';
        $cartObject->id_lang = 1;
        $cartObject->id_address_delivery = 1;
        $cartObject->id_address_invoice = 1;
        $cartObject->id_currency = 1;
        $cartObject->id_customer = $this->user->id;
        $cartObject->date_add = Factory::getDate()->toSql();
        $cartObject->date_upd = Factory::getDate()->toSql();
        $cartObject->recognize_key = $this->recognizeKey;

        try {
            $db = $this->db;
            $db->insertObject($this->cart_table, $cartObject , 'id');
            $this->cartId = $db->insertid();//or $cartObject->id cause insert updates this value
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }

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
            if(!$this->createCart())
                return false;
        }

        $itemExistsInCart = false;
        $cartItemData = null;

        // check in cart for the current item
        foreach($this->cart->items as $cart_item){
            // print_r($cart_item);
            // exit;
            if($cart_item->id == $itemId){
                $cartItemData = $cart_item;
                $itemExistsInCart = true;
                break;  // Exit the loop once the item is found
            }
        }

        // not found in cart so get the item data the same way the cart would get those
        if(!$itemExistsInCart){
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
        if( $checkStock && $cartItemData->stock != null && $cartItemData->stock <= 0){
            $this->app->enqueueMessage('Product is out of stock','error');
            return false;
        }

        $itemObject = new \stdClass();
        $itemObject->id_cart     = $this->cartId;
        $itemObject->id_item     = $itemId;
        $itemObject->quantity    = $quantity;
        $itemObject->date_add    = Factory::getDate()->toSql();


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


        try{
            if($itemExistsInCart){

                $query = $db->getQuery(true);

                // Fields to update.
                $fields = array(
                    $db->quoteName('quantity') . ' = ' . intval($itemObject->quantity),
                    $db->quoteName('date_add') . ' = ' . $db->quote($itemObject->date_add)
                );

                // Conditions for which records should be updated.
                $conditions = array(
                    $db->quoteName('id_item') . ' = ' . intval($itemId), 
                    $db->quoteName('id_cart') . ' = ' . intval($this->cartId)
                );


                if($quantity > 0){//update the item if quantity given is grater than 0
                    $query->update($db->quoteName($this->cart_items_table))->set($fields)->where($conditions);
                }else{//delete the item if quantity give is 0
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


        if(!$clearOnlyItems){
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
    public function getTotal()
    {
        $total = 0;
        // $cartItems = $this->getCartItems();

        foreach ($this->cart->items as $item) {
            $total += $item->price['final_price'];  
        }

        return $total;
    }

    // Check if the cart is empty
    public function isEmpty()
    {
        return count($this->cart->items) === 0;
    }

    // Remove item from cart
    public function removeItem($itemId){
        return $this->updateQuantity(0,$itemId);
    }


}