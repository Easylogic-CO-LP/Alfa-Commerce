<?php

namespace Alfa\Component\Alfa\Api\View\Orderstatuses;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Orderstatuses view
 *
 * @since  1.0.1
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var    array
     * @since  1.0.1
     */
    protected $fieldsToRenderItem = [
        'id',
        'name',
        'color',
        'bg_color',
        'stock_action',
        'state'
    ];

    /**
     * The fields to render items in the documents
     *
     * @var    array
     * @since  1.0.1
     */
    protected $fieldsToRenderList = [
        'id',
        'name',
        'color',
        'bg_color',
        'stock_action',
        'state'
    ];
}
