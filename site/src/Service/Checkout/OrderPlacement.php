<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Complete V3 order placement helper - production grade
 *
 * Features:
 * - Saves to ALL 7 V3 tables in single atomic transaction
 * - Professional try/catch error handling everywhere
 * - Comprehensive logging for debugging
 * - V3 field names (date_add, product_id, payment_method_name, etc.)
 * - Backward compatible with V2 fields
 * - Self-contained architecture (no backend dependencies)
 *
 * Tables Created:
 * 1. #__alfa_orders               (main order with V3 fields)
 * 2. #__alfa_order_items          (line items with V3 fields)
 * 3. #__alfa_order_payments       (payment records)
 * 4. #__alfa_order_shipments      (shipment records)
 * 5. #__alfa_order_activity_log   (unified event log)
 * 7. #__alfa_order_cart_rule      (applied discounts/coupons)
 * 8. #__alfa_user_info            (customer address)
 *
 * Usage:
 *   $helper = new OrderPlacement();
 *   $success = $helper->placeOrder($userFormData, $billingFormData);
 *   if ($success) {
 *       $order = $helper->getOrder();
 *       // Redirect to success page
 *   }
 */

namespace Alfa\Component\Alfa\Site\Service\Checkout;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Orders\OrderPlacedEvent;
use Alfa\Component\Alfa\Administrator\Event\Orders\OrderPlaceValidateEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderAfterPlaceEvent as PaymentOrderAfterPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Payments\OrderBeforePlaceEvent as PaymentOrderBeforePlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\OrderAfterPlaceEvent as ShipmentOrderAfterPlaceEvent;
use Alfa\Component\Alfa\Administrator\Event\Shipments\OrderBeforePlaceEvent as ShipmentOrderBeforePlaceEvent;
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartSubject;
use Alfa\Component\Alfa\Administrator\Service\Cart\CommercialPlugins;
use Alfa\Component\Alfa\Administrator\Service\Cart\CustomerDetailsValidator;
use Alfa\Component\Alfa\Administrator\Service\Cart\OperatorPaymentPolicy;
use Alfa\Component\Alfa\Administrator\Service\Cart\Plugin\PlacementEventContext;
use Alfa\Component\Alfa\Administrator\Service\Download\Entitlements;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Order\EmailService;
use Alfa\Component\Alfa\Administrator\Service\Order\ItemFieldsSnapshot;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderCreator;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderIdentity;
use Alfa\Component\Alfa\Administrator\Service\Order\OrderLineMoney;
use Alfa\Component\Alfa\Administrator\Service\Order\StockPolicy;
use Alfa\Component\Alfa\Site\Service\Cart\MethodRequirement;
use Alfa\Component\Alfa\Site\Service\Pricing\ChargeableQuantity;
use Alfa\Component\Alfa\Site\Service\Pricing\Money;
use Alfa\Component\Alfa\Site\Service\Pricing\PriceResult;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Utilities\IpHelper;
use RuntimeException;
use stdClass;
use Throwable;

class OrderPlacement
{
    protected DatabaseDriver $db;
    protected $app;

    /**
     * @var \Joomla\CMS\User\User THE CUSTOMER: whose order this is. Everything
     *                            that costs money or belongs to somebody follows this person: the
     *                            snapshot, id_user, the addresses, the coupon quota and the credit
     *                            wallet. Never the operator.
     */
    protected $user;

    /**
     * @var CartSubject Who the order is for, and who is placing it.
     * @since  2.0.0
     */
    protected CartSubject $subject;
    protected ?PlacementCart $cart = null;
    protected ?object $order = null;
    protected string $payment_type = '';
    protected string $shipment_type = '';

    // V3 Database tables
    protected string $order_table = '#__alfa_orders';
    protected string $order_items_table = '#__alfa_order_items';
    protected string $order_payments_table = '#__alfa_order_payments';
    protected string $order_shipments_table = '#__alfa_order_shipments';
    protected string $order_activity_log_table = '#__alfa_order_activity_log';
    protected string $order_cart_rule_table = '#__alfa_order_cart_rule';
    protected string $user_info_table = '#__alfa_user_info';

    /** @var object Default order status (loaded from DB, not hardcoded) */
    protected ?object $defaultStatus = null;

    /** @var array The delivery section's validated values; the guest's identity lives here. */
    protected array $deliveryData = [];

    /** @var array The invoice section's validated values; empty = invoice same as delivery. */
    protected array $billingData = [];

    /**
     * @var PlacementCommand|null What this placement was asked to do.
     * @since  2.0.0
     */
    protected ?PlacementCommand $command = null;

    /**
     * @var array<int, array{code: string, params: array}> Why it was refused.
     * @since  2.0.0
     */
    protected array $failures = [];

    /**
     * @var array<int, array{code: string, params: array}> What did not finish
     *                                                     AFTER the commit. Never a reason the order does not exist.
     * @since  2.0.0
     */
    protected array $warnings = [];

    /**
     * @var bool Set the instant COMMIT returns, and never unset.
     * @since  2.0.0
     */
    protected bool $committed = false;

    /**
     * @var int The order the commit created. Independent of $this->order,
     *          which is a convenience reload that can itself fail.
     * @since  2.0.0
     */
    protected int $committedOrderId = 0;

    /**
     * @var array<string, \Alfa\Component\Alfa\Site\Service\Pricing\Money>|null
     *                                                                          What the purchase came to, read while the cart still held it.
     * @since  2.0.0
     */
    private ?array $completedTotals = null;

    public const DEFAULT_CURRENCY_NUMBER = 978;

