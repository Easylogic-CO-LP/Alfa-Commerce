<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Alfa\Component\Alfa\Administrator\Field\AlfapricesField;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Alfa\Component\Alfa\Administrator\Service\Inventory\BackorderPolicy;
use Alfa\Component\Alfa\Administrator\Service\Inventory\InventoryAvailabilityService;
use Alfa\Component\Alfa\Administrator\Service\Inventory\QuantityDecimal;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * The Combination Studio body: identity, prices (full matrix + adjust
 * deltas), logistics overrides (blank = inherit) and per-language text
 * overrides using the item form's REAL MultilingualEditor fields. The
 * variant's own gallery renders in the popup template around this layout.
 *
 * @var array $displayData
 *   - object $combination Combination row (+price_rows, media_ids, translations, value labels)
 *   - array  $descriptors Override descriptors (ItemFieldsAdminHelper::getCombinationOverrideDescriptors)
 *   - array  $languages   Content languages [{tag,key,title}]
 *   - object $escaper     View for ->escape()
 */
$combination = $displayData['combination'];
$descriptors = (array) $displayData['descriptors'];
$languages = (array) $displayData['languages'];
$view = $displayData['escaper'];

$combinationId = (int) $combination->id;
$base = 'jform[combinations][' . $combinationId . ']';

// Parent item values shown as placeholders (a blank combination field inherits
// the item); falls back to the given text when the item has no value.
$item = $displayData['item'] ?? null;
$parentPlaceholder = static function (string $field, string $fallback) use ($item, $view): string {
    $value = $item->{$field} ?? null;

    return ($value !== null && $value !== '') ? $view->escape($value) : $fallback;
};

$scalarFields = array_filter($descriptors, static fn ($d) => !$d->multilingual);
$textFields = array_filter($descriptors, static fn ($d) => $d->multilingual);

// Distribute the translatable fields like the main product: Name on TOP, the
// stock messages with the Logistics/Stock group, the rest (descriptions) in General.
$stockMessageNames = ['stock_low_message', 'stock_zero_message'];
$nameDescriptor = null;
$descFields = [];
$stockMessageFields = [];

foreach ($textFields as $descriptor) {
    if ($descriptor->name === 'name') {
        $nameDescriptor = $descriptor;
    } elseif (in_array($descriptor->name, $stockMessageNames, true)) {
        $stockMessageFields[] = $descriptor;
    } else {
        $descFields[] = $descriptor;
    }
}

// The variant's own gallery form (built by the wrapper) renders in the Media tab.
$galleryForm = $displayData['galleryForm'] ?? null;

// Split the scalar overrides into the SAME captioned groups the main product
// uses (Dimensions vs everything-stock), so Logistics reads identically.
$dimensionNames = ['weight', 'width', 'height', 'depth'];
$stockOverrides = [];
$dimensionOverrides = [];

// A virtual product ships nothing, so its variants have no dimensions to
// override. Decided HERE rather than with a showon rule: this popup emits raw
// HTML and deliberately ignores showon (core showon.js crashes on missing
// targets). Not rendering them also leaves the columns alone on save; the
// persist loop skips keys the editor never posted.
$itemIsVirtual = !empty($displayData['item']->is_virtual);

foreach ($scalarFields as $descriptor) {
    if (in_array($descriptor->name, $dimensionNames, true)) {
        if ($itemIsVirtual) {
            continue;
        }

        $dimensionOverrides[] = $descriptor;
    } else {
        $stockOverrides[] = $descriptor;
    }
}

// Quantity rules are exact decimals, shown without trailing zeros. A rule of
// exactly zero follows the item like a blank one, so it shows blank.
$quantityRules = ['quantity_min', 'quantity_max', 'quantity_step'];
$quantityRuleText = static function (mixed $value): string {
    $rule = QuantityDecimal::parse(value: $value);

    return ($rule === null || QuantityDecimal::isZero(a: $rule)) ? '' : QuantityDecimal::display(a: $rule);
};

