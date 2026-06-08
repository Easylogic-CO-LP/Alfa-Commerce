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
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Exception;
use Joomla\CMS\Event\Model;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;
use Joomla\String\StringHelper;
use RuntimeException;

/**
 * Item model.
 *
 * @since  1.0.1
 */
class FormfieldModel extends AdminModel
{
    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.formfield';

    protected $formName = 'formfield';

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
     * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  True to load form data from the model state.
     *
     * @return  \Joomla\CMS\Form\Form|false  The Form object on success, false on failure.
     *
     * @since   1.0.1
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

        // $data    = $data ?: $app->getInput()->get('jform', [], 'array');

        $item = ($this->item === null ? $this->getItem() : $this->item);

        AlfaHelper::addPluginForm($form, $data, $item, 'fields');

        if ($id > 0) {
            $form->setFieldAttribute('type', 'readonly', 'true'); // field type cannot change cause the sql type may change also
            $form->setFieldAttribute('sql_type', 'readonly', 'true', 'fieldsparams'); // sql type can never change
        }

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
        $data = Factory::getApplication()->getUserState('com_alfa.edit.formfield.data', []);

        if (empty($data)) {
            if ($this->item === null) {
                $this->item = $this->getItem();
            }

            $data = $this->item;
            $data->fieldsparams = $data->params;

            // SHOWON lives inside the params JSON. Mirror it onto the
            // standalone top-level `showon` field so ShowonField hydrates
            // the builder on edit (replaces the old fieldsparams wrapper).
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
     * @param   integer  $pk  The id of the field to load (null = state id).
     *
     * @return  object|boolean  The populated field object, or false on failure.
     *
     * @since   1.0.1
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
     * @since   1.6
     */
    public function save($data)
    {
        $app = Factory::getApplication();
        $input = $app->getInput();

        // 'raw' filter preserves the per-language flat keys (name_en_gb,
        // field_label_el_gr, field_description_*, meta_*) that the default
        // 'array' filter would strip.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = array_merge($data, $rawData);

        $table = $this->getTable();
        $key = $table->getKeyName();
        $isNew = $data[$key] <= 0;

        // field_name is the machine key / DB column (NOT translatable). When the
        // user leaves it blank, derive it from the name — preferring the default
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

        // A field_name is mandatory — it becomes the #__alfa_user_info column.
        // Bail out with a friendly message rather than letting an empty
        // "ALTER TABLE ADD ``" blow up (MySQL 1166 Incorrect column name '').
        if ($data['field_name'] === '') {
            $this->setError(Text::_('COM_ALFA_FORMFIELD_NAME_REQUIRED'));

            return false;
        }

        //	    Is no needed because the manageUserInfoTable handles the duplicate new columns
        //      dynamically change name which is the column we will add in form fields, if already exists
        //	    if ($table->load(['field_name' => $data['field_name']])) {
        //		    if ($table->id != $data['id'])
        //		    {
        //			    $data['field_name'] = self::generateNewName($data['field_name'], 'field_name');
        //		    }
        //	    }

        // SHOWON: the standalone `showon` field carries the builder's
        // canonical JSON. Fold it into fieldsparams so it persists in the
        // params JSON (FieldsPlugin reads params->get('showon')); no DB
        // column and no fieldsparams wrapper needed.
        if (array_key_exists('showon', $data)) {
            if (!isset($data['fieldsparams']) || !is_array($data['fieldsparams'])) {
                $data['fieldsparams'] = [];
            }
            $data['fieldsparams']['showon'] = (string) $data['showon'];
            unset($data['showon']);
        }

        // Assign plugin field params to our params variable in the database
        $data['params'] = (isset($data['fieldsparams']) && is_array($data['fieldsparams']))
            ? (json_encode($data['fieldsparams']) ?: null) : null;

        // Edit order's user info table - the field_name maybe change inside so we update the variable if so
        $data['field_name'] = self::manageUserInfoTable($data);

        $max = (int) ($data['fieldsparams']['maxlength'] ?? 0);
        if ($max > 0 && mb_strlen((string) $data['value'] ?? '') > $max) {
            $this->setError(Text::sprintf('COM_ALFA_FIELD_VALUE_TOO_LONG', $max));
            return false;
        }

        if (!parent::save($data)) {
            return false;
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

        $assignZeroIdIfDataEmpty = true;
        AlfaHelper::setAssocsToDb($currentId, $data['users'], '#__alfa_form_fields_users', 'field_id', 'user_id', $assignZeroIdIfDataEmpty);
        AlfaHelper::setAssocsToDb($currentId, $data['usergroups'], '#__alfa_form_fields_usergroups', 'field_id', 'usergroup_id', $assignZeroIdIfDataEmpty);

        return true;
    }

    // SHOWON compile shim removed — ShowonField is now the single
    // producer of the canonical engine JSON (per-glue recursive schema).

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
     * @param   array  &$pks  The ids of the fields to delete.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function delete(&$pks)
    {
        $db = $this->getDatabase();
        $query = $db->getQuery(true);

        // Delete field's columns from user info table.
        $fieldNames = self::getFieldNames($pks);
        self::dropUserInfoColumns($fieldNames);

        $query
            ->delete('#__alfa_form_fields')
            ->whereIn($db->qn('id'), $pks);

        $db->setQuery($query);
        $db->execute();

        // MULTILINGUAL: remove the per-language rows for the deleted fields.
        MultilingualHelper::deleteMultilingualData(
            ids:               $pks,
            primaryColumnName: 'id_formfield',
            tableName:         '#__alfa_form_fields',
        );
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
        $type = $data['fieldsparams']['sql_type'] ?? 'text';
        if (!self::isAllowedSqlType($type)) {
            throw new RuntimeException('Invalid sql_type for form field: ' . $type);
        }

        $db = $this->getDatabase();
        $origTable = $this->getTable();
        $tableKey = $origTable->getKeyName();

        $isNew = $data['id'] <= 0;
        $fallBackSqlType = 'text'; // Default SQL type if none provided

        $newTableColumnName = $data['field_name'];
        $type = $data['fieldsparams']['sql_type'] ?: $fallBackSqlType;

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
            self::updateUserInfoField($origTable->field_name, $newTableColumnName, $previousColumn->Type);
        } elseif (
            $isNew ||                   // New record to insert
            empty($previousColumn)      // Previous column was deleted or missing
        ) {
            if (!empty($currentColumn)) { //change the new column name if already exists
                while (array_key_exists($newTableColumnName, $tableColumns)) {
                    $newTableColumnName = StringHelper::increment($newTableColumnName, 'dash');
                }
            }

            self::insertNewUserInfoField($newTableColumnName, $type);
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
     * @since 1.0
     */
    protected function getTableField($tableName, $field)
    {
        $db = $this->getDatabase();
        $columns = $db->getTableColumns($tableName, false);

        return !empty($columns[$field]) ? $columns[$field] : null;
    }

    //	protected function getTableColumns($tableName){
    //		$db = $this->getDatabase();
    //		$columns = $db->getTableColumns($tableName, false);
    //		return $columns;
    //	}

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
     * @param   array  $columnNames  Column names to drop.
     *
     * @return  void
     *
     * @since   1.0.1
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
     * @param   string  $name        The starting value.
     * @param   string  $columnName  The column the value must be unique within.
     *
     * @return  string  A value not currently used in that column.
     *
     * @since   1.0.1
     */
    protected function generateNewName($name, $columnName)
    {
        $table = $this->getTable();
        //		$titleField = $table->getColumnAlias($columnName);

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
            ->select($db->qn('field_name') . ',' . $db->qn('name'))
            ->from($db->qn('#__alfa_form_fields'))
            ->where($db->qn('state') . '=' . $db->q('1'));  // Published fields.

        $db->setQuery($query);
        return $db->loadObjectList();
    }
}
