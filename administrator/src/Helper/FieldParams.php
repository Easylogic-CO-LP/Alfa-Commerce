<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The single read/write primitive for item-field plugin config, scope-routed
 * across the two catalog tables (the order snapshot lives in the order
 * schema, not here):
 *
 *   SCOPE_ITEM        -> #__alfa_item_field_overrides        (item_id, field_id)
 *   SCOPE_COMBINATION -> #__alfa_item_combination_field_params (scope, scope_id, field_id)
 *   SCOPE_OPTION      -> #__alfa_item_combination_field_params (scope, scope_id, field_id)
 *
 * Every (scope-row x field) pair is ONE independent row, so several fields on
 * the same combination/option never share a JSON blob. Reads optionally fall
 * back from a variant row to the item-level default.
 *
 * Fluent, mirroring the payments PaymentLedger style; a plugin writes
 * one readable line:
 *
 *   FieldParams::item($itemId, $fieldId)->set('model', 'pencil')->save();
 *   FieldParams::variant('combination', $combinationId, $fieldId, $itemId)
 *       ->merge($posted)->save();
 *
 *   $cfg = FieldParams::getEffective('combination', $combinationId, $fieldId, $itemId);
 *
 * @since  2.0.0
 */
class FieldParams
{
    public const SCOPE_ITEM = 'item';

    public const SCOPE_COMBINATION = 'combination';

    public const SCOPE_OPTION = 'option';

    /**
     * The scope of the builder ('item', 'combination', 'option').
     *
     *
     * @since  2.0.0
     */
    protected string $scope;

    /**
     * The scope row id (item id, combination id or option-value id).
     *
     *
     * @since  2.0.0
     */
    protected int $scopeId;

    /**
     * The field id.
     *
     *
     * @since  2.0.0
     */
    protected int $fieldId;

    /**
     * The owning item id (carried on variant rows for cascade + fallback).
     *
     *
     * @since  2.0.0
     */
    protected int $itemId;

    /**
     * The working config (stored values merged with builder edits).
     *
     *
     * @since  2.0.0
     */
    protected array $config;

    /**
     * Builders start from item()/variant(); the stored config is preloaded
     * so set() merges instead of wiping.
     *
     * @param string $scope The scope.
     * @param int $scopeId The scope row id.
     * @param int $fieldId The field id.
     * @param int $itemId The owning item id.
     *
     * @since  2.0.0
     */
    protected function __construct(string $scope, int $scopeId, int $fieldId, int $itemId)
    {
        $this->scope = $scope;
        $this->scopeId = $scopeId;
        $this->fieldId = $fieldId;
        $this->itemId = $itemId;
        $this->config = self::read(scope: $scope, scopeId: $scopeId, fieldId: $fieldId)->toArray();
    }

    /**
     * Start a write builder on the ITEM-level config of a field.
     *
     * @param int $itemId The item id.
     * @param int $fieldId The field id.
     *
     *
     * @since  2.0.0
     */
    public static function item(int $itemId, int $fieldId): static
    {
        return new static(self::SCOPE_ITEM, $itemId, $fieldId, $itemId);
    }

    /**
     * Start a write builder on a VARIANT-row config (combination or option).
     *
     * @param string $scope 'combination' or 'option'.
     * @param int $scopeId The combination or option-value id.
     * @param int $fieldId The field id.
     * @param int $itemId The owning item id.
     *
     *
     * @since  2.0.0
     */
    public static function variant(string $scope, int $scopeId, int $fieldId, int $itemId): static
    {
        return new static($scope, $scopeId, $fieldId, $itemId);
    }

    /**
     * Set one config key.
     *
     * @param string $key The key.
     * @param mixed $value The value.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function set(string $key, mixed $value): static
    {
        $this->config[$key] = $value;

        return $this;
    }

    /**
     * Merge an array over the working config.
     *
     * @param array $config Key-value pairs.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function merge(array $config): static
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    /**
     * Replace the working config outright.
     *
     * @param array $config The full config.
     *
     * @return static For chaining.
     *
     * @since  2.0.0
     */
    public function replace(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * The working config as a Registry (read without persisting).
     *
     *
     * @since  2.0.0
     */
    public function toRegistry(): Registry
    {
        return new Registry($this->config);
    }

    /**
     * Persist the working config (upsert; an empty config deletes the row).
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function save(): bool
    {
        return self::write(
            scope: $this->scope,
            scopeId: $this->scopeId,
            fieldId: $this->fieldId,
            itemId: $this->itemId,
            config: $this->config,
        );
    }

    //  Reads

    /**
     * Read one (scope-row x field) config.
     *
     * @param string $scope The scope.
     * @param int $scopeId The scope row id.
     * @param int $fieldId The field id.
     *
     * @return Registry Empty when nothing is stored.
     *
     * @since  2.0.0
     */
    public static function read(string $scope, int $scopeId, int $fieldId): Registry
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)->select($db->qn('params'));

