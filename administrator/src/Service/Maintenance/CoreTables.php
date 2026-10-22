<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Maintenance;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\SyncHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginLogTable;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * The tables Alfa Commerce core owns, as a declared set: Clear everything drops and recreates these
 * and no other. Core never names, counts or touches an extension's tables; each extension clears its
 * own through the Clear Data contract.
 *
 * THE DECLARED SET, found again whenever it is asked:
 *   - the tables the installed install SQL creates: the component's, and that of every extension the
 *     installed component manifest bundles and Joomla records as installed ({@see SchemaRecreator});
 *   - the log table of every bundled payment or shipment plugin (PluginLogTable::nameOf());
 *   - the per-language tables of core's translatable tables (SyncHelper::languageSchema()): a table
 *     `<base>_<suffix>` whose suffix has the shape of a Joomla language tag (`en_gb`, `el_gr`) and
 *     whose primary key is exactly the base table's translation key. The key keeps an extension's
 *     table out (a gateway's log `#__alfa_payments_<x>_logs` is keyed on `id`, the payment methods on
 *     `id_payment`), and the shape keeps the tables of a language removed since in (they are dropped
 *     and not made again);
 *   - minus {@see self::KEEP}: the durable state, never dropped.
 *
 * @since  2.1.0
 */
final class CoreTables
{
    /**
     * Core tables Clear everything never drops: the durable state of Clear everything itself.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    public const KEEP = ['#__alfa_maintenance'];

    /**
     * The shape of a language suffix: a Joomla language tag as core names its tables by it
     * (MultilingualHelper::normaliseTag(): lower case, underscore).
     *
     * @var string
     *
     * @since  2.1.0
     */
    private const LANGUAGE_SUFFIX = '/^[a-z]{2,3}(_[a-z0-9]{2,4})?$/';

    /**
     * Tables every Joomla site has and Alfa never makes. One of them named `<prefix>alfa_...` means a
     * second site shares this database under a prefix that begins like core's tables.
     *
     * @var string[]
     *
     * @since  2.1.0
     */
    private const FOREIGN_SITE_MARKERS = ['extensions', 'assets', 'session', 'schemas', 'viewlevels'];

    /**
     * @param DatabaseInterface $db The site's connection.
     *
     * @since  2.1.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Every core table that exists now and Clear everything would drop, by name, with its rows when
     * asked for.
     *
     * @param bool $count Whether to count the rows (an exact count, table by table).
     *
     * @return array<string, int|null> Table name => rows (null when not counted), by name.
     *
     * @since  2.1.0
     */
    public function declared(bool $count = true): array
    {
        $existing = $this->existing();
        $found = [];

        foreach ([...$this->sqlTables(), ...$this->logTables()] as $table) {
            if (isset($existing[strtolower($table)])) {
                $found[$existing[strtolower($table)]] = null;
            }
        }

        foreach ($this->languageTables(existing: $existing) as $table) {
            $found[$table] = null;
        }

        foreach (self::KEEP as $keep) {
            unset($found[$existing[strtolower($this->db->replacePrefix($keep))] ?? '']);
        }

        ksort($found);

        if ($count) {
            foreach (array_keys($found) as $table) {
                $found[$table] = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName($table))->loadResult();
            }
        }

