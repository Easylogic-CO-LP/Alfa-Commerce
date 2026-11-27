<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

/**
 * Every SKU in use, once: a product's own SKU and each combination's, archived ones included, compared without regard
 * to letter case (the registry table's key, #__alfa_skus). A SKU names one unit, so an ERP finds a product or a variant
 * by its SKU and two writes cannot give one SKU to two units.
 *
 *     $unit = SkuIndex::unit('SMX-WHT-256');           // a SkuUnit, or a Refusal saying why none
 *
 *     $before = SkuIndex::skusOf(itemId: 42);          // before the write
 *     // ... the write ...
 *     SkuIndex::keep(itemId: 42, before: $before);     // in the write's transaction, after the product's rows
 *
 * Every writer of a SKU calls keep() for the product it wrote, in its own transaction: the item save, the inline SKU
 * edit, the variant's own save, the grid (inside the item save) and a deletion (forget()). keep() reads the product's
 * SKUs from its rows and writes what changed; a unit that takes a SKU another unit holds throws SkuInUseException, and
 * the write's transaction rolls back. A unit that keeps the SKU it held before the write is never refused for it: a
 * SKU two units held before the registry (the update names them) stays out of it until one of them changes. Two writes
 * claiming one SKU at once meet on the registry's key: one wins, the other is refused.
 *
 * @api READ ONLY: unit() and holder() find the unit a SKU names; keep() and forget() are the writers' own calls.
 *
 * @since  2.3.0
 */
final class SkuIndex
{
    /**
     * The registry.
     *
     * @since  2.3.0
     */
    public const TABLE = '#__alfa_skus';

    /**
     * The longest SKU, the catalogue columns' width.
     *
     * @since  2.3.0
     */
    public const MAX_LENGTH = 255;

    /**
     * The products the update's fill reads at a time.
     *
     * @since  2.3.0
     */
    private const FILL_RANGE = 1000;

    /**
     * The unit a SKU names, or why it names none: unknown (422), held by more than one unit (409), archived (409), or a
     * product's own SKU while its stock is held by its combinations (409).
     *
     * @param string $sku The SKU, trimmed here.
     *
     * @since  2.3.0
     */
    public static function unit(string $sku): SkuUnit|Refusal
    {
        $sku = trim($sku);

        if (!self::wellFormed(sku: $sku)) {
            return new Refusal(code: 'sku_invalid', sentence: Text::_('COM_ALFA_SKU_INVALID'));
        }

        $unit = self::holder(sku: $sku);

        if ($unit === null) {
            return self::heldBy(sku: $sku, exceptItemId: 0) === []
                ? new Refusal(code: 'sku_unknown', sentence: Text::sprintf('COM_ALFA_SKU_UNKNOWN', self::shown(sku: $sku)))
                : new Refusal(code: 'sku_ambiguous', sentence: Text::sprintf('COM_ALFA_SKU_AMBIGUOUS', self::shown(sku: $sku)), status: Refusal::CONFLICT);
        }

        $db = self::db();
        $itemId = $unit->itemId;
        $combinationId = $unit->combinationId;
        $itemState = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('state'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $itemId, ParameterType::INTEGER),
        )->loadResult();
        $archived = $itemState === null || (int) $itemState === CombinationState::ARCHIVED;

