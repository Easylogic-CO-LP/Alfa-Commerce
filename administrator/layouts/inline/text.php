<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Inline scalar EDITOR: ONE input for every non-translatable inline field
 * (sku, stock, ...), rendered on demand by <entity>.inlineLoad and swapped into
 * the cell by media/com_alfa/js/admin/inline-edit.js.
 *
 * Two contracts the markup must honour, both read by that script:
 *   - class "alfa-inline-input"  - how the editor's inputs are collected;
 *   - data-name "__value"        - the key the scalar branch prefers, so the
 *                                  posted payload is {id, field, value}.
 * A single input also makes Enter save (the script binds it only when the
 * editor holds exactly one input).
 *
 * @var array $displayData
 *   - value     string       Current value, printed as-is.
 *   - type      string       Input type: "text" (default) or "number".
 *   - step      string|null  Number step (ignored for text).
 *   - min       string|null  Number floor (ignored for text).
 *   - maxlength int|null     Character cap; mirror the column width.
 *   - hint      string       Optional one-line explanation under the input.
 */

defined('_JEXEC') or die;

$value     = (string) ($displayData['value'] ?? '');
$type      = ($displayData['type'] ?? 'text') === 'number' ? 'number' : 'text';
$step      = $displayData['step'] ?? null;
$min       = $displayData['min'] ?? null;
$maxlength = (int) ($displayData['maxlength'] ?? 0);
$hint      = (string) ($displayData['hint'] ?? '');
?>
<input type="<?php echo $type; ?>" class="form-control form-control-sm alfa-inline-input"
       data-name="__value"
       value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>"
    <?php if ($maxlength > 0) : ?>
       maxlength="<?php echo $maxlength; ?>"
    <?php endif; ?>
    <?php if ($type === 'number' && $step !== null) : ?>
       step="<?php echo htmlspecialchars((string) $step, ENT_QUOTES, 'UTF-8'); ?>"
    <?php endif; ?>
    <?php if ($type === 'number' && $min !== null) : ?>
       min="<?php echo htmlspecialchars((string) $min, ENT_QUOTES, 'UTF-8'); ?>"
    <?php endif; ?>
>
<?php if ($hint !== '') : ?>
    <div class="alfa-inline-hint"><?php echo htmlspecialchars($hint, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
