<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Customs;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Customs view
 *
 * @since  1.0.1
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  1.0.1
     */
    protected $fieldsToRenderItem = [
        'id',
        'state',
        'ordering',
        'type',
        'name',
        'required',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  1.0.1
     */
    protected $fieldsToRenderList = [
        'id',
        'state',
        'ordering',
        'type',
        'name',
        'required',
    ];
}