        if (!$archived && $unit->combinationId > 0) {
            $archived = !CombinationState::isCurrent(state: $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('state'))
                    ->from($db->quoteName('#__alfa_item_combinations'))
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':id', $combinationId, ParameterType::INTEGER),
            )->loadResult());
        }

        if ($archived) {
            return new Refusal(
                code: 'sku_archived',
                sentence: Text::sprintf('COM_ALFA_SKU_ARCHIVED', self::shown(sku: $sku), InventoryUnitException::labelFor(itemId: $unit->itemId, combinationId: $unit->combinationId)),
                status: Refusal::CONFLICT,
            );
        }

        if ($unit->combinationId === 0 && self::hasCurrentCombinations(db: $db, itemId: $unit->itemId)) {
            return new Refusal(
                code: 'sku_held_by_variants',
                sentence: Text::sprintf('COM_ALFA_SKU_HELD_BY_VARIANTS', self::shown(sku: $sku), InventoryUnitException::labelFor(itemId: $unit->itemId, combinationId: 0)),
                status: Refusal::CONFLICT,
            );
        }

        return $unit;
    }

    /**
     * The unit the registry holds a SKU for, archived or not; null when it holds none.
     *
     * @param string $sku The SKU, trimmed here.
     *
     * @since  2.3.0
     */
    public static function holder(string $sku): ?SkuUnit
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['item_id', 'combination_id']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('sku') . ' = :sku')
                ->bind(':sku', $sku),
        )->loadObject();

        return $row === null ? null : new SkuUnit(itemId: (int) $row->item_id, combinationId: (int) $row->combination_id);
    }

    /**
     * Write the registry rows of one product from its rows: its own SKU and each combination's, archived included.
     * Called by every writer of a SKU inside its transaction, after the product's rows are written.
     *
     * @param int $itemId The product.
     * @param array<int, string> $before The product's SKUs before the write, by combination ({@see self::skusOf()}).
     *
     * @throws SkuInUseException When a unit takes a SKU another unit holds, or two of its units take one; the
     *                           caller's transaction rolls back.
     *
     * @since  2.3.0
     */
    public static function keep(int $itemId, array $before = []): void
    {
        if ($itemId <= 0) {
            return;
        }

        $db = self::db();
        $wanted = self::unitsOf(db: $db, itemId: $itemId);
        $stored = [];

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['combination_id', 'sku']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('item_id') . ' = :item')
                ->bind(':item', $itemId, ParameterType::INTEGER),
        )->loadObjectList() ?: [] as $row) {
            $stored[(int) $row->combination_id] = (string) $row->sku;
        }

        $gone = array_keys(array_diff_assoc($stored, $wanted));
        $new = array_diff_assoc($wanted, $stored);

        if ($gone === [] && $new === []) {
            return;
        }

        // A SKU another unit already holds, registered or (a duplicate the registry never took) in the catalogue: a
        // unit that takes it is refused; one that held it before the write keeps it, out of the registry.
        foreach ($new as $combinationId => $sku) {
            $other = self::holder(sku: $sku);
            $others = $other !== null && $other->itemId !== $itemId ? [$other] : self::heldBy(sku: $sku, exceptItemId: $itemId);

            if ($others === []) {
                continue;
            }

            if (($before[$combinationId] ?? null) === $sku) {
                unset($new[$combinationId]);

                continue;
            }

            throw self::inUse(sku: $sku, holder: $others[0]);
        }

        if ($gone !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(self::TABLE))
                    ->where($db->quoteName('item_id') . ' = :item')
                    ->whereIn($db->quoteName('combination_id'), $gone)
                    ->bind(':item', $itemId, ParameterType::INTEGER),
            )->execute();
        }

        foreach ($new as $combinationId => $sku) {
            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->insert($db->quoteName(self::TABLE))
                        ->columns($db->quoteName(['sku', 'item_id', 'combination_id']))
                        ->values(':sku, :item, :combination')
                        ->bind(':sku', $sku)
                        ->bind(':item', $itemId, ParameterType::INTEGER)
                        ->bind(':combination', $combinationId, ParameterType::INTEGER),
                )->execute();
            } catch (ExecutionFailureException $e) {
                // The key is the SKU: another of this product's units holds it, or a write that committed meanwhile,
                // read past this transaction's snapshot.
                $other = $e->getCode() === 1062 ? self::lockedHolder(db: $db, sku: $sku) : null;

                if ($other === null) {
                    throw $e;
                }

                if (($before[$combinationId] ?? null) !== $sku) {
                    throw self::inUse(sku: $sku, holder: $other);
                }
            }
        }
    }

    /**
     * Remove the registry rows of deleted products, in the deletion's transaction.
     *
     * @param list<int> $itemIds The products.
     *
     * @since  2.3.0
     */
    public static function forget(array $itemIds): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));

        if ($itemIds === []) {
            return;
        }

        $db = self::db();
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('item_id'), $itemIds),
        )->execute();
    }

    /**
     * Fill the registry from the catalogue, a range of products at a time: every SKU not registered yet, unless another
     * unit holds it too. Run by the update; what it leaves out are the duplicates it returns.
     *
     * @return array{registered: int, duplicates: array<string, list<string>>} The SKUs registered, and each SKU held by
     *                                                                         more than one unit with the units' labels.
     *
     * @since  2.3.0
     */
    public static function fill(): array
    {
        $db = self::db();
        $registered = 0;
        $after = 0;

        do {
            $ids = array_map('intval', $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__alfa_items'))
                    ->where($db->quoteName('id') . ' > :after')
                    ->order($db->quoteName('id'))
                    ->bind(':after', $after, ParameterType::INTEGER),
                0,
                self::FILL_RANGE,
            )->loadColumn() ?: []);

            foreach ($ids as $itemId) {
                foreach (self::unitsOf(db: $db, itemId: $itemId) as $combinationId => $sku) {
                    $holder = self::holder(sku: $sku);

                    if ($holder !== null || self::heldBy(sku: $sku, exceptItemId: 0, exceptUnit: [$itemId, $combinationId]) !== []) {
                        continue;
                    }

                    $db->setQuery(
                        $db->getQuery(true)
                            ->delete($db->quoteName(self::TABLE))
                            ->where($db->quoteName('item_id') . ' = :item')
                            ->where($db->quoteName('combination_id') . ' = :combination')
                            ->bind(':item', $itemId, ParameterType::INTEGER)
                            ->bind(':combination', $combinationId, ParameterType::INTEGER),
                    )->execute();
                    $db->setQuery(
                        $db->getQuery(true)
                            ->insert($db->quoteName(self::TABLE))
                            ->columns($db->quoteName(['sku', 'item_id', 'combination_id']))
                            ->values(':sku, :item, :combination')
                            ->bind(':sku', $sku)
                            ->bind(':item', $itemId, ParameterType::INTEGER)
                            ->bind(':combination', $combinationId, ParameterType::INTEGER),
                    )->execute();
                    $registered++;
                }
            }

            $after = $ids === [] ? $after : (int) end($ids);
        } while (\count($ids) === self::FILL_RANGE);

        return ['registered' => $registered, 'duplicates' => self::duplicates(limit: 0)];
    }

    /**
     * The SKUs held by more than one unit, each with the units' labels, in the order of the SKUs: a SKU that a unit the
     * registry holds nothing for carries, and that another unit carries or the registry holds for another unit
     * ({@see self::duplicateSkus()}).
     *
     * @param int $limit The most SKUs named, a display limit whose total {@see self::duplicateCount()} gives; 0 for
     *                   every one.
     *
     * @return array<string, list<string>>
     *
     * @since  2.3.0
     */
    public static function duplicates(int $limit = 20): array
    {
        $db = self::db();
        $skus = array_map('strval', $db->setQuery(
            'SELECT ' . $db->quoteName('d.sku') . ' FROM (' . self::duplicateSkus(db: $db) . ') AS ' . $db->quoteName('d')
            . ' ORDER BY ' . $db->quoteName('d.sku') . ($limit > 0 ? ' LIMIT ' . $limit : ''),
        )->loadColumn() ?: []);
        $duplicates = [];

        foreach ($skus as $sku) {
            $units = self::heldBy(sku: $sku, exceptItemId: 0);
            $holder = self::holder(sku: $sku);

            if ($holder !== null) {
                $units[] = $holder;
            }

            $duplicates[$sku] = array_values(array_unique(array_map(
                static fn (SkuUnit $unit): string => InventoryUnitException::labelFor(itemId: $unit->itemId, combinationId: $unit->combinationId),
                $units,
            )));
        }

        return $duplicates;
    }

    /**
     * How many SKUs are held by more than one unit, counted in one statement: the total of which
     * {@see self::duplicates()} names the first.
     *
     * @since  2.3.0
     */
    public static function duplicateCount(): int
    {
        $db = self::db();

        return (int) $db->setQuery('SELECT COUNT(*) FROM (' . self::duplicateSkus(db: $db) . ') AS ' . $db->quoteName('d'))->loadResult();
    }

    /**
     * The statement of the SKUs held by more than one unit, one row a SKU (column sku), compared as the registry
     * compares: every unit's trimmed SKU from its row and every unit the registry holds a SKU for, grouped by the SKU,
     * the groups with more than one unit of which one has no registry row (a SKU two units held before the registry,
     * kept out of it until one of them changes).
     *
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.3.0
     */
    private static function duplicateSkus(DatabaseInterface $db): string
    {
        $unregistered = 'CASE WHEN ' . $db->quoteName('s.item_id') . ' IS NULL THEN 1 ELSE 0 END';

        return 'SELECT ' . $db->quoteName('u.sku') . ' FROM ('
            . 'SELECT TRIM(' . $db->quoteName('i.sku') . ') AS ' . $db->quoteName('sku') . ', ' . $db->quoteName('i.id') . ' AS ' . $db->quoteName('item_id')
            . ', 0 AS ' . $db->quoteName('combination_id') . ', ' . $unregistered . ' AS ' . $db->quoteName('unregistered')
            . ' FROM ' . $db->quoteName('#__alfa_items', 'i')
            . ' LEFT JOIN ' . $db->quoteName(self::TABLE, 's') . ' ON ' . $db->quoteName('s.item_id') . ' = ' . $db->quoteName('i.id')
            . ' AND ' . $db->quoteName('s.combination_id') . ' = 0'
            . ' WHERE TRIM(' . $db->quoteName('i.sku') . ") <> ''"
            . ' UNION SELECT TRIM(' . $db->quoteName('c.sku') . '), ' . $db->quoteName('c.item_id') . ', ' . $db->quoteName('c.id') . ', ' . $unregistered
            . ' FROM ' . $db->quoteName('#__alfa_item_combinations', 'c')
            . ' LEFT JOIN ' . $db->quoteName(self::TABLE, 's') . ' ON ' . $db->quoteName('s.combination_id') . ' = ' . $db->quoteName('c.id')
            . ' WHERE TRIM(' . $db->quoteName('c.sku') . ") <> ''"
            . ' UNION SELECT ' . $db->quoteName('r.sku') . ', ' . $db->quoteName('r.item_id') . ', ' . $db->quoteName('r.combination_id') . ', 0'
            . ' FROM ' . $db->quoteName(self::TABLE, 'r')
            . ') AS ' . $db->quoteName('u')
            . ' GROUP BY ' . $db->quoteName('u.sku')
            . ' HAVING COUNT(DISTINCT ' . $db->quoteName('u.item_id') . ', ' . $db->quoteName('u.combination_id') . ') > 1'
            . ' AND MAX(' . $db->quoteName('u.unregistered') . ') = 1';
    }

    /**
     * Whether a SKU can be stored and looked up: 1 to 255 characters once trimmed, no control character.
     *
     * @param string $sku The SKU, trimmed.
     *
     * @since  2.3.0
     */
    public static function wellFormed(string $sku): bool
    {
        return $sku !== '' && mb_strlen($sku) <= self::MAX_LENGTH && preg_match('/[\x00-\x1F\x7F]/', $sku) !== 1;
    }

    /**
     * A SKU as a sentence names it: escaped, since the sentence reaches the administrator's messages as HTML.
     *
     * @param string $sku The SKU.
     *
     * @since  2.3.0
     */
    public static function shown(string $sku): string
    {
        return htmlspecialchars($sku, ENT_QUOTES, 'UTF-8');
    }

    /**
     * A product's SKUs as its rows hold them now, by combination (0 the product itself): what a writer reads before
     * its write and hands to keep().
     *
     * @param int $itemId The product; 0 for one not saved yet (no SKUs).
     *
     * @return array<int, string>
     *
     * @since  2.3.0
     */
    public static function skusOf(int $itemId): array
    {
        return $itemId > 0 ? self::unitsOf(db: self::db(), itemId: $itemId) : [];
    }

    /**
     * A product's SKUs from its rows, by combination (0 the product itself): trimmed, the empty ones left out.
     *
     * @param DatabaseInterface $db The connection.
     * @param int $itemId The product.
     *
     * @return array<int, string>
     *
     * @since  2.3.0
     */
    private static function unitsOf(DatabaseInterface $db, int $itemId): array
    {
        $units = [];
        $own = trim((string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('sku'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $itemId, ParameterType::INTEGER),
        )->loadResult());

        if ($own !== '') {
            $units[0] = $own;
        }

        foreach ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'sku']))
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->where($db->quoteName('item_id') . ' = :item')
                ->order($db->quoteName('id'))
                ->bind(':item', $itemId, ParameterType::INTEGER),
        )->loadObjectList() ?: [] as $row) {
            $sku = trim((string) $row->sku);

            if ($sku !== '') {
                $units[(int) $row->id] = $sku;
            }
        }

        return $units;
    }

    /**
     * The units of the catalogue whose rows carry a SKU, compared as the registry compares, other than a product's.
     *
     * @param string $sku The SKU.
     * @param int $exceptItemId A product whose units are not counted; 0 for none.
     * @param array{0: int, 1: int}|null $exceptUnit One unit not counted (product, combination).
     *
     * @return list<SkuUnit>
     *
     * @since  2.3.0
     */
    private static function heldBy(string $sku, int $exceptItemId, ?array $exceptUnit = null): array
    {
        $db = self::db();
        // No bound values: a union's second query keeps its own.
        $items = $db->getQuery(true)
            ->select([$db->quoteName('id', 'item_id'), '0 AS ' . $db->quoteName('combination_id')])
            ->from($db->quoteName('#__alfa_items'))
            ->where($db->quoteName('sku') . ' = ' . $db->quote($sku))
            ->where($db->quoteName('id') . ' <> ' . $exceptItemId);
        $combinations = $db->getQuery(true)
            ->select([$db->quoteName('item_id'), $db->quoteName('id', 'combination_id')])
            ->from($db->quoteName('#__alfa_item_combinations'))
            ->where($db->quoteName('sku') . ' = ' . $db->quote($sku))
            ->where($db->quoteName('item_id') . ' <> ' . $exceptItemId);
        $units = [];

        foreach ($db->setQuery($items->union($combinations))->loadObjectList() ?: [] as $row) {
            if ($exceptUnit !== null && (int) $row->item_id === $exceptUnit[0] && (int) $row->combination_id === $exceptUnit[1]) {
                continue;
            }

            $units[] = new SkuUnit(itemId: (int) $row->item_id, combinationId: (int) $row->combination_id);
        }

        return $units;
    }

    /**
     * The unit the registry holds a SKU for as committed now, under a lock: a write that committed after this
     * transaction began is seen.
     *
     * @param DatabaseInterface $db The connection.
     * @param string $sku The SKU.
     *
     * @since  2.3.0
     */
    private static function lockedHolder(DatabaseInterface $db, string $sku): ?SkuUnit
    {
        $row = $db->setQuery(
            'SELECT ' . $db->quoteName('item_id') . ', ' . $db->quoteName('combination_id') . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('sku') . ' = ' . $db->quote($sku) . ' FOR UPDATE',
        )->loadObject();

        return $row === null ? null : new SkuUnit(itemId: (int) $row->item_id, combinationId: (int) $row->combination_id);
    }

    /**
     * The refusal of a SKU another unit holds.
     *
     * @param string $sku The SKU.
     * @param SkuUnit $holder The unit that holds it.
     *
     * @since  2.3.0
     */
    private static function inUse(string $sku, SkuUnit $holder): SkuInUseException
    {
        return new SkuInUseException(new Refusal(
            code: 'sku_in_use',
            sentence: Text::sprintf('COM_ALFA_SKU_IN_USE', self::shown(sku: $sku), InventoryUnitException::labelFor(itemId: $holder->itemId, combinationId: $holder->combinationId)),
            pointer: '/sku',
            status: Refusal::CONFLICT,
        ));
    }

    /**
     * Whether a product has a current (published or unpublished) combination.
     *
     * @param DatabaseInterface $db The connection.
     * @param int $itemId The product.
     *
     * @since  2.3.0
     */
    private static function hasCurrentCombinations(DatabaseInterface $db, int $itemId): bool
    {
        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_item_combinations'))
                ->where($db->quoteName('item_id') . ' = :item')
                ->where(CombinationState::currentSql(column: $db->quoteName('state')))
                ->bind(':item', $itemId, ParameterType::INTEGER),
        )->loadResult() > 0;
    }

    /**
     * The connection.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
