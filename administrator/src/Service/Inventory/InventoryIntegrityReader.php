<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Read-only inventory consistency audit.
 *
 * The stock records, movements and reservations are the inventory authority;
 * the stock and available_quantity columns of items and combinations are
 * projections the inventory service derives from them. The audit compares every
 * projection with the figures derived from the authority and reports each
 * identity whose stored state the inventory service could not have produced:
 *
 * - ledger_missing: a stock-tracked unit shows a non-zero figure (either sign)
 *   but has no stock record;
 * - integrity_hold: a unit kept unavailable for sale because a return or
 *   cancellation recorded stock on it while it was in that state; the hold ends
 *   only with the audited repair of its figures;
 * - physical_mismatch / salable_mismatch: a tracked unit's, or a
 *   combination-bearing item's, figure disagrees with its stock records;
 * - stranded_parent: a stock record held on a combination-bearing item itself,
 *   which is never sellable and never counted in any projection;
 * - untracked_stock: a stock record still holding a quantity on a unit that no
 *   longer tracks stock;
 * - archived_stock: a stock record of an archived combination, or of any
 *   identity of an archived product, still holding a quantity or a reservation,
 *   which nothing can sell;
 * - untracked_projection: an untracked unit's figures disagree with its records;
 * - orphan_stock_row: a stock record naming an item that is gone, or a
 *   combination that is gone or belongs to another item;
 * - retired_ledger_unbalanced: movements of an identity and location whose stock
 *   record no longer exists and whose net is not zero;
 * - orphan_reservation: open reservations on an identity that no longer exists;
 * - reserved_mismatch: a stock record's reserved quantity differs from the open
 *   remainders of its reservations, or open reservations name a location where
 *   their unit has no stock record, or a stock record's open reservations hold
 *   more than it has on hand while its unit denies backorders (no ordinary
 *   command leaves a record below its reservations under that policy);
 * - excess_reservation: an order line whose open reservations hold more than the
 *   line still owes its order on the stock identity it acts on, or hold stock on
 *   another identity, in a status that deducts the order's stock or claims none:
 *   a reservation no reconciliation of the order converts, which keeps the stock
 *   from other orders; listed for review only;
 * - short_deduction: an order line placed as a stock claim, of an order whose
 *   status deducts its stock and that owes no stock movement, with less
 *   delivered for it than was ordered: stock the order should have out and has
 *   not (what a physical return brought back is not missing); listed for review
 *   only;
 * - unexplained_quantity: a stock record's quantity differs from the net of its
 *   movements;
 * - unresolved_line: an order line whose stock identity no longer exists and has
 *   no recorded resolution, so the inventory service refuses its order's stock
 *   movements;
 * - untracked_claim_line: an open order line placed as a stock claim, with
 *   quantity still to deliver, whose stock identity is a current unit that no
 *   longer tracks stock; listed for review only.
 *
 * Two kinds of order lines are listed separately, as classifications: lines
 * sold on a product itself that now has combinations (historical_parent_line),
 * whose fulfilment, cancellation and returns act on the product's own stock
 * records, and lines a recorded resolution binds to another stock identity
 * (resolved_line). The inventory service handles both, so neither is an
 * inconsistency. Stock records whose open reservations hold more than they have
 * on hand (stock reserved under backorders) are listed the same way
 * (reservation_queue): their reservations in the order they are served
 * (ReservationQueue: first reserved, first served, unless an administrator served
 * an order first), each with what the stock on hand covers of it and what waits
 * for stock. Where the unit denies backorders, such a record is also reported as a
 * reserved_mismatch with the same figures.
 *
 * Stock held on a product itself, or on an archived identity, names the open
 * order lines that still rely on it: lines of open orders, placed as stock
 * claims, with quantity never yet delivered from that identity
 * (OpenLineClaims). A quantity a physical return brought back is not owed
 * again because its order is still open: it is ordinary stock of the identity
 * it came back to. The same lines are reported one by one when the identity
 * they rely on is a current unit that no longer tracks stock: such a line
 * keeps the tracking it was placed with, so its fulfilment deducts the unit's
 * stock records while nothing is reserved for it.
 *
 * Figures follow the inventory service's definitions. A combination row is
 * current unless it is archived (CombinationState). An item with current
 * combinations is combination-bearing and its units are those combinations; any
 * other item, including one whose combinations are all archived, is its own
 * unit. An archived combination keeps its own figures, judged against its own
 * records, but is never part of its item's figures. A unit's physical figure is
 * the sum of its records' quantities; its salable figure the sum of quantity
 * less reserved quantity at enabled locations. A plain item's projections are
 * its unit's two figures. A combination-bearing item's stock projection is the
 * physical sum over its current combinations and its available projection the
 * salable sum over its published, stock-tracked combinations; a record held on
 * the item itself counts in neither. A unit is tracked when its effective
 * manage_stock is 1: a combination's own value, else the item's, and NULL at
 * both levels is untracked. Quantities agree within
 * InventoryAvailabilityService::quantitiesAgree().
 *
 * An identity is resolvable while its item exists and, for a combination, its
 * row belongs to that item in any state: fulfilment, cancellation and returns
 * of existing orders act on it. Every identity of an archived product is kept
 * for order history only, like an archived combination. An order line acts on
 * the identity it was sold as, or on the one its effective recorded resolution
 * (the one no later resolution supersedes) binds it to when the line still
 * names that resolution's original or resolved identity; superseded
 * resolutions are history only.
 *
 * The audit issues SELECT statements only and never repairs: callers run it
 * inside a read-only consistent-snapshot transaction so every figure comes from
 * one point in time. Repairs are separate, explicit, audited inventory commands.
 *
 * @since  2.0.0
 */
final class InventoryIntegrityReader
{
    public const CATEGORY_LEDGER_MISSING = 'ledger_missing';

    public const CATEGORY_PHYSICAL_MISMATCH = 'physical_mismatch';

    public const CATEGORY_SALABLE_MISMATCH = 'salable_mismatch';

    public const CATEGORY_STRANDED_PARENT = 'stranded_parent';

    public const CATEGORY_UNTRACKED_STOCK = 'untracked_stock';

    public const CATEGORY_UNTRACKED_PROJECTION = 'untracked_projection';

    public const CATEGORY_ORPHAN_STOCK_ROW = 'orphan_stock_row';

    public const CATEGORY_RETIRED_LEDGER_UNBALANCED = 'retired_ledger_unbalanced';

    public const CATEGORY_ORPHAN_RESERVATION = 'orphan_reservation';

    public const CATEGORY_RESERVED_MISMATCH = 'reserved_mismatch';

    /**
     * An order line whose open reservations hold more than the line still owes.
     *
     * @since  2.0.0
     */
    public const CATEGORY_EXCESS_RESERVATION = 'excess_reservation';

    /**
     * An order line of an order whose status deducts stock with less delivered
     * for it than was ordered.
     *
     * @since  2.0.0
     */
    public const CATEGORY_SHORT_DEDUCTION = 'short_deduction';

    public const CATEGORY_UNEXPLAINED_QUANTITY = 'unexplained_quantity';

    public const CATEGORY_ARCHIVED_STOCK = 'archived_stock';

    public const CATEGORY_UNRESOLVED_LINE = 'unresolved_line';

    public const CATEGORY_INTEGRITY_HOLD = 'integrity_hold';

    public const CATEGORY_UNTRACKED_CLAIM_LINE = 'untracked_claim_line';

    /**
     * Every inconsistency category, in report order.
     *
     * @since  2.0.0
     */
    public const CATEGORIES = [
        self::CATEGORY_LEDGER_MISSING,
        self::CATEGORY_INTEGRITY_HOLD,
        self::CATEGORY_PHYSICAL_MISMATCH,
        self::CATEGORY_SALABLE_MISMATCH,
        self::CATEGORY_STRANDED_PARENT,
        self::CATEGORY_UNTRACKED_STOCK,
        self::CATEGORY_ARCHIVED_STOCK,
        self::CATEGORY_UNTRACKED_PROJECTION,
        self::CATEGORY_ORPHAN_STOCK_ROW,
        self::CATEGORY_RETIRED_LEDGER_UNBALANCED,
        self::CATEGORY_ORPHAN_RESERVATION,
        self::CATEGORY_RESERVED_MISMATCH,
        self::CATEGORY_EXCESS_RESERVATION,
        self::CATEGORY_SHORT_DEDUCTION,
        self::CATEGORY_UNEXPLAINED_QUANTITY,
        self::CATEGORY_UNRESOLVED_LINE,
        self::CATEGORY_UNTRACKED_CLAIM_LINE,
    ];

    /**
     * An order line whose stock moves against the product itself while the
     * product has current combinations, or that a recorded historical-parent
     * resolution binds to the product itself.
     */
    public const CLASSIFICATION_HISTORICAL_PARENT_LINE = 'historical_parent_line';

    /**
     * An order line whose effective recorded resolution binds it to another
     * stock identity of its product.
     */
    public const CLASSIFICATION_RESOLVED_LINE = 'resolved_line';

    /**
     * A stock record whose open reservations hold more than it has on hand: its
     * reservations in the order they are served, each with what the stock on hand
     * covers of it and what waits for stock.
     *
     * @since  2.0.0
     */
    public const CLASSIFICATION_RESERVATION_QUEUE = 'reservation_queue';

    /**
     * Every classification, in report order: listed for information, never an
     * inconsistency.
     *
     * @since  2.0.0
     */
    public const CLASSIFICATIONS = [
        self::CLASSIFICATION_HISTORICAL_PARENT_LINE,
        self::CLASSIFICATION_RESOLVED_LINE,
        self::CLASSIFICATION_RESERVATION_QUEUE,
    ];

    /** A plain item's own unit, or a record held on the item itself. */
    public const UNIT_ITEM = 'item';

    /** A combination unit. */
    public const UNIT_COMBINATION = 'combination';

    /** A combination-bearing item's aggregate projections. */
    public const UNIT_PARENT = 'parent';

    /** An order line. */
    public const UNIT_LINE = 'line';

    /**
     * Items audited per round of queries; bounds memory on a large catalogue.
     *
     * @since  2.0.0
     */
    private const BATCH_SIZE = 500;

    private DatabaseInterface $db;

    /**
     * @param DatabaseInterface|null $db The connection to read through; the application's connection when
     *                                   omitted. A caller that opens the snapshot transaction passes the
     *                                   connection it opened it on.
     *
     * @since  2.0.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(resourceName: DatabaseInterface::class);
    }

    /**
     * Audit the catalogue, or the given items, and report every inconsistency.
     *
     * Each finding is an array:
     * - category: one of CATEGORIES, or of CLASSIFICATIONS;
     * - key: 'category|item|combination|location|unit', stable across runs (location
     *   is empty for a unit-level finding and 'L<order line id>' for an order line,
     *   whose item and combination are the identity the line was sold as or, for
     *   a line that relies on a unit no longer tracking stock, that unit);
     * - item_id, combination_id (0 for the item itself), location_id (null for a
     *   unit-level or order-line finding);
     * - unit: UNIT_ITEM, UNIT_COMBINATION, UNIT_PARENT or UNIT_LINE;
     * - projection: the stored figure (null when none is stored or none applies);
     *   for an order line, the quantity ordered, and for excess_reservation what
     *   the line's open reservations hold;
     * - canonical: the figure derived from the inventory authority; for an order
     *   line, the quantity its movements deducted less what they restored, for
     *   short_deduction what was delivered for it (what its deductions took out
     *   less what restores undoing a delivery put back; a physical return is
     *   returned quantity, never subtracted), and for excess_reservation what the
     *   line may still hold: what it still owes its order on the stock identity it
     *   acts on, as far as its reservations there cover it;
     * - drift: the signed quantity the finding is about (projection less canonical);
     * - detail: category-specific facts.
     *
     * Scoped to items, the audit reads the stock records, movements, reservations
     * and order lines those items' ids name, including those of items that no
     * longer exist when their ids are given.
     *
     * @param int[]|null $itemIds The items to audit; null audits the whole catalogue together with every
     *                            item the stock records, movements, open reservations, order lines and open
     *                            integrity holds name.
     *
     * @return array{findings: array<string, list<array<string, mixed>>>, classifications: array<string, list<array<string, mixed>>>, scanned: array{units: int, rows: int}}
     *                                                                                                                                                                       Every category is present in findings, in CATEGORIES order, and every classification in
     *                                                                                                                                                                       classifications, in CLASSIFICATIONS order; only findings are inconsistencies. scanned
     *                                                                                                                                                                       counts the current stock units of the existing items audited (an archived product has
     *                                                                                                                                                                       none) and the stock records read.
     *
     * @since  2.0.0
     */
    public function audit(?array $itemIds): array
    {
        $findings = array_fill_keys(self::CATEGORIES, []);
        $classifications = array_fill_keys(self::CLASSIFICATIONS, []);
        $scanned = ['units' => 0, 'rows' => 0];

        $ids = $itemIds === null ? $this->catalogueItemIds() : $this->normaliseIds(itemIds: $itemIds);

        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $result = $this->auditBatch(itemIds: $batch);

            foreach ($result['findings'] as $finding) {
                if (\in_array($finding['category'], self::CLASSIFICATIONS, true)) {
                    $classifications[$finding['category']][] = $finding;

                    continue;
                }

                $findings[$finding['category']][] = $finding;
            }

            $scanned['units'] += $result['units'];
            $scanned['rows'] += $result['rows'];
        }

        $order = static fn (array $finding): array => [
            $finding['item_id'],
            $finding['combination_id'],
            $finding['location_id'] ?? -1,
            $finding['unit'],
            $finding['unit'] === self::UNIT_LINE ? (int) $finding['detail']['line_id'] : 0,
        ];

        $sorted = static function (array $groups) use ($order): array {
            foreach ($groups as &$list) {
                usort($list, static fn (array $a, array $b): int => $order(finding: $a) <=> $order(finding: $b));
            }

            unset($list);

            return $groups;
        };

