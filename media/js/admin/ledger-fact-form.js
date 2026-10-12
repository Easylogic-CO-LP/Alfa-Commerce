/**
 * Record a provider fact: the ledger's Record form (failed refund, won
 * dispute, chargeback).
 *
 * Loaded by layouts/actions/action_ledger_fact.php. Reads the typed amount,
 * reference and reason and navigates to the MUTATING '<action>_do' arm named
 * by the button, via AlfaAction.run(). The form arm itself never records.
 *
 * @package     Alfa.Administrator
 * @subpackage  com_alfa
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.14.0
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-ledger-fact-submit]');

        if (!button) {
            return;
        }

        var scope  = button.closest('[data-ledger-fact]') || document;
        var fields = scope.querySelectorAll('[data-ledger-fact-field]');

        if (!window.AlfaAction) {
            return;
        }

        // Native constraint check (required / min / max / step): the browser
        // shows its own message on the first invalid field, no generated markup.
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].checkValidity()) {
                fields[i].reportValidity();

                return;
            }
        }

        var value = function (name) {
            var field = scope.querySelector('[data-ledger-fact-field][name="' + name + '"]');

            return field ? field.value : '';
        };

        window.AlfaAction.run(button.getAttribute('data-ledger-fact-action'), {
            amount: value('amount'),
            reference: value('reference'),
            reason: value('reason')
        });
    });
})();
