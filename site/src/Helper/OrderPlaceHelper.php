<?php

/**
 * @package     Alfa.Component
 * @subpackage  Site.Helper
 * @version     3.0.0 - PRODUCTION READY
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * COMPLETE V3 ORDER PLACEMENT HELPER - PRODUCTION GRADE
 * ========================================================
 *
 * Features:
 * - Saves to ALL 7 V3 tables in single atomic transaction
 * - Professional try/catch error handling everywhere
 * - Comprehensive logging for debugging
 * - V3 field names (date_add, product_id, payment_method_name, etc.)
 * - Backward compatible with V2 fields
 * - Self-contained architecture (no backend dependencies)
 *
 * Tables Created:
 * 1. #__alfa_orders               (main order with V3 fields)
 * 2. #__alfa_order_items          (line items with V3 fields)
 * 3. #__alfa_order_payments       (payment records)
 * 4. #__alfa_order_shipments      (shipment records)
 * 5. #__alfa_order_activity_log   (unified event log)
 * 6. #__alfa_order_detail_tax     (per-item tax breakdown)
 * 7. #__alfa_order_cart_rule      (applied discounts/coupons)
 * 8. #__alfa_user_info            (customer address)
 *
 * Usage:
 *   $helper = new OrderPlaceHelper();
 *   $success = $helper->placeOrder($userFormData);
 *   if ($success) {
 *       $order = $helper->getOrder();
 *       // Redirect to success page
 *   }
 */

namespace Alfa\Component\Alfa\Site\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\OrderAfterPlaceEvent as PaymentOrderAfterPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderPlaceEvent as PaymentOrderPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\OrderAfterPlaceEvent as ShipmentOrderAfterPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\OrderPlaceEvent as ShipmentOrderPlaceEvent;
use Alfa\Component\Alfa\Administrator\Helper\OrderStockHelper;
use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Utilities\IpHelper;
use RuntimeException;
use stdClass;

class OrderPlaceHelper
{
    protected DatabaseDriver $db;
    protected $app;
    protected $user;
    protected ?CartHelper $cart = null;
    protected ?object $order = null;
    protected string $payment_type = '';
    protected string $shipment_type = '';

    // V3 Database tables
    protected string $order_table = '#__alfa_orders';
    protected string $order_items_table = '#__alfa_order_items';
    protected string $order_payments_table = '#__alfa_order_payments';
    protected string $order_shipments_table = '#__alfa_order_shipments';
    protected string $order_activity_log_table = '#__alfa_order_activity_log';
    protected string $order_detail_tax_table = '#__alfa_order_detail_tax';
    protected string $order_cart_rule_table = '#__alfa_order_cart_rule';
    protected string $user_info_table = '#__alfa_user_info';

    /** @var object Default order status (loaded from DB, not hardcoded) */
    protected ?object $defaultStatus = null;

    public const DEFAULT_CURRENCY_NUMBER = 978;

