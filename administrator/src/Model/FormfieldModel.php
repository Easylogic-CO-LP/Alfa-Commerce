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
use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Alfa\Component\Alfa\Administrator\Helper\RoleRegistry;
use Alfa\Component\Alfa\Administrator\Helper\SetupHelper;
use Exception;
use Joomla\CMS\Event\Model;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Table\Table;
use Joomla\String\StringHelper;
use RuntimeException;

/**
 * Item model.
 *
 * @since  1.0.0
 */
class FormfieldModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity of this screen.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'formfield';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save() aborts BEFORE parent::save() when the derived field_name is empty or a
     *   posted value exceeds the field's maxlength, so the onAlfaAdminValidate /
     *   BeforeSave / AfterSave bracket fires only for saves that actually reach the
     *   table; those aborts also happen before any #__alfa_user_info DDL.
     * - delete() always routes through parent::delete(), so the contract's delete
     *   bracket fires on every batch; the forensic column-drop pass runs after it.
     */

    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.formfield';

    protected $formName = 'formfield';

    /**
     * Deferred #__alfa_user_info DDL (column add/rename), captured by
     * manageUserInfoTable() and executed only AFTER the row save succeeds. An
     * ALTER cannot be rolled back, so it must never run before a failed save.
     *
     * @var callable|null
     * @since 1.5.0
     */
    protected $pendingUserInfoDdl = null;

    /**
     * @var null Item data
     *
     * @since  1.0.0
     */
    protected $item = null;
    protected $orderUserInfoTableName = '#__alfa_user_info';

    /**
     * Joomla's AdminModel::batch() handles copy/move. We only reassign group_id,
     * so disable copy/move and register the custom command.
     */
    protected $batch_copymove = false;

    protected $batch_commands = [
        'group_id' => 'batchGroup',
    ];

    /**
     * Reassign selected fields to the chosen group (0 = ungrouped).
     * @since  1.0.0
     */
    protected function batchGroup($value, $pks, $contexts)
    {
        $app = Factory::getApplication();

        if ($value === '' || $value === null) {
            $app->enqueueMessage(Text::_('COM_ALFA_FORM_FIELDS_GROUP_NOT_CHANGED'), 'info');
            return true;
        }

        $groupId = (int) $value;

        // Reject non-zero ids that don't exist in the groups table (prevents orphan assignments).
        if ($groupId > 0) {
            $db = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__alfa_form_field_groups'))
                ->where('id = ' . $groupId);
            $db->setQuery($query);

            if ((int) $db->loadResult() === 0) {
                $app->enqueueMessage(Text::_('COM_ALFA_FORM_FIELDS_GROUP_INVALID'), 'error');
                return false;
            }
        }

        $pks = array_map('intval', (array) $pks);
        if (!$pks) {
            return true;
        }

        $db = $this->getDatabase();
        $update = $db->getQuery(true)
            ->update($db->quoteName('#__alfa_form_fields'))
            ->set($db->quoteName('group_id') . ' = ' . $groupId)
            ->whereIn($db->quoteName('id'), $pks);
        $db->setQuery($update);
        $db->execute();

        $app->enqueueMessage(Text::_('COM_ALFA_FORM_FIELDS_GROUP_SET_SUCCESSFULLY'), 'info');
        return true;
    }

    /**
     * Unparameterised MySQL types accepted as-is (no size/precision).
     */
    private const ALLOWED_SQL_TYPES_SIMPLE = [
        'tinyint', 'smallint', 'mediumint', 'int', 'bigint',
        'float', 'double', 'real',
        'date', 'datetime', 'timestamp', 'time', 'year',
        'tinytext', 'text', 'mediumtext', 'longtext',
        'json',
        'tinyblob', 'blob', 'mediumblob', 'longblob',
        'boolean', 'bool',
    ];

    /**
     * Regex patterns for parameterised types (varchar(N), decimal(M,N), etc.).
     * Keep sizes capped to reasonable values so nobody passes varchar(99999999).
     */
    private const ALLOWED_SQL_TYPES_PATTERNS = [
        '/^(tiny|small|medium|big)?int\(\d{1,3}\)$/',
        '/^(decimal|numeric)\(\d{1,3},\d{1,3}\)$/',
        '/^(float|double)(\(\d{1,3},\d{1,3}\))?$/',
        '/^(var)?char\(\d{1,5}\)$/',
        '/^(var)?binary\(\d{1,5}\)$/',
    ];

    /**
     * True if $type is a MySQL column type we allow. Trims whitespace and
     * matches case-insensitively. Rejects anything that isn't an exact match
     * (no trailing SQL, no comments, no newlines).
     * @since  1.0.0
     */
    protected static function isAllowedSqlType(string $type): bool
    {
        $t = strtolower(trim($type));

        if ($t === '') {
            return false;
        }

        if (in_array($t, self::ALLOWED_SQL_TYPES_SIMPLE, true)) {
            return true;
        }

        foreach (self::ALLOWED_SQL_TYPES_PATTERNS as $pattern) {
            if (preg_match($pattern, $t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the form, inject plugin (fields-group) fields, and lock structural
     * attributes on edit. Once a field has an id, `type` and `sql_type` are made
     * readonly because changing them would alter the backing #__alfa_user_info column.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     *
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        // Initialise variables.
        $app = Factory::getApplication();
        // Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_users/models/fields');

        // Get the form.
        $form = $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
            [
                'control' => 'jform',
                'load_data' => $loadData,
            ],
        );

        // Get ID of the article from input
        $idFromInput = $app->getInput()->getInt('id', 0);

        // On edit order, we get ID of order from order.id state, but on save, we use data from input
        $id = (int) $this->getState($this->formName . '.id', $idFromInput);

        if (empty($form)) {
            return false;
        }

        $item = ($this->item === null ? $this->getItem() : $this->item);

        AlfaHelper::addPluginForm(form: $form, data: (array) $data, values: (array) ($item->params ?? []), type: 'formfields');

        if ($id > 0) {
            $form->setFieldAttribute('type', 'readonly', 'true'); // field type cannot change cause the sql type may change also
            $form->setFieldAttribute('sql_type', 'readonly', 'true', 'formfieldsparams'); // sql type can never change
        }

        // A SYSTEM row's definition is the product's; the editor must not offer
        // what save() will refuse. What stays editable is what the merchant
        // owns: the label, the group, the order and where it shows (above its
        // floor). Uniqueness is deliberately absent, not merely fixed: a
        // checkout address is not an account, so the same email may appear on
        // many records (a returning guest, a household); the account's
        // address is what Joomla keeps unique, at registration.
        if ($id > 0 && (int) ($item->is_system ?? 0) === 1) {
            $form->setFieldAttribute('field_name', 'readonly', 'true');
            $form->setFieldAttribute('field_name', 'class', 'readonly');
            $form->removeField('unique');
            $form->removeField('users');
            $form->removeField('usergroups');
            $form->removeField('state');

            foreach (['registration', 'shipping', 'billing'] as $section) {
                if (RoleRegistry::floor((string) $item->role, $section) === FieldsHelper::LEVEL_REQUIRED) {
                    // The floor is not a default the merchant can talk down.
                    $form->setFieldAttribute($section, 'readonly', 'true');
                    $form->setFieldAttribute($section, 'filter', 'unset');
                }
            }
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
            stateKey: 'com_alfa.edit.formfield.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;
            $data->formfieldsparams = $data->params;

            // SHOWON lives inside the params JSON. Mirror it onto the
            // standalone top-level `showon` field so ShowonField hydrates
            // the builder on edit (replaces the old formfieldsparams wrapper).
            $p = $data->params;
            if (is_string($p)) {
                $p = json_decode($p, true) ?: [];
            }
            $p = (array) $p;
            $data->showon = (string) ($p['showon'] ?? '');
        }

        return $data;
    }

    /**
     * Load a form field and attach its assigned user and usergroup ids
     * (read from the #__alfa_form_fields_users / _usergroups join tables).
     *
     * @param int $pk The id of the field to load (null = state id).
     *
     * @return object|bool The populated field object, or false on failure.
     *
     * @since  1.0.0
     */
    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
            $item->users = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_form_fields_users', 'field_id', 'user_id');
            $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_form_fields_usergroups', 'field_id', 'usergroup_id');
        }

        return $item;
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

        $table = $this->getTable();
        $key = $table->getKeyName();
        $isNew = $data[$key] <= 0;

        // IDENTITY ROLE + SYSTEM LOCK are written by the seeds, never by a form
        // post: a crafted request must not turn a merchant field into "the
        // email". A SYSTEM row keeps its definition (type, column, role, lock,
        // published, not unique) and its visibility floors; the merchant owns
        // position, group, label and everything above the floor.
        $stored = (!$isNew && $table->load((int) $data[$key])) ? clone $table : null;
        $data['role'] = $stored->role ?? null;
        $data['is_system'] = (int) ($stored->is_system ?? 0);

        if ($stored !== null && (int) $stored->is_system === 1) {
            $data['type'] = $stored->type;
            $data['field_name'] = $stored->field_name;
            $data['state'] = 1;
            $data['unique'] = 0;

            foreach (['registration', 'shipping', 'billing'] as $section) {
                $data[$section] = max(
                    (int) ($data[$section] ?? 0),
                    RoleRegistry::floor((string) $stored->role, $section),
                );
            }

            // Shown to EVERY customer: a user/usergroup restriction would hide
            // the identity capture from exactly the guests it exists for.
            $data['users'] = [];
            $data['usergroups'] = [];

            // The definition includes what the value IS: the email row keeps
            // its email validation and both rows stay plain text (filter),
            // whatever the params tab posts.
            if (!isset($data['formfieldsparams']) || !is_array($data['formfieldsparams'])) {
                $data['formfieldsparams'] = [];
            }

            $data['formfieldsparams']['filter'] = 'string';
            $data['formfieldsparams']['validation'] = $stored->role === RoleRegistry::EMAIL ? 'email' : '';
        }

        // field_name is the machine key / DB column (NOT translatable). When the
        // user leaves it blank, derive it from the name, preferring the default
        // language, then any other language that actually has a value.
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

        // A field_name is mandatory: it becomes the #__alfa_user_info column.
        // Bail out with a friendly message rather than letting an empty
        // "ALTER TABLE ADD ``" blow up (MySQL 1166 Incorrect column name '').
        if ($data['field_name'] === '') {
            $this->setError(Text::_('COM_ALFA_FORMFIELD_NAME_REQUIRED'));

            return false;
        }

        // SHOWON: the standalone `showon` field carries the builder's
        // canonical JSON. Fold it into formfieldsparams so it persists in the
        // params JSON (FormFieldsPlugin reads params->get('showon')); no DB
        // column and no formfieldsparams wrapper needed.
        if (array_key_exists('showon', $data)) {
            if (!isset($data['formfieldsparams']) || !is_array($data['formfieldsparams'])) {
                $data['formfieldsparams'] = [];
            }
            $data['formfieldsparams']['showon'] = (string) $data['showon'];
            unset($data['showon']);
        }

        // Assign plugin field params to our params variable in the database
        $data['params'] = (isset($data['formfieldsparams']) && is_array($data['formfieldsparams']))
            ? (json_encode($data['formfieldsparams'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null) : null;

        // Validate BEFORE any schema change: a too-long value must abort without
        // having altered the backing column. (The cast must wrap the coalesce:
        // `(string) $data['value'] ?? ''` binds the ?? to the cast, so the guard was
        // dead and warned on a missing key.)
        $max = (int) ($data['formfieldsparams']['maxlength'] ?? 0);
        if ($max > 0 && mb_strlen((string) ($data['value'] ?? '')) > $max) {
            $this->setError(Text::sprintf('COM_ALFA_FIELD_VALUE_TOO_LONG', $max));
            return false;
        }

        // Resolve the final column name now (the row save needs it), but DEFER the
        // actual ALTER on #__alfa_user_info until the row save succeeds: an ALTER is
        // not transactional, so running it before a failed save would rename the
        // column while #__alfa_form_fields still stored the old field_name.
        $this->pendingUserInfoDdl = null;
        $data['field_name'] = $this->manageUserInfoTable($data);

        if (!parent::save($data)) {
            return false;
        }

        if ($this->pendingUserInfoDdl !== null) {
            ($this->pendingUserInfoDdl)();
            $this->pendingUserInfoDdl = null;
        }

        $currentId = !$isNew ? intval($data['id']) : intval($this->getState($this->getName() . '.id'));

        // MULTILINGUAL: persist per-language translations (name, field_label,
        // field_description).
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_formfield',
            tableName:         '#__alfa_form_fields',
            data:              $data,
            aliasFields:       [],
        );

        // An unposted (empty) visibility multiselect omits its key, hence `?? []`.
        $assignZeroIdIfDataEmpty = true;
        AlfaHelper::setAssocsToDb($currentId, $data['users'] ?? [], '#__alfa_form_fields_users', 'field_id', 'user_id', $assignZeroIdIfDataEmpty);
        AlfaHelper::setAssocsToDb($currentId, $data['usergroups'] ?? [], '#__alfa_form_fields_usergroups', 'field_id', 'usergroup_id', $assignZeroIdIfDataEmpty);

        return true;
    }

    // SHOWON compile shim removed: ShowonField is now the single
    // producer of the canonical engine JSON (per-glue recursive schema).

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

        parent::prepareTable($table);
    }

    /**
     * Delete the selected form fields, dropping their backing columns from the
     * #__alfa_user_info table and removing their per-language translation rows.
     *
     * @param array &$pks The ids of the fields to delete.
     *
     * @return void
     *
     * @since  1.0.0
     */
    public function delete(&$pks)
    {
        // Permission first: the system-row and order-data checks below query the fields and
        // report on them, which a user who may not delete must not be shown.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $pks = $this->withoutSystemRows(array_map('intval', (array) $pks));

        if ($pks === []) {
            return false;
        }

        $db = $this->getDatabase();
        $fieldNames = self::getFieldNames($pks);

        // FORENSIC GUARD: a field column holding data referenced by placed
        // orders is part of the historical record: the field row goes, the
        // COLUMN stays (dormant, recoverable); only truly unused columns
        // are dropped. Orders reference user_info rows by id, so the check
        // is: any non-empty value on a row an order points at.
        $droppable = [];
        $preserved = [];

        foreach ($fieldNames as $columnName) {
            $inOrders = (int) $db->setQuery(
                'SELECT COUNT(*) FROM ' . $db->qn('#__alfa_user_info') . ' AS ui'
                . ' WHERE TRIM(COALESCE(ui.' . $db->qn($columnName) . ", '')) != ''"
                . ' AND EXISTS (SELECT 1 FROM ' . $db->qn('#__alfa_orders') . ' AS o'
                . ' WHERE o.' . $db->qn('id_address_delivery') . ' = ui.' . $db->qn('id')
                . ' OR o.' . $db->qn('id_address_invoice') . ' = ui.' . $db->qn('id') . ')',
            )->loadResult();

            if ($inOrders > 0) {
                $preserved[] = $columnName;
            } else {
                $droppable[] = $columnName;
            }
        }

        // The rows, ACL, checked-out state and content events belong to the
        // standard pipeline.
        if (!parent::delete($pks)) {
            return false;
        }

        // Visibility pivots of the deleted fields.
        foreach (['#__alfa_form_fields_usergroups', '#__alfa_form_fields_users'] as $pivotTable) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->qn($pivotTable))
                    ->whereIn($db->qn('field_id'), $pks),
            )->execute();
        }

        // MULTILINGUAL: remove the per-language rows for the deleted fields.
        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_formfield',
            tableName:         '#__alfa_form_fields',
        );

        if ($droppable) {
            self::dropUserInfoColumns($droppable);
        }

        if ($preserved) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_ALFA_FORMFIELD_COLUMNS_PRESERVED', implode(', ', $preserved)),
                'notice',
            );
        }

        return true;
    }

    /**
     * Publish state changes: a SYSTEM row is always published (the identity
     * capture it carries is not the merchant's to switch off), so it is taken
     * off the list for anything but "publish" and the rest proceeds.
     *
     * @param array &$pks The primary keys to change.
     * @param int $value The new state value.
     *
     * @return bool True on success.
     *
     * @since  1.10.0
     */
    public function publish(&$pks, $value = 1)
    {
        // Permission first: the system-row check below queries the fields and reports on them.
        // Refused the way AdminModel::publish() refuses.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'edit.state', user: $this->getCurrentUser())) {
            Log::add(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), Log::WARNING, 'jerror');
            $pks = [];

            return false;
        }

        $pks = array_map('intval', (array) $pks);

        if ((int) $value !== 1) {
            $pks = $this->withoutSystemRows($pks);

            if ($pks === []) {
                return false;
            }
        }

        return parent::publish($pks, $value);
    }

    /**
     * Set one section's visibility level for one field.
     *
     * The same authority the editor uses: a system row cannot go below its
     * floor, so the value stored is what the product allows rather than what
     * was asked for, and the caller is told which one that was.
     *
     * @param int $id The field.
     * @param string $section registration | shipping | billing.
     * @param int $level 0 hidden, 1 optional, 2 required.
     *
     * @return int The level actually stored.
     *
     * @throws RuntimeException When the field, the section or the level is not one we know.
     *
     * @since  1.10.0
     */
    public function setVisibility(int $id, string $section, int $level): int
    {
        if (!\in_array($section, ['registration', 'shipping', 'billing'], true)) {
            throw new RuntimeException(Text::_('COM_ALFA_FORMFIELD_VISIBILITY_INVALID'));
        }

        $level = max(FieldsHelper::LEVEL_HIDDEN, min(FieldsHelper::LEVEL_REQUIRED, $level));

        $table = $this->getTable();

        if ($id <= 0 || !$table->load($id)) {
            throw new RuntimeException(Text::_('COM_ALFA_FORMFIELD_VISIBILITY_INVALID'));
        }

        // Someone else is editing this row; their save would overwrite this.
        if (!empty($table->checked_out) && (int) $table->checked_out !== (int) $this->getCurrentUser()->id) {
            throw new RuntimeException(Text::_('JLIB_APPLICATION_ERROR_CHECKIN_USER_MISMATCH'));
        }

        if ((int) $table->is_system === 1) {
            $level = max($level, RoleRegistry::floor((string) $table->role, $section));
        }

        $db = $this->getDatabase();
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__alfa_form_fields'))
                ->set($db->quoteName($section) . ' = ' . $level)
                ->where($db->quoteName('id') . ' = ' . $id),
        )->execute();

        FieldsHelper::clearFieldsCache();
        RoleRegistry::clearCache();
        SetupHelper::invalidateFor($this->alfaEntity);

        return $level;
    }

    /**
     * The given ids minus the SYSTEM rows, with a notice when any were dropped.
     *
     * @param int[] $pks The ids.
     *
     * @return int[]
     *
     * @since  1.10.0
     */
    private function withoutSystemRows(array $pks): array
    {
        if ($pks === []) {
            return [];
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__alfa_form_fields'))
            ->where($db->quoteName('is_system') . ' = 1')
            ->whereIn($db->quoteName('id'), $pks);
        $locked = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);

        if ($locked !== []) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_ALFA_FORMFIELD_SYSTEM_LOCKED'), 'notice');
        }

        return array_values(array_diff($pks, $locked));
    }

    /**
     *  Alters the table keeping track of each order's user info.
     *      - If the id of the field is 0 (new entry), we add a new column to the table.
     *      - Otherwise, we check to see if the name of the field has been changed.
     *          - If it has, we update the column's name.
     *          - If it hasn't, we don't do anything.
     *
     * @param $data array the data given by the user.
     *
     * @return string|mixed
     */

    //	TODO: HANDLE DUPLICATES

    protected function manageUserInfoTable(array $data): string
    {
        $type = $data['formfieldsparams']['sql_type'] ?? 'text';
        if (!self::isAllowedSqlType($type)) {
            throw new RuntimeException('Invalid sql_type for form field: ' . $type);
        }

        $db = $this->getDatabase();
        $origTable = $this->getTable();
        $tableKey = $origTable->getKeyName();

        $isNew = $data['id'] <= 0;
        $fallBackSqlType = 'text'; // Default SQL type if none provided

        $newTableColumnName = $data['field_name'];
        $type = $data['formfieldsparams']['sql_type'] ?: $fallBackSqlType;

        // Get all columns from the user info table
        $tableColumns = $db->getTableColumns($this->orderUserInfoTableName, false);

        // Load the original record by its primary key
        $origTableLoaded = $origTable->load($data[$tableKey]);

        // Get info about the previous and current columns if they exist
        $previousColumn = $origTableLoaded ? ($tableColumns[$origTable->field_name] ?? null) : null;
        $currentColumn = $tableColumns[$newTableColumnName] ?? null;
        $previousTableColumnName = $previousColumn ? $previousColumn->Field : '';

        // Check if the field name has changed
        $columnNameChanged = !empty($previousColumn) && ((string) $previousTableColumnName != (string) $newTableColumnName);

        if (
            !$isNew &&                  // Existing record
            $columnNameChanged 		    // Field name has changed
        ) {
            if (!empty($currentColumn)) { //change the new column name if already exists
                while (array_key_exists($newTableColumnName, $tableColumns)) {
                    $newTableColumnName = StringHelper::increment($newTableColumnName, 'dash');
                }
            }
            // DEFERRED: run the rename only after the row save succeeds (see save()).
            $fromColumn = $origTable->field_name;
            $columnType = $previousColumn->Type;
            // NOT a static closure: the DDL helpers are instance methods and need $this.
            $this->pendingUserInfoDdl = fn () => $this->updateUserInfoField(
                previousFieldName: $fromColumn,
                newFieldName:      $newTableColumnName,
                fieldType:         $columnType,
            );
        } elseif (
            $isNew ||                   // New record to insert
            empty($previousColumn)      // Previous column was deleted or missing
        ) {
            if (!empty($currentColumn)) { //change the new column name if already exists
                while (array_key_exists($newTableColumnName, $tableColumns)) {
                    $newTableColumnName = StringHelper::increment($newTableColumnName, 'dash');
                }
            }

            // DEFERRED: create the column only after the row save succeeds (see save()).
            // NOT a static closure: the DDL helpers are instance methods and need $this.
            $this->pendingUserInfoDdl = fn () => $this->insertNewUserInfoField(
                fieldName: $newTableColumnName,
                fieldType: $type,
            );
        }

        // Return the final column name (may have been incremented if renamed)
        return $newTableColumnName;
    }

    // Inserts a new field in the #__alfa_user_info table.
    protected function insertNewUserInfoField($fieldName, $fieldType)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query = 'ALTER TABLE ' . $db->qn($this->orderUserInfoTableName) . ' ADD ' . $db->qn($fieldName)
            . ' ' . $fieldType . ' NULL';   // Used to be NOT NULL, but I think allowing NULL is better because
        //  it allows the user to submit form data even when some fields are unpublished.

        $db->setQuery($query);
        $db->execute();
    }

    // Updates an existing field in the #__alfa_user_info table.
    protected function updateUserInfoField($previousFieldName, $newFieldName, $fieldType)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query = 'ALTER TABLE ' . $db->qn($this->orderUserInfoTableName) . ' CHANGE '
            . $db->qn($previousFieldName) . ' ' . $db->qn($newFieldName) . ' '
            . $fieldType . ' NULL';     // Used to be NOT NULL.

        $db->setQuery($query);
        $db->execute();
    }

    /**
     *  Checks if a table contains a column with the given name.
     *  Returns the column's data if it does, NULL if not.
     *
     * @param $tableName string the name of the table to get the column from.
     * @param $field string the name of the column to return.
     *
     * @return object|null
     *
     * @since  1.0.0
     */
    protected function getTableField($tableName, $field)
    {
        $db = $this->getDatabase();
        $columns = $db->getTableColumns($tableName, false);

        return !empty($columns[$field]) ? $columns[$field] : null;
    }

    protected function getFieldNames($pks)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query
            ->select($db->qn('field_name'))
            ->from($db->qn('#__alfa_form_fields'))
            ->whereIn($db->qn('id'), $pks);
        $db->setQuery($query);

        return $db->loadColumn();
    }

    /**
     * Drop the given columns from the #__alfa_user_info table in a single
     * ALTER TABLE statement. Errors are caught and surfaced as messages.
     *
     * @param array $columnNames Column names to drop.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function dropUserInfoColumns($columnNames = [])
    {
        if (empty($columnNames)) {
            return;
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        $query = 'ALTER TABLE ' . $db->qn($this->orderUserInfoTableName) . ' ';
        $query .= 'DROP COLUMN ' . $db->qn($columnNames[0]);
        unset($columnNames[0]);

        foreach ($columnNames as $columnName) {
            $query .= ', DROP COLUMN ' . $db->qn($columnName);
        }
        $query .= ';';

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
        }
    }

    /**
     * Produce a unique value for the given column by appending/incrementing a
     * dash suffix until no existing row matches.
     *
     * @param string $name The starting value.
     * @param string $columnName The column the value must be unique within.
     *
     * @return string A value not currently used in that column.
     *
     * @since  1.0.0
     */
    protected function generateNewName($name, $columnName)
    {
        $table = $this->getTable();

        while ($table->load([$columnName => $name])) {
            $name = StringHelper::increment($name, 'dash');
        }

        return $name;
    }

    // Gets the IDs and names of all available form fields.
    public function getAllFields()
    {
        $db = self::getDatabase();
        $query = $db->getQuery(true);

        $query
            ->select($db->qn('a.field_name'))
            ->from($db->qn('#__alfa_form_fields', 'a'))
            ->where($db->qn('a.state') . '=' . $db->q('1'));  // Published fields.

        // The name is translatable: it lives ONLY in the per-language tables.
        MultilingualHelper::addMultilingualJoinToQuery(
            query:             $query,
            mainAlias:         'a',
            mainPrimaryColumn: 'id',
            langTableBase:     '#__alfa_form_fields',
            langPrimaryColumn: 'id_formfield',
            fields:            ['name'],
        );

        $db->setQuery($query);
        return $db->loadObjectList();
    }
}
