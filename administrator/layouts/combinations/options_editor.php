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
 * The Options PICKER popup body (lazy-loaded via item.fieldsPopup),
 * generator-style: tick the values to offer, Add creates ONLY the missing
 * option rows (additive, idempotent, no delta). Deltas are edited inline in
 * the Options list; removal happens there too. Already-offered values render
 * checked and disabled.
 *
 * @var array $displayData
 *   - array $optionFields Choice fields NOT serving as dimensions (+option_rows)
 *   - int   $itemId
 */
$optionFields = (array) $displayData['optionFields'];
$itemId = (int) $displayData['itemId'];

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="alfa-combi-tools">
    <div data-options-editor data-item-id="<?php echo $itemId; ?>">
        <p class="tools-lead"><?php echo Text::_('COM_ALFA_COMBI_OPTIONS_LEAD'); ?></p>
        <p class="tools-hint"><?php echo Text::_('COM_ALFA_COMBI_OPTIONS_PICK_HINT'); ?></p>
        <?php foreach ($optionFields as $optionField) :
            $fieldId = (int) $optionField->id;
            ?>
            <div class="tools-field" data-sortable-pills>
                <span class="tools-label"><?php echo $esc($optionField->display_name); ?></span>
                <?php foreach ($optionField->values as $poolValue) :
                    $isSet = isset($optionField->option_rows[(int) $poolValue->id]);
                    ?>
                    <span class="value-pill">
                        <span class="sortable-handler pill-drag" aria-hidden="true" title="<?php echo $esc(Text::_('JGLOBAL_ORDER')); ?>"><span class="icon-ellipsis-v"></span></span>
                        <input type="checkbox" value="<?php echo (int) $poolValue->id; ?>" data-add-option-value
                               <?php echo $isSet ? 'checked disabled' : ''; ?>
                               id="option-value-<?php echo $fieldId; ?>-<?php echo (int) $poolValue->id; ?>">
                        <label for="option-value-<?php echo $fieldId; ?>-<?php echo (int) $poolValue->id; ?>">
                            <?php echo $esc($poolValue->display_name); ?>
                        </label>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php // Last in the body, so it sits right above the footer's Add button: Add writes the options in its
              // own request (item.addOptions), so they need no product save. A product not saved yet has no id, and Add
              // adds nothing for it (since 2.1.0; as the generator's line). ?>
        <p class="tools-hint mb-0"><?php echo Text::_($itemId > 0 ? 'COM_ALFA_COMBI_OPTIONS_SAVED_AT_ONCE' : 'COM_ALFA_COMBI_OPTIONS_SAVE_PRODUCT_FIRST'); ?></p>
    </div>
</div>
