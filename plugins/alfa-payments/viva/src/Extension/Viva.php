<?php

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  VIVA PAYMENT PLUGIN — Alfa Commerce  (Smart Checkout — Pure Redirect)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uses Alfa\PhpViva SDK — zero external dependencies.
 * HTTP transport: Joomla\CMS\Http\HttpFactory (instantiated, not static)
 *
 * FLOW
 * ────
 *  1. onOrderAfterPlace()
 *     POST /checkout/v2/orders → get orderCode → save as PENDING
 *     transaction_id = orderCode (temporary — replaced in step 4)
 *
 *  2. onOrderProcessView() [VISIT 1]
 *     Read orderCode from payment record → redirect to Viva checkout URL.
 *
 *  3. Customer pays on Viva's hosted page.
 *     Viva redirects back to successUrl with:
 *       ?s={TRANSACTION_ID}&eventType=1796&ercCode=0&statusId=F
 *     statusId=F = success, X/E/C = cancelled/failed
 *
 *  4. onPaymentResponse() [customer return via task=payment.response]
 *     Read ?s= (the real Viva transaction ID) from input.
 *     Verify via GET /checkout/v2/transactions/{transactionId}.
 *     statusId=F → update payment to COMPLETED/AUTHORIZED.
 *     transaction_id is NOW updated to the real Viva transaction ID.
 *     Admin refund/capture/void all use this real transaction ID.
 *
 *  5. onOrderCompleteView() — thank-you page.
 *
 * KEY NOTE ON transaction_id
 * ──────────────────────────
 *  After step 1: transaction_id = Viva orderCode  (for checkout redirect)
 *  After step 4: transaction_id = Viva transactionId  (for refund/capture/void)
 *  Admin actions (refund, capture, void) require the REAL transactionId.
 *  If transaction_id still holds orderCode, API calls will return 403.
 *
 * AMOUNTS — Money objects (from OrderModel::getItem)
 * ──────────────────────────────────────────────────
 *  $order->total_paid_tax_incl->getMinorUnits()  int  (e.g. 999 for €9.99)
 *  $order->total_paid_tax_incl->getAmount()       float
 *  $order->currency->getCode()                    "EUR"
 *  $payment->amount->getMinorUnits()              int
 *  $payment->amount->getAmount()                  float
 *
 * @package    Alfa Commerce
 */

namespace Alfa\Plugin\AlfaPayments\Viva\Extension;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Alfa\PhpViva\SmartCheckout\Order as VivaOrder;
use Alfa\PhpViva\SmartCheckout\Transaction as VivaTransaction;
use Alfa\PhpViva\SmartCheckout\Url as VivaUrl;
use Alfa\PhpViva\Transaction\Cancel;
use Alfa\PhpViva\Transaction\Capture;
use Alfa\PhpViva\Transaction\ClassicCancel;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;
use RuntimeException;
use stdClass;

final class Viva extends PaymentsPlugin
{
    // =========================================================================
    //  PARAMS HELPERS
    // =========================================================================

    private function methodParams(object $order): Registry
    {
        $params = $order->selected_payment->params ?? [];

        if ($params instanceof Registry) {
            return $params;
        }

        if (is_string($params)) {
            return new Registry(json_decode($params, true) ?? []);
        }

        return new Registry((array) $params);
    }

    private function isTestMode(object $order): bool
    {
        return $this->methodParams($order)->get('mode', 'test') === 'test';
    }
    private function clientId(object $order): string
    {
        return (string) $this->methodParams($order)->get('client_id', '');
    }
    private function clientSecret(object $order): string
    {
        return (string) $this->methodParams($order)->get('client_secret', '');
    }
    private function sourceCode(object $order): string
    {
        return (string) $this->methodParams($order)->get('source_code', '');
    }
    private function isPreauth(object $order): bool
    {
        return $this->methodParams($order)->get('intent', 'charge') === 'authorize';
    }
    private function maxInstallments(object $order): int
    {
        return (int) $this->methodParams($order)->get('max_installments', 0);
    }

