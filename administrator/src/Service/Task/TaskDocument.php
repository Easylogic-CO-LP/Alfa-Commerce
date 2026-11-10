<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Task;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

/**
 * What the API shows of a task: the fields of its document (GET v1/alfa/tasks/{id}), and the tasks a request wrote or
 * joined (meta.tasks of a save's answer).
 *
 * The owner is com_alfa and the result null for every task of this version; the fields are there so that a later
 * public task API fills them without changing the document. Times are UTC, as stored. The last error is staff's
 * information: shown, shortened, to callers with Tools only, and null to every other reader (the creator, the orders
 * view). It is stored without a database's text (Tasks::failureLine()); rows written before 2.2.0 may still hold one.
 * meta.tasks lists only the tasks its reader may read.
 *
 * @since  2.2.0
 */
final class TaskDocument
{
    /**
     * The owner of every task of this version.
     *
     * @since  2.2.0
     */
    public const OWNER = 'com_alfa';

    /**
     * The longest last error shown.
     *
     * @since  2.2.0
     */
    public const ERROR_MAX = 200;

    /**
     * The API path of a task, after the site's own path.
     *
     * @since  2.2.0
     */
    public const PATH = '/api/index.php/v1/alfa/tasks/';

    /**
     * The columns a document is made from.
     *
     * @var list<string>
     *
     * @since  2.2.0
     */
    public const COLUMNS = [
        'id', 'type', 'handler', 'state', 'subject_type', 'subject_id', 'scope_key', 'done_units', 'total_units',
        'run_after', 'created_at', 'started_at', 'finished_at', 'updated_at', 'origin', 'last_error', 'created_by',
    ];

    /**
     * A task's row, or null when there is none of that id.
     *
     * @param int $id The task.
     *
     * @return array<string, mixed>|null
     *
     * @since  2.2.0
     */
    public static function row(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();
        $row = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(self::COLUMNS))
                ->from($db->quoteName(Tasks::TABLE))
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER),
        )->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    /**
     * The document's fields of a task's row.
     *
     * @param array<string, mixed> $row The row ({@see self::COLUMNS}).
     * @param bool $withError Whether its reader may see the last error (a caller with Tools); null otherwise.
     *
     * @return array<string, mixed>
     *
     * @since  2.2.0
     */
    public static function fields(array $row, bool $withError): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'handler' => (string) $row['handler'],
            'owner' => self::OWNER,
            'scope' => self::scope(row: $row),
            'state' => (string) $row['state'],
            'done_units' => (int) $row['done_units'],
            'total_units' => (int) $row['total_units'],
            'percent' => self::percent(row: $row),
            'run_after' => $row['run_after'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'origin' => (string) $row['origin'],
            'last_error' => $withError ? self::shortError(error: (string) $row['last_error']) : null,
            'result' => null,
        ];
    }

    /**
     * What a task covers: a job's scope, or the subject of a single (order:15); null for a single about nothing.
     *
     * @param array<string, mixed> $row The row.
     *
     * @since  2.2.0
     */
    public static function scope(array $row): ?string
    {
        if ($row['type'] === Tasks::TYPE_JOB) {
            return (string) $row['scope_key'];
        }

        return (string) $row['subject_type'] !== '' ? $row['subject_type'] . ':' . (int) $row['subject_id'] : null;
    }

    /**
     * How far a task is, 0 to 100: a done task is 100, a task with units its done share of them, any other 0.
     *
     * @param array<string, mixed> $row The row.
     *
     * @since  2.2.0
     */
    public static function percent(array $row): int
    {
        if ($row['state'] === Tasks::STATE_DONE) {
            return 100;
        }

        $total = (int) $row['total_units'];

        return $total > 0 ? min(100, intdiv(100 * (int) $row['done_units'], $total)) : 0;
    }

    /**
     * A last error as the API shows it to Tools: its first line, at most ERROR_MAX characters.
     *
     * @param string $error The stored last error.
     *
     * @since  2.2.0
     */
    public static function shortError(string $error): string
    {
        $line = trim((string) strtok(str_replace("\r", "\n", $error), "\n"));

        return mb_strlen($line) > self::ERROR_MAX ? mb_substr($line, 0, self::ERROR_MAX - 3) . '...' : $line;
    }

    /**
     * The API path of a task, after the host: the site's own path, then PATH and the id.
     *
     * @param int $id The task.
     *
     * @since  2.2.0
     */
    public static function path(int $id): string
    {
        return rtrim(Uri::root(true), '/') . self::PATH . $id;
    }

    /**
     * meta.tasks: the jobs this request wrote or joined and, when a subject is given, its singles about that subject,
     * each in the state it is in now (a single that ran after the request's commit shows how it ended), that its reader
     * may read (a request that joined a job gains no right to it). A task that is gone (its write rolled back, or it
     * was pruned) is left out.
     *
     * @param User $reader Who the answer is for.
     * @param string $subjectType The subject whose singles are listed, '' for none.
     * @param int $subjectId Its id.
     *
     * @return list<array<string, mixed>> Each {id, type, handler, owner, scope, state, done_units, total_units, percent,
     *                                    merged, links: {self}}.
     *
     * @since  2.2.0
     */
    public static function noted(User $reader, string $subjectType = '', int $subjectId = 0): array
    {
        $notes = Tasks::requestNotes();

        if ($notes === []) {
            return [];
        }

        $db = self::db();
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(self::COLUMNS))
                ->from($db->quoteName(Tasks::TABLE))
                ->whereIn($db->quoteName('id'), array_keys($notes)),
        )->loadAssocList('id') ?: [];
        $entries = [];

        foreach ($notes as $id => $merged) {
            $row = $rows[$id] ?? null;

            if ($row === null || ($row['type'] !== Tasks::TYPE_JOB && ($subjectType === '' || $row['subject_type'] !== $subjectType || (int) $row['subject_id'] !== $subjectId))
                || !TaskAccess::canRead(row: $row, user: $reader)) {
                continue;
            }

            $fields = self::fields(row: $row, withError: false);
            $entries[] = [
                'id' => $fields['id'],
                'type' => $fields['type'],
                'handler' => $fields['handler'],
                'owner' => $fields['owner'],
                'scope' => $fields['scope'],
                'state' => $fields['state'],
                'done_units' => $fields['done_units'],
                'total_units' => $fields['total_units'],
                'percent' => $fields['percent'],
                'merged' => $merged,
                'links' => ['self' => rtrim(Uri::root(), '/') . self::PATH . $fields['id']],
            ];
        }

        return $entries;
    }

    /**
     * The database.
     *
     * @since  2.2.0
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
