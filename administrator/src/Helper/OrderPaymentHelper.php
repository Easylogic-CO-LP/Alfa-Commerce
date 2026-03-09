<?php
/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Helper
 * @version     3.5.1
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2026 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Order Payment Helper — Unified CRUD Engine + Fluent Builder
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  TWO APIs, ONE CLASS — accessible from anywhere
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  HIGH-LEVEL — Fluent Builder (recommended for 95% of use cases):
 *
 *    CREATE:  OrderPaymentHelper::for($order)->pending()->save();
 *    UPDATE:  OrderPaymentHelper::load($id)->completed()->save();
 *    PLUGIN:  $this->payment($order)->pending()->save();
 *    PLUGIN:  $this->paymentUpdate($id)->completed()->transactionId('ch_3M..')->save();
 *    ADMIN:   OrderPaymentHelper::for($order)->amount(50)->pending()->save();
 *    CLI:     OrderPaymentHelper::load($id)->completed()->note('Webhook')->save();
 *
 *  LOW-LEVEL — Static CRUD (escape hatch for exotic columns, batch ops):
 *
 *    OrderPaymentHelper::create(['id_order' => 1, 'amount' => 100, ...]);
 *    OrderPaymentHelper::update(5, ['transaction_status' => 'completed', ...]);
 *    OrderPaymentHelper::delete(5, 1);
 *    OrderPaymentHelper::get(5);
 *    OrderPaymentHelper::getByOrder(1);
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  AMOUNT RESOLUTION (create mode, when ->amount() NOT called):
 * ═══════════════════════════════════════════════════════════════════════
 *
 *    Primary: $order->total_paid_tax_incl  (always correct)
 *      → During checkout: overridden by OrderPlaceHelper from CartHelper
 *      → During admin/API: computed by OrderModel::getItem() from DB
 *      → Money object — getAmount() extracts the float
 *
 *    Fallback: OrderTotalHelper::getOrderTotal($orderId, $order)
 *      → Recomputes from DB tables if total_paid_tax_incl is missing
 *
 *    Priority 3: ->amount($x)  (manual override — always wins when called)
 *    Priority 4: ->itemsOnly()  (items total, no shipping/discounts)
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  SAVE() FLOW:
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  CREATE mode (::for($order)):
 *    1. Developer chains: ->pending()->amount(100)->transactionId('ch_3M..')
 *    2. ->save() validates transaction_status is set (throws if not)
 *    3. ->save() resolves amount if not manually set (priority chain above)
 *    4. ->save() delegates to static::create($data)
 *    5. create() auto-fills: added, id_employee, id_currency, method name
 *    6. create() filters unknown columns, inserts row, logs activity
 *    7. Returns new payment row ID
 *
 *  UPDATE mode (::load($id)):
 *    1. load() reads existing payment from DB (validates existence, gets id_order)
 *    2. Developer chains: ->completed()->note('Admin verified')
 *    3. ->save() collects ONLY explicitly-set fields (no unintended overwrites)
 *    4. ->save() delegates to static::update($id, $data)
 *    5. update() diffs old vs new, updates row, logs only actual changes
 *    6. Returns payment ID on success, false on failure
 *    7. No-op if nothing was changed (returns ID without DB write)
 *
 * Path: administrator/components/com_alfa/src/Helper/OrderPaymentHelper.php
 *
 * @since  3.5.0
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\OrderTotalHelper;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

class OrderPaymentHelper
{
	// =====================================================================
	//  CONSTANTS — Transaction Status
	//
	//  The ONLY valid values for #__alfa_order_payments.transaction_status.
	//  Use builder shortcut methods for IDE autocomplete and typo safety.
	// =====================================================================

	/** @var string  Awaiting confirmation (offline payment, bank transfer) */
	const STATUS_PENDING    = 'pending';

	/** @var string  Funds reserved by gateway, not yet captured */
	const STATUS_AUTHORIZED = 'authorized';

	/** @var string  Funds captured — money received */
	const STATUS_COMPLETED  = 'completed';

	/** @var string  Gateway declined, timeout, or insufficient funds */
	const STATUS_FAILED     = 'failed';

	/** @var string  Cancelled before completion (by customer or admin) */
	const STATUS_CANCELLED  = 'cancelled';

	/** @var string  Funds returned to customer */
	const STATUS_REFUNDED   = 'refunded';

	// =====================================================================
	//  CONSTANTS — Payment Type
	//
	//  Controls financial calculations:
	//    total_paid_real = SUM(amount)
	//      WHERE payment_type = 'payment' AND transaction_status = 'completed'
	// =====================================================================

	/** @var string  Normal payment (counts toward total_paid_real when completed) */
	const TYPE_PAYMENT       = 'payment';

