<?php

namespace Joomla\Plugin\AlfaFields\Choice\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\CheckboxesField;

defined('_JEXEC') or die;

// Multi-select button group. Same DB semantics as checkboxes — value is an
// array. Min/max are stamped onto the element in prepareDom and read by both
// the layout (for the live hint) and ChoiceRule (for server validation).
class ButtonmultiField extends CheckboxesField
{
    protected $type = 'Buttonmulti';
    protected $layout = 'layouts.buttonmulti';

    public function getLayoutPaths(): array
    {
        $template = Factory::getApplication()->getTemplate();

        return array_merge(
            [
                JPATH_THEMES . '/' . $template . '/html/plg_alfa-fields_choice',
                JPATH_PLUGINS . '/alfa-fields/choice/tmpl',
            ],
            parent::getLayoutPaths(),
        );
    }

    protected function getLayoutData()
    {
        $data = parent::getLayoutData();
        $data['variant'] = (string) ($this->element['button_style'] ?: 'solid');
        $data['layoutMode'] = (string) ($this->element['layout_mode'] ?: 'vertical');
        $data['minSelections'] = (int) ($this->element['data-min-selections'] ?: 0);
        $data['maxSelections'] = (int) ($this->element['data-max-selections'] ?: 0);

        return $data;
    }
}