    /**
     * @param PlacementCart|null $cart The cart to convert; the request's when omitted.
     * @param User|null $customer THE CUSTOMER the order is for; the request's
     *                            identity when omitted.
     * @param CartSubject|null $subject Customer and actor together, for an
     *                                  operation performed on somebody's behalf.
     *                                  Supersedes $customer when both are given.
     *
     * @since  1.0.0
     */
    public function __construct(?PlacementCart $cart = null, ?User $customer = null, ?CartSubject $subject = null)
    {
        try {
            $this->app = Factory::getApplication();
            $this->db = Factory::getContainer()->get('DatabaseDriver');

            // BOTH DEFAULT TO THE REQUEST, which is what a storefront checkout
            // is and why every existing caller passes neither. Supplying them is
            // how an administrator, an importer or a test places an order for
            // someone else: the cart to convert and the person it belongs to are
            // inputs, not ambient facts.
            //
            // PHP does not check a constructor's signature against its parent,
            // so adding these breaks no subclass.
            // THE CUSTOMER AND THE ACTOR ARE NOT THE SAME QUESTION. This class
            // held one property for both and wrote it into two columns 26 lines
            // apart: id_user, the person who owes the money, and created_by,
            // the person who pressed the button. On a storefront checkout they
            // are one person and every branch below collapses to what it did
            // before; they part company the moment an order is placed for
            // somebody else, and then only one of them may be charged.
            //
            // Argument two keeps its meaning: the CUSTOMER. A subject supersedes
            // it because it says the same thing and more.
            //
            // PHP does not check a constructor's signature against its parent,
            // so adding these breaks no subclass.
            $this->subject = $subject
                ?? ($customer !== null
                    ? CartSubject::of($customer, $this->app->getIdentity() ?? new User(0))
                    : CartSubject::ambient());

            $this->user = $this->subject->customer();

            // The cart is priced for the CUSTOMER, so it is built with the same
            // subject. A cart handed in was already built with one of its own,
            // and is left alone: re-subjecting somebody else's object here would
            // silently re-price a cart the caller had already settled.
            $this->cart = $cart ?? PlacementCart::current($this->subject);
            $this->configureLogging();

            // Load the default order status from the table: the row
            // currently marked is_initial=1, falling back to the first
            // published row by ordering when no role has been nominated.
            $this->defaultStatus = StockPolicy::getDefaultOrderStatus();

            Log::add(
                'Default order status: "' . $this->defaultStatus->name
                . '" (id=' . $this->defaultStatus->id
                . ', stock_operation=' . $this->defaultStatus->stock_operation . ')',
                Log::DEBUG,
                'com_alfa.orders',
            );
        } catch (Exception $e) {
            Log::add('OrderPlacement init failed: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            throw new RuntimeException('Failed to initialize OrderPlacement: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Register the file logger for the com_alfa.orders category; failures are sent to error_log.
     *
     *
     * @since  1.0.0
     */
    protected function configureLogging(): void
    {
        try {
            // Delegated: AlfaLog owns the whole map. This used to be the ONLY
            // registration of com_alfa.orders, and it runs during site
            // checkout, so all 23 admin-side calls to that category were
            // silently dropped.
            AlfaLog::register();
        } catch (Exception $e) {
            error_log('Failed to configure logging: ' . $e->getMessage());
        }
    }

    /**
     * Get the placed order object loaded after a successful placement.
     *
     * @return object|null The order, or null if no order has been placed.
     *
     * @since  1.0.0
     */
    public function getOrder(): ?object
    {
        return $this->order;
    }

    /**
     * Main order placement
     *
     * Flow:
     *   1. Validate prerequisites (cart, payment, shipment methods)
     *   2. Fire onOrderBeforePlace to payment + shipment plugins
     *   3. BEGIN TRANSACTION
     *      a. Save user info
     *      b. Save order (with dynamic default status)
     *      c. Save order items
     *      d. Deduct stock (IF default status requires it)
     *      e. Log order creation
     *   4. COMMIT
     *   5. Load complete order from model
     *   6. Attach cart for plugins
     *   7. Fire onOrderAfterPlace -> plugins create payment + shipment records
     *   8. Clear cart
     *
     * Stock deduction respects the default order status's stock_operation:
     *   stock_operation = 0 -> deduct stock (Confirmed, Shipped, etc.)
     *   stock_operation = 1 -> keep stock   (rare: default status = Pending)
     *
     * @param array $data User form data (address fields), already validated + filtered.
     * @param array $billing The invoice section's validated values; empty = same as delivery.
     * @return bool True on success, false on failure
     * @since  1.0.0
     */
    public function placeOrder(array $data, array $billing = []): bool
    {
        // THE ONLY PLACE THIS CLASS TOUCHES THE REQUEST. A storefront checkout
        // IS an HTTP POST, so reading the three chosen values here is right;
        // reading them anywhere below is what stopped an order being placed by
        // an administrator, an importer, a scheduled reorder or a test.
        return $this->place(new PlacementCommand(
            delivery:         $data,
            billing:          $billing,
            paymentMethodId:  (int) $this->app->input->getInt('payment_method', 0),
            shipmentMethodId: (int) $this->app->input->getInt('shipment_method', 0),
            useCredit:        (int) $this->app->input->getInt('use_credit', 0) === 1,
        ));
    }

    /**
     * Place an order from a command: the same operation, without a request.
     *
     * Nothing below this line reads Input. What it still needs from the world it
     * is given: the cart to convert and the person placing it both arrive
     * through the constructor.
     *
     * @param PlacementCommand $command What to place, and how it was chosen.
     *
     * @return bool True on success, false on failure.
     *
     * @since  2.0.0
     */
    public function place(PlacementCommand $command): bool
    {
        $this->command = $command;
        $this->deliveryData = $command->delivery;
        $this->billingData = $command->billing;

        try {
            Log::add('=== Starting V3 order placement ===', Log::INFO, 'com_alfa.orders');

            // The delivery place of THIS submission decides which methods are offered.
            $this->cart->bindDeliveryPlace(self::deliveryPlaceFromData($this->deliveryData));

            if (!$this->validateOrderPrerequisites()) {
                Log::add('Order prerequisite validation failed', Log::WARNING, 'com_alfa.orders');
                return false;
            }

            $paymentMethodId = $command->paymentMethodId;
            $shipmentMethodId = $command->shipmentMethodId;

            Log::add("Payment: {$paymentMethodId}, Shipment: {$shipmentMethodId}", Log::DEBUG, 'com_alfa.orders');

            if (!$this->triggerBeforePlaceEvents($paymentMethodId, $shipmentMethodId)) {
                Log::add('Pre-placement event validation failed', Log::WARNING, 'com_alfa.orders');
                return false;
            }

            // BEGIN TRANSACTION
            Log::add('Starting database transaction', Log::DEBUG, 'com_alfa.orders');
            $this->db->transactionStart(true);

            try {
                // A cart converts to at most one order
                //
                // Two requests carrying the same cart (a double-clicked Place
                // Order, a browser retry, a proxy replay) each used to run the
                // whole placement and produce a second order, with its own
                // reservations, its own stock deduction, its own payment row
                // and its own emails.
                //
                // THE CART ROW IS THE SERIALISATION POINT AND THE TOKEN AT
                // ONCE. It is locked as the FIRST statement in the envelope, so
                // both requests queue here; the winner deletes it inside this
                // same transaction as the last step of conversion, so when the
                // loser finally takes the lock the row is GONE and there is
                // nothing left to convert.
                //
                // The read is a LOCKING read on purpose. Under REPEATABLE READ
                // the loser's snapshot predates the winner's commit, and taking
                // a lock does NOT refresh a snapshot: the same trap the
                // order-row work hit in S7. Only a current read sees that the
                // cart has just ceased to exist.
                //
                // This is why the ORDER needs no memory of its cart: the
                // guarantee lives in the cart's existence, not in a pointer
                // back to it, and the order stays a self-contained record.
                $cartId = $this->cart->cartId();

                if ($cartId > 0) {
                    $this->db->setQuery(
                        'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_cart')
                        . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $cartId . ' FOR UPDATE',
                    );

                    if ((int) $this->db->loadResult() !== $cartId) {
                        // The cart was converted (or cleared) while this request
                        // waited for the lock. Refusing is the whole protection:
                        // the in-memory cart this request still holds would
                        // otherwise become a second order for the same goods.
                        Log::add(
                            'Duplicate placement refused for cart #' . $cartId
                            . ': the cart no longer exists; it was converted by another request.',
                            Log::WARNING,
                            'com_alfa.orders',
                        );

                        $this->fail('COM_ALFA_ORDER_PLACE_CART_EMPTY');
                        TransactionState::rollBackLevel($this->db);

                        return false;
                    }
                }

                // Save user info
                Log::add('Saving user information...', Log::DEBUG, 'com_alfa.orders');
                // THE BUCKET THE CHECKS SETTLED ON, never the one handed in: an
                // operator placement adopts the form's filtered copy above.
                $userInfoObject = $this->saveUserInfo($this->deliveryData);
                if (!$userInfoObject) {
                    throw new RuntimeException('Failed to save user information');
                }
                Log::add('User info saved with ID: ' . $userInfoObject->id, Log::INFO, 'com_alfa.orders');
                // Placement-local, and deliberately so: the order freezes its own
                // copies of these ids a few lines below, and the cart itself is
                // discarded before this transaction commits. The cart neither
                // learns nor stores them. The LIVE-cart pointer is written where
                // it earns its keep: when the shopper picks a saved address.
                $this->cart->freezeAddresses(
                    (int) $userInfoObject->id,
                    (int) $this->resolveInvoiceInfo((int) $userInfoObject->id),
                );

                // PERSISTENCE: delegated to the single write path.
                //
                // This helper is an ORCHESTRATION layer: it validates, prices
                // (via the cart's PriceResults) and sequences; the actual
                // order + lines + stock + creation log are written by
                // OrderCreator, the same service renewals, admin manual
                // orders and any future API use. manageTransaction: false;
                // we already hold the transaction.
                // The applied rules' usage, re-checked under its locks BEFORE
                // the order exists: the usage rows are read joined with other
                // orders' rows, and those are taken before any stock record,
                // in the order a status change takes an order and then its
                // stock. Taken after the reservation below, they formed a
                // cycle with a status change of one of those orders.
                $this->assertCartRuleUsageAvailable();

                Log::add('Creating order via OrderCreator...', Log::DEBUG, 'com_alfa.orders');
                $created = OrderCreator::create(
                    draft:             $this->buildOrderDraft($paymentMethodId, $shipmentMethodId),
                    manageTransaction: false,
                );
                $orderId = $created->orderId;
                Log::add('Order saved with ID: ' . $orderId, Log::INFO, 'com_alfa.orders');

                // Cart-specific per-line extras: freeze the chosen item-field
                // configuration + per-line tax breakdown (need the live cart
                // objects, so they cannot live inside the cart-free service).
                $this->freezeCartLineExtras($created->lineIds, $orderId);

                // MONEY IS NOT A SNAPSHOT. The item-field configuration and
                // the per-line tax breakdown above are informational: a row
                // that fails to write is logged and the sale stands. The two
                // rows below are what the order's money is later rebuilt from,
                // and a payment row is about to be sized from the same cart
                // total. A failure there reaches the envelope and refuses the
                // placement, so an order can never commit with a money history
                // that disagrees with what it collects.
                $this->saveOrderContributions($orderId);
                $this->saveCartRules($orderId);

                // The last step of conversion, not cleanup
                //
                // The order above is now a complete, self-contained record:
                // frozen lines, prices, tax, the customer snapshot, the method
                // snapshots. The cart has no further business value, and it is
                // deleted INSIDE this transaction on purpose: the cart ceases
                // to exist at the same instant the order becomes real, so no
                // state exists in which both are true and nothing downstream
                // can grow a dependency on a converted cart.
                //
                // It is also the duplicate guard: the loser of a race takes the
                // cart-row lock, finds the row gone, and refuses. That is why
                // the order needs no id_cart.
                // WHAT THE PURCHASE CAME TO, read while the cart still holds
                // it. One line below, discard() replaces the loaded cart with an
                // empty object; every total taken after that point is a total of
                // nothing but shipping. Reading them here is the difference
                // between charging for the goods and charging for the postage.
                $this->captureCompletedTotals();

                if (!$this->cart->discard()) {
                    throw new RuntimeException('The cart could not be discarded after placement.');
                }
            } catch (Throwable $e) {
                // ROLLBACK: items, stock, everything reverted. \Throwable,
                // not Exception: a PHP \Error must roll back too, or the
                // connection is left inside a dangling transaction.
                TransactionState::rollBackLevel($this->db, $e);
                Log::add('=== Transaction rolled back ===', Log::ERROR, 'com_alfa.orders');
                // The detail stays in the log, where it is the only thing that
                // makes a failed checkout diagnosable. COM_ALFA_ORDER_PLACE_FAILED
                // interpolates it, so the shopper was shown table and constraint
                // names at the worst possible moment.
                Log::add('Error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
                $this->fail('COM_ALFA_ORDER_PLACEMENT_FAILED', [], '', null, 'error');

                return false;
            }

            // COMMIT, after the try: what follows it is on the far side of the
            // commit, and a failure there is answered by the net below, never
            // by rolling back a transaction that is already closed.
            TransactionState::commitLevel($this->db);

            // THE ORDER NOW EXISTS, AND NOTHING AFTER THIS LINE CAN MAKE
            // THAT UNTRUE. Recorded here rather than derived later from
            // $this->order, because $this->order is a reload that can fail
            // on its own, and when it did, a committed order reported
            // itself as a failed checkout. The flag is set only on the far
            // side of a commit that returned, so a rollback can never
            // produce one.
            $this->committed = true;
            $this->committedOrderId = (int) $orderId;

            Log::add('=== Transaction committed successfully ===', Log::INFO, 'com_alfa.orders');

            // Load complete order (with items, payments, shipments via admin OrderModel)
            $this->order = $this->loadOrderModel($orderId);

            // Back-office heads-up: a new order landed. Deduped per order,
            // gated to whoever may see orders, cleared when the order is opened.
            try {
                Factory::getApplication()->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR);
                \Alfa\Component\Alfa\Administrator\Helper\NotificationHelper::push(
                    dedupKey: 'order:' . $orderId,
                    title: Text::sprintf('COM_ALFA_NOTIFY_NEW_ORDER_TITLE', $orderId),
                    options: [
                        'group' => Text::_('COM_ALFA_NOTIFY_GROUP_ORDERS'),
                        'severity' => 'info',
                        'url' => 'index.php?option=com_alfa&task=order.edit&id=' . $orderId,
                        'view' => ['action' => 'alfa.orders.view', 'asset' => 'com_alfa'],
                        'link' => ['action' => 'alfa.orders.edit', 'asset' => 'com_alfa'],
                    ],
                );
            } catch (Exception $notifyError) {
                Log::add('New-order notification failed: ' . $notifyError->getMessage(), Log::WARNING, 'com_alfa.orders');
            }

            // The reload is not the order
            //
            // The order is COMMITTED. Its id is recorded and authoritative, and
            // everything below that needs only that id must still happen:
            // download entitlements and the placement email among them. What
            // the reload provides is an order OBJECT, which the wallet, the
            // gateway hooks and the placement broadcast are given; when it
            // comes back empty those cannot run, and assigning totals onto it
            // used to raise an Error that took the whole tail down with it,
            // including the effects that never needed it.
            //
            // So the object's dependants are gathered here, the id's are left
            // outside, and a failed reload is reported rather than fatal: the
            // shopper is told the order stands but part of it could not be set
            // up, which is the truth and the same sentence a failed gateway
            // hook produces.
            if ($this->order !== null) {
                // Override totals from cart for checkout context
                //
                // getItem() returned shipping = 0 because the shipment DB record
                // doesn't exist yet (created by the shipment plugin below).
                // Override with cart values so the order object is fully consistent
                // BEFORE plugins fire; plugins just read $order->total_paid_tax_incl.
                //
                // Product totals are correct (items exist in DB after commit).
                // Discount rows were frozen by saveCartRules() above.
                // GROSS on purpose. The shipment row records what the CARRIER
                // charged; the shipping-class rule row records the benefit taken
                // off it. Freezing the net here would record the benefit twice and
                // OrderTotalHelper would subtract it again: invisible on a full
                // free-shipping order (the floor hides it) but short by the
                // discount on any PARTIAL shipping rule.
                $this->order->total_shipping_tax_incl = $this->completedTotals['shipping_incl'];
                $this->order->total_shipping_tax_excl = $this->completedTotals['shipping_excl'];
                // The rate the carriage was taxed at travels with it: a rate
                // cannot be recovered from the rounded amounts, and the carrier
                // that writes the parcel row freezes it.
                $this->order->shipping_tax_rate = $this->completedTotals['shipping_rate'];
                // And how its figures were formed: under its method's entry mode,
                // or as the carrier stated them.
                $this->order->shipping_charge_basis = $this->completedTotals['shipping_basis'];
                // And the ordered rates it was calculated with: the combined
                // rate cannot reproduce a chain's per-rate rounding.
                $this->order->shipping_tax_chain = $this->completedTotals['shipping_chain'];
                $this->order->total_paid_tax_incl = $this->completedTotals['paid_incl'];
                $this->order->total_paid_tax_excl = $this->completedTotals['paid_excl'];

                // Customer credit (wallet): BEFORE the gateway event
                // The POSTed opt-in is the only authority (never a stored flag:
                // the balance can change between render and submit), the debit
                // fires only now that the order exists (no reservation, ever),
                // and it lands BEFORE onOrderAfterPlace so the gateway plugin's
                // amountToCollect() resolves to the remainder. A wallet failure
                // degrades: the order stands, the gateway collects the full
                // total, exactly as if the customer had not opted in.
                $this->applyCustomerCredit();

                // Fire after events: plugins create payment + shipment records.
                // The order is committed, so a failure cannot undo it; but it must
                // reach a human rather than being logged and forgotten.
                $afterFailures = $this->triggerAfterPlaceEvents();
            } else {
                // The hooks that create the payment and shipment rows were not
                // reached at all, which downstream reads exactly like a hook
                // that failed, so it is reported as one, by the same key.
                $afterFailures = ['payment', 'shipment'];

                Log::add(
                    'Order #' . $orderId . ' committed, but reloading it returned nothing:'
                        . ' the wallet, the gateway hooks and the placement broadcast were skipped.',
                    Log::ERROR,
                    'com_alfa.orders',
                );
            }

            if ($afterFailures !== []) {
                $this->warn(
                    'COM_ALFA_ORDER_PLACE_AFTER_HOOK_FAILED',
                    ['hooks' => $afterFailures],
                    Text::sprintf('COM_ALFA_ORDER_PLACE_AFTER_HOOK_FAILED', implode(', ', $afterFailures)),
                );
            }

            // The cart is already gone: discarded inside the transaction, as
            // the last step of conversion.

            // Instant digital delivery: when the initial status is already in
            // the is_completed family, grant download entitlements NOW so the
            // placement email below carries the links (the raw tokens only
            // live in this request's cache). No-op otherwise; the usual
            // grant happens on the later paid/completed transition.
            Entitlements::handleStatusTransition(
                orderId:     $orderId,
                oldStatusId: 0,
                newStatusId: (int) $this->defaultStatus->id,
            );

            // Frontend order placement is a "no status -> initial status"
            // transition from the customer's perspective. Reuse the same
            // helper the admin OrderModel hook calls, so the per-status
            // notify_customer flag + admin recipient list governs both
            // paths uniformly. Mail failures are swallowed inside the
            // helper; they never block order placement.
            EmailService::sendForStatusChange(
                orderId:     $orderId,
                newStatusId: (int) $this->defaultStatus->id,
            );

            // onAlfaOrderPlaced: the placement broadcast
            // Terminal position on purpose: everything above has persisted, so listeners
            // observe FINAL state: the same discipline Transition applies to
            // onOrderStatusChange. This is the ONE order moment that happens in the
            // SHOPPER'S OWN REQUEST (later transitions run in gateway callbacks or admin
            // sessions), which is why search-conversion attribution listens here and
            // nowhere else. `alfa` is already imported by the component boot; the search
            // group is loaded deliberately: it is where the first consumer lives. A
            // listener failure is logged and swallowed: the order is already placed, and
            // no observer may break or appear to break a completed checkout.
            try {
                PluginHelper::importPlugin('alfa-search');

                // The broadcast carries the order itself, so it is one of the
                // reload's dependants: with nothing to carry there is nothing
                // to broadcast, and the skip is already reported above.
                if ($this->order !== null) {
                    $this->app->getDispatcher()->dispatch(
                        'onAlfaOrderPlaced',
                        new OrderPlacedEvent(order: $this->order, orderId: (int) $orderId),
                    );
                }
            } catch (Throwable $e) {
                Log::add(
                    'onAlfaOrderPlaced listener failed for order ' . $orderId . ': ' . $e->getMessage(),
                    Log::ERROR,
                    'com_alfa.orders',
                );
            }

            Log::add('=== Order placement completed successfully ===', Log::INFO, 'com_alfa.orders');
            return true;
        } catch (Throwable $e) {
            // \Throwable: the last net before the buyer sees a white page.
            Log::add(
                'CRITICAL ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                Log::CRITICAL,
                'com_alfa.orders',
            );

            // WHICH SIDE OF THE COMMIT DID THIS HAPPEN ON?
            //
            // Before it, nothing durable was written and the answer is a plain
            // no. After it, the order exists (the stock has moved, the cart is
            // gone) and answering no is a lie with consequences: a shopper sees
            // an error for an order they will be charged for, and an operator is
            // invited to place it a second time. Everything from the reload of
            // the order onwards is on that far side, which is exactly where this
            // net used to catch and answer no.
            if ($this->committed) {
                $this->warn(
                    'COM_ALFA_ORDER_PLACE_POSTCOMMIT_WARNING',
                    ['order' => $this->committedOrderId],
                );

                return true;
            }

            $this->fail('COM_ALFA_ORDER_PLACE_CRITICAL_ERROR', [], '', null, 'error');

            return false;
        }
    }

    /**
     * Validate that the order can be placed: non-empty cart plus valid payment and shipment methods.
     * Stores the resolved shipment id on the cart on success and enqueues a message on each failure.
     *
     * @return bool True when all prerequisites pass, false otherwise.
     *
     * @since  1.0.0
     */
    /**
     * Record why a placement is being refused, and tell the shopper if there is
     * one.
     *
     * A storefront refusal is a sentence on a page: the right words, already
     * translated, in the language the shopper is reading, queued for the view
     * they are about to be returned to. That works because the site's language
     * is loaded and the shopper is standing in front of it.
     *
     * An operator placement has neither. It runs in the back office, where the
     * site's com_alfa strings are not loaded at all, so the same call queues a
     * raw language constant into a message list the operator may never see, for
     * an action taken on somebody else's behalf. And a caller that wanted to
     * report the failure properly would have to read it back out of a message
     * queue and match on its text, which is not a contract.
     *
     * So the reason is always RECORDED, as a key and its parameters, which a
     * caller can translate for whoever needs to read it; and it is enqueued
     * only when there is a shopper to enqueue it for. The pre-rendered message
     * is passed in rather than rebuilt here so that the storefront's wording
     * stays byte-identical to what it has always been.
     *
     * @param string $code The language key naming the reason.
     * @param array $params What the caller needs to render it.
     * @param string $log A line for the log, or '' for none.
     * @param string|null $message The shopper's message; the key's own text by default.
     * @param string $type The message type.
     *
     *
     * @since  2.0.0
     */
    protected function fail(
        string $code,
        array $params = [],
        string $log = '',
        ?string $message = null,
        string $type = 'warning',
    ): void {
        $this->failures[] = ['code' => $code, 'params' => $params];

        if ($log !== '') {
            Log::add($log, Log::ERROR, 'com_alfa.orders');
        }

        if (!$this->subject->isOperatorPlacement()) {
            $this->app->enqueueMessage($message ?? Text::_($code), $type);
        }
    }

    /**
     * Record something that did not finish after the commit.
     *
     * A warning is never a reason the order does not exist. It is enqueued for
     * a shopper only when there is one, on the same rule as a refusal.
     *
     * @param string $code The language key naming what did not finish.
     * @param array $params What a caller needs to render it.
     *
     *
     * @since  2.0.0
     */
    protected function warn(
        string $code,
        array $params = [],
        ?string $message = null,
        string $type = 'warning',
    ): void {
        $this->warnings[] = ['code' => $code, 'params' => $params];

        if (!$this->subject->isOperatorPlacement()) {
            // The pre-rendered message is passed in rather than rebuilt from the
            // key, for the same reason fail() takes one: several of these carry
            // interpolated values, and a shopper must keep seeing the exact
            // sentence they saw before this seam existed.
            $this->app->enqueueMessage($message ?? Text::_($code), $type);
        }
    }

    /**
     * What happened, in a form a caller can act on.
     *
     * The one question that must always be answerable is whether the order
     * exists; ask this rather than inferring it from place()'s bool or from
     * getOrder(), both of which can be false or null for an order that is
     * sitting in the database.
     *
     *
     * @since  2.0.0
     */
    public function getOutcome(): PlacementOutcome
    {
        return $this->committed
            ? PlacementOutcome::committed($this->committedOrderId, $this->warnings)
            : PlacementOutcome::rejected($this->failures);
    }

    /**
     * Did the transaction commit? True means the order exists, whatever else
     * went wrong afterwards.
     *
     *
     * @since  2.0.0
     */
    public function isCommitted(): bool
    {
        return $this->committed;
    }

    /**
     * The committed order's id, or 0 when nothing was committed.
     *
     *
     * @since  2.0.0
     */
    public function getCommittedOrderId(): int
    {
        return $this->committedOrderId;
    }

    /**
     * What did not finish after the commit, as keys and parameters.
     *
     * @return array<int, array{code: string, params: array}>
     *
     * @since  2.0.0
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Why the last placement was refused, as keys and parameters.
     *
     * Empty on success. A caller renders these itself, in whatever language its
     * own audience reads: no message queue, no exception text, nothing to
     * match on.
     *
     * @return array<int, array{code: string, params: array}>
     *
     * @since  2.0.0
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    /**
     * May this placement use the payment method it has chosen?
     *
     * Only ever asks anything of an operator placement. For a shopper the
     * answer is yes by definition: they are the person every storefront method
     * was written for, and nothing about their checkout changes here.
     *
     * The check exists twice on purpose. Hiding a method from the offered list
     * is presentation, and presentation is a thing a crafted request walks past
     * - the method id arrives as a number in a POST and nothing about it proves
     * it was ever displayed. So the offered list is filtered for tidiness, and
     * this is the one that decides, sitting immediately before the plugin can
     * be booted and called.
     *
     *
     * @since  2.0.0
     */
    /**
     * Is the plugin behind a chosen method actually switched on?
     *
     * Independent of the offered list on purpose. Discovery now excludes a
     * method whose plugin is disabled, so a stale selector cannot normally
     * reach here, but "normally" is not a boundary. A method id arrives as a
     * number in a POST, a cart can have been sitting with a selection since
     * before the merchant withdrew the gateway, and this is the last statement
     * before that gateway's code runs. It is asked for every channel, unlike
     * the operator capability, because a withdrawn plugin is withdrawn from
     * everybody.
     *
     * PluginHelper::isEnabled() is the same authority the webhook endpoints
     * use, and it answers false for disabled, uninstalled and wrong-group
     * alike.
     *
     * @param string $group The plugin folder.
     * @param string $type The method's plugin element.
     *
     *
     * @since  2.0.0
     */
    protected function methodPluginLive(string $group, string $type): bool
    {
        return $type !== '' && PluginHelper::isEnabled($group, $type);
    }

    protected function paymentMethodPermitted(): bool
    {
        if (!$this->subject->isOperatorPlacement()) {
            return true;
        }

        // Two independent requirements, and OperatorPaymentPolicy already asks
        // both: the plugin must be enabled AND it must declare operator
        // capability. Neither substitutes for the other.
        if (OperatorPaymentPolicy::allows($this->payment_type)) {
            return true;
        }

        $this->fail(
            'COM_ALFA_ORDER_PLACE_PAYMENT_NOT_OPERATOR_CAPABLE',
            ['method' => $this->payment_type],
            'Operator placement refused: payment method "' . $this->payment_type
                . '" is disabled or does not support operator placement.',
        );

        return false;
    }

    protected function validateOrderPrerequisites(): bool
    {
        try {
            // AN OPERATOR PLACEMENT CONVERTS THE CART IT WAS GIVEN, OR NONE.
            //
            // Everything else in the operator contract assumes the cart is the
            // one the caller meant: the customer was checked against that row,
            // the currency was taken off it, adoption was suppressed because an
            // id was named. A placement that arrives here without one has had
            // its cart FOUND for it (by the operator's own recognition cookie,
            // through the discovery path) and every one of those guarantees is
            // then about the wrong cart. There is no safe way to continue, so
            // this refuses rather than guesses.
            if ($this->subject->isOperatorPlacement() && !$this->cart->isNamed()) {
                $this->fail(
                    'COM_ALFA_ORDER_PLACE_OPERATOR_CART_REQUIRED',
                    [],
                    'Operator placement refused: no cart was named, so the cart was discovered rather than given.',
                );

                return false;
            }

            // Account-required checkout is enforced HERE, not only on the cart
            // view: a crafted POST must meet the same rule as the form.
            // checkout_guest asks whether SHOPPERS may check out without an
            // account. It is a storefront policy about self-service, and a
            // merchant who turns it off is saying "make them register", not
            // "refuse the phone order I am typing in myself". An authorized
            // operator is therefore outside it, and outside only this. Every
            // other guest requirement below still applies to them, because
            // those are about the order being serviceable rather than about
            // who is allowed to start one.
            if (
                (int) $this->user->id <= 0
                && !$this->subject->isOperatorPlacement()
                && (int) ComponentHelper::getParams('com_alfa')->get('checkout_guest', 1) === 0
            ) {
                $this->fail('COM_ALFA_CART_ACCOUNT_REQUIRED');

                return false;
            }

            // A guest order with no address to answer to can be neither
            // confirmed nor invoiced nor served its downloads. The form
            // demands the field; this is the guard for every other way in
            // (a hidden field, a crafted POST, an API caller).
            if ((int) $this->user->id <= 0) {
                $identity = OrderIdentity::snapshotFromDetails($this->deliveryData);

                if ($identity['customer_email'] === '') {
                    $this->fail(
                        'COM_ALFA_ORDER_PLACE_EMAIL_REQUIRED',
                        [],
                        'Guest placement refused: no customer email in the submitted details.',
                    );

                    return false;
                }
            }

            // THE MERCHANT'S OWN FIELD RULES, ASKED OF A PROGRAMMATIC CALLER.
            //
            // A shopper meets them because they filled the form in and the model
            // validated it. Placement itself has only ever checked the guest
            // email, so an array handed straight to place() meets none of the
            // rest, which is fine while the form is the only way in, and is a
            // hole the moment staff can type an order. Same form, same fields,
            // same rules; nothing about them is restated here.
            if ($this->subject->isOperatorPlacement()) {
                $checked = CustomerDetailsValidator::validate(
                    delivery: $this->deliveryData,
                    billing: $this->billingData,
                    customerId: $this->subject->customerId(),
                    actorId: $this->subject->actorId(),
                );

                if (!$checked->valid()) {
                    foreach ($checked->failures as $failure) {
                        $this->fail($failure['code'], $failure['params']);
                    }

                    return false;
                }

                // WHAT THE FORM MADE IS WHAT IS STORED. The storefront keeps the
                // filtered copy its model returns (a telephone number in E.164,
                // not as typed) and the order builder now does the same, so a
                // carrier booking against the saved number reads one shape
                // whichever door the order came through.
                $this->deliveryData = $checked->delivery;
                $this->billingData = $checked->billing;
            }

            $cartItems = $this->cart->lines();

            // NOTE ON RETRIES. A successful placement DELETES the cart, inside
            // the same transaction that writes the order, so a resubmit
            // afterwards resolves to no cart at all and "your cart is empty" is
            // the truthful answer: there is nothing left to convert.
            //
            // The dangerous window used to be the one in between (order
            // committed, cart not yet deleted) and it no longer exists,
            // because there is no "in between": the two happen together or not
            // at all. A request already inside the envelope is caught by the
            // cart-row lock below, which is the authoritative guard.
            if (empty($cartItems)) {
                $this->fail('COM_ALFA_ORDER_PLACE_CART_EMPTY');
                return false;
            }

            // A QUANTITY THAT IS NOT CHARGED STOPS THE ORDER.
            //
            // A line whose unit is above zero and whose own money settles at
            // zero would be goods handed over for nothing, so no order is made
            // from a cart that holds one. Asked here of every priced line, on
            // the price the order would freeze, whatever the cart's reading
            // marked: the customer is told which product, and the smallest
            // quantity of it the cart may hold that is charged, which the
            // cart's reading states on the line. The reason carries both, for
            // a caller that words it itself.
            $uncharged = [];

            foreach ((array) $cartItems as $cartItem) {
                $price = $cartItem->data->price ?? null;

                // Judged on what the customer pays a piece before discounts, with tax, exactly.
                if (!$price instanceof PriceResult || !ChargeableQuantity::ofUnit(unit: $price->getPayableUnitExact(), currency: $price->getCurrency())->refuses(quantity: $price->getQuantity())) {
                    continue;
                }

                $smallest = $cartItem->chargeable_from;
                $lineName = ($cartItem->data->name ?? '') !== ''
                    ? $cartItem->data->name
                    : ('#' . (int) ($cartItem->id_item ?? 0));

                $this->fail(
                    $smallest === null ? ChargeableQuantity::NEVER : ChargeableQuantity::TOO_SMALL,
                    $smallest === null ? ['line' => $lineName] : ['quantity' => $smallest, 'line' => $lineName],
                    '',
                    '"' . htmlspecialchars((string) $lineName, ENT_QUOTES, 'UTF-8') . '": ' . ChargeableQuantity::sentence(smallest: $smallest),
                );

                $uncharged[(int) $cartItem->id] = true;
            }

            // AN UNAVAILABLE LINE STOPS THE ORDER, IT DOES NOT LEAVE QUIETLY.
            //
            // Hydration used to drop a line whose product had left the
            // catalogue, so the order was placed with fewer lines than the
            // customer had added and nobody was told. The line is now surfaced
            // instead, which means checkout has to answer for it: the customer
            // is told which product is affected and the placement is refused
            // until they act on it.
            $unavailable = array_values(array_filter(
                (array) $cartItems,
                static fn ($cartItem): bool => !empty($cartItem->unavailable),
            ));

            if ($unavailable !== []) {
                foreach ($unavailable as $cartItem) {
                    // Already refused above, in its own sentence.
                    if (isset($uncharged[(int) $cartItem->id])) {
                        continue;
                    }

                    $lineName = ($cartItem->data->name ?? '') !== ''
                        ? $cartItem->data->name
                        : ('#' . (int) ($cartItem->id_item ?? 0));

                    $this->fail(
                        'COM_ALFA_ORDER_PLACE_LINE_UNAVAILABLE',
                        ['line' => $lineName],
                        '',
                        Text::sprintf('COM_ALFA_ORDER_PLACE_LINE_UNAVAILABLE', $lineName),
                    );
                }

                return false;
            }

            if ($uncharged !== []) {
                return false;
            }

            // Feature-plugin checkout validation (onOrderPlaceValidate,
            // boot-and-call): a plugin may veto the placement with
            // customer-facing messages; rejections accumulate on one
            // shared event and abort before anything is written.
            $validateEvent = new OrderPlaceValidateEvent('onOrderPlaceValidate', [
                'context' => $this->eventContext(),
            ]);

            foreach (CommercialPlugins::for($this->user) as $alfaPlugin) {
                $plugin = $this->app->bootPlugin($alfaPlugin->name, 'alfa');

                if (method_exists($plugin, 'onOrderPlaceValidate')) {
                    $plugin->onOrderPlaceValidate($validateEvent);
                }
            }

            if ($validateEvent->isRejected()) {
                foreach ($validateEvent->getMessages() as $message) {
                    // A PLUGIN'S OWN WORDS, not a language key. They cannot be
                    // translated by a caller, so they travel as a parameter of a
                    // code that can be; the shopper still sees the plugin's
                    // sentence verbatim.
                    $this->fail(
                        'COM_ALFA_ORDER_PLACE_PLUGIN_REFUSED',
                        ['message' => (string) $message],
                        '',
                        (string) $message,
                    );
                }

                return false;
            }

            // Shipping first: the payment methods offered depend on the shipping
            // method THIS submission chose, not on what the cart row last recorded.
            $shipmentMethodId = $this->command->shipmentMethodId;

            if (!$this->checkShipmentMethod($shipmentMethodId)) {
                return false;
            }

            $this->cart->assumeShipment($shipmentMethodId);

            $paymentMethodId = $this->command->paymentMethodId;

            if (!$this->checkPaymentMethod($paymentMethodId)) {
                return false;
            }

            return true;
        } catch (Throwable $e) {
            // \Throwable: this runs the payment/shipment method checks, which
            // reach plugin code. An Exception-only net lets a listener's
            // TypeError past and fatals checkout instead of refusing it.
            Log::add('Validation error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return false;
        }
    }

    /**
     * The placement as a plugin is told about it, from the command that made
     * it: the carrier is the one the command named, never one read off a
     * request that an operator's or an importer's placement did not make.
     *
     *
     * @since  2.0.0
     */
    private function eventContext(): PlacementEventContext
    {
        return $this->cart->eventContext(
            customerId: (int) $this->user->id,
            shipmentMethodId: (int) ($this->command?->shipmentMethodId ?? 0),
        );
    }

    /**
     * Fire onOrderBeforePlace on the selected payment and shipment plugins.
     *
     * A PLUGIN THAT THROWS REFUSES THE PLACEMENT. The throw is logged and false
     * is returned before the transaction is opened, so nothing has been
     * committed and nothing has to be unwound; the plugin is expected to have
     * told the shopper why, because placement itself says nothing.
     *
     * @param int $paymentMethodId The selected payment method id.
     * @param int $shipmentMethodId The selected shipment method id.
     *
     * @return bool True when every plugin let the placement proceed.
     *
     * @since  1.0.0
     */
    protected function triggerBeforePlaceEvents(int $paymentMethodId, int $shipmentMethodId): bool
    {
        try {
            $this->getPaymentType($paymentMethodId);

            if (!empty($this->payment_type) && !$this->paymentMethodPermitted()) {
                // The refusal has already been recorded and spoken by the check
                // itself; this method answers the caller in the one currency it
                // declares, which is a boolean. Naming the side that refused
                // here returned an array from a bool method, so PHP raised a
                // TypeError, this method's own net caught it, and the log
                // recorded a return-type error instead of the refusal that
                // actually happened.
                return false;
            }

            if (!empty($this->payment_type) && !$this->methodPluginLive('alfa-payments', $this->payment_type)) {
                $this->fail(
                    'COM_ALFA_ORDER_INVALID_PAYMENT_METHOD',
                    ['method' => $this->payment_type],
                    'Placement refused: the plugin behind payment method "' . $this->payment_type . '" is not enabled.',
                    null,
                    'error',
                );

                return false;
            }

            if (!empty($this->payment_type)) {
                try {
                    $event = new PaymentOrderBeforePlaceEvent('onOrderBeforePlace', ['subject' => $this->eventContext()]);
                    $plugin = $this->app->bootPlugin($this->payment_type, 'alfa-payments');
                    if ($plugin && method_exists($plugin, 'onOrderBeforePlace')) {
                        $plugin->onOrderBeforePlace($event);
                    }
                } catch (Throwable $e) {
                    // \Throwable, not Exception: a plugin's TypeError is not an
                    // Exception and used to escape every net on this path (up
                    // through placeOrder() and the controller) and fatal the
                    // checkout request outright.
                    Log::add(
                        'Payment plugin "' . $this->payment_type . '" failed in onOrderBeforePlace: '
                        . $e->getMessage() . "\n" . $e->getTraceAsString(),
                        Log::ERROR,
                        'com_alfa.orders',
                    );

                    // REFUSE THE PLACEMENT. The plugin was handed the order to
                    // prepare and could not; nothing has been committed, so
                    // stopping here costs one failed checkout instead of an
                    // order built on state nobody can vouch for.
                    return false;
                }
            }

            $this->getShipmentType($shipmentMethodId);
            if (!empty($this->shipment_type) && !$this->methodPluginLive('alfa-shipments', $this->shipment_type)) {
                $this->fail(
                    'COM_ALFA_ORDER_INVALID_SHIPMENT_METHOD',
                    ['method' => $this->shipment_type],
                    'Placement refused: the plugin behind shipment method "' . $this->shipment_type . '" is not enabled.',
                    null,
                    'error',
                );

                return false;
            }

            if (!empty($this->shipment_type)) {
                try {
                    $event = new ShipmentOrderBeforePlaceEvent('onOrderBeforePlace', ['subject' => $this->eventContext()]);
                    $plugin = $this->app->bootPlugin($this->shipment_type, 'alfa-shipments');
                    if ($plugin && method_exists($plugin, 'onOrderBeforePlace')) {
                        $plugin->onOrderBeforePlace($event);
                    }
                } catch (Throwable $e) {
                    Log::add(
                        'Shipment plugin "' . $this->shipment_type . '" failed in onOrderBeforePlace: '
                        . $e->getMessage() . "\n" . $e->getTraceAsString(),
                        Log::ERROR,
                        'com_alfa.orders',
                    );

                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            Log::add('Before-place events error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return false;
        }
    }

    /**
     * Fire onOrderAfterPlace at the selected payment and shipment plugins:
     * the hooks that CREATE THE PAYMENT AND SHIPMENT ROWS for the placed order.
     *
     * The order is already committed when this runs, so a failure here cannot
     * be rolled back. What it must not be is SILENT: a payment plugin that
     * throws leaves a live order with no ledger row, which reads downstream as
     * "nothing due" and lets the buyer through the process page as though the
     * order were paid.
     *
     * So the failure is reported rather than swallowed, and the caller is told.
     *
     * @return string[] The sides that failed ('payment', 'shipment'); empty on success.
     *
     * @since  1.0.0
     */
    protected function triggerAfterPlaceEvents(): array
    {
        $failed = [];

        try {
            if (!empty($this->payment_type) && $this->order && !$this->paymentMethodPermitted()) {
                // Belt and braces. Nothing should reach here: the same question
                // was asked and refused before the transaction opened, but this
                // is the last statement before a gateway plugin runs against a
                // COMMITTED order, and past this point nothing is undoable.
                $this->payment_type = '';
            }

            if (!empty($this->payment_type) && $this->order && !$this->methodPluginLive('alfa-payments', $this->payment_type)) {
                // Past the commit, so refusing is not an option; but booting a
                // withdrawn gateway against a real order is worse than not
                // having its ledger row. Recorded so the absence is reported.
                $failed[] = 'payment';
                Log::add(
                    'Skipped onOrderAfterPlace for order #' . (int) $this->order->id
                        . ': the plugin behind payment method "' . $this->payment_type . '" is not enabled.',
                    Log::ERROR,
                    'com_alfa.orders',
                );
                $this->payment_type = '';
            }

            if (!empty($this->payment_type) && $this->order) {
                try {
                    $event = new PaymentOrderAfterPlaceEvent('onOrderAfterPlace', ['subject' => $this->order]);
                    $plugin = $this->app->bootPlugin($this->payment_type, 'alfa-payments');
                    if ($plugin && method_exists($plugin, 'onOrderAfterPlace')) {
                        $plugin->onOrderAfterPlace($event);
                    }
                } catch (Throwable $e) {
                    // \Throwable, not Exception: a plugin's TypeError is not an
                    // Exception and used to escape every net on this path,
                    // fatalling checkout AFTER the order had been committed and
                    // BEFORE the cart was cleared.
                    $failed[] = 'payment';
                    Log::add(
                        'Payment plugin "' . $this->payment_type . '" failed in onOrderAfterPlace for order #'
                        . (int) ($this->order->id ?? 0) . ': THE PAYMENT ROW WAS NOT CREATED, so this order'
                        . ' has no ledger entry to collect against: ' . $e->getMessage()
                        . "\n" . $e->getTraceAsString(),
                        Log::CRITICAL,
                        'com_alfa.orders',
                    );
                }
            }

            if (!empty($this->shipment_type) && $this->order && !$this->methodPluginLive('alfa-shipments', $this->shipment_type)) {
                // Same reasoning as the payment side above: the order is already
                // committed, so the carrier's row simply does not get created and
                // the absence is recorded rather than hidden.
                $failed[] = 'shipment';
                Log::add(
                    'Skipped onOrderAfterPlace for order #' . (int) $this->order->id
                        . ': the plugin behind shipment method "' . $this->shipment_type . '" is not enabled.',
                    Log::ERROR,
                    'com_alfa.orders',
                );
                $this->shipment_type = '';
            }

            if (!empty($this->shipment_type) && $this->order) {
                try {
                    $event = new ShipmentOrderAfterPlaceEvent('onOrderAfterPlace', ['subject' => $this->order]);
                    $plugin = $this->app->bootPlugin($this->shipment_type, 'alfa-shipments');
                    if ($plugin && method_exists($plugin, 'onOrderAfterPlace')) {
                        $plugin->onOrderAfterPlace($event);
                    }
                } catch (Throwable $e) {
                    $failed[] = 'shipment';
                    Log::add(
                        'Shipment plugin "' . $this->shipment_type . '" failed in onOrderAfterPlace for order #'
                        . (int) ($this->order->id ?? 0) . ': the shipment row was not created: '
                        . $e->getMessage() . "\n" . $e->getTraceAsString(),
                        Log::CRITICAL,
                        'com_alfa.orders',
                    );
                }
            }
        } catch (Throwable $e) {
            $failed[] = 'unknown';
            Log::add('After-place events error: ' . $e->getMessage(), Log::CRITICAL, 'com_alfa.orders');
        }

        return $failed;
    }

    /**
     * Save order
     *
     * The order status is read from $this->defaultStatus (loaded from DB)
     * instead of the old hardcoded constant.
     *
     * @return int|false New order ID, or false on failure
     * @since  1.0.0
     */
    /**
     * Build the OrderCreator draft from the live cart: order-level fields plus
     * one line per cart item with the FROZEN amounts extracted from its
     * PriceResult (the mapping documented on freezeLineFromCartItem). This
     * helper prices; OrderCreator persists, never the other way round.
     *
     *
     * @return array The draft for OrderCreator::create().
     *
     * @since  2.0.0
     */
    /**
     * The #__languages.lang_id of the language this order is being placed in.
     *
     * The column is read by EmailService::resolveLangTag() and by
     * HasOrderContextTrait::orderLanguage(), both of which define it as the
     * order's OWN language: the one the buyer is addressed in later, from a
     * webhook, a carrier callback or a cron retry, where no customer session
     * survives to say what it was. It used to be the constant 1, so every
     * storefront order claimed whichever language happens to hold lang_id 1
     * and a Greek shopper was written down as English.
     *
     * NOT the cart's language: the cart no longer carries one, and it would be
     * the wrong authority anyway. The request the buyer CONFIRMED in is the
     * answer: a cart may have been assembled over days and several language
     * switches, but the order was placed exactly once.
     *
     * 0 when the tag cannot be resolved: exactly what both readers document
     * as "unset" and fall back on. A wrong id would not.
     *
     * @return int Joomla language id, 0 when unresolvable.
     *
     * @since  2.0.0
     */
    /**
     * The language this customer is written to in, with no request involved.
     *
     * @return string A language tag, or '' when the shop has no site default.
     *
     * @since  2.0.0
     */
    protected function customerLanguageTag(): string
    {
        $chosen = (string) $this->user->getParam('language', '');

        if ($chosen !== '') {
            return $chosen;
        }

        return (string) ComponentHelper::getParams('com_languages')->get('site', '');
    }

    protected function resolveLanguageId(): int
    {
        // The command's tag when it named one; otherwise the running
        // application's, which is what a shopper's checkout has always used.
        $tag = (string) ($this->command?->languageTag ?? '');

        // The running application's language is the SHOPPER's language on a
        // storefront checkout, and the OPERATOR's in a back office: the same
        // expression, two different people, and only one of them is going to
        // read the confirmation. So an operator placement never falls back to
        // it: the customer's own account language decides, and failing that the
        // site's default, which is at least a language the shop speaks to
        // customers in.
        if ($tag === '' && $this->subject->isOperatorPlacement()) {
            $tag = $this->customerLanguageTag();
        }

        if ($tag === '') {
            $tag = (string) Factory::getApplication()->getLanguage()->getTag();
        }

        if ($tag === '') {
            return 0;
        }

        try {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('lang_id'))
                ->from($this->db->quoteName('#__languages'))
                ->where($this->db->quoteName('lang_code') . ' = :tag')
                ->bind(':tag', $tag);

            return (int) ($this->db->setQuery($query)->loadResult() ?? 0);
        } catch (Throwable $e) {
            // Reading the language must never be what fails a placement.
            return 0;
        }
    }

    protected function buildOrderDraft(int $paymentMethodId, int $shipmentMethodId): array
    {
        $lines = [];

        foreach ($this->cart->lines() as $item) {
            $lines[] = $this->freezeLineFromCartItem($item);
        }

        // Who is ordering: a member from the account, a guest from the
        // delivery details just validated (the name/email identity fields).
        $customer = (int) $this->user->id > 0
            ? OrderIdentity::snapshotFromUser((int) $this->user->id)
            : OrderIdentity::snapshotFromDetails($this->deliveryData);

        return [
            'id_user' => (int) $this->user->id,
            'customer_name' => $customer['customer_name'],
            'customer_email' => $customer['customer_email'],
            'customer_is_guest' => $customer['customer_is_guest'],
            'id_address_delivery' => $this->cart->frozenDeliveryAddressId(),
            'id_address_invoice' => $this->cart->frozenInvoiceAddressId(),
            'id_payment_method' => $paymentMethodId,
            'id_shipment_method' => $shipmentMethodId,
            // The operator may name the status the order lands in; a shopper
            // names none and gets the store's is_initial status exactly as
            // before. OrderCreator validates the chosen id and commits it in the
            // same transaction (an unknown one lands initial, a requires-paid
            // one is refused) so nothing here needs to police it.
            'id_order_status' => ($this->command?->initialStatusId ?? 0) > 0
                ? (int) $this->command->initialStatusId
                : (int) $this->defaultStatus->id,
            'payment_method_name' => $this->getPaymentMethodName($paymentMethodId),
            'shipment_method_name' => $this->getShipmentMethodName($shipmentMethodId),
            // The plugin elements, already resolved by triggerBeforePlaceEvents()
            // above and until now thrown away after booting. The NAME cannot
            // boot a gateway; this is what a refund needs when the method row
            // is later deleted.
            'payment_method_type' => (string) $this->payment_type,
            'shipment_method_type' => (string) $this->shipment_type,
            'conversion_rate' => 1.0,
            // THE ORDER'S LANGUAGE, ADDRESS AND PROVENANCE. Each falls back to
            // the running request, which is exactly right for a shopper and
            // exactly wrong for anyone placing an order on their behalf: the
            // order would be written in the operator's language, stamped with
            // the operator's IP, and labelled storefront.
            'id_language' => $this->resolveLanguageId(),
            // THE CURRENCY THE CART WAS PRICED IN, PERSISTED.
            //
            // This key used to be absent, so OrderCreator substituted the store
            // default for every order ever placed: harmless while the two
            // always agreed, and a silent mispricing the moment they did not.
            // It matters more now than it did: buildOrderPriceContext() reads
            // orders.id_currency back to re-price an order, so an order stamped
            // with a currency it was not priced in re-prices wrong for the rest
            // of its life. The cart row is the authority, because it is what the
            // price context was built from.
            'id_currency' => $this->cart->currencyId(),
            'ip_address' => $this->command?->ipAddress ?? IpHelper::getIp(),
            // THE ACTOR, not the customer: this column records who performed
            // the placement, and it is the one field on this order that does.
            // Identical to id_user on every storefront checkout, because there
            // the two are the same person.
            'created_by' => $this->subject->actorId(),
            // The vocabulary the column documents: storefront | admin | renewal | api.
            'source' => $this->command?->source ?? PlacementCommand::SOURCE_STOREFRONT,
            // The history names the customer, a guest by the name they gave.
            'actor_name' => $customer['customer_name'] !== '' ? $customer['customer_name'] : 'Customer',
            'lines' => $lines,
        ];
    }

    /**
     * Freeze one cart line into an OrderCreator draft line.
     *
     * PRICE MAPPING (from PriceResult). The line carries the product's TRUTH:
     * what the customer actually pays, with EVERY catalog discount (before- or
     * after-tax) inside the price. The engine already derives the net and the
     * tax from the final consideration; the freeze copies them
     * rather than dividing a second time, so the cart, the line and the document
     * carry one set of figures. "Before/after tax" remains a live pricing
     * preference, never a frozen accounting anomaly.
     *
     *   total_price_tax_incl = getTotal()         (final line total, all discounts in)
     *   unit_price_tax_incl  = getPrice()         (final unit price)
     *   total_price_tax_excl = getSubtotal()      (the engine's own net for this line)
     *   unit_price_tax_excl  = getSubtotalPrice() (that total / quantity; derived)
     *   original_product_price + discount_* = the delta against getBaseTotal(),
     *                                         line-level, space-consistent
     *
     * ORDER-LEVEL rules never touch line prices: coupons (and later the cart
     * rules engine) freeze into #__alfa_order_cart_rule and are subtracted by
     * the totals; see saveCartRules().
     *
     * @param object $item The cart line (CartItem wrapper).
     *
     * @return array One OrderCreator draft line.
     *
     * @since  2.0.0
     */
    protected function freezeLineFromCartItem(object $item): array
    {
        $line = [
            'id_item' => (int) $item->id_item,
            'id_combination' => ($item->combination_id ?? null) ?: null,
            'sku' => $item->data->sku ?? null,
            'gtin' => $item->data->gtin ?? $item->data->ean13 ?? null,
            'mpn' => $item->data->mpn ?? null,
            // Virtual items never contribute shipping weight (mixed carts).
            'weight' => !empty($item->data->is_virtual) ? 0 : ($item->data->weight ?? 0),
            'quantity' => (int) $item->quantity,
        ];

        $price = $item->data->price;

        if ($price && is_object($price)) {
            $taxes = $price->getTaxes();
            $appliedTaxes = $taxes->getApplied();

            // THE WHOLE LINE'S MONEY, READ OFF THE ENGINE AND WRITTEN DOWN.
            //
            // RETIRED HERE, and named so it cannot come
            // back as an unnamed compatibility path:
            //
            //   $divisor  = 1 + $taxRate / 100;
            //   $baseIncl = round($baseExcl * $divisor, 6);
            //
            // That reconstructed the undiscounted gross by multiplying a
            // whole-line net by a BLENDED rate, at six decimals, never
            // quantised to the currency's scale. Measured: on a two-decimal
            // currency at 24% it disagreed with the money that was actually
            // charged on 96% of cent values, bound 0.0048, and it stored
            // fractions of a cent in a currency that has no such unit. On a
            // multi-rate chain the blend was a percentage NO RATE EVER CHARGED.
            //
            // The gross now comes from TaxChain::chargeForward(), the same
            // ordered per-rate charge the customer paid, and every figure below
            // is a whole-line Money the engine already computed.
            //
            // THE hasDiscount() GATE IS GONE TOO. It decided whether the three
            // discount columns were written at all, so an undiscounted line
            // left them to default, indistinguishable from a line whose
            // saving was authoritatively zero. The saving is now always
            // written, because base - final is a fact whether or not it is
            // positive.
            $money = OrderLineMoney::fromPriceResult($price);

            foreach ($money->toColumns() as $column => $value) {
                $line[$column] = $value;
            }

            $line['tax_rate'] = $taxes->getEffectiveRate();
            $line['tax_name'] = !empty($appliedTaxes) ? ($appliedTaxes[0]->name ?? '') : '';
        } else {
            // No PriceResult: zero everything (same behaviour as before).
            Log::add('No PriceResult for item ' . ($item->id_item ?? '?'), Log::WARNING, 'com_alfa.orders');
            $line['unit_price_tax_excl'] = 0;
            $line['unit_price_tax_incl'] = 0;
            $line['total_price_tax_excl'] = 0;
            $line['total_price_tax_incl'] = 0;
            $line['original_product_price'] = 0;
            $line['tax_rate'] = 0;
            $line['tax_name'] = '';
        }

        return $line;
    }

    /**
     * Per-line cart-coupled extras after OrderCreator persisted the lines:
     * item-field snapshot + tax breakdown. Line ids arrive in draft order,
     * which is cart-items order; pair by index.
     *
     * @param int[] $lineIds The created order-item ids, in cart order.
     * @param int $orderId The order id (the order object loads only AFTER
     *                     commit, so it cannot be read from \$this->order here).
     *
     * @since  2.0.0
     */
    protected function freezeCartLineExtras(array $lineIds, int $orderId): void
    {
        $cartItems = $this->cart->lines();

        foreach ($lineIds as $index => $lineId) {
            $item = $cartItems[$index] ?? null;

            if ($item === null) {
                continue;
            }

            // Non-fatal by contract (the line is committed; a snapshot
            // failure is logged inside).
            $this->saveOrderItemFields((int) $lineId, $item, $orderId);

            // Tax breakdown stays fatal: inside the transaction, a failure
            // rolls the whole placement back (same as before).
        }
    }

    /**
     * Freeze a cart line's item-field selection onto the placed order line.
     *
     * Source-specific half of the freeze: reads the live cart selection
     * (#__alfa_cart_item_fields), prices it through the SAME engine as the live
     * cart, resolves the order currency, then hands everything to the shared
     * ItemFieldsSnapshot::freezeSelection, the ONE freeze path shared with
     * the admin order-line re-configure. Non-fatal: the order line is already
     * committed, so a snapshot failure is logged, not thrown.
     *
     * @param int $orderItemId The inserted #__alfa_order_items row id.
     * @param object $cartItem The cart line (cart-item id + configured item).
     * @param int $orderId The order id (denormalized onto every frozen row).
     *
     * @since  2.0.0
     */
    protected function saveOrderItemFields(int $orderItemId, object $cartItem, int $orderId): void
    {
        $cartItemId = (int) ($cartItem->id ?? 0);

        if ($orderItemId <= 0 || $cartItemId <= 0) {
            return;
        }

        try {
            // Authoritative stored selection: the soft foreign keys + opaque
            // payload, in the canonical field-id-keyed shape the freezer takes.
            $stored = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select([
                        $this->db->quoteName('id_item_field'),
                        $this->db->quoteName('id_item_field_value'),
                        $this->db->quoteName('payload'),
                    ])
                    ->from($this->db->quoteName('#__alfa_cart_item_fields'))
                    ->where($this->db->quoteName('id_cart_item') . ' = ' . $cartItemId),
            )->loadObjectList() ?: [];

            if (!$stored) {
                return;
            }

            $rows = [];

            foreach ($stored as $row) {
                $rows[(int) $row->id_item_field] = [
                    'value_id' => $row->id_item_field_value !== null ? (int) $row->id_item_field_value : null,
                    'payload' => $row->payload,
                ];
            }

            // Analytical price breakdown (same engine as live pricing); pricing
            // unavailable -> freeze the selection without the amounts.
            $breakdown = null;

            try {
                $breakdown = $this->cart->lineBreakdown($cartItem->data, $cartItemId, (int) $cartItem->quantity);
            } catch (Throwable $e) {
                $this->rethrowIfTransactionFatal($e);

                // Freeze without the price breakdown.
            }

            // The order's currency drives impact precision (the cart line is always
            // priced at this point; the order totals derive from it).
            $currency = ($cartItem->data->price ?? null) !== null && method_exists($cartItem->data->price, 'getCurrency')
                ? $cartItem->data->price->getCurrency()
                : null;

            ItemFieldsSnapshot::freezeSelection(
                orderItemId: $orderItemId,
                orderId: $orderId,
                rows: $rows,
                item: $cartItem->data,
                breakdown: $breakdown,
                currency: $currency,
            );
        } catch (Exception $e) {
            $this->rethrowIfTransactionFatal($e);

            // Resilient by design: a snapshot failure must NOT abort the sale (the
            // order total is already correct in order_items). Logged loudly so it is
            // caught and the line can be backfilled.
            Log::add(
                'Order item-fields snapshot failed for order item ' . $orderItemId . ': ' . $e->getMessage(),
                Log::ERROR,
                'com_alfa.orders',
            );
        }
    }

    /**
     * V3: Save cart rules
     * @since  1.0.0
     */
    /**
     * Freeze the cart's plugin contributions to `#__alfa_order_contributions`
     * - every cart-level charge in full accounting form (excl + tax + incl per
     * row). The runtime contribution is the source; this table is what the
     * order's total is rebuilt from afterwards.
     *
     * MONEY-BEARING, SO TRANSACTION-FATAL. A row that cannot be written is
     * not a missed snapshot: the payment row would then be sized from a total
     * this history no longer explains. The failure is rethrown, named, with
     * the driver's exception preserved beneath it, and the envelope rolls the
     * placement back.
     *
     * @param int $orderId The created order.
     *
     *
     * @throws RuntimeException When a contribution row cannot be written.
     *
     * @since  2.0.0
     */
    protected function saveOrderContributions(int $orderId): void
    {
        // Each figure is handed to its column as the exact decimal of its Money
        // in the cart's currency: the gross as the cart computed it, the net
        // and the tax read once from the figures the fold states.
        $currency = $this->cart->grandTotal()->getCurrency();

        foreach ($this->cart->contributions() as $row) {
            $contribution = new stdClass();
            $contribution->id_order = $orderId;
            $contribution->code = $row->code;
            $contribution->source = $row->source;
            $contribution->name = $row->label;
            $contribution->amount_tax_excl = StoredDecimal::of(Money::of($row->amount_tax_excl, $currency));
            $contribution->tax_rate = $row->tax_rate;
            $contribution->tax_amount = StoredDecimal::of(Money::of($row->tax_amount, $currency));
            $contribution->amount_tax_incl = StoredDecimal::of($row->amount_tax_incl_money);
            $contribution->sort_order = (int) $row->sort_order;
            $contribution->created = Factory::getDate('now', 'UTC')->toSql();

            try {
                $this->db->insertObject('#__alfa_order_contributions', $contribution, 'id');
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Contribution "' . $row->source . '" could not be frozen for order ' . $orderId . ': ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e,
                );
            }
        }
    }

    /**
     * Re-check the applied rules' usage limits under a row lock, inside the
     * placement transaction.
     *
     * The engine counts redemptions with a plain SELECT early in the request,
     * so two checkouts racing for the last one both pass and both insert. The
     * rule row is locked here (the freeze table has no row to lock for a
     * redemption that has not happened yet), which serialises the racers: the
     * loser throws and the whole placement rolls back rather than granting a
     * discount that was never available.
     *
     *
     * @throws RuntimeException When a rule ran out between evaluation and now.
     *
     * @since  2.0.0
     */
    protected function assertCartRuleUsageAvailable(): void
    {
        $ruleIds = array_map(
            static fn ($applied): int => (int) $applied->id,
            $this->cart->appliedRules(),
        );

        if ($ruleIds === []) {
            return;
        }

        $db = $this->db;
        $userId = (int) $this->user->id;

        // Ids are inlined, not bound: whereIn() emits :preparedArray placeholders
        // and keeps the values on the QUERY object, so casting to a string to
        // append FOR UPDATE would ship placeholders with nothing bound to them.
        // Same shape as DocumentIssuer::lockOrder().
        $idList = implode(',', array_map('intval', $ruleIds));

        // Lock the rule rows first: every concurrent redeemer queues here, and
        // always in this order, so placements cannot deadlock against each other.
        $db->setQuery(
            'SELECT * FROM ' . $db->quoteName('#__alfa_cart_rules')
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . $idList . ') FOR UPDATE',
        );
        $rules = $db->loadObjectList('id') ?: [];

        // Also a LOCKING read, deliberately. Under InnoDB's default REPEATABLE
        // READ a plain SELECT is served from the snapshot this transaction took
        // at its first read (long before the racer committed) so a plain
        // recount here would re-read stale counts and the lock above would buy
        // nothing. Rows are tallied in PHP to keep the locking read aggregate-free.
        $db->setQuery(
            'SELECT ocr.' . $db->quoteName('id_cart_rule') . ', o.' . $db->quoteName('id_user')
            . ' FROM ' . $db->quoteName('#__alfa_order_cart_rule', 'ocr')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_orders', 'o')
            . ' ON o.' . $db->quoteName('id') . ' = ocr.' . $db->quoteName('id_order')
            . ' AND o.' . $db->quoteName('state') . ' = 1'
            . ' WHERE ocr.' . $db->quoteName('deleted') . ' = 0'
            . ' AND ocr.' . $db->quoteName('id_cart_rule') . ' IN (' . $idList . ')'
            . ' FOR UPDATE',
        );

        $totalUses = [];
        $userUses = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $id = (int) $row->id_cart_rule;
            $totalUses[$id] = ($totalUses[$id] ?? 0) + 1;

            if ((int) $row->id_user === $userId) {
                $userUses[$id] = ($userUses[$id] ?? 0) + 1;
            }
        }

        // The rule row no longer carries a name (translatable: it lives in the
        // per-language tables), so the message takes it from the applied DTO,
        // which already resolved it in the customer's language.
        $names = [];

        foreach ($this->cart->appliedRules() as $applied) {
            $names[(int) $applied->id] = (string) $applied->name;
        }

        foreach ($rules as $id => $rule) {
            $total = (int) $rule->usage_limit_total;
            $perUser = (int) $rule->usage_limit_per_user;

            $exhausted = ($total > 0 && ($totalUses[$id] ?? 0) >= $total)
                || ($userId > 0 && $perUser > 0 && ($userUses[$id] ?? 0) >= $perUser);

            if ($exhausted) {
                throw new RuntimeException(
                    Text::sprintf('COM_ALFA_CARTRULE_ERR_USAGE_EXHAUSTED', $names[(int) $id] ?? ('#' . $id)),
                );
            }
        }
    }

    /**
     * Freeze the applied cart rules to `#__alfa_order_cart_rule`: the
     * discount history the order's total is rebuilt from, and the usage
     * record the rules' limits are counted against.
     *
     * MONEY-BEARING, SO TRANSACTION-FATAL. The usage limits were re-checked
     * under lock before the order was created (place()); every applied rule
     * becomes one row, and a row that cannot be written is rethrown, named,
     * with the driver's exception preserved beneath it, so the envelope rolls
     * the placement back rather than committing an order whose discounts are
     * missing from its history.
     *
     * @param int $orderId The created order.
     *
     *
     * @throws RuntimeException When a limit is exhausted or a rule row cannot be written.
     *
     * @since  1.0.0
     */
    protected function saveCartRules(int $orderId): void
    {
        // The engine validated candidates (window, targeting, usage) during
        // this request and assertCartRuleUsageAvailable() re-checked the
        // limits under lock in this transaction, before the order was
        // created; the rows below ARE the usage record.
        // The rules were computed in the cart's currency.
        $currency = $this->cart->grandTotal()->getCurrency();

        foreach ($this->cart->appliedRules() as $applied) {
            $cartRule = new stdClass();
            $cartRule->id_order = $orderId;
            $cartRule->id_cart_rule = $applied->id;
            $cartRule->name = $applied->name;
            // NULL for automatic rules; emails label the row with the
            // code when the customer typed one, the name otherwise.
            $cartRule->code = $applied->code;
            // Each figure is handed to its column as the exact decimal of the
            // Money the engine computed.
            $cartRule->value_tax_excl = StoredDecimal::of($applied->discountTaxExcl);
            // Freeze the discount's OWN money. Re-deriving the inclusive
            // figure downstream from the items' VAT multiplier gave the
            // wrong answer for anything whose tax differs from the items'
            // average: shipping benefits most of all.
            $cartRule->value_tax_incl = StoredDecimal::of($applied->discountTaxInclMoney($currency));
            // The tax is the difference of the two frozen figures, in the
            // cart's minor units.
            $cartRule->value_tax = StoredDecimal::of($applied->discountTaxInclMoney($currency)->subtract($applied->discountTaxExcl));
            // Without the class, a frozen shipping benefit is
            // indistinguishable from an order discount and gets
            // subtracted a second time.
            $cartRule->discount_class = $applied->discountClass;
            $cartRule->free_shipping = $applied->freeShipping ? 1 : 0;
            $cartRule->deleted = 0;

            try {
                $this->db->insertObject($this->order_cart_rule_table, $cartRule);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Applied cart rule ' . (int) $applied->id . ' could not be frozen for order ' . $orderId . ': ' . $e->getMessage(),
                    (int) $e->getCode(),
                    $e,
                );
            }
        }
    }

    /**
     * Validate the submitted payment method against those offered to this
     * submission, reporting the reason on failure. A store that offers this
     * cart no payment method at all requires none.
     *
     * @param int|null $id The submitted payment method id.
     *
     * @return bool True when no payment is required or the id is offered.
     *
     * @since  1.0.0
     */
    protected function checkPaymentMethod(?int $id): bool
    {
        $id = (int) $id;

        // The same canonical question, for the other dimension. The two are
        // independent: a shop may take no payment for a cart it will happily
        // ship, and each refusal has to name which one is missing.
        $requirement = $this->cart->paymentRequirement();

        if ($requirement === MethodRequirement::NotApplicable) {
            if ($id > 0) {
                $this->fail('COM_ALFA_ORDER_PAYMENT_NOT_APPLICABLE', ['method' => $id], '', null, 'error');

                return false;
            }

            return true;
        }

        if ($requirement === MethodRequirement::Unavailable) {
            $this->fail('COM_ALFA_ORDER_NO_PAYMENT_FOR_SHIPMENT', [], '', null, 'error');

            return false;
        }

        $methods = $this->cart->paymentMethods();

        if ($id > 0 && isset($methods[$id])) {
            return true;
        }

        // Offered with another shipping method = refused by the chosen one.
        $refusedByShipping = $id > 0 && isset($this->cart->paymentMethodsIgnoringShipping()[$id]);

        $this->fail(
            $refusedByShipping ? 'COM_ALFA_ORDER_PAYMENT_NOT_WITH_SHIPMENT' : 'COM_ALFA_ORDER_INVALID_PAYMENT_METHOD',
            ['method' => $id],
            '',
            null,
            'error',
        );

        return false;
    }

    /**
     * Validate the submitted shipping method against those offered to this
     * submission, reporting the reason on failure. A cart that does not
     * ship, or a store that offers this cart no shipping method at all,
     * requires none.
     *
     * @param int|null $id The submitted shipping method id.
     *
     * @return bool True when no shipping is required or the id is offered.
     *
     * @since  1.0.0
     */
    protected function checkShipmentMethod(?int $id): bool
    {
        $id = (int) $id;

        // THE CANONICAL QUESTION, ASKED ONCE. "Does this cart need a carrier at
        // all?" and "is there one that serves it?" are different questions with
        // different answers, and reading a candidate COUNT collapsed them: a
        // physical cart whose shopper is outside every carrier's group has no
        // candidates, which the old guard read as "no carrier needed" and used
        // to accept any id against.
        $requirement = $this->cart->shipmentRequirement();

        if ($requirement === MethodRequirement::NotApplicable) {
            // Nothing here ships. Naming a carrier anyway is refused for what
            // it is (shipping does not apply), never as an unknown id, which
            // it is not: it is a real carrier, for a different cart.
            if ($id > 0) {
                $this->fail('COM_ALFA_ORDER_SHIPMENT_NOT_APPLICABLE', ['method' => $id], '', null, 'error');

                return false;
            }

            // Naming none is how a cart of downloads places: no carrier
            // recorded, and no parcel row invented for it.
            return true;
        }

        if ($requirement === MethodRequirement::Unavailable) {
            // Goods that ship, and nothing serves them. Refused whether or not
            // a carrier was named: this is the silence the old guard waved
            // through.
            $this->fail('COM_ALFA_ORDER_NO_SHIPMENT_AVAILABLE', [], '', null, 'error');

            return false;
        }

        $methods = $this->cart->shipmentMethods();

        if ($id <= 0 || !isset($methods[$id])) {
            $this->fail('COM_ALFA_ORDER_INVALID_SHIPMENT_METHOD', ['method' => $id], '', null, 'error');

            return false;
        }

        // And the carrier's own answer, before the gateway
        // Membership proves the shop OFFERS this carrier for this destination,
        // group and line scope. It does not prove the carrier will take THIS
        // cart: a locker network offered everywhere still cannot post a 30 kg
        // item, and the shopper may have added one since choosing.
        //
        // The submitted carrier is assumed first so the quote is asked of the
        // method being placed with, not of whatever the cart row last recorded.
        // assumeShipment() is idempotent, so the assume that follows costs
        // nothing. This runs before transactionStart() and long before a
        // gateway is booted, so a refusal declines the sale rather than
        // stranding a paid order the carrier will not collect.
        $this->cart->assumeShipment($id);
        $quote = $this->cart->shipmentQuote();

        if (!$quote->available) {
            // A CARRIER THAT ANSWERED NOTHING IS STILL A REFUSAL HERE: the
            // shop has no carriage figure and must not invent one, but it is
            // not the SAME refusal, and recording it as "cannot carry this
            // order" would file a broken plugin under a customer's parcel being
            // too big. Distinguished so the operator reading the failure is
            // sent to the plugin.
            $this->fail(
                match (true) {
                    !$quote->answered => 'COM_ALFA_SHIPMENT_NO_ANSWER',
                    $quote->reasonKey !== '' => $quote->reasonKey,
                    default => 'COM_ALFA_ORDER_SHIPMENT_CANNOT_CARRY',
                },
                ['method' => $id],
                '',
                null,
                'error',
            );

            return false;
        }

        return true;
    }

    /**
     * The delivery place submitted with the order: the delivery section's
     * country field, when the checkout has one.
     *
     * @param array $data The validated delivery section values.
     *
     * @return int|null The #__alfa_places id, or null when unknown.
     *
     * @since  1.0.0
     */
    protected static function deliveryPlaceFromData(array $data): ?int
    {
        $field = FieldsHelper::deliveryPlaceField();
        $placeId = $field !== null ? (int) ($data[$field->field_name] ?? 0) : 0;

        return $placeId > 0 ? $placeId : null;
    }

    /**
     * Persist the customer's order/address form data to the user-info table.
     * Array/object values are JSON-encoded, unique form fields are enforced, and the current user id
     * is attached before insert.
     *
     * @param array $data The submitted form field values.
     *
     * @return object|null The inserted info object (with id), or null on validation failure/error.
     *
     * @since  1.0.0
     */
    protected function saveUserInfo(array $data): ?object
    {
        try {
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    $data[$key] = json_encode($value);
                }
            }

            // Unique field validation
            $uniqueFields = $this->getUniqueFormFields();

            if (!empty($uniqueFields)) {
                $violations = $this->checkUniqueFieldViolations($data, $uniqueFields);

                if (!empty($violations)) {
                    foreach ($violations as $fieldName => $value) {
                        $this->fail(
                            'COM_ALFA_ORDER_ERROR_FIELD_NOT_UNIQUE',
                            ['field' => $fieldName],
                            '',
                            Text::sprintf('COM_ALFA_ORDER_ERROR_FIELD_NOT_UNIQUE', $fieldName),
                            'error',
                        );
                    }
                    Log::add(
                        'Unique field violation(s): ' . implode(', ', array_keys($violations)),
                        Log::WARNING,
                        'com_alfa.orders',
                    );
                    return null;
                }
            }

            // The cart NEVER updates saved info and never trusts posted row
            // ids: identical data reuses its row, data that only fills
            // previously-empty columns of exactly one row COMPLETES it, any
            // conflicting value becomes a NEW row (the second house).
            // Editing existing rows happens only on the User Info screen.
            $id = UserInfoHelper::insertOrComplete(
                userId: (int) $this->user->id,
                data: $data,
            );

            return (object) ['id' => $id];
        } catch (Exception $e) {
            Log::add('User info save error: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');
            return null;
        }
    }

    /**
     * The invoice (billing) info row for this order. When the customer asked
     * for different billing details, the billing bucket goes through the
     * same reuse/complete/insert path; otherwise the delivery row doubles
     * as the invoice row: the "same details" default.
     *
     * The values come from the VALIDATED submission, not from the raw POST:
     * the model already dropped the whole section when "different details" is
     * off and every field the ShowOn rules hide, so an unfiltered value can
     * never be written to a user-info row behind validation's back.
     *
     * @param int $deliveryId The delivery user_info row id.
     *
     * @return int The invoice user_info row id.
     *
     * @since  2.0.0
     */
    protected function resolveInvoiceInfo(int $deliveryId): int
    {
        // Keep only non-empty values; multi-value fields (arrays) are kept
        // as-is and JSON-encoded at the write funnel (normalizeFieldValues),
        // mirroring the delivery bucket; never cast to the string "Array".
        $billing = array_filter(
            $this->billingData,
            static fn ($value) => is_array($value) ? $value !== [] : trim((string) $value) !== '',
        );

        if (!$billing) {
            return $deliveryId;
        }

        return UserInfoHelper::insertOrComplete(userId: (int) $this->user->id, data: $billing);
    }

    /**
     * Load all form fields marked as unique=1 from the form fields table.
     * Returns an array of field_name strings.
     * @since  1.0.0
     */
    protected function getUniqueFormFields(): array
    {
        try {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('field_name'))
                ->from($this->db->quoteName('#__alfa_form_fields'))
                ->where($this->db->quoteName('unique') . ' = ' . '1')
                ->where($this->db->quoteName('state') . ' = ' . '1')
                ->where($this->db->quoteName('field_name') . ' IS NOT NULL')
                ->where($this->db->quoteName('field_name') . ' != ' . $this->db->quote(''));

            $this->db->setQuery($query);
            return $this->db->loadColumn() ?: [];
        } catch (Exception $e) {
            Log::add('Failed to load unique form fields: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            return [];
        }
    }

    /**
     * Detect which unique form fields already hold the submitted value for a different user.
     * Runs a single UNION ALL query across the candidate fields; guests (user id 0) are skipped.
     *
     * @param array $data The submitted field values.
     * @param array $uniqueFields The field names marked unique.
     *
     * @return array Map of violating field name => submitted value (empty when none).
     *
     * @since  1.0.0
     */
    protected function checkUniqueFieldViolations(array $data, array $uniqueFields): array
    {
        Log::add('checkUniqueFieldViolations - data values: ' . json_encode($data), Log::DEBUG, 'com_alfa.orders');

        if ($this->user->id === 0) {
            return [];
        }

        $fieldsToCheck = array_filter(
            $uniqueFields,
            fn ($field) =>
            isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null,
        );

        if (empty($fieldsToCheck)) {
            return [];
        }

        $selects = [];
        foreach ($fieldsToCheck as $fieldName) {
            $selects[] = sprintf(
                '(SELECT %s AS field_name FROM %s WHERE %s = %s AND %s != %d LIMIT 1)',
                $this->db->quote($fieldName),
                $this->db->quoteName($this->user_info_table),
                $this->db->quoteName($fieldName),
                $this->db->quote($data[$fieldName]),
                $this->db->quoteName('id_user'),
                $this->user->id,
            );
        }

        $unionQuery = implode(' UNION ALL ', $selects);

        Log::add('checkUniqueFieldViolations - query: ' . $unionQuery, Log::DEBUG, 'com_alfa.orders');

        try {
            $this->db->setQuery($unionQuery);
            $collisions = $this->db->loadColumn();
        } catch (Exception $e) {
            Log::add('Unique check query failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.orders');
            return [];
        }

        $violations = [];
        foreach ($collisions as $fieldName) {
            $violations[$fieldName] = $data[$fieldName];
        }

        return $violations;
    }

    /**
     * Resolve and cache the payment plugin type for a payment method id into $this->payment_type.
     * An empty string is stored for id <= 0 or on error.
     *
     * @param int $id The payment method id.
     *
     *
     * @since  1.0.0
     */
    protected function getPaymentType(int $id): void
    {
        // No payment method (0) -> nothing to resolve; skip the query.
        if ($id <= 0) {
            $this->payment_type = '';

            return;
        }

        try {
            $query = $this->db->getQuery(true)
                ->select('type')
                ->from('#__alfa_payments')
                ->where('id = ' . $id);
            $this->db->setQuery($query);
            $this->payment_type = $this->db->loadResult() ?: '';
        } catch (Exception $e) {
            $this->payment_type = '';
        }
    }

    /**
     * Resolve and cache the shipment plugin type for a shipment method id into $this->shipment_type.
     * An empty string is stored for id <= 0 or on error.
     *
     * @param int $id The shipment method id.
     *
     *
     * @since  1.0.0
     */
    protected function getShipmentType(int $id): void
    {
        // No shipment method (0) -> nothing to resolve; skip the query.
        if ($id <= 0) {
            $this->shipment_type = '';

            return;
        }

        try {
            $query = $this->db->getQuery(true)
                ->select('type')
                ->from('#__alfa_shipments')
                ->where('id = ' . $id);
            $this->db->setQuery($query);
            $this->shipment_type = $this->db->loadResult() ?: '';
        } catch (Exception $e) {
            $this->shipment_type = '';
        }
    }

    /**
     * Get the payment method name translated into the customer's language.
     * Returns an empty string for id <= 0, or a "Payment Method <id>" fallback when no translation exists.
     *
     * @param int $id The payment method id.
     *
     * @return string The translated payment method name.
     *
     * @since  1.0.0
     */
    protected function getPaymentMethodName(int $id): string
    {
        // No payment method (0) -> empty name (avoids a needless query and the
        // bogus "Payment Method 0" fallback).
        if ($id <= 0) {
            return '';
        }

        // Name lives in the per-language tables; resolve in the customer's language.
        return MultilingualHelper::getTranslatedValue(
            id:                $id,
            tableName:         '#__alfa_payments',
            primaryColumnName: 'id_payment',
            field:             'name',
        ) ?: 'Payment Method ' . $id;
    }

    /**
     * Get the shipment method name translated into the customer's language.
     * Returns an empty string for id <= 0, or a "Shipment Method <id>" fallback when no translation exists.
     *
     * @param int $id The shipment method id.
     *
     * @return string The translated shipment method name.
     *
     * @since  1.0.0
     */
    protected function getShipmentMethodName(int $id): string
    {
        // No shipment method (0) -> empty name (avoids a needless query and the
        // bogus "Shipment Method 0" fallback).
        if ($id <= 0) {
            return '';
        }

        // Name lives in the per-language tables; resolve in the customer's language.
        return MultilingualHelper::getTranslatedValue(
            id:                $id,
            tableName:         '#__alfa_shipments',
            primaryColumnName: 'id_shipment',
            field:             'name',
        ) ?: 'Shipment Method ' . $id;
    }

    /**
     * Sum the total weight of the cart (each item's weight multiplied by its quantity).
     *
     * @return float The aggregate cart weight; 0 on error.
     *
     * @since  1.0.0
     */
    protected function calculateTotalWeight(): float
    {
        try {
            $total = 0;
            foreach ($this->cart->lines() as $item) {
                // Virtual items never contribute shipping weight (mixed carts).
                if (!empty($item->data->is_virtual)) {
                    continue;
                }

                $total += ($item->data->weight ?? 0) * ($item->quantity ?? 1);
            }
            return $total;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Load complete order object from admin model
     *
     * @param int $orderId Order ID
     *
     * @return object|null Order object or null on failure
     * @since  1.0.0
     */
    protected function loadOrderModel(int $orderId): ?object
    {
        try {
            $orderModel = $this->app->bootComponent('com_alfa')
                ->getMVCFactory()
                ->createModel('Order', 'Administrator', ['ignore_request' => true]);

            if (!$orderModel) {
                Log::add('Failed to load Order model', Log::ERROR, 'com_alfa.orders');
                $this->warn('COM_ALFA_ORDER_MODEL_LOAD_FAILED', [], null, 'error');
                return null;
            }

            return $orderModel->getItem($orderId);
        } catch (Throwable $e) {
            // \Throwable, not Exception: this runs after the commit and its
            // declared answer is "the order, or nothing". A TypeError out of the
            // model's own hydration is not an Exception, so an Exception-only
            // net let it past a method that promises never to throw, and the
            // caller's guard, which handles "nothing" perfectly well, never got
            // the chance to.
            //
            // Same key the guard above already uses for this failure, without the
            // interpolated driver text. The LOG line is a plain string: it is
            // read by an operator chasing a fault, not translated for a shopper,
            // and a key shaped to interpolate an exception is an invitation to
            // wire it back into something a shopper reads.
            $this->warn('COM_ALFA_ORDER_MODEL_LOAD_FAILED', [], null, 'error');
            Log::add('Could not load the placed order: ' . $e->getMessage(), Log::ERROR, 'com_alfa.orders');

            return null;
        }
    }

    /**
     * Apply the customer's credit balance as a payment, when they opted in.
     *
     * Runs POST-COMMIT (the ledger opens its own transaction; inside the
     * placement transaction MySQL's START TRANSACTION would implicitly
     * commit the half-built order) and BEFORE the payment plugin's
     * onOrderAfterPlace, so the gateway sees only the remainder through
     * PaymentLedger::amountToCollect(). Non-fatal by contract.
     *
     *
     * @since  2.0.0
     */
    /**
     * Let a failure that has already killed the transaction out of a catch that
     * was written to tolerate a failed ROW.
     *
     * Two methods inside the placement transaction catch, log and carry on, and
     * they are right to: an item-field snapshot row or a tax-breakdown row
     * failing must not cost a customer their order. What they cannot see is the
     * difference between a row that failed and a TRANSACTION that the server
     * has rolled back underneath them. (The money rows, applied rules and
     * contributions, are not tolerated at all; see saveCartRules().)
     *
     * On a deadlock InnoDB rolls back the WHOLE transaction. The catch logs a
     * warning, placement carries on, and every statement after it, discard()
     * deleting the cart included, runs in AUTOCOMMIT. The order rows are gone,
     * the cart is gone, and placeOrder() returns true. Demonstrated on this
     * server: the work before the abort disappears, the work after it commits.
     *
     * So a transaction-fatal failure is rethrown past the swallow, where the
     * placement's own catch rolls back and refuses. Everything else is
     * swallowed exactly as it was.
     *
     * @param Throwable $e The failure the caller was about to tolerate.
     *
     *
     * @throws Throwable The same failure, when the transaction did not survive it.
     *
     * @since  2.0.0
     */
    private function rethrowIfTransactionFatal(Throwable $e): void
    {
        if (!$this->abortsTransaction($e)) {
            return;
        }

        Log::add(
            'Transaction-fatal database error (' . $this->errorNumber($e) . ') inside a tolerated step: '
            . $e->getMessage() . '; refusing the placement rather than continuing outside a transaction.',
            Log::ERROR,
            'com_alfa.orders',
        );

        throw $e;
    }

    /**
     * Did the server take the transaction with it?
     *
     * BY THE MYSQL ERROR NUMBER, read through MysqlError::number(): the same
     * number on both drivers (mysqli hands it as the code; the PDO driver hands
     * the SQLSTATE there, 40001 for a deadlock and 0 for a lock wait, and the
     * number in its message).
     *
     *   1213 DEADLOCK: InnoDB always rolls back the whole transaction. Fatal.
     *   1205 LOCK WAIT TIMEOUT: rolls back only the STATEMENT unless the server
     *        says otherwise, so it is fatal only when innodb_rollback_on_timeout
     *        is ON. Measured OFF here; asked rather than assumed, because it is
     *        a per-install setting and the wrong guess is expensive either way.
     *
     * @param Throwable $e The failure.
     *
     *
     * @since  2.0.0
     */
    private function abortsTransaction(Throwable $e): bool
    {
        $number = $this->errorNumber($e);

        if ($number === 1213) {
            return true;
        }

        return $number === 1205 && $this->rollsBackOnTimeout();
    }

    /**
     * The MySQL error number of the failure, or of the database failure it wraps.
     *
     * @param Throwable $e The failure.
     *
     * @return int 0 when neither is a statement's failure.
     *
     * @since  2.0.0
     */
    private function errorNumber(Throwable $e): int
    {
        $number = MysqlError::number($e);

        return $number !== 0 || $e->getPrevious() === null ? $number : MysqlError::number($e->getPrevious());
    }

    /**
     * Does a lock-wait timeout roll back the transaction on THIS server?
     *
     * Asked once per request, and only when a timeout has actually happened.
     *
     *
     * @since  2.0.0
     */
    private function rollsBackOnTimeout(): bool
    {
        static $rollsBack = null;

        if ($rollsBack !== null) {
            return $rollsBack;
        }

        try {
            $row = $this->db->setQuery("SHOW VARIABLES LIKE 'innodb_rollback_on_timeout'")->loadAssoc();
            $rollsBack = strtoupper((string) ($row['Value'] ?? 'OFF')) === 'ON';
        } catch (Throwable) {
            // Unknowable: treat it as fatal. Refusing a placement that could
            // have continued costs a retry; continuing one whose transaction is
            // gone costs the order AND the cart.
            $rollsBack = true;
        }

        return $rollsBack;
    }

    /**
     * Freeze what the purchase came to, while the cart still represents it.
     *
     * Placement deletes the cart INSIDE the transaction, deliberately, so that
     * no state exists where both the cart and its order are real. discard()
     * therefore leaves this object holding an EMPTY cart, and everything after
     * the commit that asks the cart what the order came to gets the total of
     * nothing: shipping, plus whatever contributions survive.
     *
     * That total is not decoration. It becomes $order->total_paid_tax_incl,
     * which the payment plugins read to decide what to collect and which
     * PaymentLedger prefers over recomputing from the database. Measured across
     * every scenario, the amount asked for was the shipping cost (7.00 against
     * goods ranging from 35.34 to 247.38) and on a zero-shipping order no
     * payment row was created at all.
     *
     * Called as the last read of the live cart, after the order, its lines and
     * its frozen rules exist, and one statement before the cart does not.
     *
     *
     * @since  2.0.0
     */
    private function captureCompletedTotals(): void
    {
        $this->completedTotals = [
            // GROSS on purpose, unchanged: the shipment row records what the
            // carrier charged, and the shipping-class rule row records the
            // benefit taken off it.
            'shipping_incl' => $this->cart->shipmentGross(),
            'shipping_excl' => $this->cart->shipmentGrossExcl(),
            'shipping_rate' => $this->cart->shipmentTaxRate(),
            'shipping_basis' => $this->cart->shipmentChargeBasis()?->value,
            'shipping_chain' => $this->cart->shipmentTaxChain(),
            'paid_incl' => $this->cart->grandTotal(),
            'paid_excl' => $this->cart->grandTotalExcl(),
        ];
    }

    private function applyCustomerCredit(): void
    {
        if (!($this->command?->useCredit ?? false)) {
            return;
        }

        $userId = (int) $this->user->id;

        if ($userId <= 0) {
            // Guests have no credit account; a forged flag is ignored.
            return;
        }

        try {
            $balance = \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::balanceMoney($userId);

            // The captured total, for the same reason the order's is captured:
            // this runs after the cart has been discarded, and asking the
            // discarded cart what the order came to caps the spend at the
            // shipping cost.
            $grandMoney = $this->completedTotals['paid_incl'] ?? $this->cart->grandTotal();
            $grand = $grandMoney->getAmount();
            // AT THE WALLET'S OWN CURRENCY, WHICH IS THE SHOP SETTLEMENT ONE.
            // The wallet holds that currency and nothing else, and the ledger
            // refuses an order in any other, which is what makes the balance
            // and the order's total comparable here at all. If cross-currency
            // wallets ever arrive, THIS line is the conversion seam, never a
            // threshold.
            $wallet = \Alfa\Component\Alfa\Site\Service\Pricing\Currency::getDefault();
            $spend = \Alfa\Component\Alfa\Site\Service\Pricing\Money::min(
                $balance,
                \Alfa\Component\Alfa\Site\Service\Pricing\Money::of(\Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount::ofMoney($grandMoney)->text(), $wallet),
            );

            // NOTHING TO APPLY ONLY WHEN IT ROUNDS TO ZERO AT THAT SCALE.
            // One minor unit is money (0.01 EUR, 1 JPY, 0.001 KWD) and a
            // cutoff at "a cent" means a different thing in every currency:
            // nothing at all for yen, ten fils for dinar. A hard-coded two
            // also rounded a three-decimal balance up past itself, and the
            // ledger refused the spend it had just been offered.
            if (!$spend->isPositive()) {
                // They ticked the box and nothing happened: say so rather
                // than let the full charge look like the credit was applied.
                $this->warn('COM_ALFA_CART_CREDIT_NOT_APPLIED');

                return;
            }

            \Alfa\Component\Alfa\Administrator\Service\Credit\CustomerCreditLedger::spendOnOrder(
                order:          $this->order,
                amount:         $spend,
                grandTotalHint: $grand,
            );

            Log::add(
                sprintf(
                    'Order #%d: %s paid from customer credit at placement.',
                    (int) $this->order->id,
                    $spend->format(false),
                ),
                Log::INFO,
                'com_alfa.orders',
            );
        } catch (Throwable $e) {
            // Degrade: the gateway collects the full total; the spend's own
            // atomicity already restored the balance. But the customer OPTED
            // IN: a silent degrade is indistinguishable from being charged
            // twice, so they must be told before the gateway page.
            Log::add(
                'Order #' . (int) $this->order->id . ': customer credit not applied: ' . $e->getMessage(),
                Log::WARNING,
                'com_alfa.orders',
            );
            $this->warn('COM_ALFA_CART_CREDIT_NOT_APPLIED');
        }
    }
}