	/** @var string  Refund audit trail (never counted in total_paid_real) */
	const TYPE_REFUND        = 'refund';

	/** @var string  Authorization hold (funds reserved, capture later) */
	const TYPE_AUTHORIZATION = 'authorization';

	/** @var string  Full refund — entire original amount returned */
	const REFUND_FULL = 'full';

	/** @var string  Partial refund — only some of the original amount returned */
	const REFUND_PARTIAL = 'partial';

	// =====================================================================
	//  CONSTANTS — Table Configuration
	// =====================================================================

	/** @var string  Database table */
	private const TABLE = '#__alfa_order_payments';

	/** @var string  Primary key column */
	private const PK = 'id';

	/**
	 * Nullable integer columns — form sends '', MySQL rejects for int.
	 */
	private const NULLABLE_INT_COLUMNS = ['id_refunded_payment', 'id_employee'];

	/**
	 * Nullable datetime columns — empty/zero → null.
	 */
	private const NULLABLE_DATE_COLUMNS = ['processed_at'];

	/**
	 * Nullable enum columns — '' is not a valid enum value in strict mode.
	 */
	private const NULLABLE_ENUM_COLUMNS = ['refund_type', 'refund_reason'];

	// =====================================================================
	//  BUILDER STATE (instance properties — used only in fluent mode)
	// =====================================================================

	/** @var array  Data accumulator for create/update */
	protected array $data = [];

	/** @var object|null  Order context (null in update mode) */
	protected ?object $order = null;

	/** @var string  'create' or 'update' — determines save() behaviour */
	protected string $mode = 'create';

	/** @var int|null  Existing payment PK (update mode only) */
	protected ?int $paymentId = null;

	/** @var bool  Whether ->amount() or ->itemsOnly() was explicitly called */
	protected bool $amountOverridden = false;

	// =====================================================================
	//
	//  ███████╗██╗     ██╗   ██╗███████╗███╗   ██╗████████╗
	//  ██╔════╝██║     ██║   ██║██╔════╝████╗  ██║╚══██╔══╝
	//  █████╗  ██║     ██║   ██║█████╗  ██╔██╗ ██║   ██║
	//  ██╔══╝  ██║     ██║   ██║██╔══╝  ██║╚██╗██║   ██║
	//  ██║     ███████╗╚██████╔╝███████╗██║ ╚████║   ██║
	//  ╚═╝     ╚══════╝ ╚═════╝ ╚══════╝╚═╝  ╚═══╝   ╚═╝
	//
	//  FLUENT BUILDER API — the recommended developer interface
	//
	// =====================================================================

	/**
	 * CREATE mode — build a new payment for an order.
	 *
	 * Auto-fills from order context: id_order, id_payment_method, payment_type.
	 * Amount auto-resolved on save() if not manually set.
	 *
	 * Usage:
	 *   OrderPaymentHelper::for($order)->pending()->save();
	 *   OrderPaymentHelper::for($order)->authorized()->transactionId('ch_3M..')->save();
	 *   OrderPaymentHelper::for($order)->refund()->amount(25)->completed()->save();
	 *
	 * @param   object  $order  Full order object (->id, ->items, ->id_payment_method)
	 *
	 * @return  static  Builder in create mode — chain setters, finish with ->save()
	 *
	 * @since   3.5.1
	 */
	public static function for(object $order): static
	{
		$builder = new static();
		$builder->mode  = 'create';
		$builder->order = $order;
		$builder->data  = [
			'id_order'          => (int) $order->id,
			'id_payment_method' => (int) ($order->id_payment_method ?? 0),
			'payment_type'      => self::TYPE_PAYMENT,
		];

		return $builder;
	}

	/**
	 * UPDATE mode — modify an existing payment record.
	 *
	 * Loads the existing payment from DB to validate existence and get
	 * id_order for activity logging. Only fields you explicitly set
	 * via setter methods are sent to the database.
	 *
	 * Usage:
	 *   OrderPaymentHelper::load($id)->completed()->save();
	 *   OrderPaymentHelper::load($id)->cancelled()->note('Customer request')->save();
	 *   OrderPaymentHelper::load($id)->amount(80.00)->save();
	 *
	 * @param   int  $paymentId  Existing payment row PK
	 *
	 * @return  static  Builder in update mode — chain setters, finish with ->save()
	 *
	 * @throws  \RuntimeException  If payment not found in DB
	 *
	 * @since   3.5.1
	 */
	public static function load(int $paymentId): static
	{
		$existing = self::getRaw($paymentId);

		if (!$existing) {
			throw new \RuntimeException(
				"OrderPaymentHelper::load(): Payment #{$paymentId} not found"
			);
		}

		$builder = new static();
		$builder->mode      = 'update';
		$builder->paymentId = $paymentId;
		$builder->data      = [
			'id_order' => (int) ($existing->id_order ?? 0),
		];

		return $builder;
	}

