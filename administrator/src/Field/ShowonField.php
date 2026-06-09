<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

/**
 * Visibility-rule builder field.
 *
 * Server-renders the whole recursive tree (so it shows with or without JS,
 * same taste as Deliveryplus OpeningTimesField) and ships <template> blocks
 * the JS clones for new rows/groups. The authoritative value is ONE hidden
 * input holding the engine's canonical per-glue JSON; showon-builder.js
 * mutates the DOM and serialises it back into that input on every change:
 *
 *   { "group": [
 *       { "rule":  {"field":"f1","op":"=","values":["a"]}, "glue":"AND" },
 *       { "group": [ … ], "glue":"OR" },
 *       { "rule":  {"field":"f3","op":"!=","values":["b"]} }   // last: no glue
 *   ] }
 *
 * `glue` joins an item to the NEXT sibling, strict left-to-right (precedence
 * only via nested groups). Empty tree -> '' (field always shown). The model
 * folds this value into params; FieldsPlugin reads params->get('showon').
 *
 * @since  1.0.0
 */
class ShowonField extends FormField
{
    protected $type = 'showon';

    /**
     * Operator list — the SINGLE source of truth for which operators exist
     * and their labels. The runtime engine (showon.js) owns only operator
     * *implementations*. Reuses existing COM_ALFA_SHOWON_* keys. Values
     * match exactly what the engine expects ('!' prefix = negation).
     */
    private const OPS = [
        '=' => 'COM_ALFA_SHOWON_IS',
        '!=' => 'COM_ALFA_SHOWON_ISNOT',
        'contains' => 'COM_ALFA_SHOWON_CONTAINS',
        '!contains' => 'COM_ALFA_SHOWON_NCONTAINS',
        'startsWith' => 'COM_ALFA_SHOWON_STARTS',
        'endsWith' => 'COM_ALFA_SHOWON_ENDS',
        'regex' => 'COM_ALFA_SHOWON_REGEX',
        '!regex' => 'COM_ALFA_SHOWON_NREGEX',
        'empty' => 'COM_ALFA_SHOWON_EMPTY',
        '!empty' => 'COM_ALFA_SHOWON_NEMPTY',
        '>' => 'COM_ALFA_SHOWON_GT',
        '>=' => 'COM_ALFA_SHOWON_GTE',
        '<' => 'COM_ALFA_SHOWON_LT',
        '<=' => 'COM_ALFA_SHOWON_LTE',
        'between' => 'COM_ALFA_SHOWON_BETWEEN',
        'length' => 'COM_ALFA_SHOWON_LENGTH',
        '!length' => 'COM_ALFA_SHOWON_NLENGTH',
    ];

    /** Operators that take no value (value input hidden in the builder). */
    private const NO_VALUE_OPS = ['empty', '!empty'];

    /** Switchable-field options — fetched once per render in getInput(). */
    private array $fieldOptions = [];

    /**
     * Server-render the visibility-rule builder: load its web assets, decode the
     * stored per-glue JSON into the root group, emit the hidden store input plus
     * the recursively rendered tree, and append the rule/group <template> blocks
     * the JS clones for new rows.
     *
     * @return string The builder HTML
     *
     * @since  1.0.0
     */
    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        $wa->useStyle('com_alfa.showon-builder')
            ->useScript('com_alfa.showon-builder');

        $tree = json_decode(is_string($this->value) ? $this->value : '', true);
        $root = (is_array($tree) && isset($tree['group']) && is_array($tree['group']))
            ? $tree['group'] : [];

        $this->fieldOptions = $this->switchableFields(); // one query per render

        $config = [
            'noValueOps' => self::NO_VALUE_OPS,
            'labels' => [
                'and' => Text::_('COM_ALFA_SHOWON_AND'),
                'or' => Text::_('COM_ALFA_SHOWON_OR'),
                'noRules' => Text::_('COM_ALFA_SHOWON_NO_RULES'),
            ],
        ];

