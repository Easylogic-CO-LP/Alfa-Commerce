<?php
/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Plugin
 * @version     3.5.1
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Base Payment Plugin
 *
 * All payment plugins extend this class.
 * Provides fluent builder wrappers for payment operations
 * and empty action hooks for the admin UI.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  FLUENT BUILDER API (recommended for programmatic use):
 *
 *    CREATE:
 *      $paymentId = $this->payment($order)->pending()->save();
 *      $paymentId = $this->payment($order)->authorized()->transactionId('ch_3M..')->save();
 *      $paymentId = $this->payment($order)->refund()->amount(50)->completed()->save();
 *
 *    UPDATE:
 *      $this->paymentUpdate($paymentId)->completed()->save();
 *      $this->paymentUpdate($paymentId)->cancelled()->note('Customer request')->save();
 *      $this->paymentUpdate($paymentId)->refunded()->save();
 *
 *  READ / DELETE (no builder needed):
 *    $payment  = $this->getPayment($paymentId);
 *    $payments = $this->getPaymentsByOrder($orderId);
 *    $this->deletePayment($paymentId, $orderId);
 *
 *  LOGGING (inherited from Plugin.php):
 *    $logId = $this->log(['id_order' => $orderId, ...]);
 *    $logs  = $this->loadLogs($orderId, $paymentId);
 *    $xml   = $this->getLogsSchema();
 *
 *  ADMIN ACTIONS (Fluent API on event):
 *    $event->add('mark_paid', 'Mark as Paid')
 *        ->icon('checkmark')->css('btn-success')
 *        ->confirm('Mark as paid?')->priority(200);
 * ═══════════════════════════════════════════════════════════════════
 *
 * LOG IDENTIFIER: Sets logIdentifierField = 'id_order_payment'
 *
 * Path: administrator/components/com_alfa/src/Plugin/PaymentsPlugin.php
 *
 * @since  3.0.0
 */

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Plugin
 * @version     3.5.1
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Base Payment Plugin
 *
 * All payment plugins extend this class.
 * Provides fluent builder wrappers for payment operations
 * and empty action hooks for the admin UI.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  FLUENT BUILDER API (recommended for programmatic use):
 *
 *    CREATE:
 *      $paymentId = $this->payment($order)->pending()->save();
 *      $paymentId = $this->payment($order)->authorized()->transactionId('ch_3M..')->save();
 *      $paymentId = $this->payment($order)->refund()->amount(50)->completed()->save();
 *
 *    UPDATE:
 *      $this->paymentUpdate($paymentId)->completed()->save();
 *      $this->paymentUpdate($paymentId)->cancelled()->note('Customer request')->save();
 *      $this->paymentUpdate($paymentId)->refunded()->save();
 *
 *  READ / DELETE (no builder needed):
 *    $payment  = $this->getPayment($paymentId);
 *    $payments = $this->getPaymentsByOrder($orderId);
 *    $this->deletePayment($paymentId, $orderId);
 *
 *  LOGGING (inherited from Plugin.php):
 *    $logId = $this->log(['id_order' => $orderId, ...]);
 *    $logs  = $this->loadLogs($orderId, $paymentId);
 *    $xml   = $this->getLogsSchema();
 *
 *  ADMIN ACTIONS (Fluent API on event):
 *    $event->add('mark_paid', 'Mark as Paid')
 *        ->icon('checkmark')->css('btn-success')
 *        ->confirm('Mark as paid?')->priority(200);
 * ═══════════════════════════════════════════════════════════════════
 *
 * LOG IDENTIFIER: Sets logIdentifierField = 'id_order_payment'
 *
 * Path: administrator/components/com_alfa/src/Plugin/PaymentsPlugin.php
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Helper\OrderPaymentHelper;
use Joomla\Event\SubscriberInterface;
use RuntimeException;

defined('_JEXEC') or die;

abstract class PaymentsPlugin extends Plugin implements SubscriberInterface
{
    /** @var string Default completion page URL */
    protected string $completePageUrl;

    /**
     * Events this plugin subscribes to.
     *
     * @since   3.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onItemView' => 'onItemView',
            'onCartView' => 'onCartView',
            'onOrderProcessView' => 'onOrderProcessView',
        ];
    }

    /**
     * Constructor.
     *
     * Sets logIdentifierField so $this->loadLogs($orderId, $paymentId)
     * correctly filters by id_order_payment in the plugin's log table.
     *
     * @param array $config Plugin configuration
     * @since   3.0.0
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->completePageUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_completed';
        $this->logIdentifierField = 'id_order_payment';
    }

    /**
     * Get the order completion page URL.
     *
     * @since   3.0.0
     */
    public function getCompletePageUrl(): string
    {
        return $this->completePageUrl;
    }

    /**
     * Set the order completion page URL.
     *
     * @since   3.0.0
     */
    public function setCompletePageUrl(string $url): void
    {
        $this->completePageUrl = $url;
    }

