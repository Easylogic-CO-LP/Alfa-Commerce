/**
 * Alfa ShowOn — backend visibility-rule builder.
 *
 * The PHP field (ShowonField) server-renders the whole recursive tree and
 * ships <template data-aso-tpl="rule|group"> blocks. This script only:
 *   1. wires delegated events on the root,
 *   2. clones templates for new rule/group,
 *   3. keeps the per-sibling glue rows consistent,
 *   4. serialises the DOM into the hidden input as the engine's canonical
 *      per-glue JSON: { group:[ { rule|group, glue } ] } (strict L-to-R;
 *      last item has no glue; precedence only via nested groups).
 *
 * No "render from JSON": the server already rendered it, so the builder is
 * visible with or without this script.
 */
(function () {
    'use strict';

    function S(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

    function childGroup(itemEl) {
        return itemEl.querySelector(':scope > .aso-subgroup > .aso-group') || null;
    }

    function directItems(groupEl) {
        return S(groupEl, ':scope > .aso-items > .aso-item');
    }

    function build(root) {
        if (root.__alfaSO) { return; }
        root.__alfaSO = true;

        var store = root.querySelector('[data-aso-store]');
        var cfg   = {};
        try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
        var noVal = cfg.noValueOps || ['empty', '!empty'];
        var labels  = cfg.labels || { and: 'AND', or: 'OR' };

        // ---- glue rows -------------------------------------------------
        function makeGlue(value) {
            var row = document.createElement('div');
            row.className = 'aso-glue-row';
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'aso-glue' + (value === 'OR' ? ' aso-glue--or' : '');
            b.setAttribute('data-action', 'toggle-glue');
            b.setAttribute('data-glue', value);
            b.textContent = value === 'OR' ? labels.or : labels.and;
            row.appendChild(b);
            return row;
        }

        // Every item except the last carries a glue row; the last carries none.
        function normalize(groupEl) {
            var itemsBox = groupEl.querySelector(':scope > .aso-items');
            var items = directItems(groupEl);

            var empty = itemsBox.querySelector(':scope > .aso-empty');
            if (items.length && empty) { empty.remove(); }
            if (!items.length && !empty) {
                var e = document.createElement('div');
                e.className = 'aso-empty';
                e.textContent = labels.noRules || '';
                itemsBox.appendChild(e);
            }

            items.forEach(function (it, idx) {
                var glue = it.querySelector(':scope > .aso-glue-row');
                if (idx === items.length - 1) {
                    if (glue) { glue.remove(); }
                } else if (!glue) {
                    it.appendChild(makeGlue('AND'));
                }
            });
        }

        // Show single value input / between min-max / nothing, per operator.
        function applyOp(ruleEl) {
            var o = ruleEl.querySelector('[data-aso="op"]');
            var op = o ? o.value : '=';
            var isNo = noVal.indexOf(op) !== -1;
            var isBt = op === 'between';
            var single = ruleEl.querySelector('[data-aso="value"]');
            var betw   = ruleEl.querySelector('[data-aso-between]');
            if (single) { single.style.display = (isNo || isBt) ? 'none' : ''; }
            if (betw)   { betw.style.display   = isBt ? '' : 'none'; }
        }

        // ---- serialise DOM -> canonical JSON ---------------------------
        // ONE value per rule — never split. OR is the glue. `between`
        // reads two inputs (min/max); no-value ops -> [].
        function serializeRule(ruleEl) {
            if (!ruleEl) { return null; }
            var f = ruleEl.querySelector('[data-aso="field"]');
            var o = ruleEl.querySelector('[data-aso="op"]');
            var field = (f ? f.value : '').trim();
            if (!field) { return null; }
            var op = o ? o.value : '=';

            var values = [];
            if (noVal.indexOf(op) !== -1) {
                values = [];
            } else if (op === 'between') {
                var mn = ruleEl.querySelector('[data-aso="value-min"]');
                var mx = ruleEl.querySelector('[data-aso="value-max"]');
                var a = mn ? mn.value.trim() : '';
                var b = mx ? mx.value.trim() : '';
                if (a === '' || b === '') { return null; }
                values = [a, b];
            } else {
                var v = ruleEl.querySelector('[data-aso="value"]');
                var val = v ? v.value.trim() : '';
                if (val === '') { return null; }
                values = [val];
            }
            return { field: field, op: op, values: values };
        }

        function serializeGroup(groupEl) {
            var out = [];
            directItems(groupEl).forEach(function (it) {
                var node;
                var sub = childGroup(it);
                if (sub) {
                    var kids = serializeGroup(sub);
                    if (!kids.length) { return; }
                    node = { group: kids };
                } else {
                    var rule = serializeRule(it.querySelector(':scope > .aso-rule'));
                    if (!rule) { return; }
                    node = { rule: rule };
                }
                var gb = it.querySelector(':scope > .aso-glue-row .aso-glue');
                node.__glue = gb ? (gb.getAttribute('data-glue') === 'OR' ? 'OR' : 'AND') : null;
                out.push(node);
            });
            return out.map(function (n, i) {
                var g = n.__glue;
                delete n.__glue;
                if (i < out.length - 1) { n.glue = g || 'AND'; }
                return n;
            });
        }

        function serialize() {
            var rootGroup = root.querySelector(':scope > .aso-group--root');
            var g = rootGroup ? serializeGroup(rootGroup) : [];
            store.value = g.length ? JSON.stringify({ group: g }) : '';
        }

        // ---- actions ---------------------------------------------------
        function tpl(name) {
            var t = root.querySelector('[data-aso-tpl="' + name + '"]');
            return t.content.firstElementChild.cloneNode(true);
        }

        function addItem(groupEl, name) {
            groupEl.querySelector(':scope > .aso-items').appendChild(tpl(name));
            normalize(groupEl);
            serialize();
        }

        root.addEventListener('click', function (e) {
            var a = e.target.closest('[data-action]');
            if (!a || !root.contains(a)) { return; }
            var action = a.getAttribute('data-action');

            if (action === 'add-rule' || action === 'add-group') {
                addItem(a.closest('.aso-group'), action === 'add-rule' ? 'rule' : 'group');
            } else if (action === 'remove') {
                var item = a.closest('.aso-item');
                var grp  = item.parentElement.closest('.aso-group');
                item.remove();
                normalize(grp);
                serialize();
            } else if (action === 'toggle-glue') {
                var or = a.getAttribute('data-glue') !== 'OR';
                a.setAttribute('data-glue', or ? 'OR' : 'AND');
                a.classList.toggle('aso-glue--or', or);
                a.textContent = or ? labels.or : labels.and;
                serialize();
            }
        });

        root.addEventListener('change', function (e) {
            var op = e.target.closest('select[data-aso="op"]');
            if (op) { applyOp(op.closest('.aso-rule')); }
            if (e.target.closest('[data-aso]')) { serialize(); }
        });

        root.addEventListener('input', function (e) {
            if (e.target.closest('[data-aso="value"], [data-aso="value-min"], [data-aso="value-max"]')) {
                serialize();
            }
        });

        // Normalise groups, sync each rule's inputs to its operator, snapshot.
        S(root, '.aso-group').forEach(normalize);
        S(root, '.aso-rule').forEach(applyOp);
        serialize();
    }

    function init() {
        document.querySelectorAll('.aso[data-config]').forEach(build);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
