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
 * The Inventorymovements controller
 *
 * @since  2.0.0
 */
class InventorymovementsController extends ApiController
{
    use HasApiGuards;

    /**
     * The content type of the item.
     *
     * @var string
     * @since  1.0.0
     */
    protected $contentType = 'inventorymovements';

    /**
     * The default view for the display method.
     *
     * @var string
     * @since  1.0.0
     */
    protected $default_view = 'inventorymovements';

    /**
     * Inventorymovements list view amended to add filtering of data
     *
     * Each filter goes onto the model state under the name the movements screen's filter form gives it, and the list
     * model applies it as it does for the screen (2.2.1: the date window, shop-local bounds as the screen's calendars
     * hold them; and item_id, the movements of one product).
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

        if (\array_key_exists('movement_type', $apiFilterInfo)) {
            $this->modelState->set('filter.movement_type', RequestScalar::member($apiFilterInfo, 'movement_type', 'CMD', 'filter'));
        }

        if (\array_key_exists('location_id', $apiFilterInfo)) {
            $this->modelState->set('filter.location_id', RequestScalar::member($apiFilterInfo, 'location_id', 'INT', 'filter'));
        }

        if (\array_key_exists('date_start', $apiFilterInfo)) {
            $this->modelState->set('filter.date_start', RequestScalar::member($apiFilterInfo, 'date_start', 'STRING', 'filter'));
        }

        if (\array_key_exists('date_end', $apiFilterInfo)) {
            $this->modelState->set('filter.date_end', RequestScalar::member($apiFilterInfo, 'date_end', 'STRING', 'filter'));
        }

        if (\array_key_exists('item_id', $apiFilterInfo)) {
            $this->modelState->set('filter.item_id', RequestScalar::member($apiFilterInfo, 'item_id', 'INT', 'filter'));
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
