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
use Alfa\Component\Alfa\Administrator\Helper\MediaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualAliasConfig;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Catalog\EntityChangeNotifier;
use Alfa\Component\Alfa\Administrator\Service\Catalog\RuleScopeGuard;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ScopeDeleteRefusedException;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Table;
use RuntimeException;
use Throwable;

/**
 * Manufacturer model.
 *
 * @since  1.0.0
 */
class ManufacturerModel extends AlfaAdminModel
{
    /**
     * @var string The entity a plugin targets with $context->targets('manufacturer').
     * @since  2.0.0
     */
    protected string $alfaEntity = 'manufacturer';

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
    public $typeAlias = 'com_alfa.manufacturer';

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
            'com_alfa.manufacturer',
            'manufacturer',
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
     * Method to save the form data.
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
        $input = $app->getInput();

        // Recover the per-language flat keys (e.g. name_en_gb) and other undeclared
        // keys the form filter drops, without overwriting any validated field or
        // resurrecting the housekeeping fields the form unsets. Recovered
        // per-language values are safehtml-cleaned like their base field.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        $pk = $data['id'] ?? (int) $this->getState($this->getName() . '.id');
        $isNew = $pk <= 0;

        $data['meta_data'] = json_encode(['robots' => $data['robots'] ?? '']);

        // Fetch uploads separately so $data (with the lang keys) is not clobbered.
        $newDropped = $input->files->get('jform')['uploads'] ?? [];

        // URL ALIASES, judged before anything is written: a save that would put this
        // manufacturer on another one's address in any language is refused; an address it
        // already shared is kept and said after the save. A copy takes its own aliases.
        if ($input->get('task') === 'save2copy') {
            $data = MultilingualHelper::releaseCopiedAliases(
                data:              $data,
                tableName:         '#__alfa_manufacturers',
                primaryColumnName: 'id_manufacturer',
                originalId:        $input->getInt('id'),
                aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_manufacturers'],
            );
        }

        $aliasClashes = MultilingualHelper::aliasClashes(
            tableName:         '#__alfa_manufacturers',
            primaryColumnName: 'id_manufacturer',
            currentId:         $isNew ? 0 : (int) $pk,
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_manufacturers'],
        );

        if ($aliasClashes['refuse'] !== []) {
            $this->setError(implode(' ', $aliasClashes['refuse']));

            return false;
        }

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : $pk;

