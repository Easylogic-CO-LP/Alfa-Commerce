<?php

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  VIVA PAYMENT PLUGIN — Alfa Commerce  (Smart Checkout — Pure Redirect)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uses Alfa\PhpViva SDK — zero external dependencies.
 * HTTP transport: Joomla\CMS\Http\HttpFactory
 *
 * ───────────────────────────────────────────────────────────────────────
 *  FLOW — identical pattern to Klarna HPP and PayPal redirect
 * ───────────────────────────────────────────────────────────────────────
 *
 *  1. onOrderAfterPlace()
 *     POST /checkout/v2/orders → get orderCode → save as PENDING
 *
 *  2. onOrderProcessView() [VISIT 1 — no ?s in URL]
 *     Redirect customer to:
 *       https://www.vivapayments.com/web/checkout?ref={orderCode}
 *     Customer pays on Viva's hosted page (card, wallet, etc.)
 *
 *  3. Viva redirects back to successUrl:
 *       ?s={TRANSACTION_ID}&eventType=1796&ercCode=0&statusId=F
 *     statusId 'F' = successful final transaction
 *
 *  4. onOrderProcessView() [VISIT 2 — ?s present]
 *     GET /checkout/v2/transactions/{transactionId} → verify status
 *     statusId 'F' → mark COMPLETED / AUTHORIZED → redirect to complete page
 *
 *  5. onOrderCompleteView()
 *     Thank-you page.
 *
 *  6. onPaymentResponse() — Viva webhook
 *     GET ?s=TRANSACTION_ID&eventType=1796&statusId=F
 *     Backup confirmation if return visit was missed.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  PREAUTH (AUTHORIZE) vs CHARGE
 * ───────────────────────────────────────────────────────────────────────
 *  charge    (preauth=false) → funds collected on Viva's page → COMPLETED
 *             Admin: Refund
 *  authorize (preauth=true)  → funds reserved → AUTHORIZED
 *             Admin: Capture, Void
 *
 * ───────────────────────────────────────────────────────────────────────
 *  AMOUNTS — minor units (integer cents), same as Klarna
 * ───────────────────────────────────────────────────────────────────────
 *  €9.99 → 999,  €100.00 → 10000
 *
 * @package    Alfa Commerce
 * @since      1.0.0
 */

namespace Alfa\Plugin\AlfaPayments\Viva\Extension;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Alfa\PhpViva\SmartCheckout\Order    as VivaOrder;
use Alfa\PhpViva\SmartCheckout\Transaction as VivaTransaction;
use Alfa\PhpViva\SmartCheckout\Url      as VivaUrl;
use Alfa\PhpViva\Transaction\Cancel;
use Alfa\PhpViva\Transaction\Capture;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

final class Viva extends PaymentsPlugin
{
    // =========================================================================
    //  PARAMS HELPERS
    // =========================================================================

	private function getVivaParams(object $order): Registry
	{
		$rawParams = $order->selected_payment->params ?? '{}';
		return new Registry($rawParams);
	}

	private function clientId(object $order): string    { return trim($this->getVivaParams($order)->get('client_id', '')); }
	private function clientSecret(object $order): string{ return trim($this->getVivaParams($order)->get('client_secret', '')); }
	private function sourceCode(object $order): string  { return trim($this->getVivaParams($order)->get('source_code', '')); }
	private function isTestMode(object $order): bool    { return (bool) $this->getVivaParams($order)->get('mode', false); }
	private function isPreauth(object $order): bool     { return $this->getVivaParams($order)->get('intent', 'charge') === 'authorize'; }
	private function maxInstallments(object $order): int{ return (int) $this->getVivaParams($order)->get('max_installments', 0); }

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
     * Order process page.
     *
     * VISIT 1 (no ?s param): redirect customer to Viva's hosted checkout page.
     * VISIT 2 (?s=TRANSACTION_ID): verify transaction and mark as complete.
     *
     * Viva appends ?s=TRANSACTION_ID&eventType=1796&ercCode=0&statusId=F to successUrl.
     * Alfa Commerce carries order_id in the URL — both IDs arrive together.
     */
    public function onOrderProcessView($event): void
    {
        $order         = $event->getSubject();
        $input         = Factory::getApplication()->getInput();
        $transactionId = $input->getString('s', '');

        // VISIT 2: Customer returned from Viva's checkout page
        if (!empty($transactionId)) {
            $this->handleVivaReturn($event, $order, $transactionId, $input);
            return;
        }

        // VISIT 1: Redirect to Viva's checkout page
        $this->redirectToViva($event, $order);
    }

