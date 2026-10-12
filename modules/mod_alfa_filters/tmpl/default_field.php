<?php

/**
 * One item-field facet, drawn in the mode the field's plugin params ask for
 * (params.filter_render_as -> $renderAs).
 *
 * Every mode submits the SAME control name and the same value ids (
 * filter[fields][{fieldId}][]), so the filter contract, the FormData collection
 * in filters.js and the delegated change listener are identical across modes.
 * checkboxes / buttons / swatches all keep a native <input type="checkbox">
 * (visually hidden in the latter two, never display:none, so keyboard focus and
 * screen readers keep working); only 'select' swaps the control, and it is a
 * MULTIPLE select because a facet's selection is an array; filters.js dresses it
 * with Choices.js, the markup itself stays the native control.
 *
 * @var array $fieldValues  Value rows: [id => ['id','name','count','params']].
 * @var array $activeValues Selected value ids for this field (ints).
 * @var int $fieldId      The item field's id.
 * @var string $fieldLabel   Translated field label.
 * @var string $renderAs     checkboxes|buttons|swatches|select.
 * @var int $moduleId     Module instance id; keeps every DOM id unique.
 */

\defined('_JEXEC') or die;

use Alfa\Component\Alfa\Site\Helper\Layout;
use Joomla\CMS\Language\Text;

if (isset($displayData)) {
    extract($displayData);
}

$fieldValues ??= [];
$activeValues ??= [];
$fieldId ??= 0;
$fieldLabel ??= '';
$renderAs ??= 'checkboxes';
$moduleId ??= 0;
$groups ??= [];
$activeGroups ??= [];
$fieldType ??= '';
$groupValues ??= false;   // already resolved by default_filters

// Grouping only happens where there is something to group. A field whose values carry no
// colours renders exactly as it always did, so switching the setting on cannot empty a
// panel that has no colour data behind it.
$facetGroups = $groupValues ? (array) ($groups['groups'] ?? []) : [];
$ungroupedIds = $groupValues ? array_map('intval', (array) ($groups['ungrouped'] ?? [])) : [];

if (!empty($facetGroups)) {
    // Grouped values are represented by their group chips; the values belonging to NO
    // group stay listed as ordinary value chips. An ungrouped value is never dropped:
    // turning grouping on must not make part of a facet unreachable.
    $fieldValues = array_intersect_key($fieldValues, array_flip($ungroupedIds));
}

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$inputName = 'filter[fields][' . (int) $fieldId . '][]';

// The chip style comes from the shared renderer. The module never inspects a field
// type's storage: the facet already carries the canonical colour codes and the image
// that the TYPE reported for each value, so the same call works for any field type.
// The chip INTERIOR always belongs to the field type, on group chips and on plain value
// chips alike. The module draws the control and the label; the type names a layout and the
// shared plugin-layout renderer draws inside it. A type that names none simply gets a label.
$chipInterior = static function (array $source) use ($fieldType): string {
    if (empty($source['layout'])) {
        return '';
    }

    return Layout::render(
        (string) $source['layout'],
        (array) ($source['layoutData'] ?? []),
        'alfa-item-fields',
        $fieldType,
    );
};

?>
<?php if (!empty($facetGroups)) : ?>
    <ul class="af-list af-chip-list af-facet-groups" aria-label="<?= $esc($fieldLabel) ?>">
        <?php $gIndex = 0; ?>
        <?php foreach ($facetGroups as $token => $group) : ?>
            <?php
            $gIndex++;
            $token = (string) $token;
            $isChecked = \in_array($token, array_map('strval', $activeGroups), true);
            $inputId = 'af-' . $moduleId . '-group-' . (int) $fieldId . '-' . preg_replace('/[^a-z0-9]/i', '', $token);
            ?>
            <li class="af-item af-item-<?= $gIndex ?> depth-0" data-group-type="<?= $esc($group['type'] ?? '') ?>">
                <?php // The MODULE owns every state-carrying attribute here: the control,
                      // its name, the token and the field id. A field type only ever draws
                      // the chip interior below, so nothing it returns can reach these.?>
                <input
                    class="af-chip-input"
                    type="checkbox"
                    id="<?= $esc($inputId) ?>"
                    name="filter[groups][<?= (int) $fieldId ?>][]"
                    value="<?= $esc($token) ?>"
                    <?= $isChecked ? 'checked' : '' ?>
                >
                <label class="af-chip" for="<?= $esc($inputId) ?>" title="<?= $esc($group['label'] ?? '') ?>">
                    <?php
                    // The chip INTERIOR belongs to the field type, drawn through the shared
                    // plugin-layout renderer exactly as the item page and the catalogue card
                    // draw this type's selectors. A type that names no layout simply gets
                    // the label, so the panel never depends on one being present.
                    echo $chipInterior($group);
            ?>
                    <span class="af-chip-name"><?= $esc($group['label'] ?? '') ?></span>
                    <?php if (!empty($group['count'])) : ?>
                        <span class="af-count">(<?= (int) $group['count'] ?>)</span>
                    <?php endif; ?>
                </label>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (empty($fieldValues)) : ?>
    <?php return; ?>
