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
 * Layout picker for a com_alfa site view (com_content-style alternative layouts).
 *
 * Pure filesystem discovery on every render; dropping a file makes it appear:
 *   - components/com_alfa/tmpl/<view>/<name>.xml           (component layouts; the
 *     sidecar XML with a <layout> node supplies the title, mirroring core;
 *     underscored basenames are private sub-templates and never offered)
 *   - templates/<template>/html/com_alfa/<view>/<name>.php  (template overrides,
 *     deduplicated against component layouts)
 *
 * Stored values mirror Joomla's ComponentlayoutField convention, which
 * HtmlView::setLayout() parses natively: "" (inherit), "_:name" (component),
 * "<template>:<name>" (override). Rendering goes through the standard
 * joomla.form.field.groupedlist layout for the native select styling.
 *
 * Attributes:
 *   - view          (required) site view whose tmpl dir is scanned
 *   - inheritlabel  language key for the empty option (default COM_ALFA_LAYOUT_USE_DEFAULT)
 *
 * @since  2.0.0
 */
class ViewLayoutField extends GroupedlistField
{
    protected $type = 'ViewLayout';

    /**
     * Self-documenting description: when the XML gives none, explain where
     * layouts of THIS view live and how they must be named.
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
            $this->description = Text::sprintf('COM_ALFA_VIEWLAYOUT_FIELD_DESC', preg_replace('#\W#', '', (string) $element['view']));
        }

        return $return;
    }

    /**
     * Build the option groups: inherit, component layouts, per-template overrides.
     *
     * @return array[] Option objects keyed by translated group label
     * @since  2.0.0
     */
    protected function getGroups()
    {
        $view = preg_replace('#\W#', '', (string) $this->element['view']);

        if ($view === '') {
            return [];
        }

        $inheritKey = (string) ($this->element['inheritlabel'] ?: 'COM_ALFA_LAYOUT_USE_DEFAULT');

        $groups = [
            Text::_('JOPTION_FROM_STANDARD') => [
                HTMLHelper::_('select.option', '', Text::_($inheritKey)),
            ],
        ];

        // Component layouts: selectable ones carry a sidecar XML with a <layout> node.
        // Naming contract: lowercase letters, digits, hyphens only (underscores are
        // core's private marker, dots break FileLayout paths, uppercase never matches
        // because core lowercases filenames on lookup).
        $componentLayouts = [];

        foreach (glob(JPATH_SITE . '/components/com_alfa/tmpl/' . $view . '/*.xml') ?: [] as $file) {
            $name = basename($file, '.xml');

            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
                continue;
            }

            $xml = simplexml_load_file($file);

            if (!$xml || !($layout = $xml->xpath('layout[1]'))) {
                continue;
            }

            // The baseline is always labeled "Default"; the sidecar title (e.g.
            // "Single Item") is the menu-type name, not a layout name.
            $title = $name === 'default'
                ? Text::_('COM_ALFA_SUBTEMPLATE_BASELINE')
                : ($layout[0]['title'] ? Text::_((string) $layout[0]['title']) : self::pretty($name));
            $componentLayouts[$name] = $title . ' (' . $name . '.php)';
        }

        ksort($componentLayouts);

        foreach ($componentLayouts as $name => $label) {
            $groups[Text::_('JOPTION_FROM_COMPONENT')][] = HTMLHelper::_('select.option', '_:' . $name, $label);
        }

        // Template overrides: plain .php files per template dir, no DB involved.
        foreach (glob(JPATH_SITE . '/templates/*/html/com_alfa/' . $view . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');

            if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $name) || isset($componentLayouts[$name])) {
                continue;
            }

            $template = basename(\dirname($file, 3));
            $groupLabel = Text::sprintf('COM_ALFA_LAYOUT_FROM_TEMPLATE', ucwords(str_replace('_', ' ', $template)));
            $groups[$groupLabel][] = HTMLHelper::_('select.option', $template . ':' . $name, self::pretty($name) . ' (' . $name . '.php)');
        }

        return $groups;
    }

    /**
     * Human label from a file basename: hyphens/underscores become spaces.
     *
     * @param string $name Layout basename without extension
     *
     * @return string Prettified label
     * @since  2.0.0
     */
    private static function pretty(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
