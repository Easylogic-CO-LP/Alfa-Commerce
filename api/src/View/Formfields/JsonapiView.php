<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Formfields;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Formfields view
 *
 * @since  1.0.0
 */
class JsonApiView extends BaseApiView
{
    use ListsRequestTasks;

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
        // What the field is to the shop (name, email, phone, country, address, city, postcode; null for a field of
        // the merchant's own), and the field type's settings, a choice's options among them (2.2.2).
        'role',
        'params',
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
