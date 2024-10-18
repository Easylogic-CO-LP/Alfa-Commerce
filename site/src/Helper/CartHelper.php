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

    protected $cart_table = '#__alfa_cart';
    protected $cart_items_table = '#__alfa_cart_items';

    public function __construct($cartId = 0)
    {
        $this->app = Factory::getApplication();
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->user = $this->app->getIdentity();
        $this->recognizeKey = $this->getRecognizeKey();
        $this->cartId = $cartId;

        $this->cart = $this->getCart(); // Initialize cart object and cartId
    }


    // Getter and Setter for cartId
    public function getCartId()
    {
        return $this->cartId;
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

        if($this->cartId<=0){
            return [];
        }

        $db = $this->db;
        $query = $db->getQuery(true);

        $query->select('ci.* , i.*')
                ->from($db->quoteName($this->cart_items_table, 'ci'))
                ->join('INNER', $db->quoteName('#__alfa_items', 'i'), $db->quoteName('i.id') . ' = ' . $db->quoteName('ci.id_item'))
                ->where($db->quoteName('ci.id_cart') . ' = ' . $db->quote($this->cartId));

        $db->setQuery($query);
        
        $cart_items = $db->loadObjectList();

        if(!empty($cart_items)) {
            foreach ($cart_items as $index => &$item) {
                $userGroupId = null;
                $currencyId = null;

                $itemPriceCalculator = new PriceCalculator($item->id, $item->quantity, $userGroupId, $currencyId);
                $item->price = $itemPriceCalculator->calculatePrice();
            }

            return $cart_items;
        }else{
            return [];
        }


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

    // Add item or update quantity in cart
    public function addToCart($itemId,$quantity)
    {

        if ($this->recognizeKey == '') {
            $this->createRecognizeKey();
        }

        $db = $this->db;

        // try {

        // CREATE CART IF DOESNT EXIST
        if ($this->cartId <= 0) {

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
                $db->insertObject($this->cart_table, $cartObject , 'id');
                $this->cartId = $db->insertid();//or $cartObject->id cause insert updates this value
            } catch (\Exception $e) {
                $this->app->enqueueMessage($e->getMessage());
                return false;
            }

            
        }


        $itemExists = null;
        foreach($this->cart->items as $item){
            if($item->id == $itemId){
                $itemExists = $item;
                break;  // Exit the loop once the item is found
            }
        }


        $itemObject = new \stdClass();
        $itemObject->id_cart     = $this->cartId;
        $itemObject->id_item     = $itemId;
        $itemObject->quantity    = $quantity + ( $itemExists ? $itemExists->quantity : 0 );
        $itemObject->date_add    = Factory::getDate()->toSql();


        try{
            if($itemExists){
                $query = $db->getQuery(true);
                // $updateNulls = true;
                // $db->updateObject('#__alfa_cart_items', $itemObject, 'id', $updateNulls);
                $query->update($db->quoteName($this->cart_items_table))
                      ->set($db->quoteName('quantity') . ' = ' . $db->quote($itemObject->quantity))
                      ->set($db->quoteName('date_add') . ' = ' . $db->quote($itemObject->date_add))
                      ->where($db->quoteName('id_cart') . ' = ' . $db->quote($this->cartId))
                      ->where($db->quoteName('id_item') . ' = ' . $db->quote($itemId));

                // Set the query and execute it
                $db->setQuery($query);
                $db->execute();
            } else {
                $db->insertObject($this->cart_items_table, $itemObject);
            }
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }

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
            $total += $item->price['price_with_tax'];
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


    public function updateQuantity($quantity,$itemId) {

        if($itemId<=0) {
            $this->app->enqueueMessage('Item id for updateQuantity is invalid');
            return false;
        }

        if($this->cartId<=0){
            $this->app->enqueueMessage('Cart id for updateQuantity is invalid');
            return false;
        }

        if($quantity < 0 ) { $quantity = 0; }

        // $cartItems= $this->getCartItems(); // list is always updated so we dont need this for now

        $db = $this->db;

        $query = $db->getQuery(true);

        // Fields to update.
        $fields = array(
            $db->quoteName('quantity') . ' = ' . floatval($quantity)
        );

        // Conditions for which records should be updated.
        $conditions = array(
            $db->quoteName('id_item') . ' = ' . intval($itemId), 
            $db->quoteName('id_cart') . ' = ' . intval($this->cartId)
        );


        if($quantity > 0){
            $query->update($db->quoteName($this->cart_items_table))->set($fields)->where($conditions);
        }else{
            $query->delete($db->quoteName($this->cart_items_table))->where($conditions);
        }
        

        try {
            $db->setQuery($query);
            $result = $db->execute();

        }catch (\Exception $e) {

            $this->app->enqueueMessage($e->getMessage());
            return false;

        }

        // Refresh the cart items after updating the database
        $this->cart->items = $this->getCartItems();

        return true;
    }

}