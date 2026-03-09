<?php
/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Plugin
 * @version     3.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2025 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Base Plugin Class
 *
 * Abstract base for all Alfa commerce plugins (payments, shipments, etc.).
 * Provides the shared logging system, utility methods, and plugin lifecycle.
 *
 * ═══════════════════════════════════════════════════════════════
 *  LOGGING SYSTEM
 * ═══════════════════════════════════════════════════════════════
 *
 * Every plugin can maintain its own log table. The table schema is
 * defined in the plugin's params/logs.xml file. The system:
 *
 *   - Auto-creates the table on first use (cached per request)
 *   - Merges XML-defined defaults with your data
 *   - Provides a single-method API for writing logs
 *
 * Usage in any plugin:
 *
 *   // Write a log entry — pass only what you have
 *   $logId = $this->log([
 *       'id_order'          => $order->id,
 *       'id_order_payment'  => $paymentId,
 *       'transaction_id'    => $txnId,
 *       'status'            => 'completed',
 *       'response_raw'      => $gatewayResponse,
 *   ]);
 *
 *   // Update an existing log (pass 'id' to update instead of insert)
 *   $this->log([
 *       'id'                => $logId,
 *       'id_order'          => $order->id,
 *       'status'            => 'refunded',
 *       'response_raw'      => $refundResponse,
 *   ]);
 *
 *   // Read logs for an order
 *   $logs = $this->loadLogs($orderId);
 *
 *   // Read logs for a specific payment/shipment within an order
 *   $logs = $this->loadLogs($orderId, $paymentId);
 *
 *   // Read logs with filters
 *   $logs = $this->loadLogs($orderId, 0, [
 *       'status' => 'completed',
 *       'created_on' => ['>=', '2025-01-01'],
 *   ]);
 *
 *   // Delete logs
 *   $this->deleteLog($orderId, $paymentId);
 *
 *   // Get XML schema (for the logs view template)
 *   $xml = $this->getLogsSchema();
 *
 * ═══════════════════════════════════════════════════════════════
 *  LOG TABLE NAMING
 * ═══════════════════════════════════════════════════════════════
 *
 * Each plugin gets its own table:
 *   #__alfa_payments_standard_logs   (for plugin type=alfa-payments, name=standard)
 *   #__alfa_shipments_standard_logs  (for plugin type=alfa-shipments, name=standard)
 *   #__alfa_payments_stripe_logs     (for plugin type=alfa-payments, name=stripe)
 *
 * Table has these mandatory columns:
 *   id              int AUTO_INCREMENT PRIMARY KEY
 *   id_order        int NOT NULL
 *
 * Plus all columns defined in the plugin's params/logs.xml.
 *
 * ═══════════════════════════════════════════════════════════════
 *
 * Path: administrator/components/com_alfa/src/Plugin/Plugin.php
 *
 * @since  3.0.0
 */

