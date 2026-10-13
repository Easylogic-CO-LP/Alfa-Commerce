<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Closure;
use Joomla\Database\DatabaseDriver;
use RuntimeException;
use Throwable;

defined('_JEXEC') or die;

/**
 * A payment or shipment plugin's log table: its definition and its completion.
 *
 * The table is #__alfa_{family}_{name}_logs: core's columns (id, id_order, the
 * family's identifier and method columns) and the columns the plugin declares
 * in params/logs.xml, with an index on the order, the identifier and the method.
 *
 * A plugin that ships its table creates it from its install SQL and completes
 * it from its install script on every update, through complete(). For a plugin
 * that ships none yet, Plugin::ensureLogTable() completes it at runtime, read
 * first and never inside an open transaction.
 *
 * ADDITIVE ONLY. An existing column is never re-typed, renamed or dropped:
 * logs are an audit trail, and a type change that truncates one is not a
 * decision this should be making on its own.
 *
 * @since  2.0.0
 */
final class PluginLogTable
{
    /**
     * The identifier and method columns of each plugin family.
     *
     * @var array<string, array{0: string, 1: string}>
     * @since  2.0.0
     */
    public const FAMILY_COLUMNS = [
        'alfa-payments' => ['id_order_payment', 'id_payment_method'],
        'alfa-shipments' => ['id_order_shipment', 'id_shipment_method'],
    ];

    /**
     * SQL definition of the core method column.
     *
     * @since  2.0.0
     */
    private const METHOD_COLUMN_SQL = 'INT(11) NOT NULL DEFAULT 0';

    /**
     * @param DatabaseDriver $db The connection.
     * @param string $tableName The table, with the #__ prefix.
     * @param object $xml The plugin's parsed logs.xml.
     * @param string $identifierField The family's identifier column ('' for none).
     * @param string $methodField The family's method column ('' for none).
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly DatabaseDriver $db,
        private readonly string $tableName,
        private readonly object $xml,
        private readonly string $identifierField,
        private readonly string $methodField,
    ) {
    }

    /**
     * The log table of an installed plugin, from its own params/logs.xml.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $type The plugin family, e.g. 'alfa-shipments'.
     * @param string $name The plugin element, e.g. 'mycarrier'.
     *
     * @return self|null Null when the plugin declares no log (no or no readable logs.xml).
     *
     * @since  2.0.0
     */
    public static function ofPlugin(DatabaseDriver $db, string $type, string $name): ?self
    {
        $file = JPATH_PLUGINS . '/' . $type . '/' . $name . '/params/logs.xml';
        $xml = is_file($file) ? simplexml_load_file($file) : false;

        if (!$xml || !isset($xml->fields->fieldset->field)) {
            return null;
        }

        [$identifierField, $methodField] = self::FAMILY_COLUMNS[$type] ?? ['', ''];

        return new self($db, self::nameOf($type, $name), $xml, $identifierField, $methodField);
    }

    /**
     * The table name of a plugin's log, with the #__ prefix.
     *
     * @param string $type The plugin family, e.g. 'alfa-payments'.
     * @param string $name The plugin element.
     *
     * @return string E.g. '#__alfa_payments_standard_logs'.
     *
     * @since  2.0.0
     */
    public static function nameOf(string $type, string $name): string
    {
        return '#__' . str_replace('-', '_', $type) . '_' . $name . '_logs';
    }

    /**
     * The table name, with the #__ prefix.
     *
     *
     * @since  2.0.0
     */
    public function name(): string
    {
        return $this->tableName;
    }

    /**
     * Whether a column name belongs to core rather than to logs.xml.
     *
     * @param string $name The column name.
     *
     *
     * @since  2.0.0
     */
    public function isCoreColumn(string $name): bool
    {
        return \in_array(
            strtolower($name),
            array_map('strtolower', array_filter(['id', 'id_order', $this->identifierField, $this->methodField])),
            true,
        );
    }

    /**
     * What the table lacks of what complete() provides: the table itself, a
     * declared or core method column, the method index.
     *
     * Reads only, so it commits nothing.
     *
     * @return string[] What is missing, named for a log line; empty when the table is complete.
     *
     * @throws RuntimeException When the table cannot be read for a reason other than not existing.
     *
     * @since  2.0.0
     */
    public function lacks(): array
    {
        $db = $this->db;
        $columns = [];

        try {
            foreach ($db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($this->tableName))->loadObjectList() ?: [] as $column) {
                $columns[strtolower((string) $column->Field)] = true;
            }
        } catch (Throwable $e) {
            if (MysqlError::number($e) === 1146) {
                return ['the table'];
            }

            throw $e;
        }

        $lacks = [];

        foreach (array_keys($this->wantedColumns()) as $name) {
            if (!isset($columns[strtolower($name)])) {
                $lacks[] = 'column "' . $name . '"';
            }
        }

