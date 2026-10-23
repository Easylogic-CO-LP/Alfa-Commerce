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
use Alfa\Component\Alfa\Administrator\Service\Catalog\CategoryPickerPolicy;
use Alfa\Component\Alfa\Administrator\Service\PriceIndexSyncService;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Table;
use Throwable;

/**
 * Tax model.
 *
 * Price index sync hooks
 * The price index (#__alfa_items_price_index) must be kept in sync whenever
 * a tax changes, because taxes directly affect the tax_amount, final_price,
 * and base_price_with_tax stored in the index.
 *
 *   save()    - re-indexes all items that this tax applies to, AFTER all scope
 *               associations (categories, places, usergroups etc.) are committed.
 *               Call order inside save() is critical: sync is LAST.
 *
 *   publish() - both enabling AND disabling a tax change prices for affected
 *               items, so syncByTax() is called in both directions.
 *
 *   delete()  - SPECIAL SEQUENCE (order is critical):
 *               1. Collect affected item ids BEFORE the delete, while the scope
 *                  tables (tax_categories, tax_places etc.) still exist.
 *               2. the scope pivots are cascaded explicitly below
 *               3. syncItems() re-indexes the collected items WITHOUT the tax
 *                  (final_price may drop since tax is now gone).
 *               If syncByTax() were called AFTER the delete, the scope tables
 *               would already be gone and we could not determine which items to sync.
 *
 * All sync operations are non-fatal: logged as warnings, never block the admin save.
 *
 * @since  1.0.0
 */
