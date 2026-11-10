/**
 * Alfa Commerce: the order-completed page's Print control
 *
 * Binds the page's own Print button (data-action="order-print") to the browser's
 * print dialog. The page's stylesheet decides what a print shows; this script
 * builds nothing and changes nothing on the page.
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var control = event.target instanceof Element ? event.target.closest('[data-action="order-print"]') : null;

        if (control !== null) {
            window.print();
        }
    });
})();