// One override control (select for option fields, number otherwise), blank =
// inherit the item (its value shows as the placeholder).
$renderOverride = static function (object $descriptor) use ($base, $view, $combination, $parentPlaceholder, $item, $quantityRules, $quantityRuleText): string {
    $name = $descriptor->name;
    $current = $combination->{$name};

    $label = '<label class="form-label small mb-0 text-muted" title="' . $view->escape($descriptor->description) . '">'
        . $view->escape($descriptor->label) . '</label>';

    if ($descriptor->options) {
        // "Inherit" posts a RESERVED TOKEN, not '': `backorder`'s own option list
        // already contains an empty value meaning "follow the shop", and two
        // value="" entries would be one control with two different meanings,
        // indistinguishable to the merchant and identical to the persist layer.
        $control = '<select class="form-select form-select-sm" name="' . $base . '[' . $name . ']">'
            . '<option value="' . BackorderPolicy::INHERIT_TOKEN . '"' . ($current === null ? ' selected' : '') . '>'
            . Text::_('COM_ALFA_COMBINATION_OVR_INHERIT') . '</option>';

        foreach ($descriptor->options as $option) {
            $selected = $current !== null && (string) $current === (string) $option->value ? ' selected' : '';
            $control .= '<option value="' . $view->escape($option->value) . '"' . $selected . '>' . $view->escape($option->text) . '</option>';
        }

        $control .= '</select>';
    } elseif (\in_array($name, $quantityRules, true)) {
        $parentRule = $quantityRuleText(value: $item->{$name} ?? null);
        $control = '<input type="number" step="any" class="form-control form-control-sm" name="' . $base . '[' . $name . ']"'
            . ' value="' . $view->escape(var: $quantityRuleText(value: $current)) . '"'
            . ' placeholder="' . ($parentRule !== '' ? $view->escape(var: $parentRule) : Text::_('COM_ALFA_COMBINATION_OVR_INHERIT')) . '">';
    } else {
        $control = '<input type="number" step="any" class="form-control form-control-sm" name="' . $base . '[' . $name . ']"'
            . ' value="' . ($current !== null ? $view->escape($current) : '') . '"'
            . ' placeholder="' . $parentPlaceholder($name, Text::_('COM_ALFA_COMBINATION_OVR_INHERIT')) . '">';
    }

    return '<div>' . $label . $control . '</div>';
};

// REAL item-form fields for the text overrides: same MultilingualEditor UX as
// the item itself. Requiredness/defaults are stripped: blank = inherit.
$textForm = new Form('com_alfa.combination.texts.' . $combinationId, ['control' => $base]);
$textForm->loadFile(JPATH_ADMINISTRATOR . '/components/com_alfa/forms/item.xml', false);

foreach ($textFields as $descriptor) {
    $textForm->setFieldAttribute($descriptor->name, 'required', 'false');
    $textForm->setFieldAttribute($descriptor->name, 'default', '');
    // Strip showon rules inherited from item.xml; their switch fields are
    // not part of this form, and core showon.js crashes on missing targets.
    $textForm->setFieldAttribute($descriptor->name, 'showon', '');
    // Point the editor at THIS combination's translations, not the item's: its
    // setup() reads flat lang values straight from the DB by the form's id, so
    // it must read #__alfa_item_combinations_<lang>, not #__alfa_items_<lang>.
    $textForm->setFieldAttribute($descriptor->name, 'multilingual_table', '#__alfa_item_combinations');
    $textForm->setFieldAttribute($descriptor->name, 'multilingual_pk', 'id_item_combination');
}

// The field loads its saved values from the DB keyed by the form's 'id' (the
// per-field bind is ignored by setup()), so aim the form at this combination.
$textForm->bind(['id' => $combinationId]);

// Field-plugin action buttons for THIS variant (onGetFieldActions, context
// 'combination'): every field participating in the combination may offer
// buttons, same pipeline as the order payment actions.
$combinationActions = [];

