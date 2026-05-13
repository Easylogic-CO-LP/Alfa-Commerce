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
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

/**
 * Methods supporting a list of Shipments records.
 *
 * @since  1.0.1
 */
class ShipmentsModel extends ListModel
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
                'id', 'a.id',
                'ordering', 'a.ordering',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
                'name', 'a.name',
                'state', 'a.state',
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
    protected function populateState($ordering = 'a.id', $direction = 'ASC')
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

        return parent::getStoreId($id);
    }

    /**
     * Build an SQL query to load the list data.
     *
     * DESIGN
     * ------
     * The query contains no junction/relationship JOINs and no GROUP BY.
     * Joomla's getTotalItems() / getPagination() work correctly because
     * COUNT(*) wraps a clean SELECT without aggregation.
     *
     * The shipment's own translatable name is resolved inline via
     * addMultilingualJoinToQuery() — one LEFT JOIN, no grouping side-effects.
     *
     * Related entity IDs (categories, manufacturers, users, places, usergroups)
     * are collected via addRelatedIdsToQuery(), which emits one correlated scalar
     * subquery per relationship. No outer GROUP BY is needed. Full records with
     * translated names are loaded in getItems() after pagination.
     *
     * @return \Joomla\Database\DatabaseQuery
     *
     * @since   1.0.1
     */
    protected function getListQuery()
    {
        // Create a new query object.
        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        // Select the required fields from the table.
        $query->select(
            $this->getState(
                'list.select',
                'a.*',
            ),
        );
        $query->from($db->qn('#__alfa_shipments', 'a'));

        // Join over the users for the checked out user
        $query->select($db->qn('uc.name', 'uEditor'));
        $query->join('LEFT', $db->qn('#__users', 'uc') . ' ON ' . $db->qn('uc.id') . ' = ' . $db->qn('a.checked_out'));

        // Join over the user field 'created_by'
        $query->select($db->qn('created_by.name', 'created_by'));
        $query->join('LEFT', $db->qn('#__users', 'created_by') . ' ON ' . $db->qn('created_by.id') . ' = ' . $db->qn('a.created_by'));

        // Join over the user field 'modified_by'
        $query->select($db->qn('modified_by.name', 'modified_by'));
        $query->join('LEFT', $db->qn('#__users', 'modified_by') . ' ON ' . $db->qn('modified_by.id') . ' = ' . $db->qn('a.modified_by'));

        // Resolve the shipment's own translatable name inline.
        // Joins current-language table + optional default-language fallback via COALESCE.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_shipments',
            langPrimaryColumn: 'id_shipment',
            fields:            ['name'],
        );

        // Add one correlated scalar subquery per relationship.
        // Each emits a comma-separated IDs string — no outer GROUP BY needed.
        // Full records with translated names are loaded in getItems() after pagination.
        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_shipment_categories',
            junctionFk:    'shipment_id',
            junctionValue: 'category_id',
            selectAlias:   'category_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_shipment_manufacturers',
            junctionFk:    'shipment_id',
            junctionValue: 'manufacturer_id',
            selectAlias:   'manufacturer_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_shipment_users',
            junctionFk:    'shipment_id',
            junctionValue: 'user_id',
            selectAlias:   'user_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_shipment_places',
            junctionFk:    'shipment_id',
            junctionValue: 'place_id',
            selectAlias:   'place_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_shipment_usergroups',
            junctionFk:    'shipment_id',
            junctionValue: 'usergroup_id',
            selectAlias:   'usergroup_ids',
        );

        // Filter by published state
        $published = $this->getState('filter.state');

        if (is_numeric($published)) {
            $query->where($db->qn('a.state') . ' = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where($db->qn('a.state') . ' IN (0, 1)');
        }

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where($db->qn('a.id') . ' = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                $query->where($db->qn('a.name') . ' LIKE ' . $search);
            }
        }

        // Add the list ordering clause.
        $orderCol  = $this->state->get('list.ordering', 'a.id');
        $orderDirn = $this->state->get('list.direction', 'ASC');

        if ($orderCol && $orderDirn) {
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }

        return $query;
    }

    /**
     * Get an array of data items
     *
     * Enriches each paginated shipment with full related entity data.
     *
     * PATTERN
     * -------
     * 1. All DB work is grouped before the loop.
     *    fetchRelated() is called once per relationship — it fires one query
     *    per call and returns a [$itemId => [$relatedId => $record]] map.
     *    The outer key is indexed by the shipment ID (a.id), NOT the junction
     *    FK — the junction FK was already consumed in getListQuery() to build
     *    the comma-separated IDs string on each item.
     *
     * 2. One foreach loop handles all binding and any per-item logic.
     *    bindRelated() is called once per relationship inside the loop —
     *    it assigns the correct records from the map onto each item and always
     *    initialises the property to [] when no records exist for that item.
     *
     * ALTERNATIVE — one-liner per relationship (no extra per-item logic needed):
     *    Replace all fetchRelated() calls + the foreach with individual
     *    loadRelated() calls — see the commented block below the foreach.
     *
     * @return mixed Array of data items on success, false on failure.
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        $db = $this->getDatabase();

        // ── Fetch all relationships — all DB queries grouped here ─────────────
        // fetchRelated() is pure data: no mutation, one query per relationship.
        // fields:     structural columns — same in every language (e.g. alias).
        // langFields: translatable columns — resolved via lang tables when
        //             langTableBase is set, or directly from the table when not.

        $catMap = MultilingualHelper::fetchRelated(
            db:                $db,
            items:             $items,
            idsProperty:       'category_ids',
            table:             '#__alfa_categories',
            langTableBase:     '#__alfa_categories',
            langPrimaryColumn: 'id_category',
            langFields:        ['name'],
        );

        $manMap = MultilingualHelper::fetchRelated(
            db:                $db,
            items:             $items,
            idsProperty:       'manufacturer_ids',
            table:             '#__alfa_manufacturers',
            langTableBase:     '#__alfa_manufacturers',
            langPrimaryColumn: 'id_manufacturer',
            langFields:        ['name'],
        );

        // #__users is Joomla core — no lang tables exist.
        // langTableBase omitted: name is read directly from #__users.
        $userMap = MultilingualHelper::fetchRelated(
            db:          $db,
            items:       $items,
            idsProperty: 'user_ids',
            table:       '#__users',
            langFields:  ['name'],
            // no langTableBase — name read directly from #__users
        );

        $placeMap = MultilingualHelper::fetchRelated(
            db:          $db,
            items:       $items,
            idsProperty: 'place_ids',
            table:       '#__alfa_places',
            langFields:  ['name'],
            // no langTableBase — name read directly from #__alfa_places
        );

        $ugMap = MultilingualHelper::fetchRelated(
            db:          $db,
            items:       $items,
            idsProperty: 'usergroup_ids',
            table:       '#__alfa_usergroups',
            langFields:  ['name'],
            // no langTableBase — name read directly from #__alfa_usergroups
        );

        // ── One loop — bind all maps + any per-item logic ─────────────────────
        // bindRelated() assigns [$relatedId => $record] onto each item.
        // Always sets the property to [] when the item has no related records
        // so templates never receive undefined property warnings.
        foreach ($items as $item) {
            MultilingualHelper::bindRelated($item, 'categories',    $catMap);
            MultilingualHelper::bindRelated($item, 'manufacturers', $manMap);
            MultilingualHelper::bindRelated($item, 'users',         $userMap);
            MultilingualHelper::bindRelated($item, 'places',        $placeMap);
            MultilingualHelper::bindRelated($item, 'usergroups',    $ugMap);

            // Add any other per-item logic here (links, media, prices, etc.)
        }

        // ── ALTERNATIVE: one-liner per relationship via loadRelated() ──────────
        // Use this instead of the fetchRelated() calls + foreach above when
        // there is no extra per-item logic needed in the loop.
        //
        // MultilingualHelper::loadRelated($db, $items, 'category_ids',    'categories',    '#__alfa_categories',    fields: ['alias'], langTableBase: '#__alfa_categories',    langPrimaryColumn: 'id_category',    langFields: ['name', 'description']);
        // MultilingualHelper::loadRelated($db, $items, 'manufacturer_ids','manufacturers', '#__alfa_manufacturers',                   langTableBase: '#__alfa_manufacturers', langPrimaryColumn: 'id_manufacturer', langFields: ['name']);
        // MultilingualHelper::loadRelated($db, $items, 'user_ids',        'users',         '#__users',                                                                                                                langFields: ['name']);
        // MultilingualHelper::loadRelated($db, $items, 'place_ids',       'places',        '#__alfa_places',                          langTableBase: '#__alfa_places',        langPrimaryColumn: 'id_place',        langFields: ['name']);
        // MultilingualHelper::loadRelated($db, $items, 'usergroup_ids',   'usergroups',    '#__alfa_usergroups',                      langTableBase: '#__alfa_usergroups',    langPrimaryColumn: 'id_usergroup',    langFields: ['name']);

        return $items;
    }
}