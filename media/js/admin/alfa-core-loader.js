/**
 * Alfa Admin: <alfa-core-loader>
 * Alfa's own loading indicator: the Alfa mark drawn by a colour-shifting
 * gradient arc ("Gradient Sweep", Neon Pulse ramp). Replaces Joomla's
 * <joomla-core-loader> throughout com_alfa; same element contract, so it is a
 * drop-in by name: `inline`, `size`, `color`.
 *
 * Loading it
 * From a view/layout:
 *     $wa->useScript('com_alfa.core-loader');
 *
 * From another script: add it to that asset's dependencies in
 * media/com_alfa/joomla.asset.json, and it is pulled in automatically:
 *     "dependencies": ["core", "com_alfa.core-loader"]
 *
 * USING IT: window.Alfa.loader
 * FULLSCREEN, you remove it yourself (an in-page operation, no navigation):
 *
 *     Alfa.loader.show();                        // cover the page
 *     Alfa.loader.hide();                        // take it away
 *
 * FULLSCREEN, before anything that NAVIGATES (form submit, redirect):
 *
 *     Alfa.loader.showThen(() => form.submit());
 *
 *   Use this, never `show()` then `submit()`. A DOM change and a synchronous
 *   navigation in the same task give the browser no frame to paint in, so the
 *   loader sits in the DOM unseen until the new page replaces it. showThen
 *   waits for a real paint first. You do not hide it; the new page does.
 *
 * INSIDE ONE CONTROL, for an AJAX action:
 *
 *     Alfa.loader.showIn(btn);                   // icon -> loader, label kept
 *     ... await the request ...
 *     Alfa.loader.hideIn(btn);                   // restores it exactly
 *
 *   Put hideIn() in a finally(), so a failed request cannot leave the control
 *   stuck. showIn() also disables the control, which removes the double-click
 *   double-post for free, and remembers whether it was ALREADY disabled so
 *   restoring never enables something meant to stay locked.
 *
 *   Which node does the loader stand in for? In order: a CSS selector you pass
 *   as showIn()'s second argument; else the first DIRECT-CHILD <svg> or
 *   `.icon` / `.icon-*` node; else the whole content with the width pinned.
 *   The default covers every button in Alfa today; pass a selector only when
 *   the markup is unusual:
 *
 *     Alfa.loader.showIn(btn, 'span.glyph');
 *
 * The element can also be placed directly, e.g. as an overlay inside a modal:
 *
 *     <alfa-core-loader inline size="140" hidden></alfa-core-loader>
 *
 * Notes that cost real debugging
 * - `size` is a WIDTH in px. The mark's viewBox is 607x506, not square; the
 *   height is derived. Forcing a square distorts the logo.
 * - The faint track outline behind the arc is a FIXED palette colour, never
 *   `currentColor`: this lands on white modal bodies and dark admin chrome
 *   alike, and an inherited light-on-light colour disappears entirely.
 * - Shadow DOM scopes the gradient/filter ids, so any number of loaders can
 *   share a page without colliding on url(#...).
 * - `prefers-reduced-motion: reduce` builds a static mark with no <animate> at
 *   all; a CSS media query cannot reach SMIL, so it is decided at build time.
 * - Editing this file is invisible to browsers until Joomla's media version
 *   changes; hard-reload (Cmd+Shift+R) while developing.
 *
 * @package  Alfa Commerce
 * @since  1.10.0
 */
