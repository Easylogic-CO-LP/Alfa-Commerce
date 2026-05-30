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
                'value', 'a.value',
                'state', 'a.state',
                // Translatable — resolved via the lang-table COALESCE alias.
                'name',
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

        // MULTILINGUAL: resolve the tax's own name in the active language.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_taxes',
            langPrimaryColumn: 'id_tax',
            fields:            ['name'],
        );

        // Related-entity IDs as correlated subqueries (no JOIN / GROUP BY, so
        // pagination totals stay correct). Names are resolved per-language in
        // getItems() via fetchRelated().
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
                // HAVING — `name` is the COALESCE alias from the lang join.
                $query->having('( ' . $db->quoteName('name') . ' LIKE ' . $search . ' )');
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
     * @return mixed Array of data items on success, false on failure.
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        $db = $this->getDatabase();

        // One query per relationship — names resolved per-language for the
        // translatable tables (categories, manufacturers), read directly for
        // non-translatable / core tables (users, places, usergroups).
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

        // #__users is Joomla core — name read directly, no lang tables.
        $userMap = MultilingualHelper::fetchRelated(
            db:          $db,
            items:       $items,
            idsProperty: 'user_ids',
            table:       '#__users',
            langFields:  ['name'],
        );

        // #__alfa_places — not yet translatable; name read directly.
        $placeMap = MultilingualHelper::fetchRelated(
            db:          $db,
            items:       $items,
            idsProperty: 'place_ids',
            table:       '#__alfa_places',
            langFields:  ['name'],
        );

        // Joomla core usergroups use the `title` column (not `name`).
        $ugMap = MultilingualHelper::fetchRelated(
            db:          $db,
            items:       $items,
            idsProperty: 'usergroup_ids',
            table:       '#__usergroups',
            langFields:  ['title'],
        );

        foreach ($items as $item) {
            MultilingualHelper::bindRelated($item, 'categories',    $catMap);
            MultilingualHelper::bindRelated($item, 'manufacturers', $manMap);
            MultilingualHelper::bindRelated($item, 'users',         $userMap);
            MultilingualHelper::bindRelated($item, 'places',        $placeMap);
            MultilingualHelper::bindRelated($item, 'usergroups',    $ugMap);
        }

        return $items;
    }
}
