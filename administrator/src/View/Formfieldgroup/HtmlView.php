<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Formfieldgroup;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaFormView as FormView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends FormView
{
    /**
     * @var string The entity a plugin targets with $context->targets('formfieldgroup').
     * @since  1.8.0
     */
    protected string $alfaEntity = 'formfieldgroup';

    /**
     * Render the edit form view.
     *
     * @param string|null $tpl The name of the template file to parse
     *
     * @return void
     * @since  1.0.0
     */
    public function display($tpl = null)
    {
        parent::display($tpl);
    }

    /**
     * Prepare the view: add the 'task' and base64 'return' control fields to the form.
     *
     * @return void
     * @since  1.0.0
     */
    protected function initializeView()
    {
        parent::initializeView();

        $this->form
            ->addControlField('task', '')
            ->addControlField('return', Factory::getApplication()->getInput()->getBase64('return', ''));
    }

    /**
     * Build the edit toolbar: lock the main menu and add Apply/Save, Save & New,
     * Save as Copy and Cancel buttons according to the current user permissions.
     *
     * @return void
     * @since  1.0.0
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $isNew = ($this->item->id == 0);
        $checkedOut = !(is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        // Groups are part of the Fields area, so the formfields permissions govern them.
        $canCreate = AlfaHelper::can(section: 'formfieldgroups', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'formfieldgroups', action: 'edit', user: $user);

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_FORM_FIELD_GROUP'), 'generic');

        // Apply reopens the saved record, which needs edit even when the record is new.
        if (!$checkedOut && ($isNew ? ($canCreate && $canEdit) : $canEdit)) {
            ToolbarHelper::apply('formfieldgroup.apply', 'JTOOLBAR_APPLY');
        }

        // A new group needs create, an existing one edit.
        if (!$checkedOut && ($isNew ? $canCreate : $canEdit)) {
            ToolbarHelper::save('formfieldgroup.save', 'JTOOLBAR_SAVE');
        }

        if (!$checkedOut && $canCreate) {
            ToolbarHelper::custom('formfieldgroup.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
        }

        ToolbarHelper::cancel('formfieldgroup.cancel', 'JTOOLBAR_CANCEL');
    }
}
