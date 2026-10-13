<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Joomla\CMS\Event\Model;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Table;
use Throwable;

/**
 * Discount model.
 *
 * Price index sync hooks
 * The price index (#__alfa_items_price_index) must be kept in sync whenever
 * a discount changes, because discounts directly affect the final_price,
 * discount_amount, and discount_percent stored in the index.
 *
 *   save()    - re-indexes all items that this discount applies to, AFTER all
 *               scope associations (categories, places, usergroups etc.) are
 *               committed. Call order inside save() is critical: sync is LAST.
 *
 *   publish() - both enabling AND disabling a discount change prices for affected
 *               items, so syncByDiscount() is called in both directions.
 *
 *   delete()  - SPECIAL SEQUENCE (order is critical):
 *               1. Collect affected item ids BEFORE the delete, while the scope
 *                  tables (discount_categories, discount_places etc.) still exist.
 *               2. the scope pivots are cascaded explicitly below
 *               3. syncItems() re-indexes the collected items WITHOUT the discount
 *                  (prices may have risen since the discount is now gone).
 *               If syncByDiscount() were called AFTER the delete, the scope tables
 *               would already be gone and we could not determine which items to sync.
 *
 * All sync operations are non-fatal: logged as warnings, never block the admin save.
 *
 * @since  1.0.0
 */
class DiscountModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'discount';

    /**
     * @var string The prefix to use with controller messages.
     *
     * @since  1.0.0
     */
    protected $text_prefix = 'COM_ALFA';

    /**
     * @var string Alias to manage history control
     *
     * @since  1.0.0
     */
    public $typeAlias = 'com_alfa.discount';

    protected $formName = 'discount';

    protected $item = null;

    /**
     * Method to get the record form.
     *
     * @param array $data An optional array of data for the form to interogate.
     * @param bool $loadData True if the form is to load its own data (default case), false if not.
     *
     * @return Form|bool A Form object on success, false on failure
     *
     * @since  1.0.0
     */
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

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since  1.0.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.discount.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;
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
     * @since  1.0.0
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_categories', 'discount_id', 'category_id');
            $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_manufacturers', 'discount_id', 'manufacturer_id');
            $item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_places', 'discount_id', 'place_id');

            $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_users', 'discount_id', 'user_id');
            $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_discount_usergroups', 'discount_id', 'usergroup_id');
        }

        return $item;
    }

    /**
     * Method to save the form data.
     *
     * Extends the parent save() to also maintain the price index.
     *
     * CALL ORDER: the price index sync MUST be the very last step because
     * it reads the scope associations committed in the step before it:
     *
     *   1. parent::save()           - commits the #__alfa_discounts row
     *   2. setAssocsToDb() x 5     - commits categories, manufacturers, places, users, usergroups
     *   3. PriceIndexSyncService   - reads the scope above, re-indexes affected items
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
     *
     * @since  1.0.0
     */
    public function save($data)
    {
        $app = Factory::getApplication();

        // Recover the per-language flat keys (e.g. name_en_gb) and other undeclared
        // keys the form filter drops, without overwriting any validated field or
        // resurrecting the housekeeping fields the form unsets. Recovered
        // per-language values are safehtml-cleaned like their base field.
        $rawData = $app->getInput()->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        // Step 1: save the main discount row
        if (!parent::save($data)) {
            return false;
        }

        $currentId = 0;
        if ($data['id'] > 0) { // existing record
            $currentId = intval($data['id']);
        } else { // new record: read the id from the Joomla model state
            $currentId = intval($this->getState($this->getName() . '.id'));
        }

        // Capture the items this discount affects under its CURRENT (pre-save)
        // scope, BEFORE Step 2 overwrites the scope pivots. Items that drop OUT
        // of scope on this save must be re-indexed too, or they keep a stale
        // discount in the price index (worst case: global -> specific would
        // strand the discount across the whole catalogue). getItemIdsForDiscount
        // returns ALL published items when the current scope is global.
        $priceIndexSyncService = new PriceIndexSyncService();
        $previousAffectedItemIds = [];

        // Only an EXISTING discount has a pre-save footprint to preserve. A brand
        // new record has no scope pivots yet, which reads as "global" (zero rows)
        // and would needlessly re-index the whole catalogue below; its new scope
        // is read back after the save, in Step 3.
        if ($data['id'] > 0) {
            try {
                $previousAffectedItemIds = $priceIndexSyncService->getItemIdsForDiscount($currentId);
            } catch (Throwable $scopeException) {
                Log::add(
                    '[DiscountModel::save] Could not capture pre-save scope for discount ' . $currentId . ': ' . $scopeException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        // Translations + the five scope pivots must be atomic: each setAssocsToDb
        // DELETEs then re-INSERTs, and a failure between them would leave zero scope
        // rows, which the engine reads as "applies EVERYWHERE". An unposted (empty)
        // multiselect omits its key, hence `?? []`.
        //
        // The language tables are extended BEFORE the transaction opens: DDL commits
        // implicitly, and inside it that would commit the half-rewritten scope.
        MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_discounts', primaryColumnName: 'id_discount', data: $data);

        $db = $this->getDatabase();
        $db->transactionStart(true);

        try {
            // MULTILINGUAL: persist per-language translations (name).
            // Discounts are not URL-routed, so there is no alias slug.
            MultilingualHelper::saveMultilingualData(
                currentId:         $currentId,
                primaryColumnName: 'id_discount',
                tableName:         '#__alfa_discounts',
                data:              $data,
                aliasFields:       [],
            );

            // Step 2: save all scope associations.
            // $assignZeroIdIfDataEmpty = true means: if the admin left a scope field
            // empty (e.g. no specific category selected), insert a row with id=0 which
            // means "applies to ALL" in the pricing engine.
            $assignZeroIdIfDataEmpty = true;
            AlfaHelper::setAssocsToDb($currentId, $data['categories'] ?? [], '#__alfa_discount_categories', 'discount_id', 'category_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'] ?? [], '#__alfa_discount_manufacturers', 'discount_id', 'manufacturer_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['places'] ?? [], '#__alfa_discount_places', 'discount_id', 'place_id', $assignZeroIdIfDataEmpty);

            AlfaHelper::setAssocsToDb($currentId, $data['users'] ?? [], '#__alfa_discount_users', 'discount_id', 'user_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['usergroups'] ?? [], '#__alfa_discount_usergroups', 'discount_id', 'usergroup_id', $assignZeroIdIfDataEmpty);

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            throw $e;
        }

        // Step 3: update the price index. Re-index the NEW scope AND the items
        // that were in scope BEFORE this save (captured above); otherwise a
        // narrowed or changed scope strands a stale discount on the items that
        // left it. MUST be last (reads scope associations committed above).
        // Non-fatal: if the sync fails we log a warning but do NOT block the save.
        //
        // ONE pass over the union. For a rule that applies everywhere both sets are
        // the whole catalogue, and two passes rebuilt every product's index twice in
        // one click. If the new scope cannot be read, what WAS in scope is still
        // re-indexed: that capture exists so a failure cannot strand those items.
        try {
            try {
                $affectedItemIds = $priceIndexSyncService->getItemIdsForDiscount($currentId);
            } catch (Throwable $scopeException) {
                $affectedItemIds = [];
                Log::add(
                    '[DiscountModel::save] Could not read the saved scope of discount ' . $currentId . ': ' . $scopeException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }

            $priceIndexSyncService->syncItems(array_keys(array_flip(array_merge($affectedItemIds, $previousAffectedItemIds))));
        } catch (Throwable $syncException) {
            Log::add(
                '[DiscountModel::save] Price index sync failed for discount ' . $currentId . ': ' . $syncException->getMessage(),
                Log::WARNING,
                'com_alfa',
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
     *   - Enabling  a discount  -> final_price goes DOWN,  discount_amount goes UP
     *   - Disabling a discount  -> final_price goes UP,    discount_amount goes DOWN (or 0)
     * So syncByDiscount() must be called in both directions.
     *
     * Non-fatal: sync failures are logged but never block the publish operation.
     *
     * @param array $pks An array of discount primary keys.
     * @param int $value The target state: 1=publish, 0=unpublish.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
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

            // Re-index affected items regardless of direction (enable or disable).
            // Both directions change the final_price stored in the index.
            try {
                $priceIndexSyncService->syncByDiscount($pk);
            } catch (Throwable $syncException) {
                Log::add(
                    '[DiscountModel::publish] Price index sync failed for discount ' . $pk . ': ' . $syncException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
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
     * CRITICAL ORDER: must collect affected item ids BEFORE the delete:
     *
     *   Step 1: For each discount, call getItemIdsForDiscount() while the scope
     *           tables (discount_categories, discount_places etc.) still exist.
     *           We collect all affected item ids into $affectedItemIds.
     *
     *   Step 2: the scope pivots are cascaded explicitly below
     *
     *   Step 3: syncItems() re-indexes all collected items. Because the discount
     *           is now deleted, PriceCalculator will compute prices without it:
     *           final_price may rise and discount_amount may drop back to 0.
     *
     * Non-fatal: failures at any step are logged but never block the delete.
     *
     * @param array $pks An array of discount primary keys to delete.
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function delete(&$pks)
    {
        // ApiController::delete() hands the model a SCALAR id; only
        // parent::delete() normalises, and everything below runs before it.
        $pks = (array) $pks;

        // Permission first: the scope walk below reads every affected item, work a user who
        // may not delete discounts must not trigger.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            // The same refusal as AlfaAdminModel::delete(): a message the controller shows, and
            // nothing reported as deleted.
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $db = $this->getDatabase();
        $priceIndexSyncService = new PriceIndexSyncService();

        // Step 1: collect all item ids affected by these discounts BEFORE deleting.
        // The scope tables still exist at this point so getItemIdsForDiscount() can
        // determine which categories / places / usergroups each discount covers.
        $affectedItemIds = [];

        foreach ($pks as $pk) {
            $pk = (int) $pk;

            if ($pk <= 0) {
                continue;
            }

            try {
                $itemIdsForThisDiscount = $priceIndexSyncService->getItemIdsForDiscount($pk);
                $affectedItemIds = array_unique(array_merge($affectedItemIds, $itemIdsForThisDiscount));
            } catch (Throwable $collectException) {
                Log::add(
                    '[DiscountModel::delete] Could not collect affected item ids for discount ' . $pk . ': ' . $collectException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        // Step 2: perform the actual delete (removes discount rows + scope rows)
        $result = parent::delete($pks);

        if (!$result) {
            return false;
        }

        // MULTILINGUAL: remove the per-language rows for the deleted discounts.
        // Scope/link pivots of the deleted rows.
        foreach ([
            '#__alfa_discount_categories',
            '#__alfa_discount_manufacturers',
            '#__alfa_discount_places',
            '#__alfa_discount_usergroups',
            '#__alfa_discount_users',
        ] as $pivotTable) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($pivotTable))
                    ->whereIn($db->quoteName('discount_id'), $pks),
            )->execute();
        }

        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_discount',
            tableName:         '#__alfa_discounts',
        );

        // Step 3: re-index the collected items now that the discount is gone.
        // PriceCalculator will compute prices without the deleted discount,
        // so final_price may rise and discount_amount may drop back to 0.
        if (!empty($affectedItemIds)) {
            try {
                $priceIndexSyncService->syncItems($affectedItemIds);
            } catch (Throwable $syncException) {
                Log::add(
                    '[DiscountModel::delete] Price index re-sync failed after deleting discounts: ' . $syncException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
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
     * @since  1.0.0
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
