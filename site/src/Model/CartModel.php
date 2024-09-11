<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Site\Model;
// No direct access.
defined('_JEXEC') or die;

use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\MVC\Model\ListModel;
use \Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Layout\FileLayout;
use \Joomla\Database\ParameterType;
use \Joomla\Utilities\ArrayHelper;
use \Alfa\Component\Alfa\Site\Helper\AlfaHelper;
use \Alfa\Component\Alfa\Site\Helper\ProductHelper;

/**
 * Methods supporting a list of Alfa records.
 *
 * @since  1.0.1
 */
class CartModel extends ListModel
{
    /**
     * Constructor.
     *
     * @param array $config An optional associative array of configuration settings.
     *
     * @see    JController
     * @since  1.0.1
     */
    public function __construct($config = array())
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = array(
                'id_cart', 'a.id_cart',
                'id_shop_group', 'a.id_shop_group',
                'id_carrier', 'a.id_carrier',
                'delivery_option', 'a.delivery_option',
                'id_lang', 'a.id_lang',
                'id_address_delivery', 'a.id_address_delivery',
                'id_address_invoice', 'a.id_address_invoice',
                'id_currency', 'a.id_currency',
                'id_customer', 'a.id_customer',
                'date_add', 'a.date_add',
                'date_upd', 'a.date_upd',
                'recognize_key', 'a.recognize_key',
            );
        }

        parent::__construct($config);
    }


    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @param string $ordering Elements order
     * @param string $direction Order direction
     *
     * @return  void
     *
     * @throws  Exception
     *
     * @since   1.0.1
     */
    protected function populateState($ordering = null, $direction = null)
    {
        // List state information.
        parent::populateState('a.id', 'DESC');

        $app = Factory::getApplication();
        $list = $app->getUserState($this->context . '.list');

        $value = $app->getUserState($this->context . '.list.limit', $app->get('list_limit', 25));
        $list['limit'] = $value;

        $this->setState('list.limit', $value);

        $value = $app->input->get('limitstart', 0, 'uint');
        $this->setState('list.start', $value);

        $ordering = $this->getUserStateFromRequest($this->context . '.filter_order', 'filter_order', 'a.id');
        $direction = strtoupper($this->getUserStateFromRequest($this->context . '.filter_order_Dir', 'filter_order_Dir', 'DESC'));

        if (!empty($ordering) || !empty($direction)) {
            $list['fullordering'] = $ordering . ' ' . $direction;
        }

        $app->setUserState($this->context . '.list', $list);


        $context = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
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
     * Build an SQL query to load the list data.
     *
     * @return  DatabaseQuery
     *
     * @since   1.0.1
     */
    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select('*')
            ->from($db->quoteName('#__alfa_cart'));

        return $query;
    }

    /**
     * Method to get an array of data items
     *
     * @return  mixed An array of data on success, false on failure.
     */


   public function getItems(){

        $items = parent::getItems();

        return $items;
    }

    /**
     * Overrides the default function to check Date fields format, identified by
     * "_dateformat" suffix, and erases the field if it's not correct.
     *
     * @return void
     */
    protected function loadFormData()
    {
        $app = Factory::getApplication();
        $filters = $app->getUserState($this->context . '.filter', array());
        $error_dateformat = false;

        foreach ($filters as $key => $value) {
            if (strpos($key, '_dateformat') && !empty($value) && $this->isValidDate($value) == null) {
                $filters[$key] = '';
                $error_dateformat = true;
            }
        }

        if ($error_dateformat) {
            $app->enqueueMessage(Text::_("COM_ALFA_SEARCH_FILTER_DATE_FORMAT"), "warning");
            $app->setUserState($this->context . '.filter', $filters);
        }

        return parent::loadFormData();
    }

    /**
     * Checks if a given date is valid and in a specified format (YYYY-MM-DD)
     *
     * @param string $date Date to be checked
     *
     * @return bool
     */
    private function isValidDate($date)
    {
        $date = str_replace('/', '-', $date);
        return (date_create($date)) ? Factory::getDate($date)->format("Y-m-d") : null;
    }
}
