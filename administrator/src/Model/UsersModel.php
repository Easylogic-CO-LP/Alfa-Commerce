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

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;

/**
 * List model for com_alfa users.
 *
 * Joins #__alfa_users with #__users to expose Joomla account fields
 * (username, email, lastvisitDate) alongside the component-specific columns.
 *
 * @since  1.0.0
 */
class UsersModel extends AlfaListModel
{
    /**
     * The admin-contract entity this list serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'user';

    /**
     * Constructor.
     *
     * @param array $config Optional configuration array.
     * @param MVCFactoryInterface|null $factory MVC factory.
     *
     * @since  1.0.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id',          'a.id',
                'note',        'a.note',
                // No ordering/created_by/modified_by here: #__alfa_users has
                // no such columns; whitelisting them lets list.ordering
                // produce broken SQL (the API validates against this list).
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
     * @param string $ordering Default ordering column.
     * @param string $direction Default ordering direction.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function populateState($ordering = 'a.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Returns a store ID based on the current model state.
     *
     * Ensures different filter/search combinations each get their own cache
     * entry so paginated lists stay consistent.
     *
     * @param string $id A prefix for the store ID.
     *
     * @return string
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
     * Builds the SQL query for the list.
     *
     * Selects all component columns (a.*) plus the Joomla user fields we need
     * for display (username, email, lastvisitDate). The INNER JOIN ensures
     * rows whose linked Joomla user has been hard-deleted are excluded.
     *
     * @return \Joomla\Database\DatabaseQuery
     *
     * @since  1.0.0
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select(
            $this->getState(
                'list.select',
                'a.*, u.name AS joomla_name, u.username, u.email, u.lastvisitDate',  // no DISTINCT
            ),
        );

        $query->from($db->quoteName('#__alfa_users', 'a'));

        $query->join(
            'INNER',
            $db->quoteName('#__users', 'u') . ' ON u.id = a.id_user',
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
                        ' OR a.note LIKE ' . $search .
                    ')',
                );
            }
        }

        $orderCol = $this->getState('list.ordering', 'a.id');
        $orderDirn = $this->getState('list.direction', 'ASC');
        $query->order($db->quoteName($orderCol) . ' ' . (strtoupper((string) $orderDirn) === 'DESC' ? 'DESC' : 'ASC'));

        return $query;
    }

    /**
     * Returns the list of items, with any post-processing applied.
     *
     * @return array|false
     *
     * @since  1.0.0
     */
    public function getItems()
    {
        return parent::getItems();
    }
}
