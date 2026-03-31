<?php
	namespace Alfa\Plugin\AlfaPayments\Paypal\Extension;

	defined('_JEXEC') or die;

	use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
	use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
	use Joomla\CMS\Factory;
	use Joomla\CMS\Language\Text;
	use Joomla\CMS\Log\Log;
	use Joomla\CMS\Router\Route;
	use Joomla\CMS\Uri\Uri;
	use Joomla\Registry\Registry;

	use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
	use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
	use PaypalServerSdkLib\Environment;

	final class Paypal extends PaymentsPlugin
	{
		private function paypalClient(object $order)
		{
			$rawParams = $order->selected_payment->params ?? '{}';

			$params = new Registry($rawParams);

			$clientId = $params->get('api_username', '');
			$secret = $params->get('api_password', '');
			$mode = $params->get('mode', 'sandbox');

			$environment = ($mode === 'live') ? Environment::PRODUCTION : Environment::SANDBOX;

			return PaypalServerSdkClientBuilder::init()
				->clientCredentialsAuthCredentials(
					ClientCredentialsAuthCredentialsBuilder::init($clientId, $secret)
				)
				->environment($environment)
				->build();
		}

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

		public function onOrderProcessView($event): void
		{
			$order = $event->getSubject();
			$input = Factory::getApplication()->getInput();
			$token = $input->getString('token', '');

			// ── VISIT 2: Customer returning from PayPal ───────────────────────────
			if (!empty($token)) {
				$this->handlePayPalReturn($event, $order, $token);
				return;
			}

			// ── VISIT 1: First arrival — redirect customer to PayPal ──────────────
			$this->redirectToPayPal($event, $order);
		}

		public function onOrderCompleteView($event): void
		{
			$event->setLayout('default_order_completed');
			$event->setLayoutData(['order' => $event->getSubject(), 'method' => $event->getMethod()]);
		}

		// =========================================================================
		//  ORDER PLACEMENT HOOK
		// =========================================================================

		public function onOrderAfterPlace($event): void
		{
			$order = $event->getOrder();

			if (!$order || empty($order->id)) {
				return;
			}

			$now = Factory::getDate('now', 'UTC')->toSql();

			try {
				$paymentId = $this->payment($order)
					->pending()
					->save();

				if (!$paymentId) {
					Log::add('PayPal: Failed to create payment record for order #' . $order->id, Log::ERROR, 'com_alfa.payments');
					return;
				}

				$this->log([
					'id_order'           => (int) $order->id,
					'id_order_payment'   => (int) $paymentId,
					'action'             => 'record_created',
					'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
					'amount'             => $this->resolveAmount($order),
					'currency'           => $order->id_currency ?? '',
					'note'               => 'Pending payment record created on order placement.',
					'created_on'         => $now,
					'created_by'         => 0,
				]);

			} catch (\Exception $e) {
				Log::add('PayPal: Initial setup failed for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
			}
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
				$event->add('capture', Text::_('PLG_ALFA_PAYMENTS_PAYPAL_CAPTURE'))
					->icon('checkmark')->css('btn-success')
					->confirm(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_CONFIRM_CAPTURE'))
					->priority(200);

				$event->add('void', Text::_('PLG_ALFA_PAYMENTS_PAYPAL_VOID'))
					->icon('cancel')->css('btn-outline-danger')
					->confirm(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_CONFIRM_VOID'))
					->priority(50);
			}

			if ($status === OrderPaymentHelper::STATUS_COMPLETED) {
				$event->add('refund', Text::_('PLG_ALFA_PAYMENTS_PAYPAL_REFUND'))
					->icon('undo-2')->css('btn-warning')
					->confirm(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_CONFIRM_REFUND'))
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
				default        => $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_UNKNOWN_ACTION', $event->getAction())),
			};
		}

		// =========================================================================
		//  PRIVATE — PAYPAL REDIRECT LOGIC
		// =========================================================================

		private function redirectToPayPal($event, object $order): void
		{
			try {
				$client = $this->paypalClient($order);
				$payment = $this->getLatestPendingPayment($order->id);

				// Build base URL for return / cancel
				$baseUrl = Uri::current();
				$currentVars = Uri::getInstance()->getQuery(true);
				unset($currentVars['token']);
				$processUrl = $baseUrl . '?' . http_build_query($currentVars);

				$returnUrl = $processUrl;
				$cancelUrl = $processUrl . '&paypal_cancel=1';

				// Create PayPal Order Request
				$payload = [
					'intent' => 'AUTHORIZE',
					'purchase_units' => [
						[
							'reference_id' => (string) $order->id,
							'amount' => [
								'currency_code' => $order->currency_code ?? $order->currency_iso ?? 'EUR',
								'value'         => $this->formatAmount($this->resolveAmount($order)),
							],
						]
					],
					'application_context' => [
						'return_url'          => $returnUrl,
						'cancel_url'          => $cancelUrl,
						'user_action'         => 'PAY_NOW',
						'shipping_preference' => 'NO_SHIPPING'
					]
				];

				// Execute API Call
				$ordersController = $client->getOrdersController();
				$apiResponse = $ordersController->createOrder(['body' => $payload]);

				$paypalOrder = json_decode(json_encode($apiResponse->getResult()), true);
				$paypalOrderId = $paypalOrder['id'] ?? '';

				$this->paymentUpdate((int) $payment->id)
					->transactionId($paypalOrderId)
					->save();

				$approveLink = null;
				$links = $paypalOrder['links'] ?? [];

				foreach ($links as $link) {
					if (($link['rel'] ?? '') === 'approve') {
						$approveLink = $link['href'] ?? '';
						break;
					}
				}

				if (!$approveLink) {
					throw new \Exception('No approve link found in PayPal response.');
				}

				$event->setRedirectUrl($approveLink);

			} catch (\Exception $e) {
				Log::add('PayPal Redirect failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
				$event->setLayout('default_order_process_error');
				$event->setLayoutData(['error' => Text::_('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_SESSION'), 'order' => $order]);
			}
		}

		private function handlePayPalReturn($event, object $order, string $token): void
		{
			$input = Factory::getApplication()->getInput();
			$now   = Factory::getDate('now', 'UTC')->toSql();

			if ($input->getInt('paypal_cancel', 0) === 1) {
				$event->setLayout('default_order_process_cancelled');
				$event->setLayoutData(['order' => $order]);
				return;
			}

			try {
				$client = $this->paypalClient($order);
				$payment = $this->getLatestPendingPayment($order->id);

				$ordersController = $client->getOrdersController();
				$apiResponse = $ordersController->authorizeOrder(['id' => $token]);
				$authResult = json_decode(json_encode($apiResponse->getResult()), true);

				$authId = null;
				$purchaseUnits = $authResult['purchase_units'] ?? [];

				if (!empty($purchaseUnits)) {
					$payments = $purchaseUnits[0]['payments'] ?? [];
					$authorizations = $payments['authorizations'] ?? [];

					if (!empty($authorizations)) {
						$authId = $authorizations[0]['id'] ?? '';
					}
				}

				if (!$authId) {
					throw new \Exception('Payment approved but no Authorization ID returned.');
				}

				$this->paymentUpdate((int) $payment->id)
					->authorized()
					->transactionId($authId)
					->processedAt($now)
					->save();

				$completedUrl = Route::_(
					'index.php?option=com_alfa&view=cart&layout=default_order_completed&order_id=' . $order->id,
					false, Route::TLS_FORCE, true
				);
				$event->setRedirectUrl($completedUrl);

			} catch (\Exception $e) {
				Log::add('PayPal Return failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
				$event->setLayout('default_order_process_error');
				$event->setLayoutData(['error' => Text::_('PLG_ALFA_PAYMENTS_PAYPAL_PAYMENT_FAILED'), 'order' => $order]);
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
			$authId = $payment->transaction_id ?? '';

			if (empty($authId)) {
				$event->setError(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_NO_ORDER_ID'));
				return;
			}

			try {
				$client = $this->paypalClient($order);
				$paymentsController = $client->getPaymentsController();

				// Capture the previously authorized payment
				$apiResponse = $paymentsController->captureAuthorizedPayment([
					'authorizationId' => $authId
				]);

				$captureResult = json_decode(json_encode($apiResponse->getResult()), true);

				$captureId = $captureResult['id'] ?? '';

				if (empty($captureId)) {
					throw new \Exception('Capture successful but no Capture ID returned.');
				}

				$this->paymentUpdate((int) $payment->id)
					->completed()
					->transactionId($captureId)
					->processedAt($now)
					->save();

				$event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_MSG_CAPTURED', $payment->id));
				$event->setRefresh(true);

			} catch (\Exception $e) {
				$event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_CAPTURE', $e->getMessage()));
			}
		}

		private function handleVoid($event): void
		{
			$payment = $event->getPayment();
			$authId  = $payment->transaction_id ?? '';

			if (empty($authId)) {
				$event->setError(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_NO_ORDER_ID'));
				return;
			}

			try {
				$client = $this->paypalClient($event->getOrder());
				$paymentsController = $client->getPaymentsController();

				// Void the authorization
				$paymentsController->voidPayment([
					'authorizationId' => $authId,
				]);

				$this->paymentUpdate((int) $payment->id)
					->cancelled()
					->save();

				$event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_MSG_VOIDED', $payment->id));
				$event->setRefresh(true);

			} catch (\Exception $e) {
				$event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_VOID', $e->getMessage()));
			}
		}

		private function handleRefund($event): void
		{
			$payment   = $event->getPayment();
			$order     = $event->getOrder();
			$captureId = $payment->transaction_id ?? '';

			if (empty($captureId)) {
				$event->setError(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_NO_ORDER_ID'));
				return;
			}

			try {
				$client = $this->paypalClient($order);
				$paymentsController = $client->getPaymentsController();

				// Refund the captured payment
				$paymentsController->refundCapturedPayment([
					'captureId' => $captureId,
				]);

				// Flip original payment to refunded
				$this->paymentUpdate((int) $payment->id)
					->refunded()
					->save();

				// Create refund audit record
				$this->payment($order)
					->refund()
					->amount($this->getPaymentAmount($payment))
					->refunded()
					->refundedPayment((int) $payment->id)
					->fullRefund()
					->transactionId($captureId . '_refund')
					->refundReason(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_LOG_REFUNDED', $payment->id))
					->save();

				$event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_MSG_REFUNDED', $payment->id));
				$event->setRefresh(true);

			} catch (\Exception $e) {
				$event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_REFUND', $e->getMessage()));
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
			$order   = $event->getOrder();
			$logData = $this->loadLogs((int) $order->id, (int) $payment->id);
			$event->setLayout('default_order_logs_view');
			$event->setLayoutData(['logData' => $logData ?? [], 'xml' => $this->getLogsSchema()]);
			$event->setModalTitle(Text::_('COM_ALFA_PAYMENT_LOGS') . ' #' . $payment->id);
		}

		// =========================================================================
		//  PRIVATE — UTILITIES
		// =========================================================================

		private function formatAmount(float $amount): string
		{
			return number_format($amount, 2, '.', '');
		}

		private function getPaymentAmount(object $payment): float
		{
			$amount = $payment->amount ?? 0;
			return (is_object($amount) && method_exists($amount, 'getAmount')) ? (float) $amount->getAmount() : (float) $amount;
		}

		private function resolveAmount(object $order): float
		{
			$total = $order->total_paid_tax_incl ?? $order->total_amount ?? 0;
			return (is_object($total) && method_exists($total, 'getAmount')) ? (float) $total->getAmount() : (float) $total;
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