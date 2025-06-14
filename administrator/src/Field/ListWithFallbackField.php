<?php

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

class ListWithFallbackField extends ListField
{
    protected $type = 'ListWithFallback';

    protected $options = [];

    // public function setup(\SimpleXMLElement $element, $value, $group = null)
    // {
    //     // Call the parent setup method
    //     $result = parent::setup($element, $value, $group);

    //     if (!$result) {
    //         return false;
    //     }

    //     // Dynamically modify the attributes
    //     $element['readonly'] = 'true';  // Make the field not required
    //     // $element['multiple'] = 'true';   // Enable multiple selection

    //     return true;
    // }

    protected function getOptions()
    {

        // Get the database object
        $db = Factory::getContainer()->get('DatabaseDriver');

        $table = (string) $this->getAttribute('table') ?: '#__alfa_items';
        $idField = (string) $this->getAttribute('id_field') ?: 'id';
        $nameField = (string) $this->getAttribute('name_field') ?: 'name';
        $orderField = (string) $this->getAttribute('order_field') ?: $nameField;

        $fallbackTable = (string) $this->getAttribute('fallback_table') ?: '';
        $fallbackIdField = (string) $this->getAttribute('fallback_id_field') ?: $idField;
        $fallbackNameField = (string) $this->getAttribute('fallback_name_field') ?: $nameField;

        // $this->element['readonly'] = 'true';
        // print_r(var_dump($this->element['readonly']));
        // exit;
        // $this->readonly = $this->getAttribute('readonly');

        // Build the main query
        $query = $db->getQuery(true)
            ->select([$db->quoteName($idField), $db->quoteName($nameField)])
            ->from($db->quoteName($table))
            ->order($db->quoteName($orderField));

        $db->setQuery($query);

        // print_r($db->replacePrefix((string) $query));
        // exit;
        $items = $db->loadObjectList();

        // Initialize options array with any existing options (if defined)
        $this->options = parent::getOptions();

        // Populate options with query results
        if (!empty($items)) {
            foreach ($items as $item) {
                $this->options[] = HTMLHelper::_('select.option', $item->{$idField}, $item->{$nameField});
            }
        }


        // Check if the current element value exists in the options
        $currentValue = $this->value;
        $valueExists = false;

        foreach ($this->options as $option) {
            if ((string) $option->value === (string) $currentValue) {
                $valueExists = true;
                break;
            }
        }

        // If the current value doesn't exist in the options, fetch the name from #__alfa_order_items
        if (!$valueExists && !empty($currentValue) && !empty($fallbackTable)) {
            $nameQuery = $db->getQuery(true)
                ->select($db->quoteName($fallbackNameField))
                ->from($db->quoteName($fallbackTable))
                ->where($db->quoteName($fallbackIdField) . ' = ' . $db->quote($currentValue));

            $db->setQuery($nameQuery);
            $itemName = $db->loadResult();
            $displayName = $itemName ? $itemName . ' ('.Text::_('COM_ALFA_ITEM_DELETED').')' : Text::_('COM_ALFA_ITEM_NOT_FOUND') . ' (' . $currentValue . ')';

            $this->options[] = HTMLHelper::_('select.option', $currentValue, $displayName);
        }

        return $this->options;

    }

    // protected function getInput()
    // {
    //     $input = parent::getInput();

    // //         // $this->element['readonly'] = 'true';

    //     if (!empty($this->value)) {
    // //         // Add 'disabled' attribute and Bootstrap classes
    //         $input = str_replace('<select', '<select disabled="disabled" class="disabled form-control-plaintext"', $input);

    // //         // Add hidden input to submit the value
    //         $input .= '<input type="hidden" name="' . $this->name . '" value="' . htmlspecialchars($this->value, ENT_QUOTES) . '">';
    //     }

    //     return $input;
    // }


}
