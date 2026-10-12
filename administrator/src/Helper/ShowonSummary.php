<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Helper;

use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

/**
 * Renders a ShowOn rule as ONE human-readable line, server-side, for the always-on
 * per-field summary in the combinations editor (e.g. "Show when Size is any of
 * Medium, Large"). The frontend never needs this (it is purely an admin display),
 * so it lives in PHP and is the single source of truth for rule wording (the save
 * endpoint returns it, so the UI swaps text without a JS re-implementation).
 *
 * IDs in, labels out: it maps each operand to its field label and each value id to
 * its pool label, reusing the existing COM_ALFA_SHOWON_* operator / glue strings.
 *
 * @since  1.3.0
 */
final class ShowonSummary
{
    /**
     * The summary line for a rule against an item's fields. '' when there is no rule
     * (the field is always shown).
     *
     * @param string|array $rule The stored rule (JSON string or decoded array).
     * @param object[] $fields The item's fields (carry ->field_name, ->display_name, ->values).
     *
     *
     * @since  1.3.0
     */
    public static function forItemFields($rule, array $fields): string
    {
        $operandLabels = [];
        $valueLabels = [];

        foreach ($fields as $field) {
            $name = (string) ($field->field_name ?? '');

            if ($name !== '') {
                $operandLabels[$name . '.value'] = (string) ($field->display_name ?? $name);
            }

            foreach ($field->values ?? [] as $value) {
                $valueLabels[(int) $value->id] = (string) ($value->display_name ?? ('#' . $value->id));
            }
        }

        return self::text(rule: $rule, operandLabels: $operandLabels, valueLabels: $valueLabels);
    }

    /**
     * Per-field summary lines for a list of field DEFINITIONS, keyed by field id:
     * the global ShowOn rule each one carries in its params, rendered for the admin
     * list. Operand labels come from the fields themselves (machine name -> display
     * name); only fields that actually carry a rule are returned. Form-field values
     * are raw (no value pool), so the default empty $valueLabels prints them as-is;
     * a caller with a value pool (e.g. item fields) passes the id -> label map.
     *
     * @param object[] $fields Field rows (carry ->id, ->field_name, ->name, ->params).
     * @param array $valueLabels value id => label (empty for raw/free values).
     *
     * @return array<int, string> field id => summary line.
     *
     * @since  1.3.0
     */
    public static function globalSummaries(array $fields, array $valueLabels = []): array
    {
        $operandLabels = [];

        foreach ($fields as $field) {
            $name = (string) ($field->field_name ?? '');

            if ($name !== '') {
                $operandLabels[$name . '.value'] = (string) ($field->name ?? $name);
            }
        }

        $out = [];

        foreach ($fields as $field) {
            $params = \is_string($field->params ?? null) ? (json_decode($field->params, true) ?: []) : (array) ($field->params ?? []);
            $rule = (string) ($params['showon'] ?? '');

            if ($rule === '' || $rule === '{}' || $rule === 'null' || $rule === '[]') {
                continue;
            }

            $summary = self::text(rule: $rule, operandLabels: $operandLabels, valueLabels: $valueLabels);

            if ($summary !== '') {
                $out[(int) $field->id] = $summary;
            }
        }

        return $out;
    }

    /**
     * The summary line from explicit label maps (operand -> field label, value id ->
     * label). Reuses the COM_ALFA_SHOWON_* operator / action / glue strings.
     *
     * @param string|array $rule The stored rule (JSON string or decoded array).
     * @param array $operandLabels operand address => field label.
     * @param array $valueLabels value id => label.
     *
     *
     * @since  1.3.0
     */
    public static function text($rule, array $operandLabels, array $valueLabels): string
    {
        $rule = \is_array($rule) ? $rule : (json_decode((string) $rule, true) ?: []);

        if (empty($rule['rules']) || !\is_array($rule['rules'])) {
            return '';
        }

        $body = self::group($rule, $operandLabels, $valueLabels);

        if ($body === '') {
            return '';
        }

        $action = ($rule['action'] ?? 'show') === 'hide'
            ? Text::_('COM_ALFA_SHOWON_ACTION_HIDE')
            : Text::_('COM_ALFA_SHOWON_ACTION_SHOW');

        return trim($action . ' ' . $body);
    }

    /**
     * One group -> its members joined by AND/OR; nested groups parenthesised.
     *
     * @param array $node A group node (carries ->match, ->rules).
     * @param array $operandLabels operand => label.
     * @param array $valueLabels value id => label.
     *
     *
     * @since  1.3.0
     */
    private static function group(array $node, array $operandLabels, array $valueLabels): string
    {
        $glue = ($node['match'] ?? 'all') === 'any'
            ? ' ' . Text::_('COM_ALFA_SHOWON_OR') . ' '
            : ' ' . Text::_('COM_ALFA_SHOWON_AND') . ' ';

        $parts = [];

        foreach ($node['rules'] as $child) {
            if (!\is_array($child)) {
                continue;
            }

            if (isset($child['rules']) && \is_array($child['rules'])) {
                $inner = self::group($child, $operandLabels, $valueLabels);

                if ($inner !== '') {
                    $parts[] = '(' . $inner . ')';
                }

                continue;
            }

            $leaf = self::leaf($child, $operandLabels, $valueLabels);

            if ($leaf !== '') {
                $parts[] = $leaf;
            }
        }

        return implode($glue, $parts);
    }

    /**
     * One condition -> "Field operator value".
     *
     * @param array $rule A leaf node (operand/operator/value).
     * @param array $operandLabels operand => label.
     * @param array $valueLabels value id => label.
     *
     *
     * @since  1.3.0
     */
    private static function leaf(array $rule, array $operandLabels, array $valueLabels): string
    {
        $operand = (string) ($rule['operand'] ?? '');

        if ($operand === '') {
            return '';
        }

        $operator = (string) ($rule['operator'] ?? '=');
        $name = $operandLabels[$operand] ?? $operand;
        $op = isset(ShowonOperators::LABELS[$operator]) ? Text::_(ShowonOperators::LABELS[$operator]) : $operator;

        if (\in_array($operator, ShowonOperators::NO_VALUE_OPS, true)) {
            return $name . ' ' . $op;
        }

        $value = $rule['value'] ?? null;

        if ($operator === 'between' && \is_array($value) && \count($value) === 2) {
            return $name . ' ' . $op . ' ' . self::label($value[0], $valueLabels) . ' ... ' . self::label($value[1], $valueLabels);
        }

        return trim($name . ' ' . $op . ' ' . self::valueText($value, $valueLabels));
    }

    /** A scalar or array of value ids -> comma-joined labels. */
    private static function valueText($value, array $valueLabels): string
    {
        if (\is_array($value)) {
            return implode(', ', array_map(static fn ($v) => self::label($v, $valueLabels), $value));
        }

        return self::label($value, $valueLabels);
    }

    /** One value id -> its pool label, or the raw value when unknown (text/number). */
    private static function label($value, array $valueLabels): string
    {
        if (is_numeric($value) && isset($valueLabels[(int) $value])) {
            return $valueLabels[(int) $value];
        }

        return (string) $value;
    }
}
