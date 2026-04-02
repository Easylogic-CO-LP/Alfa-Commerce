<?php

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  KLARNA PAYMENT PLUGIN — Alfa Commerce
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Gateway plugin using Klarna Hosted Payment Page (HPP) — 100% server-side.
 * No JavaScript required on your storefront. Klarna hosts the payment UI.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  FLOW (HPP redirect — zero JS)
 * ───────────────────────────────────────────────────────────────────────
 *
 *  1. onOrderAfterPlace()      Customer places order in Alfa Commerce.
 *                              → Create Klarna Payments session.
 *                              → Save KP session_id as transaction_id.
 *                              → Create payment record as PENDING.
 *
 *  2. onOrderProcessView()     Alfa Commerce redirects customer to process page.
 *     [first visit]            → Create HPP session (gets Klarna-hosted URL).
 *                              → $event->setRedirectUrl($hpp->redirectUrl).
 *                              Customer lands on Klarna's own payment page.
 *                              Klarna handles authentication + payment UI.
 *
 *  3. Klarna redirects         Customer pays on Klarna's page.
 *     back to process URL      URL contains ?sid={klarna_hpp_session_id}
 *     with ?sid=               injected automatically by Klarna.
 *
 *  4. onOrderProcessView()     Same hook fires again on return.
 *     [return visit            Detects $_GET['sid'] is present.
 *      with ?sid=...]          → getHppSession($sid) → verify COMPLETED.
 *                              → createOrderFromAuthorizationToken().
 *                              → acknowledgeOrder() — REQUIRED.
 *                              → paymentUpdate()->completed()->save().
 *                              → $event->setRedirectUrl($completedUrl).
 *
 *  5. onOrderCompleteView()    Thank-you / confirmation page.
 *
 *  Separately:
 *  6. onPaymentResponse()      Klarna pushes a webhook to notification URL.
 *                              Re-acknowledge (idempotent — safe to call twice).
 *
 * ───────────────────────────────────────────────────────────────────────
 *  STATE / SESSION — do you need setState / getState?
 * ───────────────────────────────────────────────────────────────────────
 *
 *  NO. Alfa Commerce already tracks the order_id through the checkout
 *  URL (e.g. /checkout/process?order_id=123). Klarna automatically
 *  appends ?sid={session.id} to whatever success URL you provide, so
 *  on return we get both the Alfa order_id (from the URL Alfa built)
 *  and the Klarna HPP session_id (appended by Klarna).
 *
 *  Nothing extra needs to be stored in session/state compared to any
 *  other gateway plugin you have already built.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  ADMIN ACTIONS
 * ───────────────────────────────────────────────────────────────────────
 *
 *  AUTHORIZED  → Capture (ship goods) → COMPLETED
 *  AUTHORIZED  → Cancel (void)        → CANCELLED
 *  COMPLETED   → Refund               → REFUNDED
 *
 *  Klarna does NOT capture automatically — you must call createCapture()
 *  when the goods are shipped. The plugin exposes a "Capture" admin button.
 *
 * @package    Alfa Commerce
 * @since      1.0.0
 */

namespace Alfa\Plugin\AlfaPayments\Klarna\Extension;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Alfa\PhpKlarna\Exceptions\FailedActionException;
use Alfa\PhpKlarna\Exceptions\NotFoundException;
use Alfa\PhpKlarna\Exceptions\ValidationException;
use Alfa\PhpKlarna\PhpKlarna;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

final class Klarna extends PaymentsPlugin
{
    // =========================================================================
    //  SDK FACTORY
    // =========================================================================

    /**
     * Build and return a configured PhpKlarna client from plugin params.
     *
     * Uses Joomla\CMS\Http\HttpFactory (no Guzzle) and
     * Joomla\CMS\Date\Date (no Carbon) — both ship with every Joomla install.
     */
	private function klarna(object $order): PhpKlarna
	{
		$params = $order->selected_payment->params;

		return new PhpKlarna(
			username: $params['api_username'] ?? '',
			password: $params['api_password'] ?? '',
			region:   $params['region'] ?? 'EU',
			mode:     $params['mode'] ?? 'test'
		);
	}

    // =========================================================================
    //  FRONTEND HOOKS
    // =========================================================================

    /**
     * Product page — show Klarna promotional messaging (e.g. "Pay in 3").
     */
    public function onItemView($event): void
    {
        $event->setLayout('default_item_view');
        $event->setLayoutData([
            'method' => $event->getMethod(),
            'item'   => $event->getSubject(),
        ]);
    }

