/**
 * @package    Alfa Commerce
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Progressive enhancement for any storefront search input that DECLARES itself.
 *
 * BINDS TO A DECLARATION, NEVER TO MARKUP: the contract is `data-alfa-search` on the
 * input, not `#filter_search`. Selecting by id would tie this file to the markup Joomla's
 * searchtools happens to emit today, so a template override, a second search box, a
 * mobile-specific layout or a moved module would each silently break enhancement. Any
 * input that opts in gets enhanced, wherever it lives and however many there are.
 *
 * CREATES NO MARKUP AND SETS NO CLASSES, EVER. Every element the dropdown shows is
 * authored by the `search/enhance_templates` layout as inert <template> fragments this
 * script clones and fills. The contract with the layout is ATTRIBUTES only:
 * `data-alfa-search-template` names a fragment, `data-alfa-search-slot` names a part to
 * fill, and state is expressed as data-attributes (`data-active`, `data-section`,
 * `data-unrouted`). The active option is also told to assistive technology: it gets an
 * `id` when it has none and `aria-selected`, and the input names it in
 * `aria-activedescendant`. Classes belong to the layout; overriding the layout restyles
 * or restructures the whole dropdown with no change here.
 *
 * CONSUMES THE CAPABILITY, NOT A MODULE: results come from the component's own search
 * boundary (SearchController), so this works with no search module published at all, and
 * automatically gets better when the Enterprise engine is active: ranked results, typo
 * correction and completions arrive through the same payload with no change here.
 *
 * DEGRADES TO NOTHING: with JS off, a failed endpoint, or an override that removed the
 * load-bearing templates (`panel`, `row`), the input remains an ordinary filter field and
 * the form's normal submit still searches. Enhancement never becomes a dependency.
 */
