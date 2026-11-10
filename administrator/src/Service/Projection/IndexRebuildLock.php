<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Projection;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Order\NamedLock;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Throwable;
use WeakMap;

/**
 * The item lock that every writer of an item's projection rows holds (2.2.0).
 *
 * Two rebuilds of one item that overlap leave the rows of the one that writes last, and nothing made the last writer
 * the one that read last: a change committed in between was lost. So each writer of an item's rows (a save's rebuild,
 * a bulk rebuild, a rule save's price rows, the catalogue job, a price set's preparation, a removal) asks for the
 * item's named lock with a bounded wait, reads the item only after the grant, in a transaction begun after it, and
 * commits before the release: the last writer is then the one that read last. The name is alfa.reindex.<site>.<item
 * id>, as Alfa names its other named locks ({@see NamedLock}), so two sites on one database server never wait for each
 * other.
 *
 * Several items are asked in ascending id order, and a save asks for one, so two writers never wait for each other in
 * a circle. A connection that holds an item's lock takes it again without asking the server and releases it as many
 * times: a rebuild under the manager's lock calls writers that ask for it again.
 *
 * Nothing but the grant lets a writer go on. A wait that ends without the lock is a timeout; an exception or a NULL
 * answer means the database cannot lock for this connection (failed). Both are logged with the lock's name, and the
 * caller marks the item and enqueues the catalogue job ({@see ProjectionManager}). Only that job, holding the heavy
 * slot, rebuilds an item whose lock failed, inside unlocked(): requests then never rebuild it, so the job is its only
 * writer.
 *
 * @since  2.0.0
 */
final class IndexRebuildLock
{
    use NamedLock;

    /**
     * The lock is held.
     *
     * @since  2.2.0
     */
    public const GRANTED = 'granted';

    /**
     * Another connection kept the lock for the whole wait.
     *
     * @since  2.2.0
     */
    public const TIMEOUT = 'timeout';

    /**
     * The database could not lock: an exception, or no answer.
     *
     * @since  2.2.0
     */
    public const FAILED = 'failed';

    /**
     * How long a writer waits for an item's lock, in seconds.
     *
     * @since  2.2.0
     */
    public const WAIT_SECONDS = 10;

    /**
     * Where a lock not granted is logged.
     *
     * @since  2.2.0
     */
    public const LOG_CATEGORY = 'com_alfa.projections';

    /**
     * The item locks each connection holds: item id => how many times it took the lock.
     *
     * @var WeakMap<DatabaseInterface, array<int, int>>|null
     *
     * @since  2.2.0
     */
    private static ?WeakMap $held = null;

    /**
     * How deep each connection is inside unlocked().
     *
     * @var WeakMap<DatabaseInterface, int>|null
     *
     * @since  2.2.0
     */
    private static ?WeakMap $unlocked = null;

    /** @var DatabaseInterface */
    private $db;

    /**
     * @param DatabaseInterface|null $db The connection that takes and releases the locks; defaults to the container
     *                                   driver.
     *
     * @since  2.0.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * The lock's name for an item: alfa.reindex.<site>.<item id>.
     *
     * @param int $itemId The item.
     *
     * @since  2.2.0
     */
    public function name(int $itemId): string
    {
        return self::lockName($this->db, 'reindex', $itemId);
    }

