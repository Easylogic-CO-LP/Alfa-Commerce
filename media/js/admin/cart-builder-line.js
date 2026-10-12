// @package    Alfa Commerce
// @author     Agamemnon Fakas <info@easylogic.gr>
// @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
// @license    GNU General Public License version 3 or later; see LICENSE
//
// The builder popup's quote (tmpl/cart/builder_line.php).
//
// Every change of the quantity or the configuration asks cart.priceLineConfig
// to render the quote again (layouts/cart/builder_quote.php) and puts the
// server's HTML in place. The quote is the line as the cart will price it; this
// file does no arithmetic, formats nothing and builds no markup of its own.
//
// Line comments only: the cart-builder-items suite reads this file whole.
(function () {
    'use strict';

    const options = (window.Joomla && Joomla.getOptions('com_alfa.cartBuilderLine')) || {};
    const form = document.getElementById('alfa-builder-line-form');
    const figures = document.querySelector('[data-builder-quote-figures]');
    const error = document.querySelector('[data-builder-quote-error]');

    if (!form || !figures || !options.quoteUrl) {
        return;
    }

    // The quantity and the configuration are what the quote depends on; the
    // form's own task and form fields never travel, so the answer is JSON.
    const quoted = (key) => key === 'quantity' || key.startsWith('sel[') || key.startsWith('opt[') || key.startsWith('conf[');

    // Only the latest request may paint: an earlier answer arriving late is dropped.
    let sequence = 0;

    // A failed refresh hides the previous figures: they no longer match the box.
    const showError = (message) => {
        figures.hidden = true;

        if (error) {
            error.textContent = message;
            error.hidden = false;
        }
    };

    const refresh = () => {
        const params = new URLSearchParams();

        new FormData(form).forEach((value, key) => {
            if (quoted(key)) {
                params.append(key, value);
            }
        });

        const mine = ++sequence;

        fetch(options.quoteUrl + '&' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => response.json())
            .then((answer) => {
                if (mine !== sequence) {
                    return;
                }

                if (answer && answer.success && answer.data && typeof answer.data.html === 'string') {
                    figures.innerHTML = answer.data.html;
                    figures.hidden = false;

                    if (error) {
                        error.hidden = true;
                        error.textContent = '';
                    }

                    return;
                }

                showError((answer && answer.message) || options.failed || '');
            })
            .catch(() => {
                if (mine === sequence) {
                    showError(options.failed || '');
                }
            });
    };

    form.addEventListener('change', (event) => {
        const name = (event.target && event.target.getAttribute('name')) || '';

        if (quoted(name)) {
            refresh();
        }
    });
}());
