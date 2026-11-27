<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Pricing;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Task\DueRead;
use Alfa\Component\Alfa\Administrator\Service\Task\Job\PriceAuditJob;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskDocument;
use Alfa\Component\Alfa\Administrator\Service\Task\Tasks;
use Alfa\Component\Alfa\Administrator\Service\Task\TaskStaff;
use Alfa\Component\Alfa\Administrator\Service\Task\WorkDeclarations;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * The stored result of the price check: what its job last found, kept in #__alfa_meta beside the shop's other facts,
 * and the only thing the price check page, the dashboard tile and the bell read. One row holds the summary (the moment
 * the check read at, the context, the options, the engine's count, each section's count, the index counters); one row
 * per section holds its finding rows, compact, the products named only when a page of them is shown. A run in
 * progress writes nothing here until its last piece, so the previous complete result stays readable throughout.
 *
 * A price row, a discount or a tax saved marks the result out of date with one write (the moment of the change); the
 * result is out of date while that moment is at or after the moment the check began (the summary's now), since every
 * piece of the check judges the data as of its start, and a change during the run is not in what it stored.
 *
 * The state is read from here and from the job's own rows (#__alfa_tasks): never run, running (the job waiting or
 * running, with its progress), complete, failed (the last run failed after the last complete one, with its reason) or
 * out of date; and when the schedule will run it next. What there is to act on (attention()) is judged from that state
 * alone, the one rule the dashboard tile and the bell follow.
 *
 * @since  2.3.0
 */
final class PriceAuditStore
{
    /** @since 2.3.0 */
    public const META_SUMMARY = 'price_audit.result';

    /** The prefix of a section's rows: the section's key completes it. @since 2.3.0 */
    public const META_ROWS = 'price_audit.rows.';

    /** @since 2.3.0 */
    public const META_STALE = 'price_audit.stale';

    /** @since 2.3.0 */
    public const STATE_NEVER = 'never';

    /** @since 2.3.0 */
    public const STATE_RUNNING = 'running';

    /** @since 2.3.0 */
    public const STATE_COMPLETE = 'complete';

    /** @since 2.3.0 */
    public const STATE_FAILED = 'failed';

    /** @since 2.3.0 */
    public const STATE_STALE = 'stale';

    private readonly DatabaseDriver $db;

