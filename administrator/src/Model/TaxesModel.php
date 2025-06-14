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
 * Methods supporting a list of Taxes records.
 *
 * @since  1.0.1
 */
class TaxesModel extends ListModel
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
                'name', 'a.name',
                'value', 'a.value',
                'state', 'a.state',
                'ordering', 'a.ordering',
                'created_by', 'a.created_by',
                'modified_by', 'a.modified_by',
            ];
        }

        parent::__construct($config);
    }


    /**
     * Method to auto-populate the model state.
     *
     * Note. Calling getState in this method will result in recursion.
     *
     * @param   string  $ordering   Elements tax
     * @param   string  $direction  Tax direction
     *
     * @return void
     *
     * @throws Exception
     */
    protected function populateState($ordering = null, $direction = null)
    {
        // List state information.
        parent::populateState("a.id", "ASC");

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
        $id .= ':' . $this->getState('filter.state');


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
        // Create a new query object.
        $db    = $this->getDbo();
        $query = $db->getQuery(true);

        // Select the required fields from the table.
        $query->select(
            $this->getState(
                'list.select',
                'DISTINCT a.*'
            )
        );
        $query->from('`#__alfa_taxes` AS a');

        // Join over the users for the checked out user
        $query->select("uc.name AS uEditor");
        $query->join("LEFT", "#__users AS uc ON uc.id=a.checked_out");

        // Join over the user field 'created_by'
        $query->select('`created_by`.name AS `created_by`');
        $query->join('LEFT', '#__users AS `created_by` ON `created_by`.id = a.`created_by`');

        // Join over the user field 'modified_by'
        $query->select('`modified_by`.name AS `modified_by`');
        $query->join('LEFT', '#__users AS `modified_by` ON `modified_by`.id = a.`modified_by`');


        // Join over category IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT tcat.category_id SEPARATOR ',') AS tax_category_IDs")
            ->join("LEFT", "#__alfa_tax_categories as tcat ON a.id = tcat.tax_id");

        $query->select("GROUP_CONCAT(DISTINCT cat.name SEPARATOR ', ') AS category_names")
            ->join("LEFT", "#__alfa_categories as cat ON tcat.category_id = cat.id");


        // Join over manufacturer IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT tman.manufacturer_id SEPARATOR ',') AS tax_manufacturer_IDs")
            ->join("LEFT", "#__alfa_tax_manufacturers as tman ON a.id = tman.tax_id");

        $query->select("GROUP_CONCAT(DISTINCT man.name SEPARATOR ', ') AS manufacturer_names")
            ->join("LEFT", "#__alfa_manufacturers as man ON tman.manufacturer_id = man.id");


        // Join over users IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT tu.user_id SEPARATOR ',') AS tax_user_IDs")
            ->join("LEFT", "#__alfa_tax_users as tu ON a.id = tu.tax_id");

        $query->select("GROUP_CONCAT(DISTINCT u.name SEPARATOR ', ') AS user_names")
            ->join("LEFT", "#__users as u ON tu.user_id = u.id");

        // Join over places IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT tpl.place_id SEPARATOR ',') AS tax_place_IDs")
            ->join("LEFT", "#__alfa_tax_places as tpl ON a.id = tpl.tax_id");

        $query->select("GROUP_CONCAT(DISTINCT pl.name SEPARATOR ', ') AS place_names")
            ->join("LEFT", "#__alfa_places as pl ON tpl.place_id = pl.id");



        // Join over usergroups IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT tug.usergroup_id SEPARATOR ',') AS tax_usergroup_IDs")
            ->join("LEFT", "#__alfa_tax_usergroups as tug ON a.id = tug.tax_id");

        $query->select("GROUP_CONCAT(DISTINCT ug.name SEPARATOR ', ') AS usergroup_names")
            ->join("LEFT", "#__alfa_usergroups as ug ON tug.usergroup_id = ug.id");


        // Grouping by item id.
        $query->group("a.id");

        // Filter by published state
        $published = $this->getState('filter.state');


        if (is_numeric($published)) {
            $query->where('a.state = ' . (int) $published);
        } elseif (empty($published)) {
            $query->where('(a.state IN (0, 1))');
        }

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
