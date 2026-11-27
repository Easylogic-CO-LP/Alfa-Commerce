<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\ErrorText;
use Alfa\Component\Alfa\Administrator\Helper\MysqlError;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use Alfa\Component\Alfa\Administrator\Service\Media\ThumbnailRecreation;
use DomainException;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * What staff read of the shop's background work on the Background screen and the Dashboard, and what they do with a
 * failed or held single or a failed job, each act audited in its history.
 *
 * Nothing failed or held is ever run again automatically: staff decide. Resend (an e-mail) and Run again (a plugin's
 * reaction) write a new single that names the original (resend_of, rerun_of) and carries every part recorded done in
 * the chain; it runs at once, its handler attempts only the parts not recorded as done, and the original becomes
 * resolved in the same transaction. Mark resolved
 * closes a task with no action, with the reason given. Retry puts a failed job back in the queue with its failures
 * reset. Cancel stops an extension's work that has not finished (core's correctness work is never cancelled). A refusal is a DomainException whose code is one of the REFUSED_
 * constants and whose message is its language key.
 *
 * @since  2.1.4
 */
final class TaskStaff
{
    /**
     * Refusal: the task is no longer failed or held (someone handled it, or it is gone).
     *
     * @since  2.1.4
     */
    public const REFUSED_GONE = 1;

    /**
     * Refusal: its handler offers no such action.
     *
     * @since  2.1.4
     */
    public const REFUSED_NOT_OFFERED = 2;

    /**
     * Refusal: Mark resolved was asked with no reason.
     *
     * @since  2.1.4
     */
    public const REFUSED_NO_REASON = 3;

    /**
     * Refusal: another job of the same work is already waiting or running.
     *
     * @since  2.2.0
     */
    public const REFUSED_ACTIVE = 4;

    /**
     * The longest reason kept.
     *
     * @since  2.1.4
     */
    public const REASON_MAX = 500;

    /**
     * How recently a waiting job must have advanced to read as running: the staff kick and the progress page drive a job
     * in steps a few seconds apart, and between two of them its row waits.
     *
     * @since  2.2.0
     */
    public const DRIVEN_SECONDS = 30;

    /**
     * The state of work of a row that runs now: a running task or job, or a waiting job being driven between two of
     * its steps.
     *
     * @since  2.3.0
     */
    public const WORK_RUNNING = 'running';

    /**
     * The state of work of a row that waits for its turn.
     *
     * @since  2.3.0
     */
    public const WORK_WAITING = 'waiting';

    /**
     * The state of work of a row that stopped and waits for staff: a failed task or job, or a task whose outcome is
     * unknown.
     *
     * @since  2.3.0
     */
    public const WORK_ATTENTION = 'attention';

    /**
     * The state of work of a row that ended: done, marked resolved or cancelled.
     *
     * @since  2.3.0
     */
    public const WORK_FINISHED = 'finished';

    /**
     * The four states of work, in the order the Background screen ranks them.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const WORK_STATES = [self::WORK_RUNNING, self::WORK_WAITING, self::WORK_ATTENTION, self::WORK_FINISHED];

    /**
     * The task states of each state of work. A running job and a waiting one being driven both run
     * ({@see self::drivenSql()}).
     *
     * @var array<string, list<string>>
     *
     * @since  2.3.0
     */
    public const WORK_TASK_STATES = [
        self::WORK_RUNNING => [Tasks::STATE_RUNNING],
        self::WORK_WAITING => [Tasks::STATE_PENDING],
        self::WORK_ATTENTION => [Tasks::STATE_FAILED, Tasks::STATE_HELD],
        self::WORK_FINISHED => [Tasks::STATE_DONE, Tasks::STATE_RESOLVED, Tasks::STATE_CANCELLED],
    ];

    /**
     * The columns the rows of the Background screen and of the Dashboard's card are read with, besides the state of
     * work and whether the row is driven ({@see self::describe()}).
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const COLUMNS = [
        'id', 'type', 'owner', 'handler', 'state', 'subject_type', 'subject_id', 'scope_key', 'done_units', 'total_units',
        'failures', 'claims', 'last_error', 'last_trigger', 'run_after', 'created_at', 'started_at', 'finished_at',
        'result', 'cancel_requested', 'history',
    ];

    /**
     * SQL that says whether a row is driven: running, or a waiting job that started, is due and advanced within
     * DRIVEN_SECONDS (a step's end writes updated_at; a failed step sets its retry in the future, so a job waiting out
     * its retry is not driven). The staff kick and the progress page drive a job in steps a few seconds apart, and
     * between two of them its row waits.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $alias The tasks table's alias in the query, '' for none.
     *
     * @since  2.3.0
     */
    public static function drivenSql(DatabaseDriver $db, string $alias = ''): string
    {
        $column = static fn (string $name): string => $db->quoteName($alias === '' ? $name : $alias . '.' . $name);

        return '(' . $column('state') . ' = ' . $db->quote(Tasks::STATE_RUNNING) . ' OR (' . $column('type') . ' = ' . $db->quote(Tasks::TYPE_JOB)
            . ' AND ' . $column('state') . ' = ' . $db->quote(Tasks::STATE_PENDING) . ' AND ' . $column('started_at') . ' IS NOT NULL AND '
            . $column('run_after') . ' <= UTC_TIMESTAMP(3) AND ' . $column('updated_at') . ' >= UTC_TIMESTAMP(3) - INTERVAL ' . self::DRIVEN_SECONDS . ' SECOND))';
    }

