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
     * Returns a reference to the a Table object, always creating it.
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
     * @return  \JForm|boolean  A \JForm object on success, false on failure
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
    public function save($data)
    {
        $app = Factory::getApplication();

        if (!parent::save($data)) return false;

        $orderId = 0;
        if ($data['id'] > 0) { //not a new
            $orderId = intval($data['id']);
        } else { // is new
            $orderId = intval($this->getState($this->getName() . '.id'));//get the id from setted joomla state
        }


        $this->setItems($orderId, $data['items']);

        return true;
    }

    public function setItems($orderId, $items)
    {

        if (!is_array($items) || $orderId <= 0) {
            return false;
        }

        $db = $this->getDatabase();


        $query = $db->getQuery(true);
        $query->select('id')
            ->from('#__alfa_order_items')
            ->where('id_order = ' . intval($orderId));
        $db->setQuery($query);
        $existingItemIds = $db->loadColumn();  // Array of existing item IDs

        $incomingIds = array();
        foreach ($items as $item) {
            if (isset($item['id']) && intval($item['id']) > 0) {
                $incomingIds[] = intval($item['id']);
            }
        }

        $idsToDelete = array_diff($existingItemIds, $incomingIds);

        if (!empty($idsToDelete)) {
            $query = $db->getQuery(true);
            $query->delete('#__alfa_order_items')->whereIn('id', $idsToDelete);
            $db->setQuery($query);
            $db->execute();
        }


        foreach ($items as $item) {
            $itemObject = new \stdClass();
            $itemObject->id = isset($item['id']) ? intval($item['id']) : 0;
            $itemObject->id_order = $orderId;
            $itemObject->id_item = isset($item['id_item']) ? intval($item['id_item']) : 0;
            $itemObject->quantity = isset($item['quantity']) ? floatval($item['quantity']) : 1;
            $itemObject->price = isset($item['price']) ? floatval($item['price']) : 0;

            $price_calculate_type = isset($item['price_calculate_type']) ? true : false;

            $userGroupId = 1;
            $currencyId = 1;

            // set the name of the item
            if (!($item_table = $this->getTable('Item'))) continue;

            $item_table->load($itemObject->id_item);
            $itemObject->name = $item_table->name;

            $query = $db->getQuery(true);

            if ($itemObject->id_item <= 0) continue;

            // set the price of the item
            if ($price_calculate_type || $itemObject->id <= 0) {
                $itemPriceCalculator = new PriceCalculator($itemObject->id_item, $itemObject->quantity, $userGroupId, $currencyId);
                $itemPrice = $itemPriceCalculator->calculatePrice();
                $itemObject->total = $itemPrice['base_price'];
            } else {
                $itemObject->total = $itemObject->quantity * floatval($item['price']);
            }

            if ($itemObject->id > 0 && in_array($itemObject->id, $existingItemIds)) {
                $db->updateObject('#__alfa_order_items', $itemObject, 'id', true);
            } else {
                $db->insertObject('#__alfa_order_items', $itemObject);
            }

        }

        return true;
    }
}