    public function onOrderCompleteView($event): void
    {
        $event->setLayout('default_order_completed');
        $event->setLayoutData(['order' => $event->getSubject(), 'method' => $event->getMethod()]);
    }

    // =========================================================================
    //  ORDER PLACEMENT HOOK
    // =========================================================================

    /**
     * Create the Viva Smart Checkout order immediately after Alfa Commerce order is saved.
     * Stores the orderCode for the redirect on the process page.
     */
    public function onOrderAfterPlace($event): void
    {
        $order = $event->getOrder();

        if (!$order || empty($order->id)) {
            return;
        }

        $now      = Factory::getDate('now', 'UTC')->toSql();
        $amount   = $this->amountToMinorUnit($this->resolveAmount($order));
        $currency = $order->id_currency ?? 'EUR';

        try {
            $vivaOrder = $this->buildOrder($order, $amount);
            $result    = $vivaOrder->send();

//	        echo '<pre>';
//	        print_r($vivaOrder);
//	        echo '</pre>';
//	        exit();

//	        echo '<pre style="background:#111; color:#0f0; padding:20px; z-index:9999; position:relative;">';
//	        echo '<b>RESULT:</b><br>';
//	        var_dump($result);
//	        echo '<br><br><b>ERROR:</b><br>';
//	        var_dump($vivaOrder->getError());
//	        echo '</pre>';
//	        exit();

            if (!empty($vivaOrder->getError()) || empty($result)) {
                throw new \RuntimeException($vivaOrder->getError() ?? 'Viva order creation failed');
            }

	        $orderCode = (string) $result->orderCode ?? '';

            // Store the orderCode as transaction_id so we can build the redirect URL on visit 1
            $paymentId = $this->payment($order)
                ->pending()
                ->transactionId($orderCode)
                ->save();

            if (!$paymentId) {
                Log::add('Viva: Failed to save payment record for order #' . $order->id, Log::ERROR, 'com_alfa.payments');
                return;
            }

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) $paymentId,
                'action'             => 'order_created',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'viva_order_code'    => $orderCode,
                'viva_transaction_id'=> null,
                'intent'             => $this->isPreauth($order) ? 'authorize' : 'charge',
                'amount'             => $this->resolveAmount($order),
                'currency'           => $currency,
                'note'               => 'Viva Smart Checkout order created.',
                'created_on'         => $now,
                'created_by'         => 0,
            ]);

        } catch (\Exception $e) {
            Log::add('Viva order creation failed for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
        }
    }

    // =========================================================================
    //  WEBHOOK HOOK
    // =========================================================================

    /**
     * Viva webhook — GET ?s=TRANSACTION_ID&eventType=1796&statusId=F
     * Configure in Viva merchant portal → Payment Sources → Webhooks.
     * URL: https://yourshop.gr/index.php?option=com_alfa&task=payment.notify&plugin=viva
     */
    public function onPaymentResponse($event): void
    {
        $input         = Factory::getApplication()->getInput();
        $transactionId = $input->getString('s', '');
        $eventType     = $input->getInt('eventType', 0);
        $statusId      = $input->getString('statusId', '');

        if (empty($transactionId)) {
            Factory::getApplication()->setHeader('status', '400 Bad Request', true);
            return;
        }

        // eventType 1796 = PAYMENT_CREATED, statusId 'F' = Final/successful
        if ($eventType === 1796 && $statusId === 'F') {
            Log::add('Viva webhook PAYMENT_CREATED: ' . $transactionId, Log::INFO, 'com_alfa.payments');
            // Find payment by orderCode or transactionId and confirm as completed
        }

        Factory::getApplication()->setHeader('status', '200 OK', true);
    }

    // =========================================================================
    //  ADMIN ACTIONS
    // =========================================================================

    public function onGetActions($event): void
    {
        $payment = $event->getPayment();
        $status  = $payment->transaction_status ?? 'pending';

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
            'capture'      => $this->handleCapture($event),
            'void'         => $this->handleVoid($event),
            'refund'       => $this->handleRefund($event),
            'view_details' => $this->handleViewDetails($event),
            'view_logs'    => $this->handleViewLogs($event),
            default        => $event->setError(
                Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_UNKNOWN_ACTION', $event->getAction())
            ),
        };
    }

    // =========================================================================
    //  PRIVATE — REDIRECT FLOW
    // =========================================================================

    /**
     * VISIT 1: Read stored orderCode → build Viva checkout URL → redirect.
     */
    private function redirectToViva($event, object $order): void
    {
        try {
            $payment   = $this->getLatestPendingPayment($order->id);
            $orderCode = $payment->transaction_id ?? '';

            if (empty($orderCode)) {
                throw new \RuntimeException('No Viva orderCode found for order #' . $order->id);
            }

            $redirectUrl = VivaUrl::checkoutUrl($orderCode, $this->isTestMode($order));

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) ($payment->id ?? 0),
                'action'             => 'redirect',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'viva_order_code'    => $orderCode,
                'viva_transaction_id'=> null,
                'intent'             => $this->isPreauth($order) ? 'authorize' : 'charge',
                'amount'             => $this->resolveAmount($order),
                'currency'           => $order->id_currency ?? 'EUR',
                'note'               => 'Customer redirected to Viva checkout.',
                'created_on'         => Factory::getDate('now', 'UTC')->toSql(),
                'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setRedirectUrl($redirectUrl);

        } catch (\Exception $e) {
            Log::add('Viva redirect error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
        }
    }

    /**
     * VISIT 2: Customer returned from Viva with ?s=TRANSACTION_ID.
     * Verify the transaction and mark the payment as completed or authorized.
     */
    private function handleVivaReturn($event, object $order, string $transactionId, $input): void
    {
        $now      = Factory::getDate('now', 'UTC')->toSql();
        $statusId = $input->getString('statusId', '');
        $ercCode  = $input->getInt('ercCode', -1);

        // Check for cancellation / failure before hitting the API
        if ($statusId === 'X' || $statusId === 'E' || $statusId === 'C') {
            $event->setLayout('default_order_process_cancelled');
            $event->setLayoutData(['order' => $order, 'statusId' => $statusId]);
            return;
        }

        try {
            $payment = $this->getLatestPendingPayment($order->id);

            // Verify the transaction via the Viva API
	        $trx    = (new VivaTransaction())
		        ->setClientId($this->clientId($order))
		        ->setClientSecret($this->clientSecret($order))
		        ->setTestMode($this->isTestMode($order))
		        ->setTransactionId($transactionId);

            $result = $trx->send();

            if (!empty($trx->getError()) || empty($result)) {
                throw new \RuntimeException($trx->getError() ?? 'Transaction verification failed');
            }

            // statusId 'F' = Final (successful)
            $vivaStatus = $result->statusId ?? $statusId;

            if ($vivaStatus !== 'F') {
                throw new \RuntimeException('Transaction not completed. Viva statusId: ' . $vivaStatus);
            }

            // preauth transactions are AUTHORIZED; regular charges are COMPLETED
            $isPreauth = !empty($result->isPreAuth) || $this->isPreauth($order);
            $intent    = $isPreauth ? 'authorize' : 'charge';

            if ($isPreauth) {
                $this->paymentUpdate((int) $payment->id)
                    ->authorized()
                    ->transactionId($transactionId)
                    ->processedAt($now)
                    ->save();
                $alfaStatus = OrderPaymentHelper::STATUS_AUTHORIZED;
                $note       = 'Viva authorization confirmed. Capture via admin when shipping.';
            } else {
                $this->paymentUpdate((int) $payment->id)
                    ->completed()
                    ->transactionId($transactionId)
                    ->processedAt($now)
                    ->save();
                $alfaStatus = OrderPaymentHelper::STATUS_COMPLETED;
                $note       = 'Viva payment completed.';
            }

            $this->log([
                'id_order'            => (int) $order->id,
                'id_order_payment'    => (int) $payment->id,
                'action'              => $intent,
                'transaction_status'  => $alfaStatus,
                'viva_order_code'     => (string) ($result->orderCode ?? ''),
                'viva_transaction_id' => $transactionId,
                'intent'              => $intent,
                'amount'              => $this->resolveAmount($order),
                'currency'            => $order->id_currency ?? 'EUR',
                'note'                => $note,
                'created_on'          => $now,
                'created_by'          => 0,
            ]);

            $event->setRedirectUrl(
                Route::_('index.php?option=com_alfa&task=checkout.complete&order_id=' . $order->id, false, Route::TLS_FORCE, true)
            );

        } catch (\Exception $e) {
            Log::add('Viva return error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
        }
    }

    // =========================================================================
    //  PRIVATE — ADMIN ACTION HANDLERS
    // =========================================================================

    private function handleCapture($event): void
    {
        $payment = $event->getPayment();
        $order   = $event->getOrder();
        $amount  = $this->getPaymentAmount($payment);
        $now     = Factory::getDate('now', 'UTC')->toSql();
        $txId    = $payment->transaction_id ?? '';

        if (empty($txId)) { $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID')); return; }

	    $capture = (new Capture())
		    ->setClientId($this->clientId($order))->setClientSecret($this->clientSecret($order))
		    ->setTestMode($this->isTestMode($order))->setTransactionId($txId)
		    ->setAmount($this->amountToMinorUnit($amount));

        $result = $capture->send();

        if (!empty($capture->getError()) || empty($result)) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_CAPTURE', $capture->getError() ?? 'Unknown'));
            return;
        }

        $newTxId = $result->transactionId ?? $txId;

        $this->paymentUpdate((int) $payment->id)->completed()->transactionId($newTxId)->processedAt($now)->save();

        $this->log(['id_order' => (int) $order->id, 'id_order_payment' => (int) $payment->id,
            'action' => 'admin_capture', 'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
            'viva_order_code' => null, 'viva_transaction_id' => $newTxId,
            'intent' => 'authorize', 'amount' => $amount, 'currency' => $order->id_currency ?? 'EUR',
            'note' => 'Captured by admin.', 'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_MSG_CAPTURED', $payment->id));
        $event->setRefresh(true);
    }

    private function handleVoid($event): void
    {
        $payment = $event->getPayment();
        $order   = $event->getOrder();
        $amount  = $this->getPaymentAmount($payment);
        $now     = Factory::getDate('now', 'UTC')->toSql();
        $txId    = $payment->transaction_id ?? '';

        if (empty($txId)) { $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID')); return; }

	    $cancel = (new Cancel())
		    ->setClientId($this->clientId($order))->setClientSecret($this->clientSecret($order))
		    ->setTestMode($this->isTestMode($order))->setSourceCode($this->sourceCode($order))
		    ->setTransactionId($txId);
        // No amount → void only

        $result = $cancel->send();

        if (!empty($cancel->getError()) || empty($result)) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_VOID', $cancel->getError() ?? 'Unknown'));
            return;
        }

        $this->paymentUpdate((int) $payment->id)->cancelled()->save();

        $this->log(['id_order' => (int) $order->id, 'id_order_payment' => (int) $payment->id,
            'action' => 'void', 'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
            'viva_order_code' => null, 'viva_transaction_id' => $txId,
            'intent' => 'authorize', 'amount' => $amount, 'currency' => $order->id_currency ?? 'EUR',
            'note' => 'Authorization voided by admin.', 'created_on' => $now,
            'created_by' => (int) Factory::getApplication()->getIdentity()->id]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_MSG_VOIDED', $payment->id));
        $event->setRefresh(true);
    }

    private function handleRefund($event): void
    {
        $payment = $event->getPayment();
        $order   = $event->getOrder();
        $amount  = $this->getPaymentAmount($payment);
        $now     = Factory::getDate('now', 'UTC')->toSql();
        $txId    = $payment->transaction_id ?? '';

        if (empty($txId)) { $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID')); return; }

	    $cancel = (new Cancel())
		    ->setClientId($this->clientId($order))->setClientSecret($this->clientSecret($order))
		    ->setTestMode($this->isTestMode($order))->setSourceCode($this->sourceCode($order))
		    ->setTransactionId($txId)->setAmount($this->amountToMinorUnit($amount));

        $result = $cancel->send();

        if (!empty($cancel->getError()) || empty($result)) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_ERROR_REFUND', $cancel->getError() ?? 'Unknown'));
            return;
        }

        $refundTxId = $result->transactionId ?? $txId;

        $this->paymentUpdate((int) $payment->id)->refunded()->save();

        $this->payment($order)->refund()->amount($amount)->refunded()
            ->refundedPayment((int) $payment->id)->fullRefund()->transactionId($refundTxId)
            ->refundReason(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_LOG_REFUNDED', $payment->id))->save();

        $this->log(['id_order' => (int) $order->id, 'id_order_payment' => (int) $payment->id,
            'action' => 'refund', 'transaction_status' => OrderPaymentHelper::STATUS_REFUNDED,
            'viva_order_code' => null, 'viva_transaction_id' => $refundTxId,
            'intent' => 'charge', 'amount' => $amount, 'currency' => $order->id_currency ?? 'EUR',
            'note' => Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_LOG_REFUNDED', $payment->id),
            'created_on' => $now, 'created_by' => (int) Factory::getApplication()->getIdentity()->id]);

        $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_MSG_REFUNDED', $payment->id));
        $event->setRefresh(true);
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
        $order   = $event->getOrder();
        $event->setLayout('default_order_logs_view');
        $event->setLayoutData(['logData' => $this->loadLogs((int) $order->id, (int) $payment->id) ?? [], 'xml' => $this->getLogsSchema()]);
        $event->setModalTitle(Text::_('COM_ALFA_PAYMENT_LOGS') . ' #' . $payment->id);
    }

    // =========================================================================
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
			->setSuccessUrl($this->buildReturnUrl($order->id))
			->setFailureUrl($this->buildReturnUrl($order->id, 'X'));

        // Customer info — map from Alfa Commerce order fields
        if (!empty($order->customer_email ?? '')) {
            $vivaOrder->setCustomerEmail($order->customer_email);
        }

        $name = trim(($order->billing_firstname ?? '') . ' ' . ($order->billing_lastname ?? ''));
        if (!empty($name)) {
            $vivaOrder->setCustomerFullname($name);
        }

        if (!empty($order->customer_phone ?? '')) {
            $vivaOrder->setCustomerPhone($order->customer_phone);
        }

        return $vivaOrder;
    }

    // =========================================================================
    //  PRIVATE — URL BUILDERS
    // =========================================================================

    /**
     * Build the return URL that Viva will redirect the customer back to.
     * Viva appends ?s=TRANSACTION_ID&eventType=1796&ercCode=0&statusId=F automatically.
     *
     * @param int    $orderId
     * @param string $statusId  Pass 'X' for the failureUrl (Viva will override statusId anyway)
     */
    private function buildReturnUrl(int $orderId, string $statusId = ''): string
    {
        $url = 'index.php?option=com_alfa&task=checkout.process&order_id=' . $orderId;
        if (!empty($statusId)) {
            $url .= '&statusId=' . $statusId;
        }
        return Route::_($url, false, Route::TLS_FORCE, true);
    }

    // =========================================================================
    //  PRIVATE — UTILITIES
    // =========================================================================

    private function amountToMinorUnit(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function resolveAmount(object $order): float
    {
        $total = $order->total_paid_tax_incl ?? $order->total_amount ?? 0;
        if (is_object($total) && method_exists($total, 'getAmount')) {
            return (float) $total->getAmount();
        }
        return (float) $total;
    }

    private function getPaymentAmount(object $payment): float
    {
        $amount = $payment->amount ?? 0;
        if (is_object($amount) && method_exists($amount, 'getAmount')) {
            return (float) $amount->getAmount();
        }
        return (float) $amount;
    }

    private function getLatestPendingPayment(int $orderId): object
    {
        $payments = $this->getPaymentsByOrder($orderId);
        foreach ($payments as $payment) {
            if (($payment->transaction_status ?? '') === OrderPaymentHelper::STATUS_PENDING) {
                return $payment;
            }
        }
        return new \stdClass();
    }
}