	// ─── Status Setters (one per valid status — IDE autocomplete) ────

	/**
	 * Set status to PENDING.
	 * Use for: offline payments (cash, bank transfer), awaiting gateway callback.
	 *
	 * @return  static
	 */
	public function pending(): static
	{
		$this->data['transaction_status'] = self::STATUS_PENDING;
		return $this;
	}

	/**
	 * Set status to AUTHORIZED.
	 * Use for: gateway pre-auth (funds reserved, capture later with ->completed()).
	 *
	 * @return  static
	 */
	public function authorized(): static
	{
		$this->data['transaction_status'] = self::STATUS_AUTHORIZED;
		return $this;
	}

	/**
	 * Set status to COMPLETED.
	 * Use for: payment captured — money in the bank.
	 *
	 * @return  static
	 */
	public function completed(): static
	{
		$this->data['transaction_status'] = self::STATUS_COMPLETED;
		return $this;
	}

	/**
	 * Set status to FAILED.
	 * Use for: declined card, gateway timeout, insufficient funds.
	 *
	 * @return  static
	 */
	public function failed(): static
	{
		$this->data['transaction_status'] = self::STATUS_FAILED;
		return $this;
	}

	/**
	 * Set status to CANCELLED.
	 * Use for: admin or customer cancels before payment completes.
	 *
	 * @return  static
	 */
	public function cancelled(): static
	{
		$this->data['transaction_status'] = self::STATUS_CANCELLED;
		return $this;
	}

	/**
	 * Set status to REFUNDED.
	 * Use for: marking the original payment as refunded.
	 *
	 * @return  static
	 */
	public function refunded(): static
	{
		$this->data['transaction_status'] = self::STATUS_REFUNDED;
		return $this;
	}

	// ─── Payment Type Setters ────────────────────────────────────────

	/**
	 * Mark as refund audit record.
	 * Creates a separate record with payment_type = 'refund' for the activity log.
	 *
	 * Example: $this->payment($order)->refund()->amount(50)->completed()->save();
	 *
	 * @return  static
	 */
	public function refund(): static
	{
		$this->data['payment_type'] = self::TYPE_REFUND;
		return $this;
	}

	/**
	 * Mark as authorization hold.
	 * For gateway pre-auth flows: hold funds now, capture later.
	 *
	 * @return  static
	 */
	public function authorization(): static
	{
		$this->data['payment_type'] = self::TYPE_AUTHORIZATION;
		return $this;
	}

	// ─── Amount Setters ──────────────────────────────────────────────

	/**
	 * Set amount manually — overrides all auto-calculation.
	 *
	 * Use for: partial payments, split payments, specific refund amounts,
	 * deposit payments, installments.
	 *
	 * @param   float  $amount  Payment amount (tax inclusive)
	 *
	 * @return  static
	 */
	public function amount(float $amount): static
	{
		$this->data['amount'] = $amount;
		$this->amountOverridden = true;
		return $this;
	}

	/**
	 * Set amount = items total only (no shipping, no discounts).
	 *
	 * Use for: deposit payments, item-only charges before shipping is calculated.
	 *
	 * @return  static
	 */
	public function itemsOnly(): static
	{
		if ($this->order) {
			$this->data['amount'] = OrderTotalHelper::getItemsTotal(
				(int) $this->order->id,
				$this->order
			);
			$this->amountOverridden = true;
		}

		return $this;
	}

	// ─── Data Setters ────────────────────────────────────────────────

	/**
	 * Set the external gateway transaction ID.
	 *
	 * Stored for reconciliation (Stripe charge ID, PayPal txn ID, etc.)
	 *
	 * @param   string  $id  Gateway reference (e.g. 'ch_3MqBE2...')
	 *
	 * @return  static
	 */
	public function transactionId(string $id): static
	{
		$this->data['transaction_id'] = $id;
		return $this;
	}

	/**
	 * Set admin-visible note on the payment.
	 *
	 * @param   string  $note  Free-text note
	 *
	 * @return  static
	 */
	public function note(string $note): static
	{
		$this->data['note'] = $note;
		return $this;
	}

	/**
	 * Link this refund to the original payment it refunds.
	 *
	 * Sets id_refunded_payment FK. Used by plugins when creating
	 * refund records so the audit trail links back.
	 *
	 * @param   int  $paymentId  Original payment row PK
	 *
	 * @return  static
	 *
	 * @since   4.0.0
	 */
	public function refundedPayment(int $paymentId): static
	{
		$this->data['id_refunded_payment'] = $paymentId;
		return $this;
	}

