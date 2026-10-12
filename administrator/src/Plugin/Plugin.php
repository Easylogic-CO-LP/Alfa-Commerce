<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Base Plugin Class
 *
 * Abstract base for all Alfa commerce plugins (payments, shipments, etc.).
 * Provides the shared logging system, utility methods, and plugin lifecycle.
 *
 *  Logging system
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
 *   // Write a log entry: pass only what you have
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
 *  METHOD-LEVEL LOG (facts about a method, not about an order)
 *
 * A setup checkup, a connection check or a verified notification belongs to
 * a payment or shipment METHOD. Such rows live in the same table with
 * id_order = 0 and the method id in the family's method column
 * (id_payment_method / id_shipment_method):
 *
 *   $this->logForMethod($methodId, ['action' => 'connection_check', 'note' => '...']);
 *   $rows = $this->loadMethodLogs($methodId, ['action' => 'connection_check'], 1);
 *
 * Both create or complete the table themselves; never write id_order 0 or
 * a negative id_order through log().
 *
 *  Log table naming
 *
 * Each plugin gets its own table:
 *   #__alfa_payments_standard_logs   (for plugin type=alfa-payments, name=standard)
 *   #__alfa_shipments_standard_logs  (for plugin type=alfa-shipments, name=standard)
 *   #__alfa_payments_mygateway_logs  (for plugin type=alfa-payments, name=mygateway)
 *
 * Table has these mandatory columns:
 *   id              int AUTO_INCREMENT PRIMARY KEY
 *   id_order        int NOT NULL
 *   {identifier}    int DEFAULT NULL       (logIdentifierField)
 *   {method}        int NOT NULL DEFAULT 0 (logMethodField), indexed with
 *                   action when the table has an action column
 *
 * Plus all columns defined in the plugin's params/logs.xml. A logs.xml
 * field with the name of a mandatory column is ignored: core owns it.
 *
 * A failed write is never shown to a shopper. The detail goes to the
 * plugin's text log (logCategory()); an administrator sees one generic
 * message per request.
 *
 *  TEXT LOG FILE (Joomla Log, separate from the log TABLE above)
 *
 * The table above is the merchant-facing audit trail shown in the admin
 * modal. The text file is the operator-facing one: CRITICAL lines a human
 * has to act on ("reconcile manually"), written through Joomla's logger.
 *
 * Both are per plugin. Write to yours through the accessor, never a
 * literal:
 *
 *   Log::add('My gateway: ...', Log::CRITICAL, $this->logCategory());
 *
 * The category is derived, so it cannot drift from the file:
 *   type=alfa-payments  name=mygateway
 *     -> category  com_alfa.payments.mygateway
 *     -> file      administrator/logs/alfa_payments_mygateway.php
 *
 * PaymentsPlugin / ShipmentsPlugin register the logger in their
 * constructors, so a gateway does not have to remember to, and a gateway
 * that is not installed can no longer take another one's lines down with
 * it. A SHARED category cannot do this: Joomla routes a message to every
 * logger registered for its category, so one shared name means every
 * plugin's lines land in whichever plugin happened to register first, and
 * nowhere at all when that plugin is absent.
 *
 * Path: administrator/components/com_alfa/src/Plugin/Plugin.php
 *
 * @since  1.0.0
 */

namespace Alfa\Component\Alfa\Administrator\Plugin;

use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Site\Service\Place\PlaceTree;
use Exception;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use SimpleXMLElement;
use Throwable;

defined('_JEXEC') or die;

abstract class Plugin extends CMSPlugin implements SubscriberInterface
{
    /**
     * Auto-load language files.
     *
     * @var bool
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Application object.
     *
     * @var \Joomla\CMS\Application\CMSApplication
     * @since  1.0.0
     */
    protected $app;

    /**
     * URL for the order completion page. Payment plugins may override.
     *
     * @since  1.0.0
     */
    protected string $completePageUrl;

    /**
     * Identifier field for filtering logs by payment/shipment.
     *
     * Payment plugins set this to 'id_order_payment'.
     * Shipment plugins set this to 'id_order_shipment'.
     * Set by PaymentsPlugin / ShipmentsPlugin base classes.
     *
     * @since  1.0.0
     */
    protected string $logIdentifierField = '';

    /**
     * Method column of the log table, for method-level rows.
     *
     * Payment plugins set this to 'id_payment_method'.
     * Shipment plugins set this to 'id_shipment_method'.
     * Set by PaymentsPlugin / ShipmentsPlugin base classes. Empty means the
     * family keeps no method-level log.
     *
     * @since  1.14.0
     */
    protected string $logMethodField = '';

