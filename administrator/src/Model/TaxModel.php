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

use \Joomla\CMS\Table\Table;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\MVC\Model\AdminModel;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Filter\OutputFilter;
use \Joomla\CMS\Event\Model;
use \Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;

/**
 * Tax model.
 *
 * @since  1.0.1
 */
class TaxModel extends AdminModel
{
	/**
	 * @var    string  The prefix to use with controller messages.
	 *
	 * @since  1.0.1
	 */
	protected $text_prefix = 'COM_ALFA';

	/**
	 * @var    string  Alias to manage history control
	 *
	 * @since  1.0.1
	 */
	public $typeAlias = 'com_alfa.tax';

	/**
	 * @var    null  Item data
	 *
	 * @since  1.0.1
	 */
	protected $item = null;
	

	/**
	 * Returns a reference to the a Table object, always creating it.
	 *
	 * @param   string  $type    The table type to instantiate
	 * @param   string  $prefix  A prefix for the table class name. Optional.
	 * @param   array   $config  Configuration array for model. Optional.
	 *
	 * @return  Table    A database object
	 *
	 * @since   1.0.1
	 */
	public function getTable($type = 'Tax', $prefix = 'Administrator', $config = array())
	{
		return parent::getTable($type, $prefix, $config);
	}

	/**
	 * Method to get the record form.
	 *
	 * @param   array    $data      An optional array of data for the form to interogate.
	 * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
	 *
	 * @return  \JForm|boolean  A \JForm object on success, false on failure
	 *
	 * @since   1.0.1
	 */
	public function getForm($data = array(), $loadData = true)
	{
		// Initialise variables.
		$app = Factory::getApplication();

		// Get the form.
		$form = $this->loadForm(
								'com_alfa.tax',
								'tax',
								array(
									'control' => 'jform',
									'load_data' => $loadData 
								)
							);

		

		if (empty($form))
		{
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
		$data = Factory::getApplication()->getUserState('com_alfa.edit.tax.data', array());

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
				
				$item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_categories', 'tax_id','category_id');
				$item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_manufacturers', 'tax_id','manufacturer_id');
				$item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_places', 'tax_id','place_id');

	            $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_users', 'tax_id','user_id');
            	$item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_usergroups', 'tax_id','usergroup_id');

                // $item->tax_rules = $this->getTaxRules($item->id);//id για το getTaxRules
			
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

        $data['alias'] = $data['alias'] ?: $data['name'];

        if ($app->get('unicodeslugs') == 1){
            $data['alias'] = OutputFilter::stringUrlUnicodeSlug($data['alias']);
        } else {
            $data['alias'] = OutputFilter::stringURLSafe($data['alias']);
        }

        if (!parent::save($data))return false;

        $currentId = 0;
        if($data['id']>0){ //not a new
            $currentId = intval($data['id']);
        }else{ // is new
            $currentId = intval($this->getState($this->getName().'.id'));//get the id from setted joomla state
        }

        $assignZeroIdIfDataEmpty = true;
		AlfaHelper::setAssocsToDb($data['id'], $data['categories'], '#__alfa_tax_categories', 'tax_id','category_id',$assignZeroIdIfDataEmpty);
		AlfaHelper::setAssocsToDb($data['id'], $data['manufacturers'], '#__alfa_tax_manufacturers', 'tax_id','manufacturer_id',$assignZeroIdIfDataEmpty);
		AlfaHelper::setAssocsToDb($data['id'], $data['places'], '#__alfa_tax_places', 'tax_id','place_id',$assignZeroIdIfDataEmpty);

		AlfaHelper::setAssocsToDb($data['id'], $data['users'], '#__alfa_tax_users', 'tax_id','user_id',$assignZeroIdIfDataEmpty);
		AlfaHelper::setAssocsToDb($data['id'], $data['usergroups'], '#__alfa_tax_usergroups','tax_id', 'usergroup_id',$assignZeroIdIfDataEmpty);

        // print_r($data['tax_rules']);
        // exit;
      	// $this->setTaxRules($currentId,$data['tax_rules']);

        return true;
    }

    // public function getTaxRules($tax_id){
    //     $tax_id = intval($tax_id);
    //     if($tax_id <= 0) {
    //         return [];
    //     }

    //      //Get the database object
    //     $db = $this->getDatabase();

    //     // Build the query to select all relevant fields
    //     $query = $db->getQuery(true);
    //     $query
    //         ->select('*')
    //         ->from('#__alfa_tax_rules')
    //         ->where('tax_id = ' . $db->quote($tax_id));

    //     // Execute the query
    //     $db->setQuery($query);

    //     // Return the result as an associative array
    //     return $db->loadAssocList();
    // }

    // public function setTaxRules($tax_id, $taxes){

    //     if (!is_array($taxes) || $tax_id<=0) {
    //         return false;
    //     }

    //     $db = $this->getDatabase();

    //     //Get all existing tax IDs for the product
    //     $query = $db->getQuery(true);
    //     $query->select('id')
    //         ->from('#__alfa_tax_rules')
    //         ->where('tax_id = ' . intval($tax_id));
    //     $db->setQuery($query);
    //     $existingTaxIds = $db->loadColumn();  // Array of existing tax IDs

    //     //Extract incoming IDs from the $taxes array
    //     $incomingIds = array();
    //     foreach ($taxes as $tax) {
    //         if (isset($tax['id']) && intval($tax['id']) > 0) {//not those except new with id 0
    //             $incomingIds[] = intval($tax['id']);
    //         }
    //     }

    //     //  //Find differences
    //     $idsToDelete = array_diff($existingTaxIds, $incomingIds);

    //     //  Delete records that are no longer present in incoming taxes array
    //     if (!empty($idsToDelete)) {
    //         $query = $db->getQuery(true);
    //         $query->delete('#__alfa_tax_rules')->whereIn('id', $idsToDelete);
    //         $db->setQuery($query);
    //         $db->execute();
    //     }

    //    foreach ($taxes as $tax) {

    //        $taxObject = new \stdClass();
    //        $taxObject->id = isset($tax['id']) ? intval($tax['id']) : 0;
    //        $taxObject->tax_id = $tax_id;
    //        $taxObject->place_id     = isset($tax['place_id']) ? intval($tax['place_id']) : 0;
    //        $taxObject->category_id   = isset($tax['category_id']) ? intval($tax['category_id']) : 0;

    //         $query = $db->getQuery(true);

    //         if ($taxObject->id > 0 && in_array($taxObject->id, $existingTaxIds)) {
    //             $updateNulls = true;
    //             $db->updateObject('#__alfa_tax_rules', $taxObject, 'id', $updateNulls);
    //         }else{
    //             $db->insertObject('#__alfa_tax_rules', $taxObject);
    //         }

    //     }

    //     return true;

    // }


    // TODO: ON DELETE TO DELETE ALSO THE TAX_RULES ASSOCIATED WITH OR DO IT WITH REFERENCE TABLE #__tax id AUTOMATICALLY IN SQL

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

        return parent::prepareTable($table);

    }

}
