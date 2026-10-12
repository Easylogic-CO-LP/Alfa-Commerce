<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Formfields;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Formfields view
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
        'group_id',
        'field_name',
        'name',
        'field_label',
        'field_description',
        'type',
        'required',
        'registration',
        'billing',
        'shipping',
        'unique',
        'ordering',
        'state',
        'users',
        'usergroups',
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
        'group_id',
        'field_name',
        'name',
        'type',
        'required',
        'registration',
        'billing',
        'shipping',
        'unique',
        'ordering',
        'state',
        'group_title',
    ];
}
