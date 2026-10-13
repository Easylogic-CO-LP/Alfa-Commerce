<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\KeptPost;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class FormfieldgroupModel extends AlfaAdminModel
{
    /**
     * The admin-contract entity this model serves.
     *
     *
     * @since  2.0.0
     */
    protected string $alfaEntity = 'formfieldgroup';

    /**
     * CONTRACT COVERAGE NOTES (decided at migration):
     * - save() calls getForm() again (multilingual merge of the raw per-language keys)
     *   BEFORE parent::save(), so the main form's Form/PrepareData events fire a second
     *   time during a save request; same behavior as the other multilingual entities.
     * - delete() pre-counts referencing fields and, after parent::delete(), moves them
     *   to ungrouped; all paths reach parent::delete(), so the contract's delete
     *   bracket always fires around the actual row removal.
     */

    public $typeAlias = 'com_alfa.formfieldgroup';

    protected $formName = 'formfieldgroup';

    /**
     * Build the form for the form-field group edit screen.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false The Form object on success, false on failure.
     * @since  1.0.0
     */
    public function getForm($data = [], $loadData = true)
    {
        return $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
            ['control' => 'jform', 'load_data' => $loadData],
        ) ?: false;
    }

    /**
     * Provide the data for the form, preferring any session-stored edit data
     * and falling back to the loaded item.
     *
     * @return mixed The data for the form.
     * @since  1.0.0
     */
    protected function loadFormData()
    {
        // A post kept for another record never fills this one (KeptPost).
        $data = KeptPost::forRecord(
            stateKey: 'com_alfa.edit.formfieldgroup.data',
            recordId: (int) $this->getState($this->getName() . '.id'),
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    /**
     * Save the group, then persist its per-language title + description into the
     * #__alfa_form_field_groups_<langtag> tables (auto-created on first save): the
     * same multilingual flow as the other translatable entities.
     *
     * @param array $data The form data.
     *
     * @return bool True on success, false on error.
     *
     * @since  2.0.0
     */
    public function save($data)
    {
        // Recover the per-language flat keys (e.g. name_en_gb) and other undeclared
        // keys the form filter drops, without overwriting any validated field or
        // resurrecting the housekeeping fields the form unsets. Recovered
        // per-language values are safehtml-cleaned like their base field.
        $rawData = Factory::getApplication()->getInput()->post->get('jform', [], 'raw');
        $data = MultilingualHelper::mergeValidatedFormData($data, $rawData, $this->getForm($data, false));

        $key = $this->getTable()->getKeyName();
        $isNew = ((int) ($data[$key] ?? 0)) <= 0;

        if (!parent::save($data)) {
            return false;
        }

        $currentId = $isNew ? (int) $this->getState($this->getName() . '.id') : (int) $data['id'];

        MultilingualHelper::saveMultilingualData(
            currentId:         $currentId,
            primaryColumnName: 'id_form_field_group',
            tableName:         '#__alfa_form_field_groups',
            data:              $data,
            aliasFields:       [],
        );

        return true;
    }

    /**
     * Stamp created_by on new records and modified_by on every save before
     * the table is stored.
     *
     * @param \Joomla\CMS\Table\Table $table The table object.
     *
     * @return void
     * @since  1.0.0
     */
    protected function prepareTable($table)
    {
        $user = $this->getCurrentUser();

        if ($table->id == 0 && empty($table->created_by)) {
            $table->created_by = $user->id;
        }

        $table->modified_by = $user->id;

        parent::prepareTable($table);
    }

    /**
     * Delete groups; any fields referencing a deleted group are moved to ungrouped (group_id = 0).
     * Returns via parent::delete; callers see the standard success/failure flow.
     * @since  1.0.0
     */
    public function delete(&$pks)
    {
        // Permission first: the count below reads how many fields the groups hold.
        if (!AlfaHelper::can(section: $this->alfaEntity, action: 'delete', user: $this->getCurrentUser())) {
            $this->setError(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'));
            $pks = [];

            return false;
        }

        $pks = (array) $pks;
        if (!$pks) {
            return parent::delete($pks);
        }

        $db = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__alfa_form_fields'))
            ->whereIn($db->quoteName('group_id'), array_map('intval', $pks));
        $db->setQuery($query);
        $affectedFields = (int) $db->loadResult();

        $deletedIds = array_map('intval', $pks);

        if (!parent::delete($pks)) {
            return false;
        }

        // MULTILINGUAL: remove the per-language title/description rows.
        MultilingualHelper::deleteMultilingualData(
            ids:               $deletedIds,
            primaryColumnName: 'id_form_field_group',
            tableName:         '#__alfa_form_field_groups',
        );

        if ($affectedFields > 0) {
            $update = $db->getQuery(true)
                ->update($db->quoteName('#__alfa_form_fields'))
                ->set($db->quoteName('group_id') . ' = 0')
                ->whereIn($db->quoteName('group_id'), array_map('intval', $pks));
            $db->setQuery($update);
            $db->execute();

            Factory::getApplication()->enqueueMessage(
                Text::sprintf('COM_ALFA_FORMFIELDGROUP_DELETED_FIELDS_MOVED', $affectedFields),
                'info',
            );
        }

        return true;
    }
}
