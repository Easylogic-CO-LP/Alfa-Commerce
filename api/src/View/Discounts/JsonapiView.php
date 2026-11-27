<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Discounts;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Alfa\Component\Alfa\Api\View\PublishesStoredFigures;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Discounts view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;
    use ListsTranslations;
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
        'items',
        'places',
        'users',
        'usergroups',
        'excluded_items',
        'excluded_categories',
        'new_products',
        'new_days',
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
        'new_products',
        'new_days',
        'state',
        'ordering',
        'publish_up',
        'publish_down',
    ];

    /**
     * A discount's value as exact decimal text: an amount in the shop's default
     * currency at its scale, a percentage as its own digits.
     *
     * @param object|false $item The discount, or false when there is none.
     *
     * @return object|false
     *
     * @since  2.0.0
     */
    protected function prepareItem($item)
    {
        // A record that does not exist reaches here as the model's false: it is handed back untouched, so Joomla's
        // own check answers 404 "Item does not exist".
        if (!\is_object($item)) {
            return $item;
        }

        if (property_exists($item, 'value')) {
            $item->value = (int) ($item->is_amount ?? 0) === 1
                ? self::storedAmount(value: $item->value, scale: self::defaultScale())
                : self::storedRate(value: $item->value);
        }

        return $item;
    }

    /**
     * The translations a list row carries with include=translations (ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.3.0
     */
    protected function translationSource(): array
    {
        return ['#__alfa_discounts', 'id_discount', []];
    }
}
