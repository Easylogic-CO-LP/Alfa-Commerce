<?php

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  REVOLUT PAYMENT PLUGIN — Alfa Commerce  (Merchant API — Pure Redirect)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * SDK: Alfa\PhpRevolut (zero deps, Joomla HttpFactory transport)
 * Targets: merchant.revolut.com  (not b2b.revolut.com)
 *
 * FLOW
 * ────
 *  1. onOrderAfterPlace()
 *     POST /api/orders → store revolut order id as pending payment
 *     redirect_url = task=payment.response  (customer return)
 *     webhook_url  = task=plugin.trigger&type=alfa-payments&name=revolut&func=notify
 *
 *  2. onOrderProcessView()  [VISIT 1 — redirect only]
 *     GET /api/orders/{id} → $checkout_url → setRedirectUrl()
 *
 *  3. Customer pays on Revolut's hosted page.
 *     Revolut appends ?revolut_order_id=ID to redirect_url → customer returns.
 *
 *  4. onPaymentResponse()  [customer browser return — has session, order in event]
 *     Revolut→customer→browser hits task=payment.response.
 *     Verify state via API → update payment record → redirect to complete page.
 *     This hook ALWAYS has a session and order in state.
 *
 *  5. notify()  [server webhook — no session, POST JSON]
 *     Revolut's servers POST to task=plugin.trigger&...&func=notify.
 *     Called via the frontend PluginController::trigger().
 *     No session. No order_id in URL. Plugin resolves everything from payload.
 *     Backup confirmation for browsers closed before redirect.
 *
 *  6. onOrderCompleteView()
 *     Thank-you page. Cart cleared by HtmlView.
 *
 * SEPARATION OF CONCERNS
 * ───────────────────────
 *  onPaymentResponse  = customer browser, has session → load order from state
 *  notify()           = Revolut server, no session → resolve from webhook payload
 *  No HTTP method detection hacks. No shared ambiguous endpoint.
 *
 * WEBHOOK URL (configure in Revolut dashboard → Developers → Webhooks)
 * ─────────────────────────────────────────────────────────────────────
 *   https://yourshop.gr/index.php
 *     ?option=com_alfa&task=plugin.trigger
 *     &type=alfa-payments&name=revolut&func=notify
 *
 * WEBHOOK SECURITY
 * ─────────────────
 *  Revolut signs webhooks with HMAC-SHA256 using your webhook signing secret.
 *  Header: Revolut-Signature  (format: v1=<hex_digest>)
 *  notify() verifies this before processing. See verifyWebhookSignature().
 *
 * AMOUNTS — Money objects (from OrderModel::getItem)
 * ──────────────────────────────────────────────────
 *  $order->total_paid_tax_incl->getMinorUnits()  int   (minor units, e.g. 999 for €9.99)
 *  $order->total_paid_tax_incl->getAmount()       float
 *  $order->currency->getCode()                    "EUR"
 *  $payment->amount->getMinorUnits()              int
 *  $payment->amount->getAmount()                  float
 *
 * @package    Alfa Commerce
 */

namespace Alfa\Plugin\AlfaPayments\Revolut\Extension;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Alfa\PhpRevolut\Client as RevolutClient;
use Alfa\PhpRevolut\Exceptions\MerchantException;
use Alfa\PhpRevolut\Requests\OrderCapture;
use Alfa\PhpRevolut\Requests\OrderCreate;
use Alfa\PhpRevolut\Requests\OrderRefund;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

final class Revolut extends PaymentsPlugin
{
    public function __construct($subject, $config = [])
    {
        parent::__construct($subject, $config);

        Log::addLogger(
            [
                'text_file' => 'alfa_payments_revolut.php',
                'text_file_no_php' => false,
            ],
            Log::ALL,
            ['com_alfa.payments'],
        );
    }

    // =========================================================================
    //  GET PARAMS
    // =========================================================================
    private function getParams($order)
    {
        return $order->selected_payment->params;
    }

    // =========================================================================
    //  SDK CLIENT
    // =========================================================================
    private function revolut($params): RevolutClient
    {
        return new RevolutClient(
            trim($params['secret_key']),
            (bool) $params['sandbox_mode'],
        );
    }

