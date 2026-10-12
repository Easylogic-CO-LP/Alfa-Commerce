/**
 * Alfa Commerce: ShowOn runtime engine (frontend)
 *
 * The live controller that makes conditional visibility happen. It owns a root
 * (one buy form), and on load and on every change / input / `alfa:field-change`
 * it: rebuilds the flat state from the DOM (StateReader), evaluates each target's
 * rule (RuleEvaluator), and shows or hides it. A hidden target's inputs are
 * disabled so they neither post to PHP nor feed another field's rule, the same
 * exclusion the PHP submit authority applies.
 *
 * Visibility is JS-owned at runtime; PHP renders every field present and decides
 * the initial paint. A variant reload re-runs this by dispatching `alfa:field-change`
 * on the form once its fragment has swapped in. Chained rules (A reveals B reveals
 * C) settle via a small fixpoint loop.
 *
 * Reads each target's rule from `data-showon-rule` (the frozen schema
 * `{match, action, rules}`) and its field-level `data-showon-effect`; effect
 * `"disable"` keeps a non-matching field visible but inert instead of hiding it.
 *
 * A `[data-showon-scope]` element starts an independent scope: its fields answer
 * only from within it. That is what lets a checkout repeat the same fields for
 * delivery and for the invoice without one section's answers driving the other's
 * visibility. PHP applies the identical split at submit (SubmitAuthority).
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function (global) {
    'use strict';

    var MAX_PASSES = 6;

    /** Every engine bound on this page, so a late provider push (or an ajax-inserted
     *  form) can re-evaluate them all. */
    var ENGINES = [];

    /** Resolve the two tested primitives: from the shared namespace or via require. */
    function resolveDeps(opts) {
        var ns = (global.Alfa && global.Alfa.ShowOn) || {};
        var Evaluator = opts.evaluator || ns.RuleEvaluator;
        var reader = opts.reader || ns.StateReader;

        if ((!Evaluator || !reader) && typeof require !== 'undefined') {
            Evaluator = Evaluator || require('./rule-evaluator.js');
            reader = reader || require('./state-reader.js');
        }

        return { evaluator: new Evaluator(), reader: reader };
    }

    /**
     * @param {Object} root A form-like element scoping one item's fields.
     * @param {Object} [opts] Optional {evaluator, reader} (defaults to the namespace).
     * @class
     */
    function Engine(root, opts) {
        var deps = resolveDeps(opts || {});

        this.root = root;
        this.ev = deps.evaluator;
        this.reader = deps.reader;
        this._scheduled = false;
    }

    /** Parse (and cache) a target's rule; null when absent or malformed. */
    Engine.prototype.ruleFor = function (el) {
        if (el.__showonRule !== undefined) {
            return el.__showonRule;
        }

        var raw = el.getAttribute('data-showon-rule');
        var rule = null;

        if (raw) {
            try {
                rule = JSON.parse(raw);
            } catch (e) {
                rule = null;
            }
        }

        el.__showonRule = rule;

        return rule;
    };

    /**
     * Apply a visibility decision to one target. Returns true when the visible
     * state actually flipped (so the fixpoint loop knows to run another pass).
     */
    Engine.prototype.apply = function (el, visible, effect) {
        var wasOff = el.hasAttribute('data-showon-off');

        if (visible) {
            el.removeAttribute('data-showon-off');
            el.hidden = false;
            this.toggleInputs(el, false);

            return wasOff;
        }

        el.setAttribute('data-showon-off', '');
        el.hidden = effect !== 'disable';
        this.toggleInputs(el, true);

        return !wasOff;
    };

    /** Disable (and remember) or re-enable the inputs we disabled inside a target. */
    Engine.prototype.toggleInputs = function (el, disable) {
        var nodes = el.querySelectorAll('select, input, textarea');

        for (var i = 0; i < nodes.length; i++) {
            var input = nodes[i];

            if (disable) {
                if (!input.disabled) {
                    // setAttribute, not `.disabled = true`: the property reflects
                    // as disabled="" and Joomla's own validator skips a field only
                    // when getAttribute('disabled') === 'disabled', so the property
                    // form leaves a hidden required field blocking core validation.
                    input.setAttribute('disabled', 'disabled');
                    input.setAttribute('data-showon-disabled', '1');
                }

                this.setRequired(input, false);
            } else if (
                input.getAttribute('data-showon-disabled')
                // A hidden ancestor target still owns the exclusion: an inner
                // "visible" field inside a hidden section must not re-enable.
                && !input.closest('[data-showon-off]')
            ) {
                input.removeAttribute('disabled');
                input.removeAttribute('data-showon-disabled');
                this.setRequired(input, true);
            }
        }
    };

    /**
     * Lift a parked field's "required", and put it back when the field returns.
     *
     * Disabling alone ought to be enough, but it is not dependable: another script
     * may re-assert `disabled` through the PROPERTY (intl-tel-input's setDisabled
     * does), which rewrites the attribute to "" and defeats a validator testing
     * `getAttribute('disabled') === 'disabled'`. A field the rules hide is not
     * applicable, so it is not required either; saying so outright holds no matter
     * who last touched `disabled`, and covers Joomla's validator, the browser's and
     * our own. Both carriers are handled: Joomla honours the `required` attribute
     * OR the `required` class.
     *
     * @param {HTMLElement} input    The field.
     * @param {boolean}     required True to restore, false to lift.
     */
    Engine.prototype.setRequired = function (input, required) {
        if (!required) {
            if (input.hasAttribute('required')) {
                input.removeAttribute('required');
                input.setAttribute('data-showon-required', '1');
            }

            if (input.classList.contains('required')) {
                input.classList.remove('required');
                input.setAttribute('data-showon-required-class', '1');
            }

            return;
        }

        if (input.getAttribute('data-showon-required')) {
            input.setAttribute('required', 'required');
            input.removeAttribute('data-showon-required');
        }

        if (input.getAttribute('data-showon-required-class')) {
            input.classList.add('required');
            input.removeAttribute('data-showon-required-class');
        }
    };

    /**
     * State per SCOPE: the root plus every `[data-showon-scope]` under it. A form
     * may repeat a field in two sections (delivery / invoice), so each section
     * answers from its own values instead of a single flat map where the later
     * copy wins. Returned as parallel arrays: scopes[i] holds states[i].
     */
    Engine.prototype.buildStates = function () {
        var scopes = [this.root];
        var states = [this.reader.buildState(this.root)];
        var nested = this.root.querySelectorAll('[data-showon-scope]');

        for (var i = 0; i < nested.length; i++) {
            scopes.push(nested[i]);
            states.push(this.reader.buildState(nested[i]));
        }

        return { scopes: scopes, states: states };
    };

    /** One full evaluation, repeated until visibility settles (chained rules). */
    Engine.prototype.evaluate = function () {
        var passes = 0;
        var changed = true;

        while (changed && passes++ < MAX_PASSES) {
            changed = false;

            var built = this.buildStates();
            var targets = this.root.querySelectorAll('[data-showon-rule]');

            for (var i = 0; i < targets.length; i++) {
                var el = targets[i];
                var rule = this.ruleFor(el);

                if (rule === null) {
                    continue;
                }

                // A target is judged in the scope that CONTAINS it, so a section
                // gated on an outer trigger still reads that trigger.
                var state = built.states[built.scopes.indexOf(this.reader.scopeOf(el, this.root))];

                // Effect is a field-level choice (data-showon-effect), not per-rule:
                // 'disable' keeps a non-matching field visible but inert; default hides.
                if (this.apply(el, this.ev.isVisible(rule, state), el.getAttribute('data-showon-effect'))) {
                    changed = true;
                }
            }
        }

        this.syncGroups();
    };

    /**
     * Hide a whole field group (fieldset.fields-group) once every conditional field
     * inside it is hidden, so an empty group leaves no stray heading/border; reveal
     * it again the moment one field returns.
     */
    Engine.prototype.syncGroups = function () {
        var groups = this.root.querySelectorAll('fieldset.fields-group');

        for (var i = 0; i < groups.length; i++) {
            var fs = groups[i];
            var fields = fs.querySelectorAll('[data-showon-name]');

            if (!fields.length) {
                continue;
            }

            var anyVisible = false;

            for (var j = 0; j < fields.length; j++) {
                if (!fields[j].hidden) {
                    anyVisible = true;
                    break;
                }
            }

            fs.hidden = !anyVisible;

            if (fs.hidden) {
                fs.setAttribute('aria-hidden', 'true');
            } else {
                fs.removeAttribute('aria-hidden');
            }
        }
    };

    /** Bind listeners (idempotent per root) and run the first evaluation. */
    Engine.prototype.bind = function () {
        if (this.root.__alfaShowOn) {
            return this;
        }

        this.root.__alfaShowOn = true;

        var self = this;
        var schedule = function () {
            if (self._scheduled) {
                return;
            }

            self._scheduled = true;
            // Coalesce the input+change pair a radio/checkbox fires into one pass.
            setTimeout(function () {
                self._scheduled = false;
                self.evaluate();
            }, 0);
        };

        ['change', 'input', 'alfa:field-change'].forEach(function (type) {
            self.root.addEventListener(type, schedule, true);
        });

        this.evaluate();

        return this;
    };

    /** Bind an engine to one root (or re-use the existing one). */
    function init(root, opts) {
        if (!root) {
            return null;
        }

        if (root.__alfaShowOnEngine) {
            return root.__alfaShowOnEngine;
        }

        var engine = new Engine(root, opts).bind();
        root.__alfaShowOnEngine = engine;
        ENGINES.push(engine);

        return engine;
    }

    /**
     * Re-bind any newly inserted forms and re-evaluate every bound engine, used
     * after a late value-provider registration (a custom-widget plugin loading) or
     * an ajax-inserted form, so the state is recomputed with the provider in place.
     */
    function refresh(scope) {
        boot(scope);

        // Drop engines whose root left the DOM (variant swaps replace the form),
        // then re-evaluate the survivors; keeps ENGINES bounded across a session.
        for (var i = ENGINES.length - 1; i >= 0; i--) {
            var root = ENGINES[i].root;

            if (!root || root.isConnected === false) {
                if (root) {
                    delete root.__alfaShowOnEngine;
                }

                ENGINES.splice(i, 1);
                continue;
            }

            ENGINES[i].evaluate();
        }
    }

    /** Auto-wire every buy form that carries conditional fields. */
    function boot(scope) {
        var doc = scope || (typeof document !== 'undefined' ? document : null);

        if (!doc) {
            return;
        }

        var forms = doc.querySelectorAll('form');

        for (var i = 0; i < forms.length; i++) {
            if (forms[i].querySelector('[data-showon-rule]')) {
                init(forms[i]);
            }
        }
    }

    global.Alfa = global.Alfa || {};
    global.Alfa.ShowOn = global.Alfa.ShowOn || {};
    global.Alfa.ShowOn.Engine = Engine;
    global.Alfa.ShowOn.init = init;
    global.Alfa.ShowOn.refresh = refresh;

    // Value-provider registry + queue facade. A custom-widget field whose value
    // cannot be read from its native inputs registers a reader keyed by its field
    // type; the StateReader consults these by data-showon-type, falling back to the
    // native inputs. The contract is load-order independent: before this engine
    // loads, window.alfaShowOn is a plain array (entries wait); after, it is a live
    // sink that registers the reader and re-evaluates. Native fields register
    // nothing, so the registry stays empty and reading stays purely native.
    global.Alfa.ShowOn._providers = global.Alfa.ShowOn._providers || {};

    function registerProvider(entry) {
        if (entry && entry.type && typeof entry.value === 'function') {
            global.Alfa.ShowOn._providers[entry.type] = entry.value;
        }
    }

    var queued = global.alfaShowOn;

    if (queued && typeof queued.forEach === 'function') {
        queued.forEach(registerProvider);
    }

    global.alfaShowOn = {
        push: function (entry) {
            registerProvider(entry);
            refresh();
        }
    };

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                boot();
            });
        } else {
            boot();
        }

        // A variant reload replaces the whole [data-item-id] container (a fresh
        // form), so re-bind within it. init() is idempotent per root, so untouched
        // forms are skipped; only the new one binds.
        document.addEventListener('alfa:item:updated', function (e) {
            boot(e.target && e.target.querySelectorAll ? e.target : document);
        });
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { Engine: Engine, init: init, boot: boot };
    }
})(typeof window !== 'undefined' ? window : this);
