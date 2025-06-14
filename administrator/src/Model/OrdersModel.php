<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;

/**
 * Methods supporting a list of Orders records.
 *
 * @since  1.0.1
 */
class OrdersModel extends ListModel
{
    /**
    * Constructor.
    *
    * @param   array  $config  An optional associative array of configuration settings.
    *
    * @see        JController
    * @since      1.6
    */
    public function __construct($config = [])
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'a.original_price',
                'a.shipping_tracking_number',
                'a.created',
                'user_name',
                'item_name'
            ];
        }

        parent::__construct($config);
    }



    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @param   string  $ordering   Elements order
     * @param   string  $direction  Order direction
     *
     * @return void
     *
     * @throws Exception
     */
    protected function populateState($ordering = null, $direction = null)
    {
        // List state information.
        parent::populateState("a.id", "DESC");

        $context = $this->getUserStateFromRequest($this->context.'.filter.search', 'filter_search');
        $this->setState('filter.search', $context);

        // Split context into component and optional section
        if (!empty($context)) {
            $parts = FieldsHelper::extract($context);

            if ($parts) {
                $this->setState('filter.component', $parts[0]);
                $this->setState('filter.section', $parts[1]);
            }
        }
    }

    /**
     * Method to get a store id based on model configuration state.
     *
     * This is necessary because the model is used by the component and
     * different modules that might need different sets of data or different
     * ordering requirements.
     *
     * @param   string  $id  A prefix for the store id.
     *
     * @return  string A store id.
     *
     * @since   1.0.1
     */
    protected function getStoreId($id = '')
    {
        // Compile the store id.
        $id .= ':' . $this->getState('filter.search');
        // $id .= ':' . $this->getState('filter.state');

        return parent::getStoreId($id);

    }

    /**
     * Build an SQL query to load the list data.
     *
     * @return  DatabaseQuery
     *
     * @since   1.0.1
     */
    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        // Select the required fields from the table.
        $query
            ->select(
                $this->getState(
                    'list.select',
                    'DISTINCT a.*'
                )
            )
            ->select(
                [
                    $db->quoteName('uc.name', 'editor'),
//                    $db->quoteName('oui.name', 'user_name'),
                    $db->quoteName('pm.name', 'payment_method_name'),
                    $db->quoteName('pm.color', 'payment_method_color'),
                    $db->quoteName('pm.bg_color', 'payment_method_bg_color'),
                    $db->quoteName('sm.name', 'shipment_method_name'),
                    $db->quoteName('sm.color', 'shipment_method_color'),
                    $db->quoteName('sm.bg_color', 'shipment_method_bg_color'),
                ]
            );

        // JOINS

        $query->from($db->qn('#__alfa_orders') . ' AS a');


        $query->join('LEFT', $db->quoteName('#__users', 'uc'), $db->quoteName('uc.id') . ' = ' . $db->quoteName('a.checked_out'));

        //        $query->join('LEFT', '#__alfa_order_user_info AS oui ON oui.id_order= a.id');
        $query->join('LEFT', '#__alfa_user_info AS oui ON oui.id = a.id_address_delivery');

        $query->join('LEFT', '#__alfa_payments AS pm ON pm.id = a.id_payment_method');

        $query->join('LEFT', '#__alfa_shipments AS sm ON sm.id = a.id_shipment_method');


        // FILTERING

        $orderStatusFilter = $this->getState('filter.order_status');

        if (!empty($orderStatusFilter)) {
            $query->where('a.id_order_status = ' . (int) $orderStatusFilter);
        }


        $show_trashed = $this->getState('filter.show_trashed');


        if (is_numeric($show_trashed) && $show_trashed == 1) {
            $query->where('a.state = -2');
        }
        // elseif (empty($published))
        // {
        // 	$query->where('(a.state IN (0, 1))');
        // }

        // Filter by search in title
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $query->where('a.id = ' . (int) substr($search, 3));
            } else {
                $search = $db->Quote('%' . $db->escape($search, true) . '%');

            }
        }
        // Add the list ordering clause.
        $orderCol  = $this->state->get('list.ordering', "a.id");
        $orderDirn = $this->state->get('list.direction', "ASC");

        if ($orderCol && $orderDirn) {
            $query->order($db->escape($orderCol . ' ' . $orderDirn));
        }

        return $query;
    }

    /**
     * Get an array of data items
     *
     * @return mixed Array of data items on success, false on failure.
     */
    public function getItems()
    {
        $items = parent::getItems();

        return $items;
    }


}
