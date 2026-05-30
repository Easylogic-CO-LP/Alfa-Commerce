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
use Exception;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

/**
 * Methods supporting a list of Items records.
 *
 * @since  1.0.1
 */
class ItemsModel extends ListModel
{
    /**
    * Constructor.
    *
     * @param array $config An optional associative array of configuration settings.
    *
    * @see        JController
    * @since      1.6
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
                'stock_action', 'a.stock_action',
                'manage_stock', 'a.manage_stock',
                // Translatable — resolved via the lang-table COALESCE alias.
                'name',
                'alias',
                // Relational filters (many-to-many junctions).
                'category',
                'manufacturer',
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
     * @since   1.0.1
     */
    protected function getStoreId($id = '')
    {
        // Compile the store id.
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.state');
        $id .= ':' . implode(',', (array) $this->getState('filter.category'));
        $id .= ':' . implode(',', (array) $this->getState('filter.manufacturer'));

        return parent::getStoreId($id);
    }

    /**
     * Build an SQL query to load the list data.
     *
     * @since   1.0.1
     */
    protected function getListQuery()
    {
        // Create a new query object.
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // Select the required fields from the table.
        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.*',
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
        // correlated subqueries (no JOIN, so DISTINCT a.* row count is untouched).
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

        // Filter by published state
        $published = $this->getState('filter.state');

        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where('(a.state IN (0, 1))');
        }

        // Filter by category (multi-select, many-to-many). Matches the selected
        // categories exactly — no subcategory expansion. Subquery keeps the main
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

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                // HAVING — `name` is the COALESCE alias from the lang join; sku/gtin/mpn
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
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }

        return $query;
    }

    /**
     * Get an array of data items
     *
     * @return mixed Array of data items on success, false on failure.
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        // Resolve the comma-separated category / manufacturer ids (from the
        // correlated subqueries) into translated records for display. Each bound
        // value is a map [relatedId => ['id' => …, 'name' => …]].
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

        return $items;
    }
}
