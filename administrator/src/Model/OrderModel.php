<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Order Model — Admin CRUD for orders and related entities
 *
 * Handles orders, payments, shipments, and order items through
 * a unified architecture with shared helpers.
 *
 * Architecture:
 *   Reusable helpers   → loadCurrency(), getRelatedModel(), toMoney(), moneyToFloat(),
 *                         getMethodName(), saveDataOnTable(), deleteTableEntry()
 *   Data loading       → getItem(), getPaymentData(), getShipmentData(), getOrderItemData()
 *   Save / Delete      → save(), saveOrderPayment(), saveOrderShipment(), saveOrderItem()
 *   Activity log       → logOrderActivity(), getOrderActivityLog()
 *
 * Totals:
 *   grand_total = items + shipping - discounts
 *   Computed by getItem() via OrderTotalHelper::computeFromArrays().
 *   Single source of truth — same method used by OrdersModel list view.
 *   No total columns stored on #__alfa_orders — always computed.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

//use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderPrepareFormEvent as ShipmentOrderPrepareFormEvent;
//use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderPrepareFormEvent as PaymentOrderPrepareFormEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\AdminOrderDeleteEvent as PaymentBeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\AdminOrderDeleteEvent as ShipmentBeforeDeleteEvent;
use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderShipmentHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderStockHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\AdminModel;
use stdClass;

class OrderModel extends AdminModel
{
    protected $text_prefix = 'COM_ALFA';
    public $typeAlias = 'com_alfa.order';
    protected $formName = 'order';
    protected $item = null;

    // ========================================================================
    // FORM METHODS
    // ========================================================================

