<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Filter\DecimalFilter;
use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Alfa\Component\Alfa\Site\Service\Pricing\ExactAmount;
use Alfa\Component\Alfa\Site\Service\Pricing\StoredDecimal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * The one way a product's options and specifications are written: the item screen (the option and specification
 * pickers, the Options and Informational lists saved with the product), the REST API and an extension's code build the
 * same write on the product and save it.
 *
 *     $result = ItemOptionService::of(itemId: 42)
 *         ->add(valueId: 71, operation: '+', amount: '49.00', applyOn: 'final')
 *         ->change(valueId: 72, operation: '%', amount: '10')
 *         ->remove(valueIds: [73])
 *         ->order(valueIds: [72, 71])
 *         ->informational(valueIds: [80, 81])
 *         ->save();
 *
 * An option is a value of an item field the buyer picks on the product, with a price change of its own (operation +,
 * -, % or -% and an amount; none for an option that changes no price). A value becomes an option of a product when its
 * field is published, not informational, available in the product's categories and not one of the product's variant
 * dimensions, and the value is published. A specification (informational) is a published value of a published
 * informational field the product shows; informational() replaces the product's list with the values given, in their
 * order. Removing an option takes with it its option settings of the field type, the cart selections of the product's
 * lines that chose it and the related links that preselect it.
 *
 * save() writes every step in one transaction, in the order given, or nothing; the product's catalogue is rebuilt
 * after the commit (inside a caller's transaction, at the caller's commit). The caller checks its own permission.
 *
 * @api CHANGE CATALOGUE: add, change, order or remove a product's options and set its specifications.
 *
 * @since  2.3.0
 */
final class ItemOptionService
{
    /**
     * The price changes an option can make.
     *
     * @since  2.3.0
     */
    public const OPERATIONS = ['+', '-', '%', '-%'];

    /**
     * What a fixed amount changes: the price before tax (start) or after it (final); null follows the shop's setting.
     *
     * @since  2.3.0
     */
    public const APPLY_ON = ['start', 'final'];

    /**
     * No price change.
     *
     * @since  2.3.0
     */
    private const NO_DELTA = ['operation' => null, 'amount' => null, 'apply_on' => null];

    /**
     * The steps, in the order given.
     *
     * @var list<array{op: string, id: int, ids: list<int>, delta: array{operation: ?string, amount: ?string, apply_on: ?string}, pointer: string}>
     * @since  2.3.0
     */
    private array $steps = [];

    /**
     * @param int $itemId The product.
     *
     * @since  2.3.0
     */
    private function __construct(private readonly int $itemId)
    {
    }

    /**
     * The options and specifications of a product.
     *
     * @param int $itemId The product.
     *
     * @since  2.3.0
     */
    public static function of(int $itemId): self
    {
        return new self(itemId: max(0, $itemId));
    }

    /**
     * A value made an option of the product, last in its list.
     *
     * @param int $valueId The value.
     * @param string|null $operation +, -, % or -%; null for an option that changes no price.
     * @param string|null $amount The amount, an exact decimal number.
     * @param string|null $applyOn start or final for a fixed amount; null follows the shop's setting.
     * @param string $pointer Where the option stands in the caller's request (a JSON pointer), for its refusals.
     *
     * @since  2.3.0
     */
    public function add(int $valueId, ?string $operation = null, ?string $amount = null, ?string $applyOn = null, string $pointer = ''): self
    {
        $this->steps[] = ['op' => 'add', 'id' => $valueId, 'ids' => [], 'delta' => ['operation' => $operation, 'amount' => $amount, 'apply_on' => $applyOn], 'pointer' => $pointer];

        return $this;
    }

    /**
     * An option's price change set again: the three parts as given (null operation: the option changes no price).
     *
     * @param int $valueId The option's value.
     * @param string|null $operation +, -, % or -%; null for none.
     * @param string|null $amount The amount.
     * @param string|null $applyOn start, final or null.
     * @param string $pointer Where the option stands in the caller's request, for its refusals.
     *
     * @since  2.3.0
     */
    public function change(int $valueId, ?string $operation, ?string $amount, ?string $applyOn = null, string $pointer = ''): self
    {
        $this->steps[] = ['op' => 'change', 'id' => $valueId, 'ids' => [], 'delta' => ['operation' => $operation, 'amount' => $amount, 'apply_on' => $applyOn], 'pointer' => $pointer];

        return $this;
    }

