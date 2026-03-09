<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  STANDARD PAYMENT PLUGIN — Reference Implementation
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This file is the REFERENCE IMPLEMENTATION for all Alfa payment plugins.
 * Copy this file as a starting point when building a new plugin (Stripe,
 * PayPal, Viva Wallet, etc.). Every method, constant, and pattern used
 * here is explained in detail.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  WHAT THIS PLUGIN DOES
 * ───────────────────────────────────────────────────────────────────────
 *
 * The Standard plugin handles offline payments: bank transfer, cash on
 * delivery, cheque, manual invoice. There is no external gateway API —
 * the admin manually manages the payment lifecycle through action buttons.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  PLUGIN ANATOMY — FILES & STRUCTURE
 * ───────────────────────────────────────────────────────────────────────
 *
 * plugins/alfa-payments/standard/
 * ├── src/Extension/Standard.php       ← THIS FILE (main plugin class)
 * ├── params/
 * │   └── logs.xml                     ← Plugin-specific log table schema
 * ├── tmpl/                            ← Layout templates
 * │   ├── default_item_view.php        ← Product page (payment info)
 * │   ├── default_cart_view.php        ← Cart page (method selector)
 * │   ├── default_order_process.php    ← Order processing (redirect/wait)
 * │   ├── default_order_completed.php  ← Thank-you page
 * │   ├── action_view_details.php      ← Admin: payment details modal
 * │   └── default_order_logs_view.php  ← Admin: plugin logs modal
 * ├── language/en-GB/
 * │   └── plg_alfapayments_standard.ini
 * └── services/provider.php            ← Joomla DI service provider
 *
 * ───────────────────────────────────────────────────────────────────────
 *  INHERITANCE CHAIN
 * ───────────────────────────────────────────────────────────────────────
 *
 * Standard extends PaymentsPlugin extends Plugin extends CMSPlugin
 *
 *   CMSPlugin (Joomla)
 *     └─ Plugin (Alfa base)
 *          - Logging system: log(), loadLogs(), deleteLog(), getLogsSchema()
 *          - Auto-creates plugin-specific log table from logs.xml
 *          - Abstract hooks: onCartView(), onItemView()
 *
 *        └─ PaymentsPlugin (Alfa payments base)
 *              - Fluent builder wrappers: payment(), paymentUpdate()
 *              - Read/delete wrappers: getPayment(), deletePayment()
 *              - Sets logIdentifierField = 'id_order_payment'
 *              - Abstract hooks: onOrderProcessView(), onOrderCompleteView()
 *              - Empty action hooks: onGetActions(), onExecuteAction()
 *
 *           └─ Standard (THIS — concrete implementation)
 *                 - Implements all abstract hooks
 *                 - Registers admin action buttons (Mark Paid, Cancel, Refund)
 *                 - Handles action execution with fluent builder
 *                 - Writes structured plugin logs
 *
 * ───────────────────────────────────────────────────────────────────────
 *  PAYMENT LIFECYCLE — State Machine
 * ───────────────────────────────────────────────────────────────────────
 *
 *   ┌──────────┐    Mark as Paid    ┌───────────┐    Refund     ┌──────────┐
 *   │ PENDING  │ ─────────────────→ │ COMPLETED │ ────────────→ │ REFUNDED │
 *   └──────────┘                    └───────────┘               └──────────┘
 *        │
 *        │ Cancel
 *        ▼
 *   ┌───────────┐
 *   │ CANCELLED │
 *   └───────────┘
 *
 * Valid transitions for Standard (offline):
 *   pending   → completed  (handleMarkPaid)
 *   pending   → cancelled  (handleCancel)
 *   completed → refunded   (handleRefund)
 *
 * Gateway plugins may have additional transitions:
 *   pending   → authorized → completed → refunded
 *   pending   → failed
 *   authorized → cancelled (void before capture)
 *
 * ───────────────────────────────────────────────────────────────────────
 *  REFUND STRATEGY — Two-Step Process
 * ───────────────────────────────────────────────────────────────────────
 *
 * Step 1: paymentUpdate($id)->refunded()->save()
 *         Flips the original payment's transaction_status to "refunded".
 *         Effect: drops from total_paid_real calculation, hides Refund button.
 *
 * Step 2: payment($order)->refund()->amount($amt)->refunded()
 *             ->refundedPayment($originalId)->fullRefund()
 *             ->refundReason('...')->save()
 *         Creates a NEW record (payment_type = 'refund') for the audit trail.
 *         This record is NEVER counted in total_paid_real.
 *         It exists purely for accounting: who refunded, when, how much,
 *         and which original payment it relates to.
 *
 * For gateway plugins (Stripe, PayPal), the refund also includes:
 *   ->transactionId($refundTxnId)        — gateway refund reference
 *   ->gatewayResponse(json_encode($resp)) — raw API response for debugging
 *   ->partialRefund()                     — if only part of the amount
 *
 * ───────────────────────────────────────────────────────────────────────
 *  AMOUNT RESOLUTION — How the payment amount is determined
 * ───────────────────────────────────────────────────────────────────────
 *
 * When creating a payment with the fluent builder, the amount is
 * auto-resolved if you don't call ->amount($x) explicitly:
 *
 *   Priority 1: $order->total_paid_tax_incl (Money object — always correct)
 *     - During checkout: set by OrderPlaceHelper from CartHelper
 *     - During admin/API: computed by OrderModel::getItem() from DB
 *
 *   Priority 2: OrderTotalHelper::getOrderTotal() (recomputes from DB)
 *     - Defensive fallback if total_paid_tax_incl is missing
 *
 *   Priority 3: ->amount($x) manual override (always wins when called)
 *     - Use for: partial payments, split payments, specific refund amounts
 *
 * In this Standard plugin, onOrderAfterPlace() does NOT call ->amount()
 * because the auto-resolution handles it correctly.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  FLUENT BUILDER API — Quick Reference
 * ───────────────────────────────────────────────────────────────────────
 *
 * CREATE (new payment record):
 *   $id = $this->payment($order)->pending()->save();
 *   $id = $this->payment($order)->authorized()->transactionId('ch_3M..')->save();
 *   $id = $this->payment($order)->refund()->amount(50)->completed()->save();
 *
 * UPDATE (modify existing):
 *   $this->paymentUpdate($id)->completed()->processedAt($now)->save();
 *   $this->paymentUpdate($id)->cancelled()->save();
 *   $this->paymentUpdate($id)->refunded()->save();
 *
 * READ (no builder needed):
 *   $payment  = $this->getPayment($id);         // with method params
 *   $payments = $this->getPaymentsByOrder($orderId);  // lightweight list
 *
 * DELETE (no builder needed):
 *   $this->deletePayment($id, $orderId);
 *
 * ───────────────────────────────────────────────────────────────────────
 *  BUILDER SETTERS — Full List
 * ───────────────────────────────────────────────────────────────────────
 *
 * Status:   ->pending(), ->authorized(), ->completed(),
 *           ->failed(), ->cancelled(), ->refunded()
 *
 * Type:     ->refund(), ->authorization()
 *           (default is 'payment' — no setter needed)
 *
 * Amount:   ->amount(float), ->itemsOnly()
 *
 * Gateway:  ->transactionId(string), ->gatewayResponse(string),
 *           ->processedAt(string)
 *
 * Refund:   ->refundedPayment(int), ->fullRefund(), ->partialRefund(),
 *           ->refundType(string), ->refundReason(string)
 *
 * Other:    ->note(string), ->method(int), ->set(key, value)
 *
 * Terminal: ->save() → int|false (payment ID or false)
 * Debug:    ->toArray() → array (preview without saving)
 *
 * ───────────────────────────────────────────────────────────────────────
 *  LOGGING SYSTEM — Plugin-Specific Logs
 * ───────────────────────────────────────────────────────────────────────
 *
 * Each plugin has its own log table, defined by params/logs.xml:
 *   #__alfa_payments_standard_logs   (this plugin)
 *   #__alfa_payments_stripe_logs     (Stripe plugin)
 *
 * The table is AUTO-CREATED on first use. Mandatory columns (created
 * by Plugin.php, NOT declared in logs.xml):
 *   id                int AUTO_INCREMENT PRIMARY KEY
 *   id_order          int NOT NULL
 *   id_order_payment  int DEFAULT NULL  (from logIdentifierField)
 *
 * Plugin-specific columns (from logs.xml):
 *   action, transaction_status, amount, order_total, currency,
 *   transaction_id, refund_type, note, created_on, created_by
 *
 * Writing logs:
 *   $logId = $this->log([
 *       'id_order'           => $order->id,
 *       'id_order_payment'   => $payment->id,
 *       'action'             => 'mark_paid',
 *       'transaction_status' => 'completed',
 *       ...
 *   ]);
 *
 * Reading logs:
 *   $logs = $this->loadLogs($orderId);                     // all for order
 *   $logs = $this->loadLogs($orderId, $paymentId);         // for specific payment
 *   $logs = $this->loadLogs($orderId, 0, ['action' => 'refund']); // filtered
 *
 * Log schema (for view templates):
 *   $xml = $this->getLogsSchema(); // parsed logs.xml SimpleXMLElement
 *
 * NOTE: Plugin logs are SEPARATE from the unified order activity log
 * (order_activity_log table). The activity log is written automatically
 * by OrderPaymentHelper::create/update/delete — you don't need to
 * write to it from your plugin. Plugin logs are for gateway-specific
 * debugging data that doesn't belong in the main activity timeline.
 *
 * ───────────────────────────────────────────────────────────────────────
 *  ADMIN ACTIONS — Button Registration + Execution
 * ───────────────────────────────────────────────────────────────────────
 *
 * Action buttons appear in the order edit view next to each payment.
 * Two hooks control them:
 *
 * 1. onGetActions($event) — REGISTER buttons based on current status.
 *    Called when rendering the payments list. Use the fluent API:
 *
 *      $event->add('action_name', 'Button Label')
 *          ->icon('icon-name')       // Joomla icon class (without 'icon-')
 *          ->css('btn-success')      // Bootstrap button class
 *          ->confirm('Are you sure?') // Optional confirmation dialog
 *          ->modal('layout_name', 'Modal Title') // OR: open in modal
 *          ->priority(200);          // Higher = more prominent
 *
 * 2. onExecuteAction($event) — HANDLE button click.
 *    Called when admin clicks a button. Route via match():
 *
 *      match ($event->getAction()) {
 *          'mark_paid' => $this->handleMarkPaid($event),
 *          'refund'    => $this->handleRefund($event),
 *          default     => $event->setError('Unknown action'),
 *      };
 *
 *    Available on $event:
 *      $event->getPayment()  → payment record (with Money objects)
 *      $event->getOrder()    → full order object
 *      $event->getAction()   → action name string
 *      $event->setMessage()  → success message for admin
 *      $event->setError()    → error message
 *      $event->setRefresh()  → reload the page after action
 *      $event->setLayout()   → render a template (for modal actions)
 *      $event->setLayoutData() → data for the template
 *
 * ───────────────────────────────────────────────────────────────────────
 *  CREATING YOUR OWN PLUGIN — Step by Step
 * ───────────────────────────────────────────────────────────────────────
 *
 * 1. Copy the entire plugins/alfa-payments/standard/ directory
 * 2. Rename to plugins/alfa-payments/yourname/
 * 3. Update namespace: Joomla\Plugin\AlfaPayments\YourName\Extension
 * 4. Rename class: final class YourName extends PaymentsPlugin
 * 5. Update services/provider.php with new class reference
 * 6. Update language files and XML manifest
 *
 * 7. Implement hooks:
 *    REQUIRED:
 *      onItemView()          — product page display
 *      onCartView()          — cart page display
 *      onOrderProcessView()  — after checkout (redirect to gateway here)
 *      onOrderCompleteView() — thank-you page
 *      onOrderAfterPlace()   — create initial payment record
 *
 *    RECOMMENDED:
 *      onGetActions()        — register admin buttons
 *      onExecuteAction()     — handle admin button clicks
 *
 *    OPTIONAL:
 *      onPaymentResponse()   — handle gateway webhook/callback
 *
 * 8. For gateway plugins, the key differences from Standard:
 *    - onOrderAfterPlace(): create as ->authorized() instead of ->pending()
 *    - onOrderProcessView(): redirect customer to gateway payment page
 *    - Add a webhook handler for onPaymentResponse()
 *    - handleCapture(): ->completed()->transactionId($id)->processedAt($now)
 *    - handleRefund(): add ->transactionId() and ->gatewayResponse()
 *    - logs.xml: add gateway-specific columns (e.g. stripe_event_id)
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Standard
 * @version     4.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Path: plugins/alfa-payments/standard/src/Extension/Standard.php
 *
 * @since  3.0.0
 */

