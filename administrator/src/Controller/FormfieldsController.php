<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Controller;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Helper\MultilingualHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Router\Route;
use Throwable;

/**
 * Items list controller class.
 *
 * @since  1.0.0
 */
class FormfieldsController extends AdminController
{
    /**
     * Set where a field shows in ONE section, straight from the list.
     *
     * The three sections are the question a merchant actually asks of this
     * screen ("is the phone asked at checkout?"), so it is answered where the
     * fields are listed instead of inside each editor. One click moves one
     * section one step: hidden -> optional -> required -> hidden.
     *
     *
     * @since  2.0.0
     */
    public function setVisibility(): void
    {
        $this->checkToken('get');

        if (!AlfaHelper::can(section: 'formfields', action: 'edit.state', user: $this->app->getIdentity())) {
            $this->setMessage(Text::_('JLIB_APPLICATION_ERROR_EDITSTATE_NOT_PERMITTED'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_alfa&view=formfields' . $this->getRedirectToListAppend(), false));

            return;
        }

        $id = $this->input->getInt('id', 0);
        $section = $this->input->getCmd('section', '');
        $level = $this->input->getInt('level', 0);

        try {
            $model = $this->getModel();
            $applied = $model->setVisibility(id: $id, section: $section, level: $level);

            // Names the FIELD: seven rows of chips look alike, and the click
            // that changed one of them has to say which. The name is
            // translatable (it lives only in the per-language tables, so the
            // main row has none); the machine key stands in when a language
            // has no name for it. The floor can raise what was asked for (a
            // system row is never hidden from a section that needs it), so the
            // level reported is the one now stored.
            $field = $model->getItem($id);
            $name = MultilingualHelper::getTranslatedValue(
                id:                $id,
                tableName:         '#__alfa_form_fields',
                primaryColumnName: 'id_formfield',
                field:             'name',
            );

            $this->setMessage(Text::sprintf(
                'COM_ALFA_FORMFIELD_VISIBILITY_SET',
                $name !== '' ? $name : $field->field_name,
                Text::_('COM_ALFA_FORM_LBL_SHOW_' . strtoupper($section === 'shipping' ? 'delivery' : ($section === 'billing' ? 'invoice' : 'registration'))),
                Text::_('COM_ALFA_FORM_FIELD_LEVEL_' . ['HIDDEN', 'OPTIONAL', 'REQUIRED'][$applied]),
            ));
        } catch (Throwable $e) {
            $this->setMessage($e->getMessage(), 'error');
        }

        $this->setRedirect(Route::_('index.php?option=com_alfa&view=formfields' . $this->getRedirectToListAppend(), false));
    }

    /**
     * Proxy for getModel.
     *
     * @param string $name Optional. Model name
     * @param string $prefix Optional. Class prefix
     * @param array $config Optional. Configuration array for model
     *
     * @return object The Model
     *
     * @since  1.0.0
     */
    public function getModel($name = 'Formfield', $prefix = 'Administrator', $config = ['ignore_request' => true]): object
    {
        return parent::getModel($name, $prefix, $config);
    }
}
