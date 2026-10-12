<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Locations;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Locations view
 *
 * @since  1.9.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.9.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'title',
        'name',
        'location_type',
        'capabilities',
        'kind',
        'owner_type',
        'owner_id',
        'is_default',
        'state',
        'ordering',
        'created',
        'created_by',
        'modified',
        'modified_by',
        'translations',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.9.0
     */
    protected $fieldsToRenderList = [
        'id',
        'title',
        'name',
        'location_type',
        'capabilities',
        'kind',
        'is_default',
        'state',
        'ordering',
        'stock_units',
        'stock_physical',
        'stock_reserved',
    ];
}
