<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Itemfields;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Itemfields view
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
        'field_name',
        'name',
        'description',
        'type',
        'informational',
        'required',
        'filterable',
        'searchable',
        'show_on_listing',
        'state',
        'ordering',
        'categories',
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
        'field_name',
        'name',
        'type',
        'informational',
        'required',
        'filterable',
        'searchable',
        'show_on_listing',
        'state',
        'ordering',
    ];
}
