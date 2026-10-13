<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Catalog;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use Throwable;

/**
 * Who may see which products and categories: the ONE definition, for every reader.
 *
 * INVARIANT PROTECTED: the catalogue can never disagree with itself about what a
 * visitor is allowed to see. A category page, a search, a facet count, a feed and an
 * API response must all resolve the same set of products; the moment each builds its
 * own predicate they drift, and the drift shows up as a product that is hidden in the
 * listing but reachable from search, or a count that does not match the rows below
 * it.
 *
 * This is a CATALOGUE policy, deliberately not a search concern. Search consumes it;
 * it does not own it. An engine may later apply the same policy its own way (a SQL
 * predicate, an engine-side filter, an ACL filter) but the rule itself lives here, so
 * adding an engine never means re-deciding who can see what.
 *
 * ## The rule
 *
 * An allowlist, in two independent dimensions, AND-combined:
 *
 *     user dimension:  listed for this user      OR no real users are listed
 *     group dimension: listed for one of my groups OR no real groups are listed
 *
 * So an unrestricted record is visible to everyone, and a restricted one is visible
 * only to who it names. Restricting by group and by user at once means the visitor
 * must satisfy BOTH: narrowing, never widening.
 *
 * The `> 0` guards are essential, not defensive. `AlfaHelper::setAssocsToDb()` writes
 * a SENTINEL row with id 0 when the merchant leaves a list empty, so "has no rows"
 * is never true in practice; only rows with a real id count as a restriction. This
 * mirrors {@see \Alfa\Component\Alfa\Administrator\Helper\FieldsHelper}, which has
 * shipped the same semantic for form fields; the rule is reused, not reinvented.
 *
 * ## How a product inherits from its categories
 *
 * A product can sit in several categories with different allowlists, so its audience
 * is DERIVED, not looked up. The rule, applied PER DIMENSION:
 *
 *     the product's own list wins on a dimension whenever it has one
 *     otherwise that dimension is inherited from its DEFAULT category
 *
 * Per dimension because setting allowed USERS on a product must not silently drop
 * the group restriction it was inheriting. Implicit (an empty list simply means
 * "follow my category"), because an explicit per-product mode column would buy
 * exactly one extra case ("public despite a restricted category") that is already
 * expressible: inheritance comes only from the DEFAULT category, so filing the
 * product under a public default and keeping the restricted one as a secondary
 * category says it without any new concept.
 *
 * Neither the UNION nor the INTERSECTION of a product's categories is used, and
 * both were considered:
 *
 *   union        - visible if ANY category admits you. A product filed under both a
 *                  public and a wholesale category becomes public, so the wholesale
 *                  restriction silently evaporates. Restrictions must never be
 *                  weakened by adding a category.
 *   intersection - visible only if EVERY category admits you. Defensible, but
 *                  unpredictable: adding a navigational category can hide a product
 *                  from the people it was meant for.
 *
 * The other categories a product is filed under never affect visibility; they are
 * navigation. A restricted category hides itself from listings and from the tree, and
 * (through this rule) lends its allowlist to the products whose primary category
 * it is.
 *
 * Categories themselves do NOT inherit from their parents. A visible child of a
 * restricted parent stays visible, because the alternative is a rule the merchant
 * cannot see the consequences of when reorganising a tree.
 *
 * ## Why a predicate and not a post-filter
 *
 * Filtering rows after the query would corrupt pagination and every count: page 2
 * would be computed from a total that includes rows the visitor cannot see, and pages
 * would come back short. The rule has to be inside the query.
 *
 * ## THE CACHING RULE: read this before caching anything derived from a query
 *
 * The moment a cached value depends on this policy it stops being shared data. Any
 * cache whose VALUE can differ between visitors MUST include {@see self::cacheKey()}
 * in its KEY, and MUST be invalidated when an allowlist changes. Both halves are
 * required and each has already been the cause of a real defect here:
 *
 *   1. A key without the visitor is a cross-visitor DISCLOSURE. The category tree
 *      and the category path/name caches were keyed by id and language only, on the
 *      documented assumption that "category structure is the same for all users".
 *      Adding this policy silently falsified that assumption, and those caches have
 *      a one-year lifetime: a privileged visitor's answer would have been served to
 *      guests until it expired. Applying the policy to a loader WITHOUT fixing its
 *      key is strictly worse than not applying it at all.
 *
 *   2. Missing invalidation makes a correct filter look broken. Editing only the
 *      allowlist changes neither the record's parent nor its newness, so any cache
 *      cleared on "changed or new" alone keeps serving the pre-restriction answer.
 *
 * Deciding not to key a cache by the visitor is legitimate, but it is a decision to
 * make deliberately and to write down, not one to inherit by omission. The test is
 * simply: can two visitors get different values here?
 *
 * @since  2.0.0
 */
