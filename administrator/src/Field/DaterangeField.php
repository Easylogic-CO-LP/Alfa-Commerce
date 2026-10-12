<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;

/**
 * The date-range preset dropdown shared by every admin list that filters on a period.
 *
 * Built from {@see AlfaHelper::DATE_RANGE_PRESETS}, which is also what
 * {@see AlfaHelper::buildDateRange()} resolves, so the offered options and the
 * understood options cannot drift. Before this existed the same twelve <option> lines
 * were written out in five separate filter forms, and a thirteenth preset meant
 * remembering all five.
 *
 * A list opts in with a single line:
 *
 *     <field name="date_range" type="daterange" label="COM_ALFA_FILTER_DATE_RANGE" />
 *
 * A plugin panel can use it too: its form only needs
 * addfieldprefix="Alfa\Component\Alfa\Administrator\Field". The labels are COM_ALFA_*
 * because this component class is what renders them, wherever the field appears.
 *
 * @since  1.10.0
 */
class DaterangeField extends ListField
{
    protected $type = 'daterange';

    /**
     * The empty option plus one per preset.
     *
     * @return array The option set.
     *
     * @since  1.10.0
     */
    protected function getOptions(): array
    {
        // Translated HERE: core only runs Text on options it parsed out of the XML
        // itself, so a getOptions() that builds its own would otherwise print the raw
        // language keys straight into the dropdown.
        // `blank="false"` lets a form supply its OWN empty option (a report's "Follow the
        // schedule") instead of the filter's "- Any date -"; both would otherwise render,
        // because the XML child options are merged in below and cannot replace this one.
        $options = (string) ($this->element['blank'] ?? 'true') === 'false' ? [] : [
            HTMLHelper::_('select.option', '', Text::_('COM_ALFA_FILTER_SELECT_RANGE')),
        ];

        foreach (AlfaHelper::DATE_RANGE_PRESETS as $preset) {
            $options[] = HTMLHelper::_(
                'select.option',
                $preset,
                Text::_('COM_ALFA_DATE_RANGE_' . strtoupper($preset)),
            );
        }

        // Anything the form itself declared still wins, so a list can prepend or append
        // its own entry without losing the shared set.
        return array_merge(parent::getOptions(), $options);
    }
}
