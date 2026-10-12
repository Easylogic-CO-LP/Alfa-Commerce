/**
 * @package    Alfa Commerce
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Menu landing pages: live XOR between the Category identity (request) and
 * the categories preset, plus the single-category hint. The server render and
 * ItemsModel::populateState() are authoritative; this only mirrors the rule
 * live so the merchant is never surprised on save.
 */
document.addEventListener('DOMContentLoaded', () => {
    // Name-based selectors: the POST names are OUR contract (field + control we
    // mount), immune to id-mangling conventions ("][" -> "__") changing.
    const identity = document.querySelector('select[name="jform[request][category_id]"]');
    const presetCats = document.querySelector('select[name="jform[params][presets][filter][category][]"]');
    const note = document.getElementById('alfa-preset-cats-note');
    const hint = document.getElementById('alfa-preset-single-hint');

    if (!identity || !presetCats) {
        return;
    }

    const catsWrap = document.getElementById('alfa-preset-cats-wrap');

    // Spec: choosing an identity EMPTIES the categories preset. Native select
    // only; the WHOLE field container hides, so whatever UI enhancement wraps
    // the select never needs syncing (and never gets posted or seen stale).
    const clearPresetCats = () => {
        Array.from(presetCats.options).forEach((option) => {
            option.selected = false;
        });
    };

    const refresh = () => {
        const identitySet = identity.value !== '' && identity.value !== '0';
        const presetCount = Array.from(presetCats.selectedOptions || []).filter((o) => o.value !== '').length;

        // Identity owns the category axis: preset categories empty + disappear,
        // the note takes the field's place and says why.
        if (identitySet && presetCount > 0) {
            clearPresetCats();
        }

        presetCats.disabled = identitySet;

        if (catsWrap) {
            catsWrap.hidden = identitySet;
        }

        if (note) {
            note.hidden = !identitySet;
        }

        // The reverse direction: while categories are preset, identity is parked.
        identity.disabled = !identitySet && presetCount > 0;

        if (hint) {
            hint.hidden = !(presetCount === 1 && !identitySet);
        }
    };

    identity.addEventListener('change', refresh);
    presetCats.addEventListener('change', refresh);
    refresh();
});
