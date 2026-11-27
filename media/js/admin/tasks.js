/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

/**
 * The Background screen's tabs: the server lists one tab at a time, the one the form's hidden tab field names, with
 * that tab's own filters, order and pages. Choosing the other tab writes its name into the field and submits the list's
 * form, so the page comes back listing it. The tab the page opened on is already the field's, so nothing is sent
 * for it.
 */
(() => {
    'use strict';

    const form = document.getElementById('adminForm');
    const tabs = document.getElementById('alfaBackground');

    if (!form || !tabs || !form.elements.tab) {
        return;
    }

    tabs.addEventListener('joomla.tab.shown', (event) => {
        const panel = event.target.getAttribute('aria-controls') || '';
        const tab = panel.replace(/^background-/, '');

        if (tab !== '' && tab !== form.elements.tab.value) {
            form.elements.tab.value = tab;
            Joomla.submitform('', form);
        }
    });
})();