    /**
     * Whether the generic log-failure message was already shown this request.
     *
     * @since  1.14.0
     */
    private bool $logFailureShown = false;

    /**
     * Cached parsed logs.xml schema. Null = not loaded yet.
     *
     * @var SimpleXMLElement|null|false
     * @since  1.0.0
     */
    private $logsSchemaCache = null;

    /**
     * Cached default values from logs.xml. Null = not built yet.
     *
     * @since  1.0.0
     */
    private ?array $logsDefaultsCache = null;

    /**
     * Whether the log table has been ensured this request.
     *
     * @since  1.0.0
     */
    private bool $logTableEnsured = false;

    /**
     * Constructor.
     *
     * @param array $config Plugin configuration
     *
     * @since  1.0.0
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);

        $this->completePageUrl = 'index.php?option=com_alfa&view=cart&layout=default_order_completed';
    }

    //  ABSTRACT HOOKS: Every plugin must implement

    /**
     * Cart display hook.
     *
     * Every plugin MUST set a layout, even if it is empty.
     * Called during cart rendering for each active method.
     *
     * Available on $event:
     *   $event->getRequest() -> MethodViewRequest: methodId, methodType,
     *                          methodParams, isSelected, cartTotal (Money)
     *   $event->setLayout('default_cart_view')   -> Layout file in tmpl/
     *   $event->setLayoutData([...])             -> Data for the layout
     *
     * Usage example:
     * <code>
     * $request = $event->getRequest();
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
     * @since  1.0.0
     */
    abstract public function onCartView($event): void;

    /**
     * Cart assets hook: optional; a plugin with no script leaves it alone.
     *
     * WHERE A PANEL'S CODE IS DECLARED. Called once per plugin while the cart
     * page is built (storefront cart and order builder), for every plugin that
     * owns a published method (offered for this cart or not) and never
     * inside an AJAX answer. Declared here, the script is on the page before
     * the shopper's first click; declared in the layout, it is silently lost
     * the first time that layout arrives over JSON.
     *
     * <code>
     * $wa = $event->getWebAssetManager();
     * $wa->getRegistry()->addRegistryFile('media/plg_alfa-shipments_x/joomla.asset.json');
     * $wa->useScript('plg_alfa-shipments_x.cart')->useStyle('plg_alfa-shipments_x.cart');
     *
     * Text::script('PLG_ALFA_SHIPMENTS_X_SAVE_FAILED');
     * $event->getDocument()->addScriptOptions('plg_alfa-shipments_x', ['saveUrl' => $url]);
     * </code>
     *
     * Keep it to the plugin's own bootstrap: a heavy vendor SDK is that
     * script's business to fetch on first use. Values that change during
     * checkout (a chosen locker, an amount) belong on the panel as data
     * attributes, where the JSON re-render refreshes them.
     *
     * Name dependencies only from registry files this hook adds itself, plus
     * com_alfa's own (the cart pages register it): a dependency nobody
     * registered is raised while the head is compiled, outside any plugin
     * isolation, and takes the page down.
     *
     * @param object $event CartViewAssetsEvent
     *                      $event->getWebAssetManager(), ->getDocument(),
     *                      ->getKind(), ->getMethods()
     *
     *
     * @since  1.10.0
     */
    public function onCartViewAssets($event): void
    {
    }

    /**
     * Item display hook.
     *
     * Every plugin MUST set a layout, even if it is empty.
     * Called during product page rendering for each active method.
     *
     * Available on $event:
     *   $event->getItem()    -> The product item object
     *   $event->getMethod()  -> The payment/shipment method record
     *   $event->setLayout('default_item_view')   -> Layout file in tmpl/
     *   $event->setLayoutData([...])             -> Data for the layout
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
     * @since  1.0.0
     */
    abstract public function onItemView($event): void;

    //  LOGGING: Public API

    /**
     * Write a log entry.
     *
     * INSERT (no 'id', or an empty one): the columns you pass are written and
     * everything else takes its logs.xml default.
     *
     * UPDATE (an 'id' that exists): ONLY the columns you pass are written.
     * Every other column keeps the value it already had; defaults are NOT
     * re-applied. So amending one field is genuinely one field:
     *
     *   $this->log(['id' => $logId, 'id_order' => $order->id, 'status' => 'refunded']);
     *   // transaction_id, amount, created_on ... all untouched
     *
     * The 'id_order' field is REQUIRED either way.
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
     *   // Amend it later: only 'status' changes
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
     * @since  1.0.0
     */
    protected function log(array $data): int
    {
        // Validate required field
        if (empty($data['id_order'])) {
            $this->reportLogFailure('log(): id_order is required; the row was not written.');

            return 0;
        }

        return $this->writeLogRow($data);
    }