    /**
     * Options removed from the product.
     *
     * @param list<int> $valueIds The options' values.
     * @param string $pointer Where they stand in the caller's request, for their refusals.
     *
     * @since  2.3.0
     */
    public function remove(array $valueIds, string $pointer = ''): self
    {
        $this->steps[] = ['op' => 'remove', 'id' => 0, 'ids' => self::ids(values: $valueIds), 'delta' => self::NO_DELTA, 'pointer' => $pointer];

        return $this;
    }

    /**
     * The options' order: the values given take the first places in that order; a value that is not an option of the
     * product is passed over.
     *
     * @param list<int> $valueIds The options' values.
     *
     * @since  2.3.0
     */
    public function order(array $valueIds): self
    {
        $this->steps[] = ['op' => 'order', 'id' => 0, 'ids' => self::ids(values: $valueIds), 'delta' => self::NO_DELTA, 'pointer' => ''];

        return $this;
    }

    /**
     * The product's specifications: the values given, in that order, replace its list.
     *
     * @param list<int> $valueIds The values.
     * @param string $pointer Where the list stands in the caller's request, for its refusals.
     *
     * @since  2.3.0
     */
    public function informational(array $valueIds, string $pointer = ''): self
    {
        $this->steps[] = ['op' => 'informational', 'id' => 0, 'ids' => self::ids(values: $valueIds), 'delta' => self::NO_DELTA, 'pointer' => $pointer];

        return $this;
    }

