<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

// No direct access
defined('_JEXEC') or die;

//use \Joomla\CMS\Object\CMSObject;
use DateTimeZone;
use Exception;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

/**
 * Alfa helper.
 *
 * @since  1.0.0
 */
class AlfaHelper
{
    // Data diffing

    /**
     * Build a diff between two data snapshots (old vs new).
     *
     * Accepts any combination of objects and arrays for both $old and $new.
     * Compares only the specified $trackFields. Normalises numeric values
     * so database decimals ("0.000000") and form values ("0") don't
     * produce false-positive changes.
     *
     * This is the SINGLE source of truth for change detection across the
     * entire component. Used by:
     *   - OrderModel::saveOrderItem() / logOrderChanges()
     *   - PaymentLedger::update()
     *   - ShipmentLedger::update()
     *   - Any future helper that needs before/after comparison
     *
     * Usage examples:
     *   // Object vs array (most common: DB row vs form data)
     *   $changes = AlfaHelper::buildDiff($dbRow, $formData, ['amount', 'status']);
     *
     *   // Array vs array
     *   $changes = AlfaHelper::buildDiff($oldArray, $newArray, ['name', 'qty']);
     *
     *   // Object vs object
     *   $changes = AlfaHelper::buildDiff($oldObj, $newObj, ['price']);
     *
     *   // Return as object instead of array
     *   $changes = AlfaHelper::buildDiff($old, $new, $fields, 'object');
     *
     * @param object|array $old Old snapshot (DB row, previous state)
     * @param object|array $new New snapshot (form data, updated state)
     * @param array $trackFields Field names to compare
     * @param string $returnType 'array' (default) or 'object'
     *
     * @return array|object Changes: ['field' => ['from' => old, 'to' => new], ...]
     *                      Empty array/object when nothing changed.
     *
     * @since  1.0.0
     */
    public static function buildDiff(
        object|array $old,
        object|array $new,
        array $trackFields,
        string $returnType = 'array',
    ): array|object {
        // Normalise inputs: work with arrays internally
        $oldData = is_object($old) ? get_object_vars($old) : $old;
        $newData = is_object($new) ? get_object_vars($new) : $new;

        $changes = [];

        foreach ($trackFields as $field) {
            $oldVal = $oldData[$field] ?? '';
            $newVal = $newData[$field] ?? '';

            // Numeric comparison: avoids "0.000000" vs "0" false diffs
            if (is_numeric($oldVal) && is_numeric($newVal)) {
                if ((float) $oldVal === (float) $newVal) {
                    continue;
                }
            } elseif ((string) $oldVal === (string) $newVal) {
                // Non-numeric -> string comparison
                continue;
            }

            $changes[$field] = ['from' => $oldVal, 'to' => $newVal];
        }

        return ($returnType === 'object') ? (object) $changes : $changes;
    }

    /**
     * Flatten a flat parent_id list into a depth-first ordered list, stamping
     * each row with its 'depth' and a breadcrumb 'path' by recursively walking
     * children of the given parent.
     *
     * @param array $items Flat list of rows with id/parent_id
     * @param string|null $pathField Row property used to build the path, or null
     * @param string $pathSeparator Separator between path segments
     * @param string $parentPath Accumulated parent path (recursion)
     * @param int $parentId Parent id to start from
     * @param int $depth Current depth level (recursion)
     *
     * @return array Depth-first ordered list with depth/path stamped
     *
     * @since  1.0.0
     */
    public static function addHierarchyData($items, $pathField = 'name', $pathSeparator = '/', $parentPath = '', $parentId = 0, $depth = 0)
    {
        $hierarchyData = [];
        foreach ($items as $item) {
            if ($item->parent_id == $parentId) {
                $item->depth = $depth; // Assign the current depth level

                if ($pathField && property_exists($item, $pathField)) {
                    // If parentPath is empty, avoid adding a leading separator
                    $currentPath = $parentPath ? $parentPath . $pathSeparator . $item->{$pathField} : $item->{$pathField};
                    // Add the current path to the flattened item
                    $item->path = $currentPath;
                } else {
                    // Handle cases where the property does not exist or $pathField is invalid
                    $item->path = $parentPath; // or set some default value
                }

                $hierarchyData[] = $item; // Add the item to the array
                // Recursively add next items to the array
                $hierarchyData = array_merge($hierarchyData, self::addHierarchyData($items, $pathField, $pathSeparator, $currentPath, $item->id, $depth + 1));
            }
        }
        return $hierarchyData;
    }

