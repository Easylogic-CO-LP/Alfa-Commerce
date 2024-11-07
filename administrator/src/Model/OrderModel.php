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
use \Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Joomla\CMS\Component\ComponentHelper;

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
     * @param array $data An optional array of data for the form to interogate.
     * @param boolean $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return  JForm|boolean  A \JForm object on success, false on failure
     *
     * @since   1.0.1
     */
    public function getForm($data = array(), $loadData = true)
    {
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
        $orderIdFromInput = $app->getInput()->getInt('id', 0);

        // On edit order, we get ID of order from order.id state, but on save, we use data from input
        $id = (int)$this->getState('order.id', $orderIdFromInput);

        // $record->id = $id;

        // For new orders we allow editing on user fields
        if ($id == 0) {
            $form->setFieldAttribute('user_email', 'readonly', 'false');
            $form->setFieldAttribute('user_email', 'class', '');

            $form->setFieldAttribute('user_name', 'readonly', 'false');
            $form->setFieldAttribute('user_name', 'class', '');
        }


//        $subformField = $form->getField('items')->loadSubForm();

        // TODO: load the deleted item value and name in the sql item_id subform field

        if (!empty($data->items) && is_array($data->items)) {

            foreach ($data->items as &$item) {

                // set the name of the item
                if (!($item_table = $this->getTable('Item'))) continue;

                if (!$item_table->load($item->id_item)) {
//                    $subformField->setFieldAttribute("id_item", 'type', 'hidden');
//                    $item->deleted_item_name = $item->name;

//                    echo $item->name.'is deleted';
//                    exit;
//                    $data->deleted_item_name = 'test';
                    // print_r($item->id_item);
                    // exit;
                    // $item->id_item = array(
                    //     'value' => $item->id_item,
                    //     'text'  => 'blabla',
                    // );
//		        	 $item->id_item = 3;  // Set your custom value here
                }

            }
        }

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

//	     $this->loadFormData($data);


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
        $data = Factory::getApplication()->getUserState('com_alfa.edit.order.data', array());

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;

        }

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
            // Do any procesing on fields here if needed

            $db = $this->getDatabase();
            $query = $db->getQuery(true);

            // Get user details
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

            // Get order items
            $query = $db->getQuery(true);

            $query->select('*');
            $query->from('#__alfa_order_items');
            $query->where('id_order = ' . $db->quote($order->id));

            $db->setQuery($query);

            $order->items = $db->loadObjectList();//for the subform

            // echo '<pre>';
            // print_r($order->items);
            // echo '</pre>';
            // exit;

            foreach ($order->items as $order_item) {
                $order_item->price = $order_item->total / $order_item->quantity;//calculate the price per unit
            }

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
    protected $orderStatuses = [];
    protected $keepInStock = false;

    public function save($data)
    {
        $app = Factory::getApplication();

        // Load order statuses to check stock action
        $this->orderStatuses = AlfaHelper::getOrderStatuses();

        $newOderStatus = $data['id_order_status'];
        if ($this->orderStatuses[$newOderStatus]->stock_action == 0) {
            $this->keepInStock = true;
        }

        // Create a table object to manage order data
        $table = $this->getTable();

        // Load existing data to revert if needed
        $previousData = [];
        if (isset($data['id']) && $data['id'] > 0) {
            $table->load(['id' => intval($data['id'])]);
            $previousData = $table->getProperties();
        }

       
        if (!parent::save($data)) return false;

        $orderId = $data['id'] > 0 ? intval($data['id']) : intval($this->getState($this->getName() . '.id'));

        
        // Attempt to set items associated with the order
        if (!$this->setItems($orderId, $data)) {
            // Revert the data to the previous state
            if (!empty($previousData)) {
                $table->bind($previousData);
                if (!$table->store()) {
                    // Set error if reverting fails
                    $app->enqueueMessage('Failed to revert the order back to previous data.', 'error');
                }else{
                    $app->enqueueMessage('The order has been reverted to its previous state. You may review the changes, make further edits and save again.', 'warning');
                }
            }

            return false;
        }


        return true;
    }



    public function setItems($orderId, $data)
    {
        $app = Factory::getApplication();

        $component_params = ComponentHelper::getParams('com_alfa');
        $manageStock = $component_params->get('manage_stock', 1);

        $items = $data['items'];

        if (!is_array($items) || $orderId <= 0) {
            return false;
        }

        $db = $this->getDatabase();


        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__alfa_order_items')
            ->where('id_order = ' . intval($orderId));
        $db->setQuery($query);
        $prevOrderTableItemsData = $db->loadAssocList('id');  // Array of existing items
        
        // Extract the 'id' values from $allOrderTableItemsData for comparison
        $prevOrderItemOrderIds = array_column($prevOrderTableItemsData, 'id');//the unique representation id of each item inside order_items table
        // Extract the 'id_item' values from $prevOrderTableItemsData for comparison
        $prevOrderItemIds = array_column($prevOrderTableItemsData, 'id_item');//the unique representation item_id of each item inside items table

        $currOrderItemIds = array();
        foreach ($items as $item) {
            if (isset($item['id']) && intval($item['id']) > 0) {
                $currOrderItemIds[] = intval($item['id_item']);
            }
        }

        // Get intersecting IDs (items present in both previous and current lists)
        $allOrderItemIds = $prevOrderItemIds + $currOrderItemIds;// or with array_unique(array_merge($prevOrderItemIds, $currOrderItemIds));

        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__alfa_items')
            ->whereIn('id', $allOrderItemIds);
        $db->setQuery($query);
        $allItemsTableData = $db->loadObjectList('id');  // Array of existing items


        $itemOrderObjectsTable = [];
        $itemObjectsTable = [];

        foreach ($items as $item) {
            $itemOrderObject = new \stdClass();
            $itemOrderObject->id = isset($item['id']) ? intval($item['id']) : 0;
            $itemOrderObject->id_order = $orderId;
            $itemOrderObject->id_item = isset($item['id_item']) ? intval($item['id_item']) : 0;
            $itemOrderObject->quantity = isset($item['quantity']) ? floatval($item['quantity']) : 1;
            $itemOrderObject->price = isset($item['price']) ? floatval($item['price']) : 0;
            $itemOrderObject->id_shipmentmethod = isset($item['id_shipmentmethod']) ? intval($item['id_shipmentmethod']) : 0;
            $itemOrderObject->quantity_removed = isset($prevOrderTableItemsData[$itemOrderObject->id])? $prevOrderTableItemsData[$itemOrderObject->id]['quantity_removed']: 0;

            $currentItemManageStock = $allItemsTableData[$itemOrderObject->id_item]->manage_stock == 2 ? $manageStock : $allItemsTableData[$itemOrderObject->id_item]->manage_stock;

            $price_calculate_type = isset($item['price_calculate_type']) ? true : false;

            // SET THE PRICE OF THE ITEM
            if ($price_calculate_type || $itemOrderObject->id <= 0) {
                $userGroupId = $currencyId = 1;
                $itemPriceCalculator = new PriceCalculator($itemOrderObject->id_item, $itemOrderObject->quantity, $userGroupId, $currencyId);
                $itemPrice = $itemPriceCalculator->calculatePrice();
                $itemOrderObject->total = $itemPrice['base_price'];
            } else {
                $itemOrderObject->total = $itemOrderObject->quantity * floatval($item['price']);
            }

            // SET THE QUANTITY
            if (isset($allItemsTableData[$itemOrderObject->id_item])) {//means item exists in items table database
                $itemObject = new \stdClass();
                $itemObject->id = $itemOrderObject->id_item;
                $itemObject->stock = $allItemsTableData[$itemOrderObject->id_item]->stock;

                if ($currentItemManageStock) {

                    if ($this->keepInStock ) {

                        $itemObject->stock += $itemOrderObject->quantity_removed;
                        $itemOrderObject->quantity_removed = 0;   

                    } else {

                        $stockDifferenceFromPrevious = ($itemOrderObject->quantity - $itemOrderObject->quantity_removed);


                        if ( $itemObject->stock < $stockDifferenceFromPrevious) {
                            $app->enqueueMessage('Order Items didnt change because.' . $allItemsTableData[$itemOrderObject->id_item]->name . ' quantity is greater than the available stock to be removed.Please fix the quantity of the item first', 'warning');
                            // revert the order status id to the previous
                            return false;//prevent any changes in items table and items order table
                        }
                        
                        if($stockDifferenceFromPrevious !== 0){
                            $itemObject->stock -= $stockDifferenceFromPrevious;
                            $itemOrderObject->quantity_removed = $itemOrderObject->quantity;
                        }

                    }

                }

                $itemObjectsTable[] = $itemObject;//to do all the queries later

            }

            // Add item order object to array for batch update or insert after loop
            $itemOrderObjectsTable[] = $itemOrderObject;

        }

        // HANDLE ALL DATABASE UPDATES IF NO ERROR OCCURED
        // Delete the removed items
        $idsToDelete = array_diff($prevOrderItemIds, $currOrderItemIds);

        if (!empty($idsToDelete)) {
            $query = $db->getQuery(true);
            $query->delete('#__alfa_order_items')->whereIn('id', $idsToDelete);
            $db->setQuery($query);
            $db->execute();

            // also restock them
            foreach ($idsToDelete as $idToDelete) {

                $itemToRestock = $prevOrderTableItemsData[$idToDelete];
                $itemOrderId = intval($itemToRestock['id']);
                $itemId = intval($itemToRestock['id_item']);
                $quantity_removed = floatval($itemToRestock['quantity_removed']);

                if (isset($prevOrderTableItemsData[$itemOrderId]) && isset($allItemsTableData[$itemId]) && $quantity_removed > 0) {
                    $query = $db->getQuery(true)
                        ->update('#__alfa_items')
                        ->set('stock = stock + ' . $quantity_removed)
                        ->where('id = ' . $itemId);
                    $db->setQuery($query);
                    $db->execute();
                }

            }

        }

        // Update items table
        foreach ($itemObjectsTable as $itemObject) {
            $db->updateObject('#__alfa_items', $itemObject, 'id', true);
        }

        // Update or insert order items table
        foreach ($itemOrderObjectsTable as $itemOrderObject) {
            if ($itemOrderObject->id > 0 && in_array($itemOrderObject->id, $prevOrderItemOrderIds)) {
                $db->updateObject('#__alfa_order_items', $itemOrderObject, 'id', true);
            } else {
                $db->insertObject('#__alfa_order_items', $itemOrderObject);
            }
        }

        return true;
    }

}