<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Site\Service\ShowOn;

use Alfa\Component\Alfa\Site\Service\FieldState\StateBag;

defined('_JEXEC') or die;

/**
 * The conditional-visibility consumer: given a {@see StateBag} and one rule object,
 * decide whether a field is shown. It is the PHP half of the rule walker; its JS
 * twin must mirror this exact behaviour, so the logic here is the frozen contract.
 *
 * A rule is a small JSON tree stored on the field definition. A LEAF compares one
 * dot-address against a value; a GROUP combines children by `all` (AND) or `any`
 * (OR), and groups nest for precedence:
 *
 *   {
 *     "match": "all",            // all | any
 *     "action": "show",          // show | hide
 *     "rules": [
 *       { "operand": "size.value",         "operator": "in", "value": [12, 13] },
 *       { "operand": "configurator.ready", "operator": "=",  "value": true },
 *       { "match": "any", "rules": [ ... ] }   // a nested group
 *     ]
 *   }
 *
 * Operands are ADDRESSES (`size.value`), compare values are stable IDS / primitives
 * (never labels). Two frozen normalisation rules keep PHP and JS in lock-step:
 *
 *   - equality (`=` `!=` `in` `!in`) compares AS STRING (both sides normalised), so
 *     an integer id and the string the browser posts for it match;
 *   - only the ordering operators (`< <= > >= between`) cast to float.
 *
 * A field's value may be a scalar or an array (multi-select); membership and `in`
 * treat both. An absent address is "not set", never the empty string.
 *
 * NEUTRALITY: a leaf whose operand FIELD is not part of the current form
 * (the bag has no such address) cannot be evaluated there and is skipped;
 * a rule whose leaves are all skipped leaves the field VISIBLE. A present
 * field with no value is NOT neutral: that is a real "not set" fact.
 *
 * @since  2.0.0
 */
final class RuleEvaluator
{
    /**
     * The frozen operator set. Any operator outside this list never matches.
     *
     * @var string[]
     */
    private const OPERATORS = [
        '=', '!=', 'in', '!in', 'empty', '!empty',
        '<', '<=', '>', '>=', 'between',
        'contains', 'starts_with', 'ends_with',
    ];

    /**
     * Whether a field carrying this rule should be visible against the current state.
     * An empty / rule-less definition is always visible.
     *
     * @param array|null $rule The rule tree (`match`/`action`/`rules`), or null/empty.
     * @param StateBag $bag The state every consumer reads.
     *
     *
     * @since  2.0.0
     */
    public function isVisible(?array $rule, StateBag $bag): bool
    {
        if (empty($rule) || empty($rule['rules'])) {
            return true;
        }

        $matched = $this->matches($rule, $bag);

        // Neutral rule (every operand outside this form) -> visible.
        if ($matched === null) {
            return true;
        }

        return ($rule['action'] ?? 'show') === 'hide' ? !$matched : $matched;
    }

    /**
     * Evaluate one node: a group (recurses) or a leaf (compares).
     *
     * @param array $node A group (`rules`) or a leaf (`operand`/`operator`/`value`).
     * @param StateBag $bag The state.
     *
     * @return bool|null Null = neutral (no evaluable leaf in this form).
     *
     * @since  2.0.0
     */
    private function matches(array $node, StateBag $bag): ?bool
    {
        if (isset($node['rules']) && \is_array($node['rules'])) {
            $any = ($node['match'] ?? 'all') === 'any';
            $decisive = false;

            foreach ($node['rules'] as $child) {
                if (!\is_array($child)) {
                    continue;
                }

                $result = $this->matches($child, $bag);

                if ($result === null) {
                    continue; // Neutral child: skipped on both twins.
                }

                $decisive = true;

                if ($any && $result) {
                    return true;
                }

                if (!$any && !$result) {
                    return false;
                }
            }

            // all-passed -> true; any-matched-none -> false; all-neutral -> neutral
            return $decisive ? !$any : null;
        }

        $operand = (string) ($node['operand'] ?? '');

        // The operand's field is not part of this form; the leaf is neutral.
        if ($operand !== '' && !$bag->has($operand)) {
            return null;
        }

        return $this->compare(
            current: $bag->get($operand),
            operator: (string) ($node['operator'] ?? '='),
            value: $node['value'] ?? null,
        );
    }

    /**
     * Apply one operator to the current state value and the rule's compare value.
     *
     * @param mixed $current The bag value at the operand (scalar, array, or null).
     * @param string $operator A member of {@see self::OPERATORS}.
     * @param mixed $value The rule's compare value (id, array of ids, or primitive).
     *
     *
     * @since  2.0.0
     */
    private function compare(mixed $current, string $operator, mixed $value): bool
    {
        if (!\in_array($operator, self::OPERATORS, true)) {
            return false;
        }

        $list = $this->toStringList($current);

        return match ($operator) {
            'empty' => $this->isEmpty($current),
            '!empty' => !$this->isEmpty($current),
            '=' => \in_array($this->toString($value), $list, true),
            '!=' => !\in_array($this->toString($value), $list, true),
            'in' => (bool) \array_intersect($list, $this->toStringList($value)),
            '!in' => !\array_intersect($list, $this->toStringList($value)),
            'contains' => str_contains($list[0] ?? '', $this->toString($value)),
            'starts_with' => str_starts_with($list[0] ?? '', $this->toString($value)),
            'ends_with' => str_ends_with($list[0] ?? '', $this->toString($value)),
            '<' => $this->toFloat($current) < $this->toFloat($value),
            '<=' => $this->toFloat($current) <= $this->toFloat($value),
            '>' => $this->toFloat($current) > $this->toFloat($value),
            '>=' => $this->toFloat($current) >= $this->toFloat($value),
            'between' => $this->isBetween($current, $value),
        };
    }

    /**
     * Range test: true when current in [value[0], value[1]].
     *
     * @param mixed $current The current value.
     * @param mixed $value A two-element bounds array.
     *
     *
     * @since  2.0.0
     */
    private function isBetween(mixed $current, mixed $value): bool
    {
        if (!\is_array($value) || \count($value) !== 2) {
            return false;
        }

        $bounds = \array_values($value);
        $n = $this->toFloat($current);

        return $n >= $this->toFloat($bounds[0]) && $n <= $this->toFloat($bounds[1]);
    }

    /**
     * "Not set": null, false, '' or an empty array. A literal '0' id is NOT empty.
     *
     * @param mixed $value The value to test.
     *
     *
     * @since  2.0.0
     */
    private function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return true;
        }

        return \is_array($value) && $value === [];
    }

    /**
     * Normalise a value to a list of trimmed strings for equality / membership.
     *
     * @param mixed $value Scalar, array, bool, or null.
     *
     * @return string[]
     *
     * @since  2.0.0
     */
    private function toStringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (\is_array($value)) {
            return array_map([$this, 'toString'], $value);
        }

        return [$this->toString($value)];
    }

    /**
     * Normalise a single primitive to a trimmed string (bool -> '1'/'0').
     *
     * @param mixed $value The primitive.
     *
     *
     * @since  2.0.0
     */
    private function toString(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) ($value ?? ''));
    }

    /**
     * Numeric reading for ordering operators: the first value when a list.
     *
     * @param mixed $value Scalar, array, or null.
     *
     *
     * @since  2.0.0
     */
    private function toFloat(mixed $value): float
    {
        if (\is_array($value)) {
            $value = $value[0] ?? 0;
        }

        return (float) $value;
    }
}
