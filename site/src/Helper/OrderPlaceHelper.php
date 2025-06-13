<?php

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\FormFields\ValidateFieldEvent as FormFieldValidateEvent;

use Alfa\Component\Alfa\Administrator\Event\Payments\OrderPlaceEvent as PaymentOrderPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\OrderPlaceEvent as ShipmentOrderPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderAfterPlaceEvent as PaymentOrderAfterPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\OrderAfterPlaceEvent as ShipmentOrderAfterPlaceEvent;
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
    protected $shipment_type = '';

	protected $order_table = '#__alfa_orders';
	protected $order_items_table = '#__alfa_order_items';
	protected $order_user_info_table = '#__alfa_user_info';

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
	public function placeOrder($data)
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

        // Same for shipment method.
        if (!self::checkShipmentMethod($this->app->input->getInt('shipment_method', null)))
        {
            $this->app->enqueueMessage("Invalid shipment method selected.");
            return false;
        }

        $cartData->id_shipment = $this->app->input->getInt('shipment_method');

        // alfa-plugins onOrderBeforePlace event calls.
        $onOrderBeforePlaceEventName = "onOrderBeforePlace";


        // TODO: get shipment method from the database ??
        // TODO: return true false to be able to stop the proccess
        // Payments.
		self::getPaymentType($this->app->input->getInt('payment_method'));
        $paymentOrderPlaceEvent = new PaymentOrderPlaceEvent($onOrderBeforePlaceEventName, [
            "subject" => $this->cart
        ]);
		$this->app->bootPlugin($this->payment_type, "alfa-payments")->{$onOrderBeforePlaceEventName}($paymentOrderPlaceEvent);
        $this->cart = $paymentOrderPlaceEvent->getCart();

        // Shipments.
        self::getShipmentType($this->app->input->getInt('shipment_method'));
        $shipmentOrderPlaceEvent = new ShipmentOrderPlaceEvent($onOrderBeforePlaceEventName, [
            "subject" => $this->cart
        ]);
        $this->app->bootPlugin($this->shipment_type, "alfa-shipments")->{$onOrderBeforePlaceEventName}($shipmentOrderPlaceEvent);
        $this->cart = $shipmentOrderPlaceEvent->getCart();


        // SAVE ORDER USER INFO
        $userInfoObject = $this->saveUserInfo($data);
        if ($userInfoObject != NULL){
            // Update cart with its user info.
            $this->cart->getData()->id_user_info_delivery = $userInfoObject->id;
        }
        else
        {
            $this->app->enqueueMessage("User info not inserted!");
            return false;
        }


		// SAVE ORDER MAIN INFO
		if (!($orderId = $this->saveOrder()))
		{
			$this->app->enqueueMessage("Order not inserted!");
			return false;
		}


		// TODO: transaction start end commit and rollback

		// SAVE ORDER ITEMS
		if (!$this->saveOrderItems())
		{
			$this->app->enqueueMessage("Order items not inserted!");
			return false;
		}

		
		// fetch the order from the admin order model
		$orderModel = $this->app->bootComponent('com_alfa')
				->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

		if (!$orderModel)
		{
			$this->app->enqueueMessage('Order Place Helper: Order Model not loaded successfully to get the order!', 'error');
			return false;
		}

        $this->order = $orderModel->getItem($orderId);


		// AFTER PLACE.
		$onOrderAfterPlaceEventName = "onOrderAfterPlace";
        $paymentOrderAfterPlaceEvent = new PaymentOrderAfterPlaceEvent($onOrderAfterPlaceEventName, [
           "subject" => $this->order
        ]);
		$this->app->bootPlugin($this->payment_type, "alfa-payments")->{$onOrderAfterPlaceEventName}($paymentOrderAfterPlaceEvent);
        $this->order = $paymentOrderAfterPlaceEvent->getOrder();

        $shipmentOrderAfterPlaceEvent = new ShipmentOrderAfterPlaceEvent($onOrderAfterPlaceEventName, [
            "subject" => $this->order
        ]);
        $this->app->bootPlugin($this->shipment_type, "alfa-shipments")->{$onOrderAfterPlaceEventName}($shipmentOrderAfterPlaceEvent);
        $this->order = $shipmentOrderAfterPlaceEvent->getOrder();


		// CLEAR CART NO ERROR OCCURRED
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
        $cartData = $cartHelper->getData();


		// Payment method's id has been validated from placeOrder().
		$payment_method_id = $this->app->input->getInt('payment_method', '1');
        $shipment_method_id = $this->app->input->getInt('shipment_method', '1');
		// $query = $db->getQuery(true);
		// $query->
		//     select('name')->
		//     from('#__alfa_payments')->
		//     where('id=' . $db->quote($payment_method_id));
		// $db->setQuery($query);
		// $payment_type = $db->loadResult();

		// TODO: Get currency id some other way.
		$config     = ComponentHelper::getParams('com_alfa');
		$currencyNumber = $config->get("default_currency", 978);
        $currencyID = self::getCurrencyID($currencyNumber);

        $currentDate = Factory::getDate('now','UTC');

		$order_object                      = new \stdClass();
		$order_object->id_user_group       = 1;
		$order_object->id_user             = $this->user->id;
		$order_object->id_cart             = $cartHelper->getCartId();
		$order_object->id_currency         = $currencyID;
		$order_object->id_address_delivery = $cartData->id_user_info_delivery;
		$order_object->id_address_invoice  = $cartData->id_user_info_invoice;
		$order_object->id_payment_method    = $payment_method_id;   //ID of payment method.
