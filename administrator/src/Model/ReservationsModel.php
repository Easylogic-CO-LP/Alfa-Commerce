<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;

/**
 * Stock reservations list model: read-only surface over the
 * reservation entities. PARTIAL is DERIVED at render (active +
 * partially fulfilled), never stored; the filter maps it onto the
 * quantity condition.
 *
 * @since  1.4.0
 */
class ReservationsModel extends AlfaListModel
{
    use HasDateRangeFilterTrait;

    /**
     * @var string The entity a plugin targets with $context->targets('reservation').
     * @since  1.8.0
     */
    protected string $alfaEntity = 'reservation';

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
                'state', 'a.state',
                'expires_at', 'a.expires_at',
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

        // Date window against a.created (UTC, written by InventoryService). Must run
        // AFTER the parent: the trait reads the registry the parent fills.
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
            ->from($db->quoteName('#__alfa_inventory_reservations', 'a'))
            ->join('LEFT', $db->quoteName('#__alfa_inventory_locations', 'l') . ' ON l.id = a.location_id')
            ->join('LEFT', $db->quoteName('#__alfa_items', 'i') . ' ON i.id = a.item_id');

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'i',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_items',
            langPrimaryColumn: 'id_item',
            fields:            ['name'],
        );

        // a.created is UTC; state holds SHOP-LOCAL bounds (what the calendars show).
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
            } elseif (str_starts_with($search, 'source:')) {
                $query->where($db->quoteName('a.source_id') . ' = ' . (int) substr($search, 7));
            } else {
                $like = '%' . $db->escape($search, true) . '%';
                $query->extendWhere('AND', [
                    $db->quoteName('i.sku') . ' LIKE ' . $db->quote($like),
                    $db->quoteName('a.source_type') . ' LIKE ' . $db->quote($like),
                    $db->quoteName('a.note') . ' LIKE ' . $db->quote($like),
                ], 'OR');
            }
        }

        $state = (string) $this->getState('filter.state', '');

        if ($state === 'partial') {
            // Derived display state: active with partial fulfilment.
            $query->where($db->quoteName('a.state') . ' = ' . $db->quote('active'))
                ->where($db->quoteName('a.fulfilled_quantity') . ' > 0');
        } elseif ($state !== '') {
            $query->where($db->quoteName('a.state') . ' = ' . $db->quote($state));
        }

        $query->order(
            $db->escape($this->getState('list.ordering', 'a.id')) . ' '
            . $db->escape($this->getState('list.direction', 'DESC')),
        );

        return $query;
    }
}