namespace Joomla\Plugin\AlfaPayments\Standard\Extension;

use Alfa\Component\Alfa\Administrator\Plugin\PaymentsPlugin;
use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

final class Standard extends PaymentsPlugin
{
	// =========================================================================
	//
	//  ███████╗██████╗  ██████╗ ███╗   ██╗████████╗███████╗███╗   ██╗██████╗
	//  ██╔════╝██╔══██╗██╔═══██╗████╗  ██║╚══██╔══╝██╔════╝████╗  ██║██╔══██╗
	//  █████╗  ██████╔╝██║   ██║██╔██╗ ██║   ██║   █████╗  ██╔██╗ ██║██║  ██║
	//  ██╔══╝  ██╔══██╗██║   ██║██║╚██╗██║   ██║   ██╔══╝  ██║╚██╗██║██║  ██║
	//  ██║     ██║  ██║╚██████╔╝██║ ╚████║   ██║   ███████╗██║ ╚████║██████╔╝
	//  ╚═╝     ╚═╝  ╚═╝ ╚═════╝ ╚═╝  ╚═══╝   ╚═╝   ╚══════╝╚═╝  ╚═══╝╚═════╝
	//
	//  FRONTEND HOOKS — Customer-Facing Pages
	//
	//  These render HTML on the storefront. Each hook receives an event
	//  with the relevant context (product, cart, order) and the payment
	//  method record. You MUST call setLayout() — even for an empty layout.
	//
	//  For gateway plugins: onOrderProcessView() is where you redirect
	//  the customer to the external payment page (Stripe Checkout,
	//  PayPal redirect, etc.).
	//
	// =========================================================================

