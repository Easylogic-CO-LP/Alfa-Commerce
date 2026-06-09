<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

/**
 * OrderStatusEmailLayoutField
 *
 * Picker for the email wrapper layout used to render a status-change
 * notification. Order-email wrappers live in the per-type subfolder
 * emails/order/ (siblings like emails/invoice/ belong to other email
 * types). Lists every wrapper LayoutHelper can resolve for the
 * administrator client (emails render with client = 1):
 *
 *   - administrator/components/com_alfa/layouts/emails/order/*.php   (component defaults)
 *   - administrator/templates/<tpl>/html/layouts/com_alfa/emails/order/*.php   (overrides / additions)
 *
 * The stored value is the LayoutHelper id in dot notation
 * (e.g. "emails.order.default"). Unlike FieldLayoutField, no
 * "template:layout" split is needed: OrderEmailHelper renders through
 * LayoutHelper, which already resolves a template override of the same
 * id transparently. A template only needs to add a *new* file — that
 * surfaces here as another "emails.order.<name>" option.
 *
 * `_`-prefixed files are skipped (private partials).
 *
 * Selecting a layout drives which position editors EmailPositionsField
 * shows — the positions the layout requests via $position()/$hasPosition()
 * (discovered by OrderEmailHelper::discoverPositions). See that field's getInput().
 *
 * @since  1.0.0
 */
class OrderStatusEmailLayoutField extends FormField
{
    /**
     * Field type id — must match the filename and the XML `type`.
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

        // Component defaults.
        foreach ($this->scanFolder(JPATH_ADMINISTRATOR . '/components/com_alfa/layouts/emails/order') as $name) {
            $layouts['emails.order.' . $name] ??= $this->layoutLabel(name: $name);
        }

        // Additions from every enabled admin template (client_id = 1).
        foreach ($this->getEnabledAdminTemplates() as $template) {
            foreach ($this->scanFolder(JPATH_ADMINISTRATOR . '/templates/' . $template . '/html/layouts/com_alfa/emails/order') as $name) {
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
            ->useScript('webcomponent.core-loader');

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
     * private (`_`-prefixed) files. Missing folder → empty list.
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

    /**
     * Element names of enabled administrator templates (client_id = 1).
     *
     * @return string[]
     *
     * @since  1.0.0
     */
    private function getEnabledAdminTemplates(): array
    {
        $db = $this->getDatabase();
        $clientId = 1;
        $type = 'template';

        $query = $db->createQuery()
            ->select($db->quoteName('element'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('client_id') . ' = :clientId')
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('enabled') . ' = 1')
            ->bind(':clientId', $clientId, ParameterType::INTEGER)
            ->bind(':type', $type, ParameterType::STRING);

        $db->setQuery($query);

        return $db->loadColumn() ?: [];
    }
}
