<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Tasks;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Tasks view: a task's document (Service\Task\TaskDocument::fields()), the same fields in the list.
 *
 * @since  2.2.0
 */
class JsonApiView extends BaseApiView
{
    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  2.2.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'type',
        'handler',
        'owner',
        'scope',
        'state',
        'done_units',
        'total_units',
        'percent',
        'run_after',
        'created_at',
        'started_at',
        'finished_at',
        'updated_at',
        'origin',
        'last_error',
        'result',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  2.2.0
     */
    protected $fieldsToRenderList = [
        'id',
        'type',
        'handler',
        'owner',
        'scope',
        'state',
        'done_units',
        'total_units',
        'percent',
        'run_after',
        'created_at',
        'started_at',
        'finished_at',
        'updated_at',
        'origin',
        'last_error',
        'result',
    ];
}
