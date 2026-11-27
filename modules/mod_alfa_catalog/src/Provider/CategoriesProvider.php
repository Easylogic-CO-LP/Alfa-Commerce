<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCatalog\Site\Provider;

use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Input\Input;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The categories of a tab: chosen ones, or the ones inside a parent.
 *
 * Cards and a flat list are read through the component's Categories model (published categories this visitor may
 * see, names in the page's language, their images and links): one read for the categories and one for their images,
 * whatever their number.
 *
 * A list of the categories inside a parent is a navigation tree, read a level at a time
 * (CategoryHelper::getChildren()): the first level, then the categories inside the ones that are open, down to the
 * levels asked for. One statement per level drawn, so at most as many statements as levels, whatever the size of
 * the shop; with only the visitor's branch open, each statement reads the children of one category. The branch
 * itself is the component's stored path of the visitor's category.
 *
 * Product counts are the component's (CategoryHelper::getProductCounts()): one more read for all the categories of
 * the tab together, following the visitor's groups and the stock. Nothing here queries a table.
 *
 * A tab shows as many categories as its number says, and all of them when the number is 0: nothing is left out
 * that the operator did not leave out.
 *
 * @since  2.3.0
 */
final class CategoriesProvider implements ProviderInterface
{
    /**
     * @var string[] Which categories: the ones inside a parent, or chosen ones.
     * @since  2.3.0
     */
    public const SELECT = ['children', 'chosen'];

    /**
     * @var array<string, string> The order, as the setting names it, and the list ordering the model is handed for it.
     * @since  2.3.0
     */
    public const ORDERING = ['ordering' => 'a.ordering', 'name' => 'name'];

    /**
     * @var string[] Which branches of a tree open: only the branch of the visitor's category, or every branch.
     * @since  2.3.0
     */
    public const OPEN = ['current', 'all'];

    /**
     * @var int The most levels a tree draws below its parent. A safety limit: the depth the shop's own category
     *          tree is built to. The form's field goes no higher, so the operator sees it while choosing.
     * @since  2.3.0
     */
    public const LEVELS_MAX = 10;

    /**
     * A categories tab's query. A list also names the visitor's category, which it marks; cards never do, so a
     * showcase is the same on every page.
     *
     * @param array $row The stored tab row.
     * @param string $display The tab's presentation.
     *
     * @return array<string, mixed>
     *
     * @since  2.3.0
     */
    public function query(array $row, string $display): array
    {
        $list = $display === 'list';
        $select = Providers::choice($row['cat_select'] ?? '', self::SELECT);

        return [
            'select' => $select,
            'parent' => $select === 'children' ? max(0, (int) ($row['cat_parent'] ?? 0)) : 0,
            'ids' => $select === 'chosen' ? Providers::ids($row['cat_ids'] ?? []) : [],
            'ordering' => Providers::choice($row['cat_ordering'] ?? '', array_keys(self::ORDERING)),
            'limit' => max(0, (int) ($row['cat_limit'] ?? 0)),
            'tree' => $list && $select === 'children',
            'levels' => $list && $select === 'children' ? Providers::bounded($row['cat_levels'] ?? null, 1, self::LEVELS_MAX, 1) : 1,
            'open' => Providers::choice($row['cat_open'] ?? '', self::OPEN),
            'counts' => (int) ($row['cat_show_count'] ?? 0) === 1,
            'description' => !$list && (int) ($row['cat_show_desc'] ?? 0) === 1,
            'current' => $list ? self::currentCategory(Factory::getApplication()->getInput()) : 0,
        ];
    }

    /**
     * The categories of a tab: one object per category with its id, name, link, image and its description (both ''
     * when it has none), description, count (null when counts are off), whether it is the visitor's category,
     * whether it is on that category's branch, and the categories drawn inside it.
     *
     * @param array<string, mixed> $query The tab's query.
     * @param Registry $params The module's settings.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    public function records(array $query, Registry $params): array
    {
        // The operator's number; 0 is every category.
        $limit = max(0, (int) ($query['limit'] ?? 0));
        $current = (int) ($query['current'] ?? 0);
        $counts = !empty($query['counts']);

        if (!empty($query['tree'])) {
            return self::tree(query: $query, limit: $limit, current: $current, counts: $counts);
        }

        if (($query['select'] ?? '') === 'chosen' && ($query['ids'] ?? []) === []) {
            return [];
        }

        $model = Factory::getApplication()->bootComponent('com_alfa')->getMVCFactory()
            ->createModel('Categories', 'Site', ['ignore_request' => true]);

        if (($query['select'] ?? '') === 'chosen') {
            $model->setState('filter.categories', Providers::ids($query['ids']));
        } else {
            $model->setState('filter.parent_id', (int) ($query['parent'] ?? 0));
        }

        $model->setState('list.ordering', self::ORDERING[Providers::choice($query['ordering'] ?? '', array_keys(self::ORDERING))]);
        $model->setState('list.direction', 'ASC');
        $model->setState('list.start', 0);
        $model->setState('list.limit', $limit);

        $rows = $model->getItems() ?: [];
        $productCounts = $counts && $rows !== []
            ? CategoryHelper::getProductCounts(array_map(static fn (object $row): int => (int) $row->id, $rows))
            : [];
        $branch = self::branch($current);
        $records = [];

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $name = (string) ($row->name ?? '');
            $media = $row->medias[0] ?? null;

            $records[] = (object) [
                'id' => $id,
                'name' => $name,
                'link' => (string) ($row->link ?? ''),
                'image' => (string) ($media->thumbnail_url ?? $media->url ?? ''),
                // An image always says what it shows: its own description, else the category's name.
                'alt' => trim((string) ($media->alt ?? '')) ?: $name,
                'description' => !empty($query['description']) ? trim(strip_tags((string) ($row->desc ?? ''))) : '',
                'count' => $counts ? (int) ($productCounts[$id] ?? 0) : null,
                'current' => $id === $current,
                'open' => isset($branch[$id]),
                'children' => [],
            ];
        }

        return $records;
    }

    /**
     * A stored result is one page's (a link follows the page's menu item). The visitor's category, for a list, is
     * already in the query.
     *
     * @param array<string, mixed> $query The tab's query.
     * @param Registry $params The module's settings.
     *
     * @return array<int, mixed>
     *
     * @since  2.3.0
     */
    public function varies(array $query, Registry $params): ?array
    {
        return [Factory::getApplication()->getInput()->getInt('Itemid', 0)];
    }

