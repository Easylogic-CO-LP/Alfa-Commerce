<?php

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormModel;

class UserinfoModel extends FormModel
{
    public function getRows(): array
    {
        return UserInfoHelper::getRowsByUser(userId: (int) Factory::getApplication()->getIdentity()->id);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_alfa.userinfo', 'userinfo', [
            'control' => 'jform',
            'load_data' => false,
        ]);

        FieldsHelper::prepareForm(context: 'user.edit', form: $form, data: [], targetFieldset: 'userinfo');

        $id = (int) Factory::getApplication()->input->getInt('id', 0);

        if ($id > 0) {
            $row = UserInfoHelper::getRow(userId: (int) Factory::getApplication()->getIdentity()->id, id: $id);

            if ($row) {
                foreach ((array) $row as $col => $value) {
                    if (\in_array($col, ['id', 'id_user'], true) || $value === null) {
                        continue;
                    }

                    $decoded = json_decode((string) $value, true);
                    $form->setValue($col, FieldsHelper::FIELDS_KEY, \is_array($decoded) ? $decoded : $value);
                }
            }
        }

        return $form;
    }
}