	/**
	 * Product page — show payment-related info (e.g. "Pay in installments").
	 *
	 * Available on $event:
	 *   $event->getSubject()  → Product item object
	 *   $event->getMethod()   → Payment method record (id, name, params...)
	 *   $event->setLayout()   → Template file in tmpl/ (without .php)
	 *   $event->setLayoutData() → Associative array passed to template
	 *
	 * For Standard: shows basic payment info text.
	 * For Stripe: could show "Pay in 4 installments" badge.
	 * For PayPal: could show "PayPal accepted" logo.
	 *
	 * @param   object  $event  ItemViewEvent
	 * @return  void
	 * @since   3.0.0
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
	 * Cart page — show payment method selector or payment form.
	 *
	 * Available on $event:
	 *   $event->getSubject()  → Cart object (items, totals, user info)
	 *   $event->getMethod()   → Payment method record
	 *
	 * For Standard: shows method name and description.
	 * For Stripe: could render the Stripe Elements card form.
	 * For PayPal: could render the PayPal Smart Buttons.
	 *
	 * @param   object  $event  CartViewEvent
	 * @return  void
	 * @since   3.0.0
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
	 * Order processing page — after checkout, before completion.
	 *
	 * This is the CRITICAL hook for gateway plugins. The order and payment
	 * record already exist in the database at this point.
	 *
	 * For Standard (offline): show "Your order has been placed, please
	 *   transfer funds to bank account XXXX" message.
	 *
	 * For Stripe: redirect to Stripe Checkout Session URL.
	 * For PayPal: redirect to PayPal approval URL.
	 *
	 * To redirect:
	 *   $this->getApplication()->redirect($gatewayUrl);
	 *
	 * Available on $event:
	 *   $event->getSubject()  → Order object (with ->id, ->payments, etc.)
	 *   $event->getMethod()   → Payment method record
	 *
	 * @param   object  $event  OrderProcessViewEvent
	 * @return  void
	 * @since   3.0.0
	 */
	public function onOrderProcessView($event): void
	{
		$event->setLayout('default_order_process');
		$event->setLayoutData([
			'order'  => $event->getSubject(),
			'method' => $event->getMethod(),
		]);
	}

