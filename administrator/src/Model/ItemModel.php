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
class ItemModel extends AdminModel
{

	/**
	 * @var    string  Alias to manage history control
	 *
	 */
	public $typeAlias = 'com_alfa.item';

    protected $formName = 'item';

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


	protected $item = null;
	protected $batch_copymove = false;

    protected $batch_commands = [
        'category_id' => 'batchCategory',
        'manufacturer_id' => 'batchManufacturer',
        'user_id' => 'batchUser',
        'usergroup_id' => 'batchUserGroup',
    ];

     protected function batchUser($value, $pks, $contexts)
    {
	    $app = Factory::getApplication();

	    if(sizeof($value) == 1 && $value[0]==''){
	    	$app->enqueueMessage('Users not changed', 'info');
	    	return true;
	    }

        foreach ($pks as $id) {
        	AlfaHelper::setAllowedUsers($id, $value, '#__alfa_items_users', 'item_id');
        }

		$app->enqueueMessage('Users setted successfully', 'info');

        return true;
    }

     protected function batchUserGroup($value, $pks, $contexts)
    {
	    $app = Factory::getApplication();

	    if(sizeof($value) == 1 && $value[0]==''){
	    	$app->enqueueMessage('Usergroup not changed', 'info');
	    	return true;
	    }

        foreach ($pks as $id) {
        	AlfaHelper::setAllowedUserGroups($id, $value, '#__alfa_items_usergroups', 'item_id');
        }

		$app->enqueueMessage('Usergroup setted successfully', 'info');

        return true;
    }

    protected function batchManufacturer($value, $pks, $contexts)
    {
	    $app = Factory::getApplication();

	    if(sizeof($value) == 1 && $value[0]==''){
	    	$app->enqueueMessage('Manufacturers not changed', 'info');
	    	return true;
	    }

        foreach ($pks as $id) {
        
        	$this->setManufacturers($id,$value);

        }

		$app->enqueueMessage('Manufacturers setted successfully', 'info');

        return true;
    }

	protected function batchCategory($value, $pks, $contexts)
    {
	    $app = Factory::getApplication();
   		
	    if(sizeof($value) == 1 && $value[0]==''){
	    	$app->enqueueMessage('Categories not changed', 'info');
	    	return true;
	    }

        foreach ($pks as $id) {
          $this->setCategories($id,$value);
        }

		$app->enqueueMessage('Categories setted successfully', 'info');
   
        return true;
    }


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

        // Modify the form based on access controls.
        // if (!$this->canEditState((object) $data)) {
        //     // Disable fields for display.
        //     $form->setFieldAttribute('featured', 'disabled', 'true');
        //     $form->setFieldAttribute('ordering', 'disabled', 'true');
        //     $form->setFieldAttribute('published', 'disabled', 'true');

        //     // Disable fields while saving.
        //     // The controller has already verified this is a record you can edit.
        //     $form->setFieldAttribute('featured', 'filter', 'unset');
        //     $form->setFieldAttribute('ordering', 'filter', 'unset');
        //     $form->setFieldAttribute('published', 'filter', 'unset');
        // }

        // // Don't allow to change the created_by user if not allowed to access com_users.
        // if (!$this->getCurrentUser()->authorise('core.manage', 'com_users')) {
        //     $form->setFieldAttribute('created_by', 'filter', 'unset');
        // }

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
		$data = Factory::getApplication()->getUserState('com_alfa.edit.item.data', array());

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
				if (isset($item->params))
				{
					$item->params = json_encode($item->params);
				}