final class CatalogAccessPolicy
{
    /**
     * @since  2.0.0
     */
    private readonly DatabaseInterface $db;

    /**
     * Current user id; 0 for a guest.
     *
     * @since  2.0.0
     */
    private readonly int $userId;

    /**
     * Authorised usergroup ids, already sanitised.
     *
     * @var int[]
     * @since  2.0.0
     */
    private readonly array $groups;

    /**
     * @param int|null $userId Explicit user id; null resolves the current identity.
     *                         Pass explicitly from CLI and from tests, where there
     *                         is no application to ask.
     * @param int[]|null $groups Explicit usergroup ids; null resolves them from the user.
     * @param DatabaseInterface|null $db Injected for testing; defaults to the container driver.
     *
     * @since  2.0.0
     */
    public function __construct(
        ?int $userId = null,
        ?array $groups = null,
        ?DatabaseInterface $db = null,
    ) {
        $this->db = $db ?? Factory::getContainer()->get('DatabaseDriver');

        $identity = null;

        if ($userId === null || $groups === null) {
            try {
                $identity = Factory::getApplication()->getIdentity();
            } catch (Throwable) {
                // No application (CLI, cron): fall through to guest, which is the
                // most restrictive answer and therefore the safe default.
                $identity = null;
            }
        }

        $this->userId = $userId ?? (int) ($identity?->id ?? 0);

        $this->groups = array_values(array_filter(array_map(
            'intval',
            $groups ?? (array) ($identity?->getAuthorisedGroups() ?? []),
        )));
    }

    /**
     * Restrict a query over `#__alfa_items` to what this visitor may see.
     *
     * Deliberately more than the item's own allowlist: it resolves the same
     * item-then-default-category inheritance the edit screens present, so what a
     * visitor can browse, filter and search is decided in exactly one place. A second
     * implementation of this rule anywhere means a product is eventually listed but
     * unsearchable, or worse, findable but not listed.
     *
     * @param QueryInterface $query The query being built.
     * @param string $alias The alias `#__alfa_items` is joined under.
     *
     *
     * @since  2.0.0
     */
    public function applyToItems(QueryInterface $query, string $alias = 'a'): void
    {
        $id = $this->db->quoteName($alias . '.id');
        $default = $this->db->quoteName($alias . '.id_category_default');

        $query->where($this->dimension(
            ownKey:     $id,
            inheritKey: $default,
            ownTable:   '#__alfa_items_users',
            ownFk:      'item_id',
            catTable:   '#__alfa_categories_users',
            catFk:      'category_id',
            column:     'user_id',
            permitted:  $this->userId > 0 ? [$this->userId] : [],
        ));

        $query->where($this->dimension(
            ownKey:     $id,
            inheritKey: $default,
            ownTable:   '#__alfa_items_usergroups',
            ownFk:      'item_id',
            catTable:   '#__alfa_categories_usergroups',
            catFk:      'category_id',
            column:     'usergroup_id',
            permitted:  $this->groups,
        ));
    }

    /**
     * One visibility dimension, resolved against the product's declared mode.
     *
     * The mode is a COLUMN, not something inferred from whether a list happens to be
     * empty. That is what makes "own, and deliberately empty" expressible (a product
     * that is public despite sitting in a restricted category), and it is also
     * cheaper: the branch is chosen by reading a varchar instead of by two extra
     * EXISTS probes asking "does this product have a list of its own?".
     *
     * @param string $ownKey Quoted column holding the item id.
     * @param string $inheritKey Quoted column holding the default category id.
     * @param string $ownTable Item-side pivot.
     * @param string $ownFk Item-side pivot foreign key.
     * @param string $catTable Category-side pivot.
     * @param string $catFk Category-side pivot foreign key.
     * @param string $column Principal column (`user_id` / `usergroup_id`).
     * @param int[] $permitted Principals the visitor holds on this dimension.
     *
     *
     * @since  2.0.0
     */
    private function dimension(
        string $ownKey,
        string $inheritKey,
        string $ownTable,
        string $ownFk,
        string $catTable,
        string $catFk,
        string $column,
        array $permitted,
    ): string {
        $own = $this->allowlist($ownTable, $ownFk, $ownKey, $column, $permitted);
        $cat = $this->allowlist($catTable, $catFk, $inheritKey, $column, $permitted);

        $hasOwn = 'EXISTS (SELECT 1 FROM ' . $this->db->quoteName($ownTable) . ' h'
            . ' WHERE h.' . $this->db->quoteName($ownFk) . ' = ' . $ownKey
            . ' AND h.' . $this->db->quoteName($column) . ' > 0)';

        return '((' . $hasOwn . ' AND ' . $own . ')'
            . ' OR (NOT ' . $hasOwn . ' AND ' . $cat . '))';
    }

