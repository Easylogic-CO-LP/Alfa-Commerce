<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Field;

use Alfa\Component\Alfa\Administrator\Helper\ItemFieldsAdminHelper;
use Alfa\Component\Alfa\Administrator\Helper\ShowonOperators;
use Alfa\Component\Alfa\Administrator\Service\ValuePool\ValuePoolRegistry;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Visibility-rule builder field.
 *
 * Authors the engine's canonical rule object, one shared schema for form fields and
 * item fields, evaluated identically by PHP (render/submit) and JS (live):
 *
 *   { "match": "all",                       // all | any  (per group)
 *     "action": "show",                     // show | hide (root only)
 *     "rules": [
 *       { "operand": "size.value", "operator": "in", "value": [12, 13] },
 *       { "match": "any", "rules": [ ... ] }  // nested group for precedence
 *     ] }
 *
 * Operands are dot-addresses (`<field>.value`); compare values are stable value-pool
 * **ids**, never labels, so the rule is multilingual-safe and deterministic. The
 * picker shows labels from the value-pool layer, embedded once at render (no HTTP).
 * The authoritative value is ONE hidden input holding that JSON; showon-builder.js
 * mutates the DOM and serialises it back on every change. Empty tree -> '' (always
 * shown). The model folds this into params; the surface emits it as data-showon-rule.
 *
 * @since  1.0.0
 */
class ShowonField extends FormField
{
    protected $type = 'showon';

    /** Candidate operands [['value' => 'size.value', 'text' => 'Size'], ...]. */
    private array $operandOptions = [];

    /** operand => value pool, embedded for the JS to hydrate the value control. */
    private array $pools = [];

    /**
     * Server-render the builder: load assets, decode the stored rule into the root
     * group, resolve the candidate operands and their value pools, then emit the
     * hidden store input, the rendered tree and the clone <template>s.
     *
     * @return string The builder HTML
     *
     * @since  1.0.0
     */
    protected function getInput()
    {
        $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
        // Self-register the component's asset file: a normal page auto-registers it,
        // but a raw AJAX render (the visibility popup, format=json) does not. The
        // extension form resolves media/com_alfa/joomla.asset.json (+ overrides).
        $wa->getRegistry()->addExtensionRegistryFile('com_alfa');
        $wa->useStyle('com_alfa.showon-builder')
            ->useScript('com_alfa.showon-builder');

        $root = $this->decodeRoot();
        $this->resolveOperands();

        // In the per-item popup, nudge the author toward a GLOBAL rule when the
        // visibility is not specific to this product: a global rule (set on the
        // field definition) keeps every product in sync instead of repeating it.
        $isItemScoped = (int) ($this->element['itemid'] ?? 0) > 0;

        $config = [
            'operators' => ShowonOperators::labels(),
            'domainOps' => ShowonOperators::DOMAIN_OPS,
            'multiOps' => ShowonOperators::MULTI_OPS,
            'noValueOps' => ShowonOperators::NO_VALUE_OPS,
            'betweenOp' => ShowonOperators::BETWEEN_OP,
            'pools' => $this->pools,
            'labels' => [
                'all' => Text::_('COM_ALFA_SHOWON_MATCH_ALL'),
                'any' => Text::_('COM_ALFA_SHOWON_MATCH_ANY'),
                'show' => Text::_('COM_ALFA_SHOWON_ACTION_SHOW'),
                'hide' => Text::_('COM_ALFA_SHOWON_ACTION_HIDE'),
                'pick' => Text::_('COM_ALFA_SHOWON_PICK'),
                'noRules' => Text::_('COM_ALFA_SHOWON_NO_RULES'),
                // "%s (unavailable)": the script marks a stored value its pool no longer offers.
                'unavailable' => Text::_('COM_ALFA_SHOWON_UNAVAILABLE'),
            ],
            'idOps' => array_values(array_diff(ShowonOperators::DOMAIN_OPS['enum'], ShowonOperators::NO_VALUE_OPS)),
            'valueNames' => $this->unofferedValueNames(root: $root),
        ];

        ob_start(); ?>
<div class="aso"
     id="<?php echo $this->esc($this->id); ?>"
     data-config='<?php echo $this->escAttrJson($config); ?>'>
    <input type="hidden"
           name="<?php echo $this->esc($this->name); ?>"
           value="<?php echo $this->esc($this->storedJson()); ?>"
           data-aso-store>

    <?php if ($isItemScoped) : ?>
    <p class="aso-tip"><?php echo $this->esc(Text::_('COM_ALFA_SHOWON_GLOBAL_TIP')); ?></p>
    <?php endif; ?>

    <?php echo $this->renderGroup($root, true); ?>

    <template data-aso-tpl="rule"><?php echo $this->renderRule([]); ?></template>
    <template data-aso-tpl="group"><?php echo $this->renderSubgroup(['match' => 'all', 'rules' => [[]]]); ?></template>
</div>
        <?php
        return ob_get_clean();
    }