    // =========================================================================
    //  FRONTEND HOOKS
    // =========================================================================

    public function onItemView($event): void
    {
        $event->setLayout('default_item_view');
        $event->setLayoutData(['method' => $event->getMethod(), 'item' => $event->getSubject()]);
    }

    public function onCartView($event): void
    {
        $event->setLayout('default_cart_view');
        $event->setLayoutData(['method' => $event->getMethod(), 'item' => $event->getSubject()]);
    }

    /**
     * Order process page — VISIT 1 only.
     *
     * Retrieves the stored Revolut order and redirects to checkout_url.
     * The customer pays on Revolut's hosted page and returns via
     * task=payment.response (onPaymentResponse), not back here.
     */
    public function onOrderProcessView($event): void
    {
        $input = Factory::getApplication()->getInput();
        $result = $input->getString('revolut_result', '');
        $error = $input->getString('revolut_error_msg', '');

        if ($result === 'error') {
            $event->setLayout('default_order_process_error');
            $event->setLayoutData([
                'error' => $error ?? Text::_('PLG_ALFA_PAYMENTS_REVOLUT_PAYMENT_FAILED'),
                'order' => $event->getSubject(),
            ]);
            return;
        }

        $this->redirectToRevolut($event, $event->getSubject());
    }

    public function onOrderCompleteView($event): void
    {
        // Cart cleared by HtmlView when default_order_completed loads.
        $event->setLayout('default_order_completed');
        $event->setLayoutData(['order' => $event->getSubject(), 'method' => $event->getMethod()]);
    }

    // =========================================================================
    //  ORDER PLACEMENT HOOK
    // =========================================================================

