<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Label;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaFormView as FormView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for one of the shop's labels.
 *
 * @since  2.3.0
 */
class HtmlView extends FormView
{
    /**
     * @var string The entity a plugin targets with $context->targets('label').
     * @since  2.3.0
     */
    protected string $alfaEntity = 'label';

    /**
     * Prepare the view: add the 'task' control field to the form; the form's layout carries the return address.
     *
     * @return void
     *
     * @since  2.3.0
     */
    protected function initializeView()
    {
        parent::initializeView();

        $this->form->addControlField('task', '');
    }

    /**
     * Build the edit toolbar: lock the main menu and add Apply/Save, Save & New, Save as Copy and Cancel buttons
     * according to the current user permissions.
     *
     * @return void
     *
     * @since  2.3.0
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $isNew = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        $canCreate = AlfaHelper::can(section: 'labels', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'labels', action: 'edit', user: $user);

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_LABEL'), 'tags');

        // Apply reopens the saved record, which needs edit even when the record is new.
        if (!$checkedOut && ($isNew ? ($canCreate && $canEdit) : $canEdit)) {
            ToolbarHelper::apply('label.apply', 'JTOOLBAR_APPLY');
        }

        // A new record under create, an existing one under edit.
        if (!$checkedOut && ($isNew ? $canCreate : $canEdit)) {
            ToolbarHelper::save('label.save', 'JTOOLBAR_SAVE');
        }

        if (!$checkedOut && $canCreate) {
            ToolbarHelper::custom('label.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
        }

        // Save as Copy reopens the new copy, so it needs edit as well as create.
        if (!$isNew && $canCreate && $canEdit) {
            ToolbarHelper::custom('label.save2copy', 'save-copy.png', 'save-copy_f2.png', 'JTOOLBAR_SAVE_AS_COPY', false);
        }

        ToolbarHelper::cancel('label.cancel', 'JTOOLBAR_CANCEL');
    }
}
