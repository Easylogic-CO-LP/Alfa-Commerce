<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Categories;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryAncestry;
use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Categories view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;
    use ListsTranslations {
        ListsTranslations::displayList as private translatedDisplayList;
    }

    /**
     * The product counts of the categories of the page being answered, read in one statement by displayList():
     * category id => {own, descendants}.
     *
     * @var array<int, array{own: int, descendants: int}>
     * @since  2.2.2
     */
    private array $productCounts = [];

    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'parent_id',
        'name',
        'alias',
        'desc',
        'meta_title',
        'meta_desc',
        'state',
        'meta_data',
        'robots',
        'list_options',
        'medias',
        'publish_up',
        'publish_down',
        'ordering',
        'translations',
        // Where the category sits in the tree: its ancestors from the top-level category down to its parent,
        // each {id, name, alias, state}, and its level (1 for a top-level category).
        'ancestors',
        'level',
        // The products in the category itself, and in it and every category below it.
        'item_count',
        'item_count_descendants',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderList = [
        'id',
        'name',
        'alias',
        'parent_id',
        'state',
        'item_count',
        'item_count_descendants',
        'publish_up',
        'publish_down',
        'modified',
        'ordering',
        'ancestors',
        'level',
    ];

    /**
     * Answer a page of categories, the product counts of the whole page read first, in one statement.
     *
     * @param array|null $items The rows to answer; null reads the model's.
     *
     * @return string
     *
     * @since  2.2.2
     */
    public function displayList(?array $items = null)
    {
        $rows = $items ?? ($this->getModel()->getItems() ?: []);
        $this->productCounts = CategoryAncestry::productCounts(categoryIds: array_map(static fn (object $row): int => (int) ($row->id ?? 0), $rows));

        return $this->translatedDisplayList($items);
    }

    /**
     * A category's place in the tree, on a list row and on the record: its ancestors from the top-level
     * category down to its parent, each {id, name, alias, state} in the request's language and listed whatever its
     * state, and its level, 1 for a top-level category. Read from one read of the categories per request
     * (CategoryAncestry), never per row. item_count counts the products assigned to the category itself and
     * item_count_descendants those assigned to it or to any category below it, each product once; both count the
     * products the back office lists by default (published and unpublished), from one statement for the whole page.
     *
     * @param object $item The category.
     *
     * @return object
     *
     * @since  2.2.2
     */
    protected function prepareItem($item)
    {
        if (!empty($item->id)) {
            $item->ancestors = CategoryAncestry::ancestors(categoryId: (int) $item->id);
            $item->level = \count($item->ancestors) + 1;

            // A list row's counts were read with its page; the record's are read for it alone.
            $counts = $this->productCounts[(int) $item->id] ?? CategoryAncestry::productCounts(categoryIds: [(int) $item->id])[(int) $item->id];
            $item->item_count = $counts['own'];
            $item->item_count_descendants = $counts['descendants'];
        }

        return parent::prepareItem($item);
    }

    /**
     * The translations a list row carries with include=translations (ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.2.2
     */
    protected function translationSource(): array
    {
        return ['#__alfa_categories', 'id_category', []];
    }
}
