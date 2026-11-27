<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * One item field's contribution to a product slot, rendered as its plugin asked, unless the template takes it over.
 *
 * The template's html/layouts/com_alfa folder is searched first, the way Joomla's own fields are overridden:
 *
 *   item/field/<field name>.php   this one field, wherever it is placed (the field's machine name)
 *   item/field/<field type>.php   every field of that type (the plugin element: choice, ...)
 *
 * An override receives $displayData with:
 *   surface   the descriptor (layout, layoutData, layoutPluginType, layoutPluginName, fieldId, fieldName, fieldType)
 *   data      the plugin's display data for its layout (for a choice field: field, values, selected, ...)
 *   field     the item's field entry ($item->fields[<name>]: label, value, placement, render(), the definition)
 *   item      the product (or the card's item)
 *   slot      the slot being rendered (an ItemSurface value: title, price, specifications-section, ...)
 *
 * Without an override the plugin's own layout renders, with the data the plugin set (the item when it set none).
 *
 * @var array $displayData ['surface' => array, 'slot' => string, 'item' => object|null]
 */

defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\ItemFieldsHelper;
use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Layout\FileLayout;

$surface = (array) ($displayData['surface'] ?? []);
$item    = $displayData['item'] ?? null;
$slot    = (string) ($displayData['slot'] ?? '');

if (empty($surface['layout'])) {
    return;
}

$data = !empty($surface['layoutData']) ? $surface['layoutData'] : $item;

// A file name only ever holds letters, digits, underscores and hyphens: anything else in a name is dropped.
$candidates = array_filter(array_unique(array_map(
    static fn (mixed $name): string => preg_replace('/[^A-Za-z0-9_-]/', '', (string) $name) ?? '',
    [$surface['fieldName'] ?? '', $surface['fieldType'] ?? ''],
)));

foreach ($candidates as $candidate) {
    $override = new FileLayout('item.field.' . $candidate, null, ['component' => 'com_alfa', 'client' => 'site']);

    foreach ($override->getIncludePaths() as $path) {
        if (!is_file($path . '/item/field/' . $candidate . '.php')) {
            continue;
        }

        $name = (string) ($surface['fieldName'] ?? '');
        $field = \is_object($item) ? (($item->fields[$name] ?? null) ?: ItemFieldsHelper::find($item, (int) ($surface['fieldId'] ?? 0))) : null;

        echo $override->render([
            'surface' => $surface,
            'data' => $data,
            'field' => $field,
            'item' => $item,
            'slot' => $slot,
        ]);

        return;
    }
}

echo Layout::render(
    $surface['layout'],
    $data,
    $surface['layoutPluginType'] ?? '',
    $surface['layoutPluginName'] ?? '',
);
