<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * One product field as a plain read-only list of its values, in place of the buy form in catalogue-only mode:
 * the field's name, then each value's name, as text. No input, no link, nothing a script reads. A presenter: the model
 * decided which fields are listed.
 *
 * Overridable as html/layouts/com_alfa/item/fields_readonly.php.
 *
 * @var array{field: object, values: object[]} $displayData The field (->display_name) and the values it offers (->display_name).
 */

defined('_JEXEC') or die;

$field = $displayData['field'] ?? null;
$values = (array) ($displayData['values'] ?? []);

if (!\is_object($field) || $values === []) {
    return;
}

$esc = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
?>
<dl class="item-field-readonly">
    <dt class="item-field-readonly-name"><?php echo $esc($field->display_name ?? $field->field_name ?? ''); ?></dt>
    <?php foreach ($values as $value) : ?>
        <dd class="item-field-readonly-value"><?php echo $esc($value->display_name ?? ''); ?></dd>
    <?php endforeach; ?>
</dl>
