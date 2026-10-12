<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;

/**
 * Inventory movements list model: the append-only ledger, read-only by
 * definition: corrections are opposite entries written by the service,
 * never edits here.
 *
 * @since  1.4.0
 */
class InventorymovementsModel extends AlfaListModel
{
    use HasDateRangeFilterTrait;

    /**
     * @var string The entity a plugin targets with $context->targets('inventorymovement').
     * @since  1.8.0
     */
    protected string $alfaEntity = 'inventorymovement';

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
                'created', 'a.created',
                'movement_type', 'a.movement_type',
                'location_id', 'a.location_id',
                'item_id', 'a.item_id',
            ];
        }

        parent::__construct($config);
    }

    /**
     * Populate list state (newest first).
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default direction.
     *
     * @since  1.4.0
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);

        // Date window against a.created (UTC). Must run AFTER the parent: the
        // trait reads the registry the parent fills.
        $this->resolveDateRangeFilter();
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
            ->select($db->quoteName('l.title', 'location_title'))
            ->select($db->quoteName('i.sku', 'item_sku'))
            ->from($db->quoteName('#__alfa_inventory_movements', 'a'))
            ->join('LEFT', $db->quoteName('#__alfa_inventory_locations', 'l') . ' ON l.id = a.location_id')
            ->join('LEFT', $db->quoteName('#__alfa_items', 'i') . ' ON i.id = a.item_id');

        // Item name in the active admin language.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'i',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            fields:            ['name'],
        );

        // Date window. State holds SHOP-LOCAL bounds (what the calendars show);
        // `created` is written by InventoryService as UTC, verified empirically
        // against the orders table, which shares the clock. The column's
        // current_timestamp() default would be server-local, but nothing ever hits
        // it: every writer passes an explicit UTC value.
        AlfaHelper::applyDateRange(
            query:      $query,
            column:     'a.created',
            localStart: (string) $this->getState('filter.date_start', ''),
            localEnd:   (string) $this->getState('filter.date_end', ''),
        );

        $search = trim((string) $this->getState('filter.search', ''));

        if ($search !== '') {
            if (str_starts_with($search, 'id:')) {
                $query->where($db->quoteName('a.item_id') . ' = ' . (int) substr($search, 3));
            } elseif (str_starts_with($search, 'op:')) {
                $query->where($db->quoteName('a.operation_id') . ' = ' . $db->quote(substr($search, 3)));
            } else {
                $like = '%' . $db->escape($search, true) . '%';
                $query->extendWhere('AND', [
                    $db->quoteName('i.sku') . ' LIKE ' . $db->quote($like),
                    $db->quoteName('a.note') . ' LIKE ' . $db->quote($like),
                    $db->quoteName('a.source_type') . ' LIKE ' . $db->quote($like),
                ], 'OR');
            }
        }

        $type = (string) $this->getState('filter.movement_type', '');

        if ($type !== '') {
            $query->where($db->quoteName('a.movement_type') . ' = ' . $db->quote($type));
        }

        $location = (int) $this->getState('filter.location_id', 0);

        if ($location > 0) {
            $query->where($db->quoteName('a.location_id') . ' = ' . $location);
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.id')) . ' '
            . $db->escape($this->getState('list.direction', 'DESC')),
        );

        return $query;
    }
}
