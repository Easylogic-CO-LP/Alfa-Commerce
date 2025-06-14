<?php
/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Table;
// No direct access
defined('_JEXEC') or die;

use \Joomla\Utilities\ArrayHelper;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Access\Access;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Table\Table as Table;
use \Joomla\CMS\Versioning\VersionableTableInterface;
use Joomla\CMS\Tag\TaggableTableInterface;
use Joomla\CMS\Tag\TaggableTableTrait;
use \Joomla\Database\DatabaseDriver;
use \Joomla\CMS\Filter\OutputFilter;
use \Joomla\CMS\Filesystem\File;
use \Joomla\Registry\Registry;
use \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Joomla\CMS\Helper\ContentHelper;


/**
 * Coupon table
 *
 * @since 1.0.1
 */
class CouponTable extends Table implements VersionableTableInterface, TaggableTableInterface
{
	use TaggableTableTrait;

	/**
     * Indicates that columns fully support the NULL value in the database
     *
     * @var    boolean
     * @since  4.0.0
     */
    protected $_supportNullValue = true;

	
	/**
	 * Constructor
	 *
	 * @param   JDatabase  &$db  A database connector object
	 */
	public function __construct(DatabaseDriver $db)
	{
		$this->typeAlias = 'com_alfa.coupon';
		parent::__construct('#__alfa_coupons', 'id', $db);
		$this->setColumnAlias('published', 'state');
		
	}

	/**
	 * Get the type alias for the history table
	 *
	 * @return  string  The alias as described above
	 *
	 * @since   1.0.1
	 */
	public function getTypeAlias()
	{
		return $this->typeAlias;
	}

	/**
	 * Overloaded bind function to pre-process the params.
	 *
	 * @param   array  $array   Named array
	 * @param   mixed  $ignore  Optional array or list of parameters to ignore
	 *
	 * @return  boolean  True on success.
	 *
	 * @see     Table:bind
	 * @since   1.0.1
	 * @throws  \InvalidArgumentException
	 */
	public function bind($array, $ignore = '')
	{
		$date = Factory::getDate();
		$task = Factory::getApplication()->input->get('task');
		$user = Factory::getApplication()->getIdentity();
		
		$input = Factory::getApplication()->input;
		$task = $input->getString('task', '');

		if ($array['id'] == 0 && empty($array['created_by']))
		{
			$array['created_by'] = Factory::getUser()->id;
		}

		if ($array['id'] == 0 && empty($array['modified_by']))
		{
			$array['modified_by'] = Factory::getUser()->id;
		}

		if ($task == 'apply' || $task == 'save')
		{
			$array['modified_by'] = Factory::getUser()->id;
		}

		if($array['num_of_uses'] === '')
		{
			$array['num_of_uses'] = NULL;
			$this->num_of_uses = NULL;
		}

		// Support for multiple field: value_type
		if (isset($array['value_type']))
		{
			if (is_array($array['value_type']))
			{
				$array['value_type'] = implode(',',$array['value_type']);
			}
			elseif (strpos($array['value_type'], ',') != false)
			{
				$array['value_type'] = explode(',',$array['value_type']);
			}
			elseif (strlen($array['value_type']) == 0)
			{
				$array['value_type'] = '';
			}
		}
		else
		{
			$array['value_type'] = '';
		}

		if($array['min_value'] === '')
		{
			$array['min_value'] = NULL;
			$this->min_value = NULL;
		}

		if($array['max_value'] === '')
		{
			$array['max_value'] = NULL;
			$this->max_value = NULL;
		}

		// Support for multiple field: hidden
		if (isset($array['hidden']))
		{
			if (is_array($array['hidden']))
			{
				$array['hidden'] = implode(',',$array['hidden']);
			}
			elseif (strpos($array['hidden'], ',') != false)
			{
				$array['hidden'] = explode(',',$array['hidden']);
			}
			elseif (strlen($array['hidden']) == 0)
			{
				$array['hidden'] = '';
			}
		}
		else
		{
			$array['hidden'] = '';
		}

		// Support for empty date field: start_date
		if($array['start_date'] == '0000-00-00' || empty($array['start_date']))
		{
			$array['start_date'] = NULL;
			$this->start_date = NULL;
		}

		// Support for empty date field: end_date
		if($array['end_date'] == '0000-00-00' || empty($array['end_date']))
		{
			$array['end_date'] = NULL;
			$this->end_date = NULL;
		}

		// Support for multiple field: associate_to_new_users
		if (isset($array['associate_to_new_users']))
		{
			if (is_array($array['associate_to_new_users']))
			{
				$array['associate_to_new_users'] = implode(',',$array['associate_to_new_users']);
			}
			elseif (strpos($array['associate_to_new_users'], ',') != false)
			{
				$array['associate_to_new_users'] = explode(',',$array['associate_to_new_users']);
			}
			elseif (strlen($array['associate_to_new_users']) == 0)
			{
				$array['associate_to_new_users'] = '';
			}
		}
		else
		{
			$array['associate_to_new_users'] = '';
		}

		// Support for multiple field: user_associated
		if (isset($array['user_associated']))
		{
			if (is_array($array['user_associated']))
			{
				$array['user_associated'] = implode(',',$array['user_associated']);
			}
			elseif (strpos($array['user_associated'], ',') != false)
			{
				$array['user_associated'] = explode(',',$array['user_associated']);
			}
			elseif (strlen($array['user_associated']) == 0)
			{
				$array['user_associated'] = '';
			}
		}
		else
		{
			$array['user_associated'] = '';
		}

		if (isset($array['params']) && is_array($array['params']))
		{
			$registry = new Registry;
			$registry->loadArray($array['params']);
			$array['params'] = (string) $registry;
		}

		if (isset($array['metadata']) && is_array($array['metadata']))
		{
			$registry = new Registry;
			$registry->loadArray($array['metadata']);
			$array['metadata'] = (string) $registry;
		}

		if (!$user->authorise('core.admin', 'com_alfa.coupon.' . $array['id']))
		{
			$actions         = Access::getActionsFromFile(
				JPATH_ADMINISTRATOR . '/components/com_alfa/access.xml',
				"/access/section[@name='coupon']/"
			);
			$default_actions = Access::getAssetRules('com_alfa.coupon.' . $array['id'])->getData();
			$array_jaccess   = array();

			foreach ($actions as $action)
			{
				if (key_exists($action->name, $default_actions))
				{
					$array_jaccess[$action->name] = $default_actions[$action->name];
				}
			}

			$array['rules'] = $this->JAccessRulestoArray($array_jaccess);
		}

		// Bind the rules for ACL where supported.
		if (isset($array['rules']) && is_array($array['rules']))
		{
			$this->setRules($array['rules']);
		}

		return parent::bind($array, $ignore);
	}

