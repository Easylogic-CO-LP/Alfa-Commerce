<?php

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  PAYPAL PAYMENT PLUGIN — Alfa Commerce
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uses the official PayPal PHP Server SDK v2.2.0.
 * SDK Controllers used:
 *   getOrdersController()   → createOrder, getOrder, authorizeOrder, captureOrder
 *   getPaymentsController() → captureAuthorizedPayment, voidPayment, refundCapturedPayment
 *
 * ───────────────────────────────────────────────────────────────────────
 *  FLOW — Redirect (no JS on storefront)
 * ───────────────────────────────────────────────────────────────────────
 *
 *  1. onOrderAfterPlace()          createOrder() → save PayPal order_id → PENDING
 *  2. onOrderProcessView() visit 1 getOrder()    → redirect to approve URL
 *  3. Customer approves on PayPal's page
 *     PayPal appends ?token=PAYPAL_ORDER_ID to your return_url automatically
 *  4. onOrderProcessView() visit 2 captureOrder() or authorizeOrder() → COMPLETED / AUTHORIZED
 *  5. onOrderCompleteView()        Thank-you page
 *
 * ───────────────────────────────────────────────────────────────────────
 *  INTENT: CAPTURE vs AUTHORIZE
 * ───────────────────────────────────────────────────────────────────────
 *  CAPTURE   → funds collected immediately on approval → COMPLETED
 *              Admin: Refund only
 *  AUTHORIZE → funds reserved, collected manually when shipping → AUTHORIZED
 *              Admin: Capture (→ COMPLETED), Void (→ CANCELLED)
 *              After capture: Refund
 *
 * @package    Alfa Commerce
 * @since      1.0.0
 */

namespace Alfa\Plugin\AlfaPayments\PayPal\Extension;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;

// PayPal SDK — loaded by services/provider.php via Composer autoloader
use Joomla\Registry\Registry;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Exceptions\ApiException;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\ItemBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\OrderApplicationContextBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\OrderApplicationContextLandingPage;
use PaypalServerSdkLib\Models\OrderApplicationContextShippingPreference;
use PaypalServerSdkLib\Models\OrderApplicationContextUserAction;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;

final class PayPal extends PaymentsPlugin
{
    // =========================================================================
    //  SDK CLIENT FACTORY
    // =========================================================================