    /*
     *  Decode / resolve
     */

    /** The stored rule as a root group ['match','action','rules']; defaults when empty. */
    private function decodeRoot(): array
    {
        $tree = json_decode(\is_string($this->value) ? $this->value : '', true);

        return [
            'match' => (($tree['match'] ?? '') === 'any') ? 'any' : 'all',
            'action' => (($tree['action'] ?? '') === 'hide') ? 'hide' : 'show',
            'rules' => (\is_array($tree['rules'] ?? null)) ? $tree['rules'] : [],
        ];
    }

    /** The raw stored JSON (kept verbatim so the JS hydrates from one source). */
    private function storedJson(): string
    {
        return \is_string($this->value) ? $this->value : '';
    }

    /** Build the candidate operands and their value pools (once per render). */
    private function resolveOperands(): void
    {
        // Item-scoped (the visibility popup): operands are ONLY the fields actually
        // on this item, with this item's value subset; you cannot gate on a field
        // the item does not have.
        $itemId = (int) ($this->element['itemid'] ?? 0);

        if ($itemId > 0) {
            $this->resolveItemOperands($itemId);

            return;
        }

        // Global (field-definition form): every published field of the family.
        $fields = $this->switchableFields();

        foreach ($fields as $field) {
            $this->operandOptions[] = ['value' => $field['name'] . '.value', 'text' => $field['text']];
        }

        // Enum pools come from the item-fields value pool, batched in two queries
        // (no N+1). Form fields (no pool yet) fall back to a free input; same
        // builder, no second mental model.
        if ((string) ($this->element['source'] ?? '') === 'itemfields') {
            $this->pools = (new ValuePoolRegistry())->getMany($fields);
        }
    }

