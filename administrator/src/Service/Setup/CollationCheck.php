<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\NotificationHelper;
use Alfa\Component\Alfa\Administrator\Helper\NotificationText;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Log\AlfaLog;
use Alfa\Component\Alfa\Administrator\Service\Maintenance\CoreTables;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseDriver;
use Throwable;

/**
 * One collation for every table of core's, made to hold after an update that could not convert them all.
 *
 * 2.1.1 converts core's tables created in another collation to utf8mb4_unicode_ci
 * (sql/updates/mysql/2.1.1.sql). A statement there may fail without stopping the update: two
 * values that a unique key reads as one in the new collation (ss and the sharp s), a lost
 * connection, a full disk. The installer then runs {@see self::converge()} once: every table of
 * core's ({@see CoreTables::componentTables()}: the install SQL's and their language tables) still
 * in another collation is converted with its own statement from that file, and one the file does
 * not name is converted whole when that changes no column compared byte for byte. One attempt per
 * table and run, never a retry. What is left is named with the database's own error
 * ({@see self::reportOutcome()}) and stays in the notifications until an installation converts it.
 * A table named like Alfa's that core does not own (an extension's own, one an earlier version
 * left) is never converted and never in a notice: the setup log names it once ({@see self::noteOthers()}).
 *
 * @since  2.1.1
 */
final class CollationCheck
{
    /**
     * The collation of every table of core's.
     *
     * @var string
     * @since  2.1.1
     */
    public const COLLATION = 'utf8mb4_unicode_ci';

    /**
     * The dedup key of the notice that names the tables left.
     *
     * @var string
     * @since  2.1.1
     */
    public const KEY = 'alfa.collation';

    /**
     * The update file whose statements convert the tables, relative to the administrator folder.
     *
     * @var string
     * @since  2.1.1
     */
    private const STATEMENTS_FILE = '/components/com_alfa/sql/updates/mysql/2.1.1.sql';

    /**
     * Minutes between two looks of the bell's refresh ({@see self::syncNotice()}).
     *
     * @var int
     * @since  2.1.1
     */
    private const NOTICE_CHECK_MINUTES = 10;

    /**
     * The `#__alfa_meta` row that keeps the tables of others the setup log has named ({@see self::noteOthers()}).
     *
     * @var string
     * @since  2.1.1
     */
    private const OTHERS_SAID = 'collation_others_said';

    /**
     * Core's tables not in the collation: by their default, or by a text column that is neither in
     * it nor compared byte for byte.
     *
     * @param string[]|null $only Table names (prefix resolved) to look at; null for every table of core's.
     *
     * @return string[] The table names, in the order the conversion takes them: the update file's,
     *                  then the rest by name.
     *
     * @since  2.1.1
     */
    public static function pending(?array $only = null): array
    {
        return self::look(db: self::db(), only: $only)['own'];
    }

    /**
     * The base tables named like Alfa's that are not in the collation, parted by owner: core's own
     * ({@see CoreTables::componentTables()}) and the others (an extension's own table, one an earlier
     * version or a person left), which core never converts.
     *
     * @param DatabaseDriver $db The connection.
     * @param string[]|null $only Table names (prefix resolved) to look at; null for all.
     *
     * @return array{own: string[], others: string[]} Core's in the order the conversion takes them
     *                                                (the update file's, then by name); the others by name.
     *
     * @since  2.1.1
     */
    private static function look(DatabaseDriver $db, ?array $only): array
    {
        $like = $db->quote(str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $db->getPrefix() . 'alfa_') . '%');

        // Base tables only: a view named like an Alfa table converts nothing, and no installation could clear it.
        $base = (array) $db->setQuery(
            'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE " . $like,
        )->loadAssocList('TABLE_NAME', 'TABLE_COLLATION');
        $tables = array_keys(array_filter($base, static fn ($collation): bool => (string) $collation !== self::COLLATION));

        foreach ((array) $db->setQuery(
            'SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . ' AND TABLE_NAME LIKE ' . $like . ' AND COLLATION_NAME IS NOT NULL'
            . ' AND COLLATION_NAME <> ' . $db->quote(self::COLLATION) . " AND RIGHT(COLLATION_NAME, 4) <> '_bin'",
        )->loadColumn() as $table) {
            if (\array_key_exists((string) $table, $base)) {
                $tables[] = (string) $table;
            }
        }

