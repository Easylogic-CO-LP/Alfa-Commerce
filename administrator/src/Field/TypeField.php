<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Alfa\Component\Alfa\Administrator\Plugin\AlfaPluginHelper;
use Alfa\Component\Alfa\Administrator\Plugin\PluginId;
use InvalidArgumentException;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use SimpleXMLElement;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fields Type
 *
 * @since  1.0.0
 */
class TypeField extends ListField
{
    /**
     * @var string
     */
    public $type = 'Type';

    /**
     * Method to attach a Form object to the field.
     *
     * @param SimpleXMLElement $element The SimpleXMLElement object representing the `<field>` tag for the form field object.
     * @param mixed $value The form field value to validate.
     * @param string $group The field name group control value. This acts as an array container for the field.
     *                      For example if the field has name="foo" and the group value is set to "bar" then the
     *                      full field name would end up being "bar[foo]".
     *
     * @return bool True on success.
     *
     * @since  1.0.0
     */
    public function setup(SimpleXMLElement $element, $value, $group = null)
    {
        $return = parent::setup($element, $value, $group);

        $this->onchange = 'Joomla.typeHasChanged(this);';

        return $return;
    }

    /**
     * Method to get the field options.
     *
     * @return array The field option objects.
     *
     * @since  1.0.0
     */
    protected function getOptions()
    {
        $list_value = (string) $this->element['list_value'] ?: 'name';
        $list_text = (string) $this->element['list_text'] ?: 'name';
        $plugin_type = (string) $this->element['plugin_type'] ?: '';

        $options = parent::getOptions();

        if (empty($plugin_type)) {
            return $options;
        }

        $fieldTypes = AlfaHelper::getFieldTypes($plugin_type);
        $plugins = AlfaPluginHelper::get();
        $group = AlfaHelper::pluginGroup(type: $plugin_type);

        // Each plugin by the name Extensions: Plugins shows (UX-25, UX-38).
        foreach ($fieldTypes as $fieldType) {
            $options[] = HTMLHelper::_('select.option', $fieldType[$list_value], $list_text === 'name' ? $plugins->name(PluginId::from($group, (string) $fieldType['name'])) : $fieldType[$list_text]);
        }

        // The method's own plugin when it is not enabled (a locked method whose plugin was disabled or removed): named,
        // with "(disabled)" or "(not installed)", so the locked field still says which plugin the method uses (UX-38).
        $value = (string) $this->value;

        if ($list_value === 'name' && $value !== '' && !\in_array($value, array_map(static fn (object $option): string => (string) $option->value, $options), true)) {
            try {
                $current = PluginId::from($group, $value);
                $mark = $plugins->mark($current);
                $options[] = HTMLHelper::_('select.option', $value, $plugins->name($current) . ($mark === '' ? '' : ' (' . $mark . ')'));
            } catch (InvalidArgumentException) {
                // Not a plugin's element: the list stays as Joomla builds it.
            }
        }

        // Sorting the fields based on the text which is displayed
        usort(
            $options,
            function ($a, $b) {
                return strcmp($a->text, $b->text);
            },
        );

        // Load scripts
        Factory::getApplication()->getDocument()->getWebAssetManager()
            ->useScript('com_alfa.admin-field-typehaschanged')
            ->useScript('com_alfa.core-loader');

        return $options;
    }
}
