<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Task\TaskAccess;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskDocument;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\User\User;
use Joomla\Database\QueryInterface;

/**
 * The tasks as the API lists them (GET v1/alfa/tasks), newest first, each in its document's fields
 * ({@see TaskDocument::fields()}). Filters: filter.state, filter.type (single or job) and filter.owner (every task of
 * this version is com_alfa's, so any other owner lists none).
 *
 * @since  2.2.0
 */
class TasksModel extends ListModel
{
    /**
     * The states a filter may name.
     *
     * @var list<string>
     *
     * @since  2.2.0
     */
    private const STATES = [
        Tasks::STATE_PENDING, Tasks::STATE_RUNNING, Tasks::STATE_DONE, Tasks::STATE_FAILED, Tasks::STATE_HELD,
        Tasks::STATE_RESOLVED, Tasks::STATE_CANCELLED,
    ];

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config The model's configuration.
     * @param MVCFactoryInterface|null $factory The MVC factory.
     *
     * @since  2.2.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id'];
        }

        parent::__construct($config, $factory);
    }

    /**
     * The tasks' rows, each made its document's fields.
     *
     * @return list<object>
     *
     * @since  2.2.0
     */
    public function getItems()
    {
        $rows = parent::getItems();
        // The list is Tools' (the API controller asks it first); the last error is shown to Tools only all the same.
        $withError = TaskAccess::canUseTools(user: Factory::getApplication()->getIdentity() ?? new User());

        return \is_array($rows) ? array_map(static fn (object $row): object => (object) TaskDocument::fields(row: (array) $row, withError: $withError), $rows) : [];
    }

    /**
     * The list's query: the tasks the filters keep, newest first.
     *
     * @since  2.2.0
     */
    protected function getListQuery(): QueryInterface
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(TaskDocument::COLUMNS))
            ->from($db->quoteName(Tasks::TABLE))
            ->order($db->quoteName('id') . ' DESC');
        $state = (string) $this->getState('filter.state', '');
        $type = (string) $this->getState('filter.type', '');
        $owner = (string) $this->getState('filter.owner', '');

        // A state no task can be in, a type that is neither, and an owner other than com_alfa each list none.
        if ($state !== '' && \in_array($state, self::STATES, true)) {
            $query->where($db->quoteName('state') . ' = :state')->bind(':state', $state);
        } elseif ($state !== '') {
            $query->where('1 = 0');
        }

        if ($type !== '' && \in_array($type, [Tasks::TYPE_SINGLE, Tasks::TYPE_JOB], true)) {
            $query->where($db->quoteName('type') . ' = :type')->bind(':type', $type);
        } elseif ($type !== '') {
            $query->where('1 = 0');
        }

        if ($owner !== '' && $owner !== TaskDocument::OWNER) {
            $query->where('1 = 0');
        }

        return $query;
    }
}
