<?php

namespace Alfa\Component\Alfa\Administrator\Field;

// use Joomla\CMS\Factory;
// use Joomla\CMS\Form\Field\ListField;
// use Joomla\CMS\HTML\HTMLHelper;
// use Joomla\CMS\Language\Text;
// use Joomla\CMS\Router\Route;
// use Joomla\Component\Menus\Administrator\Helper\MenusHelper;
// use Joomla\Utilities\ArrayHelper;
// use Joomla\CMS\Table\Table;
// use Joomla\CMS\Component\ComponentHelper;
// phpcs:disable PSR1.Files.SideEffects

\defined('_JEXEC') or die;


use Alfa\Component\Alfa\Administrator\Helper\AlfaHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

// use Joomla\CMS\Date\Date;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

class CategoriesField extends ListField
{

    protected $type = 'categories';

    // protected function getInput() {

    //     $id = $this->list_id;
    //     $my_var = $this->depends_on;
    //     $name = $this->name;

    //     $html = '<select id='.$id.'><option value="0">Option 1</option>
    //           <option value="1">--Option 2</option>
    //           <option value="3">--Option 3</option>
    //           <option value="3">---Option 3</option>
    //           <option value="4">Option 4</option>
    //           <option value="5">-Option 5</option>
    //           <option value="6">Option 6</option></select>';
    //     return $html;

    // }

    protected function getOptions()
    {

        $app = Factory::getApplication();
        $component = $app->bootComponent('com_alfa');
        $factory = $component->getMVCFactory();
        $model = $factory->createModel('Categories', 'Administrator');
        $model->setState('filter.state','*');
        $categories = $model->getItems();// Fetch the item using the model's getItem method.

//          array('value' => 0, 'text' => $this->default-selection)
        $options = array();
        // foreach($rows as $row){
        // array_push($options, array('value' => 1, 'text' => 'to1' ));
        // array_push($options, array('value' => 2, 'text' => 'to2' ));
        // array_push($options, array('value' => 3, 'text' => 'to3' ));
        // }

        // options[]    = HTMLHelper::_('select.option', '', $header_title);

        // // pre-select values 2 and 3
        // $this->value = array(2, 3);
        foreach ($categories as $category) {
            parent::addOption($category->name, ['value' => $category->id]);
        }
        $result = AlfaHelper::buildNestedArray($categories);

            // Merge any additional options in the XML definition.
            $options = array_merge(parent::getOptions(), $options);
            // parent::getOptions();
            return $options;
    }

    public function getAttribute($attr_name, $default = null)
    {
        // if (!empty($this->element[$attr_name])) {
        //     return $this->element[$attr_name];
        // } else {
        //     return $default;
        // }
    }
}