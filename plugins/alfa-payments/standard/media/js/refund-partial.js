/**
 * Standard payment plugin: partial-refund amount form.
 *
 * Loaded by tmpl/action_refund_partial.php through the Web Asset Manager.
 * Reads the typed amount and navigates to the mutating 'refund_partial_do'
 * arm via AlfaAction.run(): the declarative data-alfa-run attribute can
 * only carry values known at render time, hence this file.
 *
 * @package     Alfa.Plugin
 * @subpackage  AlfaPayments.Standard
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.9.0
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-refund-partial-submit]');

        if (!button) {
            return;
        }

        var scope = button.closest('[data-refund-partial]') || document;
        var input = scope.querySelector('[data-refund-partial-amount]');

        if (!input || !window.AlfaAction) {
            return;
        }

        // Native constraint check (required / min / max / step): the browser
        // shows its own message, no generated markup.
        if (!input.checkValidity()) {
            input.reportValidity();

            return;
        }

        window.AlfaAction.run('refund_partial_do', { amount: input.value });
    });
})();
