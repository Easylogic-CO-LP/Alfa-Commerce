<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Item;

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
     * The admin-contract entity of this screen.
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'item';

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

        // "Fields & combinations" popup data: category-scoped fields with pools,
        // this item's assignments/subsets. Availability follows the SAVED
        // categories; after changing categories, save to refresh the list.
        $this->itemFieldsData = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getFieldsPopupData(
            itemId:      (int) ($this->item->id ?? 0),
            categoryIds: (array) ($this->item->categories ?? []),
        );

        // The variant combinations grid (explicitly generated in the popup;
        // variant-toggled fields).
        $this->itemCombinations = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getCombinations(
            itemId: (int) ($this->item->id ?? 0),
        );

        // Overridable-field descriptors derived from forms/item.xml: labels,
        // types, options and multilingual-ness come from the form definitions.
        $this->combinationOverrideFields = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getCombinationOverrideDescriptors();

        // Downloadable files for the Downloads tab (empty for new items; the
        // tab asks to save first, uploads need the item id).
        $this->itemDownloads = \Alfa\Component\Alfa\Administrator\Service\Download\Entitlements::getFilesForItem(
            itemId: (int) ($this->item->id ?? 0),
        );

        // Content languages for the per-combination translatable overrides.
        $this->contentLanguages = [];

        foreach (\Joomla\CMS\Language\LanguageHelper::getLanguages('lang_code') as $code => $language) {
            $this->contentLanguages[] = (object) [
                'tag' => $code,
                'key' => strtolower(str_replace('-', '_', $code)),
                'title' => $language->title,
            ];
        }

        // The Studio popup (layout=edit_combination) edits ONE combination in
        // its own iframe with its own save; load it fully.
        if ($input->get('layout') === 'edit_combination') {
            $this->studioCombination = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getCombination(
                itemId:        (int) ($this->item->id ?? 0),
                combinationId: $input->getInt('combination_id', 0),
            );
        }
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

        // The Studio popup gets its own native toolbar, never the item's.
        if ($this->getLayout() === 'edit_combination') {
            $this->addCombinationToolbar();

            return;
        }

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $isNew = ($this->item->id == 0);
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        $canCreate = AlfaHelper::can(section: 'items', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'items', action: 'edit', user: $user);

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ITEM'), 'generic');

        // Apply reopens the saved record, which needs edit even when the record is new.
        if (!$checkedOut && ($isNew ? ($canCreate && $canEdit) : $canEdit)) {
            ToolbarHelper::apply('item.apply', 'JTOOLBAR_APPLY');
        }

        // If not checked out, can save the item: a new record under create, an existing one under edit.
        if (!$checkedOut && ($isNew ? $canCreate : $canEdit)) {
            ToolbarHelper::save('item.save', 'JTOOLBAR_SAVE');
        }

        if (!$checkedOut && $canCreate) {
            ToolbarHelper::custom('item.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
        }

        // Save as Copy reopens the new copy, so it needs edit as well as create.
        if (!$isNew && $canCreate && $canEdit) {
            ToolbarHelper::custom('item.save2copy', 'save-copy.png', 'save-copy_f2.png', 'JTOOLBAR_SAVE_AS_COPY', false);
        }

        // Button for version control

        ToolbarHelper::cancel('item.cancel', 'JTOOLBAR_CLOSE');
    }

    /**
     * Native toolbar for the Combination Studio popup: Apply saves and stays
     * in the dialog, Save saves and closes it (the parent item screen
     * re-renders deferred, when the dialog actually closes), Close just
     * notifies the parent. Mirrors the order-edit shipment popup pattern.
     *
     *
     * @since  1.3.0
     */
    protected function addCombinationToolbar(): void
    {
        $toolbar = $this->getDocument()->getToolbar();

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_COMBINATION_EDIT'), 'generic');

        // Saving a variant is editing its product.
        if (AlfaHelper::can(section: 'items', action: 'edit', user: $this->getCurrentUser())) {
            $toolbar->apply('item.applyCombination');
            $toolbar->save('item.saveCombination');
        }

        $toolbar->standardButton('cancel', 'JTOOLBAR_CLOSE')
            ->icon('icon-times')
            ->onclick('window.alfaStudioCancel();');
    }
}
