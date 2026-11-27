<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\View\Labels;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Api\View\ListsTranslations;
use Joomla\CMS\MVC\View\JsonApiView as BaseApiView;

/**
 * The Labels view: the shop's labels as the Labels screen keeps them.
 *
 * @since  2.3.0
 */
class JsonApiView extends BaseApiView
{
    use ListsTranslations;

    /**
     * The fields to render item in the documents
     *
     * @var array
     * @since  2.3.0
     */
    protected $fieldsToRenderItem = [
        'id',
        'name',
        'bg',
        'color',
        'class',
        'list_show',
        'list_slot',
        'item_show',
        'item_slot',
        'corner',
        'filterable',
        'state',
        'publish_up',
        'publish_down',
        'ordering',
        'modified',
        'translations',
    ];

    /**
     * The fields to render items in the documents
     *
     * @var array
     * @since  2.3.0
     */
    protected $fieldsToRenderList = [
        'id',
        'name',
        'bg',
        'color',
        'corner',
        'filterable',
        'state',
        'publish_up',
        'publish_down',
        'item_count',
        'ordering',
        'modified',
    ];

    /**
     * The translations a list row carries with include=translations (ListsTranslations).
     *
     * @return array{0: string, 1: string, 2: string[]}
     *
     * @since  2.3.0
     */
    protected function translationSource(): array
    {
        return ['#__alfa_labels', 'id_label', ['name']];
    }
}
