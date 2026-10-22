<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\GroupedlistField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use SimpleXMLElement;

/**
 * Variant picker for ONE sub-template slot of a com_alfa site view.
 *
 * A slot is a $this->loadTemplate('<slot>') call shipped by the component
 * (e.g. the "item" card of the items listing, or "images" on the item page).
 * Variants follow the naming contract "default_<slot>-<variant>.php": the
 * slot token stays in the filename so each picker only targets its own slot,
 * and the underscore keeps every file private to core layout/menu pickers.
 *
 * Pure filesystem discovery on every render; dropping a file makes it appear:
 *   - components/com_alfa/tmpl/<view>/default_<slot>(-<variant>)?.php
 *   - templates/<template>/html/com_alfa/<view>/default_<slot>-<variant>.php
 *
 * An optional sidecar "<basename>.xml" next to a variant supplies a
 * translatable title via <layout title="">, same format as the menu metadata
 * XMLs. Stored value is the loadTemplate() argument ("" = inherit, "<slot>" =
 * force baseline, "<slot>-<variant>" = variant). Rendering goes through the
 * standard joomla.form.field.groupedlist layout for the native select styling.
 *
 * Attributes:
 *   - view          (required) site view whose tmpl dir is scanned
 *   - slot          (required) sub-template slot name
 *   - inheritlabel  language key for the empty option (default COM_ALFA_LAYOUT_USE_DEFAULT)
 *
 * @since  2.0.0
 */
class SubtemplateField extends GroupedlistField
{
    protected $type = 'Subtemplate';

    /**
     * Self-documenting description: when the XML gives none, explain the exact
     * file-naming contract for THIS slot so site builders know what to create.
     *
     * @param SimpleXMLElement $element The field XML element
     * @param mixed $value The field value
     * @param string $group The field group control name
     *
     * @return bool True on success
     * @since  2.0.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $return = parent::setup($element, $value, $group);

        if ($return && trim((string) $this->description) === '') {
            $this->description = Text::sprintf(
                'COM_ALFA_SUBTEMPLATE_FIELD_DESC',
                preg_replace('#[^\w-]#', '', (string) $element['slot']),
                preg_replace('#\W#', '', (string) $element['view']),
            );
        }

        return $return;
    }

    /**
     * Build the option groups: inherit, component slot files, per-template variants.
     *
     * @return array[] Option objects keyed by translated group label
     * @since  2.0.0
     */
    protected function getGroups()
    {
        $view = preg_replace('#\W#', '', (string) $this->element['view']);
        $slot = preg_replace('#[^\w-]#', '', (string) $this->element['slot']);

        if ($view === '' || $slot === '') {
            return [];
        }

        $inheritKey = (string) ($this->element['inheritlabel'] ?: 'COM_ALFA_LAYOUT_USE_DEFAULT');

        // Naming contract for the variant part: lowercase letters, digits, hyphens
        // only (underscores are core's private marker, dots break FileLayout paths,
        // uppercase never matches because core lowercases filenames on lookup).
        $pattern = '/^default_' . preg_quote($slot, '/') . '(-[a-z0-9][a-z0-9-]*)?\.php$/';

        $groups = [
            Text::_('JOPTION_FROM_STANDARD') => [
                HTMLHelper::_('select.option', '', Text::_($inheritKey)),
            ],
        ];

        // Component slot files: baseline plus shipped variants.
        $componentValues = [];

        foreach (glob(JPATH_SITE . '/components/com_alfa/tmpl/' . $view . '/default_' . $slot . '*.php') ?: [] as $file) {
            if (!preg_match($pattern, basename($file))) {
                continue;
            }

            $value = substr(basename($file, '.php'), \strlen('default_'));
            $componentValues[$value] = true;

            $label = $this->labelFromSidecar(
                xmlFile: substr($file, 0, -4) . '.xml',
                fallback: $value === $slot ? Text::_('COM_ALFA_SUBTEMPLATE_BASELINE') : self::pretty(substr($value, \strlen($slot) + 1)),
            );

            $groups[Text::_('JOPTION_FROM_COMPONENT')][] = HTMLHelper::_(
                'select.option',
                $value,
                $label . ' (' . basename($file) . ')',
            );
        }

        // Template override variants: per template dir, no DB involved.
        foreach (glob(JPATH_SITE . '/templates/*/html/com_alfa/' . $view . '/default_' . $slot . '*.php') ?: [] as $file) {
            if (!preg_match($pattern, basename($file))) {
                continue;
            }

            $value = substr(basename($file, '.php'), \strlen('default_'));

            if (isset($componentValues[$value])) {
                continue;
            }

            // templates/<template>/html/com_alfa/<view>/default_<slot>-<variant>.php: the template is four folders up.
            $template = basename(\dirname($file, 4));
            $groupLabel = Text::sprintf('COM_ALFA_LAYOUT_FROM_TEMPLATE', ucwords(str_replace('_', ' ', $template)));

            $label = $this->labelFromSidecar(
                xmlFile: substr($file, 0, -4) . '.xml',
                fallback: self::pretty(substr($value, \strlen($slot) + 1)),
            );

            $groups[$groupLabel][] = HTMLHelper::_(
                'select.option',
                $value,
                $label . ' (' . basename($file) . ')',
            );
        }

        return $groups;
    }

    /**
     * Human label from a variant suffix: hyphens/underscores become spaces.
     *
     * @param string $name Variant part of the basename
     *
     * @return string Prettified label
     * @since  2.0.0
     */
    private static function pretty(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    /**
     * Resolve a variant's display label from its optional sidecar metadata XML.
     *
     * @param string $xmlFile Full path of the sidecar XML (may not exist)
     * @param string $fallback Label when no sidecar/title is available
     *
     * @return string Translated label
     * @since  2.0.0
     */
    private function labelFromSidecar(string $xmlFile, string $fallback): string
    {
        if (!is_file($xmlFile) || !($xml = simplexml_load_file($xmlFile))) {
            return $fallback;
        }

        $layout = $xml->xpath('layout[1]');

        return $layout && $layout[0]['title'] ? Text::_((string) $layout[0]['title']) : $fallback;
    }
}
