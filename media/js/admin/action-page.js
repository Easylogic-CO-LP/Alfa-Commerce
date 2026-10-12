/**
 * Alfa: action page helper.
 *
 * Loaded on every action page (view=action). Gives a layout a tiny, obvious API
 * to close its popup and (optionally) refresh the opener; no postMessage by
 * hand. Works whether the page is in the modal iframe or standalone.
 *
 *   AlfaAction.close()        close the popup
 *   AlfaAction.reload()       close the popup AND refresh the opener
 *   AlfaAction.run(action, data)  run another action of THIS entity in place
 *                                 (e.g. the "Yes" of a confirm dialog)
 *
 * Or, declaratively, on any element:
 *   data-alfa-close            click -> close
 *   data-alfa-reload           click -> close + refresh
 *   data-alfa-run="actionId"   click -> run that action (optional data-alfa-data='{...}')
 *   data-alfa-form             a block whose named controls (select, radio,
 *                              text) travel with a data-alfa-run click inside
 *                              it: a layout hands a choice to its next arm
 *                              without a script of its own
 *
 * @package     Alfa.Component
 * @subpackage  Administrator.JavaScript
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.3.0
 */
(function () {
    'use strict';

    /**
     * One decision, one POST. The key names this page view, so a second submit
     * that still slipped through is refused by the server as already run; the
     * flag stops it here while the first POST is in flight.
     */
    var mintKey = function () {
        return 'once-' + Date.now() + '-' + Math.random().toString(36).slice(2);
    };
    var pageOnce = mintKey();
    var submitted = false;

    // A page restored from the back-forward cache is a new view of the form.
    window.addEventListener('pageshow', function (event) {
        if (event && event.persisted) {
            pageOnce = mintKey();
            submitted = false;
        }
    });

    var AlfaAction = {
        /**
         * Close the popup. When reload is true, the opener refreshes too.
         *
         * @param {boolean} [reload]
         */
        close: function (reload) {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage(
                    { messageType: 'alfa:action-frame', reload: !!reload },
                    window.location.origin
                );
            } else if (reload) {
                window.location.reload();
            }
        },

        /** Close the popup and refresh the opener. */
        reload: function () {
            this.close(true);
        },

        /**
         * Run another action of the SAME entity in this same popup: keeps the
         * current group/ids, swaps only the action. Use it for a confirm
         * dialog's "Yes" (navigate to the real, mutating action).
         *
         * @param {string} action
         * @param {object} [data]
         *
         * @return {boolean} false when this page view has already submitted
         */
        run: function (action, data) {
            if (submitted) {
                return false;
            }

            submitted = true;

            // SUBMIT, never navigate. A state-changing arm is refused over GET,
            // and a POST-loaded page has no query string to rebuild from; the
            // context comes from the server instead.
            var ctx = (window.Joomla && Joomla.getOptions)
                ? (Joomla.getOptions('alfa.action.context') || {})
                : {};

            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php';
            form.style.display = 'none';

            var fields = {
                option: 'com_alfa',
                view: 'action',
                tmpl: 'component',
                group: ctx.group || '',
                action: action,
                once: pageOnce
            };

            // An item field's follow-up runs on the row its popup was opened for.
            ['payment_id', 'shipment_id', 'field_id', 'scope', 'scope_id', 'item_id'].forEach(function (k) {
                if (ctx[k]) { fields[k] = ctx[k]; }
            });

            if (ctx.token) { fields[ctx.token] = 1; }

            var extra = data || {};
            Object.keys(extra).forEach(function (k) { fields[k] = extra[k]; });

            Object.keys(fields).forEach(function (k) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = k;
                input.value = fields[k];
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();

            return true;
        }
    };

    window.AlfaAction = AlfaAction;

    // Declarative hooks so a layout needs zero JS to close / refresh / run.
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-alfa-close], [data-alfa-reload], [data-alfa-run]');
        if (!el) { return; }

        event.preventDefault();

        if (el.hasAttribute('data-alfa-run')) {
            var data = {};
            try { data = JSON.parse(el.getAttribute('data-alfa-data') || '{}'); } catch (e) { data = {}; }

            // WHAT THE OPERATOR CHOSE TRAVELS WITH THE CLICK. Every named,
            // enabled control in the enclosing [data-alfa-form] is posted;
            // an unchecked radio or box stays out, as it would from a form,
            // so a layout needs no script to hand a choice to its next arm.
            var scope = el.closest('[data-alfa-form]');

            if (scope) {
                scope.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (control) {
                    if (control.disabled) { return; }
                    if ((control.type === 'radio' || control.type === 'checkbox') && !control.checked) { return; }
                    data[control.name] = control.value;
                });
            }

            // A second click while the POST is in flight would run the arm
            // twice: for a booking, that is a second parcel.
            el.disabled = true;

            AlfaAction.run(el.getAttribute('data-alfa-run'), data);
        } else if (el.hasAttribute('data-alfa-reload')) {
            AlfaAction.reload();
        } else {
            AlfaAction.close();
        }
    });
}());