        if ($this->methodField !== ''
            && isset($columns[strtolower($this->methodField)])
            && !$this->hasMethodIndex($columns)
        ) {
            $lacks[] = 'index "' . $this->methodIndex(isset($columns['action']))['name'] . '"';
        }

        return $lacks;
    }

    /**
     * The table's whole definition: core's columns and keys and every column
     * logs.xml declares. A plugin's install SQL ships exactly this.
     *
     * @return string CREATE TABLE IF NOT EXISTS, with the #__ prefix.
     *
     * @since  2.0.0
     */
    public function createStatement(): string
    {
        $db = $this->db;

        $sql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteName($this->tableName) . ' ('
            . $db->quoteName('id') . ' INT(11) AUTO_INCREMENT PRIMARY KEY NOT NULL, '
            . $db->quoteName('id_order') . ' INT(11) NOT NULL';

        // The identifier column (id_order_payment / id_order_shipment) links
        // each log entry to a specific payment or shipment.
        if ($this->identifierField !== '') {
            $sql .= ', ' . $db->quoteName($this->identifierField) . ' INT(11) DEFAULT NULL';
        }

        // The method column (id_payment_method / id_shipment_method): the
        // home of method-level rows, 0 on order rows that name no method.
        if ($this->methodField !== '') {
            $sql .= ', ' . $db->quoteName($this->methodField) . ' ' . self::METHOD_COLUMN_SQL;
        }

        // The columns of logs.xml; a core column keeps core's definition.
        foreach ($this->xml->fields->fieldset->field as $field) {
            $name = (string) $field['name'];

            if ($this->isCoreColumn($name)) {
                continue;
            }

            $sql .= ', ' . $db->quoteName($name) . ' ' . $this->declaredDefinition($field);
        }

        $sql .= ', KEY ' . $db->quoteName('idx_id_order') . ' (' . $db->quoteName('id_order') . ')';

        if ($this->identifierField !== '') {
            $sql .= ', KEY ' . $db->quoteName('idx_' . $this->identifierField)
                . ' (' . $db->quoteName($this->identifierField) . ')';
        }

        // The method index: (method, action) when the table has an action.
        if ($this->methodField !== '') {
            $methodIndex = $this->methodIndex($this->declaresColumn('action'));
            $sql .= ', KEY ' . $db->quoteName($methodIndex['name'])
                . ' (' . implode(', ', array_map([$db, 'quoteName'], $methodIndex['columns'])) . ')';
        }

        return $sql . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /**
     * Create the table when it is missing and add what it lacks: the columns
     * logs.xml declares and the method index.
     *
     * DDL: MySQL commits an open transaction before it, so this is called only
     * where none is open (an install script, or Plugin::ensureLogTable() after
     * it has asked). Inside an open transaction it refuses, by the table's
     * name and what it lacks, before any statement runs.
     *
     * @param Closure $report fn(string $detail): void - told of each failure; a
     *                        failed column or index does not stop the others.
     *
     * @return bool False when the table could not be created; true otherwise.
     *
     * @throws RuntimeException When a transaction is open on the connection.
     *
     * @since  2.0.0
     */
    public function complete(Closure $report): bool
    {
        if (TransactionState::isOpen($this->db)) {
            throw new RuntimeException(
                'Log table ' . $this->tableName . ' lacks ' . (implode(', ', $this->lacks()) ?: 'nothing')
                . '; it is not created or completed inside an open transaction, which MySQL would commit.',
            );
        }

        try {
            $this->db->setQuery($this->createStatement());
            $this->db->execute();

            $columns = $this->addMissingColumns($report);

            $this->addMissingMethodIndex($columns, $report);

            return true;
        } catch (Throwable $e) {
            $report('Log table ' . $this->tableName . ' could not be created (' . $e->getCode() . ') ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Whether logs.xml declares a column.
     *
     * @param string $name The column name.
     *
     *
     * @since  2.0.0
     */
    private function declaresColumn(string $name): bool
    {
        foreach ($this->xml->fields->fieldset->field as $field) {
            if (strcasecmp((string) $field['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Name and columns of the method index.
     *
     * @param bool $withAction Whether the table has an action column.
     *
     * @return array{name: string, columns: string[]}
     *
     * @since  2.0.0
     */
    private function methodIndex(bool $withAction): array
    {
        return $withAction
            ? ['name' => 'idx_' . $this->methodField . '_action', 'columns' => [$this->methodField, 'action']]
            : ['name' => 'idx_' . $this->methodField, 'columns' => [$this->methodField]];
    }

    /**
     * The columns complete() adds to a table that lacks them: the core method
     * column and every logs.xml column, with the definition each is added with.
     *
     * @return array<string, string> Column name => definition.
     *
     * @since  2.0.0
     */
    private function wantedColumns(): array
    {
        $wanted = [];

        if ($this->methodField !== '') {
            $wanted[$this->methodField] = self::METHOD_COLUMN_SQL;
        }

        foreach ($this->xml->fields->fieldset->field as $field) {
            $name = (string) $field['name'];

            if ($name === '' || $this->isCoreColumn($name)) {
                continue;
            }

            // A column added to a table that already has rows must be
            // nullable or carry a default: existing rows have no value for it.
            $wanted[$name] = $this->declaredDefinition($field);
        }

        return $wanted;
    }

    /**
     * The definition of a column logs.xml declares: its type, and its default
     * or NULL.
     *
     * MySQL 8 takes a default for a JSON, TEXT or BLOB column only when it is
     * written as an expression, even a literal one; MariaDB accepts that form
     * as well, so it is the one both are given.
     *
     * @param object $field The field element.
     *
     * @return string E.g. "varchar(32) NOT NULL DEFAULT ''", "JSON NOT NULL DEFAULT ('{}')".
     *
     * @since  2.0.0
     */
    private function declaredDefinition(object $field): string
    {
        $type = isset($field['mysql_type']) ? (string) $field['mysql_type'] : 'VARCHAR(255)';
        $default = isset($field['default']) ? (string) $field['default'] : 'NULL';

        if ($default === 'NULL') {
            return $type . ' DEFAULT NULL';
        }

        $literal = $this->db->quote($default);

        if (preg_match('/^\s*(json|(tiny|medium|long)?(text|blob))\b/i', $type) === 1) {
            return $type . ' NOT NULL DEFAULT (' . $literal . ')';
        }

        return $type . ' NOT NULL DEFAULT ' . $literal;
    }

    /**
     * Whether the table has the method index, by name.
     *
     * @param array<string, true> $columns The table's columns, lower-cased.
     *
     *
     * @since  2.0.0
     */
    private function hasMethodIndex(array $columns): bool
    {
        $db = $this->db;
        $index = $this->methodIndex(isset($columns['action']));

        foreach ($db->setQuery('SHOW INDEX FROM ' . $db->quoteName($this->tableName))->loadObjectList() ?: [] as $key) {
            if (strcasecmp((string) $key->Key_name, $index['name']) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add the core method column and any logs.xml column the table lacks.
     *
     * CREATE TABLE IF NOT EXISTS does nothing where the table already exists,
     * so a field added to logs.xml in a later plugin version, and the core
     * method column on a table created before it existed, arrive here.
     *
     * @param Closure $report fn(string $detail): void.
     *
     * @return array<string, true> The table's columns afterwards, lower-cased.
     *
     * @since  2.0.0
     */
    private function addMissingColumns(Closure $report): array
    {
        $db = $this->db;

        // The live column list, never getTableColumns(): the table may have
        // been created moments ago, and a cached answer would miss every column.
        $db->setQuery('SHOW COLUMNS FROM ' . $db->quoteName($this->tableName));

        $existing = [];

        foreach ($db->loadObjectList() ?: [] as $column) {
            $existing[strtolower((string) $column->Field)] = true;
        }

        if (!$existing) {
            return [];
        }

        foreach ($this->wantedColumns() as $name => $definition) {
            if (isset($existing[strtolower($name)])) {
                continue;
            }

            try {
                $db->setQuery(
                    'ALTER TABLE ' . $db->quoteName($this->tableName)
                    . ' ADD COLUMN ' . $db->quoteName($name) . ' ' . $definition,
                );
                $db->execute();

                $existing[strtolower($name)] = true;
            } catch (Throwable $e) {
                // One bad field declaration must not stop the others.
                $report(
                    'Log column "' . $name . '" could not be added to ' . $this->tableName . ' (' . $e->getCode() . ') ' . $e->getMessage(),
                );
            }
        }

        return $existing;
    }

    /**
     * Add the method index when the table lacks it.
     *
     * Method-level reads filter on the method column and, where the table
     * has one, the action; without the index each read walks the whole log.
     *
     * @param array<string, true> $columns The table's columns, lower-cased.
     * @param Closure $report fn(string $detail): void.
     *
     *
     * @since  2.0.0
     */
    private function addMissingMethodIndex(array $columns, Closure $report): void
    {
        if ($this->methodField === '' || !isset($columns[strtolower($this->methodField)])) {
            return;
        }

        $db = $this->db;
        $index = $this->methodIndex(isset($columns['action']));

        try {
            if ($this->hasMethodIndex($columns)) {
                return;
            }

            $db->setQuery(
                'ALTER TABLE ' . $db->quoteName($this->tableName)
                . ' ADD INDEX ' . $db->quoteName($index['name'])
                . ' (' . implode(', ', array_map([$db, 'quoteName'], $index['columns'])) . ')',
            );
            $db->execute();
        } catch (Throwable $e) {
            // A missing index slows method-level reads; it never stops a write.
            $report(
                'Log index "' . $index['name'] . '" could not be added to ' . $this->tableName . ' (' . $e->getCode() . ') ' . $e->getMessage(),
            );
        }
    }
}
