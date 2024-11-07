class OrderManager
{
    protected $db;
    protected $orderId;
    protected $orderData;
    protected $manageStock;
    protected $orderStatuses;
    protected $keepInStock;

    public function __construct($orderId, $data = [])
    {
        // Initialize database instance
        $this->db = Factory::getDbo();

        // Assign order ID and data
        $this->orderId = $orderId;
        $this->orderData = $data;

        // Load stock management configuration
        $componentParams = ComponentHelper::getParams('com_alfa');
        $this->manageStock = $componentParams->get('manage_stock', 0);

        // Load order statuses and determine stock management action
        $this->orderStatuses = AlfaHelper::getOrderStatuses();
        $newOrderStatus = $data['id_order_status'] ?? null;
        $this->keepInStock = $newOrderStatus && $this->orderStatuses[$newOrderStatus]->stock_action === 0;
    }

    public function saveOrder()
    {
        // Verify stock levels before saving
        if (!$this->verifyStockLevels()) {
            return false;
        }

        // Save order and items
        return $this->saveOrderData() && $this->processItems();
    }

    protected function saveOrderData()
    {
        // Save primary order data
        // Placeholder for order save logic
        // Example: $this->db->insertObject('#__alfa_orders', $this->orderData);
        
        return true;
    }

    protected function processItems()
    {
        $items = $this->orderData['items'] ?? [];

        // Validate items data and ensure order ID is set
        if (!is_array($items) || !$this->orderId) {
            return false;
        }

        $existingItems = $this->getExistingItems();
        $incomingItemIds = array_column($items, 'id');
        $idsToDelete = array_diff(array_column($existingItems, 'id'), $incomingItemIds);

        // Delete removed items
        if (!empty($idsToDelete)) {
            $this->deleteItems($idsToDelete, $existingItems);
        }

        // Update existing items or add new ones
        foreach ($items as $item) {
            $this->saveOrUpdateItem($item, $existingItems);
        }

        return true;
    }

    protected function verifyStockLevels()
    {
        if ($this->keepInStock) {
            return true;
        }

        $items = $this->orderData['items'] ?? [];
        $itemIds = array_column($items, 'id_item');

        if (empty($itemIds)) {
            return true;
        }

        // Query current stock levels
        $query = $this->db->getQuery(true)
            ->select('id, stock')
            ->from('#__alfa_items')
            ->whereIn('id', $itemIds);
        $this->db->setQuery($query);
        $stockLevels = $this->db->loadAssocList('id');

        foreach ($items as $item) {
            $itemId = $item['id_item'];
            $quantity = $item['quantity'];

            if (isset($stockLevels[$itemId]) && $stockLevels[$itemId]['stock'] < $quantity) {
                Factory::getApplication()->enqueueMessage(
                    "Insufficient stock for item {$item['name']}.",
                    'error'
                );
                return false;
            }
        }

        return true;
    }

    protected function getExistingItems()
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from('#__alfa_order_items')
            ->where('id_order = ' . (int) $this->orderId);
        $this->db->setQuery($query);

        return $this->db->loadAssocList('id');
    }

    protected function deleteItems($idsToDelete, $existingItems)
    {
        // Delete items from order
        $query = $this->db->getQuery(true)
            ->delete('#__alfa_order_items')
            ->whereIn('id', $idsToDelete);
        $this->db->setQuery($query);
        $this->db->execute();

        // Restock deleted items
        foreach ($idsToDelete as $id) {
            if (isset($existingItems[$id])) {
                $this->restockItem($existingItems[$id]);
            }
        }
    }

    protected function restockItem($item)
    {
        if (!empty($item['quantity_removed'])) {
            $query = $this->db->getQuery(true)
                ->update('#__alfa_items')
                ->set('stock = stock + ' . (int) $item['quantity'])
                ->where('id = ' . (int) $item['id_item']);
            $this->db->setQuery($query);
            $this->db->execute();
        }
    }

    protected function saveOrUpdateItem($item, $existingItems)
    {
        $itemObject = (object) [
            'id' => $item['id'] ?? 0,
            'id_order' => $this->orderId,
            'id_item' => $item['id_item'] ?? 0,
            'quantity' => $item['quantity'] ?? 1,
            'price' => $item['price'] ?? 0,
            'id_shipmentmethod' => $item['id_shipmentmethod'] ?? 0,
            'total' => $item['quantity'] * ($item['price'] ?? 0)
        ];

        // Insert or update item record
        if ($itemObject->id > 0 && isset($existingItems[$itemObject->id])) {
            $this->db->updateObject('#__alfa_order_items', $itemObject, 'id');
        } else {
            $this->db->insertObject('#__alfa_order_items', $itemObject);
        }
    }
}
