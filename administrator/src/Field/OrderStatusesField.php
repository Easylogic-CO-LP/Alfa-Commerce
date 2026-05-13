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

class OrderStatusesField extends ListField
{
    protected $type = 'orderStatuses';
    protected $options = [];

    protected function getOptions()
    {
        $this->options = parent::getOptions();

        $app = Factory::getApplication();
        $factory = $app->bootComponent('com_alfa')->getMVCFactory();

        $model = $factory->createModel('Orderstatuses', 'Administrator');

        if (!$model) {
            return $this->options;
        }

        $orderBy  = $this->element['orderBy'] ? (string) $this->element['orderBy'] : 'a.id';
        $orderDir = $this->element['orderDir'] ? (string) $this->element['orderDir'] : 'ASC';

        $model->setState('filter.state', 1);
        $model->setState('list.ordering', $orderBy);
        $model->setState('list.direction', $orderDir);
        $model->setState('list.limit', 0);

        $items = $model->getItems();

        if ($items) {
            foreach ($items as $item) {
                $this->options[] = [
                    'value' => $item->id,
                    'text'  => (string) ($item->name ?? '(Untranslated)')
                ];
            }
        }

        return $this->options;
    }
}