	/**
	 * Order completion page — thank-you / confirmation.
	 *
	 * Shown after successful payment (or after order placement for offline).
	 * For gateway plugins: the customer returns here after paying on the
	 * external gateway site.
	 *
	 * Available on $event:
	 *   $event->getSubject()  → Order object
	 *   $event->getMethod()   → Payment method record
	 *
	 * @param   object  $event  OrderCompleteViewEvent
	 * @return  void
	 * @since   3.0.0
	 */
	public function onOrderCompleteView($event): void
	{
		$event->setLayout('default_order_completed');
		$event->setLayoutData([
			'order'  => $event->getSubject(),
			'method' => $event->getMethod(),
		]);
	}

	// =========================================================================
	//
	//  ██████╗ ██████╗ ██████╗ ███████╗██████╗
	//  ██╔═══██╗██╔══██╗██╔══██╗██╔════╝██╔══██╗
	//  ██║   ██║██████╔╝██║  ██║█████╗  ██████╔╝
	//  ██║   ██║██╔══██╗██║  ██║██╔══╝  ██╔══██╗
	//  ╚██████╔╝██║  ██║██████╔╝███████╗██║  ██║
	//   ╚═════╝ ╚═╝  ╚═╝╚═════╝ ╚══════╝╚═╝  ╚═╝
	//
	//  ORDER PLACEMENT HOOK
	//
	//  Called by OrderPlaceHelper::triggerAfterPlaceEvents() AFTER the
	//  order, items, and shipment are committed to the database.
	//  The order object is fully loaded at this point.
	//
	//  This is where you create the INITIAL payment record.
	//
	// =========================================================================

	/**
	 * Create the initial payment record when an order is placed.
	 *
	 * WHEN: Called once per order, right after checkout completes.
	 * WHO:  Triggered by the frontend OrderPlaceHelper.
	 * WHAT: Creates a single payment record in #__alfa_order_payments.
	 *
	 * The fluent builder auto-resolves the payment amount from
	 * $order->total_paid_tax_incl — no manual calculation needed.
	 *
	 * Status choices by plugin type:
	 *   Standard (offline):  ->pending()    — admin confirms later
	 *   Stripe (pre-auth):   ->authorized() — funds reserved, capture later
	 *   PayPal (redirect):   ->pending()    — webhook confirms later
	 *
	 * For gateway plugins that need a transaction ID from the API call:
	 *   $paymentId = $this->payment($order)
	 *       ->pending()
	 *       ->transactionId($stripeSessionId)
	 *       ->save();
	 *
	 * @param   object  $event  OrderAfterPlaceEvent
	 *                          $event->getOrder() → full order object
	 * @return  void
	 * @since   3.5.1
	 */
	public function onOrderAfterPlace($event): void
	{
		$order = $event->getOrder();

		// Guard: order must exist and have a valid ID
		if (!$order || empty($order->id)) {
			return;
		}

		// Create payment record:
		//   - Amount: auto-resolved from $order->total_paid_tax_incl
		//   - Method: auto-filled from $order->id_payment_method
		//   - Currency: auto-filled from the order's currency
		//   - Status: pending (admin will confirm manually)
		//   - Employee: auto-filled from current session (0 for frontend)
		//   - Added: auto-filled with current UTC timestamp
		$paymentId = $this->payment($order)
			->pending()
			->save();

		// Log failure — don't throw, the order is already committed.
		// The admin can manually add a payment record later.
		if (!$paymentId) {
			Log::add(
				'Standard payment: Failed to create initial payment for order #' . $order->id,
				Log::ERROR,
				'com_alfa.payments'
			);
		}
	}

