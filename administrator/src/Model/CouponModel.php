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
 * Coupon model.
 *
 * @since  1.0.1
 */
class CouponModel extends AdminModel
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
	public $typeAlias = 'com_alfa.coupon';

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
	public function getTable($type = 'Coupon', $prefix = 'Administrator', $config = array())
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
								'com_alfa.coupon', 
								'coupon',
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
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return  boolean  True on success, False on error.
     *
     * @since   1.6
     */
    public function save($data)
    {
        if (parent::save($data)) {
            $app = Factory::getApplication();

            $currentId = 0;
            if ($data['id'] > 0) { //not a new
                $currentId = intval($data['id']);
            } else { // is new
                $currentId = intval($this->getState($this->getName() . '.id')); //get the id from setted joomla state
            }

            AlfaHelper::setAllowedUsers($currentId, $data['allowedUsers'], '#__alfa_coupons_users', 'coupon_id');
            AlfaHelper::setAllowedUserGroups($currentId, $data['allowedUserGroups'], '#__alfa_coupons_usergroups', 'coupon_id');

            return true;
        }
        return false;
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
		$data = Factory::getApplication()->getUserState('com_alfa.edit.coupon.data', array());

		if (empty($data))
		{
			if ($this->item === null)
			{
				$this->item = $this->getItem();
			}

			$data = $this->item;
			

			// Support for multiple or not foreign key field: value_type
			$array = array();

			foreach ((array) $data->value_type as $value)
			{
				if (!is_array($value))
				{
					$array[] = $value;
				}
			}
			if(!empty($array)){

			$data->value_type = $array;
			}

			// Support for multiple or not foreign key field: hidden
			$array = array();

			foreach ((array) $data->hidden as $value)
			{
				if (!is_array($value))
				{
					$array[] = $value;
				}
			}
			if(!empty($array)){

			$data->hidden = $array;
			}

			// Support for multiple or not foreign key field: associate_to_new_users
			$array = array();

			foreach ((array) $data->associate_to_new_users as $value)
			{
				if (!is_array($value))
				{
					$array[] = $value;
				}
			}
			if(!empty($array)){

			$data->associate_to_new_users = $array;
			}

			// Support for multiple or not foreign key field: user_associated
			$array = array();

			foreach ((array) $data->user_associated as $value)
			{
				if (!is_array($value))
				{
					$array[] = $value;
				}
			}
			if(!empty($array)){

			$data->user_associated = $array;
			}
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
				
				// Do any procesing on fields here if needed
                $item->allowedUsers = AlfaHelper::getAllowedUsers($item->id, '#__alfa_coupons_users', 'coupon_id');
                $item->allowedUserGroups = AlfaHelper::getAllowedUserGroups($item->id, '#__alfa_coupons_usergroups', 'coupon_id');
			}

			return $item;
		
	}

	/**
	 * Method to duplicate an Coupon
	 *
	 * @param   array  &$pks  An array of primary key IDs.
	 *
	 * @return  boolean  True if successful.
	 *
	 * @throws  Exception
	 */
	public function duplicate(&$pks)
	{
		$app = Factory::getApplication();
		$user = $app->getIdentity();
        $dispatcher = $this->getDispatcher();

		// Access checks.
		if (!$user->authorise('core.create', 'com_alfa'))
		{
			throw new \Exception(Text::_('JERROR_CORE_CREATE_NOT_PERMITTED'));
		}

		$context    = $this->option . '.' . $this->name;

		// Include the plugins for the save events.
		PluginHelper::importPlugin($this->events_map['save']);

		$table = $this->getTable();

		foreach ($pks as $pk)
		{
			
				if ($table->load($pk, true))
				{
					// Reset the id to create a new record.
					$table->id = 0;

					if (!$table->check())
					{
						throw new \Exception($table->getError());
					}
					

					// Trigger the before save event.
					$beforeSaveEvent = new Model\BeforeSaveEvent($this->event_before_save, [
						'context' => $context,
						'subject' => $table,
						'isNew'   => true,
						'data'    => $table,
					]);
					
						// Trigger the before save event.
						$result = $dispatcher->dispatch($this->event_before_save, $beforeSaveEvent)->getArgument('result', []);
					
					
					if (in_array(false, $result, true) || !$table->store())
					{
						throw new \Exception($table->getError());
					}

					// Trigger the after save event.
					$dispatcher->dispatch($this->event_after_save, new Model\AfterSaveEvent($this->event_after_save, [
						'context' => $context,
						'subject' => $table,
						'isNew'   => true,
						'data'    => $table,
					]));				
				}
				else
				{
					throw new \Exception($table->getError());
				}
			
		}

		// Clean cache
		$this->cleanCache();

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
        $table->modified = Factory::getDate()->toSql();

		jimport('joomla.filter.output');

		if (empty($table->id))
		{
			// Set ordering to the last item if not set
			if (@$table->ordering === '')
			{
				$db = $this->getDbo();
				$db->setQuery('SELECT MAX(ordering) FROM #__alfa_coupons');
				$max             = $db->loadResult();
				$table->ordering = $max + 1;
			}
		}
	}
}
