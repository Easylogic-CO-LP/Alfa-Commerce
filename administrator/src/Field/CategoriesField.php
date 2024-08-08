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

    protected $options = [];

    protected function getOptions()
    {
        $app = Factory::getApplication();
        $component = $app->bootComponent('com_alfa');
        $factory = $component->getMVCFactory();
        $model = $factory->createModel('Categories', 'Administrator');
        $model->setState('filter.state', '*');
        $categories = $model->getItems();// Fetch the item using the model's getItem method.

        $nestedCategories = AlfaHelper::buildNestedArray($categories);
        AlfaHelper::iterateNestedArray($nestedCategories, function ($node, $fullPath) {
            $this->options['cat-' . $node->id] = array('value' => $node->id, 'text' => $fullPath);
        }, false);

        if ($this->element['removeCurrent'] == "true") {
            $idToRemove = $this->form->getData()->get('id');
            unset($this->options['cat-' . $idToRemove]);
        }
        return array_merge(parent::getOptions(), $this->options);
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
