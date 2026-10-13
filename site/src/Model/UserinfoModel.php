<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Model;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;
use Alfa\Component\Alfa\Administrator\Helper\UserInfoHelper;
use Alfa\Component\Alfa\Site\Service\ShowOn\SubmitAuthority;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\FormModel;

/**
 * User-info model: the current customer's saved rows and the add/edit
 * form (alfa fields via the pipeline, user.edit context).
 *
 * @since  1.0.1
 */
class UserinfoModel extends FormModel
{
    /**
     * The current user's saved rows, newest first.
     *
     * @return object[]
     *
     * @since  1.0.1
     */
    public function getRows(): array
    {
        return UserInfoHelper::getRowsByUser(userId: (int) Factory::getApplication()->getIdentity()->id);
    }

    /**
     * Build the add/edit form; an owned row id in the request binds its
     * values for editing.
     *
     * @param array $data Data for the form.
     * @param bool $loadData True to load the form data from the model state.
     *
     * @return \Joomla\CMS\Form\Form|false
     *
     * @since  1.0.1
     */
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

    /**
     * Validate the submission after taking off the form every field a ShowOn
     * rule hides: the browser disables a hidden field, so it never posts, and
     * demanding it would make an otherwise complete row unsavable. Removing the
     * field also drops it from `Form::filter()`, so a crafted POST cannot write
     * a value into a column the customer was never shown.
     *
     * @param \Joomla\CMS\Form\Form $form The user-info form.
     * @param array $data The posted data.
     * @param string|null $group The form group.
     *
     * @return array|false The filtered data, or false on validation failure.
     *
     * @since  2.0.0
     */
    public function validate($form, $data, $group = null)
    {
        $data = (array) $data;

        $data[FieldsHelper::FIELDS_KEY] = SubmitAuthority::prune(
            form: $form,
            fields: FieldsHelper::getFields('user.edit'),
            values: (array) ($data[FieldsHelper::FIELDS_KEY] ?? []),
            group: FieldsHelper::FIELDS_KEY,
        );

        return parent::validate($form, $data, $group);
    }
}