    public function getShipmentForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.order', 'order_shipments', [
            'control' => 'jform',
            'load_data' => false,
        ]);

        if (empty($form)) {
            return false;
        }

        $app = Factory::getApplication();
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        $id = (int) $this->getState('order_shipments.id', $idFromInput);
        $orderId = (int) $this->getState('order.id', $orderIdFromInput);

        if ($id > 0) {
            $shipment = $this->getShipmentData($id);
        }

        // Convert object → array with Money→float for form binding
        if (is_object($data)) {
            $data = $this->objectToFormData($data);
        }

        if (is_array($data)) {
            if (!isset($data['id'])) {
                $data['id'] = $id;
            }
            if (!isset($data['id_order'])) {
                $data['id_order'] = $orderId;
            }
        }

        // ── Inject shipment status options ──────────────────────────────
        // The XML declares <field name="status" type="list"> with NO static
        // options. We inject them here from OrderShipmentHelper constants
        // so there's a single source of truth for all valid statuses.
        $shipStatusField = $form->getField('status');
        if ($shipStatusField) {
            foreach (OrderShipmentHelper::allStatuses() as $status) {
                $shipStatusField->addOption(
                    Text::_('COM_ALFA_STATUS_' . strtoupper($status)),
                    ['value' => $status],
                );
            }
        }

        $form->bind($data);

        // Pre-select items assigned to this shipment (edit only)
        // The 'items' field is a multi-select — needs an array of item IDs
        if ($id > 0) {
            $assignedItemIds = $this->getShipmentItemIds($id);
            $form->setValue('items', null, $assignedItemIds);
        }

        $order = $this->getItem($orderId);

        //		if ($id > 0 && isset($shipment->params)) {
        //			$onAdminOrderPrepareFormEventName = "onAdminOrderShipmentPrepareForm";
        //			$shipmentPrepareFormEvent = new ShipmentOrderPrepareFormEvent($onAdminOrderPrepareFormEventName, [
        //				"subject" => $form,
        //				"method" => $shipment,
        //				"data" => $order
        //			]);
        //
        //			$orderShipmentType = $shipment->params->type;
        //			Factory::getApplication()->bootPlugin($orderShipmentType, "alfa-shipments")
        //				->{$onAdminOrderPrepareFormEventName}($shipmentPrepareFormEvent);
        //		}

        return $form;
    }

    public function getPaymentForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.order', 'order_payments', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        if (empty($form)) {
            return false;
        }

        $app = Factory::getApplication();
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        $id = (int) $this->getState('order_payments.id', $idFromInput);
        $orderId = (int) $this->getState('order.id', $orderIdFromInput);

        if ($id > 0) {
            $payment = $this->getPaymentData($id);
        }

        // Convert object → array with Money→float for form binding
        if (is_object($data)) {
            $data = $this->objectToFormData($data);
        }

        if (is_array($data)) {
            if (!isset($data['id'])) {
                $data['id'] = $id;
            }
            if (!isset($data['id_order'])) {
                $data['id_order'] = $orderId;
            }
        }

        // ── Inject payment_type options ─────────────────────────────────
        // XML declares <field name="payment_type" type="list"> with NO static
        // options. Injected from OrderPaymentHelper::allTypes() (single source of truth).
        $paymentTypeField = $form->getField('payment_type');
        if ($paymentTypeField) {
            foreach (OrderPaymentHelper::allTypes() as $type) {
                $paymentTypeField->addOption(
                    Text::_('COM_ALFA_PAYMENT_TYPE_' . strtoupper($type)),
                    ['value' => $type],
                );
            }
        }

        // ── Inject transaction_status options ───────────────────────────
        // Same pattern — injected from OrderPaymentHelper::allStatuses().
        $txnStatusField = $form->getField('transaction_status');
        if ($txnStatusField) {
            foreach (OrderPaymentHelper::allStatuses() as $status) {
                $txnStatusField->addOption(
                    Text::_('COM_ALFA_STATUS_' . strtoupper($status)),
                    ['value' => $status],
                );
            }
        }

        // ── Inject refund_type options ──────────────────────────────────
        // Empty first option ("—") for non-refund payments, then full/partial.
        $refundTypeField = $form->getField('refund_type');
        if ($refundTypeField) {
            $refundTypeField->addOption('—', ['value' => '']);
            foreach (OrderPaymentHelper::allRefundTypes() as $type) {
                $refundTypeField->addOption(
                    Text::_('COM_ALFA_REFUND_TYPE_' . strtoupper($type)),
                    ['value' => $type],
                );
            }
        }

        $form->bind($data);

        return $form;
    }

    public function getForm($data = [], $loadData = true)
    {
        $app = Factory::getApplication();

        $form = $this->loadForm('com_alfa.order', 'order', [
            'control' => 'jform',
            'load_data' => $loadData,
        ]);

        if (empty($form)) {
            return false;
        }

        $idFromInput = $app->getInput()->getInt('id', 0);
        $id = (int) $this->getState($this->formName . '.id', $idFromInput);
        $this->item = $this->getItem($id);

        if ($id == 0) {
            $form->setFieldAttribute('user_email', 'readonly', 'false');
            $form->setFieldAttribute('user_email', 'class', '');
            $form->setFieldAttribute('user_name', 'readonly', 'false');
            $form->setFieldAttribute('user_name', 'class', '');
        }

        FieldsHelper::prepareForm('com_alfa.order', $form, $data);

        foreach ($form->getFieldsets() as $fieldset) {
            if (!str_starts_with($fieldset->name, 'fields-')) {
                continue;
            }

            foreach ($form->getFieldset($fieldset->name) as $field) {
                $fieldName = str_replace(['jform[com_alfa][', ']'], '', $field->name);
                $form->setValue($fieldName, 'com_alfa', $this->item->user_info->{$fieldName} ?? '');
            }
        }

        return $form;
    }

    protected function loadFormData()
    {
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

    // ========================================================================
    // GET ORDER DATA (WITH MONEY OBJECTS)
    //
    // Loading sequence:
    //   1. Order base row (parent::getItem) + Currency
    //   2. Items (with Money objects)
    //   3. User info
    //   4. Payments → total_paid_real (Money objects)
    //   5. Shipments → per-shipment costs (Money objects)
    //   6. Order totals via OrderTotalHelper::computeFromArrays()
    //      Uses already-loaded items + shipments + discounts from DB.
    //      Single source of truth — same formula as OrdersModel.
    //   7. History
    // ========================================================================

    public function getItem($pk = null)
    {
        if ($order = parent::getItem($pk)) {
            $db = $this->getDatabase();

            // Load currency for Money objects
            $currency = $this->loadCurrency($order->id_currency);

            // ================================================================
            // 1. GET ORDER ITEMS (WITH MONEY OBJECTS)
            // ================================================================
            $order->items = OrderHelper::getOrderItems($order->id, $currency);

            // Store currency object
            $order->currency = $currency;

            // ================================================================
            // GET USER INFO
            // ================================================================
            if ($order->id_address_delivery) {
                $query = $db->getQuery(true);
                $query->select('*')
                    ->from('#__alfa_user_info')
                    ->where('id = ' . $db->quote($order->id_address_delivery));
                $db->setQuery($query);
                $order->user_info = $db->loadObject();
            }

            // ================================================================
            // GET PAYMENTS (WITH MONEY OBJECTS)
            // ================================================================
            $paymentMethods = $this->getOrderPayments($order->id);
            $paymentModel = $this->getRelatedModel('Payment');

            $order->payments = [];
            $order->total_paid_real = Money::zero($currency);

            foreach ($paymentMethods as $paymentMethod) {
                $payment = $paymentMethod;
                $payment->params = $paymentModel->getItem($paymentMethod->id_payment_method);

                // Convert to Money objects
                $paymentAmount = $this->toMoney($payment->amount, $currency);
                $payment->amount = $paymentAmount;
                $payment->conversion_rate = (float) ($payment->conversion_rate ?? 1.0);

                // Accumulate completed payments into total_paid_real
                if ($payment->transaction_status === 'completed' && $payment->payment_type === 'payment') {
                    $order->total_paid_real = $order->total_paid_real->add($paymentAmount);
                }

                // Load plugin actions for this payment (Refund, Capture, etc.)
                $payment->actions = ActionRegistry::getPaymentActions($payment, $order);

                $order->payments[] = $payment;
            }

            $order->selected_payment = $paymentModel->getItem($order->id_payment_method);

            // ================================================================
            // GET SHIPMENTS (WITH MONEY OBJECTS)
            // ================================================================
            $shipmentMethods = $this->getOrderShipments($order->id);
            $shipmentModel = $this->getRelatedModel('Shipment');

            $order->shipments = [];
            foreach ($shipmentMethods as $shipmentMethod) {
                $shipment = $shipmentMethod;
                $shipment->params = $shipmentModel->getItem($shipmentMethod->id_shipment_method);

                // Convert to Money objects
                $shipment->shipping_cost_tax_incl = $this->toMoney($shipment->shipping_cost_tax_incl, $currency);
                $shipment->shipping_cost_tax_excl = $this->toMoney($shipment->shipping_cost_tax_excl, $currency);
                $shipment->weight = (float) ($shipment->weight ?? 0);

                // Items assigned to this shipment (for list display)
                $shipment->items = $this->getShipmentItemNames((int) $shipment->id);

                $shipment->actions = ActionRegistry::getShipmentActions($shipment, $order);

                $order->shipments[] = $shipment;
            }

            $order->selected_shipment = $shipmentModel->getItem($order->id_shipment_method);

            // ================================================================
            // 5. CALCULATE ORDER TOTALS — Single Source of Truth
            //
            // Delegates to OrderTotalHelper::computeFromArrays() which
            // contains THE formula: grand_total = items + shipping - discounts
            //
            // Uses already-loaded entities from steps 1 and 4.
            // Only discounts need a DB query (not loaded elsewhere).
            //
            // The helper handles:
            //   - Money object extraction (via extractFloat)
            //   - Discount tax-inclusive approximation (excl × avg tax multiplier)
            //   - Both incl and excl totals in one call
            // ================================================================

            // Load discount cart rules (only entity not loaded in earlier steps)
            $discountQuery = $db->getQuery(true)
                ->select(['value_tax_excl'])
                ->from('#__alfa_order_cart_rule')
                ->where('id_order = ' . (int) $order->id)
                ->where('deleted = 0');
            $db->setQuery($discountQuery);
            $discountRows = $db->loadObjectList() ?: [];

            // Compute ALL totals via the single source of truth
            $totals = OrderTotalHelper::computeFromArrays(
                $order->items,     // Step 1: loaded items (with Money objects)
                $order->shipments, // Step 4: loaded shipments (with Money objects)
                $discountRows,      // Cart rules from DB (float only)
            );

            // Wrap results as Money objects for the view templates
            $order->total_products_tax_incl = Money::of($totals->items_tax_incl, $currency);
            $order->total_products_tax_excl = Money::of($totals->items_tax_excl, $currency);
            $order->total_shipping_tax_incl = Money::of($totals->shipping_tax_incl, $currency);
            $order->total_shipping_tax_excl = Money::of($totals->shipping_tax_excl, $currency);
            $order->total_discounts_tax_incl = Money::of($totals->discount_tax_incl, $currency);
            $order->total_discounts_tax_excl = Money::of($totals->discount_tax_excl, $currency);
            $order->total_paid_tax_incl = Money::of($totals->grand_total_tax_incl, $currency);
            $order->total_paid_tax_excl = Money::of($totals->grand_total_tax_excl, $currency);

            // ================================================================
            // 6. GET ORDER HISTORY
            // ================================================================
            $order->history = $this->getOrderHistory($order->id);

            // ================================================================
            // GET CURRENCY DATA
            // ================================================================
            $order->currency_data = $currency;

            $this->item = $order;
            return $order;
        }

        return null;
    }

    /**
     * Load currency as Currency object
     */
    // ========================================================================
    // PAYMENTS
    // ========================================================================

    public function getPaymentData($pk = null)
    {
        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            ->from($db->quoteName('#__alfa_order_payments', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . $db->quote($pk));

        $db->setQuery($query);
        $payment = $db->loadObject();

        if ($payment) {
            $paymentModel = $this->getRelatedModel('Payment');
            $payment->params = $paymentModel->getItem($payment->id_payment_method);

            // Convert to Money object
            $currency = $this->loadCurrency($payment->id_currency);
            $payment->amount = $this->toMoney($payment->amount, $currency);
        }

        return $payment;
    }

    public function getOrderPayments($orderId)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from('#__alfa_order_payments')
            ->where('id_order = ' . $db->quote($orderId));

        $db->setQuery($query);
        return $db->loadObjectList();
    }

    /**
     * Save or update an order payment record.
     *
     * Delegates to OrderPaymentHelper for all database operations,
     * activity logging, and server-side defaults.
     *
     * The helper handles:
     *   - Money → float conversion
     *   - Method name snapshotting
     *   - Auto-fill: added, id_employee, id_currency
     *   - Change detection and activity logging
     *
     * @param array &$data Form data (by reference — PK set on insert)
     *
     *
     * @since   3.5.0
     */
    public function saveOrderPayment(&$data): bool
    {
        // Strip nested 'payment' key if present (from form binding)
        $saveData = $data;
        if (isset($saveData['payment'])) {
            unset($saveData['payment']);
        }

        $isNew = empty($saveData['id']) || (int) $saveData['id'] === 0;

        if ($isNew) {
            // ── CREATE ──────────────────────────────────────────
            $newId = OrderPaymentHelper::create($saveData);

            if ($newId === false) {
                return false;
            }

            $data['id'] = $newId;

            return true;
        }

        // ── UPDATE ──────────────────────────────────────────────
        $id = (int) $saveData['id'];

        $success = OrderPaymentHelper::update($id, $saveData);

        if ($success) {
            $data['id'] = $id;
        }

        return $success;
    }

    /**
     * Delete an order payment record.
     *
     * Delegates to OrderPaymentHelper::delete() which handles
     * pre-delete snapshot and activity logging.
     *
     * @param int $id Payment row PK
     * @param int $id_order Order PK
     *
     *
     * @since   3.5.0
     */
    public function deleteOrderPayment($id, $id_order): bool
    {
        return OrderPaymentHelper::delete((int) $id, (int) $id_order);
    }

    protected function getOrderPayment($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from($db->qn('#__alfa_order_payments'))
            ->where($db->qn('id') . '=' . $id);

        $db->setQuery($query);
        return $db->loadObject();
    }

    // ========================================================================
    // SHIPMENTS
    // ========================================================================

    public function getShipmentData($pk = null)
    {
        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            ->from($db->quoteName('#__alfa_order_shipments', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . $db->quote($pk));

        $db->setQuery($query);
        $shipment = $db->loadObject();

        if ($shipment) {
            $shipmentModel = $this->getRelatedModel('Shipment');
            $shipment->params = $shipmentModel->getItem($shipment->id_shipment_method);

            // Convert to Money objects
            $currency = $this->loadCurrency($shipment->id_currency);
            $shipment->shipping_cost_tax_incl = $this->toMoney($shipment->shipping_cost_tax_incl, $currency);
            $shipment->shipping_cost_tax_excl = $this->toMoney($shipment->shipping_cost_tax_excl, $currency);
        }

        return $shipment;
    }

    public function getOrderShipments($orderId)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from('#__alfa_order_shipments')
            ->where('id_order = ' . $db->quote($orderId));

        $db->setQuery($query);
        return $db->loadObjectList();
    }

    /**
     * Save or update an order shipment record.
     *
     * Delegates to OrderShipmentHelper for all database operations,
     * item assignment, and activity logging.
     *
     * The helper handles:
     *   - Money → float conversion
     *   - Method name snapshotting
     *   - Auto-fill: added, id_employee, id_currency
     *   - Item assignment via 'items' key
     *   - Change detection and activity logging
     *
     * @param array &$data Form data (by reference — PK set on insert)
     *
     *
     * @since   3.5.0
     */
    public function saveOrderShipment(&$data): bool
    {
        // Strip nested 'shipment' key if present (from form binding)
        $saveData = $data;
        if (isset($saveData['shipment'])) {
            unset($saveData['shipment']);
        }

        $isNew = empty($saveData['id']) || (int) $saveData['id'] === 0;

        if ($isNew) {
            // ── CREATE ──────────────────────────────────────────
            $newId = OrderShipmentHelper::create($saveData);

            if ($newId === false) {
                return false;
            }

            $data['id'] = $newId;

            return true;
        }

        // ── UPDATE ──────────────────────────────────────────────
        $id = (int) $saveData['id'];

        $success = OrderShipmentHelper::update($id, $saveData);

        if ($success) {
            $data['id'] = $id;
        }

        return $success;
    }

    /**
     * Delete an order shipment record.
     *
     * Delegates to OrderShipmentHelper::delete() which handles
     * item unassignment, pre-delete snapshot, and activity logging.
     *
     * @param int $id Shipment row PK
     * @param int $id_order Order PK
     *
     *
     * @since   3.5.0
     */
    public function deleteOrderShipment($id, $id_order): bool
    {
        return OrderShipmentHelper::delete((int) $id, (int) $id_order);
    }

    /**
     * Assign order items to a shipment.
     *
     * Delegates to OrderShipmentHelper::assignItems().
     *
     * @param int $shipmentId Shipment PK
     * @param int $orderId Order PK (security scope)
     * @param array $selectedItemIds Array of order_items.id values
     *
     *
     * @since   3.5.0
     */
    protected function assignItemsToShipment(int $shipmentId, int $orderId, array $selectedItemIds): void
    {
        OrderShipmentHelper::assignItems($shipmentId, $orderId, $selectedItemIds);
    }

    /**
     * Get item IDs assigned to a shipment (for form pre-selection).
     *
     * Delegates to OrderShipmentHelper::getItemIds().
     *
     * @param int $shipmentId Shipment PK
     *
     * @return array Array of order_items.id values
     *
     * @since   3.5.0
     */
    protected function getShipmentItemIds(int $shipmentId): array
    {
        return OrderShipmentHelper::getItemIds($shipmentId);
    }

    /**
     * Get human-readable item names assigned to a shipment.
     *
     * Delegates to OrderShipmentHelper::getItemNames().
     *
     * @param int $shipmentId Shipment PK
     *
     * @return string Comma-separated item names, or empty string
     *
     * @since   3.5.0
     */
    protected function getShipmentItemNames(int $shipmentId): string
    {
        return OrderShipmentHelper::getItemNames($shipmentId);
    }

    /**
     * Load a raw shipment row by PK (for diffs and logging)
     *
     * @param int $id Shipment row PK
     * @return object|null
     */
    protected function getOrderShipment($id)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->qn('#__alfa_order_shipments'))
            ->where($db->qn('id') . ' = ' . intval($id));
        $db->setQuery($query);
        return $db->loadObject();
    }

    // ========================================================================
    // ORDER ITEMS (single-item CRUD — for admin popup)
    // ========================================================================

    /**
     * Get a single order item by PK
     * Mirrors getPaymentData() / getShipmentData() pattern
     *
     * @param int|null $pk Order item row PK
     * @return object|null
     */
    public function getOrderItemData($pk = null)
    {
        if ($pk == null) {
            return null;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('a.*')
            ->from($db->quoteName('#__alfa_order_items', 'a'))
            ->where($db->quoteName('a.id') . ' = ' . intval($pk));

        $db->setQuery($query);
        $item = $db->loadObject();

        if ($item) {
            // Load order currency for Money objects
            $currency = $this->loadCurrency(
                $this->getOrderCurrencyId($item->id_order),
            );

            // Convert prices to Money
            $item->unit_price_tax_incl = $this->toMoney($item->unit_price_tax_incl, $currency);
            $item->unit_price_tax_excl = $this->toMoney($item->unit_price_tax_excl, $currency);
            $item->total_price_tax_incl = $this->toMoney($item->total_price_tax_incl, $currency);
            $item->total_price_tax_excl = $this->toMoney($item->total_price_tax_excl, $currency);
            $item->original_product_price = $this->toMoney($item->original_product_price, $currency);
            $item->currency = $currency;

            // Backward compat for form display
            $item->price = ($item->quantity > 0)
                ? $item->unit_price_tax_incl
                : Money::zero($currency);
            $item->total = $item->total_price_tax_incl;
        }

        return $item;
    }

    /**
     * Get order item form (for popup edit)
     * Mirrors getPaymentForm() / getShipmentForm() pattern exactly
     *
     * Loads order_items.xml, binds existing item data for editing.
     *
     * @param array|object $data Existing item data to bind (or empty for new)
     * @param bool $loadData Whether to load data
     * @return \Joomla\CMS\Form\Form|false
     */
    public function getOrderItemForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.order', 'order_items', [
            'control' => 'jform',
            'load_data' => false,
        ]);

        if (empty($form)) {
            return false;
        }

        $app = Factory::getApplication();
        $idFromInput = $app->getInput()->getInt('id', 0);
        $orderIdFromInput = $app->getInput()->getInt('id_order', 0);

        $id = (int) $this->getState('order_items.id', $idFromInput);
        $orderId = (int) $this->getState('order.id', $orderIdFromInput);

        if ($id > 0 && empty($data)) {
            $data = $this->getOrderItemData($id);
        }

        // Convert object → array with Money→float for form binding
        if (is_object($data)) {
            $data = $this->objectToFormData($data);
        }

        if (is_array($data)) {
            if (!isset($data['id'])) {
                $data['id'] = $id;
            }
            if (!isset($data['id_order'])) {
                $data['id_order'] = $orderId;
            }
        }

        $form->bind($data);

        // Always hide id_item dropdown — product selection is handled by:
        //   New items:      AJAX search populates the hidden field
        //   Existing items: Pre-filled, shown in header, cannot be changed
        $form->setFieldAttribute('id_item', 'type', 'hidden');

        return $form;
    }

    /**
     * Save a single order item (from admin popup)
     *
     * Handles:
     * - Product snapshot (name, sku, weight, etc.)
     * - Price calculation (unit × quantity)
     * - Stock management (diff-based)
     * - INSERT: all columns set fresh
     * - UPDATE: preserves refunds, tax, shipping, discounts, added timestamp
     *
     * @param array $data Form data with: id, id_order, id_item, quantity, unit_price
     */
    public function saveOrderItem(array &$data): bool
    {
        $db = $this->getDatabase();
        $app = Factory::getApplication();

        $itemPK = (int) ($data['id'] ?? 0);
        $orderId = (int) ($data['id_order'] ?? 0);
        $idItem = (int) ($data['id_item'] ?? 0);
        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $adminPrice = (float) ($data['unit_price_tax_incl'] ?? 0); // Admin-set price (tax incl)

        if ($orderId <= 0 || $idItem <= 0) {
            $app->enqueueMessage(Text::_('COM_ALFA_ERROR_INVALID_ORDER_OR_PRODUCT'), 'error');
            return false;
        }

        // Load product from catalog with calculated prices
        $context = OrderHelper::buildOrderPriceContext($orderId);
        $product = OrderHelper::getProductById($idItem, $context, $quantity);
        if (!$product) {
            $app->enqueueMessage(Text::sprintf('COM_ALFA_ERROR_PRODUCT_NOT_FOUND', $idItem), 'error');
            return false;
        }

        // ================================================================
        // PRICE CALCULATION
        // Tax rate comes from TaxSummary (the REAL configured rate),
        // NOT computed from price difference (which breaks with discounts)
        // ================================================================
        $taxRate = (float) ($product->tax_rate ?? 0);
        $taxName = $product->tax_name ?? '';

        // Check if admin overrode the price
        $catalogPriceTaxIncl = (float) ($product->price_tax_incl ?? 0);
        $isPriceOverride = (abs($adminPrice - $catalogPriceTaxIncl) > 0.001);

        if ($isPriceOverride || !isset($product->price_result) || !$product->price_result) {
            // Admin overrode price OR no PriceResult — reverse-calculate from tax rate
            $unitPriceTaxIncl = $adminPrice;
            $unitPriceTaxExcl = ($taxRate > 0)
                ? round($adminPrice / (1 + $taxRate / 100), 6)
                : $adminPrice;
            $originalProductPrice = (float) ($product->base_price ?? $adminPrice);
            $discountPercent = 0;
            $discountAmountTaxIncl = 0;
            $discountAmountTaxExcl = 0;
        } else {
            // Using exact PriceCalculator values (no admin override)
            // price_tax_incl/excl already correctly split by attachPriceResult()
            $unitPriceTaxIncl = (float) $product->price_tax_incl;
            $unitPriceTaxExcl = (float) $product->price_tax_excl;
            $originalProductPrice = (float) $product->base_price;

            $priceResult = $product->price_result;
            $discountPercent = $priceResult->hasDiscount() ? $priceResult->getSavingsPercent() : 0;
            $discountAmountTaxExcl = $priceResult->hasDiscount() ? $priceResult->getSavingsPrice()->getAmount() : 0;
            $discountAmountTaxIncl = ($discountAmountTaxExcl > 0 && $taxRate > 0)
                ? round($discountAmountTaxExcl * (1 + $taxRate / 100), 6)
                : $discountAmountTaxExcl;
        }

        // ================================================================
        // STOCK: Calculate difference
        // ================================================================
        $oldQuantity = 0;
        $isExisting = ($itemPK > 0);

        if ($isExisting) {
            // Load existing row — preserves all protected fields
            $itemObject = $this->loadExistingItemRow($itemPK);
            if (!$itemObject) {
                $isExisting = false;
                $itemObject = new stdClass();
            } else {
                // ============================================================
                // GUARD: Reject product change on existing items
                //
                // Changing the product on an existing line would corrupt:
                // - Stock accounting (old product's deduction never reversed)
                // - quantity_in_stock snapshot (belongs to old product)
                // - Refund columns (quantities tied to old product)
                // - Invoice references
                //
                // To change the product: delete this item, add a new one.
                // This creates a clean audit trail and correct stock on both.
                // ============================================================
                if ((int) $itemObject->id_item !== $idItem) {
                    $app->enqueueMessage(
                        'Cannot change the product on an existing order item. '
                        . 'Delete this item and add the new product instead.',
                        'error',
                    );
                    return false;
                }

                $oldQuantity = (int) $itemObject->quantity;

                // Snapshot old values for activity log (before overwrite)
                $oldItemSnapshot = [
                    'id_item' => (int) ($itemObject->id_item ?? 0),
                    'name' => $itemObject->name ?? '',
                    'quantity' => $oldQuantity,
                    'unit_price_tax_incl' => (float) ($itemObject->unit_price_tax_incl ?? 0),
                    'unit_price_tax_excl' => (float) ($itemObject->unit_price_tax_excl ?? 0),
                    'tax_rate' => (float) ($itemObject->tax_rate ?? 0),
                ];
            }
        } else {
            $itemObject = new stdClass();
            $oldItemSnapshot = null;
        }

        // ================================================================
        // SET: Fields from the admin form
        // ================================================================
        $itemObject->id_item = $idItem;
        $itemObject->id_order = $orderId;

        // Product snapshot — fresh from catalog
        $itemObject->name = $product->name;
        $itemObject->reference = $product->sku ?? '';
        $itemObject->ean13 = $product->gtin ?? '';
        $itemObject->mpn = $product->mpn ?? '';
        $itemObject->weight = $product->weight ?? 0;

        // Quantity
        $itemObject->quantity = $quantity;

        // Prices — properly split between tax incl and excl
        $itemObject->unit_price_tax_incl = $unitPriceTaxIncl;
        $itemObject->unit_price_tax_excl = $unitPriceTaxExcl;
        $itemObject->total_price_tax_incl = round($unitPriceTaxIncl * $quantity, 6);
        $itemObject->total_price_tax_excl = round($unitPriceTaxExcl * $quantity, 6);
        $itemObject->original_product_price = $originalProductPrice;

        // Tax — from TaxSummary (set for both INSERT and UPDATE)
        $itemObject->tax_name = $taxName;
        $itemObject->tax_rate = $taxRate;

        // Discounts — from PriceCalculator (set for both INSERT and UPDATE)
        $itemObject->reduction_percent = $discountPercent;
        $itemObject->reduction_amount_tax_incl = $discountAmountTaxIncl;
        $itemObject->reduction_amount_tax_excl = $discountAmountTaxExcl;

        // ================================================================
        // INSERT: Set all remaining fields fresh
        // ================================================================
        if (!$isExisting) {
            $itemObject->id = 0;

            // Foreign keys
            $itemObject->id_order_invoice = null;
            $itemObject->id_warehouse = 0;
            $itemObject->id_product_attribute = (int) ($data['id_product_attribute'] ?? 0);
            $itemObject->id_customization = 0;
            $itemObject->id_shipmentmethod = 0;

            // Product snapshot extras
            $itemObject->supplier_reference = '';
            $itemObject->isbn = '';
            $itemObject->upc = '';

            // Stock snapshot
            $itemObject->quantity_in_stock = (int) ($product->stock ?? 0);

            // Refunds
            $itemObject->quantity_refunded = 0;
            $itemObject->quantity_return = 0;
            $itemObject->quantity_reinjected = 0;
            $itemObject->total_refunded_tax_excl = 0;
            $itemObject->total_refunded_tax_incl = 0;

            // Shipping
            $itemObject->total_shipping_price_tax_incl = 0;
            $itemObject->total_shipping_price_tax_excl = 0;

            // Purchase/wholesale
            $itemObject->original_wholesale_price = 0;
            $itemObject->purchase_supplier_price = 0;

            // Group reduction (customer group specific — separate from product discounts)
            $itemObject->group_reduction = 0;

            // Tax rules and computation
            $itemObject->id_tax_rules_group = 0;
            $itemObject->tax_computation_method = 0;
            $itemObject->ecotax = 0;
            $itemObject->ecotax_tax_rate = 0;

            // Downloads
            $itemObject->download_hash = null;
            $itemObject->download_nb = 0;
            $itemObject->download_deadline = null;

            // Timestamp — only on INSERT
            $itemObject->added = Factory::getDate('now', 'UTC')->toSql();

            try {
                $db->insertObject('#__alfa_order_items', $itemObject);
                $data['id'] = $db->insertid();
            } catch (Exception $e) {
                $app->enqueueMessage('Error inserting order item: ' . $e->getMessage(), 'error');
                return false;
            }
        } else {
            // UPDATE: preserves refunds, tax, shipping, discounts, added, etc.
            try {
                $db->updateObject('#__alfa_order_items', $itemObject, 'id', true);
                $data['id'] = $itemPK;
            } catch (Exception $e) {
                $app->enqueueMessage('Error updating order item: ' . $e->getMessage(), 'error');
                return false;
            }
        }

        // ================================================================
        // STOCK: Apply difference with admin warning
        // ================================================================
        $diff = $quantity - $oldQuantity;
        if ($diff !== 0) {
            // adjustProductStock takes: negative = deduct, positive = restore
            // We ordered MORE → subtract from stock → pass negative
            OrderStockHelper::adjustProductStock((int) $idItem, -$diff);

            // Warn admin about negative stock
            if ($product->manage_stock) {
                $currentStock = (float) $db->setQuery(
                    $db->getQuery(true)
                        ->select('stock')
                        ->from('#__alfa_items')
                        ->where('id = ' . intval($idItem)),
                )->loadResult();

                if ($currentStock < 0) {
                    $app->enqueueMessage(
                        sprintf(
                            'Warning: "%s" stock is now %s. Consider restocking.',
                            $product->name,
                            number_format($currentStock, 0),
                        ),
                        'warning',
                    );
                }
            }
        }

        // ================================================================
        // ACTIVITY LOG: Record the change
        // ================================================================
        if (!$isExisting) {
            // INSERT — log item.added
            $priceFormatted = number_format($unitPriceTaxIncl, 2);
            self::logOrderActivity(
                $orderId,
                'item.added',
                "Added \"{$product->name}\" ×{$quantity} at {$priceFormatted}",
                [
                    'id_item' => $idItem,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price_tax_incl' => $unitPriceTaxIncl,
                    'unit_price_tax_excl' => $unitPriceTaxExcl,
                    'tax_rate' => $taxRate,
                ],
                (int) $data['id'],
            );
        } else {
            // UPDATE — only log if something actually changed
            $newValues = [
                'id_item' => $idItem,
                'name' => $product->name,
                'quantity' => $quantity,
                'unit_price_tax_incl' => $unitPriceTaxIncl,
                'unit_price_tax_excl' => $unitPriceTaxExcl,
                'tax_rate' => $taxRate,
            ];

            // Build diff using numeric-safe comparison
            $oldObj = (object) $oldItemSnapshot;
            $changes = AlfaHelper::buildDiff($oldObj, $newValues, array_keys($newValues));

            if (!empty($changes)) {
                // Build human-readable summary
                $parts = [];
                if (isset($changes['quantity'])) {
                    $parts[] = "qty {$changes['quantity']['from']}→{$changes['quantity']['to']}";
                }
                if (isset($changes['unit_price_tax_incl'])) {
                    $parts[] = 'price ' . number_format((float) $changes['unit_price_tax_incl']['from'], 2)
                        . '→' . number_format((float) $changes['unit_price_tax_incl']['to'], 2);
                }
                $summary = "Edited \"{$product->name}\": " . implode(', ', $parts);

                self::logOrderActivity(
                    $orderId,
                    'item.edited',
                    $summary,
                    $changes,
                    $itemPK,
                );
            }
        }

        return true;
    }

    /**
     * Delete a single order item and restore stock
     *
     * @param int $id Order item row PK
     * @param int $orderId Order ID (for security)
     */
    public function deleteOrderItem(int $id, int $orderId): bool
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();

        // Load item to get quantity and product ID for stock restore
        $item = $this->loadExistingItemRow($id);
        if (!$item) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_ORDER_ITEM_NOT_FOUND'), 'error');
            return false;
        }

        // Verify order ownership
        if ((int) $item->id_order !== $orderId) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_ORDER_ITEM_NOT_IN_ORDER'), 'error');
            return false;
        }

        // Delete the item
        $query = $db->getQuery(true)
            ->delete('#__alfa_order_items')
            ->where('id = ' . intval($id))
            ->where('id_order = ' . intval($orderId));
        $db->setQuery($query);

        try {
            $db->execute();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        // Restore stock
        if ((int) $item->quantity > 0) {
            $restored = OrderStockHelper::adjustProductStock(
                (int) $item->id_item,
                (int) $item->quantity,  // Positive = restore
            );

            if ($restored) {
                $app->enqueueMessage(
                    sprintf(
                        'Restored %d units of "%s" to stock.',
                        (int) $item->quantity,
                        $item->name,
                    ),
                    'message',
                );
            }
        }

        // Activity log
        $priceFormatted = number_format((float) ($item->unit_price_tax_incl ?? 0), 2);
        self::logOrderActivity(
            $orderId,
            'item.deleted',
            "Deleted \"{$item->name}\" (qty: {$item->quantity}, price: {$priceFormatted}, stock restored)",
            [
                'id_item' => (int) $item->id_item,
                'name' => $item->name ?? '',
                'quantity' => (int) $item->quantity,
                'unit_price_tax_incl' => (float) ($item->unit_price_tax_incl ?? 0),
                'unit_price_tax_excl' => (float) ($item->unit_price_tax_excl ?? 0),
                'tax_rate' => (float) ($item->tax_rate ?? 0),
                'stock_restored' => true,
            ],
            $id,
        );

        return true;
    }

    /**
     * Load existing order item row from DB (all fields preserved)
     *
     * @param int $itemPK Row PK
     */
    protected function loadExistingItemRow(int $itemPK): ?object
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_order_items')
            ->where('id = ' . intval($itemPK));
        $db->setQuery($query);

        try {
            $row = $db->loadObject();
            return $row ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    // ========================================================================
    // ORDER HISTORY (reads from unified order_activity_log)
    // ========================================================================

    /**
     * Get order history (full activity log)
     *
     * Status is captured on EVERY row via id_order_status + status_name columns.
     * No need for a separate status query — filter in the UI if needed.
     *
     * @param int $orderId Order ID
     * @return array Activity log entries
     */
    public function getOrderHistory($orderId)
    {
        return $this->getOrderActivityLog((int) $orderId);
    }

    // ========================================================================
    // SAVE & DELETE
    // ========================================================================

    /**
     * Prepare and sanitize the table data before save
     *
     * Called automatically by AdminModel::save() after bind(), before check()+store().
     * This is the Joomla-standard hook for data sanitization.
     *
     * Handles:
     * - MySQL strict mode: empty strings → null for nullable int/datetime columns
     * - Default values for required fields
     * - Timestamp management
     *
     * @param \Joomla\CMS\Table\Table $table The table instance
     */
    protected function prepareTable($table)
    {
        parent::prepareTable($table);

        // ================================================================
        // MySQL strict mode: '' → null for nullable integer columns
        //
        // Joomla forms send '' for empty fields. MySQL strict mode rejects
        // '' for int columns. Convert to null for all nullable FK/meta fields.
        // ================================================================
        $nullableIntColumns = [
            'checked_out',
            'id_user', 'id_cart', 'id_currency',
            'id_address_delivery', 'id_address_invoice',
            'id_payment_method', 'id_shipment_method', 'id_order_status',
            'id_payment_currency', 'id_language',
            'id_coupon', 'code_coupon',
            'modified_by', 'created_by',
        ];

        foreach ($nullableIntColumns as $col) {
            if (property_exists($table, $col) && $table->{$col} === '') {
                $table->{$col} = null;
            }
        }

        // Nullable datetime columns
        $nullableDateColumns = ['checked_out_time', 'invoice_date', 'delivery_date'];

        foreach ($nullableDateColumns as $col) {
            if (property_exists($table, $col) && empty($table->{$col})) {
                $table->{$col} = null;
            }
        }

        // ================================================================
        // Timestamps
        // ================================================================
        $now = Factory::getDate()->toSql();
        $user = Factory::getApplication()->getIdentity();

        if (!(int) $table->id) {
            // New record
            if (empty($table->created)) {
                $table->created = $now;
            }
            if (empty($table->created_by)) {
                $table->created_by = $user->id;
            }
        }

        $table->modified = $now;
        $table->modified_by = $user->id;
    }

    /**
     * Save an order with stock operation handling.
     *
     * On status change, delegates to OrderStockHelper::handleStatusTransition()
     * which detects 0↔1 transitions in stock_operation and bulk-adjusts stock.
     *
     * @param array $data The form data.
     * @return bool True on success, false on failure.
     * @since   3.5.1
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $table = $this->getTable();

        $key = $table->getKeyName();
        $pk = (int) ($data[$key] ?? $this->getState($this->getName() . '.id'));
        $isNew = $pk <= 0;
        $prevOrder = !$isNew ? $this->getItem($pk) : null;

        if (!parent::save($data)) {
            return false;
        }

        $orderId = $pk > 0 ? $pk : (int) $this->getState($this->getName() . '.id');

        // ================================================================
        // Stock operation: adjust stock on status transitions
        //
        // Only fires on EDIT (not new orders — frontend handles its own
        // stock via OrderStockHelper::deductOrderStock in OrderPlaceHelper).
        // Only fires when id_order_status actually changed.
        //
        // Delegates to OrderStockHelper (single source of truth).
        // ================================================================
        if (!$isNew && $prevOrder) {
            $oldStatusId = (int) ($prevOrder->id_order_status ?? 0);
            $newStatusId = (int) ($data['id_order_status'] ?? $oldStatusId);

            if ($oldStatusId !== $newStatusId) {
                try {
                    $result = OrderStockHelper::handleStatusTransition($orderId, $oldStatusId, $newStatusId);

                    if ($result['action'] !== 'none') {
                        $app->enqueueMessage(
                            sprintf(
                                'Stock %s for %d products (status change).',
                                $result['action'],
                                $result['count'],
                            ),
                            'message',
                        );
                    }
                } catch (Exception $e) {
                    // Stock operation failure should NOT block the save
                    Log::add('Stock operation failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
                    $app->enqueueMessage(
                        'Warning: Status changed but stock adjustment failed. Check stock manually.',
                        'warning',
                    );
                }
            }
        }

        // ================================================================
        // Save customer/address info
        // ================================================================
        $userInfoData = $data['com_alfa'] ?? [];

        if (!empty($userInfoData) && !empty($data['id_address_delivery'])) {
            if (!OrderHelper::saveUserInfo((int) $data['id_address_delivery'], $userInfoData)) {
                $app->enqueueMessage(Text::_('COM_ALFA_ERROR_SAVE_USER_INFO'), 'error');
            }
        }

        // ================================================================
        // Activity log: ONE entry per save
        // ================================================================
        if ($isNew) {
            self::logOrderActivity($orderId, 'order.created', 'Order created from admin');
        } else {
            $this->logOrderChanges($orderId, $prevOrder, $data, $userInfoData);
        }

        return true;
    }

    /**
     * Handle stock operation on order status transition.
     *
     * Compares stock_operation between old and new status.
     * If the value changes, performs a bulk stock adjustment
     * for ALL items in the order.
     *
     * stock_operation values (from #__alfa_orders_statuses):
     *   0 = items should be OUT of stock (reserved/sold)
     *   1 = items should be IN stock (available)
     *
     * @param int $orderId Order PK
     * @param int $oldStatusId Previous order status ID
     * @param int $newStatusId New order status ID
     * @since   3.5.1
     */
    protected function handleStockOperation(int $orderId, int $oldStatusId, int $newStatusId): void
    {
        try {
            $statuses = AlfaHelper::getOrderStatuses();

            $oldStockOp = (int) ($statuses[$oldStatusId]->stock_operation ?? 0);
            $newStockOp = (int) ($statuses[$newStatusId]->stock_operation ?? 0);

            // No change in stock operation → nothing to do
            if ($oldStockOp === $newStockOp) {
                return;
            }

            $oldStatusName = $statuses[$oldStatusId]->name ?? "#{$oldStatusId}";
            $newStatusName = $statuses[$newStatusId]->name ?? "#{$newStatusId}";

            if ($newStockOp === 1) {
                // Transitioning TO "keep in stock" → RETURN items
                // Example: Confirmed (0) → Cancelled (1)
                $itemCount = $this->restoreOrderStock($orderId);

                self::logOrderActivity(
                    $orderId,
                    'stock.restored',
                    "Stock restored: status changed from \"{$oldStatusName}\" to \"{$newStatusName}\" ({$itemCount} items returned to stock)",
                    [
                        'old_status' => ['id' => $oldStatusId, 'name' => $oldStatusName, 'stock_operation' => $oldStockOp],
                        'new_status' => ['id' => $newStatusId, 'name' => $newStatusName, 'stock_operation' => $newStockOp],
                        'items_restored' => $itemCount,
                    ],
                );
            } else {
                // Transitioning TO "remove from stock" → DEDUCT items
                // Example: Cancelled (1) → Confirmed (0)
                $itemCount = $this->deductOrderStock($orderId);

                self::logOrderActivity(
                    $orderId,
                    'stock.deducted',
                    "Stock deducted: status changed from \"{$oldStatusName}\" to \"{$newStatusName}\" ({$itemCount} items removed from stock)",
                    [
                        'old_status' => ['id' => $oldStatusId, 'name' => $oldStatusName, 'stock_operation' => $oldStockOp],
                        'new_status' => ['id' => $newStatusId, 'name' => $newStatusName, 'stock_operation' => $newStockOp],
                        'items_deducted' => $itemCount,
                    ],
                );
            }
        } catch (Exception $e) {
            // Stock operation failure should NOT block the save
            Log::add('Stock operation failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            Factory::getApplication()->enqueueMessage(
                'Warning: Status changed but stock adjustment failed. Check stock manually.',
                'warning',
            );
        }
    }

    /**
     * Return ALL order items to stock (bulk operation).
     *
     * Used when order transitions to a status where stock_operation = 1
     * (e.g. Cancelled). Adds each item's quantity back to product stock.
     *
     * Aggregates by product ID to handle duplicate lines (same product,
     * different variations on separate order lines).
     *
     * @param int $orderId Order PK
     * @return int Number of distinct products restored
     * @since   3.5.1
     */
    protected function restoreOrderStock(int $orderId): int
    {
        $db = $this->getDatabase();

        // Load all items for this order
        $query = $db->getQuery(true)
            ->select(['id_item', 'quantity'])
            ->from('#__alfa_order_items')
            ->where('id_order = ' . intval($orderId));
        $db->setQuery($query);
        $items = $db->loadObjectList();

        if (empty($items)) {
            return 0;
        }

        // Aggregate quantities by product (supports duplicate id_item lines)
        $qtyByProduct = [];
        foreach ($items as $item) {
            $pid = (int) $item->id_item;
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (int) $item->quantity;
        }

        // Return stock for each product
        foreach ($qtyByProduct as $productId => $totalQty) {
            if ($totalQty <= 0) {
                continue;
            }

            $query = $db->getQuery(true)
                ->update('#__alfa_items')
                ->set('stock = stock + ' . intval($totalQty))
                ->where('id = ' . intval($productId));
            $db->setQuery($query);
            $db->execute();
        }

        return count($qtyByProduct);
    }

    /**
     * Deduct ALL order items from stock (bulk operation).
     *
     * Used when order transitions to a status where stock_operation = 0
     * (e.g. re-confirming a previously cancelled order). Subtracts each
     * item's quantity from product stock.
     *
     * Aggregates by product ID to handle duplicate lines.
     *
     * @param int $orderId Order PK
     * @return int Number of distinct products deducted
     * @since   3.5.1
     */
    protected function deductOrderStock(int $orderId): int
    {
        $db = $this->getDatabase();

        // Load all items for this order
        $query = $db->getQuery(true)
            ->select(['id_item', 'quantity'])
            ->from('#__alfa_order_items')
            ->where('id_order = ' . intval($orderId));
        $db->setQuery($query);
        $items = $db->loadObjectList();

        if (empty($items)) {
            return 0;
        }

        // Aggregate quantities by product
        $qtyByProduct = [];
        foreach ($items as $item) {
            $pid = (int) $item->id_item;
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (int) $item->quantity;
        }

        // Deduct stock for each product (allows negative — backorder)
        foreach ($qtyByProduct as $productId => $totalQty) {
            if ($totalQty <= 0) {
                continue;
            }

            $query = $db->getQuery(true)
                ->update('#__alfa_items')
                ->set('stock = stock - ' . intval($totalQty))
                ->where('id = ' . intval($productId));
            $db->setQuery($query);
            $db->execute();
        }

        return count($qtyByProduct);
    }

    /**
     * Diff ALL changes from one save and log as a single order.edited event
     *
     * Compares:
     * - Order fields (status, payment method, notes, dates, etc.)
     * - Customer/address info (name, phone, address, etc.)
     *
     * One save = one row in the activity log. The status snapshot columns
     * (id_order_status, status_name) on the log row already capture what
     * status the order is in — no separate status event needed.
     *
     * @param int $orderId Order PK
     * @param object|null $prevOrder Previous order from getItem() (includes user_info)
     * @param array $data New form data
     * @param array $userInfoData New customer/address data (com_alfa fields)
     */
    protected function logOrderChanges(int $orderId, ?object $prevOrder, array $data, array $userInfoData = []): void
    {
        if (!$prevOrder) {
            return;
        }

        $changes = [];

        // ================================================================
        // Order fields
        // ================================================================
        $orderFields = [
            'id_order_status', 'id_currency',
            'id_address_delivery', 'id_address_invoice',
            'id_payment_method', 'id_shipment_method',
            'customer_note', 'note', 'secure_key',
            'invoice_number', 'invoice_date', 'delivery_date',
        ];

        foreach ($orderFields as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $oldVal = (string) ($prevOrder->{$field} ?? '');
            $newVal = (string) ($data[$field] ?? '');

            if ($oldVal !== $newVal) {
                $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        // Enrich status change with human-readable names
        if (isset($changes['id_order_status'])) {
            $db = $this->getDatabase();
            $oldId = (int) $changes['id_order_status']['from'];
            $newId = (int) $changes['id_order_status']['to'];

            $statusIds = array_filter([$oldId, $newId], fn ($id) => $id > 0);
            $query = $db->getQuery(true)
                ->select(['id', 'name'])
                ->from('#__alfa_orders_statuses')
                ->whereIn('id', $statusIds);
            $db->setQuery($query);
            $names = $db->loadObjectList('id');

            $changes['id_order_status']['from_name'] = $names[$oldId]->name ?? '';
            $changes['id_order_status']['to_name'] = $names[$newId]->name ?? '';
        }

        // ================================================================
        // Customer/address fields
        // ================================================================
        $oldUserInfo = $prevOrder->user_info ?? null;

        if ($oldUserInfo && !empty($userInfoData)) {
            foreach ($userInfoData as $field => $newVal) {
                if ($field === 'id') {
                    continue;
                }

                $oldVal = (string) ($oldUserInfo->{$field} ?? '');
                $newVal = (string) $newVal;

                if ($oldVal !== $newVal) {
                    $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
                }
            }
        }

        // ================================================================
        // Nothing changed → no log entry
        // ================================================================
        if (empty($changes)) {
            return;
        }

        // Build human-readable summary from changed field names
        $fields = array_keys($changes);

        // Use status name in summary if status changed
        if (isset($changes['id_order_status'])) {
            $statusIdx = array_search('id_order_status', $fields);
            $fields[$statusIdx] = 'status → ' . ($changes['id_order_status']['to_name'] ?? '');
        }

        $summary = 'Edited: ' . implode(', ', $fields);

        self::logOrderActivity($orderId, 'order.edited', $summary, $changes);
    }

    public function delete(&$pks)
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();

        foreach ($pks as $pk) {
            $orders[] = $this->getItem($pk);
        }

        $onAdminOrderDeleteEventName = 'onAdminOrderDelete';

        foreach ($orders as $i => $order) {
            $deleteEntry = true;

            foreach ($order->payments as $j => $payment) {
                if (!$deleteEntry) {
                    break;
                }

                $paymentDeleteOrderDataEvent = new PaymentBeforeDeleteEvent($onAdminOrderDeleteEventName, [
                    'subject' => $order,
                    'method' => $payment,
                ]);

                $app->bootPlugin($payment->params->type, 'alfa-payments')
                    ->{$onAdminOrderDeleteEventName}($paymentDeleteOrderDataEvent);
                $deleteEntry = $paymentDeleteOrderDataEvent->getResult();
            }

            if ($deleteEntry) {
                foreach ($order->shipments as $shipment) {
                    if (!$deleteEntry) {
                        break;
                    }

                    $shipmentDeleteOrderDataEvent = new ShipmentBeforeDeleteEvent($onAdminOrderDeleteEventName, [
                        'subject' => $order,
                        'method' => $shipment,
                    ]);

                    $app->bootPlugin($shipment->params->type, 'alfa-shipments')
                        ->{$onAdminOrderDeleteEventName}($shipmentDeleteOrderDataEvent);
                    $deleteEntry = $shipmentDeleteOrderDataEvent->getResult();
                }
            }

            if (!$deleteEntry) {
                unset($pks[$i]);
            }
        }

        if (empty($pks)) {
            return true;
        }

        // Delete related data
        $this->deleteRelatedData($pks);

        return parent::delete($pks);
    }

    protected function deleteRelatedData(array $pks): void
    {
        $db = $this->getDatabase();

        // Delete order items
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_items'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete payments
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_payments'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete shipments
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_shipments'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete activity log
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__alfa_order_activity_log'))
            ->whereIn('id_order', $pks);
        $db->setQuery($query);
        $db->execute();

        // Delete guest user info
        $ids = implode(',', array_map([$db, 'quote'], $pks));

        $subQuery1 = $db->getQuery(true)
            ->select($db->qn('id_address_delivery'))
            ->from($db->qn('#__alfa_orders'))
            ->where($db->qn('id') . ' IN (' . $ids . ')');

        $subQuery2 = $db->getQuery(true)
            ->select($db->qn('id_address_invoice'))
            ->from($db->qn('#__alfa_orders'))
            ->where($db->qn('id') . ' IN (' . $ids . ')');

        $query = $db->getQuery(true)
            ->delete($db->qn('#__alfa_user_info'))
            ->where('(' . $db->qn('id') . ' IN (' . $subQuery1 . ') OR ' . $db->qn('id') . ' IN (' . $subQuery2 . '))')
            ->where($db->qn('id_user') . ' = 0');

        $db->setQuery($query);
        $db->execute();
    }

    // ========================================================================
    // PREFILL DATA — For AJAX partial updates
    //
    // When updating an order via AJAX (e.g. status change from list view),
    // only the changed field(s) are sent. But save() needs a COMPLETE
    // record because $table->bind($data) overwrites everything.
    //
    // prefillData() loads the full current row from the Table object,
    // then overlays only the fields you sent. Result: save() gets
    // a complete record and all its logic fires correctly.
    //
    // We use Table (not getItem) because:
    //   - Table is lightweight: 1 query, raw columns only
    //   - getItem loads items, payments, shipments, Money objects, history
    //   - For a status change we just need the orders row, not the universe
    // ========================================================================

    /**
     * Prefill order data for partial updates.
     *
     * Loads the FULL current row from the Table object (1 lightweight query),
     * then overlays the provided $data on top. Fields in $data win;
     * all other fields keep their current DB values.
     *
     * This ensures save() always receives a complete record, so all its
     * logic fires correctly: prepareTable, stock transitions, activity log.
     *
     * @param int $recordKey Order PK
     * @param array $data Partial data to overlay (changed fields only)
     *
     * @return array Complete data array safe for save()
     *
     * @since   4.1.0
     */
    public function prefillData(int $recordKey, array $data = []): array
    {
        if ($recordKey <= 0) {
            return $data;
        }

        $table = $this->getTable();

        if (!$table->load($recordKey)) {
            return $data;
        }

        // Get all table columns and their current values
        $fields = $table->getFields();

        foreach ($fields as $field) {
            $fieldName = $field->Field;

            // Don't overwrite fields that the caller explicitly set
            if (array_key_exists($fieldName, $data)) {
                continue;
            }

            // Fill from current DB values
            $data[$fieldName] = $table->{$fieldName};
        }

        return $data;
    }

    // ========================================================================
    // REUSABLE HELPERS
    //
    // Shared utilities used across payments, shipments, items, and orders.
    // Every method here exists to eliminate duplication.
    // ========================================================================

    /**
     * Load a Currency object from our currencies table
     *
     * Maps alfa_currencies.number → Brick\Money ISO numeric code.
     * Falls back to default currency on failure.
     *
     * Used by: getItem(), getPaymentData(), getShipmentData(), getOrderItemData(),
     *          saveOrderItem(), and any method that needs to wrap values as Money.
     *
     * @param int $currencyId alfa_currencies.id (NOT the ISO number)
     */
    protected function loadCurrency(int $currencyId): Currency
    {
        try {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('number')
                ->from('#__alfa_currencies')
                ->where('id = ' . intval($currencyId));
            $db->setQuery($query);
            $currencyNumber = $db->loadResult();

            if ($currencyNumber) {
                return Currency::loadByNumber((int) $currencyNumber);
            }
        } catch (Exception $e) {
            Log::add('Failed to load currency: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
        }

        return Currency::getDefault();
    }

    /**
     * Get a related admin model (Payment, Shipment, etc.)
     *
     * Wraps the verbose Joomla bootComponent → getMVCFactory → createModel chain
     * into a single reusable call.
     *
     * @param string $name Model name: 'Payment', 'Shipment', etc.
     */
    protected function getRelatedModel(string $name): AdminModel
    {
        return Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel($name, 'Administrator', ['ignore_request' => true]);
    }

    /**
     * Convert a Money object (or numeric value) to float for database storage
     *
     * Safe to call on any value — returns float whether given Money, string, or number.
     *
     * @param mixed $value Money object, numeric string, or number
     */
    protected function moneyToFloat(mixed $value): float
    {
        if ($value instanceof Money) {
            return $value->getAmount();
        }

        return (float) ($value ?? 0);
    }

    /**
     * Wrap a raw database value as a Money object
     *
     * Null-safe: treats null/empty as zero.
     *
     * @param mixed $value Raw DB value (string, float, null)
     * @param Currency $currency Currency for the Money object
     */
    protected function toMoney(mixed $value, Currency $currency): Money
    {
        return Money::of($value ?? 0, $currency);
    }

    /**
     * Look up a method/entity name from a related model
     *
     * Used to snapshot payment_method / shipment_method_name before saving,
     * so the name persists even if the method is deleted later.
     *
     * @param string $modelName 'Payment' or 'Shipment'
     * @param int $methodId PK of the method row
     * @return string Method name, or empty string if not found
     */
    protected function getMethodName(string $modelName, int $methodId): string
    {
        if ($methodId <= 0) {
            return '';
        }

        $model = $this->getRelatedModel($modelName);
        $method = $model->getItem($methodId);

        return $method->name ?? '';
    }

    /**
     * Convert an object (with possible Money values) to a flat array for form binding
     *
     * Forms expect scalar values — Money objects cause empty fields.
     * Converts Money → float, passes everything else through.
     *
     * Used by: getPaymentForm(), getShipmentForm(), getOrderItemForm()
     *
     * @param object $data Object from getPaymentData(), getShipmentData(), etc.
     * @return array Flat array safe for Form::bind()
     */
    protected function objectToFormData(object $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($value instanceof Money) {
                $result[$key] = $value->getAmount();
            } elseif (is_object($value)) {
                // Skip nested objects (params, currency, etc.) — forms don't bind them
                continue;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Get order's currency ID from the orders table
     *
     * @param int $orderId Order PK
     * @return int Currency ID (defaults to 1)
     */
    public function getOrderCurrencyId(int $orderId): int
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('id_currency')
            ->from('#__alfa_orders')
            ->where('id = ' . intval($orderId));
        $db->setQuery($query);
        return (int) ($db->loadResult() ?? 1);
    }

    /**
     * Save an associative array to a database table
     *
     * Queries actual table columns and strips unknown keys — prevents
     * "Unknown column" errors when form data has extra fields.
     * Uses INSERT for new rows, UPDATE for existing.
     *
     * @param array &$data Form data (by ref — PK is set on insert)
     * @param string $tableName Full table name with #__ prefix
     * @param string $pkColumn Primary key column name
     */
    protected function saveDataOnTable(&$data, $tableName, $pkColumn = 'id'): bool
    {
        $db = $this->getDatabase();

        // Filter to actual DB columns — prevents "Unknown column" errors
        $tableColumns = $db->getTableColumns($tableName);
        $filteredData = array_intersect_key($data, $tableColumns);

        if (empty($filteredData)) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_ERROR_NO_VALID_COLUMNS'), 'error');
            return false;
        }

        $isNew = empty($filteredData[$pkColumn]) || (int) $filteredData[$pkColumn] === 0;

        try {
            if ($isNew) {
                unset($filteredData[$pkColumn]);
                $obj = (object) $filteredData;
                $db->insertObject($tableName, $obj, $pkColumn);
                $data[$pkColumn] = $obj->{$pkColumn} ?? $db->insertid();
            } else {
                $obj = (object) $filteredData;
                $db->updateObject($tableName, $obj, $pkColumn);
                $data[$pkColumn] = $filteredData[$pkColumn];
            }
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        return true;
    }

    /**
     * Delete a single row from a table by PK
     *
     * @param string $tableName Full table name with #__ prefix
     * @param int $id Row PK value
     * @param string $pkColumn Primary key column name
     */
    protected function deleteTableEntry($tableName, $id, $pkColumn = 'id'): bool
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->delete($db->qn($tableName))
            ->where($db->qn($pkColumn) . ' = ' . intval($id));

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }

        return true;
    }

    // ========================================================================
    // ORDER ACTIVITY LOG (Unified Event System)
    //
    // Single table: #__alfa_order_activity_log
    // ONE timeline for everything that happens to an order.
    //
    // Event format: "entity.action"
    //
    // Model writes (CRUD — admin actions):
    //   order.created, order.edited
    //   item.added, item.edited, item.deleted
    //   payment.added, payment.edited, payment.deleted
    //   shipment.added, shipment.edited, shipment.deleted
    //
    // Plugins write (gateway/carrier events):
    //   payment.captured, payment.refunded, payment.failed
    //   shipment.tracking_updated, shipment.delivered
    //
    // Plugin usage (public static — callable from anywhere):
    //   OrderModel::logOrderActivity($orderId, 'payment.captured', 'Stripe captured €50', [
    //       'transaction_id' => 'ch_3MqBE2...', 'gateway' => 'stripe', 'amount' => 50.00
    //   ], $paymentId);
    //
    // Status snapshot on EVERY row — always know the order state
    // when an event happened.
    // ========================================================================

    /**
     * Log an event to the unified order activity log
     *
     * @param int $orderId Order ID
     * @param string $event Dot-notation event: 'order.edited', 'item.added', etc.
     * @param string $summary Human-readable summary
     * @param array|null $context Structured data (changes, metadata, gateway info)
     * @param int|null $entityId Related entity PK (order_items.id, order_payments.id, etc.)
     */
    public static function logOrderActivity(
        int $orderId,
        string $event,
        string $summary,
        ?array $context = null,
        ?int $entityId = null,
    ): void {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $user = Factory::getApplication()->getIdentity();

            // Get current order status for snapshot
            $statusQuery = $db->getQuery(true)
                ->select(['o.id_order_status', 's.name AS status_name'])
                ->from('#__alfa_orders AS o')
                ->join('LEFT', '#__alfa_orders_statuses AS s ON s.id = o.id_order_status')
                ->where('o.id = ' . intval($orderId));
            $db->setQuery($statusQuery);
            $status = $db->loadObject();

            $log = new stdClass();
            $log->id_order = $orderId;
            $log->id_employee = $user->id ?? 0;
            $log->employee_name = $user->name ?? 'System';
            $log->event = $event;
            $log->id_order_status = $status->id_order_status ?? null;
            $log->status_name = $status->status_name ?? '';
            $log->entity_id = $entityId;
            $log->summary = mb_substr($summary, 0, 500);
            $log->context = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;
            $log->created = Factory::getDate('now', 'UTC')->toSql();

            $db->insertObject('#__alfa_order_activity_log', $log);
        } catch (Exception $e) {
            // Never let logging break the main operation
            Log::add('Activity log failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
        }
    }

    /**
     * Get activity log entries for an order
     *
     * @param int $orderId Order ID
     * @param string $eventFilter Filter by event prefix: 'item', 'payment', 'status', '' = all
     * @param int $limit Max entries
     */
    public function getOrderActivityLog(int $orderId, string $eventFilter = '', int $limit = 100): array
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('*')
            ->from('#__alfa_order_activity_log')
            ->where('id_order = ' . intval($orderId))
            ->order('created DESC');

        if (!empty($eventFilter)) {
            $query->where('event LIKE ' . $db->quote($eventFilter . '.%'));
        }

        $query->setLimit($limit);
        $db->setQuery($query);

        try {
            return $db->loadObjectList() ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    protected function populateState()
    {
        parent::populateState();

        $input = Factory::getApplication()->getInput();
        $id_order = $input->get('id_order', null, 'STRING');

        if ($id_order !== null) {
            $this->setState($this->getName() . '.id', $id_order);
        }
    }
}
