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
 * Item table
 *
 * @since 1.0.1
 */
class ItemTable extends Table implements VersionableTableInterface, TaggableTableInterface
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
	 * Check if a field is unique
	 *
	 * @param   string  $field  Name of the field
	 *
	 * @return bool True if unique
	 */
	private function isUnique ($field)
	{
		$db = $this->_db;
		$query = $db->getQuery(true);

		$query
			->select($db->quoteName($field))
			->from($db->quoteName($this->_tbl))
			->where($db->quoteName($field) . ' = ' . $db->quote($this->$field))
			->where($db->quoteName('id') . ' <> ' . (int) $this->{$this->_tbl_key});

		$db->setQuery($query);
		$db->execute();

		return ($db->getNumRows() == 0) ? true : false;
	}

	/**
	 * Constructor
	 *
	 * @param   JDatabase  &$db  A database connector object
	 */
	public function __construct(DatabaseDriver $db)
	{
		$this->typeAlias = 'com_alfa.item';
		parent::__construct('#__alfa_items', 'id', $db);
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

		if($array['stock'] === '')
		{
			$array['stock'] = NULL;
			$this->stock = NULL;
		}

		// Support for alias field: alias
		// if (empty($array['alias']))
		// {
		// 	if (empty($array['name']))
		// 	{
		// 		$array['alias'] = OutputFilter::stringURLSafe(date('Y-m-d H:i:s'));
		// 	}
		// 	else
		// 	{
		// 		if(Factory::getConfig()->get('unicodeslugs') == 1)
		// 		{
		// 			$array['alias'] = OutputFilter::stringURLUnicodeSlug(trim($array['name']));
		// 		}
		// 		else
		// 		{
		// 			$array['alias'] = OutputFilter::stringURLSafe(trim($array['name']));
		// 		}
		// 	}
		// }


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

		if (!$user->authorise('core.admin', 'com_alfa.item.' . $array['id']))
		{
			$actions         = Access::getActionsFromFile(
				JPATH_ADMINISTRATOR . '/components/com_alfa/access.xml',
				"/access/section[@name='item']/"
			);
			$default_actions = Access::getAssetRules('com_alfa.item.' . $array['id'])->getData();
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


	//     public function store($updateNulls = true)
    // {
    //     $date   = Factory::getDate()->toSql();
    //     $userId = $this->getCurrentUser()->id;

    //     // Set created date if not set.
    //     if (!(int) $this->created) {
    //         $this->created = $date;
    //     }

    //     if ($this->id) {
    //         // Existing item
    //         $this->modified_by = $userId;
    //         $this->modified    = $date;
    //     } else {
    //         // Field created_by field can be set by the user, so we don't touch it if it's set.
    //         if (empty($this->created_by)) {
    //             $this->created_by = $userId;
    //         }

    //         if (!(int) $this->modified) {
    //             $this->modified = $date;
    //         }

    //         if (empty($this->modified_by)) {
    //             $this->modified_by = $userId;
    //         }
    //     }

    //     // Store utf8 email as punycode
    //     if ($this->email_to !== null) {
    //         $this->email_to = PunycodeHelper::emailToPunycode($this->email_to);
    //     }

    //     // Convert IDN urls to punycode
    //     if ($this->webpage !== null) {
    //         $this->webpage = PunycodeHelper::urlToPunycode($this->webpage);
    //     }

    //     // Verify that the alias is unique
    //     $table = new self($this->getDbo(), $this->getDispatcher());

    //     if ($table->load(['alias' => $this->alias, 'catid' => $this->catid]) && ($table->id != $this->id || $this->id == 0)) {
    //         // Is the existing contact trashed?
    //         $this->setError(Text::_('COM_CONTACT_ERROR_UNIQUE_ALIAS'));

    //         if ($table->published === -2) {
    //             $this->setError(Text::_('COM_CONTACT_ERROR_UNIQUE_ALIAS_TRASHED'));
    //         }

    //         return false;
    //     }

    //     return parent::store($updateNulls);
    // }

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
		
		// Check if alias is unique
		if (!$this->isUnique('alias'))
		{
			$count = 0;
			$currentAlias =  $this->alias;
			while(!$this->isUnique('alias')){
				$this->alias = $currentAlias . '-' . $count++;
			}
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
