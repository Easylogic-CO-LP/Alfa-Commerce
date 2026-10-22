<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Setup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

/**
 * The names the installation seeds, given to every content language.
 *
 * The install SQL seeds the order statuses and the identity fields with their names in en-GB only. A screen reads a
 * translatable name from the language tables of the language it is shown in, so an administrator working in Greek
 * read those names as empty. Each language table of a seeded record therefore gets the record's names, the same as
 * its English: while the en-GB value is still the seed's English, Alfa's own words for that language where its
 * language file has them, else that English ({@see MultilingualHelper::firstTranslated()}, the chain the default
 * e-mail and document texts use); once the shop changed the English, the shop's English as it is now.
 *
 * ONLY WHERE NOTHING OF THE SHOP'S IS. A value is written only where it is empty, its row is missing, or it is exactly
 * the seed's English: that is the text this fill gives a language Alfa had no words for yet (its language file comes
 * with a later Alfa install or update), never one the shop typed, so it gives way to Alfa's own words once they are
 * there. Any other text is the shop's and is never touched; the write checks that again itself, so a name saved in
 * the meantime is kept too.
 *
 * A MISSING STATUS ROW STAYS MISSING while a language row of that status holds e-mail content. The order e-mail reads
 * a status's language row whole and falls back to another language's row only while that row is missing
 * ({@see \Alfa\Component\Alfa\Administrator\Service\Order\EmailService}): making the row would stop that e-mail. The
 * status keeps an empty name in that language until the shop saves it.
 *
 * Run by the language sync ({@see \Alfa\Component\Alfa\Administrator\Helper\SyncHelper::syncLanguageSchema()}: the
 * installation, an update, Clear everything, a content language saved, Resync languages) and for a language table a
 * save creates ({@see MultilingualHelper::ensureLangSchemaForData()}).
 *
 * @since  2.1.0
 */
final class SeededNames
{
    /**
     * The language the install SQL writes the seeded names in: its value says whether a field is still the seed.
     *
     * @since  2.1.0
     */
    public const SEED_LANGUAGE = 'en-GB';

    /**
     * Every seeded record with a translatable name, per base table: the key column of its language tables, the column
     * the record is found by (a status by its id, an identity field by its role), the language key of each seeded field
     * per record, and the language-row columns whose content keeps a missing row missing.
     *
     * The en-GB text of each key is the install SQL's text of that field (tests/helpers/seeded-names-suite.php).
     *
     * @since  2.1.0
     */
    public const TABLES = [
        '#__alfa_orders_statuses' => [
            'pk' => 'id_orderstatus',
            'match' => 'id',
            'rows' => [
                1 => ['name' => 'COM_ALFA_ORDERSTATUS_SEED_PENDING', 'name_customer' => 'COM_ALFA_ORDERSTATUS_SEED_PENDING'],
                2 => ['name' => 'COM_ALFA_ORDERSTATUS_SEED_PROCESSING', 'name_customer' => 'COM_ALFA_ORDERSTATUS_SEED_PROCESSING'],
                3 => ['name' => 'COM_ALFA_ORDERSTATUS_SEED_SHIPPED', 'name_customer' => 'COM_ALFA_ORDERSTATUS_SEED_SHIPPED'],
                4 => ['name' => 'COM_ALFA_ORDERSTATUS_SEED_DELIVERED', 'name_customer' => 'COM_ALFA_ORDERSTATUS_SEED_DELIVERED'],
                5 => ['name' => 'COM_ALFA_ORDERSTATUS_SEED_CANCELLED', 'name_customer' => 'COM_ALFA_ORDERSTATUS_SEED_CANCELLED'],
                6 => ['name' => 'COM_ALFA_ORDERSTATUS_SEED_REFUNDED', 'name_customer' => 'COM_ALFA_ORDERSTATUS_SEED_REFUNDED'],
            ],
            'keepMissingWhenSet' => ['email_positions_customer', 'email_positions_admin'],
        ],
        '#__alfa_form_fields' => [
            'pk' => 'id_formfield',
            'match' => 'role',
            'rows' => [
                'name' => ['name' => 'COM_ALFA_SYSTEM_FIELD_NAME', 'field_label' => 'COM_ALFA_SYSTEM_FIELD_NAME'],
                'email' => ['name' => 'COM_ALFA_SYSTEM_FIELD_EMAIL', 'field_label' => 'COM_ALFA_SYSTEM_FIELD_EMAIL'],
                'phone' => ['name' => 'COM_ALFA_SYSTEM_FIELD_PHONE', 'field_label' => 'COM_ALFA_SYSTEM_FIELD_PHONE'],
                'country' => ['name' => 'COM_ALFA_SYSTEM_FIELD_COUNTRY', 'field_label' => 'COM_ALFA_SYSTEM_FIELD_COUNTRY'],
            ],
            'keepMissingWhenSet' => [],
        ],
    ];