    /**
     * @param DatabaseDriver|null $db The connection; the site's when omitted.
     *
     * @since  2.3.0
     */
    public function __construct(?DatabaseDriver $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * The last complete result's summary, or null when the check never completed.
     *
     * @return array|null now (the moment the check began and judged the data at), finished_at, context, options,
     *                    engine, sections (key => count, available, taxes), index.
     *
     * @since  2.3.0
     */
    public function summary(): ?array
    {
        $json = $this->value(name: self::META_SUMMARY);
        $summary = $json === null ? null : json_decode($json, true);

        return \is_array($summary) && isset($summary['sections']) ? $summary : null;
    }

    /**
     * One page of a section's stored finding rows.
     *
     * @param string $section A section.
     * @param int $offset The rows before this page.
     * @param int $limit At most this many rows; 0 for all.
     *
     * @return array{rows: list<array>, total: int}
     *
     * @since  2.3.0
     */
    public function rows(string $section, int $offset = 0, int $limit = 0): array
    {
        $json = $this->value(name: self::META_ROWS . $section);
        $rows = $json === null ? [] : json_decode($json, true);
        $rows = \is_array($rows) ? array_values($rows) : [];

        return ['rows' => $limit > 0 ? \array_slice($rows, max(0, $offset), $limit) : \array_slice($rows, max(0, $offset)), 'total' => \count($rows)];
    }

    /**
     * Keep a complete result: the summary and every section's rows, in one transaction, so a reader meets the previous
     * result or the new one, never a mix.
     *
     * @param array $summary The summary (see summary()).
     * @param array<string, list<array>> $rowsBySection The finding rows of each section.
     *
     * @since  2.3.0
     */
    public function store(array $summary, array $rowsBySection): void
    {
        $db = $this->db;
        $db->transactionStart(true);

        try {
            $this->write(name: self::META_SUMMARY, value: json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            foreach (PriceAudit::SECTIONS as $section) {
                $this->write(name: self::META_ROWS . $section, value: json_encode(array_values($rowsBySection[$section] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            TransactionState::commitLevel(db: $db);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }
    }

    /**
     * A price row, a discount or a tax changed: the stored result is out of date from now. One write; the check itself
     * is not run here.
     *
     * @param DatabaseDriver|null $db The connection; the site's when omitted.
     *
     * @since  2.3.0
     */
    public static function markStale(?DatabaseDriver $db = null): void
    {
        (new self(db: $db))->write(name: self::META_STALE, value: gmdate('Y-m-d H:i:s'));
    }

    /**
     * The moment of the last change that made the result out of date, or null when none was recorded.
     *
     * @since  2.3.0
     */
    public function staleSince(): ?string
    {
        return $this->value(name: self::META_STALE);
    }

    /**
     * Where the check stands, for the page, the tile and the bell: its state, the last complete summary, the job
     * waiting or running with its progress, the failure of the last run when it failed after the last complete one,
     * the moment the result went out of date, and when the schedule runs it next.
     *
     * @return array{state: string, summary: array|null, stale_since: string|null, job: array|null, failure: string, next_run_at: string|null, scheduled: bool}
     *
     * @since  2.3.0
     */
    public function status(): array
    {
        $summary = $this->summary();
        $db = $this->db;
        $rows = $db->setQuery(
            'SELECT ' . implode(', ', $db->quoteName(['id', 'state', 'created_at', 'finished_at', 'last_error']))
            . ' FROM ' . $db->quoteName(Tasks::TABLE)
            . ' WHERE ' . $db->quoteName('type') . ' = ' . $db->quote(Tasks::TYPE_JOB)
            . ' AND ' . $db->quoteName('owner') . ' = ' . $db->quote(DueRead::CORE_OWNER)
            . ' AND ' . $db->quoteName('handler') . ' = ' . $db->quote(PriceAuditJob::NAME)
            . ' ORDER BY ' . $db->quoteName('id') . ' DESC',
            0,
            2,
        )->loadAssocList() ?: [];
        $active = null;
        $latest = $rows[0] ?? null;

        foreach ($rows as $row) {
            if (\in_array((string) $row['state'], [Tasks::STATE_PENDING, Tasks::STATE_RUNNING], true)) {
                $active = $row;

                break;
            }
        }

        $failure = '';

        if ($latest !== null && (string) $latest['state'] === Tasks::STATE_FAILED
            && ($summary === null || (string) ($latest['created_at'] ?? '') > (string) ($summary['finished_at'] ?? ''))) {
            $failure = Tasks::errorText((string) $latest['last_error']);
        }

        $stale = $this->staleSince();
        // At or after the start: a change while the run was on is not in what it stored.
        $outOfDate = $summary !== null && $stale !== null && $stale >= (string) $summary['now'];

        $state = match (true) {
            $active !== null => self::STATE_RUNNING,
            $failure !== '' => self::STATE_FAILED,
            $summary === null => self::STATE_NEVER,
            $outOfDate => self::STATE_STALE,
            default => self::STATE_COMPLETE,
        };

        $declaration = WorkDeclarations::find(owner: DueRead::CORE_OWNER, name: PriceAuditJob::NAME);
        $every = (int) ($declaration['every_minutes'] ?? 0);
        $scheduled = $declaration !== null && (int) ($declaration['enabled'] ?? 0) === 1 && $every > 0;
        $next = null;

        if ($scheduled) {
            // The schedule owes a run once no run was created within its span: the last run's creation plus the span.
            $next = $latest === null ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s', strtotime(substr((string) $latest['created_at'], 0, 19) . ' UTC') + $every * 60);
        }

        return [
            'state' => $state,
            'summary' => $summary,
            'stale_since' => $outOfDate ? $stale : null,
            'job' => $active === null ? null : TaskStaff::job(id: (int) $active['id']),
            'failure' => $failure,
            'next_run_at' => $next,
            'scheduled' => $scheduled,
        ];
    }

    /**
     * What there is to act on, judged from a status (status()) and nothing else: the kinds of finding of the stored
     * result with their counts, whether that result is complete, out of date or kept while a new run is on; and the
     * failure of the last run, in its short form. Both are empty when the check never ran, when a run is on with no
     * earlier finding, and when the result found nothing: then the dashboard shows no Prices tile and the bell holds
     * no notice, the attention card being for things to act on.
     *
     * @param array $status A status, as status() answers it.
     *
     * @return array{counts: array<string, int>, failure: string}
     *
     * @since  2.3.0
     */
    public static function attention(array $status): array
    {
        $summary = $status['summary'] ?? null;

        return [
            'counts' => \is_array($summary) ? PriceAudit::counts(summary: $summary) : [],
            'failure' => ($status['state'] ?? '') === self::STATE_FAILED ? TaskDocument::shortError(error: (string) ($status['failure'] ?? '')) : '',
        ];
    }

    /**
     * One stored value, or null when the row is missing.
     *
     * @param string $name The fact.
     *
     * @since  2.3.0
     */
    private function value(string $name): ?string
    {
        $db = $this->db;
        $value = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('value'))
                ->from($db->quoteName('#__alfa_meta'))
                ->where($db->quoteName('name') . ' = ' . $db->quote($name)),
        )->loadResult();

        return $value === null ? null : (string) $value;
    }

    /**
     * Write one fact, new or over the old one, in one statement.
     *
     * @param string $name The fact.
     * @param string $value Its value.
     *
     * @since  2.3.0
     */
    private function write(string $name, string $value): void
    {
        $db = $this->db;
        $db->setQuery(
            'INSERT INTO ' . $db->quoteName('#__alfa_meta') . ' (' . $db->quoteName('name') . ', ' . $db->quoteName('value') . ')'
            . ' VALUES (' . $db->quote($name) . ', ' . $db->quote($value) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('value') . ' = VALUES(' . $db->quoteName('value') . ')',
        )->execute();
    }
}
