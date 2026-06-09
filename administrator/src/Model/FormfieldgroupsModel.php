<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

class FormfieldgroupsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id',       'a.id',
                'title',    'a.title',
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
     * Build the cache store id, folding the search and state filters into the key.
     *
     * @param string $id An identifier string to prefix the store id.
     *
     * @return string A store id reflecting the active filters.
     * @since  1.0.0
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.state');
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

        // Count of fields assigned to each group — useful for delete confirmation UI.
        $query->select('(SELECT COUNT(*) FROM ' . $db->quoteName('#__alfa_form_fields')
            . ' WHERE group_id = a.id) AS field_count');

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
                $query->where('a.title LIKE ' . $search);
            }
        }

        $orderCol = $this->state->get('list.ordering', 'a.ordering');
        $orderDirn = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($orderCol . ' ' . $orderDirn));

        return $query;
    }
}