    /**
     * Operands + value pools scoped to ONE item's fields (the visibility popup):
     * each operand is a field on the item except this one, its pool the item's
     * offered values (with labels); both already on the popup field rows.
     *
     * @param int $itemId The owning item id.
     *
     *
     * @since  2.0.0
     */
    private function resolveItemOperands(int $itemId): void
    {
        $selfName = (string) ($this->form ? $this->form->getValue('field_name') : '');

        $fields = ItemFieldsAdminHelper::getFieldsPopupData(
            itemId: $itemId,
            categoryIds: ItemFieldsAdminHelper::getItemCategoryIds(itemId: $itemId),
        );

        // Each operand offers ONLY the values actually configured on this item:
        // its option rows, informational picks and combination (variant) values.
        // A pool value the item never offers is not selectable at runtime, so it
        // can never satisfy a condition and must not appear in the picker.
        $comboValuesByField = ItemFieldsAdminHelper::getItemCombinationValueIdsByField(itemId: $itemId);

        // Forward-only: a field may gate only on fields shown BEFORE it (smaller
        // rank) that are interactive: variation/option, never informational
        // (static). So the picker offers exactly those, never a later field.
        $roleRank = ItemFieldsAdminHelper::fieldRoleRank(
            fields: $fields,
            comboFieldIdSet: array_fill_keys(array_keys(array_filter($comboValuesByField)), true),
        );
        $targetRank = $roleRank[$selfName]['rank'] ?? PHP_INT_MAX;

        foreach ($fields as $field) {
            $name = (string) ($field->field_name ?? '');

            if ($name === '' || $name === $selfName || empty($field->values)) {
                continue;
            }

            // Only earlier, interactive (variation/option) fields are valid switches.
            $meta = $roleRank[$name] ?? null;

            if ($meta === null || !ItemFieldsAdminHelper::isInteractiveRole($meta['role']) || $meta['rank'] >= $targetRank) {
                continue;
            }

            $inItem = [];

            foreach (array_keys((array) ($field->option_rows ?? [])) as $valueId) {
                $inItem[(int) $valueId] = true;
            }

            foreach ((array) ($field->selected_value_ids ?? []) as $valueId) {
                $inItem[(int) $valueId] = true;
            }

            foreach ($comboValuesByField[(int) $field->id] ?? [] as $valueId) {
                $inItem[(int) $valueId] = true;
            }

            // Nothing configured for this field on this item -> not a usable operand.
            if (!$inItem) {
                continue;
            }

            $items = [];

            foreach ($field->values as $value) {
                if (!isset($inItem[(int) $value->id])) {
                    continue;
                }

                $items[] = ['id' => (int) $value->id, 'label' => (string) ($value->display_name ?? ('#' . $value->id))];
            }

            if (!$items) {
                continue;
            }

            $operand = $name . '.value';
            $this->operandOptions[] = ['value' => $operand, 'text' => (string) ($field->display_name ?? $name)];
            $this->pools[$operand] = ['operand' => $operand, 'domain' => 'enum', 'items' => $items];
        }
    }

    /*
     *  Recursive server render
     */

    /**
     * A whole group: its header (match toggle; action toggle at root), the ordered
     * items, and the add bar.
     *
     * @param array $group ['match','action'?,'rules'].
     * @param bool $isRoot Whether this is the top-level group.
     *
     *
     * @since  1.0.0
     */
    private function renderGroup(array $group, bool $isRoot): string
    {
        $match = (($group['match'] ?? 'all') === 'any') ? 'any' : 'all';
        $action = (($group['action'] ?? 'show') === 'hide') ? 'hide' : 'show';
        $rules = \is_array($group['rules'] ?? null) ? $group['rules'] : [];

        $head = '<div class="aso-group-head">'
            . '<button type="button" class="aso-match" data-action="toggle-match" data-match="' . $match . '">'
            . '<span data-aso-match-label>' . $this->esc(Text::_('COM_ALFA_SHOWON_MATCH_' . strtoupper($match))) . '</span>'
            . '</button>';

        if ($isRoot) {
            $head .= '<button type="button" class="aso-action" data-action="toggle-action" data-action-mode="' . $action . '">'
                . '<span data-aso-action-label>' . $this->esc(Text::_('COM_ALFA_SHOWON_ACTION_' . strtoupper($action))) . '</span>'
                . '</button>';
        }

        $head .= '</div>';

        $items = '<div class="aso-items">';

        if (!$rules) {
            $items .= '<div class="aso-empty">' . $this->esc(Text::_('COM_ALFA_SHOWON_NO_RULES')) . '</div>';
        }

        foreach ($rules as $rule) {
            $items .= \is_array($rule['rules'] ?? null)
                ? $this->renderSubgroup($rule)
                : $this->renderRule(\is_array($rule) ? $rule : []);
        }

        $items .= '</div>';

        $bar = '<div class="aso-bar">'
            . '<button type="button" class="aso-add" data-action="add-rule">+ '
            . $this->esc(Text::_('COM_ALFA_SHOWON_ADD_RULE')) . '</button>'
            . '<button type="button" class="aso-add" data-action="add-group">+ '
            . $this->esc(Text::_('COM_ALFA_SHOWON_ADD_GROUP')) . '</button>'
            . '</div>';

        return '<div class="aso-group' . ($isRoot ? ' aso-group--root' : '') . '">' . $head . $items . $bar . '</div>';
    }

