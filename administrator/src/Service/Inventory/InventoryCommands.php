<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Inventory;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Api\IdempotencyKeys;
use Alfa\Component\Alfa\Administrator\Service\Api\IdempotencyRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SkuIndex;
use Alfa\Component\Alfa\Administrator\Service\Catalog\SkuUnit;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * The one way stock records are set or corrected: the administrator's stock editors (the item save, the list's Stock
 * cell, the variants' grid and the variant's own window) and the REST API's adjustments build the same command.
 *
 *     $result = InventoryCommands::adjust()
 *         ->set(itemId: 42, combinationId: 304, locationId: 1, quantity: 7)
 *         ->delta(itemId: 42, combinationId: 301, locationId: 1, quantity: -2)
 *         ->by(new InventoryContext(userId: 5, note: 'Stock take'))
 *         ->apply();
 *
 * A set line is an absolute figure the record converges to (InventoryService::adjustTo()), a delta line a signed change
 * (InventoryService::adjust()). apply() judges every set line first, as the editors do (validateTargets(): the archive,
 * the unit's tracking, the integrity holds, the location, the reservation floor, the physical floor); then applies
 * every line in one transaction, the products' stock records locked first in ascending order and the lines in
 * (product, combination, location) order, so a stock take lands whole or not at all. The answer is an
 * InventoryAdjustment: the lines applied, or the refusals that stopped them, nothing written.
 *
 * @api CHANGE STOCK: set or correct stock records with the stock editors' rules, in one transaction.
 *
 * @since  2.3.0
 */
final class InventoryCommands
{
    /**
     * The keys a REST line takes.
     *
     * @since  2.3.0
     */
    public const LINE_KEYS = ['sku', 'item_id', 'combination_id', 'location_id', 'set', 'delta'];

    /**
     * The most lines one adjustment takes.
     *
     * @since  2.3.0
     */
    public const MAX_LINES = 500;

    /**
     * The lines: line, pointer, item, combination (0 the product), location, mode (set or delta), quantity.
     *
     * @var list<array{line: int, pointer: string, item: int, combination: int, location: int, mode: string, quantity: float}>
     * @since  2.3.0
     */
    private array $lines = [];

    /**
     * Refusals found while the lines were read.
     *
     * @var list<Refusal>
     * @since  2.3.0
     */
    private array $refusals = [];

    /**
     * Keys of a line the adjustment does not take.
     *
     * @var list<array{code: string, title: string, pointer: string}>
     * @since  2.3.0
     */
    private array $warnings = [];

    private string $sourceType = 'manual';

    private int $sourceId = 0;

    private ?InventoryContext $context = null;

    /**
     * The Idempotency-Key the adjustment is applied once under: user, key, request.
     *
     * @var array{0: int, 1: string, 2: array}|null
     * @since  2.3.0
     */
    private ?array $once = null;

    /**
     * @since  2.3.0
     */
    private function __construct()
    {
    }

    /**
     * An adjustment of stock records.
     *
     * @since  2.3.0
     */
    public static function adjust(): self
    {
        return new self();
    }

    /**
     * Set a unit's stock at a location to a figure.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination; null or 0 for the product itself.
     * @param int $locationId The location.
     * @param float $quantity The figure.
     *
     * @since  2.3.0
     */
    public function set(int $itemId, ?int $combinationId, int $locationId, float $quantity): self
    {
        return $this->line(itemId: $itemId, combinationId: $combinationId, locationId: $locationId, mode: 'set', quantity: $quantity);
    }

    /**
     * Change a unit's stock at a location by a signed quantity.
     *
     * @param int $itemId The product.
     * @param int|null $combinationId The combination; null or 0 for the product itself.
     * @param int $locationId The location.
     * @param float $quantity The change, positive for goods received.
     *
     * @since  2.3.0
     */
    public function delta(int $itemId, ?int $combinationId, int $locationId, float $quantity): self
    {
        return $this->line(itemId: $itemId, combinationId: $combinationId, locationId: $locationId, mode: 'delta', quantity: $quantity);
    }

    /**
     * Lines as the REST API takes them: each names a unit by sku, or by item_id and combination_id (0 or absent for
     * the product), a location_id (the default location when absent) and either set (a figure) or delta (a signed
     * change), each a decimal number such as "7". A line that cannot be read is refused, pointing at it.
     *
     * @param array $lines The lines.
     * @param string $pointer The JSON pointer of the list in the request.
     *
     * @since  2.3.0
     */
    public function lines(array $lines, string $pointer = '/lines'): self
    {
        if ($lines === [] || !array_is_list($lines) || \count($lines) > self::MAX_LINES) {
            $this->refusals[] = new Refusal(code: 'lines_invalid', sentence: 'send a list of 1 to ' . self::MAX_LINES
                . ' lines, each {"sku": "SMX-WHT-256", "location_id": 1, "set": "7"} or {"item_id": 42, "combination_id": 304, "delta": "-2"}. '
                . Text::_('COM_ALFA_INVENTORY_NOTHING_CHANGED'), pointer: $pointer);

            return $this;
        }

        $default = null;

        foreach ($lines as $n => $line) {
            $at = $pointer . '/' . $n;

            if (!\is_array($line) || ($line !== [] && array_is_list($line))) {
                $this->refuse(code: 'line_invalid', sentence: 'send an object, {"sku": "SMX-WHT-256", "set": "7"}.', pointer: $at);

                continue;
            }

            foreach (array_diff(array_map('strval', array_keys($line)), self::LINE_KEYS) as $key) {
                $this->warnings[] = ['code' => 'not_a_field', 'title' => $key . ' is not a key of an adjustment line; it was left out.', 'pointer' => $at . '/' . $key];
            }

            $unit = $this->unitOf(line: $line, pointer: $at);
            $location = $this->locationOf(line: $line, pointer: $at, default: $default);
            $quantity = $this->quantityOf(line: $line, pointer: $at);

            if ($unit === null || $location === null || $quantity === null) {
                continue;
            }

            $this->lines[] = ['line' => $n, 'pointer' => $at, 'item' => $unit->itemId, 'combination' => $unit->combinationId, 'location' => $location,
                'mode' => $quantity[0], 'quantity' => $quantity[1]];
        }

        return $this;
    }

    /**
     * Where the adjustment comes from, recorded on its movements: catalog_edit for the stock editors, api for the
     * REST API.
     *
     * @param string $type The source's type.
     * @param int $id The source's id (the product an editor saves; 0 for none).
     *
     * @since  2.3.0
     */
    public function source(string $type, int $id = 0): self
    {
        $this->sourceType = $type;
        $this->sourceId = $id;

        return $this;
    }

    /**
     * Who adjusts, and why: the movements' user, correlation and note.
     *
     * @param InventoryContext $context The context.
     *
     * @since  2.3.0
     */
    public function by(InventoryContext $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Apply the adjustment once under a client's Idempotency-Key: the same request again answers the first one's
     * lines and applies nothing ({@see IdempotencyKeys}).
     *
     * @param int $userId The API user.
     * @param string $key The key.
     * @param array $request The request's body.
     *
     * @since  2.3.0
     */
    public function once(int $userId, string $key, array $request): self
    {
        $this->once = [$userId, $key, $request];

        return $this;
    }

    /**
     * Judge every line, then apply them all in one transaction, or none.
     *
     * @since  2.3.0
     */
    public function apply(): InventoryAdjustment
    {
        if ($this->refusals !== []) {
            return new InventoryAdjustment(applied: false, refusals: $this->refusals, warnings: $this->warnings);
        }

        if ($this->lines === [] && $this->once === null) {
            return new InventoryAdjustment(applied: true, warnings: $this->warnings);
        }

        $service = new InventoryService();
        $problems = $this->dryRun(service: $service);

        if ($problems !== []) {
            return new InventoryAdjustment(
                applied: false,
                refusals: array_map(fn (array $problem): Refusal => self::problemRefusal(problem: $problem), $problems),
                problems: array_map(static fn (array $problem): array => ['line' => $problem['line'], 'item_id' => $problem['item'],
                    'combination_id' => $problem['combination'], 'problem' => $problem['problem']], $problems),
                warnings: $this->warnings,
            );
        }

        $db = TransactionState::driver(db: Factory::getContainer()->get(DatabaseInterface::class));
        $context = $this->context ?? InventoryContext::ambient();
        $ordered = $this->lines;
        usort($ordered, static fn (array $a, array $b): int => [$a['item'], $a['combination'], $a['location'], $a['line']]
            <=> [$b['item'], $b['combination'], $b['location'], $b['line']]);
        $applied = [];
        $current = null;

        $db->transactionStart(true);

        try {
            if ($this->once !== null) {
                $replay = IdempotencyKeys::claim(userId: $this->once[0], scope: 'inventory.adjustments', key: $this->once[1], request: $this->once[2]);

                if ($replay !== null) {
                    TransactionState::rollBackLevel(db: $db);

                    return new InventoryAdjustment(applied: true, lines: array_values((array) ($replay['lines'] ?? [])), replayed: true, warnings: $this->warnings);
                }
            }

            $items = array_values(array_unique(array_column($ordered, 'item')));
            sort($items);

            foreach ($items as $itemId) {
                $service->lockItemStock(itemId: $itemId);
            }

            foreach ($ordered as $line) {
                $current = $line;
                $before = self::recorded(db: $db, line: $line);
                $combinationId = $line['combination'] > 0 ? $line['combination'] : null;

                if ($line['mode'] === 'set') {
                    $result = $service->adjustTo(
                        itemId: $line['item'],
                        combinationId: $combinationId,
                        locationId: $line['location'],
                        target: $line['quantity'],
                        sourceType: $this->sourceType,
                        sourceId: $this->sourceId,
                        context: $context,
                    );

                    // No stock unit to converge: archived, grown variants or stopped tracking since the dry run.
                    if ($result === null) {
                        TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('A line names no stock unit any more.'));

                        return new InventoryAdjustment(
                            applied: false,
                            refusals: [self::unitRefusal(code: 'identity_gone', itemId: $line['item'], combinationId: $line['combination'], pointer: $line['pointer'])],
                            failedLine: $line['line'],
                            unapplied: true,
                            warnings: $this->warnings,
                        );
                    }

                    $delta = (float) $result['delta'];
                    $operationId = $result['operation_id'];
                } else {
                    $result = $service->adjust(
                        itemId: $line['item'],
                        combinationId: $combinationId,
                        quantityDelta: $line['quantity'],
                        locationId: $line['location'],
                        sourceType: $this->sourceType,
                        sourceId: $this->sourceId,
                        context: $context,
                    );
                    $delta = $line['quantity'];
                    $operationId = $result['operation_id'];
                }

                $applied[$line['line']] = ['line' => $line['line'], 'item_id' => $line['item'], 'combination_id' => $line['combination'],
                    'location_id' => $line['location'], 'before' => $before, 'after' => $before + $delta, 'delta' => $delta, 'operation_id' => $operationId];
            }

            ksort($applied);
            $applied = array_values($applied);

            if ($this->once !== null) {
                IdempotencyKeys::complete(userId: $this->once[0], scope: 'inventory.adjustments', key: $this->once[1], result: ['lines' => $applied]);
            }

            // What the inventory service announces for these lines waits for this commit.
            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            return new InventoryAdjustment(
                applied: false,
                refusals: [self::failureRefusal(failure: $e, line: $current)],
                failure: $e,
                failedLine: $current['line'] ?? null,
                warnings: $this->warnings,
            );
        }

        return new InventoryAdjustment(applied: true, lines: $applied, warnings: $this->warnings);
    }

    /**
     * A refusal of the inventory service's dry run, worded as the stock editors word it: the location, the integrity
     * of the unit's figures, the reservation floor. Null for the unit-wide codes each editor words itself (the archive,
     * a unit gone, a unit that does not track stock).
     *
     * @param int $itemId The product.
     * @param int $combinationId The combination; 0 for the product.
     * @param array $problem The problem, as validateTargets() states it.
     * @param array<int, string> $titles The locations' titles by id.
     *
     * @since  2.3.0
     */
    public static function problemText(int $itemId, int $combinationId, array $problem, array $titles): ?string
    {
        $locationId = (int) ($problem['location_id'] ?? 0);
        $location = (string) ($titles[$locationId] ?? ('#' . $locationId));

        return match ((string) ($problem['code'] ?? '')) {
            'not_a_location' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NOT_A_LOCATION', '#' . $locationId),
            'location_disabled' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_LOCATION_DISABLED', $location),
            'negative_floor' => Text::sprintf('COM_ALFA_INVENTORY_TARGET_NEGATIVE', $location),
            // The unit's figures as the inventory service reports them: the ones it shows without stock records, or
            // those it showed when its hold began.
            'ledger_missing', 'integrity_held' => InventoryIntegrityException::refusalText(
                stock: isset($problem['stock']) ? (float) $problem['stock'] : null,
                available: isset($problem['available']) ? (float) $problem['available'] : null,
                held: $problem['code'] === 'integrity_held',
            ),
            // Below what open orders reserve there: the service's own sentence, names escaped once.
            'reserved_floor' => htmlspecialchars(string: InventoryReservedFloorException::refusalText(
                itemId: $itemId,
                combinationId: $combinationId,
                locationId: $locationId,
                onHand: (float) $problem['on_hand'],
                reserved: (float) $problem['reserved'],
                requested: (float) $problem['requested'],
            ), flags: ENT_QUOTES, encoding: 'UTF-8'),
            default => null,
        };
    }

    /**
     * The stock locations' titles, by id.
     *
     * @return array<int, string>
     *
     * @since  2.3.0
     */
    public static function locationTitles(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return array_map('strval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'title']))
                ->from($db->quoteName('#__alfa_inventory_locations')),
        )->loadAssocList('id', 'title') ?: []);
    }

    /**
     * One line.
     *
     * @since  2.3.0
     */
    private function line(int $itemId, ?int $combinationId, int $locationId, string $mode, float $quantity): self
    {
        $n = \count($this->lines);
        $this->lines[] = ['line' => $n, 'pointer' => '/lines/' . $n, 'item' => $itemId, 'combination' => max(0, (int) $combinationId),
            'location' => $locationId, 'mode' => $mode, 'quantity' => $quantity];

        return $this;
    }

    /**
     * The inventory service's dry run of every set line, unit by unit, and of every delta line's unit: the problems,
     * each with its line.
     *
     * @return list<array{line: int, pointer: string, item: int, combination: int, problem: array}>
     *
     * @since  2.3.0
     */
    private function dryRun(InventoryService $service): array
    {
        $units = [];

        foreach ($this->lines as $line) {
            $key = $line['item'] . ':' . $line['combination'];
            $units[$key] ??= ['item' => $line['item'], 'combination' => $line['combination'], 'targets' => [], 'lines' => []];

            if ($line['mode'] === 'set') {
                $units[$key]['targets'][$line['location']] = $line['quantity'];
            }

            $units[$key]['lines'][$line['location']] ??= $line;
        }

        $problems = [];

        foreach ($units as $unit) {
            foreach ($service->validateTargets(
                itemId: $unit['item'],
                combinationId: $unit['combination'] > 0 ? $unit['combination'] : null,
                targets: $unit['targets'],
                assumeTracked: false,
            ) as $problem) {
                $line = $unit['lines'][(int) ($problem['location_id'] ?? 0)] ?? reset($unit['lines']);
                $problems[] = ['line' => $line['line'], 'pointer' => $line['pointer'], 'item' => $unit['item'], 'combination' => $unit['combination'], 'problem' => $problem];
            }
        }

        usort($problems, static fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $problems;
    }

    /**
     * The unit a REST line names.
     *
     * @since  2.3.0
     */
    private function unitOf(array $line, string $pointer): ?SkuUnit
    {
        $bySku = \array_key_exists('sku', $line);
        $byId = \array_key_exists('item_id', $line);

        if ($bySku === $byId) {
            $this->refuse(code: 'unit_invalid', sentence: 'name the unit by sku, or by item_id and combination_id (0 or left out for the product), not both.', pointer: $pointer);

            return null;
        }

        if ($bySku) {
            if (\array_key_exists('combination_id', $line)) {
                $this->refuse(code: 'unit_invalid', sentence: 'a SKU names its unit; leave combination_id out.', pointer: $pointer . '/combination_id');

                return null;
            }

            if (!\is_string($line['sku']) || !SkuIndex::wellFormed(sku: trim($line['sku']))) {
                $this->refuse(code: 'sku_invalid', sentence: Text::_('COM_ALFA_SKU_INVALID'), pointer: $pointer . '/sku');

                return null;
            }

            $unit = SkuIndex::unit(sku: $line['sku']);

            if ($unit instanceof Refusal) {
                $this->refusals[] = $unit->at($pointer . '/sku');

                return null;
            }

            return $unit;
        }

        $itemId = self::positiveId(value: $line['item_id']);
        $combinationId = \array_key_exists('combination_id', $line) && $line['combination_id'] !== null && $line['combination_id'] !== 0
            ? self::positiveId(value: $line['combination_id'])
            : 0;

        if ($itemId === null) {
            $this->refuse(code: 'unit_invalid', sentence: 'send the product\'s id, a whole number above 0.', pointer: $pointer . '/item_id');

            return null;
        }

        if ($combinationId === null) {
            $this->refuse(code: 'unit_invalid', sentence: 'send the combination\'s id, a whole number above 0, or 0 for the product.', pointer: $pointer . '/combination_id');

            return null;
        }

        return new SkuUnit(itemId: $itemId, combinationId: $combinationId);
    }

    /**
     * The location a REST line names; the default location when it names none.
     *
     * @since  2.3.0
     */
    private function locationOf(array $line, string $pointer, ?int &$default): ?int
    {
        if (!\array_key_exists('location_id', $line) || $line['location_id'] === null) {
            return $default ??= (new InventoryService())->defaultLocationId();
        }

        $locationId = self::positiveId(value: $line['location_id']);

        if ($locationId === null) {
            $this->refuse(code: 'location_invalid', sentence: 'send the stock location\'s id, a whole number above 0, or leave it out for the default location.', pointer: $pointer . '/location_id');
        }

        return $locationId;
    }

    /**
     * The figure or the change of a REST line, as a decimal of at most 8 places.
     *
     * @return array{0: string, 1: float}|null set or delta, and the quantity.
     *
     * @since  2.3.0
     */
    private function quantityOf(array $line, string $pointer): ?array
    {
        $set = \array_key_exists('set', $line);
        $delta = \array_key_exists('delta', $line);

        if ($set === $delta) {
            $this->refuse(code: 'quantity_invalid', sentence: 'send either set, the figure the stock record takes, or delta, a signed change, such as "7" or "-2".', pointer: $pointer);

            return null;
        }

        $mode = $set ? 'set' : 'delta';
        $value = $line[$mode];
        $parsed = \is_string($value) || \is_int($value) || \is_float($value) ? QuantityDecimal::parse(value: \is_float($value) ? (string) $value : $value) : null;

        if ($parsed === null) {
            $this->refuse(code: 'quantity_invalid', sentence: 'send a decimal number of at most ' . QuantityDecimal::SCALE . ' places, such as "7" or "-2.5".', pointer: $pointer . '/' . $mode);

            return null;
        }

        if ($mode === 'delta' && QuantityDecimal::compare($parsed, 0) === 0) {
            $this->refuse(code: 'quantity_invalid', sentence: 'a delta of 0 changes nothing; send a signed change, such as "-2".', pointer: $pointer . '/delta');

            return null;
        }

        return [$mode, (float) $parsed];
    }

    /**
     * Note a refusal of a line as read.
     *
     * @since  2.3.0
     */
    private function refuse(string $code, string $sentence, string $pointer): void
    {
        $this->refusals[] = new Refusal(code: $code, sentence: $sentence . ' ' . Text::_('COM_ALFA_INVENTORY_NOTHING_CHANGED'), pointer: $pointer);
    }

    /**
     * A dry run's problem as a refusal of its line.
     *
     * @param array{line: int, pointer: string, item: int, combination: int, problem: array} $problem The problem.
     *
     * @since  2.3.0
     */
    private static function problemRefusal(array $problem): Refusal
    {
        $code = (string) ($problem['problem']['code'] ?? '');

        if (\in_array($code, ['item_archived', 'identity_gone', 'not_tracked'], true)) {
            return self::unitRefusal(code: $code, itemId: $problem['item'], combinationId: $problem['combination'], pointer: $problem['pointer']);
        }

        $text = self::problemText(itemId: $problem['item'], combinationId: $problem['combination'], problem: $problem['problem'], titles: self::locationTitles());

        return new Refusal(
            code: $code,
            sentence: html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8') . ' ' . Text::_('COM_ALFA_INVENTORY_NOTHING_CHANGED'),
            pointer: $problem['pointer'],
            status: $code === 'not_a_location' ? Refusal::INVALID : Refusal::CONFLICT,
        );
    }

    /**
     * A unit that takes no stock: archived, not a current unit, or not tracking stock.
     *
     * @since  2.3.0
     */
    private static function unitRefusal(string $code, int $itemId, int $combinationId, string $pointer): Refusal
    {
        $key = match ($code) {
            'item_archived' => 'COM_ALFA_INVENTORY_UNIT_ARCHIVED',
            'not_tracked' => 'COM_ALFA_INVENTORY_UNIT_NOT_TRACKED',
            default => 'COM_ALFA_INVENTORY_UNIT_GONE',
        };

        return new Refusal(
            code: $code,
            sentence: Text::sprintf($key, InventoryUnitException::labelFor(itemId: $itemId, combinationId: $combinationId)) . ' ' . Text::_('COM_ALFA_INVENTORY_NOTHING_CHANGED'),
            pointer: $pointer,
            status: Refusal::CONFLICT,
        );
    }

    /**
     * The exception that ended the transaction as a refusal of the line being applied.
     *
     * @param array|null $line The line.
     *
     * @since  2.3.0
     */
    private static function failureRefusal(Throwable $failure, ?array $line): Refusal
    {
        $pointer = (string) ($line['pointer'] ?? '');
        $nothing = ' ' . Text::_('COM_ALFA_INVENTORY_NOTHING_CHANGED');

        return match (true) {
            $failure instanceof IdempotencyRefusedException => $failure->refusal,
            $failure instanceof InventoryReservedFloorException => new Refusal(
                code: 'reserved_floor',
                sentence: $failure->operatorMessage() . $nothing,
                pointer: $pointer,
                status: Refusal::CONFLICT,
            ),
            $failure instanceof InventoryIntegrityException => new Refusal(
                code: 'ledger_missing',
                sentence: ErrorText::of($failure) . $nothing,
                pointer: $pointer,
                status: Refusal::CONFLICT,
            ),
            $failure instanceof InventoryUnitException => self::unitRefusal(
                code: $failure->reason === InventoryUnitException::ITEM_ARCHIVED ? 'item_archived' : ($failure->reason === InventoryUnitException::NOT_TRACKED ? 'not_tracked' : 'identity_gone'),
                itemId: (int) ($line['item'] ?? 0),
                combinationId: (int) ($line['combination'] ?? 0),
                pointer: $pointer,
            ),
            self::busy(failure: $failure) => new Refusal(code: 'busy', sentence: 'The stock records were busy with another change. Send the request again in a few seconds.'
                . $nothing, status: Refusal::CONFLICT),
            default => new Refusal(code: 'refused', sentence: ErrorText::of($failure) . $nothing, pointer: $pointer, status: Refusal::CONFLICT),
        };
    }

    /**
     * Whether a failure is a deadlock or a lock wait that ran out.
     *
     * @since  2.3.0
     */
    private static function busy(Throwable $failure): bool
    {
        for ($e = $failure; $e !== null; $e = $e->getPrevious()) {
            if (\in_array((int) $e->getCode(), [1205, 1213], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The quantity a unit's stock record holds at a location now (0 without a record).
     *
     * @since  2.3.0
     */
    private static function recorded(DatabaseDriver $db, array $line): float
    {
        return (float) $db->setQuery(
            'SELECT ' . $db->quoteName('quantity') . ' FROM ' . $db->quoteName('#__alfa_inventory_stock')
            . ' WHERE ' . $db->quoteName('item_id') . ' = ' . (int) $line['item']
            . ' AND ' . $db->quoteName('combination_id') . ' = ' . (int) $line['combination']
            . ' AND ' . $db->quoteName('location_id') . ' = ' . (int) $line['location'],
        )->loadResult();
    }

    /**
     * A positive whole number given as an integer or its digits.
     *
     * @since  2.3.0
     */
    private static function positiveId(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return \is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1 ? (int) $value : null;
    }
}
