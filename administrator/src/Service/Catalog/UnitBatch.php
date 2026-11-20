<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\ItemModel;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryIntegrityException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryProjectionMismatchException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryReservedFloorException;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryUnitException;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The prices and stock of many units written in one request (PATCH v1/alfa/items/units): a unit is a product without
 * combinations or one combination, named by item_id and combination_id (0 or absent for the product itself).
 *
 * Each unit is written in a transaction of its own, whole or not at all, and answered on its own: a unit that is
 * refused is named by its JSON pointer in the request (/units/3, /units/3/prices_state) with its status and the
 * reason, and the other units are written. A unit's prices replace its own price rows as the item write and the
 * combination's price editor write them (rows kept by id, rows not sent removed), refused 409 when the unit's
 * prices_state was sent and its prices changed since; its stock is an absolute figure, as the item write takes it
 * (stock at the one location the unit is stocked at, or inventory_stock by location), converged by the inventory
 * service as every stock editor does.
 *
 * The products whose prices were written are marked owed a rebuild in each unit's transaction, and their catalogue (the
 * price index through PriceIndexSyncService, with the other catalogue projections) is rebuilt once for all of them
 * after the last unit, in chunks, under each product's lock; a product whose rebuild cannot run now keeps its mark and
 * the catalogue job rebuilds it. Stock writes keep their read models in the inventory service's own transaction.
 *
 * @since  2.2.2
 */
final class UnitBatch
{
    /**
     * The most units one request writes.
     *
     * @since  2.2.2
     */
    public const MAX_UNITS = 100;

    /**
     * The keys a unit takes.
     *
     * @since  2.2.2
     */
    private const KEYS = ['item_id', 'combination_id', 'prices', 'prices_state', 'stock', 'inventory_stock'];

    /**
     * The connection.
     *
     * @since  2.2.2
     */
    private DatabaseDriver $db;

    /**
     * @since  2.2.2
     */
    public function __construct()
    {
        $this->db = TransactionState::driver(Factory::getContainer()->get('DatabaseDriver'));
    }

    /**
     * Write each unit, then rebuild the catalogue of the products whose prices were written, once.
     *
     * @param list<mixed> $units The units as the request sends them.
     *
     * @return list<array<string, mixed>> One answer a unit, in the request's order: pointer, status, item_id,
     *                                    combination_id, and either prices_state, stock and available_quantity (200)
     *                                    or title (a refusal).
     *
     * @since  2.2.2
     */
    public function write(array $units): array
    {
        $answers = [];
        $seen = [];
        $priced = [];

        foreach (array_values($units) as $index => $unit) {
            $pointer = '/units/' . $index;
            $refusal = $this->refusal(unit: $unit, pointer: $pointer, seen: $seen);

            // A unit is named once a request, whether or not its first mention was written.
            if (\is_array($unit) && self::id($unit['item_id'] ?? null, positive: true) && self::id($unit['combination_id'] ?? 0, positive: false)) {
                $seen[(int) $unit['item_id'] . ':' . (int) ($unit['combination_id'] ?? 0)] = true;
            }

            if ($refusal !== null) {
                $answers[] = $refusal;

                continue;
            }

            $itemId = (int) $unit['item_id'];
            $combinationId = (int) ($unit['combination_id'] ?? 0);
            $answer = $this->writeUnit(unit: $unit, itemId: $itemId, combinationId: $combinationId, pointer: $pointer);

            if ($answer['status'] === 200 && \array_key_exists('prices', $unit)) {
                $priced[$itemId] = $itemId;
            }

            $answers[] = $answer;
        }

        if ($priced !== []) {
            // One rebuild for every product whose prices were written; one that cannot run now keeps its mark.
            try {
                (new ProjectionManager())->syncItems(itemIds: array_values($priced));
            } catch (Throwable $e) {
                Log::add('The catalogue of products ' . implode(', ', $priced) . ' was not rebuilt after their prices were written: ' . $e->getMessage(), Log::ERROR, 'com_alfa.catalog');
                ProjectionManager::owe(itemIds: array_values($priced));
            }
        }

        return $this->withFigures(answers: $answers);
    }