	/**
	 * Method to store a row in the database from the Table instance properties.
	 *
	 * If a primary key value is set the row with that primary key value will be updated with the instance property values.
	 * If no primary key value is set a new row will be inserted into the database with the properties from the Table instance.
	 *
	 * @param   boolean  $updateNulls  True to update fields even if they are null.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0.1
	 */
	public function store($updateNulls = true)
	{
		
		return parent::store($updateNulls);
	}

	/**
	 * This function convert an array of Access objects into an rules array.
	 *
	 * @param   array  $jaccessrules  An array of Access objects.
	 *
	 * @return  array
	 */
	private function JAccessRulestoArray($jaccessrules)
	{
		$rules = array();

		foreach ($jaccessrules as $action => $jaccess)
		{
			$actions = array();

			if ($jaccess)
			{
				foreach ($jaccess->getData() as $group => $allow)
				{
					$actions[$group] = ((bool)$allow);
				}
			}

			$rules[$action] = $actions;
		}

		return $rules;
	}

	/**
	 * Overloaded check function
	 *
	 * @return bool
	 */
	public function check()
	{
		// If there is an ordering column and this is a new row then get the next ordering value
		if (property_exists($this, 'ordering') && $this->id == 0)
		{
			$this->ordering = self::getNextOrder();
		}
		
		

		return parent::check();
	}

	/**
	 * Define a namespaced asset name for inclusion in the #__assets table
	 *
	 * @return string The asset name
	 *
	 * @see Table::_getAssetName
	 */
	protected function _getAssetName()
	{
		$k = $this->_tbl_key;

		return $this->typeAlias . '.' . (int) $this->$k;
	}

	/**
	 * Returns the parent asset's id. If you have a tree structure, retrieve the parent's id using the external key field
	 *
	 * @param   Table   $table  Table name
	 * @param   integer  $id     Id
	 *
	 * @see Table::_getAssetParentId
	 *
	 * @return mixed The id on success, false on failure.
	 */
	protected function _getAssetParentId($table = null, $id = null)
	{
		// We will retrieve the parent-asset from the Asset-table
		$assetParent = Table::getInstance('Asset');

		// Default: if no asset-parent can be found we take the global asset
		$assetParentId = $assetParent->getRootId();

		// The item has the component as asset-parent
		$assetParent->loadByName('com_alfa');

		// Return the found asset-parent-id
		if ($assetParent->id)
		{
			$assetParentId = $assetParent->id;
		}

		return $assetParentId;
	}

	//XXX_CUSTOM_TABLE_FUNCTION

	
    /**
     * Delete a record by id
     *
     * @param   mixed  $pk  Primary key value to delete. Optional
     *
     * @return bool
     */
    public function delete($pk = null)
    {
        $this->load($pk);
        $result = parent::delete($pk);
        
        return $result;
    }
}
