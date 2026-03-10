<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\CartViewEvent as PaymentsCartViewEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\CalculateShippingCostEvent as ShipmentsCalculateShippingCostEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\CartViewEvent as ShipmentsCartViewEvent;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PricingIntent;
use Exception;
use Joomla\CMS\Factory;
use stdClass;

/**
 * Cart Helper - Enterprise Production Grade
 *
 * Professional cart management with:
 * - CartItem wrapper pattern (cart metadata + item data)
 * - ItemsModel integration (DRY principle)
 * - PriceCalculator integration with cart quantities
 * - Money objects for all prices
 * - Secure guest cart tracking
 *
 * @package   Com_Alfa
 * @version   2.0.0
 * @author    Your Company
 */
class CartHelper
{
    protected $app;
    protected $db;
    protected $user;
    protected $cartId;
    protected $recognizeKey;
    protected $cart;

    protected $items_table = '#__alfa_items';
    protected $cart_table = '#__alfa_cart';
    protected $cart_items_table = '#__alfa_cart_items';

    protected $categories = [];
    protected $manufacturers = [];

    protected $payment_methods;
    protected $shipment_methods;

    /** @var array|null Cached shipment costs ['tax_incl' => Money, 'tax_excl' => Money] */
    protected ?array $_shipmentCosts = null;

    public function __construct($cartId = 0)
    {
        $this->app = Factory::getApplication();
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->user = $this->app->getIdentity();
        $this->recognizeKey = $this->getRecognizeKey();
        $this->cartId = $cartId;

        $this->cart = $this->getCart();

        $this->payment_methods = AlfaHelper::getFilteredMethods($this->categories, $this->manufacturers, $this->user->groups, $this->user->id, 'payment');
        $this->shipment_methods = AlfaHelper::getFilteredMethods($this->categories, $this->manufacturers, $this->user->groups, $this->user->id, 'shipment');
    }

    // ========================================================================
    // GETTERS
    // ========================================================================

    public function getCartId()
    {
        return $this->cartId;
    }

