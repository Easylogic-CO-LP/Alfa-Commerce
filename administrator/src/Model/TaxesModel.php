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
 * Methods supporting a list of Taxes records.
 *
 * @since  1.0.1
 */
class TaxesModel extends ListModel
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
                'name', 'a.name',
                'value', 'a.value',
                'state', 'a.state',
                'ordering', 'a.ordering',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @param string $ordering Elements tax
     * @param string $direction Tax direction
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
     * @return DatabaseQuery
     *
     * @since   1.0.1
     */
    protected function getListQuery()
    {
        // Create a new query object.
        $db = $this->getDbo();
        $query = $db->getQuery(true);

        // Select the required fields from the table.
        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.*',
            ),
        );
        $query->from('`#__alfa_taxes` AS a');

        // Join over the users for the checked out user
        $query->select('uc.name AS uEditor');
        $query->join('LEFT', '#__users AS uc ON uc.id=a.checked_out');

        // Join over the user field 'created_by'
        $query->select('`created_by`.name AS `created_by`');
        $query->join('LEFT', '#__users AS `created_by` ON `created_by`.id = a.`created_by`');

        // Join over the user field 'modified_by'
        $query->select('`modified_by`.name AS `modified_by`');
        $query->join('LEFT', '#__users AS `modified_by` ON `modified_by`.id = a.`modified_by`');

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_taxes',
            langPrimaryColumn: 'id_tax',
            fields:            ['name'],
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_tax_categories',
            junctionFk:    'tax_id',
            junctionValue: 'category_id',
            selectAlias:   'category_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_tax_manufacturers',
            junctionFk:    'tax_id',
            junctionValue: 'manufacturer_id',
            selectAlias:   'manufacturer_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_tax_users',
            junctionFk:    'tax_id',
            junctionValue: 'user_id',
            selectAlias:   'user_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_tax_places',
            junctionFk:    'tax_id',
            junctionValue: 'place_id',
            selectAlias:   'place_ids',
        );

        MultilingualHelper::addRelatedIdsToQuery(
            query:         $query,
            mainAlias:     'a',
            mainPk:        'id',
            junctionTable: '#__alfa_tax_usergroups',
            junctionFk:    'tax_id',
            junctionValue: 'usergroup_id',
            selectAlias:   'usergroup_ids',
        );

        // Filter by published state
        $published = $this->getState('filter.state');

        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where('(a.state IN (0, 1))');
        }

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
            }
        }

        // Add the list ordering clause.
        $orderCol = $this->state->get('list.ordering', 'a.id');
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