    /**
     * Saves the assocs data to to the database.
     *
     * @return void
     * @since  1.0.0
     */
    public static function setAssocsToDb($mainFieldId, $data, $table, $mainField, $dataField, $assignZeroIdIfDataEmpty = false)
    {
        if (intval($mainFieldId) <= 0 || empty($table) || empty($mainFieldId) || empty($dataField)) {
            return false;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        // save users per category on categories_users
        $query = $db->getQuery(true);
        $query->delete($db->quoteName($table))->where($db->quoteName($mainField) . ' = ' . $mainFieldId);
        $db->setQuery($query);
        $db->execute();

        // A single-value control posts a scalar and an "all" choice posts ''
        // - both must reach the same rows as a multiselect would. Each id is
        // written once: the link tables key on the pair, so a repeated id
        // (e.g. [3, 3] through the API) would be refused by the database.
        $data = array_values(array_unique(array_map(
            'intval',
            array_filter((array) $data, static fn ($value): bool => $value !== '' && $value !== null),
        )));

        if ($data === [] && $assignZeroIdIfDataEmpty) {
            $data = [0];
        }

        if (is_array($data)) {
            foreach ($data as $curr) {
                $query = $db->getQuery(true);
                $query->insert($db->quoteName($table))
                    ->set($db->quoteName($mainField) . ' = ' . $mainFieldId)
                    ->set($db->quoteName($dataField) . ' = ' . intval($curr));
                $db->setQuery($query);
                $db->execute();
            }
        }
    }

    /**
     * Retrieves all the assocs data from the database. Reusable for all forms that have allowed user groups as a field.
     *
     * @return mixed
     * @since  1.0.0
     */
    public static function getAssocsFromDb($mainFieldId, $table, $mainField, $dataField)
    {
        if (intval($mainFieldId) <= 0 || empty($table) || empty($mainField) || empty($dataField)) {
            return [];
        }

        // load selected categories for item
        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true);
        $query
            ->select($db->quoteName($dataField))
            ->from($db->quoteName($table))
            ->where($db->quoteName($mainField) . ' = ' . intval($mainFieldId));

        $db->setQuery($query);

        return $db->loadColumn();
    }

    // LOOKUP TABLES: Cached Static Methods
    //
    // Small reference tables (3-10 rows) loaded once per request.
    // Used everywhere: OrderModel, OrdersModel, views, helpers.
    //
    // Single source of truth: no more duplicate methods in models.
    // Static cache means: first call queries DB, all subsequent calls
    // return the cached result. Cache lives for the request only.
    //
    // All methods return arrays keyed by ID for O(1) lookup:
    //   $statuses[$id]->name, $statuses[$id]->color, etc.

    /** @var array|null Cached order statuses keyed by ID */
    private static ?array $orderStatusesCache = null;

    /** @var array|null Cached payment methods keyed by ID */
    private static ?array $paymentMethodsCache = null;

    /** @var array|null Cached shipment methods keyed by ID */
    private static ?array $shipmentMethodsCache = null;

    /**
     * Get all order statuses keyed by ID (cached).
     *
     * Returns ALL statuses (active + inactive) because:
     *   - Existing orders may reference inactive statuses
     *   - The edit view needs to show the current status even if disabled
     *   - Filtering for active-only is done in the view (dropdown options)
     *
     * Each status has: id, name, color, bg_color, stock_operation, state, ordering
     *
     * Used by:
     *   - OrdersModel::getItems() -> attach names/colors to list rows
     *   - Orders list view -> status column display + dropdown
     *   - OrderModel::handleStockOperation() -> stock_operation lookup
     *   - OrderModel::logOrderChanges() -> status name enrichment
     *
     * @return array Statuses indexed by ID
     *
     * @since  1.0.0
     */
    public static function getOrderStatuses(): array
    {
        if (self::$orderStatusesCache !== null) {
            return self::$orderStatusesCache;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('a.*')
            ->from($db->quoteName('#__alfa_orders_statuses', 'a'))
            ->order('a.ordering ASC');

        // Resolve the status name in the active language from the lang tables.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_orders_statuses',
            langPrimaryColumn: 'id_orderstatus',
            fields:            ['name'],
        );

        $db->setQuery($query);

        self::$orderStatusesCache = $db->loadObjectList('id') ?: [];

        return self::$orderStatusesCache;
    }

    /**
     * Get all active payment methods keyed by ID (cached).
     *
     * Each method has: id, name, color, bg_color
     *
     * Used by:
     *   - OrdersModel::getItems() -> attach names/colors to list rows
     *   - Orders list view -> payment column display
     *
     * @return array Payment methods indexed by ID
     *
     * @since  1.0.0
     */
    public static function getPaymentMethods(): array
    {
        if (self::$paymentMethodsCache !== null) {
            return self::$paymentMethodsCache;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('a.id, a.color, a.bg_color')
            ->from($db->quoteName('#__alfa_payments', 'a'))
            ->where($db->quoteName('a.state') . ' = 1')
            ->order('name ASC');

        // Resolve the method name in the active language from the lang tables
        // (the ORDER BY then sorts on that resolved alias).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_payments',
            langPrimaryColumn: 'id_payment',
            fields:            ['name'],
        );

        $db->setQuery($query);

        self::$paymentMethodsCache = $db->loadObjectList('id') ?: [];

        return self::$paymentMethodsCache;
    }

    /**
     * Get all active shipment methods keyed by ID (cached).
     *
     * Each method has: id, name, color, bg_color
     *
     * Used by:
     *   - OrdersModel::getItems() -> attach names/colors to list rows
     *   - Orders list view -> shipment column display
     *
     * @return array Shipment methods indexed by ID
     *
     * @since  1.0.0
     */
    public static function getShipmentMethods(): array
    {
        if (self::$shipmentMethodsCache !== null) {
            return self::$shipmentMethodsCache;
        }

        $db = Factory::getContainer()->get('DatabaseDriver');
        $query = $db->getQuery(true)
            ->select('a.id, a.color, a.bg_color')
            ->from($db->quoteName('#__alfa_shipments', 'a'))
            ->where($db->quoteName('a.state') . ' = 1')
            ->order('name ASC');

        // Resolve the method name in the active language from the lang tables
        // (the ORDER BY then sorts on that resolved alias).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_shipments',
            langPrimaryColumn: 'id_shipment',
            fields:            ['name'],
        );