        return ['findings' => $sorted(groups: $findings), 'classifications' => $sorted(groups: $classifications), 'scanned' => $scanned];
    }

    /**
     * Every item id the catalogue or the inventory authority names: items, stock
     * records, movements, open reservations, order lines and open integrity
     * holds, so identities of removed items are audited too.
     *
     * @return int[] Ascending, unique.
     *
     * @since  2.0.0
     */
    private function catalogueItemIds(): array
    {
        $db = $this->db;

        $ids = $db->setQuery(
            query: 'SELECT ' . $db->quoteName(name: 'id') . ' FROM ' . $db->quoteName(name: '#__alfa_items')
            . ' UNION SELECT ' . $db->quoteName(name: 'item_id') . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_stock')
            . ' UNION SELECT ' . $db->quoteName(name: 'item_id') . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_movements')
            . ' UNION SELECT ' . $db->quoteName(name: 'item_id') . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_reservations')
            . ' WHERE ' . $db->quoteName(name: 'state') . ' = ' . $db->quote(text: InventoryService::STATE_ACTIVE)
            . ' UNION SELECT DISTINCT ' . $db->quoteName(name: 'id_item') . ' FROM ' . $db->quoteName(name: '#__alfa_order_items')
            . ' UNION SELECT ' . $db->quoteName(name: 'item_id') . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_integrity_holds')
            . ' WHERE ' . $db->quoteName(name: 'released_operation_id') . ' IS NULL',
        )->loadColumn() ?: [];

        return $this->normaliseIds(itemIds: $ids);
    }

    /**
     * Positive integer ids, unique and ascending.
     *
     * @param array $itemIds Raw ids.
     *
     * @return int[]
     *
     * @since  2.0.0
     */
    private function normaliseIds(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * Audit one batch of item ids.
     *
     * @param int[] $itemIds Positive item ids.
     *
     * @return array{findings: list<array<string, mixed>>, units: int, rows: int}
     *
     * @since  2.0.0
     */
    private function auditBatch(array $itemIds): array
    {
        $db = $this->db;
        $in = implode(',', $itemIds);

        $items = [];

        foreach ($this->select(
            sql: 'SELECT i.' . $db->quoteName(name: 'id') . ', i.' . $db->quoteName(name: 'stock') . ', i.' . $db->quoteName(name: 'available_quantity')
            . ', i.' . $db->quoteName(name: 'manage_stock') . ', i.' . $db->quoteName(name: 'state') . ', i.' . $db->quoteName(name: 'backorder')
            . ' FROM ' . $db->quoteName(name: '#__alfa_items', as: 'i')
            . ' WHERE i.' . $db->quoteName(name: 'id') . ' IN (' . $in . ')',
        ) as $row) {
            $items[(int) $row->id] = $row;
        }

        // Every combination row in any state: each is a resolvable identity of
        // its item. Only the current ones are the item's stock units.
        $combinations = [];
        $current = [];
        $owners = [];

        foreach ($this->select(
            sql: 'SELECT c.' . $db->quoteName(name: 'id') . ', c.' . $db->quoteName(name: 'item_id') . ', c.' . $db->quoteName(name: 'stock')
            . ', c.' . $db->quoteName(name: 'available_quantity') . ', c.' . $db->quoteName(name: 'manage_stock') . ', c.' . $db->quoteName(name: 'state')
            . ', c.' . $db->quoteName(name: 'sku') . ', c.' . $db->quoteName(name: 'backorder')
            . ' FROM ' . $db->quoteName(name: '#__alfa_item_combinations', as: 'c')
            . ' WHERE c.' . $db->quoteName(name: 'item_id') . ' IN (' . $in . ')'
            . ' ORDER BY c.' . $db->quoteName(name: 'item_id') . ', c.' . $db->quoteName(name: 'id'),
        ) as $row) {
            $combinations[(int) $row->item_id][(int) $row->id] = $row;
            $owners[(int) $row->id] = (int) $row->item_id;

            if (CombinationState::isCurrent(state: $row->state)) {
                $current[(int) $row->item_id][(int) $row->id] = $row;
            }
        }

        $rowsByItem = [];
        $rowKeys = [];
        $rowCount = 0;

        foreach ($this->select(
            sql: 'SELECT s.' . $db->quoteName(name: 'id') . ', s.' . $db->quoteName(name: 'item_id') . ', s.' . $db->quoteName(name: 'combination_id')
            . ', s.' . $db->quoteName(name: 'location_id') . ', s.' . $db->quoteName(name: 'quantity') . ', s.' . $db->quoteName(name: 'reserved_quantity')
            . ', CASE WHEN ' . LocationPolicy::enabledPredicate(db: $db, column: 's.' . $db->quoteName(name: 'location_id'))
            . ' THEN 1 ELSE 0 END AS ' . $db->quoteName(name: 'enabled')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_stock', as: 's')
            . ' WHERE s.' . $db->quoteName(name: 'item_id') . ' IN (' . $in . ')'
            . ' ORDER BY s.' . $db->quoteName(name: 'item_id') . ', s.' . $db->quoteName(name: 'combination_id')
            . ', s.' . $db->quoteName(name: 'location_id') . ', s.' . $db->quoteName(name: 'id'),
        ) as $row) {
            $row->id = (int) $row->id;
            $row->item_id = (int) $row->item_id;
            $row->combination_id = (int) $row->combination_id;
            $row->location_id = (int) $row->location_id;
            $row->quantity = (float) $row->quantity;
            $row->reserved = (float) $row->reserved_quantity;
            $row->enabled = (int) $row->enabled === 1;

            $rowsByItem[$row->item_id][] = $row;
            $rowKeys[$this->identityKey(itemId: $row->item_id, combinationId: $row->combination_id, locationId: $row->location_id)] = true;
            $rowCount++;
        }

        $movements = [];

        foreach ($this->select(
            sql: 'SELECT m.' . $db->quoteName(name: 'item_id') . ', m.' . $db->quoteName(name: 'combination_id') . ', m.' . $db->quoteName(name: 'location_id')
            . ', SUM(m.' . $db->quoteName(name: 'qty_change') . ') AS ' . $db->quoteName(name: 'net')
            . ', COUNT(*) AS ' . $db->quoteName(name: 'movements')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_movements', as: 'm')
            . ' WHERE m.' . $db->quoteName(name: 'item_id') . ' IN (' . $in . ')'
            . ' GROUP BY m.' . $db->quoteName(name: 'item_id') . ', m.' . $db->quoteName(name: 'combination_id') . ', m.' . $db->quoteName(name: 'location_id'),
        ) as $row) {
            $movements[$this->identityKey(itemId: (int) $row->item_id, combinationId: (int) $row->combination_id, locationId: (int) $row->location_id)] = $row;
        }

        // Open remainders, summed per reservation exactly as the inventory
        // service computes one: quantity less fulfilled less released, never below 0.
        $active = [];

        foreach ($this->select(
            sql: 'SELECT r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id') . ', r.' . $db->quoteName(name: 'location_id')
            . ', COUNT(*) AS ' . $db->quoteName(name: 'reservations')
            . ', SUM(GREATEST(0, r.' . $db->quoteName(name: 'quantity') . ' - r.' . $db->quoteName(name: 'fulfilled_quantity')
            . ' - r.' . $db->quoteName(name: 'released_quantity') . ')) AS ' . $db->quoteName(name: 'remainder')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_reservations', as: 'r')
            . ' WHERE r.' . $db->quoteName(name: 'state') . ' = ' . $db->quote(text: InventoryService::STATE_ACTIVE)
            . ' AND r.' . $db->quoteName(name: 'item_id') . ' IN (' . $in . ')'
            . ' GROUP BY r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id') . ', r.' . $db->quoteName(name: 'location_id'),
        ) as $row) {
            $row->item_id = (int) $row->item_id;
            $row->combination_id = (int) $row->combination_id;
            $row->location_id = (int) $row->location_id;
            $row->reservations = (int) $row->reservations;
            $row->remainder = (float) $row->remainder;

            $active[$this->identityKey(itemId: $row->item_id, combinationId: $row->combination_id, locationId: $row->location_id)] = $row;
        }

        $owners += $this->foreignOwners(rowsByItem: $rowsByItem, active: $active, known: $owners);

        $findings = [];
        $units = 0;
        $strandedByItem = [];
        $untrackedRows = [];
        $untrackedUnits = [];
        $archivedRows = [];
        $archivedItems = [];

        foreach ($itemIds as $itemId) {
            $item = $items[$itemId] ?? null;
            $itemCombs = $current[$itemId] ?? [];
            $unitRows = [];
            $itemLevel = [];

            // An archived product (the archived catalogue state on the item row)
            // keeps every identity for order history only: none is a current
            // unit, and whatever stock they hold is archived stock.
            $retired = $item !== null && !CombinationState::isCurrent(state: $item->state);

            if ($retired) {
                $archivedItems[$itemId] = true;
            }

            foreach ($rowsByItem[$itemId] ?? [] as $row) {
                $problem = $this->identityProblem(
                    itemExists: $item !== null,
                    combinations: $combinations[$itemId] ?? [],
                    owners: $owners,
                    combinationId: $row->combination_id,
                );

                if ($problem !== null) {
                    $findings[] = $this->finding(
                        category: self::CATEGORY_ORPHAN_STOCK_ROW,
                        itemId: $itemId,
                        combinationId: $row->combination_id,
                        locationId: $row->location_id,
                        unit: $this->unitOf(combinationId: $row->combination_id),
                        projection: null,
                        canonical: $row->quantity,
                        drift: $row->quantity,
                        detail: [
                            'stock_id' => $row->id,
                            'reason' => $problem,
                            'owner_item_id' => $row->combination_id > 0 ? ($owners[$row->combination_id] ?? null) : null,
                            'quantity' => $row->quantity,
                            'reserved' => $row->reserved,
                        ],
                    );

                    continue;
                }

                if ($itemCombs !== [] && $row->combination_id === 0) {
                    $itemLevel[] = $row;

                    continue;
                }

                $unitRows[$row->combination_id][] = $row;
            }

            if ($item !== null && $itemCombs === []) {
                $units += $retired ? 0 : 1;
                $tracked = $this->effectiveTracking(own: null, item: $item->manage_stock);

                array_push($findings, ...$this->unitFindings(
                    itemId: $itemId,
                    combinationId: 0,
                    unit: self::UNIT_ITEM,
                    tracked: $tracked,
                    stock: $this->nullableFloat(value: $item->stock),
                    available: $this->nullableFloat(value: $item->available_quantity),
                    rows: $unitRows[0] ?? [],
                    archived: false,
                ));

                if ($retired) {
                    array_push($archivedRows, ...$this->heldRows(rows: $unitRows[0] ?? []));
                } elseif (!$tracked) {
                    array_push($untrackedRows, ...$this->heldRows(rows: $unitRows[0] ?? []));
                    $untrackedUnits[$itemId][0] = true;
                }
            }

            if ($item !== null && $itemCombs !== []) {
                $physicalAll = 0.0;
                $counted = 0.0;
                $counting = 0;

                foreach ($itemCombs as $combinationId => $combination) {
                    $units += $retired ? 0 : 1;
                    $rows = $unitRows[$combinationId] ?? [];
                    $figures = $this->figures(rows: $rows);
                    $tracked = $this->effectiveTracking(own: $combination->manage_stock, item: $item->manage_stock);

                    $physicalAll += $figures['physical'];

                    if ($tracked && (int) $combination->state === 1) {
                        $counted += $figures['salable'];
                        $counting++;
                    }

                    array_push($findings, ...$this->unitFindings(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        unit: self::UNIT_COMBINATION,
                        tracked: $tracked,
                        stock: $this->nullableFloat(value: $combination->stock),
                        available: $this->nullableFloat(value: $combination->available_quantity),
                        rows: $rows,
                        archived: false,
                    ));

                    if ($retired) {
                        array_push($archivedRows, ...$this->heldRows(rows: $rows));
                    } elseif (!$tracked) {
                        array_push($untrackedRows, ...$this->heldRows(rows: $rows));
                        $untrackedUnits[$itemId][$combinationId] = true;
                    }
                }

                array_push($findings, ...$this->parentFindings(
                    itemId: $itemId,
                    stock: $this->nullableFloat(value: $item->stock),
                    available: $this->nullableFloat(value: $item->available_quantity),
                    physical: $physicalAll,
                    salable: $counted,
                    combinations: \count($itemCombs),
                    counting: $counting,
                ));

                $held = $this->heldRows(rows: $itemLevel);

                if ($held !== [] && $retired) {
                    array_push($archivedRows, ...$held);
                } elseif ($held !== []) {
                    $strandedByItem[$itemId] = [
                        'held' => $held,
                        'all' => $itemLevel,
                        'combination_on_hand' => $physicalAll,
                        'combination_salable' => $counted,
                    ];
                }
            }

            // Archived combinations: not stock units and never part of the item's
            // figures, but each keeps its own figures against its own records.
            if ($item !== null) {
                foreach (array_diff_key($combinations[$itemId] ?? [], $itemCombs) as $combinationId => $combination) {
                    $rows = $unitRows[$combinationId] ?? [];

                    array_push($findings, ...$this->unitFindings(
                        itemId: $itemId,
                        combinationId: $combinationId,
                        unit: self::UNIT_COMBINATION,
                        tracked: $this->effectiveTracking(own: $combination->manage_stock, item: $item->manage_stock),
                        stock: $this->nullableFloat(value: $combination->stock),
                        available: $this->nullableFloat(value: $combination->available_quantity),
                        rows: $rows,
                        archived: true,
                    ));
                    array_push($archivedRows, ...$this->heldRows(rows: $rows));
                }
            }

            foreach ($rowsByItem[$itemId] ?? [] as $row) {
                array_push($findings, ...$this->rowFindings(
                    row: $row,
                    movement: $movements[$this->identityKey(itemId: $itemId, combinationId: $row->combination_id, locationId: $row->location_id)] ?? null,
                    active: $active[$this->identityKey(itemId: $itemId, combinationId: $row->combination_id, locationId: $row->location_id)] ?? null,
                ));
            }
        }

        array_push($findings, ...$this->lineFindings(
            itemIds: $itemIds,
            items: $items,
            combinations: $combinations,
            current: $current,
            owners: $owners,
            rowsByItem: $rowsByItem,
        ));

        array_push($findings, ...$this->holdFindings(
            itemIds: $itemIds,
            items: $items,
            combinations: $combinations,
            current: $current,
            rowsByItem: $rowsByItem,
        ));

        // What still weighs on the identities holding stock that nothing can
        // sell: the open order lines relying on them, and the unapplied stock
        // effects of the orders acting on them. The same read, once for the
        // batch, covers every unit that no longer tracks stock, whether or not
        // it still holds any: an open line relying on such a unit is a finding
        // of its own.
        $claimedItems = array_values(array_unique([...array_keys($strandedByItem), ...array_map(static fn (object $row): int => $row->item_id, $archivedRows)]));
        $relyingItems = array_values(array_unique([...$claimedItems, ...array_keys($untrackedUnits)]));
        $relying = $relyingItems === [] ? [] : $this->relyingLines(itemIds: $relyingItems);
        $pending = $claimedItems === [] ? [] : $this->pendingEffects(itemIds: $claimedItems);

        array_push($findings, ...$this->strandedFindings(strandedByItem: $strandedByItem, active: $active, pending: $pending, relying: $relying));
        array_push($findings, ...$this->untrackedStockFindings(rows: $untrackedRows, active: $active, relying: $relying));
        array_push($findings, ...$this->archivedStockFindings(
            rows: $archivedRows,
            active: $active,
            pending: $pending,
            relying: $relying,
            archivedItems: $archivedItems,
        ));
        array_push($findings, ...$this->untrackedClaimLineFindings(
            untrackedUnits: $untrackedUnits,
            relying: $relying,
            rowsByItem: $rowsByItem,
        ));

        foreach ($movements as $key => $movement) {
            $net = (float) $movement->net;

            if (isset($rowKeys[$key]) || abs($net) <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                continue;
            }

            $combinationId = (int) $movement->combination_id;
            $findings[] = $this->finding(
                category: self::CATEGORY_RETIRED_LEDGER_UNBALANCED,
                itemId: (int) $movement->item_id,
                combinationId: $combinationId,
                locationId: (int) $movement->location_id,
                unit: $this->unitOf(combinationId: $combinationId),
                projection: null,
                canonical: 0.0,
                drift: $net,
                detail: ['movements' => (int) $movement->movements, 'net' => $net],
            );
        }

        // A reservation on an identity that still resolves (an archived
        // combination, or an item that now has combinations) is converted or
        // released by its order like any other; only a vanished identity orphans it.
        foreach ($active as $key => $group) {
            $problem = $this->identityProblem(
                itemExists: isset($items[$group->item_id]),
                combinations: $combinations[$group->item_id] ?? [],
                owners: $owners,
                combinationId: $group->combination_id,
            );

            if ($problem === null) {
                // Open reservations at a location where their unit has no stock
                // record: no record carries the reserved quantity, so its reserved
                // figure reads as 0 against the open remainder.
                if (!isset($rowKeys[$key]) && !InventoryAvailabilityService::quantitiesAgree(a: 0.0, b: $group->remainder)) {
                    $findings[] = $this->finding(
                        category: self::CATEGORY_RESERVED_MISMATCH,
                        itemId: $group->item_id,
                        combinationId: $group->combination_id,
                        locationId: $group->location_id,
                        unit: $this->unitOf(combinationId: $group->combination_id),
                        projection: 0.0,
                        canonical: $group->remainder,
                        drift: -$group->remainder,
                        detail: [
                            'stock_id' => null,
                            'reserved' => 0.0,
                            'active_remainder' => $group->remainder,
                            'active_reservations' => $group->reservations,
                        ],
                    );
                }

                continue;
            }

            $findings[] = $this->finding(
                category: self::CATEGORY_ORPHAN_RESERVATION,
                itemId: $group->item_id,
                combinationId: $group->combination_id,
                locationId: $group->location_id,
                unit: $this->unitOf(combinationId: $group->combination_id),
                projection: null,
                canonical: $group->remainder,
                drift: $group->remainder,
                detail: ['reason' => $problem, 'reservations' => $group->reservations, 'remainder' => $group->remainder],
            );
        }

        array_push($findings, ...$this->queueFindings(
            rowsByItem: $rowsByItem,
            active: $active,
            items: $items,
            combinations: $combinations,
            owners: $owners,
        ));

        array_push($findings, ...$this->excessReservationFindings(itemIds: $itemIds));

        // A line reported as unresolved_line is listed there only: one defect, one finding.
        $unresolved = [];

        foreach ($findings as $finding) {
            if ($finding['category'] === self::CATEGORY_UNRESOLVED_LINE) {
                $unresolved[(int) $finding['detail']['line_id']] = true;
            }
        }

        array_push($findings, ...$this->shortDeductionFindings(itemIds: $itemIds, unresolved: $unresolved));

        return ['findings' => $findings, 'units' => $units, 'rows' => $rowCount];
    }

    /**
     * The order lines whose open reservations hold more than the line still owes
     * its order.
     *
     * A line is judged by the claim its order's status makes, read as the order's
     * stock reconciliation reads it: a cancelled status claims nothing, a status
     * that deducts stock (or one the shop no longer has) claims its stock as a
     * deduction, any other holds reservations. In a status that deducts, a line
     * still owes what its order has to deduct itself on the stock identity the
     * line acts on (the one it was sold as, or the one its effective recorded
     * resolution binds it to): its ordered quantity less what other sources (a
     * delivery note) have out for it, the stock of theirs the order itself put back
     * on another identity (a cancellation gives back what a delivery note shipped)
     * not counted as out, clamped at zero, less what the order itself has out for it
     * on that identity, the figure the order's status commit converts reservations
     * up to there. What the order itself has out on another identity does not
     * count. Only the line's reservations on that identity, up to that figure, are
     * a claim the commit converts; every reservation on another identity is held
     * beyond it. A cancelled order's line owes nothing. A line whose order's status
     * holds reservations is not judged here: the order's reconciliation sizes its
     * reservation on the variant the line sells. Nor is a line of an order with no
     * status, which no reconciliation acts on.
     *
     * Per line: projection is what its order's open reservations on it hold,
     * canonical what of it the line may still hold (what it still owes, as far as
     * its reservations on the identity it acts on cover it), drift what they hold
     * beyond that, which is what the order's own reconciliation releases. The detail
     * names the order and the line, what was ordered, is out and is left to
     * deliver, whether the order is cancelled, the order's stock effects not yet
     * applied (an owed status commit converts or releases the reservations when it
     * applies), and each reservation's combination, location and quantity.
     * Reported only; nothing is released here.
     *
     * @param int[] $itemIds The batch's item ids.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function excessReservationFindings(array $itemIds): array
    {
        $db = $this->db;
        $holds = [];

        foreach ($this->select(
            sql: 'SELECT r.' . $db->quoteName(name: 'source_id') . ', r.' . $db->quoteName(name: 'source_line_id')
            . ', r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id') . ', r.' . $db->quoteName(name: 'location_id')
            . ', GREATEST(0, r.' . $db->quoteName(name: 'quantity') . ' - r.' . $db->quoteName(name: 'fulfilled_quantity')
            . ' - r.' . $db->quoteName(name: 'released_quantity') . ') AS ' . $db->quoteName(name: 'remainder')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_reservations', as: 'r')
            . ' WHERE r.' . $db->quoteName(name: 'state') . ' = ' . $db->quote(text: InventoryService::STATE_ACTIVE)
            . ' AND r.' . $db->quoteName(name: 'source_type') . ' = ' . $db->quote(text: 'order')
            . ' AND r.' . $db->quoteName(name: 'source_line_id') . ' IS NOT NULL'
            . ' AND r.' . $db->quoteName(name: 'item_id') . ' IN (' . implode(separator: ',', array: $itemIds) . ')'
            . ' ORDER BY r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'id'),
        ) as $row) {
            $holds[(int) $row->source_line_id][] = $row;
        }

        if ($holds === []) {
            return [];
        }

        $lineList = implode(separator: ',', array: array_keys(array: $holds));
        $lines = [];

        // The stock identity each line acts on: the one it was sold as, or the one its
        // effective recorded resolution binds it to while the line still names that
        // resolution's original or resolved identity.
        $frozenItem = 'oi.' . $db->quoteName(name: 'id_item');
        $frozenCombination = 'COALESCE(oi.' . $db->quoteName(name: 'id_combination') . ', 0)';
        $resolved = 'lr.' . $db->quoteName(name: 'id') . ' IS NOT NULL AND ('
            . '(' . $frozenItem . ' = lr.' . $db->quoteName(name: 'original_item_id')
            . ' AND ' . $frozenCombination . ' = lr.' . $db->quoteName(name: 'original_combination_id') . ')'
            . ' OR (' . $frozenItem . ' = lr.' . $db->quoteName(name: 'resolved_item_id')
            . ' AND ' . $frozenCombination . ' = lr.' . $db->quoteName(name: 'resolved_combination_id') . '))';

        // Each line with its order's claim, as the order's reconciliation reads the
        // status (orderClaimSql()).
        foreach ($this->select(
            sql: 'SELECT oi.' . $db->quoteName(name: 'id') . ', oi.' . $db->quoteName(name: 'id_order') . ', oi.' . $db->quoteName(name: 'id_item')
            . ', oi.' . $db->quoteName(name: 'id_combination') . ', oi.' . $db->quoteName(name: 'quantity')
            . ', CASE WHEN ' . $resolved . ' THEN lr.' . $db->quoteName(name: 'resolved_item_id') . ' ELSE ' . $frozenItem . ' END AS ' . $db->quoteName(name: 'acting_item')
            . ', CASE WHEN ' . $resolved . ' THEN lr.' . $db->quoteName(name: 'resolved_combination_id') . ' ELSE ' . $frozenCombination . ' END AS ' . $db->quoteName(name: 'acting_combination')
            . ', ' . $this->orderClaimSql(orderAlias: 'o', statusAlias: 's') . ' AS ' . $db->quoteName(name: 'claim')
            . ' FROM ' . $db->quoteName(name: '#__alfa_order_items', as: 'oi')
            . ' INNER JOIN ' . $db->quoteName(name: '#__alfa_orders', as: 'o') . ' ON o.' . $db->quoteName(name: 'id') . ' = oi.' . $db->quoteName(name: 'id_order')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_orders_statuses', as: 's') . ' ON s.' . $db->quoteName(name: 'id') . ' = o.' . $db->quoteName(name: 'id_order_status')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_inventory_line_resolutions', as: 'lr')
            . ' ON lr.' . $db->quoteName(name: 'order_item_id') . ' = oi.' . $db->quoteName(name: 'id')
            . ' AND ' . InventoryService::effectiveResolutionSql(db: $db, alias: 'lr')
            . ' WHERE oi.' . $db->quoteName(name: 'id') . ' IN (' . $lineList . ')',
        ) as $row) {
            $lines[(int) $row->id] = $row;
        }

        // What each line has out, the order's own movements apart from every other
        // source's, and each per stock identity.
        $out = [];
        $ownOn = [];
        $otherOn = [];

        foreach ($this->select(
            sql: 'SELECT m.' . $db->quoteName(name: 'source_line_id') . ', m.' . $db->quoteName(name: 'source_type') . ', m.' . $db->quoteName(name: 'source_id')
            . ', m.' . $db->quoteName(name: 'item_id') . ', m.' . $db->quoteName(name: 'combination_id')
            . ', COALESCE(-SUM(m.' . $db->quoteName(name: 'qty_change') . '), 0) AS ' . $db->quoteName(name: 'net')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_movements', as: 'm')
            . ' WHERE m.' . $db->quoteName(name: 'source_line_id') . ' IN (' . $lineList . ')'
            . ' AND m.' . $db->quoteName(name: 'movement_type') . ' IN (' . $db->quote(text: InventoryService::MOVE_DEDUCT) . ', ' . $db->quote(text: InventoryService::MOVE_RESTORE) . ')'
            . ' GROUP BY m.' . $db->quoteName(name: 'source_line_id') . ', m.' . $db->quoteName(name: 'source_type') . ', m.' . $db->quoteName(name: 'source_id')
            . ', m.' . $db->quoteName(name: 'item_id') . ', m.' . $db->quoteName(name: 'combination_id'),
        ) as $row) {
            $lineId = (int) $row->source_line_id;
            $own = isset($lines[$lineId]) && (string) $row->source_type === 'order' && (int) $row->source_id === (int) $lines[$lineId]->id_order;

            $out[$lineId][$own ? 'own' : 'other'] = ($out[$lineId][$own ? 'own' : 'other'] ?? 0.0) + (float) $row->net;

            $unit = (int) $row->item_id . ':' . (int) $row->combination_id;

            if ($own) {
                $ownOn[$lineId][$unit] = ($ownOn[$lineId][$unit] ?? 0.0) + (float) $row->net;
            } else {
                $otherOn[$lineId][$unit] = ($otherOn[$lineId][$unit] ?? 0.0) + (float) $row->net;
            }
        }

        // The orders' stock effects not yet applied.
        $owed = [];
        $orderIds = array_values(array: array_unique(array: array_map(callback: static fn (object $line): int => (int) $line->id_order, array: $lines)));

        if ($orderIds !== []) {
            foreach ($this->select(
                sql: 'SELECT e.' . $db->quoteName(name: 'order_id') . ', COUNT(*) AS ' . $db->quoteName(name: 'owed')
                . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_effect_outbox', as: 'e')
                . ' WHERE e.' . $db->quoteName(name: 'order_id') . ' IN (' . implode(separator: ',', array: $orderIds) . ')'
                . ' AND e.' . $db->quoteName(name: 'status') . ' <> ' . $db->quote(text: InventoryEffectOutbox::STATUS_APPLIED)
                . ' GROUP BY e.' . $db->quoteName(name: 'order_id'),
            ) as $row) {
                $owed[(int) $row->order_id] = (int) $row->owed;
            }
        }

        $findings = [];

        foreach ($holds as $lineId => $reservations) {
            $line = $lines[$lineId] ?? null;

            // A line of an order that no longer exists, has no status, or whose
            // status holds reservations, is not judged here; only the line's own
            // order's reservations count.
            if ($line === null || (int) $line->claim >= 2) {
                continue;
            }

            $reservations = array_values(array: array_filter(
                array: $reservations,
                callback: static fn (object $hold): bool => (int) $hold->source_id === (int) $line->id_order,
            ));
            $ordered = max(0.0, (float) $line->quantity);
            $ownOut = $out[$lineId]['own'] ?? 0.0;
            $otherOut = max(0.0, $out[$lineId]['other'] ?? 0.0);
            $acting = (int) $line->acting_item . ':' . (int) $line->acting_combination;
            $reserved = array_sum(array: array_map(callback: static fn (object $hold): float => (float) $hold->remainder, array: $reservations));

            // What the order put back of other sources' stock on an identity the line no
            // longer acts on: not out any more, as the status commit's share counts it.
            $givenBack = 0.0;

            foreach (array_keys(array: $otherOn[$lineId] ?? []) as $unit) {
                if ((string) $unit !== $acting) {
                    $givenBack += min(0.0, $ownOn[$lineId][$unit] ?? 0.0);
                }
            }

            // What the order still has to deduct on the identity the line acts on, as the
            // status commit measures it: its share less what it has out there itself.
            // What the order has out on another identity does not count. Only a
            // reservation on that identity, up to that figure, is a claim the commit
            // converts; any other is held beyond it.
            $share = max(0.0, $ordered - max(0.0, ($out[$lineId]['other'] ?? 0.0) + $givenBack));
            $owes = (int) $line->claim === 0 ? 0.0 : max(0.0, $share - ($ownOn[$lineId][$acting] ?? 0.0));
            $heldThere = array_sum(array: array_map(
                callback: static fn (object $hold): float => (int) $hold->item_id . ':' . (int) $hold->combination_id === $acting ? (float) $hold->remainder : 0.0,
                array: $reservations,
            ));
            $kept = min($owes, $heldThere);

            if ($reserved - $kept <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                continue;
            }

            $itemId = (int) $line->id_item;
            $combinationId = (int) ($line->id_combination ?? 0);

            $findings[] = [
                'category' => self::CATEGORY_EXCESS_RESERVATION,
                'key' => implode(separator: '|', array: [self::CATEGORY_EXCESS_RESERVATION, $itemId, $combinationId, 'L' . $lineId, self::UNIT_LINE]),
                'item_id' => $itemId,
                'combination_id' => $combinationId,
                'location_id' => null,
                'unit' => self::UNIT_LINE,
                'projection' => $reserved,
                'canonical' => $kept,
                'drift' => $reserved - $kept,
                'detail' => [
                    'order_id' => (int) $line->id_order,
                    'line_id' => $lineId,
                    'ordered' => $ordered,
                    'delivered' => $ownOut + $otherOut,
                    'outstanding' => max(0.0, $ordered - $ownOut - $otherOut),
                    'reserved' => $reserved,
                    'order_cancelled' => (int) $line->claim === 0,
                    'pending_effects' => $owed[(int) $line->id_order] ?? 0,
                    'reservations' => array_map(
                        callback: static fn (object $hold): array => [
                            'combination_id' => (int) $hold->combination_id,
                            'location_id' => (int) $hold->location_id,
                            'reserved' => (float) $hold->remainder,
                        ],
                        array: $reservations,
                    ),
                ],
            ];
        }

        return $findings;
    }

    /**
     * The order lines short of their stock deduction: lines whose order's status
     * deducts stock, with less delivered for them than was ordered.
     *
     * A line is judged when its order is out of the trash and its status claims
     * the order's stock as a deduction, read as the order's stock reconciliation
     * reads it ({@see self::orderClaimSql()}): a status that deducts stock,
     * completed ones included, or one the shop no longer has. A line of a
     * cancelled order, of an order whose status holds reservations or of an order
     * with no status is not judged. Nor is a line not placed as a stock claim
     * ({@see OpenLineClaims::placedTrackedSql()}), a line of an order that still
     * owes a stock movement (applying it deducts the rest or is refused, and the
     * owed movement is reported where outstanding stock movements are), or a line
     * reported as unresolved_line.
     *
     * What was delivered for a line is read over all of its movements, from every
     * source and on every stock identity, by the definition of OpenLineClaims:
     * what its deductions took out less what restores undoing a delivery put back.
     * A physical return is returned quantity, not a reversal, so a line returned
     * in full is not short.
     *
     * Per line: projection is what was ordered, canonical what was delivered,
     * drift what is missing. The detail names the order and the line, what was
     * ordered, delivered, returned and is left to deliver, and what the order's
     * open reservations hold for the line on any stock identity. One grouped read
     * per batch finds the short lines; the stock claim, the owed movements and the
     * reservations are read for those lines only. Reported only; nothing is
     * deducted here.
     *
     * @param int[] $itemIds The batch's item ids.
     * @param array<int, true> $unresolved Order line id => true for every line the batch reports as
     *                                     unresolved_line.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function shortDeductionFindings(array $itemIds, array $unresolved): array
    {
        $db = $this->db;
        $orderedSql = 'GREATEST(0, oi.' . $db->quoteName(name: 'quantity') . ')';
        $deliveredSql = 'COALESCE(SUM(' . OpenLineClaims::deliveredTermSql(db: $db, movementAlias: 'm') . '), 0)';
        $tolerance = number_format(num: InventoryAvailabilityService::QUANTITY_TOLERANCE, decimals: 8, decimal_separator: '.', thousands_separator: '');
        $lineColumns = 'oi.' . $db->quoteName(name: 'id') . ', oi.' . $db->quoteName(name: 'id_order') . ', oi.' . $db->quoteName(name: 'id_item')
            . ', oi.' . $db->quoteName(name: 'id_combination') . ', oi.' . $db->quoteName(name: 'quantity');
        $short = [];

        // Lines of orders out of the trash whose status claims a deduction (claim 1),
        // with what was delivered and returned over all of their deduct and restore
        // movements; only lines short beyond the tolerance come back.
        foreach ($this->select(
            sql: 'SELECT ' . $lineColumns
            . ', ' . $deliveredSql . ' AS ' . $db->quoteName(name: 'delivered')
            . ', COALESCE(SUM(' . OpenLineClaims::returnedTermSql(db: $db, movementAlias: 'm') . '), 0) AS ' . $db->quoteName(name: 'returned')
            . ' FROM ' . $db->quoteName(name: '#__alfa_order_items', as: 'oi')
            . ' INNER JOIN ' . $db->quoteName(name: '#__alfa_orders', as: 'o') . ' ON o.' . $db->quoteName(name: 'id') . ' = oi.' . $db->quoteName(name: 'id_order')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_orders_statuses', as: 's') . ' ON s.' . $db->quoteName(name: 'id') . ' = o.' . $db->quoteName(name: 'id_order_status')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_inventory_movements', as: 'm')
            . ' ON m.' . $db->quoteName(name: 'source_line_id') . ' = oi.' . $db->quoteName(name: 'id')
            . ' AND m.' . $db->quoteName(name: 'movement_type') . ' IN (' . $db->quote(text: InventoryService::MOVE_DEDUCT) . ', ' . $db->quote(text: InventoryService::MOVE_RESTORE) . ')'
            . ' WHERE oi.' . $db->quoteName(name: 'id_item') . ' IN (' . implode(separator: ',', array: $itemIds) . ')'
            . ' AND COALESCE(o.' . $db->quoteName(name: 'state') . ', 1) <> -2'
            . ' AND ' . $this->orderClaimSql(orderAlias: 'o', statusAlias: 's') . ' = 1'
            . ' GROUP BY ' . $lineColumns
            . ' HAVING ' . $orderedSql . ' - ' . $deliveredSql . ' > ' . $tolerance,
        ) as $row) {
            if (!isset($unresolved[(int) $row->id])) {
                $short[(int) $row->id] = $row;
            }
        }

        if ($short === []) {
            return [];
        }

        // Only a line placed as a stock claim is judged.
        $claims = [];

        foreach ($this->select(
            sql: 'SELECT oi.' . $db->quoteName(name: 'id')
            . ' FROM ' . $db->quoteName(name: '#__alfa_order_items', as: 'oi')
            . ' WHERE oi.' . $db->quoteName(name: 'id') . ' IN (' . implode(separator: ',', array: array_keys(array: $short)) . ')'
            . ' AND ' . OpenLineClaims::placedTrackedSql(db: $db, lineAlias: 'oi') . ' = 1',
        ) as $row) {
            $claims[(int) $row->id] = true;
        }

        $short = array_intersect_key($short, $claims);

        if ($short === []) {
            return [];
        }

        // An order that still owes a stock movement is left to it.
        $owing = [];
        $orderIds = array_values(array: array_unique(array: array_map(callback: static fn (object $line): int => (int) $line->id_order, array: $short)));

        foreach ($this->select(
            sql: 'SELECT DISTINCT e.' . $db->quoteName(name: 'order_id')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_effect_outbox', as: 'e')
            . ' WHERE e.' . $db->quoteName(name: 'order_id') . ' IN (' . implode(separator: ',', array: $orderIds) . ')'
            . ' AND e.' . $db->quoteName(name: 'status') . ' <> ' . $db->quote(text: InventoryEffectOutbox::STATUS_APPLIED),
        ) as $row) {
            $owing[(int) $row->order_id] = true;
        }

        $short = array_filter(array: $short, callback: static fn (object $line): bool => !isset($owing[(int) $line->id_order]));

        if ($short === []) {
            return [];
        }

        // What the line's own order holds for it, on any stock identity.
        $reserved = [];

        foreach ($this->select(
            sql: 'SELECT r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'source_id')
            . ', SUM(GREATEST(0, r.' . $db->quoteName(name: 'quantity') . ' - r.' . $db->quoteName(name: 'fulfilled_quantity')
            . ' - r.' . $db->quoteName(name: 'released_quantity') . ')) AS ' . $db->quoteName(name: 'remainder')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_reservations', as: 'r')
            . ' WHERE r.' . $db->quoteName(name: 'state') . ' = ' . $db->quote(text: InventoryService::STATE_ACTIVE)
            . ' AND r.' . $db->quoteName(name: 'source_type') . ' = ' . $db->quote(text: 'order')
            . ' AND r.' . $db->quoteName(name: 'source_line_id') . ' IN (' . implode(separator: ',', array: array_keys(array: $short)) . ')'
            . ' GROUP BY r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'source_id'),
        ) as $row) {
            $lineId = (int) $row->source_line_id;

            if ((int) $row->source_id === (int) $short[$lineId]->id_order) {
                $reserved[$lineId] = (float) $row->remainder;
            }
        }

        $findings = [];

        foreach ($short as $lineId => $line) {
            $ordered = max(0.0, (float) $line->quantity);
            $delivered = (float) $line->delivered;
            $itemId = (int) $line->id_item;
            $combinationId = (int) ($line->id_combination ?? 0);

            $findings[] = [
                'category' => self::CATEGORY_SHORT_DEDUCTION,
                'key' => implode(separator: '|', array: [self::CATEGORY_SHORT_DEDUCTION, $itemId, $combinationId, 'L' . $lineId, self::UNIT_LINE]),
                'item_id' => $itemId,
                'combination_id' => $combinationId,
                'location_id' => null,
                'unit' => self::UNIT_LINE,
                'projection' => $ordered,
                'canonical' => $delivered,
                'drift' => $ordered - $delivered,
                'detail' => [
                    'order_id' => (int) $line->id_order,
                    'line_id' => $lineId,
                    'ordered' => $ordered,
                    'delivered' => $delivered,
                    'returned' => (float) $line->returned,
                    'outstanding' => $ordered - $delivered,
                    'reserved' => $reserved[$lineId] ?? 0.0,
                ],
            ];
        }

        return $findings;
    }

    /**
     * The SQL value of the claim an order's status makes on its stock, as the
     * order's stock reconciliation reads it: 0 none (a cancellation), 1 a
     * deduction (a status that deducts stock, or one the shop no longer has),
     * 2 reservations, 3 no status at all, which nothing reconciles.
     *
     * @param string $orderAlias The alias of #__alfa_orders.
     * @param string $statusAlias The alias of #__alfa_orders_statuses, LEFT JOINed on the order's status.
     *
     * @return string A CASE expression.
     *
     * @since  2.0.0
     */
    private function orderClaimSql(string $orderAlias, string $statusAlias): string
    {
        $db = $this->db;

        return 'CASE WHEN COALESCE(' . $orderAlias . '.' . $db->quoteName(name: 'id_order_status') . ', 0) <= 0 THEN 3'
            . ' WHEN ' . $statusAlias . '.' . $db->quoteName(name: 'id') . ' IS NULL THEN 1'
            . ' WHEN ' . $statusAlias . '.' . $db->quoteName(name: 'is_cancelled') . ' = 1 THEN 0'
            . ' WHEN ' . $statusAlias . '.' . $db->quoteName(name: 'stock_operation') . ' = 0 THEN 1'
            . ' ELSE 2 END';
    }

    /**
     * The stock records whose open reservations hold more than they have on hand,
     * each with its reservations in the order they are served
     * ({@see ReservationQueue}): what the stock on hand covers of each, and what
     * waits for stock. A record of an identity that does not resolve is left to the
     * orphan findings.
     *
     * Per record: projection is what the open reservations hold, canonical the
     * quantity on hand, drift what waits for stock. Each reservation names its
     * order and order line (or its source when it is not an order's), what it
     * holds, what is covered and what waits, and, when an administrator served it
     * first, the reason recorded with it.
     *
     * A record whose unit denies backorders (its combination's backorder setting,
     * else its product's, else the shop's, as the inventory service resolves it)
     * holds no queue the shop allows: it is reported as a reserved_mismatch too,
     * with the same figures, unless its reserved quantity already differs from its
     * open reservations (that record is a reserved_mismatch already).
     *
     * @param array $rowsByItem Stock records by item id.
     * @param array $active Open reservation groups by identity key.
     * @param array $items Item rows by id.
     * @param array $combinations Combination rows by item id and id.
     * @param array $owners Combination id => owning item id.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function queueFindings(array $rowsByItem, array $active, array $items, array $combinations, array $owners): array
    {
        $db = $this->db;
        $records = [];

        foreach ($rowsByItem as $itemId => $rows) {
            foreach ($rows as $row) {
                $key = $this->identityKey(itemId: $row->item_id, combinationId: $row->combination_id, locationId: $row->location_id);
                $group = $active[$key] ?? null;

                if ($group === null || $group->remainder - $row->quantity <= InventoryAvailabilityService::QUANTITY_TOLERANCE) {
                    continue;
                }

                if ($this->identityProblem(
                    itemExists: isset($items[$itemId]),
                    combinations: $combinations[$itemId] ?? [],
                    owners: $owners,
                    combinationId: $row->combination_id,
                ) !== null) {
                    continue;
                }

                $records[$key] = $row;
            }
        }

        if ($records === []) {
            return [];
        }

        $queues = [];

        foreach ($this->select(
            sql: 'SELECT r.' . $db->quoteName(name: 'id') . ', r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id')
            . ', r.' . $db->quoteName(name: 'location_id') . ', r.' . $db->quoteName(name: 'source_type') . ', r.' . $db->quoteName(name: 'source_id')
            . ', r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'priority') . ', r.' . $db->quoteName(name: 'created')
            . ', r.' . $db->quoteName(name: 'note')
            . ', GREATEST(0, r.' . $db->quoteName(name: 'quantity') . ' - r.' . $db->quoteName(name: 'fulfilled_quantity')
            . ' - r.' . $db->quoteName(name: 'released_quantity') . ') AS ' . $db->quoteName(name: 'remainder')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_reservations', as: 'r')
            . ' WHERE r.' . $db->quoteName(name: 'state') . ' = ' . $db->quote(text: InventoryService::STATE_ACTIVE)
            . ' AND r.' . $db->quoteName(name: 'item_id') . ' IN (' . implode(separator: ',', array: array_unique(array: array_map(callback: static fn (object $row): int => $row->item_id, array: $records))) . ')'
            . ' ORDER BY r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id') . ', r.' . $db->quoteName(name: 'location_id')
            . ', ' . ReservationQueue::orderSql(db: $db, alias: 'r'),
        ) as $reservation) {
            $key = $this->identityKey(itemId: (int) $reservation->item_id, combinationId: (int) $reservation->combination_id, locationId: (int) $reservation->location_id);

            if (isset($records[$key])) {
                $queues[$key][] = $reservation;
            }
        }

        $findings = [];

        foreach ($records as $key => $row) {
            $queue = $queues[$key] ?? [];
            $backing = ReservationQueue::backing(
                queue: array_map(callback: static fn (object $claim): array => ['id' => (int) $claim->id, 'remainder' => (float) $claim->remainder], array: $queue),
                onHand: $row->quantity,
            );
            $held = 0.0;
            $waiting = 0.0;
            $claims = [];

            foreach ($queue as $claim) {
                $covered = $backing[(int) $claim->id];
                $held += (float) $claim->remainder;
                $waiting += $covered['waiting'];
                $entry = (string) $claim->source_type === 'order'
                    ? ['order_id' => (int) $claim->source_id, 'line_id' => $claim->source_line_id === null ? null : (int) $claim->source_line_id]
                    : ['source' => (string) $claim->source_type . ' #' . (int) $claim->source_id];
                $entry += [
                    'reserved' => (float) $claim->remainder,
                    'backed' => $covered['backed'],
                    'waiting' => $covered['waiting'],
                ];

                if ((int) $claim->priority > 0) {
                    $entry['served_first'] = (string) $claim->note;
                }

                $claims[] = $entry;
            }

            $detail = [
                'stock_id' => $row->id,
                'on_hand' => $row->quantity,
                'reserved' => $held,
                'waiting' => $waiting,
                'reservations' => $claims,
            ];

            $findings[] = $this->finding(
                category: self::CLASSIFICATION_RESERVATION_QUEUE,
                itemId: $row->item_id,
                combinationId: $row->combination_id,
                locationId: $row->location_id,
                unit: $this->unitOf(combinationId: $row->combination_id),
                projection: $held,
                canonical: $row->quantity,
                drift: $held - $row->quantity,
                detail: $detail,
            );

            // Below its reservations where the shop allows no backorder: no ordinary
            // command leaves a record there, so it is an inconsistency to review.
            if ($this->backordersDenied(item: $items[$row->item_id] ?? null, combination: $combinations[$row->item_id][$row->combination_id] ?? null)
                && InventoryAvailabilityService::quantitiesAgree(a: $row->reserved, b: $active[$key]->remainder)
            ) {
                $findings[] = $this->finding(
                    category: self::CATEGORY_RESERVED_MISMATCH,
                    itemId: $row->item_id,
                    combinationId: $row->combination_id,
                    locationId: $row->location_id,
                    unit: $this->unitOf(combinationId: $row->combination_id),
                    projection: $held,
                    canonical: $row->quantity,
                    drift: $held - $row->quantity,
                    detail: $detail,
                );
            }
        }

        return $findings;
    }

    /**
     * Whether a stock record's unit denies backorders: its combination's own
     * backorder setting when it states one, else its product's, else the shop's,
     * read the one way every surface reads it ({@see BackorderPolicy::effective()}).
     *
     * @param object|null $item The product row (with its backorder column), or null.
     * @param object|null $combination The combination row (with its backorder column), or null for the
     *                                 product's own unit.
     *
     *
     * @since  2.0.0
     */
    private function backordersDenied(?object $item, ?object $combination): bool
    {
        $stored = ($combination !== null && $combination->backorder !== null)
            ? (string) $combination->backorder
            : (($item?->backorder ?? null) !== null ? (string) $item->backorder : null);

        return (new BackorderPolicy())->effective(stored: $stored) === InventoryService::BACKORDER_DENY;
    }

    /**
     * Owners of combination ids named by stock records or open reservations but
     * not belonging to an item of the batch, so a record naming another item's
     * combination is told apart from one naming a combination that is gone.
     *
     * @param array $rowsByItem Stock records by item id.
     * @param array $active Open reservation groups by identity key.
     * @param array $known Combination id => owning item id already read.
     *
     * @return array<int, int> Combination id => owning item id.
     *
     * @since  2.0.0
     */
    private function foreignOwners(array $rowsByItem, array $active, array $known): array
    {
        $wanted = [];

        foreach ($rowsByItem as $rows) {
            foreach ($rows as $row) {
                if ($row->combination_id > 0 && !isset($known[$row->combination_id])) {
                    $wanted[$row->combination_id] = $row->combination_id;
                }
            }
        }

        foreach ($active as $group) {
            if ($group->combination_id > 0 && !isset($known[$group->combination_id])) {
                $wanted[$group->combination_id] = $group->combination_id;
            }
        }

        return $this->combinationOwners(combinationIds: array_values($wanted));
    }

    /**
     * The owning item of each combination id that still has a row, in any state.
     *
     * @param int[] $combinationIds Positive combination ids.
     *
     * @return array<int, int> Combination id => owning item id; ids without a row are absent.
     *
     * @since  2.0.0
     */
    private function combinationOwners(array $combinationIds): array
    {
        if ($combinationIds === []) {
            return [];
        }

        $db = $this->db;
        $owners = [];

        foreach ($this->select(
            sql: 'SELECT c.' . $db->quoteName(name: 'id') . ', c.' . $db->quoteName(name: 'item_id')
            . ' FROM ' . $db->quoteName(name: '#__alfa_item_combinations', as: 'c')
            . ' WHERE c.' . $db->quoteName(name: 'id') . ' IN (' . implode(',', array_map('intval', $combinationIds)) . ')',
        ) as $row) {
            $owners[(int) $row->id] = (int) $row->item_id;
        }

        return $owners;
    }

    /**
     * Why an identity does not resolve, or null when it does. It resolves while
     * its item exists and, for a combination, the combination's row belongs to
     * that item in any state (archived included); the item itself (combination
     * 0) resolves whatever combinations the item has.
     *
     * @param bool $itemExists Whether the item row exists.
     * @param array $combinations The item's combination rows by id, in every state.
     * @param array $owners Combination id => owning item id, for every combination id in question.
     * @param int $combinationId The identity's combination id (0 = the item itself).
     *
     * @return string|null item_gone | combination_gone | combination_foreign
     *
     * @since  2.0.0
     */
    private function identityProblem(bool $itemExists, array $combinations, array $owners, int $combinationId): ?string
    {
        if (!$itemExists) {
            return 'item_gone';
        }

        if ($combinationId > 0 && !isset($combinations[$combinationId])) {
            return isset($owners[$combinationId]) ? 'combination_foreign' : 'combination_gone';
        }

        return null;
    }

    /**
     * Findings of one stock unit, or of an archived combination against its own
     * records: missing ledger or figure mismatches when it tracks stock,
     * disagreeing figures when it does not.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for a plain item.
     * @param string $unit UNIT_ITEM or UNIT_COMBINATION.
     * @param bool $tracked Effective stock tracking.
     * @param float|null $stock Stored physical projection.
     * @param float|null $available Stored salable projection.
     * @param array $rows The unit's stock records.
     * @param bool $archived True for an archived combination: every finding's detail says so.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function unitFindings(int $itemId, int $combinationId, string $unit, bool $tracked, ?float $stock, ?float $available, array $rows, bool $archived): array
    {
        $findings = $this->figureFindings(
            itemId: $itemId,
            combinationId: $combinationId,
            unit: $unit,
            tracked: $tracked,
            stock: $stock,
            available: $available,
            rows: $rows,
        );

        if ($archived) {
            foreach ($findings as &$finding) {
                $finding['detail']['archived'] = true;
            }

            unset($finding);
        }

        return $findings;
    }

    /**
     * The figure findings of one unit's stored projections against its records.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for a plain item.
     * @param string $unit UNIT_ITEM or UNIT_COMBINATION.
     * @param bool $tracked Effective stock tracking.
     * @param float|null $stock Stored physical projection.
     * @param float|null $available Stored salable projection.
     * @param array $rows The unit's stock records.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function figureFindings(int $itemId, int $combinationId, string $unit, bool $tracked, ?float $stock, ?float $available, array $rows): array
    {
        $figures = $this->figures(rows: $rows);

        if (!$tracked) {
            if ($this->agree(projection: $stock, canonical: $figures['physical'])
                && $this->agree(projection: $available, canonical: $figures['salable'])) {
                return [];
            }

            return [$this->figurePairFinding(
                category: self::CATEGORY_UNTRACKED_PROJECTION,
                itemId: $itemId,
                combinationId: $combinationId,
                unit: $unit,
                stock: $stock,
                available: $available,
                figures: $figures,
            )];
        }

        if ($figures['rows'] === 0) {
            if (!InventoryAvailabilityService::projectionNonZero(stock: $stock, available: $available)) {
                return [];
            }

            return [$this->figurePairFinding(
                category: self::CATEGORY_LEDGER_MISSING,
                itemId: $itemId,
                combinationId: $combinationId,
                unit: $unit,
                stock: $stock,
                available: $available,
                figures: $figures,
            )];
        }

        $findings = [];

        if (!$this->agree(projection: $stock, canonical: $figures['physical'])) {
            $findings[] = $this->finding(
                category: self::CATEGORY_PHYSICAL_MISMATCH,
                itemId: $itemId,
                combinationId: $combinationId,
                locationId: null,
                unit: $unit,
                projection: $stock,
                canonical: $figures['physical'],
                drift: ($stock ?? 0.0) - $figures['physical'],
                detail: ['rows' => $figures['rows']],
            );
        }

        if (!$this->agree(projection: $available, canonical: $figures['salable'])) {
            $findings[] = $this->finding(
                category: self::CATEGORY_SALABLE_MISMATCH,
                itemId: $itemId,
                combinationId: $combinationId,
                locationId: null,
                unit: $unit,
                projection: $available,
                canonical: $figures['salable'],
                drift: ($available ?? 0.0) - $figures['salable'],
                detail: ['rows' => $figures['rows'], 'reserved' => $figures['reserved']],
            );
        }

        return $findings;
    }

    /**
     * Findings of a combination-bearing item's own projections against the
     * counting rule: physical over its current combinations, salable over the
     * published, stock-tracked ones.
     *
     * @param int $itemId The item.
     * @param float|null $stock Stored physical projection.
     * @param float|null $available Stored salable projection.
     * @param float $physical Physical sum over its current combinations.
     * @param float $salable Salable sum over the counting combinations.
     * @param int $combinations Number of current combinations.
     * @param int $counting Number of published, stock-tracked combinations.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function parentFindings(int $itemId, ?float $stock, ?float $available, float $physical, float $salable, int $combinations, int $counting): array
    {
        $findings = [];
        $detail = ['combinations' => $combinations, 'counting' => $counting];

        if (!$this->agree(projection: $stock, canonical: $physical)) {
            $findings[] = $this->finding(
                category: self::CATEGORY_PHYSICAL_MISMATCH,
                itemId: $itemId,
                combinationId: 0,
                locationId: null,
                unit: self::UNIT_PARENT,
                projection: $stock,
                canonical: $physical,
                drift: ($stock ?? 0.0) - $physical,
                detail: $detail,
            );
        }

        if (!$this->agree(projection: $available, canonical: $salable)) {
            $findings[] = $this->finding(
                category: self::CATEGORY_SALABLE_MISMATCH,
                itemId: $itemId,
                combinationId: 0,
                locationId: null,
                unit: self::UNIT_PARENT,
                projection: $available,
                canonical: $salable,
                drift: ($available ?? 0.0) - $salable,
                detail: $detail,
            );
        }

        return $findings;
    }

    /**
     * Findings of one stock record against its own authorities: its reserved
     * quantity against the open remainders of its reservations, its quantity
     * against the net of its movements.
     *
     * @param object $row The stock record.
     * @param object|null $movement The movement group of its identity and location.
     * @param object|null $active The open reservation group of its identity and location.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function rowFindings(object $row, ?object $movement, ?object $active): array
    {
        $findings = [];
        $remainder = $active !== null ? $active->remainder : 0.0;
        $net = $movement !== null ? (float) $movement->net : 0.0;
        $unit = $this->unitOf(combinationId: $row->combination_id);

        if (!InventoryAvailabilityService::quantitiesAgree(a: $row->reserved, b: $remainder)) {
            $findings[] = $this->finding(
                category: self::CATEGORY_RESERVED_MISMATCH,
                itemId: $row->item_id,
                combinationId: $row->combination_id,
                locationId: $row->location_id,
                unit: $unit,
                projection: $row->reserved,
                canonical: $remainder,
                drift: $row->reserved - $remainder,
                detail: [
                    'stock_id' => $row->id,
                    'reserved' => $row->reserved,
                    'active_remainder' => $remainder,
                    'active_reservations' => $active !== null ? $active->reservations : 0,
                ],
            );
        }

        if (!InventoryAvailabilityService::quantitiesAgree(a: $row->quantity, b: $net)) {
            $findings[] = $this->finding(
                category: self::CATEGORY_UNEXPLAINED_QUANTITY,
                itemId: $row->item_id,
                combinationId: $row->combination_id,
                locationId: $row->location_id,
                unit: $unit,
                projection: $row->quantity,
                canonical: $net,
                drift: $row->quantity - $net,
                detail: [
                    'stock_id' => $row->id,
                    'quantity' => $row->quantity,
                    'movements_net' => $net,
                    'movements' => $movement !== null ? (int) $movement->movements : 0,
                ],
            );
        }

        return $findings;
    }

    /**
     * One finding per stock record held on a combination-bearing item itself.
     * Such stock is excluded from every availability figure; each finding
     * carries the whole item-level picture next to the combinations' figures,
     * what still claims the item's own stock, and the open order lines that
     * still rely on it (while any remain, the stock cannot be written off).
     *
     * @param array $strandedByItem Item id => {held, all, combination_on_hand, combination_salable}.
     * @param array $active Open reservation groups by identity key.
     * @param array $pending Item id => combination id => unapplied stock effects, as
     *                       pendingEffects() returns them.
     * @param array $relying Item id => combination id => relying open lines, as relyingLines()
     *                       returns them.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function strandedFindings(array $strandedByItem, array $active, array $pending, array $relying): array
    {
        $findings = [];

        foreach ($strandedByItem as $itemId => $stranded) {
            $onHand = 0.0;
            $reserved = 0.0;
            $records = [];

            foreach ($stranded['all'] as $row) {
                $onHand += $row->quantity;
                $reserved += $row->reserved;
            }

            foreach ($stranded['held'] as $row) {
                $records[] = ['stock_id' => $row->id, 'location_id' => $row->location_id, 'quantity' => $row->quantity, 'reserved' => $row->reserved];
            }

            $reservations = $this->activeReservationsOf(active: $active, itemId: $itemId, combinationId: 0);
            $claims = $relying[$itemId][0] ?? [];
            $outstanding = $this->outstandingOf(lines: $claims);

            foreach ($stranded['held'] as $row) {
                $findings[] = $this->finding(
                    category: self::CATEGORY_STRANDED_PARENT,
                    itemId: $itemId,
                    combinationId: 0,
                    locationId: $row->location_id,
                    unit: self::UNIT_ITEM,
                    projection: null,
                    canonical: $row->quantity,
                    drift: $row->quantity,
                    detail: [
                        'stock_id' => $row->id,
                        'reserved' => $row->reserved,
                        'rows' => $records,
                        'parent_on_hand' => $onHand,
                        'parent_reserved' => $reserved,
                        'combination_on_hand' => $stranded['combination_on_hand'],
                        'combination_salable' => $stranded['combination_salable'],
                        'excluded' => true,
                        'active_reservations' => $reservations,
                        'pending_effects' => $pending[$itemId][0] ?? 0,
                        'open_lines' => $claims,
                        'outstanding' => $outstanding,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * One finding per stock record still holding a quantity on a unit that no
     * longer tracks stock, with what else still references the unit: its open
     * reservations at that location and the open order lines that still rely
     * on it (lines placed while it tracked stock).
     *
     * @param array $rows The held records of untracked units.
     * @param array $active Open reservation groups by identity key.
     * @param array $relying Item id => combination id => relying open lines, as relyingLines() returns them.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function untrackedStockFindings(array $rows, array $active, array $relying): array
    {
        $findings = [];

        foreach ($rows as $row) {
            $group = $active[$this->identityKey(itemId: $row->item_id, combinationId: $row->combination_id, locationId: $row->location_id)] ?? null;

            $findings[] = $this->finding(
                category: self::CATEGORY_UNTRACKED_STOCK,
                itemId: $row->item_id,
                combinationId: $row->combination_id,
                locationId: $row->location_id,
                unit: $this->unitOf(combinationId: $row->combination_id),
                projection: null,
                canonical: $row->quantity,
                drift: $row->quantity,
                detail: [
                    'stock_id' => $row->id,
                    'on_hand' => $row->quantity,
                    'reserved' => $row->reserved,
                    'active_reservations' => $group !== null ? $group->reservations : 0,
                    'open_order_lines' => \count($relying[$row->item_id][$row->combination_id] ?? []),
                ],
            );
        }

        return $findings;
    }

    /**
     * One finding per stock record of an archived identity still holding a
     * quantity or a reservation: a record of an archived combination, or any
     * record of an archived product. Nothing can sell it; it leaves only through
     * the audited write-off, which is refused while the identity is reserved,
     * has open reservations or pending stock effects, or while open order lines
     * still rely on it. Each finding names those claims and lines.
     *
     * @param array $rows The held records of archived identities.
     * @param array $active Open reservation groups by identity key.
     * @param array $pending Item id => combination id => unapplied stock effects, as pendingEffects()
     *                       returns them.
     * @param array $relying Item id => combination id => relying open lines, as relyingLines() returns them.
     * @param array $archivedItems Item id => true for every archived product of the batch.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function archivedStockFindings(array $rows, array $active, array $pending, array $relying, array $archivedItems): array
    {
        $findings = [];

        foreach ($rows as $row) {
            $lines = $relying[$row->item_id][$row->combination_id] ?? [];

            $findings[] = $this->finding(
                category: self::CATEGORY_ARCHIVED_STOCK,
                itemId: $row->item_id,
                combinationId: $row->combination_id,
                locationId: $row->location_id,
                unit: $this->unitOf(combinationId: $row->combination_id),
                projection: null,
                canonical: $row->quantity,
                drift: $row->quantity,
                detail: [
                    'stock_id' => $row->id,
                    'on_hand' => $row->quantity,
                    'reserved' => $row->reserved,
                    'active_reservations' => $this->activeReservationsOf(active: $active, itemId: $row->item_id, combinationId: $row->combination_id),
                    'pending_effects' => $pending[$row->item_id][$row->combination_id] ?? 0,
                    'open_lines' => $lines,
                    'outstanding' => $this->outstandingOf(lines: $lines),
                    'archived_item' => isset($archivedItems[$row->item_id]),
                    'excluded' => true,
                ],
            );
        }

        return $findings;
    }

    /**
     * One finding per open order line that still relies on a current unit that
     * no longer tracks stock: a line of an open order, placed as a stock claim,
     * with quantity never yet delivered from that unit (OpenLineClaims). The
     * line keeps the tracking it was placed with, so its fulfilment deducts the
     * unit's stock records while nothing is reserved for it. Each finding is
     * keyed by the unit the line acts on and by the line, and carries what was
     * ordered, delivered from the unit, returned to it and still to deliver,
     * next to the open reservations the line itself holds on the unit, as every
     * other order-line finding counts them, and the quantity the unit's stock
     * records hold. Listed for review only: no command repairs it.
     *
     * @param array $untrackedUnits Item id => combination id (0 for the item itself) => true: every current
     *                              unit of the batch that does not track stock, of products that are not archived.
     * @param array $relying Item id => combination id => relying open lines, as relyingLines() returns them.
     * @param array $rowsByItem Stock records of the batch by item id.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function untrackedClaimLineFindings(array $untrackedUnits, array $relying, array $rowsByItem): array
    {
        $lineIds = [];

        foreach ($untrackedUnits as $itemId => $unitIds) {
            foreach (array_keys($unitIds) as $combinationId) {
                foreach ($relying[$itemId][$combinationId] ?? [] as $line) {
                    $lineIds[$line['line_id']] = $line['line_id'];
                }
            }
        }

        if ($lineIds === []) {
            return [];
        }

        // The open reservations each reported line holds, by line and identity:
        // read only when the batch has such a line.
        $db = $this->db;
        $holds = [];

        foreach ($this->select(
            sql: 'SELECT r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id')
            . ', COUNT(*) AS ' . $db->quoteName(name: 'reservations')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_reservations', as: 'r')
            . ' WHERE r.' . $db->quoteName(name: 'state') . ' = ' . $db->quote(text: InventoryService::STATE_ACTIVE)
            . ' AND r.' . $db->quoteName(name: 'source_line_id') . ' IN (' . implode(',', $lineIds) . ')'
            . ' GROUP BY r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id'),
        ) as $row) {
            $holds[(int) $row->source_line_id . '|' . (int) $row->item_id . '|' . (int) $row->combination_id] = (int) $row->reservations;
        }

        $findings = [];

        foreach ($untrackedUnits as $itemId => $unitIds) {
            foreach (array_keys($unitIds) as $combinationId) {
                $lines = $relying[$itemId][$combinationId] ?? [];

                if ($lines === []) {
                    continue;
                }

                $onHand = 0.0;

                foreach ($rowsByItem[$itemId] ?? [] as $row) {
                    if ($row->combination_id === $combinationId) {
                        $onHand += $row->quantity;
                    }
                }

                foreach ($lines as $line) {
                    $findings[] = $this->lineFinding(
                        category: self::CATEGORY_UNTRACKED_CLAIM_LINE,
                        line: (object) ['id' => $line['line_id'], 'item_id' => $itemId, 'combination_id' => $combinationId],
                        detail: [
                            'order_id' => $line['order_id'],
                            'line_id' => $line['line_id'],
                            'order_open' => true,
                            'ordered' => $line['ordered'],
                            'deducted_net' => $line['delivered'] - $line['returned'],
                            'delivered' => $line['delivered'],
                            'returned' => $line['returned'],
                            'active_reservations' => $holds[$line['line_id'] . '|' . $itemId . '|' . $combinationId] ?? 0,
                            'outstanding' => $line['outstanding'],
                            'placed_tracked' => true,
                            'on_hand' => $onHand,
                        ],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Stock-effect obligations not yet applied for orders holding a line that
     * acts on each identity of the items, counted as the inventory service
     * counts them before a write-off: a line acts on the identity it was sold
     * as, or on the resolved identity of its effective recorded resolution when
     * the line still names that resolution's original or resolved identity.
     *
     * @param int[] $itemIds The items.
     *
     * @return array<int, array<int, int>> Item id => combination id (0 for the item itself) => number of obligations.
     *
     * @since  2.0.0
     */
    private function pendingEffects(array $itemIds): array
    {
        $db = $this->db;
        $pending = [];

        $frozenItem = 'oi.' . $db->quoteName(name: 'id_item');
        $frozenCombination = 'COALESCE(oi.' . $db->quoteName(name: 'id_combination') . ', 0)';
        $resolved = 'r.' . $db->quoteName(name: 'id') . ' IS NOT NULL AND ('
            . '(' . $frozenItem . ' = r.' . $db->quoteName(name: 'original_item_id')
            . ' AND ' . $frozenCombination . ' = r.' . $db->quoteName(name: 'original_combination_id') . ')'
            . ' OR (' . $frozenItem . ' = r.' . $db->quoteName(name: 'resolved_item_id')
            . ' AND ' . $frozenCombination . ' = r.' . $db->quoteName(name: 'resolved_combination_id') . '))';
        $actingItem = 'CASE WHEN ' . $resolved . ' THEN r.' . $db->quoteName(name: 'resolved_item_id') . ' ELSE ' . $frozenItem . ' END';
        $actingCombination = 'CASE WHEN ' . $resolved . ' THEN r.' . $db->quoteName(name: 'resolved_combination_id') . ' ELSE ' . $frozenCombination . ' END';

        foreach ($this->select(
            sql: 'SELECT ' . $frozenItem . ' AS ' . $db->quoteName(name: 'item_id')
            . ', ' . $actingCombination . ' AS ' . $db->quoteName(name: 'combination_id')
            . ', COUNT(DISTINCT o.' . $db->quoteName(name: 'id') . ') AS ' . $db->quoteName(name: 'pending')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_effect_outbox', as: 'o')
            . ' INNER JOIN ' . $db->quoteName(name: '#__alfa_order_items', as: 'oi')
            . ' ON oi.' . $db->quoteName(name: 'id_order') . ' = o.' . $db->quoteName(name: 'order_id')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_inventory_line_resolutions', as: 'r')
            . ' ON r.' . $db->quoteName(name: 'order_item_id') . ' = oi.' . $db->quoteName(name: 'id')
            . ' AND ' . InventoryService::effectiveResolutionSql(db: $db, alias: 'r')
            . ' WHERE o.' . $db->quoteName(name: 'status') . ' <> ' . $db->quote(text: InventoryEffectOutbox::STATUS_APPLIED)
            . ' AND ' . $frozenItem . ' IN (' . implode(',', array_map('intval', $itemIds)) . ')'
            . ' AND ' . $actingItem . ' = ' . $frozenItem
            . ' GROUP BY ' . $frozenItem . ', ' . $actingCombination,
        ) as $row) {
            $pending[(int) $row->item_id][(int) $row->combination_id] = (int) $row->pending;
        }

        return $pending;
    }

    /**
     * The open order lines that still rely on each identity of the items: lines
     * of open orders, placed as stock claims, with quantity never yet delivered
     * from the identity they act on, exactly the lines the inventory service
     * refuses a write-off, a removal or switching stock tracking off for
     * (OpenLineClaims). Each entry carries what was ordered, what was delivered
     * from the identity for the line, what came back to it by a physical return,
     * and the outstanding quantity (ordered less delivered) that makes the line
     * rely; it says the line was placed as a stock claim, which is why it still
     * needs the stock.
     *
     * @param int[] $itemIds The items.
     *
     * @return array<int, array<int, list<array{order_id: int, line_id: int, ordered: float, delivered: float, returned: float, outstanding: float, placed_tracked: bool}>>>
     *                                                                                                                                                                       Item id => combination id (0 for the item itself) => lines, by order and line.
     *
     * @since  2.0.0
     */
    private function relyingLines(array $itemIds): array
    {
        $relying = [];

        foreach ((new OpenLineClaims(db: $this->db))->forItems(itemIds: $itemIds) as $itemId => $byCombination) {
            foreach ($byCombination as $combinationId => $lines) {
                $list = [];

                foreach ($lines as $line) {
                    $line = (array) $line;
                    $list[] = [
                        'order_id' => (int) $line['order_id'],
                        'line_id' => (int) $line['line_id'],
                        'ordered' => (float) $line['ordered'],
                        'delivered' => (float) $line['delivered'],
                        'returned' => (float) $line['returned'],
                        'outstanding' => (float) $line['outstanding'],
                        'placed_tracked' => true,
                    ];
                }

                usort($list, static fn (array $a, array $b): int => [$a['order_id'], $a['line_id']] <=> [$b['order_id'], $b['line_id']]);

                $relying[(int) $itemId][(int) $combinationId] = $list;
            }
        }

        return $relying;
    }

    /**
     * The open reservations naming one identity, at every location.
     *
     * @param array $active Open reservation groups by identity key.
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item itself.
     *
     *
     * @since  2.0.0
     */
    private function activeReservationsOf(array $active, int $itemId, int $combinationId): int
    {
        $reservations = 0;

        foreach ($active as $group) {
            if ($group->item_id === $itemId && $group->combination_id === $combinationId) {
                $reservations += $group->reservations;
            }
        }

        return $reservations;
    }

    /**
     * The quantity a list of relying open lines still has to deliver.
     *
     * @param array $lines Entries with an outstanding quantity.
     *
     *
     * @since  2.0.0
     */
    private function outstandingOf(array $lines): float
    {
        $outstanding = 0.0;

        foreach ($lines as $line) {
            $outstanding += $line['outstanding'];
        }

        return $outstanding;
    }

    /**
     * One finding per open integrity hold of the batch: a unit kept unavailable
     * for sale since a return or cancellation recorded stock on it while its
     * figures showed stock with no stock record behind them. The hold ends only
     * with the audited repair of the unit's figures, so every open hold is an
     * inconsistency still to repair. Each finding carries the hold (when it
     * began, the acting user and command, the order line that command recorded,
     * the figures shown then) next to the unit's own figures now and what its
     * stock records hold; the item row of a product with current combinations
     * carries their totals, not the product's own figures, so none is shown for
     * a hold on the product itself then.
     *
     * @param int[] $itemIds The batch.
     * @param array $items Existing item rows of the batch by id.
     * @param array $combinations Combination rows of the batch in every state, by item id and id.
     * @param array $current Current combination rows of the batch, by item id and id.
     * @param array $rowsByItem Stock records of the batch by item id.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function holdFindings(array $itemIds, array $items, array $combinations, array $current, array $rowsByItem): array
    {
        $db = $this->db;
        $findings = [];

        foreach ($this->select(
            sql: 'SELECT h.' . $db->quoteName(name: 'id') . ', h.' . $db->quoteName(name: 'item_id') . ', h.' . $db->quoteName(name: 'combination_id')
            . ', h.' . $db->quoteName(name: 'stock_figure') . ', h.' . $db->quoteName(name: 'available_figure')
            . ', h.' . $db->quoteName(name: 'operation_id') . ', h.' . $db->quoteName(name: 'source_line_id')
            . ', h.' . $db->quoteName(name: 'created') . ', h.' . $db->quoteName(name: 'created_by')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_integrity_holds', as: 'h')
            . ' WHERE h.' . $db->quoteName(name: 'item_id') . ' IN (' . implode(',', array_map('intval', $itemIds)) . ')'
            . ' AND h.' . $db->quoteName(name: 'released_operation_id') . ' IS NULL'
            . ' ORDER BY h.' . $db->quoteName(name: 'item_id') . ', h.' . $db->quoteName(name: 'combination_id') . ', h.' . $db->quoteName(name: 'id'),
        ) as $hold) {
            $itemId = (int) $hold->item_id;
            $combinationId = (int) $hold->combination_id;
            $own = null;

            if ($combinationId > 0) {
                $own = $combinations[$itemId][$combinationId] ?? null;
            } elseif (isset($items[$itemId]) && ($current[$itemId] ?? []) === []) {
                $own = $items[$itemId];
            }

            $finding = $this->figurePairFinding(
                category: self::CATEGORY_INTEGRITY_HOLD,
                itemId: $itemId,
                combinationId: $combinationId,
                unit: $this->unitOf(combinationId: $combinationId),
                stock: $own === null ? null : $this->nullableFloat(value: $own->stock),
                available: $own === null ? null : $this->nullableFloat(value: $own->available_quantity),
                figures: $this->figures(rows: array_values(array_filter(
                    $rowsByItem[$itemId] ?? [],
                    static fn (object $row): bool => $row->combination_id === $combinationId,
                ))),
            );

            $finding['detail'] = [
                'hold_id' => (int) $hold->id,
                'created' => (string) $hold->created,
                'created_by' => (int) $hold->created_by,
                'operation_id' => (string) $hold->operation_id,
                'source_line_id' => $hold->source_line_id === null ? null : (int) $hold->source_line_id,
                'stock_figure' => $this->nullableFloat(value: $hold->stock_figure),
                'available_figure' => $this->nullableFloat(value: $hold->available_figure),
            ] + $finding['detail'];

            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * Findings of the order lines of a batch whose stock identity is not a
     * current stock unit.
     *
     * The lines read are those sold on a combination-bearing item itself, those
     * naming a combination that is not a row of their item, those of items that
     * no longer exist, and those of plain items that an effective recorded
     * resolution binds to the item itself. A line acts on the identity it was
     * sold as, or on the resolved identity of its effective recorded resolution
     * when the line still names that resolution's original or resolved
     * identity; its movements and open reservations are read on the identity it
     * acts on. Each line says whether it was placed as a stock claim, and carries
     * what was delivered from that identity for it and what came back there by a
     * physical return, by the definitions of OpenLineClaims: its outstanding
     * quantity is what was ordered less what was delivered, and its deducted net
     * (what is out now) is what was delivered less what came back.
     *
     * - unresolved_line: the identity the line acts on does not resolve, so the
     *   inventory service refuses its order's stock movements until an operator
     *   records a resolution;
     * - resolved_line (a classification): the line's effective resolution binds
     *   it to another stock identity of its product;
     * - historical_parent_line (a classification): the line acts on its item
     *   itself while the item has current combinations, or its effective
     *   resolution binds it to the item itself.
     *
     * A classified line also carries the stock identities a correction of its
     * resolution may name, by the rules the inventory service records one by: a
     * line whose sold-as combination is gone may name any combination of its
     * product or the product itself; a line sold on the product itself only the
     * product itself, and only while the product is kept for order history (it
     * is archived, or it still has current combinations); a line sold as a
     * combination that still exists, nothing.
     *
     * @param int[] $itemIds The batch.
     * @param array $items Existing item rows of the batch by id.
     * @param array $combinations Combination rows of the batch in every state, by item id and id.
     * @param array $current Current combination rows of the batch, by item id and id.
     * @param array $owners Combination id => owning item id already read.
     * @param array $rowsByItem Stock records of the batch by item id.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.0.0
     */
    private function lineFindings(array $itemIds, array $items, array $combinations, array $current, array $owners, array $rowsByItem): array
    {
        $db = $this->db;
        $bearing = [];
        $plain = [];
        $gone = [];

        foreach ($itemIds as $itemId) {
            if (!isset($items[$itemId])) {
                $gone[] = $itemId;
            } elseif (($current[$itemId] ?? []) !== []) {
                $bearing[] = $itemId;
            } else {
                $plain[] = $itemId;
            }
        }

        $existing = array_merge($bearing, $plain);
        $scopes = [];

        if ($bearing !== []) {
            // Sold on a combination-bearing item itself.
            $scopes[] = 'oi.' . $db->quoteName(name: 'id_item') . ' IN (' . implode(',', $bearing) . ')'
                . ' AND COALESCE(oi.' . $db->quoteName(name: 'id_combination') . ', 0) = 0';
        }

        if ($existing !== []) {
            // Naming a combination that is not a row of the line's item.
            $scopes[] = 'oi.' . $db->quoteName(name: 'id_item') . ' IN (' . implode(',', $existing) . ')'
                . ' AND oi.' . $db->quoteName(name: 'id_combination') . ' > 0'
                . ' AND c.' . $db->quoteName(name: 'id') . ' IS NULL';
        }

        if ($gone !== []) {
            // Of an item that no longer exists.
            $scopes[] = 'oi.' . $db->quoteName(name: 'id_item') . ' IN (' . implode(',', $gone) . ')';
        }

        if ($plain !== []) {
            // Bound to a plain item itself by an effective historical parent resolution.
            $scopes[] = 'oi.' . $db->quoteName(name: 'id') . ' IN (SELECT r.' . $db->quoteName(name: 'order_item_id')
                . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_line_resolutions', as: 'r')
                . ' WHERE r.' . $db->quoteName(name: 'resolved_item_id') . ' IN (' . implode(',', $plain) . ')'
                . ' AND r.' . $db->quoteName(name: 'resolved_combination_id') . ' = 0'
                . ' AND r.' . $db->quoteName(name: 'action') . ' = ' . $db->quote(text: InventoryService::RESOLVE_HISTORICAL_PARENT)
                . ' AND ' . InventoryService::effectiveResolutionSql(db: $db, alias: 'r') . ')';
        }

        $lines = [];

        foreach ($scopes as $scope) {
            foreach ($this->orderLineRows(condition: $scope) as $line) {
                $lines[$line->id] = $line;
            }
        }

        if ($lines === []) {
            return [];
        }

        ksort($lines);
        $lineList = implode(',', array_keys($lines));
        $resolutions = $this->lineResolutions(lineList: $lineList);

        foreach ($lines as $line) {
            $resolution = $resolutions[$line->id] ?? null;
            $applies = $resolution !== null
                && (($resolution->original_item_id === $line->item_id && $resolution->original_combination_id === $line->combination_id)
                    || ($resolution->resolved_item_id === $line->item_id && $resolution->resolved_combination_id === $line->combination_id));

            $line->resolution = $resolution;
            $line->applies = $applies;
            $line->acting_item = $applies ? $resolution->resolved_item_id : $line->item_id;
            $line->acting_combination = $applies ? $resolution->resolved_combination_id : $line->combination_id;
        }

        // A resolution binds a line to an identity of its own item; one that
        // names another item is followed all the same, with that item's facts.
        $outside = $this->outsideItems(itemIds: array_values(array_diff(
            array_unique(array_map(static fn (object $line): int => $line->acting_item, $lines)),
            $itemIds,
        )));
        $items += $outside['items'];
        $combinations += $outside['combinations'];

        foreach ($outside['combinations'] as $itemId => $rows) {
            foreach ($rows as $combinationId => $row) {
                $owners[$combinationId] = $itemId;

                if (CombinationState::isCurrent(state: $row->state)) {
                    $current[$itemId][$combinationId] = $row;
                }
            }
        }

        $owners += $this->combinationOwners(combinationIds: array_values(array_unique(array_filter(
            array_map(static fn (object $line): int => $line->acting_combination, $lines),
            static fn (int $combinationId): bool => $combinationId > 0 && !isset($owners[$combinationId]),
        ))));

        // Per line and identity: what its movements delivered from the identity
        // and what came back there by a physical return (OpenLineClaims).
        $moved = [];

        foreach ($this->select(
            sql: 'SELECT m.' . $db->quoteName(name: 'source_line_id') . ', m.' . $db->quoteName(name: 'item_id') . ', m.' . $db->quoteName(name: 'combination_id')
            . ', SUM(' . OpenLineClaims::deliveredTermSql(db: $db, movementAlias: 'm') . ') AS ' . $db->quoteName(name: 'delivered')
            . ', SUM(' . OpenLineClaims::returnedTermSql(db: $db, movementAlias: 'm') . ') AS ' . $db->quoteName(name: 'returned')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_movements', as: 'm')
            . ' WHERE m.' . $db->quoteName(name: 'source_line_id') . ' IN (' . $lineList . ')'
            . ' AND m.' . $db->quoteName(name: 'movement_type') . ' IN (' . $db->quote(text: InventoryService::MOVE_DEDUCT) . ', ' . $db->quote(text: InventoryService::MOVE_RESTORE) . ')'
            . ' GROUP BY m.' . $db->quoteName(name: 'source_line_id') . ', m.' . $db->quoteName(name: 'item_id') . ', m.' . $db->quoteName(name: 'combination_id'),
        ) as $row) {
            $moved[(int) $row->source_line_id . '|' . (int) $row->item_id . '|' . (int) $row->combination_id] = [
                'delivered' => (float) $row->delivered,
                'returned' => (float) $row->returned,
            ];
        }

        $holds = [];

        foreach ($this->select(
            sql: 'SELECT r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id')
            . ', COUNT(*) AS ' . $db->quoteName(name: 'reservations')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_reservations', as: 'r')
            . ' WHERE r.' . $db->quoteName(name: 'state') . ' = ' . $db->quote(text: InventoryService::STATE_ACTIVE)
            . ' AND r.' . $db->quoteName(name: 'source_line_id') . ' IN (' . $lineList . ')'
            . ' GROUP BY r.' . $db->quoteName(name: 'source_line_id') . ', r.' . $db->quoteName(name: 'item_id') . ', r.' . $db->quoteName(name: 'combination_id'),
        ) as $row) {
            $holds[(int) $row->source_line_id . '|' . (int) $row->item_id . '|' . (int) $row->combination_id] = (int) $row->reservations;
        }

        // The item's own records: on hand, and unreserved at enabled locations.
        $parentFigures = static function (int $itemId) use ($rowsByItem, $outside): array {
            if (isset($outside['parent'][$itemId])) {
                return $outside['parent'][$itemId];
            }

            $figures = ['on_hand' => 0.0, 'salable' => 0.0];

            foreach ($rowsByItem[$itemId] ?? [] as $row) {
                if ($row->combination_id === 0) {
                    $figures['on_hand'] += $row->quantity;
                    $figures['salable'] += $row->enabled ? $row->quantity - $row->reserved : 0.0;
                }
            }

            return $figures;
        };

        // What a correction of a classified line's resolution may name, by the
        // rules a resolution is recorded by: while the identity the line was sold
        // as is gone, any combination of its product and the product itself; a
        // line sold on the product itself only the product itself, and only while
        // the product is kept for order history (archived, or with current
        // combinations); a line sold as an existing combination nothing.
        $correctionTargets = function (object $line) use ($items, $combinations, $current, $owners): array {
            $soldAs = $this->identityProblem(
                itemExists: isset($items[$line->item_id]),
                combinations: $combinations[$line->item_id] ?? [],
                owners: $owners,
                combinationId: $line->combination_id,
            );

            if ($soldAs === null) {
                $kept = $line->combination_id === 0
                    && (!CombinationState::isCurrent(state: $items[$line->item_id]->state ?? null) || ($current[$line->item_id] ?? []) !== []);

                return ['candidates' => [], 'parent' => $kept];
            }

            return [
                'candidates' => $this->candidates(combinations: $combinations[$line->item_id] ?? []),
                'parent' => isset($items[$line->item_id]),
            ];
        };

        $findings = [];
        $unresolved = [];

        foreach ($lines as $line) {
            $acting = $line->id . '|' . $line->acting_item . '|' . $line->acting_combination;
            $moves = $moved[$acting] ?? ['delivered' => 0.0, 'returned' => 0.0];
            $facts = [
                'order_id' => $line->order_id,
                'line_id' => $line->id,
                'order_open' => $line->order_open,
                'ordered' => $line->quantity,
                'deducted_net' => $moves['delivered'] - $moves['returned'],
                'delivered' => $moves['delivered'],
                'returned' => $moves['returned'],
                'active_reservations' => $holds[$acting] ?? 0,
                'outstanding' => max(0.0, $line->quantity - $moves['delivered']),
                'placed_tracked' => $line->placed_tracked,
            ];
            $problem = $this->identityProblem(
                itemExists: isset($items[$line->acting_item]),
                combinations: $combinations[$line->acting_item] ?? [],
                owners: $owners,
                combinationId: $line->acting_combination,
            );

            if ($problem !== null) {
                $unresolved[] = ['line' => $line, 'facts' => $facts, 'reason' => $problem];

                continue;
            }

            if ($line->applies && $line->resolution->action === InventoryService::RESOLVE_BIND_UNIT) {
                $findings[] = $this->lineFinding(
                    category: self::CLASSIFICATION_RESOLVED_LINE,
                    line: $line,
                    detail: $facts + $correctionTargets(line: $line) + ['recorded' => $this->recorded(resolution: $line->resolution)],
                );

                continue;
            }

            if ($line->acting_combination !== 0) {
                continue;
            }

            $boundToParent = $line->applies && $line->resolution->action === InventoryService::RESOLVE_HISTORICAL_PARENT;

            if (($current[$line->acting_item] ?? []) === [] && !$boundToParent) {
                continue;
            }

            $parent = $parentFigures(itemId: $line->acting_item);
            $findings[] = $this->lineFinding(
                category: self::CLASSIFICATION_HISTORICAL_PARENT_LINE,
                line: $line,
                detail: $facts + [
                    'parent_on_hand' => $parent['on_hand'],
                    'parent_salable' => $parent['salable'],
                ] + $correctionTargets(line: $line) + ['recorded' => $this->recorded(resolution: $line->resolution)],
            );
        }

        if ($unresolved !== []) {
            $refused = $this->refusedEffects(orderIds: array_values(array_unique(array_map(static fn (array $entry): int => $entry['line']->order_id, $unresolved))));

            foreach ($unresolved as $entry) {
                $line = $entry['line'];
                $itemExists = isset($items[$line->acting_item]);

                $findings[] = $this->lineFinding(
                    category: self::CATEGORY_UNRESOLVED_LINE,
                    line: $line,
                    detail: $entry['facts'] + [
                        'refused_effects' => $refused[$line->order_id] ?? 0,
                        'reason' => $entry['reason'],
                        'candidates' => $itemExists ? $this->candidates(combinations: $combinations[$line->acting_item] ?? []) : [],
                        'parent' => $itemExists,
                        'recorded' => $this->recorded(resolution: $line->resolution),
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * The combinations of a product a line's resolution may name: every row in
     * any state, archived ones marked.
     *
     * @param array $combinations The product's combination rows by id, in every state.
     *
     * @return list<array{combination_id: int, archived: bool, sku: string}>
     *
     * @since  2.0.0
     */
    private function candidates(array $combinations): array
    {
        $candidates = [];

        foreach ($combinations as $combinationId => $combination) {
            $candidates[] = [
                'combination_id' => (int) $combinationId,
                'archived' => !CombinationState::isCurrent(state: $combination->state),
                'sku' => (string) $combination->sku,
            ];
        }

        return $candidates;
    }

    /**
     * Order lines matching a condition, with their order's open state and
     * whether each was placed as a stock claim (OpenLineClaims). The statement
     * aliases the line oi, the line's own combination row c (joined on id and
     * item), the order o and its status st.
     *
     * @param string $condition The WHERE condition.
     *
     * @return list<object> Lines {id, order_id, item_id, combination_id (0 for the item itself), quantity, order_open,
     *                      placed_tracked}.
     *
     * @since  2.0.0
     */
    private function orderLineRows(string $condition): array
    {
        $db = $this->db;

        $rows = $this->select(
            sql: 'SELECT oi.' . $db->quoteName(name: 'id') . ', oi.' . $db->quoteName(name: 'id_order') . ' AS ' . $db->quoteName(name: 'order_id')
            . ', oi.' . $db->quoteName(name: 'id_item') . ' AS ' . $db->quoteName(name: 'item_id')
            . ', COALESCE(oi.' . $db->quoteName(name: 'id_combination') . ', 0) AS ' . $db->quoteName(name: 'combination_id')
            . ', oi.' . $db->quoteName(name: 'quantity')
            . ', CASE WHEN o.' . $db->quoteName(name: 'id') . ' IS NOT NULL AND ' . OpenLineClaims::openOrderSql(db: $db, orderAlias: 'o', statusAlias: 'st')
            . ' THEN 1 ELSE 0 END AS ' . $db->quoteName(name: 'order_open')
            . ', CASE WHEN ' . OpenLineClaims::placedTrackedSql(db: $db, lineAlias: 'oi') . ' THEN 1 ELSE 0 END AS ' . $db->quoteName(name: 'placed_tracked')
            . ' FROM ' . $db->quoteName(name: '#__alfa_order_items', as: 'oi')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_item_combinations', as: 'c')
            . ' ON c.' . $db->quoteName(name: 'id') . ' = oi.' . $db->quoteName(name: 'id_combination')
            . ' AND c.' . $db->quoteName(name: 'item_id') . ' = oi.' . $db->quoteName(name: 'id_item')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_orders', as: 'o') . ' ON o.' . $db->quoteName(name: 'id') . ' = oi.' . $db->quoteName(name: 'id_order')
            . ' LEFT JOIN ' . $db->quoteName(name: '#__alfa_orders_statuses', as: 'st') . ' ON st.' . $db->quoteName(name: 'id') . ' = o.' . $db->quoteName(name: 'id_order_status')
            . ' WHERE ' . $condition,
        );

        foreach ($rows as $row) {
            $row->id = (int) $row->id;
            $row->order_id = (int) $row->order_id;
            $row->item_id = (int) $row->item_id;
            $row->combination_id = (int) $row->combination_id;
            $row->quantity = (float) $row->quantity;
            $row->order_open = (int) $row->order_open === 1;
            $row->placed_tracked = (int) $row->placed_tracked === 1;
        }

        return $rows;
    }

    /**
     * The effective recorded resolution of each order line: the one no other
     * resolution supersedes. A superseded resolution is history only; it is
     * listed with the effective one and decides nothing.
     *
     * @param string $lineList Comma-separated order line ids.
     *
     * @return array<int, object> Order line id => effective resolution {id, revision, action, original and
     *                            resolved item and combination ids, reason, created, created_by, consumed
     *                            (stock has moved for the line on its resolved identity since it was recorded),
     *                            superseded (the resolutions it replaced, earliest first)}.
     *
     * @since  2.0.0
     */
    private function lineResolutions(string $lineList): array
    {
        $db = $this->db;
        $effective = [];
        $superseded = [];
        $isEffective = InventoryService::effectiveResolutionSql(db: $db, alias: 'r');

        foreach ($this->select(
            sql: 'SELECT r.' . $db->quoteName(name: 'id') . ', r.' . $db->quoteName(name: 'order_item_id') . ', r.' . $db->quoteName(name: 'revision')
            . ', r.' . $db->quoteName(name: 'action')
            . ', r.' . $db->quoteName(name: 'original_item_id') . ', r.' . $db->quoteName(name: 'original_combination_id')
            . ', r.' . $db->quoteName(name: 'resolved_item_id') . ', r.' . $db->quoteName(name: 'resolved_combination_id')
            . ', r.' . $db->quoteName(name: 'reason') . ', r.' . $db->quoteName(name: 'created') . ', r.' . $db->quoteName(name: 'created_by')
            . ', CASE WHEN ' . $isEffective . ' THEN 1 ELSE 0 END AS ' . $db->quoteName(name: 'effective')
            . ', CASE WHEN ' . $isEffective . ' AND ' . InventoryService::resolutionConsumedSql(db: $db, resolutionAlias: 'r')
            . ' THEN 1 ELSE 0 END AS ' . $db->quoteName(name: 'consumed')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_line_resolutions', as: 'r')
            . ' WHERE r.' . $db->quoteName(name: 'order_item_id') . ' IN (' . $lineList . ')'
            . ' ORDER BY r.' . $db->quoteName(name: 'order_item_id') . ', r.' . $db->quoteName(name: 'revision') . ', r.' . $db->quoteName(name: 'id'),
        ) as $row) {
            $lineId = (int) $row->order_item_id;

            $row->id = (int) $row->id;
            $row->revision = (int) $row->revision;
            $row->original_item_id = (int) $row->original_item_id;
            $row->original_combination_id = (int) $row->original_combination_id;
            $row->resolved_item_id = (int) $row->resolved_item_id;
            $row->resolved_combination_id = (int) $row->resolved_combination_id;
            $row->created_by = (int) $row->created_by;
            $row->consumed = (int) $row->consumed === 1;

            if ((int) $row->effective === 1) {
                $effective[$lineId] = $row;

                continue;
            }

            $superseded[$lineId][] = [
                'id' => $row->id,
                'action' => (string) $row->action,
                'resolved_combination_id' => $row->resolved_combination_id,
                'reason' => (string) $row->reason,
                'created' => (string) $row->created,
                'created_by' => $row->created_by,
            ];
        }

        foreach ($effective as $lineId => $row) {
            $row->superseded = $superseded[$lineId] ?? [];
        }

        return $effective;
    }

    /**
     * An effective recorded resolution as a finding shows it, with the
     * resolutions it superseded and whether stock has since moved for the line
     * on its resolved identity (a consumed resolution can no longer be corrected).
     *
     * @param object|null $resolution The line's effective resolution.
     *
     * @return array{resolution_id: int, action: string, created: string, created_by: int, reason: string, revision: int, resolved_combination_id: int, consumed: bool, superseded: list<array<string, mixed>>}|null
     *
     * @since  2.0.0
     */
    private function recorded(?object $resolution): ?array
    {
        if ($resolution === null) {
            return null;
        }

        return [
            'resolution_id' => $resolution->id,
            'action' => (string) $resolution->action,
            'created' => (string) $resolution->created,
            'created_by' => $resolution->created_by,
            'reason' => (string) $resolution->reason,
            'revision' => $resolution->revision,
            'resolved_combination_id' => $resolution->resolved_combination_id,
            'consumed' => $resolution->consumed,
            'superseded' => $resolution->superseded,
        ];
    }

    /**
     * Stock-effect obligations the inventory service refused, per order.
     *
     * @param int[] $orderIds The orders.
     *
     * @return array<int, int> Order id => number of refused obligations.
     *
     * @since  2.0.0
     */
    private function refusedEffects(array $orderIds): array
    {
        $db = $this->db;
        $refused = [];

        foreach ($this->select(
            sql: 'SELECT o.' . $db->quoteName(name: 'order_id') . ', COUNT(*) AS ' . $db->quoteName(name: 'refused')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_effect_outbox', as: 'o')
            . ' WHERE o.' . $db->quoteName(name: 'status') . ' = ' . $db->quote(text: InventoryEffectOutbox::STATUS_REFUSED)
            . ' AND o.' . $db->quoteName(name: 'order_id') . ' IN (' . implode(',', array_map('intval', $orderIds)) . ')'
            . ' GROUP BY o.' . $db->quoteName(name: 'order_id'),
        ) as $row) {
            $refused[(int) $row->order_id] = (int) $row->refused;
        }

        return $refused;
    }

    /**
     * The facts of items outside the batch that a recorded resolution binds an
     * order line to: the rows that exist with their catalogue state, their
     * combination rows in every state, and the figures of the stock records held
     * on each item itself.
     *
     * @param int[] $itemIds Item ids outside the batch.
     *
     * @return array{items: array<int, object>, combinations: array<int, array<int, object>>, parent: array<int, array{on_hand: float, salable: float}>}
     *
     * @since  2.0.0
     */
    private function outsideItems(array $itemIds): array
    {
        $facts = ['items' => [], 'combinations' => [], 'parent' => []];
        $ids = $this->normaliseIds(itemIds: $itemIds);

        if ($ids === []) {
            return $facts;
        }

        $db = $this->db;
        $in = implode(',', $ids);

        foreach ($this->select(
            sql: 'SELECT i.' . $db->quoteName(name: 'id') . ', i.' . $db->quoteName(name: 'state')
            . ' FROM ' . $db->quoteName(name: '#__alfa_items', as: 'i')
            . ' WHERE i.' . $db->quoteName(name: 'id') . ' IN (' . $in . ')',
        ) as $row) {
            $facts['items'][(int) $row->id] = $row;
        }

        foreach ($this->select(
            sql: 'SELECT c.' . $db->quoteName(name: 'id') . ', c.' . $db->quoteName(name: 'item_id') . ', c.' . $db->quoteName(name: 'state') . ', c.' . $db->quoteName(name: 'sku')
            . ' FROM ' . $db->quoteName(name: '#__alfa_item_combinations', as: 'c')
            . ' WHERE c.' . $db->quoteName(name: 'item_id') . ' IN (' . $in . ')'
            . ' ORDER BY c.' . $db->quoteName(name: 'item_id') . ', c.' . $db->quoteName(name: 'id'),
        ) as $row) {
            $facts['combinations'][(int) $row->item_id][(int) $row->id] = $row;
        }

        foreach ($this->select(
            sql: 'SELECT s.' . $db->quoteName(name: 'item_id')
            . ', SUM(s.' . $db->quoteName(name: 'quantity') . ') AS ' . $db->quoteName(name: 'on_hand')
            . ', SUM(CASE WHEN ' . LocationPolicy::enabledPredicate(db: $db, column: 's.' . $db->quoteName(name: 'location_id'))
            . ' THEN s.' . $db->quoteName(name: 'quantity') . ' - s.' . $db->quoteName(name: 'reserved_quantity') . ' ELSE 0 END) AS ' . $db->quoteName(name: 'salable')
            . ' FROM ' . $db->quoteName(name: '#__alfa_inventory_stock', as: 's')
            . ' WHERE s.' . $db->quoteName(name: 'item_id') . ' IN (' . $in . ')'
            . ' AND s.' . $db->quoteName(name: 'combination_id') . ' = 0'
            . ' GROUP BY s.' . $db->quoteName(name: 'item_id'),
        ) as $row) {
            $facts['parent'][(int) $row->item_id] = ['on_hand' => (float) $row->on_hand, 'salable' => (float) $row->salable];
        }

        foreach (array_keys($facts['items']) as $itemId) {
            $facts['parent'][$itemId] ??= ['on_hand' => 0.0, 'salable' => 0.0];
        }

        return $facts;
    }

    /**
     * Build one order-line finding: keyed by the identity the given line names
     * and its id; the figures are the quantity ordered against the quantity its
     * movements deducted less what they restored. The identity is the one the
     * line was sold as, except for a line that relies on a unit no longer
     * tracking stock, which is keyed by that unit, the identity it acts on.
     *
     * @param string $category The category or classification.
     * @param object $line The order line: {id, item_id, combination_id (0 for the item itself)}, the
     *                     identity being the one the finding is keyed by.
     * @param array $detail Its facts; ordered and deducted_net are required.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    private function lineFinding(string $category, object $line, array $detail): array
    {
        return [
            'category' => $category,
            'key' => implode('|', [$category, $line->item_id, $line->combination_id, 'L' . $line->id, self::UNIT_LINE]),
            'item_id' => $line->item_id,
            'combination_id' => $line->combination_id,
            'location_id' => null,
            'unit' => self::UNIT_LINE,
            'projection' => $detail['ordered'],
            'canonical' => $detail['deducted_net'],
            'drift' => $detail['ordered'] - $detail['deducted_net'],
            'detail' => $detail,
        ];
    }

    /**
     * A finding whose unit carries two figures (a missing ledger, an untracked
     * unit's figures): reported on the figure that drifts further, with both
     * pairs in the detail.
     *
     * @param string $category The category.
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item.
     * @param string $unit UNIT_ITEM or UNIT_COMBINATION.
     * @param float|null $stock Stored physical projection.
     * @param float|null $available Stored salable projection.
     * @param array $figures The unit's figures.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    private function figurePairFinding(string $category, int $itemId, int $combinationId, string $unit, ?float $stock, ?float $available, array $figures): array
    {
        $stockDrift = ($stock ?? 0.0) - $figures['physical'];
        $availableDrift = ($available ?? 0.0) - $figures['salable'];
        $onAvailable = abs($availableDrift) > abs($stockDrift);

        return $this->finding(
            category: $category,
            itemId: $itemId,
            combinationId: $combinationId,
            locationId: null,
            unit: $unit,
            projection: $onAvailable ? $available : $stock,
            canonical: $onAvailable ? $figures['salable'] : $figures['physical'],
            drift: $onAvailable ? $availableDrift : $stockDrift,
            detail: [
                'stock' => $stock,
                'physical' => $figures['physical'],
                'available' => $available,
                'salable' => $figures['salable'],
                'rows' => $figures['rows'],
            ],
        );
    }

    /**
     * Build one finding.
     *
     * @param string $category The category.
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item.
     * @param int|null $locationId The location, null for a unit-level finding.
     * @param string $unit UNIT_ITEM, UNIT_COMBINATION or UNIT_PARENT.
     * @param float|null $projection The stored figure.
     * @param float|null $canonical The authoritative figure.
     * @param float $drift The signed quantity at issue.
     * @param array $detail Category-specific facts.
     *
     * @return array<string, mixed>
     *
     * @since  2.0.0
     */
    private function finding(
        string $category,
        int $itemId,
        int $combinationId,
        ?int $locationId,
        string $unit,
        ?float $projection,
        ?float $canonical,
        float $drift,
        array $detail,
    ): array {
        return [
            'category' => $category,
            'key' => implode('|', [$category, $itemId, $combinationId, $locationId ?? '', $unit]),
            'item_id' => $itemId,
            'combination_id' => $combinationId,
            'location_id' => $locationId,
            'unit' => $unit,
            'projection' => $projection,
            'canonical' => $canonical,
            'drift' => $drift,
            'detail' => $detail,
        ];
    }

    /**
     * A unit's figures from its stock records.
     *
     * @param array $rows The unit's stock records.
     *
     * @return array{rows: int, physical: float, reserved: float, salable: float}
     *
     * @since  2.0.0
     */
    private function figures(array $rows): array
    {
        $figures = ['rows' => \count($rows), 'physical' => 0.0, 'reserved' => 0.0, 'salable' => 0.0];

        foreach ($rows as $row) {
            $figures['physical'] += $row->quantity;
            $figures['reserved'] += $row->reserved;

            if ($row->enabled) {
                $figures['salable'] += $row->quantity - $row->reserved;
            }
        }

        return $figures;
    }

    /**
     * The records that still hold a quantity or a reservation.
     *
     * @param array $rows Stock records.
     *
     * @return list<object>
     *
     * @since  2.0.0
     */
    private function heldRows(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (object $row): bool => abs($row->quantity) > InventoryAvailabilityService::QUANTITY_TOLERANCE
                || $row->reserved > InventoryAvailabilityService::QUANTITY_TOLERANCE,
        ));
    }

    /**
     * Whether a stored figure agrees with its authoritative figure; a figure
     * never written reads as 0, as the inventory service reads it.
     *
     * @param float|null $projection The stored figure.
     * @param float $canonical The authoritative figure.
     *
     *
     * @since  2.0.0
     */
    private function agree(?float $projection, float $canonical): bool
    {
        return InventoryAvailabilityService::quantitiesAgree(a: $projection ?? 0.0, b: $canonical);
    }

    /**
     * Effective stock tracking: a combination's own value, else the item's; NULL
     * at both levels is untracked.
     *
     * @param mixed $own The combination's manage_stock (null for an item unit or when inherited).
     * @param mixed $item The item's manage_stock.
     *
     *
     * @since  2.0.0
     */
    private function effectiveTracking(mixed $own, mixed $item): bool
    {
        return (int) ($own ?? $item ?? 0) === 1;
    }

    /**
     * The unit kind of an identity.
     *
     * @param int $combinationId The combination id, 0 for the item itself.
     *
     * @return string UNIT_ITEM or UNIT_COMBINATION.
     *
     * @since  2.0.0
     */
    private function unitOf(int $combinationId): string
    {
        return $combinationId > 0 ? self::UNIT_COMBINATION : self::UNIT_ITEM;
    }

    /**
     * The lookup key of an identity at a location.
     *
     * @param int $itemId The item.
     * @param int $combinationId The combination, 0 for the item.
     * @param int $locationId The location.
     *
     *
     * @since  2.0.0
     */
    private function identityKey(int $itemId, int $combinationId, int $locationId): string
    {
        return $itemId . '|' . $combinationId . '|' . $locationId;
    }

    /**
     * A nullable numeric column as a float.
     *
     * @param mixed $value The column value.
     *
     *
     * @since  2.0.0
     */
    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Run one SELECT and return its rows.
     *
     * @param string $sql The statement.
     *
     * @return list<object>
     *
     * @since  2.0.0
     */
    private function select(string $sql): array
    {
        return $this->db->setQuery(query: $sql)->loadObjectList() ?: [];
    }
}
