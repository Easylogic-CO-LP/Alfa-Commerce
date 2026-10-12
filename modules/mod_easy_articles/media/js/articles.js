/**
 * Easy Articles module: tabs, on-demand tab panels and the Keen slider.
 *
 * Builds no markup: tabs, panels, arrows and dots are rendered by the module
 * layout; this script reads data attributes, toggles state and, for a tab that
 * loads on demand, inserts the server-rendered panel. A card has no
 * behaviour of its own: it is a link.
 *
 * @package    Easy Articles
 * @subpackage mod_easy_articles
 */
(function () {
    'use strict';

    const ROOT = '[data-mod-easy-articles]';

    /*
       Slider
       */

    /** Arrows + dots: the layout renders them; keep their state in step with the track. */
    function navigation(root) {
        return function (slider) {
            const prev = root.querySelector('[data-action="slider-prev"]');
            const next = root.querySelector('[data-action="slider-next"]');
            const dots = Array.from(root.querySelectorAll('[data-action="slider-dot"]'));

            function update() {
                const details = slider.track.details;

                if (!details) {
                    return;
                }

                const loop = !!slider.options.loop;

                if (prev) {
                    prev.disabled = !loop && details.rel <= 0;
                }

                if (next) {
                    next.disabled = !loop && details.rel >= details.maxIdx;
                }

                dots.forEach((dot, index) => {
                    // Without a loop the last cards share one position, so a dot past it
                    // would lead nowhere new.
                    dot.hidden = !loop && index > details.maxIdx;

                    if (index === details.rel) {
                        dot.setAttribute('data-state', 'active');
                    } else {
                        dot.removeAttribute('data-state');
                    }
                });
            }

            slider.on('created', () => {
                if (prev) {
                    prev.addEventListener('click', () => slider.prev());
                }

                if (next) {
                    next.addEventListener('click', () => slider.next());
                }

                dots.forEach((dot, index) => dot.addEventListener('click', () => slider.moveToIdx(index)));
                update();
            });
            slider.on('slideChanged', update);
            slider.on('optionsChanged', update);
            slider.on('updated', update);
        };
    }

    /**
     * Advance on the delay of the band the screen is in: `delay()` answers 0 where the
     * slider does not play; hold while hovered or focused, resume when left.
     */
    function autoplay(delay) {
        return function (slider) {
            let timer = 0;
            let held = false;

            const clear = () => {
                clearTimeout(timer);
                timer = 0;
            };

            const arm = () => {
                clear();

                const ms = delay();

                if (!held && ms > 0) {
                    timer = setTimeout(() => slider.next(), ms);
                }
            };

            const hold = () => {
                held = true;
                clear();
            };

            const release = () => {
                held = false;
                arm();
            };

            slider.on('created', () => {
                slider.container.addEventListener('mouseover', hold);
                slider.container.addEventListener('mouseout', release);
                slider.container.addEventListener('focusin', hold);
                slider.container.addEventListener('focusout', release);
                arm();
            });
            slider.on('dragStarted', clear);
            slider.on('animationEnded', arm);
            slider.on('updated', arm);
            slider.on('optionsChanged', arm);
            slider.on('destroyed', clear);
        };
    }

    function initSlider(root) {
        if (root.getAttribute('data-state') !== 'idle') {
            return;
        }

        const track = root.querySelector('[data-slider-track]');

        if (!track) {
            return;
        }

        if (typeof KeenSlider === 'undefined') {
            root.setAttribute('data-state', 'unavailable');

            return;
        }

        // The slider's settings on every device (CardLook::bands): cards per view, spacing,
        // loop, autoplay, each with the width it applies up to; `max` 0 is every screen
        // wider than the widest breakpoint. Narrowest first: the base options are the
        // narrowest band's and every wider one overrides them from its lower edge.
        let bands = [];

        try {
            bands = JSON.parse(root.getAttribute('data-bands') || '[]');
        } catch (e) {
            bands = [];
        }

        if (!Array.isArray(bands) || !bands.length) {
            bands = [{ max: 0, perView: 3, spacing: 16, autoplay: 0, loop: false }];
        }

        bands.sort((a, b) => (a.max || Infinity) - (b.max || Infinity));

        // Where every card fits there is nothing to slide to, so that band neither loops nor drags.
        const count = track.children.length;
        const options = (band) => ({ slides: { perView: band.perView, spacing: band.spacing }, loop: !!band.loop && count > band.perView, drag: count > band.perView });
        const queries = {};
        const edges = [];

        bands.forEach((band, index) => {
            if (index > 0) {
                const query = '(min-width: ' + (bands[index - 1].max + 1) + 'px)';

                queries[query] = options(band);
                edges.push({ media: window.matchMedia ? window.matchMedia(query) : null, band });
            }
        });

        // The band the screen is in: the widest whose lower edge it has passed.
        const current = () => edges.reduce((found, edge) => (edge.media && edge.media.matches ? edge.band : found), bands[0]);
        const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const plugins = [navigation(root)];

        if (!reducedMotion && bands.some((band) => band.autoplay > 0)) {
            plugins.push(autoplay(() => current().autoplay));
        }

        root.setAttribute('data-state', 'ready');

        root.easySlider = new KeenSlider(track, Object.assign(options(bands[0]), { breakpoints: queries }), plugins);
    }

    function initSliders(container) {
        container.querySelectorAll('[data-slider]').forEach(initSlider);
    }

    /*
       Tabs
       */

    /** Fetch a tab's server-rendered panel once; a failed fetch leaves an empty panel. */
    async function load(root, panel) {
        const base = root.getAttribute('data-lazy-url');

        if (!base) {
            return;
        }

        panel.setAttribute('data-state', 'loading');

        try {
            const url = new URL(base, window.location.href);
            url.searchParams.set('tab', panel.getAttribute('data-panel'));

            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const json = await response.json();

            if (!json || json.success === false || !json.data || typeof json.data.html !== 'string') {
                throw new Error((json && json.message) || 'Unexpected response');
            }

            panel.innerHTML = json.data.html;
            panel.setAttribute('data-state', 'loaded');
            initSliders(panel);
        } catch (error) {
            panel.setAttribute('data-state', 'error');
            console.error('[mod_easy_articles] tab could not be loaded', error);
        }
    }

    function activate(root, key) {
        const panels = Array.from(root.querySelectorAll('[data-panel]'));
        const target = panels.find((panel) => panel.getAttribute('data-panel') === key);

        if (!target) {
            return;
        }

        // A panel still to be fetched keeps the outgoing panel's height while it
        // loads, so the page does not collapse and re-grow around the fetch.
        const outgoing = panels.find((panel) => !panel.hidden && panel !== target);
        const hold = outgoing && target.getAttribute('data-state') === 'idle' ? outgoing.getBoundingClientRect().height : 0;

        if (hold > 0) {
            target.style.minHeight = Math.round(hold) + 'px';
        }

        panels.forEach((panel) => {
            panel.hidden = panel !== target;
        });

        root.querySelectorAll('[data-action="tab"]').forEach((tab) => {
            const on = tab.getAttribute('data-tab') === key;

            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;

            if (on) {
                tab.setAttribute('data-state', 'active');
            } else {
                tab.removeAttribute('data-state');
            }
        });

        if (target.getAttribute('data-state') === 'idle') {
            load(root, target).finally(() => {
                target.style.minHeight = '';
            });
        } else {
            // A slider inside a hidden panel had no width to measure; now it has.
            initSliders(target);
        }
    }

    function initModule(root) {
        // Another edition of this module may share the page; each script binds its own roots once.
        if (root.dataset.easyArticlesReady) {
            return;
        }

        root.dataset.easyArticlesReady = 'true';

        root.addEventListener('click', (event) => {
            const tab = event.target.closest('[data-action="tab"]');

            if (tab && root.contains(tab)) {
                activate(root, tab.getAttribute('data-tab'));
            }
        });

        // Arrow keys move between tabs, as a tablist is expected to.
        root.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }

            const current = event.target.closest('[data-action="tab"]');

            if (!current || !root.contains(current)) {
                return;
            }

            const tabs = Array.from(root.querySelectorAll('[data-action="tab"]'));
            const index = tabs.indexOf(current);
            const next = tabs[(index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];

            if (next) {
                event.preventDefault();
                next.focus();
                activate(root, next.getAttribute('data-tab'));
            }
        });

        root.querySelectorAll('[data-panel]:not([hidden])').forEach(initSliders);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll(ROOT).forEach(initModule);
    });
})();
