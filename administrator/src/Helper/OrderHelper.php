<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * OrderHelper V3 - With Money Objects
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 *
 * ALL ITEM PRICES ARE MONEY OBJECTS
 * - unit_price_tax_incl: Money
 * - unit_price_tax_excl: Money
 * - total_price_tax_incl: Money
 * - total_price_tax_excl: Money
 * - original_product_price: Money
 * - reduction_amount_tax_incl: Money
 * - reduction_amount_tax_excl: Money
 *
 * V3.3.0 FIXES:
 * - Form field name: reads 'price' (matching order_items.xml), not 'unit_price'
 * - Supports duplicate id_item (same product, different variation/price)
 * - Items indexed by row PK (id), not by id_item
 * - Stock aggregated per product across all lines
 * - Delete by row PK, not by id_item
 * - UPDATE preserves: added timestamp, refunds, tax, shipping, discounts
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use stdClass;

class OrderHelper
{
    /**
     * Get order items with Money objects for all prices
     *
     * @param int $orderId Order ID
     * @param Currency|null $currency Currency object (optional, will load if not provided)
     * @return array Array of order item objects with Money prices
     */
    public static function getOrderItems(int $orderId, ?Currency $currency = null): array
    {
        if ($orderId <= 0) {
            return [];
        }

        // Load currency if not provided
        if ($currency === null) {
            $currency = self::getOrderCurrency($orderId);
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query->select(['oi.*'])
            ->from($db->quoteName('#__alfa_order_items', 'oi'))
            ->where('oi.id_order = ' . intval($orderId))
            ->order('oi.added ASC');

        $db->setQuery($query);

        try {
            $items = $db->loadObjectList();
        } catch (Exception $e) {
            Log::add('Error loading order items: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return [];
        }

        if (empty($items)) {
            return [];
        }

        // Convert prices to Money objects
        foreach ($items as $item) {
            // Core price fields → Money objects
            $item->unit_price_tax_incl = Money::of($item->unit_price_tax_incl ?? 0, $currency);
            $item->unit_price_tax_excl = Money::of($item->unit_price_tax_excl ?? 0, $currency);
            $item->total_price_tax_incl = Money::of($item->total_price_tax_incl ?? 0, $currency);
            $item->total_price_tax_excl = Money::of($item->total_price_tax_excl ?? 0, $currency);
            $item->original_product_price = Money::of($item->original_product_price ?? 0, $currency);

            // Discount amounts
            $item->reduction_amount_tax_incl = Money::of($item->reduction_amount_tax_incl ?? 0, $currency);
            $item->reduction_amount_tax_excl = Money::of($item->reduction_amount_tax_excl ?? 0, $currency);

            // Refund amounts
            $item->total_refunded_tax_excl = Money::of($item->total_refunded_tax_excl ?? 0, $currency);
            $item->total_refunded_tax_incl = Money::of($item->total_refunded_tax_incl ?? 0, $currency);

            // Purchase/wholesale prices
            $item->purchase_supplier_price = Money::of($item->purchase_supplier_price ?? 0, $currency);
            $item->original_wholesale_price = Money::of($item->original_wholesale_price ?? 0, $currency);

            // Backward compatibility aliases (used by subform binding)
            $item->total = $item->total_price_tax_incl; // Money object

            // Calculate per-unit price (used by subform 'price' field)
            if ($item->quantity > 0) {
                $item->price = $item->total_price_tax_incl->divide($item->quantity);
            } else {
                $item->price = Money::zero($currency);
            }

            // Store currency object
            $item->currency = $currency;
        }

        return $items;
    }

    /**
     * Save order items with Money object support
     *
     * Supports:
     * - Same product on multiple lines (different variations/prices)
     * - Items indexed by row PK, not id_item
     * - Stock aggregated per product across all order lines
     * - Form field names matching order_items.xml: id, id_item, quantity, price, total, name
     */
    public static function setOrderItems(int $orderId, array $data, ?Currency $currency = null): bool
    {
        if ($orderId <= 0) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_INVALID_ORDER_ID'), 'error');
            return false;
        }

        // Load currency if not provided
        if ($currency === null) {
            $currency = self::getOrderCurrency($orderId);
        }

        $app = Factory::getApplication();
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Accept BOTH 'items' (Joomla subform name) and 'order_items'
        $order_items = $data['order_items'] ?? $data['items'] ?? [];

        if (empty($order_items)) {
            return true; // No items to process
        }

        // ================================================================
        // Get old items indexed by ROW PK (not id_item — supports duplicates)
        // ================================================================
        $oldItemsByPK = self::getOlderOrderItems($orderId);

        // Get items data from items table
        $ids = array_column($order_items, 'id_item');
        $ids = array_unique(array_filter($ids));
        $itemsTable = self::getItemsWithGivenIDs($ids);

        // Configure current items from form data
        $configuredItems = self::configureCurrentItems($order_items, $itemsTable, $currency);

        // ================================================================
        // STOCK: Aggregate quantities per product (id_item) for old vs new
        // This correctly handles duplicate id_item lines
        // ================================================================
        OrderStockHelper::handleItemStockDiff($configuredItems, $oldItemsByPK);

        // ================================================================
        // DELETE: Remove items whose row PK is no longer in the form
        // (Stock already handled above via aggregation)
        // ================================================================
        $newPKs = [];
        foreach ($configuredItems as $item) {
            if (($item->id ?? 0) > 0) {
                $newPKs[] = (int) $item->id;
            }
        }
        $pksToRemove = array_diff(array_keys($oldItemsByPK), $newPKs);

        if (!empty($pksToRemove)) {
            self::deleteOrderItemsByPK($orderId, $pksToRemove);
        }

        // ================================================================
        // SAVE: Insert new / Update existing
        // ================================================================
        foreach ($configuredItems as $item) {
            $isExisting = ($item->id ?? 0) > 0;

            // For UPDATE: load existing row to preserve protected fields
            if ($isExisting) {
                $itemObject = self::loadExistingItemRow($item->id);
                if (!$itemObject) {
                    $isExisting = false;
                    $itemObject = new stdClass();
                }
            } else {
                $itemObject = new stdClass();
            }

            // ============================================================
            // ALWAYS SET: Fields that the admin form can change
            // ============================================================
            $itemObject->id_item = $item->id_item;
            $itemObject->id_order = $orderId;

            // Product snapshot — refresh from items table
            $itemObject->name = $item->name;
            $itemObject->reference = $item->sku ?? '';
            $itemObject->ean13 = $item->gtin ?? '';
            $itemObject->mpn = $item->mpn ?? '';
            $itemObject->weight = $item->weight ?? 0;

            // Quantity — admin can change this
            $itemObject->quantity = $item->quantity;

            // Prices — from form 'price' field × quantity
            if (isset($item->unit_price) && $item->unit_price instanceof Money) {
                $itemObject->unit_price_tax_incl = $item->unit_price->getAmount();
                $itemObject->unit_price_tax_excl = $item->unit_price->getAmount(); // TODO: tax excl calc
            } else {
                $itemObject->unit_price_tax_incl = (float) ($item->unit_price ?? 0);
                $itemObject->unit_price_tax_excl = (float) ($item->unit_price ?? 0);
            }

            if (isset($item->line_total) && $item->line_total instanceof Money) {
                $itemObject->total_price_tax_incl = $item->line_total->getAmount();
                $itemObject->total_price_tax_excl = $item->line_total->getAmount(); // TODO: tax excl calc
            } else {
                $itemObject->total_price_tax_incl = (float) ($item->line_total ?? 0);
                $itemObject->total_price_tax_excl = (float) ($item->line_total ?? 0);
            }

            $itemObject->original_product_price = $itemObject->unit_price_tax_incl;

            // ============================================================
            // INSERT ONLY: Set all fields fresh (not touched on update)
            // ============================================================
            if (!$isExisting) {
                $itemObject->id = 0;

                // Foreign keys
                $itemObject->id_order_invoice = null;
                $itemObject->id_warehouse = 0;
                $itemObject->id_product_attribute = $item->id_product_attribute ?? null;
                $itemObject->id_customization = 0;
                $itemObject->id_shipmentmethod = 0;

                // Product snapshot extras
                $itemObject->supplier_reference = '';
                $itemObject->isbn = '';
                $itemObject->upc = '';

                // Stock snapshot
                $itemObject->quantity_in_stock = $item->stock ?? 0;

                // Refund quantities — start at zero for new items
                $itemObject->quantity_refunded = 0;
                $itemObject->quantity_return = 0;
                $itemObject->quantity_reinjected = 0;

                // Shipping per item
                $itemObject->total_shipping_price_tax_incl = 0;
                $itemObject->total_shipping_price_tax_excl = 0;

                // Original/wholesale
                $itemObject->original_wholesale_price = 0;
                $itemObject->purchase_supplier_price = 0;

                // Discounts
                $itemObject->reduction_percent = 0;
                $itemObject->reduction_amount_tax_incl = 0;
                $itemObject->reduction_amount_tax_excl = 0;
                $itemObject->group_reduction = 0;

                // Tax
                $itemObject->id_tax_rules_group = 0;
                $itemObject->tax_computation_method = 0;
                $itemObject->tax_name = '';
                $itemObject->tax_rate = 0;
                $itemObject->ecotax = 0;
                $itemObject->ecotax_tax_rate = 0;

                // Downloads
                $itemObject->download_hash = null;
                $itemObject->download_nb = 0;
                $itemObject->download_deadline = null;

                // Refund amounts
                $itemObject->total_refunded_tax_excl = 0;
                $itemObject->total_refunded_tax_incl = 0;

                // Only set 'added' on INSERT
                $itemObject->added = Factory::getDate('now', 'UTC')->toSql();

                $db->insertObject('#__alfa_order_items', $itemObject);
            } else {
                // UPDATE: Only the "ALWAYS SET" fields are overwritten.
                // PRESERVED automatically (loaded from DB via loadExistingItemRow):
                //   - added, quantity_refunded, quantity_return, quantity_reinjected
                //   - total_refunded_tax_excl, total_refunded_tax_incl
                //   - total_shipping_price_tax_incl, total_shipping_price_tax_excl
                //   - tax_rate, tax_name, id_tax_rules_group, tax_computation_method
                //   - reduction_percent, reduction_amount_tax_incl, reduction_amount_tax_excl
                //   - group_reduction, ecotax, ecotax_tax_rate
                //   - download_hash, download_nb, download_deadline
                //   - purchase_supplier_price, original_wholesale_price
                //   - id_product_attribute, id_customization, id_shipmentmethod
                //   - id_order_invoice, id_warehouse, supplier_reference, isbn, upc
                $db->updateObject('#__alfa_order_items', $itemObject, 'id', true);
            }
        }

        return true;
    }

    /**
     * Load an existing order item row from the database
     *
     * @param int $itemId The order_items PK (id column)
     * @return object|null The DB row or null if not found
     */
    protected static function loadExistingItemRow(int $itemId): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_order_items')
            ->where('id = ' . intval($itemId));
        $db->setQuery($query);

        try {
            $row = $db->loadObject();
            return $row ?: null;
        } catch (Exception $e) {
            Log::add('Error loading item row: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return null;
        }
    }

    /**
     * Configure items from form data
     *
     * Form fields (order_items.xml): id, id_item, quantity, price, total, name
     * Returns flat array (not indexed) to support duplicate id_item
     *
     * @param array $order_items Form data array
     * @param array $itemsTable Products from #__alfa_items indexed by id
     * @param Currency $currency Currency object
     * @return array Array of configured item objects
     */
    protected static function configureCurrentItems(array $order_items, array $itemsTable, Currency $currency): array
    {
        $configuredItems = [];

        foreach ($order_items as $order_item) {
            $id_item = (int) ($order_item['id_item'] ?? 0);

            if ($id_item <= 0 || !isset($itemsTable[$id_item])) {
                continue;
            }

            $item = $itemsTable[$id_item];
            $quantity = max(1, (int) ($order_item['quantity'] ?? 1));

            // ============================================================
            // FIX: Form field is 'price' (order_items.xml), not 'unit_price'
            // Fallback chain: price → unit_price (for API/programmatic use)
            // ============================================================
            $rawPrice = $order_item['price'] ?? $order_item['unit_price'] ?? 0;
            $unitPrice = Money::of((float) $rawPrice, $currency);

            // Calculate line total: unit price × quantity
            $lineTotal = $unitPrice->multiply($quantity);

            $configuredItem = (object) [
                'id' => (int) ($order_item['id'] ?? 0),
                'id_item' => $id_item,
                'id_product_attribute' => (int) ($order_item['id_product_attribute'] ?? 0),
                'name' => $order_item['name'] ?? $item->name,
                'sku' => $item->sku ?? '',
                'gtin' => $item->gtin ?? '',
                'mpn' => $item->mpn ?? '',
                'weight' => $item->weight ?? 0,
                'quantity' => $quantity,
                'stock' => $item->stock ?? 0,
                'unit_price' => $unitPrice,        // Money object
                'line_total' => $lineTotal,         // Money object
                'currency' => $currency,
            ];

            $configuredItems[] = $configuredItem;
        }

        return $configuredItems;
    }

    /**
     * Handle stock via aggregation per product.
     *
     * @deprecated  3.5.1  Use OrderStockHelper::handleItemStockDiff() directly.
     *              Kept for backward compatibility.
     *
     * @param array $newItems New item set (objects with id_item, quantity)
     * @param array $oldItemsByPK Old items indexed by row PK
     * @since   3.3.0
     */
    protected static function handleStockAggregated(array $newItems, array $oldItemsByPK): void
    {
        OrderStockHelper::handleItemStockDiff($newItems, $oldItemsByPK);
    }

    /**
     * Delete order items by their row PK (id column)
     *
     * Does NOT adjust stock — stock is handled via handleStockAggregated()
     * which already accounts for removed items.
     *
     * @param int $orderId Order ID (for safety WHERE clause)
     * @param array $pksToRemove Row PKs to delete
     */
    protected static function deleteOrderItemsByPK(int $orderId, array $pksToRemove): void
    {
        if (empty($pksToRemove)) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Delete by PK with order ID safety check
        $query = $db->getQuery(true)
            ->delete('#__alfa_order_items')
            ->where('id_order = ' . intval($orderId))
            ->whereIn('id', array_map('intval', $pksToRemove));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Get order items indexed by ROW PK (id)
     *
     * Uses row PK as key to support same product on multiple lines.
     *
     * @param int $orderId Order ID
     * @return array Items indexed by row PK (id)
     */
    protected static function getOlderOrderItems(int $orderId): array
    {
        $items = self::getOrderItems($orderId);
        $indexed = [];

        foreach ($items as $item) {
            $indexed[(int) $item->id] = $item;
        }

        return $indexed;
    }

    /**
     * Get items from items table
     */
    protected static function getItemsWithGivenIDs(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query->select('*')
            ->from('#__alfa_items')
            ->whereIn('id', array_map('intval', $ids));

        $db->setQuery($query);
        $items = $db->loadObjectList('id');

        return $items;
    }

    /**
     * Get order currency
     */
    protected static function getOrderCurrency(int $orderId): Currency
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $query = $db->getQuery(true)
                ->select('id_currency')
                ->from('#__alfa_orders')
                ->where('id = ' . intval($orderId));
            $db->setQuery($query);
            $currencyId = $db->loadResult();

            if ($currencyId) {
                $query = $db->getQuery(true)
                    ->select('number')
                    ->from('#__alfa_currencies')
                    ->where('id = ' . intval($currencyId));
                $db->setQuery($query);
                $currencyNumber = $db->loadResult();

                if ($currencyNumber) {
                    return Currency::loadByNumber((int) $currencyNumber);
                }
            }
        } catch (Exception $e) {
            Log::add('Error loading currency: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
        }

        return Currency::getDefault();
    }

    /**
     * Get order
     */
    public static function getOrder(int $orderId)
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query->select('*')
            ->from('#__alfa_orders')
            ->where('id = ' . intval($orderId));

        $db->setQuery($query);
        return $db->loadObject();
    }

