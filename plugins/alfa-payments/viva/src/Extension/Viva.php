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
	use Alfa\PhpViva\Transaction\ClassicCancel;
	use Alfa\PhpViva\Transaction\Capture;
	use Joomla\CMS\Factory;
	use Joomla\CMS\Language\Text;
	use Joomla\CMS\Log\Log;
	use Joomla\CMS\Router\Route;

	final class Viva extends PaymentsPlugin
	{
		// =========================================================================
		//  PARAMS HELPERS
		// =========================================================================

		private function getMethodParams(object $order)
		{
			// 1. Παίρνουμε τα params ακριβώς όπως έκανε ο παλιός κώδικας
			$params = $order->selected_payment->params ?? [];

			// 2. Τα μετατρέπουμε σε Joomla Registry για να μπορούμε να χρησιμοποιούμε το ->get()
			if (is_array($params) || is_object($params)) {
				return new \Joomla\Registry\Registry($params);
			} elseif (is_string($params)) {
				return new \Joomla\Registry\Registry(json_decode($params, true));
			}

			return new \Joomla\Registry\Registry();
		}

		// ΣΗΜΑΝΤΙΚΟ: Βεβαιώσου ότι τα 'client_id', 'client_secret' κλπ. είναι τα ΙΔΙΑ
		// ονόματα που έχεις βάλει στο αρχείο .xml του νέου plugin!
		// (Αν έχεις κρατήσει τα παλιά, άλλαξέ τα εδώ σε 'vivapayment_business' κλπ)

		private function isTestMode(object $order): bool    { return $this->getMethodParams($order)->get('mode', 'test') === 'test'; }
		private function clientId(object $order): string    { return $this->getMethodParams($order)->get('client_id', ''); }
		private function clientSecret(object $order): string{ return $this->getMethodParams($order)->get('client_secret', ''); }
		private function sourceCode(object $order): string  { return $this->getMethodParams($order)->get('source_code', ''); }
		private function isPreauth(object $order): bool     { return $this->getMethodParams($order)->get('intent', 'charge') === 'authorize'; }
		private function maxInstallments(object $order): int{ return (int) $this->getMethodParams($order)->get('max_installments', 0); }

		// Classic API credentials — for refund/void via Basic Auth (no portal permission needed)
    	private function merchantId(object $order): string    { return (string) $this->getMethodParams($order)->get('merchant_id', ''); }
		private function apiKey(object $order): string        { return (string) $this->getMethodParams($order)->get('api_key', ''); }
		
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
		/**
		 * Visit 1 only — redirect customer to Viva's hosted checkout page.
		 * The return is handled by onPaymentResponse() via task=payment.response.
		 */
		public function onOrderProcessView($event): void
		{
			$this->redirectToViva($event, $event->getOrder());
		}

		public function onOrderCompleteView($event): void
		{
			// Cart is cleared by HtmlView when default_order_completed loads — see HtmlView patch.
			$event->setLayout('default_order_completed');
			$event->setLayoutData(['order' => $event->getSubject(), 'method' => $event->getMethod()]);
		}

		// =========================================================================
		//  ORDER PLACEMENT HOOK
		// =========================================================================

		/**
		 * Create the Viva Smart Checkout order immediately after Alfa Commerce order is saved.
		 * Stores the orderCode for the redirect on the process page.
		 *
		 * Success URL (set automatically): /index.php?option=com_alfa&task=payment.response
		 * Failure URL (set automatically): /index.php?option=com_alfa&task=payment.response&statusId=X
		 */
		public function onOrderAfterPlace($event): void
		{
			$order = $event->getOrder();

			if (!$order || empty($order->id)) {
				return;
			}

			$now      = Factory::getDate('now', 'UTC')->toSql();
			$amount   = $order->total_paid_tax_incl->getMinorUnits();
			$currency = substr(trim((string) ($order->currency->getCode() ?? 'EUR')), 0, 3);

			try {
				$vivaOrder = $this->buildOrder($order, $amount);
				$result    = $vivaOrder->send();

				if (empty($result->orderCode)) {
					throw new \RuntimeException($vivaOrder->getError() ?? 'Viva API did not return an orderCode');
				}

				$orderCode = (string) $result->orderCode;

				$paymentObj = $this->payment($order)->pending()->transactionId($orderCode);
				$paymentId = $paymentObj->save();

				if (!$paymentId) {
					$db = Factory::getContainer()->get('DatabaseDriver');
					$query = $db->getQuery(true);

					$methodId = $order->id_payment_method ?? $this->getMethod()->id ?? 3;

					$query->insert($db->quoteName('#__alfa_order_payments'))
						->columns([
							$db->quoteName('id_order'),
							$db->quoteName('id_payment_method'),
							$db->quoteName('payment_type'),
							$db->quoteName('transaction_status'),
							$db->quoteName('transaction_id'),
							$db->quoteName('added')
						])
						->values(implode(',', [
							(int) $order->id,
							(int) $methodId,
							$db->quote('payment'),
							$db->quote('pending'),
							$db->quote($orderCode),
							$db->quote($now)
						]));

					$db->setQuery($query)->execute();
					$paymentId = $db->insertid();
				}

				if (!$paymentId) {
					throw new \RuntimeException('Database Insert completely failed. Payment ID is zero.');
				}

				$this->log([
					'id_order'           => (int) $order->id,
					'id_order_payment'   => (int) $paymentId,
					'action'             => 'order_created',
					'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
					'viva_order_code'    => $orderCode,
					'viva_transaction_id'=> null,
					'intent'             => $this->isPreauth($order) ? 'authorize' : 'charge',
					'amount'             => $order->total_paid_tax_incl->getAmount(),
					'currency'           => $currency,
					'note'               => 'Viva order created.',
					'created_on'         => $now,
					'created_by'         => 0,
				]);

			} catch (\Exception $e) {
				Factory::getApplication()->enqueueMessage('VIVA PAYMENT DATABASE ERROR: ' . $e->getMessage(), 'error');
				Log::add('Viva Critical Error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');

				throw $e;
			}
		}

		// =========================================================================
		//  PAYMENT RESPONSE HOOK — customer return from Viva checkout
		// =========================================================================

		/**
		 * Customer return from Viva's hosted checkout page.
		 *
		 * Viva appends ?s=TRANSACTION_ID&eventType=1796&ercCode=0&statusId=F
		 * to the successUrl. statusId=F = success, X/E/C = cancelled/failed.
		 *
		 * Success → verify via API → update pending payment to completed/authorized.
		 * Failure → show cancelled layout, leave payment as pending (no record added).
		 *
		 * Webhooks: add notify() via task=plugin.trigger&func=notify later if needed.
		 */
		public function onPaymentResponse($event): void
		{
			$input         = Factory::getApplication()->getInput();
			$transactionId = $input->getString('t', '');
			$order         = $event->getOrder();

			if (empty($transactionId)) {
				$event->setLayout('default_order_process_cancelled');
				$event->setLayoutData(['order' => $order]);
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
		 *
		 * Webhook URL (configure in Viva portal → Payment Sources → Webhooks):
		 *   /index.php?option=com_alfa&task=plugin.trigger&type=alfa-payments&name=viva&func=notify
		 */
		private function redirectToViva($event, object $order): void
		{
			try {
				$payment = $this->getLatestPendingPayment($order->id);

				if (!isset($payment->id) || empty($payment->id)) {
					// die('Error: No pending payment found for order ID: ' . $order->id);
					throw new \RuntimeException('No pending payment record found in database.');
				}

				$orderCode = $payment->viva_order_code ?? $payment->transaction_id ?? '';

				if (empty($orderCode)) {
					throw new \RuntimeException('Viva orderCode is missing from payment record #' . $payment->id);
				}

				$redirectUrl = VivaUrl::checkoutUrl($orderCode, $this->isTestMode($order));

				$this->log([
					'id_order'           => (int) $order->id,
					'id_order_payment'   => (int) $payment->id,
					'action'             => 'redirect',
					'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
					'viva_order_code'    => $orderCode,
					'note'               => 'Redirecting to Viva with code: ' . $orderCode,
					'created_on'         => Factory::getDate('now', 'UTC')->toSql(),
					'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
				]);

				$event->setRedirectUrl($redirectUrl);

			} catch (\Exception $e) {
				Log::add('Viva Redirect Error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
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
					'amount'              => $order->total_paid_tax_incl->getAmount(),
					'currency'            => substr(trim((string) ($order->currency->getCode() ?? 'EUR')), 0, 3),
					'note'                => $note,
					'created_on'          => $now,
					'created_by'          => 0,
				]);

				$event->setRedirectUrl(
					Route::_('index.php?option=com_alfa&view=cart&layout=default_order_completed', false, Route::TLS_FORCE, true)
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
			$amount  = $payment->amount->getAmount();
			$now     = Factory::getDate('now', 'UTC')->toSql();
			$txId    = $payment->transaction_id ?? '';

			if (empty($txId)) { $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID')); return; }

			$capture = (new Capture())
				->setClientId($this->clientId($order))->setClientSecret($this->clientSecret($order))
				->setTestMode($this->isTestMode($order))->setTransactionId($txId)
				->setAmount($payment->amount->getMinorUnits());

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
			            'intent' => 'authorize', 'amount' => $amount, 'currency' => substr(trim((string) ($order->currency->getCode() ?? 'EUR')), 0, 3),
			            'note' => 'Captured by admin.', 'created_on' => $now,
			            'created_by' => (int) Factory::getApplication()->getIdentity()->id]);

			$event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_VIVA_MSG_CAPTURED', $payment->id));
			$event->setRefresh(true);
		}

		private function handleVoid($event): void
    {
        $payment = $event->getPayment();
        $order   = $event->getOrder();
        $amount  = $payment->amount->getAmount();
        $now     = Factory::getDate('now', 'UTC')->toSql();
        $txId    = $payment->transaction_id ?? '';

        if (empty($txId)) { $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID')); return; }

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
        $order   = $event->getOrder();
        $amount  = $payment->amount->getAmount();
        $now     = Factory::getDate('now', 'UTC')->toSql();
        $txId    = $payment->transaction_id ?? '';

        if (empty($txId)) { $event->setError(Text::_('PLG_ALFA_PAYMENTS_VIVA_ERROR_NO_TRANSACTION_ID')); return; }

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
			$url = 'index.php?option=com_alfa&task=payment.response';
			if (!empty($statusId)) {
				$url .= '&statusId=' . $statusId;
			}
			return Route::_($url, false, Route::TLS_FORCE, true);
		}

		// =========================================================================
		//  PRIVATE — UTILITIES
		// =========================================================================

		// Amount helpers removed — Money objects used directly:
		//   $order->total_paid_tax_incl->getMinorUnits() → int (minor units, currency-aware)
		//   $order->total_paid_tax_incl->getAmount()     → float (major units)
		//   $order->currency->getCode()                  → string ISO code
		//   $payment->amount->getMinorUnits()             → int
		//   $payment->amount->getAmount()                 → float

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
