/**
 * @package     mod_alfa_items
 * @copyright   (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license     GNU General Public License version 3 or later; see LICENSE
 * @author      Agamemnon Fakas <info@easylogic.gr>
 *
 * The card settings editor (layouts/field/cardlook.php). A level is
 *
 *     { base: { setting: value... }, bp: { <breakpoint key>: { setting: value... } } }
 *
 * holding only what is set. The device switch picks which of them the controls show
 * and edit: every screen (base) or one breakpoint. An empty control follows what is
 * above it (on a breakpoint the wider screens of this level, else the level above)
 * and says so in its placeholder. A setting that has no effect with the others as
 * they stand is hidden. The markup is all the layout's; this script only fills it,
 * toggles it and writes the level into the hidden input the form submits.
 *
 * Everything is delegated on the document and an editor starts when it is first
 * touched or found, so rows added to a repeatable form need no start-up.
 */
(() => {
    'use strict';

    const ROOT = '[data-look-editor]';
    const editors = new WeakMap();

    const clampInt = (value, min, max) => {
        const n = parseInt(value, 10);

        return Number.isFinite(n) ? Math.max(min, Math.min(max, n)) : null;
    };

    /** Four numbers from anything stored (one number stands for all four) or null. */
    function fourOf(value, bounds) {
        const parts = Array.isArray(value) ? value : String(value).trim().split(/[\s,]+/);
        const numbers = parts.map((part) => clampInt(part, bounds[0], bounds[1]));

        if ((numbers.length !== 1 && numbers.length !== 4) || numbers.some((n) => n === null)) {
            return null;
        }

        return numbers.length === 1 ? [numbers[0], numbers[0], numbers[0], numbers[0]] : numbers;
    }

    /**
     * A level from anything stored: the shape above, a flat set from before breakpoints,
     * or nothing. `sides` names the settings that hold four numbers and their bounds: one
     * stored as a single number, from before they were four, is read as all four.
     */
    function levelOf(raw, sides) {
        let value = raw;

        if (typeof value === 'string') {
            try {
                value = value.trim() === '' ? null : JSON.parse(value);
            } catch (error) {
                value = null;
            }
        }

        const level = { base: {}, bp: {} };

        if (!value || typeof value !== 'object' || Array.isArray(value)) {
            return level;
        }

        const settings = (from) => {
            const out = {};

            Object.keys(from && typeof from === 'object' && !Array.isArray(from) ? from : {}).forEach((key) => {
                const v = from[key];

                if (v === '' || v === null || v === undefined) {
                    return;
                }

                if (sides && sides[key]) {
                    const four = fourOf(v, sides[key]);

                    if (four) {
                        out[key] = four;
                    }

                    return;
                }

                out[key] = v === true ? '1' : (v === false ? '0' : v);
            });

            return out;
        };

        if (!('base' in value) && !('bp' in value)) {
            level.base = settings(value);

            return level;
        }

        level.base = settings(value.base);

        Object.keys(value.bp && typeof value.bp === 'object' && !Array.isArray(value.bp) ? value.bp : {}).forEach((key) => {
            const own = settings(value.bp[key]);

            if (Object.keys(own).length) {
                level.bp[key] = own;
            }
        });

        return level;
    }

    /** The editor's working state, made when the editor is first met. */
    function editorOf(root) {
        let editor = editors.get(root);

        if (!editor) {
            const config = JSON.parse(root.dataset.config);

            editor = { config, level: levelOf(root.querySelector('[data-look-store]').value, config.sides), device: '', breakpoints: config.breakpoints || [] };
            editors.set(root, editor);
            paint(root);
        }

        return editor;
    }

    /** The settings being edited: every screen's, or the current breakpoint's. */
    function current(editor, create) {
        if (editor.device === '') {
            return editor.level.base;
        }

        if (!editor.level.bp[editor.device] && create) {
            editor.level.bp[editor.device] = {};
        }

        return editor.level.bp[editor.device] || {};
    }

    /**
     * What a screen shows of a per-device setting (cards side by side) until it is set
     * there (CardLook::deviceDefault()): the setting's default on screens wider than
     * every breakpoint, two up to a tablet's width, one below 768px.
     */
    function deviceDefault(editor, key) {
        const bp = editor.breakpoints.find((entry) => entry.key === editor.device);
        const base = parseInt(editor.config.defaults[key], 10);

        if (!bp) {
            return base;
        }

        return bp.max >= 768 ? Math.min(2, base) : 1;
    }

    /** What an empty box says: the wider screens' value, a per-device default on the module's level, else the level's hint. */
    function hintOf(editor, key, above, fallback) {
        if (above !== undefined) {
            return String(above);
        }

        return editor.config.level === 'module' && editor.config.perDevice.indexOf(key) !== -1 ? String(deviceDefault(editor, key)) : fallback;
    }

    /** A size as its number and unit: '1.25rem' -> ['1.25', 'rem']. */
    const sizeOf = (value) => {
        const match = /^(\d+(?:\.\d+)?)(px|em|rem|%)$/.exec(String(value === undefined ? '' : value));

        return match ? [match[1], match[2]] : ['', ''];
    };

    /** What this level already says for a setting on the screens wider than the current device. */
    function wider(editor, key) {
        if (editor.device === '' || editor.config.perDevice.indexOf(key) !== -1) {
            return undefined;
        }

        let value = editor.level.base[key];

        for (const bp of editor.breakpoints) {
            if (bp.key === editor.device) {
                break;
            }

            if (editor.level.bp[bp.key] && editor.level.bp[bp.key][key] !== undefined) {
                value = editor.level.bp[bp.key][key];
            }
        }

        return value;
    }

    /** A setting's value as far as this level knows it; the module's own level knows the default too. */
    function effective(editor, key) {
        const own = current(editor, false)[key];

        if (own !== undefined) {
            return own;
        }

        const above = wider(editor, key);

        if (above !== undefined) {
            return above;
        }

        return editor.config.level === 'module' ? editor.config.defaults[key] : undefined;
    }

    /** Whether a setting has an effect: any one of its conditions holds; a value this level cannot know counts as holding. */
    function applies(editor, key) {
        const conditions = editor.config.when[key];

        if (!conditions) {
            return true;
        }

        return conditions.some((condition) => Object.keys(condition).every((other) => {
            const value = effective(editor, other);

            return value === undefined || condition[other].indexOf(String(value)) !== -1;
        }));
    }

    const written = (settings) => Object.keys(settings).filter((key) => settings[key] !== undefined && settings[key] !== '');

    /** Whether a value says anything: on the module's every-screen level its default does not. */
    const counts = (editor, device, key, value) => value !== undefined
        && !(editor.config.level === 'module' && device === '' && String(value) === editor.config.defaults[key]);

    /** Write the level into the input the form submits. */
    function store(root) {
        const editor = editorOf(root);
        const out = { base: editor.level.base, bp: {} };

        Object.keys(editor.level.bp).forEach((key) => {
            if (written(editor.level.bp[key]).length) {
                out.bp[key] = editor.level.bp[key];
            }
        });

        const input = root.querySelector('[data-look-store]');
        const empty = !written(out.base).length && !Object.keys(out.bp).length;

        input.value = empty ? '' : JSON.stringify(out);
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function setLock(group, locked) {
        const lock = group.querySelector('[data-look-lock]');

        lock.setAttribute('aria-pressed', locked ? 'true' : 'false');
        lock.classList.toggle('active', locked);
        lock.title = locked ? lock.dataset.titleOn : lock.dataset.titleOff;
        lock.querySelector('.lock-on').hidden = !locked;
        lock.querySelector('.lock-off').hidden = locked;
    }

    /** A level without the settings of the groups this editor does not show (a block that is no slider has no slider settings). */
    function shownOnly(editor, settings) {
        if (!editor.groups) {
            return settings;
        }

        const out = {};

        editor.groups.forEach((group) => {
            (editor.config.groups[group] || []).forEach((key) => {
                if (settings[key] !== undefined) {
                    out[key] = settings[key];
                }
            });
        });

        return out;
    }

    /** Which rows show, what the groups and devices count, what the note says. */
    function paintState(root) {
        const editor = editorOf(root);
        const labels = editor.config.labels;
        const own = current(editor, false);
        const fixed = editor.device !== '' ? editor.config.baseOnly : [];

        root.querySelectorAll('[data-look-row]').forEach((row) => {
            const key = row.dataset.lookRow;

            row.hidden = !applies(editor, key);

            // The page is built with these: a breakpoint cannot change them.
            row.querySelectorAll('select, input').forEach((control) => {
                if (control.dataset.lookTitle === undefined) {
                    control.dataset.lookTitle = control.title;
                }

                control.disabled = fixed.indexOf(key) !== -1;
                control.title = control.disabled ? labels.fixed : control.dataset.lookTitle;
            });
        });

        root.querySelectorAll('[data-look-group]').forEach((group) => {
            group.hidden = !!editor.groups && editor.groups.indexOf(group.dataset.lookGroup) === -1;

            const count = Array.from(group.querySelectorAll('[data-look-row]')).filter((row) => counts(editor, editor.device, row.dataset.lookRow, own[row.dataset.lookRow])).length;
            const badge = group.querySelector('[data-look-count]');

            badge.textContent = String(count);
            badge.hidden = count === 0;
        });

        root.querySelectorAll('[data-look-device]').forEach((button) => {
            const key = button.dataset.lookDevice;
            const on = key === editor.device;
            const there = key === '' ? editor.level.base : (editor.level.bp[key] || {});
            const has = written(there).some((setting) => counts(editor, key, setting, there[setting]));

            button.classList.toggle('active', on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.querySelector('[data-look-dot]').hidden = !has;
        });

        const total = root.querySelector('[data-look-total]');

        if (total) {
            const count = written(editor.level.base).length + Object.keys(editor.level.bp).reduce((sum, key) => sum + written(editor.level.bp[key]).length, 0);

            total.textContent = String(count);
            total.hidden = count === 0;
        }

        const bp = editor.breakpoints.find((entry) => entry.key === editor.device);

        root.querySelector('[data-look-note]').textContent = bp ? labels.upTo.replace('%d', bp.max) : labels.everyScreen;
    }

    /** Show the current device's values in the controls. */
    function paint(root) {
        const editor = editorOf(root);
        const labels = editor.config.labels;
        const own = current(editor, false);
        const moduleBase = editor.config.level === 'module' && editor.device === '';

        root.querySelectorAll('[data-look-field]').forEach((field) => {
            const key = field.dataset.lookField;
            const above = wider(editor, key);

            if (field.tagName === 'SELECT') {
                const blank = field.options[0];
                const fallback = editor.config.defaults[key];
                const named = above !== undefined && Array.from(field.options).find((option) => option.value === String(above));

                // The module's every-screen choice always has a value: its default shows as chosen.
                blank.hidden = moduleBase && fallback !== '';
                blank.disabled = blank.hidden;
                blank.textContent = editor.device === '' ? labels.inherit : labels.wider + (named ? ' (' + named.textContent + ')' : '');
                field.value = own[key] !== undefined ? String(own[key]) : (blank.hidden ? fallback : '');
            } else {
                field.value = own[key] !== undefined ? String(own[key]) : '';
                field.placeholder = hintOf(editor, key, above, field.dataset.lookHint);
            }
        });

        // A size is a number and its unit; an empty one shows the wider screens' on both.
        root.querySelectorAll('[data-look-size]').forEach((group) => {
            const key = group.dataset.lookSize;
            const [number, unit] = sizeOf(own[key]);
            const [aboveNumber, aboveUnit] = sizeOf(wider(editor, key));

            group.querySelector('[data-look-size-number]').value = number;
            group.querySelector('[data-look-size-number]').placeholder = aboveNumber !== '' ? aboveNumber : group.dataset.lookHint;
            group.querySelector('[data-look-size-unit]').value = unit || aboveUnit || 'px';
        });

        root.querySelectorAll('[data-look-sides]').forEach((group) => {
            const key = group.dataset.lookSides;
            const values = Array.isArray(own[key]) ? own[key] : null;
            const above = wider(editor, key);

            group.querySelectorAll('[data-look-side]').forEach((input) => {
                const index = parseInt(input.dataset.lookSide, 10);

                input.value = values ? values[index] : '';
                input.placeholder = Array.isArray(above) ? String(above[index]) : group.dataset.lookHint;
            });

            setLock(group, !values || values.every((v) => v === values[0]));
        });

        paintState(root);
    }

    /** The breakpoint buttons, again, from the template, after the module's list changed. */
    function paintDevices(root) {
        const editor = editorOf(root);
        const host = root.querySelector('[data-look-devices]');
        const template = root.querySelector('template[data-look-tpl="device"]');

        host.querySelectorAll('[data-look-device]:not([data-look-device=""])').forEach((button) => button.remove());

        editor.breakpoints.forEach((bp) => {
            const button = template.content.firstElementChild.cloneNode(true);
            const icon = bp.max >= 1024 ? 'desktop' : (bp.max >= 600 ? 'tablet' : 'mobile');

            button.dataset.lookDevice = bp.key;
            button.title = editor.config.labels.upTo.replace('%d', bp.max);
            button.querySelector('[data-look-device-label]').textContent = bp.label;
            button.querySelectorAll('[data-look-icon]').forEach((span) => { span.hidden = span.dataset.lookIcon !== icon; });
            host.appendChild(button);
        });

        if (editor.device !== '' && !editor.breakpoints.some((bp) => bp.key === editor.device)) {
            editor.device = '';
        }
    }

    /* events */

    document.addEventListener('click', (event) => {
        const deviceButton = event.target.closest(ROOT + ' [data-look-device]');

        if (deviceButton) {
            const root = deviceButton.closest(ROOT);

            editorOf(root).device = deviceButton.dataset.lookDevice;
            paint(root);

            return;
        }

        const lock = event.target.closest(ROOT + ' [data-look-lock]');

        if (!lock) {
            return;
        }

        const root = lock.closest(ROOT);
        const group = lock.closest('[data-look-sides]');
        const locked = lock.getAttribute('aria-pressed') !== 'true';

        setLock(group, locked);

        // Locking makes the four alike: the first one typed (or none) for all.
        if (locked) {
            const inputs = Array.from(group.querySelectorAll('[data-look-side]'));
            const first = inputs.find((input) => input.value.trim() !== '');

            inputs.forEach((input) => { input.value = first ? first.value : ''; });
            readSides(root, group);
        }
    });

    /** Read a group of four numbers into the level. */
    function readSides(root, group) {
        const editor = editorOf(root);
        const key = group.dataset.lookSides;
        const [min, max] = editor.config.sides[key];
        const typed = Array.from(group.querySelectorAll('[data-look-side]')).map((input) => input.value.trim());

        if (typed.every((v) => v === '')) {
            delete current(editor, true)[key];
        } else {
            current(editor, true)[key] = typed.map((v) => clampInt(v === '' ? 0 : v, min, max) ?? min);
        }

        store(root);
        paintState(root);
    }

    const onEdit = (event) => {
        const size = event.target.closest(ROOT + ' [data-look-size]');

        if (size) {
            const root = size.closest(ROOT);
            const editor = editorOf(root);
            const number = parseFloat(size.querySelector('[data-look-size-number]').value);

            if (Number.isFinite(number) && number > 0) {
                current(editor, true)[size.dataset.lookSize] = String(Math.min(999, Math.round(number * 1000) / 1000)) + size.querySelector('[data-look-size-unit]').value;
            } else {
                delete current(editor, true)[size.dataset.lookSize];
            }

            store(root);
            paintState(root);

            return;
        }

        const side = event.target.closest(ROOT + ' [data-look-side]');

        if (side) {
            const group = side.closest('[data-look-sides]');

            if (group.querySelector('[data-look-lock]').getAttribute('aria-pressed') === 'true') {
                group.querySelectorAll('[data-look-side]').forEach((other) => {
                    if (other !== side) {
                        other.value = side.value;
                    }
                });
            }

            readSides(side.closest(ROOT), group);

            return;
        }

        const field = event.target.closest(ROOT + ' [data-look-field]');

        if (!field) {
            return;
        }

        const root = field.closest(ROOT);
        const editor = editorOf(root);
        const key = field.dataset.lookField;
        const kind = field.dataset.lookKind;
        let value = field.value.trim();

        if (kind === 'number' && value !== '') {
            const [min, max] = editor.config.numbers[key];

            value = clampInt(value, min, max);
        } else if (kind === 'text') {
            // Only what a colour, a gradient or a shadow is made of.
            value = value.replace(/[^a-z0-9#%(),.\s/+-]/gi, '').replace(/\s+/g, ' ').trim().slice(0, editor.config.texts[key]);
        }

        if (value === '' || value === null) {
            delete current(editor, true)[key];
        } else {
            current(editor, true)[key] = value;
        }

        store(root);

        // A choice can switch other settings on or off, and names itself on narrower screens.
        if (event.type === 'change') {
            paintState(root);
        }
    };

    document.addEventListener('input', onEdit);
    document.addEventListener('change', onEdit);

    // The module's breakpoints changed (the layout builder edits the list live): the
    // buttons follow, and a renamed breakpoint keeps what was set for it.
    document.addEventListener('alb:breakpoints', (event) => {
        document.querySelectorAll(ROOT).forEach((root) => {
            const editor = editorOf(root);

            if (!editor.config.live) {
                return;
            }

            const renamed = (event.detail && event.detail.renamed) || {};

            Object.keys(renamed).forEach((from) => {
                if (editor.level.bp[from] && !editor.level.bp[renamed[from]]) {
                    editor.level.bp[renamed[from]] = editor.level.bp[from];
                    delete editor.level.bp[from];
                }
            });

            if (renamed[editor.device]) {
                editor.device = renamed[editor.device];
            }

            editor.breakpoints = ((event.detail && event.detail.list) || []).map((bp) => ({ key: bp.key, label: bp.label, max: bp.max }));
            paintDevices(root);

            if (Object.keys(renamed).length) {
                store(root);
            }

            paint(root);
        });
    });

    function boot(scope) {
        (scope || document).querySelectorAll(ROOT).forEach(editorOf);
    }

    document.addEventListener('subform-row-add', (event) => {
        const row = (event.detail && event.detail.row) || event.target;

        if (row && row.querySelectorAll) {
            boot(row);
        }
    }, true);

    window.Alfa = window.Alfa || {};
    window.Alfa.LookEditor = {
        boot,

        /** Show a level in an editor (the layout builder's, for the block being edited); `groups` names the groups that block has. */
        load(root, level, options) {
            const editor = editorOf(root);

            editor.level = levelOf(level, editor.config.sides);

            if (options && options.breakpoints) {
                editor.breakpoints = options.breakpoints.map((bp) => ({ key: bp.key, label: bp.label, max: bp.max }));
                paintDevices(root);
            }

            editor.device = options && options.device && editor.breakpoints.some((bp) => bp.key === options.device) ? options.device : '';
            editor.groups = options && Array.isArray(options.groups) ? options.groups : null;
            root.querySelector('[data-look-store]').value = '';
            paint(root);
        },

        /** Show only some groups of an editor that is already loaded: a block changed what it is. */
        show(root, groups) {
            editorOf(root).groups = Array.isArray(groups) ? groups : null;
            paintState(root);
        },

        /** The level an editor holds (of the groups it shows) or null while nothing is set. */
        value(root) {
            const editor = editorOf(root);
            const base = shownOnly(editor, editor.level.base);
            const bp = {};

            Object.keys(editor.level.bp).forEach((key) => {
                const there = shownOnly(editor, editor.level.bp[key]);

                if (written(there).length) {
                    bp[key] = there;
                }
            });

            return written(base).length || Object.keys(bp).length ? JSON.parse(JSON.stringify({ base, bp })) : null;
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => boot(document));
    } else {
        boot(document);
    }
})();