    public function getData()
    {
        return $this->cart;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getPaymentMethods()
    {
        return $this->payment_methods;
    }

    public function getShipmentMethods()
    {
        return $this->shipment_methods;
    }

    public function getShipmentMethodData($shipmentMethodId = 0)
    {
        if ($shipmentMethodId == 0) {
            $shipmentMethodId = $this->getData()->id_shipment ?? 0;
        }

        if ($shipmentMethodId == 0) {
            return null;
        }

        $shipmentModel = $this->app->bootComponent('com_alfa')
            ->getMVCFactory()->createModel('Shipment', 'Administrator', ['ignore_request' => true]);

        return $shipmentModel->getItem($shipmentMethodId);
    }

    // ========================================================================
    // SETTERS
    // ========================================================================

    public function setCartId($cartId)
    {
        $this->cartId = $cartId;
        $this->cart = $this->getCart();
    }

    // ========================================================================
    // CART OPERATIONS
    // ========================================================================

    /**
     * Get cart with items
     *
     * @return object Cart object with items array
     */
    protected function getCart()
    {
        // Empty cart for guests without recognize key
        if ($this->user->id <= 0 && $this->recognizeKey == '' && $this->cartId <= 0) {
            $cart_data = new stdClass();
            $cart_data->items = [];
            return $cart_data;
        }

        $db = $this->db;

        try {
            $query = $db->getQuery(true);
            $query->select('*')
                ->from($db->quoteName($this->cart_table, 'a'));

            if ($this->cartId > 0) {
                $query->where($db->quoteName('id') . ' = ' . $db->quote($this->cartId));
            } else {
                if ($this->user->id > 0) {
                    $query->where($db->quoteName('id_customer') . ' = ' . $db->quote($this->user->id));
                } else {
                    $query->where($db->quoteName('recognize_key') . ' = ' . $db->quote($this->recognizeKey));
                }
            }

            $db->setQuery($query);
            $cart_data = $db->loadObject();

            if ($cart_data) {
                $this->cartId = $cart_data->id;
            } else {
                $cart_data = new stdClass();
                $this->cartId = 0;
            }

            // Get cart items (CartItem wrapper objects)
            $cart_data->items = $this->getCartItems();
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            $cart_data = new stdClass();
            $cart_data->items = [];
        }

        // Load user info
        if (isset($cart_data->id_user_info_delivery)) {
            $cart_data->user_info_delivery = $this->getUserInfo($cart_data->id_user_info_delivery);
        }

        if (isset($cart_data->id_user_info_invoice)) {
            $cart_data->user_info_invoice = $this->getUserInfo($cart_data->id_user_info_invoice);
        }

        return $cart_data;
    }

    /**
     * Get cart items using professional CartItem wrapper pattern
     *
     * THE MOST PROFESSIONAL APPROACH:
     * 1. Query cart_items for cart metadata (quantity, added)
     * 2. Use ItemsModel with filter.id to get complete item data
     * 3. Create CartItem wrapper objects (cartItem->data = item)
     *
     * Returns array of CartItem objects:
     * CartItem {
     *   id_cart: id_cart
     *   id_item: id_item
     *   quantity: cart_quantity
     *   added: date_added
     *   data: Item {
     *     ... complete item from ItemsModel
     *     price: Money object (calculated with cart quantity!)
     *   }
     * }
     *
     * @return array Array of CartItem objects
     */
    protected function getCartItems()
    {
        if ($this->cartId <= 0) {
            return [];
        }

        $db = $this->db;

        // ====================================================================
        // STEP 1: Get cart metadata
        // ====================================================================
        $query = $db->getQuery(true);
        $query
            ->select(['ci.id_cart', 'ci.id_item', 'ci.quantity', 'ci.added'])
            ->from($db->quoteName($this->cart_items_table, 'ci'))
            ->where('ci.id_cart = ' . (int) $this->cartId)
            ->order('ci.added DESC');

        try {
            $db->setQuery($query);
            $cartItemsData = $db->loadObjectList('id_item');
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            return [];
        }

        if (empty($cartItemsData)) {
            return [];
        }

        $itemIds = array_keys($cartItemsData);

        // ====================================================================
        // STEP 2: Build type-safe quantities array
        // ====================================================================
        $quantities = [];
        foreach ($cartItemsData as $itemId => $cartData) {
            // Ensure integer type and minimum of 1
            $quantities[$itemId] = max(1, (int) $cartData->quantity);
        }

        // ====================================================================
        // STEP 3: Fetch items with EXPLICIT cart pricing intent
        // ====================================================================
        $component = $this->app->bootComponent('com_alfa');
        $mvcFactory = $component->getMVCFactory();

        $itemsModel = $mvcFactory->createModel('Items', 'Site', ['ignore_request' => true]);

        $itemsModel->getState('list.ordering');
        //	$itemsModel->setState('filter.category_id', 0);
        //	$itemsModel->setState('filter.category', []);
        $itemsModel->setState('filter.id', $itemIds);
        $itemsModel->setState('list.limit', 0);
        $itemsModel->setState('list.start', 0);

        // Cart pricing
        $itemsModel->setPricingIntent(PricingIntent::cart($quantities));

        // Get items with prices calculated for cart context
        $items = $itemsModel->getItems();

        if (empty($items)) {
            return [];
        }

        // ====================================================================
        // STEP 4: Wrap in CartItem objects
        // ====================================================================
        $cartItems = [];

        foreach ($items as $item) {
            $itemId = $item->id;

            if (!isset($cartItemsData[$itemId])) {
                continue;
            }

            $cartItem = new stdClass();
            $cartItem->id_cart = $cartItemsData[$itemId]->id_cart;
            $cartItem->id_item = $itemId;
            $cartItem->quantity = $cartItemsData[$itemId]->quantity;
            $cartItem->added = $cartItemsData[$itemId]->added;
            $cartItem->data = $item; // Complete item with cart-priced data

            $cartItems[] = $cartItem;
        }

        return $cartItems;
    }

    /**
     * Get user info by ID
     *
     * @param int $info_id User info ID
     * @return object|null User info object
     */
    protected function getUserInfo($info_id)
    {
        if ($info_id <= 0) {
            return null;
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query
            ->select('*')
            ->from('#__alfa_user_info')
            ->where($db->qn('id') . ' = ' . (int) $info_id)
            ->order('id DESC')
            ->setLimit(1);

        try {
            $db->setQuery($query);
            $userInfo = $db->loadObject();
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            return null;
        }

        return $userInfo;
    }

    // ========================================================================
    // RECOGNIZE KEY (Secure Guest Cart Tracking)
    // ========================================================================

    /**
     * Get recognize key from cookie
     *
     * @return string Recognize key
     */
    public function getRecognizeKey()
    {
        $cookieName = 'recognize_key';
        return $this->app->input->cookie->get($cookieName, '');
    }

    /**
     * Create cryptographically secure recognize key
     *
     * IMPROVED SECURITY:
     * - Uses random_bytes() (cryptographically secure)
     * - Combines multiple unique factors
     * - SHA-256 hashing
     * - Secure cookie settings
     * - 30 days expiration
     *
     * @return void
     */
    protected function createRecognizeKey()
    {
        $cookieName = 'recognize_key';

        // Generate secure unique key
        $uniqueData = sprintf(
            '%s_%s_%s_%s',
            bin2hex(random_bytes(16)), // 32 hex characters (crypto-secure)
            $this->app->input->server->getString('REMOTE_ADDR', ''),
            substr(md5($this->app->input->server->getString('HTTP_USER_AGENT', '')), 0, 10),
            time(),
        );

        // Hash for final key
        $cookieValue = hash('sha256', $uniqueData);

        // Secure cookie settings
        $expires = time() + (3600 * 24 * 30); // 30 days
        $path = '/';
        $domain = '';
        $secure = $this->app->input->server->getBool('HTTPS', false);
        $httponly = true;
        $samesite = 'Strict';

        $this->app->input->cookie->set(
            $cookieName,
            $cookieValue,
            [
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite,
            ],
        );

        $this->recognizeKey = $cookieValue;
    }

    // ========================================================================
    // CART MODIFICATIONS
    // ========================================================================

    /**
     * Create new cart
     *
     * @return bool Success
     */
    protected function createCart(): bool
    {
        $currentDate = Factory::getDate('now', 'UTC');

        $cartObject = new stdClass();
        $cartObject->id_shop_group = 1;
        $cartObject->id_shipment = 0;
        $cartObject->id_payment = 0;
        $cartObject->id_lang = 1;
        $cartObject->id_user_info_delivery = 0;
        $cartObject->id_user_info_invoice = 0;
        $cartObject->id_currency = 1;
        $cartObject->id_customer = $this->user->id;
        $cartObject->added = $currentDate->toSql(false);
        $cartObject->updated = $currentDate->toSql(false);
        $cartObject->recognize_key = $this->recognizeKey;

        try {
            $db = $this->db;
            $db->insertObject($this->cart_table, $cartObject, 'id');
            $this->cartId = $db->insertid();
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        $this->cart = $this->getCart();
        return true;
    }

    /**
     * Add or update item in cart
     *
     * @param int $itemId Item ID
     * @param int $quantity Quantity (0 = remove)
     * @return bool Success
     */
    public function addToCart($itemId, $quantity)
    {
        // Ensure recognize key exists
        if ($this->recognizeKey == '') {
            $this->createRecognizeKey();
        }

        $db = $this->db;

        // Create cart if needed
        if ($this->cartId <= 0) {
            if (!$this->createCart()) {
                return false;
            }
        }

        // Check if item exists in cart
        $itemExistsInCart = false;
        $cartItemData = null;

        foreach ($this->cart->items as $cartItem) {
            if ($cartItem->id_item == $itemId) {
                $cartItemData = $cartItem->data;
                $itemExistsInCart = true;
                break;
            }
        }

        // Get item data if not in cart
        if (!$itemExistsInCart) {
            $query = $db->getQuery(true);
            $query->select('*')
                ->from($this->items_table)
                ->where($db->qn('id') . ' = ' . $db->q($itemId));
            $db->setQuery($query);

            $cartItemData = $db->loadObject();

            if (!$cartItemData) {
                $this->app->enqueueMessage('Product not found', 'error');
                return false;
            }
        }

        // Stock validation
        $checkStock = ($cartItemData->stock_action == 1 || $cartItemData->stock_action == 2);

        if ($checkStock && $cartItemData->stock != null && $cartItemData->stock <= 0) {
            $this->app->enqueueMessage('Product is out of stock', 'error');
            return false;
        }

        // Prepare cart item
        $itemObject = new stdClass();
        $itemObject->id_cart = $this->cartId;
        $itemObject->id_item = $itemId;
        $itemObject->quantity = $quantity;
        $itemObject->added = Factory::getDate('now', 'UTC')->toSql(false);

        // Apply quantity rules
        if (isset($cartItemData->quantity_step) && $cartItemData->quantity_step > 0) {
            if ($itemObject->quantity % $cartItemData->quantity_step != 0) {
                $itemObject->quantity = floor($itemObject->quantity / $cartItemData->quantity_step) * $cartItemData->quantity_step;
            }
        }

        if (isset($cartItemData->quantity_min) && $itemObject->quantity < $cartItemData->quantity_min) {
            $itemObject->quantity = $cartItemData->quantity_min;
        }

        if (isset($cartItemData->quantity_max) && $cartItemData->quantity_max > 0 && $itemObject->quantity > $cartItemData->quantity_max) {
            $itemObject->quantity = $cartItemData->quantity_max;
        }

        if ($checkStock && $cartItemData->stock != null && $itemObject->quantity > $cartItemData->stock) {
            $itemObject->quantity = $cartItemData->stock;
        }

        // Update or insert
        try {
            if ($itemExistsInCart) {
                $query = $db->getQuery(true);

                if ($quantity > 0) {
                    $fields = [
                        $db->quoteName('quantity') . ' = ' . intval($itemObject->quantity),
                        $db->quoteName('added') . ' = ' . $db->quote($itemObject->added),
                    ];

                    $conditions = [
                        $db->quoteName('id_item') . ' = ' . intval($itemId),
                        $db->quoteName('id_cart') . ' = ' . intval($this->cartId),
                    ];

                    $query->update($db->quoteName($this->cart_items_table))
                        ->set($fields)
                        ->where($conditions);
                } else {
                    $conditions = [
                        $db->quoteName('id_item') . ' = ' . intval($itemId),
                        $db->quoteName('id_cart') . ' = ' . intval($this->cartId),
                    ];

                    $query->delete($db->quoteName($this->cart_items_table))
                        ->where($conditions);
                }

                $db->setQuery($query);
                $db->execute();
            } else {
                if ($quantity > 0) {
                    $db->insertObject($this->cart_items_table, $itemObject);
                }
            }
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        // Refresh cart
        $this->cart->items = $this->getCartItems();
        return true;
    }

    /**
     * Clear cart
     *
     * @param bool $clearOnlyItems Keep cart record if true
     * @return bool Success
     */
    public function clearCart($clearOnlyItems = false)
    {
        $db = $this->db;

        $query = $db->getQuery(true)
            ->delete($this->cart_items_table)
            ->where('id_cart = ' . $db->quote($this->cartId));

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        if (!$clearOnlyItems) {
            $query = $db->getQuery(true)
                ->delete($this->cart_table)
                ->where('id = ' . $db->quote($this->cartId));

            try {
                $db->setQuery($query);
                $db->execute();
            } catch (Exception $e) {
                $this->app->enqueueMessage($e->getMessage(), 'error');
                return false;
            }
        }

        $this->cart->items = [];
        return true;
    }

    /**
     * Update shipment method
     *
     * @param int $id Shipment method ID
     * @return bool Success
     */
    public function updateShipment($id)
    {
        if ($this->cartId <= 0) {
            if (!$this->createCart()) {
                return false;
            }
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query
            ->update($this->cart_table)
            ->set('id_shipment = ' . (int) $id)
            ->where('id = ' . (int) $this->cartId);

        try {
            $db->setQuery($query);
            $result = $db->execute();
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        if ($result) {
            $this->cart->id_shipment = $id;
        }

        return $result;
    }

    /**
     * Update payment method
     *
     * @param int $id Payment method ID
     * @return bool Success
     */
    public function updatePayment($id)
    {
        if ($this->cartId <= 0) {
            if (!$this->createCart()) {
                return false;
            }
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query
            ->update($this->cart_table)
            ->set('id_payment = ' . (int) $id)
            ->where('id = ' . (int) $this->cartId);

        try {
            $db->setQuery($query);
            $result = $db->execute();
        } catch (Exception $e) {
            $this->app->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        if ($result) {
            $this->cart->id_payment = $id;
        }

        return $result;
    }

    // ========================================================================
    // CART CALCULATIONS
    // ========================================================================

    /**
     * Get cart total as Money object
     *
     * Sums all item prices and returns Money object.
     * Note: PriceResult has getter methods (getTotal(), getBasePrice(), etc.)
     *
     * @return Money Total as Money object
     */
    public function getTotal()
    {
        $currency = $this->getCurrency();

        // Sum all line totals
        $lineTotals = [];

        foreach ($this->cart->items as $cartItem) {
            if (isset($cartItem->data->price) && is_object($cartItem->data->price)) {
                $lineTotal = $cartItem->data->price->getTotal();
                $lineTotals[] = $lineTotal;
            }
        }

        // Sum all Money objects (or return zero if empty)
        if (empty($lineTotals)) {
            return Money::zero($currency);
        }

        return Money::sum(...$lineTotals);
    }

    /**
     * Get currency from first cart item or fallback
     */
    protected function getCurrency(): Currency
    {
        // TODO: CHECK IF WE HAVE TO LET IT LIKE THIS OR FETCH IT FROM SETTINGS THAT USER SETTED
        if (!empty($this->cart->items)) {
            $firstItem = reset($this->cart->items);
            if (isset($firstItem->data->price) && is_object($firstItem->data->price)) {
                return $firstItem->data->price->getCurrency();
            }
        }

        return Currency::loadByCode('USD'); // fallback or config default
    }

    /**
     * Get total discount for all cart items
     */
    public function getDiscountTotal(): Money
    {
        $currency = $this->getCurrency();
        $totalDiscount = Money::zero($currency);

        foreach ($this->cart->items as $cartItem) {
            if (isset($cartItem->data->price) && is_object($cartItem->data->price) && $cartItem->data->price->hasDiscount()) {
                $totalDiscount = $totalDiscount->add($cartItem->data->price->getSavingsTotal());
            }
        }

        return $totalDiscount;
    }

    /**
     * Get total tax for all cart items
     */
    public function getTaxTotal(): Money
    {
        $currency = $this->getCurrency();
        $totalTax = Money::zero($currency);

        foreach ($this->cart->items as $cartItem) {
            if (isset($cartItem->data->price) && is_object($cartItem->data->price)) {
                $totalTax = $totalTax->add($cartItem->data->price->getTaxTotal());
            }
        }

        return $totalTax;
    }

    /**
     * Get shipment cost (tax inclusive) as Money object.
     *
     * @return Money Shipping cost incl. tax
     */
    public function getShipmentTotal(): Money
    {
        return $this->computeShipmentCosts()['tax_incl'];
    }

    /**
     * Get shipment cost (tax exclusive) as Money object.
     *
     * If the plugin does not provide a tax-exclusive value,
     * this returns the same as getShipmentTotal() (zero-tax default).
     *
     * @return Money Shipping cost excl. tax
     */
    public function getShipmentTotalExcl(): Money
    {
        return $this->computeShipmentCosts()['tax_excl'];
    }

    /**
     * Fire the shipping cost event once and cache both incl/excl values.
     *
     * The CalculateShippingCostEvent carries two values:
     *   - shippingCost        → tax inclusive (required)
     *   - shippingCostTaxExcl → tax exclusive (optional, defaults to incl)
     *
     * Cached per-request: the event fires once, subsequent calls
     * to getShipmentTotal() / getShipmentTotalExcl() read from cache.
     *
     * @return array{tax_incl: Money, tax_excl: Money}
     */
    protected function computeShipmentCosts(): array
    {
        if ($this->_shipmentCosts !== null) {
            return $this->_shipmentCosts;
        }

        $currency = $this->getCurrency();
        $zero = Money::of(0, $currency);

        $currentMethod = $this->getShipmentMethodData();
        $currentType = $currentMethod?->type;

        if (!$currentType) {
            $this->_shipmentCosts = ['tax_incl' => $zero, 'tax_excl' => $zero];
            return $this->_shipmentCosts;
        }

        $eventName = 'onCalculateShippingCost';

        $event = new ShipmentsCalculateShippingCostEvent($eventName, [
            'subject' => $this,
            'method' => $currentMethod,
            'shippingCost' => 0,
        ]);

        $plugin = $this->app->bootPlugin($currentType, 'alfa-shipments');

        if (method_exists($plugin, $eventName)) {
            $plugin->{$eventName}($event);
        }

        $this->_shipmentCosts = [
            'tax_incl' => Money::of($event->getShippingCost(), $currency),
            'tax_excl' => Money::of($event->getShippingCostTaxExcl(), $currency),
        ];

        return $this->_shipmentCosts;
    }

    /**
     * Get cart items total (tax exclusive) as Money object.
     *
     * Sums PriceResult::getSubtotal() for each item (line total excl. tax).
     * Counterpart of getTotal() which sums tax-inclusive.
     *
     * @return Money Items total excl. tax
     */
    public function getTotalExcl(): Money
    {
        $currency = $this->getCurrency();
        $lineTotals = [];

        foreach ($this->cart->items as $cartItem) {
            if (isset($cartItem->data->price) && is_object($cartItem->data->price)) {
                $lineTotals[] = $cartItem->data->price->getSubtotal();
            }
        }

        if (empty($lineTotals)) {
            return Money::zero($currency);
        }

        return Money::sum(...$lineTotals);
    }

    /**
     * Get grand total (items + shipping) tax inclusive.
     *
     * @return Money Grand total incl. tax
     */
    public function getGrandTotal(): Money
    {
        return $this->getTotal()->add($this->getShipmentTotal());
    }

    /**
     * Get grand total (items + shipping) tax exclusive.
     *
     * @return Money Grand total excl. tax
     */
    public function getGrandTotalExcl(): Money
    {
        return $this->getTotalExcl()->add($this->getShipmentTotalExcl());
    }

    /**
     * Get total number of unique items
     *
     * @return int Item count
     */
    public function getTotalItems()
    {
        return count($this->cart->items ?? []);
    }

    /**
     * Get total quantity of all items
     *
     * @return int Total quantity
     */
    public function getTotalQuantity()
    {
        $quantity = 0;

        if (isset($this->cart->items)) {
            foreach ($this->cart->items as $cartItem) {
                $quantity += $cartItem->quantity ?? 0;
            }
        }

        return $quantity;
    }

    /**
     * Check if cart is empty
     *
     * @return bool True if empty
     */
    public function isEmpty()
    {
        return count($this->cart->items ?? []) === 0;
    }

    // ========================================================================
    // EVENT HANDLERS
    // ========================================================================

    /**
     * Add events to shipment methods
     *
     * @return void
     */
    public function addEventsToShipments()
    {
        foreach ($this->getShipmentMethods() as $index => &$shipmentMethod) {
            $isSelected = ($this->getData()->id_shipment == $shipmentMethod->id);

            $onCartViewEventName = 'onCartView';

            $shipmentEvent = new ShipmentsCartViewEvent($onCartViewEventName, [
                'subject' => $this,
                'method' => $shipmentMethod,
            ]);

            $plugin = $this->app->bootPlugin($shipmentMethod->type, 'alfa-shipments');

            if (is_object($plugin) && method_exists($plugin, $onCartViewEventName)) {
                $plugin->{$onCartViewEventName}($shipmentEvent);
            }

            if ($isSelected) {
                if (empty($shipmentEvent->getLayoutPluginName())) {
                    $shipmentEvent->setLayoutPluginName($shipmentMethod->type);
                }

                if (empty($shipmentEvent->getLayoutPluginType())) {
                    $shipmentEvent->setLayoutPluginType('alfa-shipments');
                }

                if ($shipmentEvent->hasRedirect()) {
                    $this->app->redirect(
                        $shipmentEvent->getRedirectUrl(),
                        $shipmentEvent->getRedirectCode() ?? 303,
                    );
                    return;
                }
            }

            $shipmentMethod->events = new stdClass();
            $shipmentMethod->events->{$onCartViewEventName} = $shipmentEvent;
        }
    }

    /**
     * Add events to payment methods
     *
     * @return void
     */
    public function addEventsToPayments()
    {
        foreach ($this->getPaymentMethods() as $index => &$paymentMethod) {
            $isSelected = ($this->getData()->id_payment == $paymentMethod->id);
            $onCartViewEventName = 'onCartView';

            $paymentEvent = new PaymentsCartViewEvent($onCartViewEventName, [
                'subject' => $this,
                'method' => $paymentMethod,
            ]);

            $plugin = $this->app->bootPlugin($paymentMethod->type, 'alfa-payments');

            if (is_object($plugin) && method_exists($plugin, $onCartViewEventName)) {
                $plugin->{$onCartViewEventName}($paymentEvent);
            }

            if ($isSelected) {
                if (empty($paymentEvent->getLayoutPluginName())) {
                    $paymentEvent->setLayoutPluginName($paymentMethod->type);
                }

                if (empty($paymentEvent->getLayoutPluginType())) {
                    $paymentEvent->setLayoutPluginType('alfa-payments');
                }

                if ($paymentEvent->hasRedirect()) {
                    $this->app->redirect(
                        $paymentEvent->getRedirectUrl(),
                        $paymentEvent->getRedirectCode() ?? 303,
                    );
                    return;
                }
            }

            $paymentMethod->events = new stdClass();
            $paymentMethod->events->{$onCartViewEventName} = $paymentEvent;
        }
    }
}
