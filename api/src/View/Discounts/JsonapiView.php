<?php

namespace Alfa\Component\Alfa\Api\View\Discounts;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Discounts view
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
        'state',
        'name',
        'desc',
        'value',
        'is_amount',
        'behavior'
    ];

    /**
     * The fields to render items in the documents
     *
     * @var    array
     * @since  1.0.1
     */
    protected $fieldsToRenderList = [
        'id',
        'state',
        'name',
        'desc',
        'value',
        'is_amount',
        'behavior'
    ];
}