//		$order_object->payment_type = $payment_type;
		$order_object->id_shipment_method        = $shipment_method_id;
		$order_object->id_order_status          = 1;
		$order_object->id_payment_currency      = 1;
		$order_object->id_language              = 1;
		$order_object->id_shipping_carrier      = 1;
		$order_object->id_coupon                = 1;
		$order_object->code_coupon              = '1';
		$order_object->original_price           = $cartHelper->getTotal();
		$order_object->payed_price              = $cartHelper->getTotal();
		$order_object->total_shipping           = 0;//$cartData->shipment_costs_total ?: 0;
		$order_object->ip_address               = IpHelper::getIp();
		// $order_object->shipping_tracking_number = '1';
		$order_object->payment_status           = '1';
		$order_object->customer_note            = '1';
		$order_object->note                     = '';
		$order_object->checked_out              = 0;
		$order_object->checked_out_time         = $currentDate->toSql(false);
		$order_object->modified                 = $currentDate->toSql(false);
		$order_object->modified_by              = 0;
		$order_object->created                  = $currentDate->toSql(false);
		$order_object->created_by               = $this->user->id;

		try
		{
			$db->insertObject($this->order_table, $order_object, 'id'); //the third 'id' value inserts in the order_object the auto increment inserted id
			// $orderId = $db->insertid();
		}
		catch (\Exception $e)
		{
			$this->app->enqueueMessage($e->getMessage());

			return false;
		}

		 $this->order     = $order_object;
		 $this->order->id = $order_object->id;

//        echo "<pre>";
//        print_r($this->order);
//        echo "</pre>";
//        exit;


		return $this->order->id;
	}

	protected function saveOrderItems()
	{
		$db         = $this->db;
		$cartHelper = $this->cart;
		$cartItems  = $cartHelper->getData()->items;

		foreach ($cartItems as $item)
        {
			$item_object                    = new \stdClass();
			$item_object->id_item           = $item->id_item;
			$item_object->id_order          = $this->order->id;
			$item_object->id_shipmentmethod = 0;
            $item_object->product_name      = $item->name;  // Change here.
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
	 * @param   int  $paymentID  The id of the submitted payment.
	 * @return bool True if the id is one of a valid payment method, false if not.
	 */
	protected function checkPaymentMethod($paymentID = null): bool
	{

        foreach($this->cart->getPaymentMethods() as $method)
            if($method->id == $paymentID)
                return true;

        return false;

	}

    protected function checkShipmentMethod($shipmentID = null): bool
    {

        foreach($this->cart->getShipmentMethods() as $method)
            if($method->id == $shipmentID)
                return true;

        return false;

    }



	/**
	 *  Saves the user's info in the database.
	 *
	 * @return object with data submitted, or null.
	 */
	protected function saveUserInfo($data)
	{
		$db    = $this->db;
	
        $info_object = (object) $data;
        $info_object->id_user = $this->user->id;

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


    protected function getShipmentType($shipmentID)
    {
        $query = $this->db->getQuery(true);

        $query
            ->select("type")
            ->from("#__alfa_shipments")
            ->where("id=" . $shipmentID);

        $this->db->setQuery($query);

        $this->shipment_type = $this->db->loadResult();

    }

    protected function getCurrencyID($currencyNumber)
    {
        $db = Factory::getContainer()->get("DatabaseDriver");
        $query = $db->getQuery(true);

        $query
            ->select('id')
            ->from('#__alfa_currencies')
            ->where('number=' . $currencyNumber);

        $db->setQuery($query);
        return $db->loadResult();
    }



}
