<?php

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use \Alfa\Component\Alfa\Site\Helper\CartHelper;
use \Joomla\Utilities\IpHelper;

class OrderPlaceHelper
{
	protected $db;
	protected $app;
	protected $user;
	protected $cart = null;
	protected $order = null;
	protected $payment_type = '';

	protected $order_table = '#__alfa_orders';
	protected $order_items_table = '#__alfa_order_items';
	protected $order_user_info_table = '#__alfa_order_user_info';

	public function __construct()
	{
		$this->app  = Factory::getApplication();
		$this->db   = Factory::getContainer()->get('DatabaseDriver');
		$this->user = $this->app->getIdentity();
		$this->cart = new CartHelper();
	}

	public function getOrder()
	{
		return $this->order;
	}

	public function getCart(): ?CartHelper
	{
		return $this->cart;
	}

	// Place an order
	public function placeOrder()
	{
		// Retrieve cart items
		$cartData  = $this->cart->getData();
		$cartItems = $cartData->items;


		if (empty($cartItems))
		{
			$this->app->enqueueMessage("Cart is empty, cannot place order!");

			return false;
		}

		// If no records are returned, the user attempted to use an invalid payment method.
		if (!self::checkPaymentMethod($this->app->input->getInt('payment_method', null)))
		{
			$this->app->enqueueMessage("Invalid payment method selected.");
			return false;
		}

		/*
		 *  alfa-payments onOrderBeforeSave event calls.
		 */

		// BEFORE SAVE.
		self::getPaymentType($this->app->input->getInt('payment_method'));

		$onOrderBeforeSaveEventName = "onOrderBeforeSave";
		$this->app->bootPlugin($this->payment_type, "alfa-payments")->{$onOrderBeforeSaveEventName}($this->cart);

		// SAVE ORDER MAIN INFO
		if (!$this->saveOrder())
		{
			$this->app->enqueueMessage("Order not inserted!");

			return false;
		}

		// SAVE ORDER ITEMS
		if (!$this->saveOrderItems())
		{
			$this->app->enqueueMessage("Order items not inserted!");

			return false;
		}

		// SAVE ORDER USER INFO
		if (!$this->saveUserInfo())
		{
			$this->app->enqueueMessage("User info not inserted!");

			return false;
		}

		// AFTER SAVE.
		$onOrderAfterSaveEventName = "onOrderAfterSave";
		$this->app->bootPlugin($this->payment_type, "alfa-payments");

		// CLEAR CART NO ERROR OCCURED
		if (!$this->cart->clearCart())
		{
			$this->app->enqueueMessage("Cart not cleared!");
		}

		return true;
	}

	protected function saveOrder()
	{
		$db         = $this->db;
		$cartHelper = $this->cart;

		// //Payment method's id has been validated from placeOrder().
		$payment_method_id = $this->app->input->getInt('payment_method', '1');
		// $query = $db->getQuery(true);
		// $query->
		//     select('name')->
		//     from('#__alfa_payments')->
		//     where('id=' . $db->quote($payment_method_id));
		// $db->setQuery($query);
		// $payment_type = $db->loadResult();

		// TODO: Get currency id some other way.
		$config     = ComponentHelper::getParams('com_alfa');
		$currencyID = $config->get("default_currency", 978);

		$order_object                      = new \stdClass();
		$order_object->id_user_group       = 1;
		$order_object->id_user             = $this->user->id;
		$order_object->id_cart             = $cartHelper->getCartId();
		$order_object->id_currency         = $currencyID;
		$order_object->id_address_delivery = 1;
		$order_object->id_address_invoice  = 1;
		$order_object->id_paymentmethod    = $payment_method_id;   //ID of payment method.
		// $order_object->payment_type = $payment_type;
		$order_object->id_shipmentmethod        = 1;
		$order_object->id_order_status          = 1;
		$order_object->id_payment_currency      = 1;
		$order_object->id_language              = 1;
		$order_object->id_shipping_carrier      = 1;
		$order_object->id_coupon                = 1;
		$order_object->code_coupon              = '1';
		$order_object->original_price           = $cartHelper->getTotal();
		$order_object->payed_price              = $cartHelper->getTotal();
		$order_object->total_shipping           = 0.00;
		$order_object->ip_address               = IpHelper::getIp();
		$order_object->shipping_tracking_number = '1';
		$order_object->payment_status           = '1';
		$order_object->customer_note            = '1';
		$order_object->note                     = '';
		$order_object->checked_out              = 0;
		$order_object->checked_out_time         = Factory::getDate()->toSql();
		$order_object->modified                 = Factory::getDate()->toSql();
		$order_object->modified_by              = 0;
		$order_object->created                  = Factory::getDate()->toSql();
		$order_object->created_by               = $this->user->id;

		try
		{
			$db->insertObject($this->order_table, $order_object, 'id'); //the third 'id' value inserts in the order_object the auto increment inserted id
			$orderId = $db->insertid();
		}
		catch (\Exception $e)
		{
			$this->app->enqueueMessage($e->getMessage());

			return false;
		}

		$this->order     = $order_object;
		$this->order->id = $orderId;


		return true;
	}

