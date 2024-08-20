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
				// if (isset($item->params))
				// {
				// 	$item->params = json_encode($item->params);
				// }
				$db = Factory::getDbo();

				// Do any procesing on fields here if needed
				
				// load selected categories for item
				$query = $db->getQuery(true);
	            $query
	                ->select('c.id')
	                ->from('#__alfa_items_categories as ic')
	                ->innerJoin('#__alfa_categories as c on (c.id = ic.category_id)')
	                ->where(sprintf('ic.product_id = %d', $item->id));
	                // ->order('c.name asc');

	            $db->setQuery($query);
	            $item->categories = $db->loadColumn();

	            // load selected categories for item
				$query = $db->getQuery(true);
	            $query
	                ->select('c.id')
	                ->from('#__alfa_items_manufacturers as ic')
	                ->innerJoin('#__alfa_manufacturers as c on (c.id = ic.manufacturer_id)')
	                ->where(sprintf('ic.product_id = %d', $item->id));
	                // ->order('c.name asc');

	            $db->setQuery($query);
	            $item->manufacturers = $db->loadColumn();

	            $item->allowedUsers = AlfaHelper::getAllowedUsers($item->id, '#__alfa_items_users', 'item_id');
            	$item->allowedUserGroups = AlfaHelper::getAllowedUserGroups($item->id, '#__alfa_items_usergroups', 'item_id');
			}

			return $item;
		
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
		$app    = Factory::getApplication();
		$db 	= Factory::getDbo();

		$data['alias'] = $data['alias'] ?: $data['name'];

		if ($app->get('unicodeslugs') == 1){
			$data['alias'] = OutputFilter::stringUrlUnicodeSlug($data['alias']);
		} else {
			$data['alias'] = OutputFilter::stringURLSafe($data['alias']);
		}

		$data['modified'] = date('Y-m-d H:i:s');

        AlfaHelper::setAllowedUsers($data['id'], $data['allowedUsers'], '#__alfa_items_users', 'item_id');
        AlfaHelper::setAllowedUserGroups($data['id'], $data['allowedUserGroups'], '#__alfa_items_usergroups', 'item_id');


		// if ($table->load(['slug' => $data['slug']])) { //checks for duplicates
        //     $data['slug'].= '-'.$pk;//if slug exists add the id after
        //     // = OutputFilter::stringURLSafe($data['name'].'-'.$pk);
        // }
		// if ($input->get('task') == 'save2copy') {
		// 	if ($table->load(['slug' => $data['slug']])) {

		// }

		if (!parent::save($data))return false;


		// $origTable = clone $this->getTable();

		$currentId = 0;
        if($data['id']>0){ //not a new
        	$currentId = intval($data['id']);
    	}else{ // is new
    		$currentId = intval($this->getState($this->getName().'.id'));//get the id from setted joomla state
    	}

    	// save item categories to items_categories table
    	$query = $db->getQuery(true);
        $query->delete('#__alfa_items_categories')->where('product_id = '. $currentId);
        $db->setQuery($query);
        $db->execute();

        if (is_array($data['categories']) || is_object($data['categories'])){
          foreach ($data['categories'] as $categoryId) {
                $query = $db->getQuery(true);
                $query->insert('#__alfa_items_categories')
                        ->set(array(
                                    ('product_id = '. $currentId),
                                    ('category_id = '. intval($categoryId))
                ));
                $db->setQuery($query);
                $db->execute();
            }
        }

    	// save item manufacturers to items_manufacturers table
    	$query = $db->getQuery(true);
        $query->delete('#__alfa_items_manufacturers')->where('product_id = '. $currentId);
        $db->setQuery($query);
        $db->execute();

        if (is_array($data['manufacturers']) || is_object($data['manufacturers'])){
          foreach ($data['manufacturers'] as $manufacturerId) {
                $query = $db->getQuery(true);
                $query->insert('#__alfa_items_manufacturers')
                        ->set(array(
                                    ('product_id = '. $currentId),
                                    ('manufacturer_id = '. intval($manufacturerId))
                ));
                $db->setQuery($query);
                $db->execute();
            }
        }


		return true;
		// return parent::save($data);
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

	/**
	 * Prepare and sanitise the table prior to saving.
	 *
	 * @param   Table  $table  Table Object
	 *
	 * @return  void
	 *
	 * @since   1.0.1
	 */
	// protected function prepareTable($table)
	// {
	// 	jimport('joomla.filter.output');

	// 	if (empty($table->id))
	// 	{
	// 		// Set ordering to the last item if not set
	// 		if (@$table->ordering === '')
	// 		{
	// 			$db = $this->getDbo();
	// 			$db->setQuery('SELECT MAX(ordering) FROM #__alfa_items');
	// 			$max             = $db->loadResult();
	// 			$table->ordering = $max + 1;
	// 		}
	// 	}
	// }
    protected function prepareTable($table)
    {
        // $date = Factory::getDate()->toSql();

        // $table->name = htmlspecialchars_decode($table->name, ENT_QUOTES);

        // $table->generateAlias();

        if (empty($table->id)) {
            // Set the values
            $table->created = $date;

            // Set ordering to the last item if not set
            if (empty($table->ordering)) {
                $db    = $this->getDatabase();
                $query = $db->getQuery(true)
                    ->select('MAX(ordering)')
                    ->from($db->quoteName('#__alfa_items'));
                $db->setQuery($query);
                $max = $db->loadResult();

                $table->ordering = $max + 1;
            }
        } 
        // else {
        //     // Set the values
        //     $table->modified    = $date;
        //     $table->modified_by = $this->getCurrentUser()->id;
        // }

        // Increment the content version number.
        $table->version++;
    }



     /**
     * Function that can be overridden to do any data cleanup after batch copying data
     *
     * @param   TableInterface  $table  The table object containing the newly created item
     * @param   integer         $newId  The id of the new item
     * @param   integer         $oldId  The original item id
     *
     * @return  void
     *
     * @since  3.8.12
     */
    // protected function cleanupPostBatchCopy(TableInterface $table, $newId, $oldId)
    // {
        // Check if the article was featured and update the #__content_frontpage table
        // if ($table->featured == 1) {
        //     $db    = $this->getDatabase();
        //     $query = $db->getQuery(true)
        //         ->select(
        //             [
        //                 $db->quoteName('featured_up'),
        //                 $db->quoteName('featured_down'),
        //             ]
        //         )
        //         ->from($db->quoteName('#__content_frontpage'))
        //         ->where($db->quoteName('content_id') . ' = :oldId')
        //         ->bind(':oldId', $oldId, ParameterType::INTEGER);

        //     $featured = $db->setQuery($query)->loadObject();

        //     if ($featured) {
        //         $query = $db->getQuery(true)
        //             ->insert($db->quoteName('#__content_frontpage'))
        //             ->values(':newId, 0, :featuredUp, :featuredDown')
        //             ->bind(':newId', $newId, ParameterType::INTEGER)
        //             ->bind(':featuredUp', $featured->featured_up, $featured->featured_up ? ParameterType::STRING : ParameterType::NULL)
        //             ->bind(':featuredDown', $featured->featured_down, $featured->featured_down ? ParameterType::STRING : ParameterType::NULL);

        //         $db->setQuery($query);
        //         $db->execute();
        //     }
        // }

        // $this->workflowCleanupBatchMove($oldId, $newId);

        // $oldItem = $this->getTable();
        // $oldItem->load($oldId);
        // $fields = FieldsHelper::getFields('com_content.article', $oldItem, true);

        // $fieldsData = [];

        // if (!empty($fields)) {
        //     $fieldsData['com_fields'] = [];

        //     foreach ($fields as $field) {
        //         $fieldsData['com_fields'][$field->name] = $field->rawvalue;
        //     }
        // }

        // Factory::getApplication()->triggerEvent('onContentAfterSave', ['com_content.article', &$this->table, false, $fieldsData]);
    // }

    /**
     * Batch move categories to a new category.
     *
     * @param   integer  $value     The new category ID.
     * @param   array    $pks       An array of row IDs.
     * @param   array    $contexts  An array of item contexts.
     *
     * @return  boolean  True on success.
     *
     * @since   3.8.6
     */
    protected function batchMove($value, $pks, $contexts)
    {
    	$this->setError(Text::_('heyy'));
    	return false;
        // if (empty($this->batchSet)) {
        //     // Set some needed variables.
        //     $this->user           = $this->getCurrentUser();
        //     $this->table          = $this->getTable();
        //     $this->tableClassName = \get_class($this->table);
        //     $this->contentType    = new UCMType();
        //     $this->type           = $this->contentType->getTypeByTable($this->tableClassName);
        // }

        // print_r($this->batchSet);
        // return;

        // $categoryId = (int) $value;

        // if (!$this->checkCategoryId($categoryId)) {
        //     return false;
        // }

        // PluginHelper::importPlugin('system');

        // Parent exists so we proceed
        foreach ($pks as $pk) {
            // if (!$this->user->authorise('core.edit', $contexts[$pk])) {
            //     $this->setError(Text::_('JLIB_APPLICATION_ERROR_BATCH_CANNOT_EDIT'));

            //     return false;
            // }

            // // Check that the row actually exists
            // if (!$this->table->load($pk)) {
            //     if ($error = $this->table->getError()) {
            //         // Fatal error
            //         $this->setError($error);

            //         return false;
            //     }

            //     // Not fatal error
            //     $this->setError(Text::sprintf('JLIB_APPLICATION_ERROR_BATCH_MOVE_ROW_NOT_FOUND', $pk));
            //     continue;
            // }

            // $fields = FieldsHelper::getFields('com_content.article', $this->table, true);

            // $fieldsData = [];

            // if (!empty($fields)) {
            //     $fieldsData['com_fields'] = [];

            //     foreach ($fields as $field) {
            //         $fieldsData['com_fields'][$field->name] = $field->rawvalue;
            //     }
            // }

            // // Set the new category ID
            // $this->table->catid = $categoryId;

            // // We don't want to modify tags - so remove the associated tags helper
            // if ($this->table instanceof TaggableTableInterface) {
            //     $this->table->clearTagsHelper();
            // }

            // // Check the row.
            // if (!$this->table->check()) {
            //     $this->setError($this->table->getError());

            //     return false;
            // }

            // // Store the row.
            // if (!$this->table->store()) {
            //     $this->setError($this->table->getError());

            //     return false;
            // }

            // Run event for moved article
            // Factory::getApplication()->triggerEvent('onContentAfterSave', ['com_content.article', &$this->table, false, $fieldsData]);
        }

        // Clean the cache
        $this->cleanCache();

        return true;
    }
}