				$item->categories = $this->getCategories($item->id);
				$item->manufacturers = $this->getManufacturers($item->id);
				$item->prices = $this->getPrices($item->id);
	            $item->allowedUsers = AlfaHelper::getAllowedUsers($item->id, '#__alfa_items_users', 'item_id');
            	$item->allowedUserGroups = AlfaHelper::getAllowedUserGroups($item->id, '#__alfa_items_usergroups', 'item_id');

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
			$currentId = intval($this->getState($this->getName().'.id'));//get the id from setted joomla state
		}


		$this->setCategories($currentId,$data['categories']);
        $this->setManufacturers($currentId,$data['manufacturers']);
        $this->setPrices($currentId,$data['prices']);
		AlfaHelper::setAllowedUsers($data['id'], $data['allowedUsers'], '#__alfa_items_users', 'item_id');
		AlfaHelper::setAllowedUserGroups($data['id'], $data['allowedUserGroups'], '#__alfa_items_usergroups', 'item_id');

		return true;
		// return parent::save($data);
	}


	public function getManufacturers($id){
    	$id = intval($id);
    	if($id<=0){return [];}
		
		$db = Factory::getDbo();
		$query = $db->getQuery(true);
        $query
            ->select('c.manufacturer_id')
            ->from('#__alfa_items_manufacturers as c')
            ->where('c.product_id= '. ($id));

        $db->setQuery($query);
        return $db->loadColumn();
	}


	public function getCategories($id){
		$id = intval($id);
    	if($id<=0){return [];}

    	// Factory::getContainer()->get('DatabaseDriver');
		$db = $this->getDatabase();

		$query = $db->getQuery(true);
        $query
            ->select('c.category_id')
            ->from('#__alfa_items_categories as c')
            ->where('c.product_id = '. ($id));

        $db->setQuery($query);
        return $db->loadColumn();
	}


	public function getPrices($id){
	    $id = intval($id);
	    if($id <= 0) {
	        return [];
	    }

	    // Get the database object
	    $db = $this->getDatabase();

	    // Build the query to select all relevant fields
	    $query = $db->getQuery(true);
	    $query
	        ->select('*')
	        ->from('#__alfa_items_prices')
	        ->where('product_id = ' . $db->quote($id));

	    // Execute the query
	    $db->setQuery($query);

	    // Return the result as an associative array
	    return $db->loadAssocList();
	}


	public function setPrices($productId, $prices){
	    if (!is_array($prices) || $productId<=0) {
	        return false;
	    }

	    $db = $this->getDatabase();


	    // Get all existing price IDs for the product
	    $query = $db->getQuery(true);
	    $query->select('id')
	          ->from('#__alfa_items_prices')
	          ->where('product_id = ' . intval($productId));
	    $db->setQuery($query);
	    $existingPriceIds = $db->loadColumn();  // Array of existing price IDs

	    // Extract incoming IDs from the $prices array
	    $incomingIds = array();
	    foreach ($prices as $price) {
	        if (isset($price['id']) && intval($price['id']) > 0) {//not those except new with id 0
	            $incomingIds[] = intval($price['id']);
	        }
	    }

	    // Find differences
	    $idsToDelete = array_diff($existingPriceIds, $incomingIds);

	    //  Delete records that are no longer present in incoming prices array
	    if (!empty($idsToDelete)) {
	        $query = $db->getQuery(true);
	        $query->delete('#__alfa_items_prices')->whereIn('id ', $idsToDelete);
	        $db->setQuery($query);
	        $db->execute();
	    }

	    foreach ($prices as $price) {

	    	$priceObject = new \stdClass();
	        $priceObject->id        = isset($price['id']) ? intval($price['id']) : 0;
	        $priceObject->product_id = $productId;
	        $priceObject->value     = isset($price['value']) ? floatval($price['value']) : 0.0;
	        $priceObject->country_id    = isset($price['country_id']) ? intval($price['country_id']) : 0;
	        $priceObject->usergroup_id  = isset($price['usergroup_id']) ? intval($price['usergroup_id']) : 0;
	        $priceObject->user_id         = isset($price['user_id']) ? intval($price['user_id']) : 0;
	        $priceObject->currency_id   = isset($price['currency_id']) ? intval($price['currency_id']) : 0;
	        $priceObject->modify = isset($price['modify']) ? intval($price['modify']) : 0;
	        $priceObject->modify_function = isset($price['modify_function']) ? $price['modify_function'] : NULL;
	        $priceObject->modify_type = isset($price['modify_type']) ? $price['modify_type'] : NULL;
	        $priceObject->publish_up  = !empty($price['publish_up']) ? Factory::getDate($price['publish_up'])->toSql() : NULL;
	        $priceObject->publish_down    = !empty($price['publish_down']) ? Factory::getDate($price['publish_down'])->toSql() : NULL;
	        $priceObject->quantity_start = isset($price['quantity_start']) ? intval($price['quantity_start']) : NULL;
	        $priceObject->quantity_end = isset($price['quantity_end']) ? intval($price['quantity_end']) : NULL;

	        $query = $db->getQuery(true);

	        if ($priceObject->id > 0 && in_array($priceObject->id, $existingPriceIds)) {
	        	$updateNulls = true;
	        	$db->updateObject('#__alfa_items_prices', $priceObject, 'id', $updateNulls);
	        }else{
	        	$db->insertObject('#__alfa_items_prices', $priceObject);
	        }

	    }

	    return true;

	}

	public function setManufacturers($id, $manufacturers){
		    if (!is_array($manufacturers)) {

		        return false;
		    }


			$db = Factory::getDbo();

			$query = $db->getQuery(true);
			$query->delete('#__alfa_items_manufacturers')->where('product_id = '. $id);
			$db->setQuery($query);
			$db->execute();


			foreach ($manufacturers as $manufacturerId) {
                
                if($manufacturerId=='')continue;

                $query = $db->getQuery(true);
                $query->insert('#__alfa_items_manufacturers')
                        ->set(array(
                                    ('product_id = '. $id),
                                    ('manufacturer_id = '. intval($manufacturerId))
                ));
                $db->setQuery($query);
                $db->execute();
            }

	}

	public function setCategories($id, $categories){
		    if (!is_array($categories)) {
		        return false;
		    }


			$db = Factory::getDbo();
        	 // save item categories to items_categories table
			$query = $db->getQuery(true);
			$query->delete('#__alfa_items_categories')->where('product_id = '. $id);
			$db->setQuery($query);
			$db->execute();

			foreach ($categories as $categoryId) {
                if($categoryId=='')continue;

                $query = $db->getQuery(true);
                $query->insert('#__alfa_items_categories')
                        ->set(array(
                                    ('product_id = '. $id),
                                    ('category_id = '. intval($categoryId))
                ));
                $db->setQuery($query);
                $db->execute();
            }


	}


	/**
	 * Method to duplicate an Item From List
	 *
	 * @param   array  &$pks  An array of primary key IDs.
	 *
	 * @return  boolean  True if successful.
	 *
	 * @throws  Exception
	 */
	// public function duplicate(&$pks)
	// {
	// 	$app = Factory::getApplication();
	// 	$user = $app->getIdentity();
    //     $dispatcher = $this->getDispatcher();

	// 	// Access checks.
	// 	if (!$user->authorise('core.create', 'com_alfa'))
	// 	{
	// 		throw new \Exception(Text::_('JERROR_CORE_CREATE_NOT_PERMITTED'));
	// 	}

	// 	$context    = $this->option . '.' . $this->name;

	// 	// Include the plugins for the save events.
	// 	PluginHelper::importPlugin($this->events_map['save']);

	// 	$table = $this->getTable();

	// 	foreach ($pks as $pk)
	// 	{
			
	// 			if ($table->load($pk, true))
	// 			{
	// 				// Reset the id to create a new record.
	// 				$table->id = 0;

	// 				if (!$table->check())
	// 				{
	// 					throw new \Exception($table->getError());
	// 				}
					

	// 				// Trigger the before save event.
	// 				$beforeSaveEvent = new Model\BeforeSaveEvent($this->event_before_save, [
	// 					'context' => $context,
	// 					'subject' => $table,
	// 					'isNew'   => true,
	// 					'data'    => $table,
	// 				]);
					
	// 					// Trigger the before save event.
	// 					$result = $dispatcher->dispatch($this->event_before_save, $beforeSaveEvent)->getArgument('result', []);
					
					
	// 				if (in_array(false, $result, true) || !$table->store())
	// 				{
	// 					throw new \Exception($table->getError());
	// 				}

	// 				// Trigger the after save event.
	// 				$dispatcher->dispatch($this->event_after_save, new Model\AfterSaveEvent($this->event_after_save, [
	// 					'context' => $context,
	// 					'subject' => $table,
	// 					'isNew'   => true,
	// 					'data'    => $table,
	// 				]));				
	// 			}
	// 			else
	// 			{
	// 				throw new \Exception($table->getError());
	// 			}
			
	// 	}

	// 	// Clean cache
	// 	$this->cleanCache();

	// 	return true;
	// }


    protected function prepareTable($table)
    {

    	$table->modified = Factory::getDate()->toSql();

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        return parent::prepareTable($table);
        
        // $date = Factory::getDate()->toSql();

        // $table->name = htmlspecialchars_decode($table->name, ENT_QUOTES);

        // $table->generateAlias();

        // if (empty($table->id)) {
        //     // Set the values
        //     $table->created = $date;

        //     // Set ordering to the last item if not set
        //     if (empty($table->ordering)) {
        //         $db    = $this->getDatabase();
        //         $query = $db->getQuery(true)
        //             ->select('MAX(ordering)')
        //             ->from($db->quoteName('#__alfa_items'));
        //         $db->setQuery($query);
        //         $max = $db->loadResult();

        //         $table->ordering = $max + 1;
        //     }
        // } 
        // else {
        //     // Set the values
        //     $table->modified    = $date;
        //     $table->modified_by = $this->getCurrentUser()->id;
        // }

        // Increment the content version number.
        // $table->version++;
    }


}
