<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;

/**
 * List model for com_alfa users.
 *
 * Joins #__alfa_users with #__users to expose Joomla account fields
 * (username, email, lastvisitDate) alongside the component-specific columns.
 *
 * @since  1.0.1
 */
class UsersModel extends ListModel
{
    /**
     * Constructor.
     *
     * @param   array                     $config   Optional configuration array.
     * @param   MVCFactoryInterface|null  $factory  MVC factory.
     *
     * @since   1.0.1
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id',          'a.id',
                'note',        'a.note',
                'ordering',    'a.ordering',
                'created_by',  'a.created_by',
                'modified_by', 'a.modified_by',
                'username',    'u.username',
                'email',       'u.email',
                'lastvisitDate', 'u.lastvisitDate',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Auto-populate the model state.
     *
     * Note: calling getState() inside this method will cause recursion.
     *
     * @param   string  $ordering   Default ordering column.
     * @param   string  $direction  Default ordering direction.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    protected function populateState($ordering = 'a.id', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Returns a store ID based on the current model state.
     *
     * Ensures different filter/search combinations each get their own cache
     * entry so paginated lists stay consistent.
     *
     * @param   string  $id  A prefix for the store ID.
     *
     * @return  string
     *
     * @since   1.0.1
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.state');

        return parent::getStoreId($id);
    }

    /**
     * Builds the SQL query for the list.
     *
     * Selects all component columns (a.*) plus the Joomla user fields we need
     * for display (username, email, lastvisitDate). The INNER JOIN ensures
     * rows whose linked Joomla user has been hard-deleted are excluded.
     *
     * @return  \Joomla\Database\DatabaseQuery
     *
     * @since   1.0.1
     */
    protected function getListQuery()
{
    $db    = $this->getDatabase();
    $query = $db->getQuery(true);

    $query->select(
        $this->getState(
            'list.select',
            'a.*, u.name AS joomla_name, u.username, u.email, u.lastvisitDate'  // no DISTINCT
        )
    );

    $query->from($db->quoteName('#__alfa_users', 'a'));

    $query->join(
        'INNER',
        $db->quoteName('#__users', 'u') . ' ON u.id = a.id_user'
    );

    $search = $this->getState('filter.search');

    if (!empty($search)) {
        if (stripos($search, 'id:') === 0) {
            $query->where('a.id = ' . (int) substr($search, 3));
        } else {
            $search = $db->quote('%' . $db->escape($search, true) . '%');
            $query->where(
                '(' .
                    'u.username LIKE ' . $search .
                    ' OR u.email LIKE ' . $search .
                    ' OR a.note LIKE '  . $search .
                ')'
            );
        }
    }

    $orderCol  = $this->getState('list.ordering', 'a.id');
    $orderDirn = $this->getState('list.direction', 'ASC');
    $query->order($db->escape($orderCol . ' ' . $orderDirn));


// print_r($db->replacePrefix((string) $query));
// exit;
    return $query;
}

    /**
     * Returns the list of items, with any post-processing applied.
     *
     * @return  array|false
     *
     * @since   1.0.1
     */
    public function getItems()
    {
        return parent::getItems();
    }
}