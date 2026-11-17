<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Cartrules;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\PublishesStoredFigures;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Cartrules view
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
        'name_customer',
        'description',
        'code',
        'discount_class',
        'action',
        'action_value',
        'max_discount_amount',
        'discount_base',
        'exclude_discounted',
        'overridden_lines',
        'min_amount',
        'min_amount_tax_incl',
        'min_amount_base',
        'combines_product',
        'combines_order',
        'combines_shipping',
        'priority',
        'usage_limit_total',
        'usage_limit_per_user',
        'highlight',
        'publish_up',
        'publish_down',
        'state',
        'created',
        'modified',
        'allowedUsers',
        'allowedUserGroups',
        'groups',
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
        'code',
        'discount_class',
        'action',
        'action_value',
        'priority',
        'highlight',
        'usage_limit_total',
        'used_count',
        'state',
        'publish_up',
        'publish_down',
    ];

    /**
     * A rule's figures as exact decimal text: an amount in the shop's default
     * currency at its scale, a percentage (and a plugin action's value) as its
     * own digits.
     *
     * @param object|false $item The rule, or false when there is none.
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

        $scale = self::defaultScale();

        if (property_exists($item, 'action_value')) {
            $item->action_value = (string) ($item->action ?? '') === 'amount'
                ? self::storedAmount(value: $item->action_value, scale: $scale)
                : self::storedRate(value: $item->action_value);
        }

        foreach (['max_discount_amount', 'min_amount'] as $field) {
            if (property_exists($item, $field)) {
                $item->$field = self::storedAmount(value: $item->$field, scale: $scale);
            }
        }

        return $item;
    }
}
