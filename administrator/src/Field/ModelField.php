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

class ModelField extends ListField
{
    protected $type = 'model';

    protected $options = [];

    /**
     * Per-request static cache, shared across all instances of this field.
     * Keyed by every XML attribute that affects the output, so different
     * field configurations on the same page don't collide.
     */
    protected static $cache = [];

    protected function getOptions()
    {
        $componentName = (string) ($this->element['component']    ?? 'com_alfa');
        $modelName     = (string) ($this->element['model']        ?? 'places');
        $columnValue   = (string) ($this->element['column_value'] ?? 'id');
        $columnText    = (string) ($this->element['column_text']  ?? 'name');
        $orderBy       = (string) ($this->element['orderBy']      ?? 'name');
        $orderDir      = strtoupper((string) ($this->element['orderDir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $this->options = parent::getOptions();

        $cacheKey = $componentName . '|' . $modelName . '|' . $columnValue . '|' . $columnText . '|' . $orderBy . '|' . $orderDir;

        // if (!isset(self::$cache[$cacheKey])) {
        self::$cache[$cacheKey] = $this->loadItems($componentName, $modelName, $columnValue, $columnText, $orderBy, $orderDir);
        // }

        foreach (self::$cache[$cacheKey] as $item) {
            $this->options[$modelName . '-' . $item['value']] = $item;
        }

        return $this->options;
    }

    protected function loadItems(string $componentName, string $modelName, string $columnValue, string $columnText, string $orderBy, string $orderDir): array
    {
        $app       = Factory::getApplication();
        $component = $app->bootComponent($componentName);
        $factory   = $component->getMVCFactory();
        $model     = $factory->createModel($modelName, 'Administrator');

        if (!$model) {
            return [];
        }

        // Force populateState() to run before our setState() calls,
        // otherwise it fires later inside getItems() and overwrites them
        // (especially list.limit, which falls back to the component default — 20).
        $model->getState('list.ordering');

        $model->setState('filter.state',   '*');
        $model->setState('filter.search',  '');
        $model->setState('list.limit',     0);
        $model->setState('list.start',     0);
        $model->setState('list.ordering',  $orderBy);
        $model->setState('list.direction', $orderDir);

        $modelItems = $model->getItems() ?: [];

        $items = [];

        foreach ($modelItems as $modelItem) {
            $items[] = [
                'value'   => $modelItem->{$columnValue} ?? '',
                'text'    => $modelItem->{$columnText}  ?? '',
                'disable' => false,
            ];
        }

        return $items;
    }
}