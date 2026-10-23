<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\Traits\HasFailClosedOptionsTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Throwable;

class ModelField extends ListField
{
    use HasFailClosedOptionsTrait;

    protected $type = 'model';

    protected $options = [];

    /**
     * Per-request static cache, shared across all instances of this field.
     * Keyed by every XML attribute that affects the output, so different
     * field configurations on the same page don't collide.
     */
    protected static $cache = [];

    /**
     * Build options from an arbitrary component model, configurable via the
     * component / model / column_value / column_text / orderBy / orderDir XML
     * attributes. Results are cached per attribute combination for the request.
     *
     * @return array The option set keyed by '<model>-<value>'
     * @since  1.0.0
     */
    protected function getOptions()
    {
        [$componentName, $modelName, $columnValue, $columnText, $orderBy, $orderDir] = $this->modelArguments();

        $this->options = parent::getOptions();

        $cacheKey = $componentName . '|' . $modelName . '|' . $columnValue . '|' . $columnText . '|' . $orderBy . '|' . $orderDir;
        $items = $this->loadModelRows();

        if ($items === null) {
            $this->reportOptionsNotLoaded();

            return $this->options;
        }

        // if (!isset(self::$cache[$cacheKey])) {
        self::$cache[$cacheKey] = $items;
        // }

        foreach (self::$cache[$cacheKey] as $item) {
            $this->options[$modelName . '-' . $item['value']] = $item;
        }

        return $this->options;
    }

    /**
     * The options of the configured model, or null when they could not be loaded.
     *
     * @return array|null List of ['value' => ..., 'text' => ..., 'disable' => false], empty when there are none.
     *
     * @since  2.1.0
     */
    protected function loadModelRows(): ?array
    {
        return $this->loadItems(...$this->modelArguments());
    }

    /**
     * The XML attributes that choose and order the model, as strings.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}
     *                                                                                 The component, model, value column, text column, ordering column and direction.
     *
     * @since  2.1.0
     */
    private function modelArguments(): array
    {
        return [
            (string) ($this->element['component'] ?? 'com_alfa'),
            (string) ($this->element['model'] ?? 'places'),
            (string) ($this->element['column_value'] ?? 'id'),
            (string) ($this->element['column_text'] ?? 'name'),
            (string) ($this->element['orderBy'] ?? 'name'),
            strtoupper((string) ($this->element['orderDir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC',
        ];
    }

    /**
     * Boot the given component, load its list model with state forced to return
     * every record unfiltered and ordered, and map each row to a value/text/disable option.
     *
     * @param string $componentName The component to boot (e.g. 'com_alfa')
     * @param string $modelName The list model name to instantiate
     * @param string $columnValue Record property used as the option value
     * @param string $columnText Record property used as the option label
     * @param string $orderBy Column to order by
     * @param string $orderDir Order direction ('ASC' or 'DESC')
     *
     * @return array|null List of ['value' => ..., 'text' => ..., 'disable' => false]; null when the
     *                    model could not be created or its rows could not be loaded (since 2.1.0)
     * @since  1.0.0
     */
    protected function loadItems(string $componentName, string $modelName, string $columnValue, string $columnText, string $orderBy, string $orderDir): ?array
    {
        try {
            $app = Factory::getApplication();
            $component = $app->bootComponent($componentName);
            $factory = $component->getMVCFactory();
            $model = $factory->createModel($modelName, 'Administrator');

            if (!$model) {
                return null;
            }

            // Force populateState() to run before our setState() calls,
            // otherwise it fires later inside getItems() and overwrites them
            // (especially list.limit, which falls back to the component default: 20).
            $model->getState('list.ordering');

            $model->setState('filter.state', '*');
            $model->setState('filter.search', '');
            $model->setState('list.limit', 0);
            $model->setState('list.start', 0);
            $model->setState('list.ordering', $orderBy);
            $model->setState('list.direction', $orderDir);

            $modelItems = $model->getItems();
        } catch (Throwable) {
            return null;
        }

        // A list model answers false when its query failed: not the same as no records.
        if (!\is_array($modelItems)) {
            return null;
        }

        $items = [];

        foreach ($modelItems as $modelItem) {
            $items[] = [
                'value' => $modelItem->{$columnValue} ?? '',
                'text' => $modelItem->{$columnText} ?? '',
                'disable' => false,
            ];
        }

        return $items;
    }
}
