<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Model\ItemModel;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryCommands;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryContext;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * A product saved with its graph in one transaction, in the order of resolution ItemWriteService states: the item
 * fields' values, the product, its options and specifications, its combinations, their stock. Each part is written by
 * the service that owns it; the first refusal rolls everything back.
 *
 * @internal ItemWriteService::save() runs it; a caller builds the write on ItemWriteService.
 *
 * @since  2.3.0
 */
final class ItemGraphWrite
{
    /**
     * What each part wrote, for the result.
     *
     * @var array{field_values: list<array>, options: list<array>, informational: ?list<int>, combinations: list<array>, stock: list<array>}
     * @since  2.3.0
     */
    private array $written = ['field_values' => [], 'options' => [], 'informational' => null, 'combinations' => [], 'stock' => []];

    /**
     * @param ItemWriteService $write The write.
     * @param ItemModel $model The item model the product is saved on.
     * @param array $graph The write's graph.
     * @param string $origin admin, api or sync.
     *
     * @since  2.3.0
     */
    public function __construct(
        private readonly ItemWriteService $write,
        private readonly ItemModel $model,
        private readonly array $graph,
        private readonly string $origin,
    ) {
    }

    /**
     * Write the graph.
     *
     * @param array $tasksBefore The background tasks the request had left before the save.
     *
     * @since  2.3.0
     */
    public function save(array $tasksBefore): ItemWriteResult
    {
        $db = TransactionState::driver(db: Factory::getContainer()->get(DatabaseInterface::class));
        $id = $this->write->id();

        // The language tables every part writes exist before the transaction: DDL commits implicitly.
        MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_items', primaryColumnName: 'id_item', data: $this->write->form());
        ItemFieldsAdminHelper::ensureCombinationLangSchema();

        foreach ($this->graph['values'] as $step) {
            MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_item_field_values', primaryColumnName: 'id_item_field_value', data: (array) $step['fields']);
        }

        // The product is locked and marked before the transaction (a new one once its row exists), and rebuilt once
        // after the commit: the parts inside leave their rebuilds to it.
        $change = $id > 0 ? ProjectionManager::markChange(itemId: $id) : null;
        $refused = [];
        $committed = false;

        try {
            $db->transactionStart(true);

            try {
                $refused = $this->values();

                if ($refused === []) {
                    $saved = $this->model->write(write: $this->write);

                    if (!$saved) {
                        $refused = $this->productRefusals();
                    } else {
                        $id = $id > 0 ? $id : (int) $this->model->getState($this->model->getName() . '.id');
                        $change ??= ProjectionManager::markChange(itemId: $id);
                        $refused = $this->options(itemId: $id);
                        $stock = [];

                        if ($refused === []) {
                            $refused = $this->combinations(itemId: $id, stock: $stock);
                        }

                        if ($refused === [] && $stock !== []) {
                            $refused = $this->stock(itemId: $id, lines: $stock);
                        }
                    }
                }

                if ($refused !== []) {
                    TransactionState::rollBackLevel(db: $db);
                } else {
                    TransactionState::commitLevel(db: $db);
                    $committed = true;
                }
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $db, failure: $e);

                throw $e;
            }
        } catch (RuntimeException $e) {
            Log::add('Product ' . $id . ' and its graph were not saved: ' . ErrorText::of($e), Log::ERROR, 'com_alfa.catalog');
            // Another write held the same rows: the request is to be sent again, as the product's own save says it.
            $refused = [MysqlError::busy(error: $e)
                ? new Refusal(code: 'busy', sentence: Text::_('COM_ALFA_ITEM_SAVE_BUSY'), status: Refusal::CONFLICT)
                : new Refusal(code: 'refused', sentence: ErrorText::of($e), status: Refusal::CONFLICT)];
        } finally {
            if ($change !== null) {
                $committed ? ProjectionManager::rebuildChanged(change: $change) : ProjectionManager::leaveChanged(change: $change);
            }
        }

