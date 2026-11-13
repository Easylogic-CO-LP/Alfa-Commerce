<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Api\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Joomla\CMS\MVC\Controller\ApiController;

/**
 * The Itemfields controller
 *
 * @since  1.0.0
 */
class ItemfieldsController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.0.0
     */
    protected $contentType = 'itemfields';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.0.0
     */
    protected $default_view = 'itemfields';

    /**
     * Relations the admin model clears when the key is absent from a PATCH.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    protected function preserveOnPatch(): array
    {
        return [
            'categories',
            'values',
        ];
    }

    /**
     * An item field's values, which the API does not write (2.2.1): `values` and the edit form's `deleted_value_ids`.
     * The field save reads both from the request as its Values tab posts them, and keeps a value row by its id
     * whatever field it belongs to, so a write naming another field's value moved it under this field, with what
     * products built on it, and a deletion reached the unused values of any field.
     *
     * @return string[]
     *
     * @since  2.2.1
     */
    protected function unwrittenKeys(): array
    {
        return ['values', 'deleted_value_ids'];
    }

    /**
     * Where they are written instead.
     *
     * @since  2.2.1
     */
    protected function unwrittenReason(): string
    {
        return 'an item field\'s values are edited on its page in the administrator. Send the field without them; nothing was saved.';
    }

    /**
     * Itemfields list view amended to add filtering of data
     *
     * @return static A BaseController object to support chaining.
     *
     * @since  2.0.0
     */
    public function displayList()
    {
        $apiFilterInfo = $this->input->get('filter', [], 'array');

        if (\array_key_exists('search', $apiFilterInfo)) {
            $this->modelState->set('filter.search', RequestScalar::member($apiFilterInfo, 'search', 'STRING', 'filter'));
        }

        if (\array_key_exists('state', $apiFilterInfo)) {
            $this->modelState->set('filter.state', RequestScalar::member($apiFilterInfo, 'state', 'INT', 'filter'));
        }

        if (\array_key_exists('type', $apiFilterInfo)) {
            $this->modelState->set('filter.type', RequestScalar::member($apiFilterInfo, 'type', 'CMD', 'filter'));
        }

        if (\array_key_exists('informational', $apiFilterInfo)) {
            $this->modelState->set('filter.informational', RequestScalar::member($apiFilterInfo, 'informational', 'INT', 'filter'));
        }

        $apiListInfo = $this->input->get('list', [], 'array');

        if (\array_key_exists('ordering', $apiListInfo)) {
            $this->modelState->set('list.ordering', RequestScalar::member($apiListInfo, 'ordering', 'STRING', 'list'));
        }

        if (\array_key_exists('direction', $apiListInfo)) {
            $this->modelState->set('list.direction', RequestScalar::member($apiListInfo, 'direction', 'STRING', 'list'));
        }

        return parent::displayList();
    }
}
