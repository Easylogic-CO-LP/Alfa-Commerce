<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Search;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Builds the canonical {@see SearchDocument} for one item in one language.
 *
 * This is the ONE place that decides what a search engine is allowed to know about
 * a product. It emits semantic truth (identifiers, per-language text, attributed
 * field values, variants, taxonomy) and never a storage or retrieval strategy.
 * How that becomes a matchable haystack, a set of analyzed fields or a nested
 * document is the business of the engine that stores it, never of this class. The
 * Indexed SQL engine flattens this document into one lowercased string; an
 * attribute-based engine reading the same document will keep it structured.
 *
 * The acceptance test it is written against: *given only its output, could someone
 * write a new engine's indexer and build their own mapping without asking Commerce
 * Core anything?* Every field here earns its place by that question.
 *
 * @since  2.0.0
 */
class SearchDocumentBuilder
{
    /**
     * Cap on extracted description text, per item per language.
     *
     * Bounds peak memory during a rebuild and the length of a haystack an engine will
     * scan. The cost is that a phrase buried past it cannot be found, which is why the
     * setting's description states the limit rather than letting it surprise anyone.
     *
     * @var int
     *
     * @since  2.0.0
     */
    private const MAX_DESCRIPTION_CHARS = 4000;

    /**
     * Descriptive columns of the per-language item table, in reading order.
     *
     * @var string[]
     *
     * @since  2.0.0
     */
    private const DESCRIPTION_COLUMNS = ['short_desc', 'full_desc', 'meta_title', 'meta_desc'];

    /** @var DatabaseInterface */
    private $db;

    /**
     * Which of {@see self::DESCRIPTION_COLUMNS} each language table actually has,
     * keyed by table suffix.
     *
     * @var array<string, string[]>
     *
     * @since  2.0.0
     */
    private array $descriptionColumns = [];

