<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Order Controller
 *
 * Handles order CRUD, payment/shipment/item sub-entity operations,
 * plugin action execution, and generic AJAX order updates.
 *
 * Endpoints:
 *   order.updateOrder          — Generic AJAX update (any field)
 *   order.executePaymentAction — Plugin payment action (AJAX)
 *   order.executeShipmentAction— Plugin shipment action (AJAX)
 *   order.savePayment          — Payment CRUD (form POST)
 *   order.saveShipment         — Shipment CRUD (form POST)
 *   order.saveOrderItem        — Order item CRUD (form POST)
 *   order.searchProducts       — Product search (AJAX)
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Payments\ExecutePaymentActionEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\ExecuteShipmentActionEvent;
use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\PluginLayoutHelper;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;
use Throwable;

class OrderController extends FormController
{
    protected $view_list = 'orders';

    /**
     * Get Order Model properly.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @throws Exception If model cannot be loaded
     *
     * @since   3.0.0
     */
    protected function getOrderModel()
    {
        $app = Factory::getApplication();

        $model = $app->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel('Order', 'Administrator', ['ignore_request' => true]);

        if (!$model) {
            throw new Exception(Text::_('COM_ALFA_ERROR_MODEL_NOT_LOADED'));
        }

        return $model;
    }

    // ═════════════════════════════════════════════════════════════
    //  GENERIC ORDER UPDATE (AJAX)
    //
    //  Accepts any subset of order fields via JSON POST.
    //  Missing fields are prefilled from the current DB row
    //  via prefillData() so save() always gets a complete record.
    //
    //  Used by: list view status dropdown, inline editing, bulk ops.
    //
    //  Example JS call:
    //    fetch('index.php?option=com_alfa&task=order.updateOrder', {
    //        method: 'POST',
    //        headers: { 'Content-Type': 'application/json' },
    //        body: JSON.stringify({
    //            order_id: 31,
    //            data: { id_order_status: 5 }
    //        })
    //    })
    // ═════════════════════════════════════════════════════════════

    /**
     * Generic AJAX endpoint for updating any order field(s).
     *
     * Accepts a JSON body with:
     *   order_id: int — the order PK
     *   data: object — key/value pairs of fields to change
     *
     * The model's prefillData() loads the full current row from the
     * Table object, then overlays only the fields you sent. This
     * ensures save() always receives a complete record and all its
     * logic fires correctly (stock transitions, activity logging, etc.).
     *
     * Returns JSON: { success: bool, message: string, data?: {...} }
     *
     *
     * @since   4.1.0
     */
    public function updateOrder(): void
    {
        $app = Factory::getApplication();

        try {
            // ── Parse JSON body ─────────────────────────────────
            $json = json_decode(file_get_contents('php://input'), true) ?: [];
            $orderId = (int) ($json['order_id'] ?? $app->input->getInt('order_id', 0));
            $data = $json['data'] ?? [];

            if ($orderId <= 0) {
                throw new Exception(Text::_('COM_ALFA_ERROR_INVALID_ORDER_ID'));
            }

            if (empty($data)) {
                throw new Exception(Text::_('COM_ALFA_ERROR_NO_DATA'));
            }

            // ── Load model and prefill ──────────────────────────
            $model = $this->getOrderModel();
            $fullData = $model->prefillData($orderId, $data);

            // ── Save through the standard save() flow ───────────
            // save() calls enqueueMessage() for stock operations,
            // activity logging, validation warnings, etc.
            // We capture all of them via getMessageQueue() below.
            if (!$model->save($fullData)) {
                throw new Exception(
                    $model->getError() ?: Text::_('COM_ALFA_ERROR_ORDER_UPDATE_FAILED'),
                );
            }

            // ── Success response ────────────────────────────────
            $this->sendJsonResponse(true, Text::_('COM_ALFA_ORDER_UPDATED'), [
                'order_id' => $orderId,
                'changed' => array_keys($data),
            ]);
        } catch (Throwable $e) {
            $this->sendJsonResponse(false, $e->getMessage());
        }
    }

