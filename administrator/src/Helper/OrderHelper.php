<?php

namespace Alfa\Component\Alfa\Administrator\Helper;

use Alfa\Component\Alfa\Site\Helper\PriceCalculator;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Plugin\Captcha\n3tMultiCaptcha\Exception;

class StockOperation
{
    public const REMOVE_STOCK = 0;
    public const RESTOCK_STOCK = 1;
}

class ManageStock{
    public const FROM_CONFIGURATION = -1;
    public const DONT_MANAGE = 0;
    public const MANAGE = 1;
}

class OrderHelper
{


    public static function getOrder($orderID = null)
    {

        if (empty($orderID)) {
            return null;
        }

        $ordersModel = Factory::getApplication()->bootComponent('com_alfa')
            ->getMVCFactory()->createModel('Order', 'Administrator', ['ignore_request' => true]);

        $order = $ordersModel->getItem($orderID);

        return $order;
    }

     static function getOrderItems($orderId = null)
    {
        // Get order items
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query->select([
                'oi.*',
                'i.name AS name'
            ]);
        // $query->from('#__alfa_order_items AS oi');
        $query->from($db->quoteName('#__alfa_order_items', 'oi'));
        $query->join('LEFT', $db->quoteName('#__alfa_items', 'i') . ' ON ' . $db->quoteName('oi.id_item') . ' = ' . $db->quoteName('i.id'));
        $query->where($db->quoteName('oi.id_order') . '=' . $db->quote($orderId));

        $db->setQuery($query);

        $items = $db->loadObjectList();//for the subform

        return $items;
    }



    public static function setOrderItems($orderId, $data, $previousOrderData){

//        echo "<pre>";
//        print_r($data);
//        echo "</pre>";
//
//        exit;
        // There can't be an order with no items.
        if (!is_array($data['items']) || $orderId <= 0) {
            return false;
        }

        $app = Factory::getApplication();
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Get the previous order's items.
        $prevOrderTableItemsData = self::getOlderOrderItems($orderId);

        // Extract the 'id' values from $allOrderTableItemsData for comparison
        $prevOrderItemPrimaryKeyIds = array_column($prevOrderTableItemsData, 'id');
        // Get previous and current items' details.
        $prevOrderItemIds = array_column($prevOrderTableItemsData, 'id_item');

        $currOrderItemIds = array();
        $currOrderItemIds = array_map(fn($item) => intval($item['id_item']), $data['items']);

        // Get IDs that are present in both previous and current lists
        $allOrderItemIds = array_merge($prevOrderItemIds, $currOrderItemIds);

        $allItemsData = self::getItemsWithGivenIDs($allOrderItemIds);

        // Get new items to be inserted.
        $configuredItems =  self::configureCurrentItems($orderId, $data, $prevOrderTableItemsData, $allItemsData, $previousOrderData);
        
        $toUpdateItems = $configuredItems['items'];
        $orderItems = $configuredItems['order_items'];

        if(empty($orderItems))
            return false;

        // Delete the removed items
        $idsToDelete = array_diff($prevOrderItemIds, $currOrderItemIds);
        if (!empty($idsToDelete)) {
            if (!self::deleteOrderItems($orderId, $idsToDelete, $prevOrderTableItemsData, $allItemsData)) {
                $app->enqueueMessage("Deleting items was unsuccessful.", "error");
                return false;
            }
        }

        // Update items' stock.
        foreach($toUpdateItems as $item) {
            $db->updateObject('#__alfa_items', $item, 'id', true);
        }

        // Update or insert order items table
        foreach ($orderItems as $itemOrderObject) {
            if ($itemOrderObject->id > 0 && in_array($itemOrderObject->id, $prevOrderItemPrimaryKeyIds)) {
                $db->updateObject('#__alfa_order_items', $itemOrderObject, 'id', true);
            } else {
                $db->insertObject('#__alfa_order_items', $itemOrderObject);
            }
        }

        return true;
    }


