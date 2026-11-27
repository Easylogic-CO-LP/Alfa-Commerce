<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Model\Traits\HasDateRangeFilterTrait;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\QueryInterface;

/**
 * The Background screen's Work tab: every task and job of the shop and its extensions, each in one of four states of
 * work (running now, waiting, needs attention, finished), with what staff read of it and may do with it
 * ({@see TaskStaff::describe()}). Filters: a search (an id, a handler, an order, a scope or an error), the state of
 * work, the kind of work (its handler) and when it was created.
 *
 * @since  2.3.0
 */
class TasksModel extends AlfaListModel
{
    use HasDateRangeFilterTrait;

    /**
     * The admin-contract entity of this screen.
     *
     * @since  2.3.0
     */
    protected string $alfaEntity = 'task';

    /**
     * The names of the users the listed histories name, id => name, read with the items.
     *
     * @var array<int, string>
     *
     * @since  2.3.0
     */
    private array $historyUsers = [];

    /**
     * Constructor.
     *
     * @param array<string, mixed> $config The model's configuration.
     * @param MVCFactoryInterface|null $factory The MVC factory.
     *
     * @since  2.3.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'a.id', 'rank', 'created_at', 'a.created_at', 'finished_at', 'a.finished_at'];
        }

        parent::__construct($config, $factory);
    }

    /**
     * The list's state: running first by default, then waiting, what needs attention and the finished, newest first
     * within each; the date window against the creation.
     *
     * @param string $ordering The default ordering.
     * @param string $direction The default direction.
     *
     * @since  2.3.0
     */
    protected function populateState($ordering = 'rank', $direction = 'ASC')
    {
        parent::populateState($ordering, $direction);

        // After the parent: the trait reads the registry the parent fills.
        $this->resolveDateRangeFilter();
    }

    /**
     * The rows of the page, each as staff read it.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public function getItems()
    {
        $rows = parent::getItems();

        if (!\is_array($rows)) {
            return [];
        }

        $described = TaskStaff::describe(rows: array_map(static fn (object $row): array => (array) $row, $rows));
        $this->historyUsers = $described['users'];

        return array_map(static fn (array $row): object => (object) $row, $described['rows']);
    }

    /**
     * The names of the users the page's histories name, id => name; read after getItems().
     *
     * @return array<int, string>
     *
     * @since  2.3.0
     */
    public function getHistoryUsers(): array
    {
        return $this->historyUsers;
    }

    /**
     * The list's query.
     *
     * @since  2.3.0
     */
    protected function getListQuery(): QueryInterface
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(array_map(static fn (string $column): string => 'a.' . $column, TaskStaff::COLUMNS)))
            ->select(TaskStaff::workSql(db: $db, alias: 'a') . ' AS ' . $db->quoteName('work'))
            ->select(TaskStaff::drivenSql(db: $db, alias: 'a') . ' AS ' . $db->quoteName('driven'))
            ->select(TaskStaff::checkpointSql(db: $db, alias: 'a') . ' AS ' . $db->quoteName('checkpoint'))
            ->from($db->quoteName(Tasks::TABLE, 'a'));

        $work = (string) $this->getState('filter.work', '');

        if ($work !== '' && \in_array($work, TaskStaff::WORK_STATES, true)) {
            $query->where(TaskStaff::workSql(db: $db, alias: 'a') . ' = :work')->bind(':work', $work);
        } elseif ($work !== '') {
            $query->where('1 = 0');
        }

        $kind = (string) $this->getState('filter.kind', '');

        if ($kind !== '') {
            $query->where($db->quoteName('a.handler') . ' = :kind')->bind(':kind', $kind);
        }

        // The creation is stored as UTC; the state holds the shop's local bounds.
        AlfaHelper::applyDateRange(
            query: $query,
            column: 'a.created_at',
            localStart: (string) $this->getState('filter.date_start', ''),
            localEnd: (string) $this->getState('filter.date_end', ''),
        );

        $search = trim((string) $this->getState('filter.search', ''));

        if ($search !== '') {
            if (preg_match('/^#?(\d+)$/', $search, $matches) === 1) {
                $query->where($db->quoteName('a.id') . ' = ' . (int) $matches[1]);
            } elseif (preg_match('/^order:(\d+)$/i', $search, $matches) === 1) {
                $query->where($db->quoteName('a.subject_type') . ' = ' . $db->quote('order'))
                    ->where($db->quoteName('a.subject_id') . ' = ' . (int) $matches[1]);
            } else {
                // One clause of its own: the search may be the only condition of the query.
                $like = $db->quote('%' . $db->escape($search, true) . '%');
                $query->where('(' . implode(' OR ', [
                    $db->quoteName('a.handler') . ' LIKE ' . $like,
                    $db->quoteName('a.scope_key') . ' LIKE ' . $like,
                    $db->quoteName('a.last_error') . ' LIKE ' . $like,
                    $db->quoteName('a.owner') . ' LIKE ' . $like,
                ]) . ')');
            }
        }

        $ordering = (string) $this->getState('list.ordering', 'rank');
        $direction = strtoupper((string) $this->getState('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        // The states in their order (the stored rank), the newest first within each: the id ends the order, so it is
        // total. The two directions differ, so neither engine reads this order from idx_rank_id (rank, id) and the rows
        // the filters keep are sorted for the page; the table is bounded by its retention (finished tasks kept
        // TaskRetention::SHORT_DAYS or TaskRetention::KEEP_DAYS, the rest waiting on the runner or on staff).
        if ($ordering === 'rank') {
            $query->order(TaskStaff::rankSql(db: $db, alias: 'a') . ' ' . $direction . ', ' . $db->quoteName('a.id') . ' DESC');
        } else {
            $query->order($db->quoteName($ordering) . ' ' . $direction);
            $this->orderByKeyLast($query, $ordering, $direction);
        }

        return $query;
    }
}
