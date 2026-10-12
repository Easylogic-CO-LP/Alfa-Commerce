/**
 * Alfa ShowOn: backend visibility-rule builder.
 *
 * The PHP field (ShowonField) server-renders the tree and ships
 * <template data-aso-tpl="rule|group"> blocks plus a data-config carrying the
 * frozen operators, the embedded value pools (operand -> {domain, items}) and the
 * labels. This script:
 *   1. hydrates each rule's value control from its operand's pool (picker shows
 *      labels, stores ids),
 *   2. wires delegated events (add/remove, match/action toggles, operand/operator
 *      change -> re-render the value control),
 *   3. serialises the DOM into the hidden input as the engine's canonical schema,
 *      on a user edit only:
 *      { match:"all|any", action:"show|hide", rules:[ {operand,operator,value} | {match,rules} ] }.
 *
 * Compare values are always ids / primitives, never labels. What the pickers no
 * longer offer (an unpublished or deleted field, a value that is unpublished or not
 * on this item) stays selected as an "unavailable" option, so a rule survives a
 * save and an unrelated edit. A stored operator its operand's type does not allow
 * stays selected and marked, and a row the author has not edited goes back as
 * stored.
 */
(function () {
    'use strict';

    /** Numeric-looking strings become Numbers, so stored ids match the value pool. */
    function coerce(v) {
        if (typeof v !== 'string') {
            return v;
        }
        return /^-?\d+$/.test(v) ? parseInt(v, 10) : v;
    }

    function child(el, sel) {
        return el ? el.querySelector(':scope > ' + sel) : null;
    }

    function directItems(groupEl) {
        var items = child(groupEl, '.aso-items');
        return items ? Array.prototype.slice.call(items.children).filter(function (c) {
            return c.classList && c.classList.contains('aso-item');
        }) : [];
    }

    /* operators (domain) */

    /** The operand's value domain: drives which operators are offered. */
    function domainOf(operand, config) {
        var pool = config.pools[operand];
        return (pool && pool.domain) || 'text';
    }

    /** Whether a row's selected operand is a stored one the field no longer offers. */
    function operandUnavailable(operandSel) {
        var opt = operandSel.options[operandSel.selectedIndex];

        return !!opt && opt.hasAttribute('data-aso-unavailable');
    }

    /**
     * Whether a row's selected operator is a stored one its operand's type does not
     * allow, or one outside the known set: the server kept it selected and marked.
     *
     * @since 1.14.0
     */
    function operatorUnavailable(operatorSel) {
        var opt = operatorSel.options[operatorSel.selectedIndex];

        return !!opt && opt.hasAttribute('data-aso-unavailable');
    }

    /**
     * The value domain of a row's selected operand. An operand no longer offered
     * has no pool, so its domain is unknown: null, which offers every operator and
     * so keeps the stored one instead of swapping in the first allowed.
     */
    function rowDomain(operandSel, config) {
        return operandUnavailable(operandSel) ? null : domainOf(operandSel.value, config);
    }

    /** Rebuild an operator <select> to the operators valid for a domain (null: all). */
    function renderOperators(operatorSel, config, domain, keep) {
        var allowed = (domain !== null && config.domainOps[domain]) || Object.keys(config.operators);
        var current = keep && allowed.indexOf(keep) !== -1 ? keep : allowed[0];

        operatorSel.innerHTML = '';

        allowed.forEach(function (op) {
            var o = document.createElement('option');
            o.value = op;
            o.textContent = config.operators[op] || op;

            if (op === current) {
                o.selected = true;
            }

            operatorSel.appendChild(o);
        });
    }

    /** Sync one rule row. */
    function hydrateRow(ruleEl, config) {
        // The server rendered this row's operators; render its value.
        renderValue(ruleEl.querySelector('[data-aso="value"]'), config);
    }

    /* value control */

    function renderValue(valueEl, config) {
        var rule = valueEl.closest('.aso-rule');
        var operandSel = rule.querySelector('[data-aso="operand"]');
        var operatorSel = rule.querySelector('[data-aso="operator"]');
        var operand = operandSel.value;
        var operator = operatorSel.value;
        var stored = storedOf(valueEl);
        var rowUnavailable = operandUnavailable(operandSel) || operatorUnavailable(operatorSel);
        // Stored ids the pickers do not offer are named only under a comparison of ids.
        var names = !operatorUnavailable(operatorSel) && config.idOps.indexOf(operator) !== -1 ? (config.valueNames[operand] || {}) : {};

        valueEl.innerHTML = '';

        if (config.noValueOps.indexOf(operator) !== -1) {
            return;
        }

        var pool = config.pools[operand] || null;
        var isMulti = config.multiOps.indexOf(operator) !== -1;
        var isBetween = operator === config.betweenOp;

        if (isBetween) {
            valueEl.appendChild(numberInput('value-min', Array.isArray(stored) ? stored[0] : ''));
            valueEl.appendChild(sep());
            valueEl.appendChild(numberInput('value-max', Array.isArray(stored) ? stored[1] : ''));
            return;
        }

        if (!rowUnavailable && pool && pool.items && pool.items.length) {
            valueEl.appendChild(selectControl(pool.items, stored, isMulti || Array.isArray(stored), config, names));
            return;
        }

        // Nothing to pick from, or a row whose operand or comparison is no longer
        // offered. A stored list, or any stored value of such a row, stays as
        // selected unavailable options so a save keeps it: a text input would
        // flatten a list into one string, and a single select would keep only its
        // last entry, so a list is shown as a list. A list field that offers no
        // value today (every value unpublished) keeps its stored ids the same way,
        // by name and marked, never as text to type over.
        if (storedValues(stored).length && (Array.isArray(stored) || rowUnavailable || (pool && pool.domain === 'enum'))) {
            valueEl.appendChild(selectControl([], stored, isMulti || Array.isArray(stored), config, names));
            return;
        }

        valueEl.appendChild(freeInput(stored));
    }

    /** A stored value as a list: a multi value as is, a scalar as one entry, blanks dropped. */
    function storedValues(stored) {
        return (Array.isArray(stored) ? stored : [stored]).filter(function (v) {
            return v !== null && v !== undefined && v !== '';
        });
    }

    /**
     * The stored value a row's value container carries (its data-value JSON), or null.
     *
     * @since 1.14.0
     */
    function storedOf(valueEl) {
        try {
            return JSON.parse(valueEl.dataset.value || 'null');
        } catch (e) {
            return null;
        }
    }

    /**
     * An option for a stored value the picker no longer offers: selected and marked,
     * so a save keeps it and the author sees why it is not among the others. It reads
     * by the value's name where the server gave one, else by the stored value.
     */
    function unavailableOption(value, config, names) {
        var o = document.createElement('option');
        o.value = value;
        o.textContent = config.labels.unavailable.replace('%s', function () { return Object.prototype.hasOwnProperty.call(names, value) ? names[value] : value; });
        o.selected = true;
        o.setAttribute('data-aso-unavailable', '');
        return o;
    }

    function selectControl(items, stored, multi, config, names) {
        var sel = document.createElement('select');
        sel.className = 'aso-select aso-select--value';
        sel.setAttribute('data-aso', 'value-control');

        if (multi) {
            sel.multiple = true;
        }

        var chosen = {};
        var offered = {};

        storedValues(stored).forEach(function (v) { chosen[String(v)] = true; });

        if (!multi) {
            var ph = document.createElement('option');
            ph.value = '';
            ph.textContent = '-';
            sel.appendChild(ph);
        }

        items.forEach(function (it) {
            var o = document.createElement('option');
            o.value = String(it.id);
            o.textContent = it.label;
            offered[String(it.id)] = true;

            if (chosen[String(it.id)]) {
                o.selected = true;
            }

            sel.appendChild(o);
        });

        // A stored value the pool no longer offers (unpublished, or not on this
        // item) stays selected and marked, so a save keeps it instead of dropping it.
        storedValues(stored).forEach(function (v) {
            var key = String(v);

            if (!offered[key]) {
                offered[key] = true;
                sel.appendChild(unavailableOption(key, config, names));
            }
        });

        return sel;
    }

    function freeInput(stored) {
        var i = document.createElement('input');
        i.type = 'text';
        i.className = 'aso-input';
        i.setAttribute('data-aso', 'value-control');
        i.value = (stored === null || stored === undefined) ? '' : stored;
        return i;
    }

    function numberInput(role, val) {
        var i = document.createElement('input');
        i.type = 'number';
        i.className = 'aso-input';
        i.setAttribute('data-aso', role);
        i.value = (val === null || val === undefined) ? '' : val;
        return i;
    }

    function sep() {
        var s = document.createElement('span');
        s.className = 'aso-between-sep';
        s.textContent = '...';
        return s;
    }

    /** Read a rule's value control back into a primitive / array (ids), or undefined. */
    function readValue(rule, operator, config) {
        if (config.noValueOps.indexOf(operator) !== -1) {
            return undefined;
        }

        if (operator === config.betweenOp) {
            var min = rule.querySelector('[data-aso="value-min"]');
            var max = rule.querySelector('[data-aso="value-max"]');
            return [coerce(min ? min.value : ''), coerce(max ? max.value : '')];
        }

        var ctrl = rule.querySelector('[data-aso="value-control"]');

        if (!ctrl) {
            return '';
        }

        if (ctrl.multiple) {
            return Array.prototype.slice.call(ctrl.selectedOptions).map(function (o) { return coerce(o.value); });
        }

        return coerce(ctrl.value);
    }

    /**
     * A row's value exactly as stored. Used for a row the author has not touched:
     * its controls cannot show every stored shape (a boolean, a list in its stored
     * order) without changing it. A no-value operator has none, as when the controls
     * are read.
     *
     * @since 1.14.0
     */
    function storedValue(ruleEl, operator, config) {
        if (config.noValueOps.indexOf(operator) !== -1) {
            return undefined;
        }

        return storedOf(ruleEl.querySelector('[data-aso="value"]'));
    }

    /**
     * Mark the rule row an edit came from: from then on its controls, not its stored
     * value, speak for it.
     *
     * @since 1.14.0
     */
    function touch(target) {
        var row = target && target.closest ? target.closest('.aso-rule') : null;

        if (row) {
            row.__asoTouched = true;
        }
    }

    /* serialise */

    function serializeRule(ruleEl, config) {
        var operandSel = ruleEl.querySelector('[data-aso="operand"]');
        var operand = operandSel.value;

        if (!operand) {
            return null;
        }

        var operator = ruleEl.querySelector('[data-aso="operator"]').value;
        var out = { operand: operand, operator: operator };
        // An edit elsewhere re-writes the whole rule: a row the author has not edited goes back
        // with its stored value as is.
        var value = ruleEl.__asoTouched
            ? readValue(ruleEl, operator, config)
            : storedValue(ruleEl, operator, config);

        if (value !== undefined) {
            out.value = value;
        }

        return out;
    }

    function serializeGroup(groupEl, isRoot, config) {
        var head = child(groupEl, '.aso-group-head');
        var match = head ? head.querySelector('[data-action="toggle-match"]') : null;
        var out = { match: (match && match.dataset.match === 'any') ? 'any' : 'all', rules: [] };

        if (isRoot) {
            var action = head ? head.querySelector('[data-action="toggle-action"]') : null;
            out.action = (action && action.dataset.actionMode === 'hide') ? 'hide' : 'show';
        }

        directItems(groupEl).forEach(function (item) {
            if (item.classList.contains('aso-item--group')) {
                var inner = child(item, '.aso-group');

                if (inner) {
                    out.rules.push(serializeGroup(inner, false, config));
                }
            } else {
                var rule = serializeRule(item.querySelector('.aso-rule'), config);

                if (rule) {
                    out.rules.push(rule);
                }
            }
        });

        return out;
    }

    function serialize(root, config) {
        var store = root.querySelector('[data-aso-store]');
        var rootGroup = root.querySelector('.aso-group--root');
        var tree = serializeGroup(rootGroup, true, config);

        store.value = tree.rules.length ? JSON.stringify(tree) : '';
    }


    /* structure */

    function emptyNotice(config) {
        var d = document.createElement('div');
        d.className = 'aso-empty';
        d.textContent = config.labels.noRules || '';
        return d;
    }

    function addItem(groupEl, tpl, config) {
        if (!tpl) {
            return;
        }

        var items = child(groupEl, '.aso-items');
        var empty = items.querySelector('.aso-empty');

        if (empty) {
            empty.remove();
        }

        items.appendChild(tpl.content.cloneNode(true));

        items.lastElementChild.querySelectorAll('.aso-rule')
            .forEach(function (r) { hydrateRow(r, config); });
    }

    function removeItem(itemEl, config) {
        var items = itemEl.parentNode;
        itemEl.remove();

        if (items && !items.querySelector('.aso-item')) {
            items.appendChild(emptyNotice(config));
        }
    }

    /* wiring */

    function init(root) {
        if (root.__asoInit) {
            return;
        }

        root.__asoInit = true;

        var config;

        try {
            config = JSON.parse(root.dataset.config || '{}');
        } catch (e) {
            return;
        }

        config.operators = config.operators || {};
        config.domainOps = config.domainOps || {};
        config.pools = config.pools || {};
        config.multiOps = config.multiOps || [];
        config.noValueOps = config.noValueOps || [];
        config.labels = config.labels || {};
        config.idOps = config.idOps || [];
        config.valueNames = config.valueNames || {};

        var tplRule = root.querySelector('template[data-aso-tpl="rule"]');
        var tplGroup = root.querySelector('template[data-aso-tpl="group"]');

        // Hydrate each rule's value control; its operators are the server's.
        root.querySelectorAll('.aso-rule').forEach(function (r) { hydrateRow(r, config); });

        root.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-action]');

            if (!btn || !root.contains(btn)) {
                return;
            }

            var action = btn.dataset.action;

            if (action === 'add-rule' || action === 'add-group') {
                addItem(btn.closest('.aso-group'), action === 'add-rule' ? tplRule : tplGroup, config);
                serialize(root, config);
            } else if (action === 'remove') {
                removeItem(btn.closest('.aso-item'), config);
                serialize(root, config);
            } else if (action === 'toggle-match') {
                var m = btn.dataset.match === 'any' ? 'all' : 'any';
                btn.dataset.match = m;
                btn.querySelector('[data-aso-match-label]').textContent =
                    m === 'any' ? config.labels.any : config.labels.all;
                serialize(root, config);
            } else if (action === 'toggle-action') {
                var a = btn.dataset.actionMode === 'hide' ? 'show' : 'hide';
                btn.dataset.actionMode = a;
                btn.querySelector('[data-aso-action-label]').textContent =
                    a === 'hide' ? config.labels.hide : config.labels.show;
                serialize(root, config);
            }
        });

        root.addEventListener('change', function (e) {
            var aso = e.target.getAttribute && e.target.getAttribute('data-aso');
            var ruleEl = (aso === 'operand' || aso === 'operator') ? e.target.closest('.aso-rule') : null;

            touch(e.target);

            // A new operand re-filters the operators to its value domain first.
            if (aso === 'operand' && ruleEl) {
                var operatorSel = ruleEl.querySelector('[data-aso="operator"]');
                renderOperators(operatorSel, config, rowDomain(e.target, config), operatorSel.value);
            }

            // Operand or operator change re-renders that row's value control.
            if (ruleEl) {
                var value = ruleEl.querySelector('[data-aso="value"]');

                if (value) {
                    value.dataset.value = 'null';
                    renderValue(value, config);
                }
            }

            serialize(root, config);
        });

        root.addEventListener('input', function (e) {
            touch(e.target);
            serialize(root, config);
        });

        // No serialise on load: the store already holds the saved rule verbatim.
        // Re-deriving it from the DOM here rewrote it, and dropped whatever the
        // pickers no longer offer, on a Save that touched nothing.
    }

    function boot(scope) {
        (scope || document).querySelectorAll('.aso[data-config]').forEach(init);
    }

    // Exposed so a lazily-injected builder (e.g. the visibility popup body, added
    // after DOMContentLoaded) can be initialised by its host script.
    window.Alfa = window.Alfa || {};
    window.Alfa.ShowonBuilder = { init: init, boot: boot };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { boot(); });
    } else {
        boot();
    }
})();