    // Delete order items from their table & replenish their stock.
    static function deleteOrderItems($orderId, $idsToDelete, &$prevOrderTableItemsData, &$allItemsTableData){

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Delete items from order_items table.
        $query = $db->getQuery(true);
        $query
            ->delete('#__alfa_order_items')
            ->where('id_order=' . $db->quote($orderId))
            ->whereIn('id_item', $idsToDelete);

        $db->setQuery($query);
        $db->execute();


        // Restock them.
        foreach ($idsToDelete as $idToDelete) {

            $itemToRestock = $prevOrderTableItemsData[$idToDelete];
            $itemOrderId = intval($itemToRestock['id']);
            $itemId = intval($itemToRestock['id_item']);
            $quantity = floatval($itemToRestock['quantity']);

            if (isset($prevOrderTableItemsData[$itemId]) && isset($allItemsTableData[$itemId]) && $quantity > 0) {
                $query = $db->getQuery(true)
                    ->update('#__alfa_items')
                    ->set('stock = stock + ' . $db->quote($quantity))
                    ->where('id = ' . $db->quote($itemId));
                $db->setQuery($query);
                $db->execute();
            }

        }

        return true;

    }



    // Get order's items based on given id.
    static function getOlderOrderItems($orderId){
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Get the previous order's items.
        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__alfa_order_items')
            ->where('id_order = ' . $db->quote(intval($orderId)));
        $db->setQuery($query);
        $prevOrderTableItemsData = $db->loadAssocList('id_item');  // Array of existing items

        return $prevOrderTableItemsData;

    }

    // Get items based on given ids.
    static function getItemsWithGivenIDs($ids){
        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__alfa_items')
            ->whereIn('id', $ids);
        $db->setQuery($query);
        $allItemsTableData = $db->loadObjectList('id');  // Array of existing items

        return $allItemsTableData;
    }


    /**
     *  Configures the order's items and returns them as objects. Also manages their quantity/stock properly.
     *  @param $orderId int The id of the order.
     *  @param $data array The given form data.
     *  @param $allItemsData array The data of all the items associated with the order.
     *  @return array The new order items, ready to be updated/inserted.
     *  @throws \Exception
     */
    static function configureCurrentItems($orderId, $data, $previousOrderItemsData, $allItemsData, $previousOrderData){

        if(empty($allItemsData))
            return null;

        $app = Factory::getApplication();
        $db = Factory::getContainer()->get("DatabaseDriver");
        $component_params = ComponentHelper::getParams('com_alfa');
        $generalManageStock = $component_params->get('manage_stock', 1);

        $orderItems = $data['items'];
        
        $orderStatuses = AlfaHelper::getOrderStatuses();

        $newOrderStatus = $orderStatuses[$data['id_order_status']];
        $oldOrderStatus = $orderStatuses[$previousOrderData['id_order_status']];
        
        $itemOrderObjectsTable = $itemObjectsTable = [];

        foreach ($orderItems as $orderItem) {
            $itemOrderObject = new \stdClass();
            $itemOrderObject->name = isset($orderItem['name']) ?? '';
            $itemOrderObject->id = isset($orderItem['id']) ? intval($orderItem['id']) : 0;
            $itemOrderObject->id_order = $orderId;
            $itemOrderObject->id_item = isset($orderItem['id_item']) ? intval($orderItem['id_item']) : 0;
            $itemOrderObject->quantity = isset($orderItem['quantity']) ? floatval($orderItem['quantity']) : 1;
            $itemOrderObject->price = isset($orderItem['price']) ? floatval($orderItem['price']) : 0;
            $itemOrderObject->id_shipmentmethod = isset($orderItem['id_shipmentmethod']) ? intval($orderItem['id_shipmentmethod']) : 0;

            $price_calculate_type = isset($orderItem['price_calculate_type']) ? true : false;

            // SET THE PRICE OF THE ITEM
            if ($price_calculate_type || $itemOrderObject->id <= 0) {
                $userGroupId = $currencyId = 1;
                $itemPriceCalculator = new PriceCalculator($itemOrderObject->id_item, $itemOrderObject->quantity, $userGroupId, $currencyId);
                $itemPrice = $itemPriceCalculator->calculatePrice();
                $itemOrderObject->total = $itemPrice['base_price'];
            } else {
                $itemOrderObject->total = $itemOrderObject->quantity * floatval($orderItem['price']);
            }


            // SET THE QUANTITY
            if (isset($allItemsData[$itemOrderObject->id_item])) { // means item exists in items table database

                $itemManageStock = $allItemsData[$itemOrderObject->id_item]->manage_stock;
                if($itemManageStock == ManageStock::FROM_CONFIGURATION)
                    $itemManageStock = $generalManageStock;

                // Check whether the item manages its stock.
                if ($itemManageStock == ManageStock::MANAGE){
                    $app->enqueueMessage('MANAGING STOCK');

                    $currentStock     = $allItemsData[$itemOrderObject->id_item]->stock;
                    $previousQuantity   = isset($previousOrderItemsData[$orderItem['id_item']]) ? $previousOrderItemsData[$orderItem['id_item']]['quantity'] : 0;
                    $newQuantity        = $orderItem['quantity'];
                    
                    $itemObject = new \stdClass;
                    $itemObject->id = $itemOrderObject->id_item;
                    $itemObject->stock = self::handleStock(
                        $currentStock,
                        $previousQuantity,
                        $newQuantity,
                        $oldOrderStatus->stock_operation == 1 ? StockOperation::RESTOCK_STOCK : StockOperation::REMOVE_STOCK,
                        $newOrderStatus->stock_operation == 1 ? StockOperation::RESTOCK_STOCK : StockOperation::REMOVE_STOCK
                    );
                    $app->enqueueMessage('OLD STATUS'.$oldOrderStatus->stock_operation);
                    $app->enqueueMessage('NEW STATUS'.$newOrderStatus->stock_operation);

                    if(is_null($itemObject->stock))
                        return null;

                    // Updating the quantity of edited items.
                    $itemObjectsTable[] = $itemObject;
                }
            }

            $itemOrderObjectsTable[] = $itemOrderObject;
        }

        // Order Items properly configured.
        return [
                'items' => $itemObjectsTable,
                'order_items'=>$itemOrderObjectsTable
                ];

    }