    /**
     * Send a clean JSON response with Joomla's message queue.
     *
     * Includes all messages that were enqueued during processing
     * (stock operations, activity logging, validation, etc.) so the
     * frontend can display them. Same pattern as Dianemo's
     * returnAjaxResponse().
     *
     * The JS handles each message type differently:
     *   - messages[type=error]   → sticky red toast
     *   - messages[type=warning] → sticky amber toast
     *   - messages[type=message] → console.log() (informational)
     *
     * If raw PHP output (deprecations, notices) somehow gets into the
     * response, the JS handles it gracefully via response.text() +
     * try/catch JSON.parse() — no crash, error shown to admin.
     *
     * @param bool $success Whether the operation succeeded
     * @param string $message Main response message
     * @param array|null $data Optional response data
     *
     *
     * @since   4.1.0
     */
    private function sendJsonResponse(bool $success, string $message, ?array $data = null): void
    {
        $app = Factory::getApplication();

        // ── Capture Joomla message queue ────────────────────────
        // getMessageQueue(true) returns + clears all messages that
        // were enqueued during save(): stock info, warnings, etc.
        $messages = $app->getMessageQueue(true);

        // ── Build response ──────────────────────────────────────
        $response = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'messages' => $messages,
        ];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response);
        $app->close();
    }

    // ═════════════════════════════════════════════════════════════
    //  GET ACTIONS (AJAX) — Lazy-loaded for detail panels
    //
    //  Returns all available plugin actions for an order's
    //  shipments and payments. Called when the detail panel
    //  opens — one request per order, no page-load cost.
    //
    //  The JS renders action buttons from the JSON response
    //  and calls executeShipmentAction/executePaymentAction
    //  on click — going through the full plugin system.
    // ═════════════════════════════════════════════════════════════

    /**
     * Get all available plugin actions for an order's entities.
     *
     * Returns shipment and payment actions as serialized arrays
     * so the JS can render buttons dynamically in the detail panel.
     *
     * Each action includes: id, label, icon, class, enabled,
     * requires_confirmation, confirmation_message, tooltip.
     *
     * Complex actions (custom button_layout, modal response_layout)
     * are still available — the JS renders a simplified button
     * and the execution goes through the full plugin system.
     *
     *
     * @since   4.1.0
     */
    public function getOrderActions(): void
    {
        $app = Factory::getApplication();

        try {
            $orderId = $app->input->getInt('order_id', 0);

            if ($orderId <= 0) {
                throw new Exception('Invalid order ID');
            }

            $model = $this->getOrderModel();

            // Load the order (needed as context for ActionRegistry)
            $order = $model->getItem($orderId);
            if (!$order || empty($order->id)) {
                throw new Exception('Order not found');
            }

            $shipmentActions = [];
            $paymentActions = [];

            // ── Get shipment actions ────────────────────────────
            // Each shipment needs params->type for plugin resolution.
            // getShipmentData() loads the row + method params.
            if (!empty($order->shipments)) {
                foreach ($order->shipments as $shipment) {
                    // Load full shipment data with params (needed by ActionRegistry)
                    $shipmentData = $model->getShipmentData($shipment->id);
                    if (!$shipmentData) {
                        continue;
                    }

                    $actions = ActionRegistry::getShipmentActions($shipmentData, $order);

                    if (!empty($actions)) {
                        $shipmentActions[$shipment->id] = array_map(
                            fn ($a) => $a->toArray(),
                            $actions,
                        );
                    }
                }
            }

            // ── Get payment actions ─────────────────────────────
            if (!empty($order->payments)) {
                foreach ($order->payments as $payment) {
                    $paymentData = $model->getPaymentData($payment->id);
                    if (!$paymentData) {
                        continue;
                    }

                    $actions = ActionRegistry::getPaymentActions($paymentData, $order);

                    if (!empty($actions)) {
                        $paymentActions[$payment->id] = array_map(
                            fn ($a) => $a->toArray(),
                            $actions,
                        );
                    }
                }
            }

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'data' => [
                    'shipments' => $shipmentActions,
                    'payments' => $paymentActions,
                ],
            ]);
            $app->close();
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
            $app->close();
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  PLUGIN ACTION EXECUTION (AJAX)
    // ═════════════════════════════════════════════════════════════

    /**
     * Execute a payment plugin action via AJAX.
     *
     * Called by order-actions.js → Alfa.executeAction('payment', id, action)
     *
     * Flow:
     *   1. Read JSON body (id, action)
     *   2. Load payment + order from model
     *   3. Boot the payment plugin
     *   4. Dispatch onExecuteAction event
     *   5. If result has a layout, render it via PluginLayoutHelper
     *   6. Return JSON response
     *
     *
     * @since   3.0.0
     */
    public function executePaymentAction(): void
    {
        $app = Factory::getApplication();

        $json = json_decode(file_get_contents('php://input'), true) ?: [];
        $paymentId = $json['id'] ?? $app->input->getInt('id', 0);
        $action = $json['action'] ?? $app->input->getString('action', '');

        try {
            // ── Load payment + order ─────────────────────────────
            $model = $this->getOrderModel();
            $payment = $model->getPaymentData($paymentId);

            if (!$payment) {
                throw new Exception("Payment #{$paymentId} not found.");
            }

            $order = $model->getItem($payment->id_order);

            // ── Boot plugin ──────────────────────────────────────
            $pluginType = $payment->params->type ?? 'standard';
            $plugin = $app->bootPlugin($pluginType, 'alfa-payments');

            if (!$plugin) {
                throw new Exception("Payment plugin \"{$pluginType}\" not found.");
            }

            if (!method_exists($plugin, 'onExecuteAction')) {
                throw new Exception(get_class($plugin) . ' has no onExecuteAction() method.');
            }

            // ── Dispatch event ───────────────────────────────────
            $event = new ExecutePaymentActionEvent('onExecuteAction', [
                'action' => $action,
                'payment' => $payment,
                'order' => $order,
                'data' => $json['data'] ?? [],
            ]);

            $plugin->onExecuteAction($event);

            // ── Render layout if plugin set one ──────────────────
            if ($event->getLayout()) {
                $html = PluginLayoutHelper::pluginLayout(
                    'alfa-payments',
                    $pluginType,
                    $event->getLayout(),
                )->render($event->getLayoutData());

                $event->setHtml($html);
            }

            // ── Return JSON ──────────────────────────────────────
            header('Content-Type: application/json; charset=utf-8');
            echo $event->toResponseJson();
            $app->close();
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            $app->close();
        }
    }

    /**
     * Execute a shipment plugin action via AJAX.
     *
     * Called by order-actions.js → Alfa.executeAction('shipment', id, action)
     *
     * Flow identical to executePaymentAction() but for shipment context.
     *
     *
     * @since   3.0.0
     */
    public function executeShipmentAction(): void
    {
        $app = Factory::getApplication();

        $json = json_decode(file_get_contents('php://input'), true) ?: [];
        $shipmentId = $json['id'] ?? $app->input->getInt('id', 0);
        $action = $json['action'] ?? $app->input->getString('action', '');

        try {
            // ── Load shipment + order ────────────────────────────
            $model = $this->getOrderModel();
            $shipment = $model->getShipmentData($shipmentId);

            if (!$shipment) {
                throw new Exception("Shipment #{$shipmentId} not found.");
            }

            $order = $model->getItem($shipment->id_order);

            // ── Boot plugin ──────────────────────────────────────
            $pluginType = $shipment->params->type ?? 'standard';
            $plugin = $app->bootPlugin($pluginType, 'alfa-shipments');

            if (!$plugin) {
                throw new Exception("Shipment plugin \"{$pluginType}\" not found.");
            }

            if (!method_exists($plugin, 'onExecuteAction')) {
                throw new Exception(get_class($plugin) . ' has no onExecuteAction() method.');
            }

            // ── Dispatch event ───────────────────────────────────
            $event = new ExecuteShipmentActionEvent('onExecuteAction', [
                'action' => $action,
                'shipment' => $shipment,
                'order' => $order,
                'data' => $json['data'] ?? [],
            ]);

            $plugin->onExecuteAction($event);

            // ── Render layout if plugin set one ──────────────────
            if ($event->getLayout()) {
                $html = PluginLayoutHelper::pluginLayout(
                    'alfa-shipments',
                    $pluginType,
                    $event->getLayout(),
                )->render($event->getLayoutData());

                $event->setHtml($html);
            }

            // ── Return JSON ──────────────────────────────────────
            header('Content-Type: application/json; charset=utf-8');
            echo $event->toResponseJson();
            $app->close();
        } catch (Throwable $e) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            $app->close();
        }
    }

    // ═════════════════════════════════════════════════════════════
    //  PAYMENTS
    // ═════════════════════════════════════════════════════════════

    /**
     * Save payment and return to order.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function savePayment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $paymentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $success = false;

        $data = $this->input->post->get('jform', [], 'array');

        if (empty($data['id_order'])) {
            $data['id_order'] = $orderID;
        }

        try {
            $model = $this->getOrderModel();

            if ($model->saveOrderPayment($data)) {
                $success = true;

                $paymentID = $model->getState('payment.id')
                    ?? $data['id']
                    ?? $paymentID;

                $app->enqueueMessage(Text::_('COM_ALFA_PAYMENT_SAVED'), 'success');
            }
        } catch (Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_payment&id={$paymentID}&id_order={$orderID}";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_payment_return&id={$paymentID}&id_order={$orderID}";

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    /**
     * Cancel payment editing.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function cancelPayment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $paymentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');
        $shouldReload = 0;

        $redirectSuccessURL =
            "index.php?option={$this->option}"
            . '&view=order'
            . '&layout=edit_payment_return'
            . '&tmpl=component'
            . "&id={$paymentID}"
            . "&id_order={$orderID}"
            . "&reload={$shouldReload}";

        $this->setRedirect(Route::_($redirectSuccessURL, false));
    }

    /**
     * Delete payment.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function deletePayment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $paymentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_payment&id={$paymentID}&id_order={$orderID}";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_payment_return&id_order={$orderID}";

        $success = false;

        try {
            $model = $this->getOrderModel();

            if ($model->deleteOrderPayment($paymentID, $orderID)) {
                $success = true;
                $app->enqueueMessage(Text::_('COM_ALFA_PAYMENT_DELETED'), 'success');
            }
        } catch (Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    // ═════════════════════════════════════════════════════════════
    //  SHIPMENTS
    // ═════════════════════════════════════════════════════════════

    /**
     * Save shipment.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function saveShipment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $shipmentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $success = false;

        $data = $this->input->post->get('jform', [], 'array');

        if (empty($data['id_order'])) {
            $data['id_order'] = $orderID;
        }

        // ── Fix: HTML multi-selects don't submit when empty ─────
        // When the admin deselects ALL items, the 'items' key is
        // missing from the POST data entirely (HTML behavior).
        // For existing shipments, we need to explicitly set it to []
        // so the helper knows to unassign all items.
        // New shipments (id=0) don't need this — no items to unassign.
        $isExisting = !empty($data['id']) && (int) $data['id'] > 0;
        if ($isExisting && !isset($data['items'])) {
            $data['items'] = [];
        }

        try {
            $model = $this->getOrderModel();

            if ($model->saveOrderShipment($data)) {
                $success = true;

                $shipmentID = $model->getState('shipment.id')
                    ?? $data['id']
                    ?? $shipmentID;

                $app->enqueueMessage(Text::_('COM_ALFA_SHIPMENT_SAVED'), 'success');
            }
        } catch (Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_shipment&id={$shipmentID}&id_order={$orderID}";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_shipment_return&id={$shipmentID}&id_order={$orderID}";

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    /**
     * Cancel shipment editing.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function cancelShipment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $shipmentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');
        $shouldReload = 0;

        $redirectSuccessURL =
            "index.php?option={$this->option}"
            . '&view=order'
            . '&layout=edit_shipment_return'
            . '&tmpl=component'
            . "&id={$shipmentID}"
            . "&id_order={$orderID}"
            . "&reload={$shouldReload}";

        $this->setRedirect(Route::_($redirectSuccessURL, false));
    }

    /**
     * Delete shipment.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function deleteShipment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $shipmentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_shipment&id={$shipmentID}&id_order={$orderID}";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_shipment_return&id_order={$orderID}";

        $success = false;

        try {
            $model = $this->getOrderModel();

            if ($model->deleteOrderShipment($shipmentID, $orderID)) {
                $success = true;
                $app->enqueueMessage(Text::_('COM_ALFA_SHIPMENT_DELETED'), 'success');
            }
        } catch (Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    // ═════════════════════════════════════════════════════════════
    //  ORDER ITEMS
    // ═════════════════════════════════════════════════════════════

    /**
     * Save order item.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function saveOrderItem()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $itemID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $success = false;

        $data = $this->input->post->get('jform', [], 'array');

        if (!isset($data['id_order']) || !$data['id_order']) {
            $data['id_order'] = $orderID;
        }

        try {
            $model = $this->getOrderModel();

            if ($model->saveOrderItem($data)) {
                $success = true;
                $itemID = $data['id'] ?? $itemID;
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_ITEM_SAVED'), 'success');
            }
        } catch (Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_order_item&id={$itemID}&id_order={$orderID}";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_order_item_return&id={$itemID}&id_order={$orderID}";

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    /**
     * Cancel order item editing.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function cancelOrderItem()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $itemID = $input->getInt('id');
        $orderID = $input->getInt('id_order');
        $shouldReload = 0;

        $redirectSuccessURL =
            "index.php?option={$this->option}"
            . '&view=order'
            . '&layout=edit_order_item_return'
            . '&tmpl=component'
            . "&id={$itemID}"
            . "&id_order={$orderID}"
            . "&reload={$shouldReload}";

        $this->setRedirect(Route::_($redirectSuccessURL, false));
    }

    /**
     * Delete order item.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function deleteOrderItem()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $itemID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_order_item&id={$itemID}&id_order={$orderID}";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_order_item_return&id_order={$orderID}";

        $success = false;

        try {
            $model = $this->getOrderModel();

            if ($model->deleteOrderItem($itemID, $orderID)) {
                $success = true;
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_ITEM_DELETED'), 'success');
            }
        } catch (Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    // ═════════════════════════════════════════════════════════════
    //  AJAX PRODUCT SEARCH
    // ═════════════════════════════════════════════════════════════

    /**
     * AJAX endpoint for product search (used by order item popup).
     *
     * Builds PriceContext from the ORDER'S CUSTOMER (not admin session)
     * so prices/discounts/taxes reflect what the customer would see.
     *
     * @return void
     *
     * @since   3.0.0
     */
    public function searchProducts()
    {
        $this->checkToken('get') || $this->checkToken('post') || die('Invalid Token');

        $app = Factory::getApplication();
        $input = $app->input;

        $query = $input->getString('q', '');
        $orderId = $input->getInt('id_order', 0);
        $limit = $input->getInt('limit', 20);

        $context = ($orderId > 0)
            ? \Alfa\Component\Alfa\Administrator\Helper\OrderHelper::buildOrderPriceContext($orderId)
            : \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext::fromSession();

        $results = \Alfa\Component\Alfa\Administrator\Helper\OrderHelper::searchProducts($query, $context, $limit);

        $output = [];
        foreach ($results as $item) {
            $output[] = [
                'id' => (int) $item->id,
                'name' => $item->name,
                'sku' => $item->sku ?? '',
                'gtin' => $item->gtin ?? '',
                'mpn' => $item->mpn ?? '',
                'stock' => (float) ($item->stock ?? 0),
                'weight' => (float) ($item->weight ?? 0),
                'manage_stock' => (int) ($item->manage_stock ?? 1),
                'price_tax_incl' => (float) ($item->price_tax_incl ?? 0),
                'price_tax_excl' => (float) ($item->price_tax_excl ?? 0),
                'base_price' => (float) ($item->base_price ?? 0),
                'customer_price' => (float) ($item->customer_price ?? $item->price_tax_incl ?? 0),
                'has_discount' => (bool) ($item->has_discount ?? false),
                'discount_percent' => (float) ($item->discount_percent ?? 0),
                'tax_rate' => (float) ($item->tax_rate ?? 0),
                'tax_name' => $item->tax_name ?? '',
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['results' => $output]);
        $app->close();
    }
}
