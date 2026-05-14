<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;

class FormfieldgroupModel extends AdminModel
{
    public $typeAlias = 'com_alfa.formfieldgroup';

    protected $formName = 'formfieldgroup';

    public function getForm($data = [], $loadData = true)
    {
        return $this->loadForm(
            'com_alfa.' . $this->formName,
            $this->formName,
            ['control' => 'jform', 'load_data' => $loadData],
        ) ?: false;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_alfa.edit.formfieldgroup.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

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
     */
    public function delete(&$pks)
    {
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

        if (!parent::delete($pks)) {
            return false;
        }

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
