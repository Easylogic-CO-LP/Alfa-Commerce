<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field: item-page selector, <select> mode.
 *
 * Choice::renderSelector() always supplies the full $displayData below, so the
 * layout just extracts it (no defaults needed):
 *
 * @var object $field       The field definition (id, display_name, ...).
 * @var object[] $values      The selectable value objects (id, display_name, params_decoded).
 * @var string $inputName   This control's form name: sel[fieldId] for a variant
 *             field, opt[fieldId] for an option field.
 * @var int $selected    The currently-chosen value id (0 = none chosen).
 * @var array $available   Selectable value ids as a set [id => true]; others are
 *            disabled (a variant value not reachable in the current combo).
 * @var array $inStock     In-stock value ids as a set [id => true] (available but
 *            out of stock -> flagged, still rendered).
 * @var string $reloadScope The data-item-reload value: 'item' re-fetches the whole
 *             item (variant), 'price' re-prices only (option).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

extract($displayData);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="variant-field">
    <label class="variant-field-label">
        <?php echo $esc($field->display_name); ?>
        <select name="<?php echo $esc($inputName); ?>"
                class="variant-field-control" data-item-reload="<?php echo $esc($reloadScope); ?>">
        <option value="0"><?php echo $esc(Text::_('PLG_ALFA_ITEM_FIELDS_CHOICE_CHOOSE')); ?></option>
        <?php foreach ($values as $value) :
            $vid = (int) $value->id;
            $isAvailable = isset($available[$vid]);
            $isInStock = isset($inStock[$vid]);
            // Reachable with the current selection but no salable stock.
            $isOutOfStock = $isAvailable && !$isInStock;
            // 'removed' policy: drop values unreachable with the current selection
            // entirely. Out-of-stock is still "available", so it is never dropped.
            if (!$isAvailable && ($unavailableMode ?? 'disabled') === 'removed') {
                continue;
            }
            ?>
            <option value="<?php echo $vid; ?>"
                <?php echo $selected === $vid ? 'selected' : ''; ?>
                <?php echo $isAvailable ? '' : 'disabled'; ?>
                <?php echo $isOutOfStock ? 'data-out-of-stock="1"' : ''; ?>>
                <?php echo $esc($value->display_name); ?><?php
            echo $isOutOfStock ? ' - ' . $esc(Text::_('PLG_ALFA_ITEM_FIELDS_CHOICE_OUT_OF_STOCK')) : ''; ?>
            </option>
        <?php endforeach; ?>
        </select>
    </label>
</div>