        return $found;
    }

    /**
     * The tables Clear everything drops now: {@see self::declared()} without counting.
     *
     * @return list<string>
     *
     * @since  2.1.0
     */
    public function dropSet(): array
    {
        return array_keys($this->declared(count: false));
    }

    /**
     * The tables a fresh install of this site makes: the install SQL's, and the per-language tables
     * of the content languages the site has now.
     *
     * @return list<string> Lower-case names.
     *
     * @since  2.1.0
     */
    public function freshSet(): array
    {
        $fresh = array_map('strtolower', $this->sqlTables());
        $languages = array_map(
            static fn (string $code): string => strtolower(str_replace('-', '_', $code)),
            array_keys(LanguageHelper::getLanguages('lang_code')),
        );

        foreach (array_keys(SyncHelper::languageSchema()) as $base) {
            foreach ($languages as $language) {
                $fresh[] = strtolower($this->db->replacePrefix($base . '_' . $language));
            }
        }

        return array_values(array_unique($fresh));
    }

    /**
     * The tables the installed install SQL creates (the component's and the installed bundled
     * extensions'), with this site's prefix.
     *
     * @return list<string>
     *
     * @throws RuntimeException When a planned install SQL cannot be read.
     *
     * @since  2.1.0
     */
    public function sqlTables(): array
    {
        $tables = [];

        foreach ((new SchemaRecreator(db: $this->db))->plan() as $entry) {
            if (!$entry['run']) {
                throw new RuntimeException($entry['extension'] . ': ' . $entry['reason']);
            }

            foreach ($entry['files'] as $file) {
                foreach (SchemaRecreator::createdTables(sql: (string) file_get_contents($file)) as $table) {
                    $tables[] = $this->db->replacePrefix($table);
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * The log tables of the bundled payment and shipment plugins, with this site's prefix.
     *
     * @return list<string>
     *
     * @since  2.1.0
     */
    public function logTables(): array
    {
        $tables = [];

        foreach (SchemaRecreator::bundled() as $extension) {
            if ($extension['type'] === 'plugin' && \in_array($extension['folder'], ['alfa-payments', 'alfa-shipments'], true)) {
                $tables[] = $this->db->replacePrefix(PluginLogTable::nameOf(type: $extension['folder'], name: $extension['element']));
            }
        }

        return $tables;
    }

    /**
     * Why Clear everything may not run on this database, or null when it may: the tables of a second
     * site whose prefix is `<prefix>alfa_...` (its `<prefix>alfa_extensions`, or
     * `<prefix>alfa_<x>_extensions` for a longer prefix), which share their names' beginning with
     * core's tables. Read only.
     *
     * @since  2.1.0
     */
    public function foreignSite(): ?string
    {
        $start = strtolower($this->db->getPrefix() . 'alfa_');
        $found = [];

        foreach ($this->existing() as $lower => $table) {
            if (!str_starts_with($lower, $start)) {
                continue;
            }

            $rest = substr($lower, \strlen($start));

            foreach (self::FOREIGN_SITE_MARKERS as $marker) {
                if ($rest === $marker || str_ends_with($rest, '_' . $marker)) {
                    $found[] = $table;
                }
            }
        }

        return $found === [] ? null : implode(', ', array_unique($found));
    }

    /**
     * The identifier floor of every table of a set that has an AUTO_INCREMENT column: the largest of
     * the floor recorded before, the column's highest value + 1 and the table's next value. A floor
     * never goes down.
     *
     * @param list<string> $tables The tables.
     * @param array<string, int> $recorded The floors recorded by an earlier pass, by table.
     *
     * @return array<string, int> Table => floor.
     *
     * @since  2.1.0
     */
    public function identifierFloors(array $tables, array $recorded = []): array
    {
        $db = $this->db;
        $this->freshStatistics();
        $want = array_flip(array_map('strtolower', $tables));
        $rows = $db->setQuery(
            'SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS col, s.AUTO_INCREMENT AS next'
            . ' FROM information_schema.COLUMNS c JOIN information_schema.TABLES s'
            . ' ON s.TABLE_SCHEMA = c.TABLE_SCHEMA AND s.TABLE_NAME = c.TABLE_NAME'
            . ' WHERE c.TABLE_SCHEMA = DATABASE() AND c.EXTRA LIKE ' . $db->quote('%auto_increment%'),
        )->loadAssocList() ?: [];
        $floors = [];

        foreach ($rows as $row) {
            $table = (string) $row['t'];

            if (!isset($want[strtolower($table)])) {
                continue;
            }

            $highest = (int) $db->setQuery('SELECT MAX(' . $db->quoteName((string) $row['col']) . ') FROM ' . $db->quoteName($table))->loadResult();
            $floors[$table] = max((int) ($recorded[$table] ?? 0), $highest + 1, (int) ($row['next'] ?? 0));
        }

        foreach ($recorded as $table => $floor) {
            $floors[$table] ??= (int) $floor;
        }

        ksort($floors);

        return $floors;
    }

    /**
     * Raise each table's next identifier to its floor. The server never sets it below the highest
     * value + 1, so seeded rows keep their ids.
     *
     * @param array<string, int> $floors Table => floor.
     *
     * @return int The tables raised.
     *
     * @throws RuntimeException When a table refuses it.
     *
     * @since  2.1.0
     */
    public function applyFloors(array $floors): int
    {
        $existing = $this->existing();
        $raised = 0;

        foreach ($floors as $table => $floor) {
            if (!isset($existing[strtolower($table)]) || $floor < 1) {
                continue;
            }

            try {
                $this->db->setQuery('ALTER TABLE ' . $this->db->quoteName($table) . ' AUTO_INCREMENT = ' . (int) $floor)->execute();
            } catch (Throwable $e) {
                throw new RuntimeException($table . ': ' . $e->getMessage(), 0, $e);
            }

            $raised++;
        }

        return $raised;
    }

    /**
     * The tables whose next identifier is below its floor now.
     *
     * @param array<string, int> $floors Table => floor.
     *
     * @return array<string, array{floor: int, next: int}>
     *
     * @since  2.1.0
     */
    public function floorsBelow(array $floors): array
    {
        $db = $this->db;
        $this->freshStatistics();
        $next = [];

        foreach ($db->setQuery(
            'SELECT TABLE_NAME AS t, AUTO_INCREMENT AS next FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        )->loadAssocList() ?: [] as $row) {
            $next[strtolower((string) $row['t'])] = (int) $row['next'];
        }

        $below = [];

        foreach ($floors as $table => $floor) {
            $now = $next[strtolower($table)] ?? null;

            if ($now !== null && $now < $floor) {
                $below[$table] = ['floor' => $floor, 'next' => $now];
            }
        }

        return $below;
    }

    /**
     * The base tables of this database, lower-case name => name.
     *
     * @return array<string, string>
     *
     * @since  2.1.0
     */
    public function existing(): array
    {
        $names = $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('TABLE_NAME') . ' FROM ' . $this->db->quoteName('information_schema.TABLES')
            . ' WHERE ' . $this->db->quoteName('TABLE_SCHEMA') . ' = DATABASE()'
            . ' AND ' . $this->db->quoteName('TABLE_TYPE') . ' = ' . $this->db->quote('BASE TABLE'),
        )->loadColumn() ?: [];
        $existing = [];

        foreach ($names as $name) {
            $existing[strtolower((string) $name)] = (string) $name;
        }

        return $existing;
    }

    /**
     * The per-language tables of core's translatable tables among the existing ones: the suffix has
     * the shape of a language tag and the primary key is the base table's translation key.
     *
     * @param array<string, string> $existing The base tables, lower-case name => name.
     *
     * @return list<string>
     *
     * @since  2.1.0
     */
    private function languageTables(array $existing): array
    {
        $keys = $this->primaryKeys();
        $found = [];

        foreach (SyncHelper::languageSchema() as $base => $definition) {
            $start = strtolower($this->db->replacePrefix($base)) . '_';

            foreach ($existing as $lower => $table) {
                if (!str_starts_with($lower, $start) || !preg_match(self::LANGUAGE_SUFFIX, substr($lower, \strlen($start)))) {
                    continue;
                }

                if (($keys[$lower] ?? []) === [strtolower((string) $definition['pk'])]) {
                    $found[] = $table;
                }
            }
        }

        return $found;
    }

    /**
     * The primary key columns of every table of this database, lower-case name => columns in order.
     *
     * @return array<string, list<string>>
     *
     * @since  2.1.0
     */
    private function primaryKeys(): array
    {
        $rows = $this->db->setQuery(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ' . $this->db->quote('PRIMARY')
            . ' ORDER BY TABLE_NAME, SEQ_IN_INDEX',
        )->loadAssocList() ?: [];
        $keys = [];

        foreach ($rows as $row) {
            $keys[strtolower((string) $row['t'])][] = strtolower((string) $row['c']);
        }

        return $keys;
    }

    /**
     * Ask MySQL 8 for live table statistics (it keeps them up to a day by default); MariaDB reads
     * them live and has no such setting.
     *
     * @since  2.1.0
     */
    private function freshStatistics(): void
    {
        try {
            $this->db->setQuery('SET SESSION information_schema_stats_expiry = 0')->execute();
        } catch (Throwable) {
            // MariaDB: the statistics are live already.
        }
    }
}
