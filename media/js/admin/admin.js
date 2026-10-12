window.Joomla = window.Joomla || {};

(function (window, Joomla) {
    Joomla.toggleField = function (id, task, field) {

        var f = document.adminForm, i = 0, cbx, cb = f[ id ];

        if (!cb) return false;

        while (true) {
            cbx = f[ 'cb' + i ];

            if (!cbx) break;

            cbx.checked = false;
            i++;
        }

        var inputField   = document.createElement('input');

        inputField.type  = 'hidden';
        inputField.name  = 'field';
        inputField.value = field;
        f.appendChild(inputField);

        cb.checked = true;
        f.boxchecked.value = 1;
        Joomla.submitform(task);

        return false;
    };

    // Per-location stock tables: the total is computed from the location inputs,
    // never entered. One delegated listener serves every table on the page.
    document.addEventListener('input', function (event) {
        var input = event.target;

        if (!(input instanceof HTMLInputElement) || !input.hasAttribute('data-alfa-location-stock')) {
            return;
        }

        var container = input.closest('[data-alfa-location-total]');
        var output = container ? container.querySelector('[data-alfa-location-total-value]') : null;

        if (!output) {
            return;
        }

        var total = 0;

        container.querySelectorAll('[data-alfa-location-stock]').forEach(function (field) {
            total += parseFloat(field.value) || 0;
        });

        // Four decimals, the precision the back office prints quantities at.
        output.textContent = String(Math.round(total * 10000) / 10000);
    });
})(window, Joomla);