	/**
	 * Set the refund reason (free text from admin or gateway).
	 *
	 * @param   string  $reason  Human-readable reason
	 *
	 * @return  static
	 *
	 * @since   4.0.0
	 */
	public function refundReason(string $reason): static
	{
		$this->data['refund_reason'] = $reason;
		return $this;
	}

	/**
	 * Set refund type: full or partial.
	 *
	 * Auto-detected by the Standard plugin based on amount comparison,
	 * but can be set manually for gateway plugins that know the type.
	 *
	 * @param   string  $type  'full' or 'partial' (use REFUND_FULL / REFUND_PARTIAL)
	 *
	 * @return  static
	 *
	 * @since   4.0.0
	 */
	public function refundType(string $type): static
	{
		$this->data['refund_type'] = $type;
		return $this;
	}

	/**
	 * Mark as full refund.
	 *
	 * Shortcut for ->refundType(self::REFUND_FULL).
	 *
	 * @return  static
	 *
	 * @since   4.0.0
	 */
	public function fullRefund(): static
	{
		$this->data['refund_type'] = self::REFUND_FULL;
		return $this;
	}

	/**
	 * Mark as partial refund.
	 *
	 * Shortcut for ->refundType(self::REFUND_PARTIAL).
	 *
	 * @return  static
	 *
	 * @since   4.0.0
	 */
	public function partialRefund(): static
	{
		$this->data['refund_type'] = self::REFUND_PARTIAL;
		return $this;
	}

	/**
	 * Set the processed_at timestamp (when gateway confirmed).
	 *
	 * @param   string  $datetime  SQL datetime string (UTC)
	 *
	 * @return  static
	 *
	 * @since   4.0.0
	 */
	public function processedAt(string $datetime): static
	{
		$this->data['processed_at'] = $datetime;
		return $this;
	}

	/**
	 * Store raw gateway response JSON for debugging/auditing.
	 *
	 * @param   string  $json  Raw JSON from Stripe, PayPal, etc.
	 *
	 * @return  static
	 *
	 * @since   4.0.0
	 */
	public function gatewayResponse(string $json): static
	{
		$this->data['gateway_response'] = $json;
		return $this;
	}

	/**
	 * Override the payment method ID.
	 *
	 * Normally auto-filled from $order->id_payment_method.
	 * Use when creating a payment for a different method than the order's default.
	 *
	 * @param   int  $methodId  Payment method PK
	 *
	 * @return  static
	 */
	public function method(int $methodId): static
	{
		$this->data['id_payment_method'] = $methodId;
		return $this;
	}

	/**
	 * Set any arbitrary column value.
	 *
	 * Escape hatch for plugin-specific columns or future schema additions.
	 * Unknown columns are safely filtered out by saveToTable().
	 *
	 * @param   string  $key    Column name
	 * @param   mixed   $value  Value to set
	 *
	 * @return  static
	 */
	public function set(string $key, mixed $value): static
	{
		$this->data[$key] = $value;
		return $this;
	}

	// ─── Terminal Operation ──────────────────────────────────────────

	/**
	 * Validate and persist the payment record.
	 *
	 * CREATE mode: validates status, resolves amount, calls create().
	 * UPDATE mode: sends only changed fields, calls update(). No-op if nothing changed.
	 *
	 * @return  int|false  Payment row ID on success, false on failure
	 *
	 * @throws  \RuntimeException  If transaction_status not set (create mode)
	 * @throws  \RuntimeException  If payment ID missing (update mode)
	 *
	 * @since   3.5.1
	 */
	public function save(): int|false
	{
		if ($this->mode === 'update') {
			return $this->doBuilderUpdate();
		}

		return $this->doBuilderCreate();
	}

	// ─── Introspection (for tooling, validation, documentation) ─────

	/**
	 * Get all valid transaction_status values.
	 *
	 * Useful for validation dropdowns, documentation generation.
	 *
	 * @return  array  ['pending', 'authorized', 'completed', 'failed', 'cancelled', 'refunded']
	 */
	public static function allStatuses(): array
	{
		return [
			self::STATUS_PENDING, self::STATUS_AUTHORIZED, self::STATUS_COMPLETED,
			self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_REFUNDED,
		];
	}

	/**
	 * Get all valid payment_type values.
	 *
	 * @return  array  ['payment', 'refund', 'authorization']
	 */
	public static function allTypes(): array
	{
		return [self::TYPE_PAYMENT, self::TYPE_REFUND, self::TYPE_AUTHORIZATION];
	}

