<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Cart\CartQuantityPolicy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * The read side of inventory: every stock question is answered from the ledger.
 *
 * `#__alfa_inventory_stock`, its movements and its reservations are the inventory
 * authority. The `stock` and `available_quantity` columns of items and combinations are
 * projections the inventory kernel maintains: display and sort figures, and the input
 * of the missing-ledger predicate. They never answer "can this be sold".
 *
 * Sellability is ONE rule in two forms over the same ledger facts:
 * {@see self::statusFrom()} / {@see self::isSellable()} / {@see self::ceilingFrom()}
 * in PHP, {@see self::sellableSql()} in SQL. A unit held until its stock figures are
 * repaired never sells, and neither does a unit whose quantity rules accept no
 * quantity at all. A unit that tracks stock sells while its smallest sale is on the
 * shelf above its safety stock when backorders are denied, and always when they are
 * allowed; a tracked unit with no stock records but non-zero projections is an
 * integrity defect and fails closed under every backorder mode.
 *
 * The quantity rules are exact decimals and a cart line holds whole units, so the
 * smallest sale is an exact whole number that no tolerance ever touches. The stock
 * side is counted in whole units ({@see self::wholeUnits()}), where a figure within the
 * stock tolerance of a whole number holds that unit; that comparison is the only place
 * the tolerance meets a quantity rule.
 *
 * @since  2.0.0
 */
final class InventoryAvailabilityService
{
    public const STATUS_IN_STOCK = 'in_stock';
    public const STATUS_BACKORDER = 'backorder';
    public const STATUS_OUT_OF_STOCK = 'out_of_stock';

    /**
     * A stock-managed unit whose projections show stock while it has no stock
     * records, or a unit held until its stock figures are repaired. Back office
     * and kernel only; storefront surfaces map it to out of stock.
     *
     * @var string
     * @since  2.0.0
     */
    public const STATUS_LEDGER_MISSING = 'ledger_missing';

    /**
     * The quantity below which two stock figures are the same figure. Stock columns
     * are single-precision FLOATs, so a value read back can differ from the value
     * written in the seventh significant digit; every projection-versus-ledger
     * comparison and every "non-zero" test uses this tolerance.
     *
     * @var float
     * @since  2.0.0
     */
    public const QUANTITY_TOLERANCE = 0.00005;

    private DatabaseInterface $db;

    /**
     * @since  2.0.0
     */
    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * Ledger figures of one unit, overall or at one location.
     *
     * Physical and salable are two different questions. `physical`, `reserved` and
     * `available` answer "what is on the shelves" and count every location, disabled
     * ones included: goods do not stop existing because a location was disabled, and
     * a disabled location stays visible in the back office. `salable` answers "what
     * may the shop sell" and counts only enabled locations. `rows` is the number of
     * stock records behind the figures, which is what tells a never-stocked unit from
     * a unit whose records are missing.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null.
     * @param int|null $locationId One location; null = all locations.
     * @param InventoryContext|null $context Reserved dimension seam.
     *
     * @return array{rows: int, physical: float, reserved: float, available: float, salable: float}
     *
     * @since  2.0.0
     */
    public function availability(
        int $itemId,
        ?int $combinationId = null,
        ?int $locationId = null,
        ?InventoryContext $context = null,
    ): array {
        $query = $this->db->getQuery(true)
            ->select(
                'COUNT(*) AS ' . $this->db->quoteName('stock_rows')
                . ', COALESCE(SUM(quantity), 0) AS physical, COALESCE(SUM(reserved_quantity), 0) AS reserved',
            )
            ->from($this->db->quoteName('#__alfa_inventory_stock'))
            ->where($this->db->quoteName('item_id') . ' = ' . $itemId)
            ->where($this->db->quoteName('combination_id') . ' = ' . (int) ($combinationId ?? 0));

        if ($locationId !== null) {
            $query->where($this->db->quoteName('location_id') . ' = ' . $locationId);
        }

        $sums = $this->db->setQuery($query)->loadObject();
        $physical = (float) ($sums->physical ?? 0);
        $reserved = (float) ($sums->reserved ?? 0);
        $available = $physical - $reserved;

        // The salable half re-asks the same question through the location state,
        // on top of whatever the caller already narrowed to, so pinning a disabled
        // location answers `physical: 12, salable: 0`: the goods are there and the
        // shop may not sell them.
        $salableQuery = clone $query;
        $salableQuery->clear('select')
            ->select('COALESCE(SUM(quantity - reserved_quantity), 0) AS salable')
            ->where(LocationPolicy::enabledPredicate(db: $this->db, column: $this->db->quoteName('location_id')));
        $salable = (float) $this->db->setQuery($salableQuery)->loadResult();

        return [
            'rows' => (int) ($sums->stock_rows ?? 0),
            'physical' => $physical,
            'reserved' => $reserved,
            'available' => $available,
            'salable' => $salable,
        ];
    }

