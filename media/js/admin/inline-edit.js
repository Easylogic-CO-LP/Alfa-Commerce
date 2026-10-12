/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * Generic inline quick-edit for admin list cells.
 *
 * HTML only marks a cell (layout inline/field.php):
 *   <span class="alfa-editable" data-view="item" data-element="name" data-id="10" data-type="multilang">...</span>
 *
 * This script finds each marker, INJECTS the pencil trigger, and fetches the
 * editor ON DEMAND when clicked (task=<view>.inlineLoad returns server-rendered
 * markup), so the field markup lives once in PHP and nothing heavy renders per
 * row.
 *
 *   GET  task=<view>.inlineLoad&id=..&element=..  -> { success, data:{ html } }
 *   POST task=<view>.inlineSave  { id, field, value|values[, baselines] } -> { success, message, data:{display}, messages }
 *
 * An editor whose inputs carry data-baseline (the figure as rendered) sends
 * those baselines with the values, so the server can tell a changed figure
 * from one the operator left alone.
 *
 * Optimistic + revert on error; mirrors OrderController::updateOrder.
 */
(function () {
    'use strict';

    /** Joomla CSRF token: never scraped from the DOM (key is random per session). */
    function token() {
        return (typeof Joomla !== 'undefined' && Joomla.getOptions('csrf.token')) || '';
    }

    function text(key, fallback) {
        if (typeof Joomla !== 'undefined' && Joomla.Text && Joomla.Text._) {
            var t = Joomla.Text._(key);
            if (t && t !== key) {
                return t;
            }
        }
        return fallback;
    }

    function taskUrl(view, task) {
        var url = 'index.php?option=com_alfa&task=' + encodeURIComponent(view) + '.' + task;
        var t = token();
        if (t) {
            url += '&' + encodeURIComponent(t) + '=1';
        }
        return url;
    }

    /** Map a Joomla message-queue type to an Alfa.notify type. */
    function mapType(t) {
        return { message: 'success', notice: 'info', warning: 'warning', error: 'error' }[t] || 'info';
    }

    /** Toast via our own Alfa.notify (consistent with order-status). */
    function notify(message, type) {
        if (window.Alfa && typeof Alfa.notify === 'function') {
            Alfa.notify(message, type || 'default');
        }
    }

    var PENCIL = '<svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor"'
        + ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M11.5 2.5l2 2L6 12l-2.5.5L4 10z"/></svg>';

    /** Inject the pencil trigger into a marked cell (idempotent). */
    function decorate(wrap) {
        if (wrap.querySelector('.alfa-edit-trigger')) {
            return;
        }

        var label = text('COM_ALFA_INLINE_EDIT', 'Quick edit');
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'alfa-edit-trigger';
        btn.title = label;
        btn.setAttribute('aria-label', label);
        btn.innerHTML = PENCIL;
        wrap.appendChild(btn);
    }

    function decorateAll(root) {
        (root || document).querySelectorAll('.alfa-editable').forEach(decorate);
    }

    /** Fetch the on-demand editor markup for a field. */
    function loadEditorHtml(view, id, element) {
        var url = taskUrl(view, 'inlineLoad') + '&id=' + id + '&element=' + encodeURIComponent(element);

        return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    throw new Error((res && res.message) || text('COM_ALFA_INLINE_LOAD_FAILED', 'Could not load'));
                }
                return (res.data && res.data.html) || '';
            });
    }

    /**
     * Swap the cell's display for the server-rendered editor (inputs + Save/Cancel).
     * JS only binds the triggers. Resolves with { values, baselines }: the collected
     * { name: value } map and the { name: baseline } map of the inputs that carry a
     * rendered baseline (null when none does), or null on cancel.
     */
    function openEditor(wrap, html) {
        return new Promise(function (resolve) {
            var display = wrap.querySelector('.alfa-editable-display');
            var trigger = wrap.querySelector('.alfa-edit-trigger');

            var holder = document.createElement('div');
            holder.innerHTML = html;                 // server-rendered editor chrome
            var editor = holder.firstElementChild;

            if (!editor) { resolve(null); return; }

            if (display) { display.hidden = true; }
            if (trigger) { trigger.hidden = true; }
            wrap.appendChild(editor);

            var inputs = editor.querySelectorAll('.alfa-inline-input');
            if (inputs.length) { inputs[0].focus(); inputs[0].select(); }

            function close() {
                editor.remove();
                if (display) { display.hidden = false; }
                if (trigger) { trigger.hidden = false; }
            }

            function collect() {
                var out = {};
                editor.querySelectorAll('.alfa-inline-input').forEach(function (i) {
                    out[i.dataset.name] = i.value;
                });
                return out;
            }

            // The figure each input was rendered with, keyed like collect().
            function collectBaselines() {
                var out = null;
                editor.querySelectorAll('.alfa-inline-input[data-baseline]').forEach(function (i) {
                    out = out || {};
                    out[i.dataset.name] = i.dataset.baseline;
                });
                return out;
            }

            function edited() {
                return { values: collect(), baselines: collectBaselines() };
            }

            var saveBtn = editor.querySelector('[data-inline-save]');
            var cancelBtn = editor.querySelector('[data-inline-cancel]');

            if (saveBtn) { saveBtn.addEventListener('click', function () { var result = edited(); close(); resolve(result); }); }
            if (cancelBtn) { cancelBtn.addEventListener('click', function () { close(); resolve(null); }); }

            editor.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { e.preventDefault(); close(); resolve(null); }
                if (e.key === 'Enter' && inputs.length === 1) { e.preventDefault(); var result = edited(); close(); resolve(result); }
            });
        });
    }

    /** POST the edited payload; resolve with the response or throw. */
    function persist(view, payload) {
        return fetch(taskUrl(view, 'inlineSave'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    var err = new Error((res && res.message) || text('COM_ALFA_INLINE_LOAD_FAILED', 'Something went wrong'));
                    // Keep the envelope on the rejection: a refusal often carries the
                    // TRUE value (stock the ledger actually holds), which the cell must
                    // adopt instead of keeping the number the edit never became.
                    err.response = res;
                    throw err;
                }
                return res;
            });
    }

    function setDisplay(wrap, value) {
        var display = wrap.querySelector('.alfa-editable-display');
        if (display) {
            display.textContent = (value == null || value === '') ? '-' : value;
        }
    }

    /**
     * Repaint the sibling cells a save invalidated (Available follows Stock).
     * The server sends `data.refresh = { <cell name>: '<html>' }` and each entry
     * replaces the matching `[data-alfa-cell][data-id]` in the SAME row; the
     * markup is rendered by the same PHP layout the list uses, so no cell is
     * ever assembled twice in two languages.
     */
    function applyRefresh(id, refresh) {
        if (!refresh) { return; }

        Object.keys(refresh).forEach(function (name) {
            var target = document.querySelector(
                '[data-alfa-cell="' + name + '"][data-id="' + id + '"]'
            );

            if (target) {
                target.outerHTML = refresh[name];
            }
        });
    }

    function begin(wrap) {
        var view    = wrap.dataset.view;
        var id      = parseInt(wrap.dataset.id, 10);
        var element = wrap.dataset.element;
        var type    = wrap.dataset.type;

        if (!view || !id || !element) { return; }

        wrap.classList.add('is-editing');

        loadEditorHtml(view, id, element)
            .then(function (html) { return openEditor(wrap, html); })
            .then(function (edited) {
                if (edited === null) { wrap.classList.remove('is-editing'); return; }

                var payload = { id: id, field: element };

                // Shape follows the EDITOR, not a list of field types: a scalar
                // editor marks its one input __value, every keyed editor
                // (language tag, location id) yields a map the controller reads
                // by field. Adding an editor needs no change here.
                if (edited.values['__value'] !== undefined) {
                    payload.value = edited.values['__value'];
                } else {
                    payload.values = edited.values;
                }

                // Sent only by an editor that renders baselines (the stock editor).
                if (edited.baselines !== null) {
                    payload.baselines = edited.baselines;
                }

                wrap.classList.add('is-saving');

                return persist(view, payload)
                    .then(function (res) {
                        setDisplay(wrap, res.data && res.data.display !== undefined ? res.data.display : payload.value);
                        applyRefresh(id, res.data && res.data.refresh);
                        // Surface any server-queued notices (alias conflicts, etc.) as toasts...
                        if (Array.isArray(res.messages)) {
                            res.messages.forEach(function (m) { notify(m.message, mapType(m.type)); });
                        }
                        // ...then the success confirmation returned by the controller.
                        if (res.message) { notify(res.message, 'success'); }
                    })
                    .catch(function (err) {
                        var res = err.response;
                        if (res) {
                            if (res.data && res.data.display !== undefined) { setDisplay(wrap, res.data.display); }
                            // A refusal carries the TRUE figures, so the derived
                            // cells must adopt them too, not keep the edit's.
                            applyRefresh(id, res.data && res.data.refresh);
                            if (Array.isArray(res.messages)) {
                                res.messages.forEach(function (m) { notify(m.message, mapType(m.type)); });
                            }
                        }
                        notify(err.message, 'error');
                    })
                    .finally(function () { wrap.classList.remove('is-saving', 'is-editing'); });
            })
            .catch(function (err) {
                wrap.classList.remove('is-editing');
                notify(err.message, 'error');
            });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('.alfa-edit-trigger');
        if (!trigger) { return; }

        var wrap = trigger.closest('.alfa-editable');
        if (!wrap || wrap.classList.contains('is-editing')) { return; }

        e.preventDefault();
        begin(wrap);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { decorateAll(document); });
    } else {
        decorateAll(document);
    }
}());
