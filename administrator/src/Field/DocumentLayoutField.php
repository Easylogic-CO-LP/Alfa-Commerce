<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\DocumentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

/**
 * DocumentLayoutField
 *
 * Picker for a document series' rendering layout: the document
 * counterpart of OrderStatusEmailLayoutField. Lists every layout
 * LayoutHelper can resolve for the administrator client:
 *
 *   - administrator/components/com_alfa/layouts/documents/<type>/*.php   (component defaults)
 *   - administrator/templates/<tpl>/html/layouts/com_alfa/documents/<type>/*.php   (template additions)
 *
 * The stored value is the LayoutHelper id in dot notation
 * (e.g. "documents.invoice.default"); the empty option keeps the series
 * on its document type's default (DocumentRenderer::layoutId()).
 * Template overrides of an EXISTING id resolve transparently at render
 * time and are not listed twice; a template only needs to add a NEW
 * file to surface another option here.
 *
 * All document types are listed (grouped in the label) because the
 * series' doc_type can change while editing; the render helper simply
 * uses whatever id is stored.
 *
 * `_`-prefixed files and the shared partials folder are skipped.
 *
 * @since  1.3.0
 */
class DocumentLayoutField extends FormField
{
    /**
     * Field type id: must match the filename and the XML `type`.
     *
     * @var string
     */
    protected $type = 'DocumentLayout';

    /**
     * Build the select: empty "type default" option first, then every
     * discovered layout id across component defaults and enabled admin
     * templates, de-duplicated and sorted.
     *
     *
     * @since  1.3.0
     */
    protected function getInput(): string
    {
        $layouts = [];

        foreach (DocumentHelper::types() as $docType) {
            $typeLabel = DocumentHelper::typeLabel($docType);

            // Namespaced aliases map dots to folders: 'core.invoice' lives
            // under layouts/documents/core/invoice/.
            $typePath = str_replace('.', '/', $docType);

            foreach ($this->scanFolder(JPATH_ADMINISTRATOR . '/components/com_alfa/layouts/documents/' . $typePath) as $name) {
                $layouts['documents.' . $docType . '.' . $name] ??= $typeLabel . ' - ' . $this->layoutLabel(name: $name);
            }

            foreach ($this->getEnabledAdminTemplates() as $template) {
                $dir = JPATH_ADMINISTRATOR . '/templates/' . $template . '/html/layouts/com_alfa/documents/' . $typePath;

                foreach ($this->scanFolder($dir) as $name) {
                    $layouts['documents.' . $docType . '.' . $name] ??= $typeLabel . ' - ' . $this->layoutLabel(name: $name);
                }
            }
        }

        ksort($layouts);

        $options = [
            HTMLHelper::_('select.option', '', Text::_('COM_ALFA_SERIES_FIELD_LAYOUT_DEFAULT')),
        ];

        foreach ($layouts as $value => $label) {
            $options[] = HTMLHelper::_('select.option', $value, $label);
        }

        // Changing the layout reloads the edit form (the email-layout
        // pattern) so the positions composer re-discovers slots/sections
        // from the newly-selected layout. FormController::reload rebinds
        // the POSTed data; DocumentPositionsField::setup overlays it so
        // unsaved content survives the reload.
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useScript('com_alfa.admin-field-typehaschanged')
            ->useScript('com_alfa.core-loader');

        return HTMLHelper::_(
            'select.genericlist',
            $options,
            $this->name,
            [
                'id' => $this->id,
                'list.attr' => ' class="form-select" onchange="Joomla.typeHasChanged(this);"',
                'list.select' => (string) $this->value,
            ],
        );
    }

    /**
     * Return the layout basenames in a folder (no extension), skipping
     * private (`_`-prefixed) files. Missing folder -> empty list.
     *
     * @param string $dir Absolute path to a documents/<type> folder.
     *
     * @return string[]
     *
     * @since  1.3.0
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
     * Human label for a layout basename: COM_ALFA_DOCUMENT_LAYOUT_<UPPER>
     * when translated, else the title-cased basename so template-added
     * layouts read sensibly without a language string.
     *
     * @param string $name Layout basename (e.g. 'default').
     *
     *
     * @since  1.3.0
     */
    private function layoutLabel(string $name): string
    {
        $key = 'COM_ALFA_DOCUMENT_LAYOUT_' . strtoupper($name);
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
     * @since  1.3.0
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