try {
    $participatingFieldIds = \Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper::getCombinationFieldIds(
        itemId: (int) $combination->item_id,
    );

    if ($participatingFieldIds) {
        $db = \Joomla\CMS\Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from($db->qn('#__alfa_item_fields'))
                ->whereIn($db->qn('id'), $participatingFieldIds),
        );

        foreach ($db->loadObjectList() ?: [] as $participatingField) {
            foreach (
                \Alfa\Component\Alfa\Administrator\Helper\ActionRegistry::getItemFieldActions(
                    field: $participatingField,
                    scope: 'combination',
                    scopeId: $combinationId,
                    itemId: (int) $combination->item_id,
                ) as $combinationAction
            ) {
                $combinationActions[] = ['field' => $participatingField, 'action' => $combinationAction];
            }
        }
    }
} catch (\Throwable $actionError) {
    $combinationActions = [];
}
?>
<div class="studio-body">

    <?php if ($combinationActions) : ?>
        <div class="studio-section d-flex align-items-center flex-wrap gap-2">
            <span class="fw-semibold text-muted me-1">
                <span class="icon-cogs" aria-hidden="true"></span> <?php echo Text::_('COM_ALFA_FIELD_ACTIONS'); ?>:
            </span>
            <?php
            $actionButton = new \Joomla\CMS\Layout\FileLayout('actions.button', JPATH_ADMINISTRATOR . '/components/com_alfa/layouts');

            foreach ($combinationActions as $entry) {
                echo $actionButton->render([
                    'action' => $entry['action'],
                    'context' => 'itemfield',
                    'id' => (int) $entry['field']->id,
                    'data' => [
                        'scope' => 'combination',
                        'scope_id' => $combinationId,
                        'item_id' => (int) $combination->item_id,
                    ],
                ]);
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if ($nameDescriptor !== null) : ?>
        <div class="studio-section studio-name form-vertical">
            <?php echo $textForm->renderField('name'); ?>
        </div>
    <?php endif; ?>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'combinationStudioTabs', ['active' => 'combo-general', 'recall' => false]); ?>

    <?php // General: identity + descriptions?>
    <?php echo HTMLHelper::_('uitab.addTab', 'combinationStudioTabs', 'combo-general', Text::_('COM_ALFA_TAB_ITEM')); ?>
        <div class="studio-section">
            <h5><?php echo Text::_('COM_ALFA_COMBINATION_IDENTITY'); ?></h5>
            <div class="studio-grid">
                <div>
                    <label class="form-label small mb-0 text-muted"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_SKU'); ?></label>
                    <input type="text" class="form-control form-control-sm" name="<?php echo $base; ?>[sku]"
                           value="<?php echo $view->escape($combination->sku); ?>"
                           placeholder="<?php echo $parentPlaceholder('sku', '-'); ?>">
                </div>
                <div>
                    <label class="form-label small mb-0 text-muted"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_GTIN'); ?></label>
                    <input type="text" class="form-control form-control-sm" name="<?php echo $base; ?>[gtin]"
                           value="<?php echo $view->escape($combination->gtin); ?>"
                           placeholder="<?php echo $parentPlaceholder('gtin', '-'); ?>">
                </div>
                <div>
                    <label class="form-label small mb-0 text-muted"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_MPN'); ?></label>
                    <input type="text" class="form-control form-control-sm" name="<?php echo $base; ?>[mpn]"
                           value="<?php echo $view->escape($combination->mpn); ?>"
                           placeholder="<?php echo $parentPlaceholder('mpn', '-'); ?>">
                </div>
            </div>
        </div>
        <?php if ($descFields) : ?>
            <div class="studio-section form-vertical">
                <h5><?php echo Text::_('COM_ALFA_COMBINATION_TEXTS'); ?></h5>
                <p class="studio-hint"><?php echo Text::_('COM_ALFA_COMBINATION_TEXTS_DESC'); ?></p>
                <?php foreach ($descFields as $descriptor) : ?>
                    <?php echo $textForm->renderField($descriptor->name); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php // Logistics: stock then dimensions, mirroring the main product?>
    <?php echo HTMLHelper::_('uitab.addTab', 'combinationStudioTabs', 'combo-logistics', Text::_('COM_ALFA_TAB_ITEM_LOGISTICS')); ?>
        <div class="studio-section">
            <div class="row">
                <div class="col-lg-6">
                        <?php
                        // Stock per location, read from the stock records: every enabled
                        // location plus any other location still holding stock. A variant
                        // that tracks stock renders each figure with a hidden copy of it, so
                        // the save applies only the figures the operator changed and never
                        // puts back units sold while the Studio was open. A variant that does
                        // not track stock renders EMPTY opening inputs: switching tracking on
                        // needs the quantity on hand at every one of them (0 included), and
                        // what is recorded only shows as a hint.
                        $locDb = Factory::getContainer()->get('DatabaseDriver');
                        $stockLocations = (new InventoryAvailabilityService())->locationBreakdown(
                            itemId: (int) $item->id,
                            combinationId: (int) $combination->id,
                        );
                        $tracksStock = (int) ($combination->manage_stock ?? $item->manage_stock ?? 0) === 1;
                        $hasBreakdown = \count($stockLocations) > 1;
                        $recordedStock = 0.0;

                        foreach ($stockLocations as $stockLocation) {
                            $recordedStock += (float) $stockLocation['physical'];
                        }

                        $figureOf = static fn (float $quantity): string => $view->escape(InventoryAvailabilityService::formatQuantity(value: $quantity));
                        ?>
                        <?php if ($hasBreakdown) : ?>
                            <table class="table table-sm mt-2 mb-1">
                                <thead><tr>
                                    <th><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_LOCATION'); ?></th>
                                    <th class="w-25 text-end"><?php echo Text::_('COM_ALFA_FORM_LBL_ITEM_STOCK'); ?></th>
                                    <th class="w-25 text-end"><?php echo Text::_('COM_ALFA_LOCATION_HEADING_RESERVED'); ?></th>
                                    <th class="w-25 text-end"><?php echo Text::_('COM_ALFA_INVENTORY_HEADING_AVAILABLE'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($stockLocations as $stockLocation) : ?>
                                    <?php $stockLocationId = (int) $stockLocation['location_id']; ?>
                                    <tr>
                                        <td><?php echo $view->escape($stockLocation['title']); ?>
                                            <?php if ($stockLocation['is_default']) : ?>
                                                <span class="badge bg-primary"><?php echo Text::_('COM_ALFA_LOCATION_BADGE_DEFAULT'); ?></span>
                                            <?php endif; ?>
                                            <?php if (!$stockLocation['published']) : ?>
                                                <span class="badge bg-secondary"><?php echo Text::_('COM_ALFA_LOCATION_BADGE_DISABLED'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($tracksStock) : ?>
                                                <input type="number" step="any" class="form-control form-control-sm text-end"
                                                       name="jform[combination_inventory_stock][<?php echo $stockLocationId; ?>]"
                                                       value="<?php echo $figureOf((float) $stockLocation['physical']); ?>">
                                                <input type="hidden"
                                                       name="jform[combination_inventory_stock_baseline][<?php echo $stockLocationId; ?>]"
                                                       value="<?php echo $figureOf((float) $stockLocation['physical']); ?>">
                                            <?php else : ?>
                                                <input type="number" step="any" class="form-control form-control-sm text-end"
                                                       name="jform[combination_opening_stock][<?php echo $stockLocationId; ?>]"
                                                       value="" placeholder="<?php echo $figureOf((float) $stockLocation['physical']); ?>">
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-muted"><?php echo $figureOf((float) $stockLocation['reserved']); ?></td>
                                        <td class="text-end text-muted"><?php echo $stockLocation['published'] ? $figureOf((float) $stockLocation['available']) : '&mdash;'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div class="form-text">
                                <?php echo $tracksStock
                                    ? Text::_('COM_ALFA_INVENTORY_STOCK_BREAKDOWN_HINT')
                                    : $view->escape(Text::sprintf('COM_ALFA_INVENTORY_TRACKING_OPENING_HINT', InventoryAvailabilityService::formatQuantity(value: $recordedStock))); ?>
                            </div>
                        <?php elseif ($stockLocations !== []) : ?>
                            <?php
                            $soleLocation = $stockLocations[0];
                            $soleLocationId = (int) $soleLocation['location_id'];
                            ?>
                            <div class="mb-2">
                                <label class="form-label small mb-0 text-muted"><?php echo Text::_('COM_ALFA_ITEM_COMBINATIONS_STOCK'); ?></label>
                                <?php if ($tracksStock) : ?>
                                    <input type="number" step="any" class="form-control form-control-sm"
                                           name="jform[combination_inventory_stock][<?php echo $soleLocationId; ?>]"
                                           value="<?php echo $figureOf((float) $soleLocation['physical']); ?>">
                                    <input type="hidden"
                                           name="jform[combination_inventory_stock_baseline][<?php echo $soleLocationId; ?>]"
                                           value="<?php echo $figureOf((float) $soleLocation['physical']); ?>">
                                    <div class="form-text">
                                        <?php echo Text::_('COM_ALFA_INVENTORY_ABBR_RESERVED'); ?> <?php echo $figureOf((float) $soleLocation['reserved']); ?>
                                        - <?php echo Text::_('COM_ALFA_INVENTORY_ABBR_AVAILABLE'); ?> <?php echo $soleLocation['published'] ? $figureOf((float) $soleLocation['available']) : '&mdash;'; ?>
                                    </div>
                                <?php else : ?>
                                    <input type="number" step="any" class="form-control form-control-sm"
                                           name="jform[combination_opening_stock][<?php echo $soleLocationId; ?>]"
                                           value="" placeholder="<?php echo $figureOf((float) $soleLocation['physical']); ?>">
                                    <div class="form-text">
                                        <?php echo $view->escape(Text::sprintf('COM_ALFA_INVENTORY_TRACKING_OPENING_HINT', InventoryAvailabilityService::formatQuantity(value: $recordedStock))); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="studio-grid">
                            <?php foreach ($stockOverrides as $descriptor) : ?>
                                <?php echo $renderOverride($descriptor); ?>
                            <?php endforeach; ?>
                        </div>

                        <?php // Dimensions follow the stock policy in one flow: one
                              // variant, one logistics panel. Absent for a virtual
                              // product, whose variants ship nothing. ?>
                        <?php if ($dimensionOverrides) : ?>
                            <div class="studio-grid mt-2">
                                <?php foreach ($dimensionOverrides as $descriptor) : ?>
                                    <?php echo $renderOverride($descriptor); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($stockMessageFields) : ?>
                            <div class="form-vertical mt-2">
                                <?php foreach ($stockMessageFields as $descriptor) : ?>
                                    <?php echo $textForm->renderField($descriptor->name); ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                </div>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php // Pricing?>
    <?php echo HTMLHelper::_('uitab.addTab', 'combinationStudioTabs', 'combo-pricing', Text::_('COM_ALFA_TAB_ITEM_PRICING')); ?>
        <div class="studio-section">
            <p class="studio-hint"><?php echo Text::_('COM_ALFA_COMBINATION_PRICES_DESC'); ?></p>
            <?php echo AlfapricesField::renderPrices(
                controlBase: 'jform[combination_prices][' . $combinationId . ']',
                rows: (array) ($combination->price_rows ?? []),
                showModify: true,
                itemId: (int) $combination->item_id,
            ); ?>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php // Media: the variant's own gallery?>
    <?php if ($galleryForm !== null) : ?>
        <?php echo HTMLHelper::_('uitab.addTab', 'combinationStudioTabs', 'combo-media', Text::_('COM_ALFA_MEDIA_TAB_ITEM_LABEL')); ?>
            <div class="studio-section">
                <p class="studio-hint"><?php echo Text::_('COM_ALFA_COMBINATION_GALLERY_DESC'); ?></p>
                <?php echo $galleryForm->renderField('medias'); ?>
            </div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>
    <?php endif; ?>

    <?php // Downloads: inherited item files + this variant's own?>
    <?php echo HTMLHelper::_('uitab.addTab', 'combinationStudioTabs', 'combo-downloads', Text::_('COM_ALFA_TAB_ITEM_DOWNLOADS')); ?>
        <?php
        $allDownloads = $locDb->setQuery(
            $locDb->getQuery(true)
                ->select('*')
                ->from($locDb->quoteName('#__alfa_item_downloads'))
                ->where($locDb->quoteName('item_id') . ' = ' . (int) $item->id)
                ->order($locDb->quoteName('ordering') . ' ASC, ' . $locDb->quoteName('id') . ' ASC'),
        )->loadObjectList() ?: [];
        $inheritedDownloads = array_values(array_filter($allDownloads, static fn ($d) => empty($d->combination_id)));
        $ownDownloads = array_values(array_filter($allDownloads, static fn ($d) => (int) $d->combination_id === (int) $combination->id));
        ?>
        <div class="studio-section" id="combination-downloads"
             data-item-id="<?php echo (int) $item->id; ?>"
             data-combination-id="<?php echo (int) $combination->id; ?>">

            <?php if ($inheritedDownloads) : ?>
                <h5><?php echo Text::_('COM_ALFA_DOWNLOADS_INHERITED'); ?></h5>
                <p class="studio-hint"><?php echo Text::_('COM_ALFA_DOWNLOADS_INHERITED_DESC'); ?></p>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($inheritedDownloads as $download) : ?>
                        <li class="text-muted">
                            <?php echo $view->escape($download->title ?: $download->display_filename); ?>
                            <small>(<?php echo $view->escape($download->display_filename); ?>)</small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h5><?php echo Text::_('COM_ALFA_DOWNLOADS_OWN'); ?></h5>
            <p class="studio-hint"><?php echo Text::_('COM_ALFA_DOWNLOADS_OWN_DESC'); ?></p>
            <?php if (!$ownDownloads) : ?>
                <p class="text-muted"><?php echo Text::_('COM_ALFA_DOWNLOADS_NONE'); ?></p>
            <?php else : ?>
                <table class="table table-sm align-middle">
                    <thead><tr>
                        <th><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_TITLE'); ?></th>
                        <th><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_FILE'); ?></th>
                        <th class="w-10"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_MAX'); ?></th>
                        <th class="w-10"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_DAYS'); ?></th>
                        <th class="w-10"><?php echo Text::_('JSTATUS'); ?></th>
                        <th class="w-25"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_ACTIONS'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($ownDownloads as $download) : ?>
                        <?php $rowName = 'jform[item_downloads][' . (int) $download->id . ']'; ?>
                        <tr data-download-id="<?php echo (int) $download->id; ?>">
                            <td>
                                <input type="hidden" name="<?php echo $rowName; ?>[combination_id]" value="<?php echo (int) $combination->id; ?>">
                                <input type="text" class="form-control form-control-sm" name="<?php echo $rowName; ?>[title]"
                                       value="<?php echo $view->escape((string) $download->title); ?>">
                            </td>
                            <td>
                                <span class="d-block text-truncate" style="max-width:180px;"><?php echo $view->escape((string) $download->display_filename); ?></span>
                            </td>
                            <td><input type="number" min="0" class="form-control form-control-sm" name="<?php echo $rowName; ?>[max_downloads]" value="<?php echo (int) $download->max_downloads; ?>"></td>
                            <td><input type="number" min="0" class="form-control form-control-sm" name="<?php echo $rowName; ?>[days_valid]" value="<?php echo (int) $download->days_valid; ?>"></td>
                            <td>
                                <select class="form-select form-select-sm" name="<?php echo $rowName; ?>[state]">
                                    <option value="1" <?php echo (int) $download->state === 1 ? 'selected' : ''; ?>><?php echo Text::_('JPUBLISHED'); ?></option>
                                    <option value="0" <?php echo (int) $download->state !== 1 ? 'selected' : ''; ?>><?php echo Text::_('JUNPUBLISHED'); ?></option>
                                </select>
                            </td>
                            <td class="text-nowrap">
                                <label class="btn btn-sm btn-outline-secondary mb-0">
                                    <?php echo Text::_('COM_ALFA_DOWNLOADS_REPLACE_FILE'); ?>
                                    <input type="file" class="d-none" data-action="replace">
                                </label>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete">
                                    <?php echo Text::_('JACTION_DELETE'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div class="row g-2 align-items-end mt-1">
                <div class="col-md-4">
                    <label class="form-label" for="combo-download-file"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_FILE'); ?></label>
                    <input type="file" class="form-control" id="combo-download-file">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="combo-download-title"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_TITLE'); ?></label>
                    <input type="text" class="form-control" id="combo-download-title"
                           placeholder="<?php echo Text::_('COM_ALFA_DOWNLOADS_TITLE_PLACEHOLDER'); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="combo-download-max"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_MAX'); ?></label>
                    <input type="number" min="0" value="0" class="form-control" id="combo-download-max">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="combo-download-days"><?php echo Text::_('COM_ALFA_DOWNLOADS_COL_DAYS'); ?></label>
                    <input type="number" min="0" value="0" class="form-control" id="combo-download-days">
                </div>
                <div class="col-12">
                    <button type="button" class="btn btn-success" id="combo-download-upload">
                        <?php echo Text::_('COM_ALFA_DOWNLOADS_UPLOAD_BUTTON'); ?>
                    </button>
                    <small class="text-muted ms-2">
                        <?php echo Text::sprintf('COM_ALFA_DOWNLOADS_MAX_UPLOAD_HINT', ini_get('upload_max_filesize')); ?>
                        <?php echo Text::_('COM_ALFA_DOWNLOADS_LIMITS_HINT'); ?>
                    </small>
                </div>
            </div>
        </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>

</div>
