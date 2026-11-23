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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Alfa\Component\Alfa\Administrator\Service\Refusal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * The one way an item field's values are created, changed or removed: the field's Values tab, the REST API and an
 * extension's code build the same write on the field and save it.
 *
 *     $result = ItemFieldValueService::of(fieldId: 5)
 *         ->create(['code' => 'silver', 'name_en_gb' => 'Silver'])
 *         ->change(valueId: 12, fields: ['name_en_gb' => 'White'])
 *         ->remove(valueIds: [14])
 *         ->save();
 *
 * A value's fields are the Values tab's: a name by its language key (name_en_gb; the alias is made from it, as the tab
 * makes it), code (the value's machine key, unique in its field, a to z, 0 to 9, - and _, at most 64 characters; empty
 * for none), state (1 published, 0 not), ordering and valueparams (the field type's own settings of the value). A new
 * value needs a name in one language at least. A value a product uses (a variant's value, an option, a specification)
 * is not removed: the save is refused with how many products use it. Removing a value takes with it everything that
 * names it: its translations, the cart selections that chose it and the links that preselect it.
 *
 * save() writes every step in one transaction, in the order given, or nothing; the products that carry a changed or
 * removed value are owed a rebuild of their catalogue to the catalogue job. The caller checks its own permission.
 *
 * @api CHANGE CATALOGUE: create, change or remove an item field's values with the Values tab's rules.
 *
 * @since  2.3.0
 */
final class ItemFieldValueService
{
    /**
     * What a value code is: a to z, 0 to 9, - and _, 1 to 64 characters.
     *
     * @since  2.3.0
     */
    public const CODE_PATTERN = '/^[a-z0-9_-]{1,64}$/';

    /**
     * The steps, in the order given: op (create, change or remove), id, fields, pointer.
     *
     * @var list<array{op: string, id: int, ids: list<int>, fields: array<string, mixed>, pointer: string}>
     * @since  2.3.0
     */
    private array $steps = [];

    /**
     * @param int $fieldId The item field.
     *
     * @since  2.3.0
     */
    private function __construct(private readonly int $fieldId)
    {
    }

    /**
     * The values of an item field.
     *
     * @param int $fieldId The item field.
     *
     * @since  2.3.0
     */
    public static function of(int $fieldId): self
    {
        return new self(fieldId: max(0, $fieldId));
    }

    /**
     * A new value.
     *
     * @param array<string, mixed> $fields Its fields, by the Values tab's names.
     * @param string $pointer Where the value stands in the caller's request (a JSON pointer), for its refusals.
     *
     * @since  2.3.0
     */
    public function create(array $fields, string $pointer = ''): self
    {
        $this->steps[] = ['op' => 'create', 'id' => 0, 'ids' => [], 'fields' => $fields, 'pointer' => $pointer];

        return $this;
    }

    /**
     * A change of a value: the fields given; the others keep what they hold.
     *
     * @param int $valueId The value.
     * @param array<string, mixed> $fields The fields changed, by the Values tab's names.
     * @param string $pointer Where the value stands in the caller's request, for its refusals.
     *
     * @since  2.3.0
     */
    public function change(int $valueId, array $fields, string $pointer = ''): self
    {
        $this->steps[] = ['op' => 'change', 'id' => $valueId, 'ids' => [], 'fields' => $fields, 'pointer' => $pointer];

        return $this;
    }

    /**
     * Remove values of the field; refused while a product uses one.
     *
     * @param list<int> $valueIds The values.
     * @param string $pointer Where they stand in the caller's request, for their refusals.
     *
     * @since  2.3.0
     */
    public function remove(array $valueIds, string $pointer = ''): self
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0)));
        $this->steps[] = ['op' => 'remove', 'id' => 0, 'ids' => $ids, 'fields' => [], 'pointer' => $pointer];

        return $this;
    }

    /**
     * Judge every step, then write them all in one transaction, in the order given.
     *
     * @since  2.3.0
     */
    public function save(): ItemFieldValueResult
    {
        $db = self::db();
        $field = $db->setQuery('SELECT ' . $db->quoteName('id') . ' FROM ' . $db->quoteName('#__alfa_item_fields') . ' WHERE '
            . $db->quoteName('id') . ' = ' . $this->fieldId)->loadResult();

        if ($field === null) {
            return new ItemFieldValueResult(saved: false, refusals: [new Refusal(code: 'not_found', sentence: Text::_('COM_ALFA_ITEMFIELD_NOT_FOUND'), status: Refusal::NOT_FOUND)]);
        }

        $refusals = $this->refusals(db: $db);

        if ($refusals !== []) {
            return new ItemFieldValueResult(saved: false, refusals: $refusals);
        }

        if ($this->steps === []) {
            return new ItemFieldValueResult(saved: true);
        }

        // The language tables the texts go into exist before the transaction: DDL commits implicitly.
        foreach ($this->steps as $step) {
            MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_item_field_values', primaryColumnName: 'id_item_field_value', data: self::texts(fields: $step['fields']));
        }

        $nested = TransactionState::isOpen(db: $db);
        $created = $changed = $removed = $items = [];

        try {
            $db->transactionStart(true);

            try {
                foreach ($this->steps as $step) {
                    if ($step['op'] === 'remove') {
                        $items = array_merge($items, self::itemsCarrying(db: $db, valueIds: $step['ids']));
                        self::cascade(db: $db, valueIds: $step['ids']);
                        $removed = array_merge($removed, $step['ids']);

                        continue;
                    }

                    $id = $this->write(db: $db, valueId: $step['id'], fields: $step['fields']);

                    if ($step['op'] === 'create') {
                        $created[] = $id;
                    } else {
                        $changed[] = $id;
                        $items = array_merge($items, self::itemsCarrying(db: $db, valueIds: [$id]));
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

            Log::add('The values of item field ' . $this->fieldId . ' were not saved: ' . ErrorText::of($e), Log::ERROR, 'com_alfa.catalog');

            return new ItemFieldValueResult(saved: false, refusals: [new Refusal(code: 'refused', sentence: ErrorText::of($e), status: Refusal::CONFLICT)]);
        }

        // A renamed or removed value changes the search document of every product that carries it, and a value can be
        // carried by thousands: they are owed to the catalogue job, never rebuilt inside the request.
        ProjectionManager::owe(itemIds: array_values(array_unique($items)));

        return new ItemFieldValueResult(saved: true, created: $created, changed: array_values(array_unique($changed)), removed: $removed);
    }

    /**
     * What depends on values, across the three ways a product uses one: the values a product uses, how many products,
     * and how many variants, options and specifications.
     *
     * @param list<int> $valueIds The values.
     *
     * @return array{ids: list<int>, items: int, combinations: int, options: int, informational: int}
     *
     * @since  2.3.0
     */
    public static function usage(array $valueIds): array
    {
        $valueIds = array_values(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0));
        $empty = ['ids' => [], 'items' => 0, 'combinations' => 0, 'options' => 0, 'informational' => 0];

        if ($valueIds === []) {
            return $empty;
        }

        $db = self::db();
        $in = implode(',', $valueIds);

        // Read from the tables the products are written in, never from the filter projection: a guard does not depend
        // on a read model being current. A specification row has no id of its own: its product and value are its key.
        $rows = $db->setQuery(
            'SELECT cv.' . $db->quoteName('value_id') . ' AS value_id, c.' . $db->quoteName('item_id') . ' AS item_id, '
            . $db->quote('combinations') . ' AS surface, cv.' . $db->quoteName('combination_id') . ' AS ref'
            . ' FROM ' . $db->quoteName('#__alfa_item_combination_values', 'cv')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_item_combinations', 'c') . ' ON c.id = cv.combination_id'
            . ' WHERE cv.value_id IN (' . $in . ')'
            . ' UNION ALL SELECT ' . $db->quoteName('value_id') . ', ' . $db->quoteName('item_id') . ', '
            . $db->quote('options') . ', ' . $db->quoteName('id')
            . ' FROM ' . $db->quoteName('#__alfa_item_option_values') . ' WHERE ' . $db->quoteName('value_id') . ' IN (' . $in . ')'
            . ' UNION ALL SELECT ' . $db->quoteName('value_id') . ', ' . $db->quoteName('item_id') . ', '
            . $db->quote('informational') . ', CONCAT(' . $db->quoteName('item_id') . ', ' . $db->quote('-') . ', ' . $db->quoteName('value_id') . ')'
            . ' FROM ' . $db->quoteName('#__alfa_item_info_values') . ' WHERE ' . $db->quoteName('value_id') . ' IN (' . $in . ')',
        )->loadObjectList() ?: [];

        if ($rows === []) {
            return $empty;
        }

        $ids = $items = $refs = [];

        foreach ($rows as $row) {
            $ids[(int) $row->value_id] = true;
            $items[(int) $row->item_id] = true;
            $refs[$row->surface][(string) $row->ref] = true;
        }

        return [
            'ids' => array_map('intval', array_keys($ids)),
            'items' => \count($items),
            'combinations' => \count($refs['combinations'] ?? []),
            'options' => \count($refs['options'] ?? []),
            'informational' => \count($refs['informational'] ?? []),
        ];
    }

    /**
     * The sentence that says why values in use are not removed: how many products, and through what.
     *
     * @param array{items: int, combinations: int, options: int, informational: int} $usage What usage() answered.
     *
     * @since  2.3.0
     */
    public static function usageSentence(array $usage): string
    {
        $via = [];

        foreach (['combinations', 'options', 'informational'] as $surface) {
            if ($usage[$surface] > 0) {
                $via[] = Text::plural('COM_ALFA_ITEMFIELD_DELETE_IN_USE_' . strtoupper($surface), $usage[$surface]);
            }
        }

        return Text::plural('COM_ALFA_ITEMFIELD_VALUE_IN_USE', $usage['items']) . ' ' . Text::sprintf('COM_ALFA_ITEMFIELD_DELETE_IN_USE_VIA', implode(', ', $via));
    }

    /**
     * The values of a field as staff manage them, in their order: id, code, state, ordering, params and the texts of
     * every content language (name, alias).
     *
     * @param int $fieldId The item field.
     * @param list<int>|null $valueIds Only these values; null for all.
     *
     * @return list<array{id: int, item_field_id: int, code: ?string, state: int, ordering: int, params: array, translations: array<string, array{name: string, alias: string}>}>
     *
     * @since  2.3.0
     */
    public static function read(int $fieldId, ?array $valueIds = null): array
    {
        $db = self::db();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'item_field_id', 'code', 'params', 'state', 'ordering']))
            ->from($db->quoteName('#__alfa_item_field_values'))
            ->where($db->quoteName('item_field_id') . ' = ' . $fieldId)
            ->order($db->quoteName('ordering') . ' ASC, ' . $db->quoteName('id') . ' ASC');

        if ($valueIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0));

            if ($ids === []) {
                return [];
            }

            $query->whereIn($db->quoteName('id'), $ids);
        }

        $rows = $db->setQuery($query)->loadAssocList() ?: [];
        $texts = $rows === [] ? [] : MultilingualHelper::getMultilingualDataForIds(
            ids: array_map('intval', array_column($rows, 'id')),
            primaryColumnName: 'id_item_field_value',
            tableName: '#__alfa_item_field_values',
        );
        $out = [];

        foreach ($rows as $row) {
            $translations = [];

            foreach (MultilingualHelper::getLanguageTags() as $tag) {
                $key = MultilingualHelper::normaliseTag(tag: $tag);
                $text = (array) ($texts[(int) $row['id']][$tag] ?? $texts[(int) $row['id']][$key] ?? []);
                $translations[$key] = ['name' => (string) ($text['name'] ?? ''), 'alias' => (string) ($text['alias'] ?? '')];
            }

            $out[] = [
                'id' => (int) $row['id'],
                'item_field_id' => (int) $row['item_field_id'],
                'code' => $row['code'] === null ? null : (string) $row['code'],
                'state' => (int) $row['state'],
                'ordering' => (int) $row['ordering'],
                'params' => \is_string($row['params']) ? (json_decode($row['params'], true) ?: []) : [],
                'translations' => $translations,
            ];
        }

        return $out;
    }

    /**
     * Values as an answer names them, whatever their field: the field (id and field_name), the code and the texts of
     * every content language (name, alias), by value id.
     *
     * @param list<int> $valueIds The values.
     *
     * @return array<int, array{field_id: int, field_name: string, code: ?string, translations: array<string, array{name: string, alias: string}>}>
     *
     * @since  2.3.0
     */
    public static function describe(array $valueIds): array
    {
        $valueIds = array_values(array_unique(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0)));

        if ($valueIds === []) {
            return [];
        }

        $db = self::db();
        $rows = $db->setQuery(
            'SELECT v.id, v.item_field_id, v.code, f.field_name FROM ' . $db->quoteName('#__alfa_item_field_values', 'v') . ' INNER JOIN '
            . $db->quoteName('#__alfa_item_fields', 'f') . ' ON f.id = v.item_field_id WHERE v.id IN (' . implode(',', $valueIds) . ')',
        )->loadAssocList('id') ?: [];
        $texts = MultilingualHelper::getMultilingualDataForIds(ids: $valueIds, primaryColumnName: 'id_item_field_value', tableName: '#__alfa_item_field_values');
        $out = [];

        foreach ($rows as $id => $row) {
            $translations = [];

            foreach (MultilingualHelper::getLanguageTags() as $tag) {
                $key = MultilingualHelper::normaliseTag(tag: $tag);
                $text = (array) ($texts[(int) $id][$tag] ?? $texts[(int) $id][$key] ?? []);
                $translations[$key] = ['name' => (string) ($text['name'] ?? ''), 'alias' => (string) ($text['alias'] ?? '')];
            }

            $out[(int) $id] = ['field_id' => (int) $row['item_field_id'], 'field_name' => (string) $row['field_name'],
                'code' => $row['code'] === null ? null : (string) $row['code'], 'translations' => $translations];
        }

        return $out;
    }

    /**
     * Every step judged before anything is written: the values named belong to the field, a code is well formed and
     * free in the field, a state is 0 or 1, an order a whole number, a new value has a name, a value removed is used by
     * no product.
     *
     * @return list<Refusal>
     *
     * @since  2.3.0
     */
    private function refusals(DatabaseDriver $db): array
    {
        // The rows whole: a column read alone gives a row back where its value is NULL.
        $rows = $db->setQuery('SELECT ' . implode(', ', $db->quoteName(['id', 'code'])) . ' FROM ' . $db->quoteName('#__alfa_item_field_values') . ' WHERE '
            . $db->quoteName('item_field_id') . ' = ' . $this->fieldId)->loadAssocList() ?: [];
        $stored = array_fill_keys(array_map('intval', array_column($rows, 'id')), true);
        $codes = [];

        foreach ($rows as $row) {
            if ($row['code'] !== null && $row['code'] !== '') {
                $codes[(string) $row['code']] = (int) $row['id'];
            }
        }

        $refusals = [];

        foreach ($this->steps as $step) {
            $at = $step['pointer'];

            if ($step['op'] === 'remove') {
                $foreign = array_values(array_diff($step['ids'], array_map('intval', array_keys($stored))));

                if ($step['ids'] === [] || $foreign !== []) {
                    $refusals[] = new Refusal(code: 'not_found', sentence: Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_NOT_FOUND', (string) ($foreign[0] ?? 0)), pointer: $at, status: Refusal::NOT_FOUND);

                    continue;
                }

                $usage = self::usage(valueIds: $step['ids']);

                if ($usage['ids'] !== []) {
                    $refusals[] = new Refusal(code: 'value_in_use', sentence: self::usageSentence(usage: $usage), pointer: $at, status: Refusal::CONFLICT);
                }

                continue;
            }

            if ($step['op'] === 'change' && !\array_key_exists($step['id'], $stored)) {
                $refusals[] = new Refusal(code: 'not_found', sentence: Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_NOT_FOUND', (string) $step['id']), pointer: $at, status: Refusal::NOT_FOUND);

                continue;
            }

            $fields = $step['fields'];

            if (\array_key_exists('code', $fields)) {
                $code = $fields['code'] === null ? '' : (\is_string($fields['code']) ? trim($fields['code']) : null);

                if ($code === null || ($code !== '' && preg_match(self::CODE_PATTERN, $code) !== 1)) {
                    $refusals[] = new Refusal(code: 'code_invalid', sentence: Text::_('COM_ALFA_ITEMFIELD_VALUE_CODE_INVALID'), pointer: $at . '/code');
                } elseif ($code !== '' && isset($codes[$code]) && $codes[$code] !== $step['id']) {
                    $refusals[] = new Refusal(code: 'code_in_use', sentence: Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_CODE_IN_USE', $code, $codes[$code]), pointer: $at . '/code', status: Refusal::CONFLICT);
                } else {
                    // A code a value gives up is free for a later step; the one it takes is its own.
                    $codes = array_filter($codes, static fn (int $holder): bool => $holder !== $step['id'] || $step['id'] === 0);

                    if ($code !== '') {
                        $codes[$code] = $step['id'] > 0 ? $step['id'] : -1;
                    }
                }
            }

            if (\array_key_exists('state', $fields) && !\in_array((string) $fields['state'], ['0', '1'], true)) {
                $refusals[] = new Refusal(code: 'state_invalid', sentence: Text::_('COM_ALFA_ITEMFIELD_VALUE_STATE_INVALID'), pointer: $at . '/state');
            }

            if (\array_key_exists('ordering', $fields) && preg_match('/^[0-9]{1,9}$/', (string) $fields['ordering']) !== 1) {
                $refusals[] = new Refusal(code: 'ordering_invalid', sentence: Text::_('COM_ALFA_ITEMFIELD_VALUE_ORDERING_INVALID'), pointer: $at . '/ordering');
            }

            if (\array_key_exists('valueparams', $fields) && !\is_array($fields['valueparams'])) {
                $refusals[] = new Refusal(code: 'params_invalid', sentence: Text::_('COM_ALFA_ITEMFIELD_VALUE_PARAMS_INVALID'), pointer: $at . '/params');
            }

            if ($step['op'] === 'create' && !self::named(fields: $fields)) {
                $refusals[] = new Refusal(code: 'name_missing', sentence: Text::_('COM_ALFA_ITEMFIELD_VALUE_NAME_MISSING'), pointer: $at . '/translations');
            }
        }

        return $refusals;
    }

    /**
     * Write one value's row and texts: a new one appended to the field's list unless an order is given.
     *
     * @param int $valueId The value; 0 for a new one.
     * @param array<string, mixed> $fields Its fields.
     *
     * @return int The value.
     *
     * @since  2.3.0
     */
    private function write(DatabaseDriver $db, int $valueId, array $fields): int
    {
        $table = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()->createTable('Itemfieldvalue', 'Administrator');

        if ($valueId > 0 && !$table->load($valueId)) {
            throw new RuntimeException(Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_NOT_FOUND', (string) $valueId));
        }

        $row = ['item_field_id' => $this->fieldId];

        if ($valueId === 0) {
            $row += ['params' => null, 'state' => 1, 'code' => null, 'ordering' => (int) $db->setQuery('SELECT COALESCE(MAX(' . $db->quoteName('ordering')
                . ') + 1, 0) FROM ' . $db->quoteName('#__alfa_item_field_values') . ' WHERE ' . $db->quoteName('item_field_id') . ' = ' . $this->fieldId)->loadResult()];
        }

        if (\array_key_exists('valueparams', $fields)) {
            $row['params'] = json_encode($fields['valueparams'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        if (\array_key_exists('code', $fields)) {
            $code = trim((string) $fields['code']);
            $row['code'] = $code === '' ? null : $code;
        }

        foreach (['state', 'ordering'] as $column) {
            if (\array_key_exists($column, $fields)) {
                $row[$column] = (int) $fields[$column];
            }
        }

        $table->bind($row);

        if (!$table->store()) {
            throw new RuntimeException(Text::sprintf('COM_ALFA_ITEMFIELD_VALUE_NOT_SAVED', $table->getError()));
        }

        $valueId = (int) $table->id;
        $texts = self::texts(fields: $fields);

        // Each name sent makes its alias again, as the Values tab makes it: a value's alias follows its name.
        if ($texts !== []) {
            MultilingualHelper::saveMultilingualData(
                currentId: $valueId,
                primaryColumnName: 'id_item_field_value',
                tableName: '#__alfa_item_field_values',
                data: $texts,
                aliasFields: ['alias'],
            );
        }

        return $valueId;
    }

    /**
     * Remove values with everything that names them: the products' uses (none, once refusals() passed; the field's
     * own deletion removes them with it), the cart selections that chose them, the value rows and their texts.
     *
     * @internal The field's deletion removes its values through it, after its own check of their use.
     *
     * @param list<int> $valueIds The values.
     *
     * @since  2.3.0
     */
    public static function cascade(DatabaseInterface $db, array $valueIds): void
    {
        $valueIds = array_values(array_filter(array_map('intval', $valueIds), static fn (int $id): bool => $id > 0));

        if ($valueIds === []) {
            return;
        }

        foreach (['#__alfa_item_info_values', '#__alfa_item_option_values', '#__alfa_item_combination_values'] as $table) {
            $db->setQuery($db->getQuery(true)->delete($db->quoteName($table))->whereIn($db->quoteName('value_id'), $valueIds))->execute();
        }

        // A cart line keeps its product: only the selection that chose a removed value goes.
        (new CartRepository($db))->removeSelectionsForFieldValues($valueIds);

        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__alfa_item_field_values'))->whereIn($db->quoteName('id'), $valueIds))->execute();

        MultilingualHelper::deleteMultilingualData(ids: $valueIds, primaryColumnName: 'id_item_field_value', tableName: '#__alfa_item_field_values');
    }

    /**
     * The products whose filter and search rows carry values.
     *
     * @param list<int> $valueIds The values.
     *
     * @return list<int>
     *
     * @since  2.3.0
     */
    private static function itemsCarrying(DatabaseDriver $db, array $valueIds): array
    {
        return $valueIds === [] ? [] : array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . $db->quoteName('item_id'))
                ->from($db->quoteName('#__alfa_item_field_value_index'))
                ->whereIn($db->quoteName('value_id'), $valueIds),
        )->loadColumn() ?: []);
    }

    /**
     * The texts among a value's fields: name_<language> with its alias made again.
     *
     * @param array<string, mixed> $fields The fields.
     *
     * @return array<string, string>
     *
     * @since  2.3.0
     */
    private static function texts(array $fields): array
    {
        $texts = [];

        foreach (MultilingualHelper::getLanguageTags() as $tag) {
            $name = MultilingualHelper::buildLangFieldKey(field: 'name', tag: $tag);

            if (\array_key_exists($name, $fields)) {
                $texts[$name] = (string) $fields[$name];
                $texts[MultilingualHelper::buildLangFieldKey(field: 'alias', tag: $tag)] = '';
            }
        }

        return $texts;
    }

    /**
     * Whether the fields name the value in one language at least.
     *
     * @since  2.3.0
     */
    private static function named(array $fields): bool
    {
        foreach (self::texts(fields: $fields) as $key => $text) {
            if (str_starts_with($key, 'name_') && trim($text) !== '') {
                return true;
            }
        }

        return false;
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