    /**
     * Take the locks of items, in ascending id order, each with the wait.
     *
     * Several items not held yet are first asked in one statement without waiting; when one of them is busy, those
     * taken are given back and every item is asked in order with the wait, so a writer never waits while it holds a
     * higher item.
     *
     * A caller with a deadline (a job's chunk: the tick's remaining budget, and never past its lease) caps the waits by
     * it: each item is asked with the whole seconds left (none when nothing is left), so the asks of a chunk together
     * never run past it, and an item not granted in that time answers TIMEOUT.
     *
     * @param int[] $itemIds The items.
     * @param float|null $until The deadline, as microtime(true); null asks each item with the full wait.
     *
     * @return array<int, string> Each item, ascending => GRANTED, TIMEOUT or FAILED. The granted ones are held until
     *                            release().
     *
     * @since  2.2.0
     */
    public function acquire(array $itemIds, ?float $until = null): array
    {
        $held = self::heldMap()[$this->db] ?? [];
        $status = [];
        $ask = [];

        foreach (self::ids(itemIds: $itemIds) as $itemId) {
            if (isset($held[$itemId])) {
                $held[$itemId]++;
                $status[$itemId] = self::GRANTED;
            } elseif ($this->isUnlocked()) {
                // The catalogue job's rebuild under the heavy slot: nothing is asked, nothing is held.
                $status[$itemId] = self::GRANTED;
            } else {
                $status[$itemId] = self::FAILED;
                $ask[] = $itemId;
            }
        }

        $granted = \count($ask) > 1 ? $this->tryAll(itemIds: $ask) : [];

        foreach ($ask as $itemId) {
            $status[$itemId] = $granted === [] ? $this->ask(itemId: $itemId, wait: self::waitFor(until: $until)) : self::GRANTED;

            if ($status[$itemId] === self::GRANTED) {
                $held[$itemId] = 1;
            }
        }

        self::heldMap()[$this->db] = $held;

        return $status;
    }

    /**
     * Give back the locks of items taken by acquire(), once for each time they were taken. An item the connection
     * does not hold is passed over.
     *
     * @param int[] $itemIds The items.
     *
     * @since  2.2.0
     */
    public function release(array $itemIds): void
    {
        $held = self::heldMap()[$this->db] ?? [];
        $free = [];

        foreach (self::ids(itemIds: $itemIds) as $itemId) {
            if (!isset($held[$itemId])) {
                continue;
            }

            if (--$held[$itemId] > 0) {
                continue;
            }

            unset($held[$itemId]);
            $free[] = $itemId;
        }

        self::heldMap()[$this->db] = $held;

        if ($free === []) {
            return;
        }

        try {
            $this->db->setQuery('SELECT ' . implode(', ', array_map(fn (int $itemId): string => 'RELEASE_LOCK(' . $this->db->quote($this->name(itemId: $itemId)) . ')', $free)))->loadRow();
        } catch (Throwable $e) {
            // Each lock still ends with the connection, at the end of the request.
            Log::add('The rebuild locks of items ' . implode(', ', $free) . ' could not be released: ' . $e->getMessage(), Log::WARNING, self::LOG_CATEGORY);
        }
    }

    /**
     * Whether this connection holds an item's lock.
     *
     * @param int $itemId The item.
     *
     * @since  2.2.0
     */
    public function holds(int $itemId): bool
    {
        return isset((self::heldMap()[$this->db] ?? [])[$itemId]);
    }

    /**
     * Run the catalogue job's rebuild of items whose lock failed without asking for it: the job holds the heavy slot,
     * and every request that meets the same failure marks and enqueues instead of rebuilding, so the job is the items'
     * only writer. Never called outside that job.
     *
     * @param DatabaseInterface $db The job's connection.
     * @param callable $work The rebuild: fn(): mixed.
     *
     * @return mixed What the work answers.
     *
     * @since  2.2.0
     */
    public static function unlocked(DatabaseInterface $db, callable $work): mixed
    {
        $depth = self::$unlocked ??= new WeakMap();
        $depth[$db] = ($depth[$db] ?? 0) + 1;

        try {
            return $work();
        } finally {
            $depth[$db]--;

            if ($depth[$db] <= 0) {
                unset($depth[$db]);
            }
        }
    }

    /**
     * The wait one ask may take: the full wait, or the whole seconds a deadline leaves (none once it has passed).
     *
     * @param float|null $until The deadline, as microtime(true); null for none.
     *
     * @since  2.2.0
     */
    private static function waitFor(?float $until): int
    {
        return $until === null ? self::WAIT_SECONDS : max(0, min(self::WAIT_SECONDS, (int) floor($until - microtime(true))));
    }

