<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Discount;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaFormView as FormView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a single Category.
 *
 * @since  1.0.0
 */
class HtmlView extends FormView
{
    /**
     * @var string The entity a plugin targets with $context->targets('discount').
     * @since  1.8.0
     */
    protected string $alfaEntity = 'discount';

    /**
     * Render the edit form view.
     *
     * @param string|null $tpl The name of the template file to parse
     *
     * @return void
     *
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
     *
     * @since  1.0.0
     */
    protected function initializeView()
    {
        parent::initializeView();

        $input = Factory::getApplication()->getInput();

        // Add form control fields
        $this->form
            ->addControlField('task', '')
            ->addControlField('return', $input->getBase64('return', ''));
    }

    /**
     * Build the edit toolbar: lock the main menu and add Apply/Save, Save & New,
     * Save as Copy and Cancel buttons according to the current user permissions.
     *
     * @return void
     *
     * @since  1.0.0
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $isNew = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        $canCreate = AlfaHelper::can(section: 'discounts', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'discounts', action: 'edit', user: $user);

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_DISCOUNT'), 'generic');

        // Apply reopens the saved record, which needs edit even when the record is new.
        if (!$checkedOut && ($isNew ? ($canCreate && $canEdit) : $canEdit)) {
            ToolbarHelper::apply('discount.apply', 'JTOOLBAR_APPLY');
        }

        // If not checked out, can save the item: a new record needs create, an existing one edit.
        if (!$checkedOut && ($isNew ? $canCreate : $canEdit)) {
            ToolbarHelper::save('discount.save', 'JTOOLBAR_SAVE');
        }

        if (!$checkedOut && $canCreate) {
            ToolbarHelper::custom('discount.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
        }

        // Save as Copy reopens the new copy, so it needs edit as well as create.
        if (!$isNew && $canCreate && $canEdit) {
            ToolbarHelper::custom('discount.save2copy', 'save-copy.png', 'save-copy_f2.png', 'JTOOLBAR_SAVE_AS_COPY', false);
        }

        ToolbarHelper::cancel('discount.cancel', 'JTOOLBAR_CANCEL');
    }
}
