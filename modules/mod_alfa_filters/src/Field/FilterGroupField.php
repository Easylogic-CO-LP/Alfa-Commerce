<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Module\AlfaFilters\Site\Field;

use Alfa\Module\AlfaFilters\Site\Section\FilterSections;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

defined('_JEXEC') or die;

/**
 * One option group of the panel to pick: the categories, the manufacturers, or one
 * filterable item field, named as the panel composer names them. The value is the
 * group's section key (category, manufacturer, field:<id>).
 *
 * @since  2.3.0
 */
class FilterGroupField extends ListField
{
    /**
     * Field type id: must match the filename and the XML `type` attribute.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'FilterGroup';

    /**
     * The groups, the built-in ones first, then the item fields in backend ordering.
     *
     * @return array
     *
     * @since  2.3.0
     */
    protected function getOptions(): array
    {
        $options = parent::getOptions();

        foreach (FilterSections::FIXED_BEFORE as $key) {
            $options[] = HTMLHelper::_('select.option', $key, FilterSections::label($key));
        }

        foreach (FilterSections::filterableFields() as $id => $label) {
            $options[] = HTMLHelper::_('select.option', FilterSections::fieldKey((int) $id), $label);
        }

        return $options;
    }
}
