<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Formfieldgroups;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsRequestTasks;
use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Formfieldgroups view
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
        'title',
        'description',
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
        'title',
        'description',
        'ordering',
        'state',
        'field_count',
    ];

    /**
     * The translations a list row carries with include=translations (2.2.2, ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.2.2
     */
    protected function translationSource(): array
    {
        return ['#__alfa_form_field_groups', 'id_form_field_group', []];
    }
}