        $tables = array_values(array_unique(array_map('strval', $tables)));

        if ($only !== null) {
            $tables = array_values(array_intersect($tables, $only));
        }

        if ($tables === []) {
            return ['own' => [], 'others' => []];
        }

        $own = (new CoreTables(db: $db))->componentTables(among: array_combine(array_map('strtolower', $tables), $tables));
        $others = array_values(array_diff($tables, $own));
        sort($others);
        $order = array_flip(array_keys(self::statements(db: $db)));
        usort($own, static fn (string $a, string $b): int => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return ['own' => $own, 'others' => $others];
    }

    /**
     * Convert each table of core's still in another collation, once.
     *
     * A table the update file names is converted with its statement there (the cart keeps its
     * byte-compared browser key). Another one is converted whole, unless it holds a column compared
     * byte for byte, which converting would change: that one is left and named. Nothing runs inside
     * an open transaction, which a conversion would commit. A table named like Alfa's that core does
     * not own is never touched. The answer lists the tables converted, those that failed with the
     * reason, those left untouched, and the tables of others in another collation.
     *
     * @param string[]|null $only Table names (prefix resolved) to convert; null for every table of core's.
     *
     * @return array{converted: string[], failed: array<string, array{error: string, index: string, columns: string[], rows: string[]}>, skipped: string[], others: string[]}
     *
     * @since  2.1.1
     */
    public static function converge(?array $only = null): array
    {
        $db = self::db();
        ['own' => $pending, 'others' => $others] = self::look(db: $db, only: $only);
        $outcome = ['converted' => [], 'failed' => [], 'skipped' => [], 'others' => $others];

        if ($pending === []) {
            return $outcome;
        }

        if (TransactionState::isOpen($db)) {
            foreach ($pending as $table) {
                $outcome['failed'][$table] = ['error' => 'not converted inside an open transaction', 'index' => '', 'columns' => [], 'rows' => []];
            }

            return $outcome;
        }

        $statements = self::statements(db: $db);

        foreach ($pending as $table) {
            $sql = $statements[$table] ?? null;

            if ($sql === null) {
                if (self::byteColumns(db: $db, table: $table) !== []) {
                    $outcome['skipped'][] = $table;

                    continue;
                }

                $sql = 'ALTER TABLE ' . $db->quoteName($table) . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . self::COLLATION;
            }

            try {
                $db->setQuery($sql)->execute();
                $outcome['converted'][] = $table;
            } catch (Throwable $e) {
                $outcome['failed'][$table] = self::diagnose(db: $db, table: $table, error: $e);
            }
        }

        // A statement that ran yet left a column in another collation has not converted its table.
        foreach (self::pending(only: $outcome['converted']) as $table) {
            $outcome['converted'] = array_values(array_diff($outcome['converted'], [$table]));
            $outcome['failed'][$table] = ['error' => 'a column is still in another collation after its statement', 'index' => '', 'columns' => [], 'rows' => []];
        }

        return $outcome;
    }