    /**
     * "Listed for me, or nothing real is listed" against one pivot table.
     *
     * @param string $table Pivot table.
     * @param string $fk Pivot foreign key column.
     * @param string $key Quoted column holding the record id to match.
     * @param string $column Principal column.
     * @param int[] $permitted Principals the visitor holds; empty means none.
     *
     *
     * @since  2.0.0
     */
    private function allowlist(string $table, string $fk, string $key, string $column, array $permitted): string
    {
        $t = $this->db->quoteName($table);
        $f = $this->db->quoteName($fk);
        $c = $this->db->quoteName($column);
        // Sentinel rows carry id 0 and mean "nothing set", so both halves test > 0.
        $any = 'NOT EXISTS (SELECT 1 FROM ' . $t . ' x WHERE x.' . $f . ' = ' . $key . ' AND x.' . $c . ' > 0)';

        if ($permitted === []) {
            return $any;
        }

        return '(EXISTS (SELECT 1 FROM ' . $t . ' x WHERE x.' . $f . ' = ' . $key
            . ' AND x.' . $c . ' IN (' . implode(',', array_map('intval', $permitted)) . '))'
            . ' OR ' . $any . ')';
    }

    /**
     * Restrict a query over `#__alfa_categories` to what this visitor may see.
     *
     * @param QueryInterface $query The query being built.
     * @param string $alias The alias `#__alfa_categories` is joined under.
     *
     *
     * @since  2.0.0
     */
    public function applyToCategories(QueryInterface $query, string $alias = 'a'): void
    {
        $query->where($this->predicate(
            key:         $this->db->quoteName($alias . '.id'),
            usersTable:  '#__alfa_categories_users',
            groupsTable: '#__alfa_categories_usergroups',
            foreignKey:  'category_id',
        ));
    }

    /**
     * Whether one item is visible, for direct access by id or alias, where there is
     * no list query to constrain.
     *
     * @param int $itemId The item id.
     *
     *
     * @since  2.0.0
     */
    public function canSeeItem(int $itemId): bool
    {
        if ($itemId <= 0) {
            return false;
        }

        // Built from applyToItems() rather than from its own predicate, so the
        // object-level answer and the list-level answer cannot drift. They already
        // did once: inheritance was added to the query path while this one still
        // consulted the item's own allowlist only, which would have made a product
        // reachable by URL that its own listing hides.
        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName('#__alfa_items', 'a'))
            ->where($this->db->quoteName('a.id') . ' = ' . $itemId);

        $this->applyToItems($query, 'a');

        return (bool) $this->db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * Whether one category is visible.
     *
     * @param int $categoryId The category id.
     *
     *
     * @since  2.0.0
     */
    public function canSeeCategory(int $categoryId): bool
    {
        if ($categoryId <= 0) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->select('1')
            ->from($this->db->quoteName('#__alfa_categories', 'a'))
            ->where($this->db->quoteName('a.id') . ' = ' . $categoryId);

        $this->applyToCategories($query, 'a');

        return (bool) $this->db->setQuery($query, 0, 1)->loadResult();
    }

