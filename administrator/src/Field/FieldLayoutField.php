<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\Database\ParameterType;

/**
 * Layout picker for an alfa-fields field.
 *
 * Lists every layout basename found in:
 *   - plugins/alfa-fields/*\/tmpl/*.php                                    (plugin defaults)
 *   - templates/<frontend-template>/html/plg_alfa-fields_*\/*.php          (template overrides)
 *
 * Every enabled frontend template is scanned (client_id = 0). Selecting an
 * option stores the value in "template:layout" form, mirroring Joomla's
 * ComponentlayoutField convention:
 *   - "_:default"       — plugin's own tmpl/default.php (no template override)
 *   - "cassiopeia:foo"  — templates/cassiopeia/html/plg_alfa-fields_<type>/foo.php
 *
 * The runtime resolver splits on ":" to pick the right file for the field's
 * type. Falls back to "_:default" if the stored override no longer exists.
 * @since  1.0.0
 */
class FieldLayoutField extends FormField
{
    protected $type = 'FieldLayout';

    /**
     * Render a grouped select listing the available layouts: a "use default"
     * entry, the union of alfa-fields plugin tmpl layouts ("_:<layout>") and the
     * per-template override layouts ("<template>:<layout>") for every enabled
     * frontend template.
     *
     * @return string The grouped-list select HTML
     * @since  1.0.0
     */
    protected function getInput(): string
    {
        $groups = [];

        // Default group — empty value = "use default.php".
        $groups['default'] = [
            'id' => $this->id . '__default',
            'text' => Text::_('JOPTION_FROM_STANDARD'),
            'items' => [
                HTMLHelper::_('select.option', '', Text::_('COM_ALFA_LAYOUT_USE_DEFAULT')),
            ],
        ];

        // From Plugins — union across all alfa-fields plugin tmpl folders.
        // Value format: "_:<layout>" to distinguish from template overrides.
        $pluginLayouts = [];
        foreach (glob(JPATH_PLUGINS . '/alfa-fields/*/tmpl/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if ($name === '' || str_starts_with($name, '_')) {
                continue;
            }
            $pluginLayouts[$name] = true;
        }
        ksort($pluginLayouts);

        if ($pluginLayouts) {
            $groups['plugins'] = [
                'id' => $this->id . '__plugins',
                'text' => Text::_('COM_ALFA_LAYOUT_FROM_PLUGINS'),
                'items' => [],
            ];
            foreach (array_keys($pluginLayouts) as $name) {
                $groups['plugins']['items'][] = HTMLHelper::_('select.option', '_:' . $name, $name);
            }
        }

        // From every enabled frontend template (client_id = 0).
        foreach ($this->getEnabledFrontendTemplates() as $template) {
            $tplLayouts = [];

            foreach (glob(JPATH_SITE . '/templates/' . $template . '/html/plg_alfa-fields_*/*.php') ?: [] as $file) {
                $name = basename($file, '.php');
                if ($name === '' || str_starts_with($name, '_')) {
                    continue;
                }
                $tplLayouts[$name] = true;
            }
            ksort($tplLayouts);

            if (!$tplLayouts) {
                continue;
            }

            $groups['tpl_' . $template] = [
                'id' => $this->id . '__tpl_' . $template,
                'text' => Text::sprintf('COM_ALFA_LAYOUT_FROM_TEMPLATE', ucwords(str_replace('_', ' ', $template))),
                'items' => [],
            ];

            foreach (array_keys($tplLayouts) as $name) {
                $groups['tpl_' . $template]['items'][] = HTMLHelper::_(
                    'select.option',
                    $template . ':' . $name,
                    $name,
                );
            }
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
                'list.select' => [$this->value],
            ],
        );
    }

    /**
     * @return string[] Element names of enabled frontend templates.
     * @since  1.0.0
     */
    private function getEnabledFrontendTemplates(): array
    {
        $db = $this->getDatabase();
        $clientId = 0;
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
