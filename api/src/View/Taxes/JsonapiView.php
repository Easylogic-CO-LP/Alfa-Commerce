<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Taxes;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\PublishesStoredFigures;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Taxes view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;
    use PublishesStoredFigures;

    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'name',
        'desc',
        'value',
        'behavior',
        'state',
        'categories',
        'manufacturers',
        'places',
        'users',
        'usergroups',
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
        'value',
        'behavior',
        'state',
        'ordering',
        'publish_up',
        'publish_down',
    ];

    /**
     * A tax's rate as exact decimal text ("24.000" is "24").
     *
     * @param object|false $item The tax, or false when there is none.
     *
     * @return object|false
     *
     * @since  2.0.0
     */
    protected function prepareItem($item)
    {
        // A record that does not exist reaches here as the model's false: it is handed back untouched, so Joomla's
        // own check answers 404 "Item does not exist" (2.2.1).
        if (!\is_object($item)) {
            return $item;
        }

        if (property_exists($item, 'value')) {
            $item->value = self::storedRate(value: $item->value);
        }

        return $item;
    }
}
