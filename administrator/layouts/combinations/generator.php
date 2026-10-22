<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

// No direct access
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * The generator popup body (lazy-loaded via item.fieldsPopup): pick pool
 * values per eligible choice field, Generate crosses ONLY them: additive,
 * idempotent, depth-locked dimension set. Option-duty fields are no
 * candidates and are not listed.
 *
 * @var array $displayData
 *   - array $choiceFields  Eligible choice fields with pools (+option_rows)
 *   - array $comboFieldIds The existing dimension set (empty = free choice)
 *   - int   $itemId
 */
$choiceFields = (array) $displayData['choiceFields'];
$comboFieldIds = (array) $displayData['comboFieldIds'];
$itemId = (int) $displayData['itemId'];

// Reordering (fields AND values) is allowed ONLY while no combinations exist;
// once a dimension set is generated the order is locked (delete to change),
// mirroring the depth lock. So all drag handles are suppressed when locked.
$locked = $comboFieldIds !== [];

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Resolve render state up-front: option-duty fields are no dimension
// candidates (skipped), the rest are either active dimensions or, once a
// set exists, locked out. The lock hint only earns its place when at least
// one field is actually greyed; otherwise it claims a constraint nobody sees.
$renderFields = [];
$hasLockedOut = false;

foreach ($choiceFields as $choiceField) {
    if (!empty($choiceField->option_rows)) {
        continue;
    }

    $lockedOut = $comboFieldIds && !in_array((int) $choiceField->id, $comboFieldIds, true);
    $hasLockedOut = $hasLockedOut || $lockedOut;
    $renderFields[] = [$choiceField, $lockedOut];
}
?>
<div class="alfa-combi-tools">
    <div data-generator data-item-id="<?php echo $itemId; ?>"
         data-locked="<?php echo $comboFieldIds ? '1' : '0'; ?>">
        <?php // The popup teaches the concept before the mechanics: a merchant who
              // opens it without having read the tour still learns what a variation is. ?>
        <p class="tools-lead"><?php echo Text::_('COM_ALFA_COMBI_GENERATE_LEAD'); ?></p>
        <p class="tools-hint"><?php echo Text::_('COM_ALFA_COMBI_GENERATE_HINT'); ?></p>
        <?php if ($comboFieldIds && $hasLockedOut) : ?>
            <p class="tools-hint tools-locked"><?php echo Text::_('COM_ALFA_COMBI_GENERATE_LOCKED_HINT'); ?></p>
        <?php endif; ?>
        <div class="tools-fields"<?php echo $locked ? '' : ' data-sortable-generator-fields'; ?>>
        <?php foreach ($renderFields as [$choiceField, $lockedOut]) :
            $fieldId = (int) $choiceField->id;
            // Sortable only when this field is an active dimension AND no set is locked.
            $canSort = !$lockedOut && !$locked;
            ?>
            <div class="tools-field<?php echo $lockedOut ? ' is-locked-out' : ''; ?>"
                 data-generator-field="<?php echo $fieldId; ?>"
                 <?php echo $lockedOut ? 'data-locked-out' : ($canSort ? 'data-sortable-pills' : ''); ?>>
                <span class="tools-label"<?php echo $canSort ? ' title="' . $esc(Text::_('JGLOBAL_ORDER')) . '"' : ''; ?>><?php if ($canSort) : ?><span class="field-drag icon-ellipsis-v" aria-hidden="true"></span> <?php endif; ?><?php echo $esc($choiceField->display_name); ?></span>
                <?php if ($lockedOut) : ?>
                    <span class="tools-field-hint"><?php echo Text::_('COM_ALFA_COMBI_GENERATE_FIELD_AS_OPTION'); ?></span>
                <?php endif; ?>
                <?php foreach ($choiceField->values as $poolValue) : ?>
                    <span class="value-pill">
                        <?php if ($canSort) : ?>
                            <span class="sortable-handler pill-drag" aria-hidden="true" title="<?php echo $esc(Text::_('JGLOBAL_ORDER')); ?>"><span class="icon-ellipsis-v"></span></span>
                        <?php endif; ?>
                        <input type="checkbox" value="<?php echo (int) $poolValue->id; ?>"
                               <?php echo $lockedOut ? 'disabled' : ''; ?>
                               id="generate-value-<?php echo $fieldId; ?>-<?php echo (int) $poolValue->id; ?>">
                        <label for="generate-value-<?php echo $fieldId; ?>-<?php echo (int) $poolValue->id; ?>">
                            <?php echo $esc($poolValue->display_name); ?>
                        </label>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        </div>
        <?php // Last in the body, so it sits right above the footer's Generate button: Generate writes the
              // combinations in its own request (item.generateCombinations), so they need no product save. A
              // product not saved yet has no id, and Generate creates nothing for it. ?>
        <p class="tools-hint mb-0"><?php echo Text::_($itemId > 0 ? 'COM_ALFA_COMBI_GENERATE_SAVED_AT_ONCE' : 'COM_ALFA_COMBI_GENERATE_SAVE_PRODUCT_FIRST'); ?></p>
    </div>
</div>