    // Classic API credentials — for refund/void via Basic Auth (no portal permission needed)
    private function merchantId(object $order): string
    {
        return (string) $this->methodParams($order)->get('merchant_id', '');
    }
    private function apiKey(object $order): string
    {
        return (string) $this->methodParams($order)->get('api_key', '');
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
     * Visit 1 only — redirect customer to Viva's hosted checkout page.
     * Return is handled by onPaymentResponse() via task=payment.response.
     * Cancelled/error cases redirect back here with ?viva_result= param.
     *
     * Return URL (set automatically): /index.php?option=com_alfa&task=payment.response
     * Failure URL (set automatically): /index.php?option=com_alfa&task=payment.response&statusId=X
     * Webhook URL (configure in Viva portal → Payment Sources → Webhooks):
     *   /index.php?option=com_alfa&task=plugin.trigger&type=alfa-payments&name=viva&func=notify
     */
    public function onOrderProcessView($event): void
    {
        $input = Factory::getApplication()->getInput();
        $result = $input->getString('viva_result', '');

        if ($result === 'cancelled') {
            $event->setLayout('default_order_process_cancelled');
            $event->setLayoutData(['order' => $event->getSubject()]);
            return;
        }

        if ($result === 'error') {
            $event->setLayout('default_order_process_error');
            $event->setLayoutData([
                'error' => $input->getString('viva_msg', Text::_('PLG_ALFA_PAYMENTS_VIVA_PAYMENT_FAILED')),
                'order' => $event->getSubject(),
            ]);
            return;
        }

        // Visit 1 — redirect to Viva checkout
        $this->redirectToViva($event, $event->getSubject());
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
     * Create the Viva Smart Checkout order after Alfa Commerce order is placed.
     * Stores the orderCode as transaction_id (temporary placeholder).
     * After payment, onPaymentResponse() replaces it with the real transaction ID.
     */
    public function onOrderAfterPlace($event): void
    {
        $order = $event->getOrder();

        if (!$order || empty($order->id)) {
            return;
        }

        $now = Factory::getDate('now', 'UTC')->toSql();
        $amount = $order->total_paid_tax_incl->getMinorUnits();
        $currency = $order->currency->getCode();

        try {
            $vivaOrder = $this->buildOrder($order, $amount);
            $result = $vivaOrder->send();

            if (empty($result->orderCode)) {
                throw new RuntimeException($vivaOrder->getError() ?? 'Viva API did not return an orderCode');
            }

            $orderCode = (string) $result->orderCode;

            $paymentId = $this->payment($order)
                ->pending()
                ->transactionId($orderCode)
                ->save();

            if (!$paymentId) {
                Log::add('Viva: failed to save payment record for order #' . $order->id, Log::ERROR, 'com_alfa.payments');
                return;
            }

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) $paymentId,
                'action' => 'order_created',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'viva_order_code' => $orderCode,
                'viva_transaction_id' => null,
                'intent' => $this->isPreauth($order) ? 'authorize' : 'charge',
                'amount' => $order->total_paid_tax_incl->getAmount(),
                'currency' => $currency,
                'note' => 'Viva Smart Checkout order created. orderCode=' . $orderCode,
                'created_on' => $now,
                'created_by' => 0,
            ]);
        } catch (Exception $e) {
            Log::add('Viva order creation failed for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
        }
    }

    // =========================================================================
    //  PAYMENT RESPONSE HOOK — customer return via task=payment.response
    // =========================================================================

    /**
     * Customer return from Viva's hosted checkout page.
     *
     * URL: /index.php?option=com_alfa&task=payment.response
     * Viva appends: ?s=TRANSACTION_ID&eventType=1796&ercCode=0&statusId=F
     *
     * IMPORTANT: We read ?s= (the real Viva transaction ID) NOT ?t= or anything else.
     * After verification, transaction_id on the payment record is updated to the
     * real Viva transaction ID so refund/capture/void work correctly.
     *
     * statusId=F → success (COMPLETED or AUTHORIZED)
     * statusId=X/E/C → cancelled/failed (leave payment as pending, show cancelled)
     */
    public function onPaymentResponse($event): void
    {
        $input = Factory::getApplication()->getInput();
        // Viva appends: ?s=ORDER_CODE&t=TRANSACTION_ID&statusId=F&eventType=1796
        // t = real transaction ID (needed for refund/capture/void via API)
        // s = order code (reference only — already stored from onOrderAfterPlace)
        $transactionId = $input->getString('t', '');
        $order = $event->getOrder();

        // PaymentController::response() only handles redirect — setLayout/setLayoutData are ignored.
        // For cancelled/error cases redirect back to the process page with ?viva_result= param.
        // onOrderProcessView reads that param and sets the appropriate layout.
        if (empty($transactionId)) {
            $event->setRedirectUrl(
                Route::_($this->getProcessPageUrl() . '&viva_result=cancelled', false, Route::TLS_FORCE, true),
            );
            return;
        }

        $this->handleVivaReturn($event, $order, $transactionId, $input);
    }

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

