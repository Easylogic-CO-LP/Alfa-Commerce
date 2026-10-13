<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Inventorymovements;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Inventorymovements view
 *
 * @since  2.0.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  2.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'item_id',
        'combination_id',
        'location_id',
        'qty_change',
        'movement_type',
        'source_type',
        'source_id',
        'source_line_id',
        'transfer_id',
        'operation_id',
        'correlation_id',
        'note',
        'created',
        'location_title',
        'item_sku',
        'name',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  2.0.0
     */
    protected $fieldsToRenderList = [
        'id',
        'item_id',
        'combination_id',
        'location_id',
        'qty_change',
        'movement_type',
        'source_type',
        'source_id',
        'source_line_id',
        'transfer_id',
        'operation_id',
        'correlation_id',
        'note',
        'created',
        'location_title',
        'item_sku',
        'name',
    ];
}
