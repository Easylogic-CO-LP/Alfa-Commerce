<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Service\Catalog\Labels;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;

/**
 * The labels a visitor may filter the catalogue by (Labels::filterable()): the published labels within their period
 * that are set as filterable, in the order of the Labels screen, each named in the request's language. The storefront
 * filter form's `label` field, and through it a menu item's preset filters.
 *
 *   <field name="label" type="labelsfilter" multiple="true" layout="joomla.form.field.list-fancy-select" ... />
 *
 * @since  2.3.0
 */
class LabelsfilterField extends ListField
{
    /**
     * The field type.
     *
     * @var string
     * @since  2.3.0
     */
    protected $type = 'Labelsfilter';

    /**
     * The options: the filterable labels, by id.
     *
     * @return object[]
     *
     * @since  2.3.0
     */
    protected function getOptions()
    {
        $options = parent::getOptions();

        foreach (Labels::filterable() as $id => $label) {
            $options[] = HTMLHelper::_('select.option', (int) $id, $label->name);
        }

        return $options;
    }
}
