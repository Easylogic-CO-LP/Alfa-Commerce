/**
 * alfa-fields/tel — client-side glue.
 *
 * 1. Boots intlTelInput on every <input data-alfa-tel>.
 * 2. Registers `alfatel` with document.formvalidator — mirrors the server-side
 *    AlfatelRule so invalid numbers are blocked the same way both sides.
 * 3. Shows the matching <small data-err="..."> rendered by tmpl/layouts/tel.php
 *    when validation fails. Translations live in PHP (Text::_()), not JS.
 * 4. On submit, replaces the input value with E.164 only when valid, so the
 *    server receives canonical data.
 *
 * Hint-toggle contract:
 *   The layout pre-renders one <small data-err="key" hidden> per error key.
 *   This script never creates hint elements; it only toggles `hidden`.
 *   Add a new error in the layout's $errors map AND mirror the key here.
 *
 * intl-tel-input v18+ API used:
 *   intlTelInput()                     factory; returns the iti instance
 *   iti.isValidNumber()                libphonenumber accept-or-reject
 *   iti.getValidationError()           code from intlTelInputUtils.validationError
 *   iti.getNumberType()                code from intlTelInputUtils.numberType
 *   iti.getSelectedCountryData()       { iso2, name, dialCode }
 *   iti.getNumber()                    E.164 best-effort
 */

(function () {
    'use strict';

    // Showon value reader — ONE line, no engine internals, any load order.
    // Contract: media/com_alfa/js/site/cart/showon.js (queue facade).
    (window.alfaShowOn = window.alfaShowOn || []).push({
        type: 'tel',
        value: function (name, form) {
            var box = form.querySelector('[data-showon-name="' + name + '"]');
            var i = box ? box.querySelector('input[data-alfa-tel]') : null;
            var iti = i && i.__iti;
            // console.log('tel reader:', name, '| input:', i, '| iti:', iti, '| value:', iti ? iti.getNumber() : (i ? i.value : null));
            return iti ? iti.getNumber() : (i ? i.value : null);
        }
    });

    function wrapFor(input) {
        return input.closest('.alfa-tel') || input.parentNode;
    }

    function hideAllHints(input) {
        wrapFor(input).querySelectorAll('[data-err]').forEach(h => { h.hidden = true; });
    }

    function showHint(input, key) {
        const wrap = wrapFor(input);
        wrap.querySelectorAll('[data-err]').forEach(h => {
            h.hidden = (h.dataset.err !== key);
        });
    }

    function pickErrorKey(iti, element) {
        // require_mobile takes priority — even a valid number is rejected if
        // it isn't mobile.
        if (element.dataset.requireMobile === '1' && iti.isValidNumber()) {
            const NT = (typeof intlTelInputUtils !== 'undefined') ? intlTelInputUtils.numberType : null;
            const type = iti.getNumberType();
            if (NT && type !== NT.MOBILE && type !== NT.FIXED_LINE_OR_MOBILE) {
                return 'not_mobile';
            }
        }

        // allowed_regions next.
        const allowed = (element.dataset.allowedRegions || '')
            .split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
        if (allowed.length && iti.isValidNumber()) {
            const country = iti.getSelectedCountryData();
            if (country && country.iso2 && !allowed.includes(country.iso2)) {
                return 'bad_region';
            }
        }

        // Generic libphonenumber error.
        const VE = (typeof intlTelInputUtils !== 'undefined') ? intlTelInputUtils.validationError : null;
        const code = iti.getValidationError();
        if (VE) {
            if (code === VE.INVALID_COUNTRY_CODE)   return 'invalid_country';
            if (code === VE.TOO_SHORT)              return 'too_short';
            if (code === VE.TOO_LONG)               return 'too_long';
            if (code === VE.IS_POSSIBLE_LOCAL_ONLY) return 'local_only';
            if (code === VE.INVALID_LENGTH)         return 'invalid_length';
        }
        return 'invalid';
    }

    function boot(input) {
        if (input.__iti) return; // idempotent

        const allowed = (input.dataset.allowedRegions || '')
            .split(',').map(s => s.trim().toLowerCase()).filter(Boolean);

        // intl-tel-input keys countries by LOWERCASE iso2. Uppercase 'GR'
        // silently fails and falls back to 'us'.
        const initialCountry = (input.dataset.defaultRegion || 'GR').toLowerCase();

        const opts = {
            initialCountry: initialCountry,
            separateDialCode: true,
            strictMode: true,
        };
        if (allowed.length) {
            opts.onlyCountries = allowed;
        }

        const iti = intlTelInput(input, opts);
        input.__iti = iti;
        input.dispatchEvent(new CustomEvent('alfa:field-change', { bubbles: true }));
        var fire = function () { input.dispatchEvent(new CustomEvent('alfa:field-change', { bubbles: true })); };
        input.addEventListener('input', fire);
        input.addEventListener('countrychange', fire);

        // Live-clear feedback as the user edits.
        input.addEventListener('input', () => {
            input.classList.remove('invalid');
            hideAllHints(input);
        });
        input.addEventListener('countrychange', () => hideAllHints(input));

        // NB: we do NOT rewrite input.value to E.164 on submit. AlfatelRule.test()
        // on the server already parses with default_region and writes the canonical
        // E.164 back to the input registry — so the JS substitution would be both
        // redundant AND visually ugly when the form stays on page (validation
        // failure elsewhere, server redirect-back). Server is the single
        // canonicalisation point. Keep the visible input showing what the user typed.
    }

    function registerValidator() {
        if (typeof document.formvalidator === 'undefined'
            || typeof document.formvalidator.setHandler !== 'function') {
            // Fix: add "form.validate" to dependencies of this script in joomla.asset.json.
            console.warn('[alfa-fields/tel] document.formvalidator unavailable when tel.js ran. '
                + 'Declare "form.validate" as a dependency in media/plg_alfa-fields_tel/joomla.asset.json.');
            return;
        }

        // Handler runs for any field with validate="alfatel".
        // Third arg true → Joomla also calls us on empty values so we can
        // explicitly defer to the `required` attribute.
        document.formvalidator.setHandler('alfatel', function (value, element) {
            const trimmed = (value || '').trim();

            if (trimmed === '') {
                hideAllHints(element);
                return !element.hasAttribute('required');
            }

            const iti = element.__iti;
            if (!iti) {
                // Not initialised yet — defer to server-side rule.
                return true;
            }

            if (!iti.isValidNumber()) {
                showHint(element, pickErrorKey(iti, element));
                return false;
            }

            if (element.dataset.requireMobile === '1') {
                const NT   = (typeof intlTelInputUtils !== 'undefined') ? intlTelInputUtils.numberType : null;
                const type = iti.getNumberType();
                if (NT && type !== NT.MOBILE && type !== NT.FIXED_LINE_OR_MOBILE) {
                    showHint(element, 'not_mobile');
                    return false;
                }
            }

            const allowed = (element.dataset.allowedRegions || '')
                .split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
            if (allowed.length) {
                const country = iti.getSelectedCountryData();
                if (country && country.iso2 && !allowed.includes(country.iso2)) {
                    showHint(element, 'bad_region');
                    return false;
                }
            }

            hideAllHints(element);
            return true;
        }, true);
    }

    function init() {
        document.querySelectorAll('input[data-alfa-tel]').forEach(boot);
        // registerValidator();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