    /**
     * SQL that names a row's state of work ({@see self::WORK_STATES}): running (a running task or job, or a waiting
     * job being driven), waiting, attention (failed or held) or finished (done, resolved or cancelled).
     *
     * @param DatabaseDriver $db The connection.
     * @param string $alias The tasks table's alias in the query, '' for none.
     *
     * @since  2.3.0
     */
    public static function workSql(DatabaseDriver $db, string $alias = ''): string
    {
        $state = $db->quoteName($alias === '' ? 'state' : $alias . '.state');
        $attention = implode(', ', array_map([$db, 'quote'], self::WORK_TASK_STATES[self::WORK_ATTENTION]));

        return 'CASE WHEN ' . self::drivenSql(db: $db, alias: $alias) . ' THEN ' . $db->quote(self::WORK_RUNNING)
            . ' WHEN ' . $state . ' = ' . $db->quote(Tasks::STATE_PENDING) . ' THEN ' . $db->quote(self::WORK_WAITING)
            . ' WHEN ' . $state . ' IN (' . $attention . ') THEN ' . $db->quote(self::WORK_ATTENTION)
            . ' ELSE ' . $db->quote(self::WORK_FINISHED) . ' END';
    }

    /**
     * The column that ranks a row for the Background screen's order: the stored rank of its state (running 0, waiting 1,
     * needs attention 2, finished 3), indexed with the id, so the queue's order (rank, then the oldest first) reads the
     * index instead of sorting the table. A waiting job being driven shows as running but ranks with the waiting.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $alias The tasks table's alias in the query, '' for none.
     *
     * @since  2.3.0
     */
    public static function rankSql(DatabaseDriver $db, string $alias = ''): string
    {
        return $db->quoteName($alias === '' ? 'rank' : $alias . '.rank');
    }

    /**
     * How many rows are in each state of work.
     *
     * @return array<string, int> By state of work ({@see self::WORK_STATES}).
     *
     * @since  2.3.0
     */
    public static function workCounts(): array
    {
        $db = self::db();
        $counts = array_fill_keys(self::WORK_STATES, 0);

        foreach ($db->setQuery(
            'SELECT ' . self::workSql(db: $db) . ' AS ' . $db->quoteName('work') . ', COUNT(*) AS ' . $db->quoteName('n')
            . ' FROM ' . $db->quoteName(Tasks::TABLE) . ' GROUP BY ' . $db->quoteName('work'),
        )->loadAssocList() ?: [] as $count) {
            $counts[(string) $count['work']] = (int) $count['n'];
        }

        return $counts;
    }

