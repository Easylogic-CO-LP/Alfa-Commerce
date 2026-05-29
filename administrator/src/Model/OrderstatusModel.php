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

use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Table\Table;

/**
 * Item model.
 *
 * @since  1.0.1
 */
class OrderstatusModel extends AdminModel
{
    /**
     * @var string Alias to manage history control
     */
    public $typeAlias = 'com_alfa.orderstatus';

    protected $formName = 'orderstatus';

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

    // protected $item = null;
    // protected $batch_copymove = false;

    public function getForm($data = [], $loadData = true)
    {
        // Initialise variables.
        // $app = Factory::getApplication();
        // Form::addFieldPath(JPATH_ADMINISTRATOR . '/components/com_users/models/fields');

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
     * @since   1.0.1
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState('com_alfa.edit.orderstatus.data', []);

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
     * @since   1.0.1
     */

    public function getItem($pk = null)
    {
        if ($item = parent::getItem($pk)) {
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
        // name_el_gr …) that the default 'array' filter would strip.
        $rawData = $input->post->get('jform', [], 'raw');
        $data = array_merge($data, $rawData);

        if (!parent::save($data)) {
            return false;
        }

        $currentId = ($data['id'] > 0)
            ? (int) $data['id']
            : (int) $this->getState($this->getName() . '.id');

        // MULTILINGUAL: persist the per-language name to the language tables.
        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_orderstatus',
            tableName:         '#__alfa_orders_statuses',
            data:              $data,
            aliasFields:       [],
        );

        return true;
    }

    /**
     * Method to delete one or more records.
     *
     * @param array &$pks An array of record primary keys.
     *
     * @return bool True on success.
     *
     * @since   1.0.1
     */
    public function delete(&$pks)
    {
        $result = parent::delete($pks);

        if ($result && !empty($pks)) {
            // MULTILINGUAL: remove the per-language rows for the deleted statuses.
            MultilingualHelper::deleteMultilingualData(
                ids:               $pks,
                primaryColumnName: 'id_orderstatus',
                tableName:         '#__alfa_orders_statuses',
            );
        }

        return $result;
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
        $table->modified = Factory::getDate()->toSql();
        $table->modified_by = $this->getCurrentUser()->id;

        return parent::prepareTable($table);
    }
}
