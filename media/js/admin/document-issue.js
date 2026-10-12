/**
 * Sales-document issue modal.
 *
 * Visibility is NOT handled here: every conditional block in the form is a
 * Joomla showon, built from the type registry's own properties in
 * OrderModel::getDocumentForm(). What is left is the genuinely dynamic data
 * showon cannot express: narrowing a list of options, re-capping a column of
 * numbers, and swapping which charges group is on screen.
 *
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */
(function (Joomla, document) {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('document-form');

        if (!form) {
            return;
        }

        var typeSelect = form.querySelector('[name="doc_type"]');
        var seriesSelect = form.querySelector('[name="id_series"]');
        var correctsSelect = form.querySelector('[name="id_corrects"]');
        // A Yes/No switcher, so a radio group: the answer is whichever one
        // is checked, and "off" means checking the 0 rather than clearing.
        var combinedRadios = form.querySelectorAll('[name="is_combined"]');
        // The sales group of the lines picker; each document a credit may
        // correct has a group of its own beside it.
        var linesTable = form.querySelector('.issue-lines-group[data-corrects="0"]');
        var chargesBlock = document.getElementById('issue_charges_block');
        var creditBuyerBlock = document.getElementById('issue_credit_buyer');
        var buyerEmail = form.querySelector('[name="buyer_email"]');
        var movementControls = form.querySelectorAll('[name="movement_purpose"], [name="carrier_name"], [name="vehicle_number"]');

        // Which series belong to which type. Published on the select itself
        // because Joomla drops a data-* attribute from a rendered <option>,
        // so the options cannot say which type they are for.
        var seriesByType = seriesSelect
            ? JSON.parse(seriesSelect.getAttribute('data-series-by-type') || '{}')
            : {};

        var remaining = linesTable ? JSON.parse(linesTable.getAttribute('data-remaining')) : {};

        // The types whose tax id is MANDATORY. Separate from whether the field
        // is shown at all; every fiscal type asks for one, only some insist.
        var vatField = form.querySelector('[name="buyer_vat_number"]');
        var vatTypes = vatField
            ? (vatField.getAttribute('data-vat-types') || '').split(',').filter(Boolean)
            : [];

        /**
         * Is the field's row on screen? Joomla's showon hides a .control-group
         * by adding the `hidden` CLASS, never an inline display, so asking the
         * element for its own style.display always answers ''. The computed
         * value is the one that knows, and it stays right if the mechanism
         * ever changes.
         */
        function rowVisible(el) {
            var wrapper = el ? el.closest('.control-group') : null;

            return wrapper !== null && window.getComputedStyle(wrapper).display !== 'none';
        }

        /**
         * Offer only the series that belong to the picked type, keeping the
         * current pick when it survives the switch.
         */
        function syncSeries() {
            if (!seriesSelect) {
                return;
            }

            var type = typeSelect ? typeSelect.value : '';
            var previous = seriesSelect.value;

            seriesSelect.textContent = '';

            (seriesByType[type] || []).forEach(function (series) {
                var option = document.createElement('option');

                option.value = String(series.id);
                option.textContent = series.label;
                seriesSelect.appendChild(option);
            });

            // Kept only when it belongs to the new type: assigning a value no
            // option has leaves the list with NOTHING picked (found in the
            // browser: switching an invoice to a credit note blanked the
            // required series). Otherwise the type's default stays picked:
            // DocumentHelper::seriesForType() lists it first.
            if (previous && Array.prototype.some.call(seriesSelect.options, function (option) {
                return option.value === previous;
            })) {
                seriesSelect.value = previous;
            }
        }

        /**
         * Show the one lines group that belongs to this draft: the order's own
         * for a sales document, the corrected document's for a credit. Hidden
         * is not enough, a form posts every ENABLED control, so the inputs of
         * every other group are disabled too. A row the server rendered
         * disabled (nothing left on it) stays disabled: that state is read
         * once, before this ever toggles it.
         *
         * The sales group is re-capped against the picked type's own
         * undocumented pool: what an invoice has already covered says nothing
         * about what a delivery note still may. A credit row is capped by what
         * credits have not yet given back of the corrected document's line,
         * which the server published on the row itself.
         *
         * A redisplayed draft (the server marks its group data-kept after a
         * refused issue) keeps each row's tick and typed quantity on the FIRST
         * sync: they are what the operator is correcting. The cap and the
         * disabled state still follow what is left; a later type change
         * re-defaults every row as before.
         */
        function syncLines(keep) {
            var isCredit = rowVisible(correctsSelect);
            var wanted = isCredit ? (correctsSelect.value || '') : '0';

            form.querySelectorAll('.issue-lines-group').forEach(function (group) {
                var match = group.getAttribute('data-corrects') === wanted;
                var kept = keep === true && group.hasAttribute('data-kept');

                group.hidden = !match;

                group.querySelectorAll('input').forEach(function (input) {
                    if (input.dataset.closed === undefined) {
                        input.dataset.closed = input.disabled ? '1' : '0';
                    }

                    input.disabled = !match || input.dataset.closed === '1';
                });

                if (!match || group === linesTable) {
                    return;
                }

                group.querySelectorAll('tr[data-line-id]').forEach(function (row) {
                    var qty = row.querySelector('.issue-line-qty');
                    var check = row.querySelector('.issue-line-check');
                    var left = Number(row.dataset.remaining);
                    var closed = (qty && qty.dataset.closed === '1') || (check && check.dataset.closed === '1');

                    if (qty) {
                        qty.max = left;

                        if (!kept) {
                            qty.value = left > 0 ? left : 0;
                        }

                        qty.disabled = left <= 0 || closed;
                    }

                    if (check) {
                        if (!kept) {
                            check.checked = left > 0;
                        }

                        check.disabled = left <= 0 || closed;
                    }
                });
            });

            if (!linesTable || isCredit) {
                return;
            }

            var pool = remaining[typeSelect ? typeSelect.value : ''] || {};
            var kept = keep === true && linesTable.hasAttribute('data-kept');

            linesTable.querySelectorAll('tr[data-line-id]').forEach(function (row) {
                var lineId = row.getAttribute('data-line-id');
                var entry = pool[lineId];
                var cell = row.querySelector('.issue-line-remaining');
                var qty = row.querySelector('.issue-line-qty');
                var check = row.querySelector('.issue-line-check');
                var left = entry ? Number(entry.remaining) : 0;

                if (cell) {
                    cell.textContent = entry ? String(left) : '-';
                }

                if (qty) {
                    qty.max = left;

                    if (!kept) {
                        qty.value = left > 0 ? left : 0;
                    }

                    qty.disabled = left <= 0;
                }

                if (check) {
                    if (!kept) {
                        check.checked = left > 0;
                    }

                    check.disabled = left <= 0;
                }
            });
        }

        /**
         * Show the one charges group that belongs to this draft: the order's
         * own for a sales document, the corrected document's for a credit.
         * Hidden is not enough (a form posts every ENABLED control, hidden or
         * not), so the inputs of every other group are disabled too. A row the
         * server rendered disabled (its charge is closed) stays disabled: that
         * state is read once, before this ever toggles it.
         */
        function syncCharges() {
            if (!chargesBlock) {
                return;
            }

            // The corrects field is only on screen for a credit; when showon
            // has hidden it, this is a sales draft and group 0 is the one.
            var isCredit = rowVisible(correctsSelect);
            var wanted = isCredit ? (correctsSelect.value || '') : '0';
            var shown = false;

            chargesBlock.querySelectorAll('.issue-charges-group').forEach(function (group) {
                var match = group.getAttribute('data-corrects') === wanted;

                group.hidden = !match;

                group.querySelectorAll('input').forEach(function (input) {
                    if (input.dataset.closed === undefined) {
                        input.dataset.closed = input.disabled ? '1' : '0';
                    }

                    input.disabled = !match || input.dataset.closed === '1';
                });

                shown = shown || match;
            });

            chargesBlock.hidden = !shown;
        }

        /**
         * Show the credit's buyer (the corrected document's, read-only) for
         * the document the Corrects picker names, or the prompt to pick one.
         * The blocks are server-rendered and hold no control, so this only
         * shows and hides them.
         */
        function syncCreditBuyer() {
            if (!creditBuyerBlock) {
                return;
            }

            var picked = correctsSelect ? (correctsSelect.value || '') : '';

            creditBuyerBlock.querySelectorAll('.issue-credit-buyer').forEach(function (block) {
                block.hidden = block.getAttribute('data-corrects') !== picked;
            });
        }

        /**
         * A credit hides the editable buyer; it is made out to the buyer of
         * the document it corrects, and the task ignores these fields for it.
         * Hidden does not exempt a field from Joomla's validator, which checks
         * every ENABLED control: an address it rejects, left in the hidden
         * email, would block the credit with nothing on screen to correct.
         * So the email's check is lifted while a credit is picked and restored
         * for every other type. The fields stay enabled: they post, and a
         * refused credit keeps them for a switch back to a sales type.
         */
        function syncBuyerEmail() {
            if (!buyerEmail) {
                return;
            }

            var isCredit = rowVisible(correctsSelect);

            buyerEmail.classList.toggle('validate-email', !isCredit);

            if (isCredit) {
                buyerEmail.classList.remove('invalid');
                buyerEmail.removeAttribute('aria-invalid');
            }
        }

        /**
         * A field showon has hidden must not keep a value: the movement block
         * asks to be shown when combined is ticked, and a tick left behind on
         * a type that cannot combine would show it on the wrong document.
         */
        function clearHiddenCombined() {
            if (!combinedRadios.length) {
                return;
            }

            if (rowVisible(combinedRadios[0])) {
                return;
            }

            var changed = false;

            combinedRadios.forEach(function (radio) {
                var wanted = radio.value === '0';

                if (radio.checked !== wanted) {
                    radio.checked = wanted;
                    changed = true;
                }
            });

            if (changed) {
                combinedRadios[0].dispatchEvent(new Event('change', {bubbles: true}));
            }
        }

        /**
         * The movement block posts only while it is on screen. Hidden is not
         * enough (a form posts every ENABLED control), and the hidden purpose
         * list's default (Sale) printed a Movement block on invoices and credit
         * notes. So each control is disabled while showon hides its row and
         * enabled again, with what was typed in it, when the row shows.
         */
        function syncMovement() {
            movementControls.forEach(function (control) {
                control.disabled = !rowVisible(control);
            });
        }

        /**
         * Mark the tax id required on the types that insist on one, and carry
         * the star Joomla would have rendered had the XML known the answer.
         */
        function syncVat() {
            if (!vatField) {
                return;
            }

            var needed = vatTypes.indexOf(typeSelect ? typeSelect.value : '') !== -1;
            var label = form.querySelector('label[for="' + vatField.id + '"]');

            vatField.required = needed;

            if (!label) {
                return;
            }

            var star = label.querySelector('.star');

            if (needed && !star) {
                star = document.createElement('span');
                star.className = 'star';
                star.setAttribute('aria-hidden', 'true');
                star.innerHTML = '&#160;*';
                label.appendChild(star);
            } else if (!needed && star) {
                star.remove();
            }
        }

        /**
         * A credit must name the document it corrects, and only a credit may.
         * Marked required on the credit types so Joomla's own validator
         * refuses the submit and shows the field's invalid-feedback; the
         * placeholder is disabled and empty, so "nothing picked" fails it.
         */
        function syncCorrects() {
            if (!correctsSelect) {
                return;
            }

            var isCredit = rowVisible(correctsSelect);

            correctsSelect.required = isCredit;
            correctsSelect.classList.toggle('required', isCredit);

            if (!isCredit) {
                correctsSelect.classList.remove('invalid');
                correctsSelect.removeAttribute('aria-invalid');
            }
        }

        function syncAll(keep) {
            syncSeries();
            syncVat();
            syncCorrects();
            syncLines(keep === true);
            clearHiddenCombined();
            syncMovement();
            syncCharges();
            syncCreditBuyer();
            syncBuyerEmail();
        }

        if (typeSelect) {
            // showon runs on the same event; let it move the blocks first so
            // clearHiddenCombined and syncCharges read settled wrappers.
            typeSelect.addEventListener('change', function () {
                window.setTimeout(syncAll, 0);
            });
        }

        if (correctsSelect) {
            correctsSelect.addEventListener('change', syncLines);
            correctsSelect.addEventListener('change', syncCharges);
            correctsSelect.addEventListener('change', syncCreditBuyer);
        }

        combinedRadios.forEach(function (radio) {
            radio.addEventListener('change', syncCharges);
        });

        // showon announces every row it shows or hides, after it has moved it,
        // so the movement controls follow the type and the combined tick alike.
        form.addEventListener('joomla:showon-show', syncMovement);
        form.addEventListener('joomla:showon-hide', syncMovement);

        /**
         * A credit's charge amount belongs to its tick. Ticking fills what the
         * document has left (the operator may lower it), unticking clears it,
         * and typing an amount ticks the charge. What posts is always an
         * amount the operator can see, never a whole credit implied by an
         * empty field. The server checks it again, at the document's scale.
         */
        if (chargesBlock) {
            chargesBlock.addEventListener('change', function (event) {
                var pick = event.target.closest('.issue-charge-pick');
                var amount = pick ? pick.closest('tr').querySelector('.issue-charge-amount') : null;

                if (!amount) {
                    return;
                }

                if (!pick.checked) {
                    amount.value = '';
                } else if (amount.value === '') {
                    amount.value = amount.getAttribute('data-remaining') || '';
                }
            });

            chargesBlock.addEventListener('input', function (event) {
                var amount = event.target.closest('.issue-charge-amount');
                var pick = amount ? amount.closest('tr').querySelector('.issue-charge-pick') : null;

                if (pick && !pick.disabled) {
                    pick.checked = amount.value.trim() !== '';
                }
            });
        }

        // The first sync keeps a redisplayed draft (syncLines); every later
        // one (a type change) re-defaults the rows.
        syncAll(true);
    });
})(window.Joomla || {}, document);