    /** A nested group item: a remove handle plus its own (non-root) group. */
    private function renderSubgroup(array $group): string
    {
        return '<div class="aso-item aso-item--group">'
            . '<button type="button" class="aso-icon aso-icon--danger" data-action="remove" title="'
            . $this->esc(Text::_('JREMOVE')) . '">&times;</button>'
            . $this->renderGroup($group, false)
            . '</div>';
    }

    /**
     * A single condition row: operand + operator selects, and a value container the
     * JS fills from the operand's pool. The stored value rides on data-value so the
     * JS hydrates the right control (select / multi / input / none) selected. A stored
     * operand the builder no longer offers stays selected as a marked "unavailable"
     * option, so the rule survives a save instead of being dropped.
     *
     * @param array $rule ['operand','operator','value'].
     *
     *
     * @since  1.0.0
     */
    private function renderRule(array $rule): string
    {
        $operand = (string) ($rule['operand'] ?? '');
        $stored = $rule['operator'] ?? '=';
        $operator = \is_scalar($stored) ? (string) $stored : '';
        $value = $rule['value'] ?? null;

        $operandSel = '<select class="aso-select" data-aso="operand">'
            . '<option value="">' . $this->esc(Text::_('COM_ALFA_SHOWON_PICK')) . '</option>';
        $offered = false;

        foreach ($this->operandOptions as $opt) {
            $offered = $offered || $opt['value'] === $operand;
            $operandSel .= '<option value="' . $this->esc($opt['value']) . '"'
                . ($opt['value'] === $operand ? ' selected' : '') . '>'
                . $this->esc($opt['text']) . '</option>';
        }

        // Its field unpublished, deleted, or (in the item popup) not an earlier field
        // of this item: kept selected and marked, so the script leaves the rule as stored.
        if ($operand !== '' && !$offered) {
            $operandSel .= '<option value="' . $this->esc($operand) . '" selected data-aso-unavailable>'
                . $this->esc(Text::sprintf('COM_ALFA_SHOWON_UNAVAILABLE', $operand)) . '</option>';
        }

        $operandSel .= '</select>';

        // The operators the row's operand offers, as the script filters them on an operand
        // change; a stored operator outside them stays selected and marked, so an edit
        // elsewhere cannot swap it.
        $unavailable = $operand !== '' && !$offered;
        $domain = $unavailable ? null : ((string) ($this->pools[$operand]['domain'] ?? '') ?: 'text');
        $allowed = $domain !== null && isset(ShowonOperators::DOMAIN_OPS[$domain])
            ? ShowonOperators::DOMAIN_OPS[$domain]
            : ShowonOperators::keys();

        $operatorSel = '<select class="aso-select" data-aso="operator">';

        foreach ($allowed as $op) {
            $operatorSel .= '<option value="' . $this->esc($op) . '"'
                . ($op === $operator ? ' selected' : '') . '>'
                . $this->esc(Text::_(ShowonOperators::LABELS[$op])) . '</option>';
        }

        if (!\in_array($operator, $allowed, true)) {
            $label = isset(ShowonOperators::LABELS[$operator]) ? Text::_(ShowonOperators::LABELS[$operator]) : $operator;
            $operatorSel .= '<option value="' . $this->esc($operator) . '" selected data-aso-unavailable>'
                . $this->esc(Text::sprintf('COM_ALFA_SHOWON_UNAVAILABLE', $label)) . '</option>';
        }

        $operatorSel .= '</select>';

        return '<div class="aso-item aso-item--rule">'
            . '<div class="aso-rule">'
            . $operandSel
            . $operatorSel
            . '<span class="aso-value" data-aso="value" data-value="' . $this->escAttrJsonRaw($value) . '"></span>'
            . '<button type="button" class="aso-icon aso-icon--danger" data-action="remove" title="'
            . $this->esc(Text::_('JREMOVE')) . '">&times;</button>'
            . '</div>'
            . '</div>';
    }

