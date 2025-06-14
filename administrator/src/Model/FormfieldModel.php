<?php

/**
 * @version    CVS: 1.0.1
 * @package    Com_Alfa
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2025 Easylogic CO LP
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Model;

// No direct access.
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Helper\TagsHelper;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Event\Model;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\String\StringHelper;

/**
 * Item model.
 *
 * @since  1.0.1
 */
class FormfieldModel extends AdminModel
{
    /**
     * @var    string  Alias to manage history control
     *
     */
    public $typeAlias = 'com_alfa.formfield';

    protected $formName = 'formfield';

    /**
     * @var    null  Item data
     *
     * @since  1.0.0
     */
    protected $item = null;
    protected $orderUserInfoTableName = "#__alfa_user_info";


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
                'control'   => 'jform',
                'load_data' => $loadData
            ]
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
     * @return  mixed  The data for the form.
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

            $data               = $this->item;
            $data->fieldsparams = $data->params;

        }

        return $data;
    }

    public function getItem($pk = null)
    {

        if ($item = parent::getItem($pk)) {
            $item->users      = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_form_fields_users', 'field_id', 'user_id');
            $item->usergroups = AlfaHelper::getAssocsFromDb($item->id, '#__alfa_form_fields_usergroups', 'field_id', 'usergroup_id');
        }

        return $item;
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

        $app = Factory::getApplication();
        //		$db = $this->getDatabase();
        //	    $input = $app->getInput();
        $table = $this->getTable();
        $key   = $table->getKeyName();
        $isNew = $data[$key] <= 0;

        $data['field_name'] = $data['field_name'] ?: $data['name'];
        $data['field_name'] = OutputFilter::stringURLSafe($data['field_name']);

        //	    Is no needed because the manageUserInfoTable handles the duplicate new columns
        //      dynamically change name which is the column we will add in form fields, if already exists
        //	    if ($table->load(['field_name' => $data['field_name']])) {
        //		    if ($table->id != $data['id'])
        //		    {
        //			    $data['field_name'] = self::generateNewName($data['field_name'], 'field_name');
        //		    }
        //	    }

        // Assign plugin field params to our params variable in the database
        $data['params'] = (isset($data['fieldsparams']) && is_array($data['fieldsparams']))
            ? (json_encode($data['fieldsparams']) ?: null) : null;


        // Edit order's user info table - the field_name maybe change inside so we update the variable if so
        $data['field_name'] = self::manageUserInfoTable($data);


        if (!parent::save($data)) {
            return false;
        }

        $currentId = !$isNew ? intval($data['id']) : intval($this->getState($this->getName() . '.id'));

        $assignZeroIdIfDataEmpty = true;
        AlfaHelper::setAssocsToDb($currentId, $data['users'], '#__alfa_form_fields_users', 'field_id', 'user_id', $assignZeroIdIfDataEmpty);
        AlfaHelper::setAssocsToDb($currentId, $data['usergroups'], '#__alfa_form_fields_usergroups', 'field_id', 'usergroup_id', $assignZeroIdIfDataEmpty);

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
        $user = $this->getCurrentUser();

        if ($table->id == 0 && empty($table->created_by)) {
            $table->created_by = $user->id;
        }

        $table->modified    = Factory::getDate()->toSql();
        $table->modified_by = $user->id;

        parent::prepareTable($table);

    }

    public function delete(&$pks)
    {

        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        // Delete field's columns from user info table.
        $fieldNames = self::getFieldNames($pks);
        self::dropUserInfoColumns($fieldNames);

        $query
            ->delete("#__alfa_form_fields")
            ->whereIn($db->qn("id"), $pks);

        $db->setQuery($query);
        $db->execute();

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

        $db        = $this->getDatabase();
        $origTable = $this->getTable();
        $tableKey  = $origTable->getKeyName();

        $isNew           = $data['id'] <= 0;
        $fallBackSqlType = 'text'; // Default SQL type if none provided

        $newTableColumnName = $data['field_name'];
        $type               = $data["fieldsparams"]["sql_type"] ?: $fallBackSqlType;

        // Get all columns from the user info table
        $tableColumns = $db->getTableColumns($this->orderUserInfoTableName, false);

        // Load the original record by its primary key
        $origTableLoaded = $origTable->load($data[$tableKey]);

        // Get info about the previous and current columns if they exist
        $previousColumn = $origTableLoaded ? ($tableColumns[$origTable->field_name] ?? null) : null;
        $currentColumn  = $tableColumns[$newTableColumnName] ?? null;
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
        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        $query = "ALTER TABLE " . $db->qn($this->orderUserInfoTableName) . " ADD " . $db->qn($fieldName)
            . " " . $fieldType . " NULL";   // Used to be NOT NULL, but I think allowing NULL is better because
        //  it allows the user to submit form data even when some fields are unpublished.

        $db->setQuery($query);
        $db->execute();
    }

    // Updates an existing field in the #__alfa_user_info table.
    protected function updateUserInfoField($previousFieldName, $newFieldName, $fieldType)
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        $query = 'ALTER TABLE ' . $db->qn($this->orderUserInfoTableName) . " CHANGE "
            . $db->qn($previousFieldName) . " " . $db->qn($newFieldName) . " "
            . $fieldType . " NULL";     // Used to be NOT NULL.

        $db->setQuery($query);
        $db->execute();
    }

    /**
     *  Checks if a table contains a column with the given name.
     *  Returns the column's data if it does, NULL if not.
     *
     * @param $tableName string the name of the table to get the column from.
     * @param $field     string the name of the column to return.
     *
     * @return object|NULL
     *
     * @since 1.0
     */
    protected function getTableField($tableName, $field)
    {
        $db      = $this->getDatabase();
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
        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        $query
            ->select($db->qn("field_name"))
            ->from($db->qn("#__alfa_form_fields"))
            ->whereIn($db->qn("id"), $pks);
        $db->setQuery($query);

        return $db->loadColumn();
    }

    protected function dropUserInfoColumns($columnNames = [])
    {

        if (empty($columnNames)) {
            return;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        $query = "ALTER TABLE " . $db->qn($this->orderUserInfoTableName) . " ";
        $query .= "DROP COLUMN " . $db->qn($columnNames[0]);
        unset($columnNames[0]);

        foreach ($columnNames as $columnName) {
            $query .= ", DROP COLUMN " . $db->qn($columnName);
        }
        $query .= ";";

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), "error");
        }

    }


    protected function generateNewName($name, $columnName)
    {
        $table = $this->getTable();
        //		$titleField = $table->getColumnAlias($columnName);

        while ($table->load([$columnName => $name])) {
            $name = StringHelper::increment($name, 'dash');
        }

        return $name;
    }


}
