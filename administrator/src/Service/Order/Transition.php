<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\OrderStatusHelper;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\OrderModel;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryEffectOutbox;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderDocuments;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderEntitlements;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStatusEmail;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStatusEvent;
use Alfa\Component\Alfa\Administrator\Service\Order\Task\OrderStock;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\ParameterType;
use Throwable;
use WeakReference;

/**
 * Transition
 *
 * THE single way an order changes status: the fluent sibling of the
 * PaymentLedger builder (payment = `paymentUpdate($id)->completed()
 * ->save()`, order = `Transition::for($id)->to($statusId)
 * ->save()`). Every caller (admin order edit, the payment-method
 * automation map, gateway plugins, the future subscriptions plugin) goes
 * through here, so the side effects can never diverge between paths.
 *
 * save() runs, in order, inside one window under the order row's lock:
 *   1. guards        - unknown order / same status = no-op; leaving the
 *                      is_cancelled family requires force() (a conscious
 *                      admin act, never automation)
 *   2. target        - must exist and be published; validated here because
 *                      every caller funnels through this method
 *   3. fact gates    - a status may declare requires_paid, answered by the
 *                      live balance; allow_unpaid_exception surfaces the
 *                      exception instead of blocking
 *   4. row update    - id_order_status is written BEFORE any side effect:
 *                      each one reads the order back and would otherwise
 *                      reconcile against the status being left
 *   5. what it owes  - the stock obligation, the timeline records
 *                      ('order.status_changed', and 'order.unpaid_exception'
 *                      when one was allowed), and the singles of the change
 *                      (Tasks): the stock drain, the download entitlements,
 *                      the documents, the status e-mail unless silent(), and
 *                      onOrderStatusChange. All of it commits with the status
 *                      or rolls back with it (2.1.4).
 *
 * The singles run once the outermost transaction has committed and Alfa's
 * named locks are released, in that order, each recorded as it ends: at once
 * when save() opened the transaction (before it returns, so the stock getters
 * answer), after the caller's commit when nested (the getters answer null
 * until then), never after a rollback. A request that dies leaves them to
 * recovery. An e-mail whose outcome is unknown, and onOrderStatusChange, are
 * never run twice automatically: staff decide (Tools > Tasks).
 *
 * @since  2.0.0
 */
class Transition
{
    /**
     * Order PK.
     */
    protected int $orderId = 0;

    /**
     * Target status id (0 = not set -> save() refuses).
     */
    protected int $toStatusId = 0;

    /**
     * Explicit previous status id, for callers whose order row was already
     * saved with the new status (OrderModel::save()). Null = read from DB.
     */
    protected ?int $fromStatusId = null;

    /**
     * Attribution: acting user id (defaults to the current identity).
     */
    protected ?int $userId = null;

    /**
     * Attribution: acting plugin element (e.g. 'vivawallet'), for automated
     * transitions where "who" is a system, not a person.
     */
    protected string $pluginName = '';

    /**
     * Skip the customer/admin status email.
     */
    protected bool $silent = false;

    /**
     * Allow leaving the is_cancelled family (conscious admin act only).
     */
    protected bool $force = false;

    /**
     * Extra human note for the activity log entry.
     */
    protected string $note = '';

    /**
     * Stock adjustment result of the last save() (['action' => ..., 'count'
     * => ...] or null): for callers that surface it to the admin UI.
     */
    protected ?array $stockResult = null;

    /**
     * Why the inventory service refused the last save()'s stock effect, as the
     * operator reads it; null when it was not refused.
     *
     * @since  2.0.0
     */
    private ?string $stockRefusal = null;

    /**
     * Why the last save()'s stock effect waits although no order line needs
     * resolving: the stock it needs is reserved for other claims. The text as the
     * operator reads it; null when the effect does not wait for that.
     *
     * @since  2.0.0
     */
    private ?string $stockReservedFloor = null;

    /**
     * What the last save()'s stock effect failed with when the movement stays
     * owed and a later attempt can apply it; null when it did not fail so.
     *
     * @since  2.0.0
     */
    private ?string $stockFailure = null;

    /**
     * TRUE while save() runs its window a second time after a deadlock.
     *
     * @since  2.0.0
     */
    private bool $retried = false;

    /**
     * The transitions waiting for their stock drain's outcome, by the drain's task id: the drain runs as the first
     * single of the change's batch and hands its outcome to the transition that wrote it, when that one still exists.
     *
     * @var array<int, WeakReference<self>>
     *
     * @since  2.1.4
     */
    private static array $awaiting = [];

    /**
     * Builders are created through for().
     */
    private function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    /**
     * Start a transition for an order.
     *
     * @param int $orderId Order PK.
     *
     * @since  2.0.0
     */
    public static function for(int $orderId): self
    {
        return new self($orderId);
    }

    /**
     * Target status.
     *
     * @param int $statusId `#__alfa_orders_statuses` PK.
     *
     * @since  2.0.0
     */
    public function to(int $statusId): self
    {
        $this->toStatusId = $statusId;

        return $this;
    }