    /**
     * Build a configured PayPal SDK client from plugin params.
     * The SDK handles OAuth2 token acquisition and refresh automatically.
     */
	private function paypal(object $order): PaypalServerSdkClient
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
     * Order process page — the redirect hub.
     *
     * VISIT 1 (no ?token): Build PayPal order → redirect to PayPal approve URL.
     * VISIT 2 (?token set): Customer returned → capture / authorize → complete.
     *
     * Return URL  (set automatically): /index.php?option=com_alfa&task=payment.response
     * Cancel URL  (set automatically): /index.php?option=com_alfa&task=payment.response&paypal_result=cancel
     * Webhook URL (configure in PayPal Developer Dashboard → My Apps → Webhooks):
     *   /index.php?option=com_alfa&task=plugin.trigger&type=alfa-payments&name=paypal&func=notify
     *
     * PayPal appends ?token=ORDER_ID&PayerID=PAYER_ID automatically to your
     * return_url. Alfa Commerce carries the internal order_id in the URL.
     * No setState / getState needed — both IDs arrive in the same request.
     */
    /**
     * Visit 1 only — redirect customer to PayPal's approval page.
     * The return (and cancellation) is handled by onPaymentResponse() via task=payment.response.
     */
    public function onOrderProcessView($event): void
    {
//		echo '<pre>';
//		print_r($event->getSubject());
//		echo '</pre>';
//		exit();

        $this->redirectToPayPal($event, $event->getSubject());
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
     * Create the initial PayPal order immediately after Alfa Commerce order is saved.
     * Stores the PayPal order_id as transaction_id so it survives abandoned checkouts.
     */
	public function onOrderAfterPlace($event): void
	{
		$order = $event->getOrder();

		if (!$order || empty($order->id)) {
			return;
		}

		$now      = Factory::getDate('now', 'UTC')->toSql();
		$intent   = strtoupper($this->params->get('intent', 'CAPTURE'));
		$currency = $order->currency->getCode();
		$amount   = (float) $order->total_paid_tax_incl->getAmount();

		try {
			$orderRequest = OrderRequestBuilder::init(
				$intent === 'AUTHORIZE'
					? CheckoutPaymentIntent::AUTHORIZE
					: CheckoutPaymentIntent::CAPTURE,
				[
					PurchaseUnitRequestBuilder::init(
						AmountWithBreakdownBuilder::init(
							$currency,
							number_format($amount, 2, '.', '')
						)->build()
					)
						->referenceId((string) $order->id)
						->description('Order #' . $order->id)
						->customId((string) $order->id)
						->build(),
				]
			)
				->applicationContext(
					OrderApplicationContextBuilder::init()
						->locale('el-GR')
						->landingPage(OrderApplicationContextLandingPage::LOGIN)
						->shippingPreference(OrderApplicationContextShippingPreference::NO_SHIPPING)
						->userAction(OrderApplicationContextUserAction::PAY_NOW)
						->returnUrl($this->buildReturnUrl($order->id))
						->cancelUrl($this->buildCancelUrl($order->id))
						->build()
				)
				->build();

			$response    = $this->paypal($order)->getOrdersController()->createOrder(['body' => $orderRequest]);
			$paypalOrder = $response->getResult();
			$paypalId    = $paypalOrder->getId();

			if (empty($paypalId)) {
				throw new \RuntimeException('PayPal API returned an empty order ID.');
			}

			$paymentId = $this->payment($order)
				->pending()
				->transactionId($paypalId)
				->save();

			if (!$paymentId) {
				throw new \RuntimeException('Failed to save PayPal payment record for order #' . $order->id);
			}

			$this->log([
				'id_order'           => (int) $order->id,
				'id_order_payment'   => (int) $paymentId,
				'action'             => 'order_created',
				'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
				'paypal_order_id'    => $paypalId,
				'paypal_capture_id'  => null,
				'intent'             => $intent,
				'amount'             => $amount,
				'currency'           => substr(trim((string) $currency), 0, 3),
				'paypal_status'      => 'CREATED',
				'note'               => 'PayPal order created on checkout.',
				'created_on'         => $now,
				'created_by'         => 0,
			]);

		} catch (ApiException $e) {
			Log::add('PayPal createOrder API error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
		} catch (\Exception $e) {
			Log::add('PayPal createOrder failed for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
		}
	}

    // =========================================================================
    //  WEBHOOK HOOK
    // =========================================================================

    /**
    /**
     * Customer return from PayPal's approval page.
     *
     * URL: /index.php?option=com_alfa&task=payment.response
     * (PayPal appends ?token=ORDER_ID&PayerID=PAYER_ID automatically)
     *
     * PayPal appends ?token=ORDER_ID&PayerID=PAYER_ID to the return_url.
     * Cancel: PayPal redirects to cancel_url (no token) — show cancelled layout.
     *
     * Success → capture or authorize → update pending payment record.
     * Cancel  → show cancelled layout, leave payment as pending.
     *
     * Webhooks: use notify() via task=plugin.trigger&func=notify if needed later.
     */
    public function onPaymentResponse($event): void
    {
        $input = Factory::getApplication()->getInput();
        $token = $input->getString('token', '');
        $order = $event->getOrder();

        if ($input->getString('paypal_result', '') === 'cancel' || empty($token)) {
            $event->setLayout('default_order_process_cancelled');
            $event->setLayoutData(['order' => $order]);
            return;
        }

        $this->handlePayPalReturn($event, $order, $token);
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
            default        => $event->setError(
                Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_UNKNOWN_ACTION', $event->getAction())
            ),
        };
    }

    // =========================================================================
    //  PRIVATE — REDIRECT FLOW
    // =========================================================================

    /**
     * VISIT 1: Retrieve the stored PayPal order, find the approve link, redirect.
     */
    private function redirectToPayPal($event, object $order): void
    {
        try {
            $payment  = $this->getLatestPendingPayment($order->id);
            $paypalId = $payment->transaction_id ?? '';

			print_r($paypalId);

            if (empty($paypalId)) {
                throw new \RuntimeException('No PayPal order ID found for order #' . $order->id . '. The order may not have been created.');
            }

            $client      = $this->paypal($order);
            $response    = $client->getOrdersController()->getOrder(['id' => $paypalId]);
            $paypalOrder = $response->getResult();
            $approveUrl  = null;

            foreach ($paypalOrder->getLinks() ?? [] as $link) {
                if ($link->getRel() === 'approve') {
                    $approveUrl = $link->getHref();
                    break;
                }
            }

            if (empty($approveUrl)) {
                throw new \RuntimeException('No approve URL in PayPal order ' . $paypalId . '. Status: ' . $paypalOrder->getStatus());
            }

            $now = Factory::getDate('now', 'UTC')->toSql();
            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) ($payment->id ?? 0),
                'action'             => 'redirect',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'paypal_order_id'    => $paypalId,
                'paypal_capture_id'  => null,
                'intent'             => strtoupper($this->params->get('intent', 'CAPTURE')),
                'amount'             => $order->total_paid_tax_incl->getAmount(),
                'currency'           => $order->currency->getCode(),
                'paypal_status'      => $paypalOrder->getStatus() ?? 'CREATED',
                'note'               => 'Customer redirected to PayPal.',
                'created_on'         => $now,
                'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setRedirectUrl($approveUrl);

        } catch (\Exception $e) {
            Log::add('PayPal redirect failed for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
        }
    }

    /**
     * VISIT 2: Customer returned from PayPal with ?token=PAYPAL_ORDER_ID.
     * Capture or authorize based on the plugin's intent setting.
     */
    private function handlePayPalReturn($event, object $order, string $token): void
    {
        $now    = Factory::getDate('now', 'UTC')->toSql();
        $intent = strtoupper($this->params->get('intent', 'CAPTURE'));
        $client = $this->paypal($order);

        try {
            $payment = $this->getLatestPendingPayment($order->id);

            if ($intent === 'AUTHORIZE') {
                // Reserve funds — admin will capture manually when shipping
                $response    = $client->getOrdersController()->authorizeOrder(['id' => $token, 'body' => null]);
                $authResult  = $response->getResult();

                // Authorization ID is nested: purchaseUnits[0].payments.authorizations[0].getId()
                $authId = $authResult
                    ->getPurchaseUnits()[0]
                    ?->getPayments()
                    ?->getAuthorizations()[0]
                    ?->getId() ?? $token;

                $this->paymentUpdate((int) $payment->id)
                    ->authorized()
                    ->transactionId($authId)
                    ->processedAt($now)
                    ->save();

                $this->log([
                    'id_order'           => (int) $order->id,
                    'id_order_payment'   => (int) $payment->id,
                    'action'             => 'authorized',
                    'transaction_status' => OrderPaymentHelper::STATUS_AUTHORIZED,
                    'paypal_order_id'    => $token,
                    'paypal_capture_id'  => null,
                    'intent'             => $intent,
                    'amount'             => $order->total_paid_tax_incl->getAmount(),
                    'currency'           => $order->currency->getCode(),
                    'paypal_status'      => $authResult->getStatus() ?? 'APPROVED',
                    'note'               => 'PayPal payment authorized. Use Capture admin action when shipping.',
                    'created_on'         => $now,
                    'created_by'         => 0,
                ]);

            } else {
                // CAPTURE — collect funds immediately on return
                $response    = $client->getOrdersController()->captureOrder(['id' => $token, 'body' => null]);
                $captureResult = $response->getResult();

                // Capture ID is nested: purchaseUnits[0].payments.captures[0].getId()
                $capture   = $captureResult->getPurchaseUnits()[0]?->getPayments()?->getCaptures()[0] ?? null;
                $captureId = $capture?->getId() ?? $token;
                $capStatus = $capture?->getStatus() ?? $captureResult->getStatus() ?? 'COMPLETED';

                if (!in_array($capStatus, ['COMPLETED', 'PENDING'], true)) {
                    throw new \RuntimeException('Unexpected PayPal capture status: ' . $capStatus);
                }

                $this->paymentUpdate((int) $payment->id)
                    ->completed()
                    ->transactionId($captureId)
                    ->processedAt($now)
                    ->save();

                $this->log([
                    'id_order'           => (int) $order->id,
                    'id_order_payment'   => (int) $payment->id,
                    'action'             => 'captured',
                    'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
                    'paypal_order_id'    => $token,
                    'paypal_capture_id'  => $captureId,
                    'intent'             => $intent,
                    'amount'             => $order->total_paid_tax_incl->getAmount(),
                    'currency'           => $order->currency->getCode(),
                    'paypal_status'      => $capStatus,
                    'note'               => 'PayPal payment captured. Status: ' . $capStatus,
                    'created_on'         => $now,
                    'created_by'         => 0,
                ]);
            }

            $event->setRedirectUrl(
                Route::_('index.php?option=com_alfa&view=cart&layout=default_order_completed', false, Route::TLS_FORCE, true)
            );

        } catch (ApiException $e) {
            Log::add('PayPal return API error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
        } catch (\Exception $e) {
            Log::add('PayPal return error for order #' . $order->id . ': ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
        }
    }

    // =========================================================================
    //  PRIVATE — ADMIN ACTION HANDLERS
    // =========================================================================

    /**
     * Capture an authorized payment (AUTHORIZE intent).
     * Uses Payments API v2: captureAuthorizedPayment().
     * Transition: authorized → completed
     */
    private function handleCapture($event): void
    {
        $payment = $event->getPayment();
        $order   = $event->getOrder();
        $amount  = $payment->amount->getAmount();
        $now     = Factory::getDate('now', 'UTC')->toSql();
        $authId  = $payment->transaction_id ?? '';

        if (empty($authId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        try {
            $response  = $this->paypal($order)->getPaymentsController()->captureAuthorizedPayment([
                'authorizationId' => $authId,
                'body'            => null, // full amount
            ]);
            $capture   = $response->getResult();
            $captureId = $capture->getId();

            $this->paymentUpdate((int) $payment->id)
                ->completed()
                ->transactionId($captureId)
                ->processedAt($now)
                ->save();

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) $payment->id,
                'action'             => 'admin_capture',
                'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
                'paypal_order_id'    => null,
                'paypal_capture_id'  => $captureId,
                'intent'             => 'AUTHORIZE',
                'amount'             => $amount,
                'currency'           => $order->currency->getCode(),
                'paypal_status'      => $capture->getStatus() ?? 'COMPLETED',
                'note'               => 'Captured by admin.',
                'created_on'         => $now,
                'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_MSG_CAPTURED', $payment->id));
            $event->setRefresh(true);

        } catch (\Exception $e) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_CAPTURE', $e->getMessage()));
        }
    }

    /**
     * Void an authorized payment before capture.
     * Uses Payments API v2: voidPayment().
     * Transition: authorized → cancelled
     */
    private function handleVoid($event): void
    {
        $payment = $event->getPayment();
        $order   = $event->getOrder();
        $amount  = $payment->amount->getAmount();
        $now     = Factory::getDate('now', 'UTC')->toSql();
        $authId  = $payment->transaction_id ?? '';

        if (empty($authId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        try {
            // SDK method: voidPayment (NOT voidAuthorizedPayment)
            $this->paypal($order)->getPaymentsController()->voidPayment([
                'authorizationId' => $authId,
            ]);

            $this->paymentUpdate((int) $payment->id)
                ->cancelled()
                ->save();

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) $payment->id,
                'action'             => 'void',
                'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
                'paypal_order_id'    => null,
                'paypal_capture_id'  => null,
                'intent'             => 'AUTHORIZE',
                'amount'             => $amount,
                'currency'           => $order->currency->getCode(),
                'paypal_status'      => 'VOIDED',
                'note'               => 'Authorization voided by admin.',
                'created_on'         => $now,
                'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_MSG_VOIDED', $payment->id));
            $event->setRefresh(true);

        } catch (\Exception $e) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_VOID', $e->getMessage()));
        }
    }

