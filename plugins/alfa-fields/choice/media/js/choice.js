/**
 * alfa-fields/choice — client-side glue.
 *
 * 1. Registers `choice` with document.formvalidator so Joomla's standard
 *    form-validate flow handles submission blocking, .invalid styling, and
 *    focus-on-first-error. No custom form.submit listener.
 * 2. Live UX hint ("X / max selected") + lock unchecked options at max.
 *
 * Both layers read the same two attrs the server uses:
 *   data-min-selections, data-max-selections.
 * They're stamped onto whatever element the field renders — <fieldset> for
 * radio/checkbox/button variants, <select multiple> for multiselect.
 */

(function () {
    'use strict';

    const SELECTOR = '[data-min-selections], [data-max-selections]';

    function getLimits(el) {
        return {
            min: parseInt(el.getAttribute('data-min-selections') || '0', 10),
            max: parseInt(el.getAttribute('data-max-selections') || '0', 10),
        };
    }

    function countSelected(el) {
        if (el.tagName === 'SELECT') {
            return Array.from(el.selectedOptions).length;
        }
        return el.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').length;
    }

    function getHintAnchor(el) {
        // joomla.form.field.list-fancy-select wraps <select> in <div class="choices">.
        // Anchor on the wrapper so the hint sits below the visible field, not inside it.
        if (el.tagName === 'SELECT') {
            return el.closest('.choices') || el;
        }
        return el;
    }

    function findHint(el) {
        const anchor = getHintAnchor(el);
        let hint = anchor.nextElementSibling;
        if (!hint || !hint.classList || !hint.classList.contains('alfa-choice__hint')) {
            hint = document.createElement('small');
            hint.className = 'alfa-choice__hint';
            hint.setAttribute('aria-live', 'polite');
            anchor.insertAdjacentElement('afterend', hint);
        }
        return hint;
    }

    function setHint(el, msg, isError) {
        const hint = findHint(el);
        hint.textContent = msg;
        hint.classList.toggle('alfa-choice__hint--error', !!isError);
    }

    function updateState(el) {
        const { min, max } = getLimits(el);
        const count = countSelected(el);

        // Lock further selections at max (checkbox-like elements only).
        if (max > 0 && el.tagName !== 'SELECT') {
            const inputs = el.querySelectorAll('input[type="checkbox"]');
            inputs.forEach(i => {
                if (!i.checked && !i.hasAttribute('data-permadisabled')) {
                    i.disabled = count >= max;
                }
            });
        }

        let msg = '';
        let isError = false;

        if (min > 0 && count < min) {
            msg = `Pick ${min - count} more (min ${min}).`;
            isError = true;
        } else if (max > 0) {
            msg = `${count} / ${max} selected.`;
        }

        if (msg) {
            setHint(el, msg, isError);
        }
    }

    function attach(el) {
        // Snapshot pre-existing disabled inputs so re-enable doesn't unlock them.
        el.querySelectorAll('input[disabled]').forEach(i => i.setAttribute('data-permadisabled', '1'));

        el.addEventListener('change', () => updateState(el));
        updateState(el);
    }

    function init() {
        document.querySelectorAll(SELECTOR).forEach(attach);

        // Plug into Joomla's validator. Handler returns false to block submit.
        // Joomla resolves the handler by the field's `validate=` attribute,
        // which we stamp in Choice::prepareDom() as `choice`.
        if (typeof document.formvalidator !== 'undefined'
            && typeof document.formvalidator.setHandler === 'function') {
            document.formvalidator.setHandler('choice', function (value, element) {
                // `element` is the <fieldset>/<select> with the data attrs.
                if (!element || !element.hasAttribute) {
                    return true;
                }
                const { min, max } = getLimits(element);
                const count = countSelected(element);
                if (min > 0 && count < min) return false;
                if (max > 0 && count > max) return false;
                return true;
            }, true /* skip empty-required check; multi mode handles min itself */);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
