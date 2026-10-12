<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Categories;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Categories view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
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
    ];
}