    /**
     * Which items' EFFECTIVE audience changes when these records change: the blast
     * radius of a visibility edit.
     *
     * This is catalogue knowledge and belongs here, not in whatever consumes it. A
     * category edit is the dangerous case: under
     * default-category inheritance it silently alters the effective
     * audience of every product whose default category it is, which on a real
     * catalogue can be thousands of products from one checkbox. A search engine
     * holding a materialised copy must not have to know that `id_category_default`
     * exists in order to discover it, and if each engine worked it out for itself,
     * they would work it out differently.
     *
     * Only the ids are computed. What to do with them (resync now, queue, ignore)
     * is the consumer's decision.
     *
     * @param int[] $itemIds Items whose own allowlist changed.
     * @param int[] $categoryIds Categories whose allowlist changed.
     *
     * @return int[] Distinct item ids, ascending.
     *
     * @since  2.0.0
     */
    public function itemsAffectedBy(array $itemIds = [], array $categoryIds = []): array
    {
        $affected = array_map('intval', $itemIds);
        $categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));

        if ($categoryIds !== []) {
            $lacks = fn (string $table, string $column): string =>
                'NOT EXISTS (SELECT 1 FROM ' . $this->db->quoteName($table) . ' h'
                . ' WHERE h.' . $this->db->quoteName('item_id') . ' = ' . $this->db->quoteName('id')
                . ' AND h.' . $this->db->quoteName($column) . ' > 0)';

            // Only products that actually INHERIT on at least one dimension are
            // affected. One that names its own users AND its own groups is untouched
            // by any category edit, and reindexing it would be pure waste.
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__alfa_items'))
                ->whereIn($this->db->quoteName('id_category_default'), $categoryIds)
                ->where(
                    '(' . $lacks('#__alfa_items_users', 'user_id')
                    . ' OR ' . $lacks('#__alfa_items_usergroups', 'usergroup_id') . ')',
                );

            foreach ((array) $this->db->setQuery($query)->loadColumn() as $id) {
                $affected[] = (int) $id;
            }
        }

        $affected = array_values(array_unique(array_filter($affected, static fn ($id) => $id > 0)));
        sort($affected);

        return $affected;
    }

    /**
     * The allowlist stored against one category.
     *
     * @param int $categoryId The category id.
     *
     * @return array{users: int[], groups: int[]}
     *
     * @since  2.0.0
     */
    public function audienceOfCategory(int $categoryId): array
    {
        return $this->audienceOf(
            id:          $categoryId,
            usersTable:  '#__alfa_categories_users',
            groupsTable: '#__alfa_categories_usergroups',
            foreignKey:  'category_id',
        );
    }

    /**
     * Read both allowlist dimensions for one record in a single round trip.
     *
     * One query rather than two because this runs once per record during a full
     * index rebuild, where a second round trip is a second round trip per product.
     *
     * @param int $id The record id.
     * @param string $usersTable Per-user pivot table.
     * @param string $groupsTable Per-group pivot table.
     * @param string $foreignKey Column in both pivots pointing back at the record.
     *
     * @return array{users: int[], groups: int[]}
     *
     * @since  2.0.0
     */
    private function audienceOf(int $id, string $usersTable, string $groupsTable, string $foreignKey): array
    {
        $audience = ['users' => [], 'groups' => []];

        if ($id <= 0) {
            return $audience;
        }

        $fk = $this->db->quoteName($foreignKey);

        // Sentinel rows carry id 0 and mean "no restriction", so they must not be
        // read back as an audience of nobody.
        $sql = 'SELECT ' . $this->db->quote('u') . ' AS ' . $this->db->quoteName('kind') . ', '
            . $this->db->quoteName('user_id') . ' AS ' . $this->db->quoteName('principal')
            . ' FROM ' . $this->db->quoteName($usersTable)
            . ' WHERE ' . $fk . ' = ' . $id . ' AND ' . $this->db->quoteName('user_id') . ' > 0'
            . ' UNION ALL '
            . 'SELECT ' . $this->db->quote('g') . ', ' . $this->db->quoteName('usergroup_id')
            . ' FROM ' . $this->db->quoteName($groupsTable)
            . ' WHERE ' . $fk . ' = ' . $id . ' AND ' . $this->db->quoteName('usergroup_id') . ' > 0';

        foreach ((array) $this->db->setQuery($sql)->loadObjectList() as $row) {
            $audience[$row->kind === 'u' ? 'users' : 'groups'][] = (int) $row->principal;
        }

        return $audience;
    }

    /**
     * A stable key for the principals this policy speaks for, so callers that cache
     * catalogue results per request do not serve one visitor's set to another.
     *
     *
     * @since  2.0.0
     */
    public function cacheKey(): string
    {
        $groups = $this->groups;
        sort($groups);

        // Keyed by ACCESS PROFILE, not by identity. Two visitors who can see exactly
        // the same catalogue should share one cache entry; keying by user id would
        // give every logged-in shopper a private copy of the category tree and make
        // the cache close to useless on a busy site.
        //
        // The user id is only part of the profile when per-user allowlists actually
        // exist somewhere; until a merchant uses that feature, group membership fully
        // determines what a visitor sees. The probe is one indexed EXISTS, resolved
        // once per request.
        $profile = 'g' . implode('.', $groups);

        return self::perUserAllowlistsInUse($this->db) ? 'u' . $this->userId . ':' . $profile : $profile;
    }

    /**
     * Whether any per-USER allowlist row exists in the catalogue.
     *
     * Decides whether identity has to enter cache keys at all. Memoised for the
     * request: it is asked once per cached lookup and the answer cannot change
     * mid-request.
     *
     * @param DatabaseInterface $db The database driver.
     *
     *
     * @since  2.0.0
     */
    private static function perUserAllowlistsInUse(DatabaseInterface $db): bool
    {
        static $inUse = null;

        if ($inUse !== null) {
            return $inUse;
        }

        try {
            // Each branch parenthesised: `SELECT ... LIMIT 1 UNION ALL SELECT ... LIMIT 1`
            // is a syntax error, and the failure is silent here because the catch
            // below answers conservatively, so the optimisation would simply never
            // engage while looking like it worked.
            $sql = '(SELECT 1 FROM ' . $db->quoteName('#__alfa_items_users')
                . ' WHERE ' . $db->quoteName('user_id') . ' > 0 LIMIT 1)'
                . ' UNION ALL '
                . '(SELECT 1 FROM ' . $db->quoteName('#__alfa_categories_users')
                . ' WHERE ' . $db->quoteName('user_id') . ' > 0 LIMIT 1)';

            return $inUse = (bool) $db->setQuery($sql)->loadResult();
        } catch (Throwable) {
            // Unknown: assume they are in use, which is the conservative answer:
            // a fragmented cache is a performance cost, a shared one would be a leak.
            return $inUse = true;
        }
    }

    /**
     * The principals this policy speaks for, for callers that must carry them
     * somewhere the policy cannot reach: an engine's own filter language, a
     * queued job, another process.
     *
     * Exposed as data on purpose: it is the ASKING side, and an engine that filters
     * natively needs it without ever learning where allowlists live.
     *
     * @return array{userId: int, groups: int[]}
     *
     * @since  2.0.0
     */
    public function principals(): array
    {
        return ['userId' => $this->userId, 'groups' => $this->groups];
    }

    /**
     * Build the two-dimension allowlist predicate.
     *
     * @param string $key Quoted column holding the record id.
     * @param string $usersTable Per-user pivot table.
     * @param string $groupsTable Per-group pivot table.
     * @param string $foreignKey Column in both pivots pointing back at the record.
     *
     *
     * @since  2.0.0
     */
    private function predicate(string $key, string $usersTable, string $groupsTable, string $foreignKey): string
    {
        $users = $this->db->quoteName($usersTable);
        $groups = $this->db->quoteName($groupsTable);
        $fk = $this->db->quoteName($foreignKey);

        // A guest can never appear in a user allowlist, so for guests the dimension
        // collapses to "this record must not have one".
        $userDimension = $this->userId > 0
            ? '(EXISTS (SELECT 1 FROM ' . $users . ' au WHERE au.' . $fk . ' = ' . $key
              . ' AND au.' . $this->db->quoteName('user_id') . ' = ' . $this->userId . ')'
              . ' OR NOT EXISTS (SELECT 1 FROM ' . $users . ' au WHERE au.' . $fk . ' = ' . $key
              . ' AND au.' . $this->db->quoteName('user_id') . ' > 0))'
            : 'NOT EXISTS (SELECT 1 FROM ' . $users . ' au WHERE au.' . $fk . ' = ' . $key
              . ' AND au.' . $this->db->quoteName('user_id') . ' > 0)';

        $groupIn = $this->groups !== [] ? implode(',', $this->groups) : '0';

        $groupDimension =
            '(EXISTS (SELECT 1 FROM ' . $groups . ' ag WHERE ag.' . $fk . ' = ' . $key
            . ' AND ag.' . $this->db->quoteName('usergroup_id') . ' IN (' . $groupIn . '))'
            . ' OR NOT EXISTS (SELECT 1 FROM ' . $groups . ' ag WHERE ag.' . $fk . ' = ' . $key
            . ' AND ag.' . $this->db->quoteName('usergroup_id') . ' > 0))';

        return '(' . $userDimension . ' AND ' . $groupDimension . ')';
    }
}
