<?php

/**
 * Alfa Commerce
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * An amount of an order's money (OrderMoneyField): Joomla's number input, its
 * value printed as the exact text it was bound with. Joomla's own layout casts
 * the value to a float, which prints fourteen digits: 81474912.58707305 would
 * show as 81474912.587073, and a Save that changed nothing else would write
 * that back. A value that is not a number is left out, as Joomla's does.
 *
 * Receives Joomla's number layout data; $step is the order's smallest unit.
 */

defined('_JEXEC') or die;

extract($displayData);

$attributes = [
    !empty($class) ? 'class="form-control ' . $class . '"' : 'class="form-control"',
    !empty($description) ? 'aria-describedby="' . ($id ?: $name) . '-desc"' : '',
    $disabled ? 'disabled' : '',
    $readonly ? 'readonly' : '',
    strlen($hint) ? 'placeholder="' . htmlspecialchars($hint, ENT_COMPAT, 'UTF-8') . '"' : '',
    !empty($onchange) ? 'onchange="' . $onchange . '"' : '',
    isset($max) ? 'max="' . $max . '"' : '',
    !empty($step) ? 'step="' . $step . '"' : '',
    isset($min) ? 'min="' . $min . '"' : '',
    $required ? 'required' : '',
    !empty($autocomplete) ? 'autocomplete="' . $autocomplete . '"' : '',
    $autofocus ? 'autofocus' : '',
    $dataAttribute,
];

$value = (\is_string($value) || \is_int($value)) && is_numeric($value) ? (string) $value : '';
?>
<input
    type="number"
    inputmode="decimal"
    name="<?php echo $name; ?>"
    id="<?php echo $id; ?>"
    value="<?php echo htmlspecialchars($value, ENT_COMPAT, 'UTF-8'); ?>"
    <?php echo implode(' ', $attributes); ?>>
