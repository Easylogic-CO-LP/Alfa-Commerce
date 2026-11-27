<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * The sections of a product page the shop writes: the general sections of Options (product_sections, each with a
 * key, a title and a body per language, an ordering number and the categories it is limited to) and a product's own
 * sections (its Sections tab, rows of `#__alfa_item_sections` with their texts in the language tables
 * `#__alfa_item_sections_<lang>`, the way every translated record keeps its texts). A product's section that names a
 * general key replaces that general section on the product's page; one that names none is a section of its own. The
 * storefront reads both through {@see \Alfa\Component\Alfa\Site\Helper\PageSections}.
 *
 * The save asks {@see self::ensureSchema()} before its transaction (the language tables are DDL, which commits
 * implicitly) and writes {@see self::replace()} inside it; a deleted product's rows go with it
 * ({@see self::deleteFor()}).
 *
 * @since  2.3.0
 */
final class ItemSections
{
    /**
     * The table of a product's own sections.
     *
     * @since  2.3.0
     */
    public const TABLE = '#__alfa_item_sections';

    /**
     * The primary column of the language tables.
     *
     * @since  2.3.0
     */
    public const LANG_PK = 'id_item_section';

    /**
     * The translated columns, kept in the language tables.
     *
     * @var list<string>
     * @since  2.3.0
     */
    public const TEXTS = ['title', 'body'];

