/**
 * Alfa Filters: panel section composer.
 *
 * Drag to reorder, click the eye to hide. Writes the result back to one hidden input as
 * JSON.
 *
 * IT WRITES NOTHING WHILE NOTHING HAS CHANGED. As long as the arrangement matches the
 * natural order the field was rendered with (the item fields' own backend ordering), the
 * input is emptied. A module that was never rearranged therefore stores no ordering at
 * all, and keeps following the catalogue's own sequence however it changes later. Only a
 * real rearrangement is persisted, and even then as a preference the server reconciles
 * against the live sections rather than as a snapshot of them.
 */
((document) => {
    'use strict';

    const sameOrder = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);

    const compose = (root) => {
        const list = root.querySelector('.afx-sections-list');
        const input = document.getElementById(root.dataset.input);
        const state = root.querySelector('[data-afx-state]');

        if (!list || !input) {
            return;
        }

        let natural = [];

        try {
            natural = JSON.parse(root.dataset.natural || '[]');
        } catch (e) {
            natural = [];
        }

        const rows = () => Array.from(list.querySelectorAll('.afx-section'));

        const sync = () => {
            const order = rows().map((li) => li.dataset.key);
            const hidden = rows().filter((li) => li.classList.contains('is-hidden')).map((li) => li.dataset.key);

            // ONLY WHAT WAS ACTUALLY SAID GETS STORED. An order identical to the natural
            // one is not a decision, it is the absence of one, and writing it down would
            // freeze today's sequence into a module that should keep following the item
            // fields. Same for an empty hidden set. Both empty -> the input is emptied, and
            // this module carries no arrangement at all.
            const payload = {};

            if (!sameOrder(order, natural)) {
                payload.order = order;
            }

            if (hidden.length) {
                payload.hidden = hidden;
            }

            const untouched = Object.keys(payload).length === 0;

            input.value = untouched ? '' : JSON.stringify(payload);

            if (state) {
                state.textContent = Joomla.Text._(
                    untouched ? 'MOD_ALFAFILTERS_SECTIONS_NATURAL' : 'MOD_ALFAFILTERS_SECTIONS_CUSTOM',
                );
            }
        };

        let dragged = null;

        list.addEventListener('dragstart', (event) => {
            const li = event.target.closest('.afx-section');

            if (!li) {
                return;
            }

            dragged = li;
            li.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            // Firefox refuses to start a drag with no payload set.
            event.dataTransfer.setData('text/plain', li.dataset.key || '');
        });

        list.addEventListener('dragend', () => {
            if (dragged) {
                dragged.classList.remove('is-dragging');
            }

            dragged = null;
            sync();
        });

        list.addEventListener('dragover', (event) => {
            if (!dragged) {
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            const over = event.target.closest('.afx-section');

            if (!over || over === dragged) {
                return;
            }

            // Past the midpoint means the pointer is heading for the far side of the row,
            // so the dragged row belongs after it; without this the row jitters between
            // two slots whenever the cursor sits on a boundary.
            const box = over.getBoundingClientRect();
            const after = event.clientY > box.top + box.height / 2;

            list.insertBefore(dragged, after ? over.nextSibling : over);
        });

        list.addEventListener('drop', (event) => event.preventDefault());

        list.addEventListener('click', (event) => {
            const eye = event.target.closest('.afx-section-eye');

            if (!eye) {
                return;
            }

            event.preventDefault();

            const li = eye.closest('.afx-section');
            const nowHidden = !li.classList.contains('is-hidden');

            li.classList.toggle('is-hidden', nowHidden);
            eye.setAttribute('aria-pressed', nowHidden ? 'false' : 'true');
            eye.querySelector('span[aria-hidden]').className = nowHidden ? 'icon-eye-slash' : 'icon-eye';
            sync();
        });

        // Keyboard parity for the drag: a pointer is not the only way to reorder a list.
        list.addEventListener('keydown', (event) => {
            if (!event.altKey || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) {
                return;
            }

            const li = event.target.closest('.afx-section');

            if (!li) {
                return;
            }

            event.preventDefault();

            if (event.key === 'ArrowUp' && li.previousElementSibling) {
                list.insertBefore(li, li.previousElementSibling);
            } else if (event.key === 'ArrowDown' && li.nextElementSibling) {
                list.insertBefore(li.nextElementSibling, li);
            }

            li.querySelector('.afx-section-eye').focus();
            sync();
        });

        const reset = root.querySelector('.afx-sections-reset');

        if (reset) {
            reset.addEventListener('click', (event) => {
                event.preventDefault();

                const byKey = new Map(rows().map((li) => [li.dataset.key, li]));

                natural.forEach((key) => {
                    const li = byKey.get(key);

                    if (li) {
                        li.classList.remove('is-hidden');
                        li.querySelector('.afx-section-eye').setAttribute('aria-pressed', 'true');
                        li.querySelector('.afx-section-eye span[aria-hidden]').className = 'icon-eye';
                        list.appendChild(li);
                    }
                });

                sync();
            });
        }

        sync();
    };

    const boot = () => document.querySelectorAll('[data-afx-sections]').forEach(compose);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(document);
