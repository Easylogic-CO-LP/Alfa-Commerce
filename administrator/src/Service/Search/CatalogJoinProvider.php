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

use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Throwable;

/**
 * Catalogue search over the LIVE tables, with no index of any kind.
 *
 * The correctness floor of the whole search subsystem. It is always present, needs
 * nothing installed, and answers completely the moment the catalogue exists: after
 * a restore, a staging sync, a migration, or on a shop that will never install a
 * search engine. Because it is complete, an engine becomes a pure SPEED decision,
 * which is what allows the engine path to be either/or instead of a union nobody can
 * ever safely remove.
 *
 *  The invariant: one EXISTS per term, never a flat WHERE
 *
 * Every searchable surface except the item's own columns is one-to-MANY: a product
 * has many field values, many variants, many categories. The naive query -
 *
 *     JOIN the surfaces, then  WHERE (...a... LIKE '%red%' OR ...b... LIKE '%red%')
 *                                AND (...a... LIKE '%shirt%' OR ...b... LIKE '%shirt%')
 *
 * - is evaluated per JOINED ROW, so it silently demands that ONE row satisfy both
 * terms. A product named "Cotton Shirt" whose Colour field is "Red" is then not
 * found by "red shirt": no single row of the joined set carries both words.
 *
 * Every indexed engine flattens per PRODUCT and has no such restriction, so the
 * naive form makes the same catalogue answer differently depending on whether an
 * engine happens to be installed or momentarily unavailable: the precise divergence
 * the architecture exists to prevent. Hence: each term becomes its own correlated
 * EXISTS, and a product matches when EVERY term is found SOMEWHERE on it.
 *
 * This is stated at length because the naive reading is the one a maintainer will
 * write, and the bug it produces is invisible in single-word testing.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: no ranking, no typo tolerance, no synonyms, no
 * autocomplete, no analytics. Those are why an engine plugin exists; reproducing
 * them here would leave core maintaining a second-rate search engine and the plugin
 * with no reason to be installed.
 *
 * ORDERING is by item id, not by relevance; there is no relevance here to order by.
 * It is nonetheless explicit, because a truncated candidate set with no ORDER BY
 * returns an arbitrary subset that changes between runs, which reads as products
 * randomly vanishing from search.
 *
 * @since  2.0.0
 */
final class CatalogJoinProvider implements SearchProviderInterface
{
    /**
     * @since  2.0.0
     */
    private readonly DatabaseInterface $db;

    /**
     * @since  2.0.0
     */
    private readonly SearchScope $scope;

    /**
     * @since  2.0.0
     */
    private readonly QueryNormalizer $normalizer;

    /**
     * @since  2.0.0
     */
    private readonly QueryExpander $expander;

    /**
     * @param DatabaseInterface|null $db Injected for testing.
     * @param SearchScope|null $scope What the merchant declared searchable;
     *                                defaults to the live declaration.
     * @param QueryNormalizer|null $normalizer Shared tokeniser.
     * @param QueryExpander|null $expander Core's cross-mode expansion rule.
     *
     * @since  2.0.0
     */
    public function __construct(
        ?DatabaseInterface $db = null,
        ?SearchScope $scope = null,
        ?QueryNormalizer $normalizer = null,
        ?QueryExpander $expander = null,
    ) {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');
        $this->scope = $scope ?? SearchScope::fromConfig();
        $this->normalizer = $normalizer ?? new QueryNormalizer();
        $this->expander = $expander ?? new QueryExpander();
    }