(function () {
    'use strict';

    var SELECTOR = '[data-alfa-search]';
    var DEBOUNCE_MS = 220;

    function options() {
        var opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions('com_alfa.search') : null;

        return opts || {};
    }

    /**
     * A named fragment from the enhance_templates layout, or null when the page (or an
     * override) does not carry it. Null is the fail-closed signal: the part simply does
     * not render.
     */
    function fragment(name) {
        var tpl = document.querySelector('template[data-alfa-search-template="' + name + '"]');

        return (tpl && tpl.content && tpl.content.firstElementChild) ? tpl.content.firstElementChild : null;
    }

    function cloneFragment(name) {
        var node = fragment(name);

        return node ? node.cloneNode(true) : null;
    }

    /**
     * The element playing a named part inside a cloned fragment; the root itself may
     * carry the slot (the suggestion chip does).
     */
    function slot(root, name) {
        var selector = '[data-alfa-search-slot="' + name + '"]';

        if (root.matches && root.matches(selector)) {
            return root;
        }

        return root.querySelector ? root.querySelector(selector) : null;
    }

    /**
     * One enhanced input. Holds its own panel, its own in-flight request and its own
     * keyboard cursor, so several search boxes on a page never interfere.
     */
    // Names the panel each enhanced input controls; two inputs never share one.
    var panelSeq = 0;

    function Enhancer(input) {
        this.input = input;
        this.config = options();
        this.minChars = parseInt(this.config.minChars, 10) || 2;
        this.panel = null;
        this.items = [];
        this.cursor = -1;
        this.timer = null;
        this.controller = null;
        // The last query whose response we accepted. Responses can arrive out of order,
        // so a stale one must be discarded by comparing what it was FOR, not by assuming
        // arrival order matches request order.
        this.rendered = null;
        this.bind();
    }

    Enhancer.prototype.bind = function () {
        var self = this;

        this.input.setAttribute('autocomplete', 'off');

        // `aria-expanded` is not a supported attribute of a plain textbox: the
        // input has to say it is a combobox before it can claim to be expanded,
        // or assistive technology (and any auditor) reads the pairing as invalid.
        this.input.setAttribute('role', 'combobox');
        this.input.setAttribute('aria-autocomplete', 'list');
        this.input.setAttribute('aria-haspopup', 'listbox');
        this.input.setAttribute('aria-expanded', 'false');

        this.input.addEventListener('input', function () {
            self.schedule();
        });

        this.input.addEventListener('keydown', function (event) {
            self.onKeydown(event);
        });

        // Close on outside click, but not on a click INSIDE the panel; that would cancel
        // the selection before its own handler ran.
        document.addEventListener('click', function (event) {
            if (event.target !== self.input && (!self.panel || !self.panel.contains(event.target))) {
                self.close();
            }
        });
    };

    Enhancer.prototype.schedule = function () {
        var self = this;

        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(function () {
            self.fetch();
        }, DEBOUNCE_MS);
    };

    Enhancer.prototype.fetch = function () {
        var value = this.input.value.trim();

        if (value.length < this.minChars) {
            this.close();

            return;
        }

        if (!this.config.url) {
            return;
        }

        // Abandon the previous request rather than letting it finish and race.
        if (this.controller) {
            this.controller.abort();
        }

        this.controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;

        var self = this;
        var url = this.config.url + (this.config.url.indexOf('?') === -1 ? '?' : '&')
            + 'q=' + encodeURIComponent(value);

        fetch(url, {
            headers: { Accept: 'application/json' },
            signal: this.controller ? this.controller.signal : undefined
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                var data = json && json.data;

                if (!data) {
                    self.close();

                    return;
                }

                // Discard a response that no longer matches what is in the box.
                if (data.query && data.query.toLowerCase() !== self.input.value.trim().toLowerCase()) {
                    return;
                }

                self.render(data);
            })
            .catch(function () {
                // A failed lookup must leave a working plain input, never an error state.
                self.close();
            });
    };

    Enhancer.prototype.getPanel = function () {
        if (this.panel) {
            return this.panel;
        }

        // The panel is layout-authored markup, cloned: role, label, classes and the
        // initial `hidden` all come from the template.
        this.panel = cloneFragment('panel');

        if (!this.panel) {
            return null;
        }

        // Positioned relative to the input's own container so the panel follows the input
        // wherever the template puts it.
        var host = this.input.parentNode;

        if (host && window.getComputedStyle(host).position === 'static') {
            host.style.position = 'relative';
        }

        if (host) {
            host.appendChild(this.panel);
        }

        return this.panel;
    };

    Enhancer.prototype.render = function (data) {
        var panel = this.getPanel();
        var sections = data.sections || {};
        var suggestions = data.suggestions || [];
        var names = Object.keys(sections);

        if (!panel) {
            return;
        }

        // A redirected search is an instruction, not a result list (contract 2: the
        // `redirect` key is always present, null = none). This is a TYPEAHEAD surface:
        // navigating mid-keystroke would yank the shopper away while typing, so the
        // panel closes and renders nothing; the merchant's redirect fires when the
        // shopper actually submits. What must never happen is treating a redirected
        // search as "no results".
        if (data.redirect) {
            this.close();

            return;
        }

        panel.textContent = '';
        this.items = [];
        this.cursor = -1;
        // The old options are gone: a dangling idref would announce one that no
        // longer exists.
        this.input.removeAttribute('aria-activedescendant');
        this.rendered = data.query || '';

        if (!names.length && !suggestions.length) {
            var empty = cloneFragment('empty');

            if (empty) {
                panel.appendChild(empty);
                this.open();
            } else {
                this.close();
            }

            return;
        }

        if (suggestions.length) {
            var block = this.suggestionBlock(suggestions);

            if (block) {
                panel.appendChild(block);
            }
        }

        for (var i = 0; i < names.length; i++) {
            var section = this.sectionBlock(names[i], sections[names[i]]);

            if (section) {
                panel.appendChild(section);
            }
        }

        this.open();
    };

    Enhancer.prototype.suggestionBlock = function (suggestions) {
        var wrap = cloneFragment('suggestions');
        var self = this;

        if (!wrap) {
            return null;
        }

        for (var i = 0; i < suggestions.length; i++) {
            (function (suggestion) {
                // Contract 2: a suggestion is `{text, type}`. The text is what the shopper
                // searches with; the type (when the catalogue declared one) is not rendered
                // by this widget; labels are the premium presentation's job.
                var spelling = (suggestion && typeof suggestion === 'object')
                    ? suggestion.text
                    : suggestion;

                var chip = spelling ? cloneFragment('suggestion') : null;
                var textSlot = chip ? slot(chip, 'text') : null;

                if (!chip || !textSlot) {
                    return;
                }

                textSlot.textContent = spelling;
                chip.addEventListener('click', function () {
                    // A completion is an offer to search for something else, so accepting
                    // it re-runs the search rather than navigating anywhere.
                    self.input.value = spelling;
                    self.close();
                    self.submit();
                });
                wrap.appendChild(chip);
                self.items.push(chip);
            }(suggestions[i]));
        }

        return wrap;
    };

    Enhancer.prototype.sectionBlock = function (name, rows) {
        // One template per section, heading and identity baked in; an unknown section
        // name (a future contract addition) has no template and is skipped, fail-closed.
        var wrap = cloneFragment('section-' + name);

        if (!wrap) {
            return null;
        }

        var host = slot(wrap, 'rows') || wrap;

        for (var i = 0; i < rows.length; i++) {
            var row = this.row(rows[i], name);

            if (row) {
                host.appendChild(row);
            }
        }

        return wrap;
    };

    Enhancer.prototype.row = function (row, section) {
        var node = cloneFragment('row');

        if (!node) {
            return null;
        }

        // Identity travels as DATA, never as a class the layout does not own.
        node.setAttribute('data-section', section);

        var link = slot(node, 'link') || node;

        if (row.url) {
            link.setAttribute('href', row.url);
        } else {
            // Not navigable (manufacturers carry no route on the wire); the template's
            // anchor stays, unrouted and declared as such for the stylesheet.
            link.removeAttribute('href');
            node.setAttribute('data-unrouted', '');
        }

        var title = slot(node, 'title');

        if (title) {
            title.textContent = row.name;
        }

        var desc = slot(node, 'description');

        if (desc) {
            if (row.description) {
                // Already stripped of markup server-side; textContent keeps it that way.
                desc.textContent = row.description;
            } else if (desc.parentNode) {
                desc.parentNode.removeChild(desc);
            }
        }

        this.items.push(node);

        return node;
    };

    Enhancer.prototype.open = function () {
        var panel = this.getPanel();

        if (!panel) {
            return;
        }

        panel.hidden = false;

        // Name the list this combobox drives, now that it is in the document.
        if (!panel.id) {
            panel.id = 'alfa-search-panel-' + (++panelSeq);
        }

        this.input.setAttribute('aria-controls', panel.id);
        this.input.setAttribute('aria-expanded', 'true');
    };

    Enhancer.prototype.close = function () {
        if (this.panel) {
            this.panel.hidden = true;
        }

        this.cursor = -1;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
    };

    Enhancer.prototype.submit = function () {
        var form = this.input.form || this.input.closest('form');

        if (form) {
            form.submit();
        }
    };

    Enhancer.prototype.onKeydown = function (event) {
        if (event.key === 'Escape') {
            this.close();

            return;
        }

        if (!this.panel || this.panel.hidden || !this.items.length) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.move(event.key === 'ArrowDown' ? 1 : -1);

            return;
        }

        if (event.key === 'Enter' && this.cursor > -1) {
            event.preventDefault();
            this.items[this.cursor].click();
        }
    };

    Enhancer.prototype.move = function (delta) {
        if (this.cursor > -1 && this.items[this.cursor]) {
            this.items[this.cursor].removeAttribute('data-active');
            this.items[this.cursor].removeAttribute('aria-selected');
        }

        this.cursor += delta;

        if (this.cursor < 0) {
            this.cursor = this.items.length - 1;
        } else if (this.cursor >= this.items.length) {
            this.cursor = 0;
        }

        var active = this.items[this.cursor];

        // Keyboard highlight is STATE, so it travels as a data-attribute the stylesheet
        // targets, never a class this script would have to own.
        active.setAttribute('data-active', '');

        // Focus stays in the input, so the input has to NAME the option: a combobox
        // announces its active descendant, and only an element with an id can be one.
        if (!active.id) {
            active.id = this.panel.id + '-opt-' + this.cursor;
        }

        active.setAttribute('aria-selected', 'true');
        this.input.setAttribute('aria-activedescendant', active.id);

        if (active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest' });
        }
    };

    function init() {
        // FAIL CLOSED WITHOUT THE LAYOUT: no panel or row template on the page (not
        // rendered, or removed by an override) means no enhancement at all: the plain
        // input keeps working and nothing half-renders.
        if (!fragment('panel') || !fragment('row')) {
            return;
        }

        var inputs = document.querySelectorAll(SELECTOR);

        for (var i = 0; i < inputs.length; i++) {
            if (!inputs[i].dataset.alfaSearchBound) {
                inputs[i].dataset.alfaSearchBound = '1';
                new Enhancer(inputs[i]);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
