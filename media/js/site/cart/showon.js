/**
 * Alfa ShowOn engine — conditional field display (frontend).
 *
 * Σταθερό «συμβόλαιο» DOM — namespaced data-showon-* attributes ανά πεδίο:
 *
 *   <div data-showon-name="address"
 *        data-showon-type="text"
 *        data-showon-rule='{...}'>          <!-- rule ΜΟΝΟ στα targets -->
 *      ...το πεδίο...
 *   </div>
 *
 *   data-showon-name : το field_name (το βρίσκει η μηχανή ως «διακόπτη»)
 *   data-showon-type : ο τύπος (choice/tel/text...) -> διαλέγει value provider
 *   data-showon-rule : ο κανόνας (ΜΟΝΟ στα targets· λείπει = πάντα ορατό)
 *
 * Ασφαλές naming: το Joomla core πιάνει ΑΚΡΙΒΩΣ το `data-showon`. Το
 * `[data-showon]` ΔΕΝ ταιριάζει με `data-showon-rule` -> 0 conflict.
 *
 * data-showon-rule schema = RECURSIVE δέντρο (απεριόριστο βάθος):
 *   node = GROUP { "match":"AND"|"OR", "children":[ node, node, ... ] }
 *        ή RULE  { "field":"f1", "op":"=", "values":["v1"] }
 *   Η ρίζα είναι πάντα ένα group. Άδειο/χωρίς children = πάντα ορατό.
 *
 *   Παράδειγμα: (f1=v1 OR f2=v3) AND f5!=v8
 *   {
 *     "match": "AND",
 *     "children": [
 *       { "match": "OR", "children": [
 *           { "field": "f1", "op": "=",  "values": ["v1"] },
 *           { "field": "f2", "op": "=",  "values": ["v3"] } ]},
 *       { "field": "f5", "op": "!=", "values": ["v8"] }
 *     ]
 *   }
 *   Επειδή είναι recursive, πιάνει ΟΠΟΙΑΔΗΠΟΤΕ λογική, π.χ.
 *   (f1∨f2) ∧ (f3∨f4) ∧ (f5 ∧ (f6∨f7)) — απλά πιο βαθύ δέντρο.
 *
 * ΑΠΛΟ ΠΑΡΑΔΕΙΓΜΑ:
 *   Πεδίο "address" θες να φαίνεται ΜΟΝΟ όταν το "delivery_method" = courier.
 *   Το HTML του address βγαίνει έτσι:
 *     <div data-showon-name="address" data-showon-type="text"
 *          data-showon-rule='{"match":"AND","groups":[
 *            {"match":"OR","rules":[
 *              {"field":"delivery_method","op":"=","values":["courier"]}]}]}'>
 *   Ο επισκέπτης διαλέγει "pickup"  -> το address ΚΡΥΒΕΤΑΙ (+ disabled inputs)
 *   Ο επισκέπτης διαλέγει "courier" -> το address ΕΜΦΑΝΙΖΕΤΑΙ αμέσως
 *
 * Πολλαπλή επιλογή = array τιμών (ταίριασμα με τομή).
 * Operators (το `!<op>` = auto-negation):
 *   = contains startsWith endsWith regex empty > >= < <= between length
 * Επέκταση χωρίς πειραγμα πυρήνα: Alfa.ShowOn.setOperator(name, fn).
 *
 * Plugins με custom widget δηλώνουν reader με ΜΙΑ γραμμή — χωρίς γνώση
 * internals, χωρίς stub, ανεξάρτητα load order (queue facade, GA-style):
 *   (window.alfaShowOn = window.alfaShowOn || []).push({
 *       type: 'choice',
 *       value: function (name, form) { return currentValueOrArray; }
 *   });
 * Πριν φορτώσει η μηχανή το alfaShowOn είναι απλός array (περιμένει)·
 * μετά είναι live sink (register + repaint αμέσως). Όλα τα internals
 * αλλάζουν αύριο χωρίς άγγιγμα κανενός plugin. Τα plugins στέλνουν και
 * bubbling 'alfa:field-change' όταν αλλάζουν. Native inputs: τίποτα.
 */
