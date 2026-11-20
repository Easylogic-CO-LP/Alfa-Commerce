<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Locations;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Locations view
 *
 * @since  2.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;
    use ListsTranslations;

    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  2.0.0
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
     * @since  2.0.0
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

    /**
     * The translations a list row carries with include=translations (ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.2.2
     */
    protected function translationSource(): array
    {
        return ['#__alfa_inventory_locations', 'id_inventory_location', []];
    }
}
