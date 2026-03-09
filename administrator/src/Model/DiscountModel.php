<?php
/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2024 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;
// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use \Joomla\CMS\Table\Table;
use \Joomla\CMS\Factory;
use \Joomla\CMS\Language\Text;
use \Joomla\CMS\Plugin\PluginHelper;
use \Joomla\CMS\MVC\Model\AdminModel;
use \Joomla\CMS\Helper\TagsHelper;
use \Joomla\CMS\Filter\OutputFilter;
use \Joomla\CMS\Event\Model;
use \Joomla\CMS\Log\Log;

/**
 * Discount model.
 *
 * PRICE INDEX SYNC HOOKS
 * ----------------------
 * The price index (#__alfa_items_price_index) must be kept in sync whenever
 * a discount changes, because discounts directly affect the final_price,
 * discount_amount, and discount_percent stored in the index.
 *
 *   save()    — re-indexes all items that this discount applies to, AFTER all
 *               scope associations (categories, places, usergroups etc.) are
 *               committed. Call order inside save() is critical — sync is LAST.
 *
 *   publish() — both enabling AND disabling a discount change prices for affected
 *               items, so syncByDiscount() is called in both directions.
 *
 *   delete()  — SPECIAL SEQUENCE (order is critical):
 *               1. Collect affected item ids BEFORE the delete, while the scope
 *                  tables (discount_categories, discount_places etc.) still exist.
 *               2. parent::delete() removes the discount and all its scope rows.
 *               3. syncItems() re-indexes the collected items WITHOUT the discount
 *                  (prices may have risen since the discount is now gone).
 *               If syncByDiscount() were called AFTER the delete, the scope tables
 *               would already be gone and we could not determine which items to sync.
 *
 * All sync operations are non-fatal — logged as warnings, never block the admin save.
 *
 * @since  1.0.1
 */
