/**
 * Alfa Commerce: ShowOn rule evaluator (frontend twin)
 *
 * The JS half of the conditional-visibility rule walker. It MUST mirror the PHP
 * RuleEvaluator (Service/ShowOn/RuleEvaluator.php) bit-for-bit: same frozen
 * operator set, same int/string normalisation, same scalar-or-array handling,
 * same nested-group precedence, same show/hide. PHP decides initial paint and the
 * submit authority; this runs on every interaction. Both read one flat state map
 * of dot-address => stable primitive (the serialised StateBag), never labels.
 * A leaf whose operand address is absent from the state map is NEUTRAL
 * (skipped); an all-neutral rule leaves the field visible; PHP mirrors this.
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function (global) {
    'use strict';

    /** Frozen operator set: anything outside this never matches. */
    var OPERATORS = [
        '=', '!=', 'in', '!in', 'empty', '!empty',
        '<', '<=', '>', '>=', 'between',
        'contains', 'starts_with', 'ends_with'
    ];

    /** Normalise a single primitive to a trimmed string (bool -> '1'/'0'). */
    function toStr(value) {
        if (typeof value === 'boolean') {
            return value ? '1' : '0';
        }
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).trim();
    }

    /** Normalise a value to a list of strings for equality / membership. */
    function toStrList(value) {
        if (value === null || value === undefined) {
            return [];
        }
        if (Array.isArray(value)) {
            return value.map(toStr);
        }
        return [toStr(value)];
    }

    /** "Not set": null/undefined, false, '' or an empty array. '0' is NOT empty. */
    function isEmpty(value) {
        if (value === null || value === undefined || value === false || value === '') {
            return true;
        }
        return Array.isArray(value) && value.length === 0;
    }

    /** Numeric reading for ordering operators: first element when a list. */
    function toNum(value) {
        if (Array.isArray(value)) {
            value = value.length ? value[0] : 0;
        }
        var n = parseFloat(value);
        return isNaN(n) ? 0 : n;
    }

    /** Whether any member of a is also in b (string lists). */
    function intersects(a, b) {
        for (var i = 0; i < a.length; i++) {
            if (b.indexOf(a[i]) !== -1) {
                return true;
            }
        }
        return false;
    }

    /** Range test: current in [value[0], value[1]]. */
    function isBetween(current, value) {
        if (!Array.isArray(value) || value.length !== 2) {
            return false;
        }
        var n = toNum(current);
        return n >= toNum(value[0]) && n <= toNum(value[1]);
    }

    /** Apply one operator to the current state value and the rule's compare value. */
    function compare(current, operator, value) {
        if (OPERATORS.indexOf(operator) === -1) {
            return false;
        }

        var list = toStrList(current);
        var head = list.length ? list[0] : '';

        switch (operator) {
            case 'empty':       return isEmpty(current);
            case '!empty':      return !isEmpty(current);
            case '=':           return list.indexOf(toStr(value)) !== -1;
            case '!=':          return list.indexOf(toStr(value)) === -1;
            case 'in':          return intersects(list, toStrList(value));
            case '!in':         return !intersects(list, toStrList(value));
            case 'contains':    return head.indexOf(toStr(value)) !== -1;
            case 'starts_with': return head.lastIndexOf(toStr(value), 0) === 0;
            case 'ends_with':   return endsWith(head, toStr(value));
            case '<':           return toNum(current) < toNum(value);
            case '<=':          return toNum(current) <= toNum(value);
            case '>':           return toNum(current) > toNum(value);
            case '>=':          return toNum(current) >= toNum(value);
            case 'between':     return isBetween(current, value);
        }

        return false;
    }

    /** str.endsWith without relying on ES6 (and matching PHP's '' => true). */
    function endsWith(haystack, needle) {
        if (needle === '') {
            return true;
        }
        var at = haystack.length - needle.length;
        return at >= 0 && haystack.indexOf(needle, at) === at;
    }

    /** Build a get(address) reader over a flat state object (missing => null). */
    function reader(state) {
        if (typeof state === 'function') {
            return state;
        }
        state = state || {};
        return function (address) {
            return Object.prototype.hasOwnProperty.call(state, address) ? state[address] : null;
        };
    }

    /** Address-existence reader; a get-function state cannot tell, so present. */
    function hasReader(state) {
        if (typeof state === 'function') {
            return function () { return true; };
        }
        state = state || {};
        return function (address) {
            return Object.prototype.hasOwnProperty.call(state, address);
        };
    }

    /** Evaluate one node: a group (recurses) or a leaf. Null = neutral. */
    function matches(node, get, has) {
        if (node && Array.isArray(node.rules)) {
            var any = node.match === 'any';
            var decisive = false;

            for (var i = 0; i < node.rules.length; i++) {
                var child = node.rules[i];

                if (!child || typeof child !== 'object') {
                    continue;
                }

                var result = matches(child, get, has);

                if (result === null) {
                    continue; // Neutral child: skipped on both twins.
                }

                decisive = true;

                if (any && result) {
                    return true;
                }

                if (!any && !result) {
                    return false;
                }
            }

            return decisive ? !any : null;
        }

        var operand = node.operand || '';

        // The operand's field is not part of this form: neutral leaf.
        if (operand !== '' && !has(operand)) {
            return null;
        }

        return compare(
            get(operand),
            String(node.operator || '='),
            node.value !== undefined ? node.value : null
        );
    }

    /**
     * Conditional-visibility evaluator. Construct once, evaluate many.
     *
     * @class
     */
    function RuleEvaluator() {}

    /**
     * Whether a field carrying this rule is visible against the current state.
     * An empty / rule-less definition is always visible.
     *
     * @param {Object|null} rule  The rule tree (match/action/rules).
     * @param {Object|Function} state  A flat dot-address map, or a get(address) fn.
     * @returns {boolean}
     */
    RuleEvaluator.prototype.isVisible = function (rule, state) {
        if (!rule || !rule.rules || !rule.rules.length) {
            return true;
        }

        var matched = matches(rule, reader(state), hasReader(state));

        // Neutral rule (every operand outside this form) -> visible.
        if (matched === null) {
            return true;
        }

        return (rule.action || 'show') === 'hide' ? !matched : matched;
    };

    /** Exposed for parity tests. */
    RuleEvaluator.OPERATORS = OPERATORS;

    global.Alfa = global.Alfa || {};
    global.Alfa.ShowOn = global.Alfa.ShowOn || {};
    global.Alfa.ShowOn.RuleEvaluator = RuleEvaluator;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = RuleEvaluator;
    }
})(typeof window !== 'undefined' ? window : this);
