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
use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Formfields view
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
        'group_id',
        'field_name',
        'name',
        'field_label',
        'field_description',
        'type',
        // Whether a field is required is its level in each section: registration, billing and shipping, each 0
        // hidden, 1 optional or 2 required.
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
        // the merchant's own), and the field type's settings, a choice's options among them.
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
        'registration',
        'billing',
        'shipping',
        'unique',
        'ordering',
        'state',
        'group_title',
    ];

    /**
     * The translations a list row carries with include=translations (ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.2.2
     */
    protected function translationSource(): array
    {
        return ['#__alfa_form_fields', 'id_formfield', []];
    }
}
