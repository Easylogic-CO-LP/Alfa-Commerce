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
 *   order.updateOrder          - Generic AJAX update (any field)
 *   order.savePayment          - Payment CRUD (form POST)
 *   order.saveShipment         - Shipment CRUD (form POST)
 *   order.saveOrderItem        - Order item CRUD (form POST)
 *   order.previewOrderItem     - Order item save dry run, the popup's preview (AJAX POST)
 *   order.searchProducts       - Product search (AJAX)
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Controller\Traits\HasFormPermissions;
use Alfa\Component\Alfa\Administrator\Helper\ActionRegistry;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Credit\CreditNoteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger;
use Alfa\Component\Alfa\Administrator\Service\Document\CorrectionStrategies;
use Alfa\Component\Alfa\Administrator\Service\Document\CreditCalculator;
use Alfa\Component\Alfa\Administrator\Service\Document\CreditExclusionList;
use Alfa\Component\Alfa\Administrator\Service\Document\CreditInput;
use Alfa\Component\Alfa\Administrator\Service\Document\CreditOutcome;
use Alfa\Component\Alfa\Administrator\Service\Document\CreditResult;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentDraft;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentIssuer;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentMoney;
use Alfa\Component\Alfa\Administrator\Service\Document\DocumentRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Document\IncrementalCorrection;
use Alfa\Component\Alfa\Administrator\Service\Document\OrderChargeLines;
use Alfa\Component\Alfa\Administrator\Service\Document\OrderItemLines;
use Alfa\Component\Alfa\Administrator\Service\Document\VoidAndReplace;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Order\ManualDiscountScope;
use Alfa\Component\Alfa\Site\Helper\VariantHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Currency;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceAction;
use Alfa\Component\Alfa\Site\Service\Pricing\CustomPriceCommand;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoney;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactMoneyRefusal;
use Alfa\Component\Alfa\Site\Service\Pricing\ExplicitPriceInstruction;
use Exception;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\String\PunycodeHelper;
use RuntimeException;
use Throwable;

class OrderController extends FormController
{
    use HasFormPermissions;

    protected $view_list = 'orders';

    /**
     * The most characters a kept issue draft holds in a single-line field:
     * four times the widest column one feeds (255). Longer is no typing error
     * to correct, and is not kept.
     *
     * @var int
     * @since 1.14.0
     */
    private const ISSUE_DRAFT_TEXT_MAX = 1024;

    /**
     * The most BYTES a kept print note holds: what its TEXT column stores. The
     * issue path takes any note that fits, so a character cap below that
     * dropped valid input on an unrelated refusal.
     *
     * @var int
     * @since 1.14.0
     */
    private const ISSUE_DRAFT_NOTE_MAX_BYTES = 65535;

    /**
     * The most characters a kept charge amount holds.
     *
     * @var int
     * @since 1.14.0
     */
    private const ISSUE_DRAFT_AMOUNT_MAX = 32;

    /**
     * The movement purposes the issue form offers (forms/document.xml).
     *
     * @var string[]
     * @since 1.14.0
     */
    private const ISSUE_MOVEMENT_PURPOSES = ['', '1', '5', '6'];

    /**
     * How many times a credit is answered by onDocumentBeforeIssue and sized
     * under the order lock before a credit that keeps changing is refused.
     *
     * @var int
     * @since 1.14.0
     */
    private const CREDIT_ROUNDS = 3;

