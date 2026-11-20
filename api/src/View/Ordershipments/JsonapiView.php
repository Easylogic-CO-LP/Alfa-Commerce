<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Ordershipments;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The shipments of one order: each with the fields the order read gives it under shipments.
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
        'id_shipment_method',
        'tracking_number',
        'shipping_cost_tax_incl',
        'shipping_cost_tax_excl',
        'shipping_tax_rate',
        'shipping_charge_basis',
        'shipping_tax_chain',
        'weight',
        'added',
    ];
}
