/**
 * Store-credit partial refund: amount form.
 *
 * Loaded by layouts/actions/action_credit_refund_partial.php. Reads the typed
 * amount and navigates to the MUTATING 'credit_refund_partial_do' arm via
 * AlfaAction.run(); the declarative data-alfa-run attribute can only carry
 * values known at render time, hence this file.
 *
 * The form arm itself never mutates: a GET that refunds money is a GET a
 * browser prefetch can fire.
 *
 * @package     Alfa.Administrator
 * @subpackage  com_alfa
 * @author      Agamemnon Fakas <info@easylogic.gr>
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 *
 * @since  2.0.0
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-credit-refund-submit]');

        if (!button) {
            return;
        }

        var scope = button.closest('[data-credit-refund]') || document;
        var input = scope.querySelector('[data-credit-refund-amount]');

        if (!input || !window.AlfaAction) {
            return;
        }

        // Native constraint check (required / min / max / step); the browser
        // shows its own message, no generated markup.
        if (!input.checkValidity()) {
            input.reportValidity();

            return;
        }

        window.AlfaAction.run('credit_refund_partial_do', { amount: input.value });
    });
})();
