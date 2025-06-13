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

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use \Joomla\CMS\Table\Table;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\MVC\Model\AdminModel;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Filter\OutputFilter;
use \Joomla\CMS\Event\Model;

/**
 * Item model.
 *
 * @since  1.0.1
 */
class OrderstatusModel extends AdminModel
{

	/**
	 * @var    string  Alias to manage history control
	 *
	 */
	public $typeAlias = 'com_alfa.orderstatus';

    protected $formName = 'orderstatus';

	/**
	 * Method to get the record form.
	 *
	 * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
     *
     * @return  Form|boolean  A Form object on success, false on failure
     *
     * @since   1.6
	 */


	// protected $item = null;
	// protected $batch_copymove = false;


	public function getForm($data = array(), $loadData = true)
	{
		// Initialise variables.
		// $app = Factory::getApplication();
		// Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_users/models/fields');

		// $this->formName is item
		// Get the form.
		
		$form = $this->loadForm(
					'com_alfa.' . $this->formName, 
					$this->formName,
					array(
						'control' => 'jform',
						'load_data' => $loadData 
					)
				);
		

		if (empty($form)){
			return false;
		}

		return $form;
	}

	/**
	 * Method to get the data that should be injected in the form.
	 *
	 * @return  mixed  The data for the form.
	 *
	 * @since   1.0.1
	 */
	protected function loadFormData()
	{
		// Check the session for previously entered form data.
		$data = Factory::getApplication()->getUserState('com_alfa.edit.orderstatus.data', array());

		if (empty($data))
		{
			if ($this->item === null)
			{
				$this->item = $this->getItem();
			}

			$data = $this->item;
			
		}

		return $data;
	}


	/**
	 * Method to get a single record.
	 *
	 * @param   integer  $pk  The id of the primary key.
	 *
	 * @return  mixed    Object on success, false on failure.
	 *
	 * @since   1.0.1
	 */
  
	public function getItem($pk = null)
	{		
	
		if ($item = parent::getItem($pk))
		{


		}

		return $item;
		
	}


	/**
	* Method to save the form data.
	*
	* @param   array  $data  The form data.
	*
	* @return  boolean  True on success, False on error.
	*
	* @since   1.6
	*/
	public function save($data)
	{

		$app = Factory::getApplication();
		
		// $data['alias'] = $data['alias'] ?: $data['name'];

		// if ($app->get('unicodeslugs') == 1){
		// 	$data['alias'] = OutputFilter::stringUrlUnicodeSlug($data['alias']);
		// } else {
		// 	$data['alias'] = OutputFilter::stringURLSafe($data['alias']);
		// }

		// if ($table->load(['slug' => $data['slug']])) { //checks for duplicates
        //     $data['slug'].= '-'.$pk;//if slug exists add the id after
        //     // = OutputFilter::stringURLSafe($data['name'].'-'.$pk);
        // }
		// if ($input->get('task') == 'save2copy') {
		// 	if ($table->load(['slug' => $data['slug']])) {

		// }
		// $origTable = clone $this->getTable();

		if (!parent::save($data))return false;

		$currentId = 0;
		if($data['id']>0){ //not a new
			$currentId = intval($data['id']);
                }else{ // is new
                        $currentId = intval($this->getState($this->getName().'.id')); // get the id from the Joomla state
		}


        // $this->setPrices($currentId,$data['prices']);

		// AlfaHelper::setAssocsToDb($data['id'], $data['categories'], '#__alfa_items_categories', 'item_id','category_id');
		// AlfaHelper::setAssocsToDb($data['id'], $data['manufacturers'], '#__alfa_items_manufacturers', 'item_id','manufacturer_id');

		// AlfaHelper::setAssocsToDb($data['id'], $data['allowedUsers'], '#__alfa_items_users', 'item_id','user_id');
		// AlfaHelper::setAssocsToDb($data['id'], $data['allowedUserGroups'], '#__alfa_items_usergroups','item_id', 'usergroup_id');

		return true;
		// return parent::save($data);
	}


	
	/**
	 * Prepare and sanitise the table prior to saving.
	 *
	 * @param   Table  $table  Table Object
	 *
	 * @return  void
	 *
	 * @since   1.0.1
	 */
    protected function prepareTable($table)
    {

    	$table->modified = Factory::getDate()->toSql();
    	$table->modified_by = $this->getCurrentUser()->id;

        return parent::prepareTable($table);
        
    }


}
