<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The per-item VISIBILITY (ShowOn) popup body, lazy-loaded via item.fieldsPopup
 * (type=visibility), exactly like the Options/Generator editors. It renders the
 * shared ShowonField builder for ONE field, scoped to this item's fields and with
 * the field itself excluded as an operand (you cannot gate a field on itself). The
 * builder's hidden store input (data-aso-store) carries the canonical rule JSON;
 * combinations.js posts it to item.saveVisibility on the modal's Save button.
 *
 * @var array $displayData ['field' => object|null, 'itemId' => int, 'rule' => string]
 */

defined('_JEXEC') or die;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;

$field  = $displayData['field'] ?? null;
$itemId = (int) ($displayData['itemId'] ?? 0);
$rule   = (string) ($displayData['rule'] ?? '');

if (!$field) {
    echo '<div class="p-3 text-muted">' . Text::_('COM_ALFA_SHOWON_NO_FIELD') . '</div>';

    return;
}

$fieldId   = (int) $field->id;
$fieldName = (string) ($field->field_name ?? '');

// A one-field form purely to render the ShowonField. `field_name` is the field's
// machine name so the builder excludes it from its own operand list; `itemid`
// scopes the operands + value pools to THIS item's fields (you cannot gate on a
// field the item does not have).
$form = new Form('item_visibility_' . $fieldId);
$form->load(
    '<form addfieldprefix="Alfa\\Component\\Alfa\\Administrator\\Field">'
    . '<field name="field_name" type="hidden" />'
    . '<field name="showon" type="showon" source="itemfields" itemid="' . $itemId . '" hiddenLabel="true" />'
    . '</form>',
);
$form->setValue('field_name', null, $fieldName);
$form->setValue('showon', null, $rule);

// The global rule already summarised server-side; the label switches to "Overrides"
// once an item rule is set (it no longer merely inherits).
$inheritedSummary = (string) ($displayData['inheritedSummary'] ?? '');
$overrides        = (bool) ($displayData['overrides'] ?? false);
?>
<div data-visibility-editor data-item-id="<?php echo $itemId; ?>" data-field-id="<?php echo $fieldId; ?>">
    <?php if ($inheritedSummary !== '') : ?>
        <div class="alert <?php echo $overrides ? 'alert-warning' : 'alert-info'; ?> py-2 px-3 mb-2">
            <strong><?php echo Text::_($overrides ? 'COM_ALFA_SHOWON_OVERRIDES' : 'COM_ALFA_SHOWON_INHERITS'); ?></strong>
            <?php echo htmlspecialchars($inheritedSummary, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <p class="tools-hint">
        <?php echo Text::sprintf('COM_ALFA_SHOWON_FIELD_HINT', htmlspecialchars($field->display_name ?? $fieldName, ENT_QUOTES, 'UTF-8')); ?>
    </p>
    <?php echo $form->renderField('showon'); ?>
</div>
