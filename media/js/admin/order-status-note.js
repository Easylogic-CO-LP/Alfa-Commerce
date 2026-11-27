/**
 * The order screen's status change: the "Notify the customer by e-mail" box follows the chosen status, ticked when
 * that status e-mails the customer by itself, each time the status is changed. The operator then sets it for this
 * change; the note and the box are shown by their own rule while the chosen status differs from the stored one.
 *
 * Markup is the form's: this file sets one checkbox, nothing more.
 *
 * Registered as web asset: com_alfa.admin.order-status-note
 * Loaded by: administrator/components/com_alfa/tmpl/order/edit.php
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function () {
    'use strict';

    const options = (window.Joomla && Joomla.getOptions('com_alfa.orderStatusNote')) || {};
    const notifies = options.notifies || {};
    const select = document.getElementById('jform_id_order_status');
    const box = document.getElementById('jform_status_notify');

    if (!select || !box) {
        return;
    }

    select.addEventListener('change', () => {
        box.checked = Number(notifies[select.value]) === 1;
    });
})();