    /**
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     *
     * @since  2.0.0
     */
    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
    }

    /**
     * The canonical document, or null when this item must not be indexed at all.
     *
     * Null (rather than an empty document) is the signal that the row should be
     * REMOVED: presence in the index must always mean "findable". The only such case
     * is an unpublished item, and that is deliberate rather than provisional:
     * publication is the one visibility rule with no per-visitor dimension, so
     * encoding it costs nothing and leaving it out would index products no visitor
     * can reach. Every other visibility rule is applied by the catalogue query.
     *
     * @param int $itemId The item id.
     * @param string $langTag The language tag, lower-snake form (e.g. en_gb).
     *
     *
     * @since  2.0.0
     */
    public function buildDocument(int $itemId, string $langTag): ?SearchDocument
    {
        if ($itemId <= 0 || $langTag === '') {
            return null;
        }

        try {
            $language = LanguageTag::from($langTag);
        } catch (InvalidArgumentException $e) {
            return null;
        }

        $item = $this->readItem($itemId);

        if ($item === null || (int) $item->state !== 1) {
            return null;
        }

        $suffix = $language->tableSuffix();
        [$title, $alias] = $this->readItemText($itemId, $suffix);

        return new SearchDocument(
            id:            DocumentId::forItem(itemId: $itemId, language: $language),
            title:         $title,
            alias:         $alias,
            sku:           (string) ($item->sku ?? ''),
            gtin:          (string) ($item->gtin ?? ''),
            mpn:           (string) ($item->mpn ?? ''),
            manufacturers: $this->readFacets(
                pivotItemId:      $itemId,
                pivotTable:       '#__alfa_items_manufacturers',
                pivotColumn:      'manufacturer_id',
                baseTable:        '#__alfa_manufacturers',
                langTable:        '#__alfa_manufacturers_' . $suffix,
                langKeyColumn:    'id_manufacturer',
                defaultLangTable: $this->defaultLanguageTable(baseTable: '#__alfa_manufacturers', suffix: $suffix),
            ),
            categories:    $this->readFacets(
                pivotItemId:      $itemId,
                pivotTable:       '#__alfa_items_categories',
                pivotColumn:      'category_id',
                baseTable:        '#__alfa_categories',
                langTable:        '#__alfa_categories_' . $suffix,
                langKeyColumn:    'id_category',
                defaultLangTable: $this->defaultLanguageTable(baseTable: '#__alfa_categories', suffix: $suffix),
            ),
            itemFields:    $this->readFieldValues($itemId, $suffix),
            combinations:  $this->readCombinations($itemId, $suffix),
            version:       (int) strtotime((string) ($item->modified ?? '')) ?: 0,
            descriptions:  $this->readDescriptions($itemId, $suffix),
        );
    }

    /**
     * The item's own row: publication state, identifiers and modification time.
     *
     * @param int $itemId The item id.
     *
     *
     * @since  2.0.0
     */
    private function readItem(int $itemId): ?object
    {
        try {
            return $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName(['state', 'sku', 'gtin', 'mpn', 'modified']))
                    ->from($this->db->quoteName('#__alfa_items'))
                    ->where($this->db->quoteName('id') . ' = ' . $itemId),
            )->loadObject();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Name and alias in one language, each falling back to the site default exactly
     * as the display path does. The governing rule: the index must be findable by
     * whatever the storefront actually renders; otherwise an untranslated item is
     * visible on the page and absent from search.
     *
     * @param int $itemId The item id.
     * @param string $suffix Normalised language suffix.
     *
     * @return array{0: string, 1: string} Title and alias.
     *
     * @since  2.0.0
     */
    private function readItemText(int $itemId, string $suffix): array
    {
        $row = $this->readItemTextRow($itemId, $suffix);
        $title = $row['name'];
        $alias = $row['alias'];

        if ($title !== '' && $alias !== '') {
            return [$title, $alias];
        }

        $defaultSuffix = LanguageTag::siteDefault()->tableSuffix();

        if ($defaultSuffix !== $suffix) {
            $fallback = $this->readItemTextRow($itemId, $defaultSuffix);
            $title = $title !== '' ? $title : $fallback['name'];
            $alias = $alias !== '' ? $alias : $fallback['alias'];
        }

        return [$title, $alias];
    }

    /**
     * @param int $itemId The item id.
     * @param string $suffix Normalised language suffix.
     *
     * @return array{name: string, alias: string}
     *
     * @since  2.0.0
     */
    private function readItemTextRow(int $itemId, string $suffix): array
    {
        try {
            $row = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName(['name', 'alias']))
                    ->from($this->db->quoteName('#__alfa_items_' . $suffix))
                    ->where($this->db->quoteName('id_item') . ' = ' . $itemId),
            )->loadAssoc();
        } catch (Throwable $e) {
            $row = null;
        }

        return [
            'name' => (string) ($row['name'] ?? ''),
            'alias' => (string) ($row['alias'] ?? ''),
        ];
    }

    /**
     * Descriptive prose in one language, as plain text.
     *
     * Read SEPARATELY from name and alias, and that separation is load-bearing: widening
     * the title's SELECT would put it behind the same failure, so one missing column on a
     * legacy language table would blank every product's title in that language.
     *
     * ONLY ENGINES EVER SEE THIS. With no engine installed, {@see SearchWriteCoordinator}
     * resolves no indexer and every document built here is discarded: the live-table
     * provider does not search descriptions at all. Stripping markup and capping length
     * therefore happen here, on the path that leads to storage, and nowhere else. That is
     * what keeps one engine from disagreeing with itself: an engine's index and its own
     * documents are rendered by this one method.
     *
     * No default-language fallback, deliberately; see {@see SearchDescriptions}.
     *
     * @param int $itemId The item id.
     * @param string $suffix Normalised language suffix.
     *
     *
     * @since  2.0.0
     */
    private function readDescriptions(int $itemId, string $suffix): SearchDescriptions
    {
        $columns = $this->descriptionColumnsFor($suffix);

        if ($columns === []) {
            return new SearchDescriptions();
        }

        try {
            $row = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName($columns))
                    ->from($this->db->quoteName('#__alfa_items_' . $suffix))
                    ->where($this->db->quoteName('id_item') . ' = ' . $itemId),
            )->loadAssoc();
        } catch (Throwable $e) {
            return new SearchDescriptions();
        }

        if (!\is_array($row)) {
            return new SearchDescriptions();
        }

        $meta = trim(((string) ($row['meta_title'] ?? '')) . ' ' . ((string) ($row['meta_desc'] ?? '')));

        return new SearchDescriptions(
            short: PlainText::fromHtml((string) ($row['short_desc'] ?? ''), self::MAX_DESCRIPTION_CHARS),
            full:  PlainText::fromHtml((string) ($row['full_desc'] ?? ''), self::MAX_DESCRIPTION_CHARS),
            meta:  PlainText::fromHtml($meta, self::MAX_DESCRIPTION_CHARS),
        );
    }

    /**
     * Which descriptive columns this language table has, probed once and remembered.
     *
     * @param string $suffix Normalised language suffix.
     *
     * @return string[] Empty when the table is unreadable or has none of them.
     *
     * @since  2.0.0
     */
    private function descriptionColumnsFor(string $suffix): array
    {
        if (isset($this->descriptionColumns[$suffix])) {
            return $this->descriptionColumns[$suffix];
        }

        try {
            $present = $this->db->getTableColumns('#__alfa_items_' . $suffix, false);
        } catch (Throwable $e) {
            $present = [];
        }

        return $this->descriptionColumns[$suffix] = array_values(
            array_filter(
                self::DESCRIPTION_COLUMNS,
                static fn (string $column): bool => isset($present[$column]),
            ),
        );
    }

    /**
     * Attributed values of every SEARCHABLE field the item carries.
     *
     * Ids travel with the text: without them "Size = M" and "Grade = M" both reach
     * an engine as the bare token "m", indistinguishable and unfilterable, and a
     * rename silently breaks every saved filter.
     *
     * @param int $itemId The item id.
     * @param string $suffix Normalised language suffix.
     *
     * @return FieldValue[]
     *
     * @since  2.0.0
     */
    private function readFieldValues(int $itemId, string $suffix): array
    {
        $db = $this->db;
        $valuesDefault = $this->defaultLanguageTable(baseTable: '#__alfa_item_field_values', suffix: $suffix);
        $fieldsDefault = $this->defaultLanguageTable(baseTable: '#__alfa_item_fields', suffix: $suffix);

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('f.id', 'field_id'),
                $db->quoteName('ix.value_id', 'value_id'),
            ])
            ->from($db->quoteName('#__alfa_item_field_value_index', 'ix'))
            ->join(
                'INNER',
                $db->quoteName('#__alfa_item_fields', 'f')
                . ' ON f.id = ix.field_id AND f.searchable = 1 AND f.state = 1',
            )
            ->join(
                'INNER',
                $db->quoteName('#__alfa_item_field_values', 'v')
                . ' ON v.id = ix.value_id AND v.state = 1',
            )
            // INNER when only this language is read: a value it has no row for is not in
            // the document. With the default language read too, a row in either will do.
            ->join(
                $valuesDefault === null ? 'INNER' : 'LEFT',
                $db->quoteName('#__alfa_item_field_values_' . $suffix, 'vl')
                . ' ON vl.id_item_field_value = ix.value_id',
            )
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_item_fields_' . $suffix, 'fl')
                . ' ON fl.id_item_field = f.id',
            )
            ->where('ix.item_id = ' . $itemId)
            // Deterministic: a document must not change because MySQL felt
            // like returning rows in another order.
            ->order('f.id ASC, ix.value_id ASC');

        if ($valuesDefault === null) {
            $query->select([
                $db->quoteName('vl.name', 'value_name'),
                $db->quoteName('vl.alias', 'value_alias'),
            ]);
        } else {
            // The names the storefront shows: this language's, else the default's.
            $query->join('LEFT', $db->quoteName($valuesDefault, 'vd') . ' ON vd.id_item_field_value = ix.value_id')
                ->where('(vl.id_item_field_value IS NOT NULL OR vd.id_item_field_value IS NOT NULL)')
                ->select("COALESCE(NULLIF(vl.name, ''), vd.name) AS " . $db->quoteName('value_name'))
                ->select("COALESCE(NULLIF(vl.alias, ''), vd.alias) AS " . $db->quoteName('value_alias'));
        }

        if ($fieldsDefault === null) {
            $query->select($db->quoteName('fl.name', 'field_name'));
        } else {
            $query->join('LEFT', $db->quoteName($fieldsDefault, 'fd') . ' ON fd.id_item_field = f.id')
                ->select("COALESCE(NULLIF(fl.name, ''), fd.name) AS " . $db->quoteName('field_name'));
        }

        try {
            $rows = $db->setQuery($query)->loadObjectList() ?: [];
        } catch (Throwable $e) {
            return [];
        }

        return array_map(
            static fn (object $row): FieldValue => new FieldValue(
                fieldId:    (int) $row->field_id,
                valueId:    (int) $row->value_id,
                fieldName:  (string) ($row->field_name ?? ''),
                valueName:  (string) ($row->value_name ?? ''),
                valueAlias: (string) ($row->value_alias ?? ''),
            ),
            $rows,
        );
    }

    /**
     * Published variants, with their own identifiers and optional per-language label.
     *
     * Every published variant travels, not only the ones a merchant renamed: a
     * variant's SKU is what a shopper reads off the physical label, and the engine
     * cannot offer what it never received.
     *
     * @param int $itemId The item id.
     * @param string $suffix Normalised language suffix.
     *
     * @return SearchCombination[]
     *
     * @since  2.0.0
     */
    private function readCombinations(int $itemId, string $suffix): array
    {
        $db = $this->db;
        $combinationsDefault = $this->defaultLanguageTable(baseTable: '#__alfa_item_combinations', suffix: $suffix);

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('c.id', 'id'),
                $db->quoteName('c.sku', 'sku'),
                $db->quoteName('c.gtin', 'gtin'),
                $db->quoteName('c.mpn', 'mpn'),
            ])
            ->from($db->quoteName('#__alfa_item_combinations', 'c'))
            ->join(
                'LEFT',
                $db->quoteName('#__alfa_item_combinations_' . $suffix, 'cl')
                . ' ON cl.id_item_combination = c.id',
            )
            ->where('c.item_id = ' . $itemId)
            ->where('c.state = 1')
            ->order('c.id ASC');

        if ($combinationsDefault === null) {
            $query->select($db->quoteName('cl.name', 'label'));
        } else {
            // The label the storefront shows: this language's, else the default's.
            $query->join('LEFT', $db->quoteName($combinationsDefault, 'cd') . ' ON cd.id_item_combination = c.id')
                ->select("COALESCE(NULLIF(cl.name, ''), cd.name) AS " . $db->quoteName('label'));
        }

        try {
            $rows = $db->setQuery($query)->loadObjectList() ?: [];
        } catch (Throwable $e) {
            return [];
        }

        return array_map(
            static fn (object $row): SearchCombination => new SearchCombination(
                id:    (int) $row->id,
                label: (string) ($row->label ?? ''),
                sku:   (string) ($row->sku ?? ''),
                gtin:  (string) ($row->gtin ?? ''),
                mpn:   (string) ($row->mpn ?? ''),
            ),
            $rows,
        );
    }

    /**
     * The default language's table of one translatable entity, read where this document's
     * language has no name, as the storefront shows it; null when the document is in the
     * default language or the table does not exist.
     *
     * @param string $baseTable Base table (e.g. "#__alfa_categories").
     * @param string $suffix Normalised language suffix of the document.
     *
     *
     * @since  2.1.1
     */
    private function defaultLanguageTable(string $baseTable, string $suffix): ?string
    {
        $default = LanguageTag::siteDefault()->tableSuffix();

        return $default !== $suffix && MultilingualHelper::hasLanguageTable(tableName: $baseTable, langTag: $default)
            ? $baseTable . '_' . $default
            : null;
    }

    /**
     * Assigned taxonomy references: ids plus per-language names, never the ancestor
     * closure. Closure is a query-time concern; baking it in would make one category
     * move invalidate every descendant's document.
     *
     * PUBLICATION STATE IS ENFORCED HERE. An unpublished brand or category must not
     * reach a document at all: its name would become searchable text and its id a
     * offerable facet, so the index would answer for something the catalogue refuses
     * to show. That is the one class of disagreement worth preventing outright,
     * unlike audience, which is carried as data precisely because the answer depends
     * on who is asking.
     *
     * DISTINCT because the pivots carry no uniqueness constraint; a duplicated row
     * would otherwise repeat the same name in the projected text and inflate a facet
     * count by exactly the duplication.
     *
     * @param int $pivotItemId The item id.
     * @param string $pivotTable Association table.
     * @param string $pivotColumn Column holding the referenced id.
     * @param string $baseTable Language-independent table of the referenced entity,
     *                          which is where publication state lives.
     * @param string $langTable Per-language table of the referenced entity.
     * @param string $langKeyColumn Primary column of that language table.
     * @param string|null $defaultLangTable The default language's table, whose name and
     *                                      alias count where $langTable has none, or null.
     *
     * @return FacetRef[]
     *
     * @since  2.0.0
     */
    private function readFacets(
        int $pivotItemId,
        string $pivotTable,
        string $pivotColumn,
        string $baseTable,
        string $langTable,
        string $langKeyColumn,
        ?string $defaultLangTable = null,
    ): array {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select('DISTINCT ' . $db->quoteName('p.' . $pivotColumn, 'id'))
            ->from($db->quoteName($pivotTable, 'p'))
            // INNER: no base row, or an unpublished one, means no facet.
            ->join(
                'INNER',
                $db->quoteName($baseTable, 'b')
                . ' ON b.' . $db->quoteName('id') . ' = p.' . $db->quoteName($pivotColumn)
                . ' AND b.' . $db->quoteName('state') . ' = 1',
            )
            // LEFT: an untranslated entity still belongs to the item; it simply
            // contributes an empty name, which the projection drops on its own.
            ->join(
                'LEFT',
                $db->quoteName($langTable, 'l')
                . ' ON l.' . $db->quoteName($langKeyColumn) . ' = p.' . $db->quoteName($pivotColumn),
            )
            ->where('p.item_id = ' . $pivotItemId)
            ->order('p.' . $db->quoteName($pivotColumn) . ' ASC');

        if ($defaultLangTable === null) {
            $query->select([$db->quoteName('l.name', 'name'), $db->quoteName('l.alias', 'alias')]);
        } else {
            // The name the storefront shows: this language's, else the default's.
            $query->join(
                'LEFT',
                $db->quoteName($defaultLangTable, 'd')
                . ' ON d.' . $db->quoteName($langKeyColumn) . ' = p.' . $db->quoteName($pivotColumn),
            )
                ->select("COALESCE(NULLIF(l.name, ''), d.name) AS " . $db->quoteName('name'))
                ->select("COALESCE(NULLIF(l.alias, ''), d.alias) AS " . $db->quoteName('alias'));
        }

        try {
            $rows = $db->setQuery($query)->loadObjectList() ?: [];
        } catch (Throwable $e) {
            return [];
        }

        return array_map(
            static fn (object $row): FacetRef => new FacetRef(
                id:    (int) $row->id,
                name:  (string) ($row->name ?? ''),
                alias: (string) ($row->alias ?? ''),
            ),
            $rows,
        );
    }
}
