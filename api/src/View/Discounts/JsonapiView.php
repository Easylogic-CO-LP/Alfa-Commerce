<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Discounts;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\PublishesStoredFigures;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Discounts view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
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
        'name_customer',
        'value',
        'is_amount',
        'behavior',
        'apply_before_tax',
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
        'is_amount',
        'behavior',
        'apply_before_tax',
        'state',
        'ordering',
        'publish_up',
        'publish_down',
    ];

    /**
     * A discount's value as exact decimal text: an amount in the shop's default
     * currency at its scale, a percentage as its own digits.
     *
     * @param object $item The discount.
     *
     * @return object
     *
     * @since  2.0.0
     */
    protected function prepareItem($item)
    {
        if (property_exists($item, 'value')) {
            $item->value = (int) ($item->is_amount ?? 0) === 1
                ? self::storedAmount(value: $item->value, scale: self::defaultScale())
                : self::storedRate(value: $item->value);
        }

        return $item;
    }
}
