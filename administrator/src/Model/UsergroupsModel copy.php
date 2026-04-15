<?php

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

class UsergroupsModel extends ListModel
{
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'usergroup_id', 'a.usergroup_id',
                'prices_enable', 'a.prices_enable',
                'core_title', 'ug.title',
            ];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'ug.title', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // 1. Select the columns that ACTUALLY exist in your table now
        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.id, a.usergroup_id, a.prices_display, a.prices_enable, ug.title AS core_title',
            ),
        );
        $query->from($db->quoteName('#__alfa_usergroups', 'a'));

        // 2. JOIN using usergroup_id instead of a.id
        $query->join('INNER', $db->quoteName('#__usergroups', 'ug') . ' ON ug.id = a.usergroup_id');

        // 3. Filter by Search (Cleaned up - removed a.name)
        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                $query->where($db->quoteName('ug.title') . ' LIKE ' . $search);
            }
        }

        // 4. Ordering
        $orderCol = $this->state->get('list.ordering', 'ug.title');
        $orderDirn = $this->state->get('list.direction', 'ASC');

        $query->order($db->escape($orderCol . ' ' . $orderDirn));

        return $query;
    }
}
