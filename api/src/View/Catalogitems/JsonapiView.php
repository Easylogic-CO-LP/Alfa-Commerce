<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Catalogitems;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The storefront catalogue view.
 *
 * The list stays deliberately leaner than the item: JsonApiView runs
 * prepareItem() per row, so anything expensive here is paid page-size times.
 *
 * Stock is rendered as the storefront resolves it from the inventory ledger:
 * `stock` and `available_quantity` are the active unit's physical and salable
 * quantities, `stockStatus` the stock verdict (in_stock, backorder or
 * out_of_stock) and `cartState` the add-to-cart verdict. Both lists carry
 * the verdicts, which the site models have already computed per row.
 *
 * @since  2.0.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  2.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'name',
        'alias',
        'short_desc',
        'full_desc',
        'meta_title',
        'meta_desc',
        'sku',
        'gtin',
        'mpn',
        'stock',
        'available_quantity',
        'stockStatus',
        'manage_stock',
        'stock_action',
        'stock_low_message',
        'stock_zero_message',
        'is_virtual',
        'width',
        'height',
        'depth',
        'weight',
        'quantity_min',
        'quantity_max',
        'quantity_step',
        'price',
        'categories',
        'manufacturers',
        'medias',
        'fields',
        'options',
        'combinations',
        'informational',
        'variant',
        'cartState',
        'payment_methods',
        'shipment_methods',
        'publish_up',
        'publish_down',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  2.0.0
     */
    protected $fieldsToRenderList = [
        'id',
        'name',
        'alias',
        'sku',
        'stock',
        'available_quantity',
        'stockStatus',
        'is_virtual',
        'price',
        'medias',
        'variant',
        'cartState',
    ];
}
