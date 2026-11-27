<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field: the value(s) the item carries, alone, as the body of a page section of its own (the section's
 * heading is the field's name). No inputs, no selector: a passive surface.
 *
 * @var object $field  The field definition (display_name).
 * @var object[] $values The informational value objects (display_name).
 */

defined('_JEXEC') or die;

extract($displayData);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<p class="spec-field-values">
    <?php echo implode(', ', array_map(
        static fn ($value) => $esc($value->display_name),
        $values,
    )); ?>
</p>