	// =========================================================================
	//
	//   █████╗  ██████╗████████╗██╗ ██████╗ ███╗   ██╗███████╗
	//  ██╔══██╗██╔════╝╚══██╔══╝██║██╔═══██╗████╗  ██║██╔════╝
	//  ███████║██║        ██║   ██║██║   ██║██╔██╗ ██║███████╗
	//  ██╔══██║██║        ██║   ██║██║   ██║██║╚██╗██║╚════██║
	//  ██║  ██║╚██████╗   ██║   ██║╚██████╔╝██║ ╚████║███████║
	//  ╚═╝  ╚═╝ ╚═════╝   ╚═╝   ╚═╝ ╚═════╝ ╚═╝  ╚═══╝╚══════╝
	//
	//  ADMIN ACTION HOOKS — Button Registration + Execution
	//
	//  These two methods control the admin action buttons that appear
	//  next to each payment in the order edit view.
	//
	//  Flow: onGetActions() registers buttons → admin clicks one →
	//        onExecuteAction() handles the click.
	//
	// =========================================================================

	/**
	 * Register action buttons based on the current payment status.
	 *
	 * Called when the payments list is rendered in the order edit view.
	 * Examines the payment's transaction_status and registers only the
	 * buttons that make sense for the current state.
	 *
	 * Button API (fluent):
	 *
	 *   $event->add('action_name', 'Button Label')
	 *       ->icon('checkmark')          // Joomla icon (without 'icon-' prefix)
	 *       ->css('btn-success')         // Bootstrap button class
	 *       ->confirm('Are you sure?')   // Confirmation dialog before execution
	 *       ->priority(200);             // Higher = more prominent position
	 *
	 * For modal buttons (open a template in a popup):
	 *
	 *   $event->add('view_details', 'Details')
	 *       ->icon('eye')
	 *       ->modal('layout_name', 'Modal Title')     // opens tmpl/layout_name.php
	 *       ->modal('layout_name', 'Title', 'lg');    // 'lg' = large modal
	 *
	 * Available on $event:
	 *   $event->getPayment()  → Payment record (with Money objects, params)
	 *   $event->getOrder()    → Full order object
	 *
	 * @param   object  $event  GetPaymentActionsEvent
	 * @return  void
	 * @since   3.0.0
	 */
	public function onGetActions($event): void
	{
		$payment = $event->getPayment();
		$status  = $payment->transaction_status ?? 'pending';

		// ── ALWAYS AVAILABLE ─────────────────────────────────────

		// View Details — opens a modal with payment information
		$event->add('view_details', Text::_('COM_ALFA_VIEW_DETAILS'))
			->icon('eye')->css('btn-outline-secondary')
			->modal('action_view_details', Text::_('COM_ALFA_PAYMENT_DETAILS') . ' #' . $payment->id)
			->priority(10);

		// View Logs — opens a modal with this payment's plugin log history
		$event->add('view_logs', Text::_('COM_ALFA_VIEW_LOGS'))
			->icon('list')->css('btn-outline-info')
			->modal('default_order_logs_view', Text::_('COM_ALFA_PAYMENT_LOGS') . ' #' . $payment->id, 'lg')
			->priority(5);

		// ── PENDING STATE ────────────────────────────────────────

		if ($status === OrderPaymentHelper::STATUS_PENDING) {
			// Mark as Paid — transitions to completed
			$event->add('mark_paid', Text::_('PLG_ALFAPAYMENTS_STANDARD_MARK_PAID'))
				->icon('checkmark')->css('btn-success')
				->confirm(Text::_('PLG_ALFAPAYMENTS_STANDARD_CONFIRM_MARK_PAID'))
				->priority(200);

			// Cancel — transitions to cancelled
			$event->add('cancel', Text::_('COM_ALFA_CANCEL'))
				->icon('cancel')->css('btn-outline-danger')
				->confirm(Text::_('PLG_ALFAPAYMENTS_STANDARD_CONFIRM_CANCEL'))
				->priority(50);
		}

		// ── COMPLETED STATE ──────────────────────────────────────

		if ($status === OrderPaymentHelper::STATUS_COMPLETED) {
			// Refund — transitions to refunded + creates refund record
			$event->add('refund', Text::_('PLG_ALFAPAYMENTS_STANDARD_REFUND'))
				->icon('undo-2')->css('btn-warning')
				->confirm(Text::_('PLG_ALFAPAYMENTS_STANDARD_CONFIRM_REFUND'))
				->priority(150);
		}

		// ── TERMINAL STATES (refunded, cancelled, failed) ────────
		// No actions — lifecycle is complete.
		// Gateway plugins might add "Retry" for failed payments.
	}