        // MULTILINGUAL: persist per-language translations (name, alias, desc,
        // meta_title, meta_desc). A blank alias slug is generated, sanitised
        // and made globally unique (manufacturers are not nested -> no scope).
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_manufacturer',
            tableName:         '#__alfa_manufacturers',
            data:              $data,
            aliasFields:       MultilingualAliasConfig::FIELDS['#__alfa_manufacturers'],
        );

        foreach ($aliasClashes['warn'] as $warning) {
            $app->enqueueMessage($warning, 'warning');
        }

        if (!empty($data['media'])) {
            $defaultLangTag = MultilingualHelper::getDefaultLanguageTag();

            MediaHelper::saveMedia(
                mediaData:      $data['media'],
                droppedMedia:   $newDropped,
                itemId:         $currentId,
                mediaOrigin:    $this->name,
                customFileName: $data['alias_' . $defaultLangTag] ?? '',
            );
        }

        $this->resyncLinkedItems($currentId);

        // AFTER the per-language rows are written (saveMultilingualData above): a
        // listener materialising this manufacturer re-reads names/descriptions, so
        // the signal must not race the data it points at.
        EntityChangeNotifier::changed(entity: 'manufacturer', ids: [$currentId]);

        return true;
    }

    /**
     * Re-sync the search documents of every item carrying this manufacturer.
     *
     * The brand's name and publication state are both copied into each linked item's
     * stored document, so one edit here changes many documents. Run unconditionally,
     * not only when brand names are searchable: with the setting off the projected
     * text is identical and the indexer's hash comparison makes the sweep write-free,
     * whereas skipping it would leave a stale index the day the setting is enabled.
     *
     * Never fatal: a search index is downstream of the catalogue and must not be
     * able to fail a save or a state change.
     *
     * @param int ...$manufacturerIds The saved or state-changed manufacturer ids.
     *
     *
     * @since  2.0.0
     */
    private function resyncLinkedItems(int ...$manufacturerIds): void
    {
        $manufacturerIds = array_values(array_filter($manufacturerIds, static fn (int $id): bool => $id > 0));

        if ($manufacturerIds === []) {
            return;
        }

        try {
            $db = $this->getDatabase();

            $affectedItems = array_map('intval', (array) $db->setQuery(
                $db->getQuery(true)
                    ->select('DISTINCT ' . $db->quoteName('item_id'))
                    ->from($db->quoteName('#__alfa_items_manufacturers'))
                    ->whereIn($db->quoteName('manufacturer_id'), $manufacturerIds),
            )->loadColumn());

            if ($affectedItems !== []) {
                (new ProjectionManager())->syncItems($affectedItems);
            }
        } catch (Throwable $e) {
            Log::add(
                '[ManufacturerModel] Search re-sync failed for manufacturer(s) '
                . implode(', ', $manufacturerIds) . ': ' . $e->getMessage(),
                Log::WARNING,
                'com_alfa',
            );
        }
    }

    /**
     * Method to delete one or more records.
     *
     * Refused, with nothing written, when the set is the last manufacturer of a
     * discount or tax rule (with no manufacturer left the rule would apply to
     * every manufacturer), or a cart rule excludes one of them
     * ({@see RuleScopeGuard}). Only the requested manufacturers that exist are
     * deleted and stripped.
     *
     * @param array &$pks An array of record primary keys.
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

        // Permission first: the linked-item read below is work a user who may not delete
        // manufacturers must not trigger.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $db = $this->getDatabase();

        $affectedItems = [];

        // ONE TRANSACTION: the rule-scope judgement, the rows and every pivot that
        // names them. The judgement locks the rule pivots it reads, so no rule editor
        // can empty a scope between it and the delete, and a refusal or a failure
        // leaves every row where it was.
        $requested = $pks;

        // Every delete veto, Alfa's and Joomla's, before the transaction and its locks (2.1.4, design 0.2): no plugin code
        // runs under them.
        if (!$this->alfaAskBeforeDelete(pks: (array) $pks)) {
            return false;
        }

        $nested = TransactionState::isOpen(db: $db);
        $db->transactionStart(true);

        try {
            // Only the manufacturers that exist, locked: the rows deleted, judged and
            // stripped below are the same set.
            $pks = RuleScopeGuard::lockExisting(db: $db, axis: RuleScopeGuard::AXIS_MANUFACTURER, ids: $pks);

            if ($pks !== []) {
                // A discount or tax whose last manufacturer is in the set would apply to
                // every manufacturer, and a cart rule excluding one of them would reach
                // its products: the delete is refused, naming the rules.
                RuleScopeGuard::assertScopesSurvive(db: $db, axis: RuleScopeGuard::AXIS_MANUFACTURER, ids: $pks);

                // Read BEFORE the pivot is emptied below: every one of these items carries
                // this manufacturer's id and per-language name inside its search document.
                $affectedItems = array_map('intval', (array) $db->setQuery(
                    $db->getQuery(true)
                        ->select('DISTINCT ' . $db->quoteName('item_id'))
                        ->from($db->quoteName('#__alfa_items_manufacturers'))
                        ->whereIn($db->quoteName('manufacturer_id'), $pks),
                )->loadColumn());

                $this->alfaDeleteAsked = true;

                if (!parent::delete($pks)) {
                    // Every close of this level discards what was deferred inside it.
                    TransactionState::rollBackLevel(db: $db, failure: new RuntimeException('The manufacturers were not deleted.'));

                    return false;
                }

                // Scope/link pivots of the deleted rows.
                foreach ([
                    '#__alfa_items_manufacturers',
                    '#__alfa_discount_manufacturers',
                    '#__alfa_payment_manufacturers',
                    '#__alfa_shipment_manufacturers',
                    '#__alfa_tax_manufacturers',
                ] as $pivotTable) {
                    $db->setQuery(
                        $db->getQuery(true)
                            ->delete($db->quoteName($pivotTable))
                            ->whereIn($db->quoteName('manufacturer_id'), $pks),
                    )->execute();
                }
            }

            TransactionState::commitLevel(db: $db);
        } catch (ScopeDeleteRefusedException $refused) {
            // A refusal is answered only while a caller's transaction still stands.
            if (TransactionState::rollBackLevel(db: $db, failure: $refused) && $nested) {
                throw $refused;
            }

            $this->setError($refused);

            return false;
        } catch (Throwable $e) {
            // After a deadlock the whole transaction is gone, on the server and in
            // the driver's count.
            TransactionState::rollBackLevel(db: $db, failure: $e);

            throw $e;
        }

        // None of the requested manufacturers exists: core answers that itself.
        if ($pks === []) {
            $this->alfaDeleteAsked = true;

            return parent::delete($requested);
        }

        if (!empty($pks)) {
            // MULTILINGUAL: remove the per-language rows for the deleted manufacturers.
            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_manufacturer',
                tableName:         '#__alfa_manufacturers',
            );

            if ($affectedItems !== []) {
                (new ProjectionManager())->syncItems($affectedItems);
            }

            // Remove the manufacturers' media (rows; files when media_full_deletion is on).
            MediaHelper::deleteMediaForItems($pks, 'manufacturer');

            // The rows can no longer be re-read, so the signal says REMOVED: a
            // listener deletes its materialised copies instead of re-projecting.
            EntityChangeNotifier::changed(entity: 'manufacturer', ids: (array) $pks, removed: true);
        }

        return true;
    }

    /**
     * Method to change the published state of one or more records.
     *
     * Overridden (the base implementation was inherited bare) because a state change
     * decides whether a manufacturer's ENTITY DOCUMENT may exist: without the signal,
     * an unpublished brand kept answering storefront searches until the next full
     * rebuild. The listener re-reads the rows and draws the conclusion itself.
     *
     * The brand's state is also copied into every linked item's stored document, and
     * the entity event hands item fan-out to ProjectionManager by contract, so the
     * linked items are re-synced here, as save() and delete() already do. Cost: one
     * pivot read for the whole selection, then one syncItems() pass over its items.
     *
     * @param array &$pks A list of the primary keys to change.
     * @param int $value The value of the published state.
     *
     * @return bool True on success.
     *
     * @since  2.0.0
     */
    public function publish(&$pks, $value = 1)
    {
        // THE IDS ARE TAKEN BEFORE THE CALL. AdminModel::publish() prunes every row
        // already at the target state and can return true with the list emptied,
        // which would re-sync nothing on exactly the retry that needs it.
        $targets = array_map('intval', (array) $pks);

        $result = parent::publish($pks, $value);

        if ($result) {
            $this->resyncLinkedItems(...$targets);
            EntityChangeNotifier::changed(entity: 'manufacturer', ids: (array) $pks);
        }

        return $result;
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
            stateKey: 'com_alfa.edit.manufacturer.data',
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
            if (isset($item->params)) {
                $item->params = json_encode($item->params);
            }

            $item->medias = MediaHelper::getMediaData(
                origin: $this->name,
                itemIDs: $item->id,
            );

            $meta_data = json_decode($item->meta_data ?? '{}');
            $item->robots = $meta_data->robots ?? '';
        }

        return $item;
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

        if (empty($table->publish_up)) {
            $table->publish_up = null;
        }

        if (empty($table->publish_down)) {
            $table->publish_down = null;
        }

        return parent::prepareTable($table);
    }
}