    /**
     * Create the Revolut order immediately after the Alfa Commerce order is placed.
     *
     * Two URLs registered with Revolut:
     *
     *   redirect_url — customer return after payment (GET, has session)
     *                  → task=payment.response → onPaymentResponse()
     *                  Revolut appends ?revolut_order_id=ID automatically.
     *
     *   webhook_url  — server-to-server notification (POST, no session)
     *                  → task=plugin.trigger&type=alfa-payments&name=revolut&func=notify
     *                  OR configure manually in Revolut dashboard.
     */
    public function onOrderAfterPlace($event): void
    {
        // 1. Check if event fires at all
        Log::add('Revolut: onOrderAfterPlace FIRED', Log::DEBUG, 'com_alfa.payments');

        // 2. Inspect the event object
        $order = $event->getOrder();
        Log::add('Revolut: order object type = ' . get_class($order), Log::DEBUG, 'com_alfa.payments');
        Log::add('Revolut: order id = ' . ($order->id ?? 'NULL'), Log::DEBUG, 'com_alfa.payments');

        // 3. Check selected_payment
        if (!isset($order->selected_payment)) {
            Log::add('Revolut: selected_payment is NOT SET', Log::ERROR, 'com_alfa.payments');
            return;
        }
        Log::add('Revolut: selected_payment = ' . print_r($order->selected_payment, true), Log::DEBUG, 'com_alfa.payments');

        $params = $order->selected_payment->params;
        Log::add('Revolut: params = ' . print_r($params, true), Log::DEBUG, 'com_alfa.payments');

        if (!$order || empty($order->id)) {
            Log::add('Revolut: order is empty/null — bailing out', Log::WARNING, 'com_alfa.payments');
            return;
        }

        // 4. Inspect currency and amount
        $now = Factory::getDate('now', 'UTC')->toSql();
        $currency = $order->currency->getCode();
        $amount = $order->total_paid_tax_incl->getMinorUnits();

        Log::add("Revolut: currency={$currency}, amount(minor)={$amount}, now={$now}", Log::DEBUG, 'com_alfa.payments');

        // 5. Inspect redirect URL
        $redirectUrl = $this->buildReturnUrl();
        Log::add('Revolut: redirect_url = ' . $redirectUrl, Log::DEBUG, 'com_alfa.payments');

        try {
            // 6. Log what we're about to send
            $payload = [
                'amount' => $amount,
                'currency' => $currency,
                'redirect_url' => $redirectUrl,
                'merchant_order_ext_ref' => (string) $order->id,
                'description' => 'Order #' . $order->id,
            ];
            Log::add('Revolut: API payload = ' . json_encode($payload), Log::DEBUG, 'com_alfa.payments');

            // 7. Check the client itself
            $client = $this->revolut($params);
            Log::add('Revolut: client type = ' . get_class($client), Log::DEBUG, 'com_alfa.payments');

            $result = $client->order->create(new OrderCreate(
                amount:                 $amount,
                currency:               $currency,
                redirect_url:           $redirectUrl,
                merchant_order_ext_ref: (string) $order->id,
                description:            'Order #' . $order->id,
            ));

            // 8. Full API response
            Log::add('Revolut: API response = ' . json_encode($result), Log::DEBUG, 'com_alfa.payments');

            $revolutOrderId = $result->id ?? '';

            if (empty($revolutOrderId)) {
                throw new MerchantException('Revolut order ID absent in response');
            }

            Log::add("Revolut: revolutOrderId = {$revolutOrderId}", Log::DEBUG, 'com_alfa.payments');

            // 9. Debug payment save
            $paymentBuilder = $this->payment($order)->pending()->transactionId($revolutOrderId);
            // Log::add('Revolut: payment builder state = ' . print_r($paymentBuilder, true), Log::DEBUG, 'com_alfa.payments');

            $paymentId = $paymentBuilder->save();
            Log::add('Revolut: paymentId = ' . var_export($paymentId, true), Log::DEBUG, 'com_alfa.payments');

            if (!$paymentId) {
                Log::add('Revolut: failed to save payment record for order #' . $order->id, Log::ERROR, 'com_alfa.payments');
                return;
            }

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) $paymentId,
                'action' => 'order_created',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'revolut_order_id' => $revolutOrderId,
                'revolut_state' => 'PENDING',
                'amount' => $order->total_paid_tax_incl->getAmount(),
                'currency' => $currency,
                'note' => 'Revolut order created. redirect_url=' . $redirectUrl,
                'created_on' => $now,
                'created_by' => 0,
            ]);

            Log::add('Revolut: onOrderAfterPlace completed successfully for order #' . $order->id, Log::DEBUG, 'com_alfa.payments');
        } catch (MerchantException $e) {
            Log::add('Revolut createOrder API error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            Log::add('Revolut: stack trace = ' . $e->getTraceAsString(), Log::DEBUG, 'com_alfa.payments');
        } catch (Exception $e) {
            Log::add('Revolut createOrder failed for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            Log::add('Revolut: stack trace = ' . $e->getTraceAsString(), Log::DEBUG, 'com_alfa.payments');
        }
    }

    // =========================================================================
    //  CUSTOMER RETURN HOOK  (task=payment.response — has session)
    // =========================================================================

    /**
     * Customer returning from Revolut's checkout page.
     *
     * Called by the Alfa Commerce PaymentController::response() action.
     * The order is always available via $event->getOrder() (loaded from session).
     * URL contains ?revolut_order_id=ID appended by Revolut.
     *
     * This hook ONLY handles the customer browser return.
     * Server webhooks go to notify() via the frontend PluginController.
     */
    public function onPaymentResponse($event): void
    {
        $app = Factory::getApplication();

        $order = $event->getOrder();
        $params = $this->getParams($order);
        $now = Factory::getDate('now', 'UTC')->toSql();

        $payment = $this->getLatestPendingPayment((int) $order->id);

        if ($payment === null) {
            $app->enqueueMessage('No valid pending payment found!', 'warning');
            $event->setRedirectUrl(Route::_('/index.php'));
            return;
        }

        $revolutOrderId = $payment->transaction_id ?? '';

        if (empty($revolutOrderId)) {
            $event->setRedirectUrl($this->getProcessPageUrl() . '&revolut_result=error');
        }

        try {
            $result = $this->revolut($params)->order->get($revolutOrderId);

            $state = strtoupper($result->state ?? '');

            if ($state === 'CANCELLED') {
                $this->paymentUpdate((int) $payment->id)->cancelled()->save();

                $this->log([
                    'id_order' => (int) $order->id,
                    'id_order_payment' => (int) $payment->id,
                    'action' => 'cancelled',
                    'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
                    'revolut_order_id' => $revolutOrderId,
                    'revolut_state' => 'CANCELLED',
                    'amount' => $order->total_paid_tax_incl->getAmount(),
                    'currency' => $order->currency->getCode(),
                    'note' => 'Payment cancelled by customer.',
                    'created_on' => $now,
                    'created_by' => 0,
                ]);

                $event->setLayout('default_order_process_cancelled');
                $event->setLayoutData(['order' => $order]);
                return;
            }

            if (!in_array($state, ['COMPLETED', 'AUTHORISED'], true)) {
                throw new MerchantException('Unexpected Revolut state on return: ' . $state);
            }

            $isAuthorised = ($state === 'AUTHORISED');
            $alfaStatus = $isAuthorised ? OrderPaymentHelper::STATUS_AUTHORIZED : OrderPaymentHelper::STATUS_COMPLETED;
            $update = $this->paymentUpdate((int) $payment->id)->transactionId($revolutOrderId)->processedAt($now);

            $isAuthorised ? $update->authorized()->save() : $update->completed()->save();

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) $payment->id,
                'action' => strtolower($state),
                'transaction_status' => $alfaStatus,
                'revolut_order_id' => $revolutOrderId,
                'revolut_state' => $state,
                'amount' => $order->total_paid_tax_incl->getAmount(),
                'currency' => $order->currency->getCode(),
                'note' => 'Revolut ' . $state . ' — customer return.',
                'created_on' => $now,
                'created_by' => 0,
            ]);

            $event->setRedirectUrl($this->getCompletePageUrl());
        } catch (Exception $e) {
            Log::add('Revolut return error for order #' . ($order->id ?? '?') . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $redirectUrl = $this->getProcessPageUrl();
            $redirectUrl .= '&revolut_result=error';
            $redirectUrl .= '&revolut_error_msg=' . urlencode($e->getMessage());

            $event->setRedirectUrl($redirectUrl);
        }
    }

    // =========================================================================
    //  WEBHOOK HOOK — server-to-server POST from Revolut
    // =========================================================================

    /**
     * Revolut server webhook.
     *
     * Called directly by PluginController::trigger() — no event, no arguments.
     * Webhook URL (configure manually in Revolut dashboard → Developers → Webhooks):
     *   /index.php?option=com_alfa&task=plugin.trigger&type=alfa-payments&name=revolut&func=notify
     *
     * Completely separate from onPaymentResponse():
     *   - No session, no order object, no event
     *   - Reads raw POST body directly
     *   - Returns mixed (PluginController serialises it as JSON)
     *
     * ATTENTION - Webhook is incomplete and may have bugs.
     */

    /* public function notify(): mixed
     {
         $payload   = (string) file_get_contents('php://input');
         $data      = json_decode($payload, true) ?? [];
         $eventType = $data['event'] ?? '';
         $orderId   = $data['order_id'] ?? '';

         if (empty($eventType) || empty($orderId)) {
             return ['ok' => false, 'error' => 'Missing event or order_id'];
         }

         try {
             // Always verify via API — never trust the webhook payload alone
             $result = $this->revolut()->order->get($orderId);
             $state  = strtoupper($result->state ?? '');

             match ($eventType) {
                 'ORDER_COMPLETED'      => Log::add('Revolut webhook COMPLETED order=' . $orderId . ' state=' . $state, Log::INFO, 'com_alfa.payments'),
                 'ORDER_AUTHORISED'     => Log::add('Revolut webhook AUTHORISED order=' . $orderId, Log::INFO, 'com_alfa.payments'),
                 'ORDER_CANCELLED'      => Log::add('Revolut webhook CANCELLED order=' . $orderId, Log::INFO, 'com_alfa.payments'),
                 'ORDER_PAYMENT_FAILED' => Log::add('Revolut webhook PAYMENT_FAILED order=' . $orderId, Log::WARNING, 'com_alfa.payments'),
                 default                => Log::add('Revolut webhook unknown event=' . $eventType, Log::DEBUG, 'com_alfa.payments'),
             };

             return ['ok' => true, 'event' => $eventType, 'state' => $state];

         } catch (\Exception $e) {
             Log::add('Revolut webhook error [' . $eventType . ']: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
             return ['ok' => false, 'error' => $e->getMessage()];
         }
     }*/

    // =========================================================================
    //  ADMIN ACTIONS
    // =========================================================================

    public function onGetActions($event): void
    {
        $payment = $event->getPayment();
        $status = $payment->transaction_status ?? 'pending';

        $event->add('view_details', Text::_('COM_ALFA_VIEW_DETAILS'))
            ->icon('eye')->css('btn-outline-secondary')
            ->modal('action_view_details', Text::_('COM_ALFA_PAYMENT_DETAILS') . ' #' . $payment->id)
            ->priority(10);

        $event->add('view_logs', Text::_('COM_ALFA_VIEW_LOGS'))
            ->icon('list')->css('btn-outline-info')
            ->modal('default_order_logs_view', Text::_('COM_ALFA_PAYMENT_LOGS') . ' #' . $payment->id, 'lg')
            ->priority(5);

        if ($status === OrderPaymentHelper::STATUS_PENDING) {
            $event->add('mark_paid', Text::_('PLG_ALFA_PAYMENTS_REVOLUT_MARK_PAID'))
                ->icon('checkmark')->css('btn-success')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CONFIRM_MARK_PAID'))
                ->priority(200);

            $event->add('cancel', Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CANCEL'))
                ->icon('cancel')->css('btn-outline-danger')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CONFIRM_CANCEL'))
                ->priority(50);
        }

        if ($status === OrderPaymentHelper::STATUS_AUTHORIZED) {
            $event->add('capture', Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CAPTURE'))
                ->icon('checkmark')->css('btn-success')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CONFIRM_CAPTURE'))
                ->priority(200);

            $event->add('void', Text::_('PLG_ALFA_PAYMENTS_REVOLUT_VOID'))
                ->icon('cancel')->css('btn-outline-danger')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CONFIRM_VOID'))
                ->priority(50);
        }

        if ($status === OrderPaymentHelper::STATUS_COMPLETED) {
            $event->add('refund', Text::_('PLG_ALFA_PAYMENTS_REVOLUT_REFUND'))
                ->icon('undo-2')->css('btn-warning')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_CONFIRM_REFUND'))
                ->priority(150);
        }
    }

    public function onExecuteAction($event): void
    {
        match ($event->getAction()) {
            'mark_paid' => $this->handleMarkPaid($event),
            'cancel' => $this->handleCancel($event),
            'capture' => $this->handleCapture($event),
            'void' => $this->handleVoid($event),
            'refund' => $this->handleRefund($event),
            'view_details' => $this->handleViewDetails($event),
            'view_logs' => $this->handleViewLogs($event),
            default => $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_UNKNOWN_ACTION', $event->getAction())),
        };
    }

    // =========================================================================
    //  PRIVATE — VISIT 1 REDIRECT
    // =========================================================================

    private function redirectToRevolut($event, object $order): void
    {
        $params = $this->getParams($order);

        try {
            $payment = $this->getLatestPendingPayment($order->id);
            if ($payment == null) {
                throw new Exception('No valid pending payment found for ' . $order->id);
            }

            $revolutOrderId = $payment->transaction_id ?? '';

            if (empty($revolutOrderId)) {
                throw new MerchantException('No Revolut order ID stored for order #' . $order->id);
            }

            $result = $this->revolut($params)->order->get($revolutOrderId);
            $checkoutUrl = $result->checkout_url ?? '';

            if (empty($checkoutUrl)) {
                throw new MerchantException('No checkout_url in Revolut order ' . $revolutOrderId . ' (state: ' . ($result->state ?? '?') . ')');
            }

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) ($payment->id ?? 0),
                'action' => 'redirect',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'revolut_order_id' => $revolutOrderId,
                'revolut_state' => $result->state ?? '',
                'amount' => $order->total_paid_tax_incl->getAmount(),
                'currency' => $order->currency->getCode(),
                'note' => 'Customer redirected to Revolut checkout.',
                'created_on' => Factory::getDate('now', 'UTC')->toSql(),
                'created_by' => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setRedirectUrl($checkoutUrl);
        } catch (Exception $e) {
            Log::add('Revolut redirect error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
        }
    }

    // =========================================================================
    //  PRIVATE — ADMIN HANDLERS
    // =========================================================================

    private function handleMarkPaid($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $now = Factory::getDate('now', 'UTC')->toSql();

        $this->paymentUpdate((int) $payment->id)->completed()->processedAt($now)->save();

        $this->log([
            'id_order' => (int) $order->id,
            'id_order_payment' => (int) $payment->id,
            'action' => 'mark_paid',
            'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
            'revolut_order_id' => $payment->transaction_id ?? null,
            'revolut_state' => 'COMPLETED',
            'amount' => $payment->amount->getAmount(),
            'currency' => $order->currency->getCode(),
            'note' => 'Manually marked as paid by admin.',
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_MSG_MARKED_PAID', $payment->id));
        $event->setRefresh(true);
    }

    private function handleCancel($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $params = $this->getParams($order);
        $now = Factory::getDate('now', 'UTC')->toSql();
        $txId = $payment->transaction_id ?? '';

        if (!empty($txId)) {
            try {
                $this->revolut($params)->order->cancel($txId);
            } catch (Exception $e) {
                Log::add('Revolut cancel API error: ' . $e->getMessage(), Log::WARNING, 'com_alfa.payments');
            }
        }

        $this->paymentUpdate((int) $payment->id)->cancelled()->save();

        $this->log([
            'id_order' => (int) $order->id,
            'id_order_payment' => (int) $payment->id,
            'action' => 'cancel',
            'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
            'revolut_order_id' => $txId ?: null,
            'revolut_state' => 'CANCELLED',
            'amount' => $payment->amount->getAmount(),
            'currency' => $order->currency->getCode(),
            'note' => 'Cancelled by admin.',
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_MSG_CANCELLED', $payment->id));
        $event->setRefresh(true);
    }

    private function handleCapture($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $params = $this->getParams($order);
        $now = Factory::getDate('now', 'UTC')->toSql();
        $txId = $payment->transaction_id ?? '';

        if (empty($txId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        try {
            $this->revolut($params)->order->capture($txId, new OrderCapture(
                amount: $payment->amount->getMinorUnits(),
            ));

            $this->paymentUpdate((int) $payment->id)->completed()->processedAt($now)->save();

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) $payment->id,
                'action' => 'admin_capture',
                'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
                'revolut_order_id' => $txId,
                'revolut_state' => 'COMPLETED',
                'amount' => $payment->amount->getAmount(),
                'currency' => $order->currency->getCode(),
                'note' => 'Captured by admin.',
                'created_on' => $now,
                'created_by' => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_MSG_CAPTURED', $payment->id));
            $event->setRefresh(true);
        } catch (Exception $e) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_CAPTURE', $e->getMessage()));
        }
    }

    private function handleVoid($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $params = $this->getParams($order);
        $now = Factory::getDate('now', 'UTC')->toSql();
        $txId = $payment->transaction_id ?? '';

        if (empty($txId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        try {
            $this->revolut($params)->order->cancel($txId);

            $this->paymentUpdate((int) $payment->id)->cancelled()->save();

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) $payment->id,
                'action' => 'void',
                'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
                'revolut_order_id' => $txId,
                'revolut_state' => 'CANCELLED',
                'amount' => $payment->amount->getAmount(),
                'currency' => $order->currency->getCode(),
                'note' => 'Authorization voided by admin.',
                'created_on' => $now,
                'created_by' => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_MSG_VOIDED', $payment->id));
            $event->setRefresh(true);
        } catch (Exception $e) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_VOID', $e->getMessage()));
        }
    }

    private function handleRefund($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $params = $this->getParams($order);
        $now = Factory::getDate('now', 'UTC')->toSql();
        $txId = $payment->transaction_id ?? '';

        if (empty($txId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        try {
            $this->revolut($params)->order->refund($txId, new OrderRefund(
                amount:      $payment->amount->getMinorUnits(),
                currency: $order->currency->getCode(),
                description: Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_LOG_REFUNDED', $payment->id),
            ));

            $this->paymentUpdate((int) $payment->id)->refunded()->save();

            $this->payment($order)->refund()
                ->amount($payment->amount->getAmount())
                ->refunded()
                ->refundedPayment((int) $payment->id)
                ->fullRefund()
                ->transactionId($txId)
                ->refundReason(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_LOG_REFUNDED', $payment->id))
                ->save();

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) $payment->id,
                'action' => 'refund',
                'transaction_status' => OrderPaymentHelper::STATUS_REFUNDED,
                'revolut_order_id' => $txId,
                'revolut_state' => 'REFUNDED',
                'amount' => $payment->amount->getAmount(),
                'currency' => $order->currency->getCode(),
                'note' => Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_LOG_REFUNDED', $payment->id),
                'created_on' => $now,
                'created_by' => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_MSG_REFUNDED', $payment->id));
            $event->setRefresh(true);
        } catch (Exception $e) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_REVOLUT_ERROR_REFUND', $e->getMessage()));
        }
    }

    private function handleViewDetails($event): void
    {
        $payment = $event->getPayment();
        $event->setLayout('action_view_details');
        $event->setLayoutData(['payment' => $payment, 'order' => $event->getOrder()]);
        $event->setModalTitle(Text::_('COM_ALFA_PAYMENT_DETAILS') . ' #' . $payment->id);
    }

    private function handleViewLogs($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $event->setLayout('default_order_logs_view');
        $event->setLayoutData([
            'logData' => $this->loadLogs((int) $order->id, (int) $payment->id) ?? [],
            'xml' => $this->getLogsSchema(),
        ]);
        $event->setModalTitle(Text::_('COM_ALFA_PAYMENT_LOGS') . ' #' . $payment->id);
    }

    // =========================================================================
    //  PRIVATE — WEBHOOK SIGNATURE VERIFICATION
    // =========================================================================

    /**
     * Verify the Revolut-Signature HMAC-SHA256 header.
     *
     * Revolut signs each webhook with:
     *   Revolut-Signature: v1=<hex(hmac-sha256(payload, signing_secret))>
     *
     * The signing_secret is shown once in Revolut dashboard → Developers → Webhooks.
     * Store it as the "webhook_secret" plugin parameter.
     *
     * Returns null on success, error string on failure.
     *
     * ATTENTION - Webhook is incomplete and may have bugs.
     */
    /*private function verifyWebhookSignature(string $payload): ?string
    {
        $secret = trim($this->params->get('webhook_secret', ''));

        if (empty($secret)) {
            // Webhook secret not configured — skip verification (dev/testing)
            Log::add('Revolut webhook: signature verification skipped (no webhook_secret configured)', Log::WARNING, 'com_alfa.payments');
            return null;
        }

        $header = $_SERVER['HTTP_REVOLUT_SIGNATURE'] ?? '';

        if (empty($header)) {
            return 'Missing Revolut-Signature header';
        }

        // Header format: "v1=<hex_digest>"
        if (!str_starts_with($header, 'v1=')) {
            return 'Unknown signature version in Revolut-Signature header';
        }

        $receivedHex  = substr($header, 3);
        $expectedHex  = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedHex, $receivedHex)) {
            return 'Revolut-Signature HMAC mismatch';
        }

        return null;
    }*/

    // =========================================================================
    //  PRIVATE — URL BUILDERS
    // =========================================================================

    /**
     * Customer return URL after Revolut checkout.
     * task=payment.response → PaymentController::response() → onPaymentResponse()
     */
    private function buildReturnUrl(): string
    {
        return Route::_('index.php?option=com_alfa&task=payment.response', false, Route::TLS_FORCE);
    }

    /**
     * Webhook URL for Revolut's server-to-server notifications.
     * Configure in Revolut dashboard → Developers → Webhooks.
     * task=plugin.trigger → frontend PluginController::trigger() → notify()
     *
     * ATTENTION - Webhook is incomplete and may have bugs.
     */
    /*public static function buildWebhookUrl(): string
    {
        return Uri::root() . 'index.php?option=com_alfa&task=plugin.trigger&type=alfa-payments&name=revolut&func=notify';
    }*/

    // =========================================================================
    //  PRIVATE — UTILITIES
    // =========================================================================

    private function getLatestPendingPayment(int $orderId)
    {
        foreach ($this->getPaymentsByOrder($orderId) as $payment) {
            if (($payment->transaction_status ?? '') === OrderPaymentHelper::STATUS_PENDING) {
                return $payment;
            }
        }
        return null;
    }
}