    /**
     * Say what {@see self::converge()} did: the tables converted, and each one left with its reason,
     * in the messages of the installation and one setup log line per table; and keep a notice that
     * names the tables left until none is, then clear it. The tables of others are named in the
     * setup log only, once ({@see self::noteOthers()}).
     *
     * @param array $outcome What {@see self::converge()} returned.
     *
     * @since  2.1.1
     */
    public static function reportOutcome(array $outcome): void
    {
        self::noteOthers(db: self::db(), tables: $outcome['others']);
        $app = Factory::getApplication();
        // Read again: during an update the language file read earlier in the request is the previous version's.
        $app->getLanguage()->load('com_alfa', JPATH_ADMINISTRATOR, null, true);
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($outcome['converted'] !== []) {
            $app->enqueueMessage(self::say(
                key:      'COM_ALFA_COLLATION_CONVERTED',
                fallback: 'Alfa Commerce converted %1$d table(s) to the collation utf8mb4_unicode_ci: %2$s.',
                values:   [\count($outcome['converted']), implode(', ', $outcome['converted'])],
            ));
        }

        $left = [];
        // The same lines as the notice says them, in keys: each reader's language, no markup.
        $notice = [];

        foreach ($outcome['failed'] as $table => $failure) {
            $line = self::say(
                key:      'COM_ALFA_COLLATION_FAILED',
                fallback: 'Alfa Commerce could not convert the table %1$s to the collation utf8mb4_unicode_ci. The database said: %2$s',
                values:   [$table, $escape(rtrim($failure['error'], '. ') . '.')],
            );
            $logged = 'The table ' . $table . ' was not converted to ' . self::COLLATION . ': ' . $failure['error'];

            if ($failure['index'] !== '') {
                $rows = $failure['rows'] !== [] ? implode('; ', $failure['rows']) : '-';
                $line .= ' ' . self::say(
                    key:      'COM_ALFA_COLLATION_FAILED_KEY',
                    fallback: 'Its unique index %1$s, on %2$s, holds values that this collation reads as one (such as ss and the sharp s),'
                        . ' in the rows with ids %3$s: make each of them distinct.',
                    values:   [$escape($failure['index']), $escape(implode(', ', $failure['columns'])), $rows],
                );
                $logged .= ' (unique index ' . $failure['index'] . ' on ' . implode(', ', $failure['columns']) . ', rows ' . $rows . ')';
            }

            $said = NotificationText::key('COM_ALFA_COLLATION_FAILED', $table, rtrim($failure['error'], '. ') . '.');
            $notice[] = $failure['index'] === ''
                ? $said
                : NotificationText::join(items: [
                    $said,
                    NotificationText::key('COM_ALFA_COLLATION_FAILED_KEY', $failure['index'], implode(', ', $failure['columns']), $failure['rows'] !== [] ? implode('; ', $failure['rows']) : '-'),
                ]);
            $left[] = $line;
            Log::add($logged, Log::WARNING, 'com_alfa.setup');
        }

        foreach ($outcome['skipped'] as $table) {
            $left[] = self::say(
                key:      'COM_ALFA_COLLATION_SKIPPED',
                fallback: 'Alfa Commerce did not convert the table %1$s: it holds columns compared byte for byte, which converting the whole'
                    . ' table would change. Convert its other text columns to utf8mb4_unicode_ci by hand.',
                values:   [$table],
            );
            $notice[] = NotificationText::key('COM_ALFA_COLLATION_SKIPPED', $table);
            Log::add('The table ' . $table . ' was not converted to ' . self::COLLATION . ': it holds columns compared byte for byte.', Log::WARNING, 'com_alfa.setup');
        }

        if ($left === []) {
            if (NotificationHelper::isOpen(dedupKey: self::KEY)) {
                NotificationHelper::clear(dedupKey: self::KEY);
            }

            return;
        }

        $howto = self::say(
            key:      'COM_ALFA_COLLATION_HOWTO',
            fallback: 'When the cause is corrected, install the Alfa Commerce package again (System -> Install -> Extensions):'
                . ' the installation converts the tables that are left.',
        );

        foreach ($left as $line) {
            $app->enqueueMessage($line, 'warning');
        }

        $app->enqueueMessage($howto, 'warning');

        // It stands until an installation converts what is left; raised again as new by every run that leaves some.
        NotificationHelper::push(
            dedupKey: self::KEY,
            title:    NotificationText::of('COM_ALFA_NOTIFY_COLLATION_TITLE'),
            options:  [
                'group' => NotificationHelper::GROUP_SETUP,
                'severity' => 'warning',
                'message' => NotificationText::sentences(...[...$notice, NotificationText::key('COM_ALFA_COLLATION_HOWTO')]),
                'url' => 'index.php?option=com_installer&view=install',
                'dismissible' => false,
                'constant' => true,
                'link' => ['action' => 'core.manage', 'asset' => 'com_installer'],
            ],
        );
    }

