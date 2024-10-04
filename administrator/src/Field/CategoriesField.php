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
        $disableDescendants = $this->element['disableDescendants'] == 'true' ? true : false;
        $showPath = $this->element['showPath'] == 'true' ? true : false;
        $orderBy = $this->element['orderBy'] ?? 'name';
        $orderDir = $this->element['orderDir'] ?? 'ASC';
        $currentCategoryIdField = $this->element['currentIdField'] ?? 'id';//by default as the current category id we get the name="id" field from the form

        $modelName = $this->element['model'] ?? 'categories';//model to use getItems from ( default is categories model )

        $this->options = parent::getOptions();

        $app = Factory::getApplication();
        $component = $app->bootComponent('com_alfa');
        $factory = $component->getMVCFactory();
        $model = $factory->createModel($modelName, 'Administrator');

        if (!$model) {
            return $this->options;
        }

        $model->setState('filter.state', '*');

        $model->getState('list.ordering');//we should use get before set the list state fields

        $model->setState('list.ordering', $orderBy);
        $model->setState('list.direction', $orderDir);

        $categories = $model->getItems();

        $currentCategoryId = $this->form->getData()->get($currentCategoryIdField);//current category id , gets the form field

        // Track if we are in descendant disabling mode
        $disableMode = false;
        $disableParentCategoryLevel = null;
        // echo '<pre>';
        // print_r($categories);
        // echo '</pre>';
        // TODO: FIX can select on the same level sometimes

        foreach ($categories as $category) {
            $disableCurrent = false;//by default disable the current category will be false


            // Check if we should start disabling descendants
            if ($disableDescendants && $currentCategoryId == $category->id) {
                $disableMode = true;
                $disableParentCategoryLevel = $category->depth; // Capture the level of the current category
            }

            // If we're in disable mode, disable until we reach a category with a depth greater than current
            if ($disableMode) {
                if ($category->depth > $disableParentCategoryLevel) {
                    $disableCurrent = true;
                } else {
                    $disableMode = false;
                }
            }

            $this->options['cat-' . $category->id] =
                array('value' => $category->id,

                    'text' => ($showPath
                        ? $category->path
                        : str_repeat('-', $category->depth) . $category->name
                    ),
                    'disable' => $disableCurrent, // Adding the disabled attribute
                );


        }


//      $removeCurrent = $this->element['removeCurrent']=='true' ? true : false;
//      if ($removeCurrent){ unset($this->options['cat-' . $currentCategoryId]); }

        return $this->options;

    }

}