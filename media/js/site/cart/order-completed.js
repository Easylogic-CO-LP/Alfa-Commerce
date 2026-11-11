/**
 * Alfa Commerce: the order-completed page's Print
 *
 * Joomla's own print pattern: Print opens this same keyed address with tmpl=component and
 * print=1, built from the address the page was asked by (so the key is never written into the
 * page), in a window of its own, where only the order shows. That print view's root carries
 * data-order-print: once it has loaded the browser's print dialog opens, and its own Print
 * prints it again in place. The page's stylesheet decides what a print shows; this script
 * builds nothing and changes nothing on the page.
 *
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function () {
    'use strict';

    var ROOT = '[data-order-completed]';
    var PRINT_VIEW = '[data-order-completed][data-order-print]';

    window.addEventListener('load', function () {
        if (document.querySelector(PRINT_VIEW) !== null) {
            window.print();
        }
    });

    document.addEventListener('click', function (event) {
        var control = event.target instanceof Element ? event.target.closest(ROOT + ' [data-action="order-print"]') : null;

        if (control === null) {
            return;
        }

        if (control.closest(PRINT_VIEW) !== null) {
            window.print();

            return;
        }

        var address = new URL(window.location.href);
        address.searchParams.set('tmpl', 'component');
        address.searchParams.set('print', '1');
        window.open(address.toString(), '_blank');
    });
})();