/**
 * @package     Alfa.Component
 * @subpackage  Administrator.Plugin
 * @version     3.0.0
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   2025 Easylogic CO LP
 * @license     GNU General Public License version 2 or later
 *
 * Base Plugin Class
 *
 * Abstract base for all Alfa commerce plugins (payments, shipments, etc.).
 * Provides the shared logging system, utility methods, and plugin lifecycle.
 *
 * ═══════════════════════════════════════════════════════════════
 *  LOGGING SYSTEM
 * ═══════════════════════════════════════════════════════════════
 *
 * Every plugin can maintain its own log table. The table schema is
 * defined in the plugin's params/logs.xml file. The system:
 *
 *   - Auto-creates the table on first use (cached per request)
 *   - Merges XML-defined defaults with your data
 *   - Provides a single-method API for writing logs
 *
 * Usage in any plugin:
 *
 *   // Write a log entry — pass only what you have
 *   $logId = $this->log([
 *       'id_order'          => $order->id,
 *       'id_order_payment'  => $paymentId,
 *       'transaction_id'    => $txnId,
 *       'status'            => 'completed',
 *       'response_raw'      => $gatewayResponse,
 *   ]);
 *
 *   // Update an existing log (pass 'id' to update instead of insert)
 *   $this->log([
 *       'id'                => $logId,
 *       'id_order'          => $order->id,
 *       'status'            => 'refunded',
 *       'response_raw'      => $refundResponse,
 *   ]);
 *
 *   // Read logs for an order
 *   $logs = $this->loadLogs($orderId);
 *
 *   // Read logs for a specific payment/shipment within an order
 *   $logs = $this->loadLogs($orderId, $paymentId);
 *
 *   // Read logs with filters
 *   $logs = $this->loadLogs($orderId, 0, [
 *       'status' => 'completed',
 *       'created_on' => ['>=', '2025-01-01'],
 *   ]);
 *
 *   // Delete logs
 *   $this->deleteLog($orderId, $paymentId);
 *
 *   // Get XML schema (for the logs view template)
 *   $xml = $this->getLogsSchema();
 *
 * ═══════════════════════════════════════════════════════════════
 *  LOG TABLE NAMING
 * ═══════════════════════════════════════════════════════════════
 *
 * Each plugin gets its own table:
 *   #__alfa_payments_standard_logs   (for plugin type=alfa-payments, name=standard)
 *   #__alfa_shipments_standard_logs  (for plugin type=alfa-shipments, name=standard)
 *   #__alfa_payments_stripe_logs     (for plugin type=alfa-payments, name=stripe)
 *
 * Table has these mandatory columns:
 *   id              int AUTO_INCREMENT PRIMARY KEY
 *   id_order        int NOT NULL
 *
 * Plus all columns defined in the plugin's params/logs.xml.
 *
 * ═══════════════════════════════════════════════════════════════
 *
 * Path: administrator/components/com_alfa/src/Plugin/Plugin.php
 *
 * @since  3.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Exception;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use SimpleXMLElement;

defined('_JEXEC') or die;

abstract class Plugin extends CMSPlugin implements SubscriberInterface
{
    /**
     * Auto-load language files.
     *
     * @var bool
     * @since  3.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Application object.
     *
     * @var \Joomla\CMS\Application\CMSApplication
     * @since  3.0.0
     */
    protected $app;

    /**
     * URL for the order completion page. Payment plugins may override.
     *
     * @since  3.0.0
     */
    protected string $completePageUrl;

    /**
     * Identifier field for filtering logs by payment/shipment.
     *
     * Payment plugins set this to 'id_order_payment'.
     * Shipment plugins set this to 'id_order_shipment'.
     * Set by PaymentsPlugin / ShipmentsPlugin base classes.
     *
     * @since  3.0.0
     */
    protected string $logIdentifierField = '';

    /**
     * Cached parsed logs.xml schema. Null = not loaded yet.
     *
     * @var SimpleXMLElement|null|false
     * @since  3.0.0
     */
    private $logsSchemaCache = null;

    /**
     * Cached default values from logs.xml. Null = not built yet.
     *
     * @since  3.0.0
     */
    private ?array $logsDefaultsCache = null;

    /**
     * Whether the log table has been ensured this request.
     *
     * @since  3.0.0
     */
    private bool $logTableEnsured = false;

    /**
     * Constructor.
     *
     * @param array $config Plugin configuration
     *
     * @since   3.0.0
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->completePageUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_completed';
    }

    // ═════════════════════════════════════════════════════════
    //  ABSTRACT HOOKS — Every plugin must implement
    // ═════════════════════════════════════════════════════════

    /**
     * Cart display hook.
     *
     * Every plugin MUST set a layout, even if it is empty.
     * Called during cart rendering for each active method.
     *
     * Available on $event:
     *   $event->getCart()    → Cart object with items, totals, and user info
     *   $event->getMethod()  → The payment/shipment method record
     *   $event->setLayout('default_cart_view')   → Layout file in tmpl/
     *   $event->setLayoutData([...])             → Data for the layout
     *
     * Usage example:
     * <code>
     * $cart   = $event->getCart();
     * $method = $event->getMethod();
     *
     * $event->setLayout('default_cart_view');
     * $event->setLayoutData([
     *     'method' => $method,
     *     'cart'   => $cart,
     * ]);
     * </code>
     *
     * @param object $event CartViewEvent
     *
     *
     * @since   3.0.0
     */
    abstract public function onCartView($event): void;

    /**
     * Item display hook.
     *
     * Every plugin MUST set a layout, even if it is empty.
     * Called during product page rendering for each active method.
     *
     * Available on $event:
     *   $event->getItem()    → The product item object
     *   $event->getMethod()  → The payment/shipment method record
     *   $event->setLayout('default_item_view')   → Layout file in tmpl/
     *   $event->setLayoutData([...])             → Data for the layout
     *
     * Usage example:
     * <code>
     * $item   = $event->getItem();
     * $method = $event->getMethod();
     *
     * $event->setLayout('default_item_view');
     * $event->setLayoutData([
     *     'method' => $method,
     *     'item'   => $item,
     * ]);
     * </code>
     *
     * @param object $event ItemViewEvent
     *
     *
     * @since   3.0.0
     */
    abstract public function onItemView($event): void;

    // ═════════════════════════════════════════════════════════
    //  LOGGING — Public API
    // ═════════════════════════════════════════════════════════

    /**
     * Write a log entry.
     *
     * Pass only the fields you have — missing fields get defaults from logs.xml.
     * If 'id' is set and non-empty, the row is UPDATED (REPLACE INTO).
     * If 'id' is null/empty/0, a new row is INSERTED.
     *
     * The 'id_order' field is REQUIRED.
     *
     * Examples:
     *
     *   // Insert a new log
     *   $logId = $this->log([
     *       'id_order'         => $order->id,
     *       'id_order_payment' => $paymentId,
     *       'transaction_id'   => 'txn_123',
     *       'status'           => 'completed',
     *       'response_raw'     => json_encode($response),
     *       'created_on'       => Factory::getDate()->format('Y-m-d H:i:s'),
     *       'created_by'       => Factory::getApplication()->getIdentity()->id,
     *   ]);
     *
     *   // Update an existing log (pass the id)
     *   $this->log([
     *       'id'       => $logId,
     *       'id_order' => $order->id,
     *       'status'   => 'refunded',
     *   ]);
     *
     * @param array $data Log data (key => value pairs matching logs.xml fields)
     *
     * @return int The inserted/updated log row ID, or 0 on failure
     *
     * @since   3.0.0
     */
    protected function log(array $data): int
    {
        $app = $this->getApplication();

        // Validate required field
        if (empty($data['id_order'])) {
            $app->enqueueMessage('log(): id_order is required', 'error');

            return 0;
        }

        // Get default values from logs.xml (cached)
        $defaults = $this->getLogsDefaults();

        if ($defaults === null) {
            // No logs.xml found — nothing to log
            return 0;
        }

        // Ensure the log table exists (once per request)
        if (!$this->ensureLogTable()) {
            return 0;
        }

        // Merge: defaults ← user data (user data wins)
        $merged = array_merge($defaults, $data);

        // Build columns and values for REPLACE INTO
        $db = Factory::getContainer()->get('DatabaseDriver');
        $insertColumns = [];
        $insertValues = [];

        foreach ($merged as $key => $value) {
            // Skip empty 'id' — let auto-increment handle new rows
            if ($key === 'id' && empty($value)) {
                continue;
            }

            $insertColumns[] = $db->quoteName($key);

            // MySQL functions like NOW() — don't quote them
            if (is_string($value) && str_ends_with($value, '()')) {
                $insertValues[] = $value;
            } else {
                $insertValues[] = $db->quote($value);
            }
        }

        $tableName = $this->getLogTableName();

        $query = 'REPLACE INTO ' . $db->quoteName($tableName)
            . ' (' . implode(', ', $insertColumns) . ')'
            . ' VALUES (' . implode(', ', $insertValues) . ')';

        try {
            $db->setQuery($query);
            $db->execute();

            return (int) $db->insertid();
        } catch (Exception $e) {
            $app->enqueueMessage(
                '(' . $e->getCode() . ') ' . $e->getMessage() . ' — Log data was not inserted.',
                'error',
            );

            return 0;
        }
    }

    /**
     * Load log entries for an order.
     *
     * Examples:
     *
     *   // All logs for an order
     *   $logs = $this->loadLogs($orderId);
     *
     *   // Logs for a specific payment within an order
     *   $logs = $this->loadLogs($orderId, $paymentId);
     *
     *   // Logs with extra filters
     *   $logs = $this->loadLogs($orderId, 0, [
     *       'status'     => 'completed',                          // Simple: field = value
     *       'amount'     => ['>', 100],                           // Operator: field > value
     *       'status'     => ['IN', ['pending', 'completed']],     // IN clause
     *       '__raw'      => 'DATE(created_on) = CURDATE()',       // Raw SQL
     *   ]);
     *
     * @param int $orderId Order ID
     * @param int $identifierId Payment/shipment ID (0 = all)
     * @param array $filters Optional extra filters
     * @param bool $asArray true = assoc arrays, false = objects
     *
     * @return array|null Array of log rows, or null if table doesn't exist
     *
     * @since   3.0.0
     */
    protected function loadLogs(int $orderId, int $identifierId = 0, array $filters = [], bool $asArray = true): ?array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName($this->getLogTableName()))
            ->where($db->quoteName('id_order') . ' = ' . (int) $orderId)
            ->order('id DESC');

        // Filter by payment/shipment ID if set
        if ($identifierId > 0 && !empty($this->logIdentifierField)) {
            $query->where($db->quoteName($this->logIdentifierField) . ' = ' . (int) $identifierId);
        }

        // Apply extra filters
        $this->applyLogFilters($query, $filters, $db);

        try {
            $db->setQuery($query);

            return $asArray ? $db->loadAssocList() : $db->loadObjectList();
        } catch (Exception $e) {
            // Table doesn't exist yet (error 1146) — return null, not an error
            if ($e->getCode() == 1146) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Delete log entries.
     *
     * @param int $orderId Order ID
     * @param int $identifierId Payment/shipment ID (0 = delete all for order)
     *
     *
     * @since   3.0.0
     */
    protected function deleteLog(int $orderId, int $identifierId = 0): void
    {
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->delete($db->quoteName($this->getLogTableName()))
            ->where($db->quoteName('id_order') . ' = ' . (int) $orderId);

        if ($identifierId > 0 && !empty($this->logIdentifierField)) {
            $query->where($db->quoteName($this->logIdentifierField) . ' = ' . (int) $identifierId);
        }

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            // Table doesn't exist — nothing to delete
            if ($e->getCode() != 1146) {
                throw $e;
            }
        }
    }

    /**
     * Get the parsed logs.xml schema.
     *
     * Used by the logs view template to build table headings.
     * Returns the SimpleXMLElement or null if no logs.xml exists.
     *
     * Example:
     *   $xml = $this->getLogsSchema();
     *   foreach ($xml->fields->fieldset->field as $field) { ... }
     *
     *
     * @since   3.0.0
     */
    protected function getLogsSchema(): ?SimpleXMLElement
    {
        $schema = $this->loadLogsSchema();

        return ($schema instanceof SimpleXMLElement) ? $schema : null;
    }

    // ═════════════════════════════════════════════════════════
    //  LOGGING — Internal (private)
    // ═════════════════════════════════════════════════════════

    /**
     * Get the log table name for this plugin.
     *
     * Format: #__alfa_{type}_{name}_logs
     * Example: #__alfa_payments_standard_logs
     *
     * @param int $prefix 0 = no prefix, 1 = real prefix, 2 = #__ prefix
     *
     *
     * @since   3.0.0
     */
    protected function getLogTableName(int $prefix = 2): string
    {
        // Normalize type: "alfa-payments" → "alfa_payments"
        $type = str_replace('-', '_', $this->_type);
        $name = $this->_name;
        $bare = $type . '_' . $name . '_logs';

        if ($prefix === 0) {
            return $bare;
        }

        if ($prefix === 1) {
            $db = Factory::getContainer()->get('DatabaseDriver');

            return $db->getPrefix() . $bare;
        }

        return '#__' . $bare;
    }

    /**
     * Load and cache the logs.xml schema.
     *
     * @return SimpleXMLElement|false Parsed XML or false if not found
     *
     * @since   3.0.0
     */
    private function loadLogsSchema()
    {
        // Already cached this request
        if ($this->logsSchemaCache !== null) {
            return $this->logsSchemaCache;
        }

        $formFile = JPATH_PLUGINS . '/' . $this->_type . '/' . $this->_name . '/params/logs.xml';

        if (!file_exists($formFile)) {
            $this->logsSchemaCache = false;

            return false;
        }

        $xml = simplexml_load_file($formFile);

        if (!$xml || !isset($xml->fields->fieldset->field)) {
            $this->getApplication()->enqueueMessage(
                'Invalid logs.xml structure in plugin: ' . $this->_name,
                'warning',
            );

            $this->logsSchemaCache = false;

            return false;
        }

        $this->logsSchemaCache = $xml;

        return $xml;
    }

    /**
     * Get default values for all log fields (cached).
     *
     * Builds an array from logs.xml with field names as keys
     * and their default values. Includes mandatory columns:
     *   id, id_order, and the logIdentifierField (if set).
     *
     * @return array|null Defaults array, or null if no logs.xml
     *
     * @since   3.0.0
     */
    private function getLogsDefaults(): ?array
    {
        // Already cached
        if ($this->logsDefaultsCache !== null) {
            return $this->logsDefaultsCache;
        }

        $xml = $this->loadLogsSchema();

        if (!$xml) {
            return null;
        }

        // Start with mandatory columns
        $defaults = [
            'id' => '',
            'id_order' => '',
        ];

        // Add the identifier column default (id_order_payment / id_order_shipment)
        if (!empty($this->logIdentifierField)) {
            $defaults[$this->logIdentifierField] = '';
        }

        // Add fields from logs.xml with their default values
        foreach ($xml->fields->fieldset->field as $field) {
            $name = (string) $field['name'];
            $default = isset($field['default']) ? (string) $field['default'] : 'NULL';
            $defaults[$name] = $default;
        }

        $this->logsDefaultsCache = $defaults;

        return $defaults;
    }

    /**
     * Ensure the log table exists in the database.
     *
     * Runs CREATE TABLE IF NOT EXISTS once per request, then caches.
     * Builds the table schema from mandatory columns + logs.xml fields.
     *
     * Mandatory columns (always created):
     *   id              int AUTO_INCREMENT PRIMARY KEY
     *   id_order        int NOT NULL
     *   {identifier}    int DEFAULT NULL  (from logIdentifierField, e.g. id_order_payment)
     *
     * @return bool true if table is ready, false on failure
     *
     * @since   3.0.0
     */
    private function ensureLogTable(): bool
    {
        // Already ensured this request — skip
        if ($this->logTableEnsured) {
            return true;
        }

        $xml = $this->loadLogsSchema();

        if (!$xml) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $tableName = $this->getLogTableName();

        // Build CREATE TABLE statement with mandatory columns
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteName($tableName) . ' ('
            . $db->quoteName('id') . ' INT(11) AUTO_INCREMENT PRIMARY KEY NOT NULL, '
            . $db->quoteName('id_order') . ' INT(11) NOT NULL';

        // Add the identifier column if set (id_order_payment / id_order_shipment)
        // This column links each log entry to a specific payment or shipment.
        if (!empty($this->logIdentifierField)) {
            $sql .= ', ' . $db->quoteName($this->logIdentifierField) . ' INT(11) DEFAULT NULL';
        }

        // Add columns from logs.xml
        foreach ($xml->fields->fieldset->field as $field) {
            $name = (string) $field['name'];
            $type = isset($field['mysql_type']) ? (string) $field['mysql_type'] : 'VARCHAR(255)';
            $default = isset($field['default']) ? (string) $field['default'] : 'NULL';
            $defaultSql = ($default === 'NULL')
                ? 'DEFAULT NULL'
                : 'NOT NULL DEFAULT ' . $db->quote($default);

            $sql .= ', ' . $db->quoteName($name) . ' ' . $type . ' ' . $defaultSql;
        }

        // Add index on id_order for fast lookups
        $sql .= ', KEY ' . $db->quoteName('idx_id_order') . ' (' . $db->quoteName('id_order') . ')';

        // Add index on the identifier column for filtered lookups
        if (!empty($this->logIdentifierField)) {
            $sql .= ', KEY ' . $db->quoteName('idx_' . $this->logIdentifierField)
                . ' (' . $db->quoteName($this->logIdentifierField) . ')';
        }

        $sql .= ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        try {
            $db->setQuery($sql);
            $db->execute();

            $this->logTableEnsured = true;

            return true;
        } catch (Exception $e) {
            $this->getApplication()->enqueueMessage(
                'Failed to create log table: ' . $e->getMessage(),
                'error',
            );

            return false;
        }
    }

    /**
     * Apply filter conditions to a log query.
     *
     * Supports:
     *   ['status' => 'completed']                       → status = 'completed'
     *   ['amount' => ['>', 100]]                        → amount > 100
     *   ['status' => ['IN', ['pending', 'completed']]]  → status IN ('pending', 'completed')
     *   ['__raw'  => 'DATE(created_on) = CURDATE()']    → raw SQL
     *
     * @param object $query Query builder
     * @param array $filters Filter definitions
     * @param object $db Database driver
     *
     *
     * @since   3.0.0
     */
    private function applyLogFilters($query, array $filters, $db): void
    {
        foreach ($filters as $key => $condition) {
            // Raw SQL condition
            if ($key === '__raw' && is_string($condition)) {
                $query->where($condition);

                continue;
            }

            $field = $db->quoteName($key);

            // Advanced format: ['operator', value]
            if (is_array($condition) && count($condition) >= 2) {
                [$operator, $value] = $condition;
                $operator = strtoupper(trim($operator));

                switch ($operator) {
                    case 'IN':
                    case 'NOT IN':
                        $escaped = array_map([$db, 'quote'], (array) $value);
                        $query->where($field . ' ' . $operator . ' (' . implode(', ', $escaped) . ')');

                        break;

                    case '=':
                    case '!=':
                    case '<>':
                    case '<':
                    case '>':
                    case '<=':
                    case '>=':
                    case 'LIKE':
                        $query->where($field . ' ' . $operator . ' ' . $db->quote($value));

                        break;

                    default:
                        // Unsupported operator — skip silently
                        break;
                }
            } else {
                // Simple format: field = value
                $query->where($field . ' = ' . $db->quote($condition));
            }
        }
    }

    // ═════════════════════════════════════════════════════════
    //  UTILITY
    // ═════════════════════════════════════════════════════════

    /**
     * Check if a value falls within a numeric or string range.
     *
     * Compares numerically when all values are numeric,
     * otherwise compares as strings (lexicographic).
     *
     * @param mixed $value The value to check
     * @param mixed $min Range minimum (inclusive)
     * @param mixed $max Range maximum (inclusive)
     *
     *
     * @since   3.0.0
     */
    protected function isValueInRange($value, $min, $max): bool
    {
        $valueStr = is_string($value) ? $value : strval($value);
        $minStr = is_string($min) ? $min : strval($min);
        $maxStr = is_string($max) ? $max : strval($max);

        // Numeric comparison when all values are numeric
        if (is_numeric($valueStr) && is_numeric($minStr) && is_numeric($maxStr)) {
            return $valueStr >= $minStr && $valueStr <= $maxStr;
        }

        // String comparison (lexicographic)
        return strcmp($valueStr, $minStr) >= 0 && strcmp($valueStr, $maxStr) <= 0;
    }
}