    /**
     * Handles stock calculation based on order statuses and quantities.
     *
     * @param int $currentStock
     * @param int $previousQuantity
     * @param int $newQuantity
     * @param StockOperation $oldOperation
     * @param StockOperation $newOperation
     * @return int Updated stock
     */
    static function handleStock($currentStock, $previousQuantity, $newQuantity, $oldOperation, $newOperation) {
        
        if($oldOperation == StockOperation::RESTOCK_STOCK)
            $previousQuantity = 0;

        if($newOperation == StockOperation::RESTOCK_STOCK)
            $newQuantity = 0;

        $quantityDifference = $newQuantity - $previousQuantity;

        // Check stock availability
        if ($currentStock + $previousQuantity < $newQuantity) {
            Factory::getApplication()->enqueueMessage("There's not enough available stock for one of the items. Saving has been canceled.", "error");
            // Optionally handle insufficient stock case
            // throw error
            return null;  // No stock change
        }

        return $currentStock - $quantityDifference;
    }

    // Attempts to save order's user info.
    static function saveUserInfo($userInfoID, $userInfo): bool{

        if(!is_object($userInfo)){
            $userInfo = json_decode(json_encode($userInfo));
        }

        $db = Factory::getContainer()->get("DatabaseDriver");

        // echo "here";
        // echo "<pre>";
        // print_r($userInfo);
        // echo "</pre>";
//        exit;

        $userInfo->id = $userInfoID;

//        exit;

        try {
            $db->updateObject('#__alfa_user_info', $userInfo, 'id', true);
        }
        catch(Exception $e){
            Factory::getApplication()->enqueueMessage($e->getMessage(), "error");
            return false;
        }

//        echo "<pre>";
//        print_r($userInfo);
//        echo "</pre>";
//exit;

        return true;
    }





}
