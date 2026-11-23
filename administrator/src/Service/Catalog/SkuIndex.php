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
 *     $unit = SkuIndex::unit('SMX-WHT-256');   // a SkuUnit, or a Refusal saying why none
 *     SkuIndex::keep(itemId: 42);              // inside the write's transaction, after the product's rows are written
 *
 * Every writer of a SKU calls keep() for the product it wrote, in its own transaction: the item save, the inline SKU
 * edit, the variant's own save, the grid (inside the item save) and a deletion (forget()). keep() reads the product's
 * SKUs from its rows and writes what changed; a SKU another unit holds throws SkuInUseException, and the write's
 * transaction rolls back. Two writes claiming one SKU at once meet on the registry's key: one wins, the other is refused.
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
     *
     * @throws SkuInUseException When a SKU of the product is another unit's, or two of its units share one; the
     *                           caller's transaction rolls back.
     *
     * @since  2.3.0
     */
    public static function keep(int $itemId): void
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

        // A SKU another unit already holds, registered or (a duplicate the registry never took) in the catalogue.
        foreach ($new as $combinationId => $sku) {
            $other = self::holder(sku: $sku);

            if ($other !== null && $other->itemId !== $itemId) {
                throw self::inUse(sku: $sku, holder: $other);
            }

            $others = self::heldBy(sku: $sku, exceptItemId: $itemId);

            if ($others !== []) {
                throw self::inUse(sku: $sku, holder: $others[0]);
            }
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

                throw self::inUse(sku: $sku, holder: $other);
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
     * The SKUs held by more than one unit, each with the units' labels: the units with a SKU the registry does not
     * hold for them.
     *
     * @param int $limit The most SKUs named; 0 for every one.
     *
     * @return array<string, list<string>>
     *
     * @since  2.3.0
     */
    public static function duplicates(int $limit = 20): array
    {
        $db = self::db();
        $items = $db->getQuery(true)
            ->select('TRIM(' . $db->quoteName('i.sku') . ') AS ' . $db->quoteName('sku'))
            ->from($db->quoteName('#__alfa_items', 'i'))
            ->join('LEFT', $db->quoteName(self::TABLE, 's'), $db->quoteName('s.item_id') . ' = ' . $db->quoteName('i.id')
                . ' AND ' . $db->quoteName('s.combination_id') . ' = 0')
            ->where('TRIM(' . $db->quoteName('i.sku') . ") <> ''")
            ->where($db->quoteName('s.item_id') . ' IS NULL');
        $combinations = $db->getQuery(true)
            ->select('TRIM(' . $db->quoteName('c.sku') . ')')
            ->from($db->quoteName('#__alfa_item_combinations', 'c'))
            ->join('LEFT', $db->quoteName(self::TABLE, 's'), $db->quoteName('s.combination_id') . ' = ' . $db->quoteName('c.id'))
            ->where('TRIM(' . $db->quoteName('c.sku') . ") <> ''")
            ->where($db->quoteName('s.item_id') . ' IS NULL');
        $skus = array_values(array_unique(array_map('strval', $db->setQuery($items->union($combinations))->loadColumn() ?: [])));
        $duplicates = [];

        foreach ($skus as $sku) {
            $units = self::heldBy(sku: $sku, exceptItemId: 0);
            $holder = self::holder(sku: $sku);

            if ($holder !== null) {
                $units[] = $holder;
            }

            $labels = array_values(array_unique(array_map(
                static fn (SkuUnit $unit): string => InventoryUnitException::labelFor(itemId: $unit->itemId, combinationId: $unit->combinationId),
                $units,
            )));

            if (\count($units) > 1) {
                $duplicates[$sku] = $labels;
            }

            if ($limit > 0 && \count($duplicates) >= $limit) {
                break;
            }
        }

        return $duplicates;
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
