<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
/**
 * The order line's price command: Leave unchanged / Set / Clear.
 *
 * A TRANSIENT COMMAND, NEVER STORED. It tells the save how to treat the
 * line's one nullable custom-price column; only that money column persists.
 * It opens on Leave unchanged whenever an existing line opens, so opening and
 * saving a line never changes its pricing. The amount input is read only
 * under Set: it is disabled otherwise and a disabled input is not posted, so
 * a displayed or empty amount can never mean Set or Clear by itself.
 *
 * A refused save comes back with the command and the amount it posted, so
 * the operator corrects what was typed instead of typing it again.
 *
 * On a new line there are no terms to keep: "Leave" is today's price.
 *
 * Layout path: administrator/components/com_alfa/layouts/order/item_price_command.php
 * Override:    administrator/templates/{template}/html/layouts/com_alfa/order/item_price_command.php
 *
 * @var array $displayData Contains:
 *   - 'is_new'              => bool        - a line that does not exist yet
 *   - 'stored_custom_price' => string|null - the line's stored custom unit price, as a plain number, or null
 *   - 'custom_label'        => string      - the stored custom price, formatted at its own decimals, or ''
 *   - 'step'                => string      - the unit-rate input step: Alfa's eight decimal places
 *   - 'command'             => string      - the choice to open on: leave (default), set or clear
 *   - 'amount'              => string|null - the amount a refused save posted, shown instead of the stored one
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

$isNew       = (bool) ($displayData['is_new'] ?? true);
$customPrice = $displayData['stored_custom_price'] ?? null;
$customLabel = (string) ($displayData['custom_label'] ?? '');
$step        = (string) ($displayData['step'] ?? '0.01');
$command     = (string) ($displayData['command'] ?? 'leave');
$amount      = $displayData['amount'] ?? $customPrice;

$choices = [
    'leave' => Text::_($isNew ? 'COM_ALFA_ORDER_ITEM_PRICE_LEAVE_NEW' : 'COM_ALFA_ORDER_ITEM_PRICE_LEAVE'),
    'set'   => Text::_('COM_ALFA_ORDER_ITEM_PRICE_SET'),
];

// Nothing to clear or reprice on a line that does not exist yet.
if (!$isNew) {
    $choices['clear'] = Text::_('COM_ALFA_ORDER_ITEM_PRICE_CLEAR');
}

// One choice is always checked: a command this line is not offered opens on Leave.
if (!isset($choices[$command])) {
    $command = 'leave';
}
?>
<fieldset class="control-group order-item-price-command" data-order-item-price-command>
    <legend class="control-label"><?php echo Text::_('COM_ALFA_ORDER_ITEM_PRICE_COMMAND'); ?></legend>
    <div class="controls">
        <?php foreach ($choices as $value => $label) : ?>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="custom_price_action"
                       id="order-item-price-<?php echo $value; ?>" value="<?php echo $value; ?>"
                       data-order-item-price-choice<?php echo $value === $command ? ' checked' : ''; ?>>
                <label class="form-check-label" for="order-item-price-<?php echo $value; ?>">
                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </label>
            </div>
        <?php endforeach; ?>

        <div class="mt-2">
            <label class="form-label" for="order-item-custom-amount">
                <?php echo Text::_('COM_ALFA_ORDER_ITEM_CUSTOM_AMOUNT'); ?>
            </label>
            <input class="form-control" type="number" min="0" step="<?php echo htmlspecialchars($step, ENT_QUOTES, 'UTF-8'); ?>"
                   id="order-item-custom-amount" name="custom_unit_price_tax_incl"
                   value="<?php echo $amount !== null ? htmlspecialchars((string) $amount, ENT_QUOTES, 'UTF-8') : ''; ?>"
                   data-order-item-custom-amount<?php echo $command === 'set' ? '' : ' disabled'; ?>>
            <?php if ($customLabel !== '') : ?>
                <div class="form-text"><?php echo Text::sprintf('COM_ALFA_ORDER_ITEM_CUSTOM_CURRENT', htmlspecialchars($customLabel, ENT_QUOTES, 'UTF-8')); ?></div>
            <?php endif; ?>
        </div>
    </div>
</fieldset>