    /**
     * Explicit previous status: for callers that already persisted the new
     * one (the admin OrderModel) or that know better than the DB row.
     *
     * @param int $statusId Previous status id.
     *
     * @since  2.0.0
     */
    public function from(int $statusId): self
    {
        $this->fromStatusId = $statusId;

        return $this;
    }

    /**
     * Attribute the transition to a user (defaults to the current identity).
     *
     * @param int $userId Joomla user id.
     *
     * @since  2.0.0
     */
    public function byUser(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Attribute the transition to a plugin/automation.
     *
     * @param string $pluginName Plugin element, e.g. 'vivawallet'.
     *
     * @since  2.0.0
     */
    public function byPlugin(string $pluginName): self
    {
        $this->pluginName = $pluginName;

        return $this;
    }

    /**
     * Skip the status email (both customer and admin recipients).
     *
     * @since  2.0.0
     */
    public function silent(): self
    {
        $this->silent = true;

        return $this;
    }

    /**
     * Permit leaving the is_cancelled family. Automation must never pass
     * this: reinstating a cancelled order is an admin decision.
     *
     * @since  2.0.0
     */
    public function force(): self
    {
        $this->force = true;

        return $this;
    }

    /**
     * Extra context line for the activity log.
     *
     * @param string $note Human-readable note.
     *
     * @since  2.0.0
     */
    public function note(string $note): self
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Stock adjustment performed by the last save(), for UI messages.
     *
     * Answered once the change's stock single ran: before save() returns when
     * save() opened the transaction; after the caller's commit when nested
     * (null until then, 2.1.4).
     *
     * @return array|null ['action' => string, 'count' => int], or null when the
     *                    stock effect was not applied: still owed and retried,
     *                    or refused ({@see self::getStockRefusal()} tells which;
     *                    {@see self::getStockReservedFloor()} says when an owed
     *                    effect waits for stock other claims reserve, and
     *                    {@see self::getStockFailure()} what any other owed
     *                    effect failed with).
     *
     * @since  2.0.0
     */
    public function getStockResult(): ?array
    {
        return $this->stockResult;
    }

    /**
     * Why the inventory service refused the last save()'s stock effect.
     *
     * A refused effect is not retried: an order line has no stock identity to
     * move against, and the line must be resolved on the Inventory audit page
     * first. A caller that reports a null stock result tells the operator this
     * instead of promising that the movement will be applied automatically.
     *
     * The text is raw: a caller that renders it as HTML escapes it.
     *
     * @return string|null The refusal as the operator reads it (one sentence per
     *                     unresolved order line), or null when it was not refused.
     *
     * @since  2.0.0
     */
    public function getStockRefusal(): ?string
    {
        return $this->stockRefusal;
    }

    /**
     * Why the last save()'s stock effect waits for stock.
     *
     * The inventory service refused the movement because the stock it needs is
     * reserved for other claims: a fulfilment never takes what another order's
     * reservation holds. The movement stays owed and is applied by a later
     * attempt once those claims are lowered or cancelled, or stock is received.
     * A caller that reports a null stock result shows this to the operator, so
     * the stock in the way is named.
     *
     * The text is raw: a caller that renders it as HTML escapes it.
     *
     * @return string|null The refusal as the operator reads it (the unit, its
     *                     location, the reserved quantity, the smallest quantity
     *                     the location may keep, the quantity on hand and what the
     *                     movement would leave), or null when the effect does not
     *                     wait for reserved stock.
     *
     * @since  2.0.0
     */
    public function getStockReservedFloor(): ?string
    {
        return $this->stockReservedFloor;
    }

    /**
     * What the last save()'s stock effect failed with, when the movement stays
     * owed and a later attempt can apply it.
     *
     * Neither refused for good nor waiting for reserved stock: the attempt
     * failed, and the order still owes the movement, which a later attempt
     * applies (Tools -> Database Sync -> Apply outstanding stock movements). A
     * caller that reports a null stock result shows this to the operator, so
     * the failure is named rather than passed over.
     *
     * The text is raw: a caller that renders it as HTML escapes it.
     *
     * @return string|null What the movement failed with, or null when the effect
     *                     did not fail in a way a later attempt can mend.
     *
     * @since  2.0.0
     */
    public function getStockFailure(): ?string
    {
        return $this->stockFailure;
    }

    /**
     * Whether a status can be landed in at all: it exists and is published.
     *
     * The structural half of the question. Order CREATION asks only this:
     * there is no order yet to weigh a payment gate against. An existing
     * order asks {@see refusalReason}, which adds what the status requires.
     *
     * @param int $statusId Candidate `#__alfa_orders_statuses` PK.
     *
     *
     * @since  2.0.0
     */
    public static function isValidTarget(int $statusId): bool
    {
        return $statusId > 0 && self::loadTarget($statusId) !== null;
    }

    /**
     * The target status row, or null when it does not exist or is unpublished.
     *
     * @param int $statusId Status PK.
     *
     *
     * @since  2.0.0
     */
    private static function loadTarget(int $statusId): ?object
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['state', 'requires_paid', 'allow_unpaid_exception']))
                ->from($db->quoteName('#__alfa_orders_statuses'))
                ->where($db->quoteName('id') . ' = ' . $statusId),
        );
        $row = $db->loadObject();

        return ($row !== null && (int) $row->state === 1) ? $row : null;
    }

    /**
     * Say that the stock effect has not happened YET, and that it will.
     *
     * The order and the shelf disagree from this moment, which is not a log
     * line's worth of news: it is the same shape the money paths treat as
     * CRITICAL. What has changed since that escalation was written is the
     * ending: the effect is no longer lost, it is outstanding, and the
     * operator is told to wait rather than to reconcile by hand. The alert is
     * retracted by whichever worker finally applies it, so a problem that
     * fixes itself stops shouting.
     *
     * A REFUSED effect is different: the inventory service refused it because
     * an order line has no stock identity to move against, and a retry meets
     * the same refusal. The notice and the activity log then say so and ask
     * for the order line to be resolved on the Inventory audit page, which
     * queues the effect again, instead of pointing at the retry.
     *
     * An effect that waits for stock other claims reserve is owed like any other
     * outstanding one; its notice names that stock in the operator's words.
     *
     * @param int $orderId The order.
     * @param int $oldStatusId Where the order came from.
     * @param int $toStatusId Where it went.
     * @param string $reason What the attempt failed with, as the service
     *                       reported it (log and activity record).
     * @param bool $refused Whether the effect was refused in a way a retry
     *                      cannot change.
     * @param mixed $notice The same reason as the notification's parameter:
     *                      language keys and their values, so every reader and the
     *                      snapshot say it in one language, or the service's
     *                      message as written.
     * @param string $noticeKey Language key of the notification's text; it takes
     *                          one value, the notice.
     *
     *
     * @since  2.0.0
     */
    private static function reportStockDeferred(int $orderId, int $oldStatusId, int $toStatusId, string $reason, bool $refused, mixed $notice, string $noticeKey): void
    {
        Log::add(
            entry:    'CRITICAL: order ' . $orderId . ' transitioned ' . $oldStatusId . ' -> '
                      . $toStatusId
                      . ($refused
                          ? ' but its stock effect was refused: ' . $reason
                            . '. A retry cannot apply it; the order line must be resolved on the Inventory audit page first.'
                          : ' but its stock effect failed: ' . $reason
                            . '. The order and the shelf disagree until the obligation is applied.'),
            priority: Log::CRITICAL,
            category: 'com_alfa.orders',
        );

        try {
            NotificationHelper::push(
                dedupKey: InventoryEffectOutbox::orderNoticeKey(orderId: $orderId),
                title:    NotificationText::of('COM_ALFA_NOTIFY_STOCK_ORDER_TITLE', $orderId),
                options:  InventoryEffectOutbox::noticeOptions(
                    message: NotificationText::of($noticeKey, $notice),
                    refused: $refused,
                ),
            );
        } catch (Throwable $notifyError) {
            // Advisory only: a failed notification must never be the reason a
            // transition reports failure, unless it ended a caller's transaction.
            if (TransactionState::endedBy(db: Factory::getContainer()->get('DatabaseDriver'), failure: $notifyError)) {
                throw $notifyError;
            }
        }

        OrderModel::logOrderActivity(
            orderId: $orderId,
            event:   'order.stock_failed',
            summary: $refused
                ? 'Stock was not moved for ' . $oldStatusId . ' -> ' . $toStatusId
                  . ' (' . $reason . '); a retry cannot apply it: resolve the order line on the Inventory audit page, and the movement is queued again.'
                : 'Stock was not adjusted for ' . $oldStatusId . ' -> ' . $toStatusId
                  . ' (' . $reason . '); the order still owes it: Tools -> Database Sync -> Apply outstanding stock movements applies it.',
            context: [
                'from' => $oldStatusId,
                'to' => $toStatusId,
                'error' => $reason,
                'refused' => $refused,
            ],
        );
    }

    /**
     * Why an order may not enter a status, or null when it may.
     *
     * Callers that persist the order row themselves (the admin save writes
     * `id_order_status` through parent::save() before handing over) must ask
     * this BEFORE writing. Refusing only inside save() leaves the row holding
     * a status whose side effects never ran.
     *
     * @param int $orderId Order PK.
     * @param int $toStatusId Candidate `#__alfa_orders_statuses` PK.
     *
     * @return string|null Human-readable reason, or null when admissible.
     *
     * @since  2.0.0
     */
    public static function refusalReason(int $orderId, int $toStatusId): ?string
    {
        return self::inspectTarget(orderId: $orderId, toStatusId: $toStatusId, lockedFromStatusId: null)['reason'];
    }

    /**
     * Resolve a target: may the order enter it, and does that need an
     * unpaid exception?
     *
     * A status that holds a reservation takes no order with a line that would
     * wait for its stock without one: a line placed as a stock claim whose unit
     * does not track stock now ({@see InventoryBridge::unheldClaimRefusal()}).
     * Asked by save() under the order's row lock for a move that changes the
     * order's claim into one that holds a reservation, the answer is judged under
     * the stock-record locks of the order's units and holds until that
     * transaction ends; every other caller reads plainly.
     *
     * @param int $orderId Order PK.
     * @param int $toStatusId Candidate status PK.
     * @param int|null $lockedFromStatusId The status the order is leaving, when the caller
     *                                     holds the order's row lock inside a transaction;
     *                                     null for a check that locks nothing.
     *
     * @return array{reason: string|null, unpaid_exception: bool, facts: object|null}
     *
     * @since  2.0.0
     */
    private static function inspectTarget(int $orderId, int $toStatusId, ?int $lockedFromStatusId): array
    {
        $refuse = static fn (string $why): array => ['reason' => $why, 'unpaid_exception' => false, 'facts' => null];

        if ($toStatusId <= 0) {
            return $refuse('no target status given');
        }

        $target = self::loadTarget($toStatusId);

        if ($target === null) {
            return $refuse('status ' . $toStatusId . ' does not exist or is not published');
        }

        // An order that names no currency is not moved. Entering a status can
        // issue a document, settle the paid gate and tell the customer what
        // the order is worth, and none of it can be said without the order's
        // own currency. Decided here, before the row is written. A reason is
        // a clause its reader ends, so the sentence gives up its full stop.
        try {
            OrderCurrency::of(orderId: $orderId);
        } catch (OrderCurrencyException $e) {
            return $refuse(rtrim($e->getMessage(), '.'));
        }

        // A status that holds a reservation takes no order with a line that
        // would wait for its stock without one. The stock-record locks are taken
        // only where the move changes the order's claim into that one; a move
        // between two such statuses, and a check made outside the locked window,
        // read plainly. The sentence gives up its full stop, as a reason does.
        if (StockPolicy::claimFor(statusId: $toStatusId) === StockPolicy::CLAIM_RESERVATION) {
            $unheld = InventoryBridge::unheldClaimRefusal(
                orderId: $orderId,
                claim:   StockPolicy::CLAIM_RESERVATION,
                lock:    $lockedFromStatusId !== null
                    && StockPolicy::claimFor(statusId: $lockedFromStatusId) !== StockPolicy::CLAIM_RESERVATION,
            );

            if ($unheld !== null) {
                return $refuse(why: rtrim(string: $unheld, characters: '.'));
            }
        }

        if ((int) ($target->requires_paid ?? 0) !== 1) {
            return ['reason' => null, 'unpaid_exception' => false, 'facts' => null];
        }

        $facts = OrderFacts::forOrder($orderId);

        // The order's own payment fact, not a second opinion about it:
        // OrderFacts settles the balance at the order currency's scale, so a
        // status that requires payment is admitted exactly when nothing
        // on-scale is owed.
        if ($facts->is_paid) {
            return ['reason' => null, 'unpaid_exception' => false, 'facts' => $facts];
        }

        if ((int) ($target->allow_unpaid_exception ?? 0) !== 1) {
            // The merchant's figure on the ORDER'S scale: a hard-coded two
            // decimals tells a scale-8 merchant that 0.00 is still due.
            return $refuse('status ' . $toStatusId . ' requires payment and '
                . number_format($facts->balance, (int) $facts->currency_scale) . ' is still due');
        }

        return ['reason' => null, 'unpaid_exception' => true, 'facts' => $facts];
    }

    /**
     * Execute the transition.
     *
     * @return bool True when the transition ran; false on any refused guard
     *              (unknown order, no target, same status, cancelled-family
     *              exit without force()).
     *
     * @since  2.0.0
     */
    public function save(): bool
    {
        $this->stockResult = null;
        $this->stockRefusal = null;
        $this->stockReservedFloor = null;
        $this->stockFailure = null;

        if ($this->orderId <= 0 || $this->toStatusId <= 0) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        // Existence, and the status as it reads here, kept only to name it when
        // the lock itself cannot be had. WHAT THE STATUS IS, for every decision,
        // gets read under the lock below, never here: this read and the
        // decision that follows it used to be two separate moments, and a
        // duplicate callback arriving in between saw the status as it had been
        // rather than as the first callback had just left it.
        //
        // bind() takes its value by reference, and the PDO driver converts a
        // value bound as a string in place: the typed property would end up a
        // string. A local copy is bound, as an integer.
        $orderId = $this->orderId;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'id_order_status']))
            ->from($db->quoteName('#__alfa_orders'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $orderId, ParameterType::INTEGER);
        $db->setQuery(query: $query);
        $existing = $db->loadObject();

        if ((int) ($existing->id ?? 0) !== $this->orderId) {
            return false;
        }

        $readStatusId = (int) $existing->id_order_status;

        // Admissibility and the row write happen under the order-row lock, in
        // one transaction. The gate reads money (the current total and the
        // ledger), so deciding outside the lock is a check-then-act: a refund
        // landing in between would leave the row written into a status the
        // gate had just approved and would now refuse, with none of the side
        // effects run. Locking is what makes the decision and the write the
        // same event.
        //
        // A busy database inside the window is answered by busy(): whether
        // this call opened the transaction decides what a deadlock means.
        $outermost = !TransactionState::isOpen($db);
        $db->transactionStart(true);

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'id_order_status']))
                    ->from($db->quoteName('#__alfa_orders'))
                    ->where($db->quoteName('id') . ' = ' . $this->orderId)
                    . ' FOR UPDATE',
            );

            // A lock-wait timeout here is an ordinary outcome, refused cleanly
            // by the catch below (busy()).
            $lockedRow = $db->loadObject();
            $lockedId = (int) ($lockedRow->id ?? 0);

            if ($lockedId !== $this->orderId) {
                TransactionState::rollBackLevel($db);

                return false;
            }

            $lockedStatusId = (int) $lockedRow->id_order_status;

            // WHERE THE ORDER IS, AS OF NOW. Everything below decides on this
            // value, taken while holding the row, so two callers cannot both
            // read the same "before" and both act on it.
            //
            // from() is the one case where the row has ALREADY been written by
            // the caller: the admin order save writes the status itself and
            // then asks for the consequences. There the row must actually be
            // at the target: if someone else has moved it since, the
            // transition the caller is describing did not survive, and running
            // its side effects would apply them to a status the order is no
            // longer in.
            if ($this->fromStatusId !== null) {
                if ($lockedStatusId !== $this->toStatusId) {
                    TransactionState::rollBackLevel($db);

                    return false;
                }

                $oldStatusId = $this->fromStatusId;
            } else {
                $oldStatusId = $lockedStatusId;

                // Same status = no-op. This is the duplicate-callback case,
                // and it is the whole point of reading under the lock: the
                // second of two identical concurrent callbacks now sees the
                // first one's write and stops here, instead of falling through
                // to re-run stock movements, a fiscal document that consumes a
                // GAPLESS number, and the customer's status email.
                if ($oldStatusId === $this->toStatusId) {
                    TransactionState::rollBackLevel($db);

                    return false;
                }
            }

            // Every caller funnels through here, so admissibility is checked once:
            // the target must exist, be published, and satisfy whatever the status
            // requires. An unknown status would write a dangling id_order_status
            // that later lookups read as "no status".
            $inspection = self::inspectTarget(
                orderId:            $this->orderId,
                toStatusId:         $this->toStatusId,
                lockedFromStatusId: $oldStatusId,
            );
            $unpaidException = $inspection['unpaid_exception'];
            $facts = $inspection['facts'];

            if ($inspection['reason'] !== null) {
                // Logged before the level is rolled back: a log write that
                // throws still finds the level open, and the catch closes it
                // once.
                Log::add(
                    entry:    'Transition refused for order ' . $this->orderId . ': ' . $inspection['reason'] . '.',
                    priority: Log::WARNING,
                    category: 'com_alfa.orders',
                );
                TransactionState::rollBackLevel($db);
            }
        } catch (Throwable $e) {
            // Nothing inside the locked window may leave the order row held
            // and the transaction depth out of step with the server: every
            // later write in this request would then commit into nothing.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            $answer = $this->busy(failure: $e, outermost: $outermost, fromStatusId: $lockedStatusId ?? $readStatusId);

            if ($answer === null) {
                throw $e;
            }

            return $answer;
        }

        if ($inspection['reason'] !== null) {
            // A refusal is not a no-op: automation may have captured money on
            // the strength of a transition that never happened. It goes on the
            // timeline, where an operator sees it without reading a log file.
            // Written once the window is rolled back, which would take it too.
            OrderModel::logOrderActivity(
                $this->orderId,
                'order.transition_refused',
                'Refused ' . $oldStatusId . ' -> ' . $this->toStatusId . ': ' . $inspection['reason'],
                [
                    'from' => $oldStatusId,
                    'to' => $this->toStatusId,
                    'reason' => $inspection['reason'],
                    'actor' => $this->pluginName !== '' ? "plugin:{$this->pluginName}" : 'user:' . $this->resolveUserId(),
                ],
            );

            return false;
        }

        try {
            // Leaving the cancelled family only happens on a conscious admin act.
            if (
                !$this->force
                && OrderStatusHelper::isCancelled($oldStatusId)
                && !OrderStatusHelper::isCancelled($this->toStatusId)
            ) {
                Log::add(
                    entry:    'Transition refused for order ' . $this->orderId . ': leaving the cancelled family ('
                              . $oldStatusId . ' -> ' . $this->toStatusId . ') requires force().',
                    priority: Log::WARNING,
                    category: 'com_alfa.orders',
                );
                TransactionState::rollBackLevel($db);

                return false;
            }

            // State first, consequences second. Every side effect below reads
            // the order back: StockPolicy resolves the claim through
            // InventoryBridge from id_order_status, as do the entitlement,
            // document and event paths. Writing the row afterwards made each of
            // them reconcile against the status being left, so a reservation
            // status kept its physical deduction.
            // Skipped when the caller already saved the row (explicit from()).
            $wrote = false;

            if ($lockedStatusId !== $this->toStatusId) {
                $update = (object) [
                    'id' => $this->orderId,
                    'id_order_status' => $this->toStatusId,
                    'modified' => Factory::getDate('now', 'UTC')->toSql(),
                    'modified_by' => $this->resolveUserId(),
                ];
                $db->updateObject('#__alfa_orders', $update, 'id');
                $wrote = true;
            }

            // WHO OWNS THE CONSEQUENCES. Exactly one caller may run them: the one
            // that moved the row, or the from() caller that moved it itself and is
            // asking for them explicitly. Everything after the commit (stock
            // movements, fiscal-document auto-issue, the status email) is
            // irreversible or externally visible, and the chain used to run
            // unconditionally, including on the path where the write was skipped
            // because the row was already at the target.
            $ownsSideEffects = $wrote || $this->fromStatusId !== null;

            // What this transition owes the shelf, committed with it
            //
            // The status commits here and its stock effect runs after the
            // lock is released, so everything in between used to be a loss
            // window, and the loss was permanent: the effect was never
            // retried, and handleStatusTransition() short-circuits on
            // `$oldClaim === $newClaim`, which on the shipped status set is
            // almost every transition (1-4 all claim physical, 5-6 none). One
            // miss and no later transition ever repaired it.
            //
            // Recording the obligation inside this transaction ends both: it
            // rolls back with a refused transition, and it survives a process
            // that dies before the effect runs.
            //
            // Only a CLAIM-CHANGING transition owes anything, so the existing
            // fast path is untouched: a claim-equal move enqueues nothing and
            // costs nothing. It does not need to: a miss stays outstanding on
            // the order's own obligation until it is answered.
            if ($ownsSideEffects && StockPolicy::claimFor($oldStatusId) !== StockPolicy::claimFor($this->toStatusId)) {
                InventoryEffectOutbox::enqueue(
                    sourceType: 'order_claim',
                    sourceId:   $this->orderId,
                    orderId:    $this->orderId,
                    payload:    (string) $this->toStatusId,
                    restate:    true,
                );
            }

            // Everything else the change owes, written with it (2.1.4): its timeline records and its singles commit
            // with the status or roll back with it. Exactly one caller owns them (above).
            if ($ownsSideEffects) {
                $this->writeConsequences(oldStatusId: $oldStatusId, unpaidException: $unpaidException, facts: $facts);
            }
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            $answer = $this->busy(failure: $e, outermost: $outermost, fromStatusId: $oldStatusId);

            if ($answer === null) {
                throw $e;
            }

            return $answer;
        }

        // The decision and the write are done; release the lock before the
        // side effects, which are long-running (stock, documents, email) and
        // must not hold the order row for their duration. Committed in its own
        // try: a COMMIT that fails is closed by rollBackLevel() and answered
        // like any failure in the window.
        try {
            TransactionState::commitLevel($db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            $answer = $this->busy(failure: $e, outermost: $outermost, fromStatusId: $oldStatusId);

            if ($answer === null) {
                throw $e;
            }

            return $answer;
        }

        // The singles of the change ran in commitLevel() when save() opened the transaction (the stock drain first,
        // its outcome handed to this object); nested, they wait for the caller's commit and lock release.
        return true;
    }

    /**
     * The answer to a busy database in the window, once its level is closed.
     *
     * A deadlock ends the whole transaction. When save() opened it, nothing of
     * a caller's was lost, and the window runs once more; a second deadlock is
     * refused. Inside a caller's transaction the caller's work is gone with it,
     * and the caller must hear the deadlock itself. A lock wait that timed out
     * is refused. A refusal is never silent: it is logged and written to the
     * order's timeline, where an operator sees that the move was not made.
     *
     * @param Throwable $failure What failed in the window.
     * @param bool $outermost Whether save() opened the transaction.
     * @param int|null $fromStatusId The status the order was read in, when it was read.
     *
     * @return bool|null What save() answers; null when the failure is rethrown.
     *
     * @since  2.0.0
     */
    private function busy(Throwable $failure, bool $outermost, ?int $fromStatusId): ?bool
    {
        $number = MysqlError::number($failure);

        if ($number !== 1213 && $number !== 1205) {
            return null;
        }

        if ($number === 1213 && !$outermost) {
            return null;
        }

        if ($number === 1213 && !$this->retried) {
            $this->retried = true;

            try {
                return $this->save();
            } finally {
                $this->retried = false;
            }
        }

        $reason = $number === 1205
            ? 'the order row is held by another writer'
            : 'the database ended the change twice to resolve a deadlock';

        Log::add(
            entry:    'Transition refused for order ' . $this->orderId . ': ' . $reason . ' - ' . $failure->getMessage(),
            priority: Log::WARNING,
            category: 'com_alfa.orders',
        );

        OrderModel::logOrderActivity(
            $this->orderId,
            'order.transition_refused',
            'Refused ' . ($fromStatusId ?? '?') . ' -> ' . $this->toStatusId . ': ' . $reason,
            [
                'from' => $fromStatusId,
                'to' => $this->toStatusId,
                'reason' => $reason,
                'actor' => $this->pluginName !== '' ? "plugin:{$this->pluginName}" : 'user:' . $this->resolveUserId(),
            ],
        );

        return false;
    }

    /**
     * Write what the change owes, inside its window: the stock drain's single first (the batch runs it before the
     * rest), the timeline records, then the singles of the change in the order they run.
     *
     * @param int $oldStatusId The status the order left.
     * @param bool $unpaidException Whether a status requiring payment was entered with money due.
     * @param object|null $facts The order's payment facts, when the gate read them.
     *
     * @since  2.1.4
     */
    private function writeConsequences(int $oldStatusId, bool $unpaidException, ?object $facts): void
    {
        $orderId = $this->orderId;
        $toStatusId = $this->toStatusId;
        $actor = $this->pluginName !== '' ? "plugin:{$this->pluginName}" : 'user:' . $this->resolveUserId();

        // The stock drain (the obligation above, and one an earlier change left owed): first in the batch, its
        // outcome handed back to this object for the stock getters.
        $stockTask = Tasks::single(handler: OrderStock::NAME, subjectType: 'order', subjectId: $orderId, payload: ['from' => $oldStatusId, 'to' => $toStatusId]);
        self::$awaiting[$stockTask] = WeakReference::create($this);

        // The timeline, atomic with the status: a request that dies after the commit cannot lose it. A record that
        // cannot be written raises the critical staff notice and its log line (logOrderActivity()); the e-mail single
        // below still commits with the change, so the e-mail is sent.
        OrderModel::logOrderActivity(
            $orderId,
            'order.status_changed',
            "Status {$oldStatusId} -> {$toStatusId} ({$actor})" . ($this->note !== '' ? ': ' . $this->note : ''),
            [
                'old_status' => $oldStatusId,
                'new_status' => $toStatusId,
                'actor' => $actor,
                'note' => $this->note,
            ],
        );

        // An allowed unpaid completion is an exception, not a silent pass:
        // it belongs on the timeline, showing money was owed at the time.
        if ($unpaidException && $facts !== null) {
            $due = number_format($facts->balance, (int) $facts->currency_scale);

            OrderModel::logOrderActivity(
                $orderId,
                'order.unpaid_exception',
                'Entered a status requiring payment with ' . $due . ' still due',
                [
                    'status' => $toStatusId,
                    'balance' => $facts->balance,
                    'actor' => $actor,
                ],
            );

            // A log line that claims what happened is written once it committed.
            PostCommit::defer(
                db:    Factory::getContainer()->get('DatabaseDriver'),
                label: 'the unpaid exception log line of order #' . $orderId,
                work:  static function () use ($orderId, $toStatusId, $due): void {
                    Log::add(
                        entry:    'Unpaid exception on order ' . $orderId . ': entered status ' . $toStatusId . ' with ' . $due . ' due.',
                        priority: Log::WARNING,
                        category: 'com_alfa.orders',
                    );
                },
            );
        }

        Tasks::single(handler: OrderEntitlements::NAME, subjectType: 'order', subjectId: $orderId, payload: ['status' => $toStatusId]);
        Tasks::single(handler: OrderDocuments::NAME, subjectType: 'order', subjectId: $orderId, payload: ['status' => $toStatusId]);

        if (!$this->silent) {
            Tasks::single(handler: OrderStatusEmail::NAME, subjectType: 'order', subjectId: $orderId, payload: ['status' => $toStatusId]);
        }

        Tasks::single(handler: OrderStatusEvent::NAME, subjectType: 'order', subjectId: $orderId, payload: [
            'from' => $oldStatusId,
            'to' => $toStatusId,
            'actor' => $actor,
            'note' => $this->note,
            'at' => Factory::getDate('now', 'UTC')->toSql(),
        ]);
    }

    /**
     * Drain an order's stock obligation after a status change, and say so when it stays owed or was refused: what
     * the order's stock single runs ({@see OrderStock}).
     *
     * Applied by draining the obligation the change committed rather than by calling the reconciler directly: if
     * this fails (or the process never gets here), the obligation is still recorded and outstanding, and Tools >
     * Database Sync finishes it. The drain also picks up an obligation an earlier change left owed, which is why a
     * claim-equal move heals an order the short-circuit would once have skipped for ever.
     *
     * @param int $orderId The order.
     * @param int $oldStatusId The status it left.
     * @param int $toStatusId The status it entered.
     *
     * @return array{result: array{action: string, count: int}|null, refusal: string|null, reservedFloor: string|null, failure: string|null}
     *
     * @since  2.1.4
     */
    public static function drainFor(int $orderId, int $oldStatusId, int $toStatusId): array
    {
        $outcome = ['result' => ['action' => 'none', 'count' => 0], 'refusal' => null, 'reservedFloor' => null, 'failure' => null];

        try {
            $drained = InventoryEffectOutbox::drain(
                limit:          1,
                onlySourceId:   $orderId,
                onlySourceType: 'order_claim',
            );

            if (isset($drained['results'][$orderId])) {
                $applied = $drained['results'][$orderId];
                $outcome['result'] = [
                    'action' => (string) ($applied['action'] ?? 'none'),
                    'count' => (int) ($applied['count'] ?? 0),
                ];
            }

            if (isset($drained['refusals'][$orderId])) {
                // Refused, not failed: a retry meets the same refusal, so the
                // notice asks for the order line to be resolved instead, and
                // the caller can say so rather than promise a retry.
                $outcome['result'] = null;
                $outcome['refusal'] = (string) $drained['refusalReasons'][$orderId];

                self::reportStockDeferred(
                    orderId:     $orderId,
                    oldStatusId: $oldStatusId,
                    toStatusId:  $toStatusId,
                    reason:      (string) $drained['refusals'][$orderId],
                    refused:     true,
                    notice:      $drained['refusalNotices'][$orderId] ?? $outcome['refusal'],
                    noticeKey:   'COM_ALFA_NOTIFY_STOCK_ORDER_REFUSED_MSG',
                );
            } elseif (isset($drained['reservedFloor'][$orderId])) {
                // Owed, not refused for good: other claims reserve the stock the
                // movement needs. The caller and the notice read which stock, in
                // the operator's words; the log and the activity record keep the
                // service's own text.
                $outcome['result'] = null;
                $outcome['reservedFloor'] = (string) $drained['reservedFloor'][$orderId];

                self::reportStockDeferred(
                    orderId:     $orderId,
                    oldStatusId: $oldStatusId,
                    toStatusId:  $toStatusId,
                    reason:      (string) ($drained['errors'][$orderId] ?? 'unknown'),
                    refused:     false,
                    notice:      $drained['reservedFloorNotices'][$orderId] ?? $outcome['reservedFloor'],
                    noticeKey:   'COM_ALFA_NOTIFY_STOCK_ORDER_RESERVED_MSG',
                );
            } elseif (($drained['failed'] ?? 0) > 0) {
                $outcome['result'] = null;
                $outcome['failure'] = (string) ($drained['errors'][$orderId] ?? 'unknown');

                self::reportStockDeferred(
                    orderId:     $orderId,
                    oldStatusId: $oldStatusId,
                    toStatusId:  $toStatusId,
                    reason:      $outcome['failure'],
                    refused:     false,
                    notice:      $drained['failureNotices'][$orderId] ?? $outcome['failure'],
                    noticeKey:   'COM_ALFA_NOTIFY_STOCK_ORDER_MSG',
                );
            }
        } catch (Throwable $e) {
            // \Throwable, not Exception. The order row is COMMITTED by the
            // time this runs, so an Error raised anywhere in the stock chain,
            // a plugin's type error, a missing class, must not escape: the
            // obligation stays owed and is reported.
            $outcome['result'] = null;
            $outcome['failure'] = $e->getMessage();

            self::reportStockDeferred(
                orderId:     $orderId,
                oldStatusId: $oldStatusId,
                toStatusId:  $toStatusId,
                reason:      $e->getMessage(),
                refused:     false,
                notice:      $e->getMessage(),
                noticeKey:   'COM_ALFA_NOTIFY_STOCK_ORDER_MSG',
            );
        }

        return $outcome;
    }

    /**
     * Hand a stock drain's outcome to the transition that wrote its single, when that one still exists in this
     * request: what its stock getters answer from then on.
     *
     * @param int $taskId The drain's task.
     * @param array{result: array{action: string, count: int}|null, refusal: string|null, reservedFloor: string|null, failure: string|null} $outcome
     *
     * @since  2.1.4
     */
    public static function deliverStockOutcome(int $taskId, array $outcome): void
    {
        $transition = (self::$awaiting[$taskId] ?? null)?->get();
        unset(self::$awaiting[$taskId]);

        if ($transition === null) {
            return;
        }

        $transition->stockResult = $outcome['result'];
        $transition->stockRefusal = $outcome['refusal'];
        $transition->stockReservedFloor = $outcome['reservedFloor'];
        $transition->stockFailure = $outcome['failure'];
    }

    /**
     * Acting user id: explicit byUser() or the current identity.
     *
     * @since  2.0.0
     */
    protected function resolveUserId(): int
    {
        if ($this->userId !== null) {
            return $this->userId;
        }

        try {
            return (int) (Factory::getApplication()->getIdentity()?->id ?? 0);
        } catch (Throwable $e) {
            // A helper with a sane default may not be the thing that fails a
            // transition: no application, no identity, an Error out of either
            // - the answer is still "nobody".
            return 0;
        }
    }
}
