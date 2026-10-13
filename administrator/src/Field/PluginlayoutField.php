<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

/**
 * Layout picker for one plugin's own layouts, for any plugin group and element.
 *
 * Lists every <prefix><name>.php found in:
 *   - plugins/<group>/<element>/tmpl/                                  (plugin layouts)
 *   - templates/<storefront-template>/html/plg_<group>_<element>/      (template overrides)
 *
 * Only the templates that render storefront pages are scanned: the template of
 * every default site style and of every style a published site menu item uses,
 * each with the parent it inherits from. The stored value is the plain <name>,
 * never "template:name": the plugin draws <prefix><name> through
 * Layout::pluginLayout(), which searches the page's template
 * html/plg_<group>_<element>/ (then its parent) before the plugin's tmpl, so an
 * override wins at runtime and a layout that exists only in a template resolves
 * by the same plain name, on the pages that template renders.
 *
 * Groups: the plugin's layouts first, then one group per template holding the
 * names that template adds, the default site template first. A name is listed
 * once: an override of a plugin layout, or a name two templates share, stays in
 * the first group that has it. A stored value the field does not offer stays
 * listed and selected, so the merchant sees what is stored and saving the form
 * never rewrites it: gone from disk, under its name; excluded, marked as not
 * offered here.
 *
 * Option text, first match wins: an <option value="<name>">KEY</option> child of
 * the field; the plugin's PLG_<GROUP>_<ELEMENT>_RENDER_<NAME> key; the name
 * prettified (hyphens to spaces, first letter upper case).
 *
 * Attributes:
 *   - plugin_group   (required) plugin group, e.g. alfa-item-fields
 *   - plugin         (required) plugin element, e.g. choice
 *   - prefix         file-name prefix the layouts share, e.g. item- (never stored)
 *   - exclude        comma list of names never offered
 *   - inherit        true adds a first option with the value "inherit"
 *   - inherit_label  language key of that option (default COM_ALFA_LAYOUT_INHERIT)
 *
 * @since  2.0.0
 */
class PluginlayoutField extends FormField
{
    protected $type = 'Pluginlayout';

