<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Factory;

class ManufacturersField extends ListField
{
    protected $type = 'manufacturers';
    protected $options = [];

    protected function getOptions()
    {
        $this->options = parent::getOptions();

        $app = Factory::getApplication();
        $factory = $app->bootComponent('com_alfa')->getMVCFactory();

        $orderBy = $this->element['orderBy'] ?? 'name';
        $orderDir = $this->element['orderDir'] ?? 'ASC';

        $model = $factory->createModel('Manufacturers', 'Administrator');

        if (!$model) {
            return $this->options;
        }

        $model->setState('filter.state', 1);
        $model->setState('list.ordering', $orderBy);
        $model->setState('list.direction', $orderDir);

        $items = $model->getItems();

        foreach ($items as $item) {
            $this->options[] = [
                'value' => $item->id,
                'text'  => $item->name
            ];
        }

        return $this->options;
    }
}