        ob_start(); ?>
<div class="aso"
     id="<?php echo $this->esc($this->id); ?>"
     data-config='<?php echo $this->escAttrJson($config); ?>'>
    <input type="hidden"
           name="<?php echo $this->esc($this->name); ?>"
           value="<?php echo $this->esc(is_string($this->value) ? $this->value : ''); ?>"
           data-aso-store>

    <?php echo $this->renderGroup($root, true); ?>

    <template data-aso-tpl="rule"><?php echo $this->renderItem(['rule' => []], true); ?></template>
    <template data-aso-tpl="group"><?php echo $this->renderItem(['group' => [['rule' => []]]], true); ?></template>
</div>
        <?php
        return ob_get_clean();
    }

    /* ============================================================
     *  Recursive server render
     * ============================================================ */

    /** A whole group: ordered items + the add bar.
     * @since  1.0.0
     */
    private function renderGroup(array $items, bool $isRoot): string
    {
        $cls = 'aso-group' . ($isRoot ? ' aso-group--root' : '');
        $n = count($items);

        $out = '<div class="' . $cls . '">';
        $out .= '<div class="aso-items">';
        if (!$n) {
            $out .= '<div class="aso-empty">' . $this->esc(Text::_('COM_ALFA_SHOWON_NO_RULES')) . '</div>';
        }
        foreach (array_values($items) as $i => $item) {
            $out .= $this->renderItem($item, $i === $n - 1);
        }
        $out .= '</div>';
        $out .= '<div class="aso-bar">'
            . '<button type="button" class="aso-add" data-action="add-rule">+ '
            . $this->esc(Text::_('COM_ALFA_SHOWON_ADD_RULE')) . '</button>'
            . '<button type="button" class="aso-add" data-action="add-group">+ '
            . $this->esc(Text::_('COM_ALFA_SHOWON_ADD_GROUP')) . '</button>'
            . '</div>';
        $out .= '</div>';

        return $out;
    }

    /** One item = a rule OR a nested group, plus the glue to the NEXT sibling.
     * @since  1.0.0
     */
    private function renderItem(array $item, bool $isLast): string
    {
        $out = '<div class="aso-item">';

        if (isset($item['group']) && is_array($item['group'])) {
            $out .= '<div class="aso-subgroup">'
                . '<div class="aso-subgroup-head">'
                . '<span class="aso-subgroup-tag">( ' . $this->esc(Text::_('COM_ALFA_SHOWON_GROUP')) . ' )</span>'
                . '<button type="button" class="aso-icon aso-icon--danger" data-action="remove" title="'
                . $this->esc(Text::_('JREMOVE')) . '">&times;</button>'
                . '</div>'
                . $this->renderGroup($item['group'], false)
                . '</div>';
        } else {
            $out .= $this->renderRule(\is_array($item['rule'] ?? null) ? $item['rule'] : []);
        }

        if (!$isLast) {
            $glue = (strtoupper((string) ($item['glue'] ?? 'AND')) === 'OR') ? 'OR' : 'AND';
            $out .= $this->renderGlue($glue);
        }

        $out .= '</div>';

        return $out;
    }

    /**
     * A single condition row. ONE value per rule (OR is the glue, not a
     * delimiter — values are never split). `between` shows two inputs
     * (min/max); no-value ops show none.
     * @since  1.0.0
     */
    private function renderRule(array $r): string
    {
        $field = (string) ($r['field'] ?? '');
        $op = (string) ($r['op'] ?? '=');
        $vals = isset($r['values']) && is_array($r['values']) ? array_values($r['values']) : [];
        $v0 = (string) ($vals[0] ?? '');
        $v1 = (string) ($vals[1] ?? '');
        $isNoVal = in_array($op, self::NO_VALUE_OPS, true);
        $isBetween = ($op === 'between');

        // field <select>
        $fOpts = '<option value="">' . $this->esc(Text::_('COM_ALFA_SHOWON_PICK')) . '</option>';
        foreach ($this->fieldOptions as $f) {
            $fOpts .= '<option value="' . $this->esc($f['value']) . '"'
                . ($f['value'] === $field ? ' selected' : '') . '>'
                . $this->esc($f['text']) . '</option>';
        }

        // operator <select>
        $oOpts = '';
        foreach (self::OPS as $v => $key) {
            $oOpts .= '<option value="' . $this->esc($v) . '"'
                . ($v === $op ? ' selected' : '') . '>'
                . $this->esc(Text::_($key)) . '</option>';
        }

        return '<div class="aso-rule">'
            . '<select class="aso-select" data-aso="field">' . $fOpts . '</select>'
            . '<select class="aso-select" data-aso="op">' . $oOpts . '</select>'
            . '<input type="text" class="aso-input" data-aso="value"'
            . ' value="' . $this->esc($v0) . '" placeholder="value"'
            . (($isNoVal || $isBetween) ? ' style="display:none"' : '') . '>'
            . '<span class="aso-between" data-aso-between'
            . ($isBetween ? '' : ' style="display:none"') . '>'
            . '<input type="text" class="aso-input" data-aso="value-min" value="'
            . $this->esc($isBetween ? $v0 : '') . '" placeholder="min">'
            . '<span class="aso-between-sep">…</span>'
            . '<input type="text" class="aso-input" data-aso="value-max" value="'
            . $this->esc($isBetween ? $v1 : '') . '" placeholder="max">'
            . '</span>'
            . '<button type="button" class="aso-icon aso-icon--danger" data-action="remove" title="'
            . $this->esc(Text::_('JREMOVE')) . '">&times;</button>'
            . '</div>';
    }

    /** The AND/OR connector between two siblings.
     * @since  1.0.0
     */
    private function renderGlue(string $glue): string
    {
        $or = $glue === 'OR';
        $txt = $or ? Text::_('COM_ALFA_SHOWON_OR') : Text::_('COM_ALFA_SHOWON_AND');

        return '<div class="aso-glue-row">'
            . '<button type="button" class="aso-glue' . ($or ? ' aso-glue--or' : '') . '"'
            . ' data-action="toggle-glue" data-glue="' . $glue . '">'
            . $this->esc($txt) . '</button>'
            . '</div>';
    }

    /* ============================================================
     *  Helpers
     * ============================================================ */

    private function esc($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * JSON-encode an array (unescaped unicode/slashes) and HTML-escape it for
     * safe use inside a double-quoted HTML attribute.
     *
     * @param array $v The value to encode
     *
     * @return string The attribute-safe JSON string
     *
     * @since  1.0.0
     */
    private function escAttrJson(array $v): string
    {
        return htmlspecialchars(
            json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ENT_QUOTES,
            'UTF-8',
        );
    }

    /**
     * Fields that can act as a switch — every published alfa form field
     * except this one (you can't gate a field on itself).
     *
     * @return array<int, array{value:string,text:string}>
     * @since  1.0.0
     */
    private function switchableFields(): array
    {
        $model = Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel('Formfields', 'Administrator', ['ignore_request' => true]);

        if (!$model) {
            return [];
        }

        // Force populateState() before our setState() — otherwise it fires
        // later inside getItems() and overwrites them (esp. list.limit,
        // which falls back to the component default, 20). Mirrors ModelField.
        $model->getState('list.ordering');
        $model->setState('filter.state', 1);   // published only
        $model->setState('filter.search', '');
        $model->setState('filter.group_id', '');
        $model->setState('list.limit', 0);
        $model->setState('list.start', 0);
        $model->setState('list.ordering', 'a.name');
        $model->setState('list.direction', 'ASC');

        $selfName = (string) ($this->form ? $this->form->getValue('field_name') : '');

        $out = [];
        foreach ($model->getItems() ?: [] as $row) {
            $value = (string) ($row->field_name ?? '');
            if ($value === '' || $value === $selfName) {
                continue;
            }
            $out[] = ['value' => $value, 'text' => (string) ($row->name ?? $value)];
        }

        return $out;
    }
}
