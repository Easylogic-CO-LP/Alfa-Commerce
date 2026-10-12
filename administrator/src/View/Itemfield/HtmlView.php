<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Itemfield;

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\View\AlfaFormView as FormView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * View class for a single item-field definition, including its inline value
 * pool (Values tab): every value, its translations and the type plugin's
 * per-value config are managed on this one screen; no separate values view.
 * Pricing and value-pair logic live with the ITEM (wizard + combinations).
 *
 * @since  1.3.0
 */
class HtmlView extends FormView
{
    /**
     * The admin-contract entity a plugin targets with $context->targets('itemfield').
     *
     *
     * @since  1.8.0
     */
    protected string $alfaEntity = 'itemfield';

    /**
     * @var array Content languages: [{tag, key, title}, ...] for the per-row name inputs.
     *
     * @since  1.3.0
     */
    public $contentLanguages = [];

    /**
     * @var string|null Path of the type plugin's params/value.xml (null = none).
     *
     * @since  1.3.0
     */
    public $valueXmlPath = null;

    /**
     * Prepare the view: control fields and the data the inline
     * Values tab needs (languages, parent candidates, currencies, the plugin's
     * value-config form file).
     *
     * @return void
     *
     * @since  1.3.0
     */
    protected function initializeView()
    {
        parent::initializeView();

        $input = Factory::getApplication()->getInput();

        // Add form control fields
        $this->form
            ->addControlField('task', '')
            ->addControlField('return', $input->getBase64('return', ''));

        // Content languages -> flat-key suffixes (en-GB -> en_gb) for the rows.
        foreach (LanguageHelper::getLanguages('lang_code') as $code => $language) {
            $this->contentLanguages[] = (object) [
                'tag' => $code,
                'key' => strtolower(str_replace('-', '_', $code)),
                'title' => $language->title,
                'title_native' => $language->title_native ?? $language->title,
                'image' => $language->image ?? '',
                'sef' => $language->sef ?? $code,
            ];
        }

        // The type plugin's per-value config (swatch hex, ...), rendered as
        // extra inputs on every saved row.
        $type = (string) ($this->item->type ?? '');

        if ($type !== '') {
            $path = JPATH_ROOT . '/plugins/alfa-item-fields/' . $type . '/params/value.xml';

            if (is_file($path)) {
                $this->valueXmlPath = $path;
                AlfaHelper::loadPluginLanguage(group: 'alfa-item-fields', element: (string) $type);
            }
        }
    }

    /**
     * Build the edit toolbar according to the current user permissions.
     *
     * @return void
     *
     * @since  1.3.0
     */
    protected function addToolbar()
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $user = $this->getCurrentUser();
        $userId = $user->id;
        $checkedOut = !(\is_null($this->item->checked_out) || $this->item->checked_out == $userId);

        $canCreate = AlfaHelper::can(section: 'itemfields', action: 'create', user: $user);
        $canEdit = AlfaHelper::can(section: 'itemfields', action: 'edit', user: $user);

        ToolbarHelper::title(Text::_('COM_ALFA_TITLE_ITEMFIELD'), 'generic');

        $isNew = empty($this->item->id);

        // Apply reopens the saved record, which needs edit even when the record is new.
        if (!$checkedOut && ($isNew ? ($canCreate && $canEdit) : $canEdit)) {
            ToolbarHelper::apply('itemfield.apply', 'JTOOLBAR_APPLY');
        }

        // A new record is saved under create, an existing one under edit (FormController::allowSave()).
        if (!$checkedOut && ($isNew ? $canCreate : $canEdit)) {
            ToolbarHelper::save('itemfield.save', 'JTOOLBAR_SAVE');
        }

        if (!$checkedOut && $canCreate) {
            ToolbarHelper::custom('itemfield.save2new', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
        }

        ToolbarHelper::cancel('itemfield.cancel', 'JTOOLBAR_CANCEL');
    }
}
