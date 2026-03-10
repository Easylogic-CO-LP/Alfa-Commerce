<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP and Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;

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
        $currentCategoryIdField = $this->element['currentIdField'] ?? 'id';
        $modelName = $this->element['model'] ?? 'categories';

        $this->options = parent::getOptions();

        $app = Factory::getApplication();
        $component = $app->bootComponent('com_alfa');
        $factory = $component->getMVCFactory();
        $model = $factory->createModel($modelName, 'Administrator');

        if (!$model) {
            return $this->options;
        }

        $model->getState('list.ordering');
        $model->setState('filter.state', '*');
        $model->setState('filter.search', '');
        $model->setState('list.limit', '0');
        $model->setState('list.ordering', $orderBy);
        $model->setState('list.direction', $orderDir);

        $categories = $model->getItems();
        $currentCategoryId = $this->form->getData()->get($currentCategoryIdField);

        $disableMode = false;
        $disableParentCategoryLevel = null;

        foreach ($categories as $category) {
            $disableCurrent = false;

            // CRITICAL: Always disable the current category itself
            if ($currentCategoryId == $category->id) {
                $disableCurrent = true;

                // If disableDescendants is enabled, also track to disable children
                if ($disableDescendants) {
                    $disableMode = true;
                    $disableParentCategoryLevel = $category->depth;
                }
            }
            // Disable descendants if we're in disable mode
            elseif ($disableMode) {
                if ($category->depth > $disableParentCategoryLevel) {
                    $disableCurrent = true;
                } else {
                    // We've moved back up or to the same level - stop disabling
                    $disableMode = false;
                }
            }

            $this->options['cat-' . $category->id] = [
                'value' => $category->id,
                'text' => (
                    $showPath
                    ? $category->path
                    : str_repeat('-', $category->depth) . $category->name
                ),
                'disable' => $disableCurrent,
            ];
        }

        return $this->options;
    }
}
