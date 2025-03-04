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

use \Joomla\CMS\Table\Table;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\MVC\Model\AdminModel;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Filter\OutputFilter;
use \Joomla\CMS\Event\Model;
use \Joomla\CMS\Component\ComponentHelper;
use \Joomla\CMS\Form;

use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Alfa\Component\Alfa\Administrator\Helper\OrderHelper;


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
    public function getTable($type = 'Order', $prefix = 'Administrator', $config = array())
    {
        return parent::getTable($type, $prefix, $config);
    }

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interrogate.
     * @param boolean $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return  JForm|boolean  A \JForm object on success, false on failure
     *
     * @since   1.0.1
     */
    public function getForm($data = array(), $loadData = true)
    {
//        exit;
        // Initialise variables.
        $app = Factory::getApplication();

        // Get the form.
        $form = $this->loadForm(
            'com_alfa.order',
            'order',
            array(
                'control' => 'jform',
                'load_data' => $loadData
            )
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

        /*
         *  alfa-payments onAdminOrderPrepareForm event call.
         */

        $onAdminOrderPrepareFormEventName = "onAdminOrderPrepareForm";

        $orderPaymentType = self::getOrderPaymentData($this->item->id_paymentmethod)->type;
        Factory::getApplication()->bootPlugin($orderPaymentType, "alfa-payments")->{$onAdminOrderPrepareFormEventName}($form, $this->item);


        //TODO: payments subform field disable some fields if are previous
        //TODO: add deleted products inside items subform field id_item sql/list field

        // change data of subform field
        // $decodedDateRanges = json_decode($data->date_ranges, true);
        // $form->setValue('date_ranges', null, $decodedDateRanges);


        // $orderPaymentsField = $form->getField('order_payments');
        // // Loop through each form element in the subform
        // foreach ($orderPaymentsField->getChildren() as $childField) {
        //     // Set each child field to be disabled.
        //     $childField->setAttribute('disabled', 'disabled');
        // }

        // print_r($form->getField('order_payments'));
        // exit;
        // $decodedPayments = json_decode($this->item->order_payments, true);

        // print_r($this->item->order_payments);
        // exit;

        // subform.order_payments
        // $paymentsSubform = &$form->getField('order_payments')->loadSubForm();


        // $subForm->setFieldAttribute('order_payments0.payment_method', 'readonly', 'true');

        // $fieldInfo = [];
        //->getFieldset() returns all the fields

        // foreach ($paymentsSubform->getFieldset() as $fieldName => &$field)
        // {      

        //     $paymentsSubform->setFieldAttribute($fieldName, 'disabled', 'true')

        // }


        // $form->setFieldAttribute('order_payments.order_payments0.amount','readonly', 'false');
        // $form->setFieldAttribute('order_payments.order_payments0.payment_method','readonly', 'false');
        // Get all fields in the subform and set them as disabled
        // foreach ($paymentsSubform->getFieldset() as $fieldName => $field) {

        //     print_r($fieldName);
        //     echo '<br>';
        //     // $paymentsSubform->setFieldAttribute($fieldName, 'disabled', 'true');
        // }

        // exit;
        // foreach ($fieldInfo as $field  => $group) {
        //     // Case using the advkontent users manager in admin
        //     // if (\in_array($formName, ['com_advkontent.user', 'com_users.user', 'com_users.registration', 'com_users.profile'])) {
        //     //     if ($this->params->get('uep_required_' . $field, 1) == 0) {
        //             $uepSubform->removeField($field, $group);
        //         // } elseif ($this->params->get('uep_required_' . $field, 0) == 1) {
        //             // $uepSubform->setFieldAttribute($field, 'required', false, $group);
        //         // } elseif ($this->params->get('uep_required_' . $field, 1) == 2) {
        //         //     $uepSubform->setFieldAttribute($field, 'required', true, $group);
        //         // }
        //     }
        // }

// exit;
        // $paymentsSubform = $form->getField('order_payments');

        // $paymentsSubform->setFieldAttribute('order_payments0.payment_method','readonly',true);

        //jform[order_payments][order_payments0][payment_method]


        // print_r($form->loadSubFormData($subformField));

        // $control  = 'subform.order_payments' . '[' . $subForm->fieldname . $i . ']';
        // $itemForm = Form::getInstance($subForm->getName() . $i, $subForm->formsource, ['control' => $control]);

        // $c = 1;

        //  for ($i = 0; $i < $c; $i++) {

        //      $control  = $subForm->getName() . '[' . $subForm->fieldname . $i . ']';
        //      $itemForm = Form::getInstance($subForm->getName() . $i, $subForm->formsource, ['control' => $control]);

        //          // Disable specific fields inside the subform
        //      foreach ($itemForm->getFieldset() as $field) {
        //          if (in_array($field->fieldname, ['amount', 'date_add'])) { // Replace with actual field names
        //              $field->setAttribute('disabled', 'true');
        //          }
        //      }

        //      // print_r($itemForm->getAttribute('name', 'disabled'))
        //      // if (!empty($value[$i])) {
        //      //     $itemForm->bind($value[$i]);
        //      // }

        //      // $forms[] = $itemForm;
        //  }

        // print_r($forms);
        // print_r($subformField->getFieldAttribute('amount','value'));
        // exit;

        // Disable specific fields by iterating through the subform fields
        // foreach ($subformField->getFields() as $field) {
        //     if ($field->name == 'amount') {  // Replace with the field name you want to disable
        //         $field->disabled = true; // Disable the field
        //     }
        // }


        // $subformData = $form->loadSubFormData($subformField);

        // print_r($subformData);
        // exit;
        // foreach ($subformField->getFieldsets() as $fieldset) {

        //     $idItemField = $form->getField($fieldset->name . '.amount');

        //     // echo 'hey';
        //     print_r($idItemField);

        // }
        //  exit;
       // $itemsField = $form->getField('items')->loadSubForm();

        // TODO: load the deleted item value and name in the sql item_id subform field


        // $dataItems = json_decode($data->date_ranges, true);
        // $form->setValue('items', null, []);
        // $form->getField('items')->loadSubForm()->getField('id_item')->addOption('foo', ['value' => 'bar']);

// $subForm = $form->getField('items.id_item')->addOption('foo', ['value' => 'bar']);

        // Load the subform
// $subForm = $form->getField('items')->loadSubForm();

// // Check if the subform is loaded correctly
// if ($subForm) {
//     // Access the field within the subform
//     $field = $subForm->getField('id_item');

//     // Add the option to the field
//     $field->addOption('foo', ['value' => 'bar']);
// } else {
//     // Handle the case where the subform could not be loaded
//     throw new \Exception('Subform could not be loaded.');
// }


       // Access the 'items' subform field
        // $itemsField = $form->getField('items');

        // if ($itemsField) {
        //     // Load the subform associated with 'items'
        //     $subForm = $itemsField->loadSubForm();

        //     if ($subForm) {
        //         // Access the 'id_item' field inside the subform
        //         $idItemField = $subForm->getField('id_item');

        //         $idItemField->getValue()
        //         if ($idItemField) {
        //             // Add a custom option to 'id_item'
        //             $idItemField->addOption(Text::_('COM_ALFA_FORM_CUSTOM_ITEM'), ['value' => '-1']);
        //         }
        //     }
        // }

        // $field = $form->getField('id_item', 'items');
        // if ($itemsField) {

        //     $id_item = $itemsField->getField('id_item');

        //     if($id_item){
        //         // $options = $id_item->getOptions();

        //         $customOptionQuery = "SELECT id, name FROM #__alfa_items WHERE id=0)";

        //         $id_item->__set( 'query', $customOptionQuery);

        //         // $options = $id_item->setOptions([]);
        //     }

        //     // print_r($id_item);
        //     echo 'FIELD FOUND';
        // }else{
        //     echo 'FIELD NOT FOUND';
        // }
        // echo '<pre>';
        // print_r($form->getValue('items'));
        // echo '</pre>';

        // $data['items'] = '{}';
        // if (!empty($data->items) && is_array($data->items)) {

        //     foreach ($data->items as &$item) {

        //         // set the name of the item
        //         if (!($item_table = $this->getTable('Item'))) continue;

        //         if (!$item_table->load($item->id_item)) {

        //         }

        //     }
        // }

        // Assuming you have a method to fetch existing items from the database or other sources
        // Here, you can add your custom items

//	    $customItems = array(
//	        (object) array('id_item' => 3, 'name' => 'Custom Item 1'),
//	        (object) array('id_item' => 4, 'name' => 'Custom Item 2'),
//	    );

        // Get the subform field
//        $subformField = $form->getField('items')->loadSubForm();
//        foreach ($subformField->getFieldsets() as $fieldset) {
//
//            $idItemField = $form->getField($fieldset->name . '.id_item');
//
//            echo 'hey';
//            print_r($idItemField);
//            exit;
//        }
//        $subformField->
//        print_r(var_dump($subformField->loadSubForm()));
//        exit;


//        print_r($field);
//        exit;
        // Add custom items to the $data->items array
//	    foreach ($customItems as $customItem) {
//	        $data->items[] = $customItem;
//	    }
        // print_r($this->form->getFieldAttribute('items'));
        // exit;

        // $this->loadFormData();

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
//        exit;
        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState('com_alfa.edit.order.data', array());

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;

        }

        // echo '<pre>';
        // print_r($data);
        // echo '</pre>';
        // exit;

        // print_r($data->items);
        // exit;

        // Check if items exist in the form data
//	    if (!empty($data->items) && is_array($data->items))
//	    {
//
//	        foreach ($data->items as &$item)
//	        {
//
//            	// set the name of the item
//		        if (! ($item_table = $this->getTable('Item')) ) continue;
//
//		        if( !$item_table->load($item->id_item)){
////		        	 $item->id_item = 3;  // This works but only for the selected values from the db
///
//		        	// print_r($item->id_item);
//		        	// exit;

//        Want to do something like this - put the deleted record in the sql list to be visible
//		        	// $item->id_item = array(
//			        //     'value' => $item->id_item,
//			        //     'text'  => 'deleted item name',
//			        // );

//		        }
//
//	        }
//	    }


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
            $order->order_payments = $this->getOrderPayments($order->id);

            // GET ORDER ITEMS
            $order->items = OrderHelper::getOrderItems($order->id);

            foreach ($order->items as $order_item) {
                $order_item->price = $order_item->total / $order_item->quantity;//calculate the price per unit
            }

            // GET USER DETAILS
            $query = $db->getQuery(true);
            $query->select('*');
            $query->from('#__alfa_order_user_info');
            $query->where('id_order = ' . $db->quote($order->id));
            $db->setQuery($query);
            $user_info_result = $db->loadObject();

            if ($user_info_result) {
                $order->user_email = $user_info_result->email;
                $order->user_name = $user_info_result->name;
                $order->shipping_address = $user_info_result->shipping_address;
            }

            // GET PAYMENT DATA
            $paymentModel = Factory::getApplication()->bootComponent('com_alfa')
                ->getMVCFactory()->createModel('Payment', 'Administrator', ['ignore_request' => true]);

            $order->payment = $paymentModel->getItem($order->id_paymentmethod);

            // to be shown in the administration form
            $order->payment_name = $order->payment->name;
            $order->payment_type = $order->payment->type;

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
        $paymentType = $this->getOrderPaymentData($data['id_paymentmethod'])->type;
        $canSave = $app->bootPlugin($paymentType, "alfa-payments")->{$onBeforeSaveEventName}($data);

        if (!$canSave) {
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

        // Get previous order data.
//        echo "<pre>";
//        print_r($previousData);
//        echo "</pre>";
//        exit;

        if (!parent::save($data)) return false;

        $orderId = $data['id'] > 0 ? intval($data['id']) : intval($this->getState($this->getName() . '.id'));

        // Attempt to set payments associated with the order
        if (!$this->setOrderPayments($orderId, $data['order_payments'])) {
            $app->enqueueMessage("Could not save order payments.", "error");
            return false;
        }

        if (!OrderHelper::setOrderItems($orderId, $data, $previousData)) {
            $app->enqueueMessage("Could not save order items.", "error");
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
        $app->bootPlugin($paymentType, "alfa-payments")->{$onAfterSaveEventName}($data);

        return true;
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
        $incomingIds = array();
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
            $paymentObject->date_add = !empty($payment['date_add']) ? Factory::getDate($payment['date_add'])->toSql() : NULL;
            $paymentObject->id_user = isset($payment['id_user']) ? intval($payment['id_user']) : 0;
            // echo '<pre>';
            // print_r($paymentObject);
            // echo '</pre><br/>';
            // continue;

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

        $onAdminOrderDeleteEventName = "onAdminOrderDelete";
        $ordersPaymentType = self::getPaymentTypes($pks);

        foreach ($ordersPaymentType as $i => $order) {
            $deleteEntry = $app->bootPlugin($order->payment_type, "alfa-payments")->{$onAdminOrderDeleteEventName}($order->order_id);
            if (!$deleteEntry)
                unset($pks[$i]);

        }

        if (empty($pks))
            return true;

        // Deleting order items.
        $query = $db->getQuery(true);
        $query
            ->delete($db->quoteName("#__alfa_order_items"))
            ->whereIN("id_order", $pks);
        $db->setQuery($query);
        $db->execute();


        // Deleting order user info.
        $query = $db->getQuery(true);
        $query
            ->delete($db->quoteName("#__alfa_order_user_info"))
            ->whereIN("id_order", $pks);
        $db->setQuery($query);
        $db->execute();


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

}