    // ==========================================================
    //  PAYMENT FLUENT BUILDER WRAPPERS
    //
    //  These return an OrderPaymentHelper builder instance.
    //  The builder validates, auto-fills, and delegates to
    //  static CRUD methods internally.
    //
    //  For direct CRUD access (batch ops, admin form saves),
    //  use OrderPaymentHelper::create/update/delete() directly.
    // ==========================================================

    /**
     * Create a new payment (returns fluent builder in CREATE mode).
     *
     * Amount is auto-resolved from the best available source:
     *   Primary: $order->total_paid_tax_incl (always correct — Money object)
     *   Priority 2: OrderTotalHelper (items + shipping - discounts from DB)
     *   Priority 3: ->amount($x) manual override (always wins when called)
     *
     * Usage:
     *   $paymentId = $this->payment($order)->pending()->save();
     *   $paymentId = $this->payment($order)->authorized()->transactionId('ch_3M..')->save();
     *   $paymentId = $this->payment($order)->refund()->amount(50)->completed()->save();
     *
     * @param object $order Full order object (->id, ->items, ->id_payment_method)
     *
     * @return OrderPaymentHelper Builder — chain setters, finish with ->save()
     *
     * @since   3.5.1
     */
    protected function payment(object $order): OrderPaymentHelper
    {
        return OrderPaymentHelper::for($order);
    }

    /**
     * Update an existing payment (returns fluent builder in UPDATE mode).
     *
     * Only fields you explicitly set via setter methods are sent to the
     * database. If nothing is changed, save() is a no-op.
     *
     * Usage:
     *   $this->paymentUpdate($id)->completed()->save();
     *   $this->paymentUpdate($id)->cancelled()->note('Customer request')->save();
     *   $this->paymentUpdate($id)->refunded()->save();
     *
     * @param int $paymentId Existing payment row PK
     *
     * @return OrderPaymentHelper Builder — chain setters, finish with ->save()
     *
     * @throws RuntimeException If payment not found in DB
     *
     * @since   3.5.1
     */
    protected function paymentUpdate(int $paymentId): OrderPaymentHelper
    {
        return OrderPaymentHelper::load($paymentId);
    }

    // ==========================================================
    //  PAYMENT READ / DELETE (no builder needed)
    // ==========================================================

    /**
     * Delete an order payment record.
     *
     * @param int $id Payment row PK
     * @param int $orderId Order PK (for activity log)
     *
     * @return bool True on success
     *
     * @since   3.5.0
     */
    protected function deletePayment(int $id, int $orderId): bool
    {
        return OrderPaymentHelper::delete($id, $orderId);
    }

    /**
     * Load a single payment with method params attached.
     *
     * @param int $id Payment row PK
     *
     * @return object|null Payment record with ->params, or null
     *
     * @since   3.5.0
     */
    protected function getPayment(int $id): ?object
    {
        return OrderPaymentHelper::get($id);
    }

    /**
     * Load all payments for an order (lightweight — no params).
     *
     * @param int $orderId Order PK
     *
     * @return array Array of payment row objects
     *
     * @since   3.5.0
     */
    protected function getPaymentsByOrder(int $orderId): array
    {
        return OrderPaymentHelper::getByOrder($orderId);
    }

    // ==========================================================
    //  FRONTEND HOOKS (abstract — plugin MUST implement)
    // ==========================================================

    /**
     * Order process view — redirect to gateway or show processing page.
     *
     * @param object $event OrderProcessViewEvent
     * @since   3.0.0
     */
    abstract public function onOrderProcessView($event): void;

    /**
     * Order complete view — show thank-you page.
     *
     * @param object $event OrderCompleteViewEvent
     * @since   3.0.0
     */
    abstract public function onOrderCompleteView($event): void;

    // ==========================================================
    //  ADMIN ACTION HOOKS (empty — plugin overrides as needed)
    // ==========================================================

    /**
     * Register available action buttons for a payment.
     *
     * Override this to add buttons using the fluent API:
     *
     *   $event->add('mark_paid', 'Mark as Paid')
     *       ->icon('checkmark')->css('btn-success')
     *       ->confirm('Mark as paid?');
     *
     * Default: no actions.
     *
     * @param object $event GetPaymentActionsEvent
     * @since   3.0.0
     */
    public function onGetActions($event): void
    {
        // Empty — plugin overrides to register actions
    }

    /**
     * Handle an action button click for a payment.
     *
     * Override and use match() to route actions:
     *
     *   match ($event->getAction()) {
     *       'mark_paid'  => $this->handleMarkPaid($event),
     *       'view_logs'  => $this->handleViewLogs($event),
     *       default      => $event->setError('Unknown action'),
     *   };
     *
     * Default: sets error "Unknown action".
     *
     * @param object $event ExecutePaymentActionEvent
     * @since   3.0.0
     */
    public function onExecuteAction($event): void
    {
        $event->setError('Unknown action: ' . $event->getAction());
    }
}
