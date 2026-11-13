<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\EmailLayouts;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * OrderStatusEmailLayoutField
 *
 * Picker for the email wrapper layout used to render a status-change
 * notification. Order-email wrappers live in the per-type subfolder
 * emails/order/ (siblings like emails/invoice/ belong to other email
 * types). Lists every wrapper LayoutHelper can resolve for the
 * administrator client (emails render with client = 1), from the folders
 * every mail renders through ({@see EmailLayouts}, 2.2.1):
 *
 *   - administrator/templates/<the default administrator template>/html/layouts/com_alfa/emails/order/*.php
 *     (overrides / additions; then the template it inherits from)
 *   - administrator/components/com_alfa/layouts/emails/order/*.php   (component defaults)
 *
 * The stored value is the LayoutHelper id in dot notation
 * (e.g. "emails.order.default"). Unlike FieldLayoutField, no
 * "template:layout" split is needed: EmailService renders through the
 * same folders, which resolve a template override of the same id
 * transparently. A template only needs to add a *new* file; that
 * surfaces here as another "emails.order.<name>" option.
 *
 * `_`-prefixed files are skipped (private partials).
 *
 * Selecting a layout drives which position editors EmailPositionsField
 * shows: the positions the layout requests via $position()/$hasPosition()
 * (discovered by EmailService::discoverPositions). See that field's getInput().
 *
 * @since  1.0.0
 */
class OrderStatusEmailLayoutField extends FormField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     */
    protected $type = 'OrderStatusEmailLayout';

    /**
     * Build the select. Component layouts first, then any added by an
     * enabled admin template; ids are de-duplicated (a template override
     * of an existing id resolves automatically at render time, so it is
     * not listed twice).
     *
     *
     * @since  1.0.0
     */
    protected function getInput(): string
    {
        $layouts = [];

        // The default administrator template's additions, its parent's, then the component's defaults: the folders
        // every mail renders through, whatever application sends it (2.2.1).
        foreach (EmailLayouts::includePaths() as $folder) {
            foreach ($this->scanFolder($folder . '/emails/order') as $name) {
                $layouts['emails.order.' . $name] ??= $this->layoutLabel(name: $name);
            }
        }

        // Safety net: the canonical layout always exists as an option even
        // if the filesystem scan came up empty for some reason.
        $layouts['emails.order.default'] ??= $this->layoutLabel(name: 'default');

        ksort($layouts);

        $options = [];

        foreach ($layouts as $value => $label) {
            $options[] = HTMLHelper::_('select.option', $value, $label);
        }

        // Changing the layout reloads the edit form (same mechanism as the
        // field-type change) so the position editors below re-discover from
        // the newly-selected layout. FormController::reload rebinds the
        // POSTed data; EmailPositionsField::setup overlays it so unsaved
        // content survives the reload.
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useScript('com_alfa.admin-field-typehaschanged')
            ->useScript('com_alfa.core-loader');

        $class = $this->element['class'] ? (string) $this->element['class'] : 'form-select';
        $attr = ' class="' . $class . '" onchange="Joomla.typeHasChanged(this);"';

        return HTMLHelper::_(
            'select.genericlist',
            $options,
            $this->name,
            [
                'id' => $this->id,
                'list.attr' => $attr,
                'list.select' => (string) $this->value,
            ],
        );
    }

    /**
     * Return the layout basenames in a folder (no extension), skipping
     * private (`_`-prefixed) files. Missing folder -> empty list.
     *
     * @param string $dir Absolute path to the layouts/emails folder.
     *
     * @return string[]
     *
     * @since  1.0.0
     */
    private function scanFolder(string $dir): array
    {
        $names = [];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if ($name === '' || str_starts_with($name, '_')) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Human label for a layout basename.
     *
     * Looks up COM_ALFA_ORDEREMAIL_LAYOUT_<UPPER>; falls back to a
     * title-cased basename so template-added layouts read sensibly
     * without a language string.
     *
     * @param string $name Layout basename (e.g. 'order').
     *
     *
     * @since  1.0.0
     */
    private function layoutLabel(string $name): string
    {
        $key = 'COM_ALFA_ORDEREMAIL_LAYOUT_' . strtoupper($name);
        $label = Text::_($key);

        if ($label === $key) {
            return ucwords(str_replace(['_', '-'], ' ', $name));
        }

        return $label;
    }
}