    /**
     * Save user info
     */
    public static function saveUserInfo(int $userInfoId, array $data): bool
    {
        if ($userInfoId <= 0 || empty($data)) {
            return true;
        }

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            $userInfoObject = (object) $data;
            $userInfoObject->id = $userInfoId;

            $db->updateObject('#__alfa_user_info', $userInfoObject, 'id', true);

            return true;
        } catch (Exception $e) {
            Log::add('Error saving user info: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }
    }

    // ====================================================================
    // PRODUCT SEARCH (for admin order item popup)
    // ====================================================================

    /**
     * Search products for the order item popup
     *
     * Searches by name, SKU, EAN13, MPN. Returns product data
     * with calculated price from PriceCalculator (includes tax, discounts).
     *
     * @return array Array of product objects with calculated prices

    // ========================================================================
    // PRICING CONTEXT
    // ========================================================================

    /**
     * Build a PriceContext for an order's customer
     *
     * Instead of using the admin's session (which gives admin prices),
     * this builds context from the order's actual customer — their user group,
     * their currency — so prices/discounts/taxes reflect what THEY would see.
     *
     * @param int $orderId Order ID to load customer context from
     */
    public static function buildOrderPriceContext(int $orderId): \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // Load order's customer and currency
        $query = $db->getQuery(true)
            ->select(['o.id_user', 'o.id_currency'])
            ->from($db->quoteName('#__alfa_orders', 'o'))
            ->where('o.id = ' . intval($orderId));
        $db->setQuery($query);
        $order = $db->loadObject();

        $customerId = $order ? (int) $order->id_user : 0;
        $currencyId = $order ? (int) $order->id_currency : 0;

        // Get customer's usergroups from Joomla
        $userGroups = [0]; // sentinel for "any group"
        if ($customerId > 0) {
            try {
                $user = \Joomla\CMS\User\User::getInstance($customerId);
                if ($user && !$user->guest) {
                    foreach ($user->getAuthorisedGroups() as $gid) {
                        $userGroups[] = (int) $gid;
                    }
                }
            } catch (Exception $e) {
                Log::add('Could not load customer groups for user ' . $customerId . ': ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            }
        }

        // Build context using the fluent API.
        // currencyId is the DB primary key from #__alfa_currencies (not the ISO code).
        // forIndex() creates an anonymous context; withUserId() attaches the customer.
        return \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext::forIndex($currencyId, 0, 0)
            ->withUserGroups(array_unique($userGroups))
            ->withUserId($customerId > 0 ? $customerId : null);
    }

    // ========================================================================
    // PRODUCT SEARCH (Admin Order Items)
    // ========================================================================

    /**
     * Search products for the order item popup
     *
     * Uses PriceCalculator with the ORDER'S CUSTOMER CONTEXT — not the admin's
     * session — so prices, discounts, and taxes reflect what the customer sees.
     *
     * @param string $searchTerm Search term (min 2 chars)
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context Customer pricing context
     * @param int $limit Max results (default 20)
     * @return array Array of product objects with calculated prices
     */
    public static function searchProducts(
        string $searchTerm,
        \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context,
        int $limit = 20,
    ): array {
        $searchTerm = trim($searchTerm);
        if (strlen($searchTerm) < 2) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $search = $db->quote('%' . $db->escape($searchTerm, true) . '%');

        $q = $db->getQuery(true)
            ->select([
                'i.id', 'i.name', 'i.sku', 'i.gtin', 'i.mpn',
                'i.stock', 'i.weight', 'i.manage_stock', 'i.state',
            ])
            ->from($db->quoteName('#__alfa_items', 'i'))
            ->where('i.state = 1')
            ->where('(' .
                'i.name LIKE ' . $search . ' OR ' .
                'i.sku LIKE ' . $search . ' OR ' .
                'i.gtin LIKE ' . $search . ' OR ' .
                'i.mpn LIKE ' . $search .
                ')')
            ->order('i.name ASC');

        $q->setLimit($limit);
        $db->setQuery($q);

        try {
            $items = $db->loadObjectList();
        } catch (Exception $e) {
            Log::add('Product search error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return [];
        }

        if (empty($items)) {
            return [];
        }

        // Use PriceCalculator with CUSTOMER context (not admin session)
        try {
            $calculator = new \Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator();

            $productIds = array_column($items, 'id');
            $quantities = array_fill_keys($productIds, 1);

            $prices = $calculator->calculate($productIds, $quantities, $context);

            foreach ($items as $item) {
                $priceResult = $prices[$item->id] ?? null;
                self::attachPriceResult($item, $priceResult);
            }
        } catch (Exception $e) {
            Log::add('PriceCalculator failed, falling back to raw prices: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            $rawPrices = self::getBaseProductPrices(array_column($items, 'id'));
            foreach ($items as $item) {
                self::attachPriceResult($item, null, $rawPrices[$item->id] ?? 0);
            }
        }

        return $items;
    }

    /**
     * Get a single product by ID with calculated prices
     *
     * Uses PriceCalculator with customer context for proper tax/discount.
     *
     * @param int $productId Product ID
     * @param \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context Customer pricing context
     * @param int $quantity Quantity for price calculation (default 1)
     * @return object|null Product object with price fields or null
     */
    public static function getProductById(
        int $productId,
        \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext $context,
        int $quantity = 1,
    ): ?object {
        if ($productId <= 0) {
            return null;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_items')
            ->where('id = ' . intval($productId));
        $db->setQuery($query);

        try {
            $item = $db->loadObject();
        } catch (Exception $e) {
            return null;
        }

        if (!$item) {
            return null;
        }

        try {
            $calculator = new \Alfa\Component\Alfa\Site\Service\Pricing\PriceCalculator();
            $priceResult = $calculator->calculate($productId, $quantity, $context);
            self::attachPriceResult($item, is_object($priceResult) ? $priceResult : null);
        } catch (Exception $e) {
            Log::add('PriceCalculator failed for product ' . $productId . ': ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            $rawPrices = self::getBaseProductPrices([$productId]);
            self::attachPriceResult($item, null, $rawPrices[$productId] ?? 0);
        }

        return $item;
    }

    /**
     * Attach PriceResult data to a product item object
     *
     * Centralizes the mapping from PriceResult to flat item properties.
     * Used by both searchProducts() and getProductById().
     *
     * @param object $item Product object to attach prices to
     * @param object|null $priceResult Calculated PriceResult (null = fallback)
     * @param float $fallbackPrice Raw price if PriceResult unavailable
     */
    protected static function attachPriceResult(object $item, $priceResult, float $fallbackPrice = 0): void
    {
        if ($priceResult && is_object($priceResult)) {
            $item->price_result = $priceResult;

            // ============================================================
            // CORRECT PRICE MAPPING:
            //
            // getSubtotalPrice() = per unit, after discounts, BEFORE tax
            // getTaxPrice()      = per unit tax amount
            // getPrice()         = per unit FINAL (after all discounts + tax)
            // getBasePrice()     = per unit ORIGINAL (before any discounts)
            //
            // For order_items we need the accounting split:
            //   price_tax_excl = subtotal (after discounts, before tax)
            //   price_tax_incl = subtotal + tax (NOT after-tax discounts)
            //   tax_rate       = from TaxSummary (the REAL rate, not computed)
            //
            // getPrice() ≠ price_tax_incl when after-tax discounts exist!
            // After-tax discounts are tracked separately (coupons, etc.)
            // ============================================================
            $item->price_tax_excl = $priceResult->getSubtotalPrice()->getAmount();
            $item->price_tax_incl = $item->price_tax_excl + $priceResult->getTaxPrice()->getAmount();
            $item->base_price = $priceResult->getBasePrice()->getAmount();
            $item->customer_price = $priceResult->getPrice()->getAmount(); // What customer actually pays
            $item->has_discount = $priceResult->hasDiscount();
            $item->discount_percent = $priceResult->hasDiscount() ? $priceResult->getSavingsPercent() : 0;

            // Tax rate from TaxSummary — the ACTUAL configured rate
            // NOT computed from price difference (which breaks with after-tax discounts)
            $item->tax_rate = $priceResult->getTaxes()->getEffectiveRate();

            // Tax name from first applied tax (for order_items.tax_name field)
            $appliedTaxes = $priceResult->getTaxes()->getApplied();
            $item->tax_name = !empty($appliedTaxes) ? $appliedTaxes[0]->name : '';
        } else {
            $item->price_result = null;
            $item->price_tax_incl = $fallbackPrice;
            $item->price_tax_excl = $fallbackPrice;
            $item->base_price = $fallbackPrice;
            $item->customer_price = $fallbackPrice;
            $item->has_discount = false;
            $item->discount_percent = 0;
            $item->tax_rate = 0;
            $item->tax_name = '';
        }
    }

    protected static function getBaseProductPrices(array $productIds, ?int $currencyId = null): array
    {
        if (empty($productIds)) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);

        $query->select([
            'p.item_id',
            'p.value',
            'p.ovewrited_value',
        ])
            ->from($db->quoteName('#__alfa_items_prices', 'p'))
            ->whereIn('p.item_id', array_map('intval', $productIds))
            ->where('p.state = 1')
            ->where('(p.user_id = 0 OR p.user_id IS NULL)')
            ->where('(p.country_id = 0 OR p.country_id IS NULL)')
            ->order('p.ordering ASC');

        // Currency filter: match specific or default (0 = default currency)
        if ($currencyId !== null && $currencyId > 0) {
            $query->where('(p.currency_id = 0 OR p.currency_id IS NULL OR p.currency_id = ' . intval($currencyId) . ')');
        }

        $db->setQuery($query);

        try {
            $rows = $db->loadObjectList();
        } catch (Exception $e) {
            return [];
        }

        // Build price map — first match per product wins (ordered by ordering)
        $prices = [];
        foreach ($rows as $row) {
            if (!isset($prices[$row->item_id])) {
                // Use overwritten value if set, otherwise base value
                $prices[$row->item_id] = ($row->ovewrited_value !== null)
                    ? (float) $row->ovewrited_value
                    : (float) $row->value;
            }
        }

        return $prices;
    }
}
