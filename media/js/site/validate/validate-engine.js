/**
 * Alfa Commerce: form validation engine
 *
 * A thin orchestrator over the browser's Constraint Validation API instead of
 * a re-implemented rule framework: native constraints (required, type, min,
 * max, pattern) are checked by the browser itself (with its own localized
 * messages) and custom rules plug in through a registry keyed by the Joomla
 * `validate-<name>` field class.
 *
 * Gates on the form's `submit` event (covers Enter as well as clicks) and
 * honours `formnovalidate` submitters. Disabled fields are skipped, which is
 * exactly how the ShowOn engine parks hidden fields: a field hidden by a
 * rule can never block submission. Fields the browser will not validate
 * (e.g. a custom widget's hidden input) participate only through their
 * registered handler.
 *
 * Live feedback: a field is validated when committed (`change`, or the
 * `alfa:field-change` contract event for widgets that write values
 * programmatically) and re-checked on every keystroke only once marked
 * invalid, so errors clear as the customer types.
 *
 * Custom rules register load-order independently, mirroring the ShowOn
 * provider queue: `window.alfaValidate.push({name, test, message})` where
 * `test(value, element)` returns a boolean, the same handler signature
 * Joomla's validator uses, so an existing handler ports verbatim.
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function (global) {
    'use strict';

    var HANDLERS = {};
    var ENGINES = [];

    /** Register a custom rule for fields carrying class `validate-<name>`. */
    function setHandler(name, test, message) {
        if (name && typeof test === 'function') {
            HANDLERS[name] = { test: test, message: message || null };
        }
    }

    /** The registered handler for a field's `validate-<name>` class, if any. */
    function handlerFor(el) {
        var classes = el.classList;

        for (var i = 0; i < classes.length; i++) {
            if (classes[i].indexOf('validate-') === 0) {
                var handler = HANDLERS[classes[i].slice(9)];

                if (handler) {
                    return handler;
                }
            }
        }

        return null;
    }

    /** Fallback message for a custom-rule failure that provides none. */
    function genericMessage() {
        if (global.Joomla && global.Joomla.Text) {
            return global.Joomla.Text._('COM_ALFA_VALIDATE_FIELD_INVALID', 'Please check this value.');
        }

        return 'Please check this value.';
    }

    /**
     * @param {HTMLFormElement} form The form to validate.
     * @class
     */
    function Engine(form) {
        this.form = form;
    }

    /**
     * Fields taking part in validation right now. Disabled fields are out
     * (ShowOn-hidden fields are disabled); fields the browser cannot validate
     * stay in only when a custom handler covers them.
     */
    Engine.prototype.fields = function () {
        var nodes = this.form.querySelectorAll('input, select, textarea');
        var seenGroups = {};
        var out = [];

        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];

            if (el.disabled) {
                continue;
            }

            // Without a custom handler a field must be browser-validatable AND
            // rendered: an enhanced widget's hidden original or an editor's
            // replaced textarea would otherwise block the form invisibly
            // (their truth is checked server-side).
            if (!handlerFor(el) && (!el.willValidate || el.offsetParent === null)) {
                continue;
            }

            // A radio group validates as one unit; its first member stands in.
            if (el.type === 'radio') {
                if (seenGroups[el.name]) {
                    continue;
                }

                seenGroups[el.name] = true;
            }

            out.push(el);
        }

        return out;
    };

    /** Validate one field: native constraints first, then its custom rule. */
    Engine.prototype.check = function (el) {
        var ok = true;
        var message = '';

        if (el.willValidate && !el.checkValidity()) {
            ok = false;
            message = el.validationMessage;
        }

        if (ok) {
            var handler = handlerFor(el);

            if (handler && !handler.test(el.value, el)) {
                ok = false;
                message = handler.message || genericMessage();
            }
        }

        this.mark(el, ok, message);

        return ok;
    };

    /** Toggle the invalid state and the inline message on one field. */
    Engine.prototype.mark = function (el, ok, message) {
        // Radios share one message per group; a labelled control anchors the
        // message after its label so it never splits control from caption.
        var key = el.type === 'radio' ? el.name : (el.id || el.name || 'field');
        var id = key + '-error';
        var note = this.form.querySelector('[data-error-for="' + id + '"]');

        if (ok) {
            el.classList.remove('invalid');
            el.removeAttribute('aria-invalid');

            if (note) {
                note.remove();
            }

            return;
        }

        el.classList.add('invalid');
        el.setAttribute('aria-invalid', 'true');

        if (!note) {
            note = document.createElement('span');
            note.className = 'field-error';
            note.setAttribute('data-error-for', id);
            note.id = id;
            el.setAttribute('aria-describedby', id);
            (el.closest('label') || el).insertAdjacentElement('afterend', note);
        }

        note.textContent = message;
    };

    /** Validate every participating field; returns the first invalid one. */
    Engine.prototype.checkAll = function () {
        var fields = this.fields();
        var firstInvalid = null;

        for (var i = 0; i < fields.length; i++) {
            if (!this.check(fields[i]) && !firstInvalid) {
                firstInvalid = fields[i];
            }
        }

        return firstInvalid;
    };

    /** The field an event concerns: the target itself or its first input. */
    Engine.prototype.resolveField = function (node) {
        if (!node || !node.matches) {
            return null;
        }

        if (node.matches('input, select, textarea')) {
            return node;
        }

        return node.querySelector('input:not([disabled]), select, textarea');
    };

    /** Bind (idempotent per form): submit gate + live per-field feedback. */
    Engine.prototype.bind = function () {
        if (this.form.__alfaValidate) {
            return this;
        }

        this.form.__alfaValidate = true;

        var self = this;

        // The engine owns the UX; suppress the browser's own bubbles.
        this.form.noValidate = true;

        this.form.addEventListener('submit', function (event) {
            if (event.submitter && event.submitter.formNoValidate) {
                return;
            }

            // Joomla.submitform() fires a real submit with the task already
            // set; a cancel must never be held back by invalid fields. A
            // cancel is `x.cancel` or, by Alfa's naming, `x.cancelSomething`
            // (documents.cancelVoid, order.cancelPayment...): matching only
            // `.cancel` left every modal's Close blocked by an empty required
            // field. A toolbar Delete or
            // Remove (order.deletePayment, cart.removeLine...) posts nothing the
            // fields must satisfy either, so it follows the same naming rule.
            var task = self.form.elements.task ? String(self.form.elements.task.value || '') : '';

            if (/\.(?:cancel|delete|remove)(?:[A-Z]\w*)?$/.test(task)) {
                return;
            }

            var invalid = self.checkAll();

            if (invalid) {
                event.preventDefault();
                event.stopPropagation();
                invalid.focus();
                invalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
                self.form.dispatchEvent(new CustomEvent('alfa:validate-failed', { bubbles: true }));
            }
        });

        ['change', 'alfa:field-change'].forEach(function (type) {
            self.form.addEventListener(type, function (event) {
                var el = self.resolveField(event.target);

                if (el && !el.disabled) {
                    self.check(el);
                }
            }, true);
        });

        // Once a field is marked, re-check per keystroke so the error clears
        // the moment the value becomes acceptable.
        this.form.addEventListener('input', function (event) {
            var el = self.resolveField(event.target);

            if (el && el.classList.contains('invalid')) {
                self.check(el);
            }
        }, true);

        return this;
    };

    /** Bind an engine to one form (or re-use the existing one). */
    function init(form) {
        if (!form) {
            return null;
        }

        if (form.__alfaValidateEngine) {
            return form.__alfaValidateEngine;
        }

        var engine = new Engine(form).bind();
        form.__alfaValidateEngine = engine;
        ENGINES.push(engine);

        return engine;
    }

    /** Auto-wire every form opting in via the standard marker class. */
    function boot(scope) {
        var doc = scope || (typeof document !== 'undefined' ? document : null);

        if (!doc) {
            return;
        }

        var forms = doc.querySelectorAll('form.form-validate');

        for (var i = 0; i < forms.length; i++) {
            init(forms[i]);
        }
    }

    global.Alfa = global.Alfa || {};
    global.Alfa.Validate = { Engine: Engine, init: init, boot: boot, setHandler: setHandler };

    // Load-order-independent handler queue (same contract as alfaShowOn):
    // entries pushed before the engine loads wait in a plain array; afterwards
    // the sink registers immediately.
    function registerEntry(entry) {
        if (entry) {
            setHandler(entry.name, entry.test, entry.message);
        }
    }

    var queued = global.alfaValidate;

    if (queued && typeof queued.forEach === 'function') {
        queued.forEach(registerEntry);
    }

    global.alfaValidate = { push: registerEntry };

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                boot();
            });
        } else {
            boot();
        }

        document.addEventListener('alfa:item:updated', function (e) {
            boot(e.target && e.target.querySelectorAll ? e.target : document);
        });
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { Engine: Engine, init: init, boot: boot, setHandler: setHandler };
    }
})(typeof window !== 'undefined' ? window : this);