    /**
     * Assert the current backend user holds an Orders permission. These custom
     * tasks are NOT covered by FormController's built-in save/delete ACL, so
     * every endpoint gates here; otherwise any authenticated backend user (or a
     * CSRF against an admin) could reach orders.
     *
     * @param string $action The Orders verb: view, edit, edit.state or delete.
     *
     *
     * @throws RuntimeException 403 when the user lacks the right.
     * @since  1.3.0
     */
    protected function assertCanEdit(string $action = 'edit'): void
    {
        if (!AlfaHelper::can(section: 'orders', action: $action)) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }
    }

    /**
     * Assert the current backend user may issue a sales document by hand.
     *
     * Manual issuance is not an order edit: it draws a gapless fiscal number
     * and creates an externally meaningful record, so it needs the Orders edit
     * right AND the Sales Documents create right. Automatic issuance
     * (AutoIssuePolicy) is system policy and asks no staff permission.
     *
     *
     * @throws RuntimeException 403 when either right is missing.
     * @since  1.10.0
     */
    protected function assertCanIssueDocument(): void
    {
        $identity = $this->app->getIdentity();

        if (!AlfaHelper::can(section: 'orders', action: 'edit', user: $identity)
            || !AlfaHelper::can(section: 'documents', action: 'create', user: $identity)) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
        }
    }

    /**
     * Get Order Model properly.
     *
     * @return \Joomla\CMS\MVC\Model\BaseDatabaseModel
     *
     * @throws Exception If model cannot be loaded
     *
     * @since  1.0.0
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

    //  The edit form's kept post
    //
    //  Core keeps the order form's post on every reload (each modal
    //  return) and on a refused save. Only the redisplay those
    //  redirects lead to may read it back, so they carry kept=1;
    //  the view forgets the post on any other open (F-19).

    /**
     * Whether the redirect back to the order that this request builds leads
     * to the redisplay that reads the kept post back.
     *
     * @since 1.14.0
     */
    private bool $redisplaysKeptPost = false;

    /**
     * Keep the order form's post and redisplay the order with it: the
     * redirect every modal return takes.
     *
     * @param string|null $key The name of the primary key.
     * @param string|null $urlVar The name of the URL variable for the id.
     *
     * @return void
     *
     * @since  1.14.0
     */
    public function reload($key = null, $urlVar = null)
    {
        // Core keeps the post on every reload, so its redirect always leads to
        // the redisplay that reads it.
        $this->redisplaysKeptPost = true;

        parent::reload($key, $urlVar);
    }

    /**
     * Save the order; a refused save redisplays it with the post core kept.
     *
     * @param string|null $key The name of the primary key.
     * @param string|null $urlVar The name of the URL variable for the id.
     *
     * @return bool True when the order was saved.
     *
     * @since  1.14.0
     */
    public function save($key = null, $urlVar = null)
    {
        $saved = parent::save($key, $urlVar);

        $urlVar = $urlVar ?: ($key ?: $this->getModel()->getTable()->getKeyName());
        $recordId = $this->input->getInt($urlVar);

        // Refused and sent back to the order: core kept the post for exactly
        // that redisplay. A refusal sent to the list keeps nothing to show.
        if (!$saved && $this->redirect === $this->getRedirectUrlToItem($recordId, $urlVar)) {
            $this->redisplaysKeptPost = true;
            $this->setRedirect($this->getRedirectUrlToItem($recordId, $urlVar));
        }

        return $saved;
    }

    /**
     * The order's redirect arguments, marked kept=1 when the redirect leads to
     * the redisplay that reads the kept post back.
     *
     * @param int|null $recordId The order id.
     * @param string $urlVar The name of the URL variable for the id.
     *
     * @return string
     *
     * @since  1.14.0
     */
    protected function getRedirectToItemAppend($recordId = null, $urlVar = 'id')
    {
        return parent::getRedirectToItemAppend($recordId, $urlVar) . ($this->redisplaysKeptPost ? '&kept=1' : '');
    }

    //  Generic order update (AJAX)
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

    /**
     * Generic AJAX endpoint for updating any order field(s).
     *
     * Accepts a JSON body with:
     *   order_id: int - the order PK
     *   data: object - key/value pairs of fields to change
     *
     * The model's prefillData() loads the full current row from the
     * Table object, then overlays only the fields you sent. This
     * ensures save() always receives a complete record and all its
     * logic fires correctly (stock transitions, activity logging, etc.).
     *
     * Returns JSON: { success: bool, message: string, data?: {...} }
     *
     *
     * @since  1.0.0
     */
    public function updateOrder(): void
    {
        $app = Factory::getApplication();

        try {
            // CSRF (token rides in the query per orders-list.js) + ACL.
            if (!Session::checkToken('request')) {
                throw new RuntimeException(Text::_('JINVALID_TOKEN'), 403);
            }

            // Either order right may reach this endpoint (a status move needs only
            // edit.state); with neither, refuse before the order is read at all.
            if (
                !AlfaHelper::can(section: 'orders', action: 'edit')
                && !AlfaHelper::can(section: 'orders', action: 'edit.state')
            ) {
                throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 403);
            }

            // Parse JSON body
            $json = json_decode(file_get_contents('php://input'), true) ?: [];
            $orderId = (int) ($json['order_id'] ?? $app->input->getInt('order_id', 0));
            $data = $json['data'] ?? [];

            if ($orderId <= 0) {
                throw new Exception(Text::_('COM_ALFA_ERROR_INVALID_ORDER_ID'));
            }

            if (empty($data)) {
                throw new Exception(Text::_('COM_ALFA_ERROR_NO_DATA'));
            }

            // Allowlist: raw JSON bodies bypass the form pipeline, so this
            // is the only place stripping arbitrary #__alfa_orders columns.
            $data = OrderModel::filterInlineOrderData((array) $data);

            if (empty($data)) {
                throw new Exception(Text::_('COM_ALFA_ERROR_NO_DATA'));
            }

            // Each change asks its own permission, before any write
            // Notes and every other inline column are ordinary editing. Asked
            // first, since it needs no read of the order.
            if (array_diff(array_keys($data), ['id_order_status']) !== []) {
                $this->assertCanEdit(action: 'edit');
            }

            // Moving the order is Change State on its own: the list offers the
            // select to edit.state holders alone. Asked only when the status
            // actually differs; the model enforces the same rule for every caller.
            if (
                \array_key_exists('id_order_status', $data)
                && (int) $data['id_order_status'] !== OrderModel::persistedStatus($orderId)
            ) {
                $this->assertCanEdit(action: 'edit.state');
            }

            // Load model and prefill
            $model = $this->getOrderModel();
            $fullData = $model->prefillData($orderId, $data);

            // An unknown id prefills nothing, and save() would then create an
            // order instead of updating one.
            if ((int) ($fullData['id'] ?? 0) !== $orderId) {
                throw new Exception(Text::_('COM_ALFA_ORDER_NOT_FOUND'));
            }

            // Save through the standard save() flow
            // save() calls enqueueMessage() for stock operations,
            // activity logging, validation warnings, etc.
            // We capture all of them via getMessageQueue() below.
            if (!$model->save($fullData)) {
                throw new Exception(
                    $model->getError() ?: Text::_('COM_ALFA_ERROR_ORDER_UPDATE_FAILED'),
                );
            }

            // Success response
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
     * frontend can display them.
     *
     * The JS handles each message type differently:
     *   - messages[type=error]   -> sticky red toast
     *   - messages[type=warning] -> sticky amber toast
     *   - messages[type=message] -> console.log() (informational)
     *
     * If raw PHP output (deprecations, notices) somehow gets into the
     * response, the JS handles it gracefully via response.text() +
     * try/catch JSON.parse(): no crash, error shown to admin.
     *
     * @param bool $success Whether the operation succeeded
     * @param string $message Main response message
     * @param array|null $data Optional response data
     *
     *
     * @since  1.0.0
     */
    private function sendJsonResponse(bool $success, string $message, ?array $data = null): void
    {
        $app = Factory::getApplication();

        // Capture Joomla message queue
        // getMessageQueue(true) returns + clears all messages that
        // were enqueued during save(): stock info, warnings, etc.
        $messages = $app->getMessageQueue(true);

        // Build response
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

    //  GET ACTIONS (AJAX) - Lazy-loaded for detail panels
    //
    //  Returns all available plugin actions for an order's
    //  shipments and payments. Called when the detail panel
    //  opens: one request per order, no page-load cost.
    //
    //  The JS renders action buttons from the JSON response;
    //  each opens the action page (view=action) on click.

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
     * are still available; the JS renders a simplified button
     * and the execution goes through the full plugin system.
     *
     *
     * @since  1.0.0
     */
    public function getOrderActions(): void
    {
        $app = Factory::getApplication();

        try {
            // Every listed action opens view=action, which asks orders edit; without
            // it the answer is no buttons (orders-list.js clears the placeholders)
            // rather than buttons that always 403.
            $this->assertCanEdit(action: 'edit');

            $orderId = $app->input->getInt('order_id', 0);

            if ($orderId <= 0) {
                throw new Exception(Text::_('COM_ALFA_ORDER_INVALID_ID'));
            }

            $model = $this->getOrderModel();

            // Load the order (needed as context for ActionRegistry)
            $order = $model->getItem($orderId);
            if (!$order || empty($order->id)) {
                throw new Exception(Text::_('COM_ALFA_ORDER_NOT_FOUND'));
            }

            $shipmentActions = [];
            $paymentActions = [];

            // Get shipment actions
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

            // Get payment actions
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

    //  Payments

    /**
     * Save payment and return to order.
     *
     * The post is read back through the modal's own form first
     * ({@see self::modalWriteData()}): its dates into UTC, then validated.
     * A refusal keeps what was typed for this payment and redisplays the modal
     * with `kept=1`, the one open that binds it (the issue modal's pattern);
     * a success forgets it.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function savePayment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $this->checkToken();
        $this->assertCanEdit();

        $paymentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $success = false;

        $data = $this->input->post->get('jform', [], 'array');

        if (empty($data['id_order'])) {
            $data['id_order'] = $orderID;
        }

        $posted = $data;
        $form = null;
        $stateKey = 'com_alfa.edit.order.payment.' . $orderID . '.' . $paymentID;

        try {
            $model = $this->getOrderModel();
            $form = $model->getPaymentForm([], false);
            $data = $this->modalWriteData(form: $form, data: $data);

            if ($data !== null && $model->saveOrderPayment($data)) {
                $success = true;

                $paymentID = $model->getState('payment.id')
                    ?? $data['id']
                    ?? $paymentID;

                $app->enqueueMessage(Text::_('COM_ALFA_PAYMENT_SAVED'), 'success');
            }
        } catch (Throwable $e) {
            // Escaped, as every sibling task escapes: the ledger's and the
            // driver's words can quote a typed value.
            $app->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $app->setUserState($stateKey, $success ? null : $this->keptModalDraft(form: $form, posted: $posted));

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_payment&id={$paymentID}&id_order={$orderID}&kept=1";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_payment_return&id={$paymentID}&id_order={$orderID}";

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    /**
     * Cancel payment editing.
     *
     * @return void
     *
     * @since  1.0.0
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
     * Removing a payment edits its order, so it asks orders edit like every
     * other sub-row removal, not the delete right that removes whole orders.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function deletePayment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $this->checkToken();
        $this->assertCanEdit();

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
        } catch (Throwable $e) {
            // Escaped, as every sibling task escapes.
            $app->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    //  Shipments

    /**
     * Save shipment.
     *
     * The post is read back through the modal's own form first
     * ({@see self::modalWriteData()}): its dates into UTC, the read-only
     * Shipped and Delivered dropped, then validated. A refusal keeps what was
     * typed for this shipment and redisplays the modal with `kept=1`; a
     * success forgets it.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function saveShipment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $this->checkToken();
        $this->assertCanEdit();

        $shipmentID = $input->getInt('id');
        $orderID = $input->getInt('id_order');

        $success = false;

        $data = $this->input->post->get('jform', [], 'array');

        if (empty($data['id_order'])) {
            $data['id_order'] = $orderID;
        }

        // Fix: HTML multi-selects don't submit when empty
        // When the admin deselects ALL items, the 'items' key is
        // missing from the POST data entirely (HTML behavior).
        // For existing shipments, we need to explicitly set it to []
        // so the helper knows to unassign all items.
        // New shipments (id=0) don't need this: no items to unassign.
        $isExisting = !empty($data['id']) && (int) $data['id'] > 0;
        if ($isExisting && !isset($data['items'])) {
            $data['items'] = [];
        }

        $posted = $data;
        $form = null;
        $stateKey = 'com_alfa.edit.order.shipment.' . $orderID . '.' . $shipmentID;

        try {
            $model = $this->getOrderModel();
            $form = $model->getShipmentForm([], false);
            $data = $this->modalWriteData(form: $form, data: $data);

            if ($data !== null && $model->saveOrderShipment($data)) {
                $success = true;

                $shipmentID = $model->getState('shipment.id')
                    ?? $data['id']
                    ?? $shipmentID;

                $app->enqueueMessage(Text::_('COM_ALFA_SHIPMENT_SAVED'), 'success');
            }
        } catch (Throwable $e) {
            // Escaped, as every sibling task escapes: the ledger's and the
            // driver's words can quote a typed value.
            $app->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $app->setUserState($stateKey, $success ? null : $this->keptModalDraft(form: $form, posted: $posted));

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_shipment&id={$shipmentID}&id_order={$orderID}&kept=1";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_shipment_return&id={$shipmentID}&id_order={$orderID}";

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    /**
     * Cancel shipment editing.
     *
     * @return void
     *
     * @since  1.0.0
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
     * Removing a shipment edits its order, so it asks orders edit like every
     * other sub-row removal, not the delete right that removes whole orders.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function deleteShipment()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $this->checkToken();
        $this->assertCanEdit();

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
        } catch (Throwable $e) {
            // Escaped, as every sibling task escapes.
            $app->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    /**
     * A payment or shipment modal's post, as the ledger may write it.
     *
     * The calendar fields post what the calendar shows: the operator's local
     * time in the operator's language format. Only the form's filter reads that
     * back into the UTC the columns hold, so it runs here, the way the core
     * FormController applies it (applyFilterForCalendarFieldsToRequestData()).
     * Unfiltered, every Save moved each date by the operator's offset, and a
     * Greek operator's d-m-Y date was refused by the database (F-04).
     *
     * A read-only calendar (a shipment's Shipped and Delivered) still posts,
     * but is display only: it is dropped, so the modal never writes it. Its
     * XML keeps filter="user_utc", which is also what shows it in local time.
     * Every other field is handed over as posted, as before; the whole post is
     * then validated by the form.
     *
     * @param \Joomla\CMS\Form\Form|false $form The modal's form, as its model builds it.
     * @param array $data The posted jform.
     *
     * @return array|null The data to save; null when the form refused it (its messages are queued).
     *
     * @throws Exception When a date cannot be read in the operator's format.
     *
     * @since   1.14.0
     */
    private function modalWriteData(\Joomla\CMS\Form\Form|false $form, array $data): ?array
    {
        if ($form === false) {
            throw new RuntimeException(Text::_('JERROR_AN_ERROR_HAS_OCCURRED'));
        }

        $data = $this->applyFilterForCalendarFieldsToRequestData($form, $data);

        foreach ($form->getFieldset() as $field) {
            if ($field->type === 'Calendar' && $field->readonly) {
                unset($data[$field->fieldname]);
            }
        }

        if ($form->validate($data)) {
            return $data;
        }

        foreach ($form->getErrors() as $error) {
            $this->app->enqueueMessage(
                htmlspecialchars($error instanceof Throwable ? $error->getMessage() : (string) $error, ENT_QUOTES, 'UTF-8'),
                'error',
            );
        }

        return null;
    }

    /**
     * What a refused payment or shipment save keeps for the modal's redisplay;
     * the order-line popup keeps its line fields the same way ({@see self::keptOrderItemDraft()}).
     *
     * The posted fields of the modal's own form, never its record keys (id,
     * id_order): the redisplay is of the record its URL names. A calendar is
     * kept in UTC, the value the field displays from, so it comes back as
     * typed; one that cannot be read in the operator's format is not kept and
     * shows its stored value. A read-only calendar is not kept. The fields
     * escape what they render.
     *
     * @param \Joomla\CMS\Form\Form|false|null $form The modal's form; nothing is kept without it.
     * @param array $posted The posted jform.
     *
     *
     * @since   1.14.0
     */
    private function keptModalDraft(\Joomla\CMS\Form\Form|false|null $form, array $posted): array
    {
        if (!$form) {
            return [];
        }

        $draft = [];

        foreach ($form->getFieldset() as $field) {
            $name = $field->fieldname;

            if (\in_array($name, ['id', 'id_order'], true) || !\array_key_exists($name, $posted)) {
                continue;
            }

            if ($field->type !== 'Calendar') {
                $draft[$name] = $posted[$name];

                continue;
            }

            if ($field->readonly || !\is_string($posted[$name])) {
                continue;
            }

            try {
                $draft[$name] = $field->filter($posted[$name]);
            } catch (Throwable) {
                // Not kept: the field shows its stored value.
            }
        }

        return $draft;
    }

    //  Order items

    /**
     * Save an order line from the popup.
     *
     * The popup's post is decoded ONCE, by {@see self::postedOrderItemWrite()},
     * and handed to the model's one writer, which prices, freezes and
     * reconciles in a single transaction. The same decoded post feeds the
     * popup's preview ({@see self::previewOrderItem()}), so what the operator
     * saw is what is saved.
     *
     * A refusal keeps what was typed for this line ({@see self::keptOrderItemDraft()})
     * and redisplays the popup with `kept=1`, as the payment and shipment
     * modals do; nothing is written. A success forgets it.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function saveOrderItem()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $this->checkToken();
        $this->assertCanEdit();

        $itemID = $input->getInt('id');
        $orderID = $input->getInt('id_order');
        $isNew = $itemID === 0;
        $stateKey = 'com_alfa.edit.order.item.' . $orderID . '.' . $itemID;

        $success = false;

        try {
            $write = $this->postedOrderItemWrite(orderId: $orderID);
            $data = $write['data'];

            if ($this->getOrderModel()->saveOrderItem(
                $data,
                price: $write['price'],
                manualDiscountPercent: $write['manualDiscountPercent'],
                configuration: $write['configuration'],
                manualDiscountScope: $write['manualDiscountScope'],
            )) {
                $success = true;
                $itemID = (int) ($data['id'] ?? $itemID);
                $app->enqueueMessage(Text::_('COM_ALFA_ORDER_ITEM_SAVED'), 'success');
            }
        } catch (Throwable $e) {
            $app->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $app->setUserState($stateKey, $success ? null : $this->keptOrderItemDraft(isNew: $isNew));

        $redirectURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_order_item&id={$itemID}&id_order={$orderID}&kept=1";
        $redirectSuccessURL = "index.php?option={$this->option}&view=order&tmpl=component&layout=edit_order_item_return&id={$itemID}&id_order={$orderID}";

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    /**
     * The popup's preview: a DRY RUN of the save (AJAX, POST).
     *
     * It decodes the same post the save decodes and asks the model the same
     * question the save asks ({@see OrderModel::planOrderItemLine()}), then
     * answers with exactly the columns the save would write, and the figures
     * the line would show, formatted server-side. The popup does no money
     * arithmetic of its own. Nothing is written.
     *
     *
     * @since  1.14.0
     */
    public function previewOrderItem(): void
    {
        if (!$this->checkToken('post', false)) {
            $this->emitOrderItemPreview(['success' => false, 'message' => Text::_('JINVALID_TOKEN_NOTICE')]);

            return;
        }

        try {
            $this->assertCanEdit();

            $write = $this->postedOrderItemWrite(orderId: $this->input->getInt('id_order', 0));
            $model = $this->getOrderModel();
            $plan = $model->planOrderItemLine(
                data: $write['data'],
                price: $write['price'],
                manualDiscountPercent: $write['manualDiscountPercent'],
                configuration: $write['configuration'],
                manualDiscountScope: $write['manualDiscountScope'],
            );

            $response = [
                'success' => true,
                // The columns the save would write: money and units as their exact
                // texts already; a rate or a percentage (a float here) as its decimal text.
                'columns' => array_map(
                    static fn ($value) => \is_float($value)
                        ? \Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal::decimal(value: \Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::ofNumber($value))
                        : $value,
                    $plan['columns'],
                ),
                'display' => $model->describeOrderItemPlan($plan),
            ];
        } catch (Throwable $e) {
            $response = ['success' => false, 'message' => $e->getMessage()];
        }

        $this->emitOrderItemPreview($response);
    }

    /**
     * Answer the preview as JSON and stop.
     *
     * Protected so a test can observe what the dry-run decided without the
     * process closing under it; production echoes and closes.
     *
     * @param array $response The preview answer.
     *
     *
     * @since  1.14.0
     */
    protected function emitOrderItemPreview(array $response): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response);
        $this->app->close();
    }

    /**
     * What the order-line popup posted, decoded once for the save and its preview.
     *
     * THE PRICE COMMAND is the three-way control, decoded by the one
     * decoder the cart builder uses ({@see CustomPriceCommand::fromAdminPost()})
     * over the RAW post: Leave (the default, and the amount is not even read),
     * Set (the amount, 0 included; negative or malformed refused) or Clear.
     * Nothing else the form posts is an instruction: the displayed unit price,
     * total and discount columns never reach the model.
     *
     * THE MANUAL DISCOUNT is the line's setting. The popup
     * opens on the stored percentage and posts what the box holds, beside the
     * value it opened on (manual_discount_percent_shown): posted equal to that
     * it is not stated, so the stored setting stands even when another save
     * changed it after the popup opened; a new value replaces it, 0 clears it.
     * An emptied box is not stated at all and keeps the stored one, as an
     * omitted REST field does; 0 is how it is cleared. The percentage is read
     * by the writer's own rule ({@see OrderModel::manualDiscountPercent()}).
     *
     * THE TICK, "Apply this discount to the custom price", posts
     * manual_discount_scope: a hidden default before the checkbox, so an
     * unticked box still says "does not apply". The popup posts it only while
     * a custom price is set; not posted, or posted as it opened
     * (manual_discount_scope_shown), the stored choice stands.
     *
     * THE CONFIGURATION is the posted sel/opt/conf selection plus the payload
     * widgets' compositions and uploads, or null when nothing was posted. The
     * model decides whether it differs from the line's frozen one. Beside it
     * ride the compositions each widget displayed (itemfields_payload_baseline)
     * and the preview's chosen-file markers (conf_file_chosen).
     *
     * THE QUANTITY is handed over raw; the writer refuses what is not a whole
     * number of 1 or more.
     *
     * @param int $orderId The order the popup was opened on.
     *
     * @return array{data: array, price: CustomPriceCommand, manualDiscountPercent: ?float, configuration: ?array,
     *               manualDiscountScope: ?ManualDiscountScope}
     *
     * @throws RuntimeException When the price command or the discount is malformed.
     *
     * @since  1.14.0
     */
    private function postedOrderItemWrite(int $orderId): array
    {
        // The configuration's own refusals (a required field, an invalid
        // selection) are worded in the storefront catalogue, where the checkout
        // that raises them lives; the admin wording is re-applied over it, so
        // a key both catalogues carry still reads as the admin says it.
        $language = $this->app->getLanguage();
        $language->load('com_alfa', JPATH_SITE);
        $language->load('com_alfa', JPATH_ADMINISTRATOR, null, true);

        // RAW, NEVER FILTERED BY ITS OWN VALUE. Input::getArray() with no
        // argument uses each posted value as the NAME of its filter, so a
        // posted "int" was cleaned by the INT filter into a price of 0.
        // The instruction keys are read raw and judged by the strict decoders
        // below; a key that was not posted reads as null.
        $post = $this->input->post->getArray([
            CustomPriceCommand::ACTION_FIELD => 'raw',
            CustomPriceCommand::FIELD => 'raw',
            'manual_discount_percent' => 'raw',
            'manual_discount_scope' => 'raw',
            'manual_discount_percent_shown' => 'raw',
            'manual_discount_scope_shown' => 'raw',
        ]);
        $jform = (array) $this->input->post->get('jform', [], 'array');

        $data = [
            'id' => (int) ($jform['id'] ?? 0),
            'id_order' => (int) ($jform['id_order'] ?? 0) ?: $orderId,
            'id_item' => (int) ($jform['id_item'] ?? 0),
            // Raw: the writer refuses anything that is not a whole number of
            // 1 or more, rather than this door coercing it.
            'quantity' => $jform['quantity'] ?? null,
        ];

        try {
            $price = CustomPriceCommand::fromAdminPost($post);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException(
                $e->getCode() === ExplicitPriceInstruction::REFUSED_PRECISION
                    ? Text::sprintf('COM_ALFA_ORDER_ITEM_ERROR_CUSTOM_PRICE_PRECISION', Currency::SCALE_MAX, ExplicitPriceInstruction::MAX_SIGNIFICANT_DIGITS)
                    : Text::_('COM_ALFA_ORDER_ITEM_ERROR_CUSTOM_PRICE_INVALID'),
                400,
                $e,
            );
        }

        $discount = $post['manual_discount_percent'] ?? null;
        $manual = null;

        if (!($discount === null || (\is_string($discount) && trim($discount) === ''))) {
            $manual = OrderModel::manualDiscountPercent($discount);

            if ($manual === null) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_MANUAL_DISCOUNT_FORMAT'), 400);
            }
        }

        $scopePosted = $post['manual_discount_scope'] ?? null;
        $scope = null;

        if ($scopePosted !== null) {
            $scope = \is_string($scopePosted) ? ManualDiscountScope::tryFrom($scopePosted) : null;

            if ($scope === null) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_MANUAL_SCOPE_INVALID'), 400);
            }
        }

        // WHAT THE POPUP SHOWED IS NOT AN INSTRUCTION (A2R-4). Beside each
        // value it holds, the popup posts the value it opened on; one posted
        // equal to it was not touched and is not stated, so a change another
        // save made after the popup opened stands. A shown value that is not
        // a percentage or a scope is refused.
        $shownPercent = $post['manual_discount_percent_shown'] ?? null;

        if ($shownPercent !== null) {
            $shown = OrderModel::manualDiscountPercent($shownPercent);

            if ($shown === null) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_MANUAL_DISCOUNT_FORMAT'), 400);
            }

            if ($manual === $shown) {
                $manual = null;
            }
        }

        $shownScope = $post['manual_discount_scope_shown'] ?? null;

        if ($shownScope !== null) {
            $shown = \is_string($shownScope) ? ManualDiscountScope::tryFrom($shownScope) : null;

            if ($shown === null) {
                throw new RuntimeException(Text::_('COM_ALFA_ORDER_ITEM_ERROR_MANUAL_SCOPE_INVALID'), 400);
            }

            if ($scope === $shown) {
                $scope = null;
            }
        }

        return [
            'data' => $data,
            'price' => $price,
            'manualDiscountPercent' => $manual,
            'configuration' => $this->postedConfiguration(),
            'manualDiscountScope' => $scope,
        ];
    }

    /**
     * The configuration the order-line popup posted, or null when it posted none.
     *
     * The sel/opt/conf selection, read the storefront's own way, plus the
     * payload widgets' compositions and uploads; the composition each widget
     * showed before the operator first touched it, so a composition posted
     * equal to it reads as untouched; and the preview's per-slot "a file is
     * chosen" markers: the preview sends no bytes, but must reach the verdict
     * the save's upload reaches.
     *
     * @return array|null requestSelection() shape plus fullPayloads, files, payloadBaselines and filesChosen.
     *
     * @since  1.14.0
     */
    private function postedConfiguration(): ?array
    {
        $jform = (array) $this->input->post->get('jform', [], 'array');
        $request = VariantHelper::requestSelection();
        $full = (array) ($jform['itemfields_payload'] ?? []);
        $files = $this->input->files->get('conf_file', [], 'raw');
        $files = \is_array($files) ? $files : [];
        $baselines = (array) $this->input->post->get('itemfields_payload_baseline', [], 'array');
        $chosen = (array) $this->input->post->get('conf_file_chosen', [], 'array');

        return ($request['selection'] === [] && $request['optionValueIds'] === []
            && $request['payloads'] === [] && $full === [] && $files === [] && $chosen === [])
            ? null
            : $request + ['fullPayloads' => $full, 'files' => $files, 'payloadBaselines' => $baselines, 'filesChosen' => $chosen];
    }

    /**
     * What a refused order-line save keeps for the popup's redisplay.
     *
     * The popup's controls as posted: the quantity and a new line's product
     * (the line form's own fields, kept as a modal's are); the price command,
     * its amount, the manual discount, its tick and the values the popup
     * opened on; and the configuration in the shape the save reads. An
     * existing line's product is its own and is not kept. Only text is kept,
     * and the popup escapes what it renders.
     *
     * A chosen file is not kept: a browser cannot post it again. A payload
     * composition that carried one would still claim it, so that field
     * reopens on the composition the popup first showed. A composition's
     * baseline is kept only when the operator changed it; an unchanged one
     * reopens as it was and takes its baseline afresh, as on a first open.
     *
     * @param bool $isNew Whether the post was for a line that does not exist yet.
     *
     *
     * @since  1.14.0
     */
    private function keptOrderItemDraft(bool $isNew): array
    {
        // The line form is loaded here, after the refusal, only to keep its
        // fields; one that cannot be loaded keeps none, as a modal keeps
        // nothing without its form, and the refusal still comes back.
        try {
            $form = $this->getOrderModel()->getOrderItemForm();
        } catch (Throwable) {
            $form = null;
        }

        $draft = [
            'jform' => $this->keptModalDraft(
                form: $form,
                posted: (array) $this->input->post->get('jform', [], 'array'),
            ),
        ];

        if (!$isNew) {
            unset($draft['jform']['id_item']);
        }

        $typed = $this->input->post->getArray([
            CustomPriceCommand::ACTION_FIELD => 'raw',
            CustomPriceCommand::FIELD => 'raw',
            'manual_discount_percent' => 'raw',
            'manual_discount_scope' => 'raw',
            'manual_discount_percent_shown' => 'raw',
            'manual_discount_scope_shown' => 'raw',
        ]);

        foreach ($typed as $name => $value) {
            if (\is_string($value)) {
                $draft[$name] = $value;
            }
        }

        // The command is kept as the popup names its choices, and only when it names one.
        $commands = array_map(
            static fn (CustomPriceAction $action): string => strtolower($action->name),
            CustomPriceAction::cases(),
        );
        $command = strtolower(trim($draft[CustomPriceCommand::ACTION_FIELD] ?? ''));
        unset($draft[CustomPriceCommand::ACTION_FIELD]);

        if (\in_array($command, $commands, true)) {
            $draft[CustomPriceCommand::ACTION_FIELD] = $command;
        }

        $configuration = $this->postedConfiguration();

        if ($configuration !== null) {
            $withFile = [];

            foreach ($configuration['files'] as $fieldId => $slots) {
                foreach ((array) $slots as $file) {
                    if (\is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $withFile[(int) $fieldId] = true;
                    }
                }
            }

            unset($configuration['files'], $configuration['filesChosen']);

            foreach (['payloads', 'fullPayloads', 'payloadBaselines'] as $part) {
                $configuration[$part] = array_filter((array) $configuration[$part], 'is_string');
            }

            foreach (array_keys($withFile) as $fieldId) {
                unset($configuration['payloads'][$fieldId], $configuration['fullPayloads'][$fieldId]);

                if (isset($configuration['payloadBaselines'][$fieldId])) {
                    $configuration['fullPayloads'][$fieldId] = $configuration['payloadBaselines'][$fieldId];
                }
            }

            foreach ($configuration['payloadBaselines'] as $fieldId => $baseline) {
                $composition = $configuration['fullPayloads'][$fieldId] ?? null;

                if ($composition === null
                    || OrderModel::canonicalPayload($composition) === OrderModel::canonicalPayload($baseline)) {
                    unset($configuration['payloadBaselines'][$fieldId]);
                }
            }

            $draft['configuration'] = $configuration;
        }

        return $draft;
    }

    /**
     * Cancel order item editing.
     *
     * @return void
     *
     * @since  1.0.0
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
     * Removing a line edits its order, so it asks orders edit (the same right
     * the API's orderops removeLine asks), not the delete right for whole orders.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function deleteOrderItem()
    {
        $app = Factory::getApplication();
        $input = $app->input;

        $this->checkToken();
        $this->assertCanEdit();

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
        } catch (Throwable $e) {
            // Escaped, as every sibling task escapes.
            $app->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($success ? $redirectSuccessURL : $redirectURL, false));
    }

    //  AJAX product search

    /**
     * AJAX endpoint for product search (used by order item popup).
     *
     * Builds PriceContext from the ORDER'S CUSTOMER (not admin session)
     * so prices/discounts/taxes reflect what the customer would see.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function searchProducts()
    {
        $this->checkToken('get') || $this->checkToken('post') || die(Text::_('JINVALID_TOKEN_NOTICE'));
        $this->assertCanEdit();

        $app = Factory::getApplication();
        $input = $app->input;

        $query = $input->getString('q', '');
        $orderId = $input->getInt('id_order', 0);
        $limit = $input->getInt('limit', 20);

        $context = ($orderId > 0)
            ? \Alfa\Component\Alfa\Administrator\Helper\OrderHelper::buildOrderPriceContext($orderId)
            : \Alfa\Component\Alfa\Site\Service\Pricing\PriceContext::fromSession();

        $results = \Alfa\Component\Alfa\Administrator\Helper\OrderHelper::searchProducts($query, $context, $limit);

        // A product with combinations shows the sum of its current combinations'
        // own stock: every combination owns its stock. An archived combination is
        // kept only for order history, so it adds nothing, and a product whose
        // combinations are all archived shows its own item-level stock.
        $variantStock = [];
        $ids = array_map(static fn ($r) => (int) $r->id, $results);

        if ($ids) {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                $db->getQuery(true)
                    ->select([$db->quoteName('item_id'), 'SUM(' . $db->quoteName('stock') . ') AS total'])
                    ->from($db->quoteName('#__alfa_item_combinations'))
                    ->whereIn($db->quoteName('item_id'), $ids)
                    ->where(CombinationState::currentSql(column: $db->quoteName('state')))
                    ->group($db->quoteName('item_id')),
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                $variantStock[(int) $row->item_id] = (float) $row->total;
            }
        }

        $output = [];
        foreach ($results as $item) {
            // Every figure is published as an exact decimal string: the units are the
            // exact units of the item's priced result (none without one), the
            // percentage and the rate their decimal text.
            $priced = ($item->price_result ?? null) instanceof \Alfa\Component\Alfa\Site\Service\Pricing\PriceResult ? $item->price_result : null;
            $unit = static fn (string $figure): ?string => $priced === null
                ? null
                : \Alfa\Component\Alfa\Site\Helper\PriceUnits::published(price: $priced, figure: $figure, text: '')['amount'];
            $final = $unit('final');

            $output[] = [
                'id' => (int) $item->id,
                'name' => $item->name,
                'sku' => $item->sku ?? '',
                'gtin' => $item->gtin ?? '',
                'mpn' => $item->mpn ?? '',
                'stock' => $variantStock[(int) $item->id] ?? (float) ($item->stock ?? 0),
                'weight' => (float) ($item->weight ?? 0),
                'manage_stock' => (int) ($item->manage_stock ?? 1),
                'price_tax_incl' => $final,
                'price_tax_excl' => $unit('subtotal'),
                'base_price' => $unit('base'),
                'customer_price' => $final,
                // The printed units, server texts: the browser formats no money.
                'price_text' => (string) ($item->price_text ?? ''),
                'price_tax_excl_text' => (string) ($item->price_tax_excl_text ?? ''),
                'has_discount' => (bool) ($item->has_discount ?? false),
                'discount_percent' => $priced === null ? null : \Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal::percent((float) ($item->discount_percent ?? 0)),
                'tax_rate' => $priced === null ? null : \Alfa\Component\Alfa\Site\Service\Pricing\PublishedDecimal::decimal(value: \Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::ofNumber((float) ($item->tax_rate ?? 0))),
                'tax_name' => $item->tax_name ?? '',
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['results' => $output]);
        $app->close();
    }

    /**
     * Pay (part of) the order from the customer's credit balance.
     *
     * The payments panel's "Pay from credit" button repoints the order
     * adminForm here via formaction; token rides along. The amount is read
     * as typed, in the credit account's money ({@see ExactMoney}): one finer
     * than that money holds is refused by name, never rounded.
     *
     *
     * @since  1.9.0
     */
    public function payFromCredit(): void
    {
        $this->checkToken();
        $this->assertCanEdit();

        $app = Factory::getApplication();
        $orderId = $this->input->getInt('id_order', 0);
        $redirect = 'index.php?option=com_alfa&view=order&layout=edit&id=' . $orderId;

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery('SELECT * FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId);
            $order = $db->loadObject();

            if ($order === null) {
                throw new RuntimeException('Unknown order.');
            }

            // An empty field is no amount, which the ledger refuses in its words.
            $typed = $this->input->getString('credit_spend_amount', '');

            try {
                $amount = ExactMoney::of(value: $typed === '' ? '0' : $typed, currency: Currency::getDefault(), commaDecimal: true);
            } catch (ExactMoneyRefusal $e) {
                throw new RuntimeException($e->words(field: Text::_('COM_ALFA_ORDER_CREDIT_PAY_TITLE')), 0, $e);
            }

            $result = CustomerCreditLedger::spendOnOrder(
                order:  $order,
                amount: $amount,
            );

            $app->enqueueMessage(
                Text::sprintf(
                    'COM_ALFA_ORDER_CREDIT_SPENT',
                    \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault()->format(abs((float) $result->entry->amount), false),
                    \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault()->format((float) $result->entry->balance_after, false),
                ),
                'success',
            );
        } catch (Throwable $e) {
            // Escaped, as every sibling task escapes. The ledger's own refusal
            // is shown in its plain reason; its message, which carries the
            // ledger's name, is the log's.
            $app->enqueueMessage(htmlspecialchars($e instanceof CreditNoteRefusedException ? $e->reason() : $e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($redirect, false));
    }

    /**
     * Issue a document for this order from the Documents panel modal.
     *
     * POST, token-checked, gated on Orders edit AND Sales Documents create
     * (assertCanIssueDocument(), before any work and outside the try below, so
     * a refusal is a 403 and never a flash message), inert when
     * documents_issuer = external.
     *
     * A SALES document is built from the picked lines and quantities, the
     * order's remaining charges and the buyer and movement blocks as posted
     * (the order line's frozen total; documents never re-price), and every
     * guard is DocumentIssuer::issue()'s.
     *
     * A CREDIT is sized, judged and numbered under ONE order lock. Its draft
     * is first calculated and answered by onDocumentBeforeIssue with no
     * transaction open (DocumentIssuer::beforeIssue()). Inside
     * DocumentIssuer::envelope() the order row is locked first; then
     * CreditCalculator::calculate() sizes the credit from the corrected
     * document as it stands; an outcome that cannot be issued is refused in
     * the calculator's words; a credit to the customer's wallet is judged by
     * the ledger's one wallet contract, and refused when that check cannot be
     * made (assertAccountTakesCredit()); and the answered draft is issued
     * when it is the credit sized under the lock. Of two credits drafted from
     * one state, the second is therefore sized after the first has committed:
     * its credit, still issuable but no longer the one answered, is answered
     * again with no transaction open and the envelope runs again on it, up to
     * CREDIT_ROUNDS rounds; a credit that keeps changing is refused. The buyer
     * and the language are the corrected document's, so the posted buyer
     * fields are ignored.
     *
     * A credit for the customer's credit account is numbered with that
     * destination frozen on it, and is paid in once it is committed
     * (creditToAccount()). Until it is paid in it holds its amount against
     * what the order can still give back, so a later credit is not numbered
     * on the same money. What landed is verified before it is allowed to
     * stay: a draft can be amended while it is issued. A note that landed
     * without the destination it was issued with would hold nothing while its
     * credit waits, and a note sent elsewhere that landed with it would hold
     * its amount and offer a pay-in nobody chose, so either Issue is refused
     * and nothing is numbered.
     *
     * A REFUSAL KEEPS THE DRAFT: the modal is redisplayed with `kept=1` and the
     * operator's validated input (postedIssueDraft()) kept in the user state,
     * read back only by that redisplay. Nothing is kept when a document did
     * land before the throw: that is sent where a success goes, so it cannot
     * invite a second issue. The refusal is escaped: it can carry a charge's
     * name or a typed amount.
     *
     *
     * @since  1.3.0
     */
    public function issueDocument(): void
    {
        $app = Factory::getApplication();
        $input = $this->input;

        $this->checkToken();
        $this->assertCanIssueDocument();

        $orderId = $input->getInt('id_order', 0);

        // The form is an iframe modal, so both ends of this task are modal
        // layouts: back to the form when it refuses, and to the return page
        // (which closes the modal and reloads the order behind it) when a
        // document was actually issued. Same two-URL shape saveShipment uses.
        $formUrl = "index.php?option={$this->option}&view=order&tmpl=component"
            . "&layout=edit_document&id_order={$orderId}";
        $returnUrl = "index.php?option={$this->option}&view=order&tmpl=component"
            . "&layout=edit_document_return&id_order={$orderId}";

        $success = false;

        if (!DocumentHelper::isIssuerEnabled()) {
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_ISSUER_EXTERNAL_MSG'), 'warning');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        $docType = $input->getString('doc_type', '');
        $isCredit = \in_array($docType, DocumentHelper::creditTypes(), true);
        $stateKey = 'com_alfa.edit.order.document.' . $orderId;

        // The picked lines and quantities: the order's own lines on a sales
        // document, the corrected document's on a credit.
        $picks = $input->post->get('lines', [], 'array');
        // Only a credit corrects a document: the Corrects row is hidden, not
        // disabled, on every other type, so a pick left behind still posts.
        $correctsId = $isCredit ? $input->getInt('id_corrects', 0) : 0;

        // What the order holds before this attempt: a throw after a commit is
        // told from a refusal by the document that landed.
        $documentsBefore = $this->orderDocumentIds($orderId);
        $landed = false;

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');

            // The movement block is read only where the form shows it (F-09):
            // a hidden control still posts, and the hidden purpose list's
            // default (Sale) printed a Movement block on invoices and credits.
            $isCombined = $input->getInt('is_combined', 0) === 1;
            $movement = OrderModel::issueFormShowsMovement(docType: $docType, isCombined: $isCombined);

            $header = [
                'is_combined' => $isCombined,
                'movement_purpose' => $movement ? ($input->getString('movement_purpose', '') ?: null) : null,
                'carrier_name' => $movement ? ($input->getString('carrier_name', '') ?: null) : null,
                'vehicle_number' => $movement ? ($input->getString('vehicle_number', '') ?: null) : null,
            ];

            if ($isCredit) {
                // Only what was posted: everything that reads the order's
                // state runs under the lock below.
                $creditInput = new CreditInput(
                    orderId:       $orderId,
                    docType:       $docType,
                    seriesId:      $input->getInt('id_series', 0),
                    correctsId:    $correctsId,
                    itemPicks:     $picks,
                    postedCharges: $input->post->get('charges', [], 'array'),
                    actorId:       (int) $app->getIdentity()->id,
                    printNote:     $input->getString('print_note', '') ?: null,
                );
                $toWallet = $input->getString('credit_destination', '') === 'credit_account';

                // The draft is answered by onDocumentBeforeIssue before any
                // transaction opens, so no subscriber acts inside the envelope.
                // The destination is frozen with the number (see below).
                $answeredHeader = $header;

                if ($toWallet) {
                    $answeredHeader['credit_destination'] = CustomerCreditLedger::CREDIT_NOTE_DESTINATION;
                }

                $unlocked = CreditCalculator::calculate(input: $creditInput);

                if (!$unlocked->outcome->issuable()) {
                    throw $unlocked->reasonKey !== ''
                        ? new DocumentRefusedException(message: $unlocked->detail !== '' ? $unlocked->detail : $unlocked->operatorText(), key: $unlocked->reasonKey, values: $unlocked->reasonValues)
                        : new RuntimeException($unlocked->detail);
                }

                $base = $unlocked->draft + $answeredHeader;
                $answered = [];
                $resized = null;

                $work = static function () use ($db, $orderId, $creditInput, $toWallet, $header, &$base, &$answered, &$resized): array {
                    // The note is judged and numbered under one order lock.
                    $db->setQuery(
                        'SELECT ' . $db->quoteName('id_user') . ', ' . $db->quoteName('id_currency')
                        . ' FROM ' . $db->quoteName('#__alfa_orders')
                        . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId . ' FOR UPDATE',
                    );
                    $order = $db->loadObject() ?? throw new RuntimeException("order #{$orderId} not found.");

                    $result = CreditCalculator::calculate(input: $creditInput);

                    if (!$result->outcome->issuable()) {
                        throw $result->reasonKey !== ''
                            ? new DocumentRefusedException(message: $result->detail !== '' ? $result->detail : $result->operatorText(), key: $result->reasonKey, values: $result->reasonValues)
                            : new RuntimeException($result->detail);
                    }

                    if ($toWallet) {
                        // The wallet is judged under the same lock, before a number is drawn.
                        self::assertAccountTakesCredit(
                            orderId:      $orderId,
                            userId:       (int) $order->id_user,
                            currencyId:   (int) $order->id_currency,
                            currencyCode: (string) $result->preview->currency_code,
                            amount:       (float) $result->preview->totals->incl,
                        );

                        // The destination is frozen on the note with its number: until
                        // its credit is paid in, the note holds its amount against what
                        // the order can still give back.
                        $header['credit_destination'] = CustomerCreditLedger::CREDIT_NOTE_DESTINATION;
                    }

                    // The credit as it stands under the lock must be the one
                    // that was answered: the order may have changed since.
                    if ($result->draft + $header !== $base) {
                        $resized = $result->draft + $header;

                        throw new DocumentRefusedException(
                            message: "the credit for order #{$orderId} changed after it was asked about, because the order changed in the meantime; nothing was issued.",
                            key:     'COM_ALFA_CORRECTION_REASON_PREVIEW_CHANGED',
                        );
                    }

                    $document = DocumentIssuer::issue(draft: $answered, manageTransaction: false);

                    // What landed, before it is allowed to stay. A draft can be amended
                    // while it is issued. A note for the customer's account that landed
                    // without that destination would hold nothing while its credit
                    // waits; a note sent elsewhere that landed with it would hold its
                    // amount and offer a pay-in nobody chose. So the landed value is the
                    // one the operator chose, none included, whatever the draft carried
                    // on its way. Refusing here still undoes the number.
                    if (($document->credit_destination ?? null) !== ($toWallet ? CustomerCreditLedger::CREDIT_NOTE_DESTINATION : null)) {
                        throw new DocumentRefusedException(
                            message: "credit note {$document->number_formatted} did not land with the customer credit destination it was issued with.",
                            key:     'COM_ALFA_CREDIT_DESTINATION_NOT_KEPT',
                        );
                    }

                    return [$document, $result];
                };

                // A credit sized under the lock that is no longer the one
                // answered is answered again, outside the transaction the
                // envelope rolled back, and sized again under the lock.
                for ($round = 1; ; $round++) {
                    $answered = DocumentIssuer::beforeIssue(draft: $base);
                    $resized = null;

                    try {
                        [$document, $result] = DocumentIssuer::envelope(work: $work);

                        break;
                    } catch (Throwable $e) {
                        if ($resized === null || $round >= self::CREDIT_ROUNDS) {
                            throw $e;
                        }

                        $base = $resized;
                    }
                }

                $success = true;
                $app->setUserState($stateKey, null);
                $this->creditIssued(document: $document, result: $result);

                // The note is committed: its credit is paid in as a step of its own.
                if ($toWallet) {
                    $this->creditToAccount(document: $document);
                }
            } else {
                // A sales document claims the order's remaining charges, and
                // takes no stated amount: that belongs to a credit alone. The
                // lines and charges are assembled by DocumentDraft, the same
                // assembler a proposed correction uses.
                $chargePicks = $input->post->get('charges', [], 'array');
                unset($chargePicks['amounts']);

                $language = DocumentHelper::defaultLanguageTag();

                $lines = DocumentDraft::lines(
                    orderId:     $orderId,
                    docType:     $docType,
                    picks:       $picks,
                    chargePicks: $chargePicks,
                    correctsId:  0,
                    language:    $language,
                );

                $document = DocumentIssuer::issue([
                    'doc_type' => $docType,
                    'id_order' => $orderId,
                    'id_series' => $input->getInt('id_series', 0),
                    'id_corrects' => 0,
                    'language' => $language,
                    'print_note' => $input->getString('print_note', '') ?: null,
                    'lines' => $lines,
                    'buyer_name' => $input->getString('buyer_name', ''),
                    'buyer_vat_number' => $input->getString('buyer_vat_number', ''),
                    'buyer_tax_office' => $input->getString('buyer_tax_office', ''),
                    'buyer_activity' => $input->getString('buyer_activity', ''),
                    'buyer_email' => $input->getString('buyer_email', ''),
                ] + $header);

                $success = true;
                $app->setUserState($stateKey, null);

                $app->enqueueMessage(
                    Text::sprintf('COM_ALFA_DOCUMENTS_ISSUED_OK', (string) $document->number_formatted),
                    'success',
                );
            }
        } catch (Throwable $e) {
            // Escaped, as every sibling task escapes: the issuer's words can
            // carry a charge's name, and a refused amount is quoted as typed.
            // A refusal the issuer can put in the operator's words is shown
            // in them; its technical message stays the exception's (C-4).
            $refusal = DocumentRefusedException::in($e);
            $app->enqueueMessage(htmlspecialchars($refusal?->operatorText() ?? $e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');

            // A refusal keeps the draft for the redisplay; a throw that came
            // after a document landed keeps nothing and goes where a success
            // goes, so the kept draft can never invite issuing it twice.
            $landed = $this->documentLanded($orderId, $documentsBefore);
            $app->setUserState($stateKey, $landed ? null : $this->postedIssueDraft($orderId));
        }

        $this->setRedirect(Route::_($success || $landed ? $returnUrl : $formUrl . '&kept=1', false));
    }

    /**
     * What a credit issue tells the operator: the success line and, when the
     * operator left eligible charges out, ONE notice listing them.
     *
     * The total is stated at the note's own scale. A quantity correction adds
     * its own sentence, escaped here because the message queue renders HTML.
     * The list of charges left out is HTML-safe as the describer returns it,
     * and is queued as given: a charge deliberately left out is information,
     * never a warning.
     *
     * @param object $document The issued credit note.
     * @param CreditResult $result The calculation the note was issued from.
     *
     *
     * @since   1.14.0
     */
    private function creditIssued(object $document, CreditResult $result): void
    {
        $app = Factory::getApplication();
        $issued = Text::sprintf(
            'COM_ALFA_DOCUMENTS_CREDIT_ISSUED_OK',
            (string) $document->number_formatted,
            DocumentMoney::formatter($document)((float) $document->total_tax_incl),
        );

        if ($result->outcome === CreditOutcome::QuantityCorrection) {
            $issued .= ' ' . htmlspecialchars($result->operatorText(), ENT_QUOTES, 'UTF-8');
        }

        $app->enqueueMessage($issued, 'success');

        $leftOut = CreditExclusionList::message(exclusions: $result->exclusions, corrected: $result->corrected);

        if ($leftOut !== null) {
            $app->enqueueMessage($leftOut, 'notice');
        }
    }

    /**
     * Pay a committed credit note into the customer's credit account, and tell the operator.
     *
     * A step of its own, taken once the note is numbered and committed. The
     * note is immutable, so a credit that is not paid in never unwinds it. The
     * ledger judged the account before the number was drawn, so what can still
     * refuse the credit happened afterwards (the note voided, the account
     * blocked or deleted, a refund recorded on the order's payments), or the
     * write itself failed.
     *
     * Staff are told what is true of the note once the pay-in did not land,
     * so its state is read afresh (creditNoteWaits()):
     *
     * - The note still waits. It keeps holding what it is owed against what
     *   the order can still give back until it is paid in, voided, or a
     *   refund is recorded against it. Staff are warned with the ledger's
     *   plain reason, or with this screen's own sentence when the write
     *   failed, and told that the note waits on its row, how it is settled
     *   there, and not to credit the amount by hand as well: the Documents
     *   panel offers this same pay-in again (payInCreditNote()), and credit
     *   added by hand is tied to no note, so a later pay-in would pay the
     *   customer twice. The technical message goes to the credit log, which
     *   outlives the one-shot message.
     * - The note is owed nothing: its credit is already in the account (a
     *   second click, another tab) or was refunded. Nothing failed, so staff
     *   are told so as information and no failure is logged.
     * - The note no longer stands (voided, or gone). It waits for nothing, so
     *   staff read the ledger's reason in the frame a refusal before
     *   numbering uses, and the failure is logged.
     *
     * @param object $document The committed credit note.
     *
     *
     * @since   1.14.0
     */
    private function creditToAccount(object $document): void
    {
        $app = Factory::getApplication();
        $orderId = (int) $document->id_order;

        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                'SELECT ' . $db->quoteName('id_user') . ' FROM ' . $db->quoteName('#__alfa_orders')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId,
            );
            $entry = CustomerCreditLedger::applyCreditNote(
                document: $document,
                userId:   (int) $db->loadResult(),
            );
            // Both figures at the note's scale, as the line that announced the
            // note states its total.
            $money = DocumentMoney::formatter($document);
            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_CREDIT_ACCOUNT_CREDITED', $money((float) $document->total_tax_incl), $money((float) $entry->balance_after)),
                'success',
            );
        } catch (Throwable $e) {
            $waits = self::creditNoteWaits(documentId: (int) $document->id);

            // Owed nothing: the credit is already in the account, or was
            // refunded. The ledger refused a second credit; nothing failed.
            if ($waits === false && $e instanceof CreditNoteRefusedException) {
                $app->enqueueMessage(
                    Text::sprintf('COM_ALFA_CREDIT_ACCOUNT_ALREADY_PAID_IN', htmlspecialchars((string) $document->number_formatted, ENT_QUOTES, 'UTF-8')),
                    'notice',
                );

                return;
            }

            // The document is committed; the missing wallet leg must
            // survive the one-shot flash message somewhere durable.
            CustomerCreditLedger::ensureLogger();
            Log::add(
                entry:    sprintf(
                    'Credit note %s (order %d): wallet credit of %s FAILED - %s',
                    (string) $document->number_formatted,
                    $orderId,
                    (string) $document->total_tax_incl,
                    $e->getMessage(),
                ),
                priority: Log::ERROR,
                category: 'com_alfa.credit',
            );
            // Staff read the ledger's plain reason, or this screen's
            // own sentence when the write itself failed; the log
            // line above keeps the technical message. Escaped once:
            // the message queue renders HTML. A note that still waits
            // is told so and how it is settled; that sentence goes on
            // after the reason, so it ends the reason itself. A note
            // that no longer stands waits for nothing: its reason is
            // framed as a refusal before numbering is.
            $reason = $e instanceof CreditNoteRefusedException ? $e->reason() : Text::_('COM_ALFA_CREDIT_ACCOUNT_WRITE_FAILED');
            $app->enqueueMessage(
                $waits === true
                    ? Text::sprintf('COM_ALFA_CREDIT_ACCOUNT_FAILED', htmlspecialchars(rtrim($reason, '.'), ENT_QUOTES, 'UTF-8'))
                    : Text::sprintf('COM_ALFA_CREDIT_WALLET_REFUSED', htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')),
                'warning',
            );
        }
    }

    /**
     * Whether a credit note still waits for its customer credit, asked once a pay-in did not land.
     *
     * Read afresh: the note may have been voided, paid in or refunded since
     * the caller read it. True while the note stands and is owed something;
     * false once it stands and is owed nothing; null when it no longer stands
     * (voided, or gone). A state that cannot be read is taken for a note that
     * waits, which is what it was when the pay-in began.
     *
     * @param int $documentId The credit note.
     *
     *
     * @since   1.14.0
     */
    private static function creditNoteWaits(int $documentId): ?bool
    {
        try {
            $note = DocumentHelper::getById($documentId);

            if ($note === null || (int) $note->status === DocumentHelper::STATUS_VOIDED) {
                return null;
            }

            return CustomerCreditLedger::creditNoteOwed(documentId: $documentId)->isPositive();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Pay a waiting credit note into the customer's credit account, asked by hand.
     *
     * The Documents panel offers it on the row of a note that was issued to
     * customer credit and is still owed that credit: the pay-in this screen
     * made when the note was issued, or replaced, did not land. It is that
     * same pay-in, asked again (creditToAccount()), so the ledger judges the
     * note, the account and the order's money again under its locks, and staff
     * read the same lines: the amount credited, or why it was not.
     *
     * POST, token-checked, and gated as issuing the note is
     * (assertCanIssueDocument()). Only a note of the posted order that was
     * issued to customer credit is paid in: the money of any other note went
     * another way, and paying it in would send it twice. Such a request is
     * refused before the ledger is asked. The request then spends a one-time
     * key, so a second submission of the same click runs nothing again; the
     * key is asked last, so a click refused for the note itself does not also
     * spend it.
     *
     *
     * @since   1.14.0
     */
    public function payInCreditNote(): void
    {
        $this->checkToken();
        $this->assertCanIssueDocument();

        $app = Factory::getApplication();
        $orderId = $this->input->getInt('id_order', 0);

        // Back to the order's own form, as a save goes back to it: its id is held.
        $this->setRedirect($this->getRedirectUrlToItem($orderId));

        $document = DocumentHelper::getById($this->input->getInt('id_document', 0));

        if ($document === null
            || (int) $document->id_order !== $orderId
            || ($document->credit_destination ?? null) !== CustomerCreditLedger::CREDIT_NOTE_DESTINATION) {
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_CREDIT_PAY_IN_NOT_WAITING'), 'warning');

            return;
        }

        if (ActionRegistry::refuseUnsafeRequest(mutating: true, input: $this->input) !== null) {
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_CREDIT_PAY_IN_REPEATED'), 'warning');

            return;
        }

        $this->creditToAccount(document: $document);
    }

    /**
     * Refuse a credit note that the customer's credit account cannot take.
     *
     * Asked under the order lock, before the note is numbered, by every act of
     * this screen that numbers a note for the account: Issue, and the
     * replacement of a note that was sent there. The ledger's one contract
     * answers. A check it could not make refuses the note as every other
     * answer of that check does, in the ledger's plain words, and every
     * refusal reaches staff inside the one sentence that frames them.
     *
     * @param int $orderId The order the note is issued on.
     * @param int $userId The order's customer.
     * @param int $currencyId The currency the order stores.
     * @param string $currencyCode The note's currency code, as a refusal names it.
     * @param float $amount The note's total, tax included.
     *
     *
     * @throws DocumentRefusedException When the account cannot take the note.
     *
     * @since   1.14.0
     */
    private static function assertAccountTakesCredit(int $orderId, int $userId, int $currencyId, string $currencyCode, float $amount): void
    {
        try {
            $refusal = CustomerCreditLedger::creditNoteRefusal(
                userId:       $userId,
                orderId:      $orderId,
                currencyId:   $currencyId,
                currencyCode: $currencyCode,
                amount:       $amount,
            );
        } catch (DocumentRefusedException $unchecked) {
            $refusal = $unchecked->operatorText();
        }

        if ($refusal !== null) {
            throw new DocumentRefusedException(message: $refusal, key: 'COM_ALFA_CREDIT_WALLET_REFUSED', values: [$refusal]);
        }
    }

    /**
     * The account's answer for a replacement, asked inside the Void & Replace act.
     *
     * Handed to the act, which asks it once the source is voided and before
     * the replacement is numbered, under the order lock: what the source held
     * on the order, and what the account gave back for it, no longer count
     * against the replacement that restates them. A source that was not sent
     * to the customer's credit account asks nothing.
     *
     * @param object $source The source document, as the act locked it.
     * @param object $preview The replacement's preview.
     *
     *
     * @throws DocumentRefusedException When the account cannot take the replacement.
     * @throws RuntimeException When the order is gone.
     *
     * @since   1.14.0
     */
    private static function assertAccountTakesReplacement(object $source, object $preview): void
    {
        if (($source->credit_destination ?? null) !== CustomerCreditLedger::CREDIT_NOTE_DESTINATION) {
            return;
        }

        $orderId = (int) $source->id_order;
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('id_user') . ', ' . $db->quoteName('id_currency')
            . ' FROM ' . $db->quoteName('#__alfa_orders')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId,
        );
        $order = $db->loadObject() ?? throw new RuntimeException("order #{$orderId} not found.");

        self::assertAccountTakesCredit(
            orderId:      $orderId,
            userId:       (int) $order->id_user,
            currencyId:   (int) $order->id_currency,
            currencyCode: (string) $preview->currency_code,
            amount:       (float) $preview->totals->incl,
        );
    }

    /**
     * The ids of an order's documents, or null when they cannot be read.
     *
     * @param int $orderId The order.
     *
     * @return int[]|null
     *
     * @since   1.14.0
     */
    private function orderDocumentIds(int $orderId): ?array
    {
        try {
            $db = Factory::getContainer()->get('DatabaseDriver');
            $db->setQuery(
                'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_documents')
                . ' WHERE ' . $db->quoteName('id_order') . ' = ' . $orderId,
            );

            return array_map('intval', $db->loadColumn() ?: []);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Did a document land on the order since the ids were read?
     *
     * Answered yes when either read failed: a document may have landed, and a
     * kept draft would then invite issuing it twice. A document another
     * operator issued at the same moment reads as landed too, which only
     * sends this operator to the reloaded order instead of the kept draft.
     *
     * @param int $orderId The order.
     * @param int[]|null $before Its document ids before the attempt.
     *
     *
     * @since   1.14.0
     */
    private function documentLanded(int $orderId, ?array $before): bool
    {
        $now = $this->orderDocumentIds($orderId);

        if ($before === null || $now === null) {
            return true;
        }

        return array_diff($now, $before) !== [];
    }

    /**
     * The issue modal's draft as posted, VALIDATED, for its redisplay after a refusal.
     *
     * An ordinary domain refusal must not destroy the
     * draft, while a security-invalid value may be rejected. So nothing kept is
     * the raw post. Every id is intersected with what this order offers NOW:
     * the registry's types, the picked type's series, the documents a credit
     * may correct, the lines the form offers (a credit's are its corrected
     * document's), the charges of the group the draft
     * claims; strings are read as the task reads them and DROPPED, never cut,
     * past a length no column takes (a cut value would be issued as something
     * the operator never typed); enumerations are whitelisted. A type the
     * registry does not know keeps nothing.
     *
     * By type: the corrected document, the credit destination and the stated
     * charge amounts belong to a credit; the discount rules to every other
     * type. The buyer fields are kept for every type: a credit's issue
     * ignores them, its buyer being its corrected document's, but they are
     * the operator's input. Quantities and amounts are kept AS TYPED, so a
     * malformed or excessive one comes back to be corrected; one that reading
     * it as text would change is dropped instead.
     *
     * @param int $orderId The order the modal issues against.
     *
     * @return array The draft in the issue form's field names; [] when nothing can be kept.
     *
     * @since   1.14.0
     */
    private function postedIssueDraft(int $orderId): array
    {
        try {
            $input = $this->input;
            $docType = $input->getString('doc_type', '');

            if (!\in_array($docType, DocumentHelper::types(), true)) {
                return [];
            }

            $isCredit = \in_array($docType, DocumentHelper::creditTypes(), true);
            $draft = ['doc_type' => $docType];

            $seriesId = $input->getInt('id_series', 0);

            if (\in_array($seriesId, array_map(static fn (object $series): int => (int) $series->id, DocumentHelper::seriesForType($docType)), true)) {
                $draft['id_series'] = $seriesId;
            }

            $correctsId = 0;

            if ($isCredit) {
                $posted = $input->getInt('id_corrects', 0);
                $correctable = array_map(
                    static fn (object $document): int => (int) $document->id,
                    DocumentHelper::correctableDocuments(DocumentHelper::getForOrder($orderId)),
                );

                if (\in_array($posted, $correctable, true)) {
                    $correctsId = $posted;
                    $draft['id_corrects'] = $correctsId;
                }

                // The wallet option exists only for an order with a customer.
                $destination = $input->getString('credit_destination', '');

                if ($destination === '' || ($destination === 'credit_account' && $this->orderHasCustomer($orderId))) {
                    $draft['credit_destination'] = $destination;
                }
            }

            $draft['is_combined'] = (int) ($input->getInt('is_combined', 0) === 1
                && DocumentHelper::familyOf($docType) === 'sales' && DocumentHelper::supportsMovement($docType));

            // The lines the form offers only: the corrected document's own on
            // a credit, the order's on every other type. The tick, and the
            // quantity as typed when a number field could hold it.
            $offeredLines = $isCredit
                ? ($correctsId > 0 ? array_map('intval', array_keys(OrderItemLines::remainingForCredit(correctsId: $correctsId))) : [])
                : $this->orderLineIds($orderId);
            $draft['lines'] = [];

            foreach ((array) $input->post->get('lines', [], 'array') as $lineId => $row) {
                if (!\is_int($lineId) || !\is_array($row) || !\in_array($lineId, $offeredLines, true)) {
                    continue;
                }

                $quantity = \is_scalar($row['quantity'] ?? null) ? trim((string) $row['quantity']) : '';

                $draft['lines'][$lineId] = [
                    'selected' => (int) !empty($row['selected']),
                    'quantity' => preg_match('/^\d{1,9}(?:\.\d{1,6})?$/', $quantity) === 1 ? $quantity : '',
                ];
            }

            $draft['charges'] = $this->postedIssueCharges(orderId: $orderId, isCredit: $isCredit, correctsId: $correctsId);

            // Kept for every type (design section 8). A credit's issue ignores them (
            // its buyer is its corrected document's), but they are what the
            // operator typed, and come back for a switch to a sales type.
            foreach (['buyer_name', 'buyer_vat_number', 'buyer_tax_office', 'buyer_activity', 'buyer_email'] as $field) {
                $this->keepPostedText($draft, $field, self::ISSUE_DRAFT_TEXT_MAX);
            }

            $purpose = $input->getString('movement_purpose', '');

            if ($input->exists('movement_purpose') && \in_array($purpose, self::ISSUE_MOVEMENT_PURPOSES, true)) {
                $draft['movement_purpose'] = $purpose;
            }

            $this->keepPostedText($draft, 'carrier_name', self::ISSUE_DRAFT_TEXT_MAX);
            $this->keepPostedText($draft, 'vehicle_number', self::ISSUE_DRAFT_TEXT_MAX);
            $this->keepPostedText($draft, 'print_note', self::ISSUE_DRAFT_NOTE_MAX_BYTES, inBytes: true);

            return $draft;
        } catch (Throwable $e) {
            // The refusal is already on screen; a draft that cannot be read
            // back is not kept rather than half kept.
            Log::add(
                entry:    'Issue modal draft for order ' . $orderId . ' was not kept: ' . $e->getMessage(),
                priority: Log::WARNING,
                category: 'com_alfa.documents',
            );

            return [];
        }
    }

    /**
     * The charge picks of a posted issue draft, kept for the group the draft claims.
     *
     * A sales draft claims the order's remaining charges, a credit the
     * corrected document's shipping and fees with the amounts the operator
     * stated (discounts follow the goods on a credit, so none are kept). A
     * credit that names no correctable document keeps no charge at all.
     *
     * @param int $orderId The order.
     * @param bool $isCredit Whether the draft is a credit.
     * @param int $correctsId The validated corrected document, or 0.
     *
     * @return array{shipping: int[], fees: int[], rules: int[], amounts: array{shipping: array<int, string>, fees: array<int, string>}}
     *
     * @since   1.14.0
     */
    private function postedIssueCharges(int $orderId, bool $isCredit, int $correctsId): array
    {
        $posted = (array) $this->input->post->get('charges', [], 'array');
        $kept = ['shipping' => [], 'fees' => [], 'rules' => [], 'amounts' => ['shipping' => [], 'fees' => []]];

        $offeredIds = static function ($ids, array $offered): array {
            $picked = [];

            foreach ((array) $ids as $id) {
                if (\is_scalar($id) && ctype_digit((string) $id) && \in_array((int) $id, $offered, true)) {
                    $picked[] = (int) $id;
                }
            }

            return array_values(array_unique($picked));
        };

        if (!$isCredit) {
            $open = OrderChargeLines::remaining(orderId: $orderId);

            foreach (['shipping' => $open->shipping, 'fees' => $open->fees, 'rules' => $open->rules] as $kind => $entries) {
                $kept[$kind] = $offeredIds($posted[$kind] ?? [], array_map(static fn (object $entry): int => (int) $entry->id, $entries));
            }

            return $kept;
        }

        if ($correctsId <= 0) {
            return $kept;
        }

        $group = OrderChargeLines::correctedCharges(correctsId: $correctsId);
        $filter = InputFilter::getInstance();

        foreach (['shipping' => $group->shipping, 'fees' => $group->fees] as $kind => $entries) {
            $offered = array_map(static fn (object $entry): int => (int) $entry->id, $entries);
            $kept[$kind] = $offeredIds($posted[$kind] ?? [], $offered);

            foreach ((array) ($posted['amounts'][$kind] ?? []) as $id => $amount) {
                if (!\is_int($id) || !\in_array($id, $offered, true) || !\is_scalar($amount)) {
                    continue;
                }

                // Read as getString() reads text, and kept only when that
                // leaves it whole: the task judged the RAW amount, and a
                // stripped one ('12<50' reads as '12') would come back as an
                // amount the operator never typed, which the next Issue would
                // credit. A string no amount is that long
                // is not an amount to correct, and goes too.
                $raw = (string) $amount;
                $text = (string) $filter->clean($raw, 'string');

                if ($text === $raw && mb_strlen($text) <= self::ISSUE_DRAFT_AMOUNT_MAX) {
                    $kept['amounts'][$kind][$id] = $text;
                }
            }
        }

        return $kept;
    }

    /**
     * Keep one posted text field on a draft, read as getString() reads it.
     *
     * Not posted, nothing kept; longer than $max, dropped whole.
     *
     * @param array $draft The draft being built.
     * @param string $field The field name.
     * @param int $max The most characters kept, or bytes with $inBytes.
     * @param bool $inBytes Measure in bytes, as a TEXT column holds them.
     *
     *
     * @since   1.14.0
     */
    private function keepPostedText(array &$draft, string $field, int $max, bool $inBytes = false): void
    {
        if (!$this->input->exists($field)) {
            return;
        }

        $value = $this->input->getString($field, '');

        if (($inBytes ? \strlen($value) : mb_strlen($value)) <= $max) {
            $draft[$field] = $value;
        }
    }

    /**
     * The ids of an order's lines.
     *
     * @param int $orderId The order.
     *
     * @return int[]
     *
     * @since   1.14.0
     */
    private function orderLineIds(int $orderId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_order_items')
            . ' WHERE ' . $db->quoteName('id_order') . ' = ' . $orderId,
        );

        return array_map('intval', $db->loadColumn() ?: []);
    }

    /**
     * Does the order belong to a customer account (and so may be credited to its wallet)?
     *
     * @param int $orderId The order.
     *
     *
     * @since   1.14.0
     */
    private function orderHasCustomer(int $orderId): bool
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'SELECT ' . $db->quoteName('id_user') . ' FROM ' . $db->quoteName('#__alfa_orders')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $orderId,
        );

        return (int) $db->loadResult() > 0;
    }

    /**
     * Issue the proposed incremental correction for an order.
     *
     * The document reconciliation contract, section 4A and section 6: the
     * operator chooses the business action, and nothing is committed until they
     * do. This task IS that choice: it is reached only from the correction
     * modal's toolbar, never from a save.
     *
     * THE PROPOSAL IS RE-DERIVED HERE, NOT TAKEN FROM THE REQUEST. The modal
     * showed a preview; what is issued is proposed again, server side, at
     * commit time. A posted draft would let the browser name the lines and the
     * money, and the preview the operator approved could differ from what the
     * order now says, which is exactly the gap between section 6's preview and section 5's
     * validity that re-deriving closes. The one thing posted is the preview's
     * fingerprint: IncrementalCorrection::issue() refuses a re-derived proposal
     * that no longer matches it (preview_changed), so what is issued is what
     * the operator reviewed, and a change in between sends them back to it.
     *
     * Every text shown back is escaped: a refusal's detail can carry charge
     * names, and the message queue renders HTML.
     *
     * IT MUTATES NO ORDER STATE (section 10). Issuing a correction records document
     * truth; the order's own quantities, prices and payments are changed
     * through the order's own workflow and never as a side effect of this.
     *
     *
     * @since   1.3.0
     */
    public function issueCorrection(): void
    {
        $app = Factory::getApplication();
        $input = $this->input;

        $this->checkToken();

        $orderId = $input->getInt('id_order', 0);
        $sourceId = $input->getInt('id_source', 0);

        $formUrl = "index.php?option={$this->option}&view=order&tmpl=component"
            . "&layout=edit_correction&id_order={$orderId}&id_source={$sourceId}";
        $returnUrl = "index.php?option={$this->option}&view=order&tmpl=component"
            . "&layout=edit_document_return&id_order={$orderId}";

        $success = false;

        if (!DocumentHelper::isIssuerEnabled()) {
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_ISSUER_EXTERNAL_MSG'), 'warning');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        // The SAME gate manual issuance answers to. A correction is an ordinary
        // issuance of an ordinary document; it is Void & Replace that carries
        // its own capability, and this is not that.
        $this->assertCanIssueDocument();

        try {
            $result = IncrementalCorrection::issue($orderId, $sourceId, $input->getString('fingerprint', ''));

            if ($result->issued === null) {
                // The proposer's own words: section 5 says the operator is told why.
                // An unavailable credit's detail is only the notes' numbers,
                // so it is told in the sentence that names them.
                $proposal = $result->proposal;
                $app->enqueueMessage(
                    Text::sprintf(
                        'COM_ALFA_CORRECTION_UNAVAILABLE',
                        htmlspecialchars(
                            match (true) {
                                $proposal->reason === CorrectionStrategies::REASON_CREDIT_UNAVAILABLE
                                    => Text::sprintf('COM_ALFA_CREDIT_UNAVAILABLE_UNRECORDED', $proposal->detail),
                                $proposal->detail !== '' => $proposal->detail,
                                default => Text::_('COM_ALFA_CORRECTION_REASON_' . strtoupper($proposal->reason)),
                            },
                            ENT_QUOTES,
                            'UTF-8',
                        ),
                    ),
                    'warning',
                );
                $this->setRedirect(Route::_($formUrl, false));

                return;
            }

            $success = true;

            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_DOCUMENTS_ISSUED_OK', (string) $result->issued->number_formatted),
                'success',
            );
        } catch (Throwable $e) {
            $app->enqueueMessage(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($success ? $returnUrl : $formUrl, false));
    }

    /**
     * Void a document and issue its replacement: the one coordinated act of section 4B.
     *
     * Reached only from the replacement modal's toolbar. The capability is the
     * Void & Replace one, asked here and again by the service and by the
     * issuer: holding Orders: Edit together with Sales Documents: Create does
     * NOT authorise this. The replacement is rebuilt
     * server side and refused unless it matches the fingerprint of the preview
     * the operator confirmed; nothing posted by the browser names a line or a
     * figure. The buyer fields ARE posted: the
     * fingerprint covers the buyer, so an edit that was never previewed is
     * refused, and on any refusal the edit and the reason are kept in the user
     * state so the modal shows them again, previewed afresh.
     *
     * The modal is redisplayed with `kept=1`: only that redisplay reads the
     * kept state back, so an edit abandoned by closing the modal never comes
     * back on the next open.
     *
     * Refused before anything is read, as issueCorrection() refuses: an
     * external issuer (documents_issuer), and a source document that belongs
     * to another order than the one posted.
     *
     * The replacement of a credit note that was sent to the customer's credit
     * account is sent there too. The account is asked as at Issue, before the
     * replacement is numbered: inside the act, once the source is voided, so
     * what the source held no longer counts against the replacement
     * (assertAccountTakesReplacement()); a refusal undoes the whole act. The
     * replacement is then paid in as an issued note is: once the act is
     * committed, as a step of its own (creditToAccount()). The void took back
     * whatever the account held for the source, so the account ends with the
     * replacement's amount alone.
     *
     *
     * @since   1.3.0
     */
    public function replaceDocument(): void
    {
        $app = Factory::getApplication();
        $input = $this->input;

        $this->checkToken();

        $orderId = $input->getInt('id_order', 0);
        $sourceId = $input->getInt('id_source', 0);

        $formUrl = "index.php?option={$this->option}&view=order&tmpl=component"
            . "&layout=edit_replacement&id_order={$orderId}&id_source={$sourceId}";
        $returnUrl = "index.php?option={$this->option}&view=order&tmpl=component"
            . "&layout=edit_document_return&id_order={$orderId}";

        if (!DocumentHelper::isIssuerEnabled()) {
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_ISSUER_EXTERNAL_MSG'), 'warning');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        if (!AlfaHelper::can(section: 'documents', action: CorrectionStrategies::CAPABILITY_REPLACE, user: $app->getIdentity())) {
            $app->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 'error');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        if ($this->replacementSourceElsewhere($orderId, $sourceId)) {
            $app->enqueueMessage(Text::_('COM_ALFA_REPLACEMENT_SOURCE_NOT_IN_ORDER'), 'error');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        $reason = trim($input->getString('reason', ''));
        $buyer = $this->postedReplacementBuyer($sourceId);
        $stateKey = 'com_alfa.edit.order.replacement.' . $sourceId;

        // Checked again on the server: the void half of this act needs its
        // audit reason exactly as a plain void does.
        if ($reason === '') {
            $app->setUserState($stateKey, ['buyer' => $buyer, 'reason' => $reason]);
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_VOID_NEEDS_REASON'), 'warning');
            $this->setRedirect(Route::_($formUrl . '&kept=1', false));

            return;
        }

        $success = false;

        try {
            $replacement = VoidAndReplace::execute(
                sourceId:        $sourceId,
                reason:          $reason,
                fingerprint:     $input->getString('fingerprint', ''),
                user:            $app->getIdentity(),
                buyer:           $buyer,
                beforeNumbering: self::assertAccountTakesReplacement(...),
            );
            $success = true;
            $app->setUserState($stateKey, null);

            $app->enqueueMessage(
                Text::sprintf('COM_ALFA_REPLACEMENT_ISSUED_OK', (string) $replacement->number_formatted),
                'success',
            );

            // The act is committed: a replacement frozen for the customer's
            // credit account has its credit paid in as a step of its own.
            if (($replacement->credit_destination ?? null) === CustomerCreditLedger::CREDIT_NOTE_DESTINATION) {
                $this->creditToAccount(document: $replacement);
            }
        } catch (Throwable $e) {
            // Kept, so the redisplayed modal previews what was typed afresh.
            // A refusal the issuer can put in the operator's words is shown in
            // them; its technical message stays the exception's and the log's.
            // Escaped: the issuer's words can carry a buyer's or a line's name.
            $app->setUserState($stateKey, ['buyer' => $buyer, 'reason' => $reason]);
            $app->enqueueMessage(htmlspecialchars(DocumentRefusedException::in($e)?->operatorText() ?? $e->getMessage(), ENT_QUOTES, 'UTF-8'), 'error');
        }

        $this->setRedirect(Route::_($success ? $returnUrl : $formUrl . '&kept=1', false));
    }

    /**
     * Preview the replacement with the operator's buyer edits: the modal's Preview button.
     *
     * WRITES NOTHING but the user state the modal reads back: the posted buyer
     * fields and the reason, keyed by the source. The redisplayed modal then
     * proposes the replacement with them, so the issuer previews, validates
     * and fingerprints exactly what will be confirmed,
     * and the typed reason is not lost on the way. The redirect
     * carries `kept=1`, the one open of the modal that reads the state back.
     * An external issuer and a source of another order keep nothing.
     *
     *
     * @since   1.14.0
     */
    public function previewReplacement(): void
    {
        $app = Factory::getApplication();
        $input = $this->input;

        $this->checkToken();

        $orderId = $input->getInt('id_order', 0);
        $sourceId = $input->getInt('id_source', 0);

        $formUrl = "index.php?option={$this->option}&view=order&tmpl=component"
            . "&layout=edit_replacement&id_order={$orderId}&id_source={$sourceId}";

        if (!DocumentHelper::isIssuerEnabled()) {
            $app->enqueueMessage(Text::_('COM_ALFA_DOCUMENTS_ISSUER_EXTERNAL_MSG'), 'warning');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        // The same capability the act itself answers to.
        if (!AlfaHelper::can(section: 'documents', action: CorrectionStrategies::CAPABILITY_REPLACE, user: $app->getIdentity())) {
            $app->enqueueMessage(Text::_('JLIB_APPLICATION_ERROR_ACCESS_FORBIDDEN'), 'error');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        if ($this->replacementSourceElsewhere($orderId, $sourceId)) {
            $app->enqueueMessage(Text::_('COM_ALFA_REPLACEMENT_SOURCE_NOT_IN_ORDER'), 'error');
            $this->setRedirect(Route::_($formUrl, false));

            return;
        }

        $app->setUserState('com_alfa.edit.order.replacement.' . $sourceId, [
            'buyer' => $this->postedReplacementBuyer($sourceId),
            'reason' => trim($input->getString('reason', '')),
        ]);

        $this->setRedirect(Route::_($formUrl . '&kept=1', false));
    }

    /**
     * The replacement buyer the modal posted, keyed as the draft names them.
     *
     * A field is an EDIT only when what the browser posted differs from what
     * the modal displayed in it. Displayed is not always stored: the email
     * layout shows an internationalised domain in Unicode, and the string
     * filter strips a '<' from a name, so an untouched field, filtered and
     * compared with the stored bytes, would read as an edit and silently
     * change a frozen buyer. The raw post is therefore
     * compared with the displayed value, and a field posted back as displayed,
     * or not posted at all, stands for the displayed bytes exactly. An edit is
     * read with the same filter the issue form uses.
     *
     * What the modal displayed is the source's frozen buyer with the kept edit
     * over it: the block HtmlView bound. Should another tab have changed the
     * kept state in between, the fingerprint refuses the result.
     *
     * A credit note's replacement takes no edit at all: it is made out to the
     * buyer of the document the note corrects, so
     * whatever a request posts for it is not read. Nor is anything read for a
     * source whose frozen buyer cannot be read back: there is no displayed
     * block to tell an edit from, and the service refuses that source by name.
     *
     * @param int $sourceId The document being replaced.
     *
     * @return array<string, string>
     *
     * @since   1.14.0
     */
    private function postedReplacementBuyer(int $sourceId): array
    {
        $source = DocumentHelper::getById($sourceId);

        // No source: the service refuses it by name.
        if (!$source || VoidAndReplace::isCredit($source)) {
            return [];
        }

        $kept = (array) Factory::getApplication()->getUserState('com_alfa.edit.order.replacement.' . $sourceId, []);

        try {
            $displayed = VoidAndReplace::buyerOf($source, \is_array($kept['buyer'] ?? null) ? $kept['buyer'] : null);
        } catch (RuntimeException) {
            // The reader refuses a stored buyer it cannot carry (an address
            // that is not a JSON object); the service says so by name.
            return [];
        }

        $buyer = [];

        foreach (VoidAndReplace::EDITABLE_BUYER as $key) {
            $shown = (string) $displayed[$key];
            $onScreen = $key === 'buyer_email' ? PunycodeHelper::emailToUTF8($shown) : $shown;
            $raw = $this->input->get($key, '', 'raw');
            $untouched = !$this->input->exists($key) || (\is_string($raw) && $raw === $onScreen);

            $buyer[$key] = $untouched ? $shown : $this->input->getString($key, '');
        }

        return $buyer;
    }

    /**
     * Does the posted source document belong to an order other than the posted one?
     *
     * The modal names both, and the form, the redirects and the audit read the
     * order from the request while the act reads the source. A pair that
     * disagrees is refused rather than replacing one order's document from
     * another's screen. A source that does not exist is not answered here:
     * the service refuses it by name.
     *
     * @param int $orderId The posted order.
     * @param int $sourceId The posted source document.
     *
     *
     * @since   1.14.0
     */
    private function replacementSourceElsewhere(int $orderId, int $sourceId): bool
    {
        $source = DocumentHelper::getById($sourceId);

        return $source !== null && (int) $source->id_order !== $orderId;
    }

    /**
     * Close the replacement modal without replacing anything.
     *
     * Also forgets the buyer edits and reason a preview kept for this source.
     *
     *
     * @since   1.3.0
     */
    public function cancelReplacement(): void
    {
        $orderID = $this->input->getInt('id_order');

        Factory::getApplication()->setUserState('com_alfa.edit.order.replacement.' . $this->input->getInt('id_source', 0), null);

        $this->setRedirect(Route::_(
            "index.php?option={$this->option}"
            . '&view=order'
            . '&layout=edit_document_return'
            . '&tmpl=component'
            . "&id_order={$orderID}"
            . '&reload=0',
            false,
        ));
    }

    /**
     * Close the correction modal without issuing anything.
     *
     *
     * @since   1.3.0
     */
    public function cancelCorrection(): void
    {
        $orderID = $this->input->getInt('id_order');

        $this->setRedirect(Route::_(
            "index.php?option={$this->option}"
            . '&view=order'
            . '&layout=edit_document_return'
            . '&tmpl=component'
            . "&id_order={$orderID}"
            . '&reload=0',
            false,
        ));
    }

    /**
     * Close the issue modal without issuing anything.
     *
     * reload=0: nothing was written, so the order behind the modal has no
     * reason to re-render. Also forgets a draft a refusal kept for this order.
     *
     *
     * @since   1.3.0
     */
    public function cancelDocument(): void
    {
        $orderID = $this->input->getInt('id_order');

        Factory::getApplication()->setUserState('com_alfa.edit.order.document.' . $orderID, null);

        $this->setRedirect(Route::_(
            "index.php?option={$this->option}"
            . '&view=order'
            . '&layout=edit_document_return'
            . '&tmpl=component'
            . "&id_order={$orderID}"
            . '&reload=0',
            false,
        ));
    }
}