    /**
     * Ledger facts of every stock unit of a batch of products, in one ledger read and one hold read.
     *
     * One ranged read on the (item_id, combination_id) prefix of the unit key, grouped
     * per unit, and one ranged read of the open integrity holds of the same products.
     * Units that have stock records appear, and so does every held unit: an absent unit
     * has no rows and no open hold, which is the never-stocked state or, with non-zero
     * projections, the missing-ledger defect. `held` is true while the unit has an open
     * integrity hold, which fails it closed whatever its records hold. The caller decides
     * which identities are units (the item level of a product with current combinations
     * is not one, and neither is an archived combination).
     *
     * @param int[] $itemIds The products.
     *
     * @return array<int, array<int, array{rows: int, physical: float, reserved: float, salable: float, held: bool}>>
     *                                                                                                                item id => combination id (0 for the item level) => facts; salable counts
     *                                                                                                                enabled locations only.
     *
     * @since  2.0.0
     */
    public static function unitFactsForItems(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('ls.item_id'),
                $db->quoteName('ls.combination_id'),
                'COUNT(*) AS ' . $db->quoteName('stock_rows'),
                'SUM(' . $db->quoteName('ls.quantity') . ') AS ' . $db->quoteName('physical'),
                'SUM(' . $db->quoteName('ls.reserved_quantity') . ') AS ' . $db->quoteName('reserved'),
                'SUM(CASE WHEN ' . LocationPolicy::enabledPredicate(db: $db, column: $db->quoteName('ls.location_id'))
                    . ' THEN ' . $db->quoteName('ls.quantity') . ' - ' . $db->quoteName('ls.reserved_quantity')
                    . ' ELSE 0 END) AS ' . $db->quoteName('salable'),
            ])
            ->from($db->quoteName('#__alfa_inventory_stock', 'ls'))
            ->whereIn($db->quoteName('ls.item_id'), $ids)
            ->group([$db->quoteName('ls.item_id'), $db->quoteName('ls.combination_id')]);

        $facts = [];

        foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $facts[(int) $row->item_id][(int) $row->combination_id] = [
                'rows' => (int) $row->stock_rows,
                'physical' => (float) $row->physical,
                'reserved' => (float) $row->reserved,
                'salable' => (float) $row->salable,
                'held' => false,
            ];
        }

        // Open holds only: a released hold is history and decides nothing.
        $holds = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('ih.item_id') . ', ' . $db->quoteName('ih.combination_id'))
            ->from($db->quoteName('#__alfa_inventory_integrity_holds', 'ih'))
            ->whereIn($db->quoteName('ih.item_id'), $ids)
            ->where($db->quoteName('ih.released_operation_id') . ' IS NULL');

        foreach ($db->setQuery($holds)->loadObjectList() ?: [] as $row) {
            $unit = &$facts[(int) $row->item_id][(int) $row->combination_id];
            $unit ??= ['rows' => 0, 'physical' => 0.0, 'reserved' => 0.0, 'salable' => 0.0];
            $unit['held'] = true;
            unset($unit);
        }

        return $facts;
    }

    /**
     * Ledger figures of a product with combinations, read over its current combination units.
     *
     * `physical` and `reserved` sum every current combination of the product, whatever
     * its publication or tracking: that stock physically exists. `salable` sums only the
     * combinations that count for the product's availability, the published ones that
     * track stock, at enabled locations. Rows on the product itself (combination 0) are
     * not a unit once combinations exist: they are reported separately as stranded stock
     * and are never part of the aggregate. Rows naming a combination that is archived,
     * gone or belongs to another product are in neither: an archived combination's
     * records are the stock identity of order history, never a figure of the product.
     *
     * @param int $itemId The product.
     *
     * @return array{physical: float, reserved: float, salable: float, item_level_physical: float, item_level_reserved: float, item_level_rows_not_zero: int}
     *
     * @since  2.0.0
     */
    public static function combinationAggregate(int $itemId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $tolerance = self::sqlQuantity(value: self::QUANTITY_TOLERANCE);
        $isUnit = $db->quoteName('c.id') . ' IS NOT NULL AND ' . CombinationState::currentSql(column: $db->quoteName('c.state'));
        $isParent = $db->quoteName('s.combination_id') . ' = 0';
        $counts = $isUnit . ' AND ' . $db->quoteName('c.state') . ' = 1'
            . ' AND COALESCE(' . $db->quoteName('c.manage_stock') . ', ' . $db->quoteName('i.manage_stock') . ', 0) = 1'
            . ' AND ' . LocationPolicy::enabledPredicate(db: $db, column: $db->quoteName('s.location_id'));

        $query = $db->getQuery(true)
            ->select([
                'COALESCE(SUM(CASE WHEN ' . $isUnit . ' THEN ' . $db->quoteName('s.quantity') . ' ELSE 0 END), 0) AS '
                    . $db->quoteName('physical'),
                'COALESCE(SUM(CASE WHEN ' . $isUnit . ' THEN ' . $db->quoteName('s.reserved_quantity') . ' ELSE 0 END), 0) AS '
                    . $db->quoteName('reserved'),
                'COALESCE(SUM(CASE WHEN ' . $counts . ' THEN ' . $db->quoteName('s.quantity') . ' - '
                    . $db->quoteName('s.reserved_quantity') . ' ELSE 0 END), 0) AS ' . $db->quoteName('salable'),
                'COALESCE(SUM(CASE WHEN ' . $isParent . ' THEN ' . $db->quoteName('s.quantity') . ' ELSE 0 END), 0) AS '
                    . $db->quoteName('item_level_physical'),
                'COALESCE(SUM(CASE WHEN ' . $isParent . ' THEN ' . $db->quoteName('s.reserved_quantity') . ' ELSE 0 END), 0) AS '
                    . $db->quoteName('item_level_reserved'),
                'COALESCE(SUM(CASE WHEN ' . $isParent . ' AND (ABS(' . $db->quoteName('s.quantity') . ') > ' . $tolerance
                    . ' OR ' . $db->quoteName('s.reserved_quantity') . ' > ' . $tolerance . ') THEN 1 ELSE 0 END), 0) AS '
                    . $db->quoteName('item_level_rows_not_zero'),
            ])
            ->from($db->quoteName('#__alfa_inventory_stock', 's'))
            ->join('INNER', $db->quoteName('#__alfa_items', 'i'), $db->quoteName('i.id') . ' = ' . $db->quoteName('s.item_id'))
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_item_combinations', 'c'),
                $db->quoteName('c.id') . ' = ' . $db->quoteName('s.combination_id')
                . ' AND ' . $db->quoteName('c.item_id') . ' = ' . $db->quoteName('s.item_id'),
            )
            ->where($db->quoteName('s.item_id') . ' = ' . $itemId);

        $row = $db->setQuery($query)->loadObject();

        return [
            'physical' => (float) ($row->physical ?? 0),
            'reserved' => (float) ($row->reserved ?? 0),
            'salable' => (float) ($row->salable ?? 0),
            'item_level_physical' => (float) ($row->item_level_physical ?? 0),
            'item_level_reserved' => (float) ($row->item_level_reserved ?? 0),
            'item_level_rows_not_zero' => (int) ($row->item_level_rows_not_zero ?? 0),
        ];
    }

    /**
     * Per-location availability breakdown of one unit.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null.
     *
     * @return array<int, array{location_id: int, physical: float, reserved: float, available: float}>
     *
     * @since  2.0.0
     */
    public function byLocation(int $itemId, ?int $combinationId = null): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['location_id', 'quantity', 'reserved_quantity']))
            ->from($this->db->quoteName('#__alfa_inventory_stock'))
            ->where($this->db->quoteName('item_id') . ' = ' . $itemId)
            ->where($this->db->quoteName('combination_id') . ' = ' . (int) ($combinationId ?? 0))
            ->order($this->db->quoteName('location_id'));

        $rows = [];

        foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $rows[] = [
                'location_id' => (int) $row->location_id,
                'physical' => (float) $row->quantity,
                'reserved' => (float) $row->reserved_quantity,
                'available' => (float) $row->quantity - (float) $row->reserved_quantity,
            ];
        }

        return $rows;
    }

    /**
     * Every place a unit's stock may be corrected at, with the location's name
     * and its current figures: the data a per-location editor needs to render
     * one input per location without asking a query per row.
     *
     * Published locations are always listed, because a merchant must be able to
     * put stock somewhere new. An UNPUBLISHED location is listed too when it
     * still holds a quantity: hiding it would strand that stock, uneditable and
     * invisible, while it keeps counting towards the product's total.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination, or null for the item unit.
     *
     * @return array<int, array{location_id: int, title: string, published: bool, is_default: bool, physical: float, reserved: float, available: float}>
     *
     * @since  2.0.0
     */
    public function locationBreakdown(int $itemId, ?int $combinationId = null): array
    {
        $held = [];

        foreach ($this->byLocation(itemId: $itemId, combinationId: $combinationId) as $row) {
            $held[(int) $row['location_id']] = $row;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'title', 'state', 'is_default']))
            ->from($this->db->quoteName('#__alfa_inventory_locations'))
            ->order($this->db->quoteName('is_default') . ' DESC, ' . $this->db->quoteName('title') . ' ASC');

        $out = [];

        foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $location) {
            $id = (int) $location->id;
            $published = (int) $location->state === 1;
            $row = $held[$id] ?? null;

            if (!$published && ($row === null || abs((float) $row['physical']) <= 0.000001)) {
                continue;
            }

            $out[] = [
                'location_id' => $id,
                'title' => (string) $location->title,
                'published' => $published,
                'is_default' => (int) $location->is_default === 1,
                'physical' => (float) ($row['physical'] ?? 0),
                'reserved' => (float) ($row['reserved'] ?? 0),
                'available' => (float) ($row['available'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * A quantity as the back office prints it: rounded to the kernel's own
     * working precision and stripped of trailing zeros, so a figure rendered
     * on page load and the same figure returned by a write are byte-identical
     * (a raw float cast leaks single-precision noise like "12.300000190734863").
     *
     * @param float $value The quantity.
     *
     * @return string Display string; never empty.
     *
     * @since  2.0.0
     */
    public static function formatQuantity(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /**
     * Whether two stock quantities are the same quantity.
     *
     * Absolute tolerance for small figures, relative (one part per million) for large
     * ones, because a single-precision column keeps about seven significant digits.
     * Every projection-versus-ledger comparison goes through this.
     *
     * @param float $a One quantity.
     * @param float $b The other.
     *
     *
     * @since  2.0.0
     */
    public static function quantitiesAgree(float $a, float $b): bool
    {
        return abs($a - $b) <= max(self::QUANTITY_TOLERANCE, 1e-6 * max(abs($a), abs($b)));
    }

    /**
     * Whether a unit's projections show any stock, in either sign.
     *
     * The missing-ledger predicate's projection half: a stock-managed unit with no stock
     * records and a non-zero projection is an integrity defect, whatever the sign. NULL
     * is no figure at all.
     *
     * @param float|null $stock The physical projection.
     * @param float|null $available The salable projection.
     *
     *
     * @since  2.0.0
     */
    public static function projectionNonZero(?float $stock, ?float $available): bool
    {
        return abs($stock ?? 0.0) > self::QUANTITY_TOLERANCE || abs($available ?? 0.0) > self::QUANTITY_TOLERANCE;
    }

    /**
     * The whole units a unit can supply: its salable quantity above its safety stock,
     * counted in whole units.
     *
     * Stock figures are single-precision, so a figure within the stock tolerance of a
     * whole number holds that unit; a fractional remainder below it is no unit a
     * shopper can buy. A negative safety stock counts as none. This is the stock side
     * of the one comparison between stock and the quantity rules: the rules themselves
     * are exact and never see the tolerance. {@see self::wholeUnitsSql()} is the same
     * computation for a query, in the same operations and the same order.
     *
     * @param float $salable The ledger salable quantity at enabled locations.
     * @param float $safety The resolved safety stock; 0 when none is withheld.
     *
     * @return float A whole number; negative when the stock is below the safety stock or oversold.
     *
     * @since  2.0.0
     */
    public static function wholeUnits(float $salable, float $safety): float
    {
        return floor($salable - max(0.0, $safety) + self::QUANTITY_TOLERANCE);
    }

    /**
     * The SQL form of {@see self::wholeUnits()}.
     *
     * Double-precision arithmetic over the stock figures, as the PHP form computes it.
     * NULL when the salable quantity is NULL (a unit without stock records).
     *
     * @param string $salableExpr SQL yielding the ledger salable quantity.
     * @param string $safetyExpr SQL yielding the resolved safety stock.
     *
     * @return string A numeric SQL expression.
     *
     * @since  2.0.0
     */
    public static function wholeUnitsSql(string $salableExpr, string $safetyExpr): string
    {
        return 'FLOOR((' . $salableExpr . ') - GREATEST((' . $safetyExpr . '), 0) + '
            . self::sqlQuantity(value: self::QUANTITY_TOLERANCE) . ')';
    }

    /**
     * The stock verdict of one unit, from its ledger facts.
     *
     * The PHP form of the one sellability rule; {@see self::sellableSql()} is the same
     * rule for a query and the two must always agree. The checks run in this order:
     *
     *  - A unit held until its stock figures are repaired is the missing-ledger defect,
     *    whatever its records, tracking and backorder mode say: it fails closed.
     *  - A unit whose quantity rules accept no quantity (no smallest sale) is out of
     *    stock, tracked or not: the cart never accepts a quantity for it.
     *  - A unit that does not manage stock is in stock: nothing else gates it.
     *  - A managed unit with no stock records whose projections show stock (either sign)
     *    is the missing-ledger defect, whatever the backorder mode: it fails closed.
     *  - Otherwise the backorder action decides. Under deny the unit is in stock while
     *    the whole units above its safety stock cover its smallest sale; a negative
     *    safety stock counts as none. Under allow and allow-notify it is in stock while
     *    its whole units cover the smallest sale and on backorder below that; safety
     *    stock withholds nothing, and a unit on backorder stays sellable.
     *
     * A cart line holds whole units, so the stock side is counted in whole units
     * ({@see self::wholeUnits()}): a fractional remainder is not something a shopper can
     * buy and reads as out of stock (deny) or backorder (allow). The smallest sale comes
     * from the exact quantity rules and is compared as it is, with no tolerance.
     * Publication is judged by its own gate.
     *
     * @param float $salable Ledger salable quantity at enabled locations (0 without rows).
     * @param bool $managed Whether the unit manages stock (effective tracking).
     * @param bool $ledgerPresent Whether the unit has at least one stock record.
     * @param bool $projectionNonZero Whether the unit's projections show stock ({@see self::projectionNonZero()}).
     * @param bool $integrityHold Whether the unit has an open integrity hold.
     * @param string|null $backorder The stored backorder value the chain settled on; NULL, '' and any
     *                               unknown value follow the shop-wide setting.
     * @param float $minQty The resolved safety stock.
     * @param int|null $saleMinimum The smallest whole quantity the unit's exact quantity rules accept
     *                              ({@see CartQuantityPolicy::saleMinimum()}); NULL when they accept none.
     *
     * @return string One of the STATUS_* constants.
     *
     * @since  2.0.0
     */
    public static function statusFrom(
        float $salable,
        bool $managed,
        bool $ledgerPresent,
        bool $projectionNonZero,
        bool $integrityHold,
        ?string $backorder,
        float $minQty,
        ?int $saleMinimum,
    ): string {
        if ($integrityHold) {
            return self::STATUS_LEDGER_MISSING;
        }

        if ($saleMinimum === null) {
            return self::STATUS_OUT_OF_STOCK;
        }

        if (!$managed) {
            return self::STATUS_IN_STOCK;
        }

        if (!$ledgerPresent && $projectionNonZero) {
            return self::STATUS_LEDGER_MISSING;
        }

        if ((new BackorderPolicy())->effective(stored: $backorder) === InventoryService::BACKORDER_DENY) {
            return self::wholeUnits(salable: $salable, safety: $minQty) >= (float) $saleMinimum
                ? self::STATUS_IN_STOCK
                : self::STATUS_OUT_OF_STOCK;
        }

        return self::wholeUnits(salable: $salable, safety: 0.0) >= (float) $saleMinimum
            ? self::STATUS_IN_STOCK
            : self::STATUS_BACKORDER;
    }

    /**
     * Whether a stock verdict lets a shopper buy the unit: in stock or on backorder.
     *
     * @param string $status One of the STATUS_* constants.
     *
     *
     * @since  2.0.0
     */
    public static function sellableStatus(string $status): bool
    {
        return $status === self::STATUS_IN_STOCK || $status === self::STATUS_BACKORDER;
    }

    /**
     * Can a shopper buy this unit, as far as stock is concerned.
     *
     * {@see self::statusFrom()} collapsed to yes or no; the storefront verdicts and the
     * SQL form {@see self::sellableSql()} answer the same question over the same facts.
     *
     * @param float $salable Ledger salable quantity at enabled locations.
     * @param bool $managed Whether the unit manages stock.
     * @param bool $ledgerPresent Whether the unit has at least one stock record.
     * @param bool $projectionNonZero Whether the unit's projections show stock.
     * @param bool $integrityHold Whether the unit has an open integrity hold.
     * @param string|null $backorder The settled backorder value.
     * @param float $minQty The resolved safety stock.
     * @param int|null $saleMinimum The smallest quantity the unit's quantity rules accept; NULL for none.
     *
     *
     * @since  2.0.0
     */
    public static function isSellable(
        float $salable,
        bool $managed,
        bool $ledgerPresent,
        bool $projectionNonZero,
        bool $integrityHold,
        ?string $backorder,
        float $minQty,
        ?int $saleMinimum,
    ): bool {
        return self::sellableStatus(status: self::statusFrom(
            salable:           $salable,
            managed:           $managed,
            ledgerPresent:     $ledgerPresent,
            projectionNonZero: $projectionNonZero,
            integrityHold:     $integrityHold,
            backorder:         $backorder,
            minQty:            $minQty,
            saleMinimum:       $saleMinimum,
        ));
    }

    /**
     * The most of a unit the shop may commit right now, from the same stock facts as the verdict.
     *
     * NULL means no stock ceiling: the unit does not manage stock, or backorders are
     * allowed. A held unit and the missing-ledger defect have a ceiling of zero under
     * every mode. Under deny the ceiling is the salable quantity above the safety stock,
     * never negative; the cart floors it to whole units. The ceiling is about stock only:
     * the merchant's quantity rules are applied by the cart's quantity decision.
     *
     * @param float $salable Ledger salable quantity at enabled locations.
     * @param bool $managed Whether the unit manages stock.
     * @param bool $ledgerPresent Whether the unit has at least one stock record.
     * @param bool $projectionNonZero Whether the unit's projections show stock.
     * @param bool $integrityHold Whether the unit has an open integrity hold.
     * @param string|null $backorder The settled backorder value.
     * @param float $minQty The resolved safety stock.
     *
     *
     * @since  2.0.0
     */
    public static function ceilingFrom(
        float $salable,
        bool $managed,
        bool $ledgerPresent,
        bool $projectionNonZero,
        bool $integrityHold,
        ?string $backorder,
        float $minQty,
    ): ?float {
        if ($integrityHold) {
            return 0.0;
        }

        if (!$managed) {
            return null;
        }

        if (!$ledgerPresent && $projectionNonZero) {
            return 0.0;
        }

        if ((new BackorderPolicy())->effective(stored: $backorder) !== InventoryService::BACKORDER_DENY) {
            return null;
        }

        return max(0.0, $salable - max(0.0, $minQty));
    }

    /**
     * SQL yielding a unit's ledger salable quantity at enabled locations.
     *
     * NULL exactly when the unit has no stock record; 0 when its records are only at
     * disabled locations. A correlated subquery keyed on the (item_id, combination_id)
     * prefix of the unit key, so it reads only the unit's own rows.
     *
     * @param DatabaseInterface $db The connection (identifier quoting).
     * @param string $itemExpr SQL yielding the item id.
     * @param string $combinationExpr SQL yielding the combination id (0 for the item level).
     *
     * @return string A parenthesised scalar subquery.
     *
     * @since  2.0.0
     */
    public static function ledgerSalableSql(DatabaseInterface $db, string $itemExpr, string $combinationExpr): string
    {
        return '(SELECT SUM(CASE WHEN ' . LocationPolicy::enabledPredicate(db: $db, column: $db->quoteName('ls.location_id'))
            . ' THEN ' . $db->quoteName('ls.quantity') . ' - ' . $db->quoteName('ls.reserved_quantity') . ' ELSE 0 END)'
            . ' FROM ' . $db->quoteName('#__alfa_inventory_stock', 'ls')
            . ' WHERE ' . $db->quoteName('ls.item_id') . ' = ' . $itemExpr
            . ' AND ' . $db->quoteName('ls.combination_id') . ' = ' . $combinationExpr . ')';
    }

    /**
     * SQL yielding whether a unit has an open integrity hold.
     *
     * A unit is held from the command that recorded stock on it while its figures showed
     * stock with no records behind them, until the audited repair of its figures releases
     * the hold. A released hold is history and decides nothing. A correlated EXISTS keyed
     * on idx_unit (item_id, combination_id, released_operation_id).
     *
     * @param DatabaseInterface $db The connection (identifier quoting).
     * @param string $itemExpr SQL yielding the item id.
     * @param string $combinationExpr SQL yielding the combination id (0 for the item level).
     *
     * @return string A parenthesised boolean expression, never NULL.
     *
     * @since  2.0.0
     */
    public static function integrityHoldSql(DatabaseInterface $db, string $itemExpr, string $combinationExpr): string
    {
        return '(EXISTS (SELECT 1 FROM ' . $db->quoteName('#__alfa_inventory_integrity_holds', 'ih')
            . ' WHERE ' . $db->quoteName('ih.item_id') . ' = ' . $itemExpr
            . ' AND ' . $db->quoteName('ih.combination_id') . ' = ' . $combinationExpr
            . ' AND ' . $db->quoteName('ih.released_operation_id') . ' IS NULL))';
    }

    /**
     * The SQL form of {@see self::projectionNonZero()}.
     *
     * @param string $stockExpr SQL yielding the physical projection.
     * @param string $availableExpr SQL yielding the salable projection.
     *
     * @return string A parenthesised boolean expression, never NULL.
     *
     * @since  2.0.0
     */
    public static function projectionNonZeroSql(string $stockExpr, string $availableExpr): string
    {
        $tolerance = self::sqlQuantity(value: self::QUANTITY_TOLERANCE);

        return '(ABS(COALESCE(' . $stockExpr . ', 0)) > ' . $tolerance
            . ' OR ABS(COALESCE(' . $availableExpr . ', 0)) > ' . $tolerance . ')';
    }

    /**
     * The SQL form of {@see BackorderPolicy::effective()}: the backorder action a stored
     * value resolves to.
     *
     * A known action is compared byte for byte, so a differently cased or padded value
     * is unknown, as it is in PHP; NULL, '' and every unknown value become the shop-wide
     * action. The result is never NULL.
     *
     * @param DatabaseInterface $db The connection (literal quoting).
     * @param string $storedExpr SQL yielding the value the inheritance chain settled on.
     * @param string $globalAction The shop-wide action, already normalised.
     *
     * @return string A parenthesised CASE expression.
     *
     * @since  2.0.0
     */
    public static function backorderActionSql(DatabaseInterface $db, string $storedExpr, string $globalAction): string
    {
        return '(CASE WHEN CAST(' . $storedExpr . ' AS BINARY) IN ('
            . implode(', ', array_map(static fn (string $action): string => $db->quote($action), BackorderPolicy::VALUES))
            . ') THEN ' . $storedExpr . ' ELSE ' . $db->quote($globalAction) . ' END)';
    }

    /**
     * The SQL form of {@see self::isSellable()}: TRUE when the unit may be sold.
     *
     * Boolean-equivalent to isSellable() over the same facts, and two-valued: it is never
     * NULL, so `NOT <expr>` is exactly the complement and the in/out filter and its two
     * counts partition a listing. The unit's stock records are read at most once per
     * row, because the branches that read them exclude each other.
     *
     *  - held: never sellable;
     *  - the quantity rules accept no quantity: never sellable;
     *  - not managed: sellable;
     *  - deny: sellable while the whole units above the safety stock reach the smallest
     *    sale, read from the unit's records in one aggregate (no records reads as nothing
     *    on the shelf);
     *  - allow: sellable unless the unit has no records while its projections show stock.
     *
     * The quantity rules are compared in exact decimal arithmetic
     * ({@see CartQuantityPolicy::saleOrderableSql()}, {@see CartQuantityPolicy::saleReachableSql()});
     * only the stock side is counted in whole units with the stock tolerance
     * ({@see self::wholeUnitsSql()}).
     *
     * @param string $itemExpr SQL yielding the unit's item id.
     * @param string $combinationExpr SQL yielding the unit's combination id (0 for the item level).
     * @param string $managesExpr Boolean SQL: the unit manages stock.
     * @param string $projectionNonZeroExpr Boolean SQL: the unit's projections show stock
     *                                      ({@see self::projectionNonZeroSql()}).
     * @param string $holdExpr Boolean SQL: the unit has an open integrity hold
     *                         ({@see self::integrityHoldSql()}).
     * @param string $backorderExpr SQL yielding the RESOLVED backorder action
     *                              ({@see self::backorderActionSql()}).
     * @param string $minQtyExpr SQL yielding the resolved safety stock.
     * @param string $minRuleExpr SQL yielding the unit's resolved minimum, never NULL
     *                            ({@see CartQuantityPolicy::ruleValueSql()}).
     * @param string $stepRuleExpr SQL yielding the unit's resolved step, never NULL.
     * @param string $maxRuleExpr SQL yielding the unit's resolved maximum, never NULL.
     *
     * @return string A parenthesised boolean expression.
     *
     * @since  2.0.0
     */
    public static function sellableSql(
        string $itemExpr,
        string $combinationExpr,
        string $managesExpr,
        string $projectionNonZeroExpr,
        string $holdExpr,
        string $backorderExpr,
        string $minQtyExpr,
        string $minRuleExpr,
        string $stepRuleExpr,
        string $maxRuleExpr,
    ): string {
        $db = Factory::getContainer()->get('DatabaseDriver');

        // The unit's salable quantity at enabled locations, summed over its own records;
        // with no record the aggregate is NULL and so is the whole-unit comparison.
        $salable = 'SUM(CASE WHEN ' . LocationPolicy::enabledPredicate(db: $db, column: $db->quoteName('ls.location_id'))
            . ' THEN ' . $db->quoteName('ls.quantity') . ' - ' . $db->quoteName('ls.reserved_quantity') . ' ELSE 0 END)';

        $reachable = '(SELECT ' . CartQuantityPolicy::saleReachableSql(
            wholeUnitsExpr: self::wholeUnitsSql(salableExpr: $salable, safetyExpr: $minQtyExpr),
            minExpr:        $minRuleExpr,
            stepExpr:       $stepRuleExpr,
            maxExpr:        $maxRuleExpr,
        )
            . ' FROM ' . $db->quoteName('#__alfa_inventory_stock', 'ls')
            . ' WHERE ' . $db->quoteName('ls.item_id') . ' = ' . $itemExpr
            . ' AND ' . $db->quoteName('ls.combination_id') . ' = ' . $combinationExpr . ')';

        $orderable = CartQuantityPolicy::saleOrderableSql(
            minExpr:  $minRuleExpr,
            stepExpr: $stepRuleExpr,
            maxExpr:  $maxRuleExpr,
        );

        return '((CASE'
            . ' WHEN (' . $holdExpr . ') THEN 0'
            . ' WHEN NOT (' . $orderable . ') THEN 0'
            . ' WHEN NOT (' . $managesExpr . ') THEN 1'
            . ' WHEN (' . $backorderExpr . ') = ' . $db->quote(InventoryService::BACKORDER_DENY)
            . ' THEN COALESCE(' . $reachable . ', 0)'
            . ' WHEN NOT (' . $projectionNonZeroExpr . ') THEN 1'
            . ' ELSE ' . self::ledgerSalableSql(db: $db, itemExpr: $itemExpr, combinationExpr: $combinationExpr) . ' IS NOT NULL'
            . ' END) = 1)';
    }

    /**
     * SQL flagging a product whose stock figures have no stock records behind them, or
     * that is held until its stock figures are repaired.
     *
     * For a product without combinations: it manages stock, has no item-level record, and
     * its projections show stock in either sign; or its item-level unit has an open
     * integrity hold. For a product with combinations: at least one of its combinations is
     * in that state (tracking resolved combination, then product) or has an open hold.
     * The item level of a product with combinations is not a unit and is not judged here.
     * Only current combinations are read: an archived combination is no unit of the
     * product, and a product whose combinations are all archived is judged as a product
     * without combinations.
     *
     * @param string $alias The alias `#__alfa_items` is selected under.
     *
     * @return string A parenthesised boolean expression.
     *
     * @since  2.0.0
     */
    public static function ledgerMissingSql(string $alias): string
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $combinations = $db->quoteName('#__alfa_item_combinations', 'lc');
        $stock = $db->quoteName('#__alfa_inventory_stock', 'ls');
        $current = CombinationState::currentSql(column: $db->quoteName('lc.state'));

        $combinationMissing = '((COALESCE(' . $db->quoteName('lc.manage_stock') . ', ' . $alias . '.manage_stock, 0) = 1'
            . ' AND ' . self::projectionNonZeroSql(
                stockExpr:     $db->quoteName('lc.stock'),
                availableExpr: $db->quoteName('lc.available_quantity'),
            )
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $stock
            . ' WHERE ' . $db->quoteName('ls.item_id') . ' = ' . $db->quoteName('lc.item_id')
            . ' AND ' . $db->quoteName('ls.combination_id') . ' = ' . $db->quoteName('lc.id') . '))'
            . ' OR ' . self::integrityHoldSql(
                db:              $db,
                itemExpr:        $db->quoteName('lc.item_id'),
                combinationExpr: $db->quoteName('lc.id'),
            ) . ')';

        $itemMissing = '(COALESCE(' . $alias . '.manage_stock, 0) = 1'
            . ' AND ' . self::projectionNonZeroSql(
                stockExpr:     $alias . '.stock',
                availableExpr: $alias . '.available_quantity',
            )
            . ' AND NOT EXISTS (SELECT 1 FROM ' . $stock
            . ' WHERE ' . $db->quoteName('ls.item_id') . ' = ' . $alias . '.id'
            . ' AND ' . $db->quoteName('ls.combination_id') . ' = 0))'
            . ' OR ' . self::integrityHoldSql(
                db:              $db,
                itemExpr:        $alias . '.id',
                combinationExpr: '0',
            );

        return '(CASE WHEN EXISTS (SELECT 1 FROM ' . $combinations
            . ' WHERE ' . $db->quoteName('lc.item_id') . ' = ' . $alias . '.id AND ' . $current . ')'
            . ' THEN EXISTS (SELECT 1 FROM ' . $combinations
            . ' WHERE ' . $db->quoteName('lc.item_id') . ' = ' . $alias . '.id AND ' . $current . ' AND ' . $combinationMissing . ')'
            . ' ELSE (' . $itemMissing . ')'
            . ' END)';
    }

    /**
     * The inventory version: the id of the last committed inventory command.
     *
     * Every committed kernel command journals exactly one operation row, publication,
     * tracking and integrity-hold changes included, and so does every save that changes
     * a unit's sale rules (its backorder policy, safety stock or quantity rules:
     * {@see InventoryService::recordSaleRules()}). Every stored input of a unit's stock
     * verdict therefore moves this value, and a cache keyed on it is rebuilt after any
     * such change; the shop-wide settings the verdict reads are part of
     * {@see \Alfa\Component\Alfa\Administrator\Service\Catalog\StockVisibilityPolicy::cacheKey()}.
     *
     * @param DatabaseInterface $db The connection.
     *
     * @return int 0 when no command has ever run.
     *
     * @since  2.0.0
     */
    public static function inventoryVersion(DatabaseInterface $db): int
    {
        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('id') . ')')
                ->from($db->quoteName('#__alfa_inventory_operations')),
        )->loadResult();
    }

    /**
     * The low-stock threshold that actually applies to a unit.
     *
     * ONE RULE, ONE OWNER. This was being resolved in four places (the item
     * form, the low-stock notification, the dashboard card and the admin list
     * cell), and only the form completed the chain. The other three stopped at
     * the product's own column, so every product left on "Use Global" resolved
     * to no threshold at all: the notification could never fire for one, the
     * card never counted one, and the list never badged one. The merchant had
     * configured a shop-wide threshold and the form told them it was active.
     *
     * The contract is the form's, stated in ItemModel::applyLowStockVisibility():
     * "an empty item value reads 'inherit the shop-wide threshold', NOT 'this
     * product has none'."
     *
     * NULL is the only spelling of "not set" here: ItemModel::save()
     * normalises an empty or non-positive item value to NULL. The dashboard
     * card resolves the same chain in SQL (it cannot call into PHP per row)
     * and additionally reads a stored 0 as "not set" via NULLIF, which is the
     * one place the two spellings differ; it is unreachable through the form,
     * whose field carries min="1".
     *
     * @param float|null $combination The unit's own value, or null.
     * @param float|null $item The product's value, or null.
     *
     * @return float 0 when no threshold applies anywhere.
     *
     * @since  2.0.0
     */
    public static function lowStockThreshold(?float $combination, ?float $item): float
    {
        if ($combination !== null) {
            return (float) $combination;
        }

        if ($item !== null) {
            return (float) $item;
        }

        return (float) ComponentHelper::getParams('com_alfa')->get('stock_low', 0);
    }

    /**
     * Whether safety stock is actually withholding units from sale for this
     * product: true only under Deny, where the stock ceiling enforces it.
     * A surface that shows the reserve should stay silent when it binds nothing.
     *
     * @param string|null $itemBackorder Per-product policy; NULL, '' and unknown values follow configuration.
     * @param float $minQty Safety stock withheld from sale.
     *
     *
     * @since  2.0.0
     */
    public static function safetyStockBinds(?string $itemBackorder, float $minQty): bool
    {
        if ($minQty <= 0) {
            return false;
        }

        // The same resolution the verdict uses, so the note and the badge beside it
        // never read the backorder value differently.
        return (new BackorderPolicy())->effective(stored: $itemBackorder) === InventoryService::BACKORDER_DENY;
    }

    /**
     * A quantity as an SQL decimal literal: fixed notation, never an exponent.
     *
     * @param float $value The quantity.
     *
     *
     * @since  2.0.0
     */
    private static function sqlQuantity(float $value): string
    {
        return number_format($value, 8, '.', '');
    }
}
