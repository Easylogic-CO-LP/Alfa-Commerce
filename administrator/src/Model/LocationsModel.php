<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;

/**
 * Inventory locations list model: location rows with their aggregate
 * physical/reserved figures (read from the authoritative stock rows,
 * display only).
 *
 * @since  1.4.0
 */
class LocationsModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'location';

    /**
     * Constructor: sortable columns.
     *
     * @param array $config Configuration array.
     *
     * @since  1.4.0
     */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'title', 'a.title',
                'location_type', 'a.location_type',
                'ordering', 'a.ordering',
                'state', 'a.state',
            ];
        }

        parent::__construct($config);
    }

    /**
     * Populate list state.
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default direction.
     *
     * @since  1.4.0
     */
    protected function populateState($ordering = 'a.ordering', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Build the list query.
     *
     * @return \Joomla\Database\QueryInterface
     *
     * @since  1.4.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select('a.*')
            ->select('COALESCE(s.units, 0) AS stock_units, COALESCE(s.physical, 0) AS stock_physical, COALESCE(s.reserved, 0) AS stock_reserved')
            ->from($db->quoteName('#__alfa_inventory_locations', 'a'))
            ->join(
                'LEFT',
                '(SELECT location_id, COUNT(*) AS units, SUM(quantity) AS physical, SUM(reserved_quantity) AS reserved'
                . ' FROM ' . $db->quoteName('#__alfa_inventory_stock') . ' GROUP BY location_id) AS s'
                . ' ON s.location_id = a.id',
            );

        // Translated display name in the active admin language.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_inventory_locations',
            langPrimaryColumn: 'id_inventory_location',
            fields:            ['name'],
        );

        $search = trim((string) $this->getState('filter.search', ''));

        if ($search !== '') {
            $like = '%' . $db->escape($search, true) . '%';
            $query->extendWhere('AND', [
                $db->quoteName('a.title') . ' LIKE ' . $db->quote($like),
                $db->quoteName('a.kind') . ' LIKE ' . $db->quote($like),
            ], 'OR');
        }

        $state = $this->getState('filter.state', '');

        if ($state !== '' && $state !== null) {
            $query->where($db->quoteName('a.state') . ' = ' . (int) $state);
        }

        $type = (string) $this->getState('filter.location_type', '');

        if ($type !== '') {
            $query->where($db->quoteName('a.location_type') . ' = ' . $db->quote($type));
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.ordering')) . ' '
            . $db->escape($this->getState('list.direction', 'ASC')),
        );

        return $query;
    }
}