    /**
     * Raise or clear the notice from the bell's background refresh, at most once in
     * {@see self::NOTICE_CHECK_MINUTES} minutes for the whole site.
     *
     * An update that dies before its last step (a time limit, a lost connection) never reaches
     * the installer's own check, and Joomla's database view cannot see a collation. This look
     * names the tables such an update left, and clears the notice once none is left. It never
     * converts a table: only an installation does.
     *
     * @since  2.1.1
     */
    public static function syncNotice(): void
    {
        try {
            Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', [
                    'defaultgroup' => 'com_alfa.collation',
                    'lifetime' => self::NOTICE_CHECK_MINUTES,
                    'caching' => true,
                ])
                ->get([self::class, 'doSyncNotice'], [], 'sync');
        } catch (Throwable) {
            // Cache unavailable: just look; correct, only less throttled.
            self::doSyncNotice();
        }
    }

    /**
     * The look behind {@see self::syncNotice()}: core's tables left, raised in a notice when none is
     * standing (a standing one, written by an installation, keeps its fuller account), cleared
     * when no table is left; the tables of others named once in the setup log.
     *
     * @return bool Always true, for the cache callback.
     *
     * @since  2.1.1
     */
    public static function doSyncNotice(): bool
    {
        try {
            $db = self::db();
            ['own' => $pending, 'others' => $others] = self::look(db: $db, only: null);
            self::noteOthers(db: $db, tables: $others);

            if ($pending === []) {
                if (NotificationHelper::isOpen(dedupKey: self::KEY)) {
                    NotificationHelper::clear(dedupKey: self::KEY);
                }

                return true;
            }

            if (NotificationHelper::isOpen(dedupKey: self::KEY)) {
                return true;
            }

            NotificationHelper::push(
                dedupKey: self::KEY,
                title:    NotificationText::of('COM_ALFA_NOTIFY_COLLATION_TITLE'),
                options:  [
                    'group' => NotificationHelper::GROUP_SETUP,
                    'severity' => 'warning',
                    'message' => NotificationText::sentences(
                        NotificationText::key('COM_ALFA_COLLATION_PENDING', implode(', ', $pending)),
                        NotificationText::key('COM_ALFA_COLLATION_HOWTO'),
                    ),
                    'url' => 'index.php?option=com_installer&view=install',
                    'dismissible' => false,
                    'constant' => true,
                    'link' => ['action' => 'core.manage', 'asset' => 'com_installer'],
                ],
            );
            Log::add('Alfa tables not in ' . self::COLLATION . ' after an update: ' . implode(', ', $pending) . '.', Log::WARNING, 'com_alfa.setup');
        } catch (Throwable $e) {
            Log::add('The collation look of the Alfa tables failed: ' . $e->getMessage(), Log::WARNING, 'com_alfa.setup');
        }

        return true;
    }

    /**
     * Name in the setup log, once for good, the tables named like Alfa's that core does not own and
     * that are not in the collation. Core converts none of them and no notice names them: nothing in
     * Alfa could clear it, each belongs to the extension or the person that made it. The names said
     * are kept in `#__alfa_meta`, and only the request that records a name says it, so two requests
     * that look at once say it once. When the row cannot be kept, nothing is said.
     *
     * @param DatabaseDriver $db The connection.
     * @param string[] $tables The tables, prefix resolved.
     *
     * @since  2.1.1
     */
    private static function noteOthers(DatabaseDriver $db, array $tables): void
    {
        if ($tables === []) {
            return;
        }

        try {
            $meta = $db->quoteName('#__alfa_meta');
            $value = $db->quoteName('value');
            $where = ' WHERE ' . $db->quoteName('name') . ' = ' . $db->quote(self::OTHERS_SAID);
            $db->setQuery('INSERT IGNORE INTO ' . $meta . ' (' . $db->quoteName('name') . ', ' . $value . ')'
                . ' VALUES (' . $db->quote(self::OTHERS_SAID) . ', ' . $db->quote('[]') . ')')->execute();
            $before = (string) $db->setQuery('SELECT ' . $value . ' FROM ' . $meta . $where)->loadResult();
            $said = json_decode($before, true);
            $said = \is_array($said) ? array_map('strval', $said) : [];
            $new = array_values(array_diff($tables, $said));

            if ($new === []) {
                return;
            }

            $db->setQuery('UPDATE ' . $meta . ' SET ' . $value . ' = ' . $db->quote((string) json_encode([...$said, ...$new]))
                . $where . ' AND ' . $value . ' = ' . $db->quote($before))->execute();

            if ($db->getAffectedRows() < 1) {
                return;
            }

            // Recorded as said, so the line must land: a request that has not booted the component has no setup log yet.
            AlfaLog::register();
            Log::add(
                'Not converted to ' . self::COLLATION . ', because Alfa Commerce core does not own them: ' . implode(', ', $new)
                . '. Each belongs to the extension that made it, or was left by an earlier version or by hand.',
                Log::NOTICE,
                'com_alfa.setup',
            );
        } catch (Throwable) {
            // Not kept, so not said: a table is never named twice.
        }
    }

    /**
     * A text of the language files, or its English when the files at hand do not have it yet.
     *
     * @param string $key The language key.
     * @param string $fallback Its English, with the same placeholders.
     * @param array $values The placeholders' values.
     *
     * @since  2.1.1
     */
    private static function say(string $key, string $fallback, array $values = []): string
    {
        $text = Text::_($key);

        return vsprintf($text === $key ? $fallback : $text, $values);
    }

    /**
     * The update file's statement for each table it converts, prefix resolved, in its order.
     *
     * @param DatabaseDriver $db The connection.
     *
     * @return array<string, string> Table name => statement; none when the file cannot be read.
     *
     * @since  2.1.1
     */
    private static function statements(DatabaseDriver $db): array
    {
        static $statements = null;

        if ($statements !== null) {
            return $statements;
        }

        $statements = [];
        $buffer = is_file(JPATH_ADMINISTRATOR . self::STATEMENTS_FILE) ? (string) file_get_contents(JPATH_ADMINISTRATOR . self::STATEMENTS_FILE) : '';

        foreach (Installer::splitSql($buffer) as $query) {
            $query = (string) preg_replace('#\s*/\*\* CAN FAIL \*\*/\s*;?\s*$#', '', trim((string) $query));

            if (preg_match('/^ALTER TABLE `(#__alfa_[a-z0-9_]+)`/i', $query, $match)) {
                $statements[$db->replacePrefix($match[1])] = $db->replacePrefix(rtrim($query, "; \t\n\r"));
            }
        }

        return $statements;
    }

    /**
     * The columns of a table compared byte for byte (a binary collation, or the ascii or binary set).
     *
     * @param DatabaseDriver $db The connection.
     * @param string $table The table, prefix resolved.
     *
     * @return string[] The column names.
     *
     * @since  2.1.1
     */
    private static function byteColumns(DatabaseDriver $db, string $table): array
    {
        return array_map('strval', (array) $db->setQuery(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote($table)
            . " AND (RIGHT(COLLATION_NAME, 4) = '_bin' OR CHARACTER_SET_NAME IN ('ascii', 'binary'))",
        )->loadColumn());
    }

    /**
     * Why a table did not convert: the database's error and, for a duplicate key, the unique index,
     * its columns and the rows it would read as one (at most five groups, by primary key).
     *
     * @param DatabaseDriver $db The connection.
     * @param string $table The table, prefix resolved.
     * @param Throwable $error What the conversion threw.
     *
     * @return array{error: string, index: string, columns: string[], rows: string[]}
     *
     * @since  2.1.1
     */
    private static function diagnose(DatabaseDriver $db, string $table, Throwable $error): array
    {
        $failure = ['error' => trim($error->getMessage()), 'index' => '', 'columns' => [], 'rows' => []];

        if (!preg_match("/for key '(?:[^']*\\.)?([^']+)'/", $failure['error'], $match)) {
            return $failure;
        }

        $failure['index'] = $match[1];

        try {
            $columnsOf = static fn (string $index): array => array_map('strval', (array) $db->setQuery(
                'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $db->quote($table)
                . ' AND INDEX_NAME = ' . $db->quote($index) . ' ORDER BY SEQ_IN_INDEX',
            )->loadColumn());
            $failure['columns'] = $columnsOf($failure['index']);
            $primary = $columnsOf('PRIMARY');

            if (\count($failure['columns']) === 1 && \count($primary) === 1) {
                $key = $db->quoteName($primary[0]);
                $failure['rows'] = array_map('strval', (array) $db->setQuery(
                    'SELECT GROUP_CONCAT(' . $key . ' ORDER BY ' . $key . " SEPARATOR ', ') FROM " . $db->quoteName($table)
                    . ' GROUP BY CONVERT(' . $db->quoteName($failure['columns'][0]) . ' USING utf8mb4) COLLATE ' . self::COLLATION
                    . ' HAVING COUNT(*) > 1',
                    0,
                    5,
                )->loadColumn());
            }
        } catch (Throwable) {
            // The error itself is said all the same.
        }

        return $failure;
    }

    /**
     * The site's database connection.
     *
     * @since  2.1.1
     */
    private static function db(): DatabaseDriver
    {
        return Factory::getContainer()->get('DatabaseDriver');
    }
}