    /**
     * Ask the server for one item's lock with a wait.
     *
     * @param int $itemId The item.
     * @param int $wait The seconds the server may wait for it.
     *
     * @return string GRANTED, TIMEOUT or FAILED; a TIMEOUT or a FAILED logged with the lock's name.
     *
     * @since  2.2.0
     */
    private function ask(int $itemId, int $wait): string
    {
        $name = $this->name(itemId: $itemId);

        try {
            $got = $this->db->setQuery('SELECT GET_LOCK(' . $this->db->quote($name) . ', ' . $wait . ')')->loadResult();
        } catch (Throwable $e) {
            self::log(itemId: $itemId, name: $name, why: 'could not be asked: ' . $e->getMessage());

            return self::FAILED;
        }

        if ($got === null) {
            self::log(itemId: $itemId, name: $name, why: 'got no answer from the database');

            return self::FAILED;
        }

        if ((int) $got !== 1) {
            self::log(itemId: $itemId, name: $name, why: 'was not granted within ' . $wait . ' s');

            return self::TIMEOUT;
        }

        return self::GRANTED;
    }

    /**
     * Ask for several items' locks in one statement without waiting. All granted: answers them. Otherwise the ones
     * taken are given back and nothing is answered, so the caller asks each in order with the wait. A try cannot wait,
     * so its order cannot make two writers wait for each other.
     *
     * @param int[] $itemIds The items, ascending, none held.
     *
     * @return int[] The items, when every lock was granted; else none.
     *
     * @since  2.2.0
     */
    private function tryAll(array $itemIds): array
    {
        $names = array_map(fn (int $itemId): string => $this->db->quote($this->name(itemId: $itemId)), $itemIds);

        try {
            $got = array_values((array) $this->db->setQuery('SELECT ' . implode(', ', array_map(static fn (string $name): string => 'GET_LOCK(' . $name . ', 0)', $names)))->loadRow());
        } catch (Throwable) {
            // Asked again one by one, where a failure is logged with its item's name.
            return [];
        }

        $taken = [];

        foreach ($names as $i => $name) {
            if (isset($got[$i]) && (int) $got[$i] === 1) {
                $taken[] = $name;
            }
        }

        if (\count($taken) === \count($names)) {
            return $itemIds;
        }

        if ($taken !== []) {
            try {
                $this->db->setQuery('SELECT ' . implode(', ', array_map(static fn (string $name): string => 'RELEASE_LOCK(' . $name . ')', $taken)))->loadRow();
            } catch (Throwable $e) {
                Log::add('A try for several rebuild locks could not give back the ones it took: ' . $e->getMessage(), Log::WARNING, self::LOG_CATEGORY);
            }
        }

        return [];
    }

    /**
     * Whether this connection runs inside unlocked().
     *
     * @since  2.2.0
     */
    private function isUnlocked(): bool
    {
        return (self::$unlocked[$this->db] ?? 0) > 0;
    }

    /**
     * The item locks of every connection.
     *
     * @return WeakMap<DatabaseInterface, array<int, int>>
     *
     * @since  2.2.0
     */
    private static function heldMap(): WeakMap
    {
        return self::$held ??= new WeakMap();
    }

    /**
     * Unique positive item ids, ascending.
     *
     * @param array<int|string> $itemIds The raw ids.
     *
     * @return int[]
     *
     * @since  2.2.0
     */
    private static function ids(array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /**
     * Log a lock not granted, with its name and what follows.
     *
     * @param int $itemId The item.
     * @param string $name The lock's name.
     * @param string $why What the database answered.
     *
     * @since  2.2.0
     */
    private static function log(int $itemId, string $name, string $why): void
    {
        Log::add('The rebuild lock ' . $name . ' of item ' . $itemId . ' ' . $why . '; the item is left to the catalogue rebuild.', Log::WARNING, self::LOG_CATEGORY);
    }
}
