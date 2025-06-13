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
 * Shipment model.
 *
 * @since  1.0.1
 */
class ShipmentModel extends AdminModel
{

	/**
	 * @var    string  Alias to manage history control
	 *
	 */
	public $typeAlias = 'com_alfa.shipment';

	protected $formName = 'shipment';

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
	public function getTable($type = 'Shipment', $prefix = 'Administrator', $config = array())
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
		// Form::addFieldPath(JPATH_ADMINISTRATOR . '/cfomponents/com_users/models/fields');

		// Get the form.
		$form = $this->loadForm(
					'com_alfa.' . $this->formName, 
					$this->formName,
					array(
						'control' => 'jform',
						'load_data' => $loadData 
					)
				);
        
		// Get ID of the article from input
		$idFromInput = $app->getInput()->getInt('id', 0);

		// On edit order, we get ID of order from order.id state, but on save, we use data from input
    	$id = (int)$this->getState($this->formName.'.id', $idFromInput);

		if (empty($form)){
			return false;
		}

		$item = ($this->item === null ? $this->getItem() : $this->item);
		
		AlfaHelper::addPluginForm($form, $data, $item ,'shipments');

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
		$data = Factory::getApplication()->getUserState('com_alfa.edit.shipment.data', array());

		if (empty($data))
		{
			$data = ($this->item === null ? $this->getItem() : $this->item);

            $data->shipmentsparams = $data->params;

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
				// Do any processing on fields here if needed
                $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_categories', 'shipment_id','category_id');
                $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_manufacturers', 'shipment_id','manufacturer_id');
                $item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_places', 'shipment_id','place_id');

                $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_users', 'shipment_id','user_id');
                $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_shipment_usergroups', 'shipment_id','usergroup_id');
			}

			return $item;
	}


    public function save($data)
    {
        $app = Factory::getApplication();
        $db = $this->getDatabase();

        $input = $app->getInput();

        $data['params'] = json_encode($data['shipmentsparams']);

        if (!parent::save($data))return false;

        $currentId = 0;
        if($data['id']>0){ //not a new
            $currentId = intval($data['id']);
        }else{ // is new
            $currentId = intval($this->getState($this->getName().'.id'));//get the id from set joomla state.
        }

        //Category/manufacturer etc associations
        $assignZeroIdIfDataEmpty = true;
        AlfaHelper::setAssocsToDb($currentId, $data['categories'], '#__alfa_shipment_categories', 'shipment_id','category_id',$assignZeroIdIfDataEmpty);
        AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'], '#__alfa_shipment_manufacturers', 'shipment_id','manufacturer_id',$assignZeroIdIfDataEmpty);
        AlfaHelper::setAssocsToDb($currentId, $data['places'], '#__alfa_shipment_places', 'shipment_id','place_id',$assignZeroIdIfDataEmpty);
        AlfaHelper::setAssocsToDb($currentId, $data['users'], '#__alfa_shipment_users', 'shipment_id','user_id',$assignZeroIdIfDataEmpty);
        AlfaHelper::setAssocsToDb($currentId, $data['usergroups'], '#__alfa_shipment_usergroups','shipment_id', 'usergroup_id',$assignZeroIdIfDataEmpty);

        return true;

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
	$user = $this->getCurrentUser();

	if ($table->id == 0 && empty($table->created_by))
	{
	    $table->created_by = $user->id;
	}

    	$table->modified = Factory::getDate()->toSql();
    	$table->modified_by = $user->id;

        return parent::prepareTable($table);
        
    }



}
