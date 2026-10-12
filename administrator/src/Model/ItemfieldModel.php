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
use Alfa\Component\Alfa\Administrator\Helper\FieldParams;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\TransactionState;
use Alfa\Component\Alfa\Administrator\Service\Cart\CartRepository;
use Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\String\StringHelper;
use Throwable;

/**
 * Item field model.
 *
 * Manages the global product-field definitions (#__alfa_item_fields): the
 * machine key, plugin type, behavior (informational / option / variant),
 * filter/search flags, category scoping and the plugin-specific params.
 * Translatable name/description persist through MultilingualHelper into
 * #__alfa_item_fields_<langtag>.
 *
 * @since  1.3.0
 */
class ItemfieldModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'itemfield';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save() returns before parent::save() when the machine field_name cannot be
     *   derived from any language's name: nothing persisted, so the contract's
     *   onAlfaAdminValidate/BeforeSave/AfterSave bracket rightly does not fire.
     * - delete() returns before parent::delete() on an empty id batch and when the
     *   combination-bound guard vetoes the batch: nothing reached the table, so
     *   the delete bracket fires only for batches that actually delete.
     */

    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.itemfield';

    protected $formName = 'itemfield';

    /**
     * @var object|null Item data cache
     *
     * @since  1.3.0
     */
    protected $item = null;

    /**
     * Build the form and inject the type plugin's params (alfa-item-fields
     * group). Once a field has an id, `type` and `behavior` are locked:
     * changing the type would orphan plugin-owned value params, changing the
     * behavior would invalidate existing combinations.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since  1.3.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $app = Factory::getApplication();

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

        $idFromInput = $app->getInput()->getInt('id', 0);
        $id = (int) $this->getState($this->formName . '.id', $idFromInput);

        $item = ($this->item === null ? $this->getItem() : $this->item);

        AlfaHelper::addPluginForm(form: $form, data: (array) $data, values: (array) ($item->params ?? []), type: 'itemfields');

        if ($id > 0) {
            $form->setFieldAttribute('type', 'readonly', 'true');
        }

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed The data for the form.
     *
     * @since  1.3.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.itemfield.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;
            $data->itemfieldsparams = $data->params;

            // VISIBILITY: showon rule / effect / depends_on preset live inside
            // the params JSON; mirror them onto the standalone form fields.
            $p = $data->params;
            if (is_string($p)) {
                $p = json_decode($p, true) ?: [];
            }
            $p = (array) $p;
            $data->showon = (string) ($p['showon'] ?? '');
            $data->showon_effect = (string) ($p['showon_effect'] ?? 'hide');
        }

        return $data;
    }

    /**
     * Load an item field and attach its category scoping ids (read from the
     * #__alfa_item_field_categories junction; empty = available everywhere).
     *
     * @param int|null $pk The id of the field to load (null = state id).
     *
     * @return object|bool The populated field object, or false on failure.
     *
     * @since  1.3.0
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            $item->categories = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_item_field_categories', 'item_field_id', 'category_id');
            $item->values = $this->getValues((int) $item->id);
        }

        return $item;
    }

    /**
     * Load the field's value pool rows for the inline Values tab: each row
     * carries its per-language translations (flat name_/alias_ keys) and the
     * decoded plugin params. Pricing is NOT defined here: impacts/overrides
     * live with the item (and its combinations), never on the value itself.
     *
     * @param int $fieldId The owning field id (0 = new field, no rows yet).
     *
     * @return array Value row objects ordered by ordering.
     *
     * @since  1.3.0
     */
    public function getValues(int $fieldId): array
    {
        if ($fieldId <= 0) {
            return [];
        }

        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->qn('#__alfa_item_field_values'))
                ->where($db->qn('item_field_id') . ' = ' . $fieldId)
                ->order($db->qn('ordering') . ' ASC, ' . $db->qn('id') . ' ASC'),
        );
        $rows = $db->loadObjectList() ?: [];

        foreach ($rows as $row) {
            // Flat per-language keys (name_en_gb, alias_el_gr, ...) for the row inputs.
            $translations = MultilingualHelper::getMultilingualDataFlat(
                currentId:         (int) $row->id,
                primaryColumnName: 'id_item_field_value',
                tableName:         '#__alfa_item_field_values',
            );

            foreach ($translations as $translationKey => $translationValue) {
                $row->{$translationKey} = $translationValue;
            }

            $row->valueparams = is_string($row->params) ? (json_decode($row->params, true) ?: []) : [];
        }

        return $rows;
    }

    /**
     * Method to save the form data.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, False on error.
     *
     * @since  1.3.0
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

        $table = $this->getTable();
        $key = $table->getKeyName();
        $isNew = ($data[$key] ?? 0) <= 0;

        // field_name is the machine key (NOT translatable). When left blank,
        // derive it from the name, preferring the default language, then any
        // other language that has a value.
        if (empty($data['field_name'])) {
            $candidates = [$data['name_' . MultilingualHelper::getDefaultLanguageTag()] ?? ''];

            foreach ($data as $dataKey => $dataValue) {
                if (is_string($dataValue) && str_starts_with($dataKey, 'name_')) {
                    $candidates[] = $dataValue;
                }
            }

            foreach ($candidates as $candidate) {
                if (trim((string) $candidate) !== '') {
                    $data['field_name'] = $candidate;
                    break;
                }
            }
        }

        $data['field_name'] = OutputFilter::stringURLSafe((string) $data['field_name']);

        if ($data['field_name'] === '') {
            $this->setError(Text::_('COM_ALFA_ITEMFIELD_NAME_REQUIRED'));

            return false;
        }

        // The column carries a UNIQUE key: increment with a dash suffix until
        // free (ignoring the record itself on edit).
        while ($table->load(['field_name' => $data['field_name']])) {
            if ((int) $table->id === (int) ($data[$key] ?? 0)) {
                break;
            }
            $data['field_name'] = StringHelper::increment($data['field_name'], 'dash');
        }

        // VISIBILITY: fold the standalone showon rule + effect into the params
        // JSON. Items can override per assignment in their wizard.
        if (!isset($data['itemfieldsparams']) || !is_array($data['itemfieldsparams'])) {
            $data['itemfieldsparams'] = [];
        }

        $data['itemfieldsparams']['showon'] = (string) ($data['showon'] ?? '');
        $data['itemfieldsparams']['showon_effect'] = (string) ($data['showon_effect'] ?? 'hide');
        unset($data['showon'], $data['showon_effect']);

        // Persist the plugin's params (itemfieldsparams fieldset) as the JSON
        // params column. Core never interprets the plugin's own contents.
        $data['params'] = json_encode($data['itemfieldsparams'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;

        if (!parent::save($data)) {
            return false;
        }

        $currentId = !$isNew ? (int) $data['id'] : (int) $this->getState($this->getName() . '.id');

        // MULTILINGUAL: persist per-language translations (name, description).
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_item_field',
            tableName:         '#__alfa_item_fields',
            data:              $data,
            aliasFields:       [],
        );

        // Category scoping: NO rows means "available in all categories", so an
        // empty selection must NOT write a zero-id placeholder row.
        AlfaHelper::setAssocsToDb($currentId, $data['categories'] ?? [], '#__alfa_item_field_categories', 'item_field_id', 'category_id', false);

        // Inline value pool (Values tab): upsert the submitted rows, cascade-
        // delete the removed ones.
        $this->saveValues(
            fieldId:         $currentId,
            rows:            (array) ($data['values'] ?? []),
            deletedValueIds: (string) ($data['deleted_value_ids'] ?? ''),
        );

        // Search fan-out: a value rename or a searchable toggle changes the search
        // document of EVERY item using this field, so re-sync them (hash-skip keeps
        // unchanged docs write-free). Routed through the projection manager so a
        // third-party index hears about it too. Non-fatal: never block the field save.
        try {
            $affectedItems = array_map('intval', (array) $this->getDatabase()->setQuery(
                $this->getDatabase()->getQuery(true)
                    ->select('DISTINCT ' . $this->getDatabase()->quoteName('item_id'))
                    ->from($this->getDatabase()->quoteName('#__alfa_item_field_value_index'))
                    ->where($this->getDatabase()->quoteName('field_id') . ' = ' . $currentId),
            )->loadColumn());

            if ($affectedItems !== []) {
                (new \Alfa\Component\Alfa\Administrator\Service\Projection\ProjectionManager())
                    ->syncItems($affectedItems);
            }
        } catch (Throwable $searchException) {
            \Joomla\CMS\Log\Log::add(
                '[ItemfieldModel::save] Search re-sync failed for field ' . $currentId . ': ' . $searchException->getMessage(),
                \Joomla\CMS\Log\Log::WARNING,
                'com_alfa',
            );
        }

        return true;
    }

    /**
     * Persist the inline value pool of the field.
     *
     * Each submitted row carries: id (0 = new), per-language name_/alias_ keys
     * and the plugin-owned valueparams (declared by the type plugin's
     * params/value.xml). Row order in the form becomes the ordering.
     * Removed rows cascade exactly like a value delete.
     *
     * @param int $fieldId The owning field id.
     * @param array $rows Submitted value rows (indexed).
     * @param string $deletedValueIds Comma list of removed existing value ids.
     *
     *
     * @since  1.3.0
     */
    protected function saveValues(int $fieldId, array $rows, string $deletedValueIds): void
    {
        $db = $this->getDatabase();

        // Deletions first: a deleted value must not survive as another row's parent.
        $deletedIds = array_values(array_filter(array_map('intval', explode(',', $deletedValueIds))));
        $refusedIds = [];

        if ($deletedIds) {
            // A VALUE THAT FORMS A VARIANT IS NOT THE MERCHANT'S TO REMOVE HERE.
            //
            // This handed the form's list straight to cascadeDeleteValues(),
            // which deletes the combination links unconditionally, so removing
            // a value on the Values tab silently amputated every variant built
            // from it, while deleting the whole FIELD was correctly refused for
            // exactly that reason. One rule, one path enforcing it.
            //
            // Refused per value rather than as a whole: parent::save() has
            // already committed the field row by the time this runs, so
            // abandoning the save would leave it half applied. The bound values
            // stay, everything else the merchant asked for still happens, and
            // the reason is said out loud.
            $binding = $this->usageBinding($deletedIds);

            if ($binding['ids'] !== []) {
                $refusedIds = $binding['ids'];
                $deletedIds = array_values(array_diff($deletedIds, $binding['ids']));

                Factory::getApplication()->enqueueMessage(
                    Text::sprintf(
                        'COM_ALFA_ITEMFIELD_VALUE_DELETE_IN_USE',
                        \count($binding['ids']),
                        $binding['items'],
                    ),
                    'error',
                );
            }
        }

        if ($deletedIds) {
            $this->cascadeDeleteValues(valueIds: $deletedIds);
        }

        $ordering = 0;

        foreach ($rows as $row) {
            $row = (array) $row;
            $valueId = (int) ($row['id'] ?? 0);

            if (in_array($valueId, $deletedIds, true)) {
                continue;
            }

            // Skip fully empty new rows (no name typed in any language).
            $hasName = false;

            foreach ($row as $rowKey => $rowValue) {
                if (str_starts_with((string) $rowKey, 'name_') && trim((string) $rowValue) !== '') {
                    $hasName = true;
                    break;
                }
            }

            if ($valueId <= 0 && !$hasName) {
                continue;
            }

            $table = $this->getMVCFactory()->createTable('Itemfieldvalue', 'Administrator');

            if ($valueId > 0) {
                $table->load($valueId);
            }

            $table->bind([
                'item_field_id' => $fieldId,
                'params' => (isset($row['valueparams']) && is_array($row['valueparams']))
                    ? (json_encode($row['valueparams'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null) : ($valueId > 0 ? $table->params : null),
                'ordering' => $ordering++,
                'state' => 1,
            ]);

            if (!$table->store()) {
                continue;
            }

            $valueId = (int) $table->id;

            // MULTILINGUAL per row: name + auto-generated, scope-unique alias.
            MultilingualHelper::saveMultilingualData(
                currentId:         $valueId,
                primaryColumnName: 'id_item_field_value',
                tableName:         '#__alfa_item_field_values',
                data:              $row,
                aliasFields:       ['alias'],
            );
        }

        // A REFUSED VALUE STILL NEEDS A PLACE IN THE LIST.
        //
        // The Values tab DETACHES a removed row, so a refused value is not in
        // $rows and the loop above never renumbered it. Left alone it keeps the
        // ordering it had before the save and collides with a row that has just
        // been renumbered; the list order then falls to the engine, which is
        // exactly what prepareTable() refuses to allow for field rows.
        //
        // Its intended position no longer exists (the merchant removed it), so
        // it goes last, in its previous relative order, where the message
        // telling them why it is still here says to look.
        if ($refusedIds !== []) {
            $db->setQuery(
                $db->getQuery(true)
                    ->select($db->qn('id'))
                    ->from($db->qn('#__alfa_item_field_values'))
                    ->whereIn($db->qn('id'), $refusedIds)
                    ->order($db->qn('ordering') . ' ASC, ' . $db->qn('id') . ' ASC'),
            );

            foreach (array_map('intval', (array) $db->loadColumn()) as $refusedId) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->qn('#__alfa_item_field_values'))
                        ->set($db->qn('ordering') . ' = ' . $db->q((string) $ordering++))
                        ->where($db->qn('id') . ' = ' . $refusedId),
                );
                $db->execute();
            }
        }
    }

    /**
     * Everything an item still depends on, across ALL THREE surfaces.
     *
     * THE CONTRACT, stated once for both paths that delete. A combination's
     * identity IS its set of rows in `#__alfa_item_combination_values`, and its
     * `signature` is those value ids sorted and joined by '-'. Remove one value
     * from that set and the combination survives describing a set it no longer
     * has: amputated, not edited, and invisible to everything downstream.
     *
     * Variants are not the only way to be load-bearing, which is why this asks the
     * wider question: an offered option carries a price delta, a spec is printed on
     * the page, and deleting the field behind either used to cascade those rows
     * away in silence. Orders survived (they carry frozen snapshots) but every live
     * product lost the line.
     *
     * Read from the LIVE tables, never from the filter projection: a guard must not
     * depend on a materialised view being in sync.
     *
     * The two callers ask this same question and answer it differently, which is
     * why it returns FACTS rather than a verdict:
     *
     *   delete()     - nothing has been written yet, so it vetoes the whole
     *                  operation and says how many products stand in the way.
     *   saveValues() - parent::save() has ALREADY committed the field row, with
     *                  no transaction around it. A veto there would abandon a
     *                  half-finished save, so it refuses only the bound values,
     *                  keeps the rest of the save, and tells the operator.
     *
     * @param array $valueIds Candidate value ids.
     *
     * @return array{ids: int[], items: int, combinations: int, options: int, informational: int}
     *                                                                                            The value ids actually in use, how many distinct items depend on
     *                                                                                            them, and how many rows of each kind.
     *
     * @since  1.3.0
     */
    private function usageBinding(array $valueIds): array
    {
        $valueIds = array_values(array_filter(array_map('intval', $valueIds)));
        $empty = ['ids' => [], 'items' => 0, 'combinations' => 0, 'options' => 0, 'informational' => 0];

        if ($valueIds === []) {
            return $empty;
        }

        $db = $this->getDatabase();
        $in = implode(',', $valueIds);

        $db->setQuery(
            'SELECT cv.' . $db->qn('value_id') . ' AS value_id, c.' . $db->qn('item_id') . ' AS item_id, '
            . $db->quote('combinations') . ' AS surface, cv.' . $db->qn('combination_id') . ' AS ref'
            . ' FROM ' . $db->qn('#__alfa_item_combination_values', 'cv')
            . ' INNER JOIN ' . $db->qn('#__alfa_item_combinations', 'c') . ' ON c.id = cv.combination_id'
            . ' WHERE cv.value_id IN (' . $in . ')'
            . ' UNION ALL SELECT ' . $db->qn('value_id') . ', ' . $db->qn('item_id') . ', '
            . $db->quote('options') . ', ' . $db->qn('id')
            . ' FROM ' . $db->qn('#__alfa_item_option_values') . ' WHERE ' . $db->qn('value_id') . ' IN (' . $in . ')'
            // Informational rows have no surrogate key: their identity IS
            // (item_id, value_id), so that pair is what counts as one row here.
            . ' UNION ALL SELECT ' . $db->qn('value_id') . ', ' . $db->qn('item_id') . ', '
            . $db->quote('informational') . ', CONCAT(' . $db->qn('item_id') . ', ' . $db->quote('-') . ', '
            . $db->qn('value_id') . ')'
            . ' FROM ' . $db->qn('#__alfa_item_info_values') . ' WHERE ' . $db->qn('value_id') . ' IN (' . $in . ')',
        );

        $rows = $db->loadObjectList() ?: [];

        if (!$rows) {
            return $empty;
        }

        $ids = $items = $refs = [];

        foreach ($rows as $row) {
            $ids[(int) $row->value_id] = true;
            $items[(int) $row->item_id] = true;
            $refs[$row->surface][(string) $row->ref] = true;
        }

        return [
            'ids' => array_map('intval', array_keys($ids)),
            'items' => \count($items),
            'combinations' => \count($refs['combinations'] ?? []),
            'options' => \count($refs['options'] ?? []),
            'informational' => \count($refs['informational'] ?? []),
        ];
    }

    /**
     * Delete value rows with their dependents: per-item subsets, combination
     * links and translations. Order snapshots stay.
     *
     * @param array $valueIds Value ids to delete.
     *
     *
     * @since  1.3.0
     */

    protected function cascadeDeleteValues(array $valueIds): void
    {
        $db = $this->getDatabase();
        $valueIds = array_map('intval', $valueIds);

        if (!$valueIds) {
            return;
        }

        // EVERYTHING THAT MUST DIE WITH A VALUE DIES HERE.
        //
        // The cart selections used to be cleaned by delete() alone, one level
        // up, so the Values tab, which calls this method directly, removed the
        // value and left live carts holding a selection that pointed at nothing.
        // A value is deleted in exactly one place; its dependants belong in that
        // same place, or the next caller inherits the same hole.
        //
        // The filter projection (#__alfa_item_field_value_index) is deliberately
        // NOT listed: save() re-syncs the affected items through ProjectionManager
        // right after this runs, and delete() clears it by field_id: it is
        // rebuilt, not cascaded, and probe case 1b holds that to account.
        foreach (
            [
                '#__alfa_item_info_values' => 'value_id',
                '#__alfa_item_option_values' => 'value_id',
                '#__alfa_item_combination_values' => 'value_id',
            ] as $tableName => $column
        ) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->qn($tableName))
                    ->whereIn($db->qn($column), $valueIds),
            );
            $db->execute();
        }

        // SELECTION-ONLY. A merchant withdrawing an option value has not
        // withdrawn the product: the shopper keeps the cart line, and what
        // changes is that the line is now configured differently from the way
        // they configured it. The repository has a separate operation for this
        // precisely so it cannot be confused with removing a line.
        (new CartRepository($db))->removeSelectionsForFieldValues($valueIds);

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->qn('#__alfa_item_field_values'))
                ->whereIn($db->qn('id'), $valueIds),
        );
        $db->execute();

        MultilingualHelper::deleteMultilingualData(
            ids:               $valueIds,
            primaryColumnName: 'id_item_field_value',
            tableName:         '#__alfa_item_field_values',
        );
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param \Joomla\CMS\Table\Table $table Table Object
     *
     * @return void
     *
     * @since  1.3.0
     */
    protected function prepareTable($table)
    {
        $user = $this->getCurrentUser();

        if ($table->id == 0 && empty($table->created_by)) {
            $table->created_by = $user->id;
        }

        // New definitions land at the END of the list: without this every
        // row saves with ordering 0 and the list order is engine-arbitrary.
        if ($table->id == 0 && empty($table->ordering)) {
            $table->ordering = $table->getNextOrder();
        }

        $table->modified_by = $user->id;

        parent::prepareTable($table);
    }

    /**
     * Change the published state of one or more fields, then re-sync every item
     * that uses them.
     *
     * The search document only carries values of fields that are `searchable = 1
     * AND state = 1` (SearchDocumentBuilder::readFieldValues), and that document is
     * STORED, not evaluated at query time. So unpublishing a field leaves its value
     * names searchable (the merchant hides a field and it keeps producing hits)
     * and publishing one leaves its values unfindable until something else happens
     * to re-save each item.
     *
     * @param array $pks The field ids being changed.
     * @param int $value The new state.
     *
     * @return bool True on success.
     *
     * @since  1.5.0
     */
    public function publish(&$pks, $value = 1)
    {
        if (!parent::publish($pks, $value)) {
            return false;
        }

        try {
            $affected = $this->itemsUsingFields($pks);

            // Through ProjectionManager, NOT ItemSearchProjection directly. Calling
            // the search projection alone would keep the built-in index correct while
            // every third-party projection registered on the seam heard nothing,
            // which is precisely the divergence the seam exists to prevent.
            if ($affected !== []) {
                (new ProjectionManager())->syncItems($affected);
            }
        } catch (Throwable $e) {
            // Non-fatal, like every other projection failure: the state change itself
            // succeeded and must not be reported as a failure.
            Log::add(
                '[ItemfieldModel::publish] re-sync failed: ' . $e->getMessage(),
                Log::WARNING,
                'com_alfa',
            );
        }

        return true;
    }

    /**
     * Items carrying a value of any of these fields.
     *
     * @param array $fieldIds The field ids.
     *
     * @return int[]
     *
     * @since  1.5.0
     */
    private function itemsUsingFields(array $fieldIds): array
    {
        $fieldIds = array_values(array_filter(array_map('intval', (array) $fieldIds)));

        if ($fieldIds === []) {
            return [];
        }

        $db = $this->getDatabase();

        return array_map('intval', (array) $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . $db->quoteName('item_id'))
                ->from($db->quoteName('#__alfa_item_field_value_index'))
                ->whereIn($db->quoteName('field_id'), $fieldIds),
        )->loadColumn());
    }

    /**
     * Delete the selected fields with full cascade: their value pool (plus the
     * values' translations, price impacts, per-item subsets and combination
     * links), the per-item assignments, the category scoping and the field
     * translations themselves.
     *
     * Combinations that referenced a deleted variant field keep their rows but
     * lose the link; the item's combination generator flags them for review
     * on the next regenerate.
     *
     * @param array &$pks The ids of the fields to delete.
     *
     * @return bool True on success.
     *
     * @since  1.3.0
     */
    public function delete(&$pks)
    {
        $db = $this->getDatabase();
        $pks = array_map('intval', (array) $pks);

        // Permission first: the usage guard below reads the value pool and explains which
        // items hold a field, work a user who may not delete fields must not trigger.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        if (!$pks) {
            return true;
        }

        // Collect the fields' value ids first: their dependents go with them.
        $query = $db->getQuery(true)
            ->select($db->qn('id'))
            ->from($db->qn('#__alfa_item_field_values'))
            ->whereIn($db->qn('item_field_id'), $pks);
        $db->setQuery($query);
        $valueIds = array_map('intval', $db->loadColumn());

        // GUARD: a field an item still depends on is not deletable: as a variant
        // dimension it is part of the combination's identity, and as an option or
        // a spec it is a line the product still shows. Unpublishing is the
        // reversible way to retire one; deletion is refused while anything points
        // at it. The question is shared with the Values tab; only the response
        // differs (see usageBinding()).
        if ($valueIds) {
            $binding = $this->usageBinding($valueIds);

            if ($binding['items'] > 0) {
                // Only the surfaces that actually hold it: "0 offered options" in a
                // refusal reads like a bug report, not an explanation.
                $via = [];

                foreach (['combinations', 'options', 'informational'] as $surface) {
                    if ($binding[$surface] > 0) {
                        $via[] = Text::plural(
                            'COM_ALFA_ITEMFIELD_DELETE_IN_USE_' . strtoupper($surface),
                            $binding[$surface],
                        );
                    }
                }

                Factory::getApplication()->enqueueMessage(
                    Text::plural('COM_ALFA_ITEMFIELD_DELETE_IN_USE', $binding['items'])
                    . ' ' . Text::sprintf('COM_ALFA_ITEMFIELD_DELETE_IN_USE_VIA', implode(', ', $via))
                    . ' ' . Text::_('COM_ALFA_ITEMFIELD_DELETE_IN_USE_UNPUBLISH'),
                    'error',
                );

                return false;
            }
        }

        // Which items carry this field's values, captured NOW because the
        // cascade below deletes #__alfa_item_field_value_index, after which the
        // association is unrecoverable. Without this their search documents keep
        // the dead field's value names (and greeklish aliases) forever.
        $affectedItemIds = array_map('intval', $db->setQuery(
            $db->getQuery(true)
                ->select('DISTINCT ' . $db->qn('item_id'))
                ->from($db->qn('#__alfa_item_field_value_index'))
                ->whereIn($db->qn('field_id'), $pks),
        )->loadColumn() ?: []);

        // The field rows themselves go through the standard pipeline (ACL,
        // checked-out guard, content events); children cascade after, all in
        // one transaction so a mid-way failure leaves nothing orphaned.
        $nested = TransactionState::isOpen(db: $db);
        $db->transactionStart(true);

        try {
            if (!parent::delete($pks)) {
                $db->transactionRollback(true);

                return false;
            }

            if ($valueIds) {
                // Live cart selections go with them; cascadeDeleteValues() owns that.
                $this->cascadeDeleteValues(valueIds: $valueIds);
            }

            // SELECTION-ONLY, again: the field is gone, the cart lines that
            // referenced it are not. Runs inside the transaction opened above,
            // so the repository nests as a savepoint.
            (new CartRepository($db))->removeSelectionsForFields($pks);

            // The search index and category links of the dead fields.
            foreach (
                [
                    '#__alfa_item_field_value_index' => 'field_id',
                    '#__alfa_item_field_categories' => 'item_field_id',
                ] as $tableName => $column
            ) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->qn($tableName))
                        ->whereIn($db->qn($column), $pks),
                )->execute();
            }

            // Plugin configs of the deleted fields (item-level + variant rows).
            FieldParams::deleteForFields(fieldIds: $pks);

            // MULTILINGUAL: remove the fields' per-language rows.
            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_item_field',
                tableName:         '#__alfa_item_fields',
            );

            $db->transactionCommit(true);
        } catch (Throwable $e) {
            // A failure that ended the transaction this was called inside is
            // that caller's to hear: answered with a refusal, the caller would
            // commit work the server has already discarded.
            if (TransactionState::rollBackLevel(db: $db, failure: $e) && $nested) {
                throw $e;
            }

            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');

            return false;
        }

        // Rebuild the projections of every item that referenced the dead field,
        // now that the cascade is committed. Mirrors save()'s resync; the delete
        // side simply never had one.
        if ($affectedItemIds) {
            (new ProjectionManager())->syncItems($affectedItemIds);
        }

        return true;
    }
}