    /**
     * Names of the stored value ids the pickers do not offer, per operand: only on an item-field
     * builder, whose values are value ids, and only for ids compared as ids (=, !=, in, !in).
     *
     * @param array $root The stored rule's root group.
     *
     * @return object Operand => {id: name}; empty (and no query) when every stored id is offered.
     *
     * @since  2.0.0
     */
    private function unofferedValueNames(array $root): object
    {
        if ((string) ($this->element['source'] ?? '') !== 'itemfields' && (int) ($this->element['itemid'] ?? 0) <= 0) {
            return (object) [];
        }

        $idOps = array_values(array_diff(ShowonOperators::DOMAIN_OPS['enum'], ShowonOperators::NO_VALUE_OPS));
        $ids = [];
        $walk = function (array $rules) use (&$walk, &$ids, $idOps): void {
            foreach ($rules as $rule) {
                if (!\is_array($rule)) {
                    continue;
                }

                if (\is_array($rule['rules'] ?? null)) {
                    $walk($rule['rules']);

                    continue;
                }

                $operand = (string) ($rule['operand'] ?? '');

                if ($operand === '' || !\in_array($rule['operator'] ?? '=', $idOps, true)) {
                    continue;
                }

                $offered = array_column($this->pools[$operand]['items'] ?? [], 'id');

                foreach ((array) ($rule['value'] ?? []) as $value) {
                    $id = \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : 0;

                    if ($id > 0 && !\in_array($id, $offered, true)) {
                        $ids[$operand][$id] = $id;
                    }
                }
            }
        };

        $walk($root['rules']);

        if ($ids === []) {
            return (object) [];
        }

        // Objects, so an id map can never encode as a JSON list.
        return (object) array_map(
            static fn (array $names): object => (object) $names,
            ItemFieldsAdminHelper::valueNamesByOperand(idsByOperand: array_map('array_values', $ids)),
        );
    }

    /*
     *  Helpers
     */

    private function esc($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /**
     * JSON-encode an array (unescaped unicode/slashes) and HTML-escape it for safe
     * use inside a single-quoted HTML attribute.
     *
     * @param array $v The value to encode
     *
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
     * Encode an arbitrary stored value (scalar / array / null) as attribute-safe JSON.
     *
     * @param mixed $v The stored rule value
     *
     *
     * @since  1.0.0
     */
    private function escAttrJsonRaw($v): string
    {
        return htmlspecialchars(
            json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null',
            ENT_QUOTES,
            'UTF-8',
        );
    }

    /**
     * Fields that can act as a switch: every published field of the configured
     * family except this one (you can't gate a field on itself). The family comes
     * from the XML `source` attribute: 'formfields' (default) or 'itemfields'. Both
     * families share the builder, the schema and the engine; only the candidate
     * list (and whether enum pools exist) differs.
     *
     * @return array<int, array{id:int,name:string,text:string}>
     *
     * @since  1.0.0
     */
    private function switchableFields(): array
    {
        $source = (string) ($this->element['source'] ?? '') === 'itemfields' ? 'Itemfields' : 'Formfields';

        $model = Factory::getApplication()
            ->bootComponent('com_alfa')
            ->getMVCFactory()
            ->createModel($source, 'Administrator', ['ignore_request' => true]);

        if (!$model) {
            return [];
        }

        // Force populateState() before our setState(); otherwise it fires later
        // inside getItems() and overwrites them (esp. list.limit). Mirrors ModelField.
        $model->getState('list.ordering');
        $model->setState('filter.state', 1);
        $model->setState('filter.search', '');
        $model->setState('list.limit', 0);
        $model->setState('list.start', 0);
        // `name` is a COALESCE alias from the multilingual join; there is no
        // `a.name` column; order by a real base column both families share.
        $model->setState('list.ordering', 'a.field_name');
        $model->setState('list.direction', 'ASC');

        if ($source === 'Formfields') {
            $model->setState('filter.group_id', '');
        }

        $selfName = (string) ($this->form ? $this->form->getValue('field_name') : '');

        $out = [];

        foreach ($model->getItems() ?: [] as $row) {
            $name = (string) ($row->field_name ?? '');

            if ($name === '' || $name === $selfName) {
                continue;
            }

            $out[] = [
                'id' => (int) ($row->id ?? 0),
                'name' => $name,
                'text' => (string) ($row->name ?? $name),
            ];
        }

        return $out;
    }
}
