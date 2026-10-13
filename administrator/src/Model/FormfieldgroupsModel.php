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
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;

class FormfieldgroupsModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'formfieldgroup';

    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id',       'a.id',
                'title',
                'ordering', 'a.ordering',
                'state',    'a.state',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Populate the model state, defaulting the ordering to the group ordering column.
     *
     * @param string $ordering The default ordering column.
     * @param string $direction The default ordering direction.
     *
     * @return void
     * @since  1.0.0
     */
    protected function populateState($ordering = 'a.ordering', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Build the cache store id, folding every active filter into the key.
     *
     * @param string $id An identifier string to prefix the store id.
     *
     * @return string A store id reflecting the active filters.
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
     * Build the list query for form-field groups, adding a per-group field_count
     * subquery and applying the state and search (id: / title LIKE) filters.
     *
     * @return \Joomla\Database\QueryInterface The query to list the groups.
     * @since  1.0.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select($this->getState('list.select', 'a.*'))
            ->from($db->quoteName('#__alfa_form_field_groups', 'a'));

        // Count of fields assigned to each group: useful for delete confirmation UI.
        $query->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_form_fields')
            . ' WHERE group_id = a.id) AS field_count');

        // MULTILINGUAL: resolve title / description in the active language from the
        // per-language tables (LEFT JOIN + COALESCE; translatable cols no longer
        // live in the main table).
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_form_field_groups',
            langPrimaryColumn: 'id_form_field_group',
            fields:            ['title', 'description'],
        );

        $published = $this->getState('filter.state');
        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where('a.state IN (0, 1)');
        }

        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->quote('%' . $db->escape($search, true) . '%');
                // HAVING: `title` is the COALESCE alias from the lang join, not a
                // real column at WHERE time.
                $query->having('(' . $db->quoteName('title') . ' LIKE ' . $search . ')');
            }
        }

        $orderCol = $this->state->get('list.ordering', 'a.ordering');
        $orderDirn = $this->state->get('list.direction', 'ASC');
        $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));

        return $query;
    }
}
