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
    use ListsTranslations;

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
        // Where the category sits in the tree (2.2.2): its ancestors from the top-level category down to its parent,
        // each {id, name, alias, state}, and its level (1 for a top-level category).
        'ancestors',
        'level',
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
        'publish_up',
        'publish_down',
        'modified',
        'ordering',
        'ancestors',
        'level',
    ];

    /**
     * A category's place in the tree, on a list row and on the record (2.2.2): its ancestors from the top-level
     * category down to its parent, each {id, name, alias, state} in the request's language and listed whatever its
     * state, and its level, 1 for a top-level category. Read from one read of the categories per request
     * (CategoryAncestry), never per row. item_count stays the category's own products, not those of its
     * sub-categories.
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
        }

        return parent::prepareItem($item);
    }

    /**
     * The translations a list row carries with include=translations (2.2.2, ListsTranslations).
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