    /**
     * Candidate item ids for a keyword.
     *
     * @param SearchQuery $query The query.
     *
     *
     * @since  2.0.0
     */
    public function search(SearchQuery $query): SearchResult
    {
        // The live-tables baseline answers products only: any other declared entity is
        // answered EMPTY in its own domain, never with item ids wearing the wrong type.
        if ($query->entity !== SearchEntity::ITEM) {
            return new SearchResult(entity: $query->entity);
        }

        $tokens = $this->normalizer->tokens($query->keyword);

        if ($tokens === []) {
            return new SearchResult();
        }

        $suffix = LanguageTag::normalize($query->lang);

        // A valid tag lets each token expand to its variant group: (original OR
        // transliterated), core's cross-mode rule, so a Latin-typed query matches
        // a native-script name here exactly as it does through the engines. A
        // malformed tag skips expansion; the per-language table lookup below would
        // answer nothing under it anyway.
        try {
            $langTag = LanguageTag::from($query->lang);
        } catch (InvalidArgumentException $e) {
            $langTag = null;
        }

        try {
            $db = $this->db;
            $dbQuery = $db->getQuery(true)
                ->select($db->quoteName('i.id'))
                ->from($db->quoteName('#__alfa_items', 'i'))
                ->where($db->quoteName('i.state') . ' = 1')
                ->order($db->quoteName('i.id') . ' ASC');

            foreach ($tokens as $token) {
                $variants = $langTag === null ? [$token] : $this->expander->expand($token, $langTag);
                $dbQuery->where('(' . $this->termMatches($variants, $suffix) . ')');
            }

            $ids = array_map('intval', $db->setQuery($dbQuery, 0, $query->limit + 1)->loadColumn() ?: []);
        } catch (Throwable $e) {
            // Every language table this joins is made at install and for each new
            // content language, so a failure here is a fault to fix: logged, and the
            // shopper is answered with no results rather than an error page.
            Log::add(
                entry:    'CatalogJoinProvider: the live catalogue search failed for language ' . $suffix . ': ' . $e->getMessage(),
                priority: Log::ERROR,
                category: 'com_alfa.search',
            );

            return new SearchResult();
        }

        $limitApplied = \count($ids) > $query->limit;

        if ($limitApplied) {
            $ids = \array_slice($ids, 0, $query->limit);
        }

        return new SearchResult(ids: $ids, limitApplied: $limitApplied);
    }

    /**
     * The disjunction that decides whether ONE term appears anywhere on the item.
     *
     * @param string[] $variants One search term's spellings: (original OR
     *                           transliterated). A column matches the term when it
     *                           matches ANY variant, so every LIKE becomes an
     *                           OR-group over the variants.
     * @param string $suffix Normalised language suffix.
     *
     * @return string SQL fragment, correlated to the outer `i`.
     *
     * @since  2.0.0
     */
    private function termMatches(array $variants, string $suffix): string
    {
        $db = $this->db;
        $patterns = array_map(
            static fn (string $variant): string => $db->quote('%' . $db->escape($variant, true) . '%'),
            $variants,
        );
        $like = static function (string $column) use ($db, $patterns): string {
            $quoted = $db->quoteName($column);
            $ors = array_map(static fn (string $p): string => $quoted . ' LIKE ' . $p, $patterns);

            return \count($ors) > 1 ? '(' . implode(' OR ', $ors) . ')' : $ors[0];
        };

        // Identifiers live on the item row itself, so they need no subquery: a shopper
        // holding the product reads the code off the label and types it in.
        $branches = [
            $like('i.sku'),
            $like('i.gtin'),
            $like('i.mpn'),
        ];

        $branches[] = $this->existsIn(
            table: '#__alfa_items_' . $suffix,
            alias: 'l',
            on:    'l.id_item = i.id',
            match: $this->textColumns($like),
        );

        $branches[] = $this->existsIn(
            table: '#__alfa_item_combinations',
            alias: 'c',
            on:    'c.item_id = i.id AND c.state = 1',
            match: [$like('c.sku'), $like('c.gtin'), $like('c.mpn')],
            joins: [
                'LEFT JOIN ' . $db->quoteName('#__alfa_item_combinations_' . $suffix, 'cl')
                    . ' ON cl.id_item_combination = c.id',
            ],
            extra: [$like('cl.name')],
        );

        // Only values of fields the merchant already marked searchable: a per-field
        // control that predates this provider and is finer than any global switch.
        $branches[] = $this->existsIn(
            table: '#__alfa_item_field_value_index',
            alias: 'ix',
            on:    'ix.item_id = i.id',
            match: [],
            joins: [
                'INNER JOIN ' . $db->quoteName('#__alfa_item_fields', 'f')
                    . ' ON f.id = ix.field_id AND f.searchable = 1 AND f.state = 1',
                'INNER JOIN ' . $db->quoteName('#__alfa_item_field_values', 'v')
                    . ' ON v.id = ix.value_id AND v.state = 1',
                'LEFT JOIN ' . $db->quoteName('#__alfa_item_field_values_' . $suffix, 'vl')
                    . ' ON vl.id_item_field_value = ix.value_id',
            ],
            extra: $this->scope->aliases
                ? [$like('vl.name'), $like('vl.alias')]
                : [$like('vl.name')],
        );

        if ($this->scope->manufacturers) {
            $branches[] = $this->existsIn(
                table: '#__alfa_items_manufacturers',
                alias: 'pm',
                on:    'pm.item_id = i.id',
                match: [],
                joins: [
                    'INNER JOIN ' . $db->quoteName('#__alfa_manufacturers', 'bm')
                        . ' ON bm.id = pm.manufacturer_id AND bm.state = 1',
                    'LEFT JOIN ' . $db->quoteName('#__alfa_manufacturers_' . $suffix, 'ml')
                        . ' ON ml.id_manufacturer = pm.manufacturer_id',
                ],
                extra: $this->scope->aliases
                    ? [$like('ml.name'), $like('ml.alias')]
                    : [$like('ml.name')],
            );
        }

        if ($this->scope->categories) {
            $branches[] = $this->existsIn(
                table: '#__alfa_items_categories',
                alias: 'pc',
                on:    'pc.item_id = i.id',
                match: [],
                joins: [
                    'INNER JOIN ' . $db->quoteName('#__alfa_categories', 'bc')
                        . ' ON bc.id = pc.category_id AND bc.state = 1',
                    'LEFT JOIN ' . $db->quoteName('#__alfa_categories_' . $suffix, 'gl')
                        . ' ON gl.id_category = pc.category_id',
                ],
                extra: $this->scope->aliases
                    ? [$like('gl.name'), $like('gl.alias')]
                    : [$like('gl.name')],
            );
        }

        return implode(' OR ', $branches);
    }

