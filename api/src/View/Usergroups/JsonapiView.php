<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Usergroups;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Usergroups view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;

    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'usergroup_id',
        'prices_enable',
        'prices_display',
        'name',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderList = [
        'id',
        'usergroup_id',
        'prices_enable',
        'core_title',
    ];

    /**
     * A customer group's price display settings as a map, an object also when the group has none of its own (2.2.1):
     * the model hands the edit form an array, which JSON writes as a list when it is empty.
     *
     * @param object|false $item The customer group, or false when there is none.
     *
     * @return object|false
     *
     * @since  2.2.1
     */
    protected function prepareItem($item)
    {
        // A record that does not exist reaches here as the model's false: it is handed back untouched, so Joomla's
        // own check answers 404 "Item does not exist".
        if (!\is_object($item)) {
            return $item;
        }

        if (property_exists($item, 'prices_display') && \is_array($item->prices_display)) {
            $item->prices_display = (object) $item->prices_display;
        }

        return $item;
    }
}
