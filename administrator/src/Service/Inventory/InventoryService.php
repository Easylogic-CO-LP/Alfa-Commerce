<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryAdjustedEvent;
use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryBeforeCommitEvent;
use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryBeforeReleaseEvent;
use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryBeforeReserveEvent;
use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryCommittedEvent;
use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryReleasedEvent;
use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryReservedEvent;
use Alfa\Component\Alfa\Administrator\Event\Inventory\InventoryTransferredEvent;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\PostCommit;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Order\ItemFieldsSnapshot;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use RuntimeException;
use Throwable;

/**
 * The inventory kernel: the only writer of stock.
 *
 * Every quantity change is a command run here as one transaction: row locks,
 * guards, an appended movement, the stock row and its derived figures. No model,
 * helper or plugin writes a stock record, a movement, a reservation or a stock
 * figure.
 *
 * Authority and derived figures:
 *   #__alfa_inventory_stock.quantity            physical quantity per (item, combination, location)
 *   #__alfa_inventory_stock.reserved_quantity   sum of ACTIVE reservation remainders on that record
 *   #__alfa_inventory_movements / _reservations the ledger and the claims
 *   items.stock / item_combinations.stock       physical figure of the unit (for an item with
 *                                               current combinations: over every current, not
 *                                               archived, combination unit)
 *   items.available_quantity / combinations'    salable figure at enabled locations (for an item
 *                                               with current combinations: over its published,
 *                                               tracked combination units)
 * The figures are display and sort values maintained relatively by
 * propagateToReadModels() and repaired only by reconcile(); availability is always
 * read from the stock records.
 *
 * Units: an item without current combinations is itself the unit (item, 0); an
 * item with current (published or unpublished) combinations stocks only through
 * them, and its own (item, 0) records are stranded stock, never sold. A unit is
 * tracked when its effective manage_stock is 1 (a combination's own value wins, NULL
 * inherits the item, NULL at both levels is untracked). Untracked units sell without
 * a ceiling and hold no reservations. Only CURRENT units take in stock or claims, are
 * given a ceiling or are counted in a figure.
 *
 * Historical identities: an archived combination, the (item, 0) identity of an
 * item with current combinations, and every identity of an archived product
 * (items.state 2) are kept as the stock identity of order history. Fulfilment,
 * cancellation and returns of existing order lines act on them (an order line acts
 * on the identity it was sold as, or on the one its effective recorded resolution
 * names, never on an inferred one); nothing new is reserved, received or sold on
 * them. The records of an archived combination and of the (item, 0) identity of an
 * item with current combinations are in no parent figure.
 *
 * Stock claims: whether an order line's fulfilment deducts stock is decided by how
 * it was placed (the placement record written by recordPlacement(), or for a line
 * placed before those records existed, its own reservations and deductions), never
 * by its identity's tracking now. An open order line placed as a stock claim with
 * quantity it has never been delivered keeps its identity from being removed,
 * archived or written off ({@see OpenLineClaims}); quantity a physical return
 * brought back is settled and claims nothing.
 *
 * Integrity holds: a return or cancellation that records stock on a unit whose
 * figures claim stock without records gives it records, so the unit is held: it
 * stays in the missing-records state for every command and reader until the
 * audited repair (reconcile()) ends the hold.
 *
 * Transactions and announcements: a command is one level, a transaction or a
 * savepoint inside its caller's, committed through TransactionState::commitLevel()
 * and closed after a failure through TransactionState::rollBackLevel(), on either
 * MySQL driver. Its AFTER events (onInventoryReserved, onInventoryReleased,
 * onInventoryCommitted, onInventoryAdjusted, onInventoryTransferred) and its
 * success log lines wait for the outermost commit and are delivered once after it,
 * never after a rollback ({@see PostCommit}); with no transaction open they are
 * delivered at once. BEFORE events run before the level opens and veto by throwing.
 *
 * Commands:
 *   reserve() / release() / releaseLine() / expireDueReservations()   claims, no movement
 *   prioritiseReservations()   the audited override that serves a source's claims first
 *   commitReservation()   fulfilment: reservations become deductions
 *   restore() / reverseRestores() / scrapRestored()   reversal on the line's acting identity
 *   receive() / adjust() / adjustTo() / transfer()     physical changes of a tracked unit
 *   changeTracking() / changeCombinationState() / changeLocationState()   structural state
 *   archiveUnits() / reactivateUnits()                 combinations kept for order history
 *   changeItemArchive()   a product leaves current commerce, or is restored to it after checks
 *   retireUnits() / writeOffStock() / reconcile()      audited structure and repair
 *   resolveLine()         the recorded stock identity of an order line whose identity is gone
 *   recordPlacement()     the placement record of an order line (removed with the line
 *                         by the database)
 *   recordSaleRules()     an operation for a sale-rule edit, so cached verdicts rebuild
 *
 * Reservation floor: the reserved quantity of a stock record is a floor under that
 * record's on-hand for every command that lowers it: adjustments, stock editors,
 * transfers, tracking openings, write-offs, scraps, reversals and the unreserved
 * remainder of a fulfilment. Converting a line's own reservations is not judged by
 * it: on-hand and reserved fall together on the reservation's record. A fulfilment
 * converts every reservation it consumes first and then draws each line's
 * unreserved remainder only from what a record holds above the reservations still
 * standing on it, whatever the shop allows for negative physical stock; a shortfall
 * refuses the whole fulfilment.
 *
 * Order of claims: a record that holds less than its reservations (backorders)
 * serves them first reserved, first served, unless an administrator served a
 * source first ({@see ReservationQueue}, {@see self::prioritiseReservations()}).
 * The conversions of a fulfilment may not take the stock that backs a reservation
 * ahead of the ones they convert, whatever the shop allows for negative physical
 * stock; judged on what the fulfilment leaves on each record, a shortfall refuses
 * the whole fulfilment before its remainders are drawn. A line lowered while it
 * holds stock gives up its reservations from the back of that order
 * ({@see self::releaseLine()}), so it keeps the place it reserved first.
 *
 * Refusals are typed: InventoryIntegrityException (figures claim stock the records do
 * not hold, or the unit is held), InventoryUnitException (not a tracked current unit),
 * InventoryLineUnresolvedException (a line's identity is gone or, for reserve, not
 * current; nothing of the command moves), InventoryReservedFloorException (a
 * reduction below what open reservations hold on a record), InventoryTrackingException,
 * InventoryUnitRetirementException, InventoryProjectionMismatchException,
 * InventoryLineResolutionException and InventoryOpenLinesException.
 *
 * Idempotency: order-line commands offset against the line's cumulative movement
 * ledger first; re-applying any trigger is a no-op.
 *
 * Lock order: stock rows, location rows (shared), combination rows, the item row,
 * then the item's combination range (shared, item-level units only). Fulfilment,
 * restore and the order-line resolution take the order row first; the resolution
 * writes its row before any lock on the product. No command takes an order row once
 * it holds a stock row: a caller that runs a fulfilment or a restore after another
 * stock command in one transaction holds the order's row before the first of them.
 *
 * Among stock rows: a command that can take more than one record of a unit takes
 * every record the unit has first, each by its own key and in location order
 * ({@see self::lockUnitRecords()}), before it reads one of them for a decision,
 * writes one, or takes the unit's combination or item row: a reservation, a
 * fulfilment (before a line's reservations are converted or its remainder is
 * drawn), a transfer, a release, the expiry sweep, the override that serves a
 * source first, a restore, the reversal of a source's restores and the write-off
 * of restored stock. So two such commands on one unit queue on its first record:
 * neither holds one of the unit's records while the other holds another. A record created after the command named the
 * unit's records is locked when the command creates or reaches it. A structural
 * command (a tracking switch, a change of combination, location or archive
 * state, a retirement, the audited write-off and the repair) takes the item's
 * records, or those of the units or locations it names, in one locking read.
 *
 * Creating a record: a record is created in the gap of the unit index just
 * before the record that sorts after it, and a command waiting for that record
 * with a lock on its gap keeps the creation waiting. So a record is created in
 * the same order as records are taken, where the command takes its unit's
 * records ({@see self::takeUnitRecords()}), before it holds any record that sorts
 * after it: the records a restore's two legs, the reversal of a source's restores
 * and the write-off of restored stock will write where their unit has none, the
 * first records of a unit with none that a reservation over several units places
 * a line on, and the default-location record a fulfilment over several units
 * draws a line's remainder from on a unit with none. What the creation found (a
 * unit whose figures claim stock without records) is handed to the line that
 * reaches the record, which holds the unit there.
 *
 * Among units: a reservation, a fulfilment, a restore, the reversal of a
 * source's restores and the write-off of restored stock that act on more than
 * one unit, a release and the judgement of an order's units
 * ({@see self::lockedUnitTracking()}) take the stock records of every unit they
 * act on first, the units in ascending item and combination order, before the
 * combination or item row of any of them. Two variants of one product share its
 * item row, so a command that held that row while it still asked for the next
 * variant's records would wait for a command that holds those records and asks
 * for the row. Reservation rows come after the unit's records in every command
 * that locks them (a fulfilment, a release, the expiry sweep, the override that
 * serves a source first); the commands that judge a unit read them under a shared
 * lock, after the unit's records too, as a fulfilment and that override read the
 * order of claims of a record.
 *
 * Not one order: a command over several products takes each product's
 * combination and item rows where its walk reaches them, in the order of its
 * lines, so two commands that reach the same two products in opposite orders,
 * on different variants, can each hold one product's row; a release takes the
 * records of a reservation its plain read did not show when it reaches that
 * reservation's row; and a few records are still created after the command holds
 * records that sort after them: a record a reservation places a line on, or a
 * fulfilment draws from, at a location where a unit that has records has none;
 * a transfer's destination on its own unit; the opening records of a tracking
 * switch, after the item's records. Such a command and another one that holds
 * what it asks for while asking for what it holds can therefore still wait for
 * each other: the database then ends one, which fails whole and leaves nothing.
 *
 * @since  2.0.0
 */
final class InventoryService
{
    public const STATE_ACTIVE = 'active';
    public const STATE_FULFILLED = 'fulfilled';
    public const STATE_RELEASED = 'released';
    public const STATE_EXPIRED = 'expired';
    public const STATE_CANCELLED = 'cancelled';

    /**
     * Roles of an order line's acting stock identity ({@see self::lineIdentities()}):
     * a current unit; an identity kept only for order history (fulfilment,
     * cancellation and returns act on it, nothing new is claimed on it); or none
     * (the identity is gone and the line has no recorded resolution).
     *
     * @since  2.0.0
     */
    public const ROLE_CURRENT = 'current';
    public const ROLE_HISTORICAL = 'historical';
    public const ROLE_UNRESOLVED = 'unresolved';

    /**
     * Actions of a recorded order-line resolution ({@see self::resolveLine()}).
     *
     * @since  2.0.0
     */
    public const RESOLVE_HISTORICAL_PARENT = 'historical_parent';
    public const RESOLVE_BIND_UNIT = 'bind_unit';

    /**
     * The two source domains whose restores assert that goods PHYSICALLY
     * came back. They are not peers, and the asymmetry is the invariant:
     *
     *   SOURCE_RETURN   a return process took the goods in: ground truth.
     *   SOURCE_DOCUMENT a credit document says they came back: bookkeeping.
     *
     * A restore from any OTHER domain (notably 'order': a quantity edit, a
     * cancellation) is not physical-return evidence at all (those units
     * never left the building) and takes no part in this.
     *
     * @since  2.0.0
     */
    public const SOURCE_RETURN = 'return';
    public const SOURCE_DOCUMENT = 'document';

    public const MOVE_RECEIVE = 'receive';
    public const MOVE_DEDUCT = 'deduct';
    public const MOVE_RESTORE = 'restore';
    public const MOVE_ADJUST = 'adjust';
    public const MOVE_TRANSFER_OUT = 'transfer_out';
    public const MOVE_TRANSFER_IN = 'transfer_in';

    public const BACKORDER_DENY = 'deny';
    public const BACKORDER_ALLOW = 'allow';
    public const BACKORDER_ALLOW_NOTIFY = 'allow_notify';

    public const OVER_SHIPMENT_STRICT = 'strict';
    public const OVER_SHIPMENT_ALLOW_WARN = 'allow_warn';

    /** Float comparison tolerance for quantity math. */
    private const EPS = 0.000001;

    /**
     * Tolerance for "is this stored quantity non-zero" and for every comparison of a
     * stored figure with the stock records (FLOAT columns carry single-precision noise).
     */
    private const TOL = InventoryAvailabilityService::QUANTITY_TOLERANCE;

    /**
     * Units whose missing stock records (or hold) were already logged in this
     * request, so a refused unit writes one ERROR however many commands try it.
     *
     * @var array<string, true>
     */
    private static array $missingLedgerLogged = [];

    private \Joomla\Database\DatabaseInterface $db;

    /** @var int|null Cached default location id. */
    private ?int $defaultLocation = null;

    /** @var array|null Memoised allocation provider chain. */
    private ?array $allocationChain = null;

    /**
     * Resolved unit policies, keyed "itemId|combinationId".
     *
     * Request-scoped: a reserve() over a basket asks for the same unit's policy
     * repeatedly, and each miss is now two table reads rather than one.
     *
     * @var array<string, array{backorder: string, min_qty: float}>
     */
    private array $policyMemo = [];

    /**
     * Request memo of dedup keys KNOWN to carry no notification row, so a healthy shop
     * pays one indexed point read per unit per request instead of a write per operation.
     * Static because every call site constructs its own service instance. Each key holds
     * the connection's rollback count when it was learnt
     * ({@see TransactionState::rollbacks()}): a rollback since may have undone the clear
     * it remembers, so the key is read again (2.1.4).
     *
     * @var array<string, int>
     * @since  2.0.0
     */
    private static array $noticeAbsent = [];

    /**
     * @since  2.0.0
     */
    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');

        // The audit trail has to have somewhere to land
        // Every com_alfa.inventory line this class writes is dropped in
        // SILENCE unless the category is registered: Joomla matches
        // categories by exact in_array and addLogEntry() iterates an empty
        // logger list with no fallback and no error.
        //
        // AlfaLog lists the category and AlfaComponent::boot() registers it,
        // but only when com_alfa actually boots. Stock moves in requests that
        // never do: a com_ajax endpoint, a cron run, a system-plugin event, a
        // CLI job. Measured in exactly such a context: the supersession, the
        // document-side enforcement and the stock-effect-skipped lines all
        // executed and com_alfa_inventory.php did not even exist.
        //
        // Same defensive call the money services already make for the same
        // reason (see PaymentLedger::ensureLogger()). AlfaLog owns the map;
        // this only makes sure it has been applied. Idempotent, one array
        // write, and a category that never fires creates no file.
        AlfaLog::register();
    }

    //  Commands

    /**
     * Reserve salable quantity for a source's lines (order placement).
     *
     * Allocation happens HERE and only here: fulfilment later consumes
     * this allocation, it never recomputes it. Creates no movements:
     * reservations claim salable quantity, physical is untouched.
     *
     * Every reservation is created at priority 0, so on its stock record it is
     * served after every reservation made before it ({@see ReservationQueue});
     * only an administrator's audited override raises a priority
     * ({@see self::prioritiseReservations()}).
     *
     * Idempotent per line: a line that already carries a reservation for
     * this source is skipped. A line on an untracked unit is skipped too:
     * untracked units hold no reservations. So is an order line that was not
     * placed as a stock claim ({@see OpenLineClaims::placedTrackedSql()}): its
     * fulfilment deducts nothing, so a hold on it would be a claim the sale never
     * made. A line without an order line id is judged by its unit alone.
     *
     * Reserve is the one command that creates a claim, so every line with a
     * quantity must act on a CURRENT unit. A line whose identity no longer exists
     * (a vanished or foreign combination, a missing item) or is kept only for order
     * history (an archived combination, the bare item of a product with
     * combinations) refuses the WHOLE reserve before anything is written: it is
     * never skipped silently.
     *
     * The backorder policy of a line is judged on its unit's stock records as
     * locked (each record by its own key, never a range of the unit index), so
     * their figures are the latest committed ones and stay so until the command
     * ends, and the shipped allocation places the reservation from those same
     * locked records.
     *
     * A reserve whose lines act on more than one unit locks the stock records of
     * every line's unit before the first line is walked, the units in ascending
     * item and combination order, so no line holds its unit's combination or item
     * row while another unit's records are still to take: an order over two
     * variants of one product and a one-line order on the second of them queue on
     * that variant's records. A unit with no stock record at all gets its first
     * records there, where the first line reserving on it is placed
     * ({@see self::firstReservation()}).
     *
     * @param array $lines Arrays: item_id, combination_id,
     *                     quantity, line_id. line_id is always
     *                     an order line, whatever the source:
     *                     the per-line ledger identity that
     *                     recorded resolutions and placement
     *                     records are read by, in every command.
     * @param string $sourceType Creating domain ('order', 'manual', ...).
     * @param int $sourceId Source row id.
     * @param InventoryContext|null $context Audit context.
     * @param string|null $expiresAt SQL datetime; null = lifecycle-governed.
     * @param bool $additive True skips the per-line existence
     *                       check: reconciliation deltas add
     *                       quantity to an already-reserved line.
     *
     * @return array{reserved: int, skipped: int, operation_id: string}
     *
     * @throws RuntimeException When the backorder policy denies a line.
     * @throws InventoryLineUnresolvedException When a line's identity is gone or not a current unit.
     * @throws InventoryIntegrityException When a unit's figures claim stock its records do not hold.
     * @throws InventoryUnitException When a unit stopped being tracked while waiting for its lock.
     *
     * @since  2.0.0
     */
    public function reserve(
        array $lines,
        string $sourceType,
        int $sourceId,
        ?InventoryContext $context = null,
        ?string $expiresAt = null,
        bool $additive = false,
    ): array {
        $context ??= new InventoryContext();

        // BEFORE event: packages may amend the lines or veto by throwing;
        // pre-transaction, a veto touches nothing.
        $before = new InventoryBeforeReserveEvent('onInventoryBeforeReserve', [
            'lines' => $lines,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'context' => $context,
        ]);
        $this->dispatch($before);
        $lines = $before->getLines();

        $operationId = $this->newOperationId();
        $reserved = 0;
        $skipped = 0;

        $this->db->transactionStart(true);

        try {
            // Every line must act on a current unit before anything is written.
            $identities = $this->preflightLines(
                command: 'reserve',
                lines: $lines,
                plan: static fn (array $line, array $unit): float => max(0.0, (float) ($line['quantity'] ?? 0)),
                historical: false,
            );
            $claims = $this->lineTracking(lines: $lines, identities: $identities);

            // A reserve over several units takes the stock records of every line's
            // unit first, in ascending unit order, before any line takes its unit's
            // combination or item row: two variants of one product share its item
            // row. Over one unit there is no order to keep: the line that reaches
            // it takes its records where its policy is judged.
            //
            // A unit with no record at all gets its first records there too, where
            // the first line reserving on it will place its reservation: that line's
            // allocation is asked for then, and the line is placed by that very
            // answer. A unit whose figures claim stock without records is left to
            // its line's policy, which refuses it.
            $unitsFirst = [];
            $takenUnits = [];
            $created = [];
            $allocations = [];

            foreach ($lines as $key => $line) {
                if ((float) ($line['quantity'] ?? 0) <= self::EPS || !$claims[$key]['tracked']) {
                    continue;
                }

                $unitsFirst[$identities[$key]['item'] . ':' . $identities[$key]['combination']] = $identities[$key];
            }

            if (\count(value: $unitsFirst) > 1) {
                uasort(
                    array: $unitsFirst,
                    callback: static fn (array $one, array $other): int => [$one['item'], $one['combination']] <=> [$other['item'], $other['combination']],
                );

                foreach ($unitsFirst as $identity) {
                    $unitFirst = $this->resolveUnit(itemId: $identity['item'], combinationId: $identity['combination']);

                    if ($unitFirst === null) {
                        continue;
                    }

                    $first = $this->firstReservation(
                        unit: $unitFirst,
                        lines: $lines,
                        identities: $identities,
                        claims: $claims,
                        sourceType: $sourceType,
                        sourceId: $sourceId,
                        additive: $additive,
                    );

                    if ($first !== null) {
                        $allocations[$first['key']] = $first['allocation'];
                    }

                    $this->takeUnitRecords(
                        unit: $unitFirst,
                        taken: $takenUnits,
                        create: $first === null ? [] : array_fill_keys(array_keys($first['allocation']), $first['line_id']),
                        created: $created,
                        refuseLedgerDefect: true,
                        command: 'reserve',
                    );
                }
            }

            foreach ($lines as $key => $line) {
                $quantity = (float) ($line['quantity'] ?? 0);
                $lineId = isset($line['line_id']) ? (int) $line['line_id'] : null;

                if ($quantity <= self::EPS) {
                    $skipped++;
                    continue;
                }

                // The pre-flight proved the acting identity is a current unit, so a
                // null here is an untracked unit: it sells without a ceiling and
                // holds nothing. An order line placed as no stock claim holds
                // nothing either, whatever its unit tracks now.
                $unit = $this->resolveUnit(itemId: $identities[$key]['item'], combinationId: $identities[$key]['combination']);

                if ($unit === null || !$claims[$key]['tracked']) {
                    $skipped++;
                    continue;
                }

                if (!$additive && $this->reservationExists(sourceType: $sourceType, sourceId: $sourceId, lineId: $lineId, itemId: $unit['item'], combinationId: $unit['combination'])) {
                    $skipped++;
                    continue;
                }

                try {
                    // Policy first, ONCE for the whole line: whether the shop will
                    // sell this is a question about the unit, not about a shelf.
                    // Which shelves it leaves from is the allocator's problem, and
                    // splitting a legal quantity must never make it illegal.
                    $this->guardUnitBackorder(unit: $unit, requested: $quantity);

                    // A line whose unit got its first records where the units were
                    // taken is placed by the allocation they were made for.
                    foreach ($allocations[$key] ?? $this->allocate(unit: $unit, quantity: $quantity) as $locationId => $locationQty) {
                        $stock = $this->lockStockRow(
                            itemId: $unit['item'],
                            combinationId: $unit['combination'],
                            locationId: $locationId,
                            refuseLedgerDefect: true,
                            historical: false,
                            command: 'reserve',
                            lineId: $lineId,
                            created: $created,
                        );

                        // Tracking read under the unit's locks: a switch-off that
                        // committed while this waited leaves nothing to reserve.
                        if (!$stock->unit_tracked) {
                            throw new InventoryUnitException(
                                itemId: $unit['item'],
                                combinationId: $unit['combination'],
                                reason: InventoryUnitException::NOT_TRACKED,
                            );
                        }

                        $row = (object) [
                            'item_id' => $unit['item'],
                            'combination_id' => $unit['combination'],
                            'location_id' => $locationId,
                            'quantity' => $locationQty,
                            'state' => self::STATE_ACTIVE,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'source_line_id' => $lineId,
                            'expires_at' => $expiresAt,
                            'priority' => 0,
                            'operation_id' => $operationId,
                            'correlation_id' => $context->correlationId,
                            'note' => $context->note,
                            'created' => Factory::getDate('now', 'UTC')->toSql(),
                            'created_by' => $context->userId,
                        ];
                        $this->db->insertObject('#__alfa_inventory_reservations', $row, 'id');

                        $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: 0, reservedDelta: $locationQty);
                    }
                } catch (InventoryUnitException $e) {
                    throw $this->lineFailure(command: 'reserve', line: $line, quantity: $quantity, failure: $e);
                }

                $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
                $reserved++;
            }

            $this->recordOperation(operationId: $operationId, command: 'reserve', sourceType: $sourceType, sourceId: $sourceId, context: $context);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $result = ['reserved' => $reserved, 'skipped' => $skipped, 'operation_id' => $operationId];

        // AFTER event: post-commit, side-effects only.
        $this->announce(label: 'onInventoryReserved', event: new InventoryReservedEvent('onInventoryReserved', [
            'lines' => $lines,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * For a unit with no stock record at all, the first line of a reserve that will
     * reserve on it, and where: its allocation, asked for once, for the reserve to
     * create the unit's first records where it takes the units' records and to
     * place the line by that same answer. The line is the first, in the command's
     * order, that reserve() does not skip (a quantity, a stock claim, no
     * reservation of its own yet unless the reserve adds to one).
     *
     * None for a unit that has records, for a unit whose figures claim stock
     * without records or that is held (its line's policy refuses it, and nothing is
     * created for it), and when an allocation provider refuses the line (its line
     * asks again, after its policy, and is refused there).
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param array $lines The reserve's lines.
     * @param array $identities Their acting identities.
     * @param array $claims Their tracking ({@see self::lineTracking()}).
     * @param string $sourceType The reserving domain.
     * @param int $sourceId Its source row.
     * @param bool $additive Whether the reserve adds to existing reservations.
     *
     * @return array{key: int|string, line_id: ?int, allocation: array<int, float>}|null
     *
     * @since  2.0.0
     */
    private function firstReservation(
        array $unit,
        array $lines,
        array $identities,
        array $claims,
        string $sourceType,
        int $sourceId,
        bool $additive,
    ): ?array {
        if ($this->unitRecordNames(unit: $unit) !== [] || $this->ledgerDefect(unit: $unit) !== null) {
            return null;
        }

        foreach ($lines as $key => $line) {
            $quantity = (float) ($line['quantity'] ?? 0);
            $lineId = isset($line['line_id']) ? (int) $line['line_id'] : null;

            if ($quantity <= self::EPS
                || !$claims[$key]['tracked']
                || (int) $identities[$key]['item'] !== (int) $unit['item']
                || (int) $identities[$key]['combination'] !== (int) $unit['combination']
                || (!$additive && $this->reservationExists(sourceType: $sourceType, sourceId: $sourceId, lineId: $lineId, itemId: $unit['item'], combinationId: $unit['combination']))
            ) {
                continue;
            }

            try {
                return ['key' => $key, 'line_id' => $lineId, 'allocation' => $this->allocate(unit: $unit, quantity: $quantity)];
            } catch (AllocationRefusedException) {
                return null;
            }
        }

        return null;
    }

    /**
     * Release the unfulfilled remainder of a source's reservations
     * (order cancellation / lifecycle end / expiry / source void).
     *
     * Only the order lifecycle and time release reservations; partial
     * fulfilment never does. Naturally idempotent: released rows hold no
     * remainder.
     *
     * The stock records of every unit the source's open reservations stand on are
     * taken first, the units in ascending order and each unit's records in location
     * order ({@see self::takeUnitRecords()}), and only then the reservation rows:
     * the order in which a fulfilment takes them, so a release and a fulfilment of
     * the same line queue on the unit's first record. The units are named by a
     * plain read; a reservation that read does not show has its unit's records
     * taken when its row is reached.
     *
     * @param string $sourceType Reservation source domain.
     * @param int $sourceId Source row id.
     * @param string $toState released | cancelled | expired.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{released_quantity: float, reservations: int, operation_id: string}
     *
     * @since  2.0.0
     */
    public function release(
        string $sourceType,
        int $sourceId,
        string $toState = self::STATE_RELEASED,
        ?InventoryContext $context = null,
    ): array {
        if (!\in_array($toState, [self::STATE_RELEASED, self::STATE_CANCELLED, self::STATE_EXPIRED], true)) {
            throw new RuntimeException("InventoryService: '{$toState}' is not a release state.");
        }

        $context ??= new InventoryContext();

        $this->dispatch(new InventoryBeforeReleaseEvent('onInventoryBeforeRelease', [
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'toState' => $toState,
        ]));

        $operationId = $this->newOperationId();
        $totalReleased = 0.0;
        $count = 0;

        $this->db->transactionStart(true);

        try {
            // The units whose stock records the command has taken.
            $takenUnits = [];

            // Every record of every unit the source's open reservations stand on,
            // before the reservation rows: the order a fulfilment takes them in.
            foreach ($this->reservedUnitsOf(sourceType: $sourceType, sourceId: $sourceId, lineId: null) as $reservedUnit) {
                $this->takeUnitRecords(unit: $reservedUnit, taken: $takenUnits);
            }

            foreach ($this->lockActiveReservations(sourceType: $sourceType, sourceId: $sourceId) as $reservation) {
                $remainder = $this->remainderOf($reservation);

                // Taken above. A reservation the plain read did not show has every
                // record of its unit taken here, in location order, before the one
                // it holds: a source's reservations of one unit can stand at
                // several locations.
                $unit = ['item' => (int) $reservation->item_id, 'combination' => (int) $reservation->combination_id];
                $this->takeUnitRecords(unit: $unit, taken: $takenUnits);

                // Releasing reduces an existing claim: it never creates a stock
                // record and never refuses (see releaseReservedOn()).
                $stock = $this->lockExistingStockRow(
                    itemId: (int) $reservation->item_id,
                    combinationId: (int) $reservation->combination_id,
                    locationId: (int) $reservation->location_id,
                );

                $this->transitionReservation(
                    reservation: $reservation,
                    releasedDelta: $remainder,
                    state: $toState,
                );

                $this->releaseReservedOn(stock: $stock, reservation: $reservation, quantity: $remainder);

                $this->evaluateUnitNotices(itemId: (int) $reservation->item_id, combinationId: (int) $reservation->combination_id);

                $totalReleased += $remainder;
                $count++;
            }

            $this->recordOperation(operationId: $operationId, command: 'release', sourceType: $sourceType, sourceId: $sourceId, context: $context);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $result = ['released_quantity' => $totalReleased, 'reservations' => $count, 'operation_id' => $operationId];

        $this->announce(label: 'onInventoryReleased', event: new InventoryReleasedEvent('onInventoryReleased', [
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'toState' => $toState,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Expire every due TTL reservation (the scheduler worker's engine).
     *
     * Candidates are picked without locks, then EACH row is settled in
     * its own transaction: lock the stock records of the reservation's unit,
     * in location order, and then the reservation (the order in which a
     * fulfilment and a release take them),
     * RE-CHECK the state and deadline under the lock (a concurrent
     * conversion may have won the race; the loser no-ops), release the
     * remainder, state -> EXPIRED. Core order reservations carry no
     * expiry (NULL) and are never touched here.
     *
     * A reservation that cannot be expired is logged and the sweep goes on to the
     * next. Run inside a caller's transaction, each row is a savepoint of it, and a
     * failure that ended that transaction (a deadlock) is logged and thrown:
     * otherwise the rows after it would be settled, and the caller would carry on,
     * outside the transaction the caller believes it holds.
     *
     * @param int $limit Batch cap per run.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{expired: int, released_quantity: float}
     *
     * @throws Throwable The failure of a row, when it ended the transaction the sweep was called in.
     *
     * @since  2.0.0
     */
    public function expireDueReservations(int $limit = 200, ?InventoryContext $context = null): array
    {
        $context ??= new InventoryContext();
        $now = Factory::getDate('now', 'UTC')->toSql();

        $query = $this->db->getQuery(true)
            ->select(columns: $this->db->quoteName(name: ['id', 'item_id', 'combination_id']))
            ->from($this->db->quoteName('#__alfa_inventory_reservations'))
            ->where($this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE))
            ->where($this->db->quoteName('expires_at') . ' IS NOT NULL')
            ->where($this->db->quoteName('expires_at') . ' <= ' . $this->db->quote($now))
            ->order($this->db->quoteName('id'));
        $candidates = $this->db->setQuery(query: $query, offset: 0, limit: $limit)->loadObjectList() ?: [];

        $expired = 0;
        $totalReleased = 0.0;

        // A row's failure can end only a transaction the sweep was called inside.
        $nested = TransactionState::isOpen(db: $this->db);

        foreach ($candidates as $candidate) {
            $id = (int) $candidate->id;
            $this->db->transactionStart(true);

            try {
                // Every record the reservation's unit has, in location order, before
                // the reservation row: the order a fulfilment of its line takes them in.
                $unit = ['item' => (int) $candidate->item_id, 'combination' => (int) $candidate->combination_id];
                $this->lockUnitRecords(unit: $unit);

                $query = $this->db->getQuery(true)
                    ->select('*')
                    ->from($this->db->quoteName('#__alfa_inventory_reservations'))
                    ->where($this->db->quoteName('id') . ' = ' . $id)
                    ->where($this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE))
                    ->where($this->db->quoteName('expires_at') . ' <= ' . $this->db->quote($now));
                $reservation = $this->db->setQuery((string) $query . ' FOR UPDATE')->loadObject();

                // Race loser: someone converted/released it meanwhile.
                if ($reservation === null) {
                    TransactionState::commitLevel(db: $this->db);
                    continue;
                }

                $stock = $this->lockExistingStockRow(
                    itemId: (int) $reservation->item_id,
                    combinationId: (int) $reservation->combination_id,
                    locationId: (int) $reservation->location_id,
                );

                $remainder = $this->remainderOf($reservation);
                $this->transitionReservation(reservation: $reservation, releasedDelta: $remainder, state: self::STATE_EXPIRED);
                $this->releaseReservedOn(stock: $stock, reservation: $reservation, quantity: $remainder);

                $this->evaluateUnitNotices(itemId: (int) $reservation->item_id, combinationId: (int) $reservation->combination_id);
                TransactionState::commitLevel(db: $this->db);

                $expired++;
                $totalReleased += $remainder;
            } catch (Throwable $e) {
                $transactionEnded = $this->rollBackCommand(failure: $e);
                Log::add(
                    entry:    "Inventory expiry failed for reservation #{$id}: " . $e->getMessage(),
                    priority: Log::ERROR,
                    category: 'com_alfa.inventory',
                );

                // The row's failure ended the transaction the sweep was called
                // in: its caller is told, not handed a result.
                if ($nested && $transactionEnded) {
                    throw $e;
                }
            }
        }

        $result = ['expired' => $expired, 'released_quantity' => $totalReleased];

        if ($expired > 0) {
            $this->recordOperation(operationId: $this->newOperationId(), command: 'expire', sourceType: 'system', sourceId: 0, context: $context);
            $this->announce(label: 'onInventoryReleased', event: new InventoryReleasedEvent('onInventoryReleased', [
                'sourceType' => '',
                'sourceId' => 0,
                'toState' => self::STATE_EXPIRED,
                'result' => $result,
            ]));
        }

        return $result;
    }

    /**
     * Release up to a quantity from ONE line's active reservations:
     * the reconciliation counterpart of an additive reserve (order line
     * shrank while the order still holds inventory).
     *
     * As in {@see self::release()}, the stock records of every unit the line's open
     * reservations stand on are taken before the reservation rows.
     *
     * A release capped at a quantity lets go of the line's reservations from the
     * back of the order of claims ({@see ReservationQueue}): the lowest priority
     * first, then the youngest, then the highest id. A line raised and lowered
     * again therefore gives up the quantity it added, and keeps the place it
     * reserved first, or the one an administrator served first. Without a cap
     * every reservation of the line goes, in id order.
     *
     * @param string $sourceType Reservation source domain.
     * @param int $sourceId Source row id.
     * @param int $lineId The line anchor.
     * @param float|null $quantity Cap; null = full remainder.
     * @param string $toState released | cancelled | expired.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{released_quantity: float, operation_id: string}
     *
     * @since  2.0.0
     */
    public function releaseLine(
        string $sourceType,
        int $sourceId,
        int $lineId,
        ?float $quantity = null,
        string $toState = self::STATE_RELEASED,
        ?InventoryContext $context = null,
        ?int $onlyItemId = null,
        ?int $onlyCombinationId = null,
    ): array {
        if (!\in_array($toState, [self::STATE_RELEASED, self::STATE_CANCELLED, self::STATE_EXPIRED], true)) {
            throw new RuntimeException("InventoryService: '{$toState}' is not a release state.");
        }

        $operationId = $this->newOperationId();
        $remaining = $quantity;
        $totalReleased = 0.0;

        $this->db->transactionStart(true);

        try {
            // The units whose stock records the command has taken.
            $takenUnits = [];

            // Every record of every unit the line's open reservations stand on,
            // before the reservation rows: the order a fulfilment takes them in.
            foreach ($this->reservedUnitsOf(sourceType: $sourceType, sourceId: $sourceId, lineId: $lineId) as $reservedUnit) {
                $this->takeUnitRecords(unit: $reservedUnit, taken: $takenUnits);
            }

            $reservations = $this->lockActiveReservations(sourceType: $sourceType, sourceId: $sourceId);

            // A release capped at a quantity lets go from the back of the order of
            // claims: the reservation served last goes first, so the line keeps the
            // place it reserved first, or the one an administrator served first.
            if ($quantity !== null) {
                $reservations = array_reverse(array: ReservationQueue::sorted(rows: $reservations));
            }

            foreach ($reservations as $reservation) {
                if ((int) ($reservation->source_line_id ?? 0) !== $lineId) {
                    continue;
                }

                // A caller letting go of ONE unit's hold must not have it taken
                // off another: a line that moved unit holds on both for a
                // moment, and releasing "the line's" hold would otherwise give
                // back whichever row happened to be locked first.
                if ($onlyItemId !== null && (int) $reservation->item_id !== $onlyItemId) {
                    continue;
                }

                if ($onlyCombinationId !== null && (int) $reservation->combination_id !== $onlyCombinationId) {
                    continue;
                }

                $give = $this->remainderOf($reservation);

                if ($remaining !== null) {
                    $give = min($give, $remaining);
                }

                if ($give <= self::EPS) {
                    continue;
                }

                // Taken above. A reservation the plain read did not show has every
                // record of its unit taken here, in location order, before the one
                // it holds.
                $unit = ['item' => (int) $reservation->item_id, 'combination' => (int) $reservation->combination_id];
                $this->takeUnitRecords(unit: $unit, taken: $takenUnits);

                $stock = $this->lockExistingStockRow(
                    itemId: (int) $reservation->item_id,
                    combinationId: (int) $reservation->combination_id,
                    locationId: (int) $reservation->location_id,
                );

                $fullRelease = $give >= $this->remainderOf($reservation) - self::EPS;
                $this->transitionReservation(
                    reservation: $reservation,
                    releasedDelta: $give,
                    state: $fullRelease ? $toState : self::STATE_ACTIVE,
                );
                $this->releaseReservedOn(stock: $stock, reservation: $reservation, quantity: $give);
                $this->evaluateUnitNotices(itemId: (int) $reservation->item_id, combinationId: (int) $reservation->combination_id);

                $totalReleased += $give;

                if ($remaining !== null) {
                    $remaining -= $give;

                    if ($remaining <= self::EPS) {
                        break;
                    }
                }
            }

            $this->recordOperation(operationId: $operationId, command: 'release', sourceType: $sourceType, sourceId: $sourceId, context: $context ?? new InventoryContext());
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        return ['released_quantity' => $totalReleased, 'operation_id' => $operationId];
    }

    /**
     * Serve a source's active reservations first: the audited override of the order
     * in which a stock record serves its reservations ({@see ReservationQueue}).
     *
     * A record serves its reservations first reserved, first served. An
     * administrator may explicitly serve a later order first: its active
     * reservations (or those of one of its order lines) are given a priority above
     * every other active reservation on the same stock records, so from the moment
     * this commits they come first and the stock on hand backs them first. A
     * reservation that loses its backing to them waits for stock from that same
     * moment. Nothing moves stock here: the shipment that follows is an ordinary
     * fulfilment, judged by the order of claims like any other.
     *
     * Refused, with nothing written: no signed-in operator; a reason that is not 1
     * to 255 characters; a source (or line) without an active reservation. When
     * each of its reservations already comes before every other active reservation
     * on its record, nothing is written and no operation is recorded, so a repeat
     * changes nothing.
     *
     * The records of every unit the source's active reservations stand on are taken
     * first, the units ascending and each unit's records in location order, then the
     * source's reservation rows, then each record's other reservations under a
     * shared lock: the order in which a release and a fulfilment take them. One
     * 'prioritise' operation by the operator; the reason is written as the note of
     * every reservation served first and logged with the operator and the operation.
     *
     * @param string $sourceType The reservations' source domain ('order').
     * @param int $sourceId The source row.
     * @param int|null $lineId One order line of the source, or null for every line.
     * @param string $reason Why this source is served first.
     * @param InventoryContext $context The operator.
     *
     * @return array{operation_id: ?string, reservations: int, records: int, ahead_of: list<array{source_type: string, source_id: int}>}
     *                                                                                                                                   The operation (null when nothing changed), the reservations served first, the stock records they
     *                                                                                                                                   stand on, and the sources whose reservations they now come before.
     *
     * @throws RuntimeException Without an operator, a reason or an active reservation to serve first.
     *
     * @since  2.0.0
     */
    public function prioritiseReservations(string $sourceType, int $sourceId, ?int $lineId, string $reason, InventoryContext $context): array
    {
        $reason = trim(string: $reason);

        if ($reason === '' || mb_strlen(string: $reason) > 255) {
            throw new RuntimeException(message: 'InventoryService: serving reservations first needs a reason of 1 to 255 characters.');
        }

        if ($context->userId <= 0) {
            throw new RuntimeException(message: 'InventoryService: serving reservations first needs a signed-in operator.');
        }

        $operationId = $this->newOperationId();
        $own = [];
        $records = [];
        $aheadOf = [];
        $changed = false;

        $this->db->transactionStart(asSavepoint: true);

        try {
            // The units whose stock records the command has taken.
            $takenUnits = [];

            // Every record of every unit the source's open reservations stand on,
            // before the reservation rows: the order a fulfilment takes them in.
            foreach ($this->reservedUnitsOf(sourceType: $sourceType, sourceId: $sourceId, lineId: $lineId) as $reservedUnit) {
                $this->takeUnitRecords(unit: $reservedUnit, taken: $takenUnits);
            }

            foreach ($this->lockActiveReservations(sourceType: $sourceType, sourceId: $sourceId) as $reservation) {
                if ($lineId !== null && (int) ($reservation->source_line_id ?? 0) !== $lineId) {
                    continue;
                }

                if ($this->remainderOf(reservation: $reservation) <= self::EPS) {
                    continue;
                }

                // Taken above; a reservation the plain read did not show has its
                // unit's records taken here.
                $unit = ['item' => (int) $reservation->item_id, 'combination' => (int) $reservation->combination_id];
                $this->takeUnitRecords(unit: $unit, taken: $takenUnits);

                $own[(int) $reservation->id] = $reservation;
                $records[$unit['item'] . ':' . $unit['combination'] . ':' . (int) $reservation->location_id] = $unit + ['location' => (int) $reservation->location_id];
            }

            if ($own === []) {
                throw new RuntimeException(message: sprintf(
                    'InventoryService: %s #%d has no open reservation%s to serve first.',
                    $sourceType,
                    $sourceId,
                    $lineId !== null ? ' on line #' . $lineId : '',
                ));
            }

            // Each record's queue as it stands: the highest priority any other
            // reservation holds there, and the other reservations that still come
            // before one of the source's.
            $highest = 0;

            foreach ($records as $record) {
                $before = [];

                foreach ($this->claimQueue(unit: $record, locationId: $record['location'], lock: true) as $row) {
                    if (isset($own[(int) $row->id])) {
                        foreach ($before as $source => $ahead) {
                            $aheadOf[$source] ??= $ahead;
                        }

                        $before = [];
                        continue;
                    }

                    $highest = max($highest, (int) $row->priority);

                    if ($this->remainderOf(reservation: $row) > self::EPS) {
                        $before[(string) $row->source_type . ':' . (int) $row->source_id] = [
                            'source_type' => (string) $row->source_type,
                            'source_id' => (int) $row->source_id,
                        ];
                    }
                }
            }

            $changed = $aheadOf !== [];

            if ($changed) {
                $this->db->setQuery(
                    query: 'UPDATE ' . $this->db->quoteName(name: '#__alfa_inventory_reservations')
                    . ' SET ' . $this->db->quoteName(name: 'priority') . ' = ' . ($highest + 1)
                    . ', ' . $this->db->quoteName(name: 'note') . ' = ' . $this->db->quote(text: $reason)
                    . ', ' . $this->db->quoteName(name: 'modified') . ' = ' . $this->db->quote(text: Factory::getDate(time: 'now', tzOffset: 'UTC')->toSql())
                    . ' WHERE ' . $this->db->quoteName(name: 'id') . ' IN (' . implode(separator: ',', array: array_keys(array: $own)) . ')'
                    . ' AND ' . $this->db->quoteName(name: 'state') . ' = ' . $this->db->quote(text: self::STATE_ACTIVE),
                )->execute();

                $this->recordOperation(operationId: $operationId, command: 'prioritise', sourceType: $sourceType, sourceId: $sourceId, context: $context);
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        if (!$changed) {
            return ['operation_id' => null, 'reservations' => \count(value: $own), 'records' => \count(value: $records), 'ahead_of' => []];
        }

        $this->logOnceStands(
            label: 'inventory line: served first',
            entry: sprintf(
                'Inventory: %s #%d%s served first by user #%d (operation %s): %d reservations on %d stock records now come before %s. Reason: %s',
                $sourceType,
                $sourceId,
                $lineId !== null ? ' line #' . $lineId : '',
                $context->userId,
                $operationId,
                \count(value: $own),
                \count(value: $records),
                implode(separator: ', ', array: array_map(
                    callback: static fn (array $source): string => $source['source_type'] . ' #' . $source['source_id'],
                    array: $aheadOf,
                )),
                $reason,
            ),
            priority: Log::INFO,
        );

        return ['operation_id' => $operationId, 'reservations' => \count(value: $own), 'records' => \count(value: $records), 'ahead_of' => array_values(array: $aheadOf)];
    }

    /**
     * Read a source's per-line inventory state: the tracked unit, the
     * active reserved remainder and the net deducted quantity of every
     * line the source ever touched (reservations OR ledger). The
     * reconciliation food of order-side callers: nobody sums rows
     * elsewhere.
     *
     * @param string $sourceType Source domain.
     * @param int $sourceId Source row id.
     * @param int[] $lineIds Lines the caller knows it owns, for ledger
     *                       rows another domain wrote against them. A
     *                       caller that holds its own line list (an
     *                       order does) should always pass it: without
     *                       it only lines carrying a reservation are
     *                       discoverable.
     *
     * @return array<int, array{item_id: int, combination_id: int, reserved_remainder: float, deducted_net: float, foreign_deducted_net: float}>
     *                                                                                                                                           Keyed by line id.
     *
     * @since  2.0.0
     */
    /**
     * What ANOTHER source physically has out on these lines, per inventory unit.
     *
     * The identity-scoped twin of {@see self::sourceLineState()}'s per-LINE
     * `foreign_deducted_net`. That figure sums every unit a line has touched,
     * which is the honest answer to "what else is out on this line" and the
     * WRONG operand for sizing a claim: after a variant change a document's
     * deduction on the OLD unit would satisfy the claim for the new one, and
     * the line would under-claim the goods it actually sells.
     *
     * Deliberately NOT a leg of lineUnitNets(): that map is source-scoped by
     * contract, and the stale-unit healing step depends on it being
     * structurally unable to see another domain's work.
     *
     * @param string $sourceType The source domain to EXCLUDE ('order', ...).
     * @param int $sourceId The source row id to exclude.
     * @param int[] $lineIds The lines to read.
     * @param bool $current Read the latest committed state.
     *
     * @return array<int, array<string, float>> [lineId]['item:combination'] => net out.
     *
     * @since  2.0.0
     */
    public function foreignUnitNets(string $sourceType, int $sourceId, array $lineIds = [], bool $current = false): array
    {
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds))));

        if ($lineIds === []) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']))
            ->select('COALESCE(-SUM(qty_change), 0) AS net')
            ->from($this->db->quoteName('#__alfa_inventory_movements'))
            ->where($this->db->quoteName('source_line_id') . ' IN (' . implode(',', $lineIds) . ')')
            ->where('NOT (' . $this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType)
                . ' AND ' . $this->db->quoteName('source_id') . ' = ' . $sourceId . ')')
            ->where($this->db->quoteName('movement_type') . ' IN ('
                . $this->db->quote(self::MOVE_DEDUCT) . ', ' . $this->db->quote(self::MOVE_RESTORE) . ')')
            ->group($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']));

        // This read DRIVES a write, so it takes the same lock its siblings do.
        $sql = $current ? ((string) $query . ' LOCK IN SHARE MODE') : $query;
        $out = [];

        foreach ($this->db->setQuery($sql)->loadObjectList() ?: [] as $row) {
            $key = (int) $row->item_id . ':' . (int) $row->combination_id;
            $out[(int) $row->source_line_id][$key] = (float) $row->net;
        }

        return $out;
    }

    /**
     * WHAT EACH LINE HAS CLAIMED, PER UNIT.
     *
     * sourceLineState() answers per LINE: it collapses every unit a line has
     * ever touched into one entry and sums `deducted_net` across them. That is
     * the right shape for converging a quantity, and the wrong shape for
     * noticing that a line's claim is sitting on a unit the line no longer
     * sells: the sum looks correct while both facts are wrong.
     *
     * This keeps the unit. The caller can then ask the only question that
     * matters when a line is re-configured: is anything still claimed
     * somewhere this line does not sell from any more?
     *
     * @param string $sourceType Owning domain.
     * @param int $sourceId Owning row.
     * @param array $lineIds Line ids to report on.
     * @param bool $current Read the latest committed state (see
     *                      sourceLineState() for why a converger must).
     *
     * @return array<int, array<string, array{item_id: int, combination_id: int, deducted_net: float, reserved_remainder: float}>>
     *                                                                                                                             line id => "item:combination" => the unit, what it still has out,
     *                                                                                                                             and what it still holds
     *
     * @since  2.0.0
     */
    public function lineUnitNets(string $sourceType, int $sourceId, array $lineIds = [], bool $current = false): array
    {
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds))));

        if ($lineIds === []) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']))
            ->select('COALESCE(-SUM(qty_change), 0) AS net')
            ->from($this->db->quoteName('#__alfa_inventory_movements'))
            ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
            ->where($this->db->quoteName('source_id') . ' = ' . $sourceId)
            ->where($this->db->quoteName('source_line_id') . ' IN (' . implode(',', $lineIds) . ')')
            ->where($this->db->quoteName('movement_type') . ' IN ('
                . $this->db->quote(self::MOVE_DEDUCT) . ', ' . $this->db->quote(self::MOVE_RESTORE) . ')')
            ->group($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']));

        $sql = $current ? ((string) $query . ' LOCK IN SHARE MODE') : $query;
        $out = [];

        $blank = static fn (int $item, int $combination): array => [
            'item_id' => $item,
            'combination_id' => $combination,
            'deducted_net' => 0.0,
            'reserved_remainder' => 0.0,
        ];

        foreach ($this->db->setQuery($sql)->loadObjectList() ?: [] as $row) {
            $key = (int) $row->item_id . ':' . (int) $row->combination_id;
            $out[(int) $row->source_line_id][$key] ??= $blank((int) $row->item_id, (int) $row->combination_id);
            $out[(int) $row->source_line_id][$key]['deducted_net'] = (float) $row->net;
        }

        // A HOLD IS A CLAIM TOO. A line that moved unit while its order only
        // reserves leaves the hold on the unit it left: the same defect as
        // the deduction, on the other axis, and invisible to any reader that
        // sums remainders across units.
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']))
            ->select('COALESCE(SUM(' . $this->db->quoteName('quantity') . ' - '
                . $this->db->quoteName('fulfilled_quantity') . ' - '
                . $this->db->quoteName('released_quantity') . '), 0) AS remainder')
            ->from($this->db->quoteName('#__alfa_inventory_reservations'))
            ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
            ->where($this->db->quoteName('source_id') . ' = ' . $sourceId)
            ->where($this->db->quoteName('source_line_id') . ' IN (' . implode(',', $lineIds) . ')')
            ->where($this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE))
            ->group($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']));

        $sql = $current ? ((string) $query . ' LOCK IN SHARE MODE') : $query;

        foreach ($this->db->setQuery($sql)->loadObjectList() ?: [] as $row) {
            $key = (int) $row->item_id . ':' . (int) $row->combination_id;
            $out[(int) $row->source_line_id][$key] ??= $blank((int) $row->item_id, (int) $row->combination_id);
            $out[(int) $row->source_line_id][$key]['reserved_remainder'] = (float) $row->remainder;
        }

        return $out;
    }

    /**
     * Per-line state of one source: what it still holds, and what is out.
     *
     * `deducted_net` is the net of DEDUCT and RESTORE movements; whose
     * movements count is decided by $intent, not by the shape of the query.
     * An ordinary order edit owns only what this source wrote, so an issued
     * document's deduction is not something the edit compensates for; a
     * lifecycle restore owns every movement anchored on the line, because it
     * is the sanctioned path that brings those units back.
     *
     * $lineIds lets a caller name lines it knows about even when no
     * reservation row points at them: a delivery note may deduct a line that
     * was never reserved, and without this the units would be invisible to
     * every axis.
     *
     * @param string $sourceType The source domain ('order', ...).
     * @param int $sourceId The source row id.
     * @param ReconciliationIntent $intent Whose movements this read owns.
     * @param int[] $lineIds Lines the caller knows are its own.
     * @param bool $current Read the latest committed state
     *                      (a caller about to WRITE a
     *                      convergence must pass true).
     *
     * @return array<int, array{item_id: int, combination_id: int, reserved_remainder: float, deducted_net: float}>
     *
     * @since  2.0.0
     */
    public function sourceLineState(
        string $sourceType,
        int $sourceId,
        ReconciliationIntent $intent,
        array $lineIds = [],
        bool $current = false,
    ): array {
        $state = [];

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['source_line_id', 'item_id', 'combination_id', 'state']))
            ->select($this->db->quoteName('quantity') . ' AS q, ' . $this->db->quoteName('fulfilled_quantity') . ' AS fq, ' . $this->db->quoteName('released_quantity') . ' AS rq')
            ->from($this->db->quoteName('#__alfa_inventory_reservations'))
            ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
            ->where($this->db->quoteName('source_id') . ' = ' . $sourceId)
            ->where($this->db->quoteName('source_line_id') . ' IS NOT NULL');

        // $current: read the LATEST committed state instead of this
        // transaction's snapshot. Callers that are about to WRITE a
        // convergence based on this answer must pass true: under REPEATABLE
        // READ a plain read reports the world as it was when the transaction
        // began, so a concurrent save's committed hold is invisible and both
        // callers converge the line from zero.
        $sql = $current ? ((string) $query . ' LOCK IN SHARE MODE') : $query;

        foreach ($this->db->setQuery($sql)->loadObjectList() ?: [] as $row) {
            $lineId = (int) $row->source_line_id;
            $state[$lineId] ??= [
                'item_id' => (int) $row->item_id,
                'combination_id' => (int) $row->combination_id,
                'reserved_remainder' => 0.0,
                'deducted_net' => 0.0,
                'foreign_deducted_net' => 0.0,
            ];

            if ((string) $row->state === self::STATE_ACTIVE) {
                $state[$lineId]['reserved_remainder'] += max(0.0, (float) $row->q - (float) $row->fq - (float) $row->rq);
            }
        }

        // Ledger rows anchor on the LINE, not on this source: fulfilment
        // triggered by another domain (a document's stock effect) still
        // deducts against the same line ids, so match movements written by
        // this source OR anchored on any line the source knows.
        //
        // Reservations alone were not enough to know those lines. A delivery
        // note deducting a line that was never reserved (commitReservation()
        // falls through to "unreserved remainder -> the line's allocated
        // locations") produced a deduction NO axis could see: the void
        // rightly leaves it (the parcel shipped), and the order lifecycle
        // could not find it either, so the units were lost outright. Callers
        // that know their own lines say so.
        $known = array_keys($state);

        foreach ($lineIds as $lineId) {
            $known[] = (int) $lineId;
        }

        $known = array_values(array_unique(array_filter(array_map('intval', $known))));

        $ownFilter = $this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType)
            . ' AND ' . $this->db->quoteName('source_id') . ' = ' . $sourceId;
        $lineFilter = $ownFilter;

        // WHOSE MOVEMENTS THIS CALLER OWNS: the one line that separates an
        // ordinary edit from a cancellation. An edit sees only what this
        // source wrote, so a delivery note's deduction is not something a
        // retyped quantity compensates for; a lifecycle restore sees every
        // movement on the line, because it is the path that brings those
        // units back. The arm is narrowed by INTENT, never severed globally.
        if ($known !== [] && $intent->ownsForeignLineMovements()) {
            $lineFilter = '(' . $lineFilter . ' OR ' . $this->db->quoteName('source_line_id')
                . ' IN (' . implode(',', $known) . '))';
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']))
            ->select('COALESCE(-SUM(qty_change), 0) AS net')
            ->from($this->db->quoteName('#__alfa_inventory_movements'))
            ->where($lineFilter)
            ->where($this->db->quoteName('source_line_id') . ' IS NOT NULL')
            ->where($this->db->quoteName('movement_type') . ' IN (' . $this->db->quote(self::MOVE_DEDUCT) . ', ' . $this->db->quote(self::MOVE_RESTORE) . ')')
            ->group($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']));

        // $current applies to what is out as well: a cancellation choosing the lines
        // to bring back from its snapshot misses a deduction committed after it.
        $sql = $current ? ((string) $query . ' LOCK IN SHARE MODE') : $query;

        foreach ($this->db->setQuery($sql)->loadObjectList() ?: [] as $row) {
            $lineId = (int) $row->source_line_id;
            $state[$lineId] ??= [
                'item_id' => (int) $row->item_id,
                'combination_id' => (int) $row->combination_id,
                'reserved_remainder' => 0.0,
                'deducted_net' => 0.0,
                'foreign_deducted_net' => 0.0,
            ];
            $state[$lineId]['deducted_net'] += (float) $row->net;
        }

        // WHAT ANOTHER SOURCE ALREADY HAS OUT ON THESE LINES, always reported
        // and never folded into `deducted_net`.
        //
        // A caller that does not OWN these movements still has to know they
        // exist: the units are physically out, so a convergence that ignored
        // them entirely would claim the line a second time. Not compensating
        // a document's deduction and not duplicating it are two different
        // rules, and this is the second one.
        if ($known !== []) {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']))
                ->select('COALESCE(-SUM(qty_change), 0) AS net')
                ->from($this->db->quoteName('#__alfa_inventory_movements'))
                ->where($this->db->quoteName('source_line_id') . ' IN (' . implode(',', $known) . ')')
                ->where('NOT (' . $ownFilter . ')')
                ->where($this->db->quoteName('movement_type') . ' IN ('
                    . $this->db->quote(self::MOVE_DEDUCT) . ', ' . $this->db->quote(self::MOVE_RESTORE) . ')')
                ->group($this->db->quoteName(['source_line_id', 'item_id', 'combination_id']));

            // $current applies here too, and it matters more than elsewhere:
            // this read DRIVES a write (syncOrderLines() sizes the order's own
            // target from it). Under REPEATABLE READ a plain read reports the
            // world as of the transaction's start, so a document deduction
            // committed in between would be invisible, the target would stay at
            // the full line quantity, and the same unit would be deducted
            // twice: the flip this item exists to prevent.
            $sql = $current ? ((string) $query . ' LOCK IN SHARE MODE') : $query;

            foreach ($this->db->setQuery($sql)->loadObjectList() ?: [] as $row) {
                $lineId = (int) $row->source_line_id;
                // Seeded from the row like every other producer of this
                // struct: a line known only from a foreign movement still has
                // a unit, and a zeroed one would resolve to no unit at all.
                $state[$lineId] ??= [
                    'item_id' => (int) $row->item_id,
                    'combination_id' => (int) $row->combination_id,
                    'reserved_remainder' => 0.0,
                    'deducted_net' => 0.0,
                    'foreign_deducted_net' => 0.0,
                ];
                $state[$lineId]['foreign_deducted_net'] += (float) $row->net;
            }
        }

        return $state;
    }

    /**
     * READ-ONLY dry run of commitReservation(): the same per-line math,
     * no locks, no writes. Callers that must veto BEFORE their own
     * transaction opens (a document issue must fail before its number
     * burns) validate here first; the real command re-checks everything
     * under locks anyway, so this is a fast-fail courtesy, never the
     * authority.
     *
     * The per-line planning is the command's own ({@see self::planCommitLine()}) on
     * the line's acting identity, so a line the command would refuse for its
     * identity being gone is named here in the same words, before a document number
     * burns.
     *
     * These are the texts of {@see self::commitmentProblems()}, the typed form: each
     * problem with its line and its code and, for a line whose unreserved remainder
     * would take stock other reservations hold, the refusal the command would throw.
     *
     * @param CommitmentRequest $request The fulfilment ask.
     *
     * @return string[] Human-readable problems; empty = would apply.
     *
     * @since  2.0.0
     */
    public function validateCommitment(CommitmentRequest $request): array
    {
        return array_column($this->commitmentProblems(request: $request), 'text');
    }

    /**
     * READ-ONLY dry run of commitReservation(), typed: one entry per problem, in the
     * request's order, with the line it concerns and a code. No locks, no writes;
     * the command judges everything again under its locks, so this is a courtesy to
     * a caller that must refuse before its own transaction opens, never the
     * authority.
     *
     * Codes:
     *   identity_gone   the line's identity is gone with no recorded resolution
     *                   while it still has quantity to deduct;
     *   over_shipment   the line asks beyond the ordered quantity under the strict
     *                   over-shipment policy;
     *   physical_floor  the stock present does not cover the line (only while
     *                   negative physical stock is not permitted);
     *   reserved_floor  the unreserved remainder of a line placed as a stock claim
     *                   would take stock that reservations still standing on a
     *                   record hold. The entry carries, as `refusal`, the
     *                   InventoryReservedFloorException the command would throw
     *                   (built, not thrown; `text` is its log message). It stands in
     *                   place of the line's physical_floor entry and is reported
     *                   whatever the shop allows for negative physical stock. The
     *                   same code reports conversions that would take stock backing
     *                   a reservation ahead of the ones they convert, in the order
     *                   of claims ({@see ReservationQueue}): on the line whose
     *                   conversion comes last in that order on the record, with
     *                   the sources of the reservations ahead in its refusal; the
     *                   command ends there, so no remainder is judged then.
     *
     * The reservation floor is judged as the command judges it, over the stock
     * records as read: every line's own reservations are taken off their records
     * first, then each claim line's remainder is drawn through
     * {@see self::unreservedTake()}, cumulatively over the request, each line's
     * shelves walked in the order the command will find them
     * ({@see self::lineLocations()} over the figures reached so far). An order line
     * named by two entries is planned once, against what the earlier entry planned;
     * its physical judgement reads the line as a whole, both entries together.
     * Where a conversion would itself be refused by the physical floor, the command
     * ends before it draws a remainder, and no reserved_floor entry is reported.
     *
     * @param CommitmentRequest $request The fulfilment ask.
     *
     * @return list<array{line_id: ?int, code: string, text: string, refusal?: InventoryReservedFloorException}>
     *                                                                                                           Empty = would apply.
     *
     * @since  2.0.0
     */
    public function commitmentProblems(CommitmentRequest $request): array
    {
        $noNegative = !$this->allowNegativePhysical();

        $identities = $this->actingIdentities(lines: $request->lines, lock: false, lockIdentity: false);
        $claims = $this->lineTracking(lines: $request->lines, identities: $identities);

        // The problems of each request line, so the answer keeps the request's order.
        $found = [];

        // The stock records the request touches, by unit and location, as read and
        // then moved as the command would move them; what each reservation has given
        // up; what the earlier entries planned for an order line; the remainders
        // still to draw.
        $records = [];
        $converted = [];
        $pending = [];
        $remainders = [];

        // The reservations the command would convert, per stock record, for the
        // judgement of the order of claims.
        $conversions = [];

        // Whether a conversion would be refused by the physical floor. The command
        // then ends in its first pass, before any remainder is drawn.
        $conversionRefused = false;

        foreach ($request->lines as $key => $line) {
            $found[$key] = [];
            $identity = $identities[$key];
            $unit = ['item' => $identity['item'], 'combination' => $identity['combination']];
            $unitKey = $unit['item'] . ':' . $unit['combination'];
            $lineId = isset($line['line_id']) ? (int) $line['line_id'] : null;
            $pendingKey = $lineId !== null ? $lineId . ':' . $unit['item'] . ':' . $unit['combination'] : null;
            $plan = $this->planCommitLine(
                request: $request,
                line: $line,
                unit: $unit,
                pending: $pendingKey !== null ? ($pending[$pendingKey] ?? 0.0) : 0.0,
            );
            $effective = $plan['effective'];

            // The command's own pre-flight judges this first.
            if ($effective > self::EPS && $identity['role'] === self::ROLE_UNRESOLVED) {
                $found[$key][] = [
                    'line_id' => $lineId,
                    'code' => 'identity_gone',
                    'text' => $this->unresolvedProblem(lineId: $lineId, unit: $identity['frozen']),
                ];
                continue;
            }

            if ($plan['strict']) {
                $found[$key][] = [
                    'line_id' => $lineId,
                    'code' => 'over_shipment',
                    'text' => sprintf(
                        'line #%s asks %.2f beyond the ordered quantity (over-shipment policy: reject)',
                        $lineId ?? '-',
                        $plan['beyond'],
                    ),
                ];
                continue;
            }

            if ($effective <= self::EPS) {
                continue;
            }

            // The line's open reservations convert first, each on its own record:
            // on-hand and reserved fall together there. Nothing is written here, so
            // what a reservation gave an earlier entry of the same line is kept.
            $remaining = $effective;

            foreach ($this->activeReservationsForLine(lineId: $lineId, unit: $unit) as $reservation) {
                if ($remaining <= self::EPS) {
                    break;
                }

                $reservationId = (int) $reservation->id;
                $take = min($remaining, max(0.0, $this->remainderOf(reservation: $reservation) - ($converted[$reservationId] ?? 0.0)));

                if ($take <= self::EPS) {
                    continue;
                }

                $locationId = (int) $reservation->location_id;
                $records[$unitKey][$locationId] ??= $this->recordFigures(unit: $unit, locationId: $locationId);

                if ($noNegative && $records[$unitKey][$locationId]['quantity'] - $take < -self::EPS) {
                    $conversionRefused = true;
                }

                $this->noteConversion(
                    conversions: $conversions,
                    unit: $unit,
                    locationId: $locationId,
                    onHand: $records[$unitKey][$locationId]['quantity'],
                    reserved: $records[$unitKey][$locationId]['reserved'],
                    reservation: $reservation,
                    take: $take,
                );
                $records[$unitKey][$locationId]['quantity'] -= $take;
                $records[$unitKey][$locationId]['reserved'] = max(0.0, $records[$unitKey][$locationId]['reserved'] - $take);
                $converted[$reservationId] = ($converted[$reservationId] ?? 0.0) + $take;
                $remaining -= $take;
            }

            // Only a line placed as a stock claim deducts its unreserved remainder;
            // it waits until every line's reservations are off their records.
            $tracked = $claims[$key]['tracked'];

            // The physical judgements below read an order line as a whole: what the
            // earlier entries of the request planned for it counts with this one's.
            $needed = $effective + ($pendingKey !== null ? ($pending[$pendingKey] ?? 0.0) : 0.0);

            if ($pendingKey !== null) {
                $pending[$pendingKey] = ($pending[$pendingKey] ?? 0.0) + ($tracked ? $effective : $effective - $remaining);
            }

            if ($tracked && $remaining > self::EPS) {
                $remainders[] = ['key' => $key, 'line_id' => $lineId, 'unit' => $unit, 'remaining' => $remaining];
            }

            if (!$noNegative) {
                continue;
            }

            // A line that was placed as no stock claim (or, without an order line,
            // an identity that does not track stock) converts only the line's open
            // reservations, each at its own location, and deducts no remainder:
            // only those conversions answer to the physical floor.
            if (!$tracked) {
                foreach ($this->reservedFloorProblems(lineId: $lineId, unit: $unit, effective: $needed) as $text) {
                    $found[$key][] = ['line_id' => $lineId, 'code' => 'physical_floor', 'text' => $text];
                }

                continue;
            }

            // Physical floor: the deduction draws from the line's
            // allocated locations (or the default): enough must be there.
            $physical = 0.0;

            foreach ($this->lineLocations(lineId: $lineId, unit: $unit, figures: []) as $locationId) {
                $query = $this->db->getQuery(true)
                    ->select($this->db->quoteName('quantity'))
                    ->from($this->db->quoteName('#__alfa_inventory_stock'))
                    ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
                    ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
                    ->where($this->db->quoteName('location_id') . ' = ' . $locationId);
                $physical += (float) $this->db->setQuery($query)->loadResult();
            }

            if ($needed - $physical > self::EPS) {
                $found[$key][] = [
                    'line_id' => $lineId,
                    'code' => 'physical_floor',
                    'text' => sprintf(
                        'line #%s needs %.2f but %.2f physical is present (negative physical stock is not permitted)',
                        $lineId ?? '-',
                        $needed,
                        max(0.0, $physical),
                    ),
                ];
            }
        }

        // A conversion the physical floor refuses ends the command in its first
        // pass. No remainder is drawn then, so no reservation floor is reported:
        // its figures would describe a record below zero, which that floor forbids.
        if ($conversionRefused) {
            return array_merge(...array_values($found));
        }

        // Conversions that would take stock backing a reservation ahead of the ones
        // they convert end the command once its first pass is over, before any
        // remainder is drawn: the refusal stands in place of the physical problems
        // of the line whose conversion takes that stock.
        $claimOrder = $this->claimOrderRefusal(conversions: $conversions, applied: false);

        if ($claimOrder !== null) {
            foreach ($request->lines as $key => $line) {
                if ((isset($line['line_id']) ? (int) $line['line_id'] : null) !== $claimOrder['line_id']) {
                    continue;
                }

                $found[$key] = array_values(array: array_filter(
                    array: $found[$key],
                    callback: static fn (array $problem): bool => $problem['code'] !== 'physical_floor',
                ));
                $found[$key][] = [
                    'line_id' => $claimOrder['line_id'],
                    'code' => 'reserved_floor',
                    'text' => $claimOrder['refusal']->getMessage(),
                    'refusal' => $claimOrder['refusal'],
                ];
                break;
            }

            return array_merge(...array_values(array: $found));
        }

        // Then each waiting remainder, in request order, from what the line's
        // records hold above the reservations still standing on them. The line's
        // shelves are walked as the command will find them: over the figures
        // reached here, after the conversions and the earlier remainders.
        foreach ($remainders as $entry) {
            $unit = $entry['unit'];
            $unitKey = $unit['item'] . ':' . $unit['combination'];
            $remaining = $entry['remaining'];
            $kept = null;

            foreach ($this->lineLocations(lineId: $entry['line_id'], unit: $unit, figures: $records[$unitKey] ?? []) as $locationId) {
                if ($remaining <= self::EPS) {
                    break;
                }

                $records[$unitKey][$locationId] ??= $this->recordFigures(unit: $unit, locationId: $locationId);
                $onHand = $records[$unitKey][$locationId]['quantity'];
                $reserved = $records[$unitKey][$locationId]['reserved'];
                $take = $this->unreservedTake(onHand: $onHand, reserved: $reserved, remaining: $remaining);

                if ($kept === null && $reserved > self::TOL && $remaining - $take > self::EPS) {
                    $kept = ['location' => $locationId, 'on_hand' => $onHand, 'reserved' => $reserved, 'wanted' => $remaining];
                }

                if ($take <= self::EPS) {
                    continue;
                }

                $records[$unitKey][$locationId]['quantity'] -= $take;
                $remaining -= $take;
            }

            if ($remaining <= self::EPS || $kept === null) {
                continue;
            }

            // The floor refusal stands in place of the line's physical problem.
            $refusal = $this->remainderFloorRefusal(unit: $unit, kept: $kept);
            $found[$entry['key']] = array_values(array_filter(
                $found[$entry['key']],
                static fn (array $problem): bool => $problem['code'] !== 'physical_floor',
            ));
            $found[$entry['key']][] = [
                'line_id' => $entry['line_id'],
                'code' => 'reserved_floor',
                'text' => $refusal->getMessage(),
                'refusal' => $refusal,
            ];
        }

        return array_merge(...array_values($found));
    }

    /**
     * Read-only physical floor of the reservation conversions commitReservation()
     * makes for a line: its ACTIVE reservations in id order, each taking up to
     * its remainder until the line's effective quantity is covered, judged per
     * location against that location's record (a missing record holds zero).
     *
     * @param int|null $lineId The order line; none means no reservation to convert.
     * @param array $unit The line's acting identity: item, combination.
     * @param float $effective The quantity the command would deduct for the line.
     *
     * @return string[] One problem per location whose record would go below zero.
     *
     * @since  2.0.0
     */
    private function reservedFloorProblems(?int $lineId, array $unit, float $effective): array
    {
        if ($lineId === null) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__alfa_inventory_reservations'))
            ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
            ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
            ->where($this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE))
            ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
            ->order($this->db->quoteName('id'));
        $remaining = $effective;
        $taken = [];

        foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $reservation) {
            if ($remaining <= self::EPS) {
                break;
            }

            $take = min($remaining, $this->remainderOf(reservation: $reservation));

            if ($take <= self::EPS) {
                continue;
            }

            $locationId = (int) $reservation->location_id;
            $taken[$locationId] = ($taken[$locationId] ?? 0.0) + $take;
            $remaining -= $take;
        }

        $problems = [];

        foreach ($taken as $locationId => $take) {
            $physical = (float) $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName('quantity'))
                    ->from($this->db->quoteName('#__alfa_inventory_stock'))
                    ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
                    ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
                    ->where($this->db->quoteName('location_id') . ' = ' . $locationId),
            )->loadResult();

            if ($physical - $take < -self::EPS) {
                $problems[] = sprintf(
                    'line #%d needs %.2f reserved at location #%d but %.2f physical is present (negative physical stock is not permitted)',
                    $lineId,
                    $take,
                    $locationId,
                    max(0.0, $physical),
                );
            }
        }

        return $problems;
    }

    /**
     * Convert reserved quantity into physical deduction: the SINGLE
     * fulfilment entry for every trigger (order-status semantics,
     * document stock effects, future connectors). Triggers build a
     * CommitmentRequest; none of them owns matching logic.
     *
     * In one transaction, two passes over the request's lines, each in the
     * request's order. First, per line:
     *   1. Offset against the line's cumulative deducted ledger
     *      (idempotency: any trigger re-firing becomes a no-op).
     *   2. Bound by ordered quantity -> over-shipment policy.
     *   3. Convert the line's ACTIVE reservations (allocation is
     *      consumed, never recomputed).
     * Then, once every reservation the command converts is off its record, the
     * order of claims is judged on each record the conversions touched: on a
     * record that holds less than its reservations, they may not take the stock
     * that backs a reservation ahead of the ones they convert ({@see ReservationQueue};
     * first reserved, first served, unless an administrator served a source first
     * with {@see self::prioritiseReservations()}). A record that still covers every
     * reservation left on it is not read again. A conversion that would is refused
     * with the record's figures and the sources ahead, whatever the shop allows for
     * negative physical stock, and the whole command is undone. Then:
     *   4. Deduct each line's unreserved remainder from the line's allocated
     *      locations (or the default location when no allocation ever
     *      existed: pre-kernel orders).
     * So a remainder is judged against the reservations the command leaves on a
     * record, never against one the command converts itself, whichever order its
     * lines came in. The remainders themselves are drawn in the request's order,
     * each from what the earlier ones left.
     * An order line named by two entries of one request is planned against what the
     * earlier entry still has to deduct, so it is deducted once.
     *
     * The stock records of a line's unit are all locked, in location order, when
     * the line is reached in the first pass ({@see self::takeUnitRecords()}), before
     * its reservations are read for conversion: both passes then act on records the
     * command already holds, in whatever order the line's shelves are walked. A
     * request whose lines act on more than one unit takes the records of every unit
     * a line still has quantity to move on before the first pass, the units in
     * ascending item and combination order, so no line holds its unit's combination
     * or item row while another unit's records are still to take: the fulfilment of
     * an order over two variants of one product and a one-line reservation on the
     * second of them queue on that variant's records. A unit with no stock record at
     * all, whose line draws its remainder at the default location, gets that record
     * there.
     *
     * Each line acts on its ACTING identity: the (item, combination) it was sold
     * as, or the identity its effective recorded resolution names. That identity
     * may be a current unit or one kept only for order history (an archived
     * combination, any identity of an archived product, the bare item of a product
     * with combinations): fulfilment of an existing line never depends on the
     * identity still being sellable. Whether the line deducts its unreserved
     * remainder is decided by how it was placed ({@see OpenLineClaims::placedTrackedSql()}),
     * never by the identity's tracking now: a line placed as a stock claim deducts
     * it only from stock no reservation holds on each record it draws from, and
     * under the physical floor ({@see self::unreservedTake()}); any other line
     * converts only its open reservations and deducts nothing. A remainder left
     * short because a record kept stock back for the reservations still standing on
     * it refuses the whole command with that record's figures, the conversions of
     * every line included. A line without an order line id is judged by its
     * identity's own tracking. A line whose identity is gone with no recorded
     * resolution while it still has quantity to deduct refuses the whole command
     * before anything moves.
     *
     * @param CommitmentRequest $request The fulfilment ask.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{deducted_quantity: float, over_shipment: float, lines: int, operation_id: string}
     *
     * @throws RuntimeException Over-shipment under strict policy, or
     *                          negative physical when not permitted.
     * @throws InventoryReservedFloorException A line's unreserved remainder would take stock that
     *                                         reservations still standing on a record hold, or its
     *                                         conversions stock that backs reservations ahead of them.
     * @throws InventoryLineUnresolvedException A line's identity is gone with no recorded resolution.
     * @throws InventoryIntegrityException A unit's figures claim stock its records do not hold.
     * @throws InventoryUnitException A unit stopped tracking stock while waiting for its lock.
     *
     * @since  2.0.0
     */
    public function commitReservation(CommitmentRequest $request, ?InventoryContext $context = null): array
    {
        $context ??= new InventoryContext();

        // BEFORE event: packages may replace the request or veto by
        // throwing; pre-transaction, nothing was deducted yet.
        $before = new InventoryBeforeCommitEvent('onInventoryBeforeCommit', ['request' => $request]);
        $this->dispatch($before);
        $request = $before->getRequest();

        $operationId = $this->newOperationId();
        $totalDeducted = 0.0;
        $overShipment = 0.0;
        $lines = 0;

        $this->db->transactionStart(true);

        try {
            // SERIALISE ON THE ORDER, BEFORE ANY DECISION IS TAKEN.
            //
            // Every per-line judgement below - is this trigger a retry, how
            // much of the line is already covered, how much is left to deduct
            // - is read from the movement ledger and then acted on. The first
            // row lock used to happen much later, at the reservations and the
            // stock row, by which time `$effective` had already been decided.
            // That is check-then-act across a whole order: two deduct triggers
            // arriving together (a status change and a delivery note, or the
            // same webhook twice) both read "nothing covered yet" and both
            // deducted the line in full.
            //
            // The order row is the right anchor because a line belongs to
            // exactly one order and every trigger that can deduct it names it.
            // Locked in id order so a multi-order request can never form a
            // cycle with another one taking the same orders in reverse.
            $this->lockOrdersOfLines($request->lines);

            // THE WHOLE COMMAND OR NONE OF IT. A line whose identity is gone with
            // no recorded resolution while it still has quantity to deduct refuses
            // the command before any reservation, stock record or movement is
            // touched. The loop acts on exactly the identities resolved here.
            $identities = $this->preflightLines(
                command: 'commit',
                lines: $request->lines,
                plan: fn (array $line, array $unit): float => $this->planCommitLine(request: $request, line: $line, unit: $unit, pending: 0.0)['effective'],
                historical: true,
            );
            $claims = $this->lineTracking(lines: $request->lines, identities: $identities);

            // What an earlier entry of this request planned for the same order line
            // on the same identity and has not written yet (its remainder waits for
            // the second pass), the lines whose remainder waits, and the units whose
            // stock records the command has taken.
            $pending = [];
            $remainders = [];
            $takenUnits = [];
            $created = [];

            // The reservations the first pass converts, per stock record, with what
            // the record held before the first of them: judged against the order of
            // claims once the pass is over.
            $conversions = [];

            // A fulfilment over several units takes the stock records of every unit
            // a line still has quantity to move on first, in ascending unit order,
            // before any line takes its unit's combination or item row: two variants
            // of one product share its item row. Over one unit there is no order to
            // keep: the line that reaches it takes its records in the first pass.
            //
            // A unit with no record at all, on which a line placed as a stock claim
            // has a remainder to draw and no reservation ever, draws it at the
            // default location, the one place its shelves name ({@see self::lineLocations()}):
            // that first record is created where the unit's records are taken. A unit
            // whose figures claim stock without records is refused where a line
            // reaches it, before anything is written there.
            $unitKeys = array_map(
                callback: static fn (array $identity): string => $identity['item'] . ':' . $identity['combination'],
                array: $identities,
            );

            if (\count(value: array_unique(array: $unitKeys)) > 1) {
                $unitsFirst = [];
                $drawsAtDefault = [];

                foreach ($identities as $key => $identity) {
                    $unitFirst = ['item' => $identity['item'], 'combination' => $identity['combination']];

                    if ($this->planCommitLine(request: $request, line: $request->lines[$key], unit: $unitFirst, pending: 0.0)['effective'] > self::EPS) {
                        $unitsFirst[$unitKeys[$key]] = $unitFirst;

                        $lineFirst = isset($request->lines[$key]['line_id']) ? (int) $request->lines[$key]['line_id'] : null;

                        if (!isset($drawsAtDefault[$unitKeys[$key]])
                            && $claims[$key]['tracked']
                            && $this->unitRecordNames(unit: $unitFirst) === []
                            && $this->lineLocations(lineId: $lineFirst, unit: $unitFirst, figures: []) === [$this->defaultLocationId()]
                        ) {
                            $drawsAtDefault[$unitKeys[$key]] = [$this->defaultLocationId() => $lineFirst];
                        }
                    }
                }

                uasort(
                    array: $unitsFirst,
                    callback: static fn (array $one, array $other): int => [$one['item'], $one['combination']] <=> [$other['item'], $other['combination']],
                );

                foreach ($unitsFirst as $unitKey => $unitFirst) {
                    $this->takeUnitRecords(
                        unit: $unitFirst,
                        taken: $takenUnits,
                        create: $drawsAtDefault[$unitKey] ?? [],
                        created: $created,
                        refuseLedgerDefect: true,
                        command: 'commit',
                    );
                }
            }

            // FIRST PASS, in request order: every line converts its own
            // reservations. On-hand and reserved fall together on the reservation's
            // own record, so no other claim loses backing. The unreserved remainder
            // of a line waits until every reservation the command converts is off
            // its record.
            foreach ($request->lines as $key => $line) {
                $lineId = isset($line['line_id']) ? (int) $line['line_id'] : null;
                $identity = $identities[$key];
                $unit = ['item' => $identity['item'], 'combination' => $identity['combination']];
                $pendingKey = $lineId !== null ? $lineId . ':' . $unit['item'] . ':' . $unit['combination'] : null;
                $plan = $this->planCommitLine(
                    request: $request,
                    line: $line,
                    unit: $unit,
                    pending: $pendingKey !== null ? ($pending[$pendingKey] ?? 0.0) : 0.0,
                );

                // Over-shipment = ONE trigger asking beyond the ordered
                // quantity. The kernel never corrects wrong business data:
                // either the document is wrong or the stock already is.
                if ($plan['strict']) {
                    throw new RuntimeException(sprintf(
                        'Inventory: fulfilment for line #%s asks %.2f beyond the ordered quantity (over-shipment policy: reject).',
                        $lineId ?? '-',
                        $plan['beyond'],
                    ));
                }

                if ($plan['beyond'] > self::EPS) {
                    // allow_warn: the excess is permitted and flagged loudly;
                    // the ask was still offset against the ledger.
                    $overShipment += $plan['beyond'];
                    Log::add(
                        entry:    sprintf('Inventory over-shipment: line #%s, %.2f beyond ordered (source %s #%d).', $lineId ?? '-', $plan['beyond'], $request->sourceType, $request->sourceId),
                        priority: Log::WARNING,
                        category: 'com_alfa.inventory',
                    );
                }

                $effective = $plan['effective'];

                if ($effective <= self::EPS) {
                    continue; // Fully covered already; the re-fired trigger no-ops.
                }

                // THE LINE'S ACTING IDENTITY, whatever its tracking or catalogue
                // state is now. The pre-flight proved it still exists. Whether the
                // line deducts was decided when it was placed.
                $tracked = $claims[$key]['tracked'];
                $remaining = $effective;

                // Every record the unit has, in location order, before the line
                // converts a reservation or draws its remainder: the one order in
                // which every command takes a unit's records. The second pass
                // walks the line's shelves in its own order over records held
                // from here.
                $this->takeUnitRecords(unit: $unit, taken: $takenUnits);

                try {
                    // 2. Consume the line's ACTIVE allocation.
                    foreach ($this->lockActiveReservationsForLine(lineId: $lineId, unit: $unit) as $reservation) {
                        if ($remaining <= self::EPS) {
                            break;
                        }

                        $take = min($remaining, $this->remainderOf($reservation));

                        if ($take <= self::EPS) {
                            continue;
                        }

                        $stock = $this->lockStockRow(
                            itemId: $unit['item'],
                            combinationId: $unit['combination'],
                            locationId: (int) $reservation->location_id,
                            refuseLedgerDefect: true,
                            historical: true,
                            command: 'commit',
                            lineId: $lineId,
                            created: $created,
                        );

                        $this->guardPhysical(stock: $stock, deduction: $take);
                        $this->noteConversion(
                            conversions: $conversions,
                            unit: $unit,
                            locationId: (int) $reservation->location_id,
                            onHand: (float) $stock->quantity,
                            reserved: (float) $stock->reserved_quantity,
                            reservation: $reservation,
                            take: $take,
                        );
                        $this->transitionReservation(reservation: $reservation, fulfilledDelta: $take);
                        $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: -$take, reservedDelta: -$take);
                        $this->appendMovement(
                            unit: $unit,
                            locationId: (int) $reservation->location_id,
                            qtyChange: -$take,
                            movementType: self::MOVE_DEDUCT,
                            sourceType: $request->sourceType,
                            sourceId: $request->sourceId,
                            sourceLineId: $lineId,
                            operationId: $operationId,
                            context: $context,
                        );

                        $remaining -= $take;
                    }
                } catch (InventoryUnitException $e) {
                    throw $this->lineFailure(command: 'commit', line: $line, quantity: $plan['effective'], failure: $e);
                }

                // A line placed as no stock claim takes nothing off any shelf:
                // any open reservation it had was converted above.
                if ($remaining > self::EPS && !$tracked) {
                    Log::add(
                        entry: sprintf(
                            'Inventory: line #%s on item #%d%s was not placed as a stock claim; %.2f unreserved not deducted.',
                            $lineId ?? '-',
                            $unit['item'],
                            $unit['combination'] > 0 ? ' combination #' . $unit['combination'] : '',
                            $remaining,
                        ),
                        priority: Log::INFO,
                        category: 'com_alfa.inventory',
                    );

                    $effective -= $remaining;
                    $remaining = 0.0;
                }

                // 3. The unreserved remainder of a line placed as a stock claim
                // waits for the second pass.
                if ($remaining > self::EPS) {
                    $remainders[] = [
                        'key' => $key,
                        'line' => $line,
                        'line_id' => $lineId,
                        'unit' => $unit,
                        'role' => $identity['role'],
                        'planned' => $plan['effective'],
                        'effective' => $effective,
                        'remaining' => $remaining,
                    ];

                    if ($pendingKey !== null) {
                        $pending[$pendingKey] = ($pending[$pendingKey] ?? 0.0) + $remaining;
                    }

                    continue;
                }

                $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
                $totalDeducted += $effective;
                $lines++;
            }

            // THE ORDER OF CLAIMS. On a record that holds less than its reservations,
            // the conversions may not leave a reservation ahead of the ones they took
            // with less of its backing than before: first reserved, first served,
            // unless an administrator served an order first. Judged on what the whole
            // pass left on each record, under its locks; a refusal undoes every
            // conversion with the rest of the command.
            $claimOrder = $this->claimOrderRefusal(conversions: $conversions, applied: true);

            if ($claimOrder !== null) {
                throw $claimOrder['refusal'];
            }

            // SECOND PASS, in request order: each waiting remainder is drawn from
            // the line's allocated locations. Every record is judged as locked now,
            // after every reservation the command converts is off it, so a remainder
            // never takes stock a reservation still holds there, whichever order the
            // lines came in.
            foreach ($remainders as $entry) {
                $lineId = $entry['line_id'];
                $unit = $entry['unit'];
                $remaining = $entry['remaining'];

                // The first record that kept stock back for its reservations.
                $kept = null;

                try {
                    foreach ($this->lineLocations(lineId: $lineId, unit: $unit, figures: []) as $locationId) {
                        if ($remaining <= self::EPS) {
                            break;
                        }

                        $stock = $this->lockStockRow(
                            itemId: $unit['item'],
                            combinationId: $unit['combination'],
                            locationId: $locationId,
                            refuseLedgerDefect: true,
                            historical: true,
                            command: 'commit',
                            lineId: $lineId,
                            created: $created,
                        );

                        // Only a line without an order line was judged by its
                        // identity's tracking, which a switch-off committed while
                        // this waited for the lock can have changed.
                        if (!$claims[$entry['key']]['placed'] && !$stock->unit_tracked) {
                            throw new InventoryUnitException(
                                itemId: $unit['item'],
                                combinationId: $unit['combination'],
                                reason: InventoryUnitException::NOT_TRACKED,
                            );
                        }

                        $onHand = (float) $stock->quantity;
                        $reserved = (float) $stock->reserved_quantity;
                        $take = $this->unreservedTake(onHand: $onHand, reserved: $reserved, remaining: $remaining);

                        if ($kept === null && $reserved > self::TOL && $remaining - $take > self::EPS) {
                            $kept = ['location' => $locationId, 'on_hand' => $onHand, 'reserved' => $reserved, 'wanted' => $remaining];
                        }

                        if ($take <= self::EPS) {
                            continue;
                        }

                        $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: -$take, reservedDelta: 0);
                        $this->appendMovement(
                            unit: $unit,
                            locationId: $locationId,
                            qtyChange: -$take,
                            movementType: self::MOVE_DEDUCT,
                            sourceType: $request->sourceType,
                            sourceId: $request->sourceId,
                            sourceLineId: $lineId,
                            operationId: $operationId,
                            context: $context,
                        );

                        $remaining -= $take;
                    }
                } catch (InventoryUnitException $e) {
                    throw $this->lineFailure(command: 'commit', line: $entry['line'], quantity: $entry['planned'], failure: $e);
                }

                // Short because a record kept stock back for the reservations still
                // standing on it: the whole fulfilment is refused with that record's
                // figures, and the savepoint takes back everything it wrote, the
                // conversions of every line included.
                if ($remaining > self::EPS && $kept !== null) {
                    throw $this->remainderFloorRefusal(unit: $unit, kept: $kept);
                }

                // A line acting on an identity kept only for order history
                // answers to the same floor: that identity takes in no new
                // stock, so what its own records hold is all it can deduct,
                // and a shortfall there is refused like any other.
                if ($remaining > self::EPS && $entry['role'] === self::ROLE_HISTORICAL) {
                    throw new RuntimeException(sprintf(
                        'Inventory: line #%s needs %.2f more than item #%d%s holds for order history at its locations (negative physical stock is not permitted).',
                        $lineId ?? '-',
                        $remaining,
                        $unit['item'],
                        $unit['combination'] > 0 ? ' combination #' . $unit['combination'] : '',
                    ));
                }

                if ($remaining > self::EPS) {
                    throw new RuntimeException(sprintf(
                        'Inventory: line #%s needs %.2f more than the physical quantity at its locations (negative physical stock is not permitted).',
                        $lineId ?? '-',
                        $remaining,
                    ));
                }

                $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
                $totalDeducted += $entry['effective'];
                $lines++;
            }

            $this->recordOperation(operationId: $operationId, command: 'commit', sourceType: $request->sourceType, sourceId: $request->sourceId, context: $context);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $result = [
            'deducted_quantity' => $totalDeducted,
            'over_shipment' => $overShipment,
            'lines' => $lines,
            'operation_id' => $operationId,
        ];

        $this->announce(label: 'onInventoryCommitted', event: new InventoryCommittedEvent('onInventoryCommitted', [
            'request' => $request,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Return previously deducted quantity to physical stock (reversal /
     * credit semantics). Idempotent by ledger: at most
     * deducted - already restored comes back per line, to the exact
     * locations it left from.
     *
     * A RETURN that takes over a document's claim is one atomic operation of
     * two legs (removing the claim, then shelving the receipt) and is judged
     * on its FINAL state: every stock row it moved must end non-negative
     * row by row, and a row it took stock off may not end below what
     * open reservations hold there, whatever the shop allows for negative
     * physical stock: judged on the operation's net effect on each row, only what
     * it takes below the row's starting on-hand counts ({@see self::guardFinalReservedFloor()}).
     * A return that removes no claim is judged as before.
     *
     * A DOCUMENT brings its units back once: what the same document already
     * claimed on a line (a return's take-over of it included) is not claimed again,
     * so a retried stock effect moves nothing more; another document for the same
     * line still brings back what is still out. A document defers to what returns
     * brought back on the line that no other document's claim has taken
     * ({@see self::receiptsUnmatched()}), and records the part a receipt covers as its
     * claim taken over by that receipt (net zero on the shelf), so one receipt covers
     * one claim and two documents and a return end the same in every order.
     *
     * The command takes the rows of the lines' orders first, in id order, as a
     * fulfilment does, and reads what each line has out and back (and the claims a
     * return takes over, and what a document already restored) as the latest
     * committed state, once more after it holds the line's stock records: two
     * restores of one line queue on its order and the second sees what the first
     * gave back.
     *
     * Each line acts on its ACTING identity (the identity it was sold as, or the
     * one its effective recorded resolution names), whatever that identity's
     * tracking or catalogue state is now: a restore is anchored to the order's
     * own history, and an archived combination, any identity of an archived
     * product or the bare item of a product with combinations takes its goods back
     * like any unit (a missing record is created at zero). On a current unit whose
     * figures claim stock it has no records for, the restore still proceeds: the
     * first record starts at zero and takes only the restored quantity (the stale
     * figure is never adopted), the disagreement is kept for the audited repair,
     * the unit is logged and notified, and in the same transaction the unit is
     * held: it stays unavailable, and refused by every command that takes in stock
     * or claims, until the audited repair ends the hold (a held unit restored onto
     * again is logged and keeps its one hold). A line whose identity is gone with
     * no recorded resolution while it still has quantity to bring back refuses the
     * whole command before anything moves.
     *
     * A restore whose lines act on more than one unit locks the stock records of
     * every unit a line asks quantity back on before the first line is walked, the
     * units in ascending item and combination order, so no line holds its unit's
     * combination or item row while another unit's records are still to take: the
     * cancellation of an order over two variants of one product and a one-line
     * reservation on the second of them queue on that variant's records. A record
     * either leg will write where its unit has none is created there, at its place
     * in that order ({@see self::restoreReach()}, {@see self::takeUnitRecords()}),
     * never once the command holds a record that sorts after it; the hold of a unit
     * whose first record it is still begins where the line reaches the record.
     *
     * @param string $sourceType Trigger domain of THIS command.
     * @param int $sourceId Trigger row id.
     * @param array $lines Arrays: item_id, combination_id,
     *                     quantity (null/absent = everything
     *                     still out for the line), line_id.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{restored_quantity: float, lines: int, operation_id: string, held_units: list<array{item_id: int, combination_id: int}>}
     *                                                                                                                                       held_units: every unit this command restored onto that is held once it commits.
     *
     * @throws RuntimeException When such a return would leave a stock row below zero.
     * @throws InventoryReservedFloorException When such a return would leave a stock row it took stock off
     *                                         below what open reservations hold there.
     * @throws InventoryLineUnresolvedException When a line's identity is gone with no recorded resolution.
     *
     * @since  2.0.0
     */
    public function restore(
        string $sourceType,
        int $sourceId,
        array $lines,
        ?InventoryContext $context = null,
    ): array {
        $context ??= new InventoryContext();
        $operationId = $this->newOperationId();
        $totalRestored = 0.0;
        $count = 0;

        // Every stock row this operation moves, by id, with its on-hand before the
        // operation first moved it, and every line whose document claim it took
        // over: a return that took one over is judged on those rows' final
        // quantities before it commits.
        $movedRows = [];
        $supersededLines = [];

        // Every unit the operation restored onto, and those held once it is done.
        $restoredUnits = [];
        $heldUnits = [];

        // The units whose stock records the command has taken, and the records it
        // created there.
        $takenUnits = [];
        $created = [];

        // What the restoring document's standing restores still cover, per order line
        // and identity, as the command counts them ({@see self::documentRestored()}).
        $documentCovered = [];
        $planCovered = [];

        // What returns brought back on each order line and identity that no document's
        // claim has taken yet, as the command counts it ({@see self::receiptsUnmatched()}).
        $receipts = [];
        $planReceipts = [];

        $this->db->transactionStart(true);

        try {
            // SERIALISE ON THE ORDER, BEFORE ANY DECISION IS TAKEN, as a fulfilment
            // does ({@see self::lockOrdersOfLines()}). What a line still has out is
            // read from the movement ledger and then acted on; two restores of one
            // line (a return and the order's cancellation, a return and a credit
            // note) that both read it before either wrote both gave the same units
            // back. The order's row first, then every figure the command decides
            // from as the latest committed state: the lock keeps the order's other
            // stock commands out, the current read makes what they committed while
            // this one waited visible to it. Either half alone leaves the double
            // restore reachable.
            $this->lockOrdersOfLines($lines);

            // THE WHOLE COMMAND OR NONE OF IT. A line whose identity is gone with
            // no recorded resolution while it still has quantity to bring back
            // (both legs of a return that takes over a document's claim) refuses
            // the command before any stock record or movement is touched. The
            // loop acts on exactly the identities resolved here.
            $identities = $this->preflightLines(
                command: 'restore',
                lines: $lines,
                plan: function (array $line, array $unit) use ($sourceType, $sourceId, &$planCovered, &$planReceipts): float {
                    return $this->planRestoreLine(
                        sourceType: $sourceType,
                        line: $line,
                        unit: $unit,
                        sourceId: $sourceType === self::SOURCE_DOCUMENT ? $sourceId : null,
                        current: true,
                        covered: $planCovered,
                        receipts: $planReceipts,
                    );
                },
                historical: true,
            );

            // Where the two legs will write, per unit: the records a unit has none
            // at are created where its records are taken.
            $reach = $this->restoreReach(sourceType: $sourceType, sourceId: $sourceId, lines: $lines, identities: $identities);

            // A restore over several units takes the stock records of every unit a
            // line asks quantity back on first, in ascending unit order, before any
            // line takes its unit's combination or item row: two variants of one
            // product share its item row. Over one unit there is no order to keep:
            // the line that reaches it takes its records where it is walked.
            $unitKeys = [];

            foreach ($identities as $key => $identity) {
                if ($identity['role'] !== self::ROLE_UNRESOLVED) {
                    $unitKeys[$key] = $identity['item'] . ':' . $identity['combination'];
                }
            }

            $unitsFirst = [];

            if (\count(value: array_unique(array: $unitKeys)) > 1) {
                foreach ($unitKeys as $key => $unitKey) {
                    if (isset($unitsFirst[$unitKey])) {
                        continue;
                    }

                    $unitFirst = ['item' => $identities[$key]['item'], 'combination' => $identities[$key]['combination']];
                    $lineFirst = isset($lines[$key]['line_id']) ? (int) $lines[$key]['line_id'] : null;

                    // What the line asks for: its stated quantity, or everything
                    // still out for it.
                    $asked = isset($lines[$key]['quantity'])
                        ? (float) $lines[$key]['quantity']
                        : max(
                            0.0,
                            $this->lineLedger(lineId: $lineFirst, unit: $unitFirst, movementType: self::MOVE_DEDUCT, current: true)
                            - $this->lineLedger(lineId: $lineFirst, unit: $unitFirst, movementType: self::MOVE_RESTORE, current: true),
                        );

                    if ($asked > self::EPS) {
                        $unitsFirst[$unitKey] = $unitFirst;
                    }
                }
            }

            if (\count(value: $unitsFirst) > 1) {
                uasort(
                    array: $unitsFirst,
                    callback: static fn (array $one, array $other): int => [$one['item'], $one['combination']] <=> [$other['item'], $other['combination']],
                );

                foreach ($unitsFirst as $unitKey => $unitFirst) {
                    $this->takeUnitRecords(
                        unit: $unitFirst,
                        taken: $takenUnits,
                        create: $reach[$unitKey] ?? [],
                        created: $created,
                        refuseLedgerDefect: false,
                        command: 'restore',
                    );
                }
            }

            foreach ($lines as $key => $line) {
                $lineId = isset($line['line_id']) ? (int) $line['line_id'] : null;

                // THE LINE'S ACTING IDENTITY: the goods come back to the identity
                // they left, whatever its tracking or catalogue state is now. One
                // that is gone has nothing left to move (the pre-flight proved it).
                if ($identities[$key]['role'] === self::ROLE_UNRESOLVED) {
                    continue;
                }

                $unit = ['item' => $identities[$key]['item'], 'combination' => $identities[$key]['combination']];

                $requested = null;

                try {
                    // quantity null = everything still out for the line.
                    $requested = isset($line['quantity'])
                        ? (float) $line['quantity']
                        : max(
                            0.0,
                            $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_DEDUCT, current: true)
                            - $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_RESTORE, current: true),
                        );

                    if ($requested <= self::EPS) {
                        continue;
                    }

                    // Every record the unit has, in location order, before either
                    // leg writes one of them: the claim hand-over and the restore
                    // each walk the line's locations in an order of their own.
                    $this->takeUnitRecords(
                        unit: $unit,
                        taken: $takenUnits,
                        create: $reach[$unit['item'] . ':' . $unit['combination']] ?? [],
                        created: $created,
                        refuseLedgerDefect: false,
                        command: 'restore',
                    );

                    // WHAT IS STILL OUT, read under the order's row and the unit's
                    // records, as the latest committed state: a restore of the same
                    // line that committed while this one waited is counted, so its
                    // units are not given back a second time.
                    $deducted = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_DEDUCT, current: true);
                    $restored = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_RESTORE, current: true);

                    if (!isset($line['quantity'])) {
                        $requested = max(0.0, $deducted - $restored);

                        if ($requested <= self::EPS) {
                            continue;
                        }
                    }

                    // One unit back, one unit shelved, whichever order
                    // A return and a credit document can both say the same goods
                    // came back. Left alone they each shelve them and the line
                    // gains twice: measured +4.00 for two returned units when the
                    // document went first. The rule is not "whoever is second
                    // loses": that is order-dependent, which is the defect. It is
                    // that PHYSICAL RECEIPT IS GROUND TRUTH and a document's claim
                    // defers to it, in both directions of time.
                    if ($sourceType === self::SOURCE_RETURN) {
                        // Goods really arrived. Take back the part of any
                        // document's claim they cover, then restore in full: the
                        // shelf nets out, but the ledger now credits the receipt,
                        // so voiding that document later withdraws only what it
                        // still genuinely claims and cannot un-shelve real units.
                        //
                        // ONE OPERATION, TWO LEGS, THIS ORDER. The restore
                        // leg below is capped by a $net re-read AFTER superseding;
                        // restoring first uncaps it and the shelf gains twice (tried
                        // and reverted). So the claim leg is not floor-checked on
                        // its own (on a shelf whose credited units were sold again
                        // it passes below zero on its way back) and every row the
                        // operation moves is judged on its FINAL quantity before
                        // commit instead (guardFinalPhysical()).
                        $superseded = $this->supersedeClaims(
                            unit: $unit,
                            lineId: $lineId,
                            supersededSource: self::SOURCE_DOCUMENT,
                            quantity: $requested,
                            operationId: $operationId,
                            context: $context,
                            movedRows: $movedRows,
                            created: $created,
                        );

                        if ($superseded > self::EPS) {
                            $supersededLines[(int) $lineId] = ($supersededLines[(int) $lineId] ?? 0.0) + $superseded;
                            $restoredUnits[$unit['item'] . ':' . $unit['combination']] = $unit;

                            // The ledger just moved underneath us.
                            $restored = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_RESTORE, current: true);
                        }
                    } elseif ($sourceType === self::SOURCE_DOCUMENT) {
                        // A DOCUMENT BRINGS ITS UNITS BACK ONCE. Its stock effect is
                        // delivered at least once, and a retry applies the same lines
                        // again: what this document already claimed on the line (what
                        // it restored, a return's take-over of it included) is not
                        // claimed again, as a delivery note that already deducted a line
                        // deducts nothing more ({@see self::planCommitLine()}). Another
                        // document for the same line still brings back what is still out.
                        $already = $this->documentRestored(
                            lineId: $lineId,
                            unit: $unit,
                            sourceId: $sourceId,
                            requested: $requested,
                            covered: $documentCovered,
                            current: true,
                        );

                        if ($already > self::EPS) {
                            Log::add(
                                entry: sprintf(
                                    'Line %d: document #%d asks %.2f back; %.2f of it the document already restored, not restored again.',
                                    $lineId ?? 0,
                                    $sourceId,
                                    $requested,
                                    $already,
                                ),
                                priority: Log::INFO,
                                category: 'com_alfa.inventory',
                            );

                            $requested -= $already;

                            if ($requested <= self::EPS) {
                                continue;
                            }
                        }

                        // Bookkeeping may only shelve what no receipt already has: what
                        // returns brought back on the line and no other document's claim
                        // has taken ({@see self::receiptsUnmatched()}). One receipt covers
                        // one claim, whichever came first. The part a receipt covers is
                        // recorded as this document's claim taken over by it, as a return
                        // arriving after the document records it: the claim and its
                        // take-over, net zero on the shelf, so a later document finds the
                        // receipt taken.
                        $receiptKey = $lineId . ':' . $unit['item'] . ':' . $unit['combination'];
                        $receipts[$receiptKey] ??= $this->receiptsUnmatched(lineId: $lineId, unit: $unit, current: true);
                        $absorbed = min($requested, $receipts[$receiptKey]['available']);

                        if ($absorbed > self::EPS) {
                            $receipts[$receiptKey]['available'] -= $absorbed;

                            Log::add(
                                entry: sprintf(
                                    'Line %d: document restoring %.2f of %.2f; %.2f already returned to stock by a return process.',
                                    $lineId ?? 0,
                                    $requested - $absorbed,
                                    $requested,
                                    $absorbed,
                                ),
                                priority: Log::INFO,
                                category: 'com_alfa.inventory',
                            );

                            foreach ([$absorbed, -$absorbed] as $qtyChange) {
                                $this->appendMovement(
                                    unit: $unit,
                                    locationId: $receipts[$receiptKey]['location'],
                                    qtyChange: $qtyChange,
                                    movementType: self::MOVE_RESTORE,
                                    sourceType: self::SOURCE_DOCUMENT,
                                    sourceId: $sourceId,
                                    sourceLineId: $lineId,
                                    operationId: $operationId,
                                    context: $context,
                                );
                            }

                            $requested -= $absorbed;

                            if ($requested <= self::EPS) {
                                continue;
                            }
                        }
                    }

                    $net = max(0.0, $deducted - $restored);
                    $effective = min($requested, $net);

                    if ($effective <= self::EPS) {
                        continue;
                    }

                    $remaining = $effective;

                    foreach ($this->lineDeductionsByLocation(lineId: $lineId, unit: $unit, current: true) as $locationId => $net) {
                        if ($remaining <= self::EPS) {
                            break;
                        }

                        $give = min($remaining, $net);

                        if ($give <= self::EPS) {
                            continue;
                        }

                        $stock = $this->lockStockRow(
                            itemId: $unit['item'],
                            combinationId: $unit['combination'],
                            locationId: $locationId,
                            refuseLedgerDefect: false,
                            historical: true,
                            command: 'restore',
                            lineId: $lineId,
                            created: $created,
                        );

                        // Its first record, created while its figures claim stock
                        // without records: the unit stays held until the audited repair.
                        if ($stock->began_on_defect !== null) {
                            $this->beginIntegrityHold(
                                unit: $unit,
                                figures: $stock->began_on_defect,
                                operationId: $operationId,
                                lineId: $lineId,
                                context: $context,
                            );
                        }

                        $movedRows[(int) $stock->id] ??= (float) $stock->quantity;
                        $restoredUnits[$unit['item'] . ':' . $unit['combination']] = $unit;
                        $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: $give, reservedDelta: 0);
                        $this->appendMovement(
                            unit: $unit,
                            locationId: $locationId,
                            qtyChange: $give,
                            movementType: self::MOVE_RESTORE,
                            sourceType: $sourceType,
                            sourceId: $sourceId,
                            sourceLineId: $lineId,
                            operationId: $operationId,
                            context: $context,
                        );

                        $remaining -= $give;
                    }

                    $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
                    $totalRestored += $effective - $remaining;
                    $count++;
                } catch (InventoryUnitException $e) {
                    throw $this->lineFailure(command: 'restore', line: $line, quantity: (float) ($requested ?? 0.0), failure: $e);
                }
            }

            $this->assertCreatedReached(created: $created, command: 'restore');

            // A return that took over a document's claim did not floor-check
            // that leg on its own; the operation answers for it here, on every
            // row it moved: the physical floor, then the reservation floor on the
            // operation's net effect on each row. Nothing was relaxed anywhere
            // else, so nothing more is judged.
            if ($supersededLines !== []) {
                $this->guardFinalPhysical(stockIds: array_keys($movedRows));
                $this->guardFinalReservedFloor(movedRows: $movedRows);
            }

            // Read under this command's locks on the units: a caller tells the
            // operator which of them stay unavailable until their figures are repaired.
            foreach ($restoredUnits as $restoredUnit) {
                if ($this->integrityHeld(unit: $restoredUnit, lock: true)) {
                    $heldUnits[] = ['item_id' => $restoredUnit['item'], 'combination_id' => $restoredUnit['combination']];
                }
            }

            $this->recordOperation(operationId: $operationId, command: 'restore', sourceType: $sourceType, sourceId: $sourceId, context: $context);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        // Logged once the operation stands: a refused return superseded nothing.
        foreach ($supersededLines as $supersededLineId => $supersededQuantity) {
            $this->logOnceStands(
                label: 'inventory line: claim superseded',
                entry: sprintf(
                    'Line %d: %.2f unit(s) of %s claim superseded by a physical receipt; the goods are shelved once.',
                    $supersededLineId,
                    $supersededQuantity,
                    self::SOURCE_DOCUMENT,
                ),
                priority: Log::INFO,
            );
        }

        return ['restored_quantity' => $totalRestored, 'lines' => $count, 'operation_id' => $operationId, 'held_units' => $heldUnits];
    }

    /**
     * Undo the restores a single source wrote, as NEGATIVE restore rows.
     *
     * WHY THIS EXISTS. A voided credit note must have ZERO active stock
     * effect: issue 3 -> DOC +3, void -> DOC -3, reissue -> DOC +3, net +3 for
     * three units returned. Without it the first note's restore is permanent,
     * the reissue finds the line's outstanding balance already reduced, and
     * the pair puts back MORE than came back.
     *
     * WHY A NEGATIVE `restore` AND NOT AN `adjust`. Consumers ask "what is the
     * net active stock effect of this document?" by summing restore rows for
     * its source. An adjust row is a different movement type and would leave
     * that sum reading +3 forever, so the document would still look active
     * after being voided. And a compensating `deduct` would inflate D, which
     * is the denominator of "units still with the customer"; it would corrupt
     * the very quantity the reversal exists to restore.
     *
     * IDEMPOTENT BY ARITHMETIC, not by a flag: it reverses the NET of this
     * source's restore rows, so a second call finds net 0 and does nothing.
     * That holds even if the caller's own guard is bypassed.
     *
     * Reverses at the LOCATIONS the units were restored to, on the identity the
     * restore was written on (an identity kept only for order history included),
     * and refuses to drive any of them negative (goods already re-sold cannot be
     * un-shelved) or below what open reservations hold there, which would leave
     * those reservations on stock the reversal says never came back. A restored
     * identity that is gone with no recorded resolution, or a current unit whose
     * figures claim stock it has no records for, refuses the whole reversal before
     * anything moves; a refusal at any record rolls back the whole reversal.
     *
     * A reversal over more than one unit locks the stock records of every unit it
     * has a restore to take back on before the first of them is reversed, the units
     * in ascending item and combination order, so no unit's combination or item
     * row is held while another unit's records are still to take. A record it
     * reaches where its unit has none is created there, at its place in that order
     * ({@see self::takeUnitRecords()}).
     *
     * @param string $sourceType Source domain ('document').
     * @param int $sourceId Source row id.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{reversed_quantity: float, lines: int, operation_id: string}
     *
     * @throws InventoryLineUnresolvedException When a restored identity is gone with no recorded resolution.
     * @throws InventoryIntegrityException When a unit's figures claim stock its records do not hold.
     * @throws InventoryReservedFloorException When a record would go below its reservations.
     *
     * @since  2.0.0
     */
    public function reverseRestores(
        string $sourceType,
        int $sourceId,
        ?InventoryContext $context = null,
    ): array {
        $context ??= new InventoryContext();
        $operationId = $this->newOperationId();
        $totalReversed = 0.0;
        $count = 0;

        $this->db->transactionStart(true);

        try {
            // The NET per (unit, location, line): earlier reversals are
            // already in this sum, which is what makes a repeat a no-op.
            $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('item_id') . ', ' . $this->db->quoteName('combination_id')
                . ', ' . $this->db->quoteName('location_id') . ', ' . $this->db->quoteName('source_line_id')
                . ', COALESCE(SUM(' . $this->db->quoteName('qty_change') . '), 0) AS ' . $this->db->quoteName('net')
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_movements')
                . ' WHERE ' . $this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType)
                . ' AND ' . $this->db->quoteName('source_id') . ' = ' . (int) $sourceId
                . ' AND ' . $this->db->quoteName('movement_type') . ' = ' . $this->db->quote(self::MOVE_RESTORE)
                . ' GROUP BY ' . $this->db->quoteName('item_id') . ', ' . $this->db->quoteName('combination_id')
                . ', ' . $this->db->quoteName('location_id') . ', ' . $this->db->quoteName('source_line_id'),
            );

            $rows = $this->db->loadObjectList() ?: [];
            $asLine = static fn (object $row): array => [
                'item_id' => (int) $row->item_id,
                'combination_id' => (int) $row->combination_id,
                'line_id' => $row->source_line_id !== null ? (int) $row->source_line_id : null,
                'net' => (float) $row->net,
            ];

            // THE WHOLE COMMAND OR NONE OF IT: a restore whose identity is gone
            // with no recorded resolution cannot be taken back, and no other row
            // is reversed alone. Each row is resolved as the line it was written
            // for (its identity and order line); the loop acts on exactly that.
            $identities = $this->preflightLines(
                command: 'reverse_restore',
                lines: array_map($asLine, $rows),
                plan: static fn (array $line, array $unit): float => max(0.0, $line['net']),
                historical: true,
            );

            // The units whose stock records the command has taken, and the records
            // it created there.
            $takenUnits = [];
            $created = [];

            // A reversal over several units takes the stock records of every unit
            // it has a restore to take back on first, in ascending unit order,
            // before it takes the combination or item row of any of them: two
            // variants of one product share its item row. Over one unit there is
            // no order to keep: the first of its rows that is reached takes them.
            // Each unit's records are taken with the ones it has none of at the
            // locations of its rows, created there.
            $unitsFirst = [];
            $reach = [];

            foreach ($rows as $key => $row) {
                if ((float) $row->net > self::EPS) {
                    $unitKey = $identities[$key]['item'] . ':' . $identities[$key]['combination'];
                    $unitsFirst[$unitKey] = [
                        'item' => $identities[$key]['item'],
                        'combination' => $identities[$key]['combination'],
                    ];
                    $reach[$unitKey][(int) ($row->location_id ?: $this->defaultLocationId())] ??= $row->source_line_id !== null ? (int) $row->source_line_id : null;
                }
            }

            if (\count(value: $unitsFirst) > 1) {
                uasort(
                    array: $unitsFirst,
                    callback: static fn (array $one, array $other): int => [$one['item'], $one['combination']] <=> [$other['item'], $other['combination']],
                );

                foreach ($unitsFirst as $unitKey => $unitFirst) {
                    $this->takeUnitRecords(
                        unit: $unitFirst,
                        taken: $takenUnits,
                        create: $reach[$unitKey],
                        created: $created,
                        refuseLedgerDefect: true,
                        command: 'reverse_restore',
                    );
                }
            }

            foreach ($rows as $key => $row) {
                $net = (float) $row->net;

                if ($net <= self::EPS) {
                    continue;
                }

                $unit = [
                    'item' => $identities[$key]['item'],
                    'combination' => $identities[$key]['combination'],
                ];
                $locationId = (int) ($row->location_id ?: $this->defaultLocationId());
                $lineId = $row->source_line_id !== null ? (int) $row->source_line_id : null;

                // Every record the unit has, in location order, before one of
                // them is reversed: a source's restores of one unit can stand at
                // several locations.
                $this->takeUnitRecords(
                    unit: $unit,
                    taken: $takenUnits,
                    create: $reach[$unit['item'] . ':' . $unit['combination']] ?? [],
                    created: $created,
                    refuseLedgerDefect: true,
                    command: 'reverse_restore',
                );

                try {
                    $stock = $this->lockStockRow(
                        itemId: $unit['item'],
                        combinationId: $unit['combination'],
                        locationId: $locationId,
                        refuseLedgerDefect: true,
                        historical: true,
                        command: 'reverse_restore',
                        lineId: $lineId,
                        created: $created,
                    );
                } catch (InventoryUnitException $e) {
                    throw $this->lineFailure(command: 'reverse_restore', line: $asLine($row), quantity: $net, failure: $e);
                }

                // Never below what open reservations hold there, and never below
                // zero: those units may already have been sold.
                $this->guardReservedFloor(stock: $stock, deduction: $net, unit: $unit, command: 'reverse_restore');
                $this->guardPhysical(stock: $stock, deduction: $net);

                $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: -$net, reservedDelta: 0);
                $this->appendMovement(
                    unit: $unit,
                    locationId: $locationId,
                    qtyChange: -$net,
                    movementType: self::MOVE_RESTORE,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    sourceLineId: $lineId,
                    operationId: $operationId,
                    context: $context,
                );

                $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
                $totalReversed += $net;
                $count++;
            }

            $this->recordOperation(
                operationId: $operationId,
                command: 'reverse_restore',
                sourceType: $sourceType,
                sourceId: $sourceId,
                context: $context,
            );
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);

            throw $e;
        }

        return ['reversed_quantity' => $totalReversed, 'lines' => $count, 'operation_id' => $operationId];
    }

    /**
     * Net quantity one source domain has restored on an order line: its
     * live CLAIM that those units came back. Net, so a voided document's
     * claim (positive row plus its negative reversal) reads as zero.
     *
     * With $current the ledger is read as the latest committed state, as restore()
     * reads every figure it decides from.
     *
     * @param string $sourceType The claiming domain.
     * @param int|null $lineId The order line, or null (unattributable).
     * @param array $unit The line's acting identity.
     * @param bool $current Read the latest committed state.
     *
     * @return float Never negative.
     *
     * @since  2.0.0
     */
    private function claimedOnLine(string $sourceType, ?int $lineId, array $unit, bool $current = false): float
    {
        if ($lineId === null) {
            return 0.0;
        }

        $query = $this->db->getQuery(true)
            ->select('COALESCE(SUM(' . $this->db->quoteName('qty_change') . '), 0)')
            ->from($this->db->quoteName('#__alfa_inventory_movements'))
            ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
            ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
            ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
            ->where($this->db->quoteName('movement_type') . ' = ' . $this->db->quote(self::MOVE_RESTORE))
            ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType));

        return max(0.0, (float) $this->db->setQuery($current ? (string) $query . ' LOCK IN SHARE MODE' : $query)->loadResult());
    }

    /**
     * How much of what one document asks back on an order line that document has
     * already claimed: its restores on the line's acting identity, a return's or a
     * receipt's take-over of them included (they were claimed all the same), read as
     * the latest committed state. A voided document is never applied again, so its
     * reversal does not arise here.
     *
     * A document's stock effect is delivered at least once: a retry applies the
     * same lines again. The document's restores already in the ledger stand for
     * what it asks first, in the order of its lines: $covered holds, per order line
     * and identity, what this command has not yet counted against one of them, read
     * when the first of them is reached. So a line the document applied already
     * brings back nothing again, and two lines of one document for the same order
     * line are counted against what it restored once.
     *
     * @param int|null $lineId The order line.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param int $sourceId The document.
     * @param float $requested What this line of the document asks back.
     * @param array<string, float> $covered What the document's standing restores still cover, per
     *                                      "line:item:combination" (read when first reached).
     * @param bool $current Read the latest committed state.
     *
     * @return float The part of $requested the document already brought back.
     *
     * @since  2.0.0
     */
    private function documentRestored(?int $lineId, array $unit, int $sourceId, float $requested, array &$covered, bool $current): float
    {
        if ($lineId === null || $requested <= self::EPS) {
            return 0.0;
        }

        $key = $lineId . ':' . $unit['item'] . ':' . $unit['combination'];

        if (!isset($covered[$key])) {
            $query = $this->db->getQuery(true)
                ->select('COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('qty_change') . ' > 0 THEN ' . $this->db->quoteName('qty_change') . ' ELSE 0 END), 0)')
                ->from($this->db->quoteName('#__alfa_inventory_movements'))
                ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
                ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
                ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
                ->where($this->db->quoteName('movement_type') . ' = ' . $this->db->quote(self::MOVE_RESTORE))
                ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote(self::SOURCE_DOCUMENT))
                ->where($this->db->quoteName('source_id') . ' = ' . $sourceId);

            $covered[$key] = max(0.0, (float) $this->db->setQuery($current ? (string) $query . ' LOCK IN SHARE MODE' : $query)->loadResult());
        }

        $already = min($requested, $covered[$key]);
        $covered[$key] -= $already;

        return $already;
    }

    /**
     * What returns brought back on an order line that no document's claim has taken
     * yet, and where: the receipts a credit document still defers to.
     *
     * A receipt covers one claim. A return arriving after a document takes the
     * document's claim over (a negative restore of that document in the return's
     * operation, {@see self::supersedeClaims()}); a document arriving after a return
     * records the part a receipt covers as its claim taken over by it (the claim and
     * its take-over, in the document's own operation). Either way the document's
     * negative restores are the receipts it took, and only the rest is still free for
     * another document: so two credit documents and one return end the same whichever
     * order they come in. A voided document (one whose restores were reversed) no
     * longer holds the receipts it took: they are free again, so a replacement
     * document defers to them as the voided one did.
     *
     * Read as the latest committed state with $current, under the order's row and the
     * unit's records: the command decides from it.
     *
     * @param int|null $lineId The order line.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param bool $current Read the latest committed state.
     *
     * @return array{available: float, location: int} The free receipts (never negative), and the
     *                                                location the earliest of them shelved stock at (the default location when none did).
     *
     * @since  2.0.0
     */
    private function receiptsUnmatched(?int $lineId, array $unit, bool $current): array
    {
        $none = ['available' => 0.0, 'location' => $this->defaultLocationId()];

        if ($lineId === null) {
            return $none;
        }

        $lock = $current ? ' LOCK IN SHARE MODE' : '';
        $rows = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('source_type') . ', ' . $this->db->quoteName('source_id') . ', ' . $this->db->quoteName('location_id')
            . ', COALESCE(SUM(' . $this->db->quoteName('qty_change') . '), 0) AS ' . $this->db->quoteName('net')
            . ', COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('qty_change') . ' < 0 THEN -' . $this->db->quoteName('qty_change') . ' ELSE 0 END), 0) AS ' . $this->db->quoteName('taken')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_movements')
            . ' WHERE ' . $this->db->quoteName('source_line_id') . ' = ' . $lineId
            . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . $unit['combination']
            . ' AND ' . $this->db->quoteName('movement_type') . ' = ' . $this->db->quote(self::MOVE_RESTORE)
            . ' AND ' . $this->db->quoteName('source_type') . ' IN (' . $this->db->quote(self::SOURCE_RETURN) . ', ' . $this->db->quote(self::SOURCE_DOCUMENT) . ')'
            . ' GROUP BY ' . $this->db->quoteName('source_type') . ', ' . $this->db->quoteName('source_id') . ', ' . $this->db->quoteName('location_id')
            . ' ORDER BY ' . $this->db->quoteName('location_id')
            . $lock,
        )->loadObjectList() ?: [];

        $received = 0.0;
        $location = null;
        $taken = [];

        foreach ($rows as $row) {
            if ((string) $row->source_type === self::SOURCE_RETURN) {
                $received += (float) $row->net;

                if ($location === null && (float) $row->net > self::EPS) {
                    $location = (int) $row->location_id;
                }

                continue;
            }

            if ((float) $row->taken > self::EPS) {
                $taken[(int) $row->source_id] = ($taken[(int) $row->source_id] ?? 0.0) + (float) $row->taken;
            }
        }

        if ($received <= self::EPS) {
            return $none;
        }

        // A voided document's take-overs no longer hold a receipt.
        if ($taken !== []) {
            $voided = $this->db->setQuery(
                'SELECT DISTINCT ' . $this->db->quoteName('source_id')
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_operations')
                . ' WHERE ' . $this->db->quoteName('command') . ' = ' . $this->db->quote('reverse_restore')
                . ' AND ' . $this->db->quoteName('source_type') . ' = ' . $this->db->quote(self::SOURCE_DOCUMENT)
                . ' AND ' . $this->db->quoteName('source_id') . ' IN (' . implode(',', array_keys($taken)) . ')'
                . $lock,
            )->loadColumn() ?: [];

            foreach ($voided as $voidedId) {
                unset($taken[(int) $voidedId]);
            }
        }

        return [
            'available' => max(0.0, $received - array_sum($taken)),
            'location' => $location ?? $this->defaultLocationId(),
        ];
    }

    /**
     * Hand a bookkeeping claim over to a physical receipt.
     *
     * When goods actually arrive on a line a document has already shelved,
     * the two are describing the SAME units. Rather than deny the arrival,
     * which would leave the shelf standing on the document, so voiding it
     * later would un-shelve real goods, the document's claim is reduced by
     * the overlap: a NEGATIVE restore against that document, at the
     * locations it restored to.
     *
     * The caller then restores in full. The two movements cancel on the
     * shelf and the outstanding balance is unchanged, but attribution has
     * moved from the claim to the receipt, which is the whole point.
     *
     * Consumes oldest document first (source_id ASC) so the outcome does not
     * depend on row order. It does NOT floor-check its own leg: the claim is
     * removed before the receipt is shelved, so a shelf whose credited units
     * were sold again passes below zero in between. Every row it moves is added
     * to $movedRows, and the caller must judge those rows on the operation's
     * final state ({@see self::guardFinalPhysical()}) before committing. A first
     * record it creates while the unit's figures claim stock without records holds
     * the unit, as the restore leg does.
     *
     * @param array $unit The line's acting identity.
     * @param int|null $lineId The order line.
     * @param string $supersededSource The domain being reduced.
     * @param float $quantity Most that may be handed over.
     * @param string $operationId The caller's operation id.
     * @param InventoryContext $context Audit context.
     * @param array<int, float> $movedRows The operation's moved stock rows: id => on-hand before the
     *                                     operation first moved it (appended to).
     * @param array $created The records the caller created where it took its units' records
     *                       ({@see self::takeUnitRecords()}).
     *
     * @return float How much was actually superseded; the caller logs it once its operation commits.
     *
     * @since  2.0.0
     */
    private function supersedeClaims(
        array $unit,
        ?int $lineId,
        string $supersededSource,
        float $quantity,
        string $operationId,
        InventoryContext $context,
        array &$movedRows,
        array &$created,
    ): float {
        if ($lineId === null || $quantity <= self::EPS) {
            return 0.0;
        }

        $remaining = $quantity;
        $superseded = 0.0;

        // The claims as the latest committed state: the caller holds the order's row
        // and the unit's records, and decides from what stands now.
        foreach ($this->restoreClaims(lineId: $lineId, unit: $unit, source: $supersededSource, current: true) as $row) {
            if ($remaining <= self::EPS) {
                break;
            }

            $take = min($remaining, $row['net']);

            if ($take <= self::EPS) {
                continue;
            }

            $locationId = $row['location'];
            $stock = $this->lockStockRow(
                itemId: $unit['item'],
                combinationId: $unit['combination'],
                locationId: $locationId,
                refuseLedgerDefect: false,
                historical: true,
                command: 'restore',
                lineId: $lineId,
                created: $created,
            );

            if ($stock->began_on_defect !== null) {
                $this->beginIntegrityHold(
                    unit: $unit,
                    figures: $stock->began_on_defect,
                    operationId: $operationId,
                    lineId: $lineId,
                    context: $context,
                );
            }

            $movedRows[(int) $stock->id] ??= (float) $stock->quantity;
            $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: -$take, reservedDelta: 0);
            $this->appendMovement(
                unit: $unit,
                locationId: $locationId,
                qtyChange: -$take,
                movementType: self::MOVE_RESTORE,
                sourceType: $supersededSource,
                sourceId: $row['source'],
                sourceLineId: $lineId,
                operationId: $operationId,
                context: $context,
            );

            $remaining -= $take;
            $superseded += $take;
        }

        if ($superseded > self::EPS) {
            $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
        }

        return $superseded;
    }

    /**
     * Receive goods into a location (+physical).
     *
     * Only a tracked stock unit receives. An untracked unit, a combination that
     * no longer exists for the item, a missing item and the bare item of a
     * product with combinations are refused and nothing is written: a receipt
     * either moves what it says or refuses.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null.
     * @param float $quantity Positive quantity received.
     * @param int|null $locationId Target; null = default location.
     * @param string $sourceType Command source domain.
     * @param int $sourceId Source row id.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{operation_id: string}
     *
     * @throws InventoryUnitException When the unit is not a tracked stock unit.
     * @throws InventoryIntegrityException When the unit's figures claim stock its records do not hold.
     * @throws RuntimeException When the location cannot take stock.
     *
     * @since  2.0.0
     */
    public function receive(
        int $itemId,
        ?int $combinationId,
        float $quantity,
        ?int $locationId = null,
        string $sourceType = 'manual',
        int $sourceId = 0,
        ?InventoryContext $context = null,
    ): array {
        if ($quantity <= self::EPS) {
            throw new RuntimeException('InventoryService: receive() takes a positive quantity.');
        }

        return $this->singleDelta(
            itemId: $itemId,
            combinationId: $combinationId,
            quantityDelta: $quantity,
            movementType: self::MOVE_RECEIVE,
            locationId: $locationId,
            sourceType: $sourceType,
            sourceId: $sourceId,
            context: $context,
        );
    }

    /**
     * Manual or import correction by a signed delta.
     *
     * Only a tracked stock unit is adjusted; every other unit is refused with
     * nothing written (see receive()). Stock editors that hold an absolute
     * figure use adjustTo() instead.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null.
     * @param float $quantityDelta Signed, non-zero correction.
     * @param int|null $locationId Target; null = default location.
     * @param string $sourceType Command source domain.
     * @param int $sourceId Source row id.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{operation_id: string}
     *
     * @throws InventoryUnitException When the unit is not a tracked stock unit.
     * @throws InventoryIntegrityException When the unit's figures claim stock its records do not hold.
     * @throws InventoryReservedFloorException When a reduction would leave on-hand below the record's reservations.
     * @throws RuntimeException For a zero delta, a location that cannot take
     *                          stock, or a breach of the physical floor.
     *
     * @since  2.0.0
     */
    public function adjust(
        int $itemId,
        ?int $combinationId,
        float $quantityDelta,
        ?int $locationId = null,
        string $sourceType = 'manual',
        int $sourceId = 0,
        ?InventoryContext $context = null,
    ): array {
        if (abs($quantityDelta) <= self::EPS) {
            throw new RuntimeException('InventoryService: adjust() takes a non-zero quantity.');
        }

        return $this->singleDelta(
            itemId: $itemId,
            combinationId: $combinationId,
            quantityDelta: $quantityDelta,
            movementType: self::MOVE_ADJUST,
            locationId: $locationId,
            sourceType: $sourceType,
            sourceId: $sourceId,
            context: $context,
        );
    }

    /**
     * Converge one unit's stock at one location to an absolute figure: the
     * command every stock editor uses.
     *
     * The figure is compared with the LOCKED record, so a figure rendered before a
     * concurrent sale moves only what differs now, and a figure equal to the
     * record within float tolerance writes nothing. A target of zero where no
     * record exists creates none. Otherwise one 'adjust' movement and one
     * operation. Adding stock at a location that is not enabled is refused; a
     * reduction runs the reservation floor guard, then the physical floor guard.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null for the item unit.
     * @param int $locationId The location.
     * @param float $target The absolute quantity wanted there.
     * @param string $sourceType Command source domain ('catalog_edit', ...).
     * @param int $sourceId Source row id.
     * @param InventoryContext $context Audit context (the operator).
     *
     * @return array{operation_id: ?string, delta: float}|null Null when the unit is not a
     *                                                         tracked stock unit (the caller reports why); operation_id null when
     *                                                         nothing needed to move.
     *
     * @throws InventoryIntegrityException When the unit's figures claim stock its records do not hold.
     * @throws InventoryUnitException When the unit stopped being tracked while waiting for its lock.
     * @throws InventoryReservedFloorException When a reduction would leave on-hand below the record's reservations.
     * @throws RuntimeException For a location that cannot take stock or a breach of the floor.
     *
     * @since  2.0.0
     */
    public function adjustTo(
        int $itemId,
        ?int $combinationId,
        int $locationId,
        float $target,
        string $sourceType,
        int $sourceId,
        InventoryContext $context,
    ): ?array {
        $unit = $this->resolveUnit(itemId: $itemId, combinationId: $combinationId);

        if ($unit === null) {
            return null;
        }

        $operationId = $this->newOperationId();
        $delta = 0.0;

        $this->db->transactionStart(true);

        try {
            $existing = $this->lockExistingStockRow(
                itemId: $unit['item'],
                combinationId: $unit['combination'],
                locationId: $locationId,
            );

            // Nothing recorded and nothing wanted: no record is created for it.
            if ($existing !== null || abs($target) > self::TOL) {
                $stock = $this->lockStockRow(
                    itemId: $unit['item'],
                    combinationId: $unit['combination'],
                    locationId: $locationId,
                    refuseLedgerDefect: true,
                    historical: false,
                    command: 'adjust',
                    lineId: null,
                );

                if (!$stock->unit_tracked) {
                    throw new InventoryUnitException(
                        itemId: $unit['item'],
                        combinationId: $unit['combination'],
                        reason: InventoryUnitException::NOT_TRACKED,
                    );
                }

                if (!InventoryAvailabilityService::quantitiesAgree(a: (float) $stock->quantity, b: $target)) {
                    $delta = $target - (float) $stock->quantity;

                    $this->applyUnitDelta(
                        unit: $unit,
                        stock: $stock,
                        locationId: $locationId,
                        delta: $delta,
                        movementType: self::MOVE_ADJUST,
                        sourceType: $sourceType,
                        sourceId: $sourceId,
                        operationId: $operationId,
                        context: $context,
                    );
                }
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        if ($delta === 0.0) {
            return ['operation_id' => null, 'delta' => 0.0];
        }

        $result = ['operation_id' => $operationId, 'delta' => $delta];

        $this->announce(label: 'onInventoryAdjusted', event: new InventoryAdjustedEvent('onInventoryAdjusted', [
            'itemId' => $unit['item'],
            'combinationId' => $unit['combination'],
            'locationId' => $locationId,
            'quantityDelta' => $delta,
            'movementType' => self::MOVE_ADJUST,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Move quantity between locations: one transfer header owning an
     * out/in movement pair, all in one transaction. Instantaneous in this
     * phase (state 'completed'); an in-transit state can arrive later
     * without reshaping the ledger.
     *
     * Only a tracked stock unit is transferred; every other unit is refused. The
     * source record may not go below what active reservations hold on it.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null.
     * @param float $quantity Positive quantity to move.
     * @param int $sourceLocationId From.
     * @param int $destinationLocationId To.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{transfer_id: int, operation_id: string}
     *
     * @throws InventoryUnitException When the unit is not a tracked stock unit.
     * @throws InventoryIntegrityException When the unit's figures claim stock its records do not hold.
     * @throws InventoryReservedFloorException When the source would go below its reservations.
     *
     * @since  2.0.0
     */
    public function transfer(
        int $itemId,
        ?int $combinationId,
        float $quantity,
        int $sourceLocationId,
        int $destinationLocationId,
        ?InventoryContext $context = null,
    ): array {
        if ($quantity <= self::EPS) {
            throw new RuntimeException('InventoryService: transfer() takes a positive quantity.');
        }

        if ($sourceLocationId === $destinationLocationId) {
            throw new RuntimeException('InventoryService: transfer() needs two distinct locations.');
        }

        if ($sourceLocationId <= 0) {
            throw new RuntimeException('InventoryService: transfer() needs a real source location.');
        }

        $unit = $this->resolveUnit(itemId: $itemId, combinationId: $combinationId);

        if ($unit === null) {
            throw new InventoryUnitException(
                itemId: $itemId,
                combinationId: (int) ($combinationId ?? 0),
                reason: $this->unresolvedReason(itemId: $itemId, combinationId: (int) ($combinationId ?? 0)),
            );
        }

        $context ??= new InventoryContext();
        $operationId = $this->newOperationId();

        $this->db->transactionStart(true);

        try {
            // Every record the unit has, in location order, before the source and
            // the destination are taken in the transfer's own order: the one order
            // in which every command takes a unit's records.
            $this->lockUnitRecords(unit: $unit);

            $source = $this->lockStockRow(
                itemId: $unit['item'],
                combinationId: $unit['combination'],
                locationId: $sourceLocationId,
                refuseLedgerDefect: true,
                historical: false,
                command: 'transfer',
                lineId: null,
            );

            // The source's reservations are judged before the destination record is
            // locked or created, so a refused transfer writes nothing at all.
            $this->guardReservedFloor(stock: $source, deduction: $quantity, unit: $unit, command: 'transfer');

            $destination = $this->lockStockRow(
                itemId: $unit['item'],
                combinationId: $unit['combination'],
                locationId: $destinationLocationId,
                refuseLedgerDefect: true,
                historical: false,
                command: 'transfer',
                lineId: null,
            );

            if (!$source->unit_tracked || !$destination->unit_tracked) {
                throw new InventoryUnitException(
                    itemId: $unit['item'],
                    combinationId: $unit['combination'],
                    reason: InventoryUnitException::NOT_TRACKED,
                );
            }

            // Transfers OUT of a disabled location are allowed and transfers
            // IN are not. Moving goods off a shelf the merchant is retiring is the
            // only way to empty it; moving goods onto it is receiving under
            // another name. Read under a shared lock, after the stock rows.
            if (!LocationPolicy::isEnabled(db: $this->db, locationId: $destinationLocationId, lockInShareMode: true)) {
                throw new RuntimeException(sprintf(
                    'InventoryService: location %d is disabled and cannot be a transfer destination.',
                    $destinationLocationId,
                ));
            }

            $this->guardPhysical(stock: $source, deduction: $quantity);

            $header = (object) [
                'source_location_id' => $sourceLocationId,
                'destination_location_id' => $destinationLocationId,
                'state' => 'completed',
                'operation_id' => $operationId,
                'correlation_id' => $context->correlationId,
                'note' => $context->note,
                'created' => Factory::getDate('now', 'UTC')->toSql(),
                'created_by' => $context->userId,
            ];
            $this->db->insertObject('#__alfa_inventory_transfers', $header, 'id');
            $transferId = (int) $header->id;

            $this->applyStockDelta(stockId: (int) $source->id, quantityDelta: -$quantity, reservedDelta: 0);
            $this->applyStockDelta(stockId: (int) $destination->id, quantityDelta: $quantity, reservedDelta: 0);

            foreach ([
                [self::MOVE_TRANSFER_OUT, $sourceLocationId, -$quantity, $destinationLocationId],
                [self::MOVE_TRANSFER_IN, $destinationLocationId, $quantity, $sourceLocationId],
            ] as [$type, $locationId, $delta, $counterparty]) {
                $this->appendMovement(
                    unit: $unit,
                    locationId: $locationId,
                    qtyChange: $delta,
                    movementType: $type,
                    sourceType: 'transfer',
                    sourceId: $transferId,
                    sourceLineId: null,
                    operationId: $operationId,
                    context: $context,
                    counterpartyType: 'location',
                    counterpartyId: $counterparty,
                    transferId: $transferId,
                );
            }

            $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
            $this->recordOperation(operationId: $operationId, command: 'transfer', sourceType: 'transfer', sourceId: $transferId, context: $context);

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $result = ['transfer_id' => $transferId, 'operation_id' => $operationId];

        $this->announce(label: 'onInventoryTransferred', event: new InventoryTransferredEvent('onInventoryTransferred', [
            'itemId' => $unit['item'],
            'combinationId' => $unit['combination'],
            'quantity' => $quantity,
            'sourceLocationId' => $sourceLocationId,
            'destinationLocationId' => $destinationLocationId,
            'result' => $result,
        ]));

        return $result;
    }

    //  Shared command internals

    /**
     * Close the level a command opened, after a failure inside it, without
     * letting that close replace the failure.
     *
     * A command inside a caller's transaction rolls back to its own savepoint and
     * leaves the caller's transaction as it was. A deadlock leaves nothing to roll
     * back to: the database has already ended the whole transaction, the caller's
     * part and every savepoint with it. The level is closed by the one rule for
     * both of Joomla's MySQL drivers, {@see TransactionState::rollBackLevel()}:
     * after a deadlock the whole transaction is rolled back on the server and in
     * the driver's count (the PDO driver sends no ROLLBACK of its own then, and
     * the server would keep every later write of the process uncommitted).
     *
     * The command then throws the failure that ended it (a deadlock tells the
     * caller its transaction is gone), never a rollback's.
     *
     * @param Throwable $failure What failed inside the command's level.
     *
     * @return bool True when no transaction is left on the connection: called
     *              inside a caller's transaction, the command has ended it.
     *
     * @since  2.0.0
     */
    private function rollBackCommand(Throwable $failure): bool
    {
        return TransactionState::rollBackLevel(db: $this->db, failure: $failure);
    }

    /**
     * Whether a failure an advisory step would swallow (a notice, an allocation
     * provider) must end the command instead.
     *
     * Inside the command's transaction a deadlock has ended that transaction,
     * and a lock wait that timed out may have (the server rolls the whole
     * transaction back when innodb_rollback_on_timeout is on). Going on would
     * write the rest of the command outside any transaction, for a caller whose
     * own work is already gone. Any other failure leaves the transaction intact
     * and stays the step's own.
     *
     * @param Throwable $failure What the step threw.
     *
     * @return bool True when the failure must be rethrown.
     *
     * @since  2.0.0
     */
    private function endsCommand(Throwable $failure): bool
    {
        return TransactionState::isOpen(db: $this->db)
            && \in_array(needle: MysqlError::number(error: $failure), haystack: [1205, 1213], strict: true);
    }

    /**
     * One-unit signed delta with movement: the receive()/adjust() core.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null.
     * @param float $quantityDelta Signed quantity.
     * @param string $movementType receive | adjust.
     * @param int|null $locationId Target; null = default location.
     * @param string $sourceType Command source domain.
     * @param int $sourceId Source row id.
     * @param InventoryContext|null $context Audit context.
     *
     * @return array{operation_id: string}
     *
     * @throws InventoryUnitException When the unit is not a tracked stock unit.
     *
     * @since  2.0.0
     */
    private function singleDelta(
        int $itemId,
        ?int $combinationId,
        float $quantityDelta,
        string $movementType,
        ?int $locationId,
        string $sourceType,
        int $sourceId,
        ?InventoryContext $context,
    ): array {
        $unit = $this->resolveUnit(itemId: $itemId, combinationId: $combinationId);

        if ($unit === null) {
            throw new InventoryUnitException(
                itemId: $itemId,
                combinationId: (int) ($combinationId ?? 0),
                reason: $this->unresolvedReason(itemId: $itemId, combinationId: (int) ($combinationId ?? 0)),
            );
        }

        $context ??= new InventoryContext();
        $operationId = $this->newOperationId();
        $locationId ??= $this->defaultLocationId();

        $this->db->transactionStart(true);

        try {
            $stock = $this->lockStockRow(
                itemId: $unit['item'],
                combinationId: $unit['combination'],
                locationId: $locationId,
                refuseLedgerDefect: true,
                historical: false,
                command: $movementType,
                lineId: null,
            );

            if (!$stock->unit_tracked) {
                throw new InventoryUnitException(
                    itemId: $unit['item'],
                    combinationId: $unit['combination'],
                    reason: InventoryUnitException::NOT_TRACKED,
                );
            }

            $this->applyUnitDelta(
                unit: $unit,
                stock: $stock,
                locationId: $locationId,
                delta: $quantityDelta,
                movementType: $movementType,
                sourceType: $sourceType,
                sourceId: $sourceId,
                operationId: $operationId,
                context: $context,
            );

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $result = ['operation_id' => $operationId];

        $this->announce(label: 'onInventoryAdjusted', event: new InventoryAdjustedEvent('onInventoryAdjusted', [
            'itemId' => $unit['item'],
            'combinationId' => $unit['combination'],
            'locationId' => $locationId,
            'quantityDelta' => $quantityDelta,
            'movementType' => $movementType,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
            'result' => $result,
        ]));

        return $result;
    }

    /**
     * Apply one signed delta to a locked stock record of a tracked unit: the
     * location guard, the reservation and physical floors for a reduction, the
     * record, one movement, the unit's notices and the operation row. Shared by
     * receive(), adjust() and adjustTo().
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param object $stock The locked stock record.
     * @param int $locationId Its location.
     * @param float $delta Signed quantity.
     * @param string $movementType receive | adjust.
     * @param string $sourceType Command source domain.
     * @param int $sourceId Source row id.
     * @param string $operationId The command's operation id.
     * @param InventoryContext $context Audit context.
     *
     *
     * @throws InventoryReservedFloorException When a reduction would leave on-hand below the record's reservations.
     * @throws RuntimeException When the location cannot take stock or the floor is breached.
     *
     * @since  2.0.0
     */
    private function applyUnitDelta(
        array $unit,
        object $stock,
        int $locationId,
        float $delta,
        string $movementType,
        string $sourceType,
        int $sourceId,
        string $operationId,
        InventoryContext $context,
    ): void {
        // A disabled location takes no new stock. Only the POSITIVE
        // direction is gated: draining a retired shelf back to zero is the
        // merchant's one way to empty it (a location carrying stock cannot be
        // deleted), so refusing that would be a trap, not a contract.
        if ($delta > self::EPS && !LocationPolicy::isEnabled(db: $this->db, locationId: $locationId, lockInShareMode: true)) {
            throw new RuntimeException(sprintf(
                'InventoryService: location %d is disabled and cannot receive stock.',
                $locationId,
            ));
        }

        if ($delta < 0) {
            $this->guardReservedFloor(stock: $stock, deduction: -$delta, unit: $unit, command: $movementType);
            $this->guardPhysical(stock: $stock, deduction: -$delta);
        }

        $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: $delta, reservedDelta: 0);
        $this->appendMovement(
            unit: $unit,
            locationId: $locationId,
            qtyChange: $delta,
            movementType: $movementType,
            sourceType: $sourceType,
            sourceId: $sourceId,
            sourceLineId: null,
            operationId: $operationId,
            context: $context,
        );
        $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);
        $this->recordOperation(operationId: $operationId, command: $movementType, sourceType: $sourceType, sourceId: $sourceId, context: $context);
    }

    /**
     * Resolve the stock unit a command names, for the commands that take IN stock
     * or claims (reserve, receive, adjust, adjustTo, transfer) and for the ceiling:
     * a CURRENT unit that tracks stock.
     *
     * A product with current combinations stocks ONLY through them: a combination
     * is a unit when its row exists for that item and is not archived, and it is
     * tracked when its own manage_stock says so or, when that is NULL, the item's
     * does (NULL at both levels is untracked). A product without current
     * combinations is itself the unit when it tracks stock. Anything else resolves
     * to null: an untracked unit, an archived, vanished or foreign combination,
     * the bare item of a product with current combinations, any identity of an
     * archived product, a missing item. There is no fallback.
     *
     * @param int $itemId The item named.
     * @param mixed $combinationId The combination named; null or 0 names the item unit.
     *
     * @return array{item: int, combination: int}|null
     *
     * @since  2.0.0
     */
    private function resolveUnit(int $itemId, mixed $combinationId): ?array
    {
        if ($itemId <= 0) {
            return null;
        }

        $combinationId = (int) ($combinationId ?? 0);

        if ($combinationId > 0) {
            $this->db->setQuery(
                'SELECT COALESCE(c.' . $this->db->quoteName('manage_stock') . ', i.' . $this->db->quoteName('manage_stock') . ', 0)'
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . $combinationId
                . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' AND ' . CombinationState::currentSql(column: 'c.' . $this->db->quoteName('state'))
                . ' AND ' . CombinationState::currentSql(column: 'i.' . $this->db->quoteName('state')),
            );
            $tracked = $this->db->loadResult();

            return $tracked !== null && (int) $tracked === 1 ? ['item' => $itemId, 'combination' => $combinationId] : null;
        }

        $this->db->setQuery(
            'SELECT COALESCE(i.' . $this->db->quoteName('manage_stock') . ', 0) AS ' . $this->db->quoteName('tracked')
            . ', ' . CombinationState::currentSql(column: 'i.' . $this->db->quoteName('state')) . ' AS ' . $this->db->quoteName('current')
            . ', ' . $this->currentCombinationsExist(itemColumn: 'i.' . $this->db->quoteName('id')) . ' AS ' . $this->db->quoteName('bears')
            . ' FROM ' . $this->db->quoteName('#__alfa_items', 'i')
            . ' WHERE i.' . $this->db->quoteName('id') . ' = ' . $itemId,
        );
        $row = $this->db->loadObject();

        if ($row === null || (int) $row->current !== 1 || (int) $row->bears > 0 || (int) $row->tracked !== 1) {
            return null;
        }

        return ['item' => $itemId, 'combination' => 0];
    }

    /**
     * The SQL test "this item has at least one current combination": a combination
     * row that is not archived, of a product that is not archived (every identity of
     * an archived product is kept only for order history).
     *
     * @param string $itemColumn The quoted item id column or literal to test.
     *
     * @return string A boolean EXISTS expression.
     *
     * @since  2.0.0
     */
    private function currentCombinationsExist(string $itemColumn): string
    {
        return 'EXISTS (SELECT 1 FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'cc')
            . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'cci') . ' ON cci.' . $this->db->quoteName('id') . ' = cc.' . $this->db->quoteName('item_id')
            . ' WHERE cc.' . $this->db->quoteName('item_id') . ' = ' . $itemColumn
            . ' AND ' . CombinationState::currentSql(column: 'cc.' . $this->db->quoteName('state'))
            . ' AND ' . CombinationState::currentSql(column: 'cci.' . $this->db->quoteName('state')) . ')';
    }

    /**
     * The role of a stock identity in the current catalogue, whatever its tracking:
     *
     *   current     a combination row of that item that is not archived, or an
     *               existing item without current combinations, of a product that
     *               is not archived;
     *   historical  kept only as the identity of order history: an archived
     *               combination of that item, the bare item of a product with
     *               current combinations, or any existing identity of an archived
     *               product (the product itself and every combination row of it, in
     *               any state);
     *   unresolved  no identity at all: a combination row that is gone or belongs to
     *               another item, a missing item (whatever combination rows it left
     *               behind).
     *
     * A plain read unless $lock: a command re-checks the identity under the unit's
     * row locks when it acts on it. The product's state is its catalogue state
     * (items.state), read with the combination states' vocabulary
     * ({@see CombinationState}).
     *
     * With $lock the rows are read as the latest committed rows under shared locks
     * held until the caller's transaction ends, in the kernel's lock order: the
     * combination row with its item row, or the item row and then its current
     * combinations. Only a caller that runs no stock command afterwards in the same
     * transaction asks for it ({@see self::lineRole()}): a shared lock taken before
     * a command's own exclusive locks on the same rows would have to be upgraded by
     * that command, and two such commands on one unit would deadlock.
     *
     * @param array{item: int, combination: int} $unit The identity.
     * @param bool $lock Read the identity's rows as a current read under
     *                   shared locks (inside a transaction), or as a
     *                   plain read.
     *
     * @return string One of the ROLE_* constants.
     *
     * @since  2.0.0
     */
    private function identityRole(array $unit, bool $lock): string
    {
        $itemId = (int) $unit['item'];
        $combinationId = (int) $unit['combination'];

        if ($itemId <= 0) {
            return self::ROLE_UNRESOLVED;
        }

        if ($combinationId > 0) {
            // The combination row counts only while its item row exists, as every
            // lock-time check of the identity reads it.
            $this->db->setQuery(
                'SELECT COALESCE(c.' . $this->db->quoteName('state') . ', ' . CombinationState::UNPUBLISHED . ') AS ' . $this->db->quoteName('state')
                . ', i.' . $this->db->quoteName('state') . ' AS ' . $this->db->quoteName('item_state')
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . $combinationId
                . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ($lock ? ' LOCK IN SHARE MODE' : ''),
            );
            $row = $this->db->loadObject();

            if ($row === null) {
                return self::ROLE_UNRESOLVED;
            }

            return CombinationState::isCurrent(state: $row->state) && CombinationState::isCurrent(state: $row->item_state)
                ? self::ROLE_CURRENT
                : self::ROLE_HISTORICAL;
        }

        if ($lock) {
            // The item row, then its current combinations as a statement of their
            // own: a subquery of the first statement would not be read under its lock.
            $this->db->setQuery(
                query: 'SELECT ' . $this->db->quoteName(name: 'state') . ' FROM ' . $this->db->quoteName(name: '#__alfa_items')
                . ' WHERE ' . $this->db->quoteName(name: 'id') . ' = ' . $itemId . ' LOCK IN SHARE MODE',
            );
            $item = $this->db->loadObject();

            if ($item === null) {
                return self::ROLE_UNRESOLVED;
            }

            if (!CombinationState::isCurrent(state: $item->state)) {
                return self::ROLE_HISTORICAL;
            }

            $this->db->setQuery(
                query: 'SELECT COUNT(*) FROM ' . $this->db->quoteName(name: '#__alfa_item_combinations')
                . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . $itemId
                . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName(name: 'state'))
                . ' LOCK IN SHARE MODE',
            );

            return (int) $this->db->loadResult() > 0 ? self::ROLE_HISTORICAL : self::ROLE_CURRENT;
        }

        $this->db->setQuery(
            'SELECT i.' . $this->db->quoteName('state') . ' AS ' . $this->db->quoteName('item_state')
            . ', ' . $this->currentCombinationsExist(itemColumn: 'i.' . $this->db->quoteName('id')) . ' AS ' . $this->db->quoteName('bears')
            . ' FROM ' . $this->db->quoteName('#__alfa_items', 'i')
            . ' WHERE i.' . $this->db->quoteName('id') . ' = ' . $itemId,
        );
        $row = $this->db->loadObject();

        if ($row === null) {
            return self::ROLE_UNRESOLVED;
        }

        return CombinationState::isCurrent(state: $row->item_state) && (int) $row->bears === 0
            ? self::ROLE_CURRENT
            : self::ROLE_HISTORICAL;
    }

    /**
     * Whether an identity is a CURRENT stock unit of its item, whatever its
     * tracking: a combination row of that item that is not archived, or an existing
     * item without current combinations, of a product that is not archived.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item unit.
     *
     *
     * @since  2.0.0
     */
    private function isCurrentIdentity(int $itemId, int $combinationId): bool
    {
        return $this->identityRole(unit: ['item' => $itemId, 'combination' => $combinationId], lock: false) === self::ROLE_CURRENT;
    }

    /**
     * The frozen identity a line names, as sold.
     *
     * @param array $line A command line: item_id, combination_id.
     *
     * @return array{item: int, combination: int}
     *
     * @since  2.0.0
     */
    private function frozenUnit(array $line): array
    {
        return [
            'item' => (int) ($line['item_id'] ?? 0),
            'combination' => (int) ($line['combination_id'] ?? 0),
        ];
    }

    /**
     * The EFFECTIVE recorded resolution of order lines, keyed by order line: the row
     * of each line that no other row supersedes ({@see self::effectiveResolutionSql()}).
     * A superseded row is history only and decides nothing.
     *
     * Every row of the lines is read in one statement and the effective one is picked
     * from that same read, so a current read under a shared lock sees the chain as
     * it is committed.
     *
     * @param int[] $lineIds The order lines.
     * @param bool $lock Current read under a shared lock (inside a command's transaction).
     *
     * @return array<int, object> order line id => id, revision, supersedes_id, original and resolved
     *                            identity, operation_id.
     *
     * @since  2.0.0
     */
    private function resolutionsOf(array $lineIds, bool $lock): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        sort($ids);

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName([
                'id', 'order_item_id', 'revision', 'supersedes_id', 'original_item_id', 'original_combination_id',
                'resolved_item_id', 'resolved_combination_id', 'operation_id',
            ]))
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_line_resolutions')
            . ' WHERE ' . $this->db->quoteName('order_item_id') . ' IN (' . implode(',', $ids) . ')'
            . ' ORDER BY ' . $this->db->quoteName('order_item_id') . ', ' . $this->db->quoteName('revision')
            . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );

        return self::effectiveOf(rows: $this->db->loadObjectList() ?: []);
    }

    /**
     * Pick the effective resolution of each order line from all of its rows: the
     * one no other row supersedes (the chain is linear, so there is one; were the
     * chain ever broken, the highest revision is taken, never an older decision).
     *
     * @param object[] $rows Resolution rows with id, order_item_id, revision and supersedes_id.
     *
     * @return array<int, object> order line id => its effective row.
     *
     * @since  2.0.0
     */
    private static function effectiveOf(array $rows): array
    {
        $superseded = [];

        foreach ($rows as $row) {
            if ($row->supersedes_id !== null) {
                $superseded[(int) $row->supersedes_id] = true;
            }
        }

        $effective = [];

        foreach ($rows as $row) {
            $lineId = (int) $row->order_item_id;

            if (isset($superseded[(int) $row->id])) {
                continue;
            }

            if (!isset($effective[$lineId]) || (int) $row->revision > (int) $effective[$lineId]->revision) {
                $effective[$lineId] = $row;
            }
        }

        return $effective;
    }

    /**
     * The SQL condition "this recorded resolution is its order line's effective one":
     * no other resolution row supersedes it. Every reader of a line's resolution
     * (the kernel, the open-line claims, the audit) joins through this; a superseded
     * row is history only.
     *
     * @param \Joomla\Database\DatabaseInterface $db The database driver.
     * @param string $alias The alias of #__alfa_inventory_line_resolutions.
     *
     * @return string A boolean NOT EXISTS expression.
     *
     * @since  2.0.0
     */
    public static function effectiveResolutionSql(\Joomla\Database\DatabaseInterface $db, string $alias): string
    {
        $sup = $alias . '_sup';

        return 'NOT EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_inventory_line_resolutions', $sup)
            . ' WHERE ' . $sup . '.' . $db->quoteName('supersedes_id') . ' = ' . $alias . '.' . $db->quoteName('id') . ')';
    }

    /**
     * The SQL condition "this recorded resolution has been consumed": stock has moved
     * for its order line on the identity it resolved to since it was recorded: a
     * movement of the line on that identity, or a reservation of the line on that
     * identity in any state, written by an operation recorded after the
     * resolution's own operation. A consumed resolution can no longer be corrected.
     *
     * For snapshot readers (the audit). resolveLine() judges the same rule with
     * locking reads, so a command that committed while it waited is seen.
     *
     * @param \Joomla\Database\DatabaseInterface $db The database driver.
     * @param string $resolutionAlias The alias of #__alfa_inventory_line_resolutions.
     *
     * @return string A parenthesised boolean expression.
     *
     * @since  2.0.0
     */
    public static function resolutionConsumedSql(\Joomla\Database\DatabaseInterface $db, string $resolutionAlias): string
    {
        $r = $resolutionAlias;
        $own = '(SELECT ' . $r . '_ro.' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_inventory_operations', $r . '_ro')
            . ' WHERE ' . $r . '_ro.' . $db->quoteName('operation_id') . ' = ' . $r . '.' . $db->quoteName('operation_id') . ')';
        $since = static fn (string $table, string $alias): string => 'EXISTS (SELECT 1 FROM ' . $db->quoteName($table, $alias)
            . ' INNER JOIN ' . $db->quoteName('#__alfa_inventory_operations', $alias . 'o')
            . ' ON ' . $alias . 'o.' . $db->quoteName('operation_id') . ' = ' . $alias . '.' . $db->quoteName('operation_id')
            . ' WHERE ' . $alias . '.' . $db->quoteName('source_line_id') . ' = ' . $r . '.' . $db->quoteName('order_item_id')
            . ' AND ' . $alias . '.' . $db->quoteName('item_id') . ' = ' . $r . '.' . $db->quoteName('resolved_item_id')
            . ' AND ' . $alias . '.' . $db->quoteName('combination_id') . ' = ' . $r . '.' . $db->quoteName('resolved_combination_id')
            . ' AND ' . $alias . 'o.' . $db->quoteName('id') . ' > ' . $own . ')';

        return '(' . $since('#__alfa_inventory_movements', $r . '_cm') . ' OR ' . $since('#__alfa_inventory_reservations', $r . '_cr') . ')';
    }

    /**
     * A line's ACTING identity: the frozen (item, combination) it names, or the
     * identity recorded by an operator resolution of that order line when the line
     * names that resolution's original or resolved identity. Nothing else maps a
     * line to another identity.
     *
     * @param array $line A command line: item_id, combination_id, line_id.
     * @param object|null $resolution The recorded resolution of its order line, if any.
     * @param bool $lockIdentity Read the acting identity's catalogue rows under shared
     *                           locks ({@see self::identityRole()}), or as a plain read.
     *
     * @return array{item: int, combination: int, frozen: array{item: int, combination: int}, resolution_id: ?int, role: string}
     *
     * @since  2.0.0
     */
    private function lineIdentity(array $line, ?object $resolution, bool $lockIdentity): array
    {
        $frozen = $this->frozenUnit(line: $line);
        $acting = $frozen;
        $resolutionId = null;

        if ($resolution !== null) {
            $named = [$frozen['item'], $frozen['combination']];
            $resolved = [(int) $resolution->resolved_item_id, (int) $resolution->resolved_combination_id];

            if ($named === [(int) $resolution->original_item_id, (int) $resolution->original_combination_id] || $named === $resolved) {
                $acting = ['item' => $resolved[0], 'combination' => $resolved[1]];
                $resolutionId = (int) $resolution->id;
            }
        }

        return [
            'item' => $acting['item'],
            'combination' => $acting['combination'],
            'frozen' => $frozen,
            'resolution_id' => $resolutionId,
            'role' => $this->identityRole(unit: $acting, lock: $lockIdentity),
        ];
    }

    /**
     * The acting identity of every line of a command, keyed like the lines, with
     * the lines' resolutions read in one query.
     *
     * The identities' catalogue rows are a plain read for every command and dry
     * run: a command locks those rows exclusively when it acts on the identity and
     * re-checks it there ({@see self::assertUnitIdentity()}), and a shared lock taken
     * here first would have to be upgraded by it, so two such commands on one unit
     * would deadlock. Only the order-line editor's check before its commit, after
     * which no stock command follows, reads them under shared locks
     * ({@see self::lineRole()}).
     *
     * @param array $lines The command's lines: item_id, combination_id, line_id.
     * @param bool $lock Read the resolutions as a current read under a shared lock.
     * @param bool $lockIdentity Read each acting identity's catalogue rows under shared locks
     *                           held until the caller's transaction ends, or as a plain read.
     *
     * @return array<int|string, array{item: int, combination: int, frozen: array, resolution_id: ?int, role: string}>
     *
     * @since  2.0.0
     */
    private function actingIdentities(array $lines, bool $lock, bool $lockIdentity): array
    {
        $lineIds = [];

        foreach ($lines as $line) {
            $lineIds[] = isset($line['line_id']) ? (int) $line['line_id'] : 0;
        }

        $resolutions = $this->resolutionsOf(lineIds: $lineIds, lock: $lock);
        $identities = [];

        foreach ($lines as $key => $line) {
            $lineId = isset($line['line_id']) ? (int) $line['line_id'] : 0;
            $identities[$key] = $this->lineIdentity(line: $line, resolution: $resolutions[$lineId] ?? null, lockIdentity: $lockIdentity);
        }

        return $identities;
    }

    /**
     * The acting stock identity of order lines and its role, read-only: what
     * fulfilment, cancellation and returns of each line act on (the identity it was
     * sold as, or the one an operator resolution recorded for it), and whether that
     * identity is a current unit, kept only for order history, or gone.
     *
     * A caller whose read drives a write inside its own transaction asks for a
     * current read: the recorded resolutions are then read as the latest committed
     * rows under a shared lock, so a resolution committed after the transaction's
     * snapshot is seen. The identities' catalogue rows are a plain read, on purpose:
     * the stock commands that follow this read lock those rows exclusively and
     * check the identity again there, and a shared lock taken here would have to be
     * upgraded by them.
     *
     * placed_tracked says whether the line was placed as a stock claim (so its
     * fulfilment deducts stock); reservable says whether a reservation may be held
     * for it now: its acting identity is a current unit that tracks stock now and
     * the line was placed as a stock claim.
     *
     * @param array $lines Order lines: line_id, item_id, combination_id.
     * @param bool $current Read the recorded resolutions as a current read under a
     *                      shared lock (inside a transaction), or as a plain read.
     *
     * @return array<int, array{item_id: int, combination_id: int, role: string, resolution_id: ?int, placed_tracked: bool, reservable: bool}>
     *                                                                                                                                         Keyed by order line id; lines without an id are not reported.
     *
     * @since  2.0.0
     */
    public function lineIdentities(array $lines, bool $current): array
    {
        $identities = $this->actingIdentities(lines: $lines, lock: $current, lockIdentity: false);
        $claims = $this->lineTracking(lines: $lines, identities: $identities);
        $out = [];

        foreach ($identities as $key => $identity) {
            $lineId = isset($lines[$key]['line_id']) ? (int) $lines[$key]['line_id'] : 0;

            if ($lineId <= 0) {
                continue;
            }

            $placed = $claims[$key]['tracked'];

            $out[$lineId] = [
                'item_id' => $identity['item'],
                'combination_id' => $identity['combination'],
                'role' => $identity['role'],
                'resolution_id' => $identity['resolution_id'],
                'placed_tracked' => $placed,
                'reservable' => $placed
                    && $identity['role'] === self::ROLE_CURRENT
                    && $this->resolveUnit(itemId: $identity['item'], combinationId: $identity['combination']) !== null,
            ];
        }

        return $out;
    }

    /**
     * The role of the stock identity an order line would act on if it named the
     * given identity: the role lineIdentities() reports for that line naming it.
     * The line's effective recorded resolution applies when it names that
     * resolution's original or resolved identity. A line that does not exist yet
     * (id 0) has no resolution, so the named identity is judged as it stands.
     *
     * For the order-line editor, which may create a line, raise its quantity or
     * point it at another identity only when this role is current.
     *
     * With $lockIdentity the identity's catalogue rows are read as the latest
     * committed rows under shared locks held until the caller's transaction ends
     * (the combination row with its item row, or the item row and then its current
     * combinations), so a combination or product archived after the transaction's
     * snapshot is seen and cannot be archived until it ends. It is for a check made
     * as the last statement of a transaction. Before a stock command of the same
     * transaction it must stay false: that command locks the same rows exclusively,
     * would have to upgrade the shared lock, and two such transactions on one unit
     * would deadlock.
     *
     * @param int $lineId The order line, 0 for a line not yet created.
     * @param int $itemId The item the line names.
     * @param int $combinationId The combination the line names, 0 for the item itself.
     * @param bool $current Read the recorded resolution as a current read under a
     *                      shared lock (inside a transaction), or as a plain read.
     * @param bool $lockIdentity Read the identity's catalogue rows under shared locks
     *                           (inside a transaction that runs no stock command
     *                           afterwards), or as a plain read.
     *
     * @return string One of the ROLE_* constants.
     *
     * @since  2.0.0
     */
    public function lineRole(int $lineId, int $itemId, int $combinationId, bool $current, bool $lockIdentity): string
    {
        $identities = $this->actingIdentities(
            lines: [['line_id' => max(0, $lineId), 'item_id' => $itemId, 'combination_id' => $combinationId]],
            lock: $current,
            lockIdentity: $lockIdentity,
        );

        return $identities[0]['role'];
    }

    /**
     * Whether each line of a command is a stock claim: whether its fulfilment
     * deducts its unreserved remainder and whether reserve may hold stock for it.
     *
     * An order line is judged by how it was placed ({@see OpenLineClaims::placedTrackedSql()}):
     * its placement record, or for a line placed before those records existed, its
     * own reservations and deductions. The current tracking of its identity is never
     * read for it. One query for the command. A command line without an order line
     * id (or naming a line that no longer exists) is no placement: it is judged by
     * its acting identity's own tracking now (a combination row in any state uses
     * its own flag, or the item's when NULL; the item identity uses the item's; a
     * missing identity is untracked).
     *
     * @param array $lines The command's lines: line_id, item_id, combination_id.
     * @param array $identities The lines' acting identities, keyed like the lines.
     *
     * @return array<int|string, array{tracked: bool, placed: bool}> Keyed like the lines: tracked = a stock
     *                                                               claim; placed = decided by the line's placement (it names an order line).
     *
     * @since  2.0.0
     */
    private function lineTracking(array $lines, array $identities): array
    {
        $lineIds = [];

        foreach ($lines as $line) {
            $lineId = isset($line['line_id']) ? (int) $line['line_id'] : 0;

            if ($lineId > 0) {
                $lineIds[$lineId] = $lineId;
            }
        }

        $placed = [];

        if ($lineIds !== []) {
            $this->db->setQuery(
                'SELECT oi.' . $this->db->quoteName('id') . ' AS ' . $this->db->quoteName('line_id')
                . ', ' . OpenLineClaims::placedTrackedSql(db: $this->db, lineAlias: 'oi') . ' AS ' . $this->db->quoteName('tracked')
                . ' FROM ' . $this->db->quoteName('#__alfa_order_items', 'oi')
                . ' WHERE oi.' . $this->db->quoteName('id') . ' IN (' . implode(',', $lineIds) . ')',
            );

            foreach ($this->db->loadObjectList() ?: [] as $row) {
                $placed[(int) $row->line_id] = (int) $row->tracked === 1;
            }
        }

        $claims = [];

        foreach ($lines as $key => $line) {
            $lineId = isset($line['line_id']) ? (int) $line['line_id'] : 0;

            if (isset($placed[$lineId])) {
                $claims[$key] = ['tracked' => $placed[$lineId], 'placed' => true];
                continue;
            }

            $unit = ['item' => (int) $identities[$key]['item'], 'combination' => (int) $identities[$key]['combination']];

            if ($unit['combination'] > 0) {
                $this->db->setQuery(
                    'SELECT COALESCE(c.' . $this->db->quoteName('manage_stock') . ', i.' . $this->db->quoteName('manage_stock') . ', 0)'
                    . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                    . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                    . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . $unit['combination']
                    . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . $unit['item'],
                );
            } else {
                $this->db->setQuery(
                    'SELECT COALESCE(' . $this->db->quoteName('manage_stock') . ', 0) FROM ' . $this->db->quoteName('#__alfa_items')
                    . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $unit['item'],
                );
            }

            $claims[$key] = ['tracked' => (int) $this->db->loadResult() === 1, 'placed' => false];
        }

        return $claims;
    }

    /**
     * Why resolveUnit() rejected a unit, as an InventoryUnitException reason.
     *
     * @param int $itemId The item named.
     * @param int $combinationId The combination named, 0 for the item unit.
     *
     *
     * @since  2.0.0
     */
    private function unresolvedReason(int $itemId, int $combinationId): string
    {
        if (!$this->itemExists(itemId: $itemId)) {
            return InventoryUnitException::ITEM_GONE;
        }

        if ($this->itemArchived(itemId: $itemId)) {
            return InventoryUnitException::ITEM_ARCHIVED;
        }

        if ($combinationId > 0) {
            return match ($this->identityRole(unit: ['item' => $itemId, 'combination' => $combinationId], lock: false)) {
                self::ROLE_CURRENT => InventoryUnitException::NOT_TRACKED,
                self::ROLE_HISTORICAL => InventoryUnitException::COMBINATION_ARCHIVED,
                default => InventoryUnitException::COMBINATION_GONE,
            };
        }

        return $this->itemHasCurrentCombinations(itemId: $itemId)
            ? InventoryUnitException::ITEM_BEARS_COMBINATIONS
            : InventoryUnitException::NOT_TRACKED;
    }

    /**
     * Whether the item row exists.
     *
     * @param int $itemId The item.
     *
     *
     * @since  2.0.0
     */
    private function itemExists(int $itemId): bool
    {
        if ($itemId <= 0) {
            return false;
        }

        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId,
        );

        return (int) $this->db->loadResult() > 0;
    }

    /**
     * Whether the item row exists and is archived (items.state 2): the product has
     * left current commerce and every identity of it is kept only for order history.
     *
     * @param int $itemId The item.
     *
     *
     * @since  2.0.0
     */
    private function itemArchived(int $itemId): bool
    {
        $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('state') . ' FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId,
        );
        $row = $this->db->loadObject();

        return $row !== null && !CombinationState::isCurrent(state: $row->state);
    }

    /**
     * Whether the item has at least one current (not archived) combination row.
     *
     * @param int $itemId The item.
     *
     *
     * @since  2.0.0
     */
    private function itemHasCurrentCombinations(int $itemId): bool
    {
        $this->db->setQuery('SELECT ' . $this->currentCombinationsExist(itemColumn: (string) $itemId));

        return (int) $this->db->loadResult() > 0;
    }

    /**
     * The pre-flight of every multi-line command: resolve each line's acting
     * identity and refuse the whole command, before any lock on a stock record,
     * reservation transition or movement, when a line that still has quantity to
     * move cannot act on it. Such a line is never skipped silently and never moved
     * partly.
     *
     * Fulfilment and reversal ($historical) accept any identity that still exists
     * (a current unit, an archived combination, the bare item of a product with
     * combinations) and refuse only a line whose identity is gone with no recorded
     * resolution (identity_gone). Reserve creates a claim, so it accepts only a
     * current unit: an identity kept for order history is refused as not_current,
     * a gone one as identity_gone. Their resolutions are read as a current read in
     * the historical commands, which hold the order lock where they have one.
     *
     * The identities' catalogue rows are a plain read here, on purpose: the command
     * takes exclusive locks on those rows when it processes each line and re-checks
     * the identity under them ({@see self::assertUnitIdentity()}). A shared lock
     * taken here would have to be upgraded there, and two commands on one unit
     * would then deadlock.
     *
     * Lines that can act are not planned at all, so the hot path pays one identity
     * read per line and one resolution read per command.
     *
     * @param string $command The command (reserve, commit, restore, reverse_restore).
     * @param array $lines The command's lines: item_id, combination_id, line_id, ...
     * @param callable $plan fn (array $line, array $unit): float - the quantity the command
     *                       would move for the line on its acting identity.
     * @param bool $historical Fulfilment or reversal (true), or reserve (false).
     *
     * @return array<int|string, array{item: int, combination: int, frozen: array, resolution_id: ?int, role: string}>
     *                                                                                                                 The acting identity of every line, keyed like the lines: the command acts on exactly these.
     *
     * @throws InventoryLineUnresolvedException Naming every such line with the identity it was sold as.
     *
     * @since  2.0.0
     */
    private function preflightLines(string $command, array $lines, callable $plan, bool $historical): array
    {
        $identities = $this->actingIdentities(lines: $lines, lock: $historical, lockIdentity: false);
        $refused = [];

        foreach ($lines as $key => $line) {
            $identity = $identities[$key];

            if ($identity['role'] === self::ROLE_CURRENT || ($historical && $identity['role'] === self::ROLE_HISTORICAL)) {
                continue;
            }

            $quantity = (float) $plan($line, ['item' => $identity['item'], 'combination' => $identity['combination']]);

            if ($quantity <= self::EPS) {
                continue;
            }

            $refused[] = [
                'line_id' => isset($line['line_id']) ? (int) $line['line_id'] : 0,
                'item_id' => $identity['frozen']['item'],
                'combination_id' => $identity['frozen']['combination'],
                'quantity' => $quantity,
                'reason' => $identity['role'] === self::ROLE_HISTORICAL
                    ? InventoryLineUnresolvedException::REASON_NOT_CURRENT
                    : InventoryLineUnresolvedException::REASON_IDENTITY_GONE,
            ];
        }

        if ($refused === []) {
            return $identities;
        }

        $refusal = new InventoryLineUnresolvedException(command: $command, lines: $refused);

        Log::add(entry: $refusal->getMessage(), priority: Log::ERROR, category: 'com_alfa.inventory');

        throw $refusal;
    }

    /**
     * Turn an identity failure detected under lock while a line was processed
     * into the refusal of the whole command naming that line with the identity it
     * was sold as; the command's savepoint then rolls back everything it wrote. An
     * identity that still exists but is no longer current (only reserve checks for
     * that) is refused as not_current, a gone one as identity_gone. A tracking
     * failure is returned unchanged.
     *
     * @param string $command The command.
     * @param array $line The line being processed.
     * @param float $quantity What the line had to move.
     * @param InventoryUnitException $failure The failure.
     *
     * @return RuntimeException The exception to throw.
     *
     * @since  2.0.0
     */
    private function lineFailure(string $command, array $line, float $quantity, InventoryUnitException $failure): RuntimeException
    {
        if (!$failure->identityLost()) {
            return $failure;
        }

        $frozen = $this->frozenUnit(line: $line);
        $refusal = new InventoryLineUnresolvedException(
            command: $command,
            lines: [[
                'line_id' => isset($line['line_id']) ? (int) $line['line_id'] : 0,
                'item_id' => $frozen['item'] > 0 ? $frozen['item'] : $failure->itemId,
                'combination_id' => $frozen['item'] > 0 ? $frozen['combination'] : $failure->combinationId,
                'quantity' => $quantity,
                'reason' => \in_array($failure->reason, [
                    InventoryUnitException::COMBINATION_ARCHIVED,
                    InventoryUnitException::ITEM_ARCHIVED,
                    InventoryUnitException::ITEM_BEARS_COMBINATIONS,
                ], true)
                    ? InventoryLineUnresolvedException::REASON_NOT_CURRENT
                    : InventoryLineUnresolvedException::REASON_IDENTITY_GONE,
            ]],
            previous: $failure,
        );

        Log::add(entry: $refusal->getMessage(), priority: Log::ERROR, category: 'com_alfa.inventory');

        return $refusal;
    }

    /**
     * The problem text validateCommitment() and validateRestore() give for a
     * line whose identity is gone with no recorded resolution.
     *
     * @param int|null $lineId The order line.
     * @param array{item: int, combination: int} $unit The identity it was sold as.
     *
     *
     * @since  2.0.0
     */
    private function unresolvedProblem(?int $lineId, array $unit): string
    {
        return sprintf(
            'line #%d refers to combination #%d of item #%d, which no longer exists and has no recorded resolution',
            (int) $lineId,
            $unit['combination'],
            $unit['item'],
        );
    }

    /**
     * What commitReservation() would deduct for one line, with the command's own
     * ledger math on the line's acting identity. Read-only.
     *
     * Two request semantics on the same ledger:
     *   'target' (status semantics, reconciliation): requested is the ABSOLUTE
     *   coverage of the line, measured against the ORDER'S OWN movements; another
     *   owner's restore (a return is always a refund here, never a replacement)
     *   is not the order's to re-take; a target of zero still plans what the
     *   source's own net is below zero;
     *   'increment' (documents, connectors): requested is THIS trigger's ask; a
     *   trigger whose source already wrote net deduction is a retry and plans
     *   nothing; overlap with other triggers is capped at the ordered quantity,
     *   and an ask beyond the ordered quantity is an over-shipment: refused under
     *   the strict policy ('strict'), otherwise permitted and still offset against
     *   the line's cumulative deduction.
     *
     * $pending is what an earlier entry of the same command planned for the same
     * order line on the same identity and has not written yet: the ledger does not
     * show it, so it counts here as that entry's deduction would. A target is
     * planned against it; an increment plans nothing while it is positive, as it
     * plans nothing once its source has written the line. A line named twice in one
     * request is therefore deducted once.
     *
     * @param CommitmentRequest $request The fulfilment ask.
     * @param array $line One request line.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param float $pending What earlier entries of the command planned for this
     *                       order line on this identity and have not written
     *                       yet; 0.0 for the first entry of a line, and for a
     *                       line without an order line id.
     *
     * @return array{effective: float, beyond: float, strict: bool}
     *
     * @since  2.0.0
     */
    private function planCommitLine(CommitmentRequest $request, array $line, array $unit, float $pending): array
    {
        $requested = (float) ($line['quantity'] ?? 0);
        $ordered = (float) ($line['ordered'] ?? $requested);
        $lineId = isset($line['line_id']) ? (int) $line['line_id'] : null;
        $mode = (string) ($line['mode'] ?? 'increment');
        $none = ['effective' => 0.0, 'beyond' => 0.0, 'strict' => false];

        if ($unit['item'] <= 0) {
            return $none;
        }

        // A target is the coverage the source's own net on the identity must reach,
        // zero included: a source whose own net there is below zero (an order whose
        // cancellation gave a delivery note's units back) covers that again.
        if ($mode === 'target') {
            $ownNet = $this->lineNet(
                lineId:     $lineId,
                unit:       $unit,
                sourceType: $request->sourceType,
                sourceId:   $request->sourceId,
            );

            return ['effective' => max(0.0, max(0.0, min($requested, $ordered)) - $ownNet - $pending), 'beyond' => 0.0, 'strict' => false];
        }

        if ($requested <= self::EPS) {
            return $none;
        }

        if ($pending > self::EPS
            || $this->lineNet(lineId: $lineId, unit: $unit, sourceType: $request->sourceType, sourceId: $request->sourceId) > self::EPS
        ) {
            return $none; // Same source already applied, or planned it in this command: a retry no-op.
        }

        $net = $this->lineNet(lineId: $lineId, unit: $unit);
        $beyond = max(0.0, $requested - $ordered);

        if ($beyond > self::EPS) {
            return [
                'effective' => max(0.0, $requested - $net),
                'beyond' => $beyond,
                'strict' => $this->overShipmentPolicy() === self::OVER_SHIPMENT_STRICT,
            ];
        }

        return ['effective' => min($requested, max(0.0, $ordered - $net)), 'beyond' => 0.0, 'strict' => false];
    }

    /**
     * What restore() would move for one line, both legs included, with the
     * command's own ledger math on the line's acting identity. Read-only.
     *
     *   return:    the document claim it takes over, min(requested, document claim),
     *              PLUS the restore leg on the ledger after that leg;
     *   document:  min(max(0, requested - what this document already claimed
     *              - what returns shelved that no other claim has taken), deducted - restored);
     *   other:     min(requested, deducted - restored).
     *
     * What the document already brought back is counted only when the document is
     * named ($sourceId): the command plans with it, the dry run before an issue has
     * no document yet. $covered carries what its standing restores still cover from
     * one line to the next ({@see self::documentRestored()}), $receipts what the free
     * receipts still cover ({@see self::receiptsUnmatched()}).
     *
     * @param string $sourceType The restoring domain.
     * @param array $line One restore line (quantity null = everything still out).
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param int|null $sourceId The restoring source row, or null for the dry run.
     * @param bool $current Read the latest committed state (the command).
     * @param array<string, float> $covered The document's standing restores not yet counted.
     * @param array<string, array> $receipts The free receipts not yet counted, per order line and identity.
     *
     *
     * @since  2.0.0
     */
    private function planRestoreLine(
        string $sourceType,
        array $line,
        array $unit,
        ?int $sourceId = null,
        bool $current = false,
        array &$covered = [],
        array &$receipts = [],
    ): float {
        $lineId = isset($line['line_id']) ? (int) $line['line_id'] : null;

        $deducted = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_DEDUCT, current: $current);
        $restored = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_RESTORE, current: $current);
        $requested = isset($line['quantity']) ? (float) $line['quantity'] : max(0.0, $deducted - $restored);

        if ($requested <= self::EPS) {
            return 0.0;
        }

        if ($sourceType === self::SOURCE_RETURN) {
            $supersede = min($requested, $this->claimedOnLine(sourceType: self::SOURCE_DOCUMENT, lineId: $lineId, unit: $unit, current: $current));

            return $supersede + min($requested, max(0.0, $deducted - ($restored - $supersede)));
        }

        if ($sourceType === self::SOURCE_DOCUMENT) {
            if ($sourceId !== null) {
                $requested -= $this->documentRestored(
                    lineId: $lineId,
                    unit: $unit,
                    sourceId: $sourceId,
                    requested: $requested,
                    covered: $covered,
                    current: $current,
                );
            }

            $receiptKey = $lineId . ':' . $unit['item'] . ':' . $unit['combination'];
            $receipts[$receiptKey] ??= $this->receiptsUnmatched(lineId: $lineId, unit: $unit, current: $current);
            $absorbed = min($requested, $receipts[$receiptKey]['available']);
            $receipts[$receiptKey]['available'] -= $absorbed;

            return min(max(0.0, $requested - $absorbed), max(0.0, $deducted - $restored));
        }

        return min($requested, max(0.0, $deducted - $restored));
    }

    /**
     * Where restore() will lock stock records: per unit, every location its claim
     * hand-over and its restore leg reach, worked out before anything is written,
     * so the records the unit has none at are created where the command takes the
     * units' records ({@see self::takeUnitRecords()}).
     *
     * The walk is restore()'s own, line by line in the command's order, on the same
     * reads, as the latest committed state: what the line has out and back, the
     * document claims it takes over (a return) or what the document itself and a
     * return already brought back (a document), and its net out per location. What
     * an earlier line of the command on the same order line and identity writes
     * counts as written, as the column stores it. A unit whose lines moved stock
     * only at locations where it has records cannot need one and is not walked.
     *
     * @param string $sourceType The restoring domain.
     * @param int $sourceId The restoring source row.
     * @param array $lines The command's lines.
     * @param array $identities Their acting identities ({@see self::preflightLines()}).
     *
     * @return array<string, array<int, int>> Per unit ("item:combination"), the locations reached, in the
     *                                        order the command reaches them, each with the order line that reaches it first.
     *
     * @since  2.0.0
     */
    private function restoreReach(string $sourceType, int $sourceId, array $lines, array $identities): array
    {
        // The lines of each unit, in the command's order. A line without an order
        // line has no ledger: it moves nothing.
        $byUnit = [];

        foreach ($lines as $key => $line) {
            if ($identities[$key]['role'] === self::ROLE_UNRESOLVED || !isset($line['line_id'])) {
                continue;
            }

            $byUnit[$identities[$key]['item'] . ':' . $identities[$key]['combination']][] = $key;
        }

        $reach = [];

        // What the restoring document's standing restores, and the free receipts, still
        // cover, per order line and identity ({@see self::documentRestored()},
        // {@see self::receiptsUnmatched()}).
        $documentCovered = [];
        $receipts = [];

        foreach ($byUnit as $unitKey => $keys) {
            $unit = ['item' => $identities[$keys[0]]['item'], 'combination' => $identities[$keys[0]]['combination']];
            $lineIds = array_values(array_unique(array_map(static fn (int|string $key): int => (int) $lines[$key]['line_id'], $keys)));

            // Every location these lines ever moved stock at on the unit.
            $this->db->setQuery(
                query: 'SELECT DISTINCT ' . $this->db->quoteName(name: 'location_id')
                . ' FROM ' . $this->db->quoteName(name: '#__alfa_inventory_movements')
                . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . (int) $unit['item']
                . ' AND ' . $this->db->quoteName(name: 'combination_id') . ' = ' . (int) $unit['combination']
                . ' AND ' . $this->db->quoteName(name: 'source_line_id') . ' IN (' . implode(',', $lineIds) . ')'
                . ' AND ' . $this->db->quoteName(name: 'movement_type') . ' IN (' . $this->db->quote(text: self::MOVE_DEDUCT) . ', ' . $this->db->quote(text: self::MOVE_RESTORE) . ')',
            );
            $moved = array_map('intval', $this->db->loadColumn() ?: []);

            if (array_diff($moved, array_keys($this->unitRecordNames(unit: $unit))) === []) {
                continue;
            }

            // What the command will have written for each order line on the unit:
            // its restore movements in all, per location, and its takings of
            // document claims per "source:location".
            $written = [];

            foreach ($keys as $key) {
                $line = $lines[$key];
                $lineId = (int) $line['line_id'];
                $own = $written[$lineId] ?? ['restore' => 0.0, 'at' => [], 'claims' => []];

                $deducted = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_DEDUCT, current: true);
                $restored = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_RESTORE, current: true) + $own['restore'];
                $requested = isset($line['quantity']) ? (float) $line['quantity'] : max(0.0, $deducted - $restored);

                if ($requested > self::EPS && $sourceType === self::SOURCE_RETURN) {
                    $remaining = $requested;
                    $superseded = 0.0;

                    foreach ($this->restoreClaims(lineId: $lineId, unit: $unit, source: self::SOURCE_DOCUMENT, written: $own['claims'], current: true) as $claim) {
                        if ($remaining <= self::EPS) {
                            break;
                        }

                        $take = min($remaining, $claim['net']);

                        if ($take <= self::EPS) {
                            continue;
                        }

                        $reach[$unitKey][$claim['location']] ??= $lineId;

                        $stored = self::asStored(value: -$take);
                        $claimKey = $claim['source'] . ':' . $claim['location'];
                        $own['claims'][$claimKey] = ($own['claims'][$claimKey] ?? 0.0) + $stored;
                        $own['at'][$claim['location']] = ($own['at'][$claim['location']] ?? 0.0) + $stored;
                        $own['restore'] += $stored;
                        $remaining -= $take;
                        $superseded += $take;
                    }

                    if ($superseded > self::EPS) {
                        $restored = $this->lineLedger(lineId: $lineId, unit: $unit, movementType: self::MOVE_RESTORE, current: true) + $own['restore'];
                    }
                } elseif ($requested > self::EPS && $sourceType === self::SOURCE_DOCUMENT) {
                    $requested -= $this->documentRestored(
                        lineId: $lineId,
                        unit: $unit,
                        sourceId: $sourceId,
                        requested: $requested,
                        covered: $documentCovered,
                        current: true,
                    );

                    $receiptKey = $lineId . ':' . $unit['item'] . ':' . $unit['combination'];
                    $receipts[$receiptKey] ??= $this->receiptsUnmatched(lineId: $lineId, unit: $unit, current: true);
                    $absorbed = min($requested, $receipts[$receiptKey]['available']);
                    $receipts[$receiptKey]['available'] -= $absorbed;
                    $requested -= $absorbed;
                }

                $effective = min($requested, max(0.0, $deducted - $restored));
                $remaining = $requested > self::EPS ? $effective : 0.0;

                foreach ($remaining > self::EPS ? $this->lineDeductionsByLocation(lineId: $lineId, unit: $unit, written: $own['at'], current: true) : [] as $locationId => $net) {
                    if ($remaining <= self::EPS) {
                        break;
                    }

                    $give = min($remaining, $net);

                    if ($give <= self::EPS) {
                        continue;
                    }

                    $reach[$unitKey][$locationId] ??= $lineId;

                    $stored = self::asStored(value: $give);
                    $own['at'][$locationId] = ($own['at'][$locationId] ?? 0.0) + $stored;
                    $own['restore'] += $stored;
                    $remaining -= $give;
                }

                $written[$lineId] = $own;
            }
        }

        return $reach;
    }

    /**
     * A quantity as a movement stores it: the FLOAT column keeps single precision
     * of the decimal text the driver sends.
     *
     * @param float $value The quantity.
     *
     *
     * @since  2.0.0
     */
    private static function asStored(float $value): float
    {
        return (float) unpack('g', pack('g', (float) str_replace(',', '.', (string) $value)))[1];
    }

    /**
     * The claims one domain's restores make on an order line (a document's, which
     * a return takes over), oldest source first: per source row and location, its
     * net restore on the line's identity, where it is positive. With $written
     * (what the command has taken of them already, by "source:location", as
     * stored), that is counted too.
     *
     * @param int $lineId The order line.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param string $source The claiming domain.
     * @param array<string, float> $written Takings the command has written, not yet in the table.
     * @param bool $current Read the latest committed state.
     *
     * @return list<array{source: int, location: int, net: float}>
     *
     * @since  2.0.0
     */
    private function restoreClaims(int $lineId, array $unit, string $source, array $written = [], bool $current = false): array
    {
        $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('source_id') . ', ' . $this->db->quoteName('location_id')
            . ', COALESCE(SUM(' . $this->db->quoteName('qty_change') . '), 0) AS ' . $this->db->quoteName('net')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_movements')
            . ' WHERE ' . $this->db->quoteName('source_line_id') . ' = ' . $lineId
            . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . $unit['combination']
            . ' AND ' . $this->db->quoteName('movement_type') . ' = ' . $this->db->quote(self::MOVE_RESTORE)
            . ' AND ' . $this->db->quoteName('source_type') . ' = ' . $this->db->quote($source)
            . ' GROUP BY ' . $this->db->quoteName('source_id') . ', ' . $this->db->quoteName('location_id')
            . ($written === [] ? ' HAVING ' . $this->db->quoteName('net') . ' > ' . self::EPS : '')
            . ' ORDER BY ' . $this->db->quoteName('source_id') . ' ASC, ' . $this->db->quoteName('location_id') . ' ASC'
            . ($current ? ' LOCK IN SHARE MODE' : ''),
        );

        $claims = [];

        foreach ($this->db->loadObjectList() ?: [] as $row) {
            $net = (float) $row->net + ($written[(int) $row->source_id . ':' . (int) $row->location_id] ?? 0.0);

            if ($written !== [] && $net <= self::EPS) {
                continue;
            }

            $claims[] = ['source' => (int) $row->source_id, 'location' => (int) $row->location_id, 'net' => $net];
        }

        return $claims;
    }

    /**
     * READ-ONLY dry run of restore()'s pre-flight: one problem per line whose
     * identity is gone with no recorded resolution while restore() would still
     * bring quantity back to it, so a credit document is refused before its number
     * burns. A line acting on an identity kept for order history (an archived
     * combination, the bare item of a product with combinations, a recorded
     * resolution) restores and raises no problem.
     *
     * @param string $sourceType The restoring domain.
     * @param array $lines Arrays: item_id, combination_id, quantity, line_id.
     *
     * @return string[] Human-readable problems; empty = would apply.
     *
     * @since  2.0.0
     */
    public function validateRestore(string $sourceType, array $lines): array
    {
        $problems = [];

        foreach ($this->actingIdentities(lines: $lines, lock: false, lockIdentity: false) as $key => $identity) {
            if ($identity['role'] !== self::ROLE_UNRESOLVED) {
                continue;
            }

            $line = $lines[$key];
            $unit = ['item' => $identity['item'], 'combination' => $identity['combination']];

            if ($this->planRestoreLine(sourceType: $sourceType, line: $line, unit: $unit) > self::EPS) {
                $problems[] = $this->unresolvedProblem(
                    lineId: isset($line['line_id']) ? (int) $line['line_id'] : null,
                    unit: $identity['frozen'],
                );
            }
        }

        return $problems;
    }

    /**
     * Reserve-time allocation: the question the core asks, never logic
     * it hardcodes. The collected provider chain answers in priority
     * order; the first non-null answer wins. No provider (or no valid
     * answer) falls back to the shipped default: {@see self::defaultAllocation()},
     * which draws from the locations that actually hold the goods.
     *
     * An answer must be locationId => positive quantity summing to the
     * asked quantity; anything else is discarded with a warning, never
     * trusted.
     *
     * @return array<int, float> locationId => quantity
     *
     * @since  2.0.0
     */
    private function allocate(array $unit, float $quantity): array
    {
        foreach ($this->allocationProviders() as $entry) {
            try {
                $answer = $entry['provider']->allocate($unit, $quantity, new InventoryContext());
            } catch (AllocationRefusedException $e) {
                // A deliberate refusal is a business rule, not a failure:
                // it propagates; the default location never silently
                // substitutes for "not permitted".
                throw $e;
            } catch (Throwable $e) {
                // A provider's failure falls back to the next one, unless it ended
                // (or may have ended) the transaction the command runs in.
                if ($this->endsCommand(failure: $e)) {
                    throw $e;
                }

                Log::add(
                    entry:    "Inventory allocation provider '{$entry['element']}' failed: " . $e->getMessage(),
                    priority: Log::WARNING,
                    category: 'com_alfa.inventory',
                );
                continue;
            }

            if ($answer === null) {
                continue; // Passes; next provider in the chain.
            }

            $clean = [];
            $sum = 0.0;

            foreach ($answer as $locationId => $qty) {
                // A provider answers with shelves, and the state contract binds
                // it exactly as it binds the shipped rule: an answer naming a
                // disabled location is dropped, the split then fails to
                // reconcile, and the chain falls through to defaultAllocation()
                // rather than quietly reserving where the shop cannot source.
                if (
                    (int) $locationId > 0 && (float) $qty > self::EPS
                    && LocationPolicy::isEnabled(db: $this->db, locationId: (int) $locationId, lockInShareMode: false)
                ) {
                    $clean[(int) $locationId] = (float) $qty;
                    $sum += (float) $qty;
                }
            }

            if ($clean !== [] && abs($sum - $quantity) <= self::EPS) {
                return $clean;
            }

            Log::add(
                entry:    "Inventory allocation provider '{$entry['element']}' returned an invalid split (asked {$quantity}, answered {$sum}), skipped.",
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }

        return $this->defaultAllocation(unit: $unit, quantity: $quantity);
    }

    /**
     * The ENABLED locations that actually hold this unit, best stocked first,
     * with the default location promoted to the front at equal footing.
     *
     * The ordering is the shop's sourcing preference, and it lives here because
     * TWO paths need exactly the same answer: {@see self::defaultAllocation()}
     * when it reserves, and {@see self::lineLocations()} when it fulfils a line
     * that never had a reservation. Those two disagreeing IS the LOC-2 defect:
     * the reserve side spread across the shelves holding the goods while the
     * fulfil side went to the default location alone, so the agreement is made
     * structural rather than restated in two queries that can drift apart.
     *
     * With $lock the rows are the unit's records as locked
     * ({@see self::lockUnitRecords()}): the figures are the latest committed ones
     * and the records stay locked until the caller's transaction ends. The reserve
     * side asks for it, so a reservation is placed only on stock that is free now;
     * the fulfil side reads plainly and judges each record again when it locks it.
     *
     * The order is decided here and nowhere else, and it is total: the default
     * location, then the most free stock (two figures within the quantity
     * tolerance of each other are equal), then the shop's own order of its
     * locations ({@see LocationPolicy::enabledIds()}: the ordering the merchant
     * set, then the id). The same figures therefore always give the same order.
     * The dry run of a fulfilment
     * relies on that: it passes the figures its simulation holds for the unit's
     * records ($figures) and walks the shelves in the order the command will find
     * once it has written them.
     *
     * @param array{item: int, combination: int} $unit Resolved inventory unit.
     * @param bool $lock Lock the unit's records and read their
     *                   current figures (inside a transaction),
     *                   or read them plainly.
     * @param array<int, array{quantity: float, reserved: float}> $figures Figures that stand in place of the
     *                                                                     stored ones, by location id; empty
     *                                                                     for the records as stored.
     *
     * @return object[] Stock rows: location_id, quantity, reserved_quantity.
     *
     * @since  2.0.0
     */
    private function stockBearingEnabledLocations(array $unit, bool $lock, array $figures): array
    {
        $default = $this->defaultLocationId();

        if ($lock) {
            $rows = array_values(array_filter(
                $this->lockUnitRecords(unit: $unit),
                static fn (object $record): bool => (int) $record->enabled === 1,
            ));
        } else {
            $this->db->setQuery(
                query: 'SELECT ' . implode(', ', $this->db->quoteName(name: ['location_id', 'quantity', 'reserved_quantity']))
                . ' FROM ' . $this->db->quoteName(name: '#__alfa_inventory_stock')
                . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . (int) $unit['item']
                . ' AND ' . $this->db->quoteName(name: 'combination_id') . ' = ' . (int) $unit['combination']
                . ' AND ' . LocationPolicy::enabledPredicate(db: $this->db, column: $this->db->quoteName(name: 'location_id')),
            );
            $rows = $this->db->loadObjectList() ?: [];
        }

        foreach ($rows as $row) {
            $held = $figures[(int) $row->location_id] ?? null;

            if ($held !== null) {
                $row->quantity = $held['quantity'];
                $row->reserved_quantity = $held['reserved'];
            }
        }

        // The shop's own order of its locations: the default, then the ordering the
        // merchant set, then the id. A location missing from it sorts last, by id.
        $rank = array_flip(array: LocationPolicy::enabledIds(db: $this->db));
        $shopOrder = static fn (object $row): array => [$rank[(int) $row->location_id] ?? PHP_INT_MAX, (int) $row->location_id];

        usort(array: $rows, callback: static fn (object $a, object $b): int => $shopOrder(row: $a) <=> $shopOrder(row: $b));

        // Free stock is compared exactly, within the quantity tolerance, so the
        // noise of a single-precision column never decides the order.
        $free = static fn (object $row): float => (float) $row->quantity - (float) $row->reserved_quantity;

        // Default location first (the shop's own preference), then whatever is
        // best stocked; equally stocked locations keep the shop's order (the sort
        // is stable and starts from it).
        usort(array: $rows, callback: static function (object $a, object $b) use ($default, $free): int {
            $aDefault = (int) $a->location_id === $default ? 1 : 0;
            $bDefault = (int) $b->location_id === $default ? 1 : 0;

            if ($aDefault !== $bDefault) {
                return $bDefault <=> $aDefault;
            }

            $difference = $free(row: $b) - $free(row: $a);

            return abs(num: $difference) <= self::TOL ? 0 : ($difference > 0 ? 1 : -1);
        });

        return $rows;
    }

    /**
     * The shipped allocation rule, used whenever no provider answers: draw from
     * the locations that actually hold the goods, default location first, then
     * the best-stocked; any remainder falls to the default location.
     *
     * Only ENABLED locations are drawn from (a disabled shelf takes no new
     * allocation). The remainder still lands on the default location, which is
     * the one location that cannot be disabled ({@see \Alfa\Component\Alfa\Administrator\Model\LocationModel::save()}
     * refuses to unpublish it), so a backorder always has somewhere visible to go.
     *
     * It has to spread. Sending the whole line to one shelf contradicted the
     * policy gate, which counts every enabled location: a two-warehouse shop passed
     * {@see self::guardUnitBackorder()} and then wrote a reservation the default
     * location could not source, leaving one row deeply negative while another
     * sat untouched. The remainder deliberately lands on the default location:
     * a genuine backorder should show up where the shop restocks.
     *
     * The records are read under the lock the policy gate took on them
     * ({@see self::guardUnitBackorder()}), so what counts as free here is free now.
     *
     * @param array{item: int, combination: int} $unit Resolved inventory unit.
     * @param float $quantity Quantity to place.
     *
     * @return array<int, float> locationId => quantity
     *
     * @since  2.0.0
     */
    private function defaultAllocation(array $unit, float $quantity): array
    {
        $default = $this->defaultLocationId();
        $rows = $this->stockBearingEnabledLocations(unit: $unit, lock: true, figures: []);

        $split = [];
        $remaining = $quantity;

        foreach ($rows as $row) {
            if ($remaining <= self::EPS) {
                break;
            }

            $free = (float) $row->quantity - (float) $row->reserved_quantity;

            if ($free <= self::EPS) {
                continue;
            }

            $take = min($free, $remaining);
            $split[(int) $row->location_id] = $take;
            $remaining -= $take;
        }

        // Nothing stocked anywhere, or a backordered remainder: the default
        // location carries it, so the shortfall is visible where restocking happens.
        if ($remaining > self::EPS || $split === []) {
            $split[$default] = ($split[$default] ?? 0.0) + $remaining;
        }

        return $split;
    }

    /**
     * The collected allocation provider chain, priority-ordered
     * (highest first), memoised per request.
     *
     * @return array<int, array{provider: object, element: string, priority: int}>
     *
     * @since  2.0.0
     */
    private function allocationProviders(): array
    {
        if ($this->allocationChain !== null) {
            return $this->allocationChain;
        }

        $event = new \Alfa\Component\Alfa\Administrator\Event\Inventory\CollectStockAllocationProvidersEvent(
            'onCollectStockAllocationProviders',
            ['providers' => []],
        );

        // Boot-and-call over the `alfa-inventory` CAPABILITY group: an allocation strategy
        // is live checkout-path domain policy, so it ships as a capability provider (like a
        // search engine or payment method), woken only here, never heard by anything else.
        // The `alfa` platform group is deliberately NOT asked: shared-backend plugins have no
        // business on the reserve path. (This retired the last app-dispatcher collect route,
        // together with DocumentTypeRegistry::syncProviders().)
        try {
            foreach (PluginHelper::getPlugin('alfa-inventory') as $descriptor) {
                try {
                    $plugin = Factory::getApplication()->bootPlugin($descriptor->name, 'alfa-inventory');

                    if (method_exists($plugin, 'onCollectStockAllocationProviders')) {
                        $plugin->onCollectStockAllocationProviders($event);
                    }
                } catch (Throwable $e) {
                    Log::add(
                        entry:    "InventoryService: allocation provider plugin '{$descriptor->name}' failed to boot and was skipped: " . $e->getMessage(),
                        priority: Log::ERROR,
                        category: 'com_alfa.inventory',
                    );
                }
            }
        } catch (Throwable) {
            // No plugin list (install, CLI harness) simply means no contributed strategies;
            // defaultAllocation() remains in charge.
        }

        $chain = $event->getProviders();
        usort($chain, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);

        return $this->allocationChain = $chain;
    }

    /**
     * Boot the package plugin group and dispatch an inventory event.
     *
     * BEFORE events veto by throwing: those exceptions PROPAGATE (they
     * are the contract). Only the plugin-group import is shielded: in
     * session-less contexts (CLI harnesses) the import cannot run and
     * the event system is simply inert there; the web path is the real
     * path.
     *
     * @param AbstractEvent $event The event to dispatch.
     *
     *
     * @since  2.0.0
     */
    private function dispatch(AbstractEvent $event): void
    {
        try {
            PluginHelper::importPlugin('alfa');
            $dispatcher = Factory::getApplication()->getDispatcher();
        } catch (Throwable) {
            return;
        }

        $dispatcher->dispatch($event->getName(), $event);
    }

    /**
     * Announce what a command did, once it stands.
     *
     * An AFTER event tells listeners about written stock, which no rollback can
     * take back from them. Run inside a caller's transaction, the command's own
     * commit only releases a savepoint, and the caller can still roll the work
     * back. So the event waits for the outermost commit and is delivered once
     * after it, or never when that transaction rolls back
     * ({@see PostCommit::defer()}); with no transaction open it is delivered at
     * once. A listener's failure is logged and never reaches the command's
     * caller: the work it follows is already committed.
     *
     * @param string $label What is announced, as a failure is logged.
     * @param AbstractEvent $event The AFTER event.
     *
     *
     * @since  2.0.0
     */
    private function announce(string $label, AbstractEvent $event): void
    {
        PostCommit::defer(db: $this->db, label: $label, work: fn () => $this->dispatch($event));
    }

    /**
     * Write a command's success line once the command stands, on the terms of
     * {@see self::announce()}: a command whose transaction rolls back writes no
     * line saying it was done.
     *
     * @param string $label What is logged, as a failure is logged.
     * @param string $entry The line.
     * @param int $priority Its priority.
     *
     *
     * @since  2.0.0
     */
    private function logOnceStands(string $label, string $entry, int $priority): void
    {
        PostCommit::defer(
            db: $this->db,
            label: $label,
            work: static fn () => Log::add(entry: $entry, priority: $priority, category: 'com_alfa.inventory'),
        );
    }

    /**
     * Lock the stock record of a unit at a location, creating it when missing,
     * and check under lock that the unit still exists.
     *
     * Every mutating path passes through this lock: the record is the
     * serialisation anchor of the unit/location pair.
     *
     * Creating a record is where a unit whose figures claim stock without any
     * record would silently become a zero-stock unit, so the create branch first
     * judges that state. With $refuseLedgerDefect the command is refused
     * ({@see self::assertLedgerBacked()}); without it (restore, the claim hand-over
     * and the returns write-off, all anchored to the order's own history) the
     * state is logged and notified and the new record starts at zero: the stale
     * figure is never adopted, and the figures it showed are returned on the
     * record as `began_on_defect` (null otherwise) so the command holds the unit
     * ({@see self::beginIntegrityHold()}) in its own transaction.
     *
     * A record the command already created where it took the unit's records
     * ({@see self::takeUnitRecords()}) is only reached here: it carries what that
     * creation found, as `began_on_defect`, exactly as if it were created here.
     *
     * After the record is locked or created, {@see self::assertUnitIdentity()}
     * re-checks the identity under the combination/item row locks, as a current
     * unit for the commands that take in stock or claims, as a still-existing
     * identity for fulfilment and reversal ($historical), and the effective
     * tracking read there is returned on the record as `unit_tracked`, so a
     * command that needs a tracked unit sees a switch-off that committed while it
     * waited. A refusing command ($refuseLedgerDefect) then refuses a held unit
     * ({@see self::assertNotHeld()}): its records exist, but it stays in the
     * missing-records state until the audited repair.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item unit.
     * @param int $locationId The location.
     * @param bool $refuseLedgerDefect Refuse (true), or log and proceed (false), on a
     *                                 unit whose figures claim stock without records.
     * @param bool $historical Fulfilment or reversal: any identity that still exists
     *                         (false: only a current unit).
     * @param string $command The calling command, for the log.
     * @param int|null $lineId The order line, for the log.
     * @param array $created The records the command created where it took its units'
     *                       records ({@see self::takeUnitRecords()}); the one reached
     *                       here is marked as reached.
     *
     * @return object The locked record (all columns) plus `unit_tracked` (bool) and `began_on_defect`
     *                (array{stock: ?float, available: ?float}|null).
     *
     * @throws InventoryIntegrityException When refused on such a unit, or on a held unit.
     * @throws InventoryUnitException When the identity is gone, or not current where a current unit is needed.
     *
     * @since  2.0.0
     */
    private function lockStockRow(
        int $itemId,
        int $combinationId,
        int $locationId,
        bool $refuseLedgerDefect,
        bool $historical,
        string $command,
        ?int $lineId,
        array &$created = [],
    ): object {
        // FAIL-CLOSED ON THE PHANTOM LOCATION. A caller reaching here with
        // 0 (an unset id, a cast of null, a location deleted underneath a
        // request) would otherwise have stock INVENTED at location 0: a record
        // belonging to no shelf, counted by every aggregate, reachable by no
        // admin screen.
        if ($locationId <= 0) {
            throw new RuntimeException(sprintf(
                'InventoryService: %d is not a location; stock cannot be held there.',
                $locationId,
            ));
        }

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__alfa_inventory_stock'))
            ->where($this->db->quoteName('item_id') . ' = ' . $itemId)
            ->where($this->db->quoteName('combination_id') . ' = ' . $combinationId)
            ->where($this->db->quoteName('location_id') . ' = ' . $locationId);

        // LOCK FIRST, CREATE ONLY IF ABSENT. An unconditional INSERT IGNORE
        // takes a SHARED lock on the duplicate it declines to insert, so two
        // sessions receiving the same unit each held S and then each asked for
        // X, and InnoDB killed one of them. The existing record is the common
        // case and must not pay; a concurrent creator is not an error: whoever
        // lost the insert re-reads the record the winner made.
        $row = $this->db->setQuery((string) $query . ' FOR UPDATE')->loadObject();
        $unit = ['item' => $itemId, 'combination' => $combinationId];
        $beganOnDefect = null;

        if ($row === null) {
            if ($refuseLedgerDefect) {
                $this->assertLedgerBacked(unit: $unit, command: $command, lineId: $lineId);
            } elseif (($defect = $this->ledgerDefect(unit: $unit)) !== null) {
                $this->logMissingLedger(
                    unit: $unit,
                    command: $command,
                    lineId: $lineId,
                    stock: $defect['stock'],
                    available: $defect['available'],
                );
                InventoryIntegrityNotice::queue(itemId: $itemId, kind: InventoryIntegrityNotice::KIND_RESTORED_ON_DEFECT);
                $beganOnDefect = ['stock' => $defect['stock'], 'available' => $defect['available']];
            }

            $row = $this->insertStockRecord(itemId: $itemId, combinationId: $combinationId, locationId: $locationId);
        } elseif (isset($created['records'][$itemId . ':' . $combinationId . ':' . $locationId])) {
            // Created by this command where it took the unit's records: what the
            // creation found there is what it would have found here.
            $created['records'][$itemId . ':' . $combinationId . ':' . $locationId] = true;
            $beganOnDefect = $created['figures'][$itemId . ':' . $combinationId] ?? null;
        }

        $row->unit_tracked = $this->assertUnitIdentity(itemId: $itemId, combinationId: $combinationId, historical: $historical);

        if ($refuseLedgerDefect) {
            $this->assertNotHeld(unit: $unit, stockId: (int) $row->id, command: $command, lineId: $lineId);
        } elseif ($beganOnDefect === null && $this->integrityHeld(unit: $unit, lock: false)) {
            // A return onto a unit already held proceeds, and says so.
            $this->logHeld(unit: $unit, command: $command, lineId: $lineId, refused: false);
        }

        $row->began_on_defect = $beganOnDefect;

        return $row;
    }

    /**
     * Create a unit's stock record at a location, at zero, and lock it: the one
     * statement that writes a new stock record. The caller has judged the unit
     * first ({@see self::lockStockRow()}, {@see self::takeUnitRecords()}).
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item unit.
     * @param int $locationId The location.
     *
     * @return object The locked record (all columns).
     *
     * @throws RuntimeException When the record can be neither created nor locked.
     *
     * @since  2.0.0
     */
    private function insertStockRecord(int $itemId, int $combinationId, int $locationId): object
    {
        try {
            $this->db->setQuery(sprintf(
                'INSERT INTO %s (item_id, combination_id, location_id, quantity, reserved_quantity, created)'
                . ' VALUES (%d, %d, %d, 0, 0, NOW())',
                $this->db->quoteName('#__alfa_inventory_stock'),
                $itemId,
                $combinationId,
                $locationId,
            ))->execute();
        } catch (Throwable $e) {
            // 1062 duplicate key: a concurrent creator won, and its record is
            // the one we want, so it is answered by looking again. Nothing
            // else is. A deadlock least of all: two sessions both found the
            // record missing and both tried to insert it, and the database
            // has by now rolled the loser's whole transaction back, the
            // caller's part included. Looking again would write the rest of
            // the command outside any transaction, for a caller whose own
            // rows no longer exist.
            if (MysqlError::number(error: $e) !== 1062) {
                throw $e;
            }
        }

        $row = $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . $combinationId
            . ' AND ' . $this->db->quoteName('location_id') . ' = ' . $locationId
            . ' FOR UPDATE',
        )->loadObject();

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'InventoryService: could not create or lock the stock row for item %d, combination %d, location %d.',
                $itemId,
                $combinationId,
                $locationId,
            ));
        }

        return $row;
    }

    /**
     * Re-check under lock that an identity still exists (and, for the commands
     * that take in stock or claims, that it is a current unit), and read its
     * effective tracking there.
     *
     * A combination locks its combination row with the item row (FOR UPDATE): it
     * must still exist for that item, and outside $historical neither it nor its
     * product may be archived. An item identity locks the item row; outside
     * $historical the product must not be archived, and it then reads the item's
     * current combinations with a shared lock, taken after the stock record, so a
     * concurrent combination generation or reactivation either commits first and is
     * seen here, or waits until this command commits. In $historical mode the item
     * identity exists whatever combinations or catalogue state it has.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item unit.
     * @param bool $historical Fulfilment or reversal: any identity that still exists.
     *
     * @return bool The identity's effective tracking under the lock (its own flag, or the item's).
     *
     * @throws InventoryUnitException When the identity is gone, or not current where a current unit is needed.
     *
     * @since  2.0.0
     */
    private function assertUnitIdentity(int $itemId, int $combinationId, bool $historical): bool
    {
        if ($combinationId > 0) {
            $this->db->setQuery(
                'SELECT COALESCE(c.' . $this->db->quoteName('state') . ', ' . CombinationState::UNPUBLISHED . ') AS ' . $this->db->quoteName('state')
                . ', i.' . $this->db->quoteName('state') . ' AS ' . $this->db->quoteName('item_state')
                . ', COALESCE(c.' . $this->db->quoteName('manage_stock') . ', i.' . $this->db->quoteName('manage_stock') . ', 0) AS ' . $this->db->quoteName('tracked')
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . $combinationId
                . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' FOR UPDATE',
            );
            $combination = $this->db->loadObject();

            if ($combination === null) {
                throw new InventoryUnitException(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    reason: $this->itemExists(itemId: $itemId)
                        ? InventoryUnitException::COMBINATION_GONE
                        : InventoryUnitException::ITEM_GONE,
                );
            }

            if (!$historical && !CombinationState::isCurrent(state: $combination->item_state)) {
                throw new InventoryUnitException(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    reason: InventoryUnitException::ITEM_ARCHIVED,
                );
            }

            if (!$historical && !CombinationState::isCurrent(state: $combination->state)) {
                throw new InventoryUnitException(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    reason: InventoryUnitException::COMBINATION_ARCHIVED,
                );
            }

            return (int) $combination->tracked === 1;
        }

        $this->db->setQuery(
            'SELECT COALESCE(' . $this->db->quoteName('manage_stock') . ', 0) AS ' . $this->db->quoteName('tracked')
            . ', ' . $this->db->quoteName('state')
            . ' FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId . ' FOR UPDATE',
        );
        $item = $this->db->loadObject();

        if ($item === null) {
            throw new InventoryUnitException(
                itemId: $itemId,
                combinationId: 0,
                reason: InventoryUnitException::ITEM_GONE,
            );
        }

        $tracked = $item->tracked;

        if ($historical) {
            return (int) $tracked === 1;
        }

        if (!CombinationState::isCurrent(state: $item->state)) {
            throw new InventoryUnitException(
                itemId: $itemId,
                combinationId: 0,
                reason: InventoryUnitException::ITEM_ARCHIVED,
            );
        }

        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_item_combinations')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName('state'))
            . ' LOCK IN SHARE MODE',
        );

        if ((int) $this->db->loadResult() > 0) {
            throw new InventoryUnitException(
                itemId: $itemId,
                combinationId: 0,
                reason: InventoryUnitException::ITEM_BEARS_COMBINATIONS,
            );
        }

        return (int) $tracked === 1;
    }

    /**
     * Lock a unit's stock record at a location only if it exists: the lock of
     * the commands that reduce an existing claim (release, releaseLine, expiry),
     * which never create a record and never refuse.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item unit.
     * @param int $locationId The location.
     *
     * @return object|null The locked record, or null when there is none.
     *
     * @since  2.0.0
     */
    private function lockExistingStockRow(int $itemId, int $combinationId, int $locationId): ?object
    {
        if ($locationId <= 0) {
            return null;
        }

        $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . $combinationId
            . ' AND ' . $this->db->quoteName('location_id') . ' = ' . $locationId
            . ' FOR UPDATE',
        );

        return $this->db->loadObject() ?: null;
    }

    /**
     * Take a released remainder off the stock record's reserved quantity.
     *
     * A reservation whose unit has no stock record at its location (a record
     * dropped or never written) is released with no stock change and a warning:
     * letting go of a claim never creates stock and never fails.
     *
     * @param object|null $stock The locked record, or null.
     * @param object $reservation The reservation being released.
     * @param float $quantity The remainder released.
     *
     *
     * @since  2.0.0
     */
    private function releaseReservedOn(?object $stock, object $reservation, float $quantity): void
    {
        if ($quantity <= self::EPS) {
            return;
        }

        if ($stock === null) {
            Log::add(
                entry: sprintf(
                    'Inventory: reservation #%d released %.4f of item #%d%s at location #%d, which has no stock record; no stock changed.',
                    (int) $reservation->id,
                    $quantity,
                    (int) $reservation->item_id,
                    (int) $reservation->combination_id > 0 ? ' combination #' . (int) $reservation->combination_id : '',
                    (int) $reservation->location_id,
                ),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );

            return;
        }

        $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: 0, reservedDelta: -$quantity);
    }

    /**
     * Apply quantity/reserved deltas to a locked stock record and carry them
     * into the derived figures.
     *
     * The reserved delta applied is the EFFECTIVE one: a release larger than the
     * record's reserved quantity is clamped at it (with a warning), so the
     * derived salable figure moves by exactly what the record moved. The unit's
     * salable delta is zero at a location that is not enabled; the parent's
     * stock figure moves only for a current (not archived) combination, and its
     * available figure only for a combination that counts for it (published and
     * tracked).
     *
     * @param int $stockId The locked record.
     * @param float $quantityDelta Physical delta.
     * @param float $reservedDelta Reserved delta (negative = release).
     *
     *
     * @since  2.0.0
     */
    private function applyStockDelta(int $stockId, float $quantityDelta, float $reservedDelta): void
    {
        $facts = $this->stockRowFacts(stockId: $stockId);
        $effectiveReserved = $reservedDelta;

        if ($reservedDelta < 0 && -$reservedDelta > $facts->reserved + self::EPS) {
            $effectiveReserved = -max(0.0, $facts->reserved);

            Log::add(
                entry: sprintf(
                    'Inventory: stock record #%d released %.4f reserved but held %.4f; the release was clamped.',
                    $stockId,
                    -$reservedDelta,
                    $facts->reserved,
                ),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }

        $sets = [$this->db->quoteName('modified') . ' = NOW()'];

        if (abs($quantityDelta) > self::EPS) {
            $sets[] = $this->db->quoteName('quantity') . ' = ' . $this->db->quoteName('quantity') . ' + ' . self::sqlNumber(value: $quantityDelta);
        }

        if (abs($effectiveReserved) > self::EPS) {
            $sets[] = $this->db->quoteName('reserved_quantity') . ' = GREATEST(0, ' . $this->db->quoteName('reserved_quantity')
                . ' + ' . self::sqlNumber(value: $effectiveReserved) . ')';
        }

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->update($this->db->quoteName('#__alfa_inventory_stock'))
                ->set($sets)
                ->where($this->db->quoteName('id') . ' = ' . $stockId),
        )->execute();

        $unitSalableDelta = $facts->locationEnabled ? $quantityDelta - $effectiveReserved : 0.0;

        $this->propagateToReadModels(
            stockId: $stockId,
            unitPhysicalDelta: $quantityDelta,
            unitSalableDelta: $unitSalableDelta,
            parentPhysicalDelta: $facts->countsInParentPhysical ? $quantityDelta : 0.0,
            parentSalableDelta: $facts->countsForParent ? $unitSalableDelta : 0.0,
            facts: $facts,
        );
    }

    /**
     * What a locked stock record means for the derived figures, read under the
     * command's locks in the kernel's lock order: the record, its location
     * (shared), the combination and item rows (FOR UPDATE), then the item's
     * combination range (shared, item-level records only). No subquery runs
     * inside the writes that use these facts.
     *
     * A combination record counts in its parent's physical figure while the
     * combination is not archived, and in its parent's available figure while it
     * is published and tracked. An item-level record belongs to a figure only
     * while the item has no current combination.
     *
     * @param int $stockId The locked record.
     *
     * @return object itemId, combinationId, locationId, quantity, reserved,
     *                locationEnabled, itemExists, combinationExists, combinationState,
     *                archived, tracked, itemBearsCombinations (current combinations),
     *                countsInParentPhysical, countsForParent.
     *
     * @since  2.0.0
     */
    private function stockRowFacts(int $stockId): object
    {
        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['item_id', 'combination_id', 'location_id', 'quantity', 'reserved_quantity']))
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $stockId . ' FOR UPDATE',
        );
        $row = $this->db->loadObject();

        if ($row === null) {
            throw new RuntimeException(sprintf('InventoryService: stock record #%d does not exist.', $stockId));
        }

        $facts = (object) [
            'itemId' => (int) $row->item_id,
            'combinationId' => (int) $row->combination_id,
            'locationId' => (int) $row->location_id,
            'quantity' => (float) $row->quantity,
            'reserved' => (float) $row->reserved_quantity,
            'locationEnabled' => LocationPolicy::isEnabled(db: $this->db, locationId: (int) $row->location_id, lockInShareMode: true),
            'itemExists' => false,
            'combinationExists' => false,
            'combinationState' => null,
            'archived' => false,
            'tracked' => false,
            'itemBearsCombinations' => false,
            'countsInParentPhysical' => false,
            'countsForParent' => false,
        ];

        if ($facts->combinationId > 0) {
            $this->db->setQuery(
                'SELECT COALESCE(c.' . $this->db->quoteName('state') . ', ' . CombinationState::UNPUBLISHED . ') AS ' . $this->db->quoteName('state')
                . ', COALESCE(c.' . $this->db->quoteName('manage_stock') . ', i.' . $this->db->quoteName('manage_stock') . ', 0) AS ' . $this->db->quoteName('tracked')
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . $facts->combinationId
                . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . $facts->itemId
                . ' FOR UPDATE',
            );
            $combination = $this->db->loadObject();

            if ($combination !== null) {
                $facts->itemExists = true;
                $facts->combinationExists = true;
                $facts->combinationState = (int) $combination->state;
                $facts->archived = !CombinationState::isCurrent(state: $facts->combinationState);
                $facts->tracked = (int) $combination->tracked === 1;
                $facts->itemBearsCombinations = !$facts->archived;
                $facts->countsInParentPhysical = !$facts->archived;
                $facts->countsForParent = $facts->combinationState === CombinationState::PUBLISHED && $facts->tracked;
            }

            return $facts;
        }

        $this->db->setQuery(
            'SELECT COALESCE(' . $this->db->quoteName('manage_stock') . ', 0) FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $facts->itemId . ' FOR UPDATE',
        );
        $tracked = $this->db->loadResult();

        if ($tracked === null) {
            return $facts;
        }

        $facts->itemExists = true;
        $facts->tracked = (int) $tracked === 1;

        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_item_combinations')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $facts->itemId
            . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName('state'))
            . ' LOCK IN SHARE MODE',
        );
        $facts->itemBearsCombinations = (int) $this->db->loadResult() > 0;

        return $facts;
    }

    /**
     * Carry a stock record's deltas into the derived figures, RELATIVELY.
     *
     * Recomputing a figure from an unlocked SUM and storing it as an absolute is
     * a lost update whenever two commands touch different units of one item;
     * folding the SUM into the UPDATE deadlocks instead, because a subquery inside
     * DML takes shared locks on every record it reads. Relative propagation reads
     * no sibling record: each writer touches only the record it holds and the
     * figure rows it changes, so two writers on one item queue on the item row.
     *
     * Routing:
     *   a combination row of the item, in     its row by the unit deltas, and the
     *   any state                             item row by the parent deltas;
     *   a vanished or foreign combination     nothing;
     *   the item unit of an item without      the item row by the unit deltas;
     *   current combinations
     *   the item's own record while it has    nothing (its records belong to no
     *   current combinations                  figure: stranded stock, or the
     *                                         identity of order history).
     * The caller decides the parent deltas: an archived combination moves its own
     * row but never its parent, and only a published tracked one moves the
     * parent's available figure.
     *
     * Drift is detected, never assumed away: reconcile() is the only repair.
     *
     * @param int $stockId The locked record.
     * @param float $unitPhysicalDelta Physical delta of the unit's own figure.
     * @param float $unitSalableDelta Salable delta of the unit's own figure.
     * @param float $parentPhysicalDelta Physical delta of the parent item (combinations only).
     * @param float $parentSalableDelta Salable delta of the parent item (combinations only).
     * @param object|null $facts stockRowFacts() of the record, when already read.
     *
     *
     * @since  2.0.0
     */
    private function propagateToReadModels(
        int $stockId,
        float $unitPhysicalDelta,
        float $unitSalableDelta,
        float $parentPhysicalDelta,
        float $parentSalableDelta,
        ?object $facts = null,
    ): void {
        $facts ??= $this->stockRowFacts(stockId: $stockId);

        $move = function (string $table, int $rowId, float $physical, float $salable): void {
            if (abs($physical) <= self::EPS && abs($salable) <= self::EPS) {
                return;
            }

            $this->db->setQuery(
                'UPDATE ' . $this->db->quoteName($table)
                . ' SET ' . $this->db->quoteName('stock') . ' = COALESCE(' . $this->db->quoteName('stock') . ', 0) + ' . self::sqlNumber(value: $physical)
                . ', ' . $this->db->quoteName('available_quantity') . ' = COALESCE(' . $this->db->quoteName('available_quantity') . ', 0) + ' . self::sqlNumber(value: $salable)
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $rowId,
            )->execute();
        };

        if ($facts->combinationId > 0) {
            if (!$facts->combinationExists) {
                return;
            }

            $move('#__alfa_item_combinations', $facts->combinationId, $unitPhysicalDelta, $unitSalableDelta);
            $move('#__alfa_items', $facts->itemId, $parentPhysicalDelta, $parentSalableDelta);

            return;
        }

        if (!$facts->itemExists || $facts->itemBearsCombinations) {
            return;
        }

        $move('#__alfa_items', $facts->itemId, $unitPhysicalDelta, $unitSalableDelta);
    }

    /**
     * A quantity as an SQL numeric literal, independent of locale and of PHP's
     * float-to-string exponent form.
     *
     * @param float $value The quantity.
     *
     *
     * @since  2.0.0
     */
    private static function sqlNumber(float $value): string
    {
        return sprintf('%.10F', $value);
    }

    /**
     * Append one ledger movement: the ONLY writer of the movements table.
     *
     * @since  2.0.0
     */
    private function appendMovement(
        array $unit,
        int $locationId,
        float $qtyChange,
        string $movementType,
        string $sourceType,
        int $sourceId,
        ?int $sourceLineId,
        string $operationId,
        InventoryContext $context,
        string $counterpartyType = '',
        ?int $counterpartyId = null,
        ?int $transferId = null,
    ): void {
        $row = (object) [
            'item_id' => $unit['item'],
            'combination_id' => $unit['combination'],
            'location_id' => $locationId,
            'qty_change' => $qtyChange,
            'movement_type' => $movementType,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?: null,
            'source_line_id' => $sourceLineId,
            'counterparty_type' => $counterpartyType,
            'counterparty_id' => $counterpartyId,
            'transfer_id' => $transferId,
            'operation_id' => $operationId,
            'correlation_id' => $context->correlationId,
            'note' => $context->note,
            'created_by' => $context->userId,
            'created' => Factory::getDate('now', 'UTC')->toSql(),
        ];

        $this->db->insertObject('#__alfa_inventory_movements', $row, 'id');
    }

    /**
     * Evaluate one unit's low-stock and backorder notices from its salable
     * quantity in the stock records (enabled locations): the notification funnel
     * every quantity-moving command ends in. It reads the records, never the
     * derived figures, and writes no stock. Only a current unit is judged: an
     * identity kept for order history is never offered for sale, so it raises no
     * low-stock or backorder notice.
     *
     * Advisory: a failure here never disturbs the command.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination unit, or 0 for the item unit.
     *
     *
     * @since  2.0.0
     */
    private function evaluateUnitNotices(int $itemId, int $combinationId): void
    {
        try {
            if (!$this->isCurrentIdentity(itemId: $itemId, combinationId: $combinationId)) {
                return;
            }

            $salable = $this->unitLedger(unit: ['item' => $itemId, 'combination' => $combinationId], lock: false)['salable'];
        } catch (Throwable $e) {
            if ($this->endsCommand(failure: $e)) {
                throw $e;
            }

            return;
        }

        $this->evaluateLowStock(itemId: $itemId, combinationId: $combinationId, salable: $salable);
        $this->evaluateBackorderNotice(itemId: $itemId, combinationId: $combinationId, salable: $salable);
    }

    /**
     * Low-stock notice for one inventory unit: when the merchant opted the
     * unit in (low_stock_notify, inheritable from the item on combinations)
     * and its salable quantity has entered the (0, stock_low] band, raise a
     * back-office notification; clear it once the unit recovers or empties.
     *
     * The notification is the first rung of the low-stock ladder: an email
     * to the merchant (and later the supplier) can hang off the same signal.
     * It is advisory: any failure here never disturbs the ledger commit.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination unit, or 0 for the item unit.
     * @param float $salable The unit's fresh salable projection.
     *
     *
     * @since  2.0.0
     */
    private function evaluateLowStock(int $itemId, int $combinationId, float $salable): void
    {
        try {
            // Resolve the effective opt-in and threshold: a combination
            // overrides the item, NULL inherits it.
            if ($combinationId > 0) {
                $combo = $this->db->setQuery(
                    $this->db->getQuery(true)
                        ->select($this->db->quoteName(['low_stock_notify', 'stock_low', 'sku']))
                        ->from($this->db->quoteName('#__alfa_item_combinations'))
                        ->where($this->db->quoteName('id') . ' = ' . $combinationId),
                )->loadObject();
            } else {
                $combo = null;
            }

            $item = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName(['low_stock_notify', 'stock_low']))
                    ->from($this->db->quoteName('#__alfa_items'))
                    ->where($this->db->quoteName('id') . ' = ' . $itemId),
            )->loadObject();

            if ($item === null) {
                return;
            }

            $notify = ($combo !== null && $combo->low_stock_notify !== null)
                ? (int) $combo->low_stock_notify
                : (int) $item->low_stock_notify;

            // THE SHOP-WIDE THRESHOLD IS THE LAST STEP OF THE CHAIN, NOT A
            // MISSING ONE.
            //
            // `stock_low` carries useglobal="true" on the item form, and
            // ItemModel::applyLowStockVisibility() states the contract in as
            // many words: "an empty item value reads 'inherit the shop-wide
            // threshold', NOT 'this product has none'. The effective threshold
            // is a server-side fact."
            //
            // This resolved `combination ?? item ?? 0` and stopped there, so a
            // product left on Use Global produced a threshold of 0, and the
            // band below requires a POSITIVE one. The merchant configured a
            // shop-wide threshold, the form told them it was active on every
            // product inheriting it, and the notification could never fire for
            // any of them.
            //
            // ItemModel::save() normalises an empty or non-positive item value
            // to NULL, so NULL really is the only spelling of "not set" and
            // `!== null` is the whole test.
            $threshold = InventoryAvailabilityService::lowStockThreshold(
                combination: $combo !== null && $combo->stock_low !== null ? (float) $combo->stock_low : null,
                item: $item->stock_low !== null ? (float) $item->stock_low : null,
            );

            $dedupKey = self::lowStockKey(itemId: $itemId, combinationId: $combinationId);

            // In the low band = opted in, a positive threshold, and salable
            // sitting in (0, threshold]. Anything else clears the notice.
            if ($notify === 1 && $threshold > 0 && $salable > 0 && $salable <= $threshold) {
                $label = $this->unitLabel(itemId: $itemId, sku: $combinationId > 0 ? ($combo->sku ?? null) : null);

                NotificationHelper::push(
                    dedupKey: $dedupKey,
                    title: NotificationText::of('COM_ALFA_NOTIFY_LOW_STOCK_TITLE', $label),
                    options: [
                        'group' => NotificationHelper::GROUP_INVENTORY,
                        'severity' => 'warning',
                        // The band is a standing condition, so the notice re-alerts
                        // while stock stays inside it. Without this a merchant who
                        // dismissed it once was never told again, however far the
                        // quantity then fell.
                        'constant' => true,
                        'message' => NotificationText::of('COM_ALFA_NOTIFY_LOW_STOCK_DESC', self::trimQty($salable), self::trimQty($threshold)),
                        'url' => 'index.php?option=com_alfa&task=item.edit&id=' . $itemId,
                        'view' => ['action' => 'alfa.items.view', 'asset' => 'com_alfa'],
                        'link' => ['action' => 'alfa.items.edit', 'asset' => 'com_alfa'],
                    ],
                );

                // A row now exists for this key; the "absent" memo is stale.
                unset(self::$noticeAbsent[$dedupKey]);
            } elseif (!self::noticeAbsent(dedupKey: $dedupKey)) {
                self::clearNotice(dedupKey: $dedupKey);
            }
        } catch (Throwable $e) {
            // Advisory only: a notification hiccup must never break stock, but
            // one that ended the command's transaction ends the command.
            if ($this->endsCommand(failure: $e)) {
                throw $e;
            }
        }
    }

    /**
     * The low-stock notice's identity, one unit = one key.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination unit, or 0 for the item unit.
     *
     *
     * @since  2.0.0
     */
    private static function lowStockKey(int $itemId, int $combinationId): string
    {
        return 'stock-low:' . ($combinationId > 0 ? 'combination:' . $combinationId : 'item:' . $itemId);
    }

    /**
     * Retire the low-stock and backorder notices of a unit that leaves the current
     * catalogue: an identity kept only for order history is never offered for sale,
     * is never evaluated again, and so must not leave a standing notice behind.
     *
     * Advisory: a failure here never disturbs the command; it is logged.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination unit, or 0 for the item unit.
     *
     *
     * @since  2.0.0
     */
    private function clearUnitNotices(int $itemId, int $combinationId): void
    {
        try {
            foreach ([
                self::lowStockKey(itemId: $itemId, combinationId: $combinationId),
                self::backorderKey(itemId: $itemId, combinationId: $combinationId),
            ] as $dedupKey) {
                if (!self::noticeAbsent(dedupKey: $dedupKey)) {
                    self::clearNotice(dedupKey: $dedupKey);
                }
            }
        } catch (Throwable $e) {
            if ($this->endsCommand(failure: $e)) {
                throw $e;
            }

            Log::add(
                entry: sprintf(
                    'Inventory: the stock notices of item #%d%s could not be retired: %s',
                    $itemId,
                    $combinationId > 0 ? ' combination #' . $combinationId : '',
                    $e->getMessage(),
                ),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );
        }
    }

    /**
     * Retire a notification this service raised, and remember it is gone.
     *
     * HARD, like the integrity notice ({@see \Alfa\Component\Alfa\Administrator\Helper\SyncHelper::applyIntegrityVerdict()}):
     * a fluctuating live state must not fill history, and an ARCHIVED row would swallow
     * the NEXT push: {@see NotificationHelper::push()} updates a dismissed row in place
     * and never un-dismisses it, so a product that went low, recovered and went low
     * again would never notify a second time.
     *
     * @param string $dedupKey The key to retire.
     *
     *
     * @since  2.0.0
     */
    private static function clearNotice(string $dedupKey): void
    {
        NotificationHelper::clear(dedupKey: $dedupKey, hard: true);

        self::$noticeAbsent[$dedupKey] = TransactionState::rollbacks(Factory::getContainer()->get('DatabaseDriver'));
    }

    /**
     * Memoised "is there anything to clear?" probe. Answers from the request memo when
     * it can, otherwise with one point read on the `dedup_key` unique index, so an
     * inventory operation on a healthy unit never issues a pointless DELETE/UPDATE.
     *
     * @param string $dedupKey The key to probe.
     *
     * @return bool True when no notification carries the key.
     *
     * @since  2.0.0
     */
    private static function noticeAbsent(string $dedupKey): bool
    {
        $rollbacks = TransactionState::rollbacks(Factory::getContainer()->get('DatabaseDriver'));

        if ((self::$noticeAbsent[$dedupKey] ?? null) === $rollbacks) {
            return true;
        }

        if (NotificationHelper::exists(dedupKey: $dedupKey)) {
            unset(self::$noticeAbsent[$dedupKey]);

            return false;
        }

        self::$noticeAbsent[$dedupKey] = $rollbacks;

        return true;
    }

    /**
     * The label of one inventory unit, as a notice's parameter ({@see NotificationText}): the item's name in every
     * content language, with the combination's SKU so a variant notice names the variant rather than the product it
     * belongs to. Each reader reads the name in their content language (since 2.1.4).
     *
     * @param int $itemId The owning item.
     * @param string|null $sku The combination SKU, or null for the item unit.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    private function unitLabel(int $itemId, ?string $sku = null): array
    {
        $name = NotificationText::content(ItemFieldsSnapshot::freezeItemName($itemId, null));

        return ($sku !== null && $sku !== '') ? NotificationText::key('COM_ALFA_NOTIFY_UNIT_SKU', $name, $sku) : $name;
    }

    /**
     * Format a quantity without trailing zeros for display.
     *
     * @param float $value The quantity.
     *
     *
     * @since  2.0.0
     */
    private static function trimQty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    //  Structure, repair and audited operations

    /**
     * Lock every stock record of an item. The next-key locks also block a new
     * record for it, so this is the first statement of every catalogue
     * transaction that changes an item's unit set, tracking, combination state or
     * stock. Meaningful only inside a transaction.
     *
     * @param int $itemId The item.
     *
     * @return int[] The record ids, ascending.
     *
     * @since  2.0.0
     */
    public function lockItemStock(int $itemId): array
    {
        $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
        );

        return array_map('intval', $this->db->loadColumn() ?: []);
    }

    /**
     * An item's whole inventory state in one consistent read: its row, its
     * combination rows, its stock records and whether their locations are enabled.
     *
     * With $lock the rows are read in the kernel's lock order: stock records
     * (FOR UPDATE, by id), locations (shared, including $extraLocationIds),
     * combination rows (FOR UPDATE), the item row (FOR UPDATE), as current reads,
     * so what is computed from them holds until the caller commits.
     *
     * @param int $itemId The item.
     * @param bool $lock Lock and read current data (inside a transaction).
     * @param int[] $extraLocationIds Further locations to read (and lock) with the rest.
     *
     * @return array{item: ?object, combinations: array<int, object>, rows: object[], enabled: array<int, bool>}
     *
     * @since  2.0.0
     */
    private function itemLedger(int $itemId, bool $lock, array $extraLocationIds = []): array
    {
        $forUpdate = $lock ? ' FOR UPDATE' : '';

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'combination_id', 'location_id', 'quantity', 'reserved_quantity']))
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' ORDER BY ' . $this->db->quoteName('id') . $forUpdate,
        );
        $rows = $this->db->loadObjectList() ?: [];

        $locationIds = array_map(static fn (object $row): int => (int) $row->location_id, $rows);

        foreach ($extraLocationIds as $locationId) {
            $locationIds[] = (int) $locationId;
        }

        $enabled = $this->locationsEnabled(locationIds: $locationIds, lock: $lock);

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'manage_stock', 'state', 'stock', 'available_quantity']))
            . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' ORDER BY ' . $this->db->quoteName('id') . $forUpdate,
        );
        $combinations = [];

        foreach ($this->db->loadObjectList() ?: [] as $combination) {
            $combinations[(int) $combination->id] = $combination;
        }

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'manage_stock', 'stock', 'available_quantity']))
            . ' FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId . $forUpdate,
        );

        return [
            'item' => $this->db->loadObject() ?: null,
            'combinations' => $combinations,
            'rows' => $rows,
            'enabled' => $enabled,
        ];
    }

    /**
     * Whether each location is enabled (a missing location is not).
     *
     * @param int[] $locationIds The locations.
     * @param bool $lock Read with shared locks (current read).
     *
     * @return array<int, bool>
     *
     * @since  2.0.0
     */
    private function locationsEnabled(array $locationIds, bool $lock): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $locationIds), static fn (int $id): bool => $id > 0)));
        sort($ids);

        $enabled = array_fill_keys($ids, false);

        if ($ids === []) {
            return $enabled;
        }

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'state'])) . ' FROM ' . $this->db->quoteName('#__alfa_inventory_locations')
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
            . ' ORDER BY ' . $this->db->quoteName('id') . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );

        foreach ($this->db->loadObjectList() ?: [] as $location) {
            $enabled[(int) $location->id] = (int) $location->state === 1;
        }

        return $enabled;
    }

    /**
     * The figure rows of an item whose stored stock or available figure disagrees
     * with its stock records.
     *
     * Targets: a combination row carries its own physical and salable quantity,
     * whatever its state (archived included) or tracking. An item without current
     * combinations carries its own unit's figures, whatever its tracking. An item
     * with current combinations carries the physical sum over its current
     * (published or unpublished) combination units and the salable sum over the
     * combination units that count for sale (published and tracked); its own
     * (item, 0) records, archived combinations and records naming another item's
     * combination are never part of its figure. Salable counts enabled locations
     * only. Figures are compared within float tolerance, an empty figure reading as
     * zero.
     *
     * @param int $itemId The item.
     * @param bool $lock Lock in the kernel's order and read current data (inside a transaction).
     *
     * @return array<int, array{table: string, id: int, stock: ?float, stock_target: float, available: ?float, available_target: float}>
     *
     * @since  2.0.0
     */
    public function projectionCorrections(int $itemId, bool $lock): array
    {
        return $this->correctionsFrom(ledger: $this->itemLedger(itemId: $itemId, lock: $lock));
    }

    /**
     * projectionCorrections() over an already-read item ledger.
     *
     * @param array $ledger itemLedger() output.
     *
     * @return array<int, array{table: string, id: int, stock: ?float, stock_target: float, available: ?float, available_target: float}>
     *
     * @since  2.0.0
     */
    private function correctionsFrom(array $ledger): array
    {
        $item = $ledger['item'];

        if ($item === null) {
            return [];
        }

        $physical = [];
        $salable = [];

        foreach ($ledger['rows'] as $row) {
            $combinationId = (int) $row->combination_id;
            $physical[$combinationId] = ($physical[$combinationId] ?? 0.0) + (float) $row->quantity;
            $salable[$combinationId] = ($salable[$combinationId] ?? 0.0)
                + (($ledger['enabled'][(int) $row->location_id] ?? false) ? (float) $row->quantity - (float) $row->reserved_quantity : 0.0);
        }

        $figures = [];
        $itemFlag = (int) ($item->manage_stock ?? 0);
        $bearsCurrent = false;
        $parentPhysical = 0.0;
        $parentSalable = 0.0;

        foreach ($ledger['combinations'] as $combinationId => $combination) {
            $unitPhysical = $physical[$combinationId] ?? 0.0;
            $unitSalable = $salable[$combinationId] ?? 0.0;

            $figures[] = [
                'table' => '#__alfa_item_combinations',
                'id' => (int) $combinationId,
                'stock' => $combination->stock !== null ? (float) $combination->stock : null,
                'stock_target' => $unitPhysical,
                'available' => $combination->available_quantity !== null ? (float) $combination->available_quantity : null,
                'available_target' => $unitSalable,
            ];

            // An archived combination keeps its own figure but is no part of its parent.
            if (!CombinationState::isCurrent(state: $combination->state)) {
                continue;
            }

            $bearsCurrent = true;
            $parentPhysical += $unitPhysical;

            if ((int) $combination->state === CombinationState::PUBLISHED && (int) ($combination->manage_stock ?? $itemFlag) === 1) {
                $parentSalable += $unitSalable;
            }
        }

        if ($bearsCurrent) {
            $itemPhysical = $parentPhysical;
            $itemSalable = $parentSalable;
        } else {
            $itemPhysical = $physical[0] ?? 0.0;
            $itemSalable = $salable[0] ?? 0.0;
        }

        $figures[] = [
            'table' => '#__alfa_items',
            'id' => (int) $item->id,
            'stock' => $item->stock !== null ? (float) $item->stock : null,
            'stock_target' => $itemPhysical,
            'available' => $item->available_quantity !== null ? (float) $item->available_quantity : null,
            'available_target' => $itemSalable,
        ];

        return array_values(array_filter(
            $figures,
            static fn (array $figure): bool => !InventoryAvailabilityService::quantitiesAgree(a: (float) ($figure['stock'] ?? 0.0), b: $figure['stock_target'])
                || !InventoryAvailabilityService::quantitiesAgree(a: (float) ($figure['available'] ?? 0.0), b: $figure['available_target']),
        ));
    }

    /**
     * Refuse a structural change while an item's stored figures disagree with its
     * stock records (drift, figures claiming stock without records, untracked
     * figures). Unlocked; the refusal queues the item's integrity notification.
     *
     * @param int $itemId The item.
     *
     *
     * @throws InventoryProjectionMismatchException When any figure disagrees.
     *
     * @since  2.0.0
     */
    public function assertProjectionsAgree(int $itemId): void
    {
        $rows = $this->projectionCorrections(itemId: $itemId, lock: false);

        if ($rows === []) {
            return;
        }

        InventoryIntegrityNotice::queue(itemId: $itemId, kind: InventoryIntegrityNotice::KIND_FIGURES_DISAGREE);

        throw new InventoryProjectionMismatchException(itemId: $itemId, rows: $rows);
    }

    /**
     * Repair an item's stored figures from its stock records: the only audited
     * repair.
     *
     * Locks in the kernel's order, sets every disagreeing figure of the item and
     * its combinations to what the records say (see projectionCorrections()) as a
     * relative correction, and always records one 'reconcile' operation under the
     * operator, even when nothing needed correcting. It writes no stock record and
     * no movement. Once the figures agree with the records, every open integrity
     * hold of the item ends in the same transaction: the hold rows record this
     * operation, the time and the operator as their release, and stay as history.
     * This is the only end of a hold. After the commit the corrections are logged
     * and the item's integrity notification is cleared.
     *
     * @param int $itemId The item.
     * @param string $sourceType The repairing domain ('inventory_repair').
     * @param int $sourceId Source row id (the item).
     * @param InventoryContext $context The operator.
     *
     * @return array{operation_id: string, corrected: int, corrections: array, holds_released: int}
     *
     * @throws InventoryUnitException When the item does not exist.
     *
     * @since  2.0.0
     */
    public function reconcile(int $itemId, string $sourceType, int $sourceId, InventoryContext $context): array
    {
        $operationId = $this->newOperationId();

        $this->db->transactionStart(true);

        try {
            $ledger = $this->itemLedger(itemId: $itemId, lock: true);

            if ($ledger['item'] === null) {
                throw new InventoryUnitException(itemId: $itemId, combinationId: 0, reason: InventoryUnitException::ITEM_GONE);
            }

            $corrections = $this->correctionsFrom(ledger: $ledger);

            foreach ($corrections as $figure) {
                $this->applyProjectionCorrection(
                    table: $figure['table'],
                    rowId: $figure['id'],
                    dStock: $figure['stock_target'] - (float) ($figure['stock'] ?? 0.0),
                    dAvail: $figure['available_target'] - (float) ($figure['available'] ?? 0.0),
                );
            }

            $this->recordOperation(operationId: $operationId, command: 'reconcile', sourceType: $sourceType, sourceId: $sourceId, context: $context);

            // The figures agree with the records now: the item's held units are
            // offered for sale again.
            $holdsReleased = $this->releaseIntegrityHolds(itemId: $itemId, operationId: $operationId, context: $context);

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $changes = array_map(
            static fn (array $figure): string => sprintf(
                '%s #%d stock %s -> %s, available %s -> %s',
                $figure['table'] === '#__alfa_items' ? 'item' : 'combination',
                $figure['id'],
                $figure['stock'] === null ? 'empty' : self::trimQty(value: $figure['stock']),
                self::trimQty(value: $figure['stock_target']),
                $figure['available'] === null ? 'empty' : self::trimQty(value: $figure['available']),
                self::trimQty(value: $figure['available_target']),
            ),
            $corrections,
        );

        $this->logOnceStands(
            label: 'inventory line: figures reconciled',
            entry: sprintf(
                'Inventory: stock figures of item #%d reconciled with its stock records by user #%d (operation %s): %s; %d integrity holds ended.',
                $itemId,
                $context->userId,
                $operationId,
                $changes === [] ? 'nothing to correct' : implode('; ', $changes),
                $holdsReleased,
            ),
            priority: Log::INFO,
        );

        // The repaired item's notice goes once the repair stands; a repair whose
        // transaction rolls back leaves the notice, and any push queued for it.
        PostCommit::defer(
            db: $this->db,
            label: 'inventory notice: integrity repaired',
            work: static fn () => InventoryIntegrityNotice::clear(itemId: $itemId),
        );

        return [
            'operation_id' => $operationId,
            'corrected' => \count($corrections),
            'corrections' => $corrections,
            'holds_released' => $holdsReleased,
        ];
    }

    /**
     * Apply a figure correction as a relative delta (reconcile() only).
     *
     * @param string $table #__alfa_items or #__alfa_item_combinations.
     * @param int $rowId The row.
     * @param float $dStock Physical correction.
     * @param float $dAvail Salable correction.
     *
     *
     * @since  2.0.0
     */
    private function applyProjectionCorrection(string $table, int $rowId, float $dStock, float $dAvail): void
    {
        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName($table)
            . ' SET ' . $this->db->quoteName('stock') . ' = COALESCE('
            . $this->db->quoteName('stock') . ', 0) + ' . self::sqlNumber(value: $dStock)
            . ', ' . $this->db->quoteName('available_quantity') . ' = COALESCE('
            . $this->db->quoteName('available_quantity') . ', 0) + ' . self::sqlNumber(value: $dAvail)
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $rowId,
        )->execute();
    }

    /**
     * Change the state of stock locations, moving exactly the salable figures
     * whose location changed enabled-ness.
     *
     * Locks the locations' stock records, then the location rows, and runs $write
     * (the caller's own save of those rows). For each location whose
     * enabled-ness changed, every record there moves its unit's available figure
     * by +/- its salable quantity (quantity - reserved) and, for a combination that
     * counts for its parent, the parent's too; one 'location_state' operation per
     * such location. Physical figures never move, and nothing converges to an
     * absolute value: a figure already in drift stays in drift for the audited
     * repair. A write that changes no enabled-ness moves nothing.
     *
     * @param int[] $locationIds The locations the write may change.
     * @param callable $write fn (): mixed - the caller's write of the location rows.
     * @param InventoryContext $context Audit context.
     *
     * @return mixed What $write returned.
     *
     * @since  2.0.0
     */
    public function changeLocationState(array $locationIds, callable $write, InventoryContext $context): mixed
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $locationIds), static fn (int $id): bool => $id > 0)));
        sort($ids);

        if ($ids === []) {
            return $write();
        }

        $in = implode(',', $ids);

        $this->db->transactionStart(true);

        try {
            $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
                . ' WHERE ' . $this->db->quoteName('location_id') . ' IN (' . $in . ')'
                . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
            )->loadColumn();

            $readStates = function () use ($ids, $in): array {
                $states = array_fill_keys($ids, false);

                $this->db->setQuery(
                    'SELECT ' . implode(', ', $this->db->quoteName(['id', 'state'])) . ' FROM ' . $this->db->quoteName('#__alfa_inventory_locations')
                    . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $in . ')'
                    . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
                );

                foreach ($this->db->loadObjectList() ?: [] as $location) {
                    $states[(int) $location->id] = (int) $location->state === 1;
                }

                return $states;
            };

            $before = $readStates();
            $result = $write();
            $after = $readStates();

            foreach ($ids as $locationId) {
                if ($before[$locationId] === $after[$locationId]) {
                    continue;
                }

                $sign = $after[$locationId] ? 1.0 : -1.0;

                $this->db->setQuery(
                    'SELECT ' . implode(', ', $this->db->quoteName(['id', 'quantity', 'reserved_quantity']))
                    . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
                    . ' WHERE ' . $this->db->quoteName('location_id') . ' = ' . $locationId
                    . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
                );

                foreach ($this->db->loadObjectList() ?: [] as $row) {
                    $salable = (float) $row->quantity - (float) $row->reserved_quantity;

                    if (abs($salable) <= self::EPS) {
                        continue;
                    }

                    $facts = $this->stockRowFacts(stockId: (int) $row->id);
                    $unitDelta = $sign * $salable;

                    $this->propagateToReadModels(
                        stockId: (int) $row->id,
                        unitPhysicalDelta: 0.0,
                        unitSalableDelta: $unitDelta,
                        parentPhysicalDelta: 0.0,
                        parentSalableDelta: $facts->countsForParent ? $unitDelta : 0.0,
                        facts: $facts,
                    );
                }

                $this->recordOperation(
                    operationId: $this->newOperationId(),
                    command: 'location_state',
                    sourceType: 'location',
                    sourceId: $locationId,
                    context: $context,
                );
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        return $result;
    }

    /**
     * Publish or unpublish combinations: the only writer of combination
     * publication.
     *
     * Locks the item's stock records, their locations (shared), the named
     * combination rows and the item row; writes the state of every combination
     * whose state changes; and, for each combination whose counting for the parent
     * changed (published and tracked), moves the parent's available figure by +/-
     * its salable quantity. No stock record, movement or physical figure changes.
     * One 'unit_state' operation when any state changed.
     *
     * Only publication is written here: an archived combination is refused (it
     * comes back only through reactivateUnits()), and archiving goes through
     * archiveUnits().
     *
     * @param int $itemId The item.
     * @param array<int, int> $states combinationId => new state (0 or 1).
     * @param InventoryContext $context Audit context.
     *
     * @return string|null The operation id, or null when no state changed.
     *
     * @throws InventoryUnitException When a named combination does not exist for the item or
     *                                is archived, or the item is gone.
     * @throws RuntimeException For a state other than 0 or 1.
     *
     * @since  2.0.0
     */
    public function changeCombinationState(int $itemId, array $states, InventoryContext $context): ?string
    {
        $wanted = [];

        foreach ($states as $combinationId => $state) {
            if ((int) $combinationId <= 0) {
                continue;
            }

            if (!\in_array((int) $state, [CombinationState::UNPUBLISHED, CombinationState::PUBLISHED], true)) {
                throw new RuntimeException(sprintf(
                    'InventoryService: combination #%d can only be published (1) or unpublished (0) here.',
                    (int) $combinationId,
                ));
            }

            $wanted[(int) $combinationId] = (int) $state;
        }

        if ($wanted === []) {
            return null;
        }

        ksort($wanted);
        $in = implode(',', array_keys($wanted));
        $operationId = null;

        $this->db->transactionStart(true);

        try {
            $this->lockItemStock(itemId: $itemId);

            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['id', 'combination_id', 'location_id', 'quantity', 'reserved_quantity']))
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
                . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' AND ' . $this->db->quoteName('combination_id') . ' IN (' . $in . ')'
                . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
            );
            $rows = $this->db->loadObjectList() ?: [];

            $enabled = $this->locationsEnabled(
                locationIds: array_map(static fn (object $row): int => (int) $row->location_id, $rows),
                lock: true,
            );

            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['id', 'state', 'manage_stock']))
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $in . ')'
                . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
            );
            $combinations = [];

            foreach ($this->db->loadObjectList() ?: [] as $combination) {
                $combinations[(int) $combination->id] = $combination;
            }

            foreach (array_keys($wanted) as $combinationId) {
                if (!isset($combinations[$combinationId])) {
                    throw new InventoryUnitException(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        reason: InventoryUnitException::COMBINATION_GONE,
                    );
                }

                if (!CombinationState::isCurrent(state: $combinations[$combinationId]->state)) {
                    throw new InventoryUnitException(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        reason: InventoryUnitException::COMBINATION_ARCHIVED,
                    );
                }
            }

            $this->db->setQuery(
                'SELECT COALESCE(' . $this->db->quoteName('manage_stock') . ', 0) FROM ' . $this->db->quoteName('#__alfa_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId . ' FOR UPDATE',
            );
            $itemFlag = $this->db->loadResult();

            if ($itemFlag === null) {
                throw new InventoryUnitException(itemId: $itemId, combinationId: 0, reason: InventoryUnitException::ITEM_GONE);
            }

            $changed = 0;

            foreach ($wanted as $combinationId => $state) {
                $combination = $combinations[$combinationId];
                $old = (int) $combination->state;

                if ($old === $state) {
                    continue;
                }

                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName('#__alfa_item_combinations')
                    . ' SET ' . $this->db->quoteName('state') . ' = ' . $state
                    . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $combinationId
                    . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId,
                )->execute();
                $changed++;

                $tracked = (int) ($combination->manage_stock ?? $itemFlag) === 1;
                $countedBefore = $old === 1 && $tracked;
                $countsAfter = $state === 1 && $tracked;

                if ($countedBefore === $countsAfter) {
                    continue;
                }

                $sign = $countsAfter ? 1.0 : -1.0;

                foreach ($rows as $row) {
                    if ((int) $row->combination_id !== $combinationId || !($enabled[(int) $row->location_id] ?? false)) {
                        continue;
                    }

                    $salable = (float) $row->quantity - (float) $row->reserved_quantity;

                    if (abs($salable) > self::EPS) {
                        $this->propagateToReadModels(
                            stockId: (int) $row->id,
                            unitPhysicalDelta: 0.0,
                            unitSalableDelta: 0.0,
                            parentPhysicalDelta: 0.0,
                            parentSalableDelta: $sign * $salable,
                        );
                    }
                }
            }

            if ($changed > 0) {
                $operationId = $this->newOperationId();
                $this->recordOperation(operationId: $operationId, command: 'unit_state', sourceType: 'catalog_edit', sourceId: $itemId, context: $context);
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        return $operationId;
    }

    /**
     * Switch stock tracking of an item or one combination: the only writer of a
     * tracking transition. Callers remove manage_stock from their own row write.
     *
     * $combinationId null writes items.manage_stock (0 or 1); otherwise
     * item_combinations.manage_stock (0, 1 or NULL = inherit the item). The units
     * affected are the item's own unit (an item without current combinations), or
     * every current combination inheriting the item's setting, or the one
     * combination; each is judged on its EFFECTIVE tracking before and after,
     * under the kernel's locks. An archived combination is refused.
     *
     * Refused, before anything is written, when: the unit is gone; any of the
     * item's figures disagrees with its stock records (either direction); a unit
     * going off still has open reservations; a unit going off is still relied on by
     * open order lines placed as stock claims, reserved or not
     * ({@see self::relyingOpenLines()}: such a line deducts as it was placed, and an
     * untracked unit would hold nothing for it); the item is switched on while
     * combinations inherit it; a unit going on has no explicit opening quantity,
     * or none for a location holding a non-zero record; a unit going on is held,
     * or its figures claim stock it has no records for; an opening quantity would
     * lower a record below what open reservations hold on it; an opening quantity
     * cannot be applied.
     *
     * Switching on converges each opening quantity like adjustTo() ('adjust'
     * movements, source 'catalog_edit', the operator as actor); 0 is a valid
     * figure and an absent one is never read as 0. Switching off leaves every
     * record, movement and reservation as it is; the records still holding
     * quantities are returned for the warning. The parent's available figure moves
     * by +/- the salable quantity of each combination whose counting for it changed.
     * One 'track_open' or 'track_close' operation, also when nothing moved. A call
     * that changes no unit's effective tracking writes only the flag.
     *
     * @param int $itemId The item.
     * @param int|null $combinationId The combination, or null for the item.
     * @param int|null $value 0, 1, or (combinations only) NULL.
     * @param array|null $openingTargets locationId => quantity on hand, for a unit going on.
     * @param InventoryContext $context The operator.
     *
     * @return array{operation_id: ?string, movements: int, kept_rows: int, kept_on_hand: float}
     *
     * @throws InventoryUnitException When the unit is gone or archived.
     * @throws InventoryProjectionMismatchException When the item's figures disagree with its records.
     * @throws InventoryIntegrityException When a unit going on is held or its figures claim stock
     *                                     without records.
     * @throws InventoryReservedFloorException When an opening quantity would lower a record below its
     *                                         reservations.
     * @throws InventoryTrackingException For the reserved, open_lines, variants_inherit, opening_required
     *                                    and target refusals.
     *
     * @since  2.0.0
     */
    public function changeTracking(int $itemId, ?int $combinationId, ?int $value, ?array $openingTargets, InventoryContext $context): array
    {
        $combinationId = ($combinationId ?? 0) > 0 ? $combinationId : null;

        if (($value !== null && $value !== 0 && $value !== 1) || ($combinationId === null && $value === null)) {
            throw new RuntimeException('InventoryService: stock tracking is 0 or 1, or NULL (inherit) for a combination.');
        }

        // The one statement that writes a tracking setting; it runs under the
        // locks below, on both the transition and the no-transition path.
        $flag = $value === null ? 'NULL' : (string) $value;

        if ($combinationId === null) {
            $flagWrite = 'UPDATE ' . $this->db->quoteName('#__alfa_items')
                . ' SET ' . $this->db->quoteName('manage_stock') . ' = ' . $flag
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId;
        } else {
            $flagWrite = 'UPDATE ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' SET ' . $this->db->quoteName('manage_stock') . ' = ' . $flag
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $combinationId
                . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId;
        }

        $targets = [];

        foreach ($openingTargets ?? [] as $locationId => $quantity) {
            $targets[(int) $locationId] = (float) $quantity;
        }

        $operationId = null;
        $movements = 0;
        $keptRows = 0;
        $keptOnHand = 0.0;
        $applied = [];

        $this->db->transactionStart(true);

        try {
            $ledger = $this->itemLedger(itemId: $itemId, lock: true, extraLocationIds: array_keys($targets));
            $item = $ledger['item'];

            if ($item === null) {
                throw new InventoryUnitException(itemId: $itemId, combinationId: (int) $combinationId, reason: InventoryUnitException::ITEM_GONE);
            }

            if ($combinationId !== null && !isset($ledger['combinations'][$combinationId])) {
                throw new InventoryUnitException(itemId: $itemId, combinationId: $combinationId, reason: InventoryUnitException::COMBINATION_GONE);
            }

            // An archived combination is kept only for order history: its tracking
            // is no longer edited.
            if ($combinationId !== null && !CombinationState::isCurrent(state: $ledger['combinations'][$combinationId]->state)) {
                throw new InventoryUnitException(itemId: $itemId, combinationId: $combinationId, reason: InventoryUnitException::COMBINATION_ARCHIVED);
            }

            $corrections = $this->correctionsFrom(ledger: $ledger);

            if ($corrections !== []) {
                InventoryIntegrityNotice::queue(itemId: $itemId, kind: InventoryIntegrityNotice::KIND_FIGURES_DISAGREE);

                throw new InventoryProjectionMismatchException(itemId: $itemId, rows: $corrections);
            }

            // Effective tracking before and after, per affected unit. Only current
            // combinations are units: an item whose combinations are all archived
            // is its own unit again.
            $itemFlag = (int) ($item->manage_stock ?? 0);
            $current = array_filter(
                $ledger['combinations'],
                static fn (object $combination): bool => CombinationState::isCurrent(state: $combination->state),
            );
            $affected = [];

            if ($combinationId === null) {
                if ($current === []) {
                    $affected[0] = [$itemFlag === 1, $value === 1];
                } else {
                    foreach ($current as $id => $combination) {
                        if ($combination->manage_stock === null) {
                            $affected[$id] = [$itemFlag === 1, $value === 1];
                        }
                    }
                }
            } else {
                $combination = $ledger['combinations'][$combinationId];
                $affected[$combinationId] = [
                    (int) ($combination->manage_stock ?? $itemFlag) === 1,
                    (int) ($value ?? $itemFlag) === 1,
                ];
            }

            $goingOn = array_keys(array_filter($affected, static fn (array $flags): bool => !$flags[0] && $flags[1]));
            $goingOff = array_keys(array_filter($affected, static fn (array $flags): bool => $flags[0] && !$flags[1]));

            if ($goingOn === [] && $goingOff === []) {
                $this->db->setQuery($flagWrite)->execute();
                TransactionState::commitLevel(db: $this->db);

                return ['operation_id' => null, 'movements' => 0, 'kept_rows' => 0, 'kept_on_hand' => 0.0];
            }

            if ($goingOff !== []) {
                $reserved = $this->countActiveReservations(itemId: $itemId, combinationIds: $goingOff, lock: true);

                if ($reserved > 0) {
                    throw new InventoryTrackingException(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        reason: InventoryTrackingException::RESERVED,
                        count: $reserved,
                        code: null,
                    );
                }

                // Open order lines placed as stock claims still have quantity to be
                // delivered from a unit going off. They deduct as they were placed,
                // so the unit keeps tracking stock until they are delivered or closed.
                $relying = $this->relyingOpenLines(itemId: $itemId, combinationIds: $goingOff, lock: true);

                if ($relying['lines'] > 0) {
                    throw new InventoryTrackingException(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        reason: InventoryTrackingException::OPEN_LINES,
                        count: $relying['lines'],
                        code: null,
                        orders: $relying['orders'],
                    );
                }
            }

            if ($goingOn !== []) {
                if ($combinationId === null && $current !== []) {
                    throw new InventoryTrackingException(
                        itemId: $itemId,
                        combinationId: null,
                        reason: InventoryTrackingException::VARIANTS_INHERIT,
                        count: \count($goingOn),
                        code: null,
                    );
                }

                $unitCombination = (int) $goingOn[0];

                if ($openingTargets === null) {
                    throw new InventoryTrackingException(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        reason: InventoryTrackingException::OPENING_REQUIRED,
                        count: 0,
                        code: null,
                    );
                }

                foreach ($ledger['rows'] as $row) {
                    if ((int) $row->combination_id === $unitCombination
                        && abs((float) $row->quantity) > self::TOL
                        && !\array_key_exists((int) $row->location_id, $targets)
                    ) {
                        throw new InventoryTrackingException(
                            itemId: $itemId,
                            combinationId: $combinationId,
                            reason: InventoryTrackingException::OPENING_REQUIRED,
                            count: 0,
                            code: null,
                        );
                    }
                }

                $problems = $this->validateTargets(
                    itemId: $itemId,
                    combinationId: $unitCombination ?: null,
                    targets: $targets,
                    assumeTracked: true,
                );

                // A held unit, or one whose figures claim stock without records, is
                // refused as the integrity refusal it is, with the figures it shows.
                if ($problems !== [] && \in_array($problems[0]['code'], ['ledger_missing', 'integrity_held'], true)) {
                    throw new InventoryIntegrityException(
                        itemId: $itemId,
                        combinationId: $unitCombination,
                        stock: $problems[0]['stock'] ?? null,
                        available: $problems[0]['available'] ?? null,
                        held: $problems[0]['code'] === 'integrity_held',
                    );
                }

                // An opening below what open reservations hold on a record is the
                // reservation floor refusal, with its figures.
                if ($problems !== [] && $problems[0]['code'] === 'reserved_floor') {
                    throw new InventoryReservedFloorException(
                        itemId: $itemId,
                        combinationId: $unitCombination,
                        locationId: (int) $problems[0]['location_id'],
                        onHand: (float) $problems[0]['on_hand'],
                        reserved: (float) $problems[0]['reserved'],
                        requested: (float) $problems[0]['requested'],
                        minimum: (float) $problems[0]['minimum'],
                        command: 'track_open',
                    );
                }

                if ($problems !== []) {
                    throw new InventoryTrackingException(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        reason: InventoryTrackingException::TARGET,
                        count: \count($problems),
                        code: $problems[0]['code'],
                        locationId: $problems[0]['location_id'],
                    );
                }
            }

            $operationId = $this->newOperationId();

            // Openings converge like adjustTo(), before the flag: the unit is still
            // untracked, so none of it counts for the parent yet.
            if ($goingOn !== []) {
                $unit = ['item' => $itemId, 'combination' => (int) $goingOn[0]];

                foreach ($targets as $locationId => $target) {
                    $existing = $this->lockExistingStockRow(itemId: $itemId, combinationId: $unit['combination'], locationId: $locationId);

                    if ($existing === null && abs($target) <= self::TOL) {
                        continue;
                    }

                    $stock = $this->lockStockRow(
                        itemId: $itemId,
                        combinationId: $unit['combination'],
                        locationId: $locationId,
                        refuseLedgerDefect: true,
                        historical: false,
                        command: 'track_open',
                        lineId: null,
                    );

                    if (InventoryAvailabilityService::quantitiesAgree(a: (float) $stock->quantity, b: $target)) {
                        continue;
                    }

                    $delta = $target - (float) $stock->quantity;

                    if ($delta > self::EPS && !LocationPolicy::isEnabled(db: $this->db, locationId: $locationId, lockInShareMode: true)) {
                        throw new RuntimeException(sprintf('InventoryService: location %d is disabled and cannot receive stock.', $locationId));
                    }

                    if ($delta < 0) {
                        $this->guardReservedFloor(stock: $stock, deduction: -$delta, unit: $unit, command: 'track_open');
                        $this->guardPhysical(stock: $stock, deduction: -$delta);
                    }

                    $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: $delta, reservedDelta: 0);
                    $this->appendMovement(
                        unit: $unit,
                        locationId: $locationId,
                        qtyChange: $delta,
                        movementType: self::MOVE_ADJUST,
                        sourceType: 'catalog_edit',
                        sourceId: $itemId,
                        sourceLineId: null,
                        operationId: $operationId,
                        context: $context,
                    );

                    $movements++;
                    $applied[] = ['locationId' => $locationId, 'delta' => $delta, 'combinationId' => $unit['combination']];
                }
            }

            $this->db->setQuery($flagWrite)->execute();

            // The parent's available figure follows every combination whose
            // counting for it changed (published and now tracked, or no longer).
            foreach ($affected as $affectedCombination => [$before, $after]) {
                if ($affectedCombination === 0 || $before === $after
                    || (int) $ledger['combinations'][$affectedCombination]->state !== 1
                ) {
                    continue;
                }

                $this->db->setQuery(
                    'SELECT ' . implode(', ', $this->db->quoteName(['id', 'location_id', 'quantity', 'reserved_quantity']))
                    . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
                    . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                    . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . (int) $affectedCombination
                    . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
                );

                foreach ($this->db->loadObjectList() ?: [] as $row) {
                    if (!LocationPolicy::isEnabled(db: $this->db, locationId: (int) $row->location_id, lockInShareMode: true)) {
                        continue;
                    }

                    $salable = (float) $row->quantity - (float) $row->reserved_quantity;

                    if (abs($salable) > self::EPS) {
                        $this->propagateToReadModels(
                            stockId: (int) $row->id,
                            unitPhysicalDelta: 0.0,
                            unitSalableDelta: 0.0,
                            parentPhysicalDelta: 0.0,
                            parentSalableDelta: ($after ? 1.0 : -1.0) * $salable,
                        );
                    }
                }
            }

            foreach ($goingOff as $offCombination) {
                foreach ($ledger['rows'] as $row) {
                    if ((int) $row->combination_id === (int) $offCombination && abs((float) $row->quantity) > self::TOL) {
                        $keptRows++;
                        $keptOnHand += (float) $row->quantity;
                    }
                }
            }

            $this->recordOperation(
                operationId: $operationId,
                command: $goingOn !== [] ? 'track_open' : 'track_close',
                sourceType: 'catalog_edit',
                sourceId: $itemId,
                context: $context,
            );

            foreach ($goingOn as $onCombination) {
                $this->evaluateUnitNotices(itemId: $itemId, combinationId: (int) $onCombination);
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        foreach ($applied as $opening) {
            $this->announce(label: 'onInventoryAdjusted', event: new InventoryAdjustedEvent('onInventoryAdjusted', [
                'itemId' => $itemId,
                'combinationId' => $opening['combinationId'],
                'locationId' => $opening['locationId'],
                'quantityDelta' => $opening['delta'],
                'movementType' => self::MOVE_ADJUST,
                'sourceType' => 'catalog_edit',
                'sourceId' => $itemId,
                'result' => ['operation_id' => $operationId],
            ]));
        }

        return ['operation_id' => $operationId, 'movements' => $movements, 'kept_rows' => $keptRows, 'kept_on_hand' => $keptOnHand];
    }

    /**
     * Read-only dry run of stock figures an editor or a tracking switch would
     * apply: every refusal knowable before the item row is saved.
     *
     * Codes (location_id 0 for a unit-wide refusal):
     *   item_archived     the product is archived (judged before any other code);
     *   identity_gone     the unit is not a current unit of the item (gone, archived,
     *                     or the bare item of a product with current combinations);
     *   not_tracked       the unit does not track stock (unless $assumeTracked);
     *   integrity_held    the unit is held until its figures are repaired (stock and
     *                     available carry the figures it showed when the hold began);
     *   ledger_missing    the tracked unit's figures claim stock it has no records for
     *                     (stock and available carry those figures);
     *   not_a_location    the location does not exist;
     *   location_disabled a figure would ADD stock at a location that is not enabled
     *                     (draining it is allowed);
     *   reserved_floor    a figure would LOWER the record below the quantity open
     *                     reservations hold on it, or lower a record already below it
     *                     (item_id, combination_id, on_hand, reserved, requested and
     *                     minimum carry the figures; judged before negative_floor);
     *   negative_floor    a figure below zero while negative physical stock is not permitted.
     * A figure equal to the current record within float tolerance is not a change
     * and is never refused.
     *
     * An item id of 0 is a product that is not saved yet: it has no identity,
     * tracking state or records to judge, so only the per-location codes apply,
     * each figure judged against a quantity of zero.
     *
     * @param int $itemId The item, or 0 for a product not saved yet.
     * @param int|null $combinationId The combination, or null for the item unit.
     * @param array<int, mixed> $targets locationId => absolute quantity.
     * @param bool $assumeTracked Judge the unit as tracked (it is being switched on).
     *
     * @return array<int, array{location_id: int, code: string, stock?: ?float, available?: ?float,
     *         item_id?: int, combination_id?: int, on_hand?: float, reserved?: float, requested?: float,
     *         minimum?: float}>
     *
     * @since  2.0.0
     */
    public function validateTargets(int $itemId, ?int $combinationId, array $targets, bool $assumeTracked): array
    {
        $unit = ['item' => $itemId, 'combination' => (int) ($combinationId ?? 0)];
        $saved = $itemId > 0;

        if ($saved) {
            // An archived product takes no new figures on any of its identities.
            if ($this->itemArchived(itemId: $unit['item'])) {
                return [['location_id' => 0, 'code' => 'item_archived']];
            }

            // Only a current unit takes new figures: an archived combination, the
            // bare item of a product with combinations and a vanished identity
            // are all gone for an editor.
            if (!$this->isCurrentIdentity(itemId: $unit['item'], combinationId: $unit['combination'])) {
                return [['location_id' => 0, 'code' => 'identity_gone']];
            }

            $tracked = $this->resolveUnit(itemId: $unit['item'], combinationId: $unit['combination']) !== null;

            if (!$tracked && !$assumeTracked) {
                return [['location_id' => 0, 'code' => 'not_tracked']];
            }

            // A held unit reports the figures it showed when the hold began, before
            // the missing-records test, so an editor names the hold, never a lack
            // of records it no longer has.
            $hold = $this->openIntegrityHold(unit: $unit, lock: false);

            if ($hold !== null) {
                return [['location_id' => 0, 'code' => 'integrity_held', 'stock' => $hold['stock'], 'available' => $hold['available']]];
            }

            $defect = $tracked ? $this->ledgerDefect(unit: $unit) : null;

            if ($defect !== null) {
                return [['location_id' => 0, 'code' => 'ledger_missing', 'stock' => $defect['stock'], 'available' => $defect['available']]];
            }
        }

        if ($targets === []) {
            return [];
        }

        $current = [];
        $reserved = [];

        if ($saved) {
            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['location_id', 'quantity', 'reserved_quantity']))
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
                . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $unit['item']
                . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . $unit['combination'],
            );

            foreach ($this->db->loadObjectList() ?: [] as $row) {
                $current[(int) $row->location_id] = (float) $row->quantity;
                $reserved[(int) $row->location_id] = (float) $row->reserved_quantity;
            }
        }

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'state'])) . ' FROM ' . $this->db->quoteName('#__alfa_inventory_locations')
            . ' WHERE ' . $this->db->quoteName('id') . ' IN ('
            . implode(',', array_map('intval', array_keys($targets)) ?: [0]) . ')',
        );
        $locations = [];

        foreach ($this->db->loadObjectList() ?: [] as $location) {
            $locations[(int) $location->id] = (int) $location->state === 1;
        }

        $allowNegative = $this->allowNegativePhysical();
        $problems = [];

        foreach ($targets as $locationId => $target) {
            $locationId = (int) $locationId;
            $target = (float) $target;

            if ($locationId <= 0 || !isset($locations[$locationId])) {
                $problems[] = ['location_id' => $locationId, 'code' => 'not_a_location'];
                continue;
            }

            $now = $current[$locationId] ?? 0.0;

            if (InventoryAvailabilityService::quantitiesAgree(a: $now, b: $target)) {
                continue;
            }

            if ($target > $now && !$locations[$locationId]) {
                $problems[] = ['location_id' => $locationId, 'code' => 'location_disabled'];
                continue;
            }

            // A reduction may not leave the record below what open reservations
            // hold on it (a record already below them may not go lower at all).
            $reservedHere = $reserved[$locationId] ?? 0.0;

            if ($target < $now && $reservedHere > self::TOL && $target < $reservedHere - self::TOL) {
                $problems[] = [
                    'location_id' => $locationId,
                    'code' => 'reserved_floor',
                    'item_id' => $unit['item'],
                    'combination_id' => $unit['combination'],
                    'on_hand' => $now,
                    'reserved' => $reservedHere,
                    'requested' => $target,
                    'minimum' => InventoryReservedFloorException::minimumFor(onHand: $now, reserved: $reservedHere),
                ];
                continue;
            }

            if ($target < -self::TOL && !$allowNegative) {
                $problems[] = ['location_id' => $locationId, 'code' => 'negative_floor'];
            }
        }

        return $problems;
    }

    /**
     * Open (ACTIVE) reservations naming the listed units of an item.
     *
     * @param int $itemId The item.
     * @param int[] $combinationIds The units (0 = the item unit).
     * @param bool $lock Count under a shared lock (inside a transaction).
     *
     *
     * @since  2.0.0
     */
    public function countActiveReservations(int $itemId, array $combinationIds, bool $lock): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $combinationIds), static fn (int $id): bool => $id >= 0)));

        if ($ids === []) {
            return 0;
        }

        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_inventory_reservations')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . $this->db->quoteName('combination_id') . ' IN (' . implode(',', $ids) . ')'
            . ' AND ' . $this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE)
            . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );

        return (int) $this->db->loadResult();
    }

    /**
     * The open order lines placed as stock claims that still rely on the listed
     * units of an item, counted with the orders they belong to: lines of open orders
     * with quantity never yet delivered from the unit, whether or not a reservation
     * was made for them ({@see OpenLineClaims}). Stock tracking of a unit is not
     * switched off while one exists ({@see self::changeTracking()}).
     *
     * Read through the one definition retirement, archiving and the audited
     * write-off use ({@see self::unitClaims()}); nothing else decides which lines
     * rely on a unit.
     *
     * @param int $itemId The item.
     * @param int[] $combinationIds The units (0 = the item unit); none listed, none counted.
     * @param bool $lock Inside the caller's transaction: lock the units' stock
     *                   records and read their reservations under a shared lock,
     *                   as unitClaims() does. False takes no lock (a check made
     *                   before a save).
     *
     * @return array{lines: int, orders: int} The relying lines, and the distinct orders they belong to.
     *
     * @since  2.0.0
     */
    public function relyingOpenLines(int $itemId, array $combinationIds, bool $lock): array
    {
        $lines = 0;
        $orders = [];

        foreach ($this->unitClaims(itemId: $itemId, combinationIds: $combinationIds, lock: $lock)['blockers'] as $blocker) {
            foreach ($blocker['open_lines'] as $line) {
                $lines++;
                $orders[(int) $line['order_id']] = true;
            }
        }

        return ['lines' => $lines, 'orders' => \count($orders)];
    }

    /**
     * Whether stock identities track stock now, read under their own locks: for a
     * caller that judges an order on the tracking of its lines' units and must hold
     * the answer until its transaction ends.
     *
     * In two rounds, each in ascending order of item and combination whatever order
     * the identities are given in. First every stock record of every identity is
     * locked, each by its own key and in location order
     * ({@see self::lockUnitRecords()}). Only then are the identities' rows read
     * under their locks in the kernel's lock order: the combination row with its
     * item row, or the item row and then the item's current combinations
     * ({@see self::assertUnitIdentity()}). No row of a product is therefore held
     * while a stock record is still asked for: two variants of one product share
     * its item row, and a command that holds the second variant's records and waits
     * for that row would otherwise wait for a caller that holds the row and waits
     * for those records. A tracking switch takes the same records and rows first
     * ({@see self::changeTracking()}), so it has either committed, and is read
     * here, or it waits until the caller's transaction ends. Two callers judging
     * the same identities take them in the same order, so they queue on the first
     * one. Meaningful only inside a transaction. A caller that also holds an
     * order's row takes that row first, as a fulfilment does.
     *
     * @param array<int, array{item: int, combination: int}> $units The identities; one named twice is read once.
     *
     * @return array<string, bool|null> "item:combination" => whether it tracks stock now, or null when
     *                                  it is not a current unit any more (gone, archived, or the product itself beside
     *                                  current combinations).
     *
     * @since  2.0.0
     */
    public function lockedUnitTracking(array $units): array
    {
        $named = [];

        foreach ($units as $unit) {
            $unit = ['item' => (int) ($unit['item'] ?? 0), 'combination' => (int) ($unit['combination'] ?? 0)];

            if ($unit['item'] > 0) {
                $named[$unit['item'] . ':' . $unit['combination']] = $unit;
            }
        }

        uasort(
            array: $named,
            callback: static fn (array $one, array $other): int => [$one['item'], $one['combination']] <=> [$other['item'], $other['combination']],
        );

        $tracking = [];

        // Every identity's stock records, before the first row of any of them.
        foreach ($named as $unit) {
            $this->lockUnitRecords(unit: $unit);
        }

        foreach ($named as $key => $unit) {
            try {
                $tracking[$key] = $this->assertUnitIdentity(
                    itemId: $unit['item'],
                    combinationId: $unit['combination'],
                    historical: false,
                );
            } catch (InventoryUnitException) {
                $tracking[$key] = null;
            }
        }

        return $tracking;
    }

    /**
     * The units of an item that still hold stock and therefore cannot be retired
     * (deleted with their records), archived or written off, judged record by
     * record: a unit is blocked while any of its records has a quantity or reserved
     * quantity not at zero, an open reservation names it, a stock effect of an order
     * holding a line that acts on it (sold as it, or resolved to it) is not applied,
     * or an open order line placed as a stock claim still relies on it to deduct its
     * stock, reserved or not ({@see OpenLineClaims}). +3 at one location and -3 at
     * another is not clear.
     *
     * @param int $itemId The item.
     * @param int[]|null $combinationIds The units (0 = the item's own stock), or null for
     *                                   every identity of the item (item level,
     *                                   combinations in any state and records naming
     *                                   another item's combination).
     *
     * @return array<int, array{combination_id: int, rows_not_zero: int, on_hand: float, reserved: float, active_reservations: int, pending_effects: int, rows: array, open_lines: array}>
     *
     * @since  2.0.0
     */
    public function retirementBlockers(int $itemId, ?array $combinationIds): array
    {
        return $this->unitClaims(itemId: $itemId, combinationIds: $combinationIds, lock: false)['blockers'];
    }

    /**
     * retirementBlockers() inside the caller's transaction, with the scoped
     * records locked (FOR UPDATE, by id) and the reservations read under a shared
     * lock.
     *
     * @param int $itemId The item.
     * @param int[] $combinationIds The units (0 = the item's own stock).
     *
     *
     * @throws InventoryUnitRetirementException When any of them still holds stock.
     *
     * @since  2.0.0
     */
    public function assertUnitsClear(int $itemId, array $combinationIds): void
    {
        $blockers = $this->unitClaims(itemId: $itemId, combinationIds: $combinationIds, lock: true)['blockers'];

        if ($blockers !== []) {
            throw new InventoryUnitRetirementException(itemId: $itemId, blockers: $blockers);
        }
    }

    /**
     * Retire units of an item: delete their stock records once every one of them
     * is at zero, with their terminal (non-active) reservations. A terminal
     * reservation of an order line that still exists is kept: it is that line's
     * stock history, from which a line without a placement record is judged a stock
     * claim ({@see OpenLineClaims::placedTrackedSql()}). Never writes stock off: it
     * locks the records first, refuses exactly as assertUnitsClear(), and then
     * deletes only records already at zero. No movement and no quantity change; one
     * 'retire' operation.
     *
     * @param int $itemId The item.
     * @param int[]|null $combinationIds The units (0 = the item's own stock), or null for every record of the item.
     * @param string $sourceType The retiring domain ('catalog_edit').
     * @param int $sourceId Source row id (the item).
     * @param InventoryContext $context The operator.
     *
     * @return array{operation_id: string, rows: int, reservations: int} The operation, the records deleted
     *                                                                   and the terminal reservations deleted.
     *
     * @throws InventoryUnitRetirementException When any of them still holds stock.
     *
     * @since  2.0.0
     */
    public function retireUnits(int $itemId, ?array $combinationIds, string $sourceType, int $sourceId, InventoryContext $context): array
    {
        $operationId = $this->newOperationId();

        $this->db->transactionStart(true);

        try {
            $claims = $this->unitClaims(itemId: $itemId, combinationIds: $combinationIds, lock: true);

            if ($claims['blockers'] !== []) {
                throw new InventoryUnitRetirementException(itemId: $itemId, blockers: $claims['blockers']);
            }

            $rowIds = array_map(static fn (object $row): int => (int) $row->id, $claims['rows']);

            if ($rowIds !== []) {
                $this->db->setQuery(
                    'DELETE FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
                    . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $rowIds) . ')',
                )->execute();
            }

            $terminal = $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['id', 'source_type', 'source_line_id']))
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_reservations')
                . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . $claims['scope']
                . ' AND ' . $this->db->quoteName('state') . ' <> ' . $this->db->quote(self::STATE_ACTIVE)
                . ' ORDER BY ' . $this->db->quoteName('id'),
            )->loadObjectList() ?: [];
            $orderLineIds = [];

            foreach ($terminal as $reservation) {
                if ((string) $reservation->source_type === 'order' && (int) $reservation->source_line_id > 0) {
                    $orderLineIds[(int) $reservation->source_line_id] = (int) $reservation->source_line_id;
                }
            }

            $existingLines = $orderLineIds === [] ? [] : array_flip(array_map('intval', $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_order_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $orderLineIds) . ')',
            )->loadColumn() ?: []));
            $deletable = [];

            foreach ($terminal as $reservation) {
                if ((string) $reservation->source_type === 'order' && isset($existingLines[(int) $reservation->source_line_id])) {
                    continue;
                }

                $deletable[] = (int) $reservation->id;
            }

            if ($deletable !== []) {
                $this->db->setQuery(
                    'DELETE FROM ' . $this->db->quoteName('#__alfa_inventory_reservations')
                    . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $deletable) . ')',
                )->execute();
            }

            $reservations = \count($deletable);

            $this->recordOperation(operationId: $operationId, command: 'retire', sourceType: $sourceType, sourceId: $sourceId, context: $context);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        return ['operation_id' => $operationId, 'rows' => \count($rowIds), 'reservations' => $reservations];
    }

    /**
     * Archive combinations that order history still refers to: the audited way a
     * variant leaves the current catalogue while its row and stock records stay the
     * stock identity its orders are fulfilled, cancelled and returned against.
     *
     * Every named combination must be clear first, exactly as retirement requires:
     * each of its records at zero, nothing reserved, no open reservation and no
     * pending stock effect (refused with InventoryUnitRetirementException, nothing
     * written). When no current combination would remain, the product becomes its
     * own unit again and its figure is read from its own records, so those must be
     * clear as well. Locks in the kernel's order (the item's records, the named
     * records, their locations, the combination rows, the item row), takes what
     * each combination still carries out of its parent's figures (its physical
     * quantity, and its salable quantity when it counted for sale) so the parent
     * stays exact, sets the rows to archived and retires their low-stock and
     * backorder notices. Stock records and reservations are kept, no movement is
     * written, and one 'archive' operation is recorded.
     *
     * @param int $itemId The item.
     * @param int[] $combinationIds Its combinations to archive.
     * @param string $sourceType The archiving domain ('catalog_edit').
     * @param int $sourceId Source row id (the item).
     * @param InventoryContext $context The operator.
     *
     * @return array{operation_id: ?string, archived: int[]} The operation (null when nothing was named) and the archived ids.
     *
     * @throws InventoryUnitRetirementException When a combination, or the product itself when it would become
     *                                          its own unit again, still holds stock or claims.
     * @throws InventoryUnitException When a combination is gone, belongs to another item or is already
     *                                archived, or the item is gone.
     *
     * @since  2.0.0
     */
    public function archiveUnits(int $itemId, array $combinationIds, string $sourceType, int $sourceId, InventoryContext $context): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $combinationIds), static fn (int $id): bool => $id > 0)));
        sort($ids);

        if ($ids === []) {
            return ['operation_id' => null, 'archived' => []];
        }

        $in = implode(',', $ids);
        $operationId = $this->newOperationId();

        $this->db->transactionStart(true);

        try {
            $this->lockItemStock(itemId: $itemId);

            // Clear first, record by record: nothing held, reserved or owed.
            $claims = $this->unitClaims(itemId: $itemId, combinationIds: $ids, lock: true);

            if ($claims['blockers'] !== []) {
                throw new InventoryUnitRetirementException(itemId: $itemId, blockers: $claims['blockers']);
            }

            $enabled = $this->locationsEnabled(
                locationIds: array_map(static fn (object $row): int => (int) $row->location_id, $claims['rows']),
                lock: true,
            );

            $combinations = $this->lockCombinationRows(itemId: $itemId, in: $in);

            foreach ($ids as $combinationId) {
                if (!isset($combinations[$combinationId])) {
                    throw new InventoryUnitException(itemId: $itemId, combinationId: $combinationId, reason: InventoryUnitException::COMBINATION_GONE);
                }

                if (!CombinationState::isCurrent(state: $combinations[$combinationId]->state)) {
                    throw new InventoryUnitException(itemId: $itemId, combinationId: $combinationId, reason: InventoryUnitException::COMBINATION_ARCHIVED);
                }
            }

            $itemFlag = $this->lockItemRow(itemId: $itemId);

            // The last current combinations leave: the product's own records become
            // its figure again, so they must hold nothing.
            $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' AND ' . $this->db->quoteName('id') . ' NOT IN (' . $in . ')'
                . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName('state'))
                . ' LOCK IN SHARE MODE',
            );

            if ((int) $this->db->loadResult() === 0) {
                $this->assertUnitsClear(itemId: $itemId, combinationIds: [0]);
            }

            foreach ($claims['rows'] as $row) {
                $combination = $combinations[(int) $row->combination_id];
                $countsForSale = (int) $combination->state === CombinationState::PUBLISHED
                    && (int) ($combination->manage_stock ?? $itemFlag) === 1;
                $physical = (float) $row->quantity;
                $salable = $countsForSale && ($enabled[(int) $row->location_id] ?? false)
                    ? (float) $row->quantity - (float) $row->reserved_quantity
                    : 0.0;

                if (abs($physical) <= self::EPS && abs($salable) <= self::EPS) {
                    continue;
                }

                $this->propagateToReadModels(
                    stockId: (int) $row->id,
                    unitPhysicalDelta: 0.0,
                    unitSalableDelta: 0.0,
                    parentPhysicalDelta: -$physical,
                    parentSalableDelta: -$salable,
                );
            }

            $this->db->setQuery(
                'UPDATE ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' SET ' . $this->db->quoteName('state') . ' = ' . CombinationState::ARCHIVED
                . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $in . ')'
                . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId,
            )->execute();

            // An archived unit is never evaluated for notices again: what it still
            // shows is retired with the archive (and restored if it rolls back).
            foreach ($ids as $combinationId) {
                $this->clearUnitNotices(itemId: $itemId, combinationId: $combinationId);
            }

            $this->recordOperation(operationId: $operationId, command: 'archive', sourceType: $sourceType, sourceId: $sourceId, context: $context);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $this->logOnceStands(
            label: 'inventory line: combinations archived',
            entry: sprintf(
                'Inventory: combinations %s of item #%d archived by user #%d (operation %s); their stock identity is kept for order history.',
                implode(', ', array_map(static fn (int $id): string => '#' . $id, $ids)),
                $itemId,
                $context->userId,
                $operationId,
            ),
            priority: Log::INFO,
        );

        return ['operation_id' => $operationId, 'archived' => $ids];
    }

    /**
     * Bring archived combinations back into the current catalogue (published),
     * with the stock records they kept: the way generating an archived
     * combination's signature again reuses its row and identity.
     *
     * Locks in the kernel's order (the item's records, the units' records, their
     * locations, the combination rows, the item row). Every named combination must
     * be an archived row of the item, and the product itself must not be archived
     * (read under its row lock): an archived product is restored first. When the
     * product had no current combination, it stops being its own unit, so its own
     * records must be clear. Sets the rows to published and adds what each carries
     * to its parent's figures: its physical quantity, and its salable quantity at
     * enabled locations when it tracks stock. The ledger is untouched; one
     * 'unarchive' operation.
     *
     * @param int $itemId The item.
     * @param int[] $combinationIds Its archived combinations.
     * @param InventoryContext $context The operator.
     *
     * @return string|null The operation id, or null when nothing was named.
     *
     * @throws InventoryUnitException When a combination is gone, belongs to another item or is not
     *                                archived, or the item is gone or archived.
     * @throws InventoryUnitRetirementException When the product's own records still hold stock or claims while it
     *                                          would stop being its own unit.
     *
     * @since  2.0.0
     */
    public function reactivateUnits(int $itemId, array $combinationIds, InventoryContext $context): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $combinationIds), static fn (int $id): bool => $id > 0)));
        sort($ids);

        if ($ids === []) {
            return null;
        }

        $in = implode(',', $ids);
        $operationId = $this->newOperationId();

        $this->db->transactionStart(true);

        try {
            $this->lockItemStock(itemId: $itemId);

            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['id', 'combination_id', 'location_id', 'quantity', 'reserved_quantity']))
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
                . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' AND ' . $this->db->quoteName('combination_id') . ' IN (' . $in . ')'
                . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
            );
            $rows = $this->db->loadObjectList() ?: [];

            $enabled = $this->locationsEnabled(
                locationIds: array_map(static fn (object $row): int => (int) $row->location_id, $rows),
                lock: true,
            );

            $combinations = $this->lockCombinationRows(itemId: $itemId, in: $in);

            foreach ($ids as $combinationId) {
                if (!isset($combinations[$combinationId])) {
                    throw new InventoryUnitException(itemId: $itemId, combinationId: $combinationId, reason: InventoryUnitException::COMBINATION_GONE);
                }

                if (CombinationState::isCurrent(state: $combinations[$combinationId]->state)) {
                    throw new InventoryUnitException(itemId: $itemId, combinationId: $combinationId, reason: InventoryUnitException::COMBINATION_NOT_ARCHIVED);
                }
            }

            $itemFlag = $this->lockItemRow(itemId: $itemId);

            // An archived product is structurally frozen: none of its combinations
            // returns to the current catalogue until the product itself is restored.
            $this->db->setQuery(
                query: 'SELECT ' . $this->db->quoteName(name: 'state') . ' FROM ' . $this->db->quoteName(name: '#__alfa_items')
                . ' WHERE ' . $this->db->quoteName(name: 'id') . ' = ' . $itemId . ' FOR UPDATE',
            );

            if (!CombinationState::isCurrent(state: $this->db->loadResult())) {
                throw new InventoryUnitException(itemId: $itemId, combinationId: 0, reason: InventoryUnitException::ITEM_ARCHIVED);
            }

            // A product without current combinations is its own unit: before it
            // stocks through combinations again, its own records must hold nothing.
            $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName('state'))
                . ' LOCK IN SHARE MODE',
            );

            if ((int) $this->db->loadResult() === 0) {
                $this->assertUnitsClear(itemId: $itemId, combinationIds: [0]);
            }

            $this->db->setQuery(
                'UPDATE ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' SET ' . $this->db->quoteName('state') . ' = ' . CombinationState::PUBLISHED
                . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $in . ')'
                . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId,
            )->execute();

            foreach ($rows as $row) {
                $combination = $combinations[(int) $row->combination_id];
                $tracked = (int) ($combination->manage_stock ?? $itemFlag) === 1;
                $physical = (float) $row->quantity;
                $salable = $tracked && ($enabled[(int) $row->location_id] ?? false)
                    ? (float) $row->quantity - (float) $row->reserved_quantity
                    : 0.0;

                if (abs($physical) <= self::EPS && abs($salable) <= self::EPS) {
                    continue;
                }

                $this->propagateToReadModels(
                    stockId: (int) $row->id,
                    unitPhysicalDelta: 0.0,
                    unitSalableDelta: 0.0,
                    parentPhysicalDelta: $physical,
                    parentSalableDelta: $salable,
                );
            }

            $this->recordOperation(operationId: $operationId, command: 'unarchive', sourceType: 'catalog_edit', sourceId: $itemId, context: $context);

            foreach ($ids as $combinationId) {
                $this->evaluateUnitNotices(itemId: $itemId, combinationId: $combinationId);
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        return $operationId;
    }

    /**
     * Archive products, or restore archived products to the active catalogue: the
     * only path by which a product leaves or re-enters current commerce
     * (items.state 2, the archived value of {@see CombinationState}).
     *
     * Archive: per product in ascending order, its stock records are locked (FOR
     * UPDATE, by id), every identity of it must be clear: every record at zero,
     * nothing reserved, no open reservation, no pending stock effect and no open
     * order line that still relies on it (InventoryUnitRetirementException
     * otherwise), and its figures must agree with its records
     * (InventoryProjectionMismatchException otherwise), then its row is locked. Only
     * then, once for the batch, $write runs (the caller's own catalogue write that
     * sets the state, with its events), the state is verified as archived, the
     * products' low-stock and backorder notices are retired and one 'archive'
     * operation is recorded per product. From then on the product and every
     * identity of it are kept only for order history: nothing new is reserved,
     * received or sold on them, while fulfilment, cancellation, returns and the
     * audited write-off still act on them. Its figures keep following its records
     * unchanged, so restoring it changes no figure.
     *
     * Restore to the active catalogue: the audited lifecycle transition out of the
     * archive, called only by the catalogue's explicit restore action (publishing
     * never un-archives). Per product in ascending order, its stock records are
     * locked (FOR UPDATE, by id) and its structure must be sound
     * ({@see self::assertRestorable()}): beside a current combination its own
     * identity is clear, its figures agree with its records, and no identity of it
     * is held. Then its row is locked, $write runs (the caller's write of the
     * unpublished state, with its events), the state is verified as no longer
     * archived and one 'unarchive' operation is recorded per product. No
     * historical identity is rewritten: the product is then an ordinary current
     * product, sold, published and given variants under the normal rules.
     *
     * One transaction: a refusal, a failed write or a state the write did not set
     * rolls everything back. No stock record, movement, reservation, resolution,
     * placement record or figure is written.
     *
     * @param int[] $itemIds The products.
     * @param bool $archive True to archive, false to restore.
     * @param callable $write fn (): mixed - the caller's write of the products' state.
     * @param InventoryContext $context The operator.
     *
     * @return array{operation_ids: array<int, string>, written: mixed} Product id => its operation; what $write returned.
     *
     * @throws InventoryUnitRetirementException When a product to archive still holds stock or claims, or a
     *                                          product to restore holds them on its own identity beside a
     *                                          current combination.
     * @throws InventoryProjectionMismatchException When a product's figures disagree with its records.
     * @throws InventoryIntegrityException When an identity of a product to restore is held.
     * @throws InventoryUnitException When a product is gone.
     * @throws RuntimeException When the write did not set the state.
     *
     * @since  2.0.0
     */
    public function changeItemArchive(array $itemIds, bool $archive, callable $write, InventoryContext $context): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        sort($ids);

        if ($ids === []) {
            return ['operation_ids' => [], 'written' => $write()];
        }

        $operationIds = [];

        $this->db->transactionStart(true);

        try {
            foreach ($ids as $itemId) {
                if ($archive) {
                    $this->lockItemStock(itemId: $itemId);

                    $blockers = $this->unitClaims(itemId: $itemId, combinationIds: null, lock: true)['blockers'];

                    if ($blockers !== []) {
                        throw new InventoryUnitRetirementException(itemId: $itemId, blockers: $blockers);
                    }

                    $corrections = $this->projectionCorrections(itemId: $itemId, lock: true);

                    if ($corrections !== []) {
                        InventoryIntegrityNotice::queue(itemId: $itemId, kind: InventoryIntegrityNotice::KIND_FIGURES_DISAGREE);

                        throw new InventoryProjectionMismatchException(itemId: $itemId, rows: $corrections);
                    }
                } else {
                    $this->lockItemStock(itemId: $itemId);
                    $this->assertRestorable(itemId: $itemId);
                }

                $this->lockItemRow(itemId: $itemId);
            }

            $written = $write();

            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['id', 'state'])) . ' FROM ' . $this->db->quoteName('#__alfa_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
                . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
            );
            $states = [];

            foreach ($this->db->loadObjectList() ?: [] as $row) {
                $states[(int) $row->id] = !CombinationState::isCurrent(state: $row->state);
            }

            foreach ($ids as $itemId) {
                if (($states[$itemId] ?? !$archive) !== $archive) {
                    throw new RuntimeException(sprintf(
                        'InventoryService: product #%d was not %s; nothing was changed.',
                        $itemId,
                        $archive ? 'archived' : 'restored to the active catalogue',
                    ));
                }
            }

            foreach ($ids as $itemId) {
                // An archived product is never offered for sale, so none of its
                // units is evaluated for notices again: what they show is retired.
                if ($archive) {
                    $this->clearUnitNotices(itemId: $itemId, combinationId: 0);

                    $this->db->setQuery(
                        'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                        . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                        . ' ORDER BY ' . $this->db->quoteName('id'),
                    );

                    foreach (array_map('intval', $this->db->loadColumn() ?: []) as $combinationId) {
                        $this->clearUnitNotices(itemId: $itemId, combinationId: $combinationId);
                    }
                }

                $operationIds[$itemId] = $this->newOperationId();
                $this->recordOperation(
                    operationId: $operationIds[$itemId],
                    command: $archive ? 'archive' : 'unarchive',
                    sourceType: 'catalog_edit',
                    sourceId: $itemId,
                    context: $context,
                );
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $this->logOnceStands(
            label: 'inventory line: products archived or restored',
            entry: sprintf(
                'Inventory: products %s were %s by user #%d (operations %s).',
                implode(', ', array_map(static fn (int $id): string => '#' . $id, $ids)),
                $archive ? 'archived, their stock identities kept for order history,' : 'restored to the active catalogue',
                $context->userId,
                implode(', ', $operationIds),
            ),
            priority: Log::INFO,
        );

        return ['operation_ids' => $operationIds, 'written' => $written];
    }

    /**
     * Refuse to restore an archived product to the active catalogue unless its
     * stock structure is sound, judged under the kernel's locks (the caller has
     * locked the product's stock records):
     *   - beside a current combination, the product's own identity (item, 0) must
     *     be clear: every record at zero, nothing reserved, no open reservation, no
     *     pending stock effect and no open order line that still relies on it;
     *   - its figures must agree with its records (figures that claim stock without
     *     records included);
     *   - no identity of it may be held until its figures are repaired.
     * A product without current combinations is its own unit again: the stock on
     * its own records stays and is sold once the product is published.
     *
     * @param int $itemId The product.
     *
     *
     * @throws InventoryUnitRetirementException When its own identity holds stock or claims beside a current
     *                                          combination.
     * @throws InventoryProjectionMismatchException When its figures disagree with its records.
     * @throws InventoryIntegrityException When an identity of it is held (held: true).
     *
     * @since  2.0.0
     */
    private function assertRestorable(int $itemId): void
    {
        $claims = $this->unitClaims(itemId: $itemId, combinationIds: [0], lock: true);
        $ledger = $this->itemLedger(itemId: $itemId, lock: true);
        $current = array_filter(
            $ledger['combinations'],
            static fn (object $combination): bool => CombinationState::isCurrent(state: $combination->state),
        );

        if ($current !== [] && $claims['blockers'] !== []) {
            throw new InventoryUnitRetirementException(itemId: $itemId, blockers: $claims['blockers']);
        }

        $corrections = $this->correctionsFrom(ledger: $ledger);

        if ($corrections !== []) {
            InventoryIntegrityNotice::queue(itemId: $itemId, kind: InventoryIntegrityNotice::KIND_FIGURES_DISAGREE);

            throw new InventoryProjectionMismatchException(itemId: $itemId, rows: $corrections);
        }

        $hold = $this->openHoldOfItem(itemId: $itemId);

        if ($hold !== null) {
            throw new InventoryIntegrityException(
                itemId: $itemId,
                combinationId: $hold['combination'],
                stock: $hold['stock'],
                available: $hold['available'],
                held: true,
            );
        }
    }

    /**
     * The first open integrity hold on any identity of an item (the product itself
     * or any combination id it names), read as a current read under a shared lock.
     *
     * @param int $itemId The item.
     *
     * @return array{id: int, combination: int, stock: ?float, available: ?float}|null The hold and the
     *                                                                                 figures its unit showed when it began, or null when no identity of the item is held.
     *
     * @since  2.0.0
     */
    private function openHoldOfItem(int $itemId): ?array
    {
        $this->db->setQuery(
            query: 'SELECT ' . implode(', ', $this->db->quoteName(name: ['id', 'combination_id', 'stock_figure', 'available_figure']))
            . ' FROM ' . $this->db->quoteName(name: '#__alfa_inventory_integrity_holds')
            . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . $itemId
            . ' AND ' . $this->db->quoteName(name: 'released_operation_id') . ' IS NULL'
            . ' ORDER BY ' . $this->db->quoteName(name: 'id')
            . ' LOCK IN SHARE MODE',
        );
        $row = $this->db->loadObject();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'combination' => (int) $row->combination_id,
            'stock' => $row->stock_figure !== null ? (float) $row->stock_figure : null,
            'available' => $row->available_figure !== null ? (float) $row->available_figure : null,
        ];
    }

    /**
     * Lock named combination rows of an item (FOR UPDATE, by id).
     *
     * @param int $itemId The item.
     * @param string $in The combination ids, comma-separated integers.
     *
     * @return array<int, object> combination id => id, state, manage_stock; rows of other items are absent.
     *
     * @since  2.0.0
     */
    private function lockCombinationRows(int $itemId, string $in): array
    {
        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'state', 'manage_stock']))
            . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations')
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . $in . ')'
            . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
        );
        $combinations = [];

        foreach ($this->db->loadObjectList() ?: [] as $combination) {
            $combinations[(int) $combination->id] = $combination;
        }

        return $combinations;
    }

    /**
     * Lock the item row (FOR UPDATE) and read its stock tracking flag.
     *
     * @param int $itemId The item.
     *
     * @return int The item's manage_stock (NULL reads as 0).
     *
     * @throws InventoryUnitException When the item is gone.
     *
     * @since  2.0.0
     */
    private function lockItemRow(int $itemId): int
    {
        $this->db->setQuery(
            'SELECT COALESCE(' . $this->db->quoteName('manage_stock') . ', 0) FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId . ' FOR UPDATE',
        );
        $flag = $this->db->loadResult();

        if ($flag === null) {
            throw new InventoryUnitException(itemId: $itemId, combinationId: 0, reason: InventoryUnitException::ITEM_GONE);
        }

        return (int) $flag;
    }

    /**
     * The stock records and outstanding claims of an item's units, per unit: records
     * not at zero, reserved quantity, open reservations, pending stock effects of
     * order lines acting on the unit, and the open order lines that still rely on the
     * unit to deliver quantity they have never received ({@see OpenLineClaims}), which
     * need no reservation to count. Quantity a physical return brought back is not
     * outstanding: it is ordinary stock of the unit and claims nothing, whether the
     * order is open or not; sending it out again is a new claim (an active
     * reservation, counted here, or the deducting command that ships it). The open
     * lines are read with a plain read after the scoped records are locked: a
     * fulfilment of such a line locks the same records first, and one that is
     * reopened meanwhile is refused later by the physical floor, never skipped.
     *
     * @param int $itemId The item.
     * @param int[]|null $combinationIds The units, or null for every identity of the item
     *                                   (historical ones included).
     * @param bool $lock Lock the records (FOR UPDATE, by id) and read the
     *                   reservations under a shared lock.
     *
     * @return array{blockers: array, rows: object[], scope: string} blockers: per blocked unit
     *                                                               combination_id, rows_not_zero, on_hand, reserved, active_reservations,
     *                                                               pending_effects, rows and open_lines (order_id, line_id, ordered, outstanding).
     *
     * @since  2.0.0
     */
    private function unitClaims(int $itemId, ?array $combinationIds, bool $lock): array
    {
        $scope = '';
        $units = null;

        if ($combinationIds !== null) {
            $units = array_values(array_unique(array_filter(array_map('intval', $combinationIds), static fn (int $id): bool => $id >= 0)));

            if ($units === []) {
                return ['blockers' => [], 'rows' => [], 'scope' => ' AND 1 = 0'];
            }

            $scope = ' AND ' . $this->db->quoteName('combination_id') . ' IN (' . implode(',', $units) . ')';
        }

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'combination_id', 'location_id', 'quantity', 'reserved_quantity']))
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId . $scope
            . ' ORDER BY ' . $this->db->quoteName('id') . ($lock ? ' FOR UPDATE' : ''),
        );
        $rows = $this->db->loadObjectList() ?: [];

        $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('combination_id') . ', COUNT(*) AS ' . $this->db->quoteName('n')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_reservations')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId . $scope
            . ' AND ' . $this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE)
            . ' GROUP BY ' . $this->db->quoteName('combination_id')
            . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );
        $active = [];

        foreach ($this->db->loadObjectList() ?: [] as $row) {
            $active[(int) $row->combination_id] = (int) $row->n;
        }

        // A pending effect weighs on the unit each order line ACTS on: the identity
        // it was sold as, or the one its effective recorded resolution names when
        // the line still names that resolution's original or resolved identity (the
        // rule of lineIdentity()).
        $frozenCombination = 'COALESCE(oi.' . $this->db->quoteName('id_combination') . ', 0)';
        $resolved = 'r.' . $this->db->quoteName('id') . ' IS NOT NULL AND ('
            . '(oi.' . $this->db->quoteName('id_item') . ' = r.' . $this->db->quoteName('original_item_id')
            . ' AND ' . $frozenCombination . ' = r.' . $this->db->quoteName('original_combination_id') . ')'
            . ' OR (oi.' . $this->db->quoteName('id_item') . ' = r.' . $this->db->quoteName('resolved_item_id')
            . ' AND ' . $frozenCombination . ' = r.' . $this->db->quoteName('resolved_combination_id') . '))';
        $actingItem = 'CASE WHEN ' . $resolved . ' THEN r.' . $this->db->quoteName('resolved_item_id') . ' ELSE oi.' . $this->db->quoteName('id_item') . ' END';
        $actingCombination = 'CASE WHEN ' . $resolved . ' THEN r.' . $this->db->quoteName('resolved_combination_id') . ' ELSE ' . $frozenCombination . ' END';

        $this->db->setQuery(
            'SELECT ' . $actingCombination . ' AS ' . $this->db->quoteName('combination_id')
            . ', COUNT(DISTINCT o.' . $this->db->quoteName('id') . ') AS ' . $this->db->quoteName('n')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_effect_outbox', 'o')
            . ' INNER JOIN ' . $this->db->quoteName('#__alfa_order_items', 'oi')
            . ' ON oi.' . $this->db->quoteName('id_order') . ' = o.' . $this->db->quoteName('order_id')
            . ' LEFT JOIN ' . $this->db->quoteName('#__alfa_inventory_line_resolutions', 'r')
            . ' ON r.' . $this->db->quoteName('order_item_id') . ' = oi.' . $this->db->quoteName('id')
            . ' AND ' . self::effectiveResolutionSql(db: $this->db, alias: 'r')
            . ' WHERE o.' . $this->db->quoteName('status') . ' <> ' . $this->db->quote('applied')
            . ' AND oi.' . $this->db->quoteName('id_item') . ' = ' . $itemId
            . ' AND ' . $actingItem . ' = ' . $itemId
            . ' GROUP BY ' . $actingCombination,
        );
        $pending = [];

        foreach ($this->db->loadObjectList() ?: [] as $row) {
            if ($units === null || \in_array((int) $row->combination_id, $units, true)) {
                $pending[(int) $row->combination_id] = (int) $row->n;
            }
        }

        // Open order lines that still rely on a unit count whether or not a
        // reservation was ever made for them.
        $openLines = [];

        foreach ((new OpenLineClaims(db: $this->db))->forItems(itemIds: [$itemId])[$itemId] ?? [] as $combinationId => $lines) {
            if ($units === null || \in_array((int) $combinationId, $units, true)) {
                $openLines[(int) $combinationId] = array_map(
                    static fn (array $line): array => [
                        'order_id' => $line['order_id'],
                        'line_id' => $line['line_id'],
                        'ordered' => $line['ordered'],
                        'outstanding' => $line['outstanding'],
                    ],
                    $lines,
                );
            }
        }

        $unitIds = array_unique(array_merge(
            array_map(static fn (object $row): int => (int) $row->combination_id, $rows),
            array_keys($active),
            array_keys($pending),
            array_keys($openLines),
        ));
        sort($unitIds);

        $blockers = [];

        foreach ($unitIds as $combinationId) {
            $onHand = 0.0;
            $reserved = 0.0;
            $notZero = [];

            foreach ($rows as $row) {
                if ((int) $row->combination_id !== $combinationId) {
                    continue;
                }

                $onHand += (float) $row->quantity;
                $reserved += (float) $row->reserved_quantity;

                if (abs((float) $row->quantity) > self::TOL || abs((float) $row->reserved_quantity) > self::TOL) {
                    $notZero[] = [
                        'stock_id' => (int) $row->id,
                        'location_id' => (int) $row->location_id,
                        'quantity' => (float) $row->quantity,
                        'reserved' => (float) $row->reserved_quantity,
                    ];
                }
            }

            $activeCount = $active[$combinationId] ?? 0;
            $pendingCount = $pending[$combinationId] ?? 0;
            $relying = $openLines[$combinationId] ?? [];

            if ($notZero === [] && $activeCount === 0 && $pendingCount === 0 && $relying === []) {
                continue;
            }

            $blockers[] = [
                'combination_id' => $combinationId,
                'rows_not_zero' => \count($notZero),
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'active_reservations' => $activeCount,
                'pending_effects' => $pendingCount,
                'rows' => $notZero,
                'open_lines' => $relying,
            ];
        }

        return ['blockers' => $blockers, 'rows' => $rows, 'scope' => $scope];
    }

    /**
     * Write off the stock held on a stock identity kept only for order history: the
     * audited operation that disposes of real stock nobody may sell any more.
     *
     * Eligible, judged under the identity's locks: an identity that exists and is
     * historical: the product itself (item, 0) of a product with current
     * combinations (stranded stock), an archived combination (stock a return brought
     * back after it was archived), or any identity of an archived product. Refused,
     * with nothing written: a current unit (its stock changes through the stock
     * editor); a gone, foreign or orphan identity; a reason that is not 1 to 255
     * characters; no signed-in operator; while a record holding stock also holds
     * quantity reserved by open reservations (refused with that record's figures,
     * once the identity is known to exist and to be kept only for order history, and
     * before its other claims are judged); while the identity's stock is reserved, an
     * open reservation names it or a stock effect of an order holding a line that
     * acts on it is pending; and
     * while open order lines placed as a stock claim still have quantity to be
     * delivered from it for the first time, since their fulfilment draws on exactly
     * that stock. Quantity a physical return brought back claims nothing, whether or
     * not its order is still open.
     *
     * Every record of the identity not at zero is set to zero by one 'adjust'
     * movement (source 'stock_writeoff', the product as source row, the reason as
     * note, the operator as actor); the records stay, at zero. The figures follow
     * the unchanged routing: an archived combination moves its own row, an archived
     * product its own rows, stranded stock none. One 'write_off' operation.
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination, 0 for the product itself.
     * @param string $reason Why the stock is written off.
     * @param InventoryContext $context The operator.
     *
     * @return array{operation_id: string, rows: int, written_off: float}
     *
     * @throws RuntimeException Without a reason or an operator, or for a current unit.
     * @throws InventoryUnitException When the identity is gone, belongs to another product or the
     *                                product is gone.
     * @throws InventoryReservedFloorException While a record holding stock holds reserved quantity.
     * @throws InventoryUnitRetirementException While the stock is reserved or claimed.
     * @throws InventoryOpenLinesException While open order lines still rely on the identity.
     *
     * @since  2.0.0
     */
    public function writeOffStock(int $itemId, int $combinationId, string $reason, InventoryContext $context): array
    {
        $reason = trim($reason);
        $combinationId = max(0, $combinationId);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new RuntimeException('InventoryService: a stock write-off needs a reason of 1 to 255 characters.');
        }

        if ($context->userId <= 0) {
            throw new RuntimeException('InventoryService: a stock write-off needs a signed-in operator.');
        }

        $audited = new InventoryContext(
            userId: $context->userId,
            correlationId: $context->correlationId,
            note: $reason,
            metadata: $context->metadata,
        );
        $unit = ['item' => $itemId, 'combination' => $combinationId];
        $operationId = $this->newOperationId();
        $rows = 0;
        $total = 0.0;

        $this->db->transactionStart(true);

        try {
            // The identity's records first (FOR UPDATE, by id), then its rows in the
            // kernel's lock order; the relying lines are read after the records.
            $claims = $this->unitClaims(itemId: $itemId, combinationIds: [$combinationId], lock: true);

            if ($this->lockedIdentityRole(unit: $unit) === self::ROLE_CURRENT) {
                throw new RuntimeException(sprintf(
                    'InventoryService: %s of item #%d is offered for sale; its stock changes through the stock editor.',
                    $combinationId > 0 ? 'combination #' . $combinationId : 'the product itself',
                    $itemId,
                ));
            }

            // Stock that open reservations hold is refused first, with its figures,
            // before anything is written: writing it off would leave them on stock
            // that no longer exists.
            foreach ($claims['rows'] as $row) {
                if ((float) $row->quantity > self::TOL) {
                    $this->guardReservedFloor(stock: $row, deduction: (float) $row->quantity, unit: $unit, command: 'write_off');
                }
            }

            foreach ($claims['blockers'] as $blocker) {
                if ($blocker['reserved'] > self::TOL || $blocker['active_reservations'] > 0 || $blocker['pending_effects'] > 0) {
                    throw new InventoryUnitRetirementException(itemId: $itemId, blockers: [$blocker]);
                }
            }

            foreach ($claims['blockers'] as $blocker) {
                if ($blocker['open_lines'] !== []) {
                    throw new InventoryOpenLinesException(itemId: $itemId, combinationId: $combinationId, lines: $blocker['open_lines']);
                }
            }

            foreach ($claims['rows'] as $row) {
                $quantity = (float) $row->quantity;

                if (abs($quantity) <= self::TOL) {
                    continue;
                }

                $this->applyStockDelta(stockId: (int) $row->id, quantityDelta: -$quantity, reservedDelta: 0);
                $this->appendMovement(
                    unit: $unit,
                    locationId: (int) $row->location_id,
                    qtyChange: -$quantity,
                    movementType: self::MOVE_ADJUST,
                    sourceType: 'stock_writeoff',
                    sourceId: $itemId,
                    sourceLineId: null,
                    operationId: $operationId,
                    context: $audited,
                );

                $rows++;
                $total += $quantity;
            }

            $this->recordOperation(operationId: $operationId, command: 'write_off', sourceType: 'stock_writeoff', sourceId: $itemId, context: $audited);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $this->logOnceStands(
            label: 'inventory line: stock written off',
            entry: sprintf(
                'Inventory: stock held on %s of item #%d written off by user #%d (operation %s): %d records, %s units. Reason: %s',
                $combinationId > 0 ? 'combination #' . $combinationId : 'the product itself',
                $itemId,
                $context->userId,
                $operationId,
                $rows,
                self::trimQty(value: $total),
                $reason,
            ),
            priority: Log::INFO,
        );

        return ['operation_id' => $operationId, 'rows' => $rows, 'written_off' => $total];
    }

    /**
     * The role of an identity read under its row locks in the kernel's lock order,
     * the combination row with its item row (FOR UPDATE), or the item row (FOR
     * UPDATE) and then its current combinations (shared), as identityRole()
     * judges it: current, or kept only for order history. An identity that does not
     * exist is refused.
     *
     * @param array{item: int, combination: int} $unit The identity.
     *
     * @return string ROLE_CURRENT or ROLE_HISTORICAL.
     *
     * @throws InventoryUnitException When the identity is gone, belongs to another item or its item is gone.
     *
     * @since  2.0.0
     */
    private function lockedIdentityRole(array $unit): string
    {
        $itemId = (int) $unit['item'];
        $combinationId = (int) $unit['combination'];

        if ($combinationId > 0) {
            $this->db->setQuery(
                'SELECT COALESCE(c.' . $this->db->quoteName('state') . ', ' . CombinationState::UNPUBLISHED . ') AS ' . $this->db->quoteName('state')
                . ', i.' . $this->db->quoteName('state') . ' AS ' . $this->db->quoteName('item_state')
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . $combinationId
                . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' FOR UPDATE',
            );
            $row = $this->db->loadObject();

            if ($row === null) {
                throw new InventoryUnitException(
                    itemId: $itemId,
                    combinationId: $combinationId,
                    reason: $this->itemExists(itemId: $itemId) ? InventoryUnitException::COMBINATION_GONE : InventoryUnitException::ITEM_GONE,
                );
            }

            return CombinationState::isCurrent(state: $row->state) && CombinationState::isCurrent(state: $row->item_state)
                ? self::ROLE_CURRENT
                : self::ROLE_HISTORICAL;
        }

        $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('state') . ' FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId . ' FOR UPDATE',
        );
        $item = $this->db->loadObject();

        if ($item === null) {
            throw new InventoryUnitException(itemId: $itemId, combinationId: 0, reason: InventoryUnitException::ITEM_GONE);
        }

        if (!CombinationState::isCurrent(state: $item->state)) {
            return self::ROLE_HISTORICAL;
        }

        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_item_combinations')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName('state'))
            . ' LOCK IN SHARE MODE',
        );

        return (int) $this->db->loadResult() > 0 ? self::ROLE_HISTORICAL : self::ROLE_CURRENT;
    }

    /**
     * Write off exactly what one restore operation put back, on the same frozen
     * identity: the second half of a returns scrap (goods came back, then are
     * discarded), which must never depend on the unit's current tracking.
     *
     * Reads the positive restore movements of that operation for that source, per
     * (item, combination, location, line), and takes each back with one 'adjust'
     * movement of the same source and line, under the reservation and physical floor
     * guards: a record may not go below what open reservations hold on it, and a
     * refusal at any record rolls back the whole write-off. One 'adjust' operation.
     * Nothing restored: quantity 0 and no operation.
     *
     * The write-off movements and their operation carry the restore operation as
     * their correlation id. What was already written off for that restore is
     * subtracted, read under the stock record's lock, so a repeated or concurrent
     * call writes off only what is left: nothing left, quantity 0 and no operation.
     * Should it create a unit's first record while the unit's figures claim stock
     * without records, it holds the unit as a restore does.
     *
     * A write-off over more than one unit locks the stock records of every unit it
     * still has restored stock to write off on before the first record is written
     * off, the units in ascending item and combination order, so no unit's
     * combination or item row is held while another unit's records are still to
     * take. A record it reaches where its unit has none is created there, at its
     * place in that order ({@see self::takeUnitRecords()}); the hold of a unit whose
     * first record it is still begins where the write-off reaches the record.
     *
     * @param string $restoreOperationId The restore operation.
     * @param string $sourceType Its source domain ('return').
     * @param int $sourceId Its source row id.
     * @param InventoryContext $context Audit context (its correlation id is replaced by the restore operation).
     *
     * @return array{operation_id: ?string, quantity: float}
     *
     * @throws InventoryUnitException When a restored unit no longer exists.
     * @throws InventoryReservedFloorException When a record would go below its reservations.
     *
     * @since  2.0.0
     */
    public function scrapRestored(string $restoreOperationId, string $sourceType, int $sourceId, InventoryContext $context): array
    {
        if ($restoreOperationId === '') {
            return ['operation_id' => null, 'quantity' => 0.0];
        }

        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['item_id', 'combination_id', 'location_id', 'source_line_id']))
            . ', SUM(' . $this->db->quoteName('qty_change') . ') AS ' . $this->db->quoteName('quantity')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_movements')
            . ' WHERE ' . $this->db->quoteName('operation_id') . ' = ' . $this->db->quote($restoreOperationId)
            . ' AND ' . $this->db->quoteName('movement_type') . ' = ' . $this->db->quote(self::MOVE_RESTORE)
            . ' AND ' . $this->db->quoteName('qty_change') . ' > ' . self::EPS
            . ' AND ' . $this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType)
            . ' AND ' . $this->db->quoteName('source_id') . ' = ' . $sourceId
            . ' GROUP BY ' . implode(', ', $this->db->quoteName(['item_id', 'combination_id', 'location_id', 'source_line_id']))
            . ' ORDER BY ' . implode(', ', $this->db->quoteName(['item_id', 'combination_id', 'location_id'])),
        );
        $groups = $this->db->loadObjectList() ?: [];

        if ($groups === []) {
            return ['operation_id' => null, 'quantity' => 0.0];
        }

        $scrapContext = new InventoryContext(
            userId: $context->userId,
            correlationId: $restoreOperationId,
            note: $context->note,
            metadata: $context->metadata,
        );
        $operationId = null;
        $total = 0.0;

        $this->db->transactionStart(true);

        try {
            // The units whose stock records the command has taken, and the records
            // it created there.
            $takenUnits = [];
            $created = [];

            // A write-off over several units takes the stock records of every unit
            // it still has restored stock to write off on first, in ascending unit
            // order, before it takes the combination or item row of any of them:
            // two variants of one product share its item row. Over one unit there
            // is no order to keep: the first of its records that is reached takes
            // them. Each unit's records are taken with the ones it has none of at
            // the locations it will write off at, created there.
            $unitKeys = array_map(
                callback: static fn (object $group): string => (int) $group->item_id . ':' . (int) $group->combination_id,
                array: $groups,
            );

            $unitsFirst = [];
            $reach = [];

            foreach ($groups as $key => $group) {
                $left = (float) $group->quantity - $this->scrappedForRestore(
                    restoreOperationId: $restoreOperationId,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    unit: ['item' => (int) $group->item_id, 'combination' => (int) $group->combination_id],
                    locationId: (int) $group->location_id,
                    lineId: $group->source_line_id !== null ? (int) $group->source_line_id : null,
                    lock: false,
                );

                if ($left > self::EPS) {
                    $unitsFirst[$unitKeys[$key]] ??= ['item' => (int) $group->item_id, 'combination' => (int) $group->combination_id];
                    $reach[$unitKeys[$key]][(int) $group->location_id] ??= $group->source_line_id !== null ? (int) $group->source_line_id : null;
                }
            }

            if (\count(value: $unitsFirst) > 1) {
                uasort(
                    array: $unitsFirst,
                    callback: static fn (array $one, array $other): int => [$one['item'], $one['combination']] <=> [$other['item'], $other['combination']],
                );

                foreach ($unitsFirst as $unitKey => $unitFirst) {
                    $this->takeUnitRecords(
                        unit: $unitFirst,
                        taken: $takenUnits,
                        create: $reach[$unitKey],
                        created: $created,
                        refuseLedgerDefect: false,
                        command: 'scrap',
                    );
                }
            }

            foreach ($groups as $group) {
                $restored = (float) $group->quantity;
                $unit = ['item' => (int) $group->item_id, 'combination' => (int) $group->combination_id];
                $locationId = (int) $group->location_id;
                $lineId = $group->source_line_id !== null ? (int) $group->source_line_id : null;
                $scrapped = fn (bool $lock): float => $this->scrappedForRestore(
                    restoreOperationId: $restoreOperationId,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    unit: $unit,
                    locationId: $locationId,
                    lineId: $lineId,
                    lock: $lock,
                );

                // Written off already (movements are never removed): no lock taken.
                if ($restored - $scrapped(false) <= self::EPS) {
                    continue;
                }

                // Every record the unit has, in location order, before one of
                // them is written off.
                $this->takeUnitRecords(
                    unit: $unit,
                    taken: $takenUnits,
                    create: $reach[$unit['item'] . ':' . $unit['combination']] ?? [],
                    created: $created,
                    refuseLedgerDefect: false,
                    command: 'scrap',
                );

                $stock = $this->lockStockRow(
                    itemId: $unit['item'],
                    combinationId: $unit['combination'],
                    locationId: $locationId,
                    refuseLedgerDefect: false,
                    historical: true,
                    command: 'scrap',
                    lineId: $lineId,
                    created: $created,
                );

                // A first record created on figures that claim stock without records
                // holds the unit, as a restore does.
                if ($stock->began_on_defect !== null) {
                    $operationId ??= $this->newOperationId();
                    $this->beginIntegrityHold(
                        unit: $unit,
                        figures: $stock->began_on_defect,
                        operationId: $operationId,
                        lineId: $lineId,
                        context: $scrapContext,
                    );
                }

                // Re-read under the record's lock: a concurrent call for the same
                // restore committed before this one got the lock.
                $quantity = $restored - $scrapped(true);

                if ($quantity <= self::EPS) {
                    continue;
                }

                $operationId ??= $this->newOperationId();

                $this->guardReservedFloor(stock: $stock, deduction: $quantity, unit: $unit, command: 'scrap');
                $this->guardPhysical(stock: $stock, deduction: $quantity);
                $this->applyStockDelta(stockId: (int) $stock->id, quantityDelta: -$quantity, reservedDelta: 0);
                $this->appendMovement(
                    unit: $unit,
                    locationId: $locationId,
                    qtyChange: -$quantity,
                    movementType: self::MOVE_ADJUST,
                    sourceType: $sourceType,
                    sourceId: $sourceId,
                    sourceLineId: $lineId,
                    operationId: $operationId,
                    context: $scrapContext,
                );
                $this->evaluateUnitNotices(itemId: $unit['item'], combinationId: $unit['combination']);

                $total += $quantity;
            }

            $this->assertCreatedReached(created: $created, command: 'scrap');

            if ($operationId !== null) {
                $this->recordOperation(operationId: $operationId, command: self::MOVE_ADJUST, sourceType: $sourceType, sourceId: $sourceId, context: $scrapContext);
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        return ['operation_id' => $operationId, 'quantity' => $total];
    }

    /**
     * The quantity already written off for one restore operation at one unit,
     * location and line: the 'adjust' movements of that source that carry the
     * restore operation as their correlation id.
     *
     * @param string $restoreOperationId The restore operation.
     * @param string $sourceType Its source domain.
     * @param int $sourceId Its source row id.
     * @param array $unit The frozen identity: item, combination.
     * @param int $locationId The location.
     * @param int|null $lineId The source line, or null.
     * @param bool $lock Locking read (sees every committed write-off).
     *
     * @return float The quantity written off, never below zero.
     *
     * @since  2.0.0
     */
    private function scrappedForRestore(
        string $restoreOperationId,
        string $sourceType,
        int $sourceId,
        array $unit,
        int $locationId,
        ?int $lineId,
        bool $lock,
    ): float {
        $this->db->setQuery(
            'SELECT COALESCE(SUM(' . $this->db->quoteName('qty_change') . '), 0)'
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_movements')
            . ' WHERE ' . $this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType)
            . ' AND ' . $this->db->quoteName('source_id') . ' = ' . $sourceId
            . ' AND ' . $this->db->quoteName('correlation_id') . ' = ' . $this->db->quote($restoreOperationId)
            . ' AND ' . $this->db->quoteName('movement_type') . ' = ' . $this->db->quote(self::MOVE_ADJUST)
            . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . $unit['combination']
            . ' AND ' . $this->db->quoteName('location_id') . ' = ' . $locationId
            . ' AND ' . $this->db->quoteName('source_line_id') . ($lineId === null ? ' IS NULL' : ' = ' . $lineId)
            . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );

        return max(0.0, -(float) $this->db->loadResult());
    }

    /**
     * Record the stock identity an order line acts on when the identity it was
     * sold as no longer exists, or record that a line sold on a product before it
     * had combinations acts on the product itself: the only way such a line's
     * fulfilment, cancellation and returns become movable. Nothing is ever mapped
     * automatically. Or correct the line's effective resolution while it has
     * produced no stock effect yet.
     *
     * Actions:
     *   historical_parent  the line acts on the product itself (item, 0): for a line
     *                      sold on the product itself while it now has current
     *                      combinations (the recorded classification; the line
     *                      already acts there), or for a line whose combination no
     *                      longer exists when its stock truly belongs to the product;
     *   bind_unit          a line whose combination no longer exists acts on a named
     *                      combination of the same product in any state (archived
     *                      included), or on the product itself when it has no current
     *                      combination.
     * A line whose own identity still exists (a current or archived combination, or
     * the product itself while it has no current combination) has nothing to
     * resolve. Moving a line off the identity it was sold as is refused while any
     * source still has stock out on that identity for the line, or an open
     * reservation of the line names it. A correction is judged by the same rules,
     * against the identity the line was sold as.
     *
     * Append-only: a row is never updated or deleted. The first resolution of a line
     * is revision 1; a correction names the effective resolution it corrects
     * ($supersedes), is recorded as the next revision superseding it, and becomes the
     * effective one, while the corrected row stays as history. A correction is
     * refused when $supersedes is not the line's effective resolution (none named
     * while one exists, or it was corrected meanwhile), and once the effective
     * resolution is consumed: stock has moved for the line on the identity it
     * resolved to since it was recorded ({@see self::resolutionConsumedSql()}).
     *
     * One transaction, anchored like fulfilment on the order row (FOR UPDATE): the
     * order line (shared lock), the line's effective resolution, then the new
     * append-only row (the line, its order, revision and superseded row, the
     * original and resolved identity, the action, the reason, a 'resolve_line'
     * operation, the time in UTC and the operator) before any lock on the product;
     * the unique keys on (line, revision) and on the superseded row turn a
     * concurrent resolution of the same line into a refusal. Then in the kernel's
     * lock order the chosen combination row, the item row and its current
     * combinations (shared locks), whether the corrected resolution is consumed and
     * the line's history on the original identity (current reads under shared
     * locks), the operation itself, and the order's refused stock effects queued
     * again. A refusal rolls the row back with the rest. No movement, stock record,
     * reservation, figure or order line is written.
     *
     * @param int $orderItemId The order line.
     * @param string $action RESOLVE_HISTORICAL_PARENT or RESOLVE_BIND_UNIT.
     * @param int $resolvedCombinationId The combination to bind to (bind_unit), 0 for the product itself.
     * @param string $reason Why (1-255 characters, required).
     * @param int|null $supersedes The id of the effective resolution this corrects; null for
     *                             the line's first resolution.
     * @param InventoryContext $context The operator (a Super User; required).
     *
     * @return array{resolution_id: int, operation_id: string, order_id: int, rearmed: array, revision: int, supersedes_id: ?int}
     *                                                                                                                            rearmed lists the order's stock effects queued again (source_type, source_id).
     *
     * @throws InventoryLineResolutionException When refused; nothing is written.
     *
     * @since  2.0.0
     */
    public function resolveLine(int $orderItemId, string $action, int $resolvedCombinationId, string $reason, ?int $supersedes, InventoryContext $context): array
    {
        $reason = trim($reason);
        $supersedes = $supersedes !== null && $supersedes > 0 ? $supersedes : null;
        $refuse = static fn (string $why): InventoryLineResolutionException => new InventoryLineResolutionException(lineId: $orderItemId, reason: $why);

        if ($reason === '' || mb_strlen($reason) > 255) {
            throw $refuse(InventoryLineResolutionException::REASON_REQUIRED);
        }

        if ($context->userId <= 0) {
            throw $refuse(InventoryLineResolutionException::OPERATOR_REQUIRED);
        }

        if (!\in_array($action, [self::RESOLVE_HISTORICAL_PARENT, self::RESOLVE_BIND_UNIT], true)) {
            throw $refuse(InventoryLineResolutionException::ACTION_UNKNOWN);
        }

        $operationId = $this->newOperationId();
        $rearmed = [];

        $this->db->transactionStart(true);

        try {
            // The order row first: the anchor every fulfilment of its lines takes.
            $orderId = $orderItemId > 0 ? (int) $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id_order') . ' FROM ' . $this->db->quoteName('#__alfa_order_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $orderItemId,
            )->loadResult() : 0;

            if ($orderId > 0) {
                $this->db->setQuery(
                    'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_orders')
                    . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $orderId . ' FOR UPDATE',
                )->loadResult();
            }

            $line = $orderItemId > 0 ? $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id_order') . ', ' . $this->db->quoteName('id_item')
                . ', COALESCE(' . $this->db->quoteName('id_combination') . ', 0) AS ' . $this->db->quoteName('id_combination')
                . ' FROM ' . $this->db->quoteName('#__alfa_order_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $orderItemId . ' LOCK IN SHARE MODE',
            )->loadObject() : null;

            if ($line === null || (int) $line->id_order !== $orderId) {
                throw $refuse(InventoryLineResolutionException::LINE_GONE);
            }

            // The line's effective resolution. The order lock keeps every other
            // resolution of this line out; the unique keys below turn one that
            // committed while this waited into a refusal.
            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName([
                    'id', 'order_item_id', 'revision', 'supersedes_id', 'original_item_id', 'original_combination_id',
                    'resolved_item_id', 'resolved_combination_id', 'operation_id',
                ]))
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_line_resolutions')
                . ' WHERE ' . $this->db->quoteName('order_item_id') . ' = ' . $orderItemId
                . ' ORDER BY ' . $this->db->quoteName('revision'),
            );
            $effective = self::effectiveOf(rows: $this->db->loadObjectList() ?: [])[$orderItemId] ?? null;

            if (($effective !== null ? (int) $effective->id : null) !== $supersedes) {
                throw $refuse(InventoryLineResolutionException::RESOLUTION_CHANGED);
            }

            $itemId = (int) $line->id_item;
            $originalCombination = (int) $line->id_combination;
            $resolvedCombination = $action === self::RESOLVE_BIND_UNIT ? max(0, $resolvedCombinationId) : 0;

            // The resolution row goes in before any lock on the product: a
            // fulfilment, cancellation or return holding the lock on the
            // resolutions it read waits for nothing this command holds yet. Every
            // refusal below rolls it back with the savepoint.
            $row = (object) [
                'order_item_id' => $orderItemId,
                'order_id' => $orderId,
                'revision' => $effective !== null ? (int) $effective->revision + 1 : 1,
                'supersedes_id' => $effective !== null ? (int) $effective->id : null,
                'original_item_id' => $itemId,
                'original_combination_id' => $originalCombination,
                'action' => $action,
                'resolved_item_id' => $itemId,
                'resolved_combination_id' => $resolvedCombination,
                'reason' => $reason,
                'operation_id' => $operationId,
                'created' => Factory::getDate('now', 'UTC')->toSql(),
                'created_by' => $context->userId,
            ];

            try {
                $this->db->insertObject('#__alfa_inventory_line_resolutions', $row, 'id');
            } catch (Throwable $e) {
                // 1062: a concurrent resolution of the same line committed first.
                if (MysqlError::number(error: $e) === 1062) {
                    throw $refuse(InventoryLineResolutionException::RESOLUTION_CHANGED);
                }

                throw $e;
            }

            // Then the product in the kernel's lock order: the chosen combination
            // row (a combination row of the same product, in any state), then the
            // item row, then its combination range.
            $target = $resolvedCombination > 0 ? $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $resolvedCombination
                . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId . ' LOCK IN SHARE MODE',
            )->loadResult() : null;

            $item = $itemId > 0 ? $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId . ' LOCK IN SHARE MODE',
            )->loadResult() : null;

            // A resolution that stock has already moved on is final.
            if ($effective !== null && $this->resolutionConsumed(resolution: $effective)) {
                throw $refuse(InventoryLineResolutionException::RESOLUTION_CONSUMED);
            }

            if ($item === null) {
                throw $refuse(InventoryLineResolutionException::ITEM_GONE);
            }

            $originalRole = $this->identityRole(unit: ['item' => $itemId, 'combination' => $originalCombination], lock: false);

            if ($action === self::RESOLVE_HISTORICAL_PARENT) {
                // A line sold on the product itself is only classified while the
                // product itself is kept for order history (it already acts there);
                // a combination line only while its combination is gone.
                $classifies = $originalCombination === 0 && $originalRole === self::ROLE_HISTORICAL;
                $rebinds = $originalCombination > 0 && $originalRole === self::ROLE_UNRESOLVED;

                if (!$classifies && !$rebinds) {
                    throw $refuse(InventoryLineResolutionException::IDENTITY_RESOLVABLE);
                }
            } else {
                if ($originalCombination === 0) {
                    throw $refuse(InventoryLineResolutionException::PARENT_LINE_NOT_REBOUND);
                }

                if ($originalRole !== self::ROLE_UNRESOLVED) {
                    throw $refuse(InventoryLineResolutionException::IDENTITY_RESOLVABLE);
                }

                if ($resolvedCombination > 0) {
                    if ($target === null) {
                        throw $refuse(InventoryLineResolutionException::TARGET_NOT_UNIT);
                    }
                } else {
                    // The product itself, only while it is its own unit.
                    $this->db->setQuery(
                        'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                        . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                        . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName('state'))
                        . ' LOCK IN SHARE MODE',
                    );

                    if ((int) $this->db->loadResult() > 0) {
                        throw $refuse(InventoryLineResolutionException::TARGET_NOT_UNIT);
                    }
                }
            }

            // Moving the line off the identity it was sold as needs that identity
            // to carry nothing of the line: no stock out from any source, no hold.
            if ($resolvedCombination !== $originalCombination
                && $this->historyOnIdentity(lineId: $orderItemId, unit: ['item' => $itemId, 'combination' => $originalCombination])
            ) {
                throw $refuse(InventoryLineResolutionException::HISTORY_ON_ORIGINAL);
            }

            $this->recordOperation(operationId: $operationId, command: 'resolve_line', sourceType: 'order_item', sourceId: $orderItemId, context: $context);

            // The order's stock effects refused for want of this resolution are
            // queued again with it, in the same transaction.
            if ($orderId > 0) {
                $rearmed = InventoryEffectOutbox::rearmRefused(orderId: $orderId);
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $this->logOnceStands(
            label: 'inventory line: order line resolved',
            entry: sprintf(
                'Inventory: order line #%d of order #%d, sold as %s of item #%d, recorded as %s acting on %s by user #%d (operation %s, revision %d%s); %d stock effects queued again. Reason: %s',
                $orderItemId,
                $orderId,
                $originalCombination > 0 ? 'combination #' . $originalCombination : 'the product itself',
                $itemId,
                $action,
                $resolvedCombination > 0 ? 'combination #' . $resolvedCombination : 'the product itself',
                $context->userId,
                $operationId,
                (int) $row->revision,
                $row->supersedes_id !== null ? ', superseding resolution #' . (int) $row->supersedes_id : '',
                \count($rearmed),
                $reason,
            ),
            priority: Log::INFO,
        );

        return [
            'resolution_id' => (int) $row->id,
            'operation_id' => $operationId,
            'order_id' => $orderId,
            'rearmed' => $rearmed,
            'revision' => (int) $row->revision,
            'supersedes_id' => $row->supersedes_id !== null ? (int) $row->supersedes_id : null,
        ];
    }

    /**
     * Whether a recorded resolution is consumed, judged with current reads under
     * shared locks: the rule of {@see self::resolutionConsumedSql()} (a movement of
     * its order line on the identity it resolved to, or a reservation of the line on
     * that identity in any state, written by an operation recorded after the
     * resolution's own operation), so a command that committed while the caller
     * waited is seen.
     *
     * @param object $resolution The resolution: order_item_id, resolved_item_id, resolved_combination_id, operation_id.
     *
     *
     * @since  2.0.0
     */
    private function resolutionConsumed(object $resolution): bool
    {
        $own = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_inventory_operations')
            . ' WHERE ' . $this->db->quoteName('operation_id') . ' = ' . $this->db->quote((string) $resolution->operation_id)
            . ' LOCK IN SHARE MODE',
        )->loadResult();

        // Without its operation the resolution has no place in time to be consumed after.
        if ($own === null) {
            return false;
        }

        foreach (['#__alfa_inventory_movements', '#__alfa_inventory_reservations'] as $table) {
            $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName($table, 'x')
                . ' INNER JOIN ' . $this->db->quoteName('#__alfa_inventory_operations', 'xo')
                . ' ON xo.' . $this->db->quoteName('operation_id') . ' = x.' . $this->db->quoteName('operation_id')
                . ' WHERE x.' . $this->db->quoteName('source_line_id') . ' = ' . (int) $resolution->order_item_id
                . ' AND x.' . $this->db->quoteName('item_id') . ' = ' . (int) $resolution->resolved_item_id
                . ' AND x.' . $this->db->quoteName('combination_id') . ' = ' . (int) $resolution->resolved_combination_id
                . ' AND xo.' . $this->db->quoteName('id') . ' > ' . (int) $own
                . ' LOCK IN SHARE MODE',
            );

            if ((int) $this->db->loadResult() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an order line still has anything on one identity: a source whose
     * deductions net of restores on it are not zero, or an open reservation of the
     * line naming it. Current reads under shared locks.
     *
     * @param int $lineId The order line.
     * @param array{item: int, combination: int} $unit The identity.
     *
     *
     * @since  2.0.0
     */
    private function historyOnIdentity(int $lineId, array $unit): bool
    {
        $this->db->setQuery(
            'SELECT COALESCE(-SUM(' . $this->db->quoteName('qty_change') . '), 0) AS ' . $this->db->quoteName('net')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_movements')
            . ' WHERE ' . $this->db->quoteName('source_line_id') . ' = ' . $lineId
            . ' AND ' . $this->db->quoteName('item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . (int) $unit['combination']
            . ' AND ' . $this->db->quoteName('movement_type') . ' IN (' . $this->db->quote(self::MOVE_DEDUCT) . ', ' . $this->db->quote(self::MOVE_RESTORE) . ')'
            . ' GROUP BY ' . $this->db->quoteName('source_type') . ', ' . $this->db->quoteName('source_id')
            . ' LOCK IN SHARE MODE',
        );

        foreach ($this->db->loadColumn() ?: [] as $net) {
            if (abs((float) $net) > self::TOL) {
                return true;
            }
        }

        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_inventory_reservations')
            . ' WHERE ' . $this->db->quoteName('source_line_id') . ' = ' . $lineId
            . ' AND ' . $this->db->quoteName('item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . (int) $unit['combination']
            . ' AND ' . $this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE)
            . ' LOCK IN SHARE MODE',
        );

        return (int) $this->db->loadResult() > 0;
    }

    /**
     * Record, per order line, whether the stock identity it is placed as tracks
     * stock at this moment: the fact that later decides whether the line's
     * fulfilment deducts stock, whatever the identity's tracking says by then
     * ({@see OpenLineClaims::placedTrackedSql()}). The only writer of the placement
     * records, called inside the placement's own transaction before any claim. A
     * record is removed with its order line by the database, whoever deletes the
     * line; nothing here deletes one.
     *
     * Per line: its frozen identity (item, combination) and that identity's
     * effective tracking (a combination's own flag, or the item's when it is NULL;
     * the item's for the item itself; an identity that does not exist tracks
     * nothing), read as the placement's transaction sees them. Reserve re-checks the
     * unit's tracking under its locks and holds nothing on a unit that stopped
     * tracking meanwhile. A line with no record gets one; a line already recorded on
     * the same identity keeps its record unchanged, so a later tracking change never
     * refreshes it; a line recorded on another identity (it was re-pointed) is
     * recorded again, as a new placement of that line. No operation row is written.
     *
     * @param int[] $lineIds The order lines being placed.
     * @param InventoryContext $context The acting user at placement (0 for a guest or the system).
     *
     * @return int The number of records written (inserted or replaced).
     *
     * @throws RuntimeException When a named order line does not exist; nothing is written.
     *
     * @since  2.0.0
     */
    public function recordPlacement(array $lineIds, InventoryContext $context): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $lineIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        sort($ids);
        $written = 0;

        $this->db->transactionStart(true);

        try {
            $this->db->setQuery(
                'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('id_order') . ', ' . $this->db->quoteName('id_item')
                . ', COALESCE(' . $this->db->quoteName('id_combination') . ', 0) AS ' . $this->db->quoteName('id_combination')
                . ' FROM ' . $this->db->quoteName('#__alfa_order_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
                . ' ORDER BY ' . $this->db->quoteName('id'),
            );
            $lines = [];

            foreach ($this->db->loadObjectList() ?: [] as $line) {
                $lines[(int) $line->id] = $line;
            }

            $missing = array_values(array_diff($ids, array_keys($lines)));

            if ($missing !== []) {
                throw new RuntimeException(sprintf(
                    'InventoryService: order line %s does not exist; no placement was recorded.',
                    implode(', ', array_map(static fn (int $id): string => '#' . $id, $missing)),
                ));
            }

            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['id', 'order_item_id', 'item_id', 'combination_id']))
                . ' FROM ' . $this->db->quoteName('#__alfa_inventory_line_provenance')
                . ' WHERE ' . $this->db->quoteName('order_item_id') . ' IN (' . implode(',', $ids) . ')',
            );
            $recorded = [];

            foreach ($this->db->loadObjectList() ?: [] as $row) {
                $recorded[(int) $row->order_item_id] = $row;
            }

            $now = Factory::getDate('now', 'UTC')->toSql();
            $tracking = [];

            foreach ($lines as $lineId => $line) {
                $itemId = (int) $line->id_item;
                $combinationId = (int) $line->id_combination;
                $existing = $recorded[$lineId] ?? null;

                if ($existing !== null && (int) $existing->item_id === $itemId && (int) $existing->combination_id === $combinationId) {
                    continue;
                }

                $key = $itemId . ':' . $combinationId;

                if (!isset($tracking[$key])) {
                    if ($combinationId > 0) {
                        $this->db->setQuery(
                            'SELECT COALESCE(c.' . $this->db->quoteName('manage_stock') . ', i.' . $this->db->quoteName('manage_stock') . ', 0)'
                            . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                            . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                            . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . $combinationId
                            . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . $itemId,
                        );
                    } else {
                        $this->db->setQuery(
                            'SELECT COALESCE(' . $this->db->quoteName('manage_stock') . ', 0) FROM ' . $this->db->quoteName('#__alfa_items')
                            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId,
                        );
                    }

                    $tracking[$key] = (int) $this->db->loadResult() === 1 ? 1 : 0;
                }

                if ($existing !== null) {
                    // Re-pointed: a new placement of the line on another identity.
                    $this->db->setQuery(
                        'UPDATE ' . $this->db->quoteName('#__alfa_inventory_line_provenance')
                        . ' SET ' . $this->db->quoteName('order_id') . ' = ' . (int) $line->id_order
                        . ', ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                        . ', ' . $this->db->quoteName('combination_id') . ' = ' . $combinationId
                        . ', ' . $this->db->quoteName('tracked') . ' = ' . $tracking[$key]
                        . ', ' . $this->db->quoteName('created') . ' = ' . $this->db->quote($now)
                        . ', ' . $this->db->quoteName('created_by') . ' = ' . (int) $context->userId
                        . ' WHERE ' . $this->db->quoteName('id') . ' = ' . (int) $existing->id,
                    )->execute();
                } else {
                    $row = (object) [
                        'order_item_id' => $lineId,
                        'order_id' => (int) $line->id_order,
                        'item_id' => $itemId,
                        'combination_id' => $combinationId,
                        'tracked' => $tracking[$key],
                        'created' => $now,
                        'created_by' => (int) $context->userId,
                    ];
                    $this->db->insertObject('#__alfa_inventory_line_provenance', $row, 'id');
                }

                $written++;
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        return $written;
    }

    /**
     * Record that a product's sale rules changed: its quantity minimum, step or
     * maximum, its backorder policy or its safety stock, or a combination's own
     * value of one of them. These rules decide every sellability verdict, so the
     * edit writes one 'sale_rules' operation (source 'catalog_edit', the product as
     * source row, the operator as actor) and every cache keyed on the inventory
     * version rebuilds. Nothing else is written: no stock record, movement,
     * reservation or figure.
     *
     * Runs in its own savepoint, so a caller's transaction commits or rolls it back
     * with the edit it records.
     *
     * @param int $itemId The product whose rules changed.
     * @param InventoryContext $context The operator.
     *
     * @return string The operation id.
     *
     * @throws RuntimeException When no product is named; nothing is written.
     *
     * @since  2.0.0
     */
    public function recordSaleRules(int $itemId, InventoryContext $context): string
    {
        if ($itemId <= 0) {
            throw new RuntimeException('InventoryService: a sale-rule edit is recorded for a saved product.');
        }

        $operationId = $this->newOperationId();

        $this->db->transactionStart(asSavepoint: true);

        try {
            $this->recordOperation(operationId: $operationId, command: 'sale_rules', sourceType: 'catalog_edit', sourceId: $itemId, context: $context);
            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);
            throw $e;
        }

        $this->logOnceStands(
            label: 'inventory line: sale rules changed',
            entry: sprintf(
                'Inventory: sale rules of item #%d changed by user #%d (operation %s).',
                $itemId,
                $context->userId,
                $operationId,
            ),
            priority: Log::INFO,
        );

        return $operationId;
    }

    //  Guards & policies

    /**
     * Tell the merchant a product has just been sold beyond what it had.
     *
     * The distinction the setting promises: "Allow" accepts a backorder quietly,
     * "Allow and notify" accepts it and says so. Before this it only wrote a log
     * line, which is not a notification by any reading a merchant would give the
     * word: the option existed and did nothing they would ever see.
     *
     * Deduplicated per INVENTORY UNIT, the same vocabulary the low-stock notice uses
     * ({@see self::evaluateLowStock()}), so a hundred backordered lines of the same
     * variant raise one notice, and a sold-out variant is never confused with its
     * stocked siblings. Advisory only: a notification failure must never break a
     * reservation, which is why the whole thing is swallowed.
     *
     * @param int $itemId The product sold beyond its salable quantity.
     * @param int $combinationId The variant sold, or 0 for the item unit.
     * @param float $shortfall How far beyond, in units.
     *
     *
     * @since  2.0.0
     */
    private function notifyBackorder(int $itemId, int $combinationId, float $shortfall): void
    {
        try {
            $sku = $combinationId > 0
                ? (string) $this->db->setQuery(
                    $this->db->getQuery(true)
                        ->select($this->db->quoteName('sku'))
                        ->from($this->db->quoteName('#__alfa_item_combinations'))
                        ->where($this->db->quoteName('id') . ' = ' . $combinationId),
                )->loadResult()
                : null;

            $dedupKey = self::backorderKey(itemId: $itemId, combinationId: $combinationId);

            NotificationHelper::push(
                dedupKey: $dedupKey,
                title:    NotificationText::of('COM_ALFA_NOTIFY_BACKORDER_TITLE', $this->unitLabel(itemId: $itemId, sku: $sku)),
                options:  [
                    'group' => NotificationHelper::GROUP_INVENTORY,
                    'severity' => 'warning',
                    // Owing the customer is a standing condition too: same
                    // re-alert rule as low stock, one vocabulary.
                    'constant' => true,
                    'message' => NotificationText::of('COM_ALFA_NOTIFY_BACKORDER_DESC', self::trimQty($shortfall)),
                    'url' => 'index.php?option=com_alfa&task=item.edit&id=' . $itemId,
                    'view' => ['action' => 'alfa.items.view', 'asset' => 'com_alfa'],
                    'link' => ['action' => 'alfa.items.edit', 'asset' => 'com_alfa'],
                ],
            );

            // A row now exists for this key; the "absent" memo is stale.
            unset(self::$noticeAbsent[$dedupKey]);
        } catch (Throwable $e) {
            // Advisory only: a notification hiccup must never block a reservation,
            // but one that ended the command's transaction ends the command.
            if ($this->endsCommand(failure: $e)) {
                throw $e;
            }
        }
    }

    /**
     * The backorder notice's identity, one unit = one key, mirroring the low-stock
     * scheme so the notification store speaks a single vocabulary.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination unit, or 0 for the item unit.
     *
     *
     * @since  2.0.0
     */
    private static function backorderKey(int $itemId, int $combinationId): string
    {
        return 'backorder:' . ($combinationId > 0 ? 'combination:' . $combinationId : 'item:' . $itemId);
    }

    /**
     * The auto-clear half of {@see self::notifyBackorder()}: same dedup key, opposite
     * direction. A backorder notice states a LIVE condition ("this unit owes stock it
     * does not hold"); once the unit can cover its reservations again the statement is
     * false, and nothing else in the kernel ever retired it.
     *
     * PREDICATE: the exact inverse of the guard that raised it.
     * {@see self::guardUnitBackorder()} fires when `requested > SUM(quantity - reserved)
     * over the unit's locations - min_qty`, so the notice stands exactly while that same
     * unit-wide sum is still below the safety floor, and clears when it recovers.
     *
     * The unit is the whole scope here, and the SIBLING is the trap: this must never be
     * evaluated on the ITEM's aggregate across sibling variants, because a stocked
     * sibling makes the total look healthy while the variant that owes stock is still
     * short, which would cancel the push inside the very same reserve() transaction.
     * Locations, by contrast, are summed deliberately: they are a sourcing dimension,
     * and the guard that raised the notice summed them too.
     *
     * Cheap-guard order, so a healthy shop pays no write: free arithmetic that can only
     * rule the clear OUT, then the memoised existence probe, and only then the policy
     * read and the removal.
     *
     * @param int $itemId The owning item.
     * @param int $combinationId The combination unit, or 0 for the item unit.
     * @param float $salable The unit's salable quantity, summed across its locations.
     *
     *
     * @since  2.0.0
     */
    private function evaluateBackorderNotice(int $itemId, int $combinationId, float $salable): void
    {
        // Still owing stock outright: decided for free, no query.
        if ($salable < -self::EPS) {
            return;
        }

        try {
            $dedupKey = self::backorderKey(itemId: $itemId, combinationId: $combinationId);

            if (self::noticeAbsent(dedupKey: $dedupKey)) {
                return;
            }

            // Oversell covered but the safety floor still breached: the guard would
            // fire again on the next order, so the condition genuinely still holds.
            if ($salable + self::EPS < $this->unitPolicy(itemId: $itemId, combinationId: $combinationId)['min_qty']) {
                return;
            }

            self::clearNotice(dedupKey: $dedupKey);
        } catch (Throwable $e) {
            // Advisory only: a notification hiccup must never break stock, but
            // one that ended the command's transaction ends the command.
            if ($this->endsCommand(failure: $e)) {
                throw $e;
            }
        }
    }

    /**
     * Backorder guard at reserve time, applied ONCE PER UNIT per line:
     * salable = SUM(quantity - reserved) over the unit's locations - safety floor.
     * Requests beyond it pass or fail by the resolved backorder policy.
     *
     * Per UNIT, not per stock row. Reserving moves no physical goods (it only
     * raises `reserved_quantity`), so at reserve time the backorder policy is the
     * only question, and it is a question about the unit, not about a shelf. The
     * guard used to run inside the allocation loop against one locked row, which
     * meant the safety floor was withheld once per location per variant and a
     * two-warehouse shop refused orders it could actually fill. It asks exactly
     * what {@see self::purchasableCeiling()} asks: the stock records through
     * {@see self::unitLedger()} and the one rule
     * {@see InventoryAvailabilityService::ceilingFrom()}, so the cart can never
     * accept what placement refuses.
     *
     * Judged on the unit's records as locked: each record the unit has is locked by
     * its own key inside reserve's transaction and read under that lock
     * ({@see self::lockUnitRecords()}), so a reduction or another reservation that
     * committed after the transaction's snapshot is counted, and the records stay
     * locked until the command ends. No range of the unit index is locked: a
     * record another command creates meanwhile is neither waited for nor counted.
     * Stock records first, as every command locks.
     *
     * A unit with no stock record whose figures claim stock, and a held unit, are
     * refused under every backorder mode ({@see self::assertLedgerBacked()}).
     *
     * @param array{item: int, combination: int} $unit Resolved inventory unit.
     * @param float $requested Quantity wanted for this line.
     *
     *
     * @throws RuntimeException When the policy denies.
     * @throws InventoryIntegrityException When the unit's figures claim stock its records do not hold.
     *
     * @since  2.0.0
     */
    private function guardUnitBackorder(array $unit, float $requested): void
    {
        $ledger = $this->unitLedger(unit: $unit, lock: true);

        // Records missing behind figures that claim stock, or a held unit: refused
        // here in every mode (the stock record's lock re-checks the hold).
        if ($ledger['rows'] === 0 || $this->integrityHeld(unit: $unit, lock: false)) {
            $this->assertLedgerBacked(unit: $unit, command: 'reserve', lineId: null);
        }

        $policy = $this->unitPolicy(itemId: $unit['item'], combinationId: (int) $unit['combination']);
        $available = $ledger['salable'] - $policy['min_qty'];

        // assertLedgerBacked() refused the defect and the hold above, so neither applies here.
        $ceiling = InventoryAvailabilityService::ceilingFrom(
            salable: $ledger['salable'],
            managed: true,
            ledgerPresent: $ledger['rows'] > 0,
            projectionNonZero: false,
            integrityHold: false,
            backorder: $policy['backorder'],
            minQty: $policy['min_qty'],
        );

        // Whole units within the quantity tolerance, as the stock verdict and the
        // cart judge them: a FLOAT salable of 0.99999 holds one unit.
        if ($ceiling !== null && $requested <= $ceiling + self::TOL) {
            return;
        }

        if ($ceiling === null && $requested <= $available + self::TOL) {
            return;
        }

        if ($ceiling !== null) {
            throw new RuntimeException(sprintf(
                'Inventory: item #%d%s has %.2f salable, %.2f requested (backorders denied).',
                $unit['item'],
                $unit['combination'] > 0 ? ' variant #' . $unit['combination'] : '',
                max(0, $available),
                $requested,
            ));
        }

        if ($policy['backorder'] === self::BACKORDER_ALLOW_NOTIFY) {
            $shortfall = $requested - max(0, $available);

            Log::add(
                entry: sprintf(
                    'Inventory backorder: item #%d%s reserved %.2f beyond salable.',
                    $unit['item'],
                    $unit['combination'] > 0 ? ' variant #' . $unit['combination'] : '',
                    $shortfall,
                ),
                priority: Log::WARNING,
                category: 'com_alfa.inventory',
            );

            $this->notifyBackorder(
                itemId:        (int) $unit['item'],
                combinationId: (int) $unit['combination'],
                shortfall:     $shortfall,
            );
        }
    }

    /**
     * Reservation floor guard: a command that lowers a record's on-hand may not
     * leave it below the quantity active reservations hold on that same record.
     *
     * The reserved quantity is read from the record the command has locked, so a
     * reservation that committed while the command waited is counted. Judged per
     * record (a reservation claims one location's stock), whatever the location's
     * state and whatever the shop allows for negative physical stock. A record
     * already below its reserved quantity may not go lower at all; raising it is
     * never judged here. A record with nothing reserved keeps only the physical
     * floor. Nothing is logged or notified: this is an operator refusal.
     *
     * @param object $stock The locked stock record.
     * @param float $deduction The quantity the command takes off it.
     * @param array{item: int, combination: int} $unit The record's unit.
     * @param string $command The calling command, for the message.
     *
     *
     * @throws InventoryReservedFloorException When the deduction would leave on-hand below the reservations.
     *
     * @since  2.0.0
     */
    private function guardReservedFloor(object $stock, float $deduction, array $unit, string $command): void
    {
        $reserved = (float) $stock->reserved_quantity;

        if ($deduction <= self::EPS || $reserved <= self::TOL) {
            return;
        }

        $onHand = (float) $stock->quantity;
        $requested = $onHand - $deduction;

        if ($requested < $reserved - self::TOL) {
            throw new InventoryReservedFloorException(
                itemId: (int) $unit['item'],
                combinationId: (int) $unit['combination'],
                locationId: (int) $stock->location_id,
                onHand: $onHand,
                reserved: $reserved,
                requested: $requested,
                minimum: InventoryReservedFloorException::minimumFor(onHand: $onHand, reserved: $reserved),
                command: $command,
            );
        }
    }

    /**
     * What a fulfilment may draw from one stock record for a line's unreserved
     * remainder: only what the record holds above the reserved quantity still
     * standing on it, so a shipment never takes stock another claim reserved.
     *
     * With a quantity reserved on the record: the whole remainder when the record
     * holds it above that quantity (within the quantity tolerance), otherwise what
     * it holds above it, and nothing from a record already below its reservations.
     * This holds whatever the shop allows for negative physical stock, and what is
     * taken never brings the record below zero. With nothing reserved on the record
     * only the physical floor applies: what the record holds, or the whole
     * remainder where the shop permits negative physical stock.
     *
     * The caller reads both figures from the record as locked, after every
     * reservation its command converts is off the record. The one rule for the
     * command and its dry run.
     *
     * @param float $onHand The record's quantity on hand.
     * @param float $reserved The quantity reservations still hold on the record.
     * @param float $remaining The remainder the line still has to draw.
     *
     * @return float The quantity to take from the record, never above $remaining.
     *
     * @since  2.0.0
     */
    private function unreservedTake(float $onHand, float $reserved, float $remaining): float
    {
        if ($reserved > self::TOL) {
            return $remaining <= ($onHand - $reserved) + self::TOL
                ? $remaining
                : max(0.0, $onHand - $reserved);
        }

        return $this->allowNegativePhysical()
            ? $remaining
            : min($remaining, max(0.0, $onHand));
    }

    /**
     * The refusal of a fulfilment whose unreserved remainder is short because a
     * record kept stock back for the reservations still standing on it, with that
     * record's figures: its on-hand and reserved quantity as they were when the
     * remainder reached it, the on-hand the remainder still wanted there would have
     * left, and the smallest on-hand the record may keep. Built for the command,
     * which throws it, and for its dry run, which reports it.
     *
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param array{location: int, on_hand: float, reserved: float, wanted: float} $kept The first record of the
     *                                                                                   line that kept stock back, and the remainder the line still wanted when it reached it.
     *
     *
     * @since  2.0.0
     */
    private function remainderFloorRefusal(array $unit, array $kept): InventoryReservedFloorException
    {
        return new InventoryReservedFloorException(
            itemId: (int) $unit['item'],
            combinationId: (int) $unit['combination'],
            locationId: (int) $kept['location'],
            onHand: (float) $kept['on_hand'],
            reserved: (float) $kept['reserved'],
            requested: (float) $kept['on_hand'] - (float) $kept['wanted'],
            minimum: InventoryReservedFloorException::minimumFor(onHand: (float) $kept['on_hand'], reserved: (float) $kept['reserved']),
            command: 'commit',
        );
    }

    /**
     * Physical floor guard: quantity never goes below zero unless the
     * shop explicitly permits negative physical stock.
     *
     * Its callers judge the reservation floor first; fulfilment judges the physical
     * floor alone for the reservations it converts, and draws a line's unreserved
     * remainder through unreservedTake(), which applies both floors.
     *
     * @throws RuntimeException When the deduction would breach the floor.
     *
     * @since  2.0.0
     */
    private function guardPhysical(object $stock, float $deduction): void
    {
        if ($this->allowNegativePhysical()) {
            return;
        }

        if ((float) $stock->quantity - $deduction < -self::EPS) {
            throw new RuntimeException(sprintf(
                'Inventory: %.2f physical at location #%d, %.2f asked; negative physical stock is not permitted.',
                (float) $stock->quantity,
                (int) $stock->location_id,
                $deduction,
            ));
        }
    }

    /**
     * Physical floor judged on an operation's FINAL state, one stock row at a
     * time: what a return that takes over a document's claim answers to in
     * place of a per-leg guard on the claim removal.
     *
     * Each row is re-read under its lock; one that ends below zero refuses the
     * whole operation, which the caller rolls back. A surplus at one location
     * never covers a deficit at another. The shop's explicit permission for
     * negative physical stock applies exactly as in guardPhysical().
     *
     * @param int[] $stockIds Stock rows the operation moved.
     *
     *
     * @throws RuntimeException When a row would end below zero.
     *
     * @since  2.0.0
     */
    private function guardFinalPhysical(array $stockIds): void
    {
        if ($stockIds === [] || $this->allowNegativePhysical()) {
            return;
        }

        $ids = array_map('intval', $stockIds);
        sort($ids);

        $rows = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('item_id')
            . ', ' . $this->db->quoteName('combination_id') . ', ' . $this->db->quoteName('location_id')
            . ', ' . $this->db->quoteName('quantity')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
            . ' ORDER BY ' . $this->db->quoteName('id')
            . ' FOR UPDATE',
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            if ((float) $row->quantity < -self::EPS) {
                throw new RuntimeException(sprintf(
                    'Inventory: location #%d would end at %.2f for item #%d%s; negative physical stock is not permitted.',
                    (int) $row->location_id,
                    (float) $row->quantity,
                    (int) $row->item_id,
                    (int) $row->combination_id > 0 ? ' combination #' . (int) $row->combination_id : '',
                ));
            }
        }
    }

    /**
     * Reservation floor judged on an operation's FINAL state, one stock row at a
     * time: the counterpart of {@see self::guardFinalPhysical()} for the reserved
     * quantity, so the claim hand-over of a return answers to the same floor as
     * every other writer that lowers a record.
     *
     * Judged on the operation's net effect on each row: a row that ends
     * at or above where the operation found it is never refused, and only what the
     * operation takes below a row's starting on-hand counts. A row it lowered may
     * not end below what open reservations hold there, and a row it found already
     * below them may not end lower at all, whatever the shop allows for negative
     * physical stock: another order's reservation is never left without the stock
     * that backed it. Each row is re-read under its lock; the first one that
     * ends below its floor refuses the whole operation, which the caller rolls back,
     * with that row's figures: its on-hand before the operation, its reserved
     * quantity, the on-hand the operation would have left and the smallest it may
     * keep.
     *
     * @param array<int, float> $movedRows The rows the operation moved: id => on-hand before the operation
     *                                     first moved it.
     *
     *
     * @throws InventoryReservedFloorException When a row would end below what its reservations hold.
     *
     * @since  2.0.0
     */
    private function guardFinalReservedFloor(array $movedRows): void
    {
        if ($movedRows === []) {
            return;
        }

        $ids = array_map('intval', array_keys($movedRows));
        sort($ids);

        $rows = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ', ' . $this->db->quoteName('item_id')
            . ', ' . $this->db->quoteName('combination_id') . ', ' . $this->db->quoteName('location_id')
            . ', ' . $this->db->quoteName('quantity') . ', ' . $this->db->quoteName('reserved_quantity')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
            . ' ORDER BY ' . $this->db->quoteName('id')
            . ' FOR UPDATE',
        )->loadObjectList() ?: [];

        foreach ($rows as $row) {
            $before = (float) $movedRows[(int) $row->id];
            $after = (float) $row->quantity;
            $reserved = (float) $row->reserved_quantity;

            if ($after < $before - self::EPS && $reserved > self::TOL && $after < $reserved - self::TOL) {
                throw new InventoryReservedFloorException(
                    itemId: (int) $row->item_id,
                    combinationId: (int) $row->combination_id,
                    locationId: (int) $row->location_id,
                    onHand: $before,
                    reserved: $reserved,
                    requested: $after,
                    minimum: InventoryReservedFloorException::minimumFor(onHand: $before, reserved: $reserved),
                    command: 'restore',
                );
            }
        }
    }

    /**
     * The storefront purchasable ceiling for a unit: the SAME rule the
     * reservation guard applies, so a cart never accepts what placement would
     * refuse (and never refuses what it would reserve).
     *
     *   untracked current unit                            null (no limit)
     *   a combination that no longer exists for the item,  0.0 (nothing to sell)
     *   an archived combination, the bare item of a
     *   product with current combinations, a missing item
     *   a tracked current unit                            InventoryAvailabilityService::ceilingFrom()
     *                                                     over its stock records, its figures (a
     *                                                     unit whose figures claim stock without
     *                                                     records, and a held unit, are 0.0 under
     *                                                     every mode) and its resolved backorder
     *                                                     policy and safety stock
     *
     * Publication is a catalogue gate outside the kernel and is not consulted:
     * an unpublished combination keeps its stock ceiling. This read never logs
     * and never raises a notification.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null for the item unit.
     *
     * @return float|null Max units addable now, or null for unlimited.
     *
     * @since  2.0.0
     */
    public function purchasableCeiling(int $itemId, ?int $combinationId = null): ?float
    {
        $combinationId = (int) ($combinationId ?? 0);
        $unit = $this->resolveUnit(itemId: $itemId, combinationId: $combinationId);

        // Not a tracked current unit: an untracked current unit sells without a
        // limit; an identity kept only for order history, or none, sells nothing.
        if ($unit === null) {
            return $this->isCurrentIdentity(itemId: $itemId, combinationId: $combinationId) ? null : 0.0;
        }

        $ledger = $this->unitLedger(unit: $unit, lock: false);
        $projection = $this->unitProjection(unit: $unit);
        $policy = $this->unitPolicy(itemId: $unit['item'], combinationId: $unit['combination']);

        return InventoryAvailabilityService::ceilingFrom(
            salable: $ledger['salable'],
            managed: true,
            ledgerPresent: $ledger['rows'] > 0,
            projectionNonZero: InventoryAvailabilityService::projectionNonZero(stock: $projection['stock'], available: $projection['available']),
            integrityHold: $this->integrityHeld(unit: $unit, lock: false),
            backorder: $policy['backorder'],
            minQty: $policy['min_qty'],
        );
    }

    /**
     * A unit's stock records: how many exist, and its salable quantity (physical
     * minus reserved) summed across its ENABLED locations: the single definition
     * of "how much of this can be sold" for the cart ceiling, the reservation
     * guard and the notices. A disabled location is not a shelf the shop sells
     * from.
     *
     * With $lock the figures are those of the unit's records as locked
     * ({@see self::lockUnitRecords()}): the latest committed ones, whatever the
     * transaction's snapshot shows, and the records stay locked until the caller's
     * transaction ends. Only the records are locked, never a range of the unit
     * index. The reservation guard asks for it; the ceiling and the notices read
     * plainly.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param bool $lock Lock the unit's records and read their
     *                   current figures (inside a transaction), or
     *                   read them plainly.
     *
     * @return array{rows: int, salable: float}
     *
     * @since  2.0.0
     */
    private function unitLedger(array $unit, bool $lock): array
    {
        if ($lock) {
            $records = $this->lockUnitRecords(unit: $unit);
            $salable = 0.0;

            foreach ($records as $record) {
                if ((int) $record->enabled === 1) {
                    $salable += (float) $record->free;
                }
            }

            return ['rows' => \count($records), 'salable' => $salable];
        }

        $this->db->setQuery(
            'SELECT COUNT(*) AS ' . $this->db->quoteName('n')
            . ', COALESCE(SUM(CASE WHEN ' . LocationPolicy::enabledPredicate(db: $this->db, column: $this->db->quoteName('location_id'))
            . ' THEN ' . $this->db->quoteName('quantity') . ' - ' . $this->db->quoteName('reserved_quantity')
            . ' ELSE 0 END), 0) AS ' . $this->db->quoteName('salable')
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . (int) $unit['combination'],
        );
        $row = $this->db->loadObject();

        return ['rows' => (int) ($row->n ?? 0), 'salable' => (float) ($row->salable ?? 0)];
    }

    /**
     * Lock the stock records a unit has, and read their figures under the lock.
     *
     * The records are named by a plain read and then locked one by one, each by
     * its whole key of the unit index (item, combination, location), in location
     * order (FOR UPDATE). That is the lock {@see self::lockStockRow()} takes on a
     * record, taken the same way, so only the records themselves are locked:
     *
     * - A locking read over the unit's range of that index would lock the gaps of
     *   the range as well, and with them the creation of every record that sorts
     *   there: the same unit at another location, another variant of the product,
     *   the first record of a neighbouring product. A command that creates such a
     *   record while it holds a record this one waits for would then deadlock
     *   with it.
     * - A lock by the record's id would take the row before its entry in the unit
     *   index, where every other command takes the entry first: the two would
     *   then wait for each other on one and the same record.
     *
     * The figures are the latest committed ones, whatever the transaction's
     * snapshot shows, and stay so until the caller's transaction ends. A record
     * created after the snapshot is not among them, so stock received on a new
     * record a moment ago is not counted yet; a record removed since is left out.
     *
     * @param array{item: int, combination: int} $unit The unit.
     *
     * @return object[] The locked records in location order: id, location_id, quantity,
     *                  reserved_quantity, free (quantity minus reserved_quantity, as the database
     *                  computes it) and enabled (1 when the record's location is enabled, 0 otherwise).
     *
     * @since  2.0.0
     */
    private function lockUnitRecords(array $unit): array
    {
        $records = [];

        foreach ($this->unitRecordNames(unit: $unit) as $locationId => $enabled) {
            $record = $this->lockUnitRecord(unit: $unit, locationId: $locationId);

            if ($record !== null) {
                $record->enabled = $enabled;
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Where a unit has stock records, as a plain read, and whether each location is
     * enabled: the records {@see self::lockUnitRecords()} names before it locks them.
     *
     * @param array{item: int, combination: int} $unit The unit.
     *
     * @return array<int, int> Location id => 1 when the location is enabled, 0 otherwise; in location order.
     *
     * @since  2.0.0
     */
    private function unitRecordNames(array $unit): array
    {
        // The plain read: where the unit has records, and whether each location
        // is enabled. The locking reads then name one record each and read
        // nothing but the stock table.
        $this->db->setQuery(
            query: 'SELECT ' . $this->db->quoteName(name: 'location_id')
            . ', (' . LocationPolicy::enabledPredicate(db: $this->db, column: $this->db->quoteName(name: 'location_id')) . ')'
            . ' AS ' . $this->db->quoteName(name: 'enabled')
            . ' FROM ' . $this->db->quoteName(name: '#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName(name: 'combination_id') . ' = ' . (int) $unit['combination']
            . ' ORDER BY ' . $this->db->quoteName(name: 'location_id'),
        );
        $names = [];

        foreach ($this->db->loadObjectList() ?: [] as $name) {
            $names[(int) $name->location_id] = (int) $name->enabled;
        }

        return $names;
    }

    /**
     * Lock one stock record of a unit by its whole key of the unit index (FOR
     * UPDATE), and read its figures under the lock.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param int $locationId The location.
     *
     * @return object|null id, location_id, quantity, reserved_quantity and free; null when there is no record.
     *
     * @since  2.0.0
     */
    private function lockUnitRecord(array $unit, int $locationId): ?object
    {
        $this->db->setQuery(
            query: 'SELECT ' . implode(', ', $this->db->quoteName(name: ['id', 'location_id', 'quantity', 'reserved_quantity']))
            . ', ' . $this->db->quoteName(name: 'quantity') . ' - ' . $this->db->quoteName(name: 'reserved_quantity')
            . ' AS ' . $this->db->quoteName(name: 'free')
            . ' FROM ' . $this->db->quoteName(name: '#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName(name: 'combination_id') . ' = ' . (int) $unit['combination']
            . ' AND ' . $this->db->quoteName(name: 'location_id') . ' = ' . $locationId . ' FOR UPDATE',
        );

        return $this->db->loadObject() ?: null;
    }

    /**
     * Take every stock record a unit has, once per command, before the command
     * reads one of them for a decision, writes one or takes the unit's combination
     * or item row ({@see self::lockUnitRecords()}: each by its own key, in location
     * order).
     *
     * A command that can reach more than one record of a unit (a line reserved at
     * several locations, a remainder drawn from several shelves, a transfer's two
     * ends) calls this where it first reaches the unit. Every such command then
     * takes the unit's records in the same order, whatever order it goes on to
     * read or write them in, so two of them on one unit queue on its first record
     * instead of each holding one record the other waits for.
     *
     * The records the command will write at locations where the unit has none
     * ($create) are created here, each at its place in that location order, and
     * so before the command holds any record that sorts after it. A record created
     * later, once the command holds records that sort after it, goes into the gap
     * before one of them; another command that waits for that record with a lock
     * on the gap before it then keeps the creation waiting while it waits for the
     * creator, and the database ends one of the two.
     *
     * The unit is judged once, before its first record is created, as
     * {@see self::lockStockRow()} judges it. A refusing command
     * ($refuseLedgerDefect) creates nothing for a unit whose figures claim stock
     * without records, or that is held: it is refused where the command reaches
     * it, before anything is written there. Any other command (a restore, the claim
     * hand-over, the write-off of restored stock) creates the records at zero and
     * logs and notifies the unit, and what it found is recorded in $created for
     * the unit: the command's {@see self::lockStockRow()} hands it on as
     * `began_on_defect` when it reaches each of those records, and the command
     * holds the unit there, as it would have where it created the record.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param array<string, true> $taken The units the command has taken already, by
     *                                   "item:combination"; the unit is added to it.
     * @param array<int, int|null> $create The locations the command will write the unit
     *                                     at, each with the order line that reaches it
     *                                     first, in the order the command reaches them:
     *                                     the ones where it has no record are created.
     * @param array $created The command's created records: records
     *                       ("item:combination:location" => reached) and
     *                       figures ("item:combination" => what the unit's
     *                       figures showed without records, or null).
     * @param bool $refuseLedgerDefect Whether the command refuses a unit whose figures
     *                                 claim stock without records.
     * @param string $command The command, for the log.
     *
     *
     * @since  2.0.0
     */
    private function takeUnitRecords(
        array $unit,
        array &$taken,
        array $create = [],
        array &$created = [],
        bool $refuseLedgerDefect = true,
        string $command = '',
    ): void {
        $key = (int) $unit['item'] . ':' . (int) $unit['combination'];

        if (isset($taken[$key])) {
            return;
        }

        $taken[$key] = true;

        // A location that is not one is never created: the command refuses it where
        // it reaches it.
        $create = array_filter(
            array: $create,
            callback: static fn (int|string $locationId): bool => (int) $locationId > 0,
            mode: ARRAY_FILTER_USE_KEY,
        );

        if ($create === []) {
            $this->lockUnitRecords(unit: $unit);

            return;
        }

        $names = $this->unitRecordNames(unit: $unit);
        $missing = array_values(array_diff(array_keys($create), array_keys($names)));
        $figures = null;

        // The unit is judged once, before its first record is created.
        if ($missing !== [] && $refuseLedgerDefect && $this->ledgerDefect(unit: $unit) !== null) {
            $missing = [];
        } elseif ($missing !== [] && !$refuseLedgerDefect && ($defect = $this->ledgerDefect(unit: $unit)) !== null) {
            // Named after the line that reaches the first of them, as it would be
            // where that line created it.
            $this->logMissingLedger(
                unit: $unit,
                command: $command,
                lineId: $create[$missing[0]],
                stock: $defect['stock'],
                available: $defect['available'],
            );
            InventoryIntegrityNotice::queue(itemId: (int) $unit['item'], kind: InventoryIntegrityNotice::KIND_RESTORED_ON_DEFECT);
            $figures = ['stock' => $defect['stock'], 'available' => $defect['available']];
        }

        $locations = array_unique([...array_keys($names), ...$missing]);
        sort($locations);

        foreach ($locations as $locationId) {
            if ($this->lockUnitRecord(unit: $unit, locationId: $locationId) !== null || !\in_array($locationId, $missing, true)) {
                continue;
            }

            $this->insertStockRecord(itemId: (int) $unit['item'], combinationId: (int) $unit['combination'], locationId: $locationId);
            $created['records'][$key . ':' . $locationId] = false;
            $created['figures'][$key] = $figures;
        }
    }

    /**
     * Refuse a command that created a stock record where it took a unit's records,
     * on figures that claimed stock without records, and then never reached it: the
     * record would stand at zero with no hold, and the unit would no longer show
     * the missing records. The command's savepoint takes everything back.
     *
     * @param array $created The command's created records ({@see self::takeUnitRecords()}).
     * @param string $command The command.
     *
     *
     * @throws RuntimeException When such a record was never reached.
     *
     * @since  2.0.0
     */
    private function assertCreatedReached(array $created, string $command): void
    {
        foreach ($created['records'] ?? [] as $record => $reached) {
            [$itemId, $combinationId, $locationId] = array_map('intval', explode(':', (string) $record));

            if ($reached || ($created['figures'][$itemId . ':' . $combinationId] ?? null) === null) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'InventoryService: %s created the stock record of item #%d%s at location #%d on figures that claim stock without records'
                . ' and never recorded stock on it; nothing was written.',
                $command,
                $itemId,
                $combinationId > 0 ? ' combination #' . $combinationId : '',
                $locationId,
            ));
        }
    }

    /**
     * One stock record's on-hand and reserved quantity, as a plain read, for the
     * fulfilment dry run. A unit with no record at the location holds nothing there.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param int $locationId The location.
     *
     * @return array{quantity: float, reserved: float}
     *
     * @since  2.0.0
     */
    private function recordFigures(array $unit, int $locationId): array
    {
        $this->db->setQuery(
            query: 'SELECT ' . implode(', ', $this->db->quoteName(name: ['quantity', 'reserved_quantity']))
            . ' FROM ' . $this->db->quoteName(name: '#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName(name: 'combination_id') . ' = ' . (int) $unit['combination']
            . ' AND ' . $this->db->quoteName(name: 'location_id') . ' = ' . $locationId,
        );
        $row = $this->db->loadObject();

        return [
            'quantity' => $row !== null ? (float) $row->quantity : 0.0,
            'reserved' => $row !== null ? (float) $row->reserved_quantity : 0.0,
        ];
    }

    /**
     * A unit's stored figures (its combination row, or the item row for the item
     * unit). Only the missing-records test reads them.
     *
     * @param array{item: int, combination: int} $unit The unit.
     *
     * @return array{stock: ?float, available: ?float}
     *
     * @since  2.0.0
     */
    private function unitProjection(array $unit): array
    {
        if ((int) $unit['combination'] > 0) {
            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['stock', 'available_quantity']))
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . (int) $unit['combination']
                . ' AND ' . $this->db->quoteName('item_id') . ' = ' . (int) $unit['item'],
            );
        } else {
            $this->db->setQuery(
                'SELECT ' . implode(', ', $this->db->quoteName(['stock', 'available_quantity']))
                . ' FROM ' . $this->db->quoteName('#__alfa_items')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . (int) $unit['item'],
            );
        }

        $row = $this->db->loadObject();

        return [
            'stock' => ($row->stock ?? null) !== null ? (float) $row->stock : null,
            'available' => ($row->available_quantity ?? null) !== null ? (float) $row->available_quantity : null,
        ];
    }

    /**
     * Whether a unit is in the missing-records state: tracked, no stock record
     * at all, and a stored figure non-zero in either sign; or held, whatever its
     * records, until the audited repair of its figures ends the hold. A tracked
     * unit with no record and zero (or empty) figures is merely never stocked.
     *
     * Judged on every tracked identity whose stored figures are its own, current
     * or archived, as the inventory audit judges them ({@see self::ownFiguresTracked()}):
     * a return onto an identity kept only for order history holds it as it holds
     * a current unit, and the product cannot come back from the archive while it
     * is held ({@see self::assertRestorable()}).
     *
     * A plain read: a command re-checks the hold once it holds the unit's stock
     * record ({@see self::assertNotHeld()}).
     *
     * @param array{item: int, combination: int} $unit The unit.
     *
     * @return array{stock: ?float, available: ?float, held: bool}|null The figures it shows (for a
     *                                                                  held unit: those it showed when the hold began) when in that state.
     *
     * @since  2.0.0
     */
    private function ledgerDefect(array $unit): ?array
    {
        $hold = $this->openIntegrityHold(unit: $unit, lock: false);

        if ($hold !== null) {
            return ['stock' => $hold['stock'], 'available' => $hold['available'], 'held' => true];
        }

        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . (int) $unit['combination'],
        );

        if ((int) $this->db->loadResult() > 0) {
            return null;
        }

        $projection = $this->unitProjection(unit: $unit);

        if (!InventoryAvailabilityService::projectionNonZero(stock: $projection['stock'], available: $projection['available'])) {
            return null;
        }

        if (!$this->ownFiguresTracked(unit: $unit)) {
            return null;
        }

        return ['stock' => $projection['stock'], 'available' => $projection['available'], 'held' => false];
    }

    /**
     * Whether an identity tracks stock on stored figures of its own, current or
     * archived: the identities the inventory audit judges against their stock
     * records. A combination row of that item, in any state, of a product in any
     * state, tracking stock by its own setting or, when that is empty, the item's;
     * or the item itself, tracking stock, while none of its combinations is
     * current, whatever the item's own state. The item of a product with current
     * combinations carries their sum, not figures of its own; an untracked
     * identity and a missing one have none to judge.
     *
     * A plain read.
     *
     * @param array{item: int, combination: int} $unit The identity.
     *
     *
     * @since  2.0.0
     */
    private function ownFiguresTracked(array $unit): bool
    {
        if ((int) $unit['combination'] > 0) {
            $this->db->setQuery(
                'SELECT COALESCE(c.' . $this->db->quoteName('manage_stock') . ', i.' . $this->db->quoteName('manage_stock') . ', 0)'
                . ' FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'c')
                . ' INNER JOIN ' . $this->db->quoteName('#__alfa_items', 'i') . ' ON i.' . $this->db->quoteName('id') . ' = c.' . $this->db->quoteName('item_id')
                . ' WHERE c.' . $this->db->quoteName('id') . ' = ' . (int) $unit['combination']
                . ' AND c.' . $this->db->quoteName('item_id') . ' = ' . (int) $unit['item'],
            );
            $tracked = $this->db->loadResult();

            return $tracked !== null && (int) $tracked === 1;
        }

        $this->db->setQuery(
            'SELECT COALESCE(i.' . $this->db->quoteName('manage_stock') . ', 0) AS ' . $this->db->quoteName('tracked')
            . ', EXISTS (SELECT 1 FROM ' . $this->db->quoteName('#__alfa_item_combinations', 'cc')
            . ' WHERE cc.' . $this->db->quoteName('item_id') . ' = i.' . $this->db->quoteName('id')
            . ' AND ' . CombinationState::currentSql(column: 'cc.' . $this->db->quoteName('state')) . ') AS ' . $this->db->quoteName('bears')
            . ' FROM ' . $this->db->quoteName('#__alfa_items', 'i')
            . ' WHERE i.' . $this->db->quoteName('id') . ' = ' . (int) $unit['item'],
        );
        $row = $this->db->loadObject();

        return $row !== null && (int) $row->tracked === 1 && (int) $row->bears === 0;
    }

    /**
     * Refuse a command on a unit whose figures claim stock it has no records for, or
     * on a held unit. The stale figure is never adopted as an opening balance: the
     * unit stays refused until the audited repair. Logs once per unit per request and
     * queues the item's integrity notification (written after the refused transaction).
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param string $command The refused command.
     * @param int|null $lineId The order line, if any.
     *
     *
     * @throws InventoryIntegrityException When the unit is in that state.
     *
     * @since  2.0.0
     */
    private function assertLedgerBacked(array $unit, string $command, ?int $lineId): void
    {
        $defect = $this->ledgerDefect(unit: $unit);

        if ($defect === null) {
            return;
        }

        if ($defect['held']) {
            $this->logHeld(unit: $unit, command: $command, lineId: $lineId, refused: true);
        } else {
            $this->logMissingLedger(unit: $unit, command: $command, lineId: $lineId, stock: $defect['stock'], available: $defect['available']);
        }

        InventoryIntegrityNotice::queue(itemId: (int) $unit['item'], kind: InventoryIntegrityNotice::KIND_LEDGER_MISSING);

        throw new InventoryIntegrityException(
            itemId: (int) $unit['item'],
            combinationId: (int) $unit['combination'],
            stock: $defect['stock'],
            available: $defect['available'],
            held: $defect['held'],
        );
    }

    /**
     * Log a unit found without stock records while its figures claim stock: one
     * ERROR per unit per request, naming the command, the line and the unit.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param string $command The command that met it.
     * @param int|null $lineId The order line, if any.
     * @param float|null $stock The stored stock figure.
     * @param float|null $available The stored available figure.
     *
     *
     * @since  2.0.0
     */
    private function logMissingLedger(array $unit, string $command, ?int $lineId, ?float $stock, ?float $available): void
    {
        $key = (int) $unit['item'] . ':' . (int) $unit['combination'];

        if (isset(self::$missingLedgerLogged[$key])) {
            return;
        }

        self::$missingLedgerLogged[$key] = true;

        Log::add(
            entry: sprintf(
                'Inventory: %s%s met item #%d%s with no stock records while its figures show stock %s / available %s;'
                . ' the figures need the audited repair.',
                $command,
                $lineId !== null ? ' of order line #' . $lineId : '',
                (int) $unit['item'],
                (int) $unit['combination'] > 0 ? ' combination #' . (int) $unit['combination'] : '',
                $stock === null ? 'empty' : self::trimQty(value: $stock),
                $available === null ? 'empty' : self::trimQty(value: $available),
            ),
            priority: Log::ERROR,
            category: 'com_alfa.inventory',
        );
    }

    /**
     * A unit's open integrity hold, if any: the hold row not yet released.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param bool $lock Current read under a shared lock (inside a
     *                   command's transaction, after the unit's
     *                   stock record is locked).
     * @param int $except A hold row to leave out (the one the caller
     *                    just wrote), 0 for none.
     *
     * @return array{id: int, stock: ?float, available: ?float}|null The hold and the figures the unit showed
     *                                                               when it began, or null when the unit is not held.
     *
     * @since  2.0.0
     */
    private function openIntegrityHold(array $unit, bool $lock, int $except = 0): ?array
    {
        $this->db->setQuery(
            'SELECT ' . implode(', ', $this->db->quoteName(['id', 'stock_figure', 'available_figure']))
            . ' FROM ' . $this->db->quoteName('#__alfa_inventory_integrity_holds')
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName('combination_id') . ' = ' . (int) $unit['combination']
            . ' AND ' . $this->db->quoteName('released_operation_id') . ' IS NULL'
            . ($except > 0 ? ' AND ' . $this->db->quoteName('id') . ' <> ' . $except : '')
            . ' ORDER BY ' . $this->db->quoteName('id')
            . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );
        $row = $this->db->loadObject();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'stock' => $row->stock_figure !== null ? (float) $row->stock_figure : null,
            'available' => $row->available_figure !== null ? (float) $row->available_figure : null,
        ];
    }

    /**
     * Whether a unit is held: a return or cancellation recorded stock on it while
     * its figures claimed stock without records, and the audited repair has not
     * ended the hold yet. A held unit is in the missing-records state for every
     * purpose although it has records.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param bool $lock Current read under a shared lock.
     *
     *
     * @since  2.0.0
     */
    private function integrityHeld(array $unit, bool $lock): bool
    {
        return $this->openIntegrityHold(unit: $unit, lock: $lock) !== null;
    }

    /**
     * Refuse a command that takes in stock or claims, or deducts, on a held unit,
     * judged once the unit's stock record, combination row and item row are locked.
     * Logs once per unit per request and queues the item's integrity notification.
     *
     * A hold is begun only by the command that creates a stock record of a unit that
     * had none, in that command's transaction. So when the record locked here is part
     * of this transaction's snapshot, no hold on the unit can have begun since that
     * snapshot, and a plain read answers exactly. The holds are read again as a
     * current read under a shared lock only when the locked record was created after
     * the snapshot (a return that committed while this command waited), or when the
     * plain read shows a hold (the repair may have ended it since). The ordinary path
     * of every command therefore takes no lock on the holds, so a command that has
     * passed one unit and waits for another that a return is holding does not block
     * that return from writing its hold.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param int $stockId The unit's stock record, locked by the caller.
     * @param string $command The refused command.
     * @param int|null $lineId The order line, if any.
     *
     *
     * @throws InventoryIntegrityException When the unit is held (held: true).
     *
     * @since  2.0.0
     */
    private function assertNotHeld(array $unit, int $stockId, string $command, ?int $lineId): void
    {
        $hold = $this->openIntegrityHold(unit: $unit, lock: false);

        if ($hold !== null || !$this->inSnapshot(stockId: $stockId)) {
            $hold = $this->openIntegrityHold(unit: $unit, lock: true);
        }

        if ($hold === null) {
            return;
        }

        $this->logHeld(unit: $unit, command: $command, lineId: $lineId, refused: true);
        InventoryIntegrityNotice::queue(itemId: (int) $unit['item'], kind: InventoryIntegrityNotice::KIND_LEDGER_MISSING);

        throw new InventoryIntegrityException(
            itemId: (int) $unit['item'],
            combinationId: (int) $unit['combination'],
            stock: $hold['stock'],
            available: $hold['available'],
            held: true,
        );
    }

    /**
     * Whether a stock record is part of this transaction's snapshot: a plain read of
     * it by id. False for a record another transaction created after the snapshot
     * was taken (the caller holds the record's lock, so that transaction has
     * committed). A transaction that has not read anything yet takes its snapshot
     * here, and the record is then part of it.
     *
     * @param int $stockId The stock record.
     *
     *
     * @since  2.0.0
     */
    private function inSnapshot(int $stockId): bool
    {
        $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $stockId,
        );

        return (int) $this->db->loadResult() > 0;
    }

    /**
     * Hold a unit whose first stock record a return or cancellation just created
     * while its figures claimed stock without records, in that command's
     * transaction: one hold row with the figures shown then, the command's
     * operation, the order line, the time and the actor. A unit already held keeps
     * its one open hold. The hold ends only through the audited repair
     * ({@see self::releaseIntegrityHolds()}); until then the unit stays unavailable
     * for sale and refused by every command that takes in stock or claims. The hold
     * is logged as a WARNING beside the command's one ERROR for the missing records.
     *
     * Called with the unit's stock record, combination row and item row locked, so
     * two returns onto one unit cannot both begin a hold. The row is written first,
     * under a savepoint, and the unit's other open holds are looked for afterwards
     * with a current read under a shared lock; the row is taken back when one
     * exists. Looking first would lock the gap the new row goes into, and two
     * returns holding two different units at the same moment would each wait for
     * the other's gap.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param array{stock: ?float, available: ?float} $figures The figures it showed without records.
     * @param string $operationId The recording command's operation.
     * @param int|null $lineId The order line it recorded, if any.
     * @param InventoryContext $context The acting user.
     *
     *
     * @since  2.0.0
     */
    private function beginIntegrityHold(array $unit, array $figures, string $operationId, ?int $lineId, InventoryContext $context): void
    {
        $row = (object) [
            'item_id' => (int) $unit['item'],
            'combination_id' => (int) $unit['combination'],
            'stock_figure' => $figures['stock'],
            'available_figure' => $figures['available'],
            'operation_id' => $operationId,
            'source_line_id' => $lineId,
            'created' => Factory::getDate('now', 'UTC')->toSql(),
            'created_by' => (int) $context->userId,
        ];

        $this->db->transactionStart(true);

        try {
            $this->db->insertObject('#__alfa_inventory_integrity_holds', $row, 'id');
            $alreadyHeld = $this->openIntegrityHold(unit: $unit, lock: true, except: (int) $row->id) !== null;
        } catch (Throwable $e) {
            $this->rollBackCommand(failure: $e);

            throw $e;
        }

        if ($alreadyHeld) {
            $this->db->transactionRollback(true);

            return;
        }

        TransactionState::commitLevel(db: $this->db);

        $this->logOnceStands(
            label: 'inventory line: unit held',
            entry: sprintf(
                'Inventory: item #%d%s is held (hold #%d, operation %s%s): stock was recorded on it while its figures showed stock %s / available %s'
                . ' with no stock records; it stays unavailable until its figures are repaired through the inventory audit.',
                (int) $unit['item'],
                (int) $unit['combination'] > 0 ? ' combination #' . (int) $unit['combination'] : '',
                (int) $row->id,
                $operationId,
                $lineId !== null ? ', order line #' . $lineId : '',
                $figures['stock'] === null ? 'empty' : self::trimQty(value: $figures['stock']),
                $figures['available'] === null ? 'empty' : self::trimQty(value: $figures['available']),
            ),
            priority: Log::WARNING,
        );
    }

    /**
     * End every open integrity hold of an item: the release half of a hold,
     * written only by the audited repair (reconcile()) once the item's figures
     * agree with its records, in its transaction. The rows record the repair's
     * operation, the time and the operator, and stay as history.
     *
     * @param int $itemId The item.
     * @param string $operationId The repair's operation.
     * @param InventoryContext $context The operator.
     *
     * @return int The number of holds ended.
     *
     * @since  2.0.0
     */
    private function releaseIntegrityHolds(int $itemId, string $operationId, InventoryContext $context): int
    {
        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__alfa_inventory_integrity_holds')
            . ' SET ' . $this->db->quoteName('released_operation_id') . ' = ' . $this->db->quote($operationId)
            . ', ' . $this->db->quoteName('released') . ' = ' . $this->db->quote(Factory::getDate('now', 'UTC')->toSql())
            . ', ' . $this->db->quoteName('released_by') . ' = ' . (int) $context->userId
            . ' WHERE ' . $this->db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . $this->db->quoteName('released_operation_id') . ' IS NULL',
        )->execute();

        return (int) $this->db->getAffectedRows();
    }

    /**
     * Log a command meeting a held unit: refused (ERROR), or a return or
     * cancellation proceeding onto it (WARNING). Once per unit per request.
     *
     * @param array{item: int, combination: int} $unit The unit.
     * @param string $command The command.
     * @param int|null $lineId The order line, if any.
     * @param bool $refused Whether the command was refused.
     *
     *
     * @since  2.0.0
     */
    private function logHeld(array $unit, string $command, ?int $lineId, bool $refused): void
    {
        $key = 'held:' . (int) $unit['item'] . ':' . (int) $unit['combination'];

        if (isset(self::$missingLedgerLogged[$key])) {
            return;
        }

        self::$missingLedgerLogged[$key] = true;

        Log::add(
            entry: sprintf(
                'Inventory: %s%s %s item #%d%s, which is held until its figures are repaired through the inventory audit.',
                $command,
                $lineId !== null ? ' of order line #' . $lineId : '',
                $refused ? 'was refused on' : 'recorded stock on',
                (int) $unit['item'],
                (int) $unit['combination'] > 0 ? ' combination #' . (int) $unit['combination'] : '',
            ),
            priority: $refused ? Log::ERROR : Log::WARNING,
            category: 'com_alfa.inventory',
        );
    }

    /**
     * Resolve an inventory UNIT's policy: combination override, else item
     * override, else global configuration.
     *
     * Named for the unit, not the item, because that is what it answers for: a
     * variant may carry its own backorder mode and its own safety floor.
     *
     * Resolution is `!== null`, never falsy: a variant that sets `min_qty = 0`
     * means "explicitly no safety stock here, ignore the shop default" and must
     * keep its 0. `backorder` gets a third level from the item column's own
     * vocabulary: NULL inherits the item, `''` skips it and follows the shop. The
     * settled value is then read the one way every surface reads it
     * ({@see BackorderPolicy::effective()}): an empty or unknown value follows the
     * shop, and an unknown shop setting is deny. The safety floor is never
     * negative.
     *
     * Null-safe by design: callers on the deletion path resolve the policy after
     * the row is gone, and must then get the configuration defaults.
     *
     * Memoised per unit: it is called once per line per guard.
     *
     * @param int $itemId The product whose policy is asked.
     * @param int $combinationId The variant, or 0 for the product's own unit.
     *
     * @return array{backorder: string, min_qty: float}
     *
     * @since  2.0.0
     */
    private function unitPolicy(int $itemId, int $combinationId = 0): array
    {
        $memoKey = $itemId . '|' . $combinationId;

        if (isset($this->policyMemo[$memoKey])) {
            return $this->policyMemo[$memoKey];
        }

        $item = $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName(['backorder', 'min_qty']))
                ->from($this->db->quoteName('#__alfa_items'))
                ->where($this->db->quoteName('id') . ' = ' . $itemId),
        )->loadObject();

        $combo = null;

        if ($combinationId > 0) {
            $combo = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName(['backorder', 'min_qty']))
                    ->from($this->db->quoteName('#__alfa_item_combinations'))
                    ->where($this->db->quoteName('id') . ' = ' . $combinationId),
            )->loadObject();
        }

        $params = ComponentHelper::getParams('com_alfa');

        // The variant speaks first when it has said anything at all; '' is a
        // deliberate statement ("follow the shop"), not silence.
        $backorder = ($combo !== null && $combo->backorder !== null)
            ? (string) $combo->backorder
            : (($item->backorder ?? null) !== null ? (string) $item->backorder : null);

        $minQty = ($combo !== null && $combo->min_qty !== null)
            ? (float) $combo->min_qty
            : (($item->min_qty ?? null) !== null ? (float) $item->min_qty : null);

        return $this->policyMemo[$memoKey] = [
            'backorder' => (new BackorderPolicy())->effective(stored: $backorder),
            'min_qty' => max(0.0, $minQty ?? (float) $params->get('min_qty', 0)),
        ];
    }

    /**
     * @since  2.0.0
     */
    private function overShipmentPolicy(): string
    {
        return (string) ComponentHelper::getParams('com_alfa')->get('over_shipment', self::OVER_SHIPMENT_STRICT);
    }

    /**
     * @since  2.0.0
     */
    private function allowNegativePhysical(): bool
    {
        return (bool) ComponentHelper::getParams('com_alfa')->get('negative_physical', 0);
    }

    //  Reservation internals

    /**
     * The unfulfilled, unreleased remainder of a reservation row.
     *
     * @since  2.0.0
     */
    private function remainderOf(object $reservation): float
    {
        return max(0.0, (float) $reservation->quantity - (float) $reservation->fulfilled_quantity - (float) $reservation->released_quantity);
    }

    /**
     * Advance a reservation's quantities/state. FULFILLED closes a row
     * whose remainder reaches zero through fulfilment; release states are
     * explicit. PARTIAL is derived by readers, never stored.
     *
     * @since  2.0.0
     */
    private function transitionReservation(
        object $reservation,
        float $fulfilledDelta = 0,
        float $releasedDelta = 0,
        ?string $state = null,
    ): void {
        $fulfilled = (float) $reservation->fulfilled_quantity + $fulfilledDelta;
        $released = (float) $reservation->released_quantity + $releasedDelta;
        $remainder = max(0.0, (float) $reservation->quantity - $fulfilled - $released);

        if ($state === null) {
            $state = $remainder <= self::EPS ? self::STATE_FULFILLED : self::STATE_ACTIVE;
        }

        $row = (object) [
            'id' => (int) $reservation->id,
            'fulfilled_quantity' => $fulfilled,
            'released_quantity' => $released,
            'state' => $state,
            'modified' => Factory::getDate('now', 'UTC')->toSql(),
        ];
        $this->db->updateObject('#__alfa_inventory_reservations', $row, 'id');

        $reservation->fulfilled_quantity = $fulfilled;
        $reservation->released_quantity = $released;
        $reservation->state = $state;
    }

    /**
     * The units a source's ACTIVE reservations stand on, as a plain read, in
     * ascending item and combination order: what a release takes the stock records
     * of before it locks the reservation rows themselves
     * ({@see self::lockActiveReservations()}).
     *
     * @param string $sourceType The reservation source domain.
     * @param int $sourceId The source row.
     * @param int|null $lineId One order line, or null for every line of the source.
     *
     * @return array<int, array{item: int, combination: int}>
     *
     * @since  2.0.0
     */
    private function reservedUnitsOf(string $sourceType, int $sourceId, ?int $lineId): array
    {
        $this->db->setQuery(
            query: 'SELECT DISTINCT ' . implode(separator: ', ', array: $this->db->quoteName(name: ['item_id', 'combination_id']))
            . ' FROM ' . $this->db->quoteName(name: '#__alfa_inventory_reservations')
            . ' WHERE ' . $this->db->quoteName(name: 'source_type') . ' = ' . $this->db->quote(text: $sourceType)
            . ' AND ' . $this->db->quoteName(name: 'source_id') . ' = ' . $sourceId
            . ' AND ' . $this->db->quoteName(name: 'state') . ' = ' . $this->db->quote(text: self::STATE_ACTIVE)
            . ($lineId !== null ? ' AND ' . $this->db->quoteName(name: 'source_line_id') . ' = ' . $lineId : '')
            . ' ORDER BY ' . $this->db->quoteName(name: 'item_id') . ', ' . $this->db->quoteName(name: 'combination_id'),
        );

        return array_map(
            callback: static fn (object $row): array => ['item' => (int) $row->item_id, 'combination' => (int) $row->combination_id],
            array: $this->db->loadObjectList() ?: [],
        );
    }

    /**
     * Lock a source's ACTIVE reservations (row locks, id order).
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    private function lockActiveReservations(string $sourceType, int $sourceId): array
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__alfa_inventory_reservations'))
            ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
            ->where($this->db->quoteName('source_id') . ' = ' . $sourceId)
            ->where($this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE))
            ->order($this->db->quoteName('id'));

        return $this->db->setQuery((string) $query . ' FOR UPDATE')->loadObjectList() ?: [];
    }

    /**
     * Lock a line's ACTIVE reservations: fulfilment matching is a
     * LOOKUP on the line anchor, never a heuristic.
     *
     * @return object[]
     *
     * @since  2.0.0
     */
    private function lockActiveReservationsForLine(?int $lineId, array $unit): array
    {
        // No line anchor = no allocation to consume: line-less asks
        // (manual documents) go straight to unreserved deduction and must
        // never eat another source's reservations.
        if ($lineId === null) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName('#__alfa_inventory_reservations'))
            ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
            ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
            ->where($this->db->quoteName('state') . ' = ' . $this->db->quote(self::STATE_ACTIVE))
            ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
            ->order($this->db->quoteName('id'));

        return $this->db->setQuery((string) $query . ' FOR UPDATE')->loadObjectList() ?: [];
    }

    /**
     * A line's ACTIVE reservations on its acting identity, in id order, as a plain
     * read: what a fulfilment of the line would convert, in the order it would
     * convert them. For the fulfilment dry run, which takes no lock.
     *
     * @param int|null $lineId The order line; none means no reservation to convert.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     *
     * @return object[] Reservation rows.
     *
     * @since  2.0.0
     */
    private function activeReservationsForLine(?int $lineId, array $unit): array
    {
        if ($lineId === null) {
            return [];
        }

        $this->db->setQuery(
            query: 'SELECT * FROM ' . $this->db->quoteName(name: '#__alfa_inventory_reservations')
            . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName(name: 'combination_id') . ' = ' . (int) $unit['combination']
            . ' AND ' . $this->db->quoteName(name: 'state') . ' = ' . $this->db->quote(text: self::STATE_ACTIVE)
            . ' AND ' . $this->db->quoteName(name: 'source_line_id') . ' = ' . $lineId
            . ' ORDER BY ' . $this->db->quoteName(name: 'id'),
        );

        return $this->db->loadObjectList() ?: [];
    }

    /**
     * The active reservations of one stock record in the order of claims
     * ({@see ReservationQueue}): who is served first from the record's stock.
     *
     * @param array{item: int, combination: int} $unit The record's unit.
     * @param int $locationId The record's location.
     * @param bool $lock Read the latest committed rows under a shared
     *                   lock (inside a command that holds the unit's
     *                   records), or plainly.
     *
     * @return object[] Reservation rows (every column), the one served first first.
     *
     * @since  2.0.0
     */
    private function claimQueue(array $unit, int $locationId, bool $lock): array
    {
        $this->db->setQuery(
            query: 'SELECT * FROM ' . $this->db->quoteName(name: '#__alfa_inventory_reservations')
            . ' WHERE ' . $this->db->quoteName(name: 'item_id') . ' = ' . (int) $unit['item']
            . ' AND ' . $this->db->quoteName(name: 'combination_id') . ' = ' . (int) $unit['combination']
            . ' AND ' . $this->db->quoteName(name: 'location_id') . ' = ' . $locationId
            . ' AND ' . $this->db->quoteName(name: 'state') . ' = ' . $this->db->quote(text: self::STATE_ACTIVE)
            . ' ORDER BY ' . ReservationQueue::orderSql(db: $this->db, alias: '')
            . ($lock ? ' LOCK IN SHARE MODE' : ''),
        );

        return $this->db->loadObjectList() ?: [];
    }

    /**
     * Note one reservation a fulfilment converts (or its dry run plans to convert),
     * on its stock record, for the judgement of the order of claims
     * ({@see self::claimOrderRefusal()}). The record's figures and the
     * reservation's remainder are those before the command's first conversion of
     * them; what is taken adds up.
     *
     * @param array $conversions The command's notes, by record (appended to).
     * @param array{item: int, combination: int} $unit The record's unit.
     * @param int $locationId The record's location.
     * @param float $onHand The record's on-hand before this conversion.
     * @param float $reserved The record's reserved quantity before it.
     * @param object $reservation The reservation row, as read before it.
     * @param float $take The quantity converted.
     *
     *
     * @since  2.0.0
     */
    private function noteConversion(
        array &$conversions,
        array $unit,
        int $locationId,
        float $onHand,
        float $reserved,
        object $reservation,
        float $take,
    ): void {
        $recordKey = (int) $unit['item'] . ':' . (int) $unit['combination'] . ':' . $locationId;
        $conversions[$recordKey] ??= [
            'unit' => ['item' => (int) $unit['item'], 'combination' => (int) $unit['combination']],
            'location' => $locationId,
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'taken' => 0.0,
            'converted' => [],
        ];
        $conversions[$recordKey]['taken'] += $take;

        $reservationId = (int) $reservation->id;
        $conversions[$recordKey]['converted'][$reservationId] ??= [
            'row' => clone $reservation,
            'remainder' => $this->remainderOf(reservation: $reservation),
            'take' => 0.0,
        ];
        $conversions[$recordKey]['converted'][$reservationId]['take'] += $take;
    }

    /**
     * The order of claims, judged on what a fulfilment's conversions leave on each
     * stock record: first reserved, first served, unless an administrator served
     * an order first ({@see ReservationQueue}).
     *
     * A conversion takes on-hand and reserved together off its own reservation's
     * record. Where the record holds less than its reservations, the units it takes
     * may be units that back a reservation ahead of the converted one. That is
     * refused: no reservation still standing on the record may wait for more stock
     * after the command than before it. A record that still covers every
     * reservation left on it is not read again. Otherwise its active reservations
     * are read in the order of claims and what the on-hand covers of each is
     * compared, before and after the command's conversions on the record. The
     * judgement is the same whichever order the request names its lines in.
     *
     * The refusal carries the record's figures: its on-hand before the command,
     * the remainders of the reservations ahead of the last converted one in the
     * order of claims, the on-hand the conversions would leave and the smallest
     * on-hand those reservations allow, with the sources that hold them.
     *
     * @param array $conversions The notes of {@see self::noteConversion()}, by record.
     * @param bool $applied True in the command: the conversions are written and the record's
     *                      reservations are read as they stand now, under a shared lock. False
     *                      in the dry run: nothing is written and they are read plainly.
     *
     * @return array{refusal: InventoryReservedFloorException, line_id: ?int}|null The first record's
     *                                                                             refusal, with the order line whose conversion is the last in the order of claims there;
     *                                                                             null when every record keeps the order of claims.
     *
     * @since  2.0.0
     */
    private function claimOrderRefusal(array $conversions, bool $applied): ?array
    {
        foreach ($conversions as $record) {
            $onHandAfter = $record['on_hand'] - $record['taken'];

            // Every reservation left on the record is covered in full: none waits.
            if ($onHandAfter >= $record['reserved'] - $record['taken'] - self::TOL) {
                continue;
            }

            // The record's reservations after the conversions, with what each held
            // before them; a reservation converted in full is no longer active.
            $rows = [];

            foreach ($this->claimQueue(unit: $record['unit'], locationId: $record['location'], lock: $applied) as $row) {
                $take = $record['converted'][(int) $row->id]['take'] ?? 0.0;
                $stored = $this->remainderOf(reservation: $row);
                $rows[(int) $row->id] = [
                    'row' => $row,
                    'before' => $applied ? $stored + $take : $stored,
                    'after' => $applied ? $stored : max(0.0, $stored - $take),
                ];
            }

            foreach ($record['converted'] as $reservationId => $note) {
                $rows[$reservationId] ??= ['row' => $note['row'], 'before' => $note['remainder'], 'after' => max(0.0, $note['remainder'] - $note['take'])];
            }

            $ordered = ReservationQueue::sorted(rows: array_map(callback: static fn (array $entry): object => $entry['row'], array: $rows));
            $queue = static fn (string $when): array => array_map(
                callback: static fn (object $row): array => ['id' => (int) $row->id, 'remainder' => $rows[(int) $row->id][$when]],
                array: $ordered,
            );
            $before = ReservationQueue::backing(queue: $queue(when: 'before'), onHand: $record['on_hand']);
            $after = ReservationQueue::backing(queue: $queue(when: 'after'), onHand: $onHandAfter);
            $shorted = false;

            foreach ($after as $reservationId => $now) {
                if ($rows[$reservationId]['after'] > self::TOL && $now['waiting'] > $before[$reservationId]['waiting'] + self::TOL) {
                    $shorted = true;
                    break;
                }
            }

            if (!$shorted) {
                continue;
            }

            // The last converted reservation in the order of claims, and every
            // reservation still standing ahead of it.
            $last = null;

            foreach ($ordered as $position => $row) {
                if (isset($record['converted'][(int) $row->id])) {
                    $last = $position;
                }
            }

            $aheadQuantity = 0.0;
            $aheadSources = [];

            foreach (\array_slice(array: $ordered, offset: 0, length: (int) $last) as $row) {
                $remainder = $rows[(int) $row->id]['after'];

                if ($remainder <= self::TOL) {
                    continue;
                }

                $aheadQuantity += $remainder;
                $source = (string) $row->source_type . ':' . (int) $row->source_id;
                $aheadSources[$source] ??= ['source_type' => (string) $row->source_type, 'source_id' => (int) $row->source_id];
            }

            $lastRow = $ordered[(int) $last];

            return [
                'refusal' => new InventoryReservedFloorException(
                    itemId: (int) $record['unit']['item'],
                    combinationId: (int) $record['unit']['combination'],
                    locationId: (int) $record['location'],
                    onHand: $record['on_hand'],
                    reserved: $aheadQuantity,
                    requested: $onHandAfter,
                    minimum: InventoryReservedFloorException::minimumFor(onHand: $record['on_hand'], reserved: $aheadQuantity),
                    command: 'commit',
                    ahead: array_values(array: $aheadSources),
                ),
                'line_id' => $lastRow->source_line_id !== null ? (int) $lastRow->source_line_id : null,
            ];
        }

        return null;
    }

    /**
     * Whether the source already reserved this line (idempotent reserve).
     *
     * @since  2.0.0
     */
    private function reservationExists(string $sourceType, int $sourceId, ?int $lineId, int $itemId, int $combinationId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__alfa_inventory_reservations'))
            ->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
            ->where($this->db->quoteName('source_id') . ' = ' . $sourceId)
            ->where($this->db->quoteName('item_id') . ' = ' . $itemId)
            ->where($this->db->quoteName('combination_id') . ' = ' . $combinationId);

        if ($lineId !== null) {
            $query->where($this->db->quoteName('source_line_id') . ' = ' . $lineId);
        } else {
            $query->where($this->db->quoteName('source_line_id') . ' IS NULL');
        }

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    //  LEDGER READS (inside command transactions)

    /**
     * Cumulative ledger of one line for a movement type: the per-line
     * idempotency anchor. Deductions count positive.
     *
     * With $current the ledger is read as the latest committed state (a locking
     * read), as {@see self::lineNet()} reads it: restore() decides from it under
     * the order's row lock, and a plain read would answer from its transaction's
     * snapshot, which can predate a restore that committed while it waited.
     *
     * @param int|null $lineId The order line.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param string $movementType The movement type.
     * @param bool $current Read the latest committed state.
     *
     *
     * @since  2.0.0
     */
    private function lineLedger(?int $lineId, array $unit, string $movementType, bool $current = false): float
    {
        if ($lineId === null) {
            return 0.0;
        }

        $sign = $movementType === self::MOVE_DEDUCT ? -1 : 1;

        $query = $this->db->getQuery(true)
            ->select('COALESCE(SUM(qty_change), 0)')
            ->from($this->db->quoteName('#__alfa_inventory_movements'))
            ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
            ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
            ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
            ->where($this->db->quoteName('movement_type') . ' = ' . $this->db->quote($movementType));

        return $sign * (float) $this->db->setQuery($current ? (string) $query . ' LOCK IN SHARE MODE' : $query)->loadResult();
    }

    /**
     * Net quantity out for a line (deducts - restores), optionally
     * scoped to one writing source: the retry-detection anchor.
     *
     * @since  2.0.0
     */
    private function lineNet(?int $lineId, array $unit, ?string $sourceType = null, ?int $sourceId = null): float
    {
        if ($lineId === null) {
            return 0.0;
        }

        $query = $this->db->getQuery(true)
            ->select('COALESCE(-SUM(qty_change), 0)')
            ->from($this->db->quoteName('#__alfa_inventory_movements'))
            ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
            ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
            ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
            ->where($this->db->quoteName('movement_type') . ' IN (' . $this->db->quote(self::MOVE_DEDUCT) . ', ' . $this->db->quote(self::MOVE_RESTORE) . ')');

        if ($sourceType !== null) {
            $query->where($this->db->quoteName('source_type') . ' = ' . $this->db->quote($sourceType))
                ->where($this->db->quoteName('source_id') . ' = ' . (int) $sourceId);
        }

        // A CURRENT READ, not a snapshot read. Holding the order lock keeps
        // other deduct triggers for this order out of the critical section,
        // but it does NOT make their committed work visible: under REPEATABLE
        // READ this transaction's snapshot was fixed at its first read, and a
        // plain SELECT would still report the ledger as it looked then. The
        // lock and the current read are two halves of one fix; either alone
        // leaves the double deduction reachable.
        return (float) $this->db->setQuery((string) $query . ' LOCK IN SHARE MODE')->loadResult();
    }

    /**
     * Lock the order rows owning these request lines, in id order.
     *
     * @param array<int, array<string, mixed>> $lines The request's lines.
     *
     * @since  2.0.0
     */
    private function lockOrdersOfLines(array $lines): void
    {
        $lineIds = [];

        foreach ($lines as $line) {
            $lineId = isset($line['line_id']) ? (int) $line['line_id'] : 0;

            if ($lineId > 0) {
                $lineIds[$lineId] = true;
            }
        }

        if ($lineIds === []) {
            return;
        }

        // Resolved from the lines rather than taken from the caller: a
        // document, an RMA and a status change all deduct the same order lines
        // through different sourceTypes, and only the lines say which order
        // they belong to in every one of those cases.
        $this->db->setQuery(
            'SELECT DISTINCT ' . $this->db->quoteName('id_order')
            . ' FROM ' . $this->db->quoteName('#__alfa_order_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', array_keys($lineIds)) . ')'
            . ' ORDER BY ' . $this->db->quoteName('id_order'),
        );
        $orderIds = array_values(array_filter(array_map('intval', (array) $this->db->loadColumn())));

        if ($orderIds === []) {
            return;
        }

        $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('id') . ' FROM ' . $this->db->quoteName('#__alfa_orders')
            . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', $orderIds) . ')'
            . ' ORDER BY ' . $this->db->quoteName('id') . ' FOR UPDATE',
        )->loadColumn();
    }

    /**
     * The line's allocated locations: distinct locations of its
     * reservations (any state: allocation history), else the ENABLED
     * locations that actually hold the goods, for lines that never had an
     * allocation (pre-kernel orders, and any trigger that fulfils without
     * reserving first).
     *
     * Allocated locations come in the shop's own order of its locations
     * ({@see LocationPolicy::enabledIds()}: the default, then the ordering the
     * merchant set, then the id), a location no longer enabled last by id, so of
     * two allocated shelves with stock free for the line the one the shop puts
     * first gives first.
     *
     * THE RESERVATION HALF IS DELIBERATELY UNFILTERED. A reservation is a
     * promise the shop already made, and the location policy keeps such a promise fulfillable
     * from a shelf that has since been retired. Only the FALLBACK (where no
     * promise exists and the deduction is choosing a shelf now) is bound by
     * the state contract.
     *
     * The fallback used to be a bare `[defaultLocationId()]`, and that was
     * LOC-2: a two-location shop holding all of its stock at B had its
     * fulfilment refused for a shortfall at A that did not exist shop-wide,
     * and with negative_physical enabled it drove A's row arbitrarily negative
     * while B sat untouched. It is the same asymmetry {@see self::defaultAllocation()}
     * was fixed for on the reserve side, so it answers with the same ordering:
     * the shelves that hold the goods, default first, then the best stocked.
     *
     * The fallback depends on what the unit's records hold when it is asked: a shelf
     * an earlier line of the same command emptied is no longer among them, and the
     * best stocked may be another one. The command asks for each line in turn,
     * after its earlier writes. Its dry run writes nothing, so it passes the figures
     * its simulation has reached ($figures) and gets the same walk.
     *
     * @param int|null $lineId The order line, or none.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param array<int, array{quantity: float, reserved: float}> $figures Figures that stand in place of the
     *                                                                     stored ones for the unit's records, by
     *                                                                     location id; empty for the records as
     *                                                                     stored.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private function lineLocations(?int $lineId, array $unit, array $figures): array
    {
        if ($lineId !== null) {
            $query = $this->db->getQuery(true)
                ->select('DISTINCT ' . $this->db->quoteName('location_id'))
                ->from($this->db->quoteName('#__alfa_inventory_reservations'))
                ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
                ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
                ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
                ->order($this->db->quoteName('location_id'));
            $locations = array_map('intval', $this->db->setQuery($query)->loadColumn() ?: []);

            // Several allocated shelves are walked in the shop's own order of its
            // locations, as the fallback below and the reserve allocation are: the
            // default, then the ordering the merchant set, then the id. A shelf the
            // shop no longer offers comes last, by id.
            if (\count(value: $locations) > 1) {
                $rank = array_flip(array: LocationPolicy::enabledIds(db: $this->db));

                usort(
                    array: $locations,
                    callback: static fn (int $one, int $other): int => [$rank[$one] ?? PHP_INT_MAX, $one] <=> [$rank[$other] ?? PHP_INT_MAX, $other],
                );
            }

            if ($locations !== []) {
                return $locations;
            }
        }

        $default = $this->defaultLocationId();
        $stocked = [];

        foreach ($this->stockBearingEnabledLocations(unit: $unit, lock: false, figures: $figures) as $row) {
            if ((float) $row->quantity > self::EPS) {
                $stocked[] = (int) $row->location_id;
            }
        }

        // The default location closes the list rather than opening it: it is
        // where a genuine shortfall must surface (the shop restocks there, and
        // it is the one location that cannot be disabled), but it must not be
        // drawn from ahead of a shelf that actually holds the goods.
        if (!\in_array($default, $stocked, true)) {
            $stocked[] = $default;
        }

        return $stocked;
    }

    /**
     * Net deducted quantity per location for a line (deducts minus
     * restores): restores return quantity where it actually left.
     *
     * With $written, restore movements the caller has planned and not written
     * (by location, as stored) count as written ({@see self::restoreReach()}).
     * With $current the ledger is read as the latest committed state, as
     * restore() reads every figure it decides from.
     *
     * @param int|null $lineId The order line.
     * @param array{item: int, combination: int} $unit The line's acting identity.
     * @param array<int, float> $written Planned restore movements per location, not yet in the table.
     * @param bool $current Read the latest committed state.
     *
     * @return array<int, float> locationId => net deducted
     *
     * @since  2.0.0
     */
    private function lineDeductionsByLocation(?int $lineId, array $unit, array $written = [], bool $current = false): array
    {
        if ($lineId === null) {
            return [];
        }

        // Deduct rows are negative, restore rows positive: the negated
        // sum is the net quantity still out at each location.
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('location_id'))
            ->select('COALESCE(-SUM(qty_change), 0) AS net')
            ->from($this->db->quoteName('#__alfa_inventory_movements'))
            ->where($this->db->quoteName('source_line_id') . ' = ' . $lineId)
            ->where($this->db->quoteName('item_id') . ' = ' . $unit['item'])
            ->where($this->db->quoteName('combination_id') . ' = ' . $unit['combination'])
            ->where($this->db->quoteName('movement_type') . ' IN (' . $this->db->quote(self::MOVE_DEDUCT) . ', ' . $this->db->quote(self::MOVE_RESTORE) . ')')
            ->group($this->db->quoteName('location_id'))
            ->order($this->db->quoteName('location_id'));

        $result = [];

        foreach ($this->db->setQuery($current ? (string) $query . ' LOCK IN SHARE MODE' : $query)->loadObjectList() ?: [] as $row) {
            $net = (float) $row->net - ($written[(int) $row->location_id] ?? 0.0);

            if ($net > self::EPS) {
                $result[(int) $row->location_id] = $net;
            }
        }

        return $result;
    }

    //  Locations

    /**
     * The default location id, self-healing: exactly one default
     * location always exists, creating it when a bare database misses it.
     *
     * @since  2.0.0
     */
    public function defaultLocationId(): int
    {
        if ($this->defaultLocation !== null) {
            return $this->defaultLocation;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__alfa_inventory_locations'))
            ->where($this->db->quoteName('is_default') . ' = 1')
            ->order($this->db->quoteName('id'));
        $id = (int) $this->db->setQuery($query, 0, 1)->loadResult();

        if ($id === 0) {
            $row = (object) [
                'title' => 'Default location',
                'location_type' => 'physical',
                'capabilities' => 'storage,fulfillment',
                'kind' => 'warehouse',
                'is_default' => 1,
                'state' => 1,
                'ordering' => 1,
                'created' => Factory::getDate('now', 'UTC')->toSql(),
            ];
            $this->db->insertObject('#__alfa_inventory_locations', $row, 'id');
            $id = (int) $row->id;
        }

        return $this->defaultLocation = $id;
    }

    /**
     * @since  2.0.0
     */
    private function newOperationId(): string
    {
        return 'invop_' . bin2hex(random_bytes(10));
    }

    /**
     * Register the executed command on the operation spine: one row per
     * command, written INSIDE the command's transaction so the registry
     * and the state it describes commit or roll back together. External
     * connectors dedup business events against this table instead of
     * searching the movement ledger.
     *
     * @since  2.0.0
     */
    private function recordOperation(
        string $operationId,
        string $command,
        string $sourceType,
        int $sourceId,
        InventoryContext $context,
    ): void {
        $row = (object) [
            'operation_id' => $operationId,
            'command' => $command,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'correlation_id' => $context->correlationId,
            'status' => 'completed',
            'created' => Factory::getDate('now', 'UTC')->toSql(),
            'created_by' => $context->userId,
        ];

        $this->db->insertObject('#__alfa_inventory_operations', $row, 'id');
    }
}
