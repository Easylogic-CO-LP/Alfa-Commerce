<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field: one row of the Specifications section's table. The cells of a table row, the label as the row's
 * header and the value(s) the item carries beside it; the table and the row element are the section's. No inputs,
 * no selector: a passive surface.
 *
 * @var object $field  The field definition (display_name).
 * @var object[] $values The informational value objects (display_name).
 */

defined('_JEXEC') or die;

extract($displayData);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<th scope="row" class="spec-field-label"><?php echo $esc($field->display_name); ?></th>
<td class="spec-field-values">
    <?php echo implode(', ', array_map(
        static fn ($value) => $esc($value->display_name),
        $values,
    )); ?>
</td>