    /**
     * A unit refused before anything is written: its shape, its keys, the unit it names. Null when it can be written.
     *
     * @param mixed $unit The unit as sent.
     * @param string $pointer Its JSON pointer.
     * @param array<string, true> $seen The units named before it.
     *
     * @return array<string, mixed>|null
     *
     * @since  2.2.2
     */
    private function refusal(mixed $unit, string $pointer, array $seen): ?array
    {
        if (!\is_array($unit) || ($unit !== [] && array_is_list($unit))) {
            return self::refused($pointer, 422, 'send each unit as an object, {"item_id": 12, "prices": [...], "stock": "5"}.');
        }

        foreach (array_keys($unit) as $key) {
            if (!\in_array($key, self::KEYS, true)) {
                return self::refused($pointer . '/' . self::segment((string) $key), 422, 'a unit takes item_id, combination_id, prices, prices_state, stock and inventory_stock only.');
            }
        }

        $itemId = $unit['item_id'] ?? null;
        $combinationId = $unit['combination_id'] ?? 0;

        if (!self::id($itemId, positive: true)) {
            return self::refused($pointer . '/item_id', 422, 'send the product\'s id, a whole number above 0.');
        }

        if (!self::id($combinationId, positive: false)) {
            return self::refused($pointer . '/combination_id', 422, 'send the combination\'s id, a whole number; 0 or none for the product itself.');
        }

        $itemId = (int) $itemId;
        $combinationId = (int) $combinationId;
        $answer = static fn (string $at, int $status, string $title): array => self::refused($at, $status, $title, $itemId, $combinationId);

        if (!\array_key_exists('prices', $unit) && !\array_key_exists('stock', $unit) && !\array_key_exists('inventory_stock', $unit)) {
            return $answer($pointer, 422, 'send prices, stock or inventory_stock: the unit names nothing to write.');
        }

        if (isset($seen[$itemId . ':' . $combinationId])) {
            return $answer($pointer, 422, 'this unit is named earlier in the request; send each unit once.');
        }

        if (\array_key_exists('prices', $unit)) {
            if (!\is_array($unit['prices']) || !array_is_list($unit['prices'])) {
                return $answer($pointer . '/prices', 422, 'send prices as a list of price rows, [] for none.');
            }

            foreach ($unit['prices'] as $row => $price) {
                if (!\is_array($price) || ($price !== [] && array_is_list($price))) {
                    return $answer($pointer . '/prices/' . $row, 422, 'send each price row as an object.');
                }

                $mode = $price['mode'] ?? 'price';

                if (!\in_array($mode, ['price', 'adjust'], true) || ($mode === 'adjust' && $combinationId === 0)) {
                    return $answer($pointer . '/prices/' . $row . '/mode', 422, $combinationId === 0
                        ? 'a product\'s own price rows are of the price mode only.'
                        : 'send mode as price or adjust.');
                }
            }

            $invalid = ItemModel::priceRefusals(rows: $unit['prices']);

            if ($invalid !== []) {
                return $answer($pointer . '/prices', 422, html_entity_decode(strip_tags(implode(' ', $invalid)), ENT_QUOTES, 'UTF-8'));
            }
        }

        if (\array_key_exists('prices_state', $unit)) {
            if (!\array_key_exists('prices', $unit)) {
                return $answer($pointer . '/prices_state', 422, 'send prices_state with prices, the state the unit\'s prices were read in.');
            }

            if (!\is_string($unit['prices_state']) || preg_match(PricesState::PATTERN, $unit['prices_state']) !== 1) {
                return $answer($pointer . '/prices_state', 422, 'send prices_state as it was read, 32 hexadecimal digits.');
            }
        }

        if (\array_key_exists('stock', $unit) && \array_key_exists('inventory_stock', $unit)) {
            return $answer($pointer . '/inventory_stock', 422, 'send stock or inventory_stock, not both.');
        }

        if (\array_key_exists('stock', $unit) && !self::figure($unit['stock'])) {
            return $answer($pointer . '/stock', 422, 'send one figure of 0 or more, a number such as "5", for a unit stocked at one location; for stock per location send inventory_stock.');
        }

        if (\array_key_exists('inventory_stock', $unit)) {
            $map = $unit['inventory_stock'];

            if (!\is_array($map) || $map === [] || array_is_list($map)) {
                return $answer($pointer . '/inventory_stock', 422, 'send an object of location id to figure, {"1": "3", "2": "2"}.');
            }

            foreach ($map as $location => $figure) {
                if (preg_match('/^[1-9][0-9]*$/D', (string) $location) !== 1 || !self::figure($figure)) {
                    return $answer($pointer . '/inventory_stock/' . self::segment((string) $location), 422, 'each key is a stock location\'s id (GET v1/alfa/locations), each value a figure of 0 or more.');
                }
            }

            $known = array_map('intval', $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName('id'))
                    ->from($this->db->quoteName('#__alfa_inventory_locations'))
                    ->whereIn($this->db->quoteName('id'), array_map('intval', array_keys($map))),
            )->loadColumn() ?: []);

