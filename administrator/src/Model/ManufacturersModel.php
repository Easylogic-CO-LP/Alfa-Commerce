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

/**
 * Methods supporting a list of Manufacturers records.
 *
 * @since  1.0.0
 */
class ManufacturersModel extends AlfaListModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('manufacturer').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'manufacturer';

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
                'ordering', 'a.ordering',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
                'id', 'a.id',
                'state', 'a.state',
                'website', 'a.website',
                // Translatable: resolved via the lang-table COALESCE alias.
                'name',
                'alias',
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
     * @since  1.0.0
     */
    protected function getStoreId($id = '')
    {
        // Serialise EVERY filter into the cache identity, including filters a plugin
        // declared through onAlfaAdminListFilters, which a hand-enumerated list would
        // silently omit (stale cache + the contract's list phases skipping re-runs).
        // Same discipline as the orders grid.
        $filters = [];

        // toArray(), not (array): the model State keeps LITERAL dotted keys, so an
        // object cast returns its protected properties and matches nothing.
        foreach ($this->getState()->toArray() as $key => $value) {
            if (str_starts_with((string) $key, 'filter.')) {
                $filters[$key] = \is_array($value) ? implode(',', $value) : (string) $value;
            }
        }

        ksort($filters);

        foreach ($filters as $key => $value) {
            $id .= ':' . $key . '=' . $value;
        }

        return parent::getStoreId($id);
    }

    /**
     * Build an SQL query to load the list data.
     *
     * @return DatabaseQuery
     *
     * @since  1.0.0
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
        $query->from('`#__alfa_manufacturers` AS a');

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
            langTableBase:     '#__alfa_manufacturers',
            langPrimaryColumn: 'id_manufacturer',
            fields:            ['name', 'alias'],
        );

        // Assigned item count: correlated subquery for the same reason as
        // CategoriesModel: DISTINCT a.* plus a bare HAVING forbid a junction join.
        // COUNT(DISTINCT) because the junction table has no unique key, and the
        // item states mirror the Items list default so the badge and the linked
        // list agree.
        $query->select(
            '(SELECT COUNT(DISTINCT ' . $db->quoteName('im.item_id') . ')'
            . ' FROM ' . $db->quoteName('#__alfa_items_manufacturers', 'im')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_items', 'mi')
            . ' ON ' . $db->quoteName('mi.id') . ' = ' . $db->quoteName('im.item_id')
            . ' WHERE ' . $db->quoteName('im.manufacturer_id') . ' = ' . $db->quoteName('a.id')
            . ' AND ' . $db->quoteName('mi.state') . ' IN (0, 1)'
            . ') AS ' . $db->quoteName('item_count'),
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
                // HAVING: `name` is the COALESCE alias from the lang join, not a
                // real column at WHERE time.
                $query->having('( ' . $db->quoteName('name') . ' LIKE ' . $search . ' )');
            }
        }

        // Add the list ordering clause.
        $orderCol = $this->state->get('list.ordering', 'id');
        $orderDirn = $this->state->get('list.direction', 'ASC');

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
        $items = parent::getItems();

        return $items;
    }
}