        if ($status === OrderPaymentHelper::STATUS_AUTHORIZED) {
            $event->add('capture', Text::_('PLG_ALFA_PAYMENTS_VIVA_CAPTURE'))
                ->icon('checkmark')->css('btn-success')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_VIVA_CONFIRM_CAPTURE'))
                ->priority(200);

            $event->add('void', Text::_('PLG_ALFA_PAYMENTS_VIVA_VOID'))
                ->icon('cancel')->css('btn-outline-danger')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_VIVA_CONFIRM_VOID'))
                ->priority(50);
        }

        if ($status === OrderPaymentHelper::STATUS_COMPLETED) {
            $event->add('refund', Text::_('PLG_ALFA_PAYMENTS_VIVA_REFUND'))
                ->icon('undo-2')->css('btn-warning')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_VIVA_CONFIRM_REFUND'))
                ->priority(150);
        }
    }

    public function onExecuteAction($event): void
    {
        match ($event->getAction()) {
            'capture' => $this->handleCapture($event),
            'void' => $this->handleVoid($event),
            'refund' => $this->handleRefund($event),
            'view_details' => $this->handleViewDetails($event),
            'view_logs' => $this->handleViewLogs($event),
            default => $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_UNKNOWN_ACTION', $event->getAction())),
        };
    }

    // =========================================================================
    //  PRIVATE — VISIT 1 REDIRECT
    // =========================================================================

    private function redirectToViva($event, object $order): void
    {
        try {
            $payment = $this->getLatestPendingPayment((int) $order->id);
            $orderCode = $payment->transaction_id ?? '';

            if (empty($orderCode)) {
                throw new RuntimeException('No Viva orderCode found for order #' . $order->id);
            }

            $redirectUrl = VivaUrl::checkoutUrl($orderCode, $this->isTestMode($order));

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) ($payment->id ?? 0),
                'action' => 'redirect',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'viva_order_code' => $orderCode,
                'viva_transaction_id' => null,
                'intent' => $this->isPreauth($order) ? 'authorize' : 'charge',
                'amount' => $order->total_paid_tax_incl->getAmount(),
                'currency' => $order->currency->getCode(),
                'note' => 'Customer redirected to Viva checkout.',
                'created_on' => Factory::getDate('now', 'UTC')->toSql(),
                'created_by' => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setRedirectUrl($redirectUrl);
        } catch (Exception $e) {
            Log::add('Viva redirect error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            Log::add('Viva redirect error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setRedirectUrl(
                Route::_($this->getProcessPageUrl() . '&viva_result=error&viva_msg=' . urlencode($e->getMessage()), false, Route::TLS_FORCE, true),
            );
        }
    }

    // =========================================================================
    //  PRIVATE — CUSTOMER RETURN
    // =========================================================================

    private function handleVivaReturn($event, object $order, string $transactionId, $input): void
    {
        $now = Factory::getDate('now', 'UTC')->toSql();
        $statusId = $input->getString('statusId', '');

        // Cancelled / failed before hitting API
        if (in_array($statusId, ['X', 'E', 'C'], true)) {
            $event->setRedirectUrl(
                Route::_($this->getProcessPageUrl() . '&viva_result=cancelled', false, Route::TLS_FORCE, true),
            );
            return;
        }

        try {
            $payment = $this->getLatestPendingPayment((int) $order->id);

            // Verify the real transaction ID via Viva API
            $trx = (new VivaTransaction())
                ->setClientId($this->clientId($order))
                ->setClientSecret($this->clientSecret($order))
                ->setTestMode($this->isTestMode($order))
                ->setTransactionId($transactionId);

            $result = $trx->send();

            if (!empty($trx->getError()) || empty($result)) {
                throw new RuntimeException($trx->getError() ?? 'Transaction verification failed');
            }

            $vivaStatus = $result->statusId ?? $statusId;

            if ($vivaStatus !== 'F') {
                throw new RuntimeException('Transaction not completed. Viva statusId: ' . $vivaStatus);
            }

            $isPreauth = !empty($result->isPreAuth) || $this->isPreauth($order);
            $intent = $isPreauth ? 'authorize' : 'charge';
            $alfaStatus = $isPreauth ? OrderPaymentHelper::STATUS_AUTHORIZED : OrderPaymentHelper::STATUS_COMPLETED;
            $note = $isPreauth
                ? 'Viva authorization confirmed. Capture via admin when shipping.'
                : 'Viva payment completed.';

            // Update transaction_id to the REAL Viva transaction ID
            // (was orderCode — now becomes the actual transaction ID for refund/capture/void)
            $update = $this->paymentUpdate((int) $payment->id)
                ->transactionId($transactionId)
                ->processedAt($now);

            $isPreauth ? $update->authorized()->save() : $update->completed()->save();

            $this->log([
                'id_order' => (int) $order->id,
                'id_order_payment' => (int) $payment->id,
                'action' => $intent,
                'transaction_status' => $alfaStatus,
                'viva_order_code' => (string) ($result->orderCode ?? ''),
                'viva_transaction_id' => $transactionId,
                'intent' => $intent,
                'amount' => $order->total_paid_tax_incl->getAmount(),
                'currency' => $order->currency->getCode(),
                'note' => $note,
                'created_on' => $now,
                'created_by' => 0,
            ]);

            $event->setRedirectUrl(
                Route::_($this->getCompletePageUrl(), false, Route::TLS_FORCE, true),
            );
        } catch (Exception $e) {
            Log::add('Viva return error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setRedirectUrl(
                Route::_($this->getProcessPageUrl() . '&viva_result=error&viva_msg=' . urlencode($e->getMessage()), false, Route::TLS_FORCE, true),
            );
        }
    }

    // =========================================================================
    //  PRIVATE — ADMIN ACTION HANDLERS
    // =========================================================================

    private function handleCapture($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $now = Factory::getDate('now', 'UTC')->toSql();
        $txId = $payment->transaction_id ?? '';

        if (empty($txId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        $capture = (new Capture())
            ->setClientId($this->clientId($order))
            ->setClientSecret($this->clientSecret($order))  // $order required — was missing in original
            ->setTestMode($this->isTestMode($order))
            ->setTransactionId($txId)
            ->setAmount($payment->amount->getMinorUnits());

        $result = $capture->send();

        if (!empty($capture->getError()) || empty($result)) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_CAPTURE', $capture->getError() ?? 'Unknown'));
            return;
        }

        $newTxId = $result->transactionId ?? $txId;

        $this->paymentUpdate((int) $payment->id)->completed()->transactionId($newTxId)->processedAt($now)->save();

        $this->log([
            'id_order' => (int) $order->id,
            'id_order_payment' => (int) $payment->id,
            'action' => 'admin_capture',
            'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
            'viva_order_code' => null,
            'viva_transaction_id' => $newTxId,
            'intent' => 'authorize',
            'amount' => $payment->amount->getAmount(),
            'currency' => $order->currency->getCode(),
            'note' => 'Captured by admin.',
            'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id,
        ]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_MSG_CAPTURED', $payment->id));
        $event->setRefresh(true);
    }

    private function handleVoid($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $amount = $payment->amount->getAmount();
        $now = Factory::getDate('now', 'UTC')->toSql();
        $txId = $payment->transaction_id ?? '';

        if (empty($txId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        // Try Native Checkout v2 (OAuth2) first
        $cancel = (new Cancel())
            ->setClientId($this->clientId($order))
            ->setClientSecret($this->clientSecret($order))
            ->setTestMode($this->isTestMode($order))
            ->setSourceCode($this->sourceCode($order))
            ->setTransactionId($txId);

        $result = $cancel->send();

        // On 403 fall back to Classic API (Basic Auth) — no portal permission needed
        if (empty($result) && $cancel->getLastHttpCode() === 403) {
            $cancel = (new ClassicCancel())
                ->setMerchantId($this->merchantId($order))
                ->setApiKey($this->apiKey($order))
                ->setTestMode($this->isTestMode($order))
                ->setSourceCode($this->sourceCode($order))
                ->setTransactionId($txId);

            $result = $cancel->send();
        }

        if (!empty($cancel->getError()) || empty($result)) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_VOID', $cancel->getError() ?? 'Unknown'));
            return;
        }

        $this->paymentUpdate((int) $payment->id)->cancelled()->save();

        $this->log(['id_order' => (int) $order->id, 'id_order_payment' => (int) $payment->id,
            'action' => 'void', 'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
            'viva_order_code' => null, 'viva_transaction_id' => $txId,
            'intent' => 'authorize', 'amount' => $amount, 'currency' => substr(trim((string) ($order->currency->getCode() ?? 'EUR')), 0, 3),
            'note' => 'Authorization voided by admin.', 'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_MSG_VOIDED', $payment->id));
        $event->setRefresh(true);
    }

    private function handleRefund($event): void
    {
        $payment = $event->getPayment();
        $order = $event->getOrder();
        $amount = $payment->amount->getAmount();
        $now = Factory::getDate('now', 'UTC')->toSql();
        $txId = $payment->transaction_id ?? '';

        if (empty($txId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        // Try Native Checkout v2 (OAuth2) first
        $cancel = (new Cancel())
            ->setClientId($this->clientId($order))
            ->setClientSecret($this->clientSecret($order))
            ->setTestMode($this->isTestMode($order))
            ->setSourceCode($this->sourceCode($order))
            ->setTransactionId($txId)
            ->setAmount($payment->amount->getMinorUnits());

        $result = $cancel->send();

        // On 403 fall back to Classic API (Basic Auth) — no portal permission needed
        if (empty($result) && $cancel->getLastHttpCode() === 403) {
            $cancel = (new ClassicCancel())
                ->setMerchantId($this->merchantId($order))
                ->setApiKey($this->apiKey($order))
                ->setTestMode($this->isTestMode($order))
                ->setSourceCode($this->sourceCode($order))
                ->setTransactionId($txId)
                ->setAmount($payment->amount->getMinorUnits());

            $result = $cancel->send();
        }

        if (!empty($cancel->getError()) || empty($result)) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_REFUND', $cancel->getError() ?? 'Unknown'));
            return;
        }

        $refundTxId = $result->TransactionId ?? ($result->transactionId ?? $txId);

        $this->paymentUpdate((int) $payment->id)->refunded()->save();

        $this->payment($order)->refund()->amount($amount)->refunded()
            ->refundedPayment((int) $payment->id)->fullRefund()->transactionId($refundTxId)
            ->refundReason(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_LOG_REFUNDED', $payment->id))->save();

        $this->log(['id_order' => (int) $order->id, 'id_order_payment' => (int) $payment->id,
            'action' => 'refund', 'transaction_status' => OrderPaymentHelper::STATUS_REFUNDED,
            'viva_order_code' => null, 'viva_transaction_id' => $refundTxId,
            'intent' => 'charge', 'amount' => $amount, 'currency' => substr(trim((string) ($order->currency->getCode() ?? 'EUR')), 0, 3),
            'note' => Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_LOG_REFUNDED', $payment->id),
            'created_on' => $now, 'created_by' => (int) Factory::getApplication()->getIdentity()->id]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_MSG_REFUNDED', $payment->id));
        $event->setRefresh(true);
    }

    //  PRIVATE — SDK BUILDERS
    // =========================================================================

    private function buildOrder(object $order, int $amount): VivaOrder
    {
        $vivaOrder = (new VivaOrder())
            ->setClientId($this->clientId($order))
            ->setClientSecret($this->clientSecret($order))
            ->setTestMode($this->isTestMode($order))
            ->setAmount($amount)
            ->setSourceCode($this->sourceCode($order))
            ->setMerchantTrns((string) $order->id)
            ->setCustomerTrns('Order #' . $order->id)
            ->setPreauth($this->isPreauth($order))
            ->setMaxInstallments($this->maxInstallments($order))
            ->setSuccessUrl($this->buildReturnUrl())
            ->setFailureUrl($this->buildReturnUrl('X'));

        if (!empty($order->customer_email)) {
            $vivaOrder->setCustomerEmail($order->customer_email);
        }

        $name = trim(($order->billing_firstname ?? '') . ' ' . ($order->billing_lastname ?? ''));
        if (!empty($name)) {
            $vivaOrder->setCustomerFullname($name);
        }

        if (!empty($order->customer_phone)) {
            $vivaOrder->setCustomerPhone($order->customer_phone);
        }

        return $vivaOrder;
    }

    // =========================================================================
    //  PRIVATE — URL BUILDERS
    // =========================================================================

    /**
     * Return URL for Viva checkout.
     * Viva appends ?s=TRANSACTION_ID&eventType=1796&ercCode=0&statusId=F automatically.
     * We read ?s= in onPaymentResponse() — NOT ?t= or any other param.
     *
     * @param string $statusId Pass 'X' for failure URL (Viva overrides statusId anyway)
     */
    private function buildReturnUrl(string $statusId = ''): string
    {
        $url = 'index.php?option=com_alfa&task=payment.response';
        if (!empty($statusId)) {
            $url .= '&statusId=' . $statusId;
        }
        return Route::_($url, false, Route::TLS_FORCE, true);
    }

    // =========================================================================
    //  PRIVATE — UTILITIES
    // =========================================================================

    private function getLatestPendingPayment(int $orderId): object
    {
        foreach ($this->getPaymentsByOrder($orderId) as $payment) {
            if (($payment->transaction_status ?? '') === OrderPaymentHelper::STATUS_PENDING) {
                return $payment;
            }
        }
        return new stdClass();
    }
}
