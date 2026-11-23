<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaCategories\Site\Helper;

use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Router\Route;
use Joomla\Input\Input;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What the categories module shows, read through the shop's own category tree.
 *
 * CategoryHelper::getCategoryTree() is the tree the shop's navigation reads: published categories the visitor may see
 * (the catalogue access policy), names in the page's language, product counts that follow the visitor's group and the
 * stock, all cached by the component. CategoryHelper::getCategoryPath() names the branch of the visitor's category.
 * Nothing here queries a table.
 *
 * @since  2.3.0
 */
final class AlfaCategoriesHelper
{
    /**
     * Which branches open: only the branch of the visitor's category, or every branch to the levels set.
     *
     * @var string[]
     * @since 2.3.0
     */
    public const OPEN = ['current', 'all'];

    /**
     * The most levels the module draws below its parent.
     *
     * @since 2.3.0
     */
    public const LEVELS_MAX = 10;

    /**
     * The categories the module draws, nested.
     *
     * @param Registry $params The module's settings.
     * @param int $currentId The visitor's category: the category of the shop's page they are on, 0 for none.
     *
     * @return list<object{id: int, name: string, link: string, count: int|null, current: bool, open: bool, children: list<object>}>
     *         One node per category, its children inside it; empty when the parent has no category the visitor may see.
     *
     * @since  2.3.0
     */
    public static function nodes(Registry $params, int $currentId): array
    {
        $levels = min(self::LEVELS_MAX, max(1, (int) $params->get('levels', 3)));
        $counts = (int) $params->get('show_count', 0) === 1;
        $value = (string) $params->get('open', self::OPEN[0]);
        $openAll = (\in_array($value, self::OPEN, true) ? $value : self::OPEN[0]) === 'all';

        $tree = CategoryHelper::getCategoryTree(parentId: max(0, (int) $params->get('parent', 0)), maxDepth: $levels, includeCount: $counts);
        $branch = [];

        if ($currentId > 0) {
            foreach (CategoryHelper::getCategoryPath($currentId) as $category) {
                $branch[(int) $category['id']] = true;
            }
        }

        return self::build(categories: $tree, branch: $branch, currentId: $currentId, openAll: $openAll, counts: $counts);
    }

    /**
     * The visitor's category on the page being drawn: the category of a com_alfa listing or of a product reached
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
     * One level of nodes from the tree's categories.
     *
     * @param array<object> $categories The tree's categories at this level.
     * @param array<int, true> $branch The ids on the branch of the visitor's category, the category included.
     * @param int $currentId The visitor's category.
     * @param bool $openAll Whether every branch opens.
     * @param bool $counts Whether the product counts are drawn.
     *
     * @return list<object>
     *
     * @since  2.3.0
     */
    private static function build(array $categories, array $branch, int $currentId, bool $openAll, bool $counts): array
    {
        $nodes = [];

        foreach ($categories as $category) {
            $id = (int) $category->id;
            $open = isset($branch[$id]);

            $nodes[] = (object) [
                'id' => $id,
                'name' => (string) ($category->name ?? ''),
                'link' => Route::_('index.php?option=com_alfa&view=items&category_id=' . $id),
                'count' => $counts ? (int) ($category->product_count ?? 0) : null,
                'current' => $id === $currentId,
                'open' => $open,
                'children' => ($openAll || $open) && !empty($category->children)
                    ? self::build(categories: $category->children, branch: $branch, currentId: $currentId, openAll: $openAll, counts: $counts)
                    : [],
            ];
        }

        return $nodes;
    }
}