    /**
     * Which columns of the per-language item table this term may match.
     *
     * @param callable $like Builds a LIKE predicate for a column.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private function textColumns(callable $like): array
    {
        // The name, unconditionally. The alias (the accent-folded, transliterated
        // form of the name, which is what lets an unaccented or Latin-typed query
        // match) follows the merchant's scope, shipped ON.
        $columns = [$like('l.name')];

        if ($this->scope->aliases) {
            $columns[] = $like('l.alias');
        }

        // Descriptions, when the merchant declared them searchable. THE DATABASE
        // SEARCH READS THE COLUMN AS STORED (editor HTML included), because a SQL
        // predicate cannot strip markup at query time. That is a DOCUMENTED
        // approximation, not an oversight: the Options text says exactly this, the
        // switches ship off, and it can occasionally match a word inside formatting.
        // An engine is the better version of the same scope, not a wider one: it
        // prepares the text at index time (markup stripped, length capped,
        // transliterated form added), so it answers the same declaration more
        // precisely and faster. Scope is the merchant's; QUALITY of reading it is
        // what the tiers sell.
        if ($this->scope->shortDesc) {
            $columns[] = $like('l.short_desc');
        }

        if ($this->scope->fullDesc) {
            $columns[] = $like('l.full_desc');
        }

        if ($this->scope->metaText) {
            $columns[] = $like('l.meta_title');
            $columns[] = $like('l.meta_desc');
        }

        return $columns;
    }

    /**
     * A correlated EXISTS over one surface.
     *
     * @param string $table Surface table.
     * @param string $alias Its alias.
     * @param string $on Correlation to the outer item, plus any state filter.
     * @param string[] $match Predicates on the surface table itself.
     * @param string[] $joins Additional joins, already written as SQL.
     * @param string[] $extra Predicates on the joined tables.
     *
     *
     * @since  2.0.0
     */
    private function existsIn(
        string $table,
        string $alias,
        string $on,
        array $match,
        array $joins = [],
        array $extra = [],
    ): string {
        $predicates = array_merge($match, $extra);

        return 'EXISTS (SELECT 1 FROM ' . $this->db->quoteName($table, $alias)
            . ($joins === [] ? '' : ' ' . implode(' ', $joins))
            . ' WHERE ' . $on
            . ' AND (' . implode(' OR ', $predicates) . '))';
    }
}
