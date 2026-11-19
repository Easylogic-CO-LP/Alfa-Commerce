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
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * A category's place in the tree, as the REST API publishes it (2.2.2): its ancestors from the root down and its level.
 *
 * Categories are an adjacency list (parent_id, no nested set and no path column), so the chains are walked in PHP
 * from one read of every category per request and language, kept for the request: a page of fifty categories, or a
 * product in ten of them, costs one statement, never one per row.
 *
 * ancestors  the categories above it, from the top-level one down to its parent, each {id, name, alias, state} with
 *            the name and alias in the request's content language. Every ancestor is listed whatever its state
 *            (1 published, 0 unpublished, 2 archived, -2 trashed), so the chain is honest and a client can tell a
 *            category under an unpublished parent. A chain that reaches a parent that no longer exists, or loops,
 *            stops there.
 * level      1 for a top-level category (parent_id 0), its children 2, and so on: the number of its ancestors plus one.
 *
 * For the storefront catalogue an ancestor the visitor may not see is listed by its id and state only, its name and
 * alias null: a restricted category is never named to someone it is restricted from (CatalogAccessPolicy).
 *
 * @since  2.2.2
 */
final class CategoryAncestry
{
    /**
     * Every category of this request, by language: id => [parent_id, state, name, alias].
     *
     * @var array<string, array<int, array{0: int, 1: int, 2: string, 3: string}>>
     *
     * @since  2.2.2
     */
    private static array $categories = [];

    /**
     * The categories the storefront's visitor may see, by language and visitor: id => true.
     *
     * @var array<string, array<int, true>>
     *
     * @since  2.2.2
     */
    private static array $visible = [];

    /**
     * The ancestors of a category, from the top-level one down to its parent.
     *
     * @param int $categoryId The category.
     * @param bool $storefront Whether the chain is for the storefront catalogue: an ancestor the visitor may not see
     *                         is listed without its name and alias.
     *
     * @return list<array{id: int, name: string|null, alias: string|null, state: int}>
     *
     * @since  2.2.2
     */
    public static function ancestors(int $categoryId, bool $storefront = false): array
    {
        $all = self::all();
        $visible = $storefront ? self::visible() : null;
        $chain = [];
        $seen = [$categoryId => true];
        $parentId = (int) ($all[$categoryId][0] ?? 0);

        while ($parentId > 0 && isset($all[$parentId]) && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            [$grandParent, $state, $name, $alias] = $all[$parentId];
            $named = $visible === null || isset($visible[$parentId]);
            array_unshift($chain, [
                'id' => $parentId,
                'name' => $named ? $name : null,
                'alias' => $named ? $alias : null,
                'state' => $state,
            ]);
            $parentId = $grandParent;
        }

        return $chain;
    }

    /**
     * Every category, read once per request and language.
     *
     * @return array<int, array{0: int, 1: int, 2: string, 3: string}>
     *
     * @since  2.2.2
     */
    private static function all(): array
    {
        $language = MultilingualHelper::getCurrentLanguageTag();

        if (isset(self::$categories[$language])) {
            return self::$categories[$language];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['a.id', 'a.parent_id', 'a.state']))
            ->from($db->quoteName('#__alfa_categories', 'a'));

        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_categories',
            langPrimaryColumn: 'id_category',
            fields:            ['name', 'alias'],
        );

        $map = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $map[(int) $row['id']] = [(int) $row['parent_id'], (int) $row['state'], (string) ($row['name'] ?? ''), (string) ($row['alias'] ?? '')];
        }

        return self::$categories[$language] = $map;
    }

    /**
     * The categories the storefront's visitor may see, read once per request.
     *
     * @return array<int, true>
     *
     * @since  2.2.2
     */
    private static function visible(): array
    {
        $identity = Factory::getApplication()->getIdentity();
        $key = (string) ($identity->id ?? 0) . ':' . implode(',', $identity?->getAuthorisedGroups() ?? []);

        if (isset(self::$visible[$key])) {
            return self::$visible[$key];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('a.id'))
            ->from($db->quoteName('#__alfa_categories', 'a'));
        (new CatalogAccessPolicy())->applyToCategories($query, 'a');

        return self::$visible[$key] = array_fill_keys(array_map('intval', $db->setQuery($query)->loadColumn() ?: []), true);
    }
}
