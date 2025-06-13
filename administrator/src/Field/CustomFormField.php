<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_fields
 *
 * @copyright   (C) 2016 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Alfa\Component\Alfa\Administrator\Field;

use Alfa\Component\Alfa\Administrator\Helper\CustomFormFieldHelper;
use Alfa\Component\Alfa\Administrator\Helper\ShipmentsHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
//use Alfa\Component\Alfa\Administrator\Helper\PaymentsHelper;
// use Alfa\Component\Alfa\Administrator\Helper\FieldsHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Fields Type
 *
 * @since  3.7.0
 */
class CustomFormFieldTypeField extends ListField
{
    /**
     * @var    string
     */
    public $type = 'CustomFormField';

    /**
     * Method to attach a Form object to the field.
     *
     * @param   \SimpleXMLElement  $element  The SimpleXMLElement object representing the `<field>` tag for the form field object.
     * @param   mixed              $value    The form field value to validate.
     * @param   string             $group    The field name group control value. This acts as an array container for the field.
     *                                       For example if the field has name="foo" and the group value is set to "bar" then the
     *                                       full field name would end up being "bar[foo]".
     *
     * @return  boolean  True on success.
     *
     * @since   3.7.0
     */
    public function setup(\SimpleXMLElement $element, $value, $group = null)
    {
        $return = parent::setup($element, $value, $group);

//         $this->onchange = 'Joomla.typeHasChanged(this);';

        return $return;
    }

    /**
     * Method to get the field options.
     *
     * @return  array  The field option objects.
     *
     * @since   3.7.0
     */
    protected function getOptions()
    {
        $list_value = (string) $this->element['list_value'] ?: 'name';
        $list_text = (string) $this->element['list_text'] ?: 'name';

        $options = parent::getOptions();
        $fieldTypes = CustomFormFieldHelper::getFieldTypes();

        foreach ($fieldTypes as $fieldType) {
            $options[] = HTMLHelper::_('select.option', $fieldType[$list_value], $fieldType[$list_text]);
        }

        // Sorting the fields based on the text which is displayed
        usort(
            $options,
            function ($a, $b) {
                return strcmp($a->text, $b->text);
            }
        );

        return $options;
    }
}