        return new ItemWriteResult(
            saved: $committed,
            id: $committed ? $id : $this->write->id(),
            refusals: array_map(static fn (Refusal $refusal): string => $refusal->placed(), $refused),
            warnings: $this->write->warnings(),
            tasks: array_values(array_map('intval', array_keys(array_diff_key(Tasks::requestNotes(), $tasksBefore)))),
            written: $committed ? $this->written : [],
            refused: $refused,
        );
    }

    /**
     * The item fields' values: each created, or changed when its field already has a value with the code sent.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function values(): array
    {
        foreach ($this->graph['values'] as $step) {
            $fieldId = ItemFieldValueService::fieldId(field: $step['field']);

            if ($fieldId === null) {
                return [self::unknownField(field: $step['field'], pointer: $step['pointer'] . '/field')];
            }

            $code = trim((string) ($step['fields']['code'] ?? ''));
            $existing = $code === '' ? null : ItemFieldValueService::byCode(fieldId: $fieldId, code: $code);
            $write = ItemFieldValueService::of(fieldId: $fieldId);
            $result = ($existing === null ? $write->create(fields: $step['fields'], pointer: $step['pointer'])
                : $write->change(valueId: $existing, fields: $step['fields'], pointer: $step['pointer']))->save();

            if (!$result->saved) {
                return $result->refusals;
            }

            $this->written['field_values'][] = ['pointer' => $step['pointer'], 'field_id' => $fieldId, 'id' => $existing ?? (int) ($result->created[0] ?? 0), 'created' => $existing === null];
        }

        return [];
    }

    /**
     * The product's options (added, or their price change set) and its specifications (replaced).
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function options(int $itemId): array
    {
        if ($this->graph['options'] !== []) {
            $current = array_column(ItemOptionService::options(itemId: $itemId), 'value_id');
            $write = ItemOptionService::of(itemId: $itemId);

            foreach ($this->graph['options'] as $step) {
                $valueId = $this->valueId(value: $step['value'], pointer: $step['pointer'] . '/value');

                if ($valueId instanceof Refusal) {
                    return [$valueId];
                }

                $created = !\in_array($valueId, $current, true);
                $created
                    ? $write->add(valueId: $valueId, operation: $step['operation'], amount: $step['amount'], applyOn: $step['apply_on'], pointer: $step['pointer'])
                    : $write->change(valueId: $valueId, operation: $step['operation'], amount: $step['amount'], applyOn: $step['apply_on'], pointer: $step['pointer']);
                $current[] = $valueId;
                $this->written['options'][] = ['pointer' => $step['pointer'], 'value_id' => $valueId, 'created' => $created];
            }

            $result = $write->save();

            if (!$result->saved) {
                return $result->refusals;
            }
        }

        if ($this->graph['informational'] !== null) {
            $ids = [];

            foreach ($this->graph['informational']['values'] as $n => $value) {
                $valueId = $this->valueId(value: $value, pointer: $this->graph['informational']['pointer'] . '/' . $n);

                if ($valueId instanceof Refusal) {
                    return [$valueId];
                }

                $ids[] = $valueId;
            }

            $result = ItemOptionService::of(itemId: $itemId)->informational(valueIds: $ids, pointer: $this->graph['informational']['pointer'])->save();

            if (!$result->saved) {
                return $result->refusals;
            }

            $this->written['informational'] = $ids;
        }

        return [];
    }

    /**
     * The product's combinations: each created by its values, or changed when the product already has one with those
     * values; their stock figures collected for the stock step.
     *
     * @param list<array> $stock The stock lines, added to.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function combinations(int $itemId, array &$stock): array
    {
        foreach ($this->graph['combinations'] as $step) {
            $values = [];

            foreach ($step['values'] as $field => $value) {
                $at = $step['pointer'] . '/values/' . $field;
                $fieldId = ItemFieldValueService::fieldId(field: \is_int($field) ? $field : (string) $field);

                if ($fieldId === null) {
                    return [self::unknownField(field: $field, pointer: $at)];
                }

                // A number names a value by its id, text by its code: a code made of digits ("512") is a code.
                $valueId = $this->valueId(value: \is_int($value) ? $value : ['field' => $fieldId, 'code' => (string) $value], pointer: $at);

                if ($valueId instanceof Refusal) {
                    return [$valueId];
                }

                $values[$fieldId] = $valueId;
            }

            $existing = self::combinationWith(itemId: $itemId, valueIds: array_values($values));
            $write = $existing === null
                ? CombinationService::create(itemId: $itemId)->values(values: $values)
                : CombinationService::change(itemId: $itemId, combinationId: $existing);
            $write->with(fields: $step['fields'])->from(origin: $this->origin);

            if ($step['prices'] !== null) {
                $write->prices(rows: $step['prices']);
            }

            $result = $write->save();

            if (!$result->saved) {
                return array_map(static fn (Refusal $refusal): Refusal => $refusal->at(pointer: $step['pointer'] . $refusal->pointer), $result->refusals);
            }

            foreach ($result->warnings as $warning) {
                $this->write->warn($warning['message'], $warning['type']);
            }

            $this->written['combinations'][] = ['pointer' => $step['pointer'], 'id' => $result->id, 'created' => $existing === null];

            foreach ($step['stock'] as $n => $line) {
                $stock[] = ['combination' => $result->id, 'location' => (int) $line['location_id'], 'quantity' => (float) $line['quantity'], 'pointer' => $step['pointer'] . '/stock/' . $n];
            }
        }

        return [];
    }

    /**
     * The combinations' stock figures, set in one adjustment as the stock editors set them.
     *
     * @param list<array{combination: int, location: int, quantity: float, pointer: string}> $lines The lines.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function stock(int $itemId, array $lines): array
    {
        $command = InventoryCommands::adjust()
            ->source(type: match ($this->origin) {
                'api' => 'api',
                'sync' => 'sync',
                default => 'catalog_edit',
            }, id: $itemId)
            ->by(context: InventoryContext::ambient());

        foreach ($lines as $line) {
            $command->set(itemId: $itemId, combinationId: $line['combination'], locationId: $line['location'], quantity: $line['quantity']);
        }

        $result = $command->apply();

        if (!$result->applied) {
            // A line's refusal points at the line as the adjustment numbers it (/lines/n): the request's place instead.
            return array_map(static function (Refusal $refusal) use ($lines): Refusal {
                if (preg_match('#^/lines/(\d+)(.*)$#', $refusal->pointer, $m) === 1 && isset($lines[(int) $m[1]])) {
                    return $refusal->at(pointer: $lines[(int) $m[1]]['pointer'] . $m[2]);
                }

                return $refusal;
            }, $result->refusals);
        }

        foreach ($result->lines as $n => $line) {
            $this->written['stock'][] = ['pointer' => $lines[$n]['pointer'] ?? ''] + $line;
        }

        return [];
    }

    /**
     * The product's own refusals (the item save's), each a refusal of the request as a whole; a SKU in use and a save
     * that met another write of the same rows keep their own place and status.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function productRefusals(): array
    {
        $refusals = [];

        foreach ($this->model->getErrors() as $error) {
            if ($error instanceof SkuInUseException || $error instanceof ItemSaveBusyException) {
                $refusals[] = $error->refusal;
            }
        }

        return $refusals !== [] ? $refusals : array_map(
            static fn (string $sentence): Refusal => new Refusal(code: 'refused', sentence: $sentence),
            ItemWriteService::errorsOf(model: $this->model) ?: [Text::_('JLIB_APPLICATION_ERROR_SAVE_FAILED')],
        );
    }

    /**
     * A value named by its id, or by its field (id or field_name) and code; a refusal when none is.
     *
     * @param int|array $value The value.
     *
     * @since  2.3.0
     */
    private function valueId(int|array $value, string $pointer): int|Refusal
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : new Refusal(code: 'value_unknown', sentence: Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_NOT_FOUND', (string) $value), pointer: $pointer);
        }

        $fieldId = ItemFieldValueService::fieldId(field: $value['field'] ?? 0);

        if ($fieldId === null) {
            return self::unknownField(field: $value['field'] ?? '', pointer: $pointer . '/field');
        }

        $valueId = ItemFieldValueService::byCode(fieldId: $fieldId, code: (string) ($value['code'] ?? ''));

        return $valueId ?? new Refusal(code: 'value_unknown', sentence: Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_CODE_UNKNOWN', (string) ($value['code'] ?? '')), pointer: $pointer . '/code');
    }

    /**
     * The product's current combination with exactly these values; null for none.
     *
     * @param list<int> $valueIds The values.
     *
     * @since  2.3.0
     */
    private static function combinationWith(int $itemId, array $valueIds): ?int
    {
        sort($valueIds);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $id = $db->setQuery(
            'SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_item_combinations') . ' WHERE ' . $db->quoteName('item_id') . ' = ' . $itemId
            . ' AND ' . $db->quoteName('signature') . ' = ' . $db->quote(implode('-', $valueIds)) . ' AND ' . CombinationState::currentSql(column: $db->quoteName('state')),
        )->loadResult();

        return $id === null ? null : (int) $id;
    }

    /**
     * The refusal of a field that does not exist.
     *
     * @since  2.3.0
     */
    private static function unknownField(int|string $field, string $pointer): Refusal
    {
        return new Refusal(code: 'field_unknown', sentence: Text::sprintf('COM_ALFA_ITEMFIELD_NAMED_NOT_FOUND', (string) $field), pointer: $pointer);
    }
}