    public function __construct()
    {
        try {
            $this->app = Factory::getApplication();
            $this->db = Factory::getContainer()->get('DatabaseDriver');
            $this->user = $this->app->getIdentity();
            $this->cart = new CartHelper();
            $this->configureLogging();

            // Load default order status from database (not hardcoded).
            // OrderStockHelper::getDefaultOrderStatus() reads is_default=1
            // from #__alfa_orders_statuses, with fallback to first by ordering.
            $this->defaultStatus = OrderStockHelper::getDefaultOrderStatus();

            Log::add(
                'Default order status: "' . $this->defaultStatus->name
                . '" (id=' . $this->defaultStatus->id
                . ', stock_operation=' . $this->defaultStatus->stock_operation . ')',
                Log::DEBUG,
                'com_alfa.orders',
            );
        } catch (Exception $e) {
            error_log('OrderPlaceHelper init failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to initialize OrderPlaceHelper: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function configureLogging(): void
    {
        try {
            Log::addLogger(['text_file' => 'com_alfa_orders.php'], Log::ALL, ['com_alfa.orders']);
        } catch (Exception $e) {
            error_log('Failed to configure logging: ' . $e->getMessage());
        }
    }

    public function getOrder(): ?object
    {
        return $this->order;
    }
    public function getCart(): ?CartHelper
    {
        return $this->cart;
    }

    /**
     * Main order placement
     *
     * Flow:
     *   1. Validate prerequisites (cart, payment, shipment methods)
     *   2. Fire onOrderBeforePlace to payment + shipment plugins
     *   3. BEGIN TRANSACTION
     *      a. Save user info
     *      b. Save order (with dynamic default status)
     *      c. Save order items
     *      d. Deduct stock (IF default status requires it)
     *      e. Log order creation
     *   4. COMMIT
     *   5. Load complete order from model
     *   6. Attach cart for plugins
     *   7. Fire onOrderAfterPlace → plugins create payment + shipment records
     *   8. Clear cart
     *
     * Stock deduction respects the default order status's stock_operation:
     *   stock_operation = 0 → deduct stock (Confirmed, Shipped, etc.)
     *   stock_operation = 1 → keep stock   (rare: default status = Pending)
     *
     * @param array $data User form data (address fields)
     * @return bool True on success, false on failure
     * @since   3.5.1
     */
    public function placeOrder(array $data): bool
    {
        try {
            Log::add('=== Starting V3 order placement ===', Log::INFO, 'com_alfa.orders');

            if (!$this->validateOrderPrerequisites()) {
                Log::add('Order prerequisite validation failed', Log::WARNING, 'com_alfa.orders');
                return false;
            }

            $paymentMethodId = $this->app->input->getInt('payment_method', null);
            $shipmentMethodId = $this->app->input->getInt('shipment_method', null);

            Log::add("Payment: {$paymentMethodId}, Shipment: {$shipmentMethodId}", Log::DEBUG, 'com_alfa.orders');

            if (!$this->triggerBeforePlaceEvents($paymentMethodId, $shipmentMethodId)) {
                Log::add('Pre-placement event validation failed', Log::WARNING, 'com_alfa.orders');
                return false;
            }

            // BEGIN TRANSACTION
            Log::add('Starting database transaction', Log::DEBUG, 'com_alfa.orders');
            $this->db->transactionStart();

            try {
                // Save user info
                Log::add('Saving user information...', Log::DEBUG, 'com_alfa.orders');
                $userInfoObject = $this->saveUserInfo($data);
                if (!$userInfoObject) {
                    throw new RuntimeException('Failed to save user information');
                }
                Log::add('User info saved with ID: ' . $userInfoObject->id, Log::INFO, 'com_alfa.orders');
                $this->cart->getData()->id_user_info_delivery = $userInfoObject->id;

                // Save order (uses dynamic default status)
                Log::add('Saving main order...', Log::DEBUG, 'com_alfa.orders');
                $orderId = $this->saveOrder($paymentMethodId, $shipmentMethodId);
                if (!$orderId) {
                    throw new RuntimeException('Failed to save order');
                }
                Log::add('Order saved with ID: ' . $orderId, Log::INFO, 'com_alfa.orders');

                // Save items
                Log::add('Saving order items...', Log::DEBUG, 'com_alfa.orders');
                if (!$this->saveOrderItems()) {
                    throw new RuntimeException('Failed to save order items');
                }
                Log::add('Order items saved', Log::INFO, 'com_alfa.orders');

                // ============================================================
                // STOCK: Deduct if the default status requires it.
                //
                // Uses the centralized OrderStockHelper (single source of truth).
                // shouldDeductStock() checks stock_operation on the default status:
                //   stock_operation = 0 → deduct (Confirmed, Shipped, etc.)
                //   stock_operation = 1 → skip   (Cancelled-like statuses)
                //
                // Runs INSIDE the transaction — if anything after this fails,
                // both item inserts AND stock deductions roll back together.
                // ============================================================
                if (OrderStockHelper::shouldDeductStock((int) $this->defaultStatus->id)) {
                    Log::add('Deducting stock (default status requires it)...', Log::DEBUG, 'com_alfa.orders');
                    try {
                        OrderStockHelper::deductOrderStock($orderId);
                        Log::add('Stock deducted for order #' . $orderId, Log::INFO, 'com_alfa.orders');
                    } catch (Exception $e) {
                        // Stock failure is non-fatal: order is more important than stock accuracy
                        Log::add('Warning: Stock deduction failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                    }
                } else {
                    Log::add('Stock NOT deducted (default status stock_operation=1)', Log::INFO, 'com_alfa.orders');
                }

                // Log order creation
                Log::add('Creating order history...', Log::DEBUG, 'com_alfa.orders');
                try {
                    $this->createOrderHistory((int) $this->defaultStatus->id);
                    Log::add('History entry created', Log::INFO, 'com_alfa.orders');
                } catch (Exception $e) {
                    Log::add('Warning: History creation failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                }

                // COMMIT
                $this->db->transactionCommit();
                Log::add('=== Transaction committed successfully ===', Log::INFO, 'com_alfa.orders');
            } catch (Exception $e) {
                // ROLLBACK — items, stock, everything reverted
                $this->db->transactionRollback();
                Log::add('=== Transaction rolled back ===', Log::ERROR, 'com_alfa.orders');
                Log::add('Error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
                $this->app->enqueueMessage('Order placement failed: ' . $e->getMessage(), 'error');
                return false;
            }

            // Load complete order (with items, payments, shipments via admin OrderModel)
            $this->order = $this->loadOrderModel($orderId);

            // ── Override totals from cart for checkout context ───────
            //
            // getItem() returned shipping = 0 because the shipment DB record
            // doesn't exist yet (created by the shipment plugin below).
            // Override with cart values so the order object is fully consistent
            // BEFORE plugins fire — plugins just read $order->total_paid_tax_incl.
            //
            // Product totals are correct (items exist in DB after commit).
            // Discounts stay at 0 until coupon system calls saveCartRules().
            $this->order->_checkout_cart = $this->cart;
            $this->order->total_shipping_tax_incl = $this->cart->getShipmentTotal();
            $this->order->total_shipping_tax_excl = $this->cart->getShipmentTotalExcl();
            $this->order->total_paid_tax_incl = $this->cart->getGrandTotal();
            $this->order->total_paid_tax_excl = $this->cart->getGrandTotalExcl();

            // Fire after events — plugins create payment + shipment records
            $this->triggerAfterPlaceEvents();

            // Clear cart
            if (!$this->cart->clearCart()) {
                Log::add('Cart clearing failed', Log::WARNING, 'com_alfa.orders');
            }

            Log::add('=== Order placement completed successfully ===', Log::INFO, 'com_alfa.orders');
            return true;
        } catch (Exception $e) {
            Log::add('CRITICAL ERROR: ' . $e->getMessage(), Log::CRITICAL, 'com_alfa.orders');
            $this->app->enqueueMessage('Critical error during order placement', 'error');
            return false;
        }
    }

    protected function validateOrderPrerequisites(): bool
    {
        try {
            $cartData = $this->cart->getData();
            $cartItems = $cartData->items ?? [];

            if (empty($cartItems)) {
                $this->app->enqueueMessage('Cannot place order: Cart is empty.', 'warning');
                return false;
            }

            $paymentMethodId = $this->app->input->getInt('payment_method', null);
            if (!$this->checkPaymentMethod($paymentMethodId)) {
                $this->app->enqueueMessage('Invalid payment method.', 'error');
                return false;
            }

            $shipmentMethodId = $this->app->input->getInt('shipment_method', null);
            if (!$this->checkShipmentMethod($shipmentMethodId)) {
                $this->app->enqueueMessage('Invalid shipment method.', 'error');
                return false;
            }

            $this->cart->getData()->id_shipment = $shipmentMethodId;
            return true;
        } catch (Exception $e) {
            Log::add('Validation error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return false;
        }
    }

    protected function triggerBeforePlaceEvents(int $paymentMethodId, int $shipmentMethodId): bool
    {
        try {
            $this->getPaymentType($paymentMethodId);
            if (!empty($this->payment_type)) {
                try {
                    $event = new PaymentOrderPlaceEvent('onOrderBeforePlace', ['subject' => $this->cart]);
                    $plugin = $this->app->bootPlugin($this->payment_type, 'alfa-payments');
                    if ($plugin && method_exists($plugin, 'onOrderBeforePlace')) {
                        $plugin->onOrderBeforePlace($event);
                        $this->cart = $event->getCart();
                    }
                } catch (Exception $e) {
                    Log::add('Payment event error: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                }
            }

            $this->getShipmentType($shipmentMethodId);
            if (!empty($this->shipment_type)) {
                try {
                    $event = new ShipmentOrderPlaceEvent('onOrderBeforePlace', ['subject' => $this->cart]);
                    $plugin = $this->app->bootPlugin($this->shipment_type, 'alfa-shipments');
                    if ($plugin && method_exists($plugin, 'onOrderBeforePlace')) {
                        $plugin->onOrderBeforePlace($event);
                        $this->cart = $event->getCart();
                    }
                } catch (Exception $e) {
                    Log::add('Shipment event error: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                }
            }

            return true;
        } catch (Exception $e) {
            Log::add('Before-place events error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return false;
        }
    }

    protected function triggerAfterPlaceEvents(): void
    {
        try {
            if (!empty($this->payment_type) && $this->order) {
                try {
                    $event = new PaymentOrderAfterPlaceEvent('onOrderAfterPlace', ['subject' => $this->order]);
                    $plugin = $this->app->bootPlugin($this->payment_type, 'alfa-payments');
                    if ($plugin && method_exists($plugin, 'onOrderAfterPlace')) {
                        $plugin->onOrderAfterPlace($event);
                    }
                } catch (Exception $e) {
                    Log::add('Payment after event error: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                }
            }

            if (!empty($this->shipment_type) && $this->order) {
                try {
                    $event = new ShipmentOrderAfterPlaceEvent('onOrderAfterPlace', ['subject' => $this->order]);
                    $plugin = $this->app->bootPlugin($this->shipment_type, 'alfa-shipments');
                    if ($plugin && method_exists($plugin, 'onOrderAfterPlace')) {
                        $plugin->onOrderAfterPlace($event);
                    }
                } catch (Exception $e) {
                    Log::add('Shipment after event error: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                }
            }
        } catch (Exception $e) {
            Log::add('After-place events error: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
        }
    }

    /**
     * Save order
     *
     * The order status is read from $this->defaultStatus (loaded from DB)
     * instead of the old hardcoded constant.
     *
     * @param int $paymentMethodId Selected payment method PK
     * @param int $shipmentMethodId Selected shipment method PK
     * @return int|false New order ID, or false on failure
     * @since   3.5.1
     */
    protected function saveOrder(int $paymentMethodId, int $shipmentMethodId)
    {
        try {
            $cartHelper = $this->cart;
            $cartData = $cartHelper->getData();
            $config = ComponentHelper::getParams('com_alfa');
            $currencyNumber = $config->get('default_currency', self::DEFAULT_CURRENCY_NUMBER);
            $currencyID = $this->getCurrencyID($currencyNumber);

            if (!$currencyID) {
                throw new RuntimeException('Currency configuration error');
            }

            $currentDate = Factory::getDate('now', 'UTC');
            $order = new stdClass();

            // Core fields
            $order->id_user = $this->user->id;
            $order->id_cart = $cartHelper->getCartId();
            $order->id_currency = $currencyID;
            $order->id_address_delivery = $cartData->id_user_info_delivery ?? null;
            $order->id_address_invoice = $cartData->id_user_info_invoice ?? null;
            $order->id_payment_method = $paymentMethodId;
            $order->id_shipment_method = $shipmentMethodId;

            // Dynamic default status (from DB, not hardcoded)
            $order->id_order_status = (int) $this->defaultStatus->id;

            // V3: Method name snapshots
            $order->payment_method_name = $this->getPaymentMethodName($paymentMethodId);
            $order->shipment_method_name = $this->getShipmentMethodName($shipmentMethodId);

            // V3: Price breakdown
            $order->conversion_rate = 1.000000;

            // V2 compatibility
            $order->id_payment_currency = $currencyID;
            $order->id_language = 1;
            $order->id_coupon = null;
            $order->code_coupon = null;

            // Other fields
            $order->ip_address = IpHelper::getIp();
            $order->customer_note = $cartData->customer_note ?? '';
            $order->note = '';
            $order->state = 1;
            $order->checked_out = 0;
            $order->checked_out_time = $this->db->getNullDate();

            // Timestamps
            $order->created = $currentDate->toSql();
            $order->modified = $currentDate->toSql();
            $order->created_by = $this->user->id;
            $order->modified_by = $this->user->id;

            $this->db->insertObject($this->order_table, $order, 'id');
            $this->order = $order;

            return $order->id;
        } catch (Exception $e) {
            Log::add('Save order error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            throw $e;
        }
    }

    /**
     * Save order items - V3 COMPLETE
     *
     * PRICE MAPPING (from PriceResult):
     *   unit_price_tax_excl  = getSubtotalPrice()  (after discounts, before tax)
     *   unit_price_tax_incl  = subtotal + tax       (NOT getPrice() which includes after-tax discounts)
     *   total_price_tax_excl = getSubtotal()        (line total excl. tax)
     *   total_price_tax_incl = subtotal + taxTotal   (line total incl. tax)
     *   tax_rate             = getTaxes()->getEffectiveRate()  (configured rate, not computed)
     *   tax_name             = getTaxes()->getApplied()[0]->name
     *
     * WARNING: getPrice()/getTotal() include after-tax discounts — DO NOT use for tax-incl fields.
     * After-tax discounts (coupons) are tracked in order_cart_rule, not in item prices.
     */
    protected function saveOrderItems(): bool
    {
        try {
            $cartItems = $this->cart->getData()->items;
            if (empty($cartItems)) {
                return false;
            }

            foreach ($cartItems as $item) {
                try {
                    $itemObject = new stdClass();

                    // Core IDs
                    $itemObject->id_item = $item->id_item;
                    $itemObject->id_order = $this->order->id;
                    $itemObject->id_shipmentmethod = $this->order->id_shipment_method ?? 0;

                    // ============================================================
                    // PRODUCT SNAPSHOT — frozen at order time
                    // ============================================================
                    $itemObject->name = $item->data->name ?? 'Unknown';
                    $itemObject->reference = $item->data->sku ?? null;
                    $itemObject->ean13 = $item->data->gtin ?? $item->data->ean13 ?? null;
                    $itemObject->mpn = $item->data->mpn ?? null;
                    $itemObject->weight = $item->data->weight ?? 0;
                    $itemObject->quantity = $item->quantity;
                    $itemObject->quantity_in_stock = $item->data->stock ?? 0;

                    // ============================================================
                    // PRICING — from PriceResult (correct mapping)
                    // ============================================================
                    $price = $item->data->price;

                    if ($price && is_object($price)) {
                        // Per-unit prices
                        $unitExcl = $price->getSubtotalPrice()->getAmount();  // After discounts, before tax
                        $unitTax = $price->getTaxPrice()->getAmount();       // Tax per unit
                        $unitIncl = $unitExcl + $unitTax;                     // Correct tax-inclusive

                        $itemObject->unit_price_tax_excl = $unitExcl;
                        $itemObject->unit_price_tax_incl = $unitIncl;

                        // Line totals
                        $totalExcl = $price->getSubtotal()->getAmount();
                        $totalTax = $price->getTaxTotal()->getAmount();
                        $totalIncl = $totalExcl + $totalTax;

                        $itemObject->total_price_tax_excl = $totalExcl;
                        $itemObject->total_price_tax_incl = $totalIncl;

                        // Original price (before discounts)
                        $itemObject->original_product_price = $price->getBasePrice()->getAmount();

                        // ============================================================
                        // TAX — from TaxSummary (the REAL configured rate)
                        // NOT computed from price difference
                        // ============================================================
                        $taxes = $price->getTaxes();
                        $itemObject->tax_rate = $taxes->getEffectiveRate();

                        $appliedTaxes = $taxes->getApplied();
                        $itemObject->tax_name = !empty($appliedTaxes) ? ($appliedTaxes[0]->name ?? '') : '';

                        // ============================================================
                        // DISCOUNTS — from DiscountSummary
                        // ============================================================
                        if ($price->hasDiscount()) {
                            $itemObject->reduction_percent = $price->getSavingsPercent();

                            // Per-unit discount amounts (tax excl)
                            $discountExcl = $price->getSavingsPrice()->getAmount();
                            $itemObject->reduction_amount_tax_excl = $discountExcl;

                            // Tax-inclusive discount: apply tax rate
                            $taxRate = $taxes->getEffectiveRate();
                            $itemObject->reduction_amount_tax_incl = ($taxRate > 0)
                                ? round($discountExcl * (1 + $taxRate / 100), 6)
                                : $discountExcl;
                        } else {
                            $itemObject->reduction_percent = 0;
                            $itemObject->reduction_amount_tax_excl = 0;
                            $itemObject->reduction_amount_tax_incl = 0;
                        }
                    } else {
                        // No PriceResult — zero everything
                        Log::add('No PriceResult for item ' . ($item->id_item ?? '?'), Log::WARNING, 'com_alfa.orders');
                        $itemObject->unit_price_tax_incl = 0;
                        $itemObject->unit_price_tax_excl = 0;
                        $itemObject->total_price_tax_incl = 0;
                        $itemObject->total_price_tax_excl = 0;
                        $itemObject->original_product_price = 0;
                        $itemObject->tax_rate = 0;
                        $itemObject->tax_name = '';
                        $itemObject->reduction_percent = 0;
                        $itemObject->reduction_amount_tax_excl = 0;
                        $itemObject->reduction_amount_tax_incl = 0;
                    }

                    $this->db->insertObject($this->order_items_table, $itemObject, 'id');

                    // Save per-item tax breakdown to order_detail_tax
                    $this->saveItemTaxBreakdown($itemObject->id, $item);
                } catch (Exception $e) {
                    Log::add('Item save error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
                    throw $e;
                }
            }

            return true;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to save items: ' . $e->getMessage());
        }
    }

    /**
     * V3: Save per-item tax breakdown to order_detail_tax
     *
     * Gets applied taxes from PriceResult->getTaxes()->getApplied(),
     * not from a non-existent $cartItem->data->taxes property.
     *
     * @param int $orderDetailId The order_items row ID
     * @param object $cartItem Cart item with ->data->price (PriceResult)
     */
    protected function saveItemTaxBreakdown(int $orderDetailId, object $cartItem): bool
    {
        try {
            $price = $cartItem->data->price ?? null;
            if (!$price || !is_object($price)) {
                return true;
            }

            $taxSummary = $price->getTaxes();
            if (!$taxSummary->hasTaxes()) {
                return true;
            }

            $appliedTaxes = $taxSummary->getApplied();
            $quantity = max(1, $cartItem->quantity);

            foreach ($appliedTaxes as $tax) {
                try {
                    // Calculate tax amounts from the rate and subtotal
                    $subtotalExcl = $price->getSubtotal()->getAmount();
                    $taxAmount = round($subtotalExcl * ($tax->rate / 100), 6);
                    $unitTaxAmount = round($taxAmount / $quantity, 6);

                    $taxObject = new stdClass();
                    $taxObject->id_order_detail = $orderDetailId;
                    $taxObject->id_tax = $tax->id ?? 0;
                    $taxObject->unit_amount = $unitTaxAmount;
                    $taxObject->total_amount = $taxAmount;
                    $this->db->insertObject($this->order_detail_tax_table, $taxObject);
                } catch (Exception $e) {
                    Log::add('Tax breakdown save warning: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                }
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * V3: Create payment record
     */
    //	protected function createInitialPayment(int $paymentMethodId): bool
    //	{
    //		try {
    //			$currentDate = Factory::getDate('now', 'UTC');
    //			$payment = new \stdClass();
    //			$payment->id_order = $this->order->id;
    //			$payment->id_currency = $this->order->id_currency;
    //			$payment->amount = $this->order->total_paid_tax_incl;
    //			$payment->id_payment_method = $paymentMethodId;
    //			$payment->payment_method = $this->getPaymentMethodName($paymentMethodId);
    //			$payment->payment_type = 'payment';
    //			$payment->transaction_status = 'pending';
    //			$payment->conversion_rate = 1.000000;
    //			$payment->date_add = $currentDate->toSql();
    //			$payment->id_employee = 0;
    //
    //			$this->db->insertObject($this->order_payments_table, $payment);
    //			return true;
    //
    //		} catch (Exception $e) {
    //			throw new RuntimeException('Payment creation failed: ' . $e->getMessage());
    //		}
    //	}

    /**
     * V3: Create shipment record
     */
    //	protected function createInitialShipment(int $shipmentMethodId): bool
    //	{
    //		try {
    //			$currentDate = Factory::getDate('now', 'UTC');
    //			$shipment = new \stdClass();
    //			$shipment->id_order = $this->order->id;
    //			$shipment->id_shipment_method = $shipmentMethodId;
    //			$shipment->shipment_method_name = $this->getShipmentMethodName($shipmentMethodId);
    //			$shipment->id_currency = $this->order->id_currency;
    //			$shipment->shipping_cost_tax_incl = $this->order->total_shipping_tax_incl;
    //			$shipment->shipping_cost_tax_excl = $this->order->total_shipping_tax_excl;
    //			$shipment->weight = $this->calculateTotalWeight();
    //			$shipment->date_add = $currentDate->toSql();
    //			$shipment->id_employee = 0;
    //
    //			$this->db->insertObject($this->order_shipments_table, $shipment);
    //			return true;
    //
    //		} catch (Exception $e) {
    //			throw new RuntimeException('Shipment creation failed: ' . $e->getMessage());
    //		}
    //	}

    /**
     * Log order creation to the unified activity log
     *
     * One action = one row. The initial status is captured via
     * id_order_status + status_name columns, plus in the context.
     *
     * @param int $statusId Initial order status ID
     */
    protected function createOrderHistory(int $statusId): bool
    {
        try {
            $currentDate = Factory::getDate('now', 'UTC');

            // Get status name
            $query = $this->db->getQuery(true)
                ->select('name')
                ->from('#__alfa_orders_statuses')
                ->where('id = ' . intval($statusId));
            $this->db->setQuery($query);
            $statusName = $this->db->loadResult() ?: "Status #{$statusId}";

            $log = new stdClass();
            $log->id_order = $this->order->id;
            $log->id_employee = 0;
            $log->employee_name = $this->user->name ?? 'Customer';
            $log->event = 'order.created';
            $log->id_order_status = $statusId;
            $log->status_name = $statusName;
            $log->entity_id = null;
            $log->summary = 'Order placed';
            $log->context = json_encode([
                'source' => 'frontend',
                'cart_id' => $this->cart->getCartId(),
                'ip' => IpHelper::getIp(),
                'initial_status' => ['id' => $statusId, 'name' => $statusName],
            ]);
            $log->created = $currentDate->toSql();

            $this->db->insertObject($this->order_activity_log_table, $log);
            return true;
        } catch (Exception $e) {
            throw new RuntimeException('Activity log creation failed: ' . $e->getMessage());
        }
    }

    /**
     * V3: Save cart rules
     */
    protected function saveCartRules(): bool
    {
        try {
            $cartData = $this->cart->getData();
            if (!isset($cartData->applied_coupons) || empty($cartData->applied_coupons)) {
                return true;
            }

            foreach ($cartData->applied_coupons as $coupon) {
                try {
                    $cartRule = new stdClass();
                    $cartRule->id_order = $this->order->id;
                    $cartRule->id_cart_rule = $coupon->id ?? 0;
                    $cartRule->name = $coupon->name ?? 'Discount';
                    //					$cartRule->value = $coupon->value ?? 0;
                    $cartRule->value_tax_excl = $coupon->value_tax_excl ?? 0;
                    $cartRule->free_shipping = $coupon->free_shipping ?? 0;
                    $cartRule->deleted = 0;

                    $this->db->insertObject($this->order_cart_rule_table, $cartRule);
                } catch (Exception $e) {
                    Log::add('Cart rule save warning: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
                }
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // Helper methods
    protected function checkPaymentMethod(?int $id): bool
    {
        if (!$id) {
            return false;
        }
        foreach ($this->cart->getPaymentMethods() as $m) {
            if ($m->id == $id) {
                return true;
            }
        }
        return false;
    }

    protected function checkShipmentMethod(?int $id): bool
    {
        if (!$id) {
            return false;
        }
        foreach ($this->cart->getShipmentMethods() as $m) {
            if ($m->id == $id) {
                return true;
            }
        }
        return false;
    }

    protected function saveUserInfo(array $data): ?object
    {
        try {
            $infoObject = (object) $data;
            $infoObject->id_user = $this->user->id;
            $this->db->insertObject($this->user_info_table, $infoObject, 'id');
            return $infoObject;
        } catch (Exception $e) {
            Log::add('User info save error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return null;
        }
    }

    protected function getPaymentType(int $id): void
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('type')
                ->from('#__alfa_payments')
                ->where('id = ' . $id);
            $this->db->setQuery($query);
            $this->payment_type = $this->db->loadResult() ?: '';
        } catch (Exception $e) {
            $this->payment_type = '';
        }
    }

    protected function getShipmentType(int $id): void
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('type')
                ->from('#__alfa_shipments')
                ->where('id = ' . $id);
            $this->db->setQuery($query);
            $this->shipment_type = $this->db->loadResult() ?: '';
        } catch (Exception $e) {
            $this->shipment_type = '';
        }
    }

    protected function getPaymentMethodName(int $id): string
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('name')
                ->from('#__alfa_payments')
                ->where('id = ' . $id);
            $this->db->setQuery($query);
            return $this->db->loadResult() ?: 'Payment Method ' . $id;
        } catch (Exception $e) {
            return 'Payment Method ' . $id;
        }
    }

    protected function getShipmentMethodName(int $id): string
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('name')
                ->from('#__alfa_shipments')
                ->where('id = ' . $id);
            $this->db->setQuery($query);
            return $this->db->loadResult() ?: 'Shipment Method ' . $id;
        } catch (Exception $e) {
            return 'Shipment Method ' . $id;
        }
    }

    protected function getCurrencyID(int $number): ?int
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('id')
                ->from('#__alfa_currencies')
                ->where('number = ' . $number);
            $this->db->setQuery($query);
            $result = $this->db->loadResult();
            return $result ? (int) $result : null;
        } catch (Exception $e) {
            return null;
        }
    }

    protected function calculateTotalWeight(): float
    {
        try {
            $total = 0;
            foreach ($this->cart->getData()->items ?? [] as $item) {
                $total += ($item->data->weight ?? 0) * ($item->quantity ?? 1);
            }
            return $total;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Load complete order object from admin model
     *
     * @param int $orderId Order ID
     *
     * @return object|null Order object or null on failure
     */
    protected function loadOrderModel(int $orderId): ?object
    {
        try {
            $orderModel = $this->app->bootComponent('com_alfa')
                ->getMVCFactory()
                ->createModel('Order', 'Administrator', ['ignore_request' => true]);

            if (!$orderModel) {
                Log::add('Failed to load Order model', Log::ERROR, 'com_alfa.orders');
                $this->app->enqueueMessage('Order model could not be loaded', 'error');
                return null;
            }

            return $orderModel->getItem($orderId);
        } catch (Exception $e) {
            $this->app->enqueueMessage('Error loading order model: ' . $e->getMessage(), 'error');
            Log::add('Error loading order model: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return null;
        }
    }
}