    /**
     * Refund a captured payment (full).
     * Uses Payments API v2: refundCapturedPayment().
     * Transition: completed → refunded (two-step, identical to Standard plugin pattern)
     */
    private function handleRefund($event): void
    {
        $payment   = $event->getPayment();
        $order     = $event->getOrder();
        $amount    = $payment->amount->getAmount();
        $now       = Factory::getDate('now', 'UTC')->toSql();
        $captureId = $payment->transaction_id ?? '';

        if (empty($captureId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_PAYPAL_ERROR_NO_TRANSACTION_ID'));
            return;
        }

        try {
            $response = $this->paypal($order)->getPaymentsController()->refundCapturedPayment([
                'captureId' => $captureId,
                'body'      => null, // full refund
            ]);
            $refund   = $response->getResult();
            $refundId = $refund->getId();

            // Step 1: Flip original payment to refunded
            $this->paymentUpdate((int) $payment->id)->refunded()->save();

            // Step 2: Create refund audit record (same pattern as Standard plugin)
            $this->payment($order)
                ->refund()
                ->amount($amount)
                ->refunded()
                ->refundedPayment((int) $payment->id)
                ->fullRefund()
                ->transactionId($refundId)
                ->refundReason(Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_LOG_REFUNDED', $payment->id))
                ->save();

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) $payment->id,
                'action'             => 'refund',
                'transaction_status' => OrderPaymentHelper::STATUS_REFUNDED,
                'paypal_order_id'    => null,
                'paypal_capture_id'  => $captureId,
                'intent'             => 'CAPTURE',
                'amount'             => $amount,
                'currency'           => $order->currency->getCode(),
                'paypal_status'      => $refund->getStatus() ?? 'COMPLETED',
                'note'               => Text::sprintf('PLG_ALFA_PAYMENTS_PAYPAL_LOG_REFUNDED', $payment->id),
                'created_on'         => $now,
                'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
            ]);

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
    //  PRIVATE — WEBHOOK HANDLERS
    // =========================================================================

    private function webhookCaptureCompleted(array $resource, string $now): void
    {
        $captureId = $resource['id'] ?? '';
        if (empty($captureId)) {
            return;
        }
        // Find the Alfa payment by capture_id and mark completed if not already
        Log::add('PayPal webhook CAPTURE.COMPLETED: ' . $captureId, Log::INFO, 'com_alfa.payments');
        // Implementation: query #__alfa_order_payments WHERE transaction_id = $captureId
        // and call paymentUpdate()->completed() if status !== completed
    }

    private function webhookCaptureRefunded(array $resource, string $now): void
    {
        $captureId = $resource['id'] ?? '';
        Log::add('PayPal webhook CAPTURE.REFUNDED: ' . $captureId, Log::INFO, 'com_alfa.payments');
    }

    // =========================================================================
    //  PRIVATE — URL BUILDERS
    // =========================================================================

    /**
     * Return URL — PayPal appends ?token=ORDER_ID&PayerID=PAYER_ID automatically.
     * The Alfa Commerce order_id is already in the URL; PayPal adds its params on top.
     */
    private function buildReturnUrl(int $orderId): string
    {
        return Route::_(
            'index.php?option=com_alfa&task=payment.response',
            false, Route::TLS_FORCE, true
        );
    }

    private function buildCancelUrl(int $orderId): string
    {
        return Route::_(
            'index.php?option=com_alfa&task=payment.response' . '&paypal_result=cancel',
            false, Route::TLS_FORCE, true
        );
    }

    // =========================================================================
    //  PRIVATE — DATA BUILDERS
    // =========================================================================

    /**
     * Build PayPal Items array from Alfa Commerce order items.
     * PayPal amounts are decimal strings ("9.99"), NOT minor-unit integers.
     * Adjust field names to match your actual order item object properties.
     */
    private function buildPayPalItems(object $order): array
    {
        $items    = [];
        $currency = $order->currency->getCode();

        foreach ($order->items ?? [] as $item) {
            $unitPrice = (float) ($item->unit_price_tax_excl ?? $item->unit_price ?? 0);
            $items[]   = ItemBuilder::init(
                (string) ($item->product_name ?? $item->name ?? 'Item'),
                MoneyBuilder::init($currency, number_format($unitPrice, 2, '.', ''))->build(),
                (string) ((int) ($item->product_quantity ?? 1))
            )
            ->description((string) ($item->product_reference ?? ''))
            ->sku((string) ($item->product_reference ?? ''))
            ->build();
        }

        return $items;
    }

    // =========================================================================
    //  PRIVATE — UTILITIES
    // =========================================================================

    // Amount helpers removed — Money objects used directly:
    //   PayPal needs decimal strings: number_format($order->total_paid_tax_incl->getAmount(), 2, '.', '')
    //   $order->currency->getCode()           → ISO code
    //   $payment->amount->getAmount()          → float

    /**
     * Get the most recent PENDING payment record for an order.
     * Returns empty stdClass if not found — callers check transaction_id.
     */
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