	/**
	 * Handle an admin action button click.
	 *
	 * Routes the action name to the appropriate handler method.
	 * Uses PHP 8.0 match() for clean dispatch — add your custom
	 * actions here when extending.
	 *
	 * Available on $event:
	 *   $event->getAction()    → action name string (e.g. 'mark_paid')
	 *   $event->getPayment()   → payment record (with Money objects)
	 *   $event->getOrder()     → full order object
	 *   $event->setMessage()   → success message shown to admin
	 *   $event->setError()     → error message shown to admin
	 *   $event->setRefresh()   → true to reload the page after action
	 *   $event->setLayout()    → render template (for modal actions)
	 *   $event->setLayoutData() → data for the template
	 *   $event->setModalTitle() → title for modal actions
	 *
	 * @param   object  $event  ExecutePaymentActionEvent
	 * @return  void
	 * @since   3.0.0
	 */
	public function onExecuteAction($event): void
	{
		match ($event->getAction()) {
			'mark_paid'    => $this->handleMarkPaid($event),
			'cancel'       => $this->handleCancel($event),
			'refund'       => $this->handleRefund($event),
			'view_details' => $this->handleViewDetails($event),
			'view_logs'    => $this->handleViewLogs($event),
			default        => $event->setError('Unknown action: ' . $event->getAction()),
		};
	}

	// =========================================================================
	//
	//  ██╗  ██╗ █████╗ ███╗   ██╗██████╗ ██╗     ███████╗██████╗ ███████╗
	//  ██║  ██║██╔══██╗████╗  ██║██╔══██╗██║     ██╔════╝██╔══██╗██╔════╝
	//  ███████║███████║██╔██╗ ██║██║  ██║██║     █████╗  ██████╔╝███████╗
	//  ██╔══██║██╔══██║██║╚██╗██║██║  ██║██║     ██╔══╝  ██╔══██╗╚════██║
	//  ██║  ██║██║  ██║██║ ╚████║██████╔╝███████╗███████╗██║  ██║███████║
	//  ╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═══╝╚═════╝ ╚══════╝╚══════╝╚═╝  ╚═╝╚══════╝
	//
	//  ACTION HANDLERS — Business Logic
	//
	//  Each handler follows the same pattern:
	//    1. Get payment + order from event
	//    2. Use fluent builder to modify the payment record
	//    3. Write a structured log entry with ALL schema columns
	//    4. Set success/error message on the event
	//    5. Request page refresh if data changed
	//
	//  Log schema columns (10 plugin-specific + 3 auto):
	//    Auto:    id, id_order, id_order_payment
	//    Plugin:  action, transaction_status, amount, order_total,
	//             currency, transaction_id, refund_type, note,
	//             created_on, created_by
	//
	// =========================================================================

	/**
	 * Mark payment as paid (completed).
	 *
	 * Transition: pending → completed
	 *
	 * What happens:
	 *   1. Updates transaction_status to 'completed' in #__alfa_order_payments
	 *   2. Sets processed_at to current UTC timestamp (when confirmed)
	 *   3. The payment now counts toward total_paid_real
	 *   4. If total_paid_real >= order_total → payment_status = 'paid'
	 *   5. Writes plugin log + automatic activity log (via helper)
	 *
	 * For gateway plugins, this is typically triggered by a webhook
	 * callback (Stripe: charge.succeeded, PayPal: PAYMENT.CAPTURE.COMPLETED)
	 * rather than an admin button click.
	 *
	 * @param   object  $event  ExecutePaymentActionEvent
	 * @return  void
	 * @since   4.0.0
	 */
	private function handleMarkPaid($event): void
	{
		$payment = $event->getPayment();
		$order   = $event->getOrder();
		$amount  = $this->getPaymentAmount($payment);
		$now     = Factory::getDate('now', 'UTC')->toSql();

		// Update the payment record via fluent builder.
		// ->completed() sets transaction_status = 'completed'
		// ->processedAt() records when the payment was confirmed
		// ->save() delegates to OrderPaymentHelper::update() which:
		//   - Diffs old vs new values
		//   - Updates only changed columns
		//   - Writes to order_activity_log automatically
		$result = $this->paymentUpdate((int) $payment->id)
			->completed()
			->processedAt($now)
			->save();

		if ($result === false) {
			$event->setError('Failed to mark payment #' . $payment->id . ' as paid');
			return;
		}

		// Write to the PLUGIN-SPECIFIC log table.
		// This is separate from the order activity log (which the builder
		// writes automatically). Plugin logs store gateway-specific data
		// and are shown in the "View Logs" modal.
		$this->log([
			'id_order'           => (int) $order->id,
			'id_order_payment'   => (int) $payment->id,
			'action'             => 'mark_paid',
			'transaction_status' => OrderPaymentHelper::STATUS_COMPLETED,
			'amount'             => $amount,
			'order_total'        => $amount,
			'currency'           => $order->id_currency ?? '',
			'transaction_id'     => $payment->transaction_id ?? null,
			'refund_type'        => null,
			'note'               => 'Marked as paid by admin',
			'created_on'         => $now,
			'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
		]);

		// Success: show message and refresh the payments list
		$event->setMessage('Payment #' . $payment->id . ' marked as paid');
		$event->setRefresh(true);
	}