    /**
     * Write a METHOD-LEVEL log row: a fact about a payment or shipment method
     * rather than about an order (a setup checkup, a connection check, a
     * verified notification).
     *
     * The row is stored with id_order = 0 and $methodId in the family's
     * method column (id_payment_method / id_shipment_method); both are set
     * here and override anything $data carries for them. Every other column
     * behaves as in log(): an insert fills what is not passed from the
     * logs.xml defaults.
     *
     * Pass 'id' to amend an earlier method-level row of the SAME method; only
     * the passed columns change. An id that is not a method-level row of this
     * method is refused.
     *
     *   $this->logForMethod($methodId, [
     *       'action'             => 'connection_check',
     *       'transaction_status' => 'ok',
     *       'note'               => 'Credentials accepted',
     *   ]);
     *
     * The first call of a request creates or completes the log table when it
     * lacks something, as log() does, and never inside an open transaction.
     *
     * @param int $methodId The payment/shipment method id (> 0).
     * @param array $data Log data (key => value pairs matching logs.xml fields).
     *
     * @return int The inserted/updated log row ID, or 0 on failure.
     *
     * @since  1.14.0
     */
    protected function logForMethod(int $methodId, array $data): int
    {
        if ($methodId <= 0 || $this->logMethodField === '') {
            $this->reportLogFailure(
                'logForMethod(): ' . ($methodId <= 0 ? 'a positive method id is required' : 'this plugin family keeps no method-level log')
                . '; the row was not written.',
            );

            return 0;
        }

        $data['id_order'] = 0;
        $data[$this->logMethodField] = $methodId;

        $logId = (int) ($data['id'] ?? 0);

        if ($logId > 0 && !$this->isMethodLogRow($logId, $methodId)) {
            $this->reportLogFailure(
                'logForMethod(): row #' . $logId . ' is not a method-level row of method #' . $methodId . '; nothing was changed.',
            );

            return 0;
        }

        return $this->writeLogRow($data);
    }