    /**
     * Cart / checkout page — show Klarna as a payment option.
     */
    public function onCartView($event): void
    {
        $event->setLayout('default_cart_view');
        $event->setLayoutData([
            'method' => $event->getMethod(),
            'item'   => $event->getSubject(),
        ]);
    }

    /**
     * Order process page — the core of the HPP integration.
     *
     * This hook fires TWICE for Klarna HPP orders:
     *
     *   VISIT 1 (first time, no ?sid): Build HPP session → redirect customer to Klarna.
     *   VISIT 2 (customer returns with ?sid): Verify payment → create Klarna order → complete.
     *
     * Alfa Commerce already carries the internal order_id in the URL.
     * Klarna automatically appends &sid={hpp_session_id} to our success URL.
     * No setState/getState needed — everything is in the URL.
     */
    /**
     * Visit 1 only — create Klarna HPP session and redirect.
     * The return is handled by onPaymentResponse() via task=payment.response.
     */
    public function onOrderProcessView($event): void
    {
        $this->redirectToKlarna($event, $event->getSubject());
    }

    /**
     * Order completion / thank-you page.
     */
    public function onOrderCompleteView($event): void
    {
        // Cart is cleared by HtmlView when default_order_completed loads — see HtmlView patch.
        $event->setLayout('default_order_completed');
        $event->setLayoutData([
            'order'  => $event->getSubject(),
            'method' => $event->getMethod(),
        ]);
    }

    // =========================================================================
    //  ORDER PLACEMENT HOOK
    // =========================================================================

