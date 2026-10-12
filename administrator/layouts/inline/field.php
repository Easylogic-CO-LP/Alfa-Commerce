<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Inline quick-edit CELL marker. Renders ONLY the display value (kept as the
 * normal edit link) wrapped in a declarative marker. media/com_alfa/js/admin/
 * inline-edit.js finds the marker, injects the pencil trigger, and fetches the
 * editor on demand (task=<view>.inlineLoad). Markup here stays minimal; the
 * affordance and the editor are the JS's job.
 *
 * When the user cannot edit, only the plain value is printed (no marker -> no
 * trigger), so inline edit degrades cleanly.
 *
 * @var array $displayData
 *   - view     string  Inline controller/entity for the task (e.g. "item").
 *   - element  string  Field key (e.g. "name").
 *   - id       int     Row id.
 *   - type     string  Editor kind: multilang|text|number.
 *   - display  string  Current value shown in the cell.
 *   - editHref string  Optional RAW url (Route::_(..., false)); escaped once here.
 *   - canEdit  bool    Whether the field is editable.
 */

defined('_JEXEC') or die;

$view    = (string) ($displayData['view'] ?? '');
$element = (string) ($displayData['element'] ?? '');
$id      = (int) ($displayData['id'] ?? 0);
$type    = (string) ($displayData['type'] ?? 'text');
$display = (string) ($displayData['display'] ?? '');
$editHref = (string) ($displayData['editHref'] ?? '');
$canEdit = !empty($displayData['canEdit']);

$shown = $display === '' ? '-' : $display;

if (!$canEdit) {
    echo htmlspecialchars($shown, ENT_QUOTES, 'UTF-8');

    return;
}
?>
<span class="alfa-editable"
      data-view="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>"
      data-element="<?php echo htmlspecialchars($element, ENT_QUOTES, 'UTF-8'); ?>"
      data-id="<?php echo $id; ?>"
      data-type="<?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($editHref !== '') : ?>
        <a class="alfa-editable-display" href="<?php echo htmlspecialchars($editHref, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($shown, ENT_QUOTES, 'UTF-8'); ?></a>
    <?php else : ?>
        <span class="alfa-editable-display"><?php echo htmlspecialchars($shown, ENT_QUOTES, 'UTF-8'); ?></span>
    <?php endif; ?>
</span>