	/**
	 * Cancel a pending payment.
	 *
	 * Transition: pending → cancelled
	 *
	 * What happens:
	 *   1. Updates transaction_status to 'cancelled'
	 *   2. The payment no longer counts toward total_paid_real
	 *      (it never did — pending payments don't count either)
	 *   3. The Cancel and Mark as Paid buttons disappear
	 *   4. Writes plugin log + automatic activity log
	 *
	 * For gateway plugins, this might also need to void a pre-auth
	 * hold via the gateway API. Example:
	 *
	 *   $stripe->paymentIntents->cancel($paymentIntentId);
	 *   $this->paymentUpdate($id)->cancelled()
	 *       ->transactionId($voidTxnId)
	 *       ->gatewayResponse(json_encode($response))
	 *       ->save();
	 *
	 * @param   object  $event  ExecutePaymentActionEvent
	 * @return  void
	 * @since   4.0.0
	 */
	private function handleCancel($event): void
	{
		$payment = $event->getPayment();
		$order   = $event->getOrder();
		$amount  = $this->getPaymentAmount($payment);
		$now     = Factory::getDate('now', 'UTC')->toSql();

		$result = $this->paymentUpdate((int) $payment->id)
			->cancelled()
			->save();

		if ($result === false) {
			$event->setError('Failed to cancel payment #' . $payment->id);
			return;
		}

		$this->log([
			'id_order'           => (int) $order->id,
			'id_order_payment'   => (int) $payment->id,
			'action'             => 'cancel',
			'transaction_status' => OrderPaymentHelper::STATUS_CANCELLED,
			'amount'             => $amount,
			'order_total'        => $amount,
			'currency'           => $order->id_currency ?? '',
			'transaction_id'     => $payment->transaction_id ?? null,
			'refund_type'        => null,
			'note'               => 'Cancelled by admin',
			'created_on'         => $now,
			'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
		]);

		$event->setMessage('Payment #' . $payment->id . ' cancelled');
		$event->setRefresh(true);
	}

	/**
	 * Refund a completed payment.
	 *
	 * Transition: completed → refunded (two-step process)
	 *
	 * STEP 1 — Mark original as refunded:
	 *   paymentUpdate($id)->refunded()->save()
	 *   Effect: transaction_status flips to 'refunded', which:
	 *     - Drops this payment from total_paid_real calculation
	 *     - Hides the Refund button (terminal state)
	 *     - Activity log records the status change automatically
	 *
	 * STEP 2 — Create refund audit record:
	 *   payment($order)->refund()->amount($amt)->refunded()
	 *       ->refundedPayment($originalId)->fullRefund()
	 *       ->refundReason('...')->save()
	 *   Effect: creates a NEW row in #__alfa_order_payments with:
	 *     - payment_type = 'refund' (never counted in total_paid_real)
	 *     - id_refunded_payment = FK to the original payment
	 *     - refund_type = 'full' (or 'partial' for gateway plugins)
	 *     - refund_reason = human-readable explanation
	 *
	 * WHY two steps?
	 *   Step 1 fixes the financial totals immediately.
	 *   Step 2 creates the audit trail: who refunded, when, how much,
	 *   and which payment it was for. Without step 2, you'd have no
	 *   record of the refund happening — just a payment that changed
	 *   status. Step 2 is the receipt.
	 *
	 * FOR GATEWAY PLUGINS (Stripe, PayPal):
	 *   Before step 1, call the gateway refund API:
	 *
	 *     $refund = $stripe->refunds->create([
	 *         'payment_intent' => $payment->transaction_id,
	 *         'amount' => $amountInCents,  // optional for partial
	 *     ]);
	 *
	 *   Then in step 2, add gateway data:
	 *     ->transactionId($refund->id)
	 *     ->gatewayResponse(json_encode($refund))
	 *     ->partialRefund()  // if partial amount
	 *
	 * @param   object  $event  ExecutePaymentActionEvent
	 * @return  void
	 * @since   4.0.0
	 */
	private function handleRefund($event): void
	{
		$payment = $event->getPayment();
		$order   = $event->getOrder();
		$amount  = $this->getPaymentAmount($payment);
		$now     = Factory::getDate('now', 'UTC')->toSql();

		// ── STEP 1: Flip original payment to "refunded" ─────────
		$result = $this->paymentUpdate((int) $payment->id)
			->refunded()
			->save();

		if ($result === false) {
			$event->setError('Failed to refund payment #' . $payment->id);
			return;
		}

		// ── STEP 2: Create refund audit record ──────────────────
		$this->payment($order)
			->refund()                                    // payment_type = 'refund'
			->amount($amount)                             // same as original (full refund)
			->refunded()                                  // transaction_status = 'refunded'
			->refundedPayment((int) $payment->id)         // FK → original payment
			->fullRefund()                                // refund_type = 'full'
			->refundReason('Refund for payment #' . $payment->id)
			->save();

		// ── Write plugin-specific log ───────────────────────────
		$this->log([
			'id_order'           => (int) $order->id,
			'id_order_payment'   => (int) $payment->id,
			'action'             => 'refund',
			'transaction_status' => OrderPaymentHelper::STATUS_REFUNDED,
			'amount'             => $amount,
			'order_total'        => $amount,
			'currency'           => $order->id_currency ?? '',
			'transaction_id'     => $payment->transaction_id ?? null,
			'refund_type'        => OrderPaymentHelper::REFUND_FULL,
			'note'               => 'Full refund for payment #' . $payment->id,
			'created_on'         => $now,
			'created_by'         => (int) Factory::getApplication()->getIdentity()->id,
		]);

		$event->setMessage('Payment #' . $payment->id . ' refunded (full)');
		$event->setRefresh(true);
	}