    /**
     * @param DatabaseInterface $db The connection the names are written through.
     *
     * @since  2.1.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Give the seeded records their names in every content language whose language table exists.
     *
     * The content languages are the ones published now (MultilingualHelper::publishedLanguages()), so a
     * language published earlier in the same request gets its names with its tables.
     *
     * Never throws: a base table that cannot be completed is named in the result with the reason.
     *
     * @param string $table One base table (e.g. "#__alfa_orders_statuses"), or '' for every one.
     * @param string $language One content language (a tag or a table suffix: "el-GR", "el_gr"), or '' for every one.
     *
     * @return array{written: int, errors: array<string, string>} The rows written; per base table, why it was not done.
     *
     * @since  2.1.0
     */
    public function fill(string $table = '', string $language = ''): array
    {
        $result = ['written' => 0, 'errors' => []];

        try {
            $languages = [];

            foreach (MultilingualHelper::publishedLanguages() as $tag => $suffix) {
                $languages[$suffix] = $tag;
            }

            $existing = array_flip($this->db->getTableList());
        } catch (Throwable $e) {
            $result['errors'][$table !== '' ? $table : 'content languages'] = $e->getMessage();

            return $result;
        }

        $only = $language === '' ? '' : MultilingualHelper::normaliseTag(tag: $language);

        foreach (self::TABLES as $base => $definition) {
            if ($table !== '' && $table !== $base) {
                continue;
            }

            try {
                $result['written'] += $this->fillTable(
                    base: $base,
                    definition: $definition,
                    languages: $languages,
                    only: $only,
                    existing: $existing,
                );
            } catch (Throwable $e) {
                $result['errors'][$base] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Complete one base table's seeded records in the content languages asked for.
     *
     * @param string $base The base table.
     * @param array $definition Its entry in {@see self::TABLES}.
     * @param array<string, string> $languages Every content language, table suffix => tag.
     * @param string $only The one table suffix to write, or '' for every content language.
     * @param array<string, int> $existing The database's tables, real name => any.
     *
     * @return int The language rows written.
     *
     * @since  2.1.0
     */
    private function fillTable(string $base, array $definition, array $languages, string $only, array $existing): int
    {
        $db = $this->db;
        $pk = $definition['pk'];
        $seedCode = MultilingualHelper::normaliseTag(tag: self::SEED_LANGUAGE);
        $seedTable = $db->replacePrefix($base . '_' . $seedCode);

        if (!isset($existing[$seedTable])) {
            return 0;
        }

        $ids = $this->recordIds(base: $base, definition: $definition);
        $seedRows = $this->rows(table: $seedTable, pk: $pk, ids: array_values($ids));

        // Per record id and field: its language key, the seed's English, and the English it holds now.
        $named = [];

        foreach ($definition['rows'] as $record => $fields) {
            $id = $ids[$record] ?? null;

            if ($id === null) {
                continue;
            }

            foreach ($fields as $field => $key) {
                $english = (string) ($seedRows[$id][$field] ?? '');

                if ($english !== '') {
                    $named[$id][$field] = [
                        'key' => $key,
                        'seed' => MultilingualHelper::firstTranslated(key: $key, langTag: self::SEED_LANGUAGE),
                        'english' => $english,
                    ];
                }
            }
        }

        if ($named === []) {
            return 0;
        }

        $kept = $this->rowsHolding(
            base: $base,
            definition: $definition,
            codes: array_keys([$seedCode => true] + $languages),
            ids: array_keys($named),
            existing: $existing,
        );
        $written = 0;

        foreach ($languages as $code => $tag) {
            $languageTable = $db->replacePrefix($base . '_' . $code);

            if ($code === $seedCode || ($only !== '' && $code !== $only) || !isset($existing[$languageTable])) {
                continue;
            }

            $columns = array_keys($db->getTableColumns($languageTable));
            $rows = $this->rows(table: $languageTable, pk: $pk, ids: array_keys($named));

            foreach ($named as $id => $fields) {
                if (!isset($rows[$id]) && isset($kept[$id])) {
                    continue;
                }

                $values = [];
                $seeds = [];

                foreach ($fields as $field => $name) {
                    $current = (string) ($rows[$id][$field] ?? '');

                    if (!\in_array($field, $columns, true) || ($current !== '' && $current !== $name['seed'])) {
                        continue;
                    }

                    $text = $name['english'] === $name['seed']
                        ? MultilingualHelper::firstTranslated(key: $name['key'], langTag: $tag)
                        : $name['english'];

                    if ($text !== '' && $text !== $current) {
                        $values[$field] = $text;
                        $seeds[$field] = $name['seed'];
                    }
                }

                if ($values !== []) {
                    $this->writeUnfilled(table: $languageTable, pk: $pk, id: $id, values: $values, seeds: $seeds);
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * The id of each seeded record the base table still has, by its key in {@see self::TABLES}.
     *
     * @param string $base The base table.
     * @param array $definition Its entry in {@see self::TABLES}.
     *
     * @return array<int|string, int>
     *
     * @since  2.1.0
     */
    private function recordIds(string $base, array $definition): array
    {
        $db = $this->db;
        $keys = array_keys($definition['rows']);
        $column = (string) $definition['match'];
        $query = $db->createQuery()
            ->select([$db->quoteName('id'), $db->quoteName($column, 'k')])
            ->from($db->quoteName($base));

        if ($column === 'id') {
            $query->whereIn($db->quoteName('id'), array_map('intval', $keys));
        } else {
            $query->whereIn($db->quoteName($column), array_map('strval', $keys), ParameterType::STRING);
        }

        $found = $db->setQuery($query)->loadAssocList('k', 'id') ?: [];
        $ids = [];

        foreach ($keys as $key) {
            if (isset($found[(string) $key])) {
                $ids[$key] = (int) $found[(string) $key];
            }
        }

        return $ids;
    }

    /**
     * The rows of one language table for the given records, by record id.
     *
     * @param string $table The language table (real name).
     * @param string $pk Its key column.
     * @param int[] $ids The record ids.
     *
     * @return array<int, array<string, mixed>>
     *
     * @since  2.1.0
     */
    private function rows(string $table, string $pk, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $db = $this->db;

        return $db->setQuery(
            $db->createQuery()
                ->select('*')
                ->from($db->quoteName($table))
                ->whereIn($db->quoteName($pk), array_map('intval', $ids)),
        )->loadAssocList($pk) ?: [];
    }

    /**
     * The records one of whose language rows (the seed language's or a content language's) holds content in a
     * column that keeps a missing row missing.
     *
     * @param string $base The base table.
     * @param array $definition Its entry in {@see self::TABLES}.
     * @param string[] $codes The table suffixes to read.
     * @param int[] $ids The record ids.
     * @param array<string, int> $existing The database's tables, real name => any.
     *
     * @return array<int, true>
     *
     * @since  2.1.0
     */
    private function rowsHolding(string $base, array $definition, array $codes, array $ids, array $existing): array
    {
        $db = $this->db;
        $held = [];

        if ($definition['keepMissingWhenSet'] === []) {
            return $held;
        }

        foreach ($codes as $code) {
            $table = $db->replacePrefix($base . '_' . $code);

            if (!isset($existing[$table])) {
                continue;
            }

            $columns = array_intersect($definition['keepMissingWhenSet'], array_keys($db->getTableColumns($table)));

            if ($columns === []) {
                continue;
            }

            $query = $db->createQuery()
                ->select($db->quoteName($definition['pk']))
                ->from($db->quoteName($table))
                ->whereIn($db->quoteName($definition['pk']), array_map('intval', $ids))
                ->extendWhere('AND', array_map(
                    static fn (string $column): string => 'COALESCE(' . $db->quoteName($column) . ", '') <> ''",
                    array_values($columns),
                ), 'OR');

            foreach ($db->setQuery($query)->loadColumn() ?: [] as $id) {
                $held[(int) $id] = true;
            }
        }

        return $held;
    }

    /**
     * Write the given names into one language row, the row made when missing. A column keeps what it holds unless that
     * is empty or exactly the seed's English (compared byte for byte: "PENDING" is the shop's, not the seed).
     *
     * @param string $table The language table (real name).
     * @param string $pk Its key column.
     * @param int $id The record id.
     * @param array<string, string> $values Column => name.
     * @param array<string, string> $seeds Column => the seed's English.
     *
     * @since  2.1.0
     */
    private function writeUnfilled(string $table, string $pk, int $id, array $values, array $seeds): void
    {
        $db = $this->db;
        $columns = [$db->quoteName($pk)];
        $inserted = [(string) $id];
        $kept = [];

        foreach ($values as $column => $value) {
            $name = $db->quoteName($column);
            $columns[] = $name;
            $inserted[] = $db->quote($value);
            $kept[] = $name . ' = IF(COALESCE(' . $name . ", '') = '' OR CAST(" . $name . ' AS BINARY) = CAST('
                . $db->quote((string) ($seeds[$column] ?? '')) . ' AS BINARY), VALUES(' . $name . '), ' . $name . ')';
        }

        $db->setQuery(
            'INSERT INTO ' . $db->quoteName($table) . ' (' . implode(', ', $columns) . ')'
            . ' VALUES (' . implode(', ', $inserted) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $kept),
        )->execute();
    }
}