	/**
	 * Get all valid refund_type values.
	 *
	 * @return  array  ['full', 'partial']
	 *
	 * @since   4.0.0
	 */
	public static function allRefundTypes(): array
	{
		return [self::REFUND_FULL, self::REFUND_PARTIAL];
	}

	/**
	 * Get current builder mode.
	 *
	 * @return  string  'create' or 'update'
	 */
	public function getMode(): string
	{
		return $this->mode;
	}

	/**
	 * Preview the data array without saving.
	 *
	 * Resolves amount for create mode (shows what save() would compute).
	 * Useful for debugging and testing.
	 *
	 * @return  array  The data that would be passed to create() or update()
	 */
	public function toArray(): array
	{
		$data = $this->data;

		if ($this->mode === 'create' && !$this->amountOverridden && $this->order) {
			$data['amount'] = $this->resolveAmount();
		}

		return $data;
	}

	// ─── Builder Internal Methods ────────────────────────────────────

	/**
	 * Execute the CREATE flow.
	 *
	 * @return  int|false
	 */
	protected function doBuilderCreate(): int|false
	{
		// Validate: status is required for create
		if (empty($this->data['transaction_status'])) {
			throw new \RuntimeException(
				'OrderPaymentHelper: transaction_status is required. '
				. 'Call ->pending(), ->authorized(), ->completed(), ->failed(), '
				. '->cancelled(), or ->refunded() before ->save(). '
				. 'Valid values: ' . implode(', ', self::allStatuses())
			);
		}

		// Resolve amount if not manually overridden
		if (!$this->amountOverridden) {
			$this->data['amount'] = $this->resolveAmount();
		}

		// Delegate to the static CRUD engine
		return static::create($this->data);
	}

	/**
	 * Execute the UPDATE flow.
	 *
	 * Only sends fields that were explicitly set by the developer.
	 * Returns the payment ID on success (including no-op), false on failure.
	 *
	 * @return  int|false
	 */
	protected function doBuilderUpdate(): int|false
	{
		if (!$this->paymentId) {
			throw new \RuntimeException(
				'OrderPaymentHelper: No payment ID for update. Use ::load($id) first.'
			);
		}

		// Only fields explicitly set (beyond the always-present id_order)
		$changes = array_diff_key($this->data, ['id_order' => true]);

		if (empty($changes)) {
			// No-op: nothing to change, return current ID
			return $this->paymentId;
		}

		$success = static::update($this->paymentId, $this->data);

		return $success ? $this->paymentId : false;
	}

	/**
	 * Resolve payment amount from the order's grand total.
	 *
	 * total_paid_tax_incl is always correct regardless of context:
	 *   - Checkout: overridden by OrderPlaceHelper from CartHelper
	 *   - Admin/API: computed by OrderModel::getItem() from items + shipping - discounts
	 *
	 * Falls back to OrderTotalHelper if total_paid_tax_incl is not set
	 * (defensive — shouldn't happen in normal flow).
	 *
	 * Called automatically by doBuilderCreate() when ->amount() was not called.
	 *
	 * @return  float
	 */
	protected function resolveAmount(): float
	{
		if (!$this->order) {
			return 0.0;
		}

		// Primary: read from the order's computed grand total (Money object)
		if (isset($this->order->total_paid_tax_incl) && is_object($this->order->total_paid_tax_incl)) {
			return $this->order->total_paid_tax_incl->getAmount();
		}

		// Fallback: recompute from DB tables (shouldn't reach here normally)
		return OrderTotalHelper::getOrderTotal((int) $this->order->id, $this->order);
	}

	// =====================================================================
	//
	//  ███████╗████████╗ █████╗ ████████╗██╗ ██████╗
	//  ██╔════╝╚══██╔══╝██╔══██╗╚══██╔══╝██║██╔════╝
	//  ███████╗   ██║   ███████║   ██║   ██║██║
	//  ╚════██║   ██║   ██╔══██║   ██║   ██║██║
	//  ███████║   ██║   ██║  ██║   ██║   ██║╚██████╗
	//  ╚══════╝   ╚═╝   ╚═╝  ╚═╝   ╚═╝   ╚═╝ ╚═════╝
	//
	//  STATIC CRUD ENGINE — the low-level database operations
	//
	//  The fluent builder delegates here. Also callable directly
	//  when the builder is overkill (batch operations, exotic plugins).
	//
	// =====================================================================

