/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The order-line popup (tmpl/order/edit_order_item.php).
 *
 * Two jobs, and neither of them is arithmetic:
 *
 *   - the product search of a NEW line, which reloads the popup as the full
 *     edit view of the picked product (configure first, one save);
 *   - the PREVIEW: every change posts the whole form to the save's own dry run
 *     (order.previewOrderItem) and shows the figures the server answers with.
 *     What the popup shows is what Save writes, because the server computes
 *     both with the same function. This file never multiplies, divides or
 *     formats money.
 *
 * Markup is the layout's: this file fills [data-order-item-preview-field]
 * slots with text and toggles hidden/disabled attributes, nothing more.
 */
(function () {
    'use strict';

    const form = document.getElementById('order-item-form');

    if (!form) {
        return;
    }

    const options = (window.Joomla && Joomla.getOptions('com_alfa.orderItem')) || {};
    const T = (key) => (window.Joomla && Joomla.Text ? Joomla.Text._(key) : key);

    // The search publishes its rates and percentages as exact decimal strings:
    // read on their digits, never through a float.
    const decimal = (text) => /^(-?)(\d+)(?:\.(\d+))?$/.exec(String(text));
    const isPositive = (text) => {
        const match = decimal(text);

        return match !== null && match[1] === '' && /[1-9]/.test(match[2] + (match[3] || ''));
    };
    // A two-place percentage rounded to a whole percent, half away from zero.
    const wholePercent = (text) => {
        const match = decimal(text);

        if (match === null) {
            return 0;
        }

        const whole = parseInt(match[2], 10) + ((match[3] || '0').charAt(0) >= '5' ? 1 : 0);

        return match[1] === '-' ? -whole : whole;
    };

    // PRODUCT SEARCH (a new line only; the layout renders nothing on edit)
    const searchUrlEl = document.getElementById('product-search-url');

    if (searchUrlEl) {
        const searchUrl = searchUrlEl.value;
        const searchInput = document.getElementById('product-search-input');
        const searchResults = document.getElementById('product-search-results');
        const searchSpinner = document.getElementById('search-spinner');
        const searchSection = document.getElementById('product-search-section');
        const headerSection = document.getElementById('selected-product-header');
        const btnChange = document.getElementById('btn-change-product');
        const resultTemplate = document.getElementById('product-result-template');
        const minChars = 2;
        let searchTimer = null;

        const hideResults = () => searchResults && searchResults.classList.remove('show');

        const setText = (root, selector, value) => {
            const el = root.querySelector(selector);

            if (el) {
                el.textContent = value;
            }
        };

        // Configure-first: the picked product reloads this popup as its full
        // edit view (configuration + pricing), one save in total.
        const selectProduct = (product) => {
            window.location.href = 'index.php?option=com_alfa&view=order&layout=edit_order_item&tmpl=component'
                + '&id=0&id_order=' + encodeURIComponent(options.orderId || 0) + '&id_item=' + encodeURIComponent(product.id);
        };

        const renderResults = (results) => {
            searchResults.textContent = '';

            if (results.length === 0) {
                searchResults.textContent = T('COM_ALFA_NO_PRODUCTS_FOUND');
                searchResults.classList.add('show');

                return;
            }

            results.forEach((product) => {
                const clone = resultTemplate.content.cloneNode(true);
                const wrapper = clone.querySelector('.product-result');

                setText(clone, '[data-field="name"]', product.name);
                setText(clone, '[data-field="id"]', 'ID: ' + product.id);
                setText(clone, '[data-field="stock"]', product.stock);
                setText(clone, '[data-field="sku"]', product.sku ? T('COM_ALFA_SKU') + ': ' + product.sku + ' - ' : '');

                // The units are the server's texts, as the product page prints them.
                setText(clone, '[data-field="price"]', product.price_text || T('COM_ALFA_NO_PRICE'));

                if (isPositive(product.tax_rate) && product.price_tax_excl_text) {
                    setText(clone, '[data-field="tax-info"]', '(' + T('COM_ALFA_TAX_EXCL_SHORT') + ': ' + product.price_tax_excl_text + ')');
                }

                if (product.has_discount && wholePercent(product.discount_percent) > 0) {
                    setText(clone, '[data-field="discount-badge"]', ' -' + wholePercent(product.discount_percent) + '%');
                }

                const stockContainer = clone.querySelector('[data-field="stock-container"]');

                if (stockContainer && product.stock <= 0) {
                    stockContainer.classList.add('low');
                }

                if (wrapper) {
                    wrapper.addEventListener('click', () => selectProduct(product));
                }

                searchResults.appendChild(clone);
            });

            searchResults.classList.add('show');
        };

        const fetchProducts = (query) => {
            fetch(searchUrl + '&q=' + encodeURIComponent(query))
                .then((response) => response.json())
                .then((data) => {
                    searchSpinner.classList.remove('show');
                    renderResults(data.results || []);
                })
                .catch(() => {
                    searchSpinner.classList.remove('show');
                    searchResults.textContent = T('COM_ALFA_SEARCH_ERROR');
                    searchResults.classList.add('show');
                });
        };

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const query = this.value.trim();
                clearTimeout(searchTimer);

                if (query.length < minChars) {
                    hideResults();

                    return;
                }

                searchSpinner.classList.add('show');
                searchTimer = setTimeout(() => fetchProducts(query), 300);
            });

            document.addEventListener('click', (event) => {
                if (!searchInput.contains(event.target) && !searchResults.contains(event.target)) {
                    hideResults();
                }
            });
        }

        if (btnChange) {
            btnChange.addEventListener('click', () => {
                const idItem = document.getElementById('jform_id_item');

                if (idItem) {
                    idItem.value = 0;
                }

                searchSection.style.display = '';
                headerSection.style.display = 'none';
                searchInput.value = '';
                searchInput.focus();
            });
        }
    }

    // THE PRICE COMMAND: the amount is posted under Set only, and the
    // manual discount's custom-price tick only while a custom price is set
    const amount = form.querySelector('[data-order-item-custom-amount]');
    const scope = form.querySelector('[data-order-item-manual-scope]');

    const syncCommand = () => {
        const chosen = form.querySelector('[data-order-item-price-choice]:checked');
        const command = chosen ? chosen.value : 'leave';

        if (amount) {
            amount.disabled = command !== 'set';
        }

        // "Apply this discount to the custom price" is shown while a
        // custom price is set: a Set, or a kept one under Leave. Hidden, its
        // inputs are disabled and post nothing, so the stored choice stands.
        if (scope) {
            const shown = command === 'set'
                || (command === 'leave' && scope.getAttribute('data-has-custom-price') === '1');

            scope.hidden = !shown;
            scope.querySelectorAll('[data-order-item-manual-scope-input]').forEach((input) => {
                input.disabled = !shown;
            });
        }
    };

    form.querySelectorAll('[data-order-item-price-choice]').forEach((choice) => {
        choice.addEventListener('change', syncCommand);
    });

    syncCommand();

    // PAYLOAD BASELINES: a widget's own re-serialisation is not a change
    // A payload widget (the configurator) re-serialises its stored
    // composition when it initialises, so the value it posts differs from the
    // frozen one although nobody configured anything. What a payload input
    // holds at the operator's FIRST interaction with its surface is what the
    // popup showed; it rides every post, the preview's and the save's, as
    // itemfields_payload_baseline[fieldId], and the server reads a composition
    // equal to it as untouched. Until the operator touches a surface, its
    // baseline is the current value.
    const payloadName = /^jform\[itemfields_payload\]\[(\d+)\]$/;
    const payloadInputs = (root) => root.querySelectorAll('input[name^="jform[itemfields_payload]["]');
    const baselines = new Map();

    // A refused Save's redisplay carries the baseline of each composition the
    // operator changed: the widget re-opens on that composition, which must
    // still read as a change. An unchanged one carries none, so its baseline
    // is taken afresh below, as on a first open.
    form.querySelectorAll('[data-order-item-payload-baseline]').forEach((kept) => {
        baselines.set('jform[itemfields_payload][' + kept.getAttribute('data-order-item-payload-baseline') + ']', kept.value);
    });

    form.querySelectorAll('.order-item-config-surface').forEach((surface) => {
        const freeze = (event) => {
            if (!event.isTrusted) {
                return;
            }

            payloadInputs(surface).forEach((input) => {
                if (!baselines.has(input.name)) {
                    baselines.set(input.name, input.value);
                }
            });
        };

        ['pointerdown', 'keydown', 'wheel', 'dragenter', 'drop', 'paste'].forEach((type) => {
            surface.addEventListener(type, freeze, { capture: true, passive: true });
        });
    });

    // Fired for every entry list the form builds: the native submit and the
    // preview's new FormData(form) alike.
    form.addEventListener('formdata', (event) => {
        payloadInputs(form).forEach((input) => {
            const match = payloadName.exec(input.name);

            if (match && !input.disabled) {
                event.formData.set(
                    'itemfields_payload_baseline[' + match[1] + ']',
                    baselines.has(input.name) ? baselines.get(input.name) : input.value,
                );
            }
        });
    });

    // THE PREVIEW: the save's own dry run, shown as the server wrote it
    const box = form.querySelector('[data-order-item-preview]');

    if (!box || !options.previewUrl) {
        return;
    }

    const slots = box.querySelectorAll('[data-order-item-preview-field]');
    const unchanged = box.querySelector('[data-order-item-preview-unchanged]');
    const error = box.querySelector('[data-order-item-preview-error]');
    let timer = null;
    let sequence = 0;

    const show = (answer) => {
        const ok = Boolean(answer && answer.success);
        const display = ok ? (answer.display || {}) : {};

        box.setAttribute('data-state', ok ? (display.changed ? 'changed' : 'unchanged') : 'error');

        slots.forEach((slot) => {
            const value = display[slot.getAttribute('data-order-item-preview-field')];
            slot.textContent = value === undefined || value === null ? '' : String(value);
        });

        if (unchanged) {
            unchanged.hidden = !(ok && !display.changed);
        }

        if (error) {
            error.hidden = ok;
            error.textContent = ok ? '' : String((answer && answer.message) || options.failed || '');
        }
    };

    const run = () => {
        const product = form.querySelector('[name="jform[id_item]"]');

        // Nothing to preview until a product is chosen.
        if (!product || !(parseInt(product.value, 10) > 0)) {
            return;
        }

        // The whole form, as Save would post it, minus the uploads' bytes: a
        // preview must not carry a file anywhere. A CHOSEN file rides as a
        // marker in its slot (conf_file_chosen[field][slot]) instead, because
        // the save's upload makes the line a reconfigure and the preview must
        // reach that same verdict.
        const body = new FormData();
        const uploadSlot = /^conf_file(\[.+)$/;

        new FormData(form).forEach((value, key) => {
            if (key === 'task') {
                return;
            }

            if (value instanceof File) {
                const slot = uploadSlot.exec(key);

                if (slot && (value.name !== '' || value.size > 0)) {
                    body.append('conf_file_chosen' + slot[1], '1');
                }

                return;
            }

            body.append(key, value);
        });

        const mine = ++sequence;

        fetch(options.previewUrl, { method: 'POST', body, credentials: 'same-origin' })
            .then((response) => response.json())
            .then((answer) => {
                if (mine === sequence) {
                    show(answer);
                }
            })
            .catch(() => {
                if (mine === sequence) {
                    show({ success: false, message: options.failed });
                }
            });
    };

    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(run, 250);
    };

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);

    run();
})();
