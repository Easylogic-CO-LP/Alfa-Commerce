/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * EmailPositionsField — "email composer" behaviour
 *
 *  - Custom language tabs (toggle .aec-panel by data-lang) — no joomla-tab.
 *    The active tab is remembered (sessionStorage) so it survives the
 *    layout-change reload.
 *  - Each position is a Joomla WYSIWYG editor (the form input); subject is
 *    a plain text input. We don't touch the editors' own toolbars.
 *  - Variables palette: click a chip → insert the {token} into whatever is
 *    focused — the active TinyMCE editor, else the focused subject input,
 *    else copy to clipboard.
 *  - Section eye toggles: per-recipient show/hide for each structural block
 *    (items/payments/shipments), driving one hidden flag input per section.
 *  - Restore-defaults: refill the active language's slots from the field's
 *    emitted defaults.
 *  - Preview / Send-test buttons (in the tab bar) point their modal iframe
 *    at the chosen recipient + the ACTIVE language tab.
 */
(function () {
    'use strict';

    let lastInput = null; // last focused .aec-subject text input

    function toast(msg) {
        let t = document.querySelector('.aec-toast');
        if (!t) { t = document.createElement('div'); t.className = 'aec-toast'; document.body.appendChild(t); }
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.classList.remove('show'); }, 1100);
    }

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).catch(function () {});
        }
        toast(text);
    }

    /* ---------- tabs ---------- */
    // Activate a language tab + its panel (pure visibility toggle — inactive
    // panels stay laid out via CSS absolute/visibility:hidden, so revealing
    // one never re-layouts or focuses a TinyMCE editor). When `remember`,
    // persist the choice so it survives the layout-change reload (a full
    // server round-trip that otherwise re-opens on the FIRST language).
    function activateTab(root, tabs, tab, remember) {
        const lang = tab.getAttribute('data-lang');
        tabs.forEach(function (t) { t.setAttribute('aria-selected', String(t === tab)); });
        root.querySelectorAll('.aec-panel').forEach(function (p) {
            p.hidden = p.getAttribute('data-lang') !== lang;
        });
        if (remember) {
            try { sessionStorage.setItem('alfa.aec.tab:' + root.id, lang); } catch (e) {}
        }
    }

    function initTabs(root) {
        const tabs = [].slice.call(root.querySelectorAll('.aec-tab'));

        // Restore the previously-active language (e.g. after a layout-change
        // reload) before wiring clicks. Keyed per field id so the customer and
        // admin composers remember independently; falls through to the server
        // default (first tab) when nothing's stored or the language is gone.
        let saved = null;
        try { saved = sessionStorage.getItem('alfa.aec.tab:' + root.id); } catch (e) {}
        if (saved) {
            const match = tabs.find(function (t) { return t.getAttribute('data-lang') === saved; });
            if (match) activateTab(root, tabs, match, false);
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function (e) {
                // Button is type=button, but preventDefault is cheap insurance
                // against any form-submit/anchor behaviour.
                e.preventDefault();
                activateTab(root, tabs, tab, true);
            });
        });
    }

    /* ---------- subject inputs ---------- */
    function initSubjects(root) {
        root.querySelectorAll('.aec-subject').forEach(function (inp) {
            inp.addEventListener('focus', function () { lastInput = inp; });
        });
    }

    /* ---------- variables palette ---------- */
    function initVars(root) {
        root.querySelectorAll('.aec-chip').forEach(function (chip) {
            chip.addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep focus/selection
            chip.addEventListener('click', function () {
                const token = chip.getAttribute('data-token');
                if (!token) return;
                insertToken(token);
            });
        });

        const search = root.querySelector('.aec-vars-search');
        if (search) {
            search.addEventListener('input', function () {
                const q = search.value.toLowerCase();
                root.querySelectorAll('.aec-vars-group').forEach(function (g) {
                    let any = false;
                    g.querySelectorAll('.aec-chip').forEach(function (ch) {
                        const hit = ch.textContent.toLowerCase().indexOf(q) !== -1;
                        ch.style.display = hit ? '' : 'none';
                        if (hit) any = true;
                    });
                    g.style.display = any ? '' : 'none';
                });
            });
        }
    }

    function insertToken(token) {
        const ed = window.tinymce && window.tinymce.activeEditor;
        const subjFocused = lastInput && document.activeElement === lastInput;

        if (ed && ed.hasFocus && ed.hasFocus()) { ed.insertContent(token); toast(token); return; }
        if (subjFocused) { insertIntoInput(lastInput, token); toast(token); return; }
        if (ed && !(ed.isHidden && ed.isHidden())) { ed.insertContent(token); toast(token); return; }
        if (lastInput) { insertIntoInput(lastInput, token); toast(token); return; }
        copy(token);
    }

    function insertIntoInput(inp, text) {
        const s = inp.selectionStart != null ? inp.selectionStart : inp.value.length;
        const e = inp.selectionEnd != null ? inp.selectionEnd : s;
        inp.value = inp.value.slice(0, s) + text + inp.value.slice(e);
        const pos = s + text.length;
        inp.focus();
        try { inp.setSelectionRange(pos, pos); } catch (err) {}
    }

    /* ---------- restore defaults ---------- */
    // Refill the ACTIVE language tab from this field's emitted defaults
    // (alfaEmailDefaults[fieldId][langSuffix] = { position: html }). Scoped
    // to one language; confirms before overwriting.
    function initRestore() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.aec-restore-btn');
            if (!btn) return;

            const fieldId = btn.getAttribute('data-field');
            const root = btn.closest('.aec');
            if (!fieldId || !root) return;

            const all = (window.Joomla && Joomla.getOptions) ? (Joomla.getOptions('alfaEmailDefaults') || {}) : {};
            const map = all[fieldId];
            if (!map) return;

            const active = root.querySelector('.aec-tab[aria-selected="true"]');
            const suffix = active ? active.getAttribute('data-lang') : null;
            const defaults = suffix ? map[suffix] : null;
            if (!defaults) return;

            const msg = (window.Joomla && Joomla.Text) ? Joomla.Text._('COM_ALFA_ORDEREMAIL_RESTORE_DEFAULTS_CONFIRM') : '';
            if (msg && !window.confirm(msg)) return;

            Object.keys(defaults).forEach(function (position) {
                const html = defaults[position];
                const elId = fieldId + '_' + suffix + '_' + position;

                if (position === 'subject') {
                    const inp = document.getElementById(elId);
                    if (inp) inp.value = html;
                    return;
                }
                const ed = window.tinymce && window.tinymce.get(elId);
                if (ed) { ed.setContent(html); return; }
                const ta = document.getElementById(elId);
                if (ta) ta.value = html;
            });
        });
    }

    /* ---------- section eye toggles ---------- */
    // Each struct block (items/payments/shipments/…) has an eye button.
    // Clicking it flips that section's single hidden flag input and dims
    // every copy of the block (the block repeats once per language panel),
    // swapping the icon eye <-> eye-slash. One posted value per section.
    function initSectionEyes(root) {
        root.addEventListener('click', function (e) {
            const eye = e.target.closest('.aec-struct-eye');
            if (!eye || !root.contains(eye)) return;
            e.preventDefault();

            const slug = eye.getAttribute('data-slug');
            if (!slug) return;

            const flag = root.querySelector('.aec-section-flag[data-slug="' + slug + '"]');
            const enable = !(flag && flag.value === '1'); // toggle
            if (flag) flag.value = enable ? '1' : '0';

            // All copies of this block across language panels.
            root.querySelectorAll('.aec-struct[data-slug="' + slug + '"]').forEach(function (block) {
                block.classList.toggle('aec-struct--off', !enable);
                const btn = block.querySelector('.aec-struct-eye');
                if (btn) btn.setAttribute('aria-pressed', String(enable));
                const ico = block.querySelector('.aec-struct-eye span');
                if (ico) ico.className = enable ? 'icon-eye' : 'icon-eye-slash';
            });
        });
    }

    /* ---------- preview / send-test buttons ---------- */
    // Point the modal iframe at the chosen recipient + the ACTIVE language
    // tab, so there are no in-modal language/recipient pickers.
    function initActions() {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.aec-preview-btn, .aec-test-btn');
            if (!btn) return;
            const frame = document.getElementById(btn.getAttribute('data-frame'));
            if (!frame) return;
            const root = btn.closest('.aec');
            const active = root && root.querySelector('.aec-tab[aria-selected="true"]');
            const tag = active ? active.getAttribute('data-lang-tag') : '';
            const base = btn.getAttribute('data-base');
            frame.src = base + (tag ? '&lang=' + encodeURIComponent(tag) : '');
        });
    }

    /* ---------- boot ---------- */
    function init() {
        document.querySelectorAll('.aec').forEach(function (root) {
            initTabs(root);
            initSubjects(root);
            initVars(root);
            initSectionEyes(root);
        });
        initActions();
        initRestore();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
