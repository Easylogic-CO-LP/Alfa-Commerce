<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Currencies;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Currencies view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.0.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'name',
        'code',
        'number',
        'symbol',
        'decimal_place',
        'decimal_separator',
        'thousand_separator',
        'format_pattern',
        // currency_thousands / currency_positive_style / currency_negative_style
        // are columns no code reads: never rendered, so the API does not
        // publish values that can never take effect.
        'state',
        'ordering',
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
        'number',
        'symbol',
        'decimal_place',
        'decimal_separator',
        'thousand_separator',
        'format_pattern',
        'state',
        'ordering',
    ];
}