    /**
     * The general sections of Options, each with its title and body in the request's language, its ordering number
     * and the categories it is limited to ([] for every product), in the order they are listed. A row without a key
     * is no section.
     *
     * @param Registry $params The component's Options.
     *
     * @return list<array{key: string, title: string, body: string, ordering: int, categories: list<int>}>
     *
     * @since  2.3.0
     */
    public static function general(Registry $params): array
    {
        $out = [];

        foreach ((array) $params->get('product_sections', []) as $row) {
            $row = (array) $row;
            $key = self::key((string) ($row['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $out[] = [
                'key' => $key,
                'title' => MultilingualHelper::resolveLangMap($row['title'] ?? ''),
                'body' => MultilingualHelper::resolveLangMap($row['body'] ?? ''),
                'ordering' => (int) ($row['ordering'] ?? 0),
                'categories' => array_values(array_filter(array_map('intval', (array) ($row['categories'] ?? [])))),
            ];
        }

        return $out;
    }

    /**
     * A section key as the page uses it: lower-case letters, digits and hyphens, at most 64 characters; '' when
     * nothing of it is left.
     *
     * @param string $key The key as typed or stored.
     *
     * @since  2.3.0
     */
    public static function key(string $key): string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($key))), '-');

        return substr($key, 0, 64);
    }

    /**
     * A product's own sections as its Sections tab holds them: id, the general key it replaces ('' for none), its
     * position, and its title and body as {language: text} maps, in position order.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     *
     * @return list<array{id: int, section_key: string, position: int, title: array<string, string>, body: array<string, string>}>
     *
     * @since  2.3.0
     */
    public static function rows(DatabaseInterface $db, int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'section_key', 'position']))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('item_id') . ' = :item')
                ->order($db->quoteName('position') . ' ASC, ' . $db->quoteName('id') . ' ASC')
                ->bind(':item', $itemId, ParameterType::INTEGER),
        )->loadAssocList() ?: [];

        if ($rows === []) {
            return [];
        }

        $texts = MultilingualHelper::getMultilingualDataForIds(
            ids:               array_map('intval', array_column($rows, 'id')),
            primaryColumnName: self::LANG_PK,
            tableName:         self::TABLE,
        );
        $out = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $entry = ['id' => $id, 'section_key' => (string) $row['section_key'], 'position' => (int) $row['position'], 'title' => [], 'body' => []];

            foreach ($texts[$id] ?? [] as $lang => $columns) {
                $entry['title'][$lang] = (string) ($columns['title'] ?? '');
                $entry['body'][$lang] = (string) ($columns['body'] ?? '');
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * The rows a save carries, as the Sections tab posts them (a subform's rows, each with id, section_key,
     * position and the {language: text} maps title and body). A row naming no general key with no text in any
     * language is no section and is left out.
     *
     * @param mixed $posted The save's `sections` value.
     *
     * @return list<array{id: int, section_key: string, position: int, title: array<string, string>, body: array<string, string>}>
     *
     * @since  2.3.0
     */
    public static function posted(mixed $posted): array
    {
        $out = [];

        foreach ((array) $posted as $row) {
            $row = (array) $row;
            $key = self::key((string) ($row['section_key'] ?? ''));
            $title = self::texts($row['title'] ?? []);
            $body = self::texts($row['body'] ?? []);

            if ($key === '' && trim(implode('', $title)) === '' && trim(strip_tags(implode('', $body))) === '') {
                continue;
            }

            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'section_key' => $key,
                'position' => (int) ($row['position'] ?? 0),
                'title' => $title,
                'body' => $body,
            ];
        }

        return $out;
    }

    /**
     * The language tables and columns of the product sections, made before a save's transaction opens: DDL commits
     * implicitly, so nothing of it may run inside one.
     *
     * @since  2.3.0
     */
    public static function ensureSchema(): void
    {
        MultilingualHelper::ensureLangSchema(tableName: self::TABLE, primaryColumnName: self::LANG_PK, fields: self::TEXTS);
    }

    /**
     * A product's own sections become the rows given: a row with the id of one of the product's sections changes it,
     * any other row is a new section, and a section no row names is removed with its texts. Inside the save's
     * transaction, after {@see self::ensureSchema()}.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     * @param list<array{id: int, section_key: string, position: int, title: array<string, string>, body: array<string, string>}> $rows The sections, as {@see self::posted()} gives them.
     *
     * @since  2.3.0
     */
    public static function replace(DatabaseInterface $db, int $itemId, array $rows): void
    {
        if ($itemId <= 0) {
            return;
        }

        $existing = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::TABLE))
                ->where($db->quoteName('item_id') . ' = :item')
                ->bind(':item', $itemId, ParameterType::INTEGER),
        )->loadColumn() ?: []);
        $kept = [];

        foreach ($rows as $row) {
            $id = \in_array((int) $row['id'], $existing, true) ? (int) $row['id'] : 0;
            $key = (string) $row['section_key'];
            $position = (int) $row['position'];

            if ($id > 0) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName(self::TABLE))
                        ->set($db->quoteName('section_key') . ' = :key')
                        ->set($db->quoteName('position') . ' = :position')
                        ->where($db->quoteName('id') . ' = :id')
                        ->bind(':key', $key)
                        ->bind(':position', $position, ParameterType::INTEGER)
                        ->bind(':id', $id, ParameterType::INTEGER),
                )->execute();
            } else {
                $db->setQuery(
                    $db->getQuery(true)
                        ->insert($db->quoteName(self::TABLE))
                        ->columns($db->quoteName(['item_id', 'section_key', 'position']))
                        ->values(':item, :key, :position')
                        ->bind(':item', $itemId, ParameterType::INTEGER)
                        ->bind(':key', $key)
                        ->bind(':position', $position, ParameterType::INTEGER),
                )->execute();
                $id = (int) $db->insertid();
            }

            $kept[] = $id;
            $texts = [];

            foreach (self::TEXTS as $column) {
                foreach ((array) $row[$column] as $lang => $text) {
                    $texts[MultilingualHelper::buildLangFieldKey($column, (string) $lang)] = (string) $text;
                }
            }

            if ($texts !== []) {
                MultilingualHelper::saveMultilingualData(
                    currentId:         $id,
                    primaryColumnName: self::LANG_PK,
                    tableName:         self::TABLE,
                    data:              $texts,
                    aliasFields:       [],
                );
            }
        }

        $gone = array_values(array_diff($existing, $kept));

        if ($gone !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(self::TABLE))
                    ->whereIn($db->quoteName('id'), $gone),
            )->execute();
            MultilingualHelper::deleteMultilingualData(ids: $gone, primaryColumnName: self::LANG_PK, tableName: self::TABLE);
        }
    }

    /**
     * Remove every section of these products, with their texts: the product delete's own statement.
     *
     * @param DatabaseInterface $db The database.
     * @param list<int> $itemIds The deleted products.
     *
     * @since  2.3.0
     */
    public static function deleteFor(DatabaseInterface $db, array $itemIds): void
    {
        $itemIds = array_values(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0));

        if ($itemIds === []) {
            return;
        }

        $ids = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('item_id'), $itemIds),
        )->loadColumn() ?: []);

        if ($ids === []) {
            return;
        }

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName(self::TABLE))
                ->whereIn($db->quoteName('id'), $ids),
        )->execute();
        MultilingualHelper::deleteMultilingualData(ids: $ids, primaryColumnName: self::LANG_PK, tableName: self::TABLE);
    }

    /**
     * A product's own sections for its page: id, section_key, position, and title and body in the request's
     * language (the default language where it has none), in position order.
     *
     * @param DatabaseInterface $db The database.
     * @param int $itemId The product.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public static function forDisplay(DatabaseInterface $db, int $itemId): array
    {
        if ($itemId <= 0) {
            return [];
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName(['s.id', 's.section_key', 's.position']))
            ->from($db->quoteName(self::TABLE, 's'))
            ->where($db->quoteName('s.item_id') . ' = :item')
            ->order($db->quoteName('s.position') . ' ASC, ' . $db->quoteName('s.id') . ' ASC')
            ->bind(':item', $itemId, ParameterType::INTEGER);

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         's',
            mainPrimaryColumn: 'id',
            langTableBase:     self::TABLE,
            langPrimaryColumn: self::LANG_PK,
            fields:            self::TEXTS,
        );

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * A {language: text} map as posted or stored (an array, an object, a JSON string, or a bare string for the
     * default language), as [tag => text] with normalised tags.
     *
     * @param mixed $map The map.
     *
     * @return array<string, string>
     *
     * @since  2.3.0
     */
    private static function texts(mixed $map): array
    {
        if (\is_string($map)) {
            $decoded = json_decode($map, true);
            $map = \is_array($decoded) ? $decoded : ($map !== '' ? [MultilingualHelper::getDefaultLanguageTag() => $map] : []);
        }

        $out = [];

        foreach ((array) $map as $lang => $text) {
            $tag = MultilingualHelper::normaliseTag((string) $lang);

            if ($tag !== '' && \is_scalar($text)) {
                $out[$tag] = (string) $text;
            }
        }

        return $out;
    }
}