    /**
     * Render a grouped select: the optional inherit entry and a stored value the
     * field does not offer (ungrouped, first), the plugin's tmpl layouts, then
     * the layouts each storefront template adds.
     *
     * @return string The grouped-list select HTML
     *
     * @throws InvalidArgumentException When plugin_group or plugin is missing.
     *
     * @since  2.0.0
     */
    protected function getInput(): string
    {
        $group = preg_replace('#[^\w\-]#', '', (string) $this->element['plugin_group']);
        $element = preg_replace('#[^\w\-]#', '', (string) $this->element['plugin']);

        if ($group === '' || $element === '') {
            throw new InvalidArgumentException(
                sprintf('Field "%s" (type pluginlayout) needs both plugin_group and plugin.', $this->fieldname),
            );
        }

        $prefix = preg_replace('#[^\w\-.]#', '', (string) $this->element['prefix']);
        $exclude = array_values(array_filter(array_map('trim', explode(',', (string) $this->element['exclude']))));
        $value = (string) $this->value;

        AlfaHelper::loadPluginLanguage(group: $group, element: $element);

        $keyPrefix = 'PLG_' . strtoupper(str_replace('-', '_', $group)) . '_' . strtoupper($element);
        $labels = [];

        foreach ($this->element->option as $option) {
            $labels[(string) $option['value']] = trim((string) $option);
        }

        $head = [];
        $groups = [];
        $listed = [];

        if (filter_var((string) $this->element['inherit'], FILTER_VALIDATE_BOOLEAN)) {
            $head[] = HTMLHelper::_(
                'select.option',
                'inherit',
                Text::_((string) ($this->element['inherit_label'] ?: 'COM_ALFA_LAYOUT_INHERIT')),
            );
            $listed['inherit'] = true;
        }

        // From Plugins: the plugin's own tmpl layouts.
        $pluginLayouts = self::layoutNames(
            dir: $this->pluginLayoutDir(group: $group, element: $element),
            prefix: $prefix,
            exclude: $exclude,
        );

        if ($pluginLayouts) {
            $groups['plugins'] = [
                'id' => $this->id . '__plugins',
                'text' => Text::_('COM_ALFA_LAYOUT_FROM_PLUGINS'),
                'items' => [],
            ];

            foreach ($pluginLayouts as $name) {
                $groups['plugins']['items'][] = HTMLHelper::_(
                    'select.option',
                    $name,
                    self::optionText(name: $name, labels: $labels, keyPrefix: $keyPrefix),
                );
                $listed[$name] = true;
            }
        }

        // From each storefront template: only the names no earlier group lists.
        foreach ($this->templateLayoutDirs(group: $group, element: $element) as $template => $dir) {
            $tplLayouts = array_values(array_filter(
                self::layoutNames(dir: $dir, prefix: $prefix, exclude: $exclude),
                static fn (string $name): bool => !isset($listed[$name]),
            ));

            if (!$tplLayouts) {
                continue;
            }

            $groups['tpl_' . $template] = [
                'id' => $this->id . '__tpl_' . $template,
                'text' => Text::sprintf('COM_ALFA_LAYOUT_FROM_TEMPLATE', ucwords(str_replace('_', ' ', $template))),
                'items' => [],
            ];

            foreach ($tplLayouts as $name) {
                $groups['tpl_' . $template]['items'][] = HTMLHelper::_(
                    'select.option',
                    $name,
                    self::optionText(name: $name, labels: $labels, keyPrefix: $keyPrefix),
                );
                $listed[$name] = true;
            }
        }

        // A stored value not offered above stays selected, so a save keeps it and the
        // merchant sees it: an excluded one is marked as not offered on this surface.
        if ($value !== '' && !isset($listed[$value])) {
            $text = self::optionText(name: $value, labels: $labels, keyPrefix: $keyPrefix);

            $head[] = HTMLHelper::_(
                'select.option',
                $value,
                \in_array($value, $exclude, true) ? Text::sprintf('COM_ALFA_LAYOUT_NOT_OFFERED', $text) : $text,
            );
        }

        if ($head) {
            $groups = array_merge([['items' => $head]], $groups);
        }

        $attr = $this->element['size'] ? ' size="' . (int) $this->element['size'] . '"' : '';
        $attr .= $this->element['class'] ? ' class="' . (string) $this->element['class'] . '"' : '';

        return HTMLHelper::_(
            'select.groupedlist',
            $groups,
            $this->name,
            [
                'id' => $this->id,
                'group.id' => 'id',
                'list.attr' => $attr,
                'list.select' => [$value],
            ],
        );
    }

    /**
     * The folder holding the plugin's own layouts.
     *
     * @param string $group The plugin group.
     * @param string $element The plugin element.
     *
     * @return string Absolute path of plugins/<group>/<element>/tmpl.
     *
     * @since  2.0.0
     */
    protected function pluginLayoutDir(string $group, string $element): string
    {
        return JPATH_PLUGINS . '/' . $group . '/' . $element . '/tmpl';
    }

    /**
     * The override folder of this plugin in every storefront template, the
     * default site template first.
     *
     * @param string $group The plugin group.
     * @param string $element The plugin element.
     *
     * @return array<string, string> Template element => absolute path of its
     *                               html/plg_<group>_<element> folder.
     *
     * @since  2.0.0
     */
    protected function templateLayoutDirs(string $group, string $element): array
    {
        $dirs = [];

        foreach ($this->getStorefrontTemplates() as $template) {
            $dirs[$template] = JPATH_SITE . '/templates/' . $template . '/html/plg_' . $group . '_' . $element;
        }

        return $dirs;
    }