	/**
	 * Create a new order payment record.
	 *
	 * Auto-fills server-side defaults for missing fields:
	 *   - added          → current UTC timestamp
	 *   - id_employee    → current admin user ID
	 *   - id_currency    → inherited from the parent order
	 *   - payment_method → name snapshot (survives method deletion)
	 *
	 * @param   array  $data  Payment data. 'id_order' is REQUIRED.
	 *
	 * @return  int|false  New payment row ID, or false on failure
	 *
	 * @since   3.5.0
	 */
	public static function create(array $data): int|false
	{
		$orderId = (int) ($data['id_order'] ?? 0);

		if ($orderId <= 0) {
			self::warn('create(): id_order is required');
			return false;
		}

		// Ensure this is treated as an insert (strip any stale PK)
		unset($data[self::PK]);

		// Convert Money objects → float for database storage
		$data['amount'] = self::moneyToFloat($data['amount'] ?? 0);

		// Snapshot payment method name (survives method deletion)
		if (!empty($data['id_payment_method']) && empty($data['payment_method'])) {
			$data['payment_method'] = self::resolveMethodName(
				'Payment',
				(int) $data['id_payment_method']
			);
		}

		// Auto-fill server-side defaults
		if (empty($data['added'])) {
			$data['added'] = Factory::getDate('now', 'UTC')->toSql();
		}
		if (empty($data['id_employee'])) {
			$data['id_employee'] = self::getCurrentUserId();
		}
		if (empty($data['id_currency']) && $orderId > 0) {
			$data['id_currency'] = self::getOrderCurrencyId($orderId);
		}

		// Auto-set processed_at for completed payments
		if (($data['transaction_status'] ?? '') === self::STATUS_COMPLETED && empty($data['processed_at'])) {
			$data['processed_at'] = Factory::getDate('now', 'UTC')->toSql();
		}

		// Persist to database
		$success = self::saveToTable($data);

		if (!$success) {
			return false;
		}

		$newId = (int) $data[self::PK];

		// Log activity
		$methodName = $data['payment_method'] ?? '';
		$amount     = number_format((float) $data['amount'], 2);

		OrderModel::logOrderActivity(
			$orderId,
			'payment.added',
			"Added payment: {$methodName} — {$amount}",
			[
				'amount'              => (float) $data['amount'],
				'payment_method'      => $methodName,
				'transaction_status'  => $data['transaction_status'] ?? '',
				'payment_type'        => $data['payment_type'] ?? '',
				'transaction_id'      => $data['transaction_id'] ?? '',
				'refund_type'         => $data['refund_type'] ?? null,
				'refund_reason'       => $data['refund_reason'] ?? null,
				'id_refunded_payment' => $data['id_refunded_payment'] ?? null,
			],
			$newId
		);

		return $newId;
	}

	/**
	 * Update an existing order payment record.
	 *
	 * Only changed fields are logged. Numeric values are compared as
	 * floats to avoid false-positive diffs ("0.000000" vs "0").
	 *
	 * @param   int    $id    Payment row PK
	 * @param   array  $data  Fields to update. 'id_order' is REQUIRED for logging.
	 *
	 * @return  bool  True on success
	 *
	 * @since   3.5.0
	 */
	public static function update(int $id, array $data): bool
	{
		if ($id <= 0) {
			self::warn('update(): invalid payment ID');
			return false;
		}

		$orderId = (int) ($data['id_order'] ?? 0);

		if ($orderId <= 0) {
			self::warn('update(): id_order is required for activity logging');
			return false;
		}

		// Force the correct PK into the data array
		$data[self::PK] = $id;

		// Snapshot old values for change detection
		$oldSnapshot = self::getRaw($id);

		// Convert Money → float
		if (array_key_exists('amount', $data)) {
			$data['amount'] = self::moneyToFloat($data['amount']);
		}

		// Snapshot payment method name if method changed
		if (!empty($data['id_payment_method']) && empty($data['payment_method'])) {
			$data['payment_method'] = self::resolveMethodName(
				'Payment',
				(int) $data['id_payment_method']
			);
		}

		$success = self::saveToTable($data);

		if ($success && $oldSnapshot) {
			$trackFields = [
				'amount',
				'transaction_status',
				'payment_type',
				'id_payment_method',
				'payment_method',
				'transaction_id',
				'refund_type',
				'refund_reason',
				'processed_at',
			];
			$changes = AlfaHelper::buildDiff($oldSnapshot, $data, $trackFields);

			if (!empty($changes)) {
				$changedKeys = implode(', ', array_keys($changes));
				OrderModel::logOrderActivity(
					$orderId,
					'payment.edited',
					"Edited payment #{$id}: {$changedKeys}",
					$changes,
					$id
				);
			}
		}

		return $success;
	}

