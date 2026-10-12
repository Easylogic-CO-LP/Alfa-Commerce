<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Choice field: listing-card text mode. Lists the values the item offers for
 * the field as plain labels: no input, no selector, so nothing is posted and a
 * choice the buyer must make is made on the product page.
 *
 * Choice::renderSelector() always supplies the full $displayData below, so the
 * layout just extracts it (no defaults needed):
 *
 * @var object $field  The field definition (id, display_name, ...).
 * @var object[] $values The value objects to list (id, display_name).
 */

defined('_JEXEC') or die;

extract($displayData);

$esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="variant-field">
    <span class="variant-field-label"><?php echo $esc($field->display_name); ?></span>
    <span class="variant-field-values">
        <?php echo implode(', ', array_map(
            static fn ($value) => '<span class="variant-field-value">' . $esc($value->display_name) . '</span>',
            $values,
        )); ?>
    </span>
</div>
