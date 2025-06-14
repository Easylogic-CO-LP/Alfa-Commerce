<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderPrepareFormEvent as ShipmentOrderPrepareFormEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderBeforeSaveEvent as ShipmentBeforeSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderAfterSaveEvent as ShipmentAfterSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderDeleteEvent as ShipmentBeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderPrepareFormEvent as PaymentOrderPrepareFormEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderBeforeSaveEvent as PaymentBeforeSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderAfterSaveEvent as PaymentAfterSaveEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderDeleteEvent as PaymentBeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Component\ComponentHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderHelper;

/**
 * Order model.
 *
 * @since  1.0.1
 */
class OrderModel extends AdminModel
{
    /**
     * @var    string  The prefix to use with controller messages.
     *
     * @since  1.0.1
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var    string  Alias to manage history control
     *
     * @since  1.0.1
     */
    public $typeAlias = 'com_alfa.order';

    protected $formName = 'order';

    /**
     * @var    null  Item data
     *
     * @since  1.0.1
     */
    protected $item = null;

    /**
     * Returns a reference to the Table object, always creating it.
     *
     * @param string $type The table type to instantiate
     * @param string $prefix A prefix for the table class name. Optional.
     * @param array $config Configuration array for model. Optional.
     *
     * @return  Table    A database object
     *
     * @since   1.0.1
     */
    public function getTable($type = 'Order', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    public function getShipmentForm($data = [], $loadData = true)
    {

        $form = $this->loadForm(
            'com_alfa.order',
            'order_shipments',
            [
                'control' => 'jform',
                'load_data' => false//$loadData custom bind of data
            ]
        );

        if (empty($form)) {
            return false;
        }

        //        $form->bind();

        $app = Factory::getApplication();

        // Get ID of the article from input
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        // On edit order, we get ID of order from order.id state, but on save, we use data from input
        $id = (int)$this->getState('order_shipments.id', $idFromInput);
        $orderId = (int)$this->getState('order.id', $orderIdFromInput);

        if ($id > 0) {
            $shipment = $this->getShipmentData($id);
        }

        // Set IDs if they're not present in $data.
        if (is_array($data)) {
            if (!isset($data["id"])) {
                $data["id"] = $id;
            }
            if (!isset($data["id_order"])) {
                $data["id_order"] = $orderId;
            }
        }

        $form->bind($data);

        $order = $this->getItem($orderId);  //or from $shipment->id_order

        //	    $shipmentPrepareFormEvent = new ShipmentOrderPrepareFormEvent($onAdminOrderPrepareFormEventName, [
        //		    "subject" => $form,
        //		    "method" => $shipment,
        //		    "data" => $order
        //	    ]);

        if ($id > 0 && isset($shipment->params)) {

            $onAdminOrderPrepareFormEventName = "onAdminOrderShipmentPrepareForm";
            $shipmentPrepareFormEvent = new ShipmentOrderPrepareFormEvent($onAdminOrderPrepareFormEventName, [
                "subject" => $form,
                "method" => $shipment,
                "data" => $order
            ]);

            $orderShipmentType = $shipment->params->type;
            Factory::getApplication()->bootPlugin($orderShipmentType, "alfa-shipments")
                ->{$onAdminOrderPrepareFormEventName}($shipmentPrepareFormEvent);
        }


        // Order and form should be editable by the events.
        //	    $this->item = $shipmentPrepareFormEvent->getData();
        //	    $form = $shipmentPrepareFormEvent->getForm();

        return $form;
    }

    public function getShipmentData($pk = null)
    {

        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();

        $query = $db->getQuery(true);

        $query
            ->select('a.*')
            ->from($db->quoteName('#__alfa_order_shipments', 'a'))
            ->where($db->quoteName('a.id') . ' = '. $db->quote($pk));

        $db->setQuery($query);
        $shipment = $db->loadObject();

        $shipmentModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Shipment', 'Administrator', ['ignore_request' => true]);
        $shipment->params = $shipmentModel->getItem($shipment->id_shipment_method);

        return $shipment;

    }

    public function getPaymentForm($data = [], $loadData = true)
    {

        $form = $this->loadForm(
            'com_alfa.order',
            'order_payments',
            [
                'control' => 'jform',
                'load_data' => $loadData
            ]
        );

        if (empty($form)) {
            return false;
        }

        $app = Factory::getApplication();

        // Get ID of the article from input
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        // On edit order, we get ID of order from order.id state, but on save, we use data from input
        $id = (int)$this->getState('order_payments.id', $idFromInput);
        $orderId = (int)$this->getState('order.id', $orderIdFromInput);

        if ($id > 0) {
            $payment = $this->getPaymentData($id);
        }

        // Set IDs if they're not present in $data.
        if (is_array($data)) {
            if (!isset($data["id"])) {
                $data["id"] = $id;
            }
            if (!isset($data["id_order"])) {
                $data["id_order"] = $orderId;
            }
        }

        $form->bind($data);


        $order = $this->getItem($orderId);//or from $payment->id_order

        if ($id > 0 && isset($payment->params)) {

            $onAdminOrderPrepareFormEventName = "onAdminOrderPaymentPrepareForm";
            $paymentPrepareFormEvent = new PaymentOrderPrepareFormEvent($onAdminOrderPrepareFormEventName, [
                "subject" => $form,
                "method" => $payment,
                "data" => $order
            ]);

            $orderPaymentType = $payment->params->type;
            Factory::getApplication()->bootPlugin($orderPaymentType, "alfa-payments")
                ->{$onAdminOrderPrepareFormEventName}($paymentPrepareFormEvent);
        }


        // Order and form should be editable by the events.
        //	    $this->item = $paymentPrepareFormEvent->getData();
        //	    $form = $paymentPrepareFormEvent->getForm();

        return $form;
    }

    public function getPaymentData($pk = null)
    {

        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();

        $query = $db->getQuery(true);

        $query
            ->select('a.*')
            ->from($db->quoteName('#__alfa_order_payments', 'a'))
            ->where($db->quoteName('a.id') . ' = '. $db->quote($pk));

        $db->setQuery($query);
        $payment = $db->loadObject();

        $paymentModel = Factory::getApplication()->bootComponent('com_alfa')
            ->getMVCFactory()->createModel('Payment', 'Administrator', ['ignore_request' => true]);
        $payment->params = $paymentModel->getItem($payment->id_payment_method);

        return $payment;

    }


    /**
     * Stock method to auto-populate the model state.
     *
     * @return  void
     *
     * @since   1.6
     */
    protected function populateState()
    {
        parent::populateState();

        $input = Factory::getApplication()->getInput();
        $id_order = $input->get('id_order', null, 'STRING');

        // if id_order exists means we opened order_payments or order_shipments
        if ($id_order !== null) {
            $this->setState($this->getName() . '.id', $id_order);
        }
    }

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interrogate.
     * @param boolean $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return  \Joomla\CMS\Form\Form|boolean  A \JForm object on success, false on failure
     *
     * @since   1.0.1
     */
    public function getForm($data = [], $loadData = true)
    {

        // Initialise variables.
        $app = Factory::getApplication();

        // Get the form.
        $form = $this->loadForm(
            'com_alfa.order',
            'order',
            [
                'control' => 'jform',
                'load_data' => $loadData
            ]
        );

        if (empty($form)) {
            return false;
        }

        // Get ID of the article from input
        $idFromInput = $app->getInput()->getInt('id', 0);

        // On edit order, we get ID of order from order.id state, but on save, we use data from input
        $id = (int)$this->getState($this->formName . '.id', $idFromInput);

        $this->item = $this->getItem($id);

        // $record->id = $id;

        // For new orders we allow editing on user fields
        if ($id == 0) {
            $form->setFieldAttribute('user_email', 'readonly', 'false');
            $form->setFieldAttribute('user_email', 'class', '');

            $form->setFieldAttribute('user_name', 'readonly', 'false');
            $form->setFieldAttribute('user_name', 'class', '');
        }

        // Get user info form.
        //        $formFieldsModel = Factory::getApplication()->bootComponent('com_alfa')
        //            ->getMVCFactory()->createModel('Formfield', 'Administrator', ['ignore_request' => true]);

        //        echo "<pre>";
        //        $fields = FieldsHelper::getFields("com_alfa.order");
        //
        //        echo "<pre>";
        //        print_r($fields);
        //        echo "</pre>";
        //        exit;

        //        foreach($formFieldForm->getFieldsets() as $fieldset){
        //            print_r($fieldset);
        //        }
        //        echo "</pre>";
        //        exit;

        FieldsHelper::prepareForm('com_alfa.order', $form, $data);       // Comes in as "fields-0".

        // TODO: TO BE PUTTED MAYBE INSIDE PREPARE FORM AND PASS DATA VIA $data
        foreach ($form->getFieldsets() as $fieldset) {
            if (!str_starts_with($fieldset->name, 'fields-')) {
                continue;
            }

            foreach ($form->getFieldset($fieldset->name) as $field) {
                $fieldName = str_replace(["jform[com_alfa][", "]"], "", $field->name);
                $form->setValue($fieldName, 'com_alfa', $this->item->user_info->{$fieldName} ?? '');
            }
        }

        return $form;
    }


    /**
     * Method to get the data that should be injected in the form.
     *
     * @return  mixed  The data for the form.
     *
     * @since   1.0.1
     */
    protected function loadFormData()
    {

        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState('com_alfa.edit.order.data', []);

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
                if (empty($this->item)) {
                    $this->item = [];
                }
            }
            $data = $this->item;
        }