    /**
     * Read METHOD-LEVEL log rows of one method, newest first.
     *
     * Only rows written by logForMethod() (id_order = 0) are returned; the
     * order-level rows of that method are loadLogs()'s business. Filters
     * take the loadLogs() format:
     *
     *   // the latest connection check in sandbox
     *   $last = $this->loadMethodLogs($methodId, [
     *       'action'      => 'connection_check',
     *       'environment' => 'sandbox',
     *   ], 1)[0] ?? null;
     *
     * The first call of a request creates or completes the log table, so a
     * missing table or method column reads as "nothing logged", never as an
     * error. Inside an open transaction a table that lacks something is left
     * as it is, and reads as "nothing logged" too.
     *
     * @param int $methodId The payment/shipment method id.
     * @param array $filters Optional extra filters (see loadLogs()).
     * @param int $limit Maximum rows; 0 = all.
     *
     * @return object[] The rows as objects; empty when there are none or the read failed.
     *
     * @since  1.14.0
     */
    protected function loadMethodLogs(int $methodId, array $filters = [], int $limit = 0): array
    {
        if ($methodId <= 0 || $this->logMethodField === '' || $this->getLogsDefaults() === null || !$this->ensureLogTable()) {
            return [];
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName($this->getLogTableName()))
                ->where($db->quoteName('id_order') . ' = 0')
                ->where($db->quoteName($this->logMethodField) . ' = ' . $methodId)
                ->order($db->quoteName('id') . ' DESC');

            $this->applyLogFilters($query, $filters, $db);

            if ($limit > 0) {
                $query->setLimit($limit);
            }

            return $db->setQuery($query)->loadObjectList() ?: [];
        } catch (Throwable $e) {
            $this->reportLogFailure(
                'loadMethodLogs(): the method-level log of method #' . $methodId . ' could not be read ('
                . $e->getCode() . ') ' . $e->getMessage(),
            );

            return [];
        }
    }

    /**
     * Whether a log row is a method-level row of the given method.
     *
     * @param int $logId The log row id.
     * @param int $methodId The method id.
     *
     *
     * @since  1.14.0
     */
    private function isMethodLogRow(int $logId, int $methodId): bool
    {
        if ($this->getLogsDefaults() === null || !$this->ensureLogTable()) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');

        try {
            return (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName($this->getLogTableName()))
                    ->where($db->quoteName('id') . ' = ' . $logId)
                    ->where($db->quoteName('id_order') . ' = 0')
                    ->where($db->quoteName($this->logMethodField) . ' = ' . $methodId),
            )->loadResult() === 1;
        } catch (Throwable $e) {
            $this->reportLogFailure('logForMethod(): row #' . $logId . ' could not be read (' . $e->getCode() . ') ' . $e->getMessage());

            return false;
        }
    }

    /**
     * The shared write of log() and logForMethod(): insert, or update when
     * $data carries an existing 'id'.
     *
     * @param array $data Log data, already validated by the caller.
     *
     * @return int The inserted/updated log row ID, or 0 on failure.
     *
     * @since  1.14.0
     */
    private function writeLogRow(array $data): int
    {
        // Get default values from logs.xml (cached)
        $defaults = $this->getLogsDefaults();

        if ($defaults === null) {
            // No logs.xml found: nothing to log
            return 0;
        }

        // Ensure the log table exists (once per request)
        if (!$this->ensureLogTable()) {
            return 0;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $tableName = $this->getLogTableName();
        $logId = (int) ($data['id'] ?? 0);

        try {
            if ($logId > 0) {
                // UPDATE: only what the caller passed
                $assignments = [];

                foreach ($data as $key => $value) {
                    if ($key === 'id') {
                        continue;
                    }

                    $assignments[] = $db->quoteName($key) . ' = ' . $this->logValueSql($db, $value);
                }

                if (!$assignments) {
                    // Nothing but the id: no write, but not a failure either.
                    return $logId;
                }

                $db->setQuery(
                    'UPDATE ' . $db->quoteName($tableName)
                    . ' SET ' . implode(', ', $assignments)
                    . ' WHERE ' . $db->quoteName('id') . ' = ' . $logId,
                );
                $db->execute();

                return $logId;
            }

            // INSERT: defaults fill whatever was not passed
            $merged = array_merge($defaults, $data);
            unset($merged['id']);

            $columns = [];
            $values = [];

            foreach ($merged as $key => $value) {
                $columns[] = $db->quoteName($key);
                $values[] = $this->logValueSql($db, $value);
            }

            $db->setQuery(
                'INSERT INTO ' . $db->quoteName($tableName)
                . ' (' . implode(', ', $columns) . ')'
                . ' VALUES (' . implode(', ', $values) . ')',
            );
            $db->execute();

            return (int) $db->insertid();
        } catch (Throwable $e) {
            $this->reportLogFailure(
                'Log row ' . ($logId > 0 ? '#' . $logId . ' ' : '') . $this->logRowLabel($data)
                . ' not written to ' . $tableName . ' (' . $e->getCode() . ') ' . $e->getMessage(),
            );

            return 0;
        }
    }

    /**
     * Which row a failed write meant, for the text log: its action and the
     * order or method it belongs to.
     *
     * @param array $data The row's data.
     *
     * @return string E.g. '"refund" for order #42'.
     *
     * @since  1.14.0
     */
    private function logRowLabel(array $data): string
    {
        $action = isset($data['action']) && \is_scalar($data['action']) ? '"' . $data['action'] . '" ' : '';
        $orderId = (int) ($data['id_order'] ?? 0);

        if ($orderId !== 0) {
            return $action . 'for order #' . $orderId;
        }

        $methodId = $this->logMethodField !== '' ? (int) ($data[$this->logMethodField] ?? 0) : 0;

        return $action . ($methodId > 0 ? 'for method #' . $methodId : 'without an order');
    }

    /**
     * Record a log-table failure where it can be acted on, and nowhere else.
     *
     * The detail (which may carry database or exception text) goes to this
     * plugin's own text log only. An administrator sees one generic,
     * translated notice per request; the storefront, the API and the CLI
     * see nothing.
     *
     * @param string $detail Operator-facing description of the failure.
     *
     *
     * @since  1.14.0
     */
    private function reportLogFailure(string $detail): void
    {
        try {
            Log::add($detail, Log::ERROR, $this->logCategory());
        } catch (Throwable $e) {
            // An unwritable log file must not turn a failed log row into a
            // failed request.
        }

        $app = $this->getApplication();

        if ($this->logFailureShown || !$app instanceof CMSApplicationInterface || !$app->isClient('administrator')) {
            return;
        }

        $this->logFailureShown = true;

        try {
            $language = $app->getLanguage();
            $language->load('com_alfa', JPATH_ADMINISTRATOR);
            $app->enqueueMessage($language->_('COM_ALFA_PLUGIN_LOG_FAILED'), 'warning');
        } catch (Throwable $e) {
            // The notice is advisory; the detail is already in the text log.
        }
    }

    /**
     * Render one log value as SQL.
     *
     * Two literals are passed through unquoted rather than as text:
     *   NOW()  - the timestamp default logs.xml files use
     *   NULL   - logs.xml writes default="NULL" as a STRING, and quoting it
     *            stored the four characters 'NULL' (which MySQL then coerced
     *            to 0 on numeric columns) instead of a real SQL NULL.
     *
     * Nothing else is ever unquoted: inferring SQL functions from a value's
     * shape would let anything ending in "()" splice itself into the query.
     *
     * @param object $db The database driver
     * @param mixed $value The value to render
     *
     * @return string SQL-safe fragment
     *
     * @since  1.0.0
     */
    private function logValueSql(object $db, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if (strcasecmp($trimmed, 'NOW()') === 0) {
                return 'NOW()';
            }

            if (strcasecmp($trimmed, 'NULL') === 0) {
                return 'NULL';
            }
        }

        return $db->quote($value);
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
     * @since  1.0.0
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
            // Table doesn't exist yet (MySQL 1146): return null, not an error
            if (MysqlError::number($e) === 1146) {
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
     * @since  1.0.0
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
            // Table doesn't exist (MySQL 1146): nothing to delete
            if (MysqlError::number($e) !== 1146) {
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
     * @since  1.0.0
     */
    protected function getLogsSchema(): ?SimpleXMLElement
    {
        $schema = $this->loadLogsSchema();

        return ($schema instanceof SimpleXMLElement) ? $schema : null;
    }

    //  LOGGING: Internal (private)

    /**
     * Get the log table name for this plugin.
     *
     * Format: #__alfa_{type}_{name}_logs
     * Example: #__alfa_payments_standard_logs
     *
     * @param int $prefix 0 = no prefix, 1 = real prefix, 2 = #__ prefix
     *
     *
     * @since  1.0.0
     */
    protected function getLogTableName(int $prefix = 2): string
    {
        // "alfa-payments" / "standard" -> "alfa_payments_standard_logs"
        $bare = substr(PluginLogTable::nameOf($this->_type, $this->_name), 3);

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
     * The Joomla Log category this plugin writes under.
     *
     * Derived, never declared: "alfa-payments"/"mygateway" becomes
     * `com_alfa.payments.mygateway`. Scoped to the plugin because Joomla hands
     * a message to every logger registered for its category: a name shared
     * between gateways files each one's lines in whichever gateway registered
     * a logger first, and drops them entirely when none did.
     *
     * @return string Category to pass as Log::add()'s third argument.
     *
     * @since  1.9.1
     */
    protected function logCategory(): string
    {
        $family = preg_replace('/^alfa[-_]/', '', (string) $this->_type);

        // A plugin group outside the alfa-* families keeps its own name rather
        // than being folded into com_alfa.<nothing>.
        if ($family === '' || $family === null) {
            $family = (string) $this->_type;
        }

        return 'com_alfa.' . str_replace('-', '_', $family) . '.' . $this->_name;
    }

    /**
     * The text log file this plugin's category writes to.
     *
     * Same two parts as {@see self::logCategory()} and
     * {@see self::getLogTableName()}, so file, category and table can never
     * name different plugins: `alfa_payments_mygateway.php`.
     *
     * @return string File name, relative to Joomla's log path.
     *
     * @since  1.9.1
     */
    protected function logFileName(): string
    {
        return str_replace('-', '_', (string) $this->_type) . '_' . $this->_name . '.php';
    }

    /**
     * Register this plugin's text logger.
     *
     * Called from PaymentsPlugin / ShipmentsPlugin construction, so every
     * gateway gets an audit trail without writing a line for it. Registering
     * twice is harmless: Joomla keys loggers by a hash of their options, so
     * an identical registration collapses onto the first.
     *
     *
     * @since  1.9.1
     */
    protected function registerLogger(): void
    {
        Log::addLogger(
            [
                'text_file' => $this->logFileName(),
                'text_file_no_php' => false,
            ],
            Log::ALL,
            [$this->logCategory()],
        );
    }

    /**
     * Load and cache the logs.xml schema.
     *
     * @return SimpleXMLElement|false Parsed XML or false if not found
     *
     * @since  1.0.0
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
            $this->reportLogFailure('Invalid logs.xml structure in plugin ' . $this->_type . '/' . $this->_name . '; nothing is logged.');

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
     *   id, id_order, the logIdentifierField and the logMethodField (if set).
     *
     * @return array|null Defaults array, or null if no logs.xml
     *
     * @since  1.0.0
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

        // Add the identifier column default (id_order_payment / id_order_shipment):
        // NULL, the column's own default. An empty string is not an integer,
        // and strict mode refused every row that did not pass one.
        if (!empty($this->logIdentifierField)) {
            $defaults[$this->logIdentifierField] = 'NULL';
        }

        // Add fields from logs.xml with their default values; a mandatory
        // column keeps core's definition.
        $table = $this->logTableOf($xml);

        foreach ($xml->fields->fieldset->field as $field) {
            $name = (string) $field['name'];

            if ($table->isCoreColumn($name)) {
                continue;
            }

            $default = isset($field['default']) ? (string) $field['default'] : 'NULL';
            $defaults[$name] = $default;
        }

        // Order-level rows name no method unless the plugin passes one.
        if ($this->logMethodField !== '') {
            $defaults[$this->logMethodField] = 0;
        }

        $this->logsDefaultsCache = $defaults;

        return $defaults;
    }

    /**
     * Ensure the log table exists AND has every column logs.xml declares.
     *
     * A plugin that ships its table (its install SQL, completed by its install
     * script on update) finds it complete here, and no DDL runs. For one that
     * does not yet, this completes it (PluginLogTable::complete()).
     *
     * Runs once per request, then caches. What the table has is READ first,
     * and the DDL runs only when something is missing, in three steps:
     *   1. CREATE TABLE IF NOT EXISTS: builds it from scratch on a new site.
     *   2. ADD COLUMN for anything declared but missing, so a plugin that
     *      gains a log field in a later version starts recording it on sites
     *      where the table already exists. Without this, the CREATE is a
     *      no-op there and the new field silently never appears. The core
     *      method column is reconciled the same way.
     *   3. ADD INDEX for the method column when it is missing.
     *
     * NEVER INSIDE AN OPEN TRANSACTION. MySQL commits the open transaction
     * before any CREATE or ALTER, so DDL run from a log write inside a
     * caller's transaction (a placement hook holds one) would commit the
     * caller's work half done, and leave its driver counting a transaction
     * the server no longer has. A table that lacks something while a
     * transaction is open is reported and not written to; the next write
     * outside a transaction completes it. A complete table needs no DDL.
     *
     * @return bool true if table is ready, false on failure
     *
     * @since  1.0.0
     */
    private function ensureLogTable(): bool
    {
        // Already ensured this request: skip
        if ($this->logTableEnsured) {
            return true;
        }

        $xml = $this->loadLogsSchema();

        if (!$xml) {
            return false;
        }

        $table = $this->logTableOf($xml);

        try {
            $lacks = $table->lacks();
        } catch (Throwable $e) {
            $this->reportLogFailure(
                'Log table ' . $table->name() . ' could not be read (' . $e->getCode() . ') ' . $e->getMessage(),
            );

            return false;
        }

        if ($lacks === []) {
            $this->logTableEnsured = true;

            return true;
        }

        if (TransactionState::isOpen(Factory::getContainer()->get('DatabaseDriver'))) {
            $this->reportLogFailure(
                'Log table ' . $table->name() . ' lacks ' . implode(', ', $lacks)
                . ' and is not changed inside an open transaction; the row was not written.'
                . ' The next write outside a transaction completes the table.',
            );

            return false;
        }

        if (!$table->complete(fn (string $detail) => $this->reportLogFailure($detail))) {
            return false;
        }

        $this->logTableEnsured = true;

        return true;
    }

    /**
     * This plugin's log table, as logs.xml and its family declare it.
     *
     * @param object $xml Parsed logs.xml.
     *
     *
     * @since  1.14.0
     */
    private function logTableOf(object $xml): PluginLogTable
    {
        return new PluginLogTable(
            db:              Factory::getContainer()->get('DatabaseDriver'),
            tableName:       $this->getLogTableName(),
            xml:             $xml,
            identifierField: $this->logIdentifierField,
            methodField:     $this->logMethodField,
        );
    }

    /**
     * Apply filter conditions to a log query.
     *
     * Supports:
     *   ['status' => 'completed']                       -> status = 'completed'
     *   ['amount' => ['>', 100]]                        -> amount > 100
     *   ['status' => ['IN', ['pending', 'completed']]]  -> status IN ('pending', 'completed')
     *   ['__raw'  => 'DATE(created_on) = CURDATE()']    -> raw SQL
     *
     * @param object $query Query builder
     * @param array $filters Filter definitions
     * @param object $db Database driver
     *
     *
     * @since  1.0.0
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
                        // Unsupported operator: skip silently
                        break;
                }
            } else {
                // Simple format: field = value
                $query->where($field . ' = ' . $db->quote($condition));
            }
        }
    }

    //  Utility

    /**
     * WHO AN ORDER IS FOR IS THE ORDER'S OWN QUESTION, not this base class's.
     *
     * `customer($order)` and `recipient($order)` used to live here and were a
     * second way to ask what the order reader already answers, and a worse
     * one, because `customer()` fell back to the CURRENT account when the
     * order's own values were empty, which is right for showing a buyer in the
     * admin and wrong for booking a parcel under someone else's name.
     *
     * Every plugin is handed an {@see \Alfa\Component\Alfa\Site\Service\Order\Order}
     * by `$event->getOrder()`, so ask it directly:
     *
     *     $order->getName()                       who BOUGHT, never an account fallback
     *     $order->getEmail()
     *     $order->isGuest()
     *     $order->delivery()->getName()           who the parcel is FOR
     *     $order->delivery()->getEmail()          trimmed string, '' when absent
     *     $order->delivery()->role('phone')       a role the merchant may drop
     *     $order->delivery()->field($configured)  a mapping the merchant chose
     *     $order->invoice()                       who it is BILLED to
     *     $order->total(), ->shipmentTotal(), ->items(), ->paymentMethod()
     *
     * The buyer and the recipient are DIFFERENT PEOPLE as often as a present
     * is, so a carrier that needs the second must not read the first.
     */

    /**
     * A place and the places it belongs to, nearest first.
     *
     * WHY A PLUGIN NEEDS THIS AT ALL. Shops differ: one sells to a flat list of
     * countries, the next models prefectures and towns beneath them. A carrier
     * that compares a configured place against a delivery place directly works
     * in the first shop and silently stops matching in the second, because the
     * order is going to a town while the zone names the country. Asking for the
     * whole chain and matching against any of it works in both.
     *
     * ONE ARGUMENT, AND IT IS A PLACE ID. This knows nothing about methods,
     * carts or field mappings: it expands a place, and that is all. Reading the
     * place is the caller's own step, and the two stay separate because only
     * SOME fields hold a place: a postcode is a string, a telephone is a
     * string, and neither is ever passed here:
     *
     *     // the destination this method was configured to read
     *     $placeId = $cart->delivery()->field($configuredFieldId)->int();
     *     $places  = $this->placesFor((int) $placeId);
     *
     *     // or the shop's own, when the method configures none
     *     $places  = $cart->delivery()->places();
     *
     * A zero or unknown id is NOT an error and is not a fallback: it answers
     * with an empty chain, which admits only what names no place. That is what
     * an unfilled field, a cleared one and a deleted one all have to mean.
     *
     * The tree is read once per request and shared, so asking repeatedly is
     * cheap and a plugin never has to reach for the component's internals.
     *
     * @param int $placeId The `#__alfa_places` id.
     *
     * @return int[] The place and its ancestors; empty for a zero or unknown id.
     *
     * @since  1.10.0
     */
    protected function placesFor(int $placeId): array
    {
        return PlaceTree::placesFor($placeId);
    }

    /**
     * Is a value within the limits a configuration row sets?
     *
     * ONE TEST FOR POSTCODES, AMOUNTS AND DIMENSIONS. It replaces four
     * different hand-written conventions that disagreed with each other: a
     * postcode band that read 0..0 as "unrestricted", an amount band that read
     * a 0 upper bound as "and above", and four bare `<=` dimension comparisons
     * that read a 0 ceiling as a real ceiling. A merchant should not have to
     * know which field they are filling in to know what leaving it empty means.
     *
     * AN UNSET LIMIT IS NO LIMIT. null, an empty string, and any numeric value
     * at or below zero all mean "the merchant did not set this". Every band
     * field in params.xml ships with default="0", so the shipped default has to
     * mean unrestricted, and it is why this is not called isValueInRange: a
     * range test says 5 is not between 0 and 0, and it would be right. This is
     * not a range test.
     *
     * AN ABSENT VALUE IS JUDGED BY THE LOWER BOUND. A cart that has not reached
     * the address step has no postcode, and refusing it would take the carrier
     * off every cart in the shop. A band with no real floor (0..999999) still
     * admits it; a band that names postcodes (10000..20000) does not.
     *
     * NUMBERS COMPARE AS NUMBERS. Money and weight are decimals, and 50.5 must
     * exceed 50.45, which comparing text cannot deliver.
     *
     * ANYTHING ELSE COMPARES NATURALLY, via strnatcasecmp(): digit runs inside
     * the string compare as numbers, letter runs as letters, case ignored.
     * This is what makes postcodes work. Plain string comparison puts M10
     * inside M1..M9 because '1' sorts below '9', so a Manchester shop quoting
     * for district M1 silently quoted for M10 to M99 as well; comparing 10
     * against 9 as NUMBERS gets it right. It also makes k1a 0b1 match a K..Q
     * band, which a shopper typing in lower case previously failed.
     *
     * SEPARATORS ARE LEFT ALONE, NOT STRIPPED. Deleting the space in M1 4BT
     * turns it into M14BT and hands district M1 to district M14: the reason
     * a normalise-then-compare approach was rejected.
     *
     * KINDS THAT DO NOT MATCH CANNOT BE JUDGED. A number against a letter (a
     * 5 kg parcel against a ceiling somebody typed as 'abc', a Greek postcode
     * against a Canadian K..Q band) is refused rather than admitted. The old
     * code admitted it: PHP 8 compares 5 with 'abc' as text, every digit sorts
     * below every letter, and a corrupt weight ceiling therefore accepted a
     * 900 kg parcel.
     *
     * @param mixed $value The value to judge: a postcode, a cart total, a weight.
     * @param mixed $min The lower limit; null/''/0 for none.
     * @param mixed $max The upper limit; null/''/0 for none.
     *
     *
     * @since  1.10.0
     */
    final protected function isWithinLimits(mixed $value, mixed $min = null, mixed $max = null): bool
    {
        $hasMin = !self::isNoLimit($min);
        $hasMax = !self::isNoLimit($max);

        if (!$hasMin && !$hasMax) {
            return true;
        }

        $subject = \is_string($value) ? trim($value) : $value;

        if ($subject === null || $subject === '') {
            return !$hasMin;
        }

        if (
            is_numeric($subject)
            && (!$hasMin || is_numeric($min))
            && (!$hasMax || is_numeric($max))
        ) {
            return (!$hasMin || (float) $subject >= (float) $min)
                && (!$hasMax || (float) $subject <= (float) $max);
        }

        $subject = (string) $subject;

        if ($hasMin && (!self::comparableKinds($subject, (string) $min) || strnatcasecmp($subject, (string) $min) < 0)) {
            return false;
        }

        if ($hasMax && (!self::comparableKinds($subject, (string) $max) || strnatcasecmp($subject, (string) $max) > 0)) {
            return false;
        }

        return true;
    }

    /**
     * Whether a configured bound means "no limit at all".
     *
     * @param mixed $bound The configured value.
     *
     *
     * @since  1.10.0
     */
    private static function isNoLimit(mixed $bound): bool
    {
        if ($bound === null) {
            return true;
        }

        $bound = trim((string) $bound);

        return $bound === '' || (is_numeric($bound) && (float) $bound <= 0.0);
    }

    /**
     * Whether a value and a bound are the same kind of thing at all.
     *
     * A value that starts with a number and a bound that starts with a letter
     * are not two points on one scale: a 5 kg parcel against a ceiling
     * somebody typed as 'abc', a Greek postcode against a Canadian K..Q band.
     * PHP will happily order them anyway, and did: comparing 5 with 'abc' as
     * text puts every digit below every letter, so a corrupt weight ceiling
     * accepted a 900 kg parcel. Refusing to judge is the honest answer.
     *
     * @param string $value The value being judged.
     * @param string $bound The configured limit.
     *
     *
     * @since  1.10.0
     */
    private static function comparableKinds(string $value, string $bound): bool
    {
        $first = static function (string $subject): ?string {
            return preg_match('/[0-9A-Z]/', strtoupper($subject), $matches) ? $matches[0] : null;
        };

        $left = $first($value);
        $right = $first($bound);

        return $left !== null && $right !== null && ctype_digit($left) === ctype_digit($right);
    }
}