	// =========================================================================
	//
	//  ███╗   ███╗ ██████╗ ██████╗  █████╗ ██╗     ███████╗
	//  ████╗ ████║██╔═══██╗██╔══██╗██╔══██╗██║     ██╔════╝
	//  ██╔████╔██║██║   ██║██║  ██║███████║██║     ███████╗
	//  ██║╚██╔╝██║██║   ██║██║  ██║██╔══██║██║     ╚════██║
	//  ██║ ╚═╝ ██║╚██████╔╝██████╔╝██║  ██║███████╗███████║
	//  ╚═╝     ╚═╝ ╚═════╝ ╚═════╝ ╚═╝  ╚═╝╚══════╝╚══════╝
	//
	//  MODAL ACTION HANDLERS
	//
	//  These open templates in a Bootstrap modal rather than
	//  performing an action. Registered with ->modal() in onGetActions().
	//
	// =========================================================================

	/**
	 * Show payment details in a modal.
	 *
	 * Opens tmpl/action_view_details.php with the payment and order data.
	 * The template can display: amount, status, gateway response,
	 * transaction timeline, etc.
	 *
	 * @param   object  $event  ExecutePaymentActionEvent
	 * @return  void
	 * @since   3.0.0
	 */
	private function handleViewDetails($event): void
	{
		$payment = $event->getPayment();

		$event->setLayout('action_view_details');
		$event->setLayoutData([
			'payment' => $payment,
			'order'   => $event->getOrder(),
		]);
		$event->setModalTitle(Text::_('COM_ALFA_PAYMENT_DETAILS') . ' #' . $payment->id);
	}

	/**
	 * Show plugin-specific logs in a modal.
	 *
	 * Opens tmpl/default_order_logs_view.php with log data and the
	 * XML schema (for building table headers dynamically).
	 *
	 * loadLogs() automatically filters by id_order_payment because
	 * PaymentsPlugin sets logIdentifierField = 'id_order_payment'.
	 *
	 * @param   object  $event  ExecutePaymentActionEvent
	 * @return  void
	 * @since   3.5.0
	 */
	private function handleViewLogs($event): void
	{
		$payment = $event->getPayment();
		$order   = $event->getOrder();

		// Load logs for this specific payment (filtered by id_order_payment)
		$logData = $this->loadLogs((int) $order->id, (int) $payment->id);

		$event->setLayout('default_order_logs_view');
		$event->setLayoutData([
			'logData' => $logData ?? [],
			'xml'     => $this->getLogsSchema(),
		]);
		$event->setModalTitle(Text::_('COM_ALFA_PAYMENT_LOGS') . ' #' . $payment->id);
	}

	// =========================================================================
	//
	//  ██╗   ██╗████████╗██╗██╗
	//  ██║   ██║╚══██╔══╝██║██║
	//  ██║   ██║   ██║   ██║██║
	//  ██║   ██║   ██║   ██║██║
	//  ╚██████╔╝   ██║   ██║███████╗
	//   ╚═════╝    ╚═╝   ╚═╝╚══════╝
	//
	//  UTILITY METHODS
	//
	// =========================================================================

	/**
	 * Extract the payment amount as a float.
	 *
	 * Safely handles all possible amount formats:
	 *   - Money object (from OrderModel::getItem) → calls ->getAmount()
	 *   - Raw float (from direct DB query) → casts to float
	 *   - String (from form input) → casts to float
	 *   - Null/missing → returns 0.0
	 *
	 * Used by action handlers for logging and refund amount calculation.
	 *
	 * @param   object  $payment  Payment record
	 * @return  float   Payment amount as a plain float
	 * @since   3.5.0
	 */
	private function getPaymentAmount(object $payment): float
	{
		$amount = $payment->amount ?? 0;

		// Money object — the standard format from OrderModel::getItem()
		if (is_object($amount) && method_exists($amount, 'getAmount')) {
			return (float) $amount->getAmount();
		}

		return (float) $amount;
	}
}