<?php

namespace Alfa\Component\Alfa\Administrator\Field;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\HTML\HTMLHelper;
use Alfa\Component\Alfa\Administrator\Model\FormfieldModel;

class AlfaFieldField extends ListField
{

    public function setup(\SimpleXMLElement $element, $value, $group = null)
    {
        $return = parent::setup($element, $value, $group);
        return $return;
    }

    protected function getOptions(){

        $list_value = (string) $this->element['list_value'] ?: 'id';
        $list_text = (string) $this->element['list_text'] ?: 'name';

        $options = parent::getOptions();

        $fieldModel = new FormfieldModel(); // J5 way of accessing a model.

        $formFields = $fieldModel->getAllFields();

        foreach($formFields as $field)
            $options[] = HTMLHelper::_('select.option', $field[$list_value], $field[$list_text]);


        return $options;

    }



}