            foreach (array_keys($map) as $location) {
                if (!\in_array((int) $location, $known, true)) {
                    return $answer($pointer . '/inventory_stock/' . $location, 422, 'this is not one of the shop\'s stock locations (GET v1/alfa/locations).');
                }
            }
        }

        $product = $this->db->setQuery(
            'SELECT ' . CombinationState::currentSql(column: $this->db->quoteName('state')) . ' FROM ' . $this->db->quoteName('#__alfa_items')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $itemId,
        )->loadResult();

        if ($product === null || (int) $product !== 1) {
            return $answer($pointer . '/item_id', 404, 'there is no current product with this id.');
        }

        if ($combinationId > 0) {
            $combination = $this->db->setQuery(
                'SELECT 1 FROM ' . $this->db->quoteName('#__alfa_item_combinations')
                . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $combinationId . ' AND ' . $this->db->quoteName('item_id') . ' = ' . $itemId
                . ' AND ' . CombinationState::currentSql(column: $this->db->quoteName('state')),
            )->loadResult();

            if ($combination === null) {
                return $answer($pointer . '/combination_id', 404, 'the product has no current combination with this id.');
            }
        }

        return null;
    }

    /**
     * Write one unit in a transaction of its own: its stock records locked first (the inventory's lock order), its
     * prices judged against the state sent and replaced, its stock converged; refused whole on any refusal.
     *
     * @param array<string, mixed> $unit The unit, checked.
     * @param int $itemId The product.
     * @param int $combinationId The combination; 0 for the product itself.
     * @param string $pointer The unit's JSON pointer.
     *
     * @return array<string, mixed> The unit's answer.
     *
     * @since  2.2.2
     */
    private function writeUnit(array $unit, int $itemId, int $combinationId, string $pointer): array
    {
        $refused = static fn (string $at, int $status, string $title): array => self::refused($at, $status, $title, $itemId, $combinationId);
        $inventory = new InventoryService();
        $stocked = \array_key_exists('stock', $unit) || \array_key_exists('inventory_stock', $unit);
        $at = $pointer;

        $this->db->transactionStart(true);

        try {
            if ($stocked) {
                $inventory->lockItemStock(itemId: $itemId);
            }

            if (\array_key_exists('prices', $unit)) {
                $at = $pointer . '/prices';
                $this->writePrices(unit: $unit, itemId: $itemId, combinationId: $combinationId);
            }

            if ($stocked) {
                $at = $pointer . (\array_key_exists('stock', $unit) ? '/stock' : '/inventory_stock');
                $targets = \array_key_exists('stock', $unit)
                    ? $this->plainTarget(itemId: $itemId, combinationId: $combinationId, figure: (string) $unit['stock'], inventory: $inventory)
                    : array_map(static fn (mixed $figure): string => trim((string) $figure), (array) $unit['inventory_stock']);

                foreach ($targets as $locationId => $figure) {
                    $applied = $inventory->adjustTo(
                        itemId: $itemId,
                        combinationId: $combinationId > 0 ? $combinationId : null,
                        locationId: (int) $locationId,
                        target: (float) $figure,
                        sourceType: 'catalog_edit',
                        sourceId: $itemId,
                        context: InventoryContext::ambient(),
                    );

                    if ($applied === null) {
                        throw new UnitBatchRefusal(422, $combinationId > 0
                            ? 'this combination does not track stock.'
                            : 'this product does not keep stock of its own: it does not track stock, or its stock is kept on its combinations.');
                    }
                }
            }

            TransactionState::commitLevel(db: $this->db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $this->db, failure: $e);

            return match (true) {
                $e instanceof UnitBatchRefusal => $refused($at, $e->status, $e->getMessage()),
                $e instanceof PricesStateChanged => $refused($pointer . '/prices_state', 409, 'the unit\'s prices changed since you read them (prices_state). Read them again.'),
                $e instanceof InventoryReservedFloorException, $e instanceof InventoryIntegrityException => $refused($at, 409, $e->operatorMessage()),
                $e instanceof InventoryUnitException => $refused($at, 422, $combinationId > 0 ? 'this combination does not track stock.' : 'this product does not track stock.'),
                $e instanceof InventoryProjectionMismatchException => $refused($at, 409, 'the unit\'s stock figures disagree with its stock records; the shop repairs them first.'),
                // The services' own refusals (a place deleted since, a location that takes no stock); never the database's.
                $e instanceof RuntimeException && !str_starts_with($e::class, 'Joomla\\Database\\') && !TransactionState::endedBy(db: $this->db, failure: $e)
                    => $refused($at, 422, $e->getMessage()),
                default => $this->failed(at: $at, itemId: $itemId, combinationId: $combinationId, failure: $e),
            };
        }

        return ['pointer' => $pointer, 'status' => 200, 'item_id' => $itemId, 'combination_id' => $combinationId];
    }

    /**
     * Replace the unit's own price rows, judged first against the state sent, and record the change: the product
     * marked owed a rebuild and its commerce change time moved, in the unit's transaction.
     *
     * @param array<string, mixed> $unit The unit, checked.
     * @param int $itemId The product.
     * @param int $combinationId The combination; 0 for the product itself.
     *
     * @throws PricesStateChanged When the unit's prices changed since the state sent was read.
     * @throws RuntimeException When a row names a place deleted since.
     *
     * @since  2.2.2
     */
    private function writePrices(array $unit, int $itemId, int $combinationId): void
    {
        if (\is_string($unit['prices_state'] ?? null)) {
            PricesState::assertUnchanged(db: $this->db, itemId: $itemId, expected: $unit['prices_state'], combinationId: $combinationId);
        }

        $rows = (array) $unit['prices'];

        // Marked before the rows change, by the statement the item save uses: never committed unmarked.
        ProjectionManager::mark(itemIds: [$itemId]);

        if ($combinationId > 0) {
            $written = ItemFieldsAdminHelper::replaceCombinationPrices(
                itemId: $itemId,
                combinationId: $combinationId,
                rows: array_map(static fn (array $row): array => ['_mode' => $row['mode'] ?? 'price'] + $row, $rows),
            );

            if (!$written) {
                throw new UnitBatchRefusal(404, 'the product has no current combination with this id.');
            }
        } else {
            $widened = PriceRowPlaces::widenedFromDeleted(itemId: $itemId, combinationId: null, rows: $rows);

            if ($widened !== []) {
                throw new UnitBatchRefusal(422, PriceRowPlaces::refusal(widened: $widened));
            }

            $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()->createModel('Item', 'Administrator', ['ignore_request' => true]);

            if (!$model instanceof ItemModel) {
                throw new LogicException('The product model of com_alfa is not available.');
            }

            $model->setPrices($itemId, $rows);
        }

        CommerceChange::mark(db: $this->db, itemIds: [$itemId]);
    }

    /**
     * The one location a plain stock figure is for: the location the unit is stocked at, or the shop's default
     * location for a unit with no stock record yet. A unit stocked at more than one location is refused: its figure
     * would not say which.
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination; 0 for the product itself.
     * @param string $figure The figure.
     * @param InventoryService $inventory The inventory service.
     *
     * @return array<int, string> Location id => figure.
     *
     * @throws UnitBatchRefusal When the unit is stocked at more than one location.
     *
     * @since  2.2.2
     */
    private function plainTarget(int $itemId, int $combinationId, string $figure, InventoryService $inventory): array
    {
        $locations = array_keys((new InventoryAvailabilityService())->locationBreakdown(itemId: $itemId, combinationId: $combinationId > 0 ? $combinationId : null));

        if (\count($locations) > 1) {
            throw new UnitBatchRefusal(422, 'the unit is stocked at ' . \count($locations) . ' locations: send inventory_stock, an object of location id to figure.');
        }

        return [$locations[0] ?? $inventory->defaultLocationId() => trim($figure)];
    }

    /**
     * Each unit written given its figures as they stand after the batch: its prices_state, stock and available
     * quantity.
     *
     * @param list<array<string, mixed>> $answers The answers.
     *
     * @return list<array<string, mixed>>
     *
     * @since  2.2.2
     */
    private function withFigures(array $answers): array
    {
        $units = [];

        foreach ($answers as $answer) {
            if ($answer['status'] === 200) {
                $units[$answer['item_id']][$answer['combination_id']] = true;
            }
        }

        if ($units === []) {
            return $answers;
        }

        $states = PricesState::ofUnits(units: $units);
        $figures = [];
        $combinations = [];

        foreach ($units as $itemId => $ids) {
            foreach (array_keys($ids) as $combinationId) {
                if ($combinationId > 0) {
                    $combinations[] = (int) $combinationId;
                }
            }
        }

        foreach ($this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName(['id', 'stock', 'available_quantity']))
                ->from($this->db->quoteName('#__alfa_items'))
                ->whereIn($this->db->quoteName('id'), array_map('intval', array_keys($units))),
        )->loadObjectList() ?: [] as $row) {
            $figures[(int) $row->id . ':0'] = $row;
        }

        if ($combinations !== []) {
            foreach ($this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName(['id', 'item_id', 'stock', 'available_quantity']))
                    ->from($this->db->quoteName('#__alfa_item_combinations'))
                    ->whereIn($this->db->quoteName('id'), $combinations),
            )->loadObjectList() ?: [] as $row) {
                $figures[(int) $row->item_id . ':' . (int) $row->id] = $row;
            }
        }

        foreach ($answers as &$answer) {
            if ($answer['status'] !== 200) {
                continue;
            }

            $key = $answer['item_id'] . ':' . $answer['combination_id'];
            $answer['prices_state'] = $states[$key] ?? null;
            $answer['stock'] = isset($figures[$key]->stock) ? (string) $figures[$key]->stock : null;
            $answer['available_quantity'] = isset($figures[$key]->available_quantity) ? (string) $figures[$key]->available_quantity : null;
        }

        unset($answer);

        return $answers;
    }

    /**
     * A unit that failed for a reason that is no refusal: logged with the reason, answered 500 without it.
     *
     * @param string $at The JSON pointer.
     * @param int $itemId The product.
     * @param int $combinationId The combination.
     * @param Throwable $failure The failure.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    private function failed(string $at, int $itemId, int $combinationId, Throwable $failure): array
    {
        Log::add('The batch write of unit ' . $itemId . ':' . $combinationId . ' failed: ' . $failure::class . ': ' . $failure->getMessage(), Log::ERROR, 'com_alfa.catalog');

        return self::refused($at, 500, 'the unit could not be written; nothing of it was saved. The shop\'s log names the reason.', $itemId, $combinationId);
    }

    /**
     * A unit's refusal.
     *
     * @param string $pointer The JSON pointer of what was refused.
     * @param int $status The HTTP status the refusal would have alone.
     * @param string $title Why, and what to send.
     * @param int $itemId The product, when it is known.
     * @param int $combinationId The combination, when it is known.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.2
     */
    private static function refused(string $pointer, int $status, string $title, int $itemId = 0, int $combinationId = 0): array
    {
        return [
            'pointer' => $pointer,
            'status' => $status,
            'item_id' => $itemId,
            'combination_id' => $combinationId,
            'title' => ucfirst($title) . ' Nothing of this unit was saved.',
        ];
    }

    /**
     * Whether a value is an id: a whole number, above 0 when it must be.
     *
     * @param mixed $value The value.
     * @param bool $positive Whether 0 is refused.
     *
     * @since  2.2.2
     */
    private static function id(mixed $value, bool $positive): bool
    {
        $text = \is_int($value) ? (string) $value : (\is_string($value) ? $value : '');

        return preg_match($positive ? '/^[1-9][0-9]{0,9}$/D' : '/^(0|[1-9][0-9]{0,9})$/D', $text) === 1;
    }

    /**
     * Whether a value is a stock figure: a number of 0 or more, as a number or as text.
     *
     * @param mixed $value The value.
     *
     * @since  2.2.2
     */
    private static function figure(mixed $value): bool
    {
        if (!\is_int($value) && !\is_float($value) && !\is_string($value)) {
            return false;
        }

        $text = trim((string) $value);

        return preg_match('/^[0-9]{1,12}(\.[0-9]{1,4})?$/D', $text) === 1;
    }

    /**
     * A key as a JSON pointer's segment (RFC 6901: ~ as ~0, / as ~1), its first 64 characters.
     *
     * @param string $key The key.
     *
     * @since  2.2.2
     */
    private static function segment(string $key): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], mb_substr($key, 0, 64, 'UTF-8'));
    }
}
