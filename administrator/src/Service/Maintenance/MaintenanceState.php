<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Maintenance;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\ClearData\ClearDataStatus;
use Alfa\Component\Alfa\Administrator\Service\Setup\AlfaInstallerGate;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Whether Alfa Commerce is in maintenance: the state Clear everything puts the shop in while it
 * clears the extensions' data and removes and recreates core's own, and leaves it in while it is
 * paused or could not finish.
 *
 * WHERE IT LIVES. In the `state` column of `#__alfa_maintenance` ({@see AlfaInstallerGate::TABLE}), a
 * core table Clear everything never drops and an uninstall keeps, so the state outlasts the dropping
 * of every other Alfa table and an uninstall of the component: a reinstall finds a failed reset
 * again. Nothing else writes that row: Joomla's Options save, the installer's store of the extension
 * row and every other load-then-store of `#__extensions` cannot touch it. Every installer reads it the
 * same way through {@see AlfaInstallerGate}.
 *
 * WHO ASKS. Every way into Alfa asks {@see self::active()} first and answers "maintenance" or does
 * nothing: the storefront, its router, the administrator screens and the REST API through their
 * dispatchers, the system plugin's handlers, the modules and com_ajax (through the system plugin), the
 * scheduled task and the console command. An extension's entry point that core does not gate asks the
 * same.
 *
 * WHAT IT COSTS. One read of one row by its key, at most once in {@see self::FRESH_SECONDS} per
 * process, made only by requests that are about to touch Alfa.
 *
 * WRITES are compare-and-set on the state as it was read (the stored text, byte for byte), and raise
 * the row's `seq`: two administrators who start Clear everything at the same moment cannot both
 * enter, and a request that finds the state changed under it is told so. A write of another column of
 * the row (an extension's Clear data record) does not disturb a run.
 *
 * @since  2.1.0
 */
final class MaintenanceState
{
    /**
     * The version of the state's shape.
     *
     * @var int
     *
     * @since  2.1.0
     */
    public const FORMAT = 1;

    /**
     * How long a read is reused by the same process, in seconds. A web request ends long before;
     * a long-running console process reads again.
     *
     * @var float
     *
     * @since  2.1.0
     */
    public const FRESH_SECONDS = 2.0;

    /**
     * The last answer of {@see self::active()}, and when it was read.
     *
     * @var array{0: bool, 1: float}|null
     *
     * @since  2.1.0
     */
    private static ?array $memo = null;

    /**
     * Whether Alfa is in maintenance now: a Clear everything is running, paused, failed, or stopped
     * without a word.
     *
     * Fails closed: when the state cannot be read the answer is yes, because a shop half cleared must
     * never be served. A state table that does not exist is not maintenance: no Clear everything can be
     * at work without its row.
     *
     * @since  2.1.0
     */
    public static function active(): bool
    {
        $now = microtime(true);

        if (self::$memo !== null && ($now - self::$memo[1]) < self::FRESH_SECONDS) {
            return self::$memo[0];
        }

        try {
            $active = (AlfaInstallerGate::stored()['state'] ?? null) !== null;
        } catch (Throwable) {
            $active = true;
        }

        self::$memo = [$active, $now];

        return $active;
    }

    /**
     * The maintenance state as it is stored now, or null when Alfa is not in maintenance. Always read
     * fresh.
     *
     * Shape (format 1): run, phase (`running`, `paused` or `failed`), step, user (id, name, username),
     * started, updated, error, extensions (by key: name, extension_id, folder, element, status, message),
     * areas (the download store as resolved before anything was removed), files, ids (the identifier
     * floors), images, tables, rows, dropped. A stored state that cannot be decoded reads as failed, so
     * the page can run it again.
     *
     * @return array<string, mixed>|null
     *
     * @throws Throwable When the state cannot be read.
     *
     * @since  2.1.0
     */
    public static function current(): ?array
    {
        $raw = AlfaInstallerGate::stored()['state'] ?? null;

        return $raw === null ? null : self::decode(raw: $raw);
    }

    /**
     * What the last Clear everything that finished left on record, or null when none did.
     *
     * @return array<string, mixed>|null
     *
     * @since  2.1.0
     */
    public static function lastClear(): ?array
    {
        $last = json_decode((string) (AlfaInstallerGate::stored()['last_clear'] ?? ''), true);

        return \is_array($last) ? $last : null;
    }

    /**
     * Enter maintenance, unless Alfa is in it already. Atomic: the row changes only while it holds no
     * state.
     *
     * @param array<string, mixed> $state The state to store.
     *
     * @return bool False when Alfa was in maintenance already.
     *
     * @since  2.1.0
     */
    public static function enter(array $state): bool
    {
        $db = self::db();
        ClearDataStatus::ensureRow(db: $db);

        $db->setQuery(
            $db->createQuery()
                ->update($db->quoteName(AlfaInstallerGate::TABLE))
                ->set($db->quoteName('state') . ' = ' . $db->quote(self::encode(state: ['format' => self::FORMAT] + $state)))
                ->set($db->quoteName('seq') . ' = ' . $db->quoteName('seq') . ' + 1')
                ->where($db->quoteName('id') . ' = 1')
                ->where($db->quoteName('state') . ' IS NULL'),
        )->execute();

        self::$memo = null;

        return $db->getAffectedRows() === 1;
    }

    /**
     * Change the stored state while in maintenance.
     *
     * @param array<string, mixed> $changes The keys to set.
     *
     * @throws RuntimeException When Alfa is not in maintenance, or another request changed the state.
     *
     * @since  2.1.0
     */
    public static function update(array $changes): void
    {
        $raw = AlfaInstallerGate::stored()['state'] ?? null;

        if ($raw === null) {
            throw new RuntimeException('Alfa Commerce is not in maintenance.');
        }

        $state = array_merge(self::decode(raw: $raw), $changes, ['updated' => gmdate('Y-m-d H:i:s')]);

        if (!self::swap(expected: $raw, state: self::encode(state: $state), lastClear: null)) {
            throw new RuntimeException('The maintenance state of Alfa Commerce was changed by another request.');
        }
    }

    /**
     * Leave maintenance, keeping what the finished run left on record. One write: the state goes and
     * the last-clear record is written together.
     *
     * @param array<string, mixed> $lastClear The outcome to keep.
     *
     * @throws RuntimeException When Alfa is not in maintenance, or another request changed the state.
     *
     * @since  2.1.0
     */
    public static function leave(array $lastClear): void
    {
        $raw = AlfaInstallerGate::stored()['state'] ?? null;

        if ($raw === null) {
            throw new RuntimeException('Alfa Commerce is not in maintenance.');
        }

        if (!self::swap(expected: $raw, state: null, lastClear: self::encode(state: ['format' => self::FORMAT] + $lastClear))) {
            throw new RuntimeException('The maintenance state of Alfa Commerce was changed by another request.');
        }
    }

    /**
     * Take THE lock: the one Clear everything holds while it works and every Clear Data of Tools > Extensions holds
     * while it calls an extension ({@see AlfaInstallerGate::lockName()}), so two of them never work together. A
     * connection lock: the database frees it when the connection ends, so a request that died leaves it free.
     *
     * @param DatabaseInterface $db The connection the work runs on.
     * @param int $wait Seconds to wait for another connection to give it back; 0 by default.
     *
     * @return bool False when another connection holds it.
     *
     * @since  2.1.0
     */
    public static function lock(DatabaseInterface $db, int $wait = 0): bool
    {
        return (int) $db->setQuery('SELECT GET_LOCK(' . $db->quote(AlfaInstallerGate::lockName()) . ', ' . max(0, $wait) . ')')->loadResult() === 1;
    }

    /**
     * Give THE lock back.
     *
     * @param DatabaseInterface $db The connection that took it.
     *
     * @since  2.1.0
     */
    public static function unlock(DatabaseInterface $db): void
    {
        try {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote(AlfaInstallerGate::lockName()) . ')')->loadResult();
        } catch (Throwable) {
            // The database frees it when the connection ends.
        }
    }

    /**
     * Whether THIS connection holds the lock now. Joomla's drivers reconnect after a lost connection and run the
     * statement again, and the lock dies with the old connection: work that must hold it asks this first.
     *
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.1.0
     */
    public static function holdsLock(DatabaseInterface $db): bool
    {
        return (int) $db->setQuery('SELECT IS_USED_LOCK(' . $db->quote(AlfaInstallerGate::lockName()) . ') <=> CONNECTION_ID()')->loadResult() === 1;
    }

    /**
     * Whether any connection holds the lock now (a run or a Clear Data at work).
     *
     * @param DatabaseInterface $db The connection.
     *
     * @since  2.1.0
     */
    public static function lockTaken(DatabaseInterface $db): bool
    {
        return (int) $db->setQuery('SELECT IS_USED_LOCK(' . $db->quote(AlfaInstallerGate::lockName()) . ') IS NOT NULL')->loadResult() === 1;
    }

    /**
     * Replace the state, if it is still what was read.
     *
     * @param string $expected The state as it was read.
     * @param string|null $state The new state, or null to leave maintenance.
     * @param string|null $lastClear The last-clear record to write with it, or null to keep it.
     *
     * @return bool False when another request changed the state in between.
     *
     * @since  2.1.0
     */
    private static function swap(string $expected, ?string $state, ?string $lastClear): bool
    {
        $db = self::db();
        $query = $db->createQuery()
            ->update($db->quoteName(AlfaInstallerGate::TABLE))
            ->set($db->quoteName('state') . ' = ' . ($state === null ? 'NULL' : $db->quote($state)))
            ->set($db->quoteName('seq') . ' = ' . $db->quoteName('seq') . ' + 1')
            ->where($db->quoteName('id') . ' = 1')
            // Byte for byte, whatever the column's collation would let pass as equal.
            ->where('MD5(' . $db->quoteName('state') . ') = ' . $db->quote(md5($expected)));

        if ($lastClear !== null) {
            $query->set($db->quoteName('last_clear') . ' = ' . $db->quote($lastClear));
        }

        $db->setQuery($query)->execute();
        self::$memo = null;

        return $db->getAffectedRows() === 1;
    }

    /**
     * A stored state decoded; one that cannot be decoded reads as a failed run, so it can be run again.
     *
     * @param string $raw The stored state.
     *
     * @return array<string, mixed>
     *
     * @since  2.1.0
     */
    private static function decode(string $raw): array
    {
        $state = json_decode($raw, true);

        if (\is_array($state)) {
            return $state;
        }

        return [
            'format' => self::FORMAT,
            'run' => '',
            'phase' => 'failed',
            'step' => 'files',
            'error' => 'The stored state of Clear everything cannot be read: ' . mb_substr($raw, 0, 200),
            'extensions' => [],
        ];
    }

    /**
     * A state as stored.
     *
     * @param array<string, mixed> $state The state.
     *
     * @throws RuntimeException When it cannot be encoded.
     *
     * @since  2.1.0
     */
    private static function encode(array $state): string
    {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new RuntimeException('The maintenance state of Alfa Commerce could not be encoded: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Joomla's connection.
     *
     * @since  2.1.0
     */
    private static function db(): DatabaseInterface
    {
        return Factory::getContainer()->get(DatabaseInterface::class);
    }
}