    /**
     * Called by Alfa Commerce immediately after the order is committed to DB.
     *
     * We create a Klarna Payments session HERE (not in onOrderProcessView)
     * so we have the session_id available to store against the payment record.
     * This also validates the order data with Klarna before the customer ever
     * lands on the process page — if Klarna rejects the session we know early.
     *
     * Payment record: PENDING + transactionId = Klarna KP session_id
     * The HPP session itself is created later in onOrderProcessView (visit 1).
     *
     * Success URL    (set automatically): /index.php?option=com_alfa&task=payment.response
     * Cancel URL     (set automatically): /index.php?option=com_alfa&task=payment.response&klarna_result=cancel
     * Failure URL    (set automatically): /index.php?option=com_alfa&task=payment.response&klarna_result=failure
     * Notification URL (set automatically): /index.php?option=com_alfa&task=plugin.trigger&type=alfa-payments&name=klarna&func=notify&order_id={id}
     */
    public function onOrderAfterPlace($event): void
    {
        $order = $event->getOrder();

        if (!$order || empty($order->id)) {
            return;
        }

        $now = Factory::getDate('now', 'UTC')->toSql();

        try {
            $klarna  = $this->klarna($order);
            $session = $klarna->createSession($this->buildSessionData($order));

            // Store KP session_id as the transaction_id on the payment record.
            // The HPP session_id will be stored in the plugin log when created.
            $paymentId = $this->payment($order)
                ->pending()
                ->transactionId($session->sessionId)
                ->save();

            if (!$paymentId) {
                Log::add(
                    'Klarna: Failed to create payment record for order #' . $order->id,
                    Log::ERROR,
                    'com_alfa.payments'
                );

                return;
            }

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => $paymentId,
                'action'             => 'session_created',
                'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
                'klarna_session_id'  => $session->sessionId,
                'klarna_order_id'    => null,
                'hpp_session_id'     => null,
                'amount'             => $order->total_paid_tax_incl->getAmount(),
                'currency'           => $order->currency->getCode(),
                'klarna_event'       => 'kp_session_created',
                'note'               => 'Klarna Payments session created on order placement.',
                'created_on'         => $now,
                'created_by'         => 0,
            ]);

        } catch (ValidationException $e) {
            Log::add(
                'Klarna: Session validation failed for order #' . $order->id . ': ' . print_r($e->getErrors(), true),
                Log::ERROR,
                'com_alfa.payments'
            );
        } catch (\Exception $e) {
            Log::add(
                'Klarna: Session creation failed for order #' . $order->id . ': ' . $e->getMessage(),
                Log::ERROR,
                'com_alfa.payments'
            );
        }
    }

    // =========================================================================
    //  WEBHOOK HOOK
    // =========================================================================

    /**
     * Handle Klarna's push notification webhook.
     *
     * Klarna POSTs to the notification URL after payment completion.
     * We re-acknowledge the order here — acknowledgeOrder() is idempotent,
     * calling it twice is safe and ensures the order is never auto-cancelled.
     *
     * Webhooks: use notify() via task=plugin.trigger&func=notify if needed later.
     */
    public function onPaymentResponse($event): void
    {
        $input = Factory::getApplication()->getInput();
        $sid   = $input->getString('sid', '');
        $order = $event->getOrder();

        if (empty($sid)) {
            $event->setLayout('default_order_process_cancelled');
            $event->setLayoutData(['order' => $order]);
            return;
        }

        $this->handleKlarnaReturn($event, $order, $sid);
    }

    // =========================================================================
    //  ADMIN ACTIONS
    // =========================================================================

    /**
     * Register admin action buttons based on current payment status.
     *
     * Klarna payment lifecycle:
     *   pending    → (waiting for HPP)
     *   authorized → Capture or Cancel (void)
     *   completed  → Refund
     *   cancelled  → (terminal)
     *   refunded   → (terminal)
     */
    public function onGetActions($event): void
    {
        $payment = $event->getPayment();
        $status  = $payment->transaction_status ?? 'pending';

        // Always available — view details and logs
        $event->add('view_details', Text::_('COM_ALFA_VIEW_DETAILS'))
            ->icon('eye')->css('btn-outline-secondary')
            ->modal('action_view_details', Text::_('COM_ALFA_PAYMENT_DETAILS') . ' #' . $payment->id)
            ->priority(10);

        $event->add('view_logs', Text::_('COM_ALFA_VIEW_LOGS'))
            ->icon('list')->css('btn-outline-info')
            ->modal('default_order_logs_view', Text::_('COM_ALFA_PAYMENT_LOGS') . ' #' . $payment->id, 'lg')
            ->priority(5);

        // AUTHORIZED — can Capture or Cancel (void)
        if ($status === OrderPaymentHelper::STATUS_AUTHORIZED) {
            $event->add('capture', Text::_('PLG_ALFA_PAYMENTS_KLARNA_CAPTURE'))
                ->icon('checkmark')->css('btn-success')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_KLARNA_CONFIRM_CAPTURE'))
                ->priority(200);

            $event->add('void', Text::_('PLG_ALFA_PAYMENTS_KLARNA_VOID'))
                ->icon('cancel')->css('btn-outline-danger')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_KLARNA_CONFIRM_VOID'))
                ->priority(50);
        }

        // COMPLETED — can Refund
        if ($status === OrderPaymentHelper::STATUS_COMPLETED) {
            $event->add('refund', Text::_('PLG_ALFA_PAYMENTS_KLARNA_REFUND'))
                ->icon('undo-2')->css('btn-warning')
                ->confirm(Text::_('PLG_ALFA_PAYMENTS_KLARNA_CONFIRM_REFUND'))
                ->priority(150);
        }
    }

    /**
     * Route admin action button clicks to the correct handler.
     */
    public function onExecuteAction($event): void
    {
        match ($event->getAction()) {
            'capture'      => $this->handleCapture($event),
            'void'         => $this->handleVoid($event),
            'refund'       => $this->handleRefund($event),
            'view_details' => $this->handleViewDetails($event),
            'view_logs'    => $this->handleViewLogs($event),
            default        => $event->setError(
                Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_ERROR_UNKNOWN_ACTION', $event->getAction())
            ),
        };
    }

    // =========================================================================
    //  PRIVATE — HPP REDIRECT LOGIC
    // =========================================================================

    /**
     * VISIT 1: Build an HPP session and redirect the customer to Klarna.
     *
     * The process URL already contains the Alfa Commerce order_id.
     * We use the current request URL as the base for success/cancel/etc.,
     * and Klarna appends &sid={session.id} automatically.
     */
	private function redirectToKlarna($event, object $order): void
	{
		try {
			$klarna  = $this->klarna($order);

			$payment   = $this->getLatestPendingPayment($order->id);
			$kpSession = $payment->transaction_id ?? '';

			if (empty($kpSession)) {
				$session   = $klarna->createSession($this->buildSessionData($order));
				$kpSession = $session->sessionId;
			}

			$returnUrl = Route::_(
				'index.php?option=com_alfa&task=payment.response',
				false, Route::TLS_FORCE, true
			);

			$hpp = $klarna->createHppSession(
				paymentSessionId: $kpSession,
				urls: [
					'success'      => $returnUrl . '&klarna_result=success&authorization_token={{authorization_token}}&sid={{session_id}}',
					'cancel'       => $returnUrl . '&klarna_result=cancel&sid={{session_id}}',
					'failure'      => $returnUrl . '&klarna_result=failure&authorization_token={{authorization_token}}&sid={{session_id}}',
					'back'         => $returnUrl . '&klarna_result=back&sid={{session_id}}',

					'notification' => Route::_(
						'index.php?option=com_alfa&task=payment.notify&plugin=klarna'
						. '&order_id=' . $order->id
						. '&sid={{session_id}}',
						false,
						Route::TLS_FORCE,
						true
					),
				]
			);

			// Log the HPP session creation
			$now = Factory::getDate('now', 'UTC')->toSql();
			$this->log([
				'id_order'           => (int) $order->id,
				'id_order_payment'   => (int) ($payment->id ?? 0),
				'action'             => 'hpp_session_created',
				'transaction_status' => OrderPaymentHelper::STATUS_PENDING,
				'klarna_session_id'  => $kpSession,
				'klarna_order_id'    => null,
				'hpp_session_id'     => $hpp->sessionId,
				'amount'             => $order->total_paid_tax_incl->getAmount(),
				'currency'           => $order->id_currency ?? '',
				'klarna_event'       => 'hpp_redirect',
				'note'               => 'Customer redirected to Klarna HPP.',
				'created_on'         => $now,
				'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
			]);

			$event->setRedirectUrl($hpp->redirectUrl);

		} catch (ValidationException $e) {
			Log::add('Klarna HPP: Validation failed: ' . print_r($e->getErrors(), true), Log::ERROR, 'com_alfa.payments');
			$event->setLayout('default_order_process_error');
			$event->setLayoutData(['error' => Text::_('PLG_ALFA_PAYMENTS_KLARNA_ERROR_SESSION'), 'order' => $order]);

		} catch (\Exception $e) {
			Log::add('Klarna HPP: Redirect failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
			$event->setLayout('default_order_process_error');
			$event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
		}
	}

    /**
     * VISIT 2: Customer has returned from Klarna's page with ?sid=.
     *
     * Verify the HPP session, create the Klarna order, acknowledge it,
     * and mark the Alfa Commerce payment as completed.
     *
     * No setState/getState needed:
     *   - order_id  → already in the URL (managed by Alfa Commerce)
     *   - sid       → appended by Klarna to our success URL automatically
     */
    private function handleKlarnaReturn($event, object $order, string $sid): void
    {
        $now    = Factory::getDate('now', 'UTC')->toSql();
        $result = Factory::getApplication()->getInput()->getString('klarna_result', '');

        // Customer clicked Cancel or Back on Klarna's page
        if (in_array($result, ['cancel', 'back'], true)) {
            $event->setLayout('default_order_process_cancelled');
            $event->setLayoutData(['order' => $order]);

            return;
        }

        // Customer's payment was rejected / failed
        if ($result === 'failure') {
            $event->setLayout('default_order_process_error');
            $event->setLayoutData([
                'error' => Text::_('PLG_ALFA_PAYMENTS_KLARNA_PAYMENT_FAILED'),
                'order' => $order,
            ]);

            return;
        }

        try {
            $klarna  = $this->klarna($order);
            $payment = $this->getLatestPendingPayment($order->id);

            // 1. Verify the HPP session is actually completed
            $hpp = $klarna->getHppSession($sid);

            if ($hpp->status !== 'COMPLETED') {
                // Not paid yet — show appropriate page
                $event->setLayout('default_order_process_error');
                $event->setLayoutData([
                    'error' => Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_HPP_STATUS', $hpp->status),
                    'order' => $order,
                ]);

                return;
            }

            // 2. Create the Klarna order using the authorization token
            $klarnaOrder = $klarna->createOrderFromAuthorizationToken(
                $hpp->authorizationToken,
                $this->buildOrderData($order)
            );

            // 3. Acknowledge immediately — REQUIRED, Klarna auto-cancels after 14 days
            $klarna->acknowledgeOrder($klarnaOrder->orderId);

            // 4. Mark Alfa Commerce payment as authorized
            //    (not completed — Klarna requires explicit capture when goods ship)
            $this->paymentUpdate((int) $payment->id)
                ->authorized()
                ->transactionId($klarnaOrder->orderId)
                ->processedAt($now)
                ->save();

            // 5. Write plugin log
            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) $payment->id,
                'action'             => 'payment_authorized',
                'transaction_status' => OrderPaymentHelper::STATUS_AUTHORIZED,
                'klarna_session_id'  => $payment->transaction_id ?? '',
                'klarna_order_id'    => $klarnaOrder->orderId,
                'hpp_session_id'     => $sid,
                'amount'             => $order->total_paid_tax_incl->getAmount(),
                'currency'           => $order->currency->getCode(),
                'klarna_event'       => 'order_created_acknowledged',
                'note'               => 'Klarna order created and acknowledged. Fraud: ' . $klarnaOrder->fraudStatus,
                'created_on'         => $now,
                'created_by'         => 0,
            ]);

            // 6. Redirect to Alfa Commerce order completion page
            $event->setRedirectUrl(Route::_(
                'index.php?option=com_alfa&view=cart&layout=default_order_completed',
                false, Route::TLS_FORCE, true
            ));

        } catch (NotFoundException $e) {
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => 'Klarna session not found.', 'order' => $order]);

        } catch (\Exception $e) {
            Log::add('Klarna return: ' . $e->getMessage(), Log::ERROR, 'com_alfa.payments');
            $event->setLayout('default_order_process_error');
            $event->setLayoutData(['error' => $e->getMessage(), 'order' => $order]);
        }
    }

    // =========================================================================
    //  PRIVATE — ADMIN ACTION HANDLERS
    // =========================================================================

    /**
     * Capture — ship the goods and collect the payment from Klarna.
     *
     * Transition: authorized → completed
     * Calls Klarna Order Management API createCapture().
     * Only call this when goods are physically shipped.
     */
	private function handleCapture($event): void
	{
		$payment = $event->getPayment();
		$order   = $event->getOrder();
		$amount  = $payment->amount->getAmount();
		$now     = Factory::getDate('now', 'UTC')->toSql();

		$klarnaOrderId = $payment->transaction_id ?? '';

		if (empty($klarnaOrderId)) {
			$event->setError(Text::_('PLG_ALFA_PAYMENTS_KLARNA_ERROR_NO_ORDER_ID'));

			return;
		}

		try {
			$this->klarna($order)->createCapture($klarnaOrderId, [
				'captured_amount' => $payment->amount->getMinorUnits(),
				'description'     => 'Capture for Alfa Commerce order #' . $order->id,
				'order_lines'     => $this->buildOrderLines($order),
			]);

			$this->paymentUpdate((int) $payment->id)
				->completed()
				->processedAt($now)
				->save();

			$this->log([
				'id_order'           => (int) $order->id,
				'id_order_payment'   => (int) $payment->id,
				'action'             => 'capture',
				'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
				'klarna_session_id'  => null,
				'klarna_order_id'    => $klarnaOrderId,
				'hpp_session_id'     => null,
				'amount'             => $amount,
				'currency'           => $order->id_currency ?? '',
				'klarna_event'       => 'capture_created',
				'note'               => 'Payment captured by admin.',
				'created_on'         => $now,
				'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
			]);

			$event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_MSG_CAPTURED', $payment->id));
			$event->setRefresh(true);

		} catch (\Exception $e) {
			$event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_ERROR_CAPTURE', $e->getMessage()));
		}
	}

    /**
     * Void — cancel an authorized (not yet captured) Klarna order.
     *
     * Transition: authorized → cancelled
     * Calls Klarna Order Management API cancelOrder().
     */
    private function handleVoid($event): void
    {
        $payment       = $event->getPayment();
        $order         = $event->getOrder();
        $amount        = $payment->amount->getAmount();
        $now           = Factory::getDate('now', 'UTC')->toSql();
        $klarnaOrderId = $payment->transaction_id ?? '';

        if (empty($klarnaOrderId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_KLARNA_ERROR_NO_ORDER_ID'));

            return;
        }

        try {
            $this->klarna($order)->cancelOrder($klarnaOrderId);

            $this->paymentUpdate((int) $payment->id)
                ->cancelled()
                ->save();

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) $payment->id,
                'action'             => 'void',
                'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
                'klarna_session_id'  => null,
                'klarna_order_id'    => $klarnaOrderId,
                'hpp_session_id'     => null,
                'amount'             => $amount,
                'currency'           => $order->currency->getCode(),
                'klarna_event'       => 'order_cancelled',
                'note'               => 'Order voided by admin before capture.',
                'created_on'         => $now,
                'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_MSG_VOIDED', $payment->id));
            $event->setRefresh(true);

        } catch (\Exception $e) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_ERROR_VOID', $e->getMessage()));
        }
    }

    /**
     * Refund a captured Klarna payment.
     *
     * Transition: completed → refunded (two-step like Standard plugin)
     * Step 1: Call Klarna createRefund() API
     * Step 2: paymentUpdate()->refunded() (flip original)
     * Step 3: payment()->refund()->...->save() (create audit record)
     */
    private function handleRefund($event): void
    {
        $payment       = $event->getPayment();
        $order         = $event->getOrder();
        $amount        = $payment->amount->getAmount();
        $now           = Factory::getDate('now', 'UTC')->toSql();
        $klarnaOrderId = $payment->transaction_id ?? '';

        if (empty($klarnaOrderId)) {
            $event->setError(Text::_('PLG_ALFA_PAYMENTS_KLARNA_ERROR_NO_ORDER_ID'));

            return;
        }

        try {
            // Step 1: Call Klarna refund API
            $this->klarna($order)->createRefund($klarnaOrderId, [
                'refunded_amount' => $payment->amount->getMinorUnits(),
                'description'     => 'Refund for Alfa Commerce order #' . $order->id,
                'order_lines'     => $this->buildOrderLines($order),
            ]);

            // Step 2: Flip original payment to refunded (same as Standard plugin)
            $this->paymentUpdate((int) $payment->id)
                ->refunded()
                ->save();

            // Step 3: Create refund audit record (same as Standard plugin)
            $this->payment($order)
                ->refund()
                ->amount($amount)
                ->refunded()
                ->refundedPayment((int) $payment->id)
                ->fullRefund()
                ->transactionId($klarnaOrderId . '_refund')
                ->refundReason(Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_LOG_REFUNDED', $payment->id))
                ->save();

            $this->log([
                'id_order'           => (int) $order->id,
                'id_order_payment'   => (int) $payment->id,
                'action'             => 'refund',
                'transaction_status' => OrderPaymentHelper::STATUS_REFUNDED,
                'klarna_session_id'  => null,
                'klarna_order_id'    => $klarnaOrderId,
                'hpp_session_id'     => null,
                'amount'             => $amount,
                'currency'           => $order->currency->getCode(),
                'klarna_event'       => 'refund_created',
                'note'               => Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_LOG_REFUNDED', $payment->id),
                'created_on'         => $now,
                'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
            ]);

            $event->setMessage(Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_MSG_REFUNDED', $payment->id));
            $event->setRefresh(true);

        } catch (\Exception $e) {
            $event->setError(Text::sprintf('PLG_ALFA_PAYMENTS_KLARNA_ERROR_REFUND', $e->getMessage()));
        }
    }

    /**
     * View payment details in a modal (same pattern as Standard plugin).
     */
    private function handleViewDetails($event): void
    {
        $payment = $event->getPayment();
        $event->setLayout('action_view_details');
        $event->setLayoutData(['payment' => $payment, 'order' => $event->getOrder()]);
        $event->setModalTitle(Text::_('COM_ALFA_PAYMENT_DETAILS') . ' #' . $payment->id);
    }

    /**
     * View plugin logs in a modal (same pattern as Standard plugin).
     */
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
    //  PRIVATE — DATA BUILDERS
    // =========================================================================

    /**
     * Build the Klarna Payments session payload from an Alfa Commerce order.
     * Adjust field mapping to match your Alfa Commerce order object structure.
     */
    private function buildSessionData(object $order): array
    {
        return [
            'purchase_country'  => $order->billing_country_code ?? 'GR',
            'purchase_currency' => $order->currency->getCode(),
            'locale'            => $order->language ?? 'el-GR',
            'order_amount'      => $order->total_paid_tax_incl->getMinorUnits(),
            'order_tax_amount'  => ($order->total_tax instanceof \Alfa\Component\Alfa\Site\Service\Pricing\Money ? $order->total_tax->getMinorUnits() : 0),
            'order_lines'       => $this->buildOrderLines($order),
        ];
    }

    /**
     * Build the Klarna order creation payload (used in createOrderFromAuthorizationToken).
     * Includes merchant URLs for Klarna's confirmation / push.
     */
    private function buildOrderData(object $order): array
    {
        return array_merge($this->buildSessionData($order), [
            'merchant_reference1' => (string) $order->id,
            'merchant_urls'       => [
                'confirmation' => Route::_(
                    'index.php?option=com_alfa&view=cart&layout=default_order_completed',
                    false, Route::TLS_FORCE, true
                ),
                'notification' => Route::_(
                    'index.php?option=com_alfa&task=plugin.trigger&type=alfa-payments&name=klarna&func=notify&order_id=' . $order->id,
                    false, Route::TLS_FORCE, true
                ),
            ],
        ]);
    }

    /**
     * Build Klarna order_lines array from Alfa Commerce order items.
     * Adjust field names to match your actual order item object.
     */
	private function buildOrderLines(object $order): array
	{
		$lines = [];

		foreach ($order->items ?? [] as $item) {
			$rawUnitPrice  = $item->unit_price_tax_incl ?? 0;
			$rawTotalPrice = $item->total_price_tax_incl ?? 0;
			$rawTaxAmount  = $item->tax_amount ?? 0;

			$unitPrice = ($rawUnitPrice instanceof \Alfa\Component\Alfa\Site\Service\Pricing\Money)
				? $rawUnitPrice->getMinorUnits()
				: (int) round((float) $rawUnitPrice * 100);

			$totalPrice = ($rawTotalPrice instanceof \Alfa\Component\Alfa\Site\Service\Pricing\Money)
				? $rawTotalPrice->getMinorUnits()
				: (int) round((float) $rawTotalPrice * 100);

			$taxAmount = ($rawTaxAmount instanceof \Alfa\Component\Alfa\Site\Service\Pricing\Money)
				? $rawTaxAmount->getMinorUnits()
				: (int) round((float) $rawTaxAmount * 100);

			$taxRate = (int) (((float) ($item->tax_rate ?? 0)) * 100); // π.χ. 24.00 -> 2400

			$lines[] = [
				'type'             => 'physical',
				'reference'        => (string) ($item->product_reference ?? $item->id_product ?? ''),
				'name'             => (string) ($item->product_name ?? $item->name ?? 'Item'),
				'quantity'         => (int) ($item->product_quantity ?? 1),
				'unit_price'       => $unitPrice,
				'tax_rate'         => $taxRate,
				'total_amount'     => $totalPrice,
				'total_tax_amount' => $taxAmount,
			];
		}

		$rawShipping = $order->total_shipping_tax_incl ?? 0;

		$shippingAmount = ($rawShipping instanceof \Alfa\Component\Alfa\Site\Service\Pricing\Money)
			? $rawShipping->getMinorUnits()
			: (int) round((float) $rawShipping * 100);

		if ($shippingAmount > 0) {
			$lines[] = [
				'type'             => 'shipping_fee',
				'name'             => 'Shipping',
				'quantity'         => 1,
				'unit_price'       => $shippingAmount,
				'tax_rate'         => 2400,
				'total_amount'     => $shippingAmount,
				'total_tax_amount' => 0,
			];
		}

		return $lines;
	}

    // =========================================================================
    //  PRIVATE — UTILITIES
    // =========================================================================

    /**
     * Convert a decimal amount to Klarna's minor unit (integer cents).
     * €99.00 → 9900,  $1.50 → 150
     */
    // Amount helpers removed — Money objects used directly:
    //   $order->total_paid_tax_incl->getMinorUnits()   → int (minor units, currency-aware via Currency::getDecimalPlaces())
    //   $order->total_paid_tax_incl->getAmount()        → float (major units)
    //   $order->total_products_tax_excl->getAmount()    → float (tax excl)
    //   $order->total_tax->getAmount()                  → float (tax amount)
    //   $order->currency->getCode()                     → string ISO code
    //   $payment->amount->getMinorUnits()               → int
    //   $payment->amount->getAmount()                   → float

    /**
     * Get the most recent pending payment for an order.
     * Returns a minimal stdClass with at least ->id and ->transaction_id.
     */
    private function getLatestPendingPayment(int $orderId): object
    {
        $payments = $this->getPaymentsByOrder($orderId);

        foreach ($payments as $payment) {
            if (($payment->transaction_status ?? '') === OrderPaymentHelper::STATUS_PENDING) {
                return $payment;
            }
        }

        // Fallback: return empty object — callers check for empty transaction_id
        return new \stdClass();
    }
}
