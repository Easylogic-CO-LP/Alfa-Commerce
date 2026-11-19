<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Orderstatuses;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Orderstatuses view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;
    use ListsTranslations;

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
        'color',
        'bg_color',
        'stock_operation',
        'is_initial',
        'is_cancelled',
        'is_completed',
        'notify_customer',
        // Whether entering the status mails one of its administrator recipients, and who they are (Joomla user ids)
        // (2.2.2).
        'notifies_staff',
        'admin_recipient_ids',
        'ordering',
        'state',
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
        'color',
        'bg_color',
        'stock_operation',
        'is_initial',
        'is_cancelled',
        'is_completed',
        // Who entering the status mails: the customer, and its administrator recipients (2.2.2).
        'notify_customer',
        'notifies_staff',
        'ordering',
        'state',
    ];

    /**
     * A list row's or the record's mail facts as whole numbers, as the status's other flags read (2.2.2).
     *
     * @param object $item The status.
     *
     * @return object
     *
     * @since  2.2.2
     */
    protected function prepareItem($item)
    {
        foreach (['notify_customer', 'notifies_staff'] as $flag) {
            if (isset($item->{$flag})) {
                $item->{$flag} = (int) $item->{$flag};
            }
        }

        return parent::prepareItem($item);
    }

    /**
     * The translations a list row carries with translations=1 (the status labels only, as the record carries them) (2.2.2, ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.2.2
     */
    protected function translationSource(): array
    {
        return ['#__alfa_orders_statuses', 'id_orderstatus', ['name', 'name_customer']];
    }
}