	/**
	 * Delete an order payment record.
	 *
	 * No builder needed — call statically:
	 *   OrderPaymentHelper::delete($id, $orderId);
	 *
	 * @param   int  $id       Payment row PK
	 * @param   int  $orderId  Order PK (for activity log)
	 *
	 * @return  bool  True on success
	 *
	 * @since   3.5.0
	 */
	public static function delete(int $id, int $orderId): bool
	{
		$payment = self::getRaw($id);

		$success = self::deleteFromTable($id);

		if ($success && $payment) {
			$methodName = $payment->payment_method ?? '';
			$amount     = number_format((float) ($payment->amount ?? 0), 2);

			OrderModel::logOrderActivity(
				$orderId,
				'payment.deleted',
				"Deleted payment: {$methodName} — {$amount}",
				[
					'amount'             => (float) ($payment->amount ?? 0),
					'payment_method'     => $methodName,
					'transaction_status' => $payment->transaction_status ?? '',
					'payment_type'       => $payment->payment_type ?? '',
				],
				$id
			);
		}

		return $success;
	}

	// =====================================================================
	//  STATIC READ API
	// =====================================================================

	/**
	 * Load a single payment with its method params attached.
	 *
	 * Returns the raw DB row enriched with:
	 *   - params → full payment method record (from PaymentModel)
	 *
	 * @param   int  $id  Payment row PK
	 *
	 * @return  object|null  Payment record with params, or null if not found
	 *
	 * @since   3.5.0
	 */
	public static function get(int $id): ?object
	{
		if ($id <= 0) {
			return null;
		}

		$db    = self::db();
		$query = $db->getQuery(true)
			->select('a.*')
			->from($db->quoteName(self::TABLE, 'a'))
			->where($db->quoteName('a.id') . ' = ' . (int) $id);

		$db->setQuery($query);
		$payment = $db->loadObject();

		if (!$payment) {
			return null;
		}

		// Attach the payment method configuration
		try {
			$paymentModel    = self::getRelatedModel('Payment');
			$payment->params = $paymentModel->getItem($payment->id_payment_method);
		} catch (\Exception $e) {
			$payment->params = null;
		}

		return $payment;
	}

	/**
	 * Load all payments for an order (lightweight — no method params).
	 *
	 * For fully-enriched records, call get($id) on individual items.
	 *
	 * @param   int  $orderId  Order PK
	 *
	 * @return  array  Array of payment row objects
	 *
	 * @since   3.5.0
	 */
	public static function getByOrder(int $orderId): array
	{
		if ($orderId <= 0) {
			return [];
		}

		$db    = self::db();
		$query = $db->getQuery(true)
			->select('*')
			->from(self::TABLE)
			->where('id_order = ' . (int) $orderId);

		$db->setQuery($query);

		return $db->loadObjectList() ?: [];
	}

	// =====================================================================
	//  INTERNAL — Database Operations
	// =====================================================================

	/**
	 * Load a raw payment row (no enrichment, no Money conversion).
	 *
	 * Used internally for snapshots (diffing) and pre-delete capture.
	 *
	 * @param   int  $id  Payment row PK
	 *
	 * @return  object|null  Raw DB row
	 *
	 * @since   3.5.0
	 */
	private static function getRaw(int $id): ?object
	{
		$db    = self::db();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->quoteName(self::TABLE))
			->where($db->quoteName(self::PK) . ' = ' . (int) $id);

		$db->setQuery($query);

