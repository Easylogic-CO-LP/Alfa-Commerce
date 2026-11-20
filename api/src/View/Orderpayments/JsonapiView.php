<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Orderpayments;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The payments of one order: each with the fields the order read gives it under payments.
 *
 * @since  2.2.2
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render in the list.
     *
     * @var array
     * @since  2.2.2
     */
    protected $fieldsToRenderList = [
        'id',
        'id_payment_method',
        'payment_type',
        'transaction_status',
        'transaction_id',
        'amount',
        'conversion_rate',
        'refund_type',
        'refund_reason',
        'note',
        'id_document',
        'added',
    ];
}