class DiscountModel extends AdminModel
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
	public $typeAlias = 'com_alfa.discount';

	protected $formName = 'discount';

	protected $item = null;

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
		$data = Factory::getApplication()->getUserState('com_alfa.edit.discount.data', array());

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
			$item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_categories', 'discount_id','category_id');
			$item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_manufacturers', 'discount_id','manufacturer_id');
			$item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_places', 'discount_id','place_id');

			$item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_users', 'discount_id','user_id');
			$item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_usergroups', 'discount_id','usergroup_id');

		}

		return $item;

	}


	/**
	 * Method to save the form data.
	 *
	 * Extends the parent save() to also maintain the price index.
	 *
	 * CALL ORDER — the price index sync MUST be the very last step because
	 * it reads the scope associations committed in the step before it:
	 *
	 *   1. parent::save()           — commits the #__alfa_discounts row
	 *   2. setAssocsToDb() x 5     — commits categories, manufacturers, places, users, usergroups
	 *   3. PriceIndexSyncService   — reads the scope above, re-indexes affected items
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

		// Step 1: save the main discount row
		if (!parent::save($data))return false;

		$currentId = 0;
		if($data['id']>0){ //not a new
			$currentId = intval($data['id']);
		}else{ // is new
			$currentId = intval($this->getState($this->getName().'.id'));//get the id from setted joomla state
		}


		// Step 2: save all scope associations.
		// $assignZeroIdIfDataEmpty = true means: if the admin left a scope field
		// empty (e.g. no specific category selected), insert a row with id=0 which
		// means "applies to ALL" in the pricing engine.
		$assignZeroIdIfDataEmpty = true;
		AlfaHelper::setAssocsToDb($currentId, $data['categories'], '#__alfa_discount_categories', 'discount_id','category_id',$assignZeroIdIfDataEmpty);
		AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'], '#__alfa_discount_manufacturers', 'discount_id','manufacturer_id',$assignZeroIdIfDataEmpty);
		AlfaHelper::setAssocsToDb($currentId, $data['places'], '#__alfa_discount_places', 'discount_id','place_id',$assignZeroIdIfDataEmpty);

		AlfaHelper::setAssocsToDb($currentId, $data['users'], '#__alfa_discount_users', 'discount_id','user_id',$assignZeroIdIfDataEmpty);
		AlfaHelper::setAssocsToDb($currentId, $data['usergroups'], '#__alfa_discount_usergroups','discount_id', 'usergroup_id',$assignZeroIdIfDataEmpty);

		// Step 3: update the price index for all items this discount affects.
		// This MUST be last — it reads scope associations committed above.
		// Non-fatal: if the sync fails we log a warning but do NOT block the save.
		$priceIndexSyncService = new PriceIndexSyncService();

		try
		{
			$priceIndexSyncService->syncByDiscount($currentId);
		}
		catch (\Throwable $syncException)
		{
			Log::add(
				'[DiscountModel::save] Price index sync failed for discount ' . $currentId . ': ' . $syncException->getMessage(),
				Log::WARNING,
				'com_alfa'
			);
		}

		return true;
		// return parent::save($data);
	}


	/**
	 * Method to change the published state of one or more discounts.
	 *
	 * Extends the parent publish() to keep the price index in sync.
	 *
	 * Both enabling AND disabling a discount change the prices of affected items:
	 *   - Enabling  a discount  → final_price goes DOWN,  discount_amount goes UP
	 *   - Disabling a discount  → final_price goes UP,    discount_amount goes DOWN (or 0)
	 * So syncByDiscount() must be called in both directions.
	 *
	 * Non-fatal: sync failures are logged but never block the publish operation.
	 *
	 * @param   array    $pks    An array of discount primary keys.
	 * @param   integer  $value  The target state: 1=publish, 0=unpublish.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0.1
	 */
	public function publish(&$pks, $value = 1)
	{
		// Let Joomla handle the actual state change in the database
		$result = parent::publish($pks, $value);

		if (!$result)
		{
			return false;
		}

		$priceIndexSyncService = new PriceIndexSyncService();

		foreach ($pks as $pk)
		{
			$pk = (int) $pk;

			if ($pk <= 0)
			{
				continue;
			}

			// Re-index affected items regardless of direction (enable or disable).
			// Both directions change the final_price stored in the index.
			try
			{
				$priceIndexSyncService->syncByDiscount($pk);
			}
			catch (\Throwable $syncException)
			{
				Log::add(
					'[DiscountModel::publish] Price index sync failed for discount ' . $pk . ': ' . $syncException->getMessage(),
					Log::WARNING,
					'com_alfa'
				);
			}
		}

		return true;
	}


	/**
	 * Method to delete one or more discount records.
	 *
	 * Extends the parent delete() to re-index the items that were affected.
	 *
	 * CRITICAL ORDER — must collect affected item ids BEFORE the delete:
	 *
	 *   Step 1: For each discount, call getItemIdsForDiscount() while the scope
	 *           tables (discount_categories, discount_places etc.) still exist.
	 *           We collect all affected item ids into $affectedItemIds.
	 *
	 *   Step 2: parent::delete() removes the discount rows and all associated
	 *           scope rows from discount_categories, discount_manufacturers, etc.
	 *
	 *   Step 3: syncItems() re-indexes all collected items. Because the discount
	 *           is now deleted, PriceCalculator will compute prices without it —
	 *           final_price may rise and discount_amount may drop back to 0.
	 *
	 * Non-fatal: failures at any step are logged but never block the delete.
	 *
	 * @param   array  $pks  An array of discount primary keys to delete.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0.1
	 */
	public function delete(&$pks)
	{
		$priceIndexSyncService = new PriceIndexSyncService();

		// Step 1: collect all item ids affected by these discounts BEFORE deleting.
		// The scope tables still exist at this point so getItemIdsForDiscount() can
		// determine which categories / places / usergroups each discount covers.
		$affectedItemIds = array();

		foreach ($pks as $pk)
		{
			$pk = (int) $pk;

			if ($pk <= 0)
			{
				continue;
			}

			try
			{
				$itemIdsForThisDiscount = $priceIndexSyncService->getItemIdsForDiscount($pk);
				$affectedItemIds        = array_unique(array_merge($affectedItemIds, $itemIdsForThisDiscount));
			}
			catch (\Throwable $collectException)
			{
				Log::add(
					'[DiscountModel::delete] Could not collect affected item ids for discount ' . $pk . ': ' . $collectException->getMessage(),
					Log::WARNING,
					'com_alfa'
				);
			}
		}

		// Step 2: perform the actual delete (removes discount rows + scope rows)
		$result = parent::delete($pks);

		if (!$result)
		{
			return false;
		}

		// Step 3: re-index the collected items now that the discount is gone.
		// PriceCalculator will compute prices without the deleted discount,
		// so final_price may rise and discount_amount may drop back to 0.
		if (!empty($affectedItemIds))
		{
			try
			{
				$priceIndexSyncService->syncItems($affectedItemIds);
			}
			catch (\Throwable $syncException)
			{
				Log::add(
					'[DiscountModel::delete] Price index re-sync failed after deleting discounts: ' . $syncException->getMessage(),
					Log::WARNING,
					'com_alfa'
				);
			}
		}

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
		$table->modified_by = $this->getCurrentUser()->id;

		if (empty($table->publish_up)) {
			$table->publish_up = null;
		}

		if (empty($table->publish_down)) {
			$table->publish_down = null;
		}

		return parent::prepareTable($table);

	}
}