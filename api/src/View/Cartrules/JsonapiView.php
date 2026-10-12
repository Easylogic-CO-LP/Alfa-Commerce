<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Cartrules;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\PublishesStoredFigures;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Cartrules view
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
     * @param object $item The rule.
     *
     * @return object
     *
     * @since  1.14.0
     */
    protected function prepareItem($item)
    {
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
