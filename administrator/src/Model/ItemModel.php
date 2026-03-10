<?php

/**
 * @version    1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2025-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Alfa\Component\Alfa\Site\Helper\CategoryHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Joomla\String\StringHelper;
use stdClass;
use Throwable;

/**
 * Item model.
 *
 * PRICE INDEX SYNC HOOKS
 * ----------------------
 * The price index (#__alfa_items_price_index) must be kept in sync whenever
 * an item's published state or pricing data changes. Three hooks are added:
 *
 *   save()          — re-indexes this item after ALL associated data is committed
 *                     (prices, categories, manufacturers, users, usergroups).
 *                     Call order inside save() is critical — syncItem() is LAST.
 *
 *   publish()       — if state becomes 1 (published): re-index the item so it
 *                     appears in the filter with correct prices.
 *                     If state is anything else (0=unpublish, -2=trash): remove
 *                     the item's index rows so it disappears from the filter.
 *
 *   delete()        — remove the item's index rows.
 *                     Note: the FK ON DELETE CASCADE on the index table already
 *                     handles hard deletes automatically. This call is an explicit
 *                     safety net for soft-delete / trash scenarios.
 *
 * All sync operations are wrapped in try/catch and are NON-FATAL — a sync
 * failure is logged as a warning but never blocks the admin save operation.
 *
 * @since  1.0.1
 */
class ItemModel extends AdminModel
{
    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.item';

    protected $formName = 'item';

    /**
     * Method to get the record form.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return Form|bool A Form object on success, false on failure
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

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_USERS_NOT_CHANGED'), 'info');

            return true;
        }

        foreach ($pks as $id) {
            //			TODO:: CHECK
            AlfaHelper::setAllowedUsers($id, $value, '#__alfa_items_users', 'item_id', 'user_id');
        }

        $app->enqueueMessage(Text::_('COM_ALFA_USERS_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    protected function batchUserGroup($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_USERGROUP_NOT_CHANGED'), 'info');

            return true;
        }

        foreach ($pks as $id) {
            AlfaHelper::setAllowedUserGroups($id, $value, '#__alfa_items_usergroups', 'item_id', 'usergroup_id');
        }

        $app->enqueueMessage(Text::_('COM_ALFA_USERGROUP_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    protected function batchManufacturer($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_MANUFACTURERS_NOT_CHANGED'), 'info');

            return true;
        }

        foreach ($pks as $id) {
            AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');
        }

        $app->enqueueMessage(Text::_('COM_ALFA_MANUFACTURERS_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    protected function batchCategory($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if (sizeof($value) == 1 && $value[0] == '') {
            $app->enqueueMessage(Text::_('COM_ALFA_CATEGORIES_NOT_CHANGED'), 'info');

            return true;
        }

        $affectedCategories = [];
        foreach ($pks as $id) {
            $oldCats = AlfaHelper::getAssocsFromDb($id, '#__alfa_items_categories', 'item_id', 'category_id');
            $affectedCategories = array_merge($affectedCategories, array_map('intval', $oldCats));
            AlfaHelper::setAssocsToDb($id, $value, '#__alfa_items_categories', 'item_id', 'category_id');
        }

        // Clear cache for all affected categories (old + new)
        $affectedCategories = array_unique(array_merge($affectedCategories, array_map('intval', $value)));
        foreach ($affectedCategories as $catId) {
            if ($catId > 0) {
                CategoryHelper::clearCache($catId);
            }
        }

        $app->enqueueMessage(Text::_('COM_ALFA_CATEGORIES_SET_SUCCESSFULLY'), 'info');

        return true;
    }

    public function getForm($data = [], $loadData = true)
    {
        // $this->formName is item
        // Get the form.
        $form = $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        if (empty($form)) {
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
     * @return mixed The data for the form.
     *
     * @since   1.0.1
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState('com_alfa.edit.item.data', []);

        if (empty($data)) {
            $data = ($this->item === null ? $this->getItem() : $this->item);
        }

        return $data;
    }

    /**
     * Method to get a single record.
     *
     * @param int $pk The id of the primary key.
     *
     * @return mixed Object on success, false on failure.
     *
     * @since   1.0.1
     */

