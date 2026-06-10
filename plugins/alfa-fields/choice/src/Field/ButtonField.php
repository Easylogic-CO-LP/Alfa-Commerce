<?php

namespace Alfa\Plugin\AlfaFields\Choice\Field;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\RadioField;

defined('_JEXEC') or die;

// Single-select button group. Same DB semantics as a radio — only the visual
// layout differs. The variant (solid / chip / pill / outline) is read from
// the element's button_style attribute and forwarded to the layout.
class ButtonField extends RadioField
{
    protected $type = 'Button';
    protected $layout = 'layouts.button';

    // Template plg-tmpl override + plugin tmpl, then parent's defaults
    // (template html/layouts, JPATH_ROOT/layouts, any layoutIncludePath attr).
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

        return $data;
    }
}
