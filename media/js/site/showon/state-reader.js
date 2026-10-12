/**
 * Alfa Commerce: ShowOn state reader (frontend)
 *
 * Builds the flat state object the JS RuleEvaluator consumes, straight from the
 * DOM (the live twin of the PHP StateBagBuilder). For every field surface
 * (a `[data-showon-name]` element) it reads the buyer's current pick(s) from the
 * native inputs inside it as `<name>.value` (a value-pool id, an array of ids for
 * multi-select, or null when nothing is chosen), and lifts any computed facets a
 * complex field publishes as `data-state-<facet>` attributes into `<name>.<facet>`.
 *
 * Values stay raw ids/strings (never labels), so PHP (render/submit) and JS agree.
 *
 * One call reads ONE scope: only the surfaces whose nearest `[data-showon-scope]`
 * is $root contribute, and a value provider is handed that same $root, so a form
 * repeating a field in two sections (delivery / invoice) keeps two answers.
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function (global) {
    'use strict';

    var SKIP_TYPES = ['submit', 'button', 'reset', 'file', 'image'];

    /**
     * The SCOPE an element answers in: its nearest `[data-showon-scope]` ancestor,
     * or $root when it has none. A form may repeat the same field in two sections
     * (delivery / invoice), identical names, separate answers, and a flat state
     * would let one overwrite the other. Read from the PARENT up, so a section that
     * is itself a target (the invoice block, gated on its own checkbox) is judged
     * in the scope that CONTAINS it, where its trigger lives.
     */
    function scopeOf(el, root) {
        var parent = el.parentElement && el.parentElement.closest('[data-showon-scope]');

        return parent && root.contains(parent) ? parent : root;
    }

    /** The buyer's current pick(s) within one field surface: scalar id, array, or null. */
    function readValue(wrapper) {
        var values = [];
        var nodes = wrapper.querySelectorAll('select, input, textarea');

        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];

            // A hidden surface disables its inputs; a disabled input does not
            // contribute to state (so a hidden field cannot satisfy another field's
            // rule), mirroring how it would not post to PHP.
            if (el.disabled) {
                continue;
            }

            var tag = (el.tagName || '').toLowerCase();

            if (tag === 'select') {
                if (el.multiple && el.options) {
                    for (var j = 0; j < el.options.length; j++) {
                        if (el.options[j].selected) {
                            values.push(el.options[j].value);
                        }
                    }
                } else if (el.value !== '' && el.value != null) {
                    values.push(el.value);
                }

                continue;
            }

            var type = (el.type || 'text').toLowerCase();

            if (type === 'radio' || type === 'checkbox') {
                if (el.checked) {
                    values.push(el.value);
                }
            } else if (SKIP_TYPES.indexOf(type) === -1) {
                if (el.value !== '' && el.value != null) {
                    values.push(el.value);
                }
            }
        }

        if (values.length === 0) {
            return null;
        }

        return values.length === 1 ? values[0] : values;
    }

    /**
     * Computed facets a complex field publishes on the surface as data-state-<facet>.
     * Returns how many were found, so the caller can tell a facet-publishing complex
     * field from a plain enumerable one.
     */
    function readFacets(wrapper, name, state) {
        var attrs = wrapper.attributes;
        var found = 0;

        if (!attrs) {
            return 0;
        }

        for (var i = 0; i < attrs.length; i++) {
            var attr = attrs[i];

            if (attr.name.indexOf('data-state-') === 0) {
                var facet = attr.name.slice('data-state-'.length);
                state[name + '.' + facet] = attr.value;
                found++;
            }
        }

        return found;
    }

    /** Build the flat state map (`<name>.value`, `<name>.<facet>`) under a root. */
    function buildState(root) {
        var state = {};
        var wrappers = root.querySelectorAll('[data-showon-name]');
        var providers = (global.Alfa && global.Alfa.ShowOn && global.Alfa.ShowOn._providers) || {};

        for (var i = 0; i < wrappers.length; i++) {
            var wrapper = wrappers[i];
            var name = wrapper.getAttribute('data-showon-name');

            if (!name || scopeOf(wrapper, root) !== root) {
                continue;
            }

            var facets = readFacets(wrapper, name, state);

            // A plugin whose widget value cannot be read from the native inputs may
            // register a reader keyed by the field's data-showon-type (the queue
            // facade in the engine). When one is present it owns the value; otherwise
            // we read the native inputs. Any provider error falls back to native.
            var type = wrapper.getAttribute('data-showon-type') || '';
            var provider = type && providers[type];
            var value;

            if (provider) {
                try {
                    // Third arg is the exact surface this value belongs to. A
                    // provider resolving by name alone would find the first copy
                    // in the root, which is the wrong one for a repeated field;
                    // older providers ignoring it still work in a single-copy form.
                    value = provider(name, root, wrapper);
                } catch (e) {
                    value = readValue(wrapper);
                }

                if (value === undefined) {
                    value = null;
                }
            } else {
                value = readValue(wrapper);
            }

            // Enumerable field -> always carry `.value` (null = "not set", a real fact).
            // A complex field declares facets and owns its addresses; only give it a
            // synthetic `.value` if its markup actually produced one (never a phantom
            // null), so the JS bag matches the PHP StateBagBuilder exactly.
            if (facets === 0 || value !== null) {
                state[name + '.value'] = value;
            }
        }

        return state;
    }

    var StateReader = { buildState: buildState, readValue: readValue, scopeOf: scopeOf };

    global.Alfa = global.Alfa || {};
    global.Alfa.ShowOn = global.Alfa.ShowOn || {};
    global.Alfa.ShowOn.StateReader = StateReader;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = StateReader;
    }
})(typeof window !== 'undefined' ? window : this);