    public function getItem($pk = null)
    {
        $pk = (!empty($pk)) ? $pk : (int) $this->getState($this->getName() . '.id');

        if (isset($this->item[$pk])) {
            return $this->item[$pk];
        }

        if ($item = parent::getItem($pk)) {
            $meta_data = json_decode($item->meta_data ?? '{}');
            $item->robots = $meta_data->robots ?? '';

            $item->prices = $this->getPrices($item->id);

            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_categories', 'item_id', 'category_id');
            $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');

            $item->allowedUsers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_users', 'item_id', 'user_id');
            $item->allowedUserGroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_items_usergroups', 'item_id', 'usergroup_id');
        }

        $this->item[$pk] = $item;

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * Extends the parent save() to also maintain the price index.
     *
     * CALL ORDER — the price index sync MUST be the very last step because
     * it reads all associated data committed in the steps before it:
     *
     *   1. parent::save()           — commits the #__alfa_items row
     *   2. setPrices()              — commits #__alfa_items_prices rows
     *   3. setAssocsToDb() x 4     — commits categories, manufacturers, users, usergroups
     *   4. PriceIndexSyncService    — reads everything above, writes the index
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
     *
     * @since   1.6
     */
    public function save($data)
    {
        //		$app = Factory::getApplication();
        //		$input = $app->input;
        //		$user = $app->getIdentity();
        //		$db = $this->getDatabase();
        $table = $this->getTable();

        //		$key   = $table->getKeyName();
        //		$pk  = $data[$key] ?? (int) $this->getState($this->getName() . '.id');
        $pk = $data['id'] ?? (int) $this->getState($this->getName() . '.id');
        //		$isNew = $pk <= 0;
        //		$prevItem = !$isNew ? $this->getItem($pk) : null;
        //		$isClientApi = $app->isClient('api');

        $data['alias'] = $data['alias'] ?: $data['name'];

        // Sanitize alias
        $data['alias'] = $this->sanitizeAlias($data['alias']);
        // Check alias uniqueness
        $data['alias'] = $this->getUniqueAlias($data['alias'], $pk);

        // Checking valid height/width/depth/weight.
        if ($data['width'] < 0) {
            $data['width'] = 0;
        }
        if ($data['height'] < 0) {
            $data['height'] = 0;
        }
        if ($data['depth'] < 0) {
            $data['depth'] = 0;
        }
        if ($data['weight'] < 0) {
            $data['weight'] = 0;
        }

        $data['meta_data'] = json_encode(
            ['robots' => $data['robots']],
        );

        // Step 1: save the main item row
        if (!parent::save($data)) {
            return false;
        }

        $currentId = $pk > 0 ? $pk : (int) $this->getState($this->getName() . '.id'); //get the id from joomla state

        // AUTO SET DEFAULT CATEGORY ID AND CATEGORIES ARRAY
        // Check if $categoryIdDefault is set, if not set it to the first category
        $categoryIdDefault = $data['id_category_default'];
        $categories = $data['categories'] ?? [];

        if (!isset($categoryIdDefault) && !empty($categories)) {
            $categoryIdDefault = $categories[0]; // assuming categories are indexed as an array
        }

        // Check if $defaultCategoryId exists in $data['categories'], if not, add it
        if (!in_array($categoryIdDefault, $categories)) {
            $data['categories'][] = $categoryIdDefault;
        }
        // END OF AUTO SET DEFAULT CATEGORY ID AND CATEGORIES ARRAY

        // Step 2: save prices
        $this->setPrices($currentId, $data['prices']);

        // Step 3: save all associations
        // Get old categories BEFORE overwriting so we can clear their cache too
        $oldCategories = AlfaHelper::getAssocsFromDb($currentId, '#__alfa_items_categories', 'item_id', 'category_id');
        $newCategories = $data['categories'] ?? [];

        AlfaHelper::setAssocsToDb($currentId, $newCategories, '#__alfa_items_categories', 'item_id', 'category_id');
        AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'] ?? [], '#__alfa_items_manufacturers', 'item_id', 'manufacturer_id');

        // Clear category cache for all affected categories (old + new)
        $affectedCategories = array_unique(array_merge(
            array_map('intval', $oldCategories),
            array_map('intval', $newCategories),
        ));
        foreach ($affectedCategories as $catId) {
            if ($catId > 0) {
                CategoryHelper::clearCache($catId);
            }
        }

        AlfaHelper::setAssocsToDb($currentId, $data['allowedUsers'] ?? [], '#__alfa_items_users', 'item_id', 'user_id');
        AlfaHelper::setAssocsToDb($currentId, $data['allowedUserGroups'] ?? [], '#__alfa_items_usergroups', 'item_id', 'usergroup_id');

        // Step 4: update the price index.
        // This MUST be the last step — it reads prices and associations committed above.
        // Non-fatal: if the sync fails we log a warning but do NOT block the save.
        $priceIndexSyncService = new PriceIndexSyncService();

        try {
            $priceIndexSyncService->syncItem($currentId);
        } catch (Throwable $syncException) {
            Log::add(
                '[ItemModel::save] Price index sync failed for item ' . $currentId . ': ' . $syncException->getMessage(),
                Log::WARNING,
                'com_alfa',
            );
        }

        return true;
        // return parent::save($data);
    }

    /**
     * Method to change the published state of one or more items.
     *
     * Extends the parent publish() to keep the price index in sync:
     *
     *   state = 1 (publish)             → syncItem()      item re-enters the filter
     *   state = 0 / -2 (unpublish/trash) → deleteForItem() item leaves the filter immediately
     *
     * Non-fatal: sync failures are logged but never block the publish operation.
     *
     * @param array $pks An array of item primary keys.
     * @param int $value The target state: 1=publish, 0=unpublish, -2=trash.
     *
     * @return bool True on success.
     *
     * @since   1.0.1
     */
    public function publish(&$pks, $value = 1)
    {
        // Let Joomla handle the actual state change in the database
        $result = parent::publish($pks, $value);

        if (!$result) {
            return false;
        }

        $priceIndexSyncService = new PriceIndexSyncService();

        foreach ($pks as $pk) {
            $pk = (int) $pk;

            if ($pk <= 0) {
                continue;
            }

            if ((int) $value === 1) {
                // Item was re-published: rebuild its index rows so it appears in the price filter
                try {
                    $priceIndexSyncService->syncItem($pk);
                } catch (Throwable $syncException) {
                    Log::add(
                        '[ItemModel::publish] Price index sync failed for item ' . $pk . ': ' . $syncException->getMessage(),
                        Log::WARNING,
                        'com_alfa',
                    );
                }
            } else {
                // Item was unpublished or trashed: remove it from the filter immediately
                try {
                    $priceIndexSyncService->deleteForItem($pk);
                } catch (Throwable $syncException) {
                    Log::add(
                        '[ItemModel::publish] Price index delete failed for item ' . $pk . ': ' . $syncException->getMessage(),
                        Log::WARNING,
                        'com_alfa',
                    );
                }
            }
        }

        return true;
    }

    /**
     * Method to delete one or more records.
     *
     * Extends the parent delete() to remove the item's price index rows.
     *
     * Note: the FK ON DELETE CASCADE on #__alfa_items_price_index already handles
     * hard deletes automatically. This explicit call is a safety net for soft-delete
     * scenarios and any future cases where the FK is not active.
     *
     * Non-fatal: sync failures are logged but never block the delete operation.
     *
     * @param array $pks An array of item primary keys to delete.
     *
     * @return bool True on success.
     *
     * @since   1.0.1
     */
    public function delete(&$pks)
    {
        // Let Joomla handle the actual delete
        $result = parent::delete($pks);

        if (!$result) {
            return false;
        }

        $priceIndexSyncService = new PriceIndexSyncService();

        foreach ($pks as $pk) {
            $pk = (int) $pk;

            if ($pk <= 0) {
                continue;
            }

            // Remove the item's price index rows.
            // The FK CASCADE already handles hard deletes, but we call this
            // explicitly for soft-delete / trash safety.
            try {
                $priceIndexSyncService->deleteForItem($pk);
            } catch (Throwable $syncException) {
                Log::add(
                    '[ItemModel::delete] Price index delete failed for item ' . $pk . ': ' . $syncException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        return true;
    }

    public function getPrices($id)
    {
        $id = intval($id);
        if ($id <= 0) {
            return [];
        }

        // Get the database object
        $db = $this->getDatabase();

        // Build the query to select all relevant fields
        $query = $db->getQuery(true);
        $query
            ->select('*')
            ->from('#__alfa_items_prices')
            ->where('item_id = ' . $db->quote($id));

        // Execute the query
        $db->setQuery($query);

        // Return the result as an associative array
        return $db->loadAssocList();
    }

    public function setPrices($productId, $prices)
    {
        if (!is_array($prices) || $productId <= 0) {
            return false;
        }

        $db = $this->getDatabase();

        // Get all existing price IDs for the product
        $query = $db->getQuery(true);
        $query->select('id')
            ->from('#__alfa_items_prices')
            ->where('item_id = ' . intval($productId));
        $db->setQuery($query);
        $existingPriceIds = $db->loadColumn();  // Array of existing price IDs

        // Extract incoming IDs from the $prices array
        $incomingIds = [];
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
            $query->delete('#__alfa_items_prices')->whereIn('id', $idsToDelete);
            $db->setQuery($query);
            $db->execute();
        }

        foreach ($prices as $price) {
            $priceObject = new stdClass();
            $priceObject->id = isset($price['id']) ? intval($price['id']) : 0;
            $priceObject->item_id = $productId;
            $priceObject->value = isset($price['value']) ? floatval($price['value']) : 0.0;
            $priceObject->country_id = isset($price['country_id']) ? intval($price['country_id']) : 0;
            $priceObject->usergroup_id = isset($price['usergroup_id']) ? intval($price['usergroup_id']) : 0;
            $priceObject->user_id = isset($price['user_id']) ? intval($price['user_id']) : 0;
            $priceObject->currency_id = isset($price['currency_id']) ? intval($price['currency_id']) : 0;
            $priceObject->modify = isset($price['modify']) ? intval($price['modify']) : 0;
            $priceObject->modify_function = $price['modify_function'] ?? null;
            $priceObject->modify_type = $price['modify_type'] ?? null;
            $priceObject->publish_up = !empty($price['publish_up']) ? Factory::getDate($price['publish_up'])->toSql() : null;
            $priceObject->publish_down = !empty($price['publish_down']) ? Factory::getDate($price['publish_down'])->toSql() : null;
            $priceObject->quantity_start = !empty($price['quantity_start']) ? intval($price['quantity_start']) : null;
            $priceObject->quantity_end = !empty($price['quantity_end']) ? intval($price['quantity_end']) : null;

            $query = $db->getQuery(true);

            if ($priceObject->id > 0 && in_array($priceObject->id, $existingPriceIds)) {
                // Update existing price row.
                // $updateNulls = true so nullable fields (like publish_down,
                // quantity_end) can be explicitly cleared back to NULL.
                $updateNulls = true;
                $db->updateObject('#__alfa_items_prices', $priceObject, 'id', $updateNulls);
            } else {
                // Insert new price (unset ID to let DB auto-increment)
                unset($priceObject->id);
                $db->insertObject('#__alfa_items_prices', $priceObject);
            }
        }

        return true;
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param Table $table Table Object
     *
     * @return void
     *
     * @since   1.0.1
     */
    protected function prepareTable($table)
    {
        $user = $this->getCurrentUser();

        if (empty($table->stock) || $table->stock <= 0) {
            $table->stock = null;
        }

        if (empty($table->quantity_min) || $table->quantity_min <= 0) {
            $table->quantity_min = 1;
        }

        if (empty($table->quantity_step) || $table->quantity_step <= 0) {
            $table->quantity_step = 1;
        }

        if (empty($table->quantity_max) || $table->quantity_max <= 0) {
            $table->quantity_max = null;
        }

        if (empty($table->stock_low) || $table->stock_low <= 0) {
            $table->stock_low = null;
        }

        if ($table->id == 0 && empty($table->created_by)) {
            $table->created_by = $user->id;
        }

        $table->modified = Factory::getDate()->toSql();
        $table->modified_by = $user->id;

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        parent::prepareTable($table);
    }

    /**
     * Sanitize alias based on Joomla configuration.
     *
     * @param string $alias The alias to sanitize.
     *
     * @return string The sanitized alias.
     *
     * @since   1.0.1
     */
    protected function sanitizeAlias($alias)
    {
        $app = Factory::getApplication();

        if ($app->get('unicodeslugs') == 1) {
            return OutputFilter::stringUrlUnicodeSlug($alias);
        }

        return OutputFilter::stringURLSafe($alias);
    }

    /**
     * Method to ensure alias is unique, incrementing if necessary.
     *
     * @param string $alias The desired alias.
     * @param int $id The item id (0 for new items).
     *
     * @return string The unique alias.
     *
     * @since   1.0.1
     */
    /**
     * Method to ensure alias is unique.
     *
     * @param string $alias The desired alias.
     * @param int $id The item id (0 for new items).
     *
     * @return string The unique alias.
     *
     * @since   1.0.1
     */
    protected function getUniqueAlias($alias, $id = 0)
    {
        $db = $this->getDatabase();
        $maxAttempts = 100;
        $attempts = 0;

        while ($attempts < $maxAttempts) {
            $attempts++;

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__alfa_items'))
                ->where($db->quoteName('alias') . ' = ' . $db->quote($alias));

            if ($id > 0) {
                $query->where($db->quoteName('id') . ' != ' . (int) $id);
            }

            $db->setQuery($query);

            if (!$db->loadResult()) {
                return $alias;
            }

            $alias = StringHelper::increment($alias, 'dash');
        }

        // Fallback if max attempts reached
        return $alias . '-' . time();
    }
}
