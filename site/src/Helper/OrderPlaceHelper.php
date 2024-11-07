<?php

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Alfa\Component\Alfa\Site\Helper\CartHelper;
use \Joomla\Utilities\IpHelper;

class OrderPlaceHelper
{
    protected $db;
    protected $app;
    protected $user;
    protected $cart;

    protected $order_table = '#__alfa_orders';
    protected $order_items_table = '#__alfa_order_items';
    protected $order_user_info_table = '#__alfa_order_user_info';

    public function __construct()
    {
        $this->app = Factory::getApplication();
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->user = $this->app->getIdentity();
        $this->cart = new CartHelper();
    }

    // Place an order
    public function placeOrder()
    {
        // Retrieve cart items
        $cartData = $this->cart->getData();
        $cartItems = $cartData->items;

        if (empty($cartItems)) {
            $this->app->enqueueMessage("Cart is empty, cannot place order!");
            return false;
        }

        //1.CHECK AND INSERT USER user info in #__alfa_user_info
        // TODO: MAKE IT ONE WITH UPDATE USER INFO by checking the variables we get
        $user_object = $this->saveUserInfo();
        if (empty($user_object)) {
            $this->app->enqueueMessage("User info not inserted!");
            return false;
        }

        //2.CHECK AND INSERT ORDER
        // main order row in #__alfa_orders
        $order_object = $this->saveOrder();

        if (empty($order_object)) {
            $this->app->enqueueMessage("Order not inserted!");
            return false;
        }

        // order items in #__alfa_order_items
        if (!$this->saveOrderItems($order_object->id)) {
            $this->app->enqueueMessage("Order items not inserted!");
            return false;
        }

        //4.UPDATE USER user info in #__alfa_user_info
        // TODO: make it one with saveUserInfo by checking before every variable we have to insert is valid
        if (!$this->updateUserInfo($user_object,$order_object->id)) {
            $this->app->enqueueMessage("User info not updated!");
            return false;
        }

        // 3. CLEAR CART NO ERROR OCCURED
        if (!$this->cart->clearCart()) {
            $this->app->enqueueMessage("Cart not cleared!");
        }

        return true;
    }

    protected function saveOrder()
    {
        $db = $this->db;
        $cartHelper = $this->cart;

        $order_object = new \stdClass();
        $order_object->id_user_group = 1;
        $order_object->id_user = $this->user->id;
        $order_object->id_cart = $cartHelper->getCartId();
        $order_object->id_currency = 1;
        $order_object->id_address_delivery = 1;
        $order_object->id_address_invoice = 1;
        $order_object->id_paymentmethod = 1;
        $order_object->id_shipmentmethod = 1;
        $order_object->id_order_status = 1;
        $order_object->id_payment_currency = 1;
        $order_object->id_language = 1;
        $order_object->id_shipping_carrier = 1;
        $order_object->id_coupon = 1;
        $order_object->code_coupon = '1';
        $order_object->original_price = $cartHelper->getTotal();
        $order_object->payed_price = $cartHelper->getTotal();
        $order_object->total_shipping = 0.00;
        $order_object->ip_address = IpHelper::getIp();
        $order_object->shipping_tracking_number = '1';
        $order_object->payment_status = '1';
        $order_object->customer_note = '1';
        $order_object->note = '';
        $order_object->checked_out = 0;
        $order_object->checked_out_time = Factory::getDate()->toSql();
        $order_object->modified = Factory::getDate()->toSql();
        $order_object->modified_by = 0;
        $order_object->created = Factory::getDate()->toSql();
        $order_object->created_by = $this->user->id;

        try {
            $db->insertObject($this->order_table, $order_object, 'id');
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return null;
        }

        return $order_object;
    }

    protected function saveOrderItems($orderId)
    {
        $db = $this->db;
        $cartHelper = $this->cart;
        $cartItems = $cartHelper->getData()->items;

        foreach ($cartItems as $item) {
            $item_object = new \stdClass();
            $item_object->id_item = $item->id;
            $item_object->id_order = $orderId;
            $item_object->id_shipmentmethod = 0;
            $item_object->name = $item->name;
            $item_object->total = $item->price['base_price'];
            $item_object->quantity = $item->quantity;
            $item_object->quantity_removed = 0;

            try {
                $db->insertObject($this->order_items_table, $item_object, 'id');
            } catch (\Exception $e) {
                $this->app->enqueueMessage($e->getMessage());
                return false;
            }
        }

        return true;
    }

    protected function saveUserInfo()
    {
        $db = $this->db;

        $info_object = new \stdClass();
        $info_object->id_order = 0;
        $info_object->name = $_POST['name'] ?? '';
        $info_object->email = $_POST['email'] ?? '';
        $info_object->shipping_address = $_POST['shipping_address'] ?? '';
        $info_object->city = $_POST['city'] ?? '';
        $info_object->state = $_POST['state'] ?? '';
        $info_object->zip_code = $_POST['zip_code'] ?? '';

        try {
            $db->insertObject($this->order_user_info_table, $info_object, 'id');
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return null;
        }
        return $info_object;
    }

    // TODO: make it one with saveUserInfo
    protected function updateUserInfo($userObject, $orderId)
    {
        $db = $this->db;

        $info_object = new \stdClass();
        $info_object->id = $userObject->id;
        $info_object->id_order = $orderId;

        try {
            $db->updateObject($this->order_user_info_table, $info_object, 'id');
        } catch (\Exception $e) {
            $this->app->enqueueMessage($e->getMessage());
            return false;
        }
        return true;
    }
}
