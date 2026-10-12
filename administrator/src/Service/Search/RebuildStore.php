<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use RuntimeException;
use Throwable;

/**
 * Where a rebuild's position lives between requests.
 *
 * A rebuild walks the whole catalogue and cannot finish inside one request, so its
 * checkpoint has to outlive the request. It used to live in the administrator's
 * SESSION, which made three things true at once: closing the tab stranded a pending
 * generation that nothing would ever activate, a second administrator could start a
 * competing rebuild over the same engine, and no cron or console driver could exist
 * at all: the checkpoint was unreachable outside a browser session, which is why no
 * such driver was ever written.
 *
 * Moving it here makes every driver equal. The admin's progress bar, a cron tick and
 * `alfa:search:rebuild` read and write the same row, so a run abandoned by a closed
 * laptop is resumed rather than restarted.
 *
 * MUTUAL EXCLUSION IS THE SCHEMA, NOT A CONVENTION. `#__alfa_search_rebuilds` carries
 * a UNIQUE key on `engine`, so a second {@see self::open()} for an engine already
 * rebuilding fails at the database rather than interleaving two walks into one
 * generation. Conventions get forgotten; a unique index does not.
 *
 * ABANDONMENT IS A HEARTBEAT, NOT A LOCK. Every step stamps `heartbeat`. A rebuild
 * whose heartbeat has gone quiet longer than {@see self::STALE_AFTER_SECONDS} is
 * adoptable, which needs no lock service, no lease renewal and no cleanup cron, and
 * degrades safely: the worst case is one wasted rebuild, never two writers.
 *
 * @since  1.5.0
 */
final class RebuildStore
{
    /**
     * @var string
     *
     * @since  1.5.0
     */
    private const TABLE = '#__alfa_search_rebuilds';

    /**
     * @var string
     *
     * @since  1.5.0
     */
    private const ITEMS_TABLE = '#__alfa_search_rebuild_items';

    /**
     * How long a silent rebuild may hold its engine before another driver may take
     * it over.
     *
     * Generous on purpose: a single chunk on a large catalogue with a slow remote
     * engine can legitimately take minutes, and stealing a rebuild that is merely
     * slow is worse than waiting for one that is genuinely dead.
     *
     * @var int
     *
     * @since  1.5.0
     */
    public const STALE_AFTER_SECONDS = 900;

    /**
     * @since  1.5.0
     */
    private readonly DatabaseInterface $db;

    /**
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     *
     * @since  1.5.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * The rebuild currently in flight for an engine, if any.
     *
     * @param string $engine Engine key.
     *
     *
     * @since  1.5.0
     */
    public function current(string $engine): ?RebuildProgress
    {
        $row = $this->row($engine);

        return $row === null ? null : $this->toProgress($row);
    }

    /**
     * Record the start of a rebuild.
     *
     * @param string $engine Engine key.
     * @param RebuildProgress $progress The opening state, as returned by the runner.
     *
     *
     * @throws RuntimeException When a rebuild is already in flight and still alive.
     *
     * @since  1.5.0
     */
    public function open(string $engine, RebuildProgress $progress): void
    {
        $existing = $this->row($engine);

        if ($existing !== null) {
            if (!$this->isStale($existing)) {
                throw new RuntimeException(
                    'A rebuild of the "' . $engine . '" search index is already running.',
                    409,
                );
            }

            // Abandoned: its generation is orphaned either way, and the caller has
            // already opened a fresh one.
            $this->close($engine);
        }

        $now = gmdate('Y-m-d H:i:s');

        $insert = $this->db->getQuery(true)
            ->insert($this->db->quoteName(self::TABLE))
            ->columns($this->db->quoteName([
                'engine', 'generation', 'supersedes', 'cursor', 'processed', 'total',
                'written', 'removed', 'failures', 'enumerated', 'activated', 'started', 'heartbeat',
            ]))
            ->values(':engine, :generation, :supersedes, :cursor, :processed, :total, '
                . ':written, :removed, :failures, :enumerated, :activated, :started, :heartbeat');

        $this->bindProgress($insert, $engine, $progress);
        $insert->bind(':started', $now, ParameterType::STRING);

        $this->db->setQuery($insert)->execute();
    }

    /**
     * Persist the state after a step, and prove the rebuild is alive.
     *
     * @param string $engine Engine key.
     * @param RebuildProgress $progress Current state.
     *
     *
     * @since  1.5.0
     */
    public function save(string $engine, RebuildProgress $progress): void
    {
        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName(self::TABLE))
            ->set($this->db->quoteName('generation') . ' = :generation')
            ->set($this->db->quoteName('supersedes') . ' = :supersedes')
            ->set($this->db->quoteName('cursor') . ' = :cursor')
            ->set($this->db->quoteName('processed') . ' = :processed')
            ->set($this->db->quoteName('total') . ' = :total')
            ->set($this->db->quoteName('written') . ' = :written')
            ->set($this->db->quoteName('removed') . ' = :removed')
            ->set($this->db->quoteName('failures') . ' = :failures')
            ->set($this->db->quoteName('enumerated') . ' = :enumerated')
            ->set($this->db->quoteName('activated') . ' = :activated')
            ->set($this->db->quoteName('heartbeat') . ' = :heartbeat')
            ->where($this->db->quoteName('engine') . ' = :engine');

        $this->bindProgress($update, $engine, $progress);