(function () {
    'use strict';

    window.Alfa = window.Alfa || {};

    var ShowOn = window.Alfa.ShowOn || {
        _providers: {},
        operators: {},
        /** Κάθε plugin με custom widget καλεί αυτό. */
        setValueProvider: function (type, fn) {
            this._providers[type] = fn;
            if (this.refresh) { this.refresh(); } // late register -> repaint
        },
        /**
         * Πρόσθεσε/αντικατέστησε operator.
         *   fn(currentValues:string[], wanted:string[]) -> boolean
         * Το `!` prefix κάνει auto-negation, οπότε δηλώνεις μόνο το θετικό.
         */
        setOperator: function (name, fn) {
            this.operators[name] = fn;
        }
    };
    window.Alfa.ShowOn = ShowOn;

    // ---------------------------------------------------------------------
    // Index: 1 φορά ανά φόρμα διαβάζουμε όλα τα data-showon-name
    // ---------------------------------------------------------------------

    function buildIndex(form) {
        var byName = {};
        var targets = [];

        form.querySelectorAll('[data-showon-name]').forEach(function (el) {
            var name = el.getAttribute('data-showon-name');
            byName[name] = { el: el, type: el.getAttribute('data-showon-type') || '' };

            var raw = el.getAttribute('data-showon-rule');
            if (!raw) {
                return;
            }
            var spec;
            try {
                spec = JSON.parse(raw);
            } catch (e) {
                return;
            }
            var hasRule = spec && ((spec.children && spec.children.length)
                || (spec.group && spec.group.length));
            if (hasRule) {
                targets.push({ el: el, spec: spec });
            }
        });

        return { byName: byName, targets: targets, _val: {} };
    }

    // ---------------------------------------------------------------------
    // Value reading
    // ---------------------------------------------------------------------

    /**
     * Default reader: διαβάζει τα native inputs ΜΕΣΑ στο wrapper του πεδίου
     * (ανεξάρτητο από το name — τα alfa inputs είναι jform[alfa_form_fields][x]).
     * Πολλαπλή επιλογή (select multiple / πολλά checkbox) -> ΠΑΝΤΑ array.
     */
    function readNative(wrapper) {
        if (!wrapper) {
            return null;
        }
        var nodes = wrapper.querySelectorAll('input, select, textarea');
        if (!nodes.length) {
            return null;
        }

        var first = nodes[0];

        if (first.tagName === 'SELECT') {
            var picked = Array.prototype.map.call(first.selectedOptions, function (o) {
                return o.value;
            });
            return first.multiple ? picked : (picked.length ? picked[0] : null);
        }

        if (first.type === 'checkbox' || first.type === 'radio') {
            var on = [];
            Array.prototype.forEach.call(nodes, function (n) {
                if ((n.type === 'checkbox' || n.type === 'radio') && n.checked) {
                    on.push(n.value);
                }
            });
            if (first.type === 'radio') {
                return on.length ? on[0] : null; // radio = 1 τιμή
            }
            return on; // checkbox group = ΠΑΝΤΑ array (0..n τιμές)
        }

        return first.value; // text / single value
    }

    /** Η ΤΡΕΧΟΥΣΑ τιμή του διακόπτη "name" (provider ανά τύπο, αλλιώς native). */
    function readValue(name, form, index) {
        var entry = index.byName[name];
        if (!entry) {
            return null;
        }
        // Per-pass memo: form state is constant within one evaluateForm, so
        // a switch is read at most ONCE regardless of how many targets gate
        // on it (eval is O(distinct switches), not O(rules)).
        if (index._val && name in index._val) {
            return index._val[name];
        }
        var prov = entry.type && ShowOn._providers[entry.type];
        var v;
        try {
            v = prov ? prov(name, form) : readNative(entry.el);
        } catch (e) {
            v = readNative(entry.el);
        }
        if (index._val) {
            index._val[name] = v;
        }
        return v;
    }

    function asArray(v) {
        if (v === null || v === undefined || v === '') {
            return [];
        }
        return Array.isArray(v) ? v.map(String) : [String(v)];
    }

    // ---------------------------------------------------------------------
    // Operators (επεκτάσιμοι — Alfa.ShowOn.setOperator)
    // ---------------------------------------------------------------------

    function num(x) {
        var n = parseFloat(x);
        return isNaN(n) ? null : n;
    }

    // numeric compare: πρώτη τρέχουσα τιμή vs πρώτη ζητούμενη
    function numCmp(cur, want, f) {
        var a = num(cur[0]);
        var b = num(want[0]);
        return a !== null && b !== null && f(a, b);
    }

    // length expr: "8" | "=8" | ">=8" | "<20" | ">3" | "<=5"
    function lenMatch(len, expr) {
        var m = String(expr).match(/^\s*(<=|>=|<|>|=)?\s*(\d+)\s*$/);
        if (!m) {
            return false;
        }
        var n = parseInt(m[2], 10);
        switch (m[1] || '=') {
            case '>':  return len > n;
            case '<':  return len < n;
            case '>=': return len >= n;
            case '<=': return len <= n;
            default:   return len === n;
        }
    }

    // Default operators. fn(currentValues[], wanted[]) -> bool.
    // Δηλώνουμε μόνο τα ΘΕΤΙΚΑ· το `!<op>` παράγεται αυτόματα (negation).
    var DEFAULT_OPS = {
        '=':          function (cur, want) { return cur.some(function (c) { return want.indexOf(c) !== -1; }); },
        'contains':   function (cur, want) { return cur.some(function (c) { return want.some(function (w) { return w !== '' && c.indexOf(w) !== -1; }); }); },
        'startsWith': function (cur, want) { return cur.some(function (c) { return want.some(function (w) { return w !== '' && c.lastIndexOf(w, 0) === 0; }); }); },
        'endsWith':   function (cur, want) { return cur.some(function (c) { return want.some(function (w) { return w !== '' && c.slice(-w.length) === w; }); }); },
        'regex':      function (cur, want) { return cur.some(function (c) { return want.some(function (w) { try { return new RegExp(w).test(c); } catch (e) { return false; } }); }); },
        'empty':      function (cur)       { return cur.length === 0 || cur.every(function (c) { return c === ''; }); },
        '>':          function (cur, want) { return numCmp(cur, want, function (a, b) { return a > b; }); },
        '>=':         function (cur, want) { return numCmp(cur, want, function (a, b) { return a >= b; }); },
        '<':          function (cur, want) { return numCmp(cur, want, function (a, b) { return a < b; }); },
        '<=':         function (cur, want) { return numCmp(cur, want, function (a, b) { return a <= b; }); },
        'between':    function (cur, want) { var v = num(cur[0]), lo = num(want[0]), hi = num(want[1]); return v !== null && lo !== null && hi !== null && v >= lo && v <= hi; },
        'length':     function (cur, want) { return cur.some(function (c) { return lenMatch(c.length, want[0] || ''); }); }
    };

    Object.keys(DEFAULT_OPS).forEach(function (k) {
        if (!(k in ShowOn.operators)) {            // μην πατήσεις plugin overrides
            ShowOn.operators[k] = DEFAULT_OPS[k];
        }
    });

    // ---------------------------------------------------------------------
    // Rule evaluation (rules -> groups -> spec)
    // ---------------------------------------------------------------------

    function ruleMatches(rule, form, index) {
        // Always trim BOTH sides — the value we read AND the rule value we
        // stored. Universal: every field, every operator. Stray spaces
        // (e.g. value="courier ") never break a rule, and whitespace-only
        // counts as empty for `empty`/`!empty`. Numeric ops parseFloat so
        // they're unaffected.
        var trim = function (s) { return String(s).trim(); };
        var current = asArray(readValue(rule.field, form, index)).map(trim);
        var wanted = (rule.values || []).map(trim);

        var op = String(rule.op || '=');
        var negate = op.charAt(0) === '!';
        if (negate) {
            op = op.slice(1);
        }

        var fn = ShowOn.operators[op] || ShowOn.operators['='];
        var res = !!fn(current, wanted);
        return negate ? !res : res;
    }

    // RECURSIVE: ένας κόμβος είναι είτε GROUP (έχει children) είτε RULE.
    function evalNode(node, form, index) {
        if (node && node.children) {                       // GROUP
            var kids = node.children;
            if (!kids.length) {
                return true;
            }
            if ((node.match || 'AND').toUpperCase() === 'OR') {
                return kids.some(function (k) { return evalNode(k, form, index); });
            }
            return kids.every(function (k) { return evalNode(k, form, index); });
        }
        return ruleMatches(node, form, index);             // RULE
    }

    // Νέο schema: per-item glue, strict LEFT-TO-RIGHT, recursive.
    // item = { rule:{…} } ή { group:[…] }· προαιρετικό glue:'AND'|'OR'
    // ΕΝΩΝΕΙ με το ΕΠΟΜΕΝΟ sibling. Precedence ΜΟΝΟ μέσω nested group.
    // Το τελευταίο item δεν έχει glue.
    function evalItem(item, form, index) {
        if (item && item.group) {
            return evalGroup(item.group, form, index);
        }
        return ruleMatches(item && item.rule ? item.rule : item, form, index);
    }

    function evalGroup(items, form, index) {
        if (!items || !items.length) {
            return true;
        }
        var acc = evalItem(items[0], form, index);
        for (var i = 1; i < items.length; i++) {
            var glue = String(items[i - 1].glue || 'AND').toUpperCase();
            var cur  = evalItem(items[i], form, index);
            acc = (glue === 'OR') ? (acc || cur) : (acc && cur);
        }
        return acc;
    }

    function specMatches(spec, form, index) {
        if (spec && spec.group) {                       // νέο schema
            return spec.group.length ? evalGroup(spec.group, form, index) : true;
        }
        if (!spec || !spec.children || !spec.children.length) {
            return true; // χωρίς κανόνα -> πάντα ορατό (legacy {match,children})
        }
        return evalNode(spec, form, index);             // legacy
    }

    // ---------------------------------------------------------------------
    // Show / hide
    // ---------------------------------------------------------------------

    /**
     * Όταν ένα target κρύβεται, κάνουμε disable τα inputs μέσα του ώστε να ΜΗΝ
     * μπλοκάρουν submit/validation (κρίσιμο για required). Στην επανεμφάνιση
     * ξανα-ενεργοποιούνται — εκτός αν ήταν ήδη disabled από αλλού.
     */
    function setVisible(el, visible) {
        if (visible) {
            if (!el.hidden) {
                return;
            }
            el.hidden = false;
            el.removeAttribute('aria-hidden');
            el.querySelectorAll('[data-showon-disabled]').forEach(function (i) {
                i.disabled = false;
                i.removeAttribute('data-showon-disabled');
            });
        } else {
            if (el.hidden) {
                return;
            }
            el.hidden = true;
            el.setAttribute('aria-hidden', 'true');
            el.querySelectorAll('input, select, textarea').forEach(function (i) {
                if (!i.disabled) {
                    i.disabled = true;
                    i.setAttribute('data-showon-disabled', '1');
                }
            });
        }
    }

    /** Αν όλα τα showon πεδία ενός group κρυφτούν -> κρύψε όλο το fieldset. */
    function syncGroups(form) {
        form.querySelectorAll('fieldset.fields-group').forEach(function (fs) {
            var fields = fs.querySelectorAll('[data-showon-name]');
            if (!fields.length) {
                return;
            }
            var anyVisible = Array.prototype.some.call(fields, function (f) {
                return !f.hidden;
            });
            fs.hidden = !anyVisible;
            if (fs.hidden) {
                fs.setAttribute('aria-hidden', 'true');
            } else {
                fs.removeAttribute('aria-hidden');
            }
        });
    }

    function evaluateForm(form) {
        var index = buildIndex(form);
        index.targets.forEach(function (t) {
            setVisible(t.el, specMatches(t.spec, form, index));
        });
        syncGroups(form);
    }

    // ---------------------------------------------------------------------
    // Wiring
    // ---------------------------------------------------------------------

    function init(root) {
        var scope = root || document;
        scope.querySelectorAll('form').forEach(function (form) {
            if (form.__alfaShowOn) {
                evaluateForm(form);
                return;
            }
            if (!form.querySelector('[data-showon-rule]')) {
                return;
            }
            form.__alfaShowOn = true;

            // One gesture on a radio/checkbox/<select> fires TWO UA events:
            // `input` then `change`. The browser performs a microtask
            // checkpoint BETWEEN them (JS stack empties after the `input`
            // listener), so a microtask flush would reset the guard before
            // `change` → two evaluateForm passes. A MACROtask (setTimeout 0)
            // flushes only AFTER the whole interaction task (input + change
            // done) → the whole gesture coalesces into ONE pass. Text inputs
            // only fire `input` while typing, so they were never affected.
            var pending = false;
            var handler = function () {
                if (pending) {
                    return;
                }
                pending = true;
                setTimeout(function () { pending = false; evaluateForm(form); }, 0);
            };
            form.addEventListener('change', handler, true);
            form.addEventListener('input', handler, true);
            form.addEventListener('alfa:field-change', handler, true);

            evaluateForm(form); // αρχική κατάσταση στο load
        });
    }

    /** Για δυναμικά εισαγόμενες φόρμες (π.χ. ajax cart): Alfa.ShowOn.refresh(node) */
    ShowOn.refresh = function (node) { init(node || document); };

    // ── Public plugin contract: queue facade ──────────────────────────────
    // Plugins push { type, value } χωρίς να ξέρουν ΤΙΠΟΤΑ από internals και
    // ανεξάρτητα load order. Πριν τη μηχανή = array (περιμένει)· μετά = live
    // sink. Providers/operators/eval/refresh αλλάζουν αύριο χωρίς άγγιγμα
    // κανενός plugin — μόνο το { type, value } είναι το σταθερό συμβόλαιο.
    function register(entry) {
        if (entry && entry.type && typeof entry.value === 'function') {
            ShowOn._providers[entry.type] = entry.value;
        }
    }

    // Στραγγίζουμε την ουρά ΠΡΙΝ το πρώτο evaluate -> οι providers είναι
    // έτοιμοι, ΕΝΑ πέρασμα στο load (όχι διπλό). Μετά το drain, κάθε late
    // push κάνει register + refresh (σπάνιο: δυναμικά φορτωμένο plugin).
    var queued = window.alfaShowOn;
    if (queued && typeof queued.forEach === 'function') {
        queued.forEach(register);
    }
    window.alfaShowOn = {
        push: function (entry) {
            register(entry);
            if (ShowOn.refresh) { ShowOn.refresh(); }
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})();