<?php endif; ?>

<?php if ($renderAs === 'select') : ?>
    <?php $selectId = 'af-' . $moduleId . '-field-' . (int) $fieldId; ?>
    <?php // A plain multiple select. filters.js dresses it with Choices.js (Joomla's own
          // vendor copy, loaded by the dispatcher only when a facet renders this way)
          // and without JS the native control stays. The strings Choices needs travel
          // on data attributes from the MODULE's own keys, so a shop can override them
          // for Alfa alone: the layout owns every word, the script authors none.?>
    <select class="af-field-select"
            id="<?= $esc($selectId) ?>"
            name="<?= $esc($inputName) ?>"
            multiple
            aria-label="<?= $esc($fieldLabel) ?>"
            data-placeholder="<?= $esc(Text::_('MOD_ALFAFILTERS_FILTER_SELECT_PLACEHOLDER')) ?>"
            data-no-results="<?= $esc(Text::_('MOD_ALFAFILTERS_FILTER_SELECT_NO_RESULTS')) ?>">
        <?php foreach ($fieldValues as $valueId => $value) : ?>
            <?php $valueId = (int) $valueId; ?>
            <option value="<?= $valueId ?>"<?= \in_array($valueId, $activeValues, true) ? ' selected' : '' ?>>
                <?= $esc($value['name'] ?? '') ?><?= !empty($value['count']) ? ' (' . (int) $value['count'] . ')' : '' ?>
            </option>
        <?php endforeach; ?>
    </select>

<?php elseif ($renderAs === 'buttons' || $renderAs === 'swatches') : ?>
    <?php // A group of native checkboxes skinned as chips. The <ul>/<li> shape is
          // kept so the list primitives and the reset logic stay identical.?>
    <ul class="af-list af-chip-list" aria-label="<?= $esc($fieldLabel) ?>">
        <?php $vIndex = 0; ?>
        <?php foreach ($fieldValues as $valueId => $value) : ?>
            <?php
            $vIndex++;
            $valueId = (int) $valueId;
            $isChecked = \in_array($valueId, $activeValues, true);
            $inputId = 'af-' . $moduleId . '-field-' . (int) $fieldId . '-' . $valueId;
            $interior = $renderAs === 'swatches' ? $chipInterior($value) : '';
            ?>
            <li class="af-item af-item-<?= $vIndex ?> depth-0" data-id="<?= $valueId ?>">
                <?php // The input is a SIBLING of the chip, not a child: the checked/focus
                      // skin is driven by `input:checked + .af-chip`. :has() would read
                      // better but its :checked invalidation is unreliable, so a chip
                      // could stay unpainted until the next AJAX re-render.?>
                <input
                    class="af-chip-input"
                    type="checkbox"
                    id="<?= $esc($inputId) ?>"
                    name="<?= $esc($inputName) ?>"
                    value="<?= $valueId ?>"
                    <?= $isChecked ? 'checked' : '' ?>
                >
                <label class="af-chip" for="<?= $esc($inputId) ?>" title="<?= $esc($value['name'] ?? '') ?>">
                    <?php if ($renderAs === 'swatches') : ?>
                        <?= $interior !== '' ? $interior : '<span class="af-chip-swatch" data-empty="1"></span>' ?>
                    <?php endif; ?>
                    <span class="af-chip-name"><?= $esc($value['name'] ?? '') ?></span>
                    <?php if (!empty($value['count'])) : ?>
                        <span class="af-count">(<?= (int) $value['count'] ?>)</span>
                    <?php endif; ?>
                </label>
            </li>
        <?php endforeach; ?>
    </ul>

<?php else : ?>
    <?php // checkboxes: the original markup, unchanged.?>
    <ul class="af-list">
        <?php $vIndex = 0; ?>
        <?php foreach ($fieldValues as $valueId => $value) : ?>
            <?php
            $vIndex++;
            $valueId = (int) $valueId;
            $isChecked = \in_array($valueId, $activeValues, true);
            $inputId = 'af-' . $moduleId . '-field-' . (int) $fieldId . '-' . $valueId;
            ?>
            <li class="af-item af-item-<?= $vIndex ?> depth-0" data-id="<?= $valueId ?>">
                <div class="af-option">
                    <input
                        type="checkbox"
                        id="<?= $esc($inputId) ?>"
                        name="<?= $esc($inputName) ?>"
                        value="<?= $valueId ?>"
                        <?= $isChecked ? 'checked' : '' ?>
                    >
                    <label class="af-label" for="<?= $esc($inputId) ?>"><?= $esc($value['name'] ?? '') ?></label>
                    <?php if (!empty($value['count'])) : ?>
                        <span class="af-count">(<?= (int) $value['count'] ?>)</span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
