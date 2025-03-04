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
use Joomla\CMS\Language\Multilanguage;
use \Joomla\CMS\Table\Table;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\MVC\Model\AdminModel;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Filter\OutputFilter;
use \Joomla\CMS\Event\Model;
use Joomla\CMS\Language\LanguageHelper;

/**
 * Payment model.
 *
 * @since  1.0.1
 */
class PaymentModel extends AdminModel
{

	/**
	 * @var    string  Alias to manage history control
	 *
	 */
	public $typeAlias = 'com_alfa.payment';

    	protected $formName = 'payment';

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


	protected $payment = null;

	public function getForm($data = array(), $loadData = true)
	{

		// Initialise variables.
		$app = Factory::getApplication();
		// Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_users/models/fields');

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

		// Load the 'alfa-payments' plugin params inside the form base on the type.
		//  TODO: PUT IN HELPER
		$pluginGroup = 'alfa-payments';

		$pluginName = $data['type'] ?? $form->getValue('type'); //$data when we save but when we open the form we get it from getValue
		
		$paramsFile = JPATH_ROOT . '/plugins/'.$pluginGroup.'/'.$pluginName.'/params/params.xml';

		$app->getLanguage()->load('plg_'.$pluginGroup.'_'.$pluginName);
	    	
        if (file_exists($paramsFile)) {
            // Load the XML file into the form.
            $form->loadFile($paramsFile, false);
        }

        //set the plugin data
        $data = $this->getItem();
        $payment_params = $data->params;

        foreach($payment_params as $index=>$payment_param){
            $form->setValue($index,'paymentsparams',$payment_param);
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

        //        exit;
		// Check the session for previously entered form data.
		$data = Factory::getApplication()->getUserState('com_alfa.edit.item.data', array());

		if (empty($data))
		{
			if ($this->item === null)
			{
				$this->item = $this->getItem();
			}

			$data = $this->item;
            $data->paymentsparams = $data->params;
//            echo "<pre>";
//            print_r($data->paymentsparams);
//            echo "</pre>";
//            exit;

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
//        exit;
		
		if ($item = parent::getItem($pk))
		{
			if (isset($item->params))
			{
//                exit;
				//$payment_params = json_decode($item->params);
//                print_r($item);
//                exit;
//                $item->paymentsparams['test'] = 2;
//                print_r($item->test);

//                $item->paymentsparams = $item->params;
              //  exit;
			}
            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_categories', 'payment_id','category_id');
            $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_manufacturers', 'payment_id','manufacturer_id');
            $item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_places', 'payment_id','place_id');

            $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_users', 'payment_id','user_id');
            $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_payment_usergroups', 'payment_id','usergroup_id');

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
//        exit;
		$app = Factory::getApplication();
		$db = $this->getDatabase();

		$input = $app->getInput();
//exit;

		// print_r($input->get('jform', [], 'array'));
		// exit;
		// $data['alias'] = $data['alias'] ?: $data['name'];

		// if ($app->get('unicodeslugs') == 1){
		// 	$data['alias'] = OutputFilter::stringUrlUnicodeSlug($data['alias']);
		// } else {
		// 	$data['alias'] = OutputFilter::stringURLSafe($data['alias']);
		// }

//		print_r($data['paymentsparams']);
//		exit;

		$data['params'] = json_encode($data['paymentsparams']);

        // echo "<pre>";
        // print_r($data['params']);
        // echo "</pre>";
        // exit;


		if (!parent::save($data))return false;

		$currentId = 0;
		if($data['id']>0){ //not a new
			$currentId = intval($data['id']);
		}else{ // is new
			$currentId = intval($this->getState($this->getName().'.id'));//get the id from setted joomla state
		}

	        //Category/manufacturer etc associations
	        $assignZeroIdIfDataEmpty = true;
	        AlfaHelper::setAssocsToDb($currentId, $data['categories'], '#__alfa_payment_categories', 'payment_id','category_id',$assignZeroIdIfDataEmpty);
	        AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'], '#__alfa_payment_manufacturers', 'payment_id','manufacturer_id',$assignZeroIdIfDataEmpty);
	        AlfaHelper::setAssocsToDb($currentId, $data['places'], '#__alfa_payment_places', 'payment_id','place_id',$assignZeroIdIfDataEmpty);

	        AlfaHelper::setAssocsToDb($currentId, $data['users'], '#__alfa_payment_users', 'payment_id','user_id',$assignZeroIdIfDataEmpty);
	        AlfaHelper::setAssocsToDb($currentId, $data['usergroups'], '#__alfa_payment_usergroups','payment_id', 'usergroup_id',$assignZeroIdIfDataEmpty);

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