		return $db->loadObject() ?: null;
	}

	/**
	 * Save data to the payments table (insert or update).
	 *
	 * Filters to actual DB columns to prevent "Unknown column" SQL errors.
	 *
	 * @param   array  &$data  Data array (by reference — PK is set on insert)
	 *
	 * @return  bool  True on success
	 *
	 * @since   3.5.0
	 */
	private static function saveToTable(array &$data): bool
	{
		$db = self::db();

		// Filter to real DB columns — unknown keys safely stripped
		$tableColumns = $db->getTableColumns(self::TABLE);
		$filtered     = array_intersect_key($data, $tableColumns);

		if (empty($filtered)) {
			self::warn('saveToTable(): no valid columns after filtering');
			return false;
		}

		// Sanitize nullable columns — forms send '' which MySQL strict rejects
		foreach (self::NULLABLE_INT_COLUMNS as $col) {
			if (array_key_exists($col, $filtered) && $filtered[$col] === '') {
				$filtered[$col] = null;
			}
		}
		foreach (self::NULLABLE_DATE_COLUMNS as $col) {
			if (array_key_exists($col, $filtered) && empty($filtered[$col])) {
				$filtered[$col] = null;
			}
		}
		foreach (self::NULLABLE_ENUM_COLUMNS as $col) {
			if (array_key_exists($col, $filtered) && $filtered[$col] === '') {
				$filtered[$col] = null;
			}
		}

		$isNew = empty($filtered[self::PK]) || (int) $filtered[self::PK] === 0;

		try {
			if ($isNew) {
				unset($filtered[self::PK]);
				$obj = (object) $filtered;
				$db->insertObject(self::TABLE, $obj, self::PK);
				$data[self::PK] = $obj->{self::PK} ?? $db->insertid();
			} else {
				$obj = (object) $filtered;
				$db->updateObject(self::TABLE, $obj, self::PK);
				$data[self::PK] = $filtered[self::PK];
			}
		} catch (\Exception $e) {
			self::warn('saveToTable() failed: ' . $e->getMessage());
			return false;
		}

		return true;
	}

	/**
	 * Delete a single row from the payments table.
	 *
	 * @param   int  $id  Row PK
	 *
	 * @return  bool  True on success
	 *
	 * @since   3.5.0
	 */
	private static function deleteFromTable(int $id): bool
	{
		$db    = self::db();
		$query = $db->getQuery(true)
			->delete($db->quoteName(self::TABLE))
			->where($db->quoteName(self::PK) . ' = ' . (int) $id);

		try {
			$db->setQuery($query);
			$db->execute();
		} catch (\Exception $e) {
			self::warn('deleteFromTable() failed: ' . $e->getMessage());
			return false;
		}

		return true;
	}

	// =====================================================================
	//  INTERNAL — Utilities
	// =====================================================================

	/**
	 * Get the Joomla database driver from the DI container.
	 *
	 * @return  \Joomla\Database\DatabaseDriver
	 *
	 * @since   3.5.0
	 */
	private static function db(): \Joomla\Database\DatabaseDriver
	{
		return Factory::getContainer()->get('DatabaseDriver');
	}

	/**
	 * Convert a Money object (or numeric value) to float for DB storage.
	 *
	 * @param   mixed  $value  Money object, numeric string, or number
	 *
	 * @return  float
	 *
	 * @since   3.5.0
	 */
	private static function moneyToFloat(mixed $value): float
	{
		if ($value instanceof Money) {
			return $value->getAmount();
		}

		return (float) ($value ?? 0);
	}

	/**
	 * Resolve a payment method name from its model.
	 *
	 * Snapshot at save time — survives method deletion.
	 *
	 * @param   string  $modelName  'Payment' or 'Shipment'
	 * @param   int     $methodId   Method row PK
	 *
	 * @return  string  Method name, or empty string if not found
	 *
	 * @since   3.5.0
	 */
	private static function resolveMethodName(string $modelName, int $methodId): string
	{
		if ($methodId <= 0) {
			return '';
		}

		try {
			$model  = self::getRelatedModel($modelName);
			$method = $model->getItem($methodId);
			return $method->name ?? '';
		} catch (\Exception $e) {
			return '';
		}
	}

	/**
	 * Get a related admin model via Joomla's MVC factory.
	 *
	 * @param   string  $name  Model name: 'Payment', 'Shipment', etc.
	 *
	 * @return  \Joomla\CMS\MVC\Model\AdminModel
	 *
	 * @since   3.5.0
	 */
	private static function getRelatedModel(string $name)
	{
		return Factory::getApplication()
			->bootComponent('com_alfa')
			->getMVCFactory()
			->createModel($name, 'Administrator', ['ignore_request' => true]);
	}

	/**
	 * Get the currency ID assigned to an order.
	 *
	 * @param   int  $orderId  Order PK
	 *
	 * @return  int  Currency ID (defaults to 1)
	 *
	 * @since   3.5.0
	 */
	private static function getOrderCurrencyId(int $orderId): int
	{
		$db    = self::db();
		$query = $db->getQuery(true)
			->select('id_currency')
			->from('#__alfa_orders')
			->where('id = ' . (int) $orderId);
		$db->setQuery($query);

		return (int) ($db->loadResult() ?? 1);
	}

	/**
	 * Get the current authenticated user's ID.
	 *
	 * Returns 0 for guests and CLI contexts.
	 *
	 * @return  int
	 *
	 * @since   3.5.0
	 */
	private static function getCurrentUserId(): int
	{
		try {
			return Factory::getApplication()->getIdentity()->id ?? 0;
		} catch (\Exception $e) {
			return 0;
		}
	}

	/**
	 * Log a warning to Joomla log and admin message area.
	 *
	 * @param   string  $message  Warning text
	 *
	 * @return  void
	 *
	 * @since   3.5.0
	 */
	private static function warn(string $message): void
	{
		Log::add('[OrderPaymentHelper] ' . $message, Log::WARNING, 'com_alfa.orders');

		try {
			Factory::getApplication()->enqueueMessage($message, 'error');
		} catch (\Exception $e) {
			// Silently ignore — CLI or early boot context
		}
	}
}