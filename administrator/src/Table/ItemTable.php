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


use \Joomla\CMS\Table\Table;
use \Joomla\Database\DatabaseDriver;

/**
 * Item table
 *
 * @since 1.0.1
 */
class ItemTable extends Table
{

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
		$this->typeAlias = 'com_alfa.item';
		parent::__construct('#__alfa_items', 'id', $db);
		$this->setColumnAlias('published', 'state');
		
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
//	public function bind($array, $ignore = '')
//	{
//		$date = Factory::getDate();
//		$task = Factory::getApplication()->input->get('task');
//		$user = Factory::getApplication()->getIdentity();
//
//		$input = Factory::getApplication()->input;
//		$task = $input->getString('task', '');

//		if ($array['id'] == 0 && empty($array['created_by']))
//		{
//			$array['created_by'] = $user->id;
//		}

//		$array['modified_by'] = $user->id;

//		if($array['stock'] === '')
//		{
//			$array['stock'] = NULL;
//			$this->stock = NULL;
//		}

//		return parent::bind($array, $ignore);
//	}

	/**
	 * Check if a field is unique
	 *
	 * @param   string  $field  Name of the field
	 *
	 * @return bool True if unique
	 */
	// private function isUnique ($field)
	// {
	// 	$db = $this->_db;
	// 	$query = $db->getQuery(true);

	// 	$query
	// 		->select($db->quoteName($field))
	// 		->from($db->quoteName($this->_tbl))
	// 		->where($db->quoteName($field) . ' = ' . $db->quote($this->$field))
	// 		->where($db->quoteName('id') . ' <> ' . (int) $this->{$this->_tbl_key});

	// 	$db->setQuery($query);
	// 	$db->execute();

	// 	return ($db->getNumRows() == 0) ? true : false;
	// }


}
