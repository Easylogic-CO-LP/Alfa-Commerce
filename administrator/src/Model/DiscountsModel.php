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

use \Joomla\CMS\MVC\Model\ListModel;
use \Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\Database\ParameterType;
use \Joomla\Utilities\ArrayHelper;
use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;

/**
 * Methods supporting a list of Discounts records.
 *
 * @since  1.0.1
 */
class DiscountsModel extends ListModel
{
	/**
	* Constructor.
	*
	* @param   array  $config  An optional associative array of configuration settings.
	*
	* @see        JController
	* @since      1.6
	*/
	public function __construct($config = array())
	{
		if (empty($config['filter_fields']))
		{
			$config['filter_fields'] = array(
				'id', 'a.id',
				'ordering', 'a.ordering',
				'created_by', 'a.created_by',
				'modified_by', 'a.modified_by',
				'name', 'a.name',
				'state', 'a.state',
			);
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
		parent::populateState('id', 'ASC');

		$context = $this->getUserStateFromRequest($this->context.'.filter.search', 'filter_search');
		$this->setState('filter.search', $context);

		// Split context into component and optional section
		if (!empty($context))
		{
			$parts = FieldsHelper::extract($context);

			if ($parts)
			{
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
				'list.select', 'DISTINCT a.*'
			)
		);
		$query->from('`#__alfa_discounts` AS a');
		
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
        $query->select("GROUP_CONCAT(DISTINCT dcat.category_id SEPARATOR ',') AS discount_category_IDs")
            ->join("LEFT", "#__alfa_discount_categories as dcat ON a.id = dcat.discount_id");

        $query->select("GROUP_CONCAT(DISTINCT cat.name SEPARATOR ', ') AS category_names")
            ->join("LEFT", "#__alfa_categories as cat ON dcat.category_id = cat.id");

// Join over manufacturer IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT dman.manufacturer_id SEPARATOR ',') AS discount_manufacturer_IDs")
            ->join("LEFT", "#__alfa_discount_manufacturers as dman ON a.id = dman.discount_id");

        $query->select("GROUP_CONCAT(DISTINCT man.name SEPARATOR ', ') AS manufacturer_names")
            ->join("LEFT", "#__alfa_manufacturers as man ON dman.manufacturer_id = man.id");

// Join over users IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT du.user_id SEPARATOR ',') AS discount_user_IDs")
            ->join("LEFT", "#__alfa_discount_users as du ON a.id = du.discount_id");

        $query->select("GROUP_CONCAT(DISTINCT u.name SEPARATOR ', ') AS user_names")
            ->join("LEFT", "#__users as u ON du.user_id = u.id");

        // Join over places IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT dpl.place_id SEPARATOR ',') AS discount_place_IDs")
            ->join("LEFT", "#__alfa_discount_places as dpl ON a.id = dpl.discount_id");

        $query->select("GROUP_CONCAT(DISTINCT pl.name SEPARATOR ', ') AS place_names")
            ->join("LEFT", "#__alfa_places as pl ON dpl.place_id = pl.id");


// Join over usergroups IDs and names.
        $query->select("GROUP_CONCAT(DISTINCT dug.usergroup_id SEPARATOR ',') AS discount_usergroup_IDs")
            ->join("LEFT", "#__alfa_discount_usergroups as dug ON a.id = dug.discount_id");

        $query->select("GROUP_CONCAT(DISTINCT ug.name SEPARATOR ', ') AS usergroup_names")
            ->join("LEFT", "#__alfa_usergroups as ug ON dug.usergroup_id = ug.id");

        // Grouping by item id.
        $query->group("a.id");



        // Filter by published state
		$published = $this->getState('filter.state');

		if (is_numeric($published))
		{
			$query->where('a.state = ' . (int) $published);
		}
		elseif (empty($published))
		{
			$query->where('(a.state IN (0, 1))');
		}

		// Filter by search in title
		$search = $this->getState('filter.search');

		if (!empty($search))
		{
			if (stripos($search, 'id:') === 0)
			{
				$query->where('a.id = ' . (int) substr($search, 3));
			}
			else
			{
				$search = $db->Quote('%' . $db->escape($search, true) . '%');
				$query->where('( a.name LIKE ' . $search . ' )');
			}
		}
		
		// Add the list ordering clause.
		$orderCol  = $this->state->get('list.ordering', 'id');
		$orderDirn = $this->state->get('list.direction', 'ASC');

		if ($orderCol && $orderDirn)
		{
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