        return $data;
    }

    /**
     * Method to get a single record.
     *
     * @param integer $pk The id of the primary key.
     *
     * @return  mixed    Object on success, false on failure.
     *
     * @since   1.0.1
     */
    public function getItem($pk = null)
    {

        if ($order = parent::getItem($pk)) {

            $db = $this->getDatabase();

            // GET ORDER PAYMENTS
            //	        $order->payments = [];
            //	        $order->shipments = [];
            //            $order->order_payments = $this->getOrderPayments($order->id);
            //            $order->order_shipments = $this->getOrderShipments($order->id);

            // GET ORDER ITEMS
            $order->items = OrderHelper::getOrderItems($order->id);

            foreach ($order->items as $order_item) {
                $order_item->price = $order_item->total / $order_item->quantity;//calculate the price per unit
            }

            // HERE: Associating order with user info via id_user_info_invoice/id_user_info_delivery.

            // GET USER DETAILS
            $query = $db->getQuery(true);
            $query->select('*');
            $query->from('#__alfa_user_info');
            $query->where('id = ' . $db->quote($order->id_address_delivery));
            $db->setQuery($query);
            $user_info_result = $db->loadObject();

            if ($user_info_result) {
                $order->user_info = $user_info_result;
                //                $order->user_email = $user_info_result->email;
                //                $order->user_name = $user_info_result->name;
                //                $order->shipping_address = $user_info_result->shipping_address;
                //                $order->city = $user_info_result->city;
                //                $order->zip_code = $user_info_result->zip_code;
                //                $order->state_province = $user_info_result->state;
            }

            // Get all payment data
            $paymentMethods =  $this->getOrderPayments($order->id); // Gets an array of values

            $paymentModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Payment', 'Administrator', ['ignore_request' => true]);


            $order->payments = [];
            foreach ($paymentMethods as $paymentMethod) {
                $payment = new \stdClass();
                $payment = $paymentMethod;
                $payment->params = $paymentModel->getItem($paymentMethod->id_payment_method);
                $order->payments[] = $payment;
            }

            $order->selected_payment = $paymentModel->getItem($order->id_payment_method);

            // Get all shipment data
            $shipmentMethods =  $this->getOrderShipments($order->id); // Gets an array of values

            $shipmentModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Shipment', 'Administrator', ['ignore_request' => true]);

            foreach ($shipmentMethods as $shipmentMethod) {
                $shipment = new \stdClass();
                $shipment = $shipmentMethod;
                $shipment->params = $shipmentModel->getItem($shipmentMethod->id_shipment_method);

                $order->shipments[] = $shipment;
            }

            $order->selected_shipment = $shipmentModel->getItem($order->id_shipment_method);

            // GET CURRENCY DATA
            $query = $db->getQuery(true);
            $query
                ->select("*")
                ->from("#__alfa_currencies")
                ->where("id=" . intval($order->id_currency));
            $db->setQuery($query);
            $order->currency_data = $db->loadObject();

            $this->item = $order;

            return $order;
        }

        return null;
    }

    /**
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return  boolean  True on success, False on error.
     *
     * @since   1.6
     */

    public function save($data)
    {

        $app = Factory::getApplication();

        // Setting up the alfa-payments event calls.
        // BEFORE SAVE.
        $onBeforeSaveEventName = "onAdminOrderBeforeSave";

        $paymentType = $this->getOrderPaymentData($data['id_payment_method'])->type; // ???? Data missing on form submission.
        // Probably changed things when we changed the way shipments and payments show.


        // TODO: Needs work as $data is an array but event expects an object. We also need to pass $data
        //       by reference.
        $onPaymentBeforeSaveEvent = new PaymentBeforeSaveEvent($onBeforeSaveEventName, [
            'subject' => json_decode(json_encode($data))
        ]);

        $app->bootPlugin($paymentType, "alfa-payments")->{$onBeforeSaveEventName}($onPaymentBeforeSaveEvent);
        $paymentCanSave = $onPaymentBeforeSaveEvent->getCanSave();

        if (!$paymentCanSave) {
            return false;
        }

        // TODO: UPDATE FOR SHIPMENTS.
        $onShipmentBeforeSaveEvent = new ShipmentBeforeSaveEvent($onBeforeSaveEventName, [
            'subject' => json_decode(json_encode($data))
        ]);
        $shipmentType = $this->getOrderShipmentData($data['id_shipment_method'])->type;
        $app->bootPlugin($shipmentType, "alfa-shipments")->{$onBeforeSaveEventName}($onShipmentBeforeSaveEvent);
        $shipmentCanSave = $onShipmentBeforeSaveEvent->getCanSave();

        if (!$shipmentCanSave) {
            return false;
        }

        // Load order statuses to check stock action
        //        $this->orderStatuses = AlfaHelper::getOrderStatuses();
        //
        //        $newOderStatus = $data['id_order_status'];
        //        if ($this->orderStatuses[$newOderStatus]->stock_action == 0) {
        //            $this->keepInStock = true;
        //        }

        // Create a table object to manage order data
        $table = $this->getTable();

        // Load existing data to revert if needed
        $previousData = [];
        if (isset($data['id']) && $data['id'] > 0) {
            $table->load(['id' => intval($data['id'])]);
            $previousData = $table->getProperties();
        }

        if (!parent::save($data)) {
            return false;
        }

        $orderId = $data['id'] > 0 ? intval($data['id']) : intval($this->getState($this->getName() . '.id'));


        //        echo "<pre>";
        //        print_r($data);
        //        echo "</pre>";
        //        exit;

        // Attempt to set payments associated with the order
        //        if (!$this->setOrderPayments($orderId, $data['order_payments']))
        //        {
        //            $app->enqueueMessage("Could not save order payments.", "error");
        //            return false;
        //        }

        // Save order's items.
        if (!OrderHelper::setOrderItems($orderId, $data, $previousData)) {
            $app->enqueueMessage("Could not save order items.", "error");
            return false;
        }

        // Attempt to set shipments associated with the order
        //        if (!$this->setOrderShipments($orderId, $data['order_shipments']))
        //        {
        //            $app->enqueueMessage("Could not save order shipments.", "error");
        //            return false;
        //        }

        // Attempt to save user info associated with the order.
        if (!OrderHelper::saveUserInfo($data["id_address_delivery"], $data["com_alfa"])) {
            $app->enqueueMessage("Could not save user info.", "error");
            return false;
        }


        // Attempt to set items associated with the order
        // if (!$this->setOrderItems($orderId, $data)) {
        //     // Revert the data to the previous state
        //     if (!empty($previousData)) {
        //         $table->bind($previousData);
        //         if (!$table->store()) {
        //             // Set error if reverting fails
        //             $app->enqueueMessage('Failed to revert the order back to previous data.', 'error');
        //         }else{
        //             $app->enqueueMessage('The order has been reverted to its previous state. You may review the changes, make further edits and save again.', 'warning');
        //         }
        //     }

        //     return false;
        // }

        // AFTER SAVE.
        $onAfterSaveEventName = "onAdminOrderAfterSave";
        $onAfterSavePaymentEvent = new PaymentAfterSaveEvent($onAfterSaveEventName, [
            'subject' => json_decode(json_encode($data))
        ]);

        $app->bootPlugin($paymentType, "alfa-payments")->{$onAfterSaveEventName}($onAfterSavePaymentEvent);


        // Shipments after save.
        $onAfterSaveShipmentEvent = new ShipmentAfterSaveEvent($onBeforeSaveEventName, [
            'subject' => json_decode(json_encode($data))
        ]);
        $app->bootPlugin($shipmentType, "alfa-shipments")->{$onAfterSaveEventName}($onAfterSaveShipmentEvent);


        return true;
    }


    public function setOrderShipments($orderID, $data)
    {
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        // Insert or update for each object of $data.
        foreach ($data as $shipment) {
            $shipment["id_order"] = $orderID;
            $shipment = json_decode(json_encode($shipment));

            if (!empty($shipment->id)) {
                $db->updateObject('#__alfa_order_shipments', $shipment, 'id', true);
            } else {
                $db->insertObject('#__alfa_order_shipments', $shipment);
            }
        }

        return true;

    }

    // Get order shipments.
    public function getOrderShipments($orderId)
    {
        // Get order items
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*');
        $query->from('#__alfa_order_shipments');
        $query->where('id_order = ' . $db->quote($orderId));
        $db->setQuery($query);
        $items = $db->loadObjectList();//for the subform

        return $items;

    }



    // GET AND SET ORDER PAYMENTS

    public function getOrderPayments($orderId)
    {

        // Get order items
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*');
        $query->from('#__alfa_order_payments');
        $query->where('id_order = ' . $db->quote($orderId));

        $db->setQuery($query);
        $items = $db->loadObjectList();//for the subform

        return $items;

    }

    /**
     * @param $orderId int The id of the order given.
     * @param $data array Contains data about the order.
     * @return bool True, if saving was successful, false if not.
     * @throws \Exception
     */
    public function setOrderPayments($orderId, $orderPayments): bool
    {
        $app = Factory::getApplication();

        // Invalid order id.
        if ($orderId <= 0) {
            $app->enqueueMessage("Invalid order id.", "error");
            return false;
        }

        // Get older payments.
        $db = $this->getDatabase();
        $query = $db->getQuery(true);
        $query->select('id')
            ->from('#__alfa_order_payments')
            ->where('id_order = ' . intval($orderId));
        $db->setQuery($query);
        $prevOrderPaymentIDs = $db->loadColumn();  // Array of existing payments.

        // Extract incoming IDs from the $prices array
        $incomingIds = [];
        foreach ($orderPayments as $payment) {
            if (isset($payment['id']) && intval($payment['id']) > 0) {//not those except new with id 0
                $incomingIds[] = intval($payment['id']);
            }
        }

        // Find differences
        $idsToDelete = array_diff($prevOrderPaymentIDs, $incomingIds);

        //  Delete records that are no longer present in incoming prices array
        if (!empty($idsToDelete)) {
            $query = $db->getQuery(true);
            $query->delete('#__alfa_order_payments')->whereIn('id', $idsToDelete);
            $db->setQuery($query);
            $db->execute();
        }

        // Get default currency.
        $component_params = ComponentHelper::getParams('com_alfa');
        $currency_id = $component_params->get('default_currency', 47);//47 is euro with number 978

        foreach ($orderPayments as $payment) {

            $paymentObject = new \stdClass();
            $paymentObject->id = isset($payment['id']) ? intval($payment['id']) : 0;
            $paymentObject->id_order = $orderId;
            $paymentObject->id_currency = isset($payment['id_currency']) ? intval($payment['id_currency']) : $currency_id;
            $paymentObject->amount = isset($payment['amount']) ? floatval($payment['amount']) : 0.0;
            $paymentObject->id_payment_method = isset($payment['id_payment_method']) ? intval($payment['id_payment_method']) : 0;
            $paymentObject->conversion_rate = isset($payment['conversion_rate']) ? floatval($payment['conversion_rate']) : 0.0;
            $paymentObject->transaction_id = isset($payment['transaction_id']) ? $payment['transaction_id'] : '';
            $paymentObject->date_add = !empty($payment['date_add']) ? Factory::getDate($payment['date_add'])->toSql() : null;
            $paymentObject->id_user = isset($payment['id_user']) ? intval($payment['id_user']) : 0;

            if ($paymentObject->id > 0 && in_array($paymentObject->id, $prevOrderPaymentIDs)) {
                $updateNulls = true;
                $db->updateObject('#__alfa_order_payments', $paymentObject, 'id', $updateNulls);
            } else {
                $db->insertObject('#__alfa_order_payments', $paymentObject);
            }

        }

        // exit;
        $app->enqueueMessage('Payments saved.', 'info');

        return true;

    }


    public function delete(&$pks)
    {

        $app = Factory::getApplication();
        $db = $this->getDatabase();

        foreach ($pks as $pk) {
            $orders[] = self::getItem($pk);
        }

        $onAdminOrderDeleteEventName = "onAdminOrderDelete";
        foreach ($orders as $i => $order) {
            $paymentDeleteOrderDataEvent = new PaymentBeforeDeleteEvent($onAdminOrderDeleteEventName, [
                "subject" => $order,
                "method" => $order->payment
            ]);

            $app->bootPlugin($order->payment_type, "alfa-payments")
                ->{$onAdminOrderDeleteEventName}($paymentDeleteOrderDataEvent);
            $deleteEntry = $paymentDeleteOrderDataEvent->getCanDelete();

            if ($deleteEntry) {
                $shipmentDeleteOrderDataEvent = new ShipmentBeforeDeleteEvent($onAdminOrderDeleteEventName, [
                    "subject" => $order,
                    "method" => $order->shipment
                ]);

                $app->bootPlugin($order->shipment_type, "alfa-shipments")
                    ->{$onAdminOrderDeleteEventName}($shipmentDeleteOrderDataEvent);
                $deleteEntry = $shipmentDeleteOrderDataEvent->getCanDelete();
            }

            if (!$deleteEntry) {
                unset($pks[$i]);
            }

        }

        // TODO: delete shipment data also by booting the plugin

        if (empty($pks)) {
            return true;
        }

        // Deleting order items.
        $query = $db->getQuery(true);
        $query
            ->delete($db->quoteName("#__alfa_order_items"))
            ->whereIn("id_order", $pks);
        $db->setQuery($query);
        $db->execute();

        //        try {
        // Deleting non-user user info.
        $ids = implode(',', array_map([$db, 'quote'], $pks));

        $subQuery1 = 'SELECT ' . $db->qn('id_address_delivery') .
            ' FROM ' . $db->qn('#__alfa_orders') .
            ' WHERE ' . $db->qn('id') . ' IN (' . $ids . ')';

        $subQuery2 = 'SELECT ' . $db->qn('id_address_invoice') .
            ' FROM ' . $db->qn('#__alfa_orders') .
            ' WHERE ' . $db->qn('id') . ' IN (' . $ids . ')';

        $query = $db->getQuery(true)
            ->delete($db->qn('#__alfa_user_info'))
            ->where('(' . $db->qn('id') . ' IN (' . $subQuery1 . ') OR ' . $db->qn('id') . ' IN (' . $subQuery2 . '))')
            ->where($db->qn('id_user') . ' = 0');

        $db->setQuery($query);
        $db->execute();

        //        }
        //        catch(\Exception $e){
        //            echo $e->getMessage();
        //            echo "<br>QUERY:" . $query;
        //        }

        // Deleting user info of non-user entries.
        // Get
        //        $query
        //            ->select("id_address_delivery, id_address_invoice")
        //            ->from("#__alfa_orders")
        //            ->whereIn("id", $pks);
        //
        //        $query = $db->getQuery(true);
        //        $query
        //            ->delete($db->quoteName("#__alfa_order_user_info"))
        //            ->whereIN("id_order", $pks);
        //        $db->setQuery($query);
        //        $db->execute();


        // Now check to see if this articles was featured if so delete it from the #__content_frontpage table
        //
        // $query = $db->getQuery(true)
        //     ->delete($db->quoteName('#__content_frontpage'))
        //     ->whereIn($db->quoteName('content_id'), $pks);
        // $db->setQuery($query);
        // $db->execute();


        // $query->delete($db->quoteName('#__user_profiles'));
        // $query->where($conditions);

        // $db->setQuery($query);
        // ms0bn_alfa_order_items
        // ms0bn_alfa_order_user_info

        // alfa-payments onAdminOrderDelete event call.


        $result = parent::delete($pks);
        return $result;
    }

    protected function getOrderShipmentData($shipmentID)
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true);

        $query
            ->select("*")
            ->from("#__alfa_shipments")
            ->where("id=" . $shipmentID);

        $db->setQuery($query);

        return $db->loadObject();

    }

    protected function getOrderPaymentData($paymentID)
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true);

        $query
            ->select("*")
            ->from("#__alfa_payments")
            ->where("id=" . $paymentID);

        $db->setQuery($query);

        return $db->loadObject();

    }

    /**
     * @param $pks primary keys of to be deleted orders.
     * @return array of objects containing the primary key of the order and its payment type.
     */
    protected function getPaymentTypes(&$pks)
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true);

        $query
            ->select('o.id as order_id, p.type as payment_type')
            ->from($db->quoteName('#__alfa_orders', 'o'))
            ->leftJoin($db->quoteName('#__alfa_payments', 'p') . ' ON ' . $db->quoteName('o.id_paymentmethod') . ' = ' . $db->quoteName('p.id'))
            ->whereIn($db->quoteName('o.id'), $pks);

        $db->setQuery($query);
        $result = $db->loadObjectList();
        return $result;

    }

    protected function getShipmentTypes(&$pks)
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true);

        $query
            ->select("o.id as order_id, s.type as shipment_type")
            ->from($db->quoteName("#__alfa_orders", "o"))
            ->leftJoin($db->qn("#__alfa_shipments", "s") . " ON " . $db->qn("o.id_shipmentmethod") . " = " . $db->qn("s.id"))
            ->whereIn($db->qn("o.id"), $pks);

        $db->setQuery($query);
        $result = $db->loadObjectList();
        return $result;

    }


    // TODO: we will need to save shipment's items, so a separation between saveOrderShipment and saveOrderPayment is necessary.
    public function saveOrderShipment(&$data): bool
    {
        // TODO: Add alfa-shipments event call here.
        $saveData = $data;
        if (isset($saveData["shipment"])) {
            unset($saveData["shipment"]);
        }

        /* TODO: Should update order's total amount based on plugin's onCalculateShippingCost. */

        $updateSuccessful = self::saveDataOnTable($saveData, "#__alfa_order_shipments");

        // Using the newly-inserted ID as the shipment's id.
        if ($updateSuccessful) {
            $data["id"] = $saveData["id"];
        }

        return $updateSuccessful;
    }

    public function saveOrderPayment(&$data): bool
    {
        // TODO: Add an alfa-payments event call here.
        $saveData = $data;
        if (isset($saveData["payment"])) {
            unset($saveData["payment"]);
        }

        // Update order's paid amount.
        $addAmount = 0;
        if ($data["id"] != 0) {    // Existing order.
            $oldPayment = self::getOrderPayment($data["id"]);
            $addAmount = $data["amount"] - $oldPayment->amount;
        } else { // New order.
            $addAmount = $data["amount"];
        }

        if ($addAmount != 0) {
            self::updateOrderAmountPaid($data["id_order"], $addAmount);
        }

        $updateSuccessful = self::saveDataOnTable($saveData, "#__alfa_order_payments");

        // Using the newly-inserted ID as the shipment's id.
        if ($updateSuccessful) {
            $data["id"] = $saveData["id"];
        }

        return $updateSuccessful;
    }

    public function deleteOrderShipment($id, $id_order)
    {
        $deletedCorrectly = self::deleteTableEntry("#__alfa_order_shipments", $id);
        return $deletedCorrectly;
    }

    public function deleteOrderPayment($id, $id_order)
    {
        // Remove paid amount from order.
        $oldPayment = self::getOrderPayment($id);
        if ($oldPayment->amount != 0) {
            self::updateOrderAmountPaid($id_order, ($oldPayment->amount * -1));
        }

        $deletedCorrectly = self::deleteTableEntry("#__alfa_order_payments", $id);
        return $deletedCorrectly;
    }



    protected function deleteTableEntry($tableName, $id)
    {
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        $query
            ->delete($db->qn($tableName))
            ->where($db->qn("id") . "=" .  $id);

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), "error");
            return false;
        }
        return true;
    }

    protected function saveDataOnTable(&$data, $tableName): bool
    {
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        // Quote columns/values.
        $columns = array_map([$db, "qn"], array_keys($data));
        $values = array_map([$db, "quote"], $data);

        $query = "REPLACE INTO " . $db->qn($tableName)
            . "(" . implode(",", $columns) . ")"
            . " VALUES (" . implode(",", $values) . ")";

        try {
            $db->setQuery($query);
            $db->execute();

            $data["id"] = $db->insertid(); // Set the row's PK in case a new row was inserted.
        } catch (\Exception $e) {
            echo "CAUGHT: " . $e->getMessage();
            Factory::getApplication()->enqueueMessage($e->getMessage(), "error");
            return false;
        }

        return true;
    }

    /**
     *  Updates the order's amount_paid value by adding the $amountAdded's value (can be a negative number).
     *  @param $orderID int the order's ID.
     *  @param $amountAdded int the amount to add (can be negative).
     *  @return void
     */
    protected function updateOrderAmountPaid($orderID, $amountAdded): bool
    {
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        $query
            ->update($db->qn("#__alfa_orders"))
            ->set($db->qn("payed_price") . "=" . $db->qn("payed_price") . "+" . $db->quote($amountAdded))
            ->where($db->qn("id") . "=" . $db->quote($orderID));

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), "error");
            return false;
        }
        return true;
    }

    protected function getOrderPayment($id)
    {
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        $query
            ->select("*")
            ->from($db->qn("#__alfa_order_payments"))
            ->where($db->qn("id") . "=" . $id);

        $db->setQuery($query);

        return $db->loadObject();
    }

}
