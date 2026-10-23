<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\View\Resetdefaults;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Service\Maintenance\ResetToDefault;
use Alfa\Component\Alfa\Site\Helper\PriceSettings;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Tools > Reset to default: every setting the reset would change, current value and default,
 * and the confirmation.
 *
 * @since  2.1.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * What the reset would change ({@see ResetToDefault::preview()}).
     *
     * @var array{options: list<array{key: string, current: mixed, default: mixed}>, groups: list<array<string, mixed>>}
     *
     * @since  2.1.0
     */
    public array $preview = ['options' => [], 'groups' => []];

    /**
     * The label of each Option, from the Options form, by key.
     *
     * @var array<string, string>
     *
     * @since  2.1.0
     */
    public array $labels = [];

    /**
     * The customer group's price display settings as its form names them, by key: the setting's label (with its
     * section when several settings share a label) and the text of each value.
     *
     * @var array<string, array{label: string, options: array<string, string>}>
     *
     * @since  2.1.1
     */
    public array $displayFields = [];

    /**
     * Render the page.
     *
     * @param string|null $tpl The template name.
     *
     * @since  2.1.0
     */
    public function display($tpl = null): void
    {
        if (!ResetToDefault::permitted(Factory::getApplication()->getIdentity())) {
            throw new NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->preview = (new ResetToDefault())->preview();
        $this->labels = $this->labels();
        $this->displayFields = $this->displayFields();

        ToolbarHelper::title(Text::_('COM_ALFA_RESET_TITLE'), 'refresh');
        ToolbarHelper::back('JTOOLBAR_BACK', 'index.php?option=com_alfa&view=tools');

        parent::display($tpl);
    }

    /**
     * The customer group's price display settings, as the customer group form shows them (its fields are the
     * Options' price display fields), by key.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     *
     * @since  2.1.1
     */
    private function displayFields(): array
    {
        $fields = [];
        $xml = @simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_alfa/config.xml');

        if ($xml === false) {
            return $fields;
        }

        foreach ($xml->xpath('//fieldset[@name="prices"]//field[@name]') ?: [] as $field) {
            $name = (string) $field['name'];

            if (!\in_array($name, PriceSettings::FIELDS, true)) {
                continue;
            }

            $section = $field->xpath('parent::fieldset[@label]');
            $options = [];

            foreach ($field->option as $option) {
                $options[(string) $option['value']] = Text::_((string) $option);
            }

            $fields[$name] = [
                'label' => Text::_((string) $field['label']),
                'section' => $section ? Text::_((string) $section[0]['label']) : '',
                'options' => $options,
            ];
        }

        // A label several settings share ("Show Label") is told apart by its section.
        $counts = array_count_values(array_column($fields, 'label'));

        foreach ($fields as $name => $field) {
            $fields[$name] = [
                'label' => $counts[$field['label']] > 1 && $field['section'] !== '' ? $field['section'] . ', ' . $field['label'] : $field['label'],
                'options' => $field['options'],
            ];
        }

        return $fields;
    }

    /**
     * The label of each field of the Options form, translated, by name.
     *
     * @return array<string, string>
     *
     * @since  2.1.0
     */
    private function labels(): array
    {
        $labels = [];
        $xml = @simplexml_load_file(JPATH_ADMINISTRATOR . '/components/com_alfa/config.xml');

        if ($xml === false) {
            return $labels;
        }

        foreach ($xml->xpath('//field[@name]') ?: [] as $field) {
            $label = (string) ($field['label'] ?? '');

            if ($label !== '') {
                $labels[(string) $field['name']] = Text::_($label);
            }
        }

        return $labels;
    }
}