        $db->setQuery($query);

        self::$shipmentMethodsCache = $db->loadObjectList('id') ?: [];

        return self::$shipmentMethodsCache;
    }

    //    FUNCTION TO BE DELETED
    /* public static function iterateNestedArray($tree, $callback, $fullPath = false, $parentNames = '')
       {
           foreach ($tree as $node) {
               // Build the full path or hierarchical representation of category names based on the format
               if ($fullPath) {
                   $currentPath = empty($parentNames) ? $node->name : $parentNames . ' / ' . $node->name;
               } else {
                   $currentPath = str_repeat('- ', $node->depth) . $node->name;
               }

               // Apply the callback function to the current node with the full path or hierarchical representation
               $callback($node, $currentPath);

               // If the node has children, recursively iterate through them
               if (!empty($node->children)) {
                   self::iterateNestedArray($node->children, $callback, $fullPath, $currentPath);
               }
           }
       }*/

    /**
     * Resolves a plugin family key ('formfields', 'itemfields', 'payments',
     * 'shipments', ...) to its plugin group folder name. The family key also
     * derives the params fieldset name (\$type . 'params': formfieldsparams /
     * itemfieldsparams) used by the plugins' params.xml files.
     *
     * @param string $type Plugin family key
     *
     * @return string Plugin group folder (e.g. 'alfa-form-fields')
     *
     * @since  1.2.1
     */
    private static function pluginGroup(string $type): string
    {
        return match ($type) {
            'formfields' => 'alfa-form-fields',
            'itemfields' => 'alfa-item-fields',
            default => 'alfa-' . $type,
        };
    }

    /**
     * Loads the fields plugins and returns an array of field types from the plugins.
     *
     * The returned array contains arrays with the following keys:
     * - label: The label of the field
     * - type:  The type of the field
     * - path:  The path of the folder where the field can be found
     *
     * @return array
     *
     * @since  1.0.0
     */
    public static function getFieldTypes($type)
    {
        $plugin_types = [];

        $pluginGroup = self::pluginGroup(type: $type);

        $plugins = PluginHelper::getPlugin($pluginGroup);// Get a list of all plugins in the specified group

        foreach ($plugins as $plugin) {// Process each shipment payment group plugin.
            $plugin_types[] =
                [
                    'id' => $plugin->id,
                    'name' => $plugin->name,
                    'params' => $plugin->params,
                ];
        }
        return $plugin_types;
    }

    /**
     * Merge the active plugin's params.xml into the form and seed its fieldset
     * with the values the caller hands over. Resolves the plugin name from
     * posted data or the form value, defaulting to 'text' (fields) or
     * 'standard' (shipments/payments) for new entries, and loads its language
     * file.
     *
     * The caller decides what seeds the fieldset: after a failed save that is
     * the kept post, so what the admin typed survives the round trip; the
     * form's own data was bound before params.xml was loaded, and a bind drops
     * every key that has no field yet.
     *
     * @param Form $form The form to augment.
     * @param array $data Posted data; may carry the selected 'type'.
     * @param array $values The plugin fieldset's values, keyed by field name; [] seeds nothing.
     * @param string $type Plugin family: 'payments', 'shipments', 'formfields', 'itemfields', ...
     *
     *
     * @since  1.0.0
     */
    public static function addPluginForm(Form $form, array $data, array $values, string $type): void
    {
        $app = Factory::getApplication();

        $pluginGroup = self::pluginGroup(type: $type);
        $pluginName = $data['type'] ?? $form->getValue('type'); // $data when we save but when we open the form we get it from getValue

        // New entry - Auto select the default value for first time
        if (empty($pluginName)) {
            if ($type == 'formfields') {   // Form fields plugin type, use text as default.
                $pluginName = 'text';
            } elseif ($type == 'itemfields') {   // Item fields plugin type, use choice as default.
                $pluginName = 'choice';
            } elseif ($type == 'shipments' || $type == 'payments') {    // Shipments/Payments plugin type, use standard as default.
                $pluginName = 'standard';
            }
        }

        $paramsFile = JPATH_ROOT . '/plugins/' . $pluginGroup . '/' . $pluginName . '/params/params.xml';

        self::loadPluginLanguage(group: $pluginGroup, element: $pluginName);

        if (file_exists($paramsFile)) {
            // Load the XML file into the form.
            $form->loadFile($paramsFile, false);
        }

        // The plugin's group: paymentsparams, shipmentsparams, ...
        $form->bind([$type . 'params' => $values]);
    }
    /**
     * Load one plugin's language file, from wherever that plugin keeps it.
     *
     * An INSTALLED extension has its INIs in `administrator/language/<tag>/`:
     * the installer moves them there out of the package. A plugin dropped into a
     * tree by hand still carries its own `language/` folder. Six places in this
     * component print plugin-owned strings and each used to guess ONE of those
     * two locations, so a key rendered in the dashboard and the same key rendered
     * in a settings form could disagree: the shipment plugin's params showed raw
     * PLG_* keys for exactly this reason.
     *
     * The order is Joomla's own (CMSPlugin::loadLanguage): administrator first,
     * plugin folder second, short-circuited on `||`. Admin-first means an
     * installed copy WINS over the one still sitting in the plugin folder; keep
     * that in mind when editing strings, or the edit renders nowhere.
     *
     * @param string $group The plugin group, e.g. 'alfa-shipments'.
     * @param string $element The plugin element, e.g. 'standard'.
     *
     * @return bool True when a file was found and parsed.
     *
     * @since  1.10.0
     */
    public static function loadPluginLanguage(string $group, string $element): bool
    {
        $language = Factory::getApplication()->getLanguage();
        $file = 'plg_' . $group . '_' . $element;

        return $language->load($file, JPATH_ADMINISTRATOR)
            || $language->load($file, JPATH_PLUGINS . '/' . $group . '/' . $element);
    }

    /**
     * The shop timezone (the Joomla site offset).
     *
     * The shop's own clock: legal document dates, year scoping and "orders
     * today" are all computed HERE, never in UTC, and never in the viewing
     * administrator's personal timezone: two admins in different timezones
     * must not see two different todays.
     *
     * Once lived on DocumentHelper because documents needed it
     * first, which left the general helper depending on a specialised one. It
     * is a property of the SHOP, not of documents, so the definition belongs
     * here. Document code still asks DocumentHelper::shopTimezone(), which
     * delegates to this: a domain seam over one definition, and a dependency
     * that points specific -> general instead of the reverse.
     *
     * Deliberately ONE definition rather than a copy per caller: two shop clocks
     * that can disagree is a silent fiscal bug: a document dated one day and an
     * "orders today" window covering another.
     *
     *
     * @since   1.3.0
     */
    public static function shopTimezone(): DateTimeZone
    {
        return new DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC'));
    }

    /**
     * Constrain a query to a date window held in SHOP-LOCAL time.
     *
     * The bounds travel through the UI in the shop's timezone all the way (it is what
     * the calendars show and what the operator typed), so the single conversion to
     * whatever the COLUMN stores happens here, once, at the query boundary.
     *
     * $convertToUtc = true  for columns written as Factory::getDate('now', 'UTC')
     *                      (orders.created, inventory_movements.created,
     *                      inventory_reservations.created, alfa_returns.created).
     * $convertToUtc = false for a column that is ALREADY shop-local and frozen:
     *                      documents.issue_date_local is a bare DATE holding the legal
     *                      issue date. Converting it would shift every document by the
     *                      offset and land the small hours of the 1st in the previous
     *                      month, which on a fiscal list is a reporting error.
     *
     * One-sided windows are honoured: "everything since the 1st" is a real question.
     * A bound that cannot be parsed is dropped rather than guessed at, so a broken
     * value widens the result set instead of silently hiding rows.
     *
     * @param QueryInterface $query Query to constrain.
     * @param string $column Quoted-name-safe column, e.g. 'a.created'.
     * @param string $localStart Lower bound in shop-local time, '' for none.
     * @param string $localEnd Upper bound in shop-local time, '' for none.
     * @param bool $convertToUtc Whether the column stores UTC.
     *
     *
     * @since   1.10.0
     */
    public static function applyDateRange(
        QueryInterface $query,
        string $column,
        string $localStart,
        string $localEnd,
        bool $convertToUtc = true,
    ): void {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $shopTz = self::shopTimezone();

        $bound = static function (string $local) use ($shopTz, $convertToUtc): string {
            if ($local === '') {
                return '';
            }

            if (!$convertToUtc) {
                // Already in the column's own frame; a DATE column compares on the
                // date part, so a time riding along on a hand-typed value is trimmed.
                return substr($local, 0, 10);
            }

            try {
                return Factory::getDate($local, $shopTz)->toSql();
            } catch (Exception) {
                return '';
            }
        };

        $start = $bound($localStart);
        $end = $bound($localEnd);

        if ($start !== '' && $end !== '') {
            $query->where(
                $db->quoteName($column) . ' BETWEEN ' . $db->quote($start) . ' AND ' . $db->quote($end),
            );
        } elseif ($start !== '') {
            $query->where($db->quoteName($column) . ' >= ' . $db->quote($start));
        } elseif ($end !== '') {
            $query->where($db->quoteName($column) . ' <= ' . $db->quote($end));
        }
    }

    /**
     * The date-range presets, in the order the filter dropdown offers them.
     *
     * THE single source of truth: {@see self::buildDateRange()} resolves exactly these
     * keys, and {@see \Alfa\Component\Alfa\Administrator\Field\DaterangeField} builds
     * the dropdown from them. Adding a preset means editing this list and the switch,
     * never five filter forms, which is what the XML used to demand.
     *
     * The names are a FROZEN vocabulary: saved filters, bookmarked URLs and the
     * dashboard tiles all carry these keys, so renaming one breaks them silently.
     * The label key is COM_ALFA_DATE_RANGE_<KEY>.
     *
     * @var string[]
     *
     * @since  1.10.0
     */
    public const DATE_RANGE_PRESETS = [
        'today',
        'yesterday',
        'week_to_date',
        'previous_week',
        'month_to_date',
        'previous_month',
        'past_7days',
        'past_15days',
        'past_30days',
        'past_60days',
        'past_90days',
        'past_180days',
    ];

    /**
     * Turn a date-range preset into concrete bounds.
     *
     * The anchor is the SHOP's timezone, not the viewing administrator's: "orders
     * today" is a question about the business day, and two admins in different
     * timezones must not see two different todays, the same reason
     * {@see self::shopTimezone()} anchors fiscal documents.
     *
     * Bounds come back as Date objects still IN that timezone, so the caller
     * converts once with toSql() rather than round-tripping through a string.
     *
     * @param string $range Preset name, e.g. "today" or "past_30days".
     *
     * @return array{dStart: Date, dEnd: Date}|null Null for an unknown/empty preset.
     *
     * @since   1.10.0
     */
    public static function buildDateRange(string $range): ?array
    {
        if ($range === '') {
            return null;
        }

        $now = new Date('now', self::shopTimezone());
        $dStart = clone $now;
        $dEnd = clone $now;

        switch ($range) {
            case 'today':
                $dStart->setTime(0, 0, 0);
                $dEnd->setTime(23, 59, 59);
                break;
            case 'yesterday':
                $dStart->modify('-1 day')->setTime(0, 0, 0);
                $dEnd->modify('-1 day')->setTime(23, 59, 59);
                break;
            case 'week_to_date':
                $dStart->modify('monday this week')->setTime(0, 0, 0);
                $dEnd->setTime(23, 59, 59);
                break;
            case 'previous_week':
                $dStart->modify('monday last week')->setTime(0, 0, 0);
                $dEnd->modify('sunday last week')->setTime(23, 59, 59);
                break;
            case 'month_to_date':
                $dStart->setDate((int) $now->format('Y'), (int) $now->format('m'), 1)->setTime(0, 0, 0);
                $dEnd->setTime(23, 59, 59);
                break;
            case 'previous_month':
                $dStart->modify('first day of last month')->setTime(0, 0, 0);
                $dEnd->modify('last day of last month')->setTime(23, 59, 59);
                break;
            case 'past_7days':
            case 'past_15days':
            case 'past_30days':
            case 'past_60days':
            case 'past_90days':
            case 'past_180days':
                $dStart->modify('-' . (int) filter_var($range, FILTER_SANITIZE_NUMBER_INT) . ' day')->setTime(0, 0, 0);
                $dEnd->setTime(23, 59, 59);
                break;
            default:
                return null;
        }

        return ['dStart' => $dStart, 'dEnd' => $dEnd];
    }

    // Permissions

    /**
     * The access.xml section that governs each admin entity, keyed by every name the admin
     * uses for it: the singular record name (a model's `$alfaEntity`, a FormController's
     * `view_item`) and the plural list name (`view_list`, a menu view).
     *
     * A screen with no section of its own borrows the one it is managed from: document types
     * and series from Documents, field groups from Form Fields, reservations from Inventory
     * Movements, item downloads from Items. One map serves the dispatcher, the controllers,
     * the models, the toolbars and the API, so a screen can never be guarded by one section
     * and drawn by another.
     *
     * @var array<string, string>
     *
     * @since  1.10.0
     */
    public const PERMISSION_SECTIONS = [
        'item' => 'items',
        'items' => 'items',
        'itemdownload' => 'items',
        'itemdownloads' => 'items',
        'category' => 'categories',
        'categories' => 'categories',
        'manufacturer' => 'manufacturers',
        'manufacturers' => 'manufacturers',
        'itemfield' => 'itemfields',
        'itemfields' => 'itemfields',
        'order' => 'orders',
        'orders' => 'orders',
        'document' => 'documents',
        'documents' => 'documents',
        'documenttype' => 'documents',
        'documenttypes' => 'documents',
        'series' => 'documents',
        'documentseries' => 'documents',
        'cart' => 'carts',
        'carts' => 'carts',
        'cartrule' => 'cartrules',
        'cartrules' => 'cartrules',
        'discount' => 'discounts',
        'discounts' => 'discounts',
        'location' => 'locations',
        'locations' => 'locations',
        'inventorymovement' => 'inventorymovements',
        'inventorymovements' => 'inventorymovements',
        'reservation' => 'inventorymovements',
        'reservations' => 'inventorymovements',
        'place' => 'places',
        'places' => 'places',
        'currency' => 'currencies',
        'currencies' => 'currencies',
        'tax' => 'taxes',
        'taxes' => 'taxes',
        'payment' => 'payments',
        'payments' => 'payments',
        'shipment' => 'shipments',
        'shipments' => 'shipments',
        'formfield' => 'formfields',
        'formfields' => 'formfields',
        'formfieldgroup' => 'formfields',
        'formfieldgroups' => 'formfields',
        'orderstatus' => 'orderstatuses',
        'orderstatuses' => 'orderstatuses',
        'user' => 'users',
        'users' => 'users',
        'usergroup' => 'usergroups',
        'usergroups' => 'usergroups',
        'notification' => 'notifications',
        'notifications' => 'notifications',
    ];

    /**
     * The access.xml section for an entity or screen name, or the name itself when the map
     * does not know it (a section named directly, or one a plugin declares).
     *
     * @param string $name An entity, screen or section name, e.g. 'item', 'items', 'documenttypes'.
     *
     * @return string The section, e.g. 'items'.
     *
     * @since   1.10.0
     */
    public static function permissionSection(string $name): string
    {
        $name = strtolower(trim($name));

        return self::PERMISSION_SECTIONS[$name] ?? $name;
    }

    /**
     * May the user do this to this part of the shop: `alfa.<section>.<action>` on com_alfa.
     *
     * THE permission check of the admin. Only Alfa's own actions are asked, never Joomla's
     * core.create / core.edit / core.edit.state / core.delete, so a group can do exactly what
     * it was granted in Options -> Permissions and nothing it would inherit from Global
     * Configuration. Nothing is granted at install: a Super User passes every check
     * (User::authorise() lets core.admin on the root asset through), everyone else only what a
     * Super User grants by hand.
     *
     * Always User::authorise(), never Access::check(): only the former lets a Super User through.
     *
     * @param string $section A section ('items') or any entity or screen
     *                        name {@see self::PERMISSION_SECTIONS} knows.
     * @param string $action view | create | edit | edit.state | delete,
     *                       or a section's own (carts: checkout).
     * @param \Joomla\CMS\User\User|null $user The user, or null for the current identity.
     *
     *
     * @since   1.10.0
     */
    public static function can(string $section, string $action, ?\Joomla\CMS\User\User $user = null): bool
    {
        $section = self::permissionSection($section);
        $action = strtolower(trim($action));

        if ($section === '' || $action === '') {
            return false;
        }

        $user ??= Factory::getApplication()->getIdentity();

        return $user !== null && (bool) $user->authorise('alfa.' . $section . '.' . $action, 'com_alfa');
    }

    /**
     * Admin screens that list a section's records: opening one needs that section's view permission.
     * With FORM_SCREENS and SELF_GUARDED_SCREENS, the map the dispatcher opens screens by.
     *
     * @var string[]
     *
     * @since  1.10.0
     */
    public const LIST_SCREENS = [
        'items', 'categories', 'manufacturers', 'itemfields',
        'orders', 'documents', 'documentseries', 'documenttypes', 'carts',
        'cartrules', 'discounts',
        'locations', 'inventorymovements', 'reservations',
        'places', 'currencies', 'taxes',
        'payments', 'shipments', 'formfields', 'formfieldgroups', 'orderstatuses',
        'users', 'usergroups',
    ];

    /**
     * Admin screens that edit one record: an existing record needs edit, a new one create. Every
     * other layout of these screens (an order's payment popup, an item's combination editor, a
     * user's credit form) works on a record being edited, so it needs edit.
     *
     * @var string[]
     *
     * @since  1.10.0
     */
    public const FORM_SCREENS = [
        'item', 'category', 'manufacturer', 'itemfield',
        'order', 'documenttype', 'series',
        'cartrule', 'discount',
        'location',
        'place', 'currency', 'tax',
        'payment', 'shipment', 'formfield', 'formfieldgroup', 'orderstatus',
        'user', 'usergroup',
    ];

    /**
     * Admin screens whose own display() checks a permission of its own: Tools and its media screen
     * (alfa.tools), Notifications (alfa.notifications.view), a plugin panel (its menu entry's
     * action), the plugin action popup (the section the action works on) and the inventory audit
     * (core.admin).
     *
     * @var string[]
     *
     * @since  1.10.0
     */
    public const SELF_GUARDED_SCREENS = ['tools', 'toolsmedia', 'notifications', 'panel', 'action', 'inventoryaudit'];

    /**
     * What each standard Joomla list and record task does, as a permission verb. Alfa's own tasks
     * are in TASK_PERMISSIONS; any other task is one Alfa cannot vouch for.
     *
     * @var array<string, string>
     *
     * @since  1.10.0
     */
    public const TASK_ACTIONS = [
        'add' => 'create',
        'save2new' => 'create',
        'save2copy' => 'create',
        'edit' => 'edit',
        'save' => 'edit',
        'apply' => 'edit',
        'reload' => 'edit',
        'cancel' => 'edit',
        'checkin' => 'edit',
        'publish' => 'edit.state',
        'unpublish' => 'edit.state',
        'archive' => 'edit.state',
        'trash' => 'edit.state',
        'report' => 'edit.state',
        'saveorder' => 'edit.state',
        'saveorderajax' => 'edit.state',
        'reorder' => 'edit.state',
        'orderup' => 'edit.state',
        'orderdown' => 'edit.state',
        'delete' => 'delete',
    ];

    /**
     * Alfa's own controller tasks that one permission vouches for, each with the section and action
     * its controller enforces, where that differs from what the verb alone would say, or the verb
     * is Alfa's own. A task whose controller asks several permissions together (dismissing a
     * notification needs view and change state) is left out, and so hidden until a plugin declares
     * what its action needs.
     *
     * THE ORDER BUILDER'S `cart.*` TASKS ARE A DELIBERATE EXCEPTION, and they stay listed with
     * Check out on behalf, the permission every one of them asks. On a cart a storefront shopper
     * created the controller asks Carts: Edit as well ({@see \Alfa\Component\Alfa\Administrator\Service\Cart\CartTakeover}),
     * but that depends on the CART and not on the task, so it cannot be stated here and removing
     * the rows would hide plugin actions on admin carts, where checkout alone is the whole rule.
     * `cart.takeOver` asks both on a shopper's cart and Check out on behalf alone on a builder
     * cart, which is the same cart-dependent pair, so it is left out altogether.
     *
     * @var array<string, array{0: string, 1: string}>
     *
     * @since  1.10.0
     */
    public const TASK_PERMISSIONS = [
        'carts.purge' => ['carts', 'delete'],
        'cart.checkout' => ['carts', 'checkout'],
        'cart.createbuildercart' => ['carts', 'checkout'],
        'cart.searchproducts' => ['carts', 'checkout'],
        'cart.addline' => ['carts', 'checkout'],
        'cart.setquantity' => ['carts', 'checkout'],
        'cart.removeline' => ['carts', 'checkout'],
        'cart.cancelline' => ['carts', 'checkout'],
        'cart.pricelineconfig' => ['carts', 'checkout'],
        'cart.applycoupon' => ['carts', 'checkout'],
        'cart.removecoupon' => ['carts', 'checkout'],
        'cart.pointaddress' => ['carts', 'checkout'],
        'cart.chooseshipment' => ['carts', 'checkout'],
        'cart.choosepayment' => ['carts', 'checkout'],
        'cart.snapshot' => ['carts', 'checkout'],
        'documents.void' => ['documents', 'edit.state'],
        'documents.printdocument' => ['documents', 'view'],
        'documents.downloadpdf' => ['documents', 'view'],
        'formfields.setvisibility' => ['formfields', 'edit.state'],
        'itemdownloads.upload' => ['items', 'edit'],
        'itemdownloads.replace' => ['items', 'edit'],
        'itemdownloads.delete' => ['items', 'edit'],
        'item.executefieldaction' => ['items', 'edit'],
        'notifications.panel' => ['notifications', 'view'],
    ];

    /**
     * Tasks Joomla's list and record controllers register under another method's name: what
     * BaseController::execute() really runs for them.
     *
     * @var array<class-string, array<string, string>>
     *
     * @since  1.10.0
     */
    public const TASK_ALIASES = [
        \Joomla\CMS\MVC\Controller\AdminController::class => [
            'unpublish' => 'publish',
            'archive' => 'publish',
            'trash' => 'publish',
            'report' => 'publish',
            'orderup' => 'reorder',
            'orderdown' => 'reorder',
        ],
        \Joomla\CMS\MVC\Controller\FormController::class => [
            'apply' => 'save',
            'save2menu' => 'save',
            'save2new' => 'save',
            'save2copy' => 'save',
            'editassociations' => 'save',
        ],
    ];

    /**
     * How a record screen's model reads its record id, where that is not Joomla's own
     * (AdminModel::populateState(): `id` through the int filter): the order screen reads id_order
     * through the string filter (OrderModel::populateState()), because its payment, shipment and
     * line popups carry the sub-record in id.
     *
     * @var array<string, array{0: string, 1: string}> view => [request key, input filter]
     *
     * @since  1.10.0
     */
    public const RECORD_KEYS = ['order' => ['id_order', 'string']];

    /**
     * The record a screen request will load, read exactly as its model reads it: the same key
     * ({@see self::RECORD_KEYS}, else `id`), the same input filter, and the same (int) cast the
     * model applies to what it stored.
     *
     * @param string $view The view.
     * @param \Joomla\Input\Input $input The request.
     *
     * @return int The record id, 0 for none.
     *
     * @since   1.10.0
     */
    public static function recordId(string $view, \Joomla\Input\Input $input): int
    {
        [$key, $filter] = self::RECORD_KEYS[strtolower(trim($view))] ?? ['id', 'int'];

        if (!$input->exists($key)) {
            [$key, $filter] = ['id', 'int'];
        }

        $id = $input->get($key, 0, $filter);

        // As the model's (int) cast: a list is record 1, never a new record, and an encoded or
        // exponent id is the number the model will load.
        return \is_array($id) ? (int) ($id !== []) : (int) $id;
    }

    /**
     * Whether an Alfa admin controller really runs this task, as BaseController::execute() decides:
     * a public method of its own (not one of BaseController's, display apart), or a task Joomla's
     * list or record controller registers under another method ({@see self::TASK_ALIASES}). A task
     * it does not run falls through to display.
     *
     * @param string $controller The controller name, e.g. 'currencies'.
     * @param string $task The task, e.g. 'publish'.
     *
     * @return bool|null Null when Alfa has no such controller.
     *
     * @since   1.10.0
     */
    public static function controllerRuns(string $controller, string $task): ?bool
    {
        $controller = strtolower(trim($controller));
        $class = 'Alfa\\Component\\Alfa\\Administrator\\Controller\\' . ucfirst($controller) . 'Controller';

        if ($controller === '' || !class_exists($class)) {
            return null;
        }

        $task = strtolower(trim($task));

        foreach (self::TASK_ALIASES as $base => $aliases) {
            if (isset($aliases[$task]) && is_subclass_of($class, $base)) {
                $task = $aliases[$task];

                break;
            }
        }

        if ($task === 'display') {
            return true;
        }

        return \in_array($task, array_map('strtolower', get_class_methods($class)), true)
            && !\in_array($task, array_map('strtolower', get_class_methods(\Joomla\CMS\MVC\Controller\BaseController::class)), true);
    }

    /**
     * The permission that opens an admin list or record screen, for AlfaHelper::can().
     *
     * Null for any other screen: the dashboard and the order builder are decided by
     * {@see self::mayOpenScreen()}, a self-guarded screen by its own display(), and a screen Alfa
     * does not know has no permission to give.
     *
     * @param string $view The view, e.g. 'currencies' or 'currency'.
     * @param string $layout The layout ('' reads as edit on a record screen).
     * @param int $id The record id (0 for a new record).
     *
     * @return array{section: string, action: string}|null
     *
     * @since   1.10.0
     */
    public static function screenPermission(string $view, string $layout = '', int $id = 0): ?array
    {
        $view = strtolower(trim($view));

        if (\in_array($view, self::LIST_SCREENS, true)) {
            return ['section' => self::permissionSection($view), 'action' => 'view'];
        }

        if (\in_array($view, self::FORM_SCREENS, true)) {
            return ['section' => self::permissionSection($view), 'action' => self::recordLayout(layout: $layout) !== 'edit' || $id > 0 ? 'edit' : 'create'];
        }

        return null;
    }

    /**
     * The layout a record screen will render, read as HtmlView renders it: setLayout() keeps the
     * part after a template: prefix, and loadTemplate() drops every character a file name cannot
     * hold before loading the file. No layout renders the record's form.
     *
     * @param string $layout The requested layout.
     *
     * @return string The layout file's name, lower-cased; 'edit' for none.
     *
     * @since   1.14.0
     */
    public static function recordLayout(string $layout): string
    {
        if (str_contains($layout, ':')) {
            $layout = explode(':', $layout)[1];
        }

        $layout = strtolower(preg_replace('/[^A-Z0-9_\.-]/i', '', $layout));

        return $layout === '' ? 'edit' : $layout;
    }

    /**
     * Whether a request renders a record's own form: a record screen ({@see self::FORM_SCREENS})
     * on its edit layout, read as {@see self::recordLayout()} reads it. The record's other layouts
     * (an order's payment popup, an item's combination editor) are not its form.
     *
     * @param string $view The view.
     * @param string $layout The requested layout.
     *
     *
     * @since   1.14.0
     */
    public static function isRecordForm(string $view, string $layout): bool
    {
        return \in_array(strtolower(trim($view)), self::FORM_SCREENS, true) && self::recordLayout(layout: $layout) === 'edit';
    }

    /**
     * May the user open this admin screen: the one answer the dispatcher gives a page request and
     * a plugin's link to that page is shown by.
     *
     * The dashboard needs only entry to Alfa (every card on it guards itself); a cart's screen is
     * also the order builder a checkout operator works in, so carts view or checkout opens it; a
     * list or record screen needs its own permission ({@see self::screenPermission()}); any other
     * screen stays shut. The self-guarded screens are not decided here: each checks its own
     * permission when it renders.
     *
     * @param string $view The view, e.g. 'orders'.
     * @param string $layout The layout.
     * @param int $id The record id (0 for a new record).
     * @param \Joomla\CMS\User\User|null $user The user, or null for the current identity.
     *
     *
     * @since   1.10.0
     */
    public static function mayOpenScreen(string $view, string $layout = '', int $id = 0, ?\Joomla\CMS\User\User $user = null): bool
    {
        $view = strtolower(trim($view));

        if ($view === 'dashboard') {
            return true;
        }

        if ($view === 'cart') {
            return self::can(section: 'carts', action: 'view', user: $user)
                || self::can(section: 'carts', action: 'checkout', user: $user);
        }

        $permission = self::screenPermission(view: $view, layout: $layout, id: $id);

        return $permission !== null
            && self::can(section: $permission['section'], action: $permission['action'], user: $user);
    }

    /**
     * The permission a controller task needs, for AlfaHelper::can(): 'currencies.checkin' needs
     * currencies edit, 'items.publish' items change state, 'carts.purge' carts delete.
     *
     * Null when Alfa cannot vouch for the task: a controller that is not one of Alfa's sections (a
     * plugin's own task, a panel task), a task that is neither a standard Joomla one (TASK_ACTIONS)
     * nor one of Alfa's own listed in TASK_PERMISSIONS, or a standard verb the controller does not
     * actually run ({@see self::controllerRuns()}). Only the one who wrote such a task can say what
     * it needs.
     *
     * @param string $task A controller.task command.
     *
     * @return array{section: string, action: string}|null
     *
     * @since   1.10.0
     */
    public static function taskPermission(string $task): ?array
    {
        $task = strtolower(trim($task));

        if (!str_contains($task, '.')) {
            return null;
        }

        // Split as ComponentDispatcher::dispatch() splits it: anything after a second dot is not run.
        [$controller, $verb] = explode('.', $task);

        if (isset(self::TASK_PERMISSIONS[$controller . '.' . $verb])) {
            [$section, $action] = self::TASK_PERMISSIONS[$controller . '.' . $verb];

            return ['section' => $section, 'action' => $action];
        }

        if (!isset(self::PERMISSION_SECTIONS[$controller], self::TASK_ACTIONS[$verb])
            || self::controllerRuns($controller, $verb) !== true) {
            return null;
        }

        return ['section' => self::PERMISSION_SECTIONS[$controller], 'action' => self::TASK_ACTIONS[$verb]];
    }

    /**
     * A DECIMAL column's value as a merchant reads it: exact, with the column's padding zeros
     * dropped.
     *
     * The database string is trimmed directly rather than passed through a float, so a
     * DECIMAL(24,8) figure wider than float's significand keeps every digit. Trimming is confined
     * to values that carry a decimal point: "100" must never become "1".
     *
     * Presentation only. Storage and the API keep the exact decimal string.
     *
     * @param string|float|int|null $value The raw column value.
     *
     * @return string The value with no trailing zeros, "0" for zero.
     *
     * @since   1.10.0
     */
    public static function decimalDisplay(string|float|int|null $value): string
    {
        $text = (string) ($value ?? '');

        if ($text === '') {
            return '0';
        }

        if (!str_contains($text, '.')) {
            return $text;
        }

        $trimmed = rtrim(rtrim($text, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
