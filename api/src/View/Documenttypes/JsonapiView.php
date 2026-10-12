<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Documenttypes;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Documenttypes view
 *
 * @since  1.9.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * `corrects` is the list of sales type aliases a credit type corrects, read
     * only: the controller drops it from every write.
     *
     * @var array
     * @since  1.9.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'alias',
        'name',
        'family',
        'quantity_pool',
        'shows_prices',
        'movement_mode',
        'requires_original',
        'corrects',
        'requires_buyer_tax_id',
        'stock_effect',
        'default_layout',
        'icon',
        'color',
        'ordering',
        'state',
        'is_core',
        'provider',
        'provider_version',
        'translations',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.9.0
     */
    protected $fieldsToRenderList = [
        'id',
        'alias',
        'name',
        'family',
        'quantity_pool',
        'shows_prices',
        'movement_mode',
        'requires_original',
        'requires_buyer_tax_id',
        'stock_effect',
        'icon',
        'color',
        'ordering',
        'state',
        'is_core',
    ];
}