(function () {
    'use strict';

    if (window.customElements && window.customElements.get('alfa-core-loader')) {
        return;
    }

    /**
     * The Alfa mark as one continuous path, and the viewBox it is drawn in.
     * `PATH_LENGTH` is the dash geometry's reference length: an approximation of
     * the path's real length, which is all stroke-dasharray needs.
     */
    var LOGO = 'M399.099 298.081c-17.492 65.238-74.455 108.753-145.743 110.293-88.66 1.004-159.807-76.017-154.92-166.634 4.865-80.87 69.18-145.184 150.054-145.184 72.343-.066 135.223 49.654 151.84 120.062l105.058 274.565h100.716L500.871 204.722C449.23 65.377 371.274 3.002 246.541.054 114.023-2.838 0 112.143 0 245.646c0 145.191 114.022 263.108 256.28 260.17 77.563-1.642 140.068-35.558 183.994-95.344z';
    var VIEW_BOX = '0 0 607 506';
    var PATH_LENGTH = 2500;
    var ASPECT = 506 / 607;

    /**
     * "Neon Pulse" ramp for the sweep: cyan -> violet -> magenta. Constant in both
     * colour schemes: the backdrop adapts, the mark does not.
     *
     * `track` is the faint full outline behind the arc, and it is deliberately the
     * VIOLET rather than the cyan: the element lands on white modal bodies and dark
     * admin chrome alike, and cyan at low opacity vanishes against white. Violet
     * reads on both.
     */
    var BRAND = { c1: '#00f2ff', c2: '#7b2dff', c3: '#ff2d7b', track: '#7b2dff' };

    /**
     * Backdrop colours, matching <joomla-core-loader> so a fullscreen Alfa loader
     * sits on the same wash of page the admin already expects.
     */
    var LIGHT_BACKDROP = 'rgba(255, 255, 255, 0.8)';
    var DARK_BACKDROP = 'rgba(0, 0, 0, 0.8)';

    var darkModeQuery = window.matchMedia('(prefers-color-scheme: dark)');
    var reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');

    /**
     * Whether the admin has FORCED a colour scheme (Joomla stamps the dataset when
     * the user picked one); if not, the OS preference decides.
     *
     * @return {boolean}
     */
    function schemeNotForced() {
        return !('colorSchemeOs' in document.documentElement.dataset);
    }

    /**
     * The backdrop colour for the current scheme.
     *
     * @return {string}
     */
    function backdropColor() {
        if (schemeNotForced() && 'colorScheme' in document.documentElement.dataset) {
            return document.documentElement.dataset.colorScheme === 'dark' ? DARK_BACKDROP : LIGHT_BACKDROP;
        }

        return darkModeQuery.matches ? DARK_BACKDROP : LIGHT_BACKDROP;
    }

    /**
     * The animated mark: one gradient-stroked arc chasing the path while the three
     * gradient stops cycle through the brand ramp, over a faint full outline that
     * keeps the logo readable at every point of the sweep.
     *
     * The outline uses a fixed palette colour rather than `currentColor`: an
     * inherited text colour is not guaranteed to contrast with the surface right
     * behind it, and a light-on-light track disappears entirely.
     *
     * @return {string} SVG markup.
     */
    function animatedMark() {
        return '<defs>'
            + '<linearGradient id="sweep" x1="0%" y1="0%" x2="100%" y2="0%">'
            + '<stop offset="0%" stop-color="' + BRAND.c1 + '">'
            + '<animate attributeName="stop-color" values="' + BRAND.c1 + ';' + BRAND.c2 + ';' + BRAND.c3 + ';' + BRAND.c1 + '" dur="3s" repeatCount="indefinite"/>'
            + '</stop>'
            + '<stop offset="50%" stop-color="' + BRAND.c2 + '">'
            + '<animate attributeName="stop-color" values="' + BRAND.c2 + ';' + BRAND.c3 + ';' + BRAND.c1 + ';' + BRAND.c2 + '" dur="3s" repeatCount="indefinite"/>'
            + '</stop>'
            + '<stop offset="100%" stop-color="' + BRAND.c3 + '">'
            + '<animate attributeName="stop-color" values="' + BRAND.c3 + ';' + BRAND.c1 + ';' + BRAND.c2 + ';' + BRAND.c3 + '" dur="3s" repeatCount="indefinite"/>'
            + '</stop>'
            + '</linearGradient>'
            + '<filter id="bloom" x="-50%" y="-50%" width="200%" height="200%">'
            + '<feGaussianBlur stdDeviation="4" result="blurred"/>'
            + '<feMerge><feMergeNode in="blurred"/><feMergeNode in="SourceGraphic"/></feMerge>'
            + '</filter>'
            + '</defs>'
            + '<path fill="none" stroke="' + BRAND.track + '" stroke-width="2" opacity="0.24" d="' + LOGO + '"/>'
            + '<path fill="none" stroke="url(#sweep)" stroke-width="5" stroke-linecap="round" filter="url(#bloom)"'
            + ' stroke-dasharray="' + (PATH_LENGTH * 0.35) + ' ' + (PATH_LENGTH * 0.65) + '" d="' + LOGO + '">'
            + '<animate attributeName="stroke-dashoffset" values="' + PATH_LENGTH + ';0" dur="2.2s" repeatCount="indefinite"/>'
            + '</path>';
    }

    /**
     * The same mark with no motion at all, for `prefers-reduced-motion: reduce`.
     * Still unmistakably the brand, just still.
     *
     * @return {string} SVG markup.
     */
    function staticMark() {
        return '<defs>'
            + '<linearGradient id="sweep" x1="0%" y1="0%" x2="100%" y2="100%">'
            + '<stop offset="0%" stop-color="' + BRAND.c1 + '"/>'
            + '<stop offset="50%" stop-color="' + BRAND.c2 + '"/>'
            + '<stop offset="100%" stop-color="' + BRAND.c3 + '"/>'
            + '</linearGradient>'
            + '</defs>'
            + '<path fill="none" stroke="url(#sweep)" stroke-width="5" stroke-linecap="round" d="' + LOGO + '"/>';
    }

    /**
     * The Alfa loading indicator.
     *
     * @since 1.10.0
     */
    class AlfaCoreLoader extends HTMLElement {
        /**
         * Inline = no page backdrop; the element flows where it is placed.
         *
         * @return {boolean}
         */
        get inline() {
            return this.hasAttribute('inline');
        }

        set inline(value) {
            if (value !== null && value !== false) {
                this.setAttribute('inline', '');
            } else {
                this.removeAttribute('inline');
            }
        }

        /**
         * Mark WIDTH in px. Height follows the logo's aspect; it is not a square,
         * and stretching it to one would distort the brand.
         *
         * @return {string}
         */
        get size() {
            return this.getAttribute('size') || (this.inline ? '20' : '260');
        }

        set size(value) {
            this.setAttribute('size', value);
        }

        /**
         * Backdrop colour override (fullscreen only).
         *
         * @return {string|null}
         */
        get color() {
            return this.getAttribute('color');
        }

        set color(value) {
            this.setAttribute('color', value);
        }

        static get observedAttributes() {
            return ['color', 'size', 'inline'];
        }

        constructor() {
            super();

            this.attachShadow({ mode: 'open' });
            this.systemQuery = this.systemQuery.bind(this);

            var width = this.size;
            var height = Math.round(parseFloat(width) * ASPECT);
            var template = document.createElement('template');

            template.innerHTML = ''
                + '<style>'
                + ':host{z-index:10000;display:flex;align-items:center;justify-content:center;overflow:visible;line-height:0}'
                + ':host([hidden]){display:none}'
                + ':host(.alfa-loader-fullscreen){position:fixed;top:0;left:0;width:100%;height:100%}'
                + '</style>'
                + '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' + VIEW_BOX + '" width="' + width + '" height="' + height + '"'
                + ' overflow="visible" aria-hidden="true" focusable="false">'
                + (reducedMotionQuery.matches ? staticMark() : animatedMark())
                + '</svg>';

            this.shadowRoot.appendChild(template.content.cloneNode(true));
        }

        connectedCallback() {
            if (!this.hasAttribute('role')) {
                this.setAttribute('role', 'status');
            }

            if (!this.hasAttribute('aria-label')) {
                this.setAttribute('aria-label', AlfaCoreLoader.label());
            }

            if (this.inline) {
                this.classList.remove('alfa-loader-fullscreen');
            } else {
                this.classList.add('alfa-loader-fullscreen');
                this.style.backgroundColor = this.color ? this.color : backdropColor();
                darkModeQuery.addEventListener('change', this.systemQuery);
            }
        }

        disconnectedCallback() {
            darkModeQuery.removeEventListener('change', this.systemQuery);
        }

        attributeChangedCallback(attr, oldValue, newValue) {
            if (newValue === oldValue) {
                return;
            }

            switch (attr) {
                case 'color':
                    if (newValue && !this.inline) {
                        this.style.backgroundColor = newValue;
                    }

                    break;

                case 'size':
                    if (newValue) {
                        var svg = this.shadowRoot.querySelector('svg');

                        svg.setAttribute('width', newValue);
                        svg.setAttribute('height', String(Math.round(parseFloat(newValue) * ASPECT)));
                    }

                    break;

                case 'inline':
                    if (this.inline) {
                        this.classList.remove('alfa-loader-fullscreen');
                        this.style.backgroundColor = '';
                    } else {
                        this.classList.add('alfa-loader-fullscreen');
                        this.style.backgroundColor = this.color ? this.color : backdropColor();
                    }

                    break;
            }
        }

        /**
         * Follow the OS scheme while the backdrop is showing, unless the page
         * forced a scheme, or the caller pinned a colour.
         *
         * @param {MediaQueryListEvent} event The scheme change.
         */
        systemQuery(event) {
            if (!schemeNotForced() || this.color || this.inline) {
                return;
            }

            this.style.backgroundColor = event.matches === true ? DARK_BACKDROP : LIGHT_BACKDROP;
        }

        /**
         * Translated accessible name, falling back to English when the string was
         * never pushed to Text.script().
         *
         * @return {string}
         */
        static label() {
            if (window.Joomla && Joomla.Text && Joomla.Text._) {
                return Joomla.Text._('COM_ALFA_LOADING', 'Loading...');
            }

            return 'Loading...';
        }
    }

    window.customElements.define('alfa-core-loader', AlfaCoreLoader);

    /**
     * Once the loader appears it stays for at least this long, however fast the
     * action turns out to be. That single rule is the whole anti-flicker story:
     * the loader ALWAYS shows (the merchant asked for proof that something is
     * happening) and it can never blink out in a frame or two.
     *
     * An earlier version also delayed the first paint, so quick actions showed
     * nothing at all, which is precisely the "I don't see the loader" complaint
     * it was meant to prevent. A minimum duration does the job on its own.
     */
    var MIN_VISIBLE = 420;

    /**
     * Per-control busy bookkeeping (pending timer + the moment the loader appeared).
     * A WeakMap, so nothing leaks into markup and a swapped-out node is collected.
     */
    var busyState = new WeakMap();

    /**
     * Put the loader into a control, remembering what it displaced.
     *
     * @param {Element} el     The control.
     * @param {string}  [slot] CSS selector for the node to stand in for.
     */
    function paint(el, slot) {
        if (el.classList.contains('alfa-is-busy')) {
            return;
        }

        el.dataset.alfaBusyMarkup = el.innerHTML;
        el.classList.add('alfa-is-busy');

        var loader = document.createElement('alfa-core-loader');

        // 1.4em tracks the control's own type size, so the mark matches the icon it
        // stands in for whatever the button's scale.
        loader.setAttribute('inline', '');
        loader.setAttribute('size', '24');
        loader.style.width = '1.4em';
        loader.style.height = 'auto';
        loader.style.display = 'inline-flex';
        loader.style.verticalAlign = 'middle';
        loader.style.flex = '0 0 auto';

        // WHAT GETS REPLACED, in order:
        //
        // WHAT GETS REPLACED, in order:
        //
        //  1. The caller's own selector, passed to busy(). The call site already
        //     has the control in hand, so it is the right place to say which node
        //     to stand in for; no markers scattered through the layouts.
        //  2. Otherwise the first DIRECT-CHILD icon: an <svg>, or a node classed
        //     `icon` / `icon-*` (Joomla's own icon convention). Direct child only:
        //     an <svg> buried inside a wrapper is left alone rather than risk
        //     replacing a node that also holds the label.
        //  3. Otherwise the whole content, with the measured width pinned.
        //
        // Swapping the icon rather than the content matters: the label is what tells
        // the merchant WHICH action is running, and keeping it stops the button
        // collapsing to a square and shoving the rest of the toolbar sideways.
        var chosen = slot ? el.querySelector(slot) : null;

        if (chosen && chosen !== el && el.contains(chosen)) {
            chosen.replaceWith(loader);

            return;
        }

        var icon = el.querySelector('svg, .icon, [class*="icon-"]');

        if (icon && icon.parentNode === el) {
            icon.replaceWith(loader);

            return;
        }

        // Nothing to stand in for: replace the content, but pin the measured box
        // first so the layout does not jump.
        var box = el.getBoundingClientRect();

        if (box.width) {
            el.style.minWidth = Math.ceil(box.width) + 'px';
            el.dataset.alfaBusyWidth = '1';
        }

        el.innerHTML = '';
        el.appendChild(loader);
    }

    /**
     * Restore a control to exactly what it was before {@see paint}.
     *
     * @param {Element} el The control.
     */
    function release(el) {
        el.classList.remove('alfa-is-busy');
        el.removeAttribute('aria-busy');

        if (el.dataset.alfaBusyMarkup !== undefined) {
            el.innerHTML = el.dataset.alfaBusyMarkup;
            delete el.dataset.alfaBusyMarkup;
        }

        if (el.dataset.alfaBusyWidth === '1') {
            el.style.minWidth = '';
            delete el.dataset.alfaBusyWidth;
        }

        // Only re-enable what WE disabled: a control the page meant to keep locked
        // must stay locked.
        if (el.dataset.alfaBusyDisabled === '1') {
            delete el.dataset.alfaBusyDisabled;
        } else {
            el.removeAttribute('disabled');
        }
    }

    /**
     * Small imperative API, because most call sites want a behaviour rather than an
     * element: cover the page, or mark one control busy while a request is in
     * flight. Every entry point is idempotent: calling it twice does not stack a
     * second loader, and hiding something never shown is a no-op.
     */
    window.Alfa = window.Alfa || {};

    window.Alfa.loader = {
        /**
         * Cover the page (or a positioned host) with the fullscreen loader.
         *
         * @param {Element} [host] Where to append; defaults to <body>.
         *
         * @return {Element} The loader element.
         */
        show: function (host) {
            var parent = host || document.body;
            var existing = parent.querySelector(':scope > alfa-core-loader');

            if (existing) {
                return existing;
            }

            return parent.appendChild(document.createElement('alfa-core-loader'));
        },

        /**
         * Show the fullscreen loader, then run `next` only once the browser has
         * ACTUALLY PAINTED it.
         *
         * This is the difference between a loader that works and one that appears
         * never to fire. `show(); form.submit();` mutates the DOM and starts a
         * navigation in the SAME task, so no frame is ever rendered in between:
         * the browser keeps displaying the last painted state (without the loader)
         * until the new document commits. The loader is in the DOM the whole time
         * and no human ever sees it.
         *
         * Two rAFs: the first runs before the next paint, the second after it has
         * been committed, so by the time `next` runs, the loader is on screen.
         *
         * @param {Function} next   Runs after the loader is visible (e.g. the submit).
         * @param {Element}  [host] Where to append; defaults to <body>.
         *
         * @return {Element} The loader element.
         */
        showThen: function (next, host) {
            var loader = window.Alfa.loader.show(host);

            if (typeof next !== 'function') {
                return loader;
            }

            // rAF is PAUSED in a hidden tab. Gating the caller's action on it alone
            // means a merchant who switches tab right after clicking never submits
            // anything, so the frame wait races a timer, and whichever wins runs
            // `next` exactly once. A cosmetic paint-wait must never be able to
            // block the actual work.
            var done = false;

            var run = function () {
                if (done) {
                    return;
                }

                done = true;
                next();
            };

            if (typeof window.requestAnimationFrame === 'function') {
                window.requestAnimationFrame(function () {
                    window.requestAnimationFrame(run);
                });
            }

            window.setTimeout(run, 80);

            return loader;
        },

        /**
         * Remove the fullscreen loader, if one is showing.
         *
         * @param {Element} [host] Where it was appended; defaults to <body>.
         */
        hide: function (host) {
            var parent = host || document.body;
            var loader = parent.querySelector(':scope > alfa-core-loader');

            if (loader) {
                loader.remove();
            }
        },

        /**
         * Show the loader INSIDE one control: a button running an AJAX action.
         *
         * The control is disabled and announced as busy (which also removes the
         * double-click double-post), and the loader stands in for its icon so the
         * label survives and the button keeps its width.
         *
         * WHAT THE LOADER STANDS IN FOR, in order:
         *   1. `slot`: a CSS selector you pass, when the markup is unusual.
         *   2. Otherwise the first DIRECT-CHILD <svg> or `.icon` / `.icon-*` node.
         *      This covers every button in Alfa today; you rarely pass `slot`.
         *   3. Otherwise the whole content, with the measured width pinned.
         *
         * Pair every call with {@see hideIn}, in a finally(), so a failed request
         * cannot leave the control stuck.
         *
         * @param {Element} el     The control.
         * @param {string}  [slot] CSS selector for the node to replace.
         *
         * @return {Element} The same element.
         */
        showIn: function (el, slot) {
            if (!el || busyState.get(el)) {
                return el;                                  // absent, or already busy
            }

            // Remember whether it was ALREADY disabled, so restoring never enables
            // a control the page meant to keep locked.
            if (el.hasAttribute('disabled')) {
                el.dataset.alfaBusyDisabled = '1';
            }

            el.setAttribute('aria-busy', 'true');
            el.setAttribute('disabled', 'disabled');

            busyState.set(el, { shownAt: Date.now() });
            paint(el, slot);

            return el;
        },

        /**
         * Take the loader back out of a control and restore it exactly.
         *
         * Never snaps away instantly: if the action finished sooner than
         * {@see MIN_VISIBLE}, the loader is held for the remainder, because a
         * loader that blinks out in two frames reads as a glitch.
         *
         * @param {Element} el The control.
         *
         * @return {Element} The same element.
         */
        hideIn: function (el) {
            if (!el) {
                return el;
            }

            var state = busyState.get(el);

            if (!state) {
                return el;                                  // never shown
            }

            var shownFor = Date.now() - state.shownAt;

            if (shownFor >= MIN_VISIBLE) {
                busyState.delete(el);
                release(el);

                return el;
            }

            window.setTimeout(function () {
                busyState.delete(el);
                release(el);
            }, MIN_VISIBLE - shownFor);

            return el;
        }
    };
}());