class TaxModel extends AlfaAdminModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('tax').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'tax';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save()/delete() extend the parent mid-method (price index sync + pivot
     *   cascade around parent::save()/parent::delete()), with no early return
     *   before the parent call; the contract's Validate/BeforeSave/AfterSave
     *   and delete brackets fire on every run.
     * - publish() wraps parent::publish() for price-index sync; state changes
     *   are outside the contract's save/delete brackets.
     */

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
    public $typeAlias = 'com_alfa.tax';

    /**
     * @var null Item data
     *
     * @since  1.0.0
     */
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
        // Initialise variables.
        $app = Factory::getApplication();

        // Get the form.
        $form = $this->loadForm(
            'com_alfa.tax',
            'tax',
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
            stateKey: 'com_alfa.edit.tax.data',
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
            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_categories', 'tax_id', 'category_id');
            $item->manufacturers = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_manufacturers', 'tax_id', 'manufacturer_id');
            $item->places = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_places', 'tax_id', 'place_id');

            $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_users', 'tax_id', 'user_id');
            $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_tax_usergroups', 'tax_id', 'usergroup_id');
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
     *   1. parent::save()           - commits the #__alfa_taxes row
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

        // Step 1: save the main tax row
        // A NEW category must be available (not archived or trashed, not inside an archived or trashed category);
        // one the record already holds is kept. Refused before anything is written.
        $categoryRefusal = CategoryPickerPolicy::refusalForSave(
            recordId:     (int) ($data['id'] ?? 0),
            posted:       (array) ($data['categories'] ?? []),
            table:        '#__alfa_tax_categories',
            recordColumn: 'tax_id',
        );

        if ($categoryRefusal !== null) {
            $this->setError($categoryRefusal);

            return false;
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = 0;
        if ($data['id'] > 0) { // existing record
            $currentId = intval($data['id']);
        } else { // new record: read the id from the Joomla model state
            $currentId = intval($this->getState($this->getName() . '.id'));
        }

        // Capture the items this tax affects under its CURRENT (pre-save) scope,
        // BEFORE Step 2 overwrites the scope pivots. Items that drop OUT of scope
        // on this save must be re-indexed too, or they keep a stale tax in the
        // price index; worst case an item left UNTAXED in the index (global ->
        // specific). getItemIdsForTax returns ALL published items when the
        // current scope is global.
        $priceIndexSyncService = new PriceIndexSyncService();
        $previousAffectedItemIds = [];

        // Only an EXISTING tax has a pre-save footprint to preserve. A brand new
        // record has no scope pivots yet, which reads as "global" (zero rows) and
        // would needlessly re-index the whole catalogue below; its new scope is
        // read back after the save, in Step 3.
        if ($data['id'] > 0) {
            try {
                $previousAffectedItemIds = $priceIndexSyncService->getItemIdsForTax($currentId);
            } catch (Throwable $scopeException) {
                Log::add(
                    '[TaxModel::save] Could not capture pre-save scope for tax ' . $currentId . ': ' . $scopeException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        // Translations + the five scope pivots must be atomic. Each setAssocsToDb
        // DELETEs the tax's rows then re-INSERTs; a failure between them would leave
        // zero scope rows, which the pricing engine reads as "applies EVERYWHERE".
        // Wrapping them means a mid-rewrite failure restores the prior scope intact.
        //
        // The language tables are extended BEFORE the transaction opens: DDL commits
        // implicitly, and inside it that would commit the half-rewritten scope.
        MultilingualHelper::ensureLangSchemaForData(tableName: '#__alfa_taxes', primaryColumnName: 'id_tax', data: $data);

        $db = $this->getDatabase();
        $db->transactionStart(true);

        try {
            // MULTILINGUAL: persist per-language translations (name, desc).
            // Taxes are not URL-routed, so there is no alias slug.
            MultilingualHelper::saveMultilingualData(
                currentId:         $currentId,
                primaryColumnName: 'id_tax',
                tableName:         '#__alfa_taxes',
                data:              $data,
                aliasFields:       [],
            );

            // Step 2: save all scope associations.
            // $assignZeroIdIfDataEmpty = true means: if the admin left a scope field
            // empty (e.g. no specific category selected), insert a row with id=0 which
            // means "applies to ALL" in the pricing engine.
            $assignZeroIdIfDataEmpty = true;
            AlfaHelper::setAssocsToDb($currentId, $data['categories'] ?? [], '#__alfa_tax_categories', 'tax_id', 'category_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['manufacturers'] ?? [], '#__alfa_tax_manufacturers', 'tax_id', 'manufacturer_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['places'] ?? [], '#__alfa_tax_places', 'tax_id', 'place_id', $assignZeroIdIfDataEmpty);

            AlfaHelper::setAssocsToDb($currentId, $data['users'] ?? [], '#__alfa_tax_users', 'tax_id', 'user_id', $assignZeroIdIfDataEmpty);
            AlfaHelper::setAssocsToDb($currentId, $data['usergroups'] ?? [], '#__alfa_tax_usergroups', 'tax_id', 'usergroup_id', $assignZeroIdIfDataEmpty);

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            TransactionState::rollBackLevel(db: $db, failure: $e);
            throw $e;
        }

        // Step 3: update the price index. Re-index the NEW scope AND the items
        // that were in scope BEFORE this save (captured above); otherwise a
        // narrowed or changed scope strands a stale tax on the items that left
        // it. MUST be last (reads scope associations committed above).
        // Non-fatal: if the sync fails we log a warning but do NOT block the save.
        //
        // ONE pass over the union. For a rule that applies everywhere both sets are
        // the whole catalogue, and two passes rebuilt every product's index twice in
        // one click. If the new scope cannot be read, what WAS in scope is still
        // re-indexed: that capture exists so a failure cannot strand those items.
        try {
            try {
                $affectedItemIds = $priceIndexSyncService->getItemIdsForTax($currentId);
            } catch (Throwable $scopeException) {
                $affectedItemIds = [];
                Log::add(
                    '[TaxModel::save] Could not read the saved scope of tax ' . $currentId . ': ' . $scopeException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }

            $priceIndexSyncService->syncItems(array_keys(array_flip(array_merge($affectedItemIds, $previousAffectedItemIds))));
        } catch (Throwable $syncException) {
            Log::add(
                '[TaxModel::save] Price index sync failed for tax ' . $currentId . ': ' . $syncException->getMessage(),
                Log::WARNING,
                'com_alfa',
            );
        }

        return true;
    }

    /**
     * Method to change the published state of one or more taxes.
     *
     * Extends the parent publish() to keep the price index in sync.
     *
     * Both enabling AND disabling a tax change the prices of affected items:
     *   - Enabling  a tax  -> final_price goes UP,   tax_amount goes UP
     *   - Disabling a tax  -> final_price goes DOWN,  tax_amount drops to 0
     * So syncByTax() must be called in both directions.
     *
     * Non-fatal: sync failures are logged but never block the publish operation.
     *
     * @param array $pks An array of tax primary keys.
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
            // Both directions change the final_price and tax_amount in the index.
            try {
                $priceIndexSyncService->syncByTax($pk);
            } catch (Throwable $syncException) {
                Log::add(
                    '[TaxModel::publish] Price index sync failed for tax ' . $pk . ': ' . $syncException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        return true;
    }

    /**
     * Method to delete one or more tax records.
     *
     * Extends the parent delete() to re-index the items that were affected.
     *
     * CRITICAL ORDER: must collect affected item ids BEFORE the delete:
     *
     *   Step 1: For each tax, call getItemIdsForTax() while the scope tables
     *           (tax_categories, tax_places etc.) still exist.
     *           We collect all affected item ids into $affectedItemIds.
     *
     *   Step 2: the scope pivots are cascaded explicitly below
     *
     *   Step 3: syncItems() re-indexes all collected items. Because the tax
     *           is now deleted, PriceCalculator will compute prices without it:
     *           final_price may drop and tax_amount will become 0.
     *
     * Non-fatal: failures at any step are logged but never block the delete.
     *
     * @param array $pks An array of tax primary keys to delete.
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
        // may not delete taxes must not trigger.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            // The same refusal as AlfaAdminModel::delete(): a message the controller shows, and
            // nothing reported as deleted.
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $db = $this->getDatabase();
        $priceIndexSyncService = new PriceIndexSyncService();

        // Step 1: collect all item ids affected by these taxes BEFORE deleting.
        // The scope tables still exist at this point so getItemIdsForTax() can
        // determine which categories / places / usergroups each tax covers.
        $affectedItemIds = [];

        foreach ($pks as $pk) {
            $pk = (int) $pk;

            if ($pk <= 0) {
                continue;
            }

            try {
                $itemIdsForThisTax = $priceIndexSyncService->getItemIdsForTax($pk);
                $affectedItemIds = array_unique(array_merge($affectedItemIds, $itemIdsForThisTax));
            } catch (Throwable $collectException) {
                Log::add(
                    '[TaxModel::delete] Could not collect affected item ids for tax ' . $pk . ': ' . $collectException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        // Step 2: perform the actual delete (removes tax rows + scope rows)
        $result = parent::delete($pks);

        if (!$result) {
            return false;
        }

        // MULTILINGUAL: remove the per-language rows for the deleted taxes.
        // Scope/link pivots of the deleted rows.
        foreach ([
            '#__alfa_tax_categories',
            '#__alfa_tax_manufacturers',
            '#__alfa_tax_places',
            '#__alfa_tax_usergroups',
            '#__alfa_tax_users',
            // A shipping method that referenced the rule no longer does.
            '#__alfa_shipment_taxes',
        ] as $pivotTable) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($pivotTable))
                    ->whereIn($db->quoteName('tax_id'), $pks),
            )->execute();
        }

        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_tax',
            tableName:         '#__alfa_taxes',
        );

        // Step 3: re-index the collected items now that the tax is gone.
        // PriceCalculator will compute prices without the deleted tax,
        // so final_price may drop and tax_amount will become 0.
        if (!empty($affectedItemIds)) {
            try {
                $priceIndexSyncService->syncItems($affectedItemIds);
            } catch (Throwable $syncException) {
                Log::add(
                    '[TaxModel::delete] Price index re-sync failed after deleting taxes: ' . $syncException->getMessage(),
                    Log::WARNING,
                    'com_alfa',
                );
            }
        }

        return true;
    }

    // TODO: ON DELETE TO DELETE ALSO THE TAX_RULES ASSOCIATED WITH OR DO IT WITH REFERENCE TABLE #__tax id AUTOMATICALLY IN SQL

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
        $user = $this->getCurrentUser();

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
    }
}
