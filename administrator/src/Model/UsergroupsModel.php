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

    /**
     * Populate the model state, defaulting the ordering to the core usergroup title.
     *
     * @param string $ordering The default ordering column.
     * @param string $direction The default ordering direction.
     *
     * @return void
     */
    protected function populateState($ordering = 'ug.title', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Build the list query, joining the Alfa usergroup settings rows to the core
     * #__usergroups table (on usergroup_id) and applying the id: / title LIKE search filter.
     *
     * @return \Joomla\Database\QueryInterface The query to list the usergroup settings.
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // Select the Alfa settings columns plus the core usergroup title.
        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.id, a.usergroup_id, a.prices_display, a.prices_enable, ug.title AS core_title',
            ),
        );
        $query->from($db->quoteName('#__alfa_usergroups', 'a'));

        // Join the core usergroups on usergroup_id (not a.id) for the title.
        $query->join('INNER', $db->quoteName('#__usergroups', 'ug') . ' ON ug.id = a.usergroup_id');

        // Search filter: id:<n> matches the row id, otherwise LIKE on the title.
        $search = $this->getState('filter.search');
        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');
                $query->where($db->quoteName('ug.title') . ' LIKE ' . $search);
            }
        }

        // Ordering.
        $orderCol = $this->state->get('list.ordering', 'ug.title');
        $orderDirn = $this->state->get('list.direction', 'ASC');

        $query->order($db->escape($orderCol . ' ' . $orderDirn));

        return $query;
    }
}