        if ($scope === self::SCOPE_ITEM) {
            $query->from($db->qn('#__alfa_item_field_overrides'))
                ->where($db->qn('item_id') . ' = ' . (int) $scopeId)
                ->where($db->qn('field_id') . ' = ' . (int) $fieldId);
        } else {
            $query->from($db->qn('#__alfa_item_combination_field_params'))
                ->where($db->qn('scope') . ' = ' . $db->q($scope))
                ->where($db->qn('scope_id') . ' = ' . (int) $scopeId)
                ->where($db->qn('field_id') . ' = ' . (int) $fieldId);
        }

        $db->setQuery($query);

        return new Registry((string) $db->loadResult());
    }

    /**
     * Read the EFFECTIVE config of a field on a variant row: the row config
     * merged over the item-level default, so a combination/option inherits
     * the item setup and overrides only what it sets.
     *
     * @param string $scope 'combination' or 'option'.
     * @param int $scopeId The variant row id.
     * @param int $fieldId The field id.
     * @param int $itemId The owning item id.
     *
     *
     * @since  2.0.0
     */
    public static function getEffective(string $scope, int $scopeId, int $fieldId, int $itemId): Registry
    {
        $base = self::read(scope: self::SCOPE_ITEM, scopeId: $itemId, fieldId: $fieldId)->toArray();
        $row = self::read(scope: $scope, scopeId: $scopeId, fieldId: $fieldId)->toArray();

        return new Registry(array_merge($base, $row));
    }

    /**
     * Read every item-level field config of one item, keyed by field id.
     *
     * @param int $itemId The item id.
     *
     * @return Registry[] field_id => Registry.
     *
     * @since  2.0.0
     */
    public static function getItemAll(int $itemId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn(['field_id', 'params']))
                ->from($db->qn('#__alfa_item_field_overrides'))
                ->where($db->qn('item_id') . ' = ' . (int) $itemId),
        );

        $out = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $out[(int) $row->field_id] = new Registry((string) $row->params);
        }

        return $out;
    }

    /**
     * Read every field config on one variant row, keyed by field id.
     *
     * @param string $scope 'combination' or 'option'.
     * @param int $scopeId The variant row id.
     *
     * @return Registry[] field_id => Registry.
     *
     * @since  2.0.0
     */
    public static function getVariantAll(string $scope, int $scopeId): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select($db->qn(['field_id', 'params']))
                ->from($db->qn('#__alfa_item_combination_field_params'))
                ->where($db->qn('scope') . ' = ' . $db->q($scope))
                ->where($db->qn('scope_id') . ' = ' . (int) $scopeId),
        );

        $out = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $out[(int) $row->field_id] = new Registry((string) $row->params);
        }

        return $out;
    }

    /**
     * The item ids on a page that carry ANY config for the given field
     * definitions (one batched query, used by listing decorators). Accepts
     * MANY field ids, since a type can have several definitions on a product.
     *
     * @param int[] $fieldIds The field-definition ids.
     * @param array $itemIds Candidate item ids.
     *
     * @return int[] Item ids that have config for any of those fields.
     *
     * @since  2.0.0
     */
    public static function itemsWithConfig(array $fieldIds, array $itemIds): array
    {
        $fieldIds = array_values(array_filter(array_map('intval', $fieldIds)));
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));

        if (!$fieldIds || !$itemIds) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . $db->qn('item_id'))
                ->from($db->qn('#__alfa_item_field_overrides'))
                ->whereIn($db->qn('field_id'), $fieldIds)
                ->whereIn($db->qn('item_id'), $itemIds)
                ->union(
                    $db->getQuery(true)
                        ->select('DISTINCT ' . $db->qn('item_id'))
                        ->from($db->qn('#__alfa_item_combination_field_params'))
                        ->whereIn($db->qn('field_id'), $fieldIds)
                        ->whereIn($db->qn('item_id'), $itemIds),
                ),
        );

        return array_map('intval', $db->loadColumn() ?: []);
    }

    //  Writes / cascades

    /**
     * Upsert one (scope-row x field) config. An empty config deletes the row.
     *
     * @param string $scope The scope.
     * @param int $scopeId The scope row id.
     * @param int $fieldId The field id.
     * @param int $itemId The owning item id.
     * @param array $config The config to store.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public static function write(string $scope, int $scopeId, int $fieldId, int $itemId, array $config): bool
    {
        if ($scopeId <= 0 || $fieldId <= 0) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $isItem = $scope === self::SCOPE_ITEM;
        $table = $isItem ? '#__alfa_item_field_overrides' : '#__alfa_item_combination_field_params';

        if (!$config) {
            $delete = $db->getQuery(true)->delete($db->qn($table))
                ->where($db->qn('field_id') . ' = ' . (int) $fieldId);

            if ($isItem) {
                $delete->where($db->qn('item_id') . ' = ' . (int) $scopeId);
            } else {
                $delete->where($db->qn('scope') . ' = ' . $db->q($scope))
                    ->where($db->qn('scope_id') . ' = ' . (int) $scopeId);
            }

            $db->setQuery($delete)->execute();

            return true;
        }

        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        if ($isItem) {
            $sql = 'INSERT INTO ' . $db->qn($table)
                . ' (' . implode(',', $db->qn(['item_id', 'field_id', 'params'])) . ')'
                . ' VALUES (' . (int) $scopeId . ', ' . (int) $fieldId . ', ' . $db->q($json) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $db->qn('params') . ' = ' . $db->q($json);
        } else {
            $sql = 'INSERT INTO ' . $db->qn($table)
                . ' (' . implode(',', $db->qn(['scope', 'scope_id', 'field_id', 'item_id', 'params'])) . ')'
                . ' VALUES (' . $db->q($scope) . ', ' . (int) $scopeId . ', ' . (int) $fieldId . ', '
                . (int) $itemId . ', ' . $db->q($json) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . $db->qn('params') . ' = ' . $db->q($json)
                . ', ' . $db->qn('item_id') . ' = ' . (int) $itemId;
        }

        $db->setQuery($sql)->execute();

        return true;
    }

    /**
     * Drop every field config of the given items (item delete cascade):
     * item-level rows and every variant row of those items.
     *
     * @param array $itemIds Item ids.
     *
     *
     * @since  2.0.0
     */
    public static function deleteForItems(array $itemIds): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds)));

        if (!$itemIds) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        foreach (['#__alfa_item_field_overrides', '#__alfa_item_combination_field_params'] as $table) {
            $db->setQuery(
                $db->getQuery(true)->delete($db->qn($table))->whereIn($db->qn('item_id'), $itemIds),
            )->execute();
        }
    }

    /**
     * Drop every config of the given fields (field delete cascade).
     *
     * @param array $fieldIds Field ids.
     *
     *
     * @since  2.0.0
     */
    public static function deleteForFields(array $fieldIds): void
    {
        $fieldIds = array_values(array_filter(array_map('intval', $fieldIds)));

        if (!$fieldIds) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        foreach (['#__alfa_item_field_overrides', '#__alfa_item_combination_field_params'] as $table) {
            $db->setQuery(
                $db->getQuery(true)->delete($db->qn($table))->whereIn($db->qn('field_id'), $fieldIds),
            )->execute();
        }
    }

    /**
     * Drop the variant configs of the given rows (combination/option delete
     * cascade).
     *
     * @param string $scope 'combination' or 'option'.
     * @param array $scopeIds Variant row ids.
     *
     *
     * @since  2.0.0
     */
    public static function deleteForVariants(string $scope, array $scopeIds): void
    {
        $scopeIds = array_values(array_filter(array_map('intval', $scopeIds)));

        if (!$scopeIds) {
            return;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->qn('#__alfa_item_combination_field_params'))
                ->where($db->qn('scope') . ' = ' . $db->q($scope))
                ->whereIn($db->qn('scope_id'), $scopeIds),
        )->execute();
    }
}
