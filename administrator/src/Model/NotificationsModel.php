<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\QueryInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * List model for backend notifications. The read side of the notification system:
 * the quick panel sets `filter.scope = active` (not-yet-dismissed), the history view
 * uses `filter.scope = history` (everything still retained) with the usual list
 * filters + pagination. Writes go through {@see NotificationHelper}.
 *
 * Programmatic use (e.g. the quick panel), mirroring ModelField:
 *   $model = $component->getMVCFactory()->createModel('Notifications', 'Administrator');
 *   $model->getState('list.ordering');          // force populateState()
 *   $model->setState('filter.scope', 'active');
 *   $model->setState('list.limit', 0);
 *   $items = $model->getItems();
 *
 * @since  1.0.0
 */
class NotificationsModel extends ListModel
{
    /**
     * Constructor.
     *
     * @param array $config Configuration array.
     * @param MVCFactoryInterface|null $factory The factory.
     *
     * @since  1.0.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'severity', 'a.severity',
                'notify_group', 'a.notify_group',
                'created', 'a.created',
                'readed', 'a.readed',
                'dismissed', 'a.dismissed',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Autopopulate the model state from the request.
     *
     * @param string $ordering Default ordering column.
     * @param string $direction Default ordering direction.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function populateState($ordering = 'a.created', $direction = 'DESC')
    {
        $app = Factory::getApplication();

        $this->setState('filter.scope', $app->getUserStateFromRequest($this->context . '.filter.scope', 'filter_scope', 'history', 'cmd'));
        $this->setState('filter.search', $app->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string'));
        $this->setState('filter.group', $app->getUserStateFromRequest($this->context . '.filter.group', 'filter_group', '', 'cmd'));
        $this->setState('filter.severity', $app->getUserStateFromRequest($this->context . '.filter.severity', 'filter_severity', '', 'cmd'));
        $this->setState('filter.read', $app->getUserStateFromRequest($this->context . '.filter.read', 'filter_read', '', 'cmd'));

        parent::populateState($ordering, $direction);
    }

    /**
     * Build the store id so different filter/scope combinations cache separately.
     *
     * @param string $id A prefix for the store id.
     *
     * @return string
     *
     * @since  1.0.0
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.scope');
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.group');
        $id .= ':' . $this->getState('filter.severity');
        $id .= ':' . $this->getState('filter.read');

        return parent::getStoreId($id);
    }

    /**
     * Build the list query.
     *
     * @return QueryInterface
     *
     * @since  1.0.0
     */
    protected function getListQuery()
    {
        // Drop expired history rows before reading (lazy cleanup — no cron).
        NotificationHelper::purge();

        $db = $this->getDatabase();
        $query = $db->createQuery();

        $query->select($this->getState('list.select', 'a.*'))
            ->from($db->quoteName('#__alfa_notifications', 'a'));

        // Scope: active = not dismissed; history = everything still retained.
        if ($this->getState('filter.scope', 'history') === 'active') {
            $query->where($db->quoteName('a.dismissed') . ' IS NULL');
        }

        $group = $this->getState('filter.group');

        if ($group !== '' && $group !== null) {
            $query->where($db->quoteName('a.notify_group') . ' = :grp')->bind(':grp', $group);
        }

        $severity = $this->getState('filter.severity');

        if ($severity !== '' && $severity !== null) {
            $query->where($db->quoteName('a.severity') . ' = :sev')->bind(':sev', $severity);
        }

        $read = $this->getState('filter.read');

        if ($read === 'read') {
            $query->where($db->quoteName('a.readed') . ' IS NOT NULL');
        } elseif ($read === 'unread') {
            $query->where($db->quoteName('a.readed') . ' IS NULL');
        }

        $search = $this->getState('filter.search');

        if (!empty($search)) {
            $like = '%' . str_replace(' ', '%', trim($search)) . '%';
            $query->where(
                '(' . $db->quoteName('a.title') . ' LIKE :s1 OR ' . $db->quoteName('a.message') . ' LIKE :s2)',
            )
                ->bind(':s1', $like)
                ->bind(':s2', $like);
        }

        // Ordering: an explicit column if the list asked for one, else worst-severity-first then newest.
        $orderCol = $this->state->get('list.ordering', '');
        $orderDir = $this->state->get('list.direction', 'DESC');

        if ($orderCol !== '') {
            $query->order($db->escape($orderCol) . ' ' . $db->escape($orderDir));
        } else {
            $query->order("FIELD(a.severity, 'danger', 'warning', 'info', 'success') ASC, a.created DESC");
        }

        return $query;
    }
}
