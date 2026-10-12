<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field: item-page selector, radio mode. (item-buttons reuses this with
 * a button skin by injecting $variant = 'buttons' before requiring this file.)
 *
 * Choice::renderSelector() always supplies the full $displayData below, so the
 * layout just extracts it (no defaults needed):
 *
 * @var object $field       The field definition (id, display_name, ...).
 * @var object[] $values      The selectable value objects (id, display_name, params_decoded).
 * @var string $inputName   This control's form name: sel[fieldId] (variant) or opt[fieldId] (option).
 * @var int $selected    The currently-chosen value id (0 = none chosen).
 * @var array $available   Selectable value ids as a set [id => true]; others are disabled.
 * @var array $inStock     In-stock value ids as a set [id => true] (available but flagged when not in stock).
 * @var string $reloadScope The data-item-reload value: 'item' re-fetches the whole item (variant), 'price' re-prices only (option).
 * @var string $variant     Render skin (radio|buttons), layout-internal, NOT plugin data; item-buttons injects 'buttons'.
 */

defined('_JEXEC') or die;

extract($displayData);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="variant-field">
    <span class="variant-field-label"><?php echo $esc($field->display_name); ?></span>
    <div class="variant-options variant-options--<?php echo $esc($variant ?? 'radio'); ?>"
         role="radiogroup" aria-label="<?php echo $esc($field->display_name); ?>">
        <?php foreach ($values as $value) :
            $vid = (int) $value->id;
            $isAvailable = isset($available[$vid]);
            $isInStock = isset($inStock[$vid]);
            // 'removed' policy: drop values unreachable with the current selection
            // entirely. Out-of-stock is still "available", so it is never dropped.
            if (!$isAvailable && ($unavailableMode ?? 'disabled') === 'removed') {
                continue;
            }
            // Selected state is NOT a class here; CSS keys off the native radio's
            // :checked (set below), so a price-only reload still shows the choice.
            $classes = 'variant-option'
                . ($isAvailable ? '' : ' is-unavailable')
                . ($isAvailable && !$isInStock ? ' is-out-of-stock' : '');
            ?>
            <label class="<?php echo $classes; ?>">
                <input type="radio" name="<?php echo $esc($inputName); ?>"
                       value="<?php echo $vid; ?>" data-item-reload="<?php echo $esc($reloadScope); ?>"
                    <?php echo $selected === $vid ? 'checked' : ''; ?>
                    <?php echo $isAvailable ? '' : 'disabled'; ?>>
                <span class="variant-option-label"><?php echo $esc($value->display_name); ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