        $this->db->setQuery($update)->execute();
    }

    /**
     * Forget a rebuild and everything recorded against it.
     *
     * @param string $engine Engine key.
     *
     *
     * @since  1.5.0
     */
    public function close(string $engine): void
    {
        $row = $this->row($engine);

        if ($row === null) {
            return;
        }

        $id = (int) $row->id;

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName(self::ITEMS_TABLE))
                ->where($this->db->quoteName('rebuild_id') . ' = ' . $id),
        )->execute();

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('id') . ' = ' . $id),
        )->execute();
    }

    /**
     * Note that items were written by the live path while a rebuild is walking.
     *
     * The whole point of the child table. The runner builds a chunk of documents in
     * memory and writes it afterwards; an item saved inside that window is written
     * correctly by the live path and then overwritten by the runner's older snapshot.
     * Recording the id lets activation re-project exactly those items instead of
     * repeating the walk or, worse, shipping a generation that is quietly wrong.
     *
     * Silent when no rebuild is running, which is the overwhelmingly common case, and
     * never fatal: a save must not fail because bookkeeping did.
     *
     * @param string $engine Engine key.
     * @param int[] $itemIds Items just written.
     *
     *
     * @since  1.5.0
     */
    public function noteWritten(string $engine, array $itemIds): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));

        if ($itemIds === []) {
            return;
        }

        try {
            $row = $this->row($engine);

            if ($row === null) {
                return;
            }

            $id = (int) $row->id;
            $values = [];

            foreach ($itemIds as $itemId) {
                $values[] = '(' . $id . ',' . $itemId . ')';
            }

            // INSERT IGNORE rather than read-modify-write: two administrators saving
            // at the same moment would otherwise race, and one id would be lost.
            $this->db->setQuery(
                'INSERT IGNORE INTO ' . $this->db->quoteName(self::ITEMS_TABLE)
                . ' (' . $this->db->quoteName('rebuild_id') . ', ' . $this->db->quoteName('item_id') . ')'
                . ' VALUES ' . implode(',', $values),
            )->execute();
        } catch (Throwable $e) {
            // Bookkeeping must never fail a catalogue save.
        }
    }

    /**
     * Items written live since this rebuild started.
     *
     * @param string $engine Engine key.
     *
     * @return int[]
     *
     * @since  1.5.0
     */
    public function writtenDuringRebuild(string $engine): array
    {
        $row = $this->row($engine);

        if ($row === null) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('item_id'))
            ->from($this->db->quoteName(self::ITEMS_TABLE))
            ->where($this->db->quoteName('rebuild_id') . ' = ' . (int) $row->id)
            ->order($this->db->quoteName('item_id') . ' ASC');

        return array_map('intval', (array) $this->db->setQuery($query)->loadColumn());
    }

    /**
     * Whether a stored rebuild has gone quiet long enough to be taken over.
     *
     * @param object $row Stored row.
     *
     *
     * @since  1.5.0
     */
    private function isStale(object $row): bool
    {
        $heartbeat = strtotime((string) ($row->heartbeat ?? '') . ' UTC');

        return $heartbeat === false || (time() - $heartbeat) > self::STALE_AFTER_SECONDS;
    }

    /**
     * @param string $engine Engine key.
     *
     *
     * @since  1.5.0
     */
    private function row(string $engine): ?object
    {
        try {
            $query = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('engine') . ' = :engine')
                ->bind(':engine', $engine, ParameterType::STRING);

            return $this->db->setQuery($query)->loadObject();
        } catch (Throwable $e) {
            // Table not yet created by the update; no rebuild is in flight.
            return null;
        }
    }

    /**
     * @param object $row Stored row.
     *
     *
     * @since  1.5.0
     */
    private function toProgress(object $row): RebuildProgress
    {
        $failures = json_decode((string) ($row->failures ?? ''), true);

        return new RebuildProgress(
            generation: (string) $row->generation,
            cursor:     (int) $row->cursor,
            processed:  (int) $row->processed,
            total:      (int) $row->total,
            written:    (int) $row->written,
            removed:    (int) $row->removed,
            failures:   \is_array($failures) ? $failures : [],
            enumerated: (bool) $row->enumerated,
            activated:  (bool) $row->activated,
            supersedes: (string) $row->supersedes,
        );
    }

    /**
     * Bind every progress field plus the heartbeat.
     *
     * Values are held in local variables because Joomla's `bind()` takes them BY
     * REFERENCE: passing an expression is a fatal error on PHP 8.
     *
     * @param object $query Query to bind onto.
     * @param string $engine Engine key.
     * @param RebuildProgress $progress State to bind.
     *
     *
     * @since  1.5.0
     */
    private function bindProgress(object $query, string $engine, RebuildProgress $progress): void
    {
        $generation = $progress->generation;
        $supersedes = $progress->supersedes;
        $cursor = $progress->cursor;
        $processed = $progress->processed;
        $total = $progress->total;
        $written = $progress->written;
        $removed = $progress->removed;
        $failures = $progress->failures === [] ? null : json_encode($progress->failures);
        $enumerated = $progress->enumerated ? 1 : 0;
        $activated = $progress->activated ? 1 : 0;
        $heartbeat = gmdate('Y-m-d H:i:s');

        $query->bind(':engine', $engine, ParameterType::STRING)
            ->bind(':generation', $generation, ParameterType::STRING)
            ->bind(':supersedes', $supersedes, ParameterType::STRING)
            ->bind(':cursor', $cursor, ParameterType::INTEGER)
            ->bind(':processed', $processed, ParameterType::INTEGER)
            ->bind(':total', $total, ParameterType::INTEGER)
            ->bind(':written', $written, ParameterType::INTEGER)
            ->bind(':removed', $removed, ParameterType::INTEGER)
            ->bind(':failures', $failures, $failures === null ? ParameterType::NULL : ParameterType::STRING)
            ->bind(':enumerated', $enumerated, ParameterType::INTEGER)
            ->bind(':activated', $activated, ParameterType::INTEGER)
            ->bind(':heartbeat', $heartbeat, ParameterType::STRING);
    }
}