    /**
     * Judge every step, then write them all in one transaction, in the order given.
     *
     * @since  2.3.0
     */
    public function save(): ItemOptionResult
    {
        $db = self::db();

        if ((int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_items') . ' WHERE ' . $db->quoteName('id') . ' = ' . $this->itemId)->loadResult() === 0) {
            return new ItemOptionResult(saved: false, refusals: [new Refusal(code: 'not_found', sentence: Text::_('COM_ALFA_ITEM_STOCK_REASON_GONE'), status: Refusal::NOT_FOUND)]);
        }

        $refusals = $this->refusals(db: $db);

        if ($refusals !== []) {
            return new ItemOptionResult(saved: false, refusals: $refusals);
        }

        if ($this->steps === []) {
            return new ItemOptionResult(saved: true);
        }

        // The product's filter and search rows read its options and specifications: inside a caller's transaction the
        // product is marked for the caller's commit to rebuild; otherwise it is locked and marked now, rebuilt after.
        $nested = TransactionState::isOpen(db: $db);
        $change = $nested ? null : ProjectionManager::markChange(itemId: $this->itemId);
        $added = $changed = $removed = [];
        $selections = 0;

        try {
            $db->transactionStart(true);

            try {
                if ($nested) {
                    ProjectionManager::mark(itemIds: [$this->itemId]);
                }

                foreach ($this->steps as $step) {
                    switch ($step['op']) {
                        case 'add':
                            $added[] = $this->insert(db: $db, valueId: $step['id'], delta: $step['delta']);
                            break;
                        case 'change':
                            $changed[] = $this->update(db: $db, valueId: $step['id'], delta: $step['delta']);
                            break;
                        case 'remove':
                            $selections += $this->delete(db: $db, valueIds: $step['ids'], removed: $removed);
                            break;
                        case 'order':
                            $this->reorder(db: $db, valueIds: $step['ids']);
                            break;
                        default:
                            $this->replaceInformational(db: $db, valueIds: $step['ids']);
                    }
                }

                TransactionState::commitLevel(db: $db);
            } catch (Throwable $e) {
                TransactionState::rollBackLevel(db: $db, failure: $e);

                throw $e;
            }
        } catch (RuntimeException $e) {
            // A failure that ended the caller's transaction is the caller's to hear: a refusal would let it commit work
            // the server has already discarded.
            if ($nested && !TransactionState::isOpen(db: $db)) {
                throw $e;
            }

            Log::add('The options of item ' . $this->itemId . ' were not saved: ' . ErrorText::of($e), Log::ERROR, 'com_alfa.catalog');

            return new ItemOptionResult(saved: false, refusals: [new Refusal(code: 'refused', sentence: ErrorText::of($e), status: Refusal::CONFLICT)]);
        } finally {
            if ($change !== null) {
                ProjectionManager::rebuildChanged(change: $change);
            }
        }

        return new ItemOptionResult(saved: true, added: $added, changed: $changed, removed: $removed, cartSelectionsRemoved: $selections);
    }

    /**
     * A product's options in their order: value_id, field_id, operation, amount, apply_on, ordering.
     *
     * @param int $itemId The product.
     *
     * @return list<array{value_id: int, field_id: int, operation: ?string, amount: ?string, apply_on: ?string, ordering: int}>
     *
     * @since  2.3.0
     */
    public static function options(int $itemId): array
    {
        $db = self::db();

        return array_map(static fn (array $row): array => [
            'value_id' => (int) $row['value_id'],
            'field_id' => (int) $row['field_id'],
            'operation' => $row['operation'] === null ? null : (string) $row['operation'],
            'amount' => $row['amount'] === null ? null : DecimalFilter::text((string) $row['amount']),
            'apply_on' => $row['apply_on'] === null ? null : (string) $row['apply_on'],
            'ordering' => (int) $row['ordering'],
        ], $db->setQuery(
            'SELECT o.value_id, v.item_field_id AS field_id, o.operation, o.amount, o.apply_on, o.ordering FROM '
            . $db->quoteName('#__alfa_item_option_values', 'o') . ' INNER JOIN ' . $db->quoteName('#__alfa_item_field_values', 'v')
            . ' ON v.id = o.value_id WHERE o.item_id = ' . $itemId . ' ORDER BY o.ordering ASC, o.id ASC',
        )->loadAssocList() ?: []);
    }

    /**
     * A product's specifications in their order: value_id, field_id, ordering.
     *
     * @param int $itemId The product.
     *
     * @return list<array{value_id: int, field_id: int, ordering: int}>
     *
     * @since  2.3.0
     */
    public static function specifications(int $itemId): array
    {
        $db = self::db();

        return array_map(static fn (array $row): array => ['value_id' => (int) $row['value_id'], 'field_id' => (int) $row['field_id'], 'ordering' => (int) $row['ordering']], $db->setQuery(
            'SELECT i.value_id, v.item_field_id AS field_id, i.ordering FROM ' . $db->quoteName('#__alfa_item_info_values', 'i')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_field_values', 'v') . ' ON v.id = i.value_id WHERE i.item_id = ' . $itemId
            . ' ORDER BY i.ordering ASC, i.value_id ASC',
        )->loadAssocList() ?: []);
    }

    /**
     * Every step judged before anything is written, against the product's options as the earlier steps leave them.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function refusals(DatabaseDriver $db): array
    {
        $options = array_fill_keys(array_column(self::options(itemId: $this->itemId), 'value_id'), true);
        $specifications = array_fill_keys(array_column(self::specifications(itemId: $this->itemId), 'value_id'), true);
        $eligible = array_map('intval', array_diff(
            ItemFieldsAdminHelper::getEligibleChoiceFieldIds(itemId: $this->itemId),
            ItemFieldsAdminHelper::getCombinationFieldIds(itemId: $this->itemId),
        ));
        $dimensions = array_map('intval', ItemFieldsAdminHelper::getCombinationFieldIds(itemId: $this->itemId));
        $refusals = [];

        foreach ($this->steps as $step) {
            $at = $step['pointer'];

            switch ($step['op']) {
                case 'add':
                    $value = self::value(db: $db, valueId: $step['id']);

                    if ($value === null || (int) $value->state !== 1 || (int) $value->field_state !== 1 || (int) $value->informational === 1) {
                        $refusals[] = new Refusal(code: 'value_invalid', sentence: Text::sprintf('COM_ALFA_OPTION_VALUE_INVALID', (string) $step['id']), pointer: $at . '/value_id');
                    } elseif (\in_array((int) $value->field_id, $dimensions, true)) {
                        $refusals[] = new Refusal(code: 'field_is_dimension', sentence: Text::sprintf('COM_ALFA_OPTION_FIELD_IS_DIMENSION', self::fieldName(fieldId: (int) $value->field_id)), pointer: $at . '/value_id');
                    } elseif (!\in_array((int) $value->field_id, $eligible, true)) {
                        $refusals[] = new Refusal(code: 'field_not_eligible', sentence: Text::sprintf('COM_ALFA_OPTION_FIELD_NOT_ELIGIBLE', self::fieldName(fieldId: (int) $value->field_id)), pointer: $at . '/value_id');
                    } elseif (isset($options[$step['id']])) {
                        $refusals[] = new Refusal(code: 'option_exists', sentence: Text::sprintf('COM_ALFA_OPTION_EXISTS', self::valueName(valueId: $step['id'])), pointer: $at . '/value_id', status: Refusal::CONFLICT);
                    } else {
                        $options[$step['id']] = true;
                    }

                    array_push($refusals, ...self::deltaRefusals(delta: $step['delta'], pointer: $at));
                    break;

                case 'change':
                    if (!isset($options[$step['id']])) {
                        $refusals[] = new Refusal(code: 'not_found', sentence: Text::sprintf('COM_ALFA_OPTION_NOT_FOUND', (string) $step['id']), pointer: $at, status: Refusal::NOT_FOUND);
                    }

                    array_push($refusals, ...self::deltaRefusals(delta: $step['delta'], pointer: $at));
                    break;

                case 'remove':
                    foreach ($step['ids'] as $valueId) {
                        if (!isset($options[$valueId])) {
                            $refusals[] = new Refusal(code: 'not_found', sentence: Text::sprintf('COM_ALFA_OPTION_NOT_FOUND', (string) $valueId), pointer: $at, status: Refusal::NOT_FOUND);
                        }

                        unset($options[$valueId]);
                    }

                    break;

                case 'informational':
                    $new = array_values(array_diff($step['ids'], array_keys($specifications)));
                    $valid = $new === [] ? [] : array_map('intval', $db->setQuery(
                        'SELECT v.id FROM ' . $db->quoteName('#__alfa_item_field_values', 'v') . ' INNER JOIN ' . $db->quoteName('#__alfa_item_fields', 'f')
                        . ' ON f.id = v.item_field_id WHERE v.state = 1 AND f.state = 1 AND f.informational = 1 AND v.id IN (' . implode(',', $new) . ')',
                    )->loadColumn() ?: []);

                    foreach (array_diff($new, $valid) as $valueId) {
                        $refusals[] = new Refusal(code: 'value_invalid', sentence: Text::sprintf('COM_ALFA_SPECIFICATION_VALUE_INVALID', (string) $valueId),
                            pointer: $at . '/' . array_search($valueId, $step['ids'], true));
                    }

                    $specifications = array_fill_keys($step['ids'], true);
                    break;
            }
        }

        return $refusals;
    }

    /**
     * A price change's refusals: an operation outside the four, an amount that is no number, or one its column cannot
     * hold as it is (the column never rounds an amount).
     *
     * @param array{operation: ?string, amount: ?string, apply_on: ?string} $delta The price change.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private static function deltaRefusals(array $delta, string $pointer): array
    {
        if ($delta['operation'] === null || $delta['operation'] === '') {
            return [];
        }

        if (!\in_array($delta['operation'], self::OPERATIONS, true)) {
            return [new Refusal(code: 'operation_invalid', sentence: Text::_('COM_ALFA_OPTION_OPERATION_INVALID'), pointer: $pointer . '/operation')];
        }

        $amount = trim((string) $delta['amount']);

        if ($amount === '' || !is_numeric($amount)) {
            return [new Refusal(code: 'amount_invalid', sentence: Text::_('COM_ALFA_OPTION_AMOUNT_INVALID'), pointer: $pointer . '/amount')];
        }

        $refusal = match (StoredDecimal::refusal(value: ExactAmount::ofNumber($amount))) {
            StoredDecimal::FINER => Text::sprintf('COM_ALFA_ERROR_DECIMAL_TOO_FINE', $amount, StoredDecimal::SCALE),
            StoredDecimal::LARGER => Text::sprintf('COM_ALFA_ERROR_DECIMAL_TOO_LARGE', $amount),
            default => null,
        };

        if ($refusal !== null) {
            return [new Refusal(code: 'amount_invalid', sentence: $refusal, pointer: $pointer . '/amount')];
        }

        if ($delta['apply_on'] !== null && $delta['apply_on'] !== '' && !\in_array($delta['apply_on'], self::APPLY_ON, true)) {
            return [new Refusal(code: 'apply_on_invalid', sentence: Text::_('COM_ALFA_OPTION_APPLY_ON_INVALID'), pointer: $pointer . '/apply_on')];
        }

        return [];
    }

    /**
     * The columns of a price change as stored: the three NULL for none; apply_on only while an amount runs.
     *
     * @param array{operation: ?string, amount: ?string, apply_on: ?string} $delta The price change.
     *
     * @return array{operation: ?string, amount: ?string, apply_on: ?string}
     *
     * @since  2.3.0
     */
    private static function columns(array $delta): array
    {
        if ($delta['operation'] === null || $delta['operation'] === '') {
            return self::NO_DELTA;
        }

        return [
            'operation' => $delta['operation'],
            'amount' => DecimalFilter::text(trim((string) $delta['amount'])),
            'apply_on' => \in_array($delta['apply_on'], self::APPLY_ON, true) ? $delta['apply_on'] : null,
        ];
    }

    /**
     * Insert an option, last in the product's list.
     *
     * @since  2.3.0
     */
    private function insert(DatabaseDriver $db, int $valueId, array $delta): int
    {
        $columns = self::columns(delta: $delta);
        $row = (object) [
            'item_id' => $this->itemId,
            'value_id' => $valueId,
            'operation' => $columns['operation'],
            'amount' => $columns['amount'],
            'apply_on' => $columns['apply_on'],
            'ordering' => (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_option_values') . ' WHERE '
                . $db->quoteName('item_id') . ' = ' . $this->itemId)->loadResult(),
        ];
        $db->insertObject('#__alfa_item_option_values', $row);

        return $valueId;
    }

    /**
     * Set an option's price change.
     *
     * @since  2.3.0
     */
    private function update(DatabaseDriver $db, int $valueId, array $delta): int
    {
        $columns = self::columns(delta: $delta);
        $query = $db->getQuery(true)->update($db->quoteName('#__alfa_item_option_values'));

        foreach ($columns as $column => $value) {
            $query->set($db->quoteName($column) . ' = ' . ($value === null ? 'NULL' : $db->quote($value)));
        }

        $db->setQuery($query->where($db->quoteName('item_id') . ' = ' . $this->itemId)->where($db->quoteName('value_id') . ' = ' . $valueId))->execute();

        return $valueId;
    }

    /**
     * Remove options with what names them: their option settings of the field type (a later option of the same value
     * gets a new row and must not find them), the cart selections of the product's lines that chose them.
     *
     * @param list<int> $valueIds The options' values.
     * @param list<int> $removed The values removed so far, added to.
     *
     * @return int The cart selections removed.
     *
     * @since  2.3.0
     */
    private function delete(DatabaseDriver $db, array $valueIds, array &$removed): int
    {
        $rowIds = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_item_option_values'))
                ->where($db->quoteName('item_id') . ' = ' . $this->itemId)
                ->whereIn($db->quoteName('value_id'), $valueIds),
        )->loadColumn() ?: []);