    /**
     * The visitor's category on the page being drawn: the category of a catalogue listing or of a product reached
     * through one; 0 on any other page.
     *
     * @param Input $input The request.
     *
     * @since  2.3.0
     */
    public static function currentCategory(Input $input): int
    {
        if ($input->getCmd('option') !== 'com_alfa' || !\in_array($input->getCmd('view'), ['items', 'item'], true)) {
            return 0;
        }

        return max(0, $input->getInt('category_id', 0));
    }

    /**
     * The ids on the branch of a category, the category included.
     *
     * @param int $current The category, 0 for none.
     *
     * @return array<int, true>
     *
     * @since  2.3.0
     */
    private static function branch(int $current): array
    {
        $branch = [];

        if ($current > 0) {
            foreach (CategoryHelper::getCategoryPath($current) as $category) {
                $branch[(int) $category['id']] = true;
            }
        }

        return $branch;
    }

    /**
     * The tree of a tab: the categories inside its parent, and inside every one of them that is open, down to the
     * levels asked for. A category is open when every branch opens, or when it leads to the visitor's category.
     * Read a level at a time, one statement per level, the categories inside every open category of the level
     * above together; a level with no open category ends the reading.
     *
     * @param array<string, mixed> $query The tab's query.
     * @param int $limit The most categories at the first level; 0 = all.
     * @param int $current The visitor's category, 0 for none.
     * @param bool $counts Whether the product counts are drawn.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function tree(array $query, int $limit, int $current, bool $counts): array
    {
        $levels = Providers::bounded($query['levels'] ?? null, 1, self::LEVELS_MAX, 1);
        $parent = (int) ($query['parent'] ?? 0);
        $openAll = ($query['open'] ?? '') === 'all';
        $byName = ($query['ordering'] ?? '') === 'name';
        $branch = self::branch($current);
        $inside = [];
        $ids = [];
        $parents = [$parent];

        for ($level = 1; $level <= $levels && $parents !== []; $level++) {
            $read = CategoryHelper::getChildren(parentIds: $parents, byName: $byName, limit: $level === 1 ? $limit : 0);
            $parents = [];

            foreach ($read as $parentId => $rows) {
                $inside[(int) $parentId] = $rows;

                foreach ($rows as $row) {
                    $ids[] = (int) $row->id;

                    if ($openAll || isset($branch[(int) $row->id])) {
                        $parents[] = (int) $row->id;
                    }
                }
            }
        }

        return self::nodes(
            inside:        $inside,
            parent:        $parent,
            branch:        $branch,
            current:       $current,
            productCounts: $counts && $ids !== [] ? CategoryHelper::getProductCounts($ids) : null,
        );
    }

    /**
     * The nodes inside one category, each with the nodes inside it, from the levels read.
     *
     * @param array<int, list<object>> $inside The categories read, by the category they are inside.
     * @param int $parent The category whose nodes these are.
     * @param array<int, true> $branch The ids on the branch of the visitor's category.
     * @param int $current The visitor's category.
     * @param array<int, int>|null $productCounts The products per category, null when counts are off.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function nodes(array $inside, int $parent, array $branch, int $current, ?array $productCounts): array
    {
        $nodes = [];

        foreach ($inside[$parent] ?? [] as $category) {
            $id = (int) $category->id;

            $nodes[] = (object) [
                'id' => $id,
                'name' => (string) ($category->name ?? ''),
                'link' => Route::_('index.php?option=com_alfa&view=items&category_id=' . $id),
                'image' => '',
                'alt' => '',
                'description' => '',
                'count' => $productCounts === null ? null : (int) ($productCounts[$id] ?? 0),
                'current' => $id === $current,
                'open' => isset($branch[$id]),
                'children' => self::nodes(inside: $inside, parent: $id, branch: $branch, current: $current, productCounts: $productCounts),
            ];
        }

        return $nodes;
    }
}
