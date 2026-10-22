<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\CombinationState;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Projection\ItemFieldValueProjection;
use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Exception;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\QueryInterface;

/**
 * Methods supporting a list of Items records.
 *
 * @since  1.0.0
 */
class ItemsModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'item';

    /**
     * The enriched page of the last getItems() run: the contract's list phases
     * re-ask getItems(), and without the memo the two loadRelated() resolutions
     * would run twice per request.
     *
     *
     * @since  2.0.0
     */
    private ?array $alfaEnriched = null;

    /**
    * Constructor.
    *
     * @param array $config An optional associative array of configuration settings.
    *
    * @see        JController
    * @since  1.0.0
    */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'state', 'a.state',
                'ordering', 'a.ordering',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
                'id', 'a.id',
                'sku', 'a.sku',
                'gtin', 'a.gtin',
                'mpn', 'a.mpn',
                'stock', 'a.stock',
                'available_quantity', 'a.available_quantity',
                'stock_action', 'a.stock_action',
                'manage_stock', 'a.manage_stock',
                // Translatable: resolved via the lang-table COALESCE alias.
                // Real ORDER BY targets: the multilingual join emits both
                // (addMultilingualJoinToQuery fields: ['name', 'alias']).
                'name',
                'alias',

                // NOTE: 'category' and 'manufacturer' are deliberately ABSENT.
                //
                // filter_fields is not a filter list; it is the ORDERING whitelist
                // (`in_array($fullOrdering, $this->filter_fields)` in
                // ListModel::populateState). Both are many-to-many junction FILTERS
                // with no column or alias behind them, so listing them here made
                // `list[fullordering]=category ASC` an accepted sort: the query then
                // asked for a column that does not exist and the grid silently
                // returned "No Matching Results", and it STAYS that way, because
                // searchtools' Clear button does not reset the sort unless
                // clearListOptions is on (it is off by default). Only the "Sort Table
                // By" dropdown gets you out, and it renders blank in that state.
                //
                // They do not need to be here: getActiveFilters() derives from the
                // filter FORM ({@see HasFormDerivedFiltersTrait}), so the filter panel
                // still opens for them.
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @param string $ordering Elements order
     * @param string $direction Order direction
     *
     * @return void
     *
     * @throws Exception
     * @since  1.0.0
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Method to get a store id based on model configuration state.
     *
     * This is necessary because the model is used by the component and
     * different modules that might need different sets of data or different
     * ordering requirements.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string A store id.
     *
     * @since  1.0.0
     */
    protected function getStoreId($id = '')
    {
        // Serialise EVERY filter into the cache identity, including filters a plugin
        // declared through onAlfaAdminListFilters, which a hand-enumerated list would
        // silently omit (stale cache + the contract's list phases skipping re-runs).
        // Same discipline as the orders grid.
        $filters = [];

        // toArray(), NOT (array): the model State keeps LITERAL dotted keys
        // ('filter.category'), and an object cast hands back the protected
        // properties instead, so this loop used to match nothing at all and every
        // filter combination shared one store id.
        foreach ($this->getState()->toArray() as $key => $value) {
            if (str_starts_with((string) $key, 'filter.')) {
                // filter.fields is nested ([fieldId => [valueIds]]), so a flat implode
                // would stringify "Array" and collide two different selections.
                $filters[$key] = \is_array($value) ? json_encode($value) : (string) $value;
            }
        }

        ksort($filters);

        foreach ($filters as $key => $value) {
            $id .= ':' . $key . '=' . $value;
        }

        return parent::getStoreId($id);
    }

    /**
     * The item-field facets reachable from the CURRENT admin filters.
     *
     * Same shape and same rules as the storefront's facet bar: the candidate set
     * is whatever the grid is showing (category, manufacturer, state, the other
     * fields' selections), each facet excludes ONLY its own selection so picking
     * Color=Red still lists every Color, and a field with nothing reachable drops
     * out entirely, which is what stops the bar from listing filters that would
     * only ever return nothing.
     *
     * Two things differ from the storefront, both on purpose. Publication is not a
     * criterion: a field the merchant unpublished is precisely the one being
     * hunted for, so it is listed and labelled instead of hidden. And the free-text
     * search does not narrow the counts: it lives in HAVING over a multilingual
     * COALESCE alias, which cannot survive the regrouping this query does.
     *
     * @return array<int, array{label: string, retired: bool, values: array<int, array{name: string, count: int}>}>
     *
     * @since  2.0.0
     */
    public function getAvailableItemFieldValues(): array
    {
        $usedFieldIds = ItemFieldValueProjection::usedFieldIds();

        if (!$usedFieldIds) {
            return [];
        }

        $db = $this->getDatabase();
        $fields = ItemFieldsHelper::getFields($usedFieldIds);

        // Only a field with a real selection needs a query of its own. Self-exclusion
        // is a no-op for every other field, so they all share ONE pass grouped by
        // (field_id, value_id) instead of one query each: 1 + selected, not 1 per
        // field. On a catalogue with twenty item fields that is the difference
        // between twenty joins of the full list query per page load and one.
        $active = [];

        foreach ((array) $this->getState('filter.fields', []) as $fieldId => $valueIds) {
            if (array_filter(array_map('intval', (array) $valueIds))) {
                $active[(int) $fieldId] = true;
            }
        }

        $facetQuery = function (array $fieldFilter, ?int $only) use ($db) {
            $sub = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
                ->createModel('Items', 'Administrator', ['ignore_request' => true]);

            foreach ($this->getState()->toArray() as $key => $value) {
                if (str_starts_with((string) $key, 'filter.')) {
                    $sub->setState($key, $value);
                }
            }

            $sub->setState('filter.fields', $fieldFilter ?: null);

            $query = $sub->getListQuery();
            $query->clear('select')
                ->clear('order')
                ->clear('group')
                ->clear('having')
                ->join('INNER', $db->quoteName('#__alfa_item_field_value_index', 'ifx') . ' ON ifx.item_id = a.id')
                ->select('ifx.field_id AS field_id, ifx.value_id AS value_id, COUNT(DISTINCT a.id) AS cnt')
                ->group('ifx.field_id, ifx.value_id');

            if ($only !== null) {
                $query->where('ifx.field_id = ' . $only);
            }

            return $db->setQuery($query)->loadObjectList() ?: [];
        };

        $selections = (array) $this->getState('filter.fields', []);
        $counts = [];

        foreach ($facetQuery($selections, null) as $row) {
            $counts[(int) $row->field_id][(int) $row->value_id] = (int) $row->cnt;
        }

        // A field you have already picked from must keep listing all of its values,
        // or you could never add a second one, so it is counted against everything
        // EXCEPT its own selection.
        foreach (array_keys($active) as $fieldId) {
            $without = $selections;
            unset($without[$fieldId]);
            $counts[$fieldId] = [];

            foreach ($facetQuery($without, $fieldId) as $row) {
                $counts[$fieldId][(int) $row->value_id] = (int) $row->cnt;
            }
        }

        $result = [];
        $allValues = ItemFieldsHelper::getValues(array_merge(...array_values(array_map('array_keys', $counts)) ?: [[]]));

        foreach ($fields as $fieldId => $field) {
            $fieldId = (int) $fieldId;

            if (empty($counts[$fieldId])) {
                continue;
            }

            $values = [];

            foreach ($counts[$fieldId] as $valueId => $total) {
                $values[$valueId] = [
                    'name' => $allValues[$valueId]->display_name ?? ('#' . $valueId),
                    'count' => $total,
                ];
            }

            $result[$fieldId] = [
                'label' => (string) ($field->display_name ?? $field->field_name ?? ''),
                'retired' => !empty($field->retired),
                'values' => $values,
            ];
        }

        return $result;
    }

    /**
     * Narrow the list by stock position, with the dashboard's definitions.
     *
     * A product without variants is read at item level; one with variants is read
     * through its published variants (any variant out = the product is out; any
     * variant in = in). Archived variants are not variants of the product here: one
     * whose variants are all archived is read at item level. "Running low" is at or
     * below the low-stock mark: the unit's own (a variant falls back to its
     * product's), else the shop-wide one, and includes zero. Unmanaged stock is
     * always "in". The dashboard's stock tiles open this list with these filters, so
     * they must count by these same definitions.
     *
     * @param QueryInterface $query The list query.
     * @param string $stock `in`, `low` or `out`.
     *
     *
     * @since  2.0.0
     */
    private function applyStockFilter(QueryInterface $query, string $stock): void
    {
        $db = $this->getDatabase();
        $global = (float) ComponentHelper::getParams('com_alfa')->get('stock_low', 0);
        $itemMark = 'COALESCE(NULLIF(' . $db->quoteName('a.stock_low') . ', 0), ' . $global . ')';
        $unitMark = 'COALESCE(NULLIF(' . $db->quoteName('c.stock_low') . ', 0), NULLIF(' . $db->quoteName('a.stock_low') . ', 0), ' . $global . ')';
        // A tracked quantity never initialised is NULL, and NULL <= 0 is not true: read
        // it as zero, as the storefront's sellability rule does, or such a product is
        // neither in nor out of anything.
        $qty = 'COALESCE(' . $db->quoteName('a.available_quantity') . ', 0)';
        $unitQty = 'COALESCE(' . $db->quoteName('c.available_quantity') . ', 0)';
        $units = $db->quoteName('#__alfa_item_combinations', 'c');
        $owned = $db->quoteName('c.item_id') . ' = ' . $db->quoteName('a.id');
        $noUnits = 'NOT EXISTS (SELECT 1 FROM ' . $units . ' WHERE ' . $owned
            . ' AND ' . CombinationState::currentSql(column: $db->quoteName('c.state')) . ')';
        // A unit is tracked by its own flag, the item's when it has none: the same
        // chain the storefront's sellability rule resolves, so a product whose
        // tracking lives on its variants is not missed here.
        $tracked = 'COALESCE(' . $db->quoteName('c.manage_stock') . ', ' . $db->quoteName('a.manage_stock') . ', 0) = 1';
        $anyUnit = static fn (string $cond): string => 'EXISTS (SELECT 1 FROM ' . $units . ' WHERE ' . $owned
            . ' AND ' . $db->quoteName('c.state') . ' = 1 AND ' . $tracked . ' AND ' . $cond . ')';
        $managed = $db->quoteName('a.manage_stock') . ' = 1';

        switch ($stock) {
            case 'out':
                $query->where('((' . $managed . ' AND ' . $qty . ' <= 0 AND ' . $noUnits . ') OR ' . $anyUnit($unitQty . ' <= 0') . ')');
                break;

            case 'low':
                $query->where('((' . $managed . ' AND ' . $itemMark . ' > 0 AND ' . $qty . ' <= ' . $itemMark . ' AND ' . $noUnits . ')'
                    . ' OR ' . $anyUnit($unitMark . ' > 0 AND ' . $unitQty . ' <= ' . $unitMark) . ')');
                break;

            case 'in':
                $query->where('((' . $db->quoteName('a.manage_stock') . ' = 0 AND ' . $noUnits . ') OR (' . $qty . ' > 0 AND ' . $noUnits . ')'
                    . ' OR EXISTS (SELECT 1 FROM ' . $units . ' WHERE ' . $owned . ' AND ' . $db->quoteName('c.state') . ' = 1'
                    . ' AND (NOT ' . $tracked . ' OR ' . $unitQty . ' > 0)))');
                break;
        }
    }

    /**
     * Build an SQL query to load the list data.
     *
     * @since  1.0.0
     */
    protected function getListQuery()
    {
        // Create a new query object.
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // Select the required fields from the table. No DISTINCT: every join below is
        // to one row (users by primary key, the language row by its primary key) and the
        // relations are correlated subqueries, so no product appears twice. DISTINCT made
        // the database evaluate every subquery for every product before taking the page.
        $query->select(
            $this->getState(
                'list.select',
                'a.*',
            ),
        );
        $query->from('`#__alfa_items` AS a');

        // Join over the users for the checked out user
        $query->select('uc.name AS uEditor');
        $query->join('LEFT', '#__users AS uc ON uc.id=a.checked_out');

        // Join over the user field 'created_by'
        $query->select('`created_by`.name AS `created_by`');
        $query->join('LEFT', '#__users AS `created_by` ON `created_by`.id = a.`created_by`');

        // Join over the user field 'modified_by'
        $query->select('`modified_by`.name AS `modified_by`');
        $query->join('LEFT', '#__users AS `modified_by` ON `modified_by`.id = a.`modified_by`');

        // MULTILINGUAL: resolve name / alias in the active language from the
        // per-language tables (LEFT JOIN + COALESCE keeps untranslated rows).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            fields:            ['name', 'alias'],
        );

        // RELATIONS: comma-separated category / manufacturer ids per item via
        // correlated subqueries (no JOIN, so the row count is untouched).
        // getItems() resolves these ids to translated names.
        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_items_categories',
            junctionFk:    'item_id',
            junctionValue: 'category_id',
            selectAlias:   'category_ids',
        );
        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_items_manufacturers',
            junctionFk:    'item_id',
            junctionValue: 'manufacturer_id',
            selectAlias:   'manufacturer_ids',
        );

        // INLINE QUICK-EDIT decision data: resolved inside the ONE list query as
        // a correlated scalar subquery, never as a lookup per rendered row.
        // Variants own their own sku and stock, so a variant product's item-level
        // cells are read-only aggregates; the same count also labels the SKU cell.
        // How many LOCATIONS hold the stock is deliberately NOT asked here: it
        // gates nothing (the editor asks for each location by name) and the list
        // no longer shows it, so it would be two subqueries per row for nothing.
        // Only current variants count: an archived one is kept as the stock identity
        // of order history, and a product whose variants are all archived is plain.
        $query->select(
            '(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_combinations', 'ic')
            . ' WHERE ' . $db->quoteName('ic.item_id') . ' = ' . $db->quoteName('a.id')
            . ' AND ' . CombinationState::currentSql(column: $db->quoteName('ic.state'))
            . ') AS ' . $db->quoteName('combination_count'),
        );

        // STOCK STATUS DATA for the Available cell, in the same list query. The
        // missing-ledger flag reads the stock records (a stock-managed product, or one
        // of its combinations, showing stock figures without any record) and the open
        // integrity holds, and the tracked-unit count tells a product with combinations
        // whether any of them tracks stock, with each combination's own setting before
        // the product's. The cell's smallest sale is computed from the product's
        // quantity_min, quantity_step and quantity_max, which a.* already selects.
        $query->select(InventoryAvailabilityService::ledgerMissingSql(alias: 'a') . ' AS ' . $db->quoteName('ledger_missing'));
        $query->select(
            '(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_item_combinations', 'tu')
            . ' WHERE ' . $db->quoteName('tu.item_id') . ' = ' . $db->quoteName('a.id')
            . ' AND ' . CombinationState::currentSql(column: $db->quoteName('tu.state'))
            . ' AND COALESCE(' . $db->quoteName('tu.manage_stock') . ', ' . $db->quoteName('a.manage_stock') . ', 0) = 1'
            . ') AS ' . $db->quoteName('tracked_units'),
        );

        // Filter by published state
        $published = $this->getState('filter.state');

        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where('(a.state IN (0, 1))');
        }

        // Stock: in, running low, out. The dashboard's stock tiles link here with these
        // filters and must count by the same definitions (see applyStockFilter).
        $stock = (string) $this->getState('filter.stock', '');

        if ($stock !== '') {
            $this->applyStockFilter($query, $stock);
        }

        // Filter by category (multi-select, many-to-many). Matches the selected
        // categories exactly: no subcategory expansion. Subquery keeps the main
        // query shape (no JOIN, no GROUP BY needed).
        $categories = array_filter(array_map('intval', (array) $this->getState('filter.category')));

        if (!empty($categories)) {
            $query->where(
                'a.id IN (SELECT ' . $db->quoteName('item_id')
                . ' FROM ' . $db->quoteName('#__alfa_items_categories')
                . ' WHERE ' . $db->quoteName('category_id') . ' IN (' . implode(',', $categories) . '))',
            );
        }

        // Filter by manufacturer (multi-select, many-to-many).
        $manufacturers = array_filter(array_map('intval', (array) $this->getState('filter.manufacturer')));

        if (!empty($manufacturers)) {
            $query->where(
                'a.id IN (SELECT ' . $db->quoteName('item_id')
                . ' FROM ' . $db->quoteName('#__alfa_items_manufacturers')
                . ' WHERE ' . $db->quoteName('manufacturer_id') . ' IN (' . implode(',', $manufacturers) . '))',
            );
        }

        // Item-field value filter (Size, Color, ...)
        // The SAME contract the storefront and mod_alfa_filters use:
        // filter[fields][<id>][] -> AND across fields, OR within a field, over the
        // shared projection. Reusing it is what lets "used by 12 items" on a field
        // be a link straight into this grid, and what guarantees the admin count
        // and the catalogue page can never disagree. A field with no value ids
        // asks the weaker question: which items use this field at all.
        foreach ((array) $this->getState('filter.fields', []) as $fieldId => $valueIds) {
            $query->where(ItemFieldValueProjection::existsSql(
                itemAlias: 'a',
                fieldId:   (int) $fieldId,
                valueIds:  (array) $valueIds,
            ));
        }

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                // HAVING: `name` is the COALESCE alias from the lang join; sku/gtin/mpn
                // are real columns (selected via a.*). Match any of them.
                $query->having(
                    '(' . $db->quoteName('name') . ' LIKE ' . $search
                    . ' OR ' . $db->quoteName('a.sku') . ' LIKE ' . $search
                    . ' OR ' . $db->quoteName('a.gtin') . ' LIKE ' . $search
                    . ' OR ' . $db->quoteName('a.mpn') . ' LIKE ' . $search
                    . ')',
                );
            }
        }

        // Add the list ordering clause.
        $orderCol = $this->state->get('list.ordering', 'id');
        $orderDirn = $this->state->get('list.direction', 'DESC');

        if ($orderCol && $orderDirn) {
            $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));
        }

        return $query;
    }

    /**
     * Get an array of data items
     *
     * @return mixed Array of data items on success, false on failure.
     * @since  1.0.0
     */
    public function getItems()
    {
        if ($this->alfaEnriched !== null) {
            return $this->alfaEnriched;
        }

        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        // Resolve the comma-separated category / manufacturer ids (from the
        // correlated subqueries) into translated records for display. Each bound
        // value is a map [relatedId => ['id' => ..., 'name' => ...]].
        $db = $this->getDatabase();

        MultilingualHelper::loadRelated(
            db:                $db,
            items:             $items,
            idsProperty:       'category_ids',
            bindTo:            'categories',
            table:             '#__alfa_categories',
            langTableBase:     '#__alfa_categories',
            langPrimaryColumn: 'id_category',
        );
        MultilingualHelper::loadRelated(
            db:                $db,
            items:             $items,
            idsProperty:       'manufacturer_ids',
            bindTo:            'manufacturers',
            table:             '#__alfa_manufacturers',
            langTableBase:     '#__alfa_manufacturers',
            langPrimaryColumn: 'id_manufacturer',
        );

        // Derive the inline-editability of the stock cell from the counts the
        // list query already returned; no query here, and the UX mirror only:
        // ItemController re-derives the same rule server-side before writing.
        // Several locations is NOT a blocker: the editor asks for each location
        // by name. Only a VARIANT product is refused, because its item-level
        // figure is an aggregate no single correction can own, and variants are
        // tested first, so a variant product with manage_stock off reads as
        // "stocked per variant" rather than "not tracked". An archived product is
        // kept for its order history and is not a stock unit anyone edits here.
        foreach ($items as $item) {
            $item->stock_inline_editable = (int) ($item->combination_count ?? 0) === 0
                && (int) ($item->manage_stock ?? 0) === 1
                && CombinationState::isCurrent(state: $item->state ?? null);
        }

        return $this->alfaEnriched = $items;
    }
}