    /**
     * The layout names in one folder: <prefix><name>.php with the prefix
     * stripped, minus the excluded names and underscored private partials.
     *
     * @param string $dir The folder to scan.
     * @param string $prefix The file-name prefix the layouts share.
     * @param string[] $exclude Names never offered.
     *
     * @return string[] Names, sorted.
     *
     * @since  2.0.0
     */
    private static function layoutNames(string $dir, string $prefix, array $exclude): array
    {
        $names = [];

        foreach (glob($dir . '/' . $prefix . '*.php') ?: [] as $file) {
            $name = substr(basename($file, '.php'), \strlen($prefix));

            if ($name === '' || str_starts_with($name, '_') || \in_array($name, $exclude, true)) {
                continue;
            }

            $names[$name] = true;
        }

        ksort($names);

        return array_keys($names);
    }

    /**
     * The text of one option: the field's own <option> label, else the plugin's
     * <keyPrefix>_RENDER_<NAME> key, else the name prettified.
     *
     * @param string $name The layout name.
     * @param array<string, string> $labels Name => language key, from the field's <option> children.
     * @param string $keyPrefix The plugin's language key prefix (PLG_<GROUP>_<ELEMENT>).
     *
     * @return string The translated option text.
     *
     * @since  2.0.0
     */
    private static function optionText(string $name, array $labels, string $keyPrefix): string
    {
        if (($labels[$name] ?? '') !== '') {
            return Text::_($labels[$name]);
        }

        $key = $keyPrefix . '_RENDER_' . strtoupper(str_replace('-', '_', $name));

        if (Factory::getApplication()->getLanguage()->hasKey($key)) {
            return Text::_($key);
        }

        return ucfirst(str_replace('-', ' ', $name));
    }

    /**
     * The templates that render storefront pages: the template of every default
     * site style (the all-languages default first, then one per language) and of
     * every style a published site menu item uses, each followed by the parent
     * it inherits from. A page searches these, and only these, for a plugin
     * layout, so a template that renders no page is never offered.
     *
     * @return string[] Template element names, unique, the default site template first.
     *
     * @since  2.0.0
     */
    private function getStorefrontTemplates(): array
    {
        $db = $this->getDatabase();
        $clientId = 0;
        $type = 'template';
        $notHome = '0';

        // The styles published site menu items render their pages with.
        $assigned = $db->createQuery()
            ->select($db->quoteName('m.template_style_id'))
            ->from($db->quoteName('#__menu', 'm'))
            ->where($db->quoteName('m.client_id') . ' = 0')
            ->where($db->quoteName('m.published') . ' = 1');

        $query = $db->createQuery()
            ->select($db->quoteName(['s.template', 's.parent', 's.home']))
            ->from($db->quoteName('#__template_styles', 's'))
            ->join(
                'INNER',
                $db->quoteName('#__extensions', 'e'),
                $db->quoteName('e.element') . ' = ' . $db->quoteName('s.template')
                    . ' AND ' . $db->quoteName('e.type') . ' = :type'
                    . ' AND ' . $db->quoteName('e.client_id') . ' = ' . $db->quoteName('s.client_id'),
            )
            ->where($db->quoteName('s.client_id') . ' = :clientId')
            ->where($db->quoteName('e.enabled') . ' = 1')
            ->where('(' . $db->quoteName('s.home') . ' != :notHome OR ' . $db->quoteName('s.id') . ' IN (' . $assigned . '))')
            ->bind(':clientId', $clientId, ParameterType::INTEGER)
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':notHome', $notHome, ParameterType::STRING);

        $db->setQuery($query);

        $styles = $db->loadObjectList() ?: [];

        // The all-languages default first, then by template name.
        usort(
            $styles,
            static fn (object $a, object $b): int => [(string) $a->home !== '1', (string) $a->template]
                <=> [(string) $b->home !== '1', (string) $b->template],
        );

        $templates = [];

        foreach ($styles as $style) {
            foreach ([(string) $style->template, (string) $style->parent] as $template) {
                if ($template !== '') {
                    $templates[$template] = true;
                }
            }
        }

        return array_keys($templates);
    }
}