        if ($rowIds !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_item_combination_field_params'))
                    ->where($db->quoteName('scope') . ' = ' . $db->quote('option'))
                    ->whereIn($db->quoteName('scope_id'), $rowIds),
            )->execute();
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__alfa_item_option_values'))
                ->where($db->quoteName('item_id') . ' = ' . $this->itemId)
                ->whereIn($db->quoteName('value_id'), $valueIds),
        )->execute();

        array_push($removed, ...$valueIds);

        // A cart line keeps the product: only the selection that chose a removed option goes.
        return (new CartRepository($db))->removeSelectionsForItemValues(itemId: $this->itemId, valueIds: $valueIds);
    }

    /**
     * The options' order: the values given take the first places, the others follow in the order they had.
     *
     * @param list<int> $valueIds The options' values.
     *
     * @since  2.3.0
     */
    private function reorder(DatabaseDriver $db, array $valueIds): void
    {
        $current = array_column(self::options(itemId: $this->itemId), 'value_id');
        $position = 0;

        foreach (array_merge(array_values(array_intersect($valueIds, $current)), array_values(array_diff($current, $valueIds))) as $valueId) {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__alfa_item_option_values'))
                    ->set($db->quoteName('ordering') . ' = ' . $position++)
                    ->where($db->quoteName('item_id') . ' = ' . $this->itemId)
                    ->where($db->quoteName('value_id') . ' = ' . (int) $valueId),
            )->execute();
        }
    }

    /**
     * The product's specifications replaced: the rows not given removed, the new ones added, all in the order given.
     *
     * @param list<int> $valueIds The values.
     *
     * @since  2.3.0
     */
    private function replaceInformational(DatabaseDriver $db, array $valueIds): void
    {
        $current = array_column(self::specifications(itemId: $this->itemId), 'value_id');
        $gone = array_values(array_diff($current, $valueIds));

        if ($gone !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName('#__alfa_item_info_values'))
                    ->where($db->quoteName('item_id') . ' = ' . $this->itemId)
                    ->whereIn($db->quoteName('value_id'), $gone),
            )->execute();
        }

        foreach ($valueIds as $position => $valueId) {
            if (\in_array($valueId, $current, true)) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName('#__alfa_item_info_values'))
                        ->set($db->quoteName('ordering') . ' = ' . (int) $position)
                        ->where($db->quoteName('item_id') . ' = ' . $this->itemId)
                        ->where($db->quoteName('value_id') . ' = ' . (int) $valueId),
                )->execute();

                continue;
            }

            $row = (object) ['item_id' => $this->itemId, 'value_id' => (int) $valueId, 'ordering' => (int) $position];
            $db->insertObject('#__alfa_item_info_values', $row);
        }
    }

    /**
     * A value with its field's facts, or null when it does not exist.
     *
     * @since  2.3.0
     */
    private static function value(DatabaseDriver $db, int $valueId): ?object
    {
        return $db->setQuery(
            'SELECT v.id, v.state, v.item_field_id AS field_id, f.state AS field_state, f.informational FROM '
            . $db->quoteName('#__alfa_item_field_values', 'v') . ' INNER JOIN ' . $db->quoteName('#__alfa_item_fields', 'f')
            . ' ON f.id = v.item_field_id WHERE v.id = ' . $valueId,
        )->loadObject() ?: null;
    }

    /**
     * A field's name in the language read.
     *
     * @since  2.3.0
     */
    private static function fieldName(int $fieldId): string
    {
        return self::textOf(id: $fieldId, column: 'id_item_field', table: '#__alfa_item_fields');
    }

    /**
     * A value's name in the language read.
     *
     * @since  2.3.0
     */
    private static function valueName(int $valueId): string
    {
        return self::textOf(id: $valueId, column: 'id_item_field_value', table: '#__alfa_item_field_values');
    }

    /**
     * A record's name in the language read, or its id.
     *
     * @since  2.3.0
     */
    private static function textOf(int $id, string $column, string $table): string
    {
        $texts = MultilingualHelper::getMultilingualDataFlat(currentId: $id, primaryColumnName: $column, tableName: $table);

        foreach ([Factory::getApplication()->getLanguage()->getTag(), MultilingualHelper::getDefaultLanguageTag()] as $tag) {
            $name = trim((string) ($texts[MultilingualHelper::buildLangFieldKey(field: 'name', tag: $tag)] ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return '#' . $id;
    }

    /**
     * Positive ids, once each, in the order given.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    private static function ids(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));
    }

    /**
     * The connection.
     *
     * @since  2.3.0
     */
    private static function db(): DatabaseDriver
    {
        return TransactionState::driver(db: Factory::getContainer()->get(DatabaseInterface::class));
    }
}