    /**
     * The rows of the Background screen and of the Dashboard's card, each told for staff: its name, its extension,
     * what it covers, why it waits, what it reported, its history and what staff may do with it.
     *
     * @param list<array<string, mixed>> $rows Rows read with {@see self::COLUMNS}, the state of work as work
     *                                         ({@see self::workSql()}), whether the row is driven as driven
     *                                         ({@see self::drivenSql()}), and the checkpoint of the handlers that
     *                                         keep their report in it ({@see self::CHECKPOINT_HANDLERS}).
     *
     * @return array{rows: list<array<string, mixed>>, users: array<int, string>} The rows, each with label (a
     *                                        language key, '' for a handler this version does not have), labelText,
     *                                        extension (its name, '' for core), covers (plain text, '' for a task),
     *                                        work, driven, waits (why an extension's job waits, '' when it does not),
     *                                        resultLines, reportLines, percent, history, units (unit => its last
     *                                        recorded outcome), action (resend, rerun or ''), cancellable and
     *                                        progressText; and the names of the users the histories name.
     *
     * @since  2.3.0
     */
    public static function describe(array $rows): array
    {
        $db = self::db();
        $priceSet = Job\PriceSetPrepareJob::NAME . ':';
        $search = Job\SearchRebuildJob::NAME . ':';
        $contextIds = [];
        $userIds = [];

        foreach ($rows as &$row) {
            $owner = (string) $row['owner'];
            $isJob = (string) $row['type'] === Tasks::TYPE_JOB;
            WorkDeclarations::loadLanguage(owner: $owner);
            $policy = $isJob ? Runner::handler(name: (string) $row['handler'], owner: $owner)?->policy() : Tasks::handler(name: (string) $row['handler'], owner: $owner)?->policy();
            $row['label'] = $policy->label ?? '';
            $row['labelText'] = $row['label'] !== '' ? Text::_($row['label']) : Text::sprintf('COM_ALFA_TASKS_HANDLER_UNKNOWN', (string) $row['handler']);
            $row['extension'] = self::extensionName(owner: $owner);
            $row['driven'] = (bool) ($row['driven'] ?? false);
            $row['work'] = (string) ($row['work'] ?? self::work(row: $row));
            $row['waits'] = $owner === DueRead::CORE_OWNER || !$isJob || $row['state'] !== Tasks::STATE_PENDING ? '' : self::waitReason(row: $row);
            $row['resultLines'] = $owner === DueRead::CORE_OWNER ? [] : self::resultLines(result: (string) ($row['result'] ?? ''));
            $row['cancellable'] = \in_array($row['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true) && (int) $row['cancel_requested'] === 0 && WorkCancel::cancellable(row: $row);
            $row['percent'] = TaskDocument::percent(row: $row);
            $row['action'] = $policy instanceof TaskPolicy && \in_array($row['state'], [Tasks::STATE_FAILED, Tasks::STATE_HELD], true) ? $policy->staffAction : '';
            $row['history'] = $isJob ? [] : Tasks::decodeHistory($row['history']);
            $row['units'] = [];
            $row['report'] = self::report(row: $row);
            $row['reportLines'] = self::reportLines(row: $row);
            $row['progressText'] = $isJob ? self::progressText(row: $row) : '';
            $row['covers'] = '';

            foreach ($row['history'] as $entry) {
                if (isset($entry['unit'])) {
                    $row['units'][(string) $entry['unit']] = (string) ($entry['outcome'] ?? '');
                }

                if ((int) ($entry['user'] ?? 0) > 0) {
                    $userIds[(int) $entry['user']] = true;
                }
            }

            if ($isJob && $owner === DueRead::CORE_OWNER && str_starts_with((string) $row['scope_key'], $priceSet)) {
                $contextIds[] = (int) substr((string) $row['scope_key'], \strlen($priceSet));
            }

            unset($row['result'], $row['checkpoint']);
        }

        unset($row);

        // A preparation job's scope names its pricing context: the groups shown are the context's set's.
        $groups = self::groupsOfContexts(db: $db, contextIds: array_values(array_unique(array_filter($contextIds))));

        foreach ($rows as &$row) {
            if ((string) $row['type'] !== Tasks::TYPE_JOB) {
                continue;
            }

            $scope = (string) $row['scope_key'];
            $row['covers'] = match (true) {
                (string) $row['owner'] !== DueRead::CORE_OWNER => self::extensionCovers(row: $row),
                str_starts_with($scope, $priceSet) => self::groupsCovers(groups: $groups[(int) substr($scope, \strlen($priceSet))] ?? []),
                str_starts_with($scope, $search) => Text::sprintf('COM_ALFA_TASKS_COVERS_SEARCH', Job\SearchRebuildJob::engineName(key: substr($scope, \strlen($search)))),
                $scope === Job\ThumbnailsRecreateJob::NAME => Text::_((($row['report']['mode'] ?? ThumbnailRecreation::mode()) === ThumbnailRecreation::MODE_ALL)
                    ? 'COM_ALFA_TASKS_COVERS_THUMBNAILS_ALL' : 'COM_ALFA_TASKS_COVERS_THUMBNAILS_MISSING'),
                str_starts_with($scope, Job\StockRepairJob::NAME . ':section:') => Text::sprintf('COM_ALFA_TASKS_COVERS_STOCK_REPAIR_SECTION', Text::_('COM_ALFA_INVENTORY_AUDIT_CATEGORY_' . strtoupper(substr($scope, \strlen(Job\StockRepairJob::NAME . ':section:'))))),
                str_starts_with($scope, Job\StockRepairJob::NAME . ':') => Text::_('COM_ALFA_TASKS_COVERS_STOCK_REPAIR'),
                default => Text::_('COM_ALFA_TASKS_COVERS_SHOP'),
            };
        }

        unset($row);

        $users = [];

        if ($userIds !== []) {
            $users = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'name']))
                    ->from($db->quoteName('#__users'))
                    ->whereIn($db->quoteName('id'), array_keys($userIds)),
            )->loadAssocList('id', 'name') ?: [];
        }

        return ['rows' => $rows, 'users' => $users];
    }

    /**
     * The jobs running or waiting, running first, as the Dashboard's background-work card lists them.
     *
     * @param int $limit The most rows.
     *
     * @return list<array<string, mixed>> Rows as {@see self::describe()} tells them.
     *
     * @since  2.3.0
     */
    public static function activeJobs(int $limit): array
    {
        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(self::COLUMNS))
                ->select(self::workSql(db: $db) . ' AS ' . $db->quoteName('work'))
                ->select(self::drivenSql(db: $db) . ' AS ' . $db->quoteName('driven'))
                ->select(self::checkpointSql(db: $db) . ' AS ' . $db->quoteName('checkpoint'))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                ->whereIn($db->quoteName('state'), [Tasks::STATE_RUNNING, Tasks::STATE_PENDING], ParameterType::STRING)
                ->order(self::rankSql(db: $db) . ' ASC, ' . $db->quoteName('id') . ' ASC'),
            0,
            max(1, $limit),
        )->loadAssocList() ?: [];

        return self::describe(rows: $rows)['rows'];
    }

    /**
     * The handlers whose report lives in their checkpoint while they run and after they end, read with the rows.
     *
     * @var list<string>
     *
     * @since  2.3.0
     */
    public const CHECKPOINT_HANDLERS = [Job\ThumbnailsRecreateJob::NAME, Job\StockRepairJob::NAME];

    /**
     * SQL that reads the checkpoint of the handlers that keep their report in it, and NULL for every other row, so a
     * large checkpoint never travels with a list.
     *
     * @param DatabaseDriver $db The connection.
     * @param string $alias The tasks table's alias in the query, '' for none.
     *
     * @since  2.3.0
     */
    public static function checkpointSql(DatabaseDriver $db, string $alias = ''): string
    {
        $column = static fn (string $name): string => $db->quoteName($alias === '' ? $name : $alias . '.' . $name);

        return 'CASE WHEN ' . $column('handler') . ' IN (' . implode(', ', array_map([$db, 'quote'], self::CHECKPOINT_HANDLERS)) . ') THEN ' . $column('checkpoint') . ' END';
    }

    /**
     * The state of work of a row, from its state and whether it is driven.
     *
     * @param array<string, mixed> $row The row.
     *
     * @since  2.3.0
     */
    public static function work(array $row): string
    {
        if (!empty($row['driven'])) {
            return self::WORK_RUNNING;
        }

        foreach (self::WORK_TASK_STATES as $work => $states) {
            if (\in_array((string) $row['state'], $states, true)) {
                return $work;
            }
        }

        return self::WORK_FINISHED;
    }

    /**
     * A job's progress in words for a list: "640 of 1500 (43%)"; "Not counted yet" for a total not counted while it
     * waits or runs; nothing once it ended with no total (its state says how).
     *
     * @param array<string, mixed> $row The row.
     *
     * @since  2.3.0
     */
    private static function progressText(array $row): string
    {
        if ((int) $row['total_units'] > 0) {
            return self::unitsText(done: (int) $row['done_units'], total: (int) $row['total_units'], percent: TaskDocument::percent(row: $row));
        }

        return \in_array($row['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true) ? Text::_('COM_ALFA_TASKS_PROGRESS_NOT_COUNTED') : '';
    }

    /**
     * What a job of core reports, read from its checkpoint or its result: the thumbnails job's report, the stock
     * repair's counts and the product it stopped at; null for a row with none.
     *
     * @param array<string, mixed> $row The row.
     *
     * @return array<string, mixed>|null
     *
     * @since  2.3.0
     */
    private static function report(array $row): ?array
    {
        if ((string) $row['owner'] !== DueRead::CORE_OWNER) {
            return null;
        }

        return match ((string) $row['handler']) {
            Job\ThumbnailsRecreateJob::NAME => ThumbnailRecreation::reportOf(checkpoint: $row['checkpoint'] ?? null),
            Job\StockRepairJob::NAME => Job\StockRepairJob::reportOf(row: $row),
            default => null,
        };
    }

    /**
     * A job's report as plain sentences for staff: the thumbnails job's counts, the image files missing and the
     * thumbnails not made with their reason, named by their media row; the stock repair's products repaired, figures
     * corrected and units offered for sale again, and the product it stopped at with why.
     *
     * @param array<string, mixed> $row The row, its report read ({@see self::report()}).
     *
     * @return list<string>
     *
     * @since  2.3.0
     */
    private static function reportLines(array $row): array
    {
        $report = $row['report'] ?? null;

        if (!\is_array($report)) {
            return [];
        }

        if ((string) $row['handler'] === Job\StockRepairJob::NAME) {
            $lines = [Text::sprintf('COM_ALFA_TASKS_STOCK_REPAIR_REPORT', (int) $report['repaired'], (int) $report['checked'], (int) $report['total'], (int) $report['corrected'], (int) $report['released'])];

            if (\is_array($report['refused'])) {
                $lines[] = Text::sprintf(
                    'COM_ALFA_TASKS_STOCK_REPAIR_STOPPED',
                    (int) $report['refused']['item'],
                    $report['refused']['gone'] ? Text::_('COM_ALFA_INVENTORY_AUDIT_REASON_ITEM_GONE') : (string) $report['refused']['reason'],
                );
            }

            return $lines;
        }

        if ((string) $row['handler'] !== Job\ThumbnailsRecreateJob::NAME) {
            return [];
        }

        $named = static fn (array $ids, array $reasons = []): string => implode(', ', array_map(
            static fn ($id): string => '#' . (int) $id . (isset($reasons[(string) $id]) ? ' (' . Text::_('COM_ALFA_TASKS_THUMBNAILS_REASON_' . strtoupper((string) $reasons[(string) $id])) . ')' : ''),
            $ids,
        ));
        $lines = [Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_REPORT', (int) $report['made'], (int) $report['kept'], (int) $report['missing'], (int) $report['failed'])];

        if ($report['missingIds'] !== []) {
            $lines[] = Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_MISSING_ROWS', $named($report['missingIds']));
        }

        if ($report['failedIds'] !== []) {
            $lines[] = Text::sprintf('COM_ALFA_TASKS_THUMBNAILS_FAILED_ROWS', $named($report['failedIds'], (array) $report['failedReasons']));
        }

        // Of the made thumbnails: those the box rule wrote again, and those whose stored image is smaller than the
        // thumbnail size, named by their media row.
        if ((int) $report['guarded'] > 0) {
            $lines[] = Text::plural('COM_ALFA_TASKS_THUMBNAILS_GUARDED', (int) $report['guarded']);
        }

        if ((int) $report['small'] > 0) {
            $lines[] = Text::plural('COM_ALFA_TASKS_THUMBNAILS_SMALL', (int) $report['small'], $named($report['smallIds']));
        }

        return $lines;
    }

    /**
     * The customer groups the price sets of pricing contexts cover, by context.
     *
     * @param DatabaseDriver $db The connection.
     * @param list<int> $contextIds The contexts.
     *
     * @return array<int, array<int, string>> By context: group id => title.
     *
     * @since  2.3.0
     */
    private static function groupsOfContexts(DatabaseDriver $db, array $contextIds): array
    {
        if ($contextIds === []) {
            return [];
        }

        $lists = $db->setQuery(
            'SELECT c.id, s.group_list FROM ' . $db->quoteName('#__alfa_price_contexts', 'c')
            . ' INNER JOIN ' . $db->quoteName('#__alfa_price_group_sets', 's') . ' ON s.id = c.group_set_id'
            . ' WHERE c.id IN (' . implode(',', $contextIds) . ')',
        )->loadAssocList('id', 'group_list') ?: [];
        $ids = [];

        foreach ($lists as $list) {
            foreach (array_filter(array_map('intval', explode(',', (string) $list))) as $id) {
                $ids[$id] = true;
            }
        }

        $titles = $ids === [] ? [] : ($db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'title']))
                ->from($db->quoteName('#__usergroups'))
                ->whereIn($db->quoteName('id'), array_keys($ids)),
        )->loadAssocList('id', 'title') ?: []);
        $groups = [];

        foreach ($lists as $contextId => $list) {
            $groups[(int) $contextId] = [];

            foreach (array_filter(array_map('intval', explode(',', (string) $list))) as $id) {
                $groups[(int) $contextId][$id] = (string) ($titles[$id] ?? ('#' . $id));
            }
        }

        return $groups;
    }

    /**
     * What a price set's job covers: its customer groups by name, or the customers in no group.
     *
     * @param array<int, string> $groups The groups, id => title.
     *
     * @since  2.3.0
     */
    private static function groupsCovers(array $groups): string
    {
        return $groups === [] ? Text::_('COM_ALFA_TASKS_COVERS_NO_GROUP') : Text::sprintf('COM_ALFA_TASKS_COVERS_GROUPS', implode(' + ', $groups));
    }

    /**
     * Delete finished entries: the rows named that are done, resolved or cancelled. A row in any other state is kept
     * and counted.
     *
     * @param list<int> $ids The rows.
     *
     * @return array{deleted: int, kept: int}
     *
     * @since  2.3.0
     */
    public static function deleteFinished(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return ['deleted' => 0, 'kept' => 0];
        }

        $db = self::db();
        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(Tasks::TABLE))
                ->whereIn($db->quoteName('id'), $ids)
                ->whereIn($db->quoteName('state'), self::WORK_TASK_STATES[self::WORK_FINISHED], ParameterType::STRING),
        )->execute();
        $deleted = $db->getAffectedRows();

        return ['deleted' => $deleted, 'kept' => \count($ids) - $deleted];
    }

    /**
     * How many singles are failed or held.
     *
     * @since  2.1.4
     */
    public static function attentionTotal(): int
    {
        $db = self::db();

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE))
                ->whereIn($db->quoteName('state'), [Tasks::STATE_FAILED, Tasks::STATE_HELD], ParameterType::STRING),
        )->loadResult();
    }

    /**
     * The name of an extension that owns work, as Extensions: Plugins shows it; '' for core's own work.
     *
     * @param string $owner The owner.
     *
     * @since  2.3.0
     */
    private static function extensionName(string $owner): string
    {
        return $owner === DueRead::CORE_OWNER ? '' : AlfaPluginHelper::get()->name(PluginId::of($owner));
    }

    /**
     * What an extension's job covers: its declaration's Covers text with the scope, or the scope as given, or the
     * whole shop for a job with no scope.
     *
     * @param array<string, mixed> $row The job's row.
     *
     * @since  2.3.0
     */
    private static function extensionCovers(array $row): string
    {
        $prefix = (string) $row['handler'] . ':';
        $scope = str_starts_with((string) $row['scope_key'], $prefix) ? substr((string) $row['scope_key'], \strlen($prefix)) : '';
        $covers = (string) (WorkDeclarations::find(owner: (string) $row['owner'], name: (string) $row['handler'])['covers'] ?? '');

        if ($scope === '') {
            return Text::_('COM_ALFA_TASKS_COVERS_SHOP');
        }

        return $covers === '' ? $scope : Text::sprintf($covers, $scope);
    }

    /**
     * Why an extension's job waits when its owner cannot run it: disabled, or no longer declaring the work; '' when it
     * waits for its turn only.
     *
     * @param array<string, mixed> $row The job's row.
     *
     * @since  2.3.0
     */
    private static function waitReason(array $row): string
    {
        $declaration = WorkDeclarations::find(owner: (string) $row['owner'], name: (string) $row['handler']);

        return match (true) {
            $declaration === null => Text::_('COM_ALFA_TASKS_WAITS_UNDECLARED'),
            (int) $declaration['enabled'] !== 1 => Text::_('COM_ALFA_TASKS_WAITS_DISABLED'),
            default => '',
        };
    }

    /**
     * An extension's result as plain key: value lines: scalars as they are, anything nested as JSON shortened to 200
     * characters.
     *
     * @param string $result The stored result.
     *
     * @return list<string>
     *
     * @since  2.3.0
     */
    private static function resultLines(string $result): array
    {
        $decoded = json_decode($result, true);
        $lines = [];

        foreach (\is_array($decoded) ? $decoded : [] as $key => $value) {
            $text = match (true) {
                \is_string($value) => $value,
                \is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                \is_scalar($value) => (string) $value,
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };
            $lines[] = $key . ': ' . (mb_strlen($text) > 200 ? mb_substr($text, 0, 197) . '...' : $text);
        }

        return $lines;
    }

    /**
     * Cancel an extension's waiting or running job, or a waiting task, with the reason given: a waiting one at once, a
     * running job once its step has ended.
     *
     * @param int $id The task or job.
     * @param int $userId Who cancels it.
     * @param string $reason Why.
     *
     * @throws DomainException When no reason is given, or it cannot be cancelled (finished, running task, core's
     *                         correctness work); the message is a language key.
     *
     * @since  2.3.0
     */
    public static function cancel(int $id, int $userId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, self::REASON_MAX);

        if ($reason === '') {
            throw new DomainException('COM_ALFA_TASKS_REASON_REQUIRED', self::REFUSED_NO_REASON);
        }

        if (!WorkCancel::cancel(id: $id, owner: null, trigger: 'staff', userId: $userId, reason: $reason)) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        Tasks::log(entry: 'Task #' . $id . ' was cancelled by user ' . $userId . ': ' . $reason, priority: Log::INFO);
    }

    /**
     * A job's progress in words for its progress card: "640 of 1500 (43%)", or nothing when its total is 0, where the
     * card says the state alone, as the jobs list does.
     *
     * @param int $done The units done.
     * @param int $total The units in all.
     * @param int $percent Its percent.
     *
     * @since  2.2.0
     */
    public static function unitsText(int $done, int $total, int $percent): string
    {
        return $total > 0 ? Text::sprintf('COM_ALFA_TASKS_PROGRESS_UNITS', $done, $total, $percent) : '';
    }

    /**
     * One job as the progress page shows it, or null when there is no job of that id.
     *
     * @param int $id The job.
     *
     * @return array{id: int, label: string, heavy: bool, state: string, done: int, total: int, percent: int, error: string,
     *               started_at: ?string, finished_at: ?string, reportLines: list<string>}|null
     *
     * @since  2.2.0
     */
    public static function job(int $id): ?array
    {
        $row = TaskDocument::row(id: $id);

        if ($row === null || $row['type'] !== Tasks::TYPE_JOB) {
            return null;
        }

        $row['report'] = self::report(row: $row);

        return [
            'id' => (int) $row['id'],
            'label' => Runner::handler(name: (string) $row['handler'], owner: (string) $row['owner'])?->policy()->label ?? '',
            'heavy' => Runner::handler(name: (string) $row['handler'], owner: (string) $row['owner'])?->policy()->weight === JobPolicy::HEAVY,
            'state' => (string) $row['state'],
            'done' => (int) $row['done_units'],
            'total' => (int) $row['total_units'],
            'percent' => TaskDocument::percent(row: $row),
            'error' => (string) $row['last_error'],
            'started_at' => $row['started_at'],
            'finished_at' => $row['finished_at'],
            // What the job reports, so far or at its end, as plain sentences.
            'reportLines' => self::reportLines(row: $row),
        ];
    }

    /**
     * Process now on a job waiting out its backoff: due now, as staff asked. A job that is not waiting, or is
     * due already, is left as it is.
     *
     * @param int $id The job.
     *
     * @since  2.2.0
     */
    public static function dueNow(int $id): void
    {
        $db = self::db();

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('run_after') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_PENDING))
                    ->where($db->quoteName('run_after') . ' > UTC_TIMESTAMP(3)')
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();
        } catch (Throwable $e) {
            Tasks::log(entry: 'Process now: job #' . $id . ' could not be made due now: ' . $e::class . ': ' . ErrorText::of($e), priority: Log::WARNING);
        }
    }

    /**
     * Put a failed job back in the queue: waiting, due now, its failures reset. The next tick works it from its
     * checkpoint.
     *
     * @param int $id The job.
     * @param int $userId Who asks.
     *
     * @throws DomainException When it is no longer a failed job, or another job of the same work is active (the
     *                         message is a language key).
     *
     * @since  2.2.0
     */
    public static function retry(int $id, int $userId): void
    {
        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type', 'handler', 'state', 'history']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadAssoc();

        if (!\is_array($row) || $row['type'] !== Tasks::TYPE_JOB || $row['state'] !== Tasks::STATE_FAILED) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        $history = Tasks::encodeHistory([...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: 'staff', userId: $userId, outcome: 'retried')]);
        $pending = Tasks::STATE_PENDING;
        $trigger = 'staff';

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName(Tasks::TABLE))
                    ->set($db->quoteName('state') . ' = :state')
                    ->set($db->quoteName('failures') . ' = 0')
                    ->set($db->quoteName('run_after') . ' = UTC_TIMESTAMP(3)')
                    ->set($db->quoteName('lease_owner') . ' = NULL')
                    ->set($db->quoteName('lease_until') . ' = NULL')
                    ->set($db->quoteName('finished_at') . ' = NULL')
                    ->set($db->quoteName('history') . ' = :history')
                    ->set($db->quoteName('last_trigger') . ' = :trigger')
                    ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                    ->where($db->quoteName('id') . ' = :id')
                    ->where($db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB))
                    ->where($db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_FAILED))
                    ->bind(':state', $pending)
                    ->bind(':history', $history)
                    ->bind(':trigger', $trigger)
                    ->bind(':id', $id, ParameterType::INTEGER),
            )->execute();
        } catch (Throwable $e) {
            // Waiting again would make it the second active job of its scope: the one already active covers it.
            if (MysqlError::number(error: $e) === 1062) {
                throw new DomainException('COM_ALFA_TASKS_JOB_ACTIVE', self::REFUSED_ACTIVE);
            }

            throw $e;
        }

        if ($db->getAffectedRows() !== 1) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        Tasks::log(entry: 'Job #' . $id . ' (' . $row['handler'] . ') was put back in the queue by user ' . $userId . '.', priority: Log::INFO);
        TaskAttention::sync();
    }

    /**
     * Resend a failed or held e-mail to the recipients not recorded as sent.
     *
     * @param int $id The task.
     * @param int $userId Who asks.
     *
     * @return int The new task.
     *
     * @throws DomainException When the task cannot be resent (the message is a language key).
     *
     * @since  2.1.4
     */
    public static function resend(int $id, int $userId): int
    {
        return self::again(id: $id, userId: $userId, action: TaskPolicy::ACTION_RESEND);
    }

    /**
     * Run a failed or held task again for the parts not recorded as completed.
     *
     * @param int $id The task.
     * @param int $userId Who asks.
     *
     * @return int The new task.
     *
     * @throws DomainException When the task cannot be run again (the message is a language key).
     *
     * @since  2.1.4
     */
    public static function runAgain(int $id, int $userId): int
    {
        return self::again(id: $id, userId: $userId, action: TaskPolicy::ACTION_RERUN);
    }

    /**
     * Close a failed or held single, or a failed job, with no action.
     *
     * @param int $id The task.
     * @param int $userId Who closes it.
     * @param string $reason Why nothing more is needed.
     *
     * @throws DomainException When no reason is given or the task is no longer failed or held (the message is a
     *                         language key).
     *
     * @since  2.1.4
     */
    public static function resolve(int $id, int $userId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, self::REASON_MAX);

        if ($reason === '') {
            throw new DomainException('COM_ALFA_TASKS_REASON_REQUIRED', self::REFUSED_NO_REASON);
        }

        $db = self::db();
        $row = self::actionable(db: $db, id: $id);
        $history = [...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: 'staff', userId: $userId, outcome: Tasks::STATE_RESOLVED, more: ['reason' => $reason])];

        // Closed, and an extension's plugin told, in one transaction.
        $db->transactionStart(true);

        try {
            if (!self::close(db: $db, id: $id, history: $history)) {
                TransactionState::rollBackLevel(db: $db);

                throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
            }

            WorkSettled::owe(owner: (string) $row['owner'], id: $id, reason: $reason);
            TransactionState::commitLevel($db);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        // Its handler records it where staff look for it (the order's timeline): who, when and why.
        Tasks::notifySettled(row: $row, event: TaskSettlement::RESOLVED, state: Tasks::STATE_RESOLVED, history: $history, userId: $userId, reason: $reason);
        Tasks::log(entry: 'Task #' . $id . ' (' . $row['handler'] . ') was marked resolved by user ' . $userId . ': ' . $reason, priority: Log::INFO);
        TaskAttention::sync();
    }

    /**
     * Resend or run again: a new single that names the original, written in the transaction that resolves the
     * original, and run once it has committed.
     *
     * @param int $id The task.
     * @param int $userId Who asks.
     * @param string $action TaskPolicy::ACTION_RESEND or TaskPolicy::ACTION_RERUN.
     *
     * @return int The new task.
     *
     * @throws DomainException When the task does not allow it or is no longer failed or held.
     *
     * @since  2.1.4
     */
    private static function again(int $id, int $userId, string $action): int
    {
        $db = self::db();
        $row = self::actionable(db: $db, id: $id);
        $policy = Tasks::handler(name: (string) $row['handler'], owner: (string) $row['owner'])?->policy();

        if ($policy === null || $policy->staffAction !== $action) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_NOT_OFFERED', self::REFUSED_NOT_OFFERED);
        }

        $payload = json_decode((string) $row['payload'], true);
        $payload = \is_array($payload) ? $payload : [];
        unset($payload['version'], $payload['resend_of'], $payload['rerun_of']);
        $payload[$action === TaskPolicy::ACTION_RESEND ? 'resend_of' : 'rerun_of'] = $id;
        $outcome = $action === TaskPolicy::ACTION_RESEND ? 'resent' : 'run again';

        // Every unit recorded done anywhere in the chain travels with the new task, so it is never repeated, whatever
        // is pruned of the tasks before it.
        $carried = array_map(
            static fn (string $unit): array => Tasks::entry(trigger: 'staff', userId: $userId, outcome: TaskRun::DONE, unit: $unit, more: ['carried_from' => $id]),
            Tasks::doneUnits(row: $row),
        );

        $db->transactionStart(true);

        try {
            $newId = Tasks::write(
                handler: (string) $row['handler'],
                subjectType: (string) $row['subject_type'],
                subjectId: (int) $row['subject_id'],
                payload: $payload,
                trigger: 'staff',
                userId: $userId,
                history: $carried,
                owner: (string) $row['owner'],
            );
            $history = [...Tasks::decodeHistory($row['history']), Tasks::entry(trigger: 'staff', userId: $userId, outcome: $outcome, more: ['task' => $newId])];

            if (!self::close(db: $db, id: $id, history: $history)) {
                TransactionState::rollBackLevel(db: $db);

                throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
            }

            WorkSettled::owe(owner: (string) $row['owner'], id: $id);

            // Recorded with the act, in its transaction (the order's timeline: who resent or ran it again).
            Tasks::notifySettled(
                row: $row,
                event: $action === TaskPolicy::ACTION_RESEND ? TaskSettlement::RESENT : TaskSettlement::RUN_AGAIN,
                state: Tasks::STATE_RESOLVED,
                history: $history,
                userId: $userId,
                newTaskId: $newId,
            );

            // The new task runs after this commit.
            TransactionState::commitLevel($db);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        Tasks::log(entry: 'Task #' . $id . ' (' . $row['handler'] . ') was ' . $outcome . ' by user ' . $userId . ' as task #' . $newId . '.', priority: Log::INFO);
        TaskAttention::sync();

        return $newId;
    }

    /**
     * A task staff may act on: a failed or held single, or a failed job.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException When it is neither a failed or held single nor a failed job.
     *
     * @since  2.1.4
     */
    private static function actionable(DatabaseDriver $db, int $id): array
    {
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'type', 'owner', 'handler', 'state', 'subject_type', 'subject_id', 'payload', 'history']))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadAssoc();

        if (!\is_array($row) || !self::open(type: (string) $row['type'], state: (string) $row['state'])) {
            throw new DomainException('COM_ALFA_TASKS_ACTION_GONE', self::REFUSED_GONE);
        }

        return $row;
    }

    /**
     * Resolve a failed or held single, or a failed job, with its history: the affected row count says whether this
     * act did it.
     *
     * @param DatabaseDriver $db The connection.
     * @param int $id The task.
     * @param list<array<string, mixed>> $history Its history with this act.
     *
     * @since  2.1.4
     */
    private static function close(DatabaseDriver $db, int $id, array $history): bool
    {
        $json = Tasks::encodeHistory($history);
        $state = Tasks::STATE_RESOLVED;
        $trigger = 'staff';
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName(Tasks::TABLE))
                ->set($db->quoteName('state') . ' = :state')
                ->set($db->quoteName('history') . ' = :history')
                ->set($db->quoteName('last_trigger') . ' = :trigger')
                ->set($db->quoteName('updated_at') . ' = UTC_TIMESTAMP(3)')
                ->set($db->quoteName('finished_at') . ' = UTC_TIMESTAMP(3)')
                ->where($db->quoteName('id') . ' = :id')
                ->where('((' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_SINGLE) . ' AND ' . $db->quoteName('state') . ' IN ('
                    . $db->quote(Tasks::STATE_FAILED) . ', ' . $db->quote(Tasks::STATE_HELD) . ')) OR (' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB)
                    . ' AND ' . $db->quoteName('state') . ' = ' . $db->quote(Tasks::STATE_FAILED) . '))')
                ->bind(':state', $state)
                ->bind(':history', $json)
                ->bind(':trigger', $trigger)
                ->bind(':id', $id, ParameterType::INTEGER),
        )->execute();

        return $db->getAffectedRows() === 1;
    }

    /**
     * Whether staff may act on a task of this type in this state: a failed or held single, or a failed job.
     *
     * @param string $type Its type.
     * @param string $state Its state.
     *
     * @since  2.2.0
     */
    private static function open(string $type, string $state): bool
    {
        return $type === Tasks::TYPE_SINGLE ? \in_array($state, [Tasks::STATE_FAILED, Tasks::STATE_HELD], true) : $type === Tasks::TYPE_JOB && $state === Tasks::STATE_FAILED;
    }

    /**
     * The site's connection.
     *
     * @since  2.1.4
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
