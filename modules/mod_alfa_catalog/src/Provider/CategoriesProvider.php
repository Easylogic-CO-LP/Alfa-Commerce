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
 * whatever their number. A list of the categories inside a parent is the shop's own navigation tree
 * (CategoryHelper::getCategoryTree(), stored by the component), drawn to the levels asked for, with the visitor's
 * category marked and its branch open. Product counts come from that same tree, so they follow the visitor's groups
 * and the stock. Nothing here queries a table.
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
     * @var int The most levels a tree draws below its parent.
     * @since  2.3.0
     */
    public const LEVELS_MAX = 10;

    /**
     * @var int The most categories a tab shows at its first level; a stored 0 means this many.
     * @since  2.3.0
     */
    public const LIMIT_MAX = 200;

    /**
     * @var int The most categories a tree draws over all its levels.
     * @since  2.3.0
     */
    public const NODES_MAX = 1000;

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
            'limit' => Providers::bounded($row['cat_limit'] ?? null, 0, self::LIMIT_MAX, 0),
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
        $limit = (int) ($query['limit'] ?? 0);
        $limit = $limit > 0 ? min($limit, self::LIMIT_MAX) : self::LIMIT_MAX;
        $current = (int) ($query['current'] ?? 0);
        $counts = !empty($query['counts']);

        if (!empty($query['tree'])) {
            $levels = Providers::bounded($query['levels'] ?? null, 1, self::LEVELS_MAX, 1);
            $tree = CategoryHelper::getCategoryTree(parentId: (int) ($query['parent'] ?? 0), maxDepth: $levels, includeCount: $counts);
            $budget = self::NODES_MAX;

            return self::nodes(
                categories: \array_slice(self::ordered(array_values($tree), (string) $query['ordering']), 0, $limit),
                branch:     self::branch($current),
                current:    $current,
                openAll:    ($query['open'] ?? '') === 'all',
                counts:     $counts,
                byName:     ($query['ordering'] ?? '') === 'name',
                budget:     $budget,
            );
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

        $productCounts = $counts ? self::productCounts() : [];
        $branch = self::branch($current);
        $records = [];

        foreach ($model->getItems() ?: [] as $row) {
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
     * One level of a tree in the order asked for: the shop's own, or by name.
     *
     * @param array<object> $categories The tree's categories at one level.
     * @param string $ordering A key of ORDERING.
     *
     * @return array<object>
     *
     * @since  2.3.0
     */
    private static function ordered(array $categories, string $ordering): array
    {
        if ($ordering === 'name') {
            usort($categories, static fn (object $a, object $b): int => strnatcasecmp((string) ($a->name ?? ''), (string) ($b->name ?? '')));
        }

        return $categories;
    }

    /**
     * One level of nodes from the tree's categories. A branch is drawn below a category when every branch opens or
     * the category leads to the visitor's; the whole tree never holds more than its budget of nodes.
     *
     * @param array<object> $categories The tree's categories at this level.
     * @param array<int, true> $branch The ids on the branch of the visitor's category.
     * @param int $current The visitor's category.
     * @param bool $openAll Whether every branch opens.
     * @param bool $counts Whether the product counts are drawn.
     * @param bool $byName Whether each level is ordered by name.
     * @param int $budget The nodes still allowed, counted down.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function nodes(array $categories, array $branch, int $current, bool $openAll, bool $counts, bool $byName, int &$budget): array
    {
        $nodes = [];

        foreach ($categories as $category) {
            if ($budget <= 0) {
                break;
            }

            $budget -= 1;
            $id = (int) $category->id;
            $open = isset($branch[$id]);
            $children = ($openAll || $open) && !empty($category->children) ? array_values((array) $category->children) : [];

            $nodes[] = (object) [
                'id' => $id,
                'name' => (string) ($category->name ?? ''),
                'link' => Route::_('index.php?option=com_alfa&view=items&category_id=' . $id),
                'image' => '',
                'alt' => '',
                'description' => '',
                'count' => $counts ? (int) ($category->product_count ?? 0) : null,
                'current' => $id === $current,
                'open' => $open,
                'children' => $children === [] ? [] : self::nodes(
                    categories: self::ordered($children, $byName ? 'name' : 'ordering'),
                    branch:     $branch,
                    current:    $current,
                    openAll:    $openAll,
                    counts:     $counts,
                    byName:     $byName,
                    budget:     $budget,
                ),
            ];
        }

        return $nodes;
    }

    /**
     * How many products each category holds, its subcategories included, for this visitor: the shop's own count
     * tree, flattened. One stored tree answers every category.
     *
     * @return array<int, int> Category id => products.
     *
     * @since  2.3.0
     */
    private static function productCounts(): array
    {
        $counts = [];
        $walk = static function (array $categories) use (&$walk, &$counts): void {
            foreach ($categories as $category) {
                $counts[(int) $category->id] = (int) ($category->product_count ?? 0);

                if (!empty($category->children)) {
                    $walk((array) $category->children);
                }
            }
        };

        $walk(CategoryHelper::getCategoryTree(parentId: 0, maxDepth: self::LEVELS_MAX, includeCount: true));

        return $counts;
    }
}
