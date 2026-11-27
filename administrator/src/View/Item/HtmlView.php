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
use Alfa\Component\Alfa\Administrator\Helper\RequestScalar;
use Alfa\Component\Alfa\Administrator\Service\Catalog\ItemShipments;
use Alfa\Component\Alfa\Administrator\View\AlfaFormView as FormView;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

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
     * @since  2.0.0
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
     * Prepare the view: add the 'task' control field to the form; the form's layout carries the return address.
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
            ->addControlField('task', '');

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
        if (RequestScalar::cmd($input, 'layout') === 'edit_combination') {
            $this->studioCombination = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getCombination(
                itemId:        (int) ($this->item->id ?? 0),
                combinationId: RequestScalar::int($input, 'combination_id'),
            );

            // The variant's own shipping list (none: as the product), the choice the window shows for it, and the
            // published methods it may name.
            $itemId = (int) ($this->item->id ?? 0);
            $combinationId = RequestScalar::int($input, 'combination_id');
            $scope = ItemShipments::forItems(Factory::getContainer()->get(DatabaseInterface::class), [$itemId])[$itemId] ?? null;
            $own = $scope?->combinations[$combinationId] ?? null;

            $this->studioShipments = [
                'choice'  => ItemShipments::combinationChoice($own),
                'ids'     => array_map('intval', $own ?? []),
                'methods' => AlfaHelper::getShipmentMethods(),
            ];
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

        $this->addPreviewButton(isNew: $isNew);

        ToolbarHelper::cancel('item.cancel', 'JTOOLBAR_CLOSE');
    }

    /**
     * Preview: the product's storefront page, in a window over this screen or in a new tab, under one control. Only
     * a saved, published product has a page for the shopper, so the control is disabled until then; the save that
     * publishes the product reloads the form with it enabled.
     *
     * Disabled is the toolbar's own state: the disabled class on the button, the one the toolbar's web component sets
     * while a list button waits for a selection. The dropdown ignores a toggle that carries it.
     *
     * @param bool $isNew Whether the product has no id yet
     *
     * @since  2.3.0
     */
    private function addPreviewButton(bool $isNew): void
    {
        $canPreview = !$isNew && (int) ($this->item->state ?? 0) === 1;
        $url = $this->previewUrl();

        $dropdown = $this->getDocument()->getToolbar()->dropdownButton('preview')
            ->text('JGLOBAL_PREVIEW')
            ->toggleSplit(false)
            ->icon('icon-eye')
            ->buttonClass('btn btn-action' . ($canPreview ? '' : ' disabled'));

        $childBar = $dropdown->getChildToolbar();

        // The storefront page in Joomla's dialog: an iframe of the same site, which the site's frame policy allows.
        $childBar->popupButton('preview-window', 'COM_ALFA_PREVIEW_IN_WINDOW')
            ->popupType('iframe')
            ->textHeader(Text::_('JGLOBAL_PREVIEW'))
            ->url($url)
            ->modalWidth('90vw')
            ->modalHeight('90vh')
            ->icon('icon-eye');

        $childBar->linkButton('preview-tab', 'COM_ALFA_PREVIEW_IN_NEW_TAB')
            ->url($url)
            ->icon('icon-new-tab')
            ->target('_blank');
    }

    /**
     * The product's storefront address: its page under its default category (its first category when none is
     * marked default), in the site's default language when the shop is multilingual.
     *
     *
     * @since  2.3.0
     */
    private function previewUrl(): string
    {
        $categories = array_values((array) ($this->item->categories ?? []));
        $categoryId = (int) ($this->item->id_category_default ?? 0) ?: (int) ($categories[0] ?? 0);
        $url = 'index.php?option=com_alfa&view=item&id=' . (int) $this->item->id
            . ($categoryId > 0 ? '&category_id=' . $categoryId : '');

        if (Multilanguage::isEnabled()) {
            $url .= '&lang=' . ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        }

        return Route::link(client: 'site', url: $url, xhtml: false, absolute: true);
    }

    /**
     * Native toolbar for the Combination Studio popup: Apply saves and stays
     * in the dialog, Save saves and closes it (the parent item screen
     * re-renders deferred, when the dialog actually closes), Close just
     * notifies the parent. Mirrors the order-edit shipment popup pattern.
     *
     *
     * @since  2.0.0
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