	protected function saveOrderItems()
	{
		$db         = $this->db;
		$cartHelper = $this->cart;
		$cartItems  = $cartHelper->getData()->items;

//        echo "<pre>";
//        print_r($cartItems);
//        echo "</pre>";
//        exit;


		foreach ($cartItems as $item)
		{

//            echo "<pre>";
//            print_r($item);
//            echo "</pre>";
//            exit;

			$item_object                    = new \stdClass();
			$item_object->id_item           = $item->id_item;
			$item_object->id_order          = $this->order->id;
			$item_object->id_shipmentmethod = 0;
			$item_object->name              = $item->name;
			$item_object->total             = $item->price['base_price'];
			$item_object->quantity          = $item->quantity;
			$item_object->quantity_removed  = 0;

			try
			{
				$db->insertObject($this->order_items_table, $item_object, 'id');
			}
			catch (\Exception $e)
			{
				$this->app->enqueueMessage($e->getMessage());

				return false;
			}
		}

		return true;
	}


	/**
	 *  Checks if the payment id submitted by the user is valid.
	 *
	 * @param   int  $paymentID  The id of the submitted payment.
	 *
	 * @return bool True if the id is one of a valid payment method, false if not.
	 */
	protected function checkPaymentMethod($paymentID = null): bool
	{

        foreach($this->cart->getPaymentMethods() as $method)
            if($method->id == $paymentID)
                return true;

        return false;

	}


	/**
	 *  Saves the user's info in the database.
	 *
	 * @return object with data submitted, or null.
	 */
	protected function saveUserInfo()
	{
		$db    = $this->db;
		$input = $this->app->input;

		$info_object                   = new \stdClass();
		$info_object->id_order         = $this->order ? $this->order->id : 0;
		$info_object->name             = $input->get('name') ?? '';
		$info_object->email            = $input->get('email') ?? '';
		$info_object->shipping_address = $input->get('shipping_address') ?? '';
		$info_object->city             = $input->get('city') ?? '';
		$info_object->state            = $input->get('state') ?? '';
		$info_object->zip_code         = $input->get('zip_code') ?? '';


		try
		{
			$db->insertObject($this->order_user_info_table, $info_object, 'id');
		}
		catch (\Exception $e)
		{
			$this->app->enqueueMessage($e->getMessage());

			return null;
		}

		return $info_object;
	}

	/**
	 * @param $paymentID int The order's payment method id.
	 *
	 * @return void
	 */
	protected function getPaymentType($paymentID)
	{
		$query = $this->db->getQuery(true);

		$query
			->select("type")
			->from("#__alfa_payments")
			->where("id=" . $paymentID);

		$this->db->setQuery($query);

		$this->payment_type = $this->db->loadResult();

	}

}





