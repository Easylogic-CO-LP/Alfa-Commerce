<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
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

    /**
     * Build the category options from the configured model (default 'categories'),
     * rendered as a depth-indented or full-path tree. When 'disableDescendants'
     * is set on a self-referential picker, disables the current node and its
     * descendants to prevent a category from becoming its own parent (cycle).
     *
     * @return array The option set keyed by 'cat-<id>'
     * @since  1.0.0
     */
    protected function getOptions()
    {
        // `disableDescendants` is the single switch for self-parent prevention.
        // It is ONLY valid on a self-referential picker — a category/place
        // choosing its own parent — and there it disables the current node
        // itself AND its descendants (so a node can't be its own parent or pick
        // a child as parent → cycle). On every other consumer (items, payments,
        // … assigning categories) it is false, so NOTHING is disabled: those
        // forms don't pick a parent, so there is no node to exclude, and the
        // edited record's id isn't even from this tree.
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
        $currentCategoryId = $disableDescendants
            ? $this->form->getData()->get($currentCategoryIdField)
            : null;

        $disableMode = false;
        $disableParentCategoryLevel = null;

        foreach ($categories as $category) {
            $disableCurrent = false;

            if ($disableDescendants) {
                // Disable the current node itself (can't be its own parent) …
                if ($currentCategoryId == $category->id) {
                    $disableCurrent = true;
                    $disableMode = true;
                    $disableParentCategoryLevel = $category->depth;
                }
                // … and its descendants (can't pick a child as parent → cycle).
                elseif ($disableMode) {
                    if ($category->depth > $disableParentCategoryLevel) {
                        $disableCurrent = true;
                    } else {
                        // Moved back up or to the same level — stop disabling.
                        $disableMode = false;
                    }
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
