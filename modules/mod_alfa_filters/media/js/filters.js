/**
 * Alfa Filters Module - Main JavaScript
 *
 * Handles:
 * - Filter refresh via AJAX (both dynamic and non-dynamic modes)
 * - Off-canvas panel toggling
 * - Responsive layout switching
 * - Price range slider
 * - Category/subcategory checkbox toggling
 *
 * @package    Alfa
 * @subpackage mod_alfa_filters
 */

document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    /*
       Utility functions
       */

    const qs = (selector, context = document) => {
        try {
            return context.querySelector(selector);
        } catch (error) {
            console.error(`[AlfaFilters] Invalid selector: ${selector}`, error);
            return null;
        }
    };

    const qsAll = (selector, context = document) => {
        try {
            return context.querySelectorAll(selector);
        } catch (error) {
            console.error(`[AlfaFilters] Invalid selector: ${selector}`, error);
            return [];
        }
    };

    // Tiny TTL cache: a filter combination is deterministic, so the same query
    // string returns the same panel until the catalog changes; replay it
    // instead of re-hitting the server. Short TTL bounds staleness.
    const createTtlCache = (ttlMs = 60000) => {
        const store = new Map();

        return {
            get(key) {
                const hit = store.get(key);
                if (!hit) {
                    return undefined;
                }
                if (hit.expiry < Date.now()) {
                    store.delete(key);
                    return undefined;
                }
                return hit.value;
            },
            set(key, value) {
                store.set(key, { value, expiry: Date.now() + ttlMs });
            },
            clear() {
                store.clear();
            },
        };
    };

    const parseJsonResponse = async (response) => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    };

    /*
       Alfa filters instance class
       */

    class AlfaFiltersInstance {

        constructor(wrapper) {
            this.wrapper = wrapper;
            this.moduleId = wrapper.dataset.moduleId;
            this.config = window.alfaFiltersModules?.[this.moduleId] || {};

            // Cache DOM elements scoped to this wrapper
            this.filterForm = qs('form[name="alfaFilterForm"]', this.wrapper);
            this.offCanvas = qs('.alfa-filters-offcanvas', this.wrapper);
            this.toggler = qs('.alfa-filters-offcanvas-toggler', this.wrapper);
            this.loadingOverlay = qs('.alfa-filters-loading-overlay', this.wrapper);

            // State
            this.isVisible = false;
            this.currentMode = null;
            this._isPriceChange = false;
            this._isProductSetChange = false;

            if (!this.filterForm) {
                console.warn(`[AlfaFilters:${this.moduleId}] Filter form not found.`);
                return;
            }

            this.init();
        }

        init() {
            try {
                this.initOffCanvas();
                this.initResponsiveBehavior();
                this.initFilterRefresh();
                this.initResetButtons();
                this.initPriceSlider();
                this.initDiscountFilters();
                this.initSubcategoryTogglers();
                this.initFacetSelects();
            } catch (error) {
                console.error(`[AlfaFilters:${this.moduleId}] Init failed:`, error);
            }
        }

        /*
            Loading overlay
        */

        showLoading() {
            this.loadingOverlay?.classList.add('is-active');
        }

        hideLoading() {
            this.loadingOverlay?.classList.remove('is-active');
        }

        /*
           Reset buttons
           */

        initResetButtons() {
            // Global reset: rendered outside .af-form-content, so the AJAX swap never
            // replaces it and the binding is made once.
            if (!this._globalResetBound) {
                this._globalResetBound = true;

                // ONE handler, attached to each rendered button. It cannot be delegated
                // from the wrapper: the panel stops click propagation at
                // .alfa-filters-wrapper-inner so a backdrop click can close it, and a
                // wrapper listener would never see the reset at all.
                const resetAll = () => {

                    // Uncheck all checkboxes
                    this.filterForm.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                        cb.checked = false;
                    });

                    // Reset selects to first option. A facet rendered as a MULTIPLE
                    // select must be cleared instead: selectedIndex = 0 would SELECT
                    // its first option and leave the facet filtering on that value.
                    this.filterForm.querySelectorAll('select').forEach(sel => {
                        if (sel.multiple) {
                            Array.from(sel.options).forEach(option => {
                                option.selected = false;
                            });
                            return;
                        }

                        sel.selectedIndex = 0;
                    });

                    // Reset number inputs (discount fields)
                    this.filterForm.querySelectorAll('input[type="number"]').forEach(input => {
                        if (input.classList.contains('af-price-min') || input.classList.contains('af-price-max')) return;
                        input.value = '';
                    });

                    // Reset price slider to absolute range
                    const priceReset = qs('.af-price-reset', this.wrapper);
                    priceReset?.click();

                    this.filterForm.dispatchEvent(new Event('change', { bubbles: true }));
                };

                qsAll('.af-reset-all', this.wrapper).forEach((btn) => {
                    btn.addEventListener('click', resetAll);
                });

                // Listen for form changes to update reset button visibility
                this.filterForm.addEventListener('change', () => {
                    this.updateResetVisibility();
                });
            }

            // Per-section resets (inside .af-form-content; rebind after AJAX)
            this.initSectionResetButtons();

            // Set initial visibility
            this.updateResetVisibility();
        }

        initSectionResetButtons() {
            // Category reset
            const categoryResetBtn = qs('.af-reset-category', this.wrapper);
            categoryResetBtn?.addEventListener('click', () => {
                this.filterForm.querySelectorAll('input[name="filter[category][]"]').forEach(cb => {
                    cb.checked = false;
                });
                this.filterForm.dispatchEvent(new Event('change', { bubbles: true }));
            });

            // Manufacturer reset
            const manufacturerResetBtn = qs('.af-reset-manufacturer', this.wrapper);
            manufacturerResetBtn?.addEventListener('click', () => {
                this.filterForm.querySelectorAll('input[name="filter[manufacturer][]"]').forEach(cb => {
                    cb.checked = false;
                });
                this.filterForm.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        /**
         * Price is a real filter ONLY when the user explicitly set it (moved the
         * slider / edited the inputs). We track that intent with a persistent flag
         * rather than comparing slider position to the absolute range: that range
         * changes on every AJAX re-render (combine narrows it, remove widens it), so
         * a stale position would falsely read as "active" and submit a phantom price
         * that empties both the results and the facets.
         */
        isPriceActive() {
            return !!this._priceUserSet;
        }

        updateResetVisibility() {
            // Every match, not the first: Reset All is rendered twice (once in the
            // off-canvas header, once in the desktop bar) and the breakpoint decides
            // which one is on screen, so both must carry the same state.
            const toggle = (selector, hasActive) => {
                qsAll(selector, this.wrapper).forEach((btn) => {
                    btn.classList.toggle('is-visible', hasActive);
                });
            };

            // Category: any checked
            const hasCategory = this.filterForm.querySelector('input[name="filter[category][]"]:checked') !== null;
            toggle('.af-reset-category', hasCategory);

            // Manufacturer: any checked
            const hasManufacturer = this.filterForm.querySelector('input[name="filter[manufacturer][]"]:checked') !== null;
            toggle('.af-reset-manufacturer', hasManufacturer);

            // Price: slider moved from its absolute range.
            const hasPrice = this.isPriceActive();
            toggle('.af-price-reset', hasPrice);

            // Global: any filter is in effect, whether or not it has a control on screen.
            toggle('.af-reset-all', this.hasActiveFilterState());
        }

        /**
         * Whether the request carries a filter the shopper would want to clear.
         *
         * The rendered form cannot answer this alone. A count-derived facet with nothing
         * reachable drops out of the panel and takes its control with it, while its selection
         * keeps narrowing the results, so on such a URL there is nothing checked to find, and
         * the one button that clears it was hidden. The address bar still holds the selection,
         * and applyFilteredItems() keeps it in step with the form, so together they answer for
         * both the rendered and the dropped facets.
         */
        hasActiveFilterState() {
            const rendered = Array.from(this.filterForm.querySelectorAll('input, select')).some((el) => {
                const name = el.name || '';

                if (!name.startsWith('filter[')) {
                    return false;
                }

                // The price inputs always carry the absolute range, so a value there proves
                // nothing; intent is tracked by isPriceActive() instead.
                if (name.startsWith('filter[price_')) {
                    return false;
                }

                // An empty value is the neutral choice, never a filter: the stock and
                // on_sale groups render their "any" option as a checked radio with value="".
                const value = String(el.value ?? '').trim();

                return el.type === 'checkbox' || el.type === 'radio'
                    ? el.checked && value !== ''
                    : value !== '';
            });

            return rendered || this.isPriceActive() || this.urlHasActiveFilter(location.search);
        }

        /**
         * Whether a query string carries a non-empty Alfa filter. Ordering, page size,
         * pagination and routing variables are state, not filters, and an empty control is
         * not a filter either.
         *
         * @param {string} search A query string, with or without its leading '?'.
         */
        urlHasActiveFilter(search) {
            for (const [key, value] of new URLSearchParams(search || '')) {
                if (key.startsWith('filter[') && String(value).trim() !== '') {
                    return true;
                }
            }

            return false;
        }

        /*
           REINITIALIZE FORM ELEMENTS (after AJAX content replacement)
           */

        reinitFormElements() {
            this.initSectionResetButtons();
            this.initPriceSlider();
            this.initDiscountFilters();
            this.initSubcategoryTogglers();
            this.initFacetSelects();
            this.updateResetVisibility();

            // The AJAX response replaced the facets, so any ShowOn target the engine was
            // tracking is a detached node now. Re-boot it over the fresh markup. Absent
            // when no facet carries a rule; the engine is only loaded when one does.
            window.Alfa?.ShowOn?.refresh?.(this.filterForm);
        }

        /*
           FACET DROPDOWNS: Choices.js over the plain <select multiple>
           */

        /**
         * Dress every dropdown facet with Choices.js. The markup stays a plain
         * multiple <select>; Choices is Joomla's vendor copy, loaded by the
         * dispatcher only when a facet renders this way; absent, the native
         * control simply stays. Every string comes from the select's data
         * attributes, so the layout owns the words.
         */
        initFacetSelects() {
            this._facetSelects = [];
            this._facetSelectPending = false;

            if (typeof window.Choices !== 'function') {
                return;
            }

            qsAll('select.af-field-select', this.filterForm).forEach(select => {
                this._facetSelects.push(new window.Choices(select, {
                    removeItemButton: true,
                    allowHTML: false,
                    shouldSort: false,                        // merchant value order
                    placeholderValue: select.dataset.placeholder || null,
                    noResultsText: select.dataset.noResults || undefined,
                    itemSelectText: '',
                    searchResultLimit: select.options.length, // a search never hides a matching value
                    fuseOptions: { threshold: 0.3 },          // the default 0.6 matches far too loosely for value names
                }));
            });
        }

        /**
         * Tear the Choices instances down. They listen on the document, so one
         * simply dropped with its markup would keep handling clicks for a select
         * that no longer exists. A pick held for an open dropdown is not lost:
         * the refresh that replaces the panel reads the whole form.
         */
        destroyFacetSelects() {
            (this._facetSelects || []).forEach(instance => instance.destroy());
            this._facetSelects = [];
            this._facetSelectPending = false;
        }

        isFacetDropdownOpen(select) {
            return (this._facetSelects || []).some(
                instance => instance.passedElement.element === select && instance.dropdown.isActive,
            );
        }

        /*
           Fetch available filters (AJAX)
           */

        async fetchAvailableFilters(queryString, { resetPrice = false } = {}) {
            const filtersActionUrl = this.filterForm?.dataset?.filtersAction;
            if (!filtersActionUrl) return;

            // TTL cache: an identical filter combination replays instantly with
            // no server round-trip (the response is deterministic per query).
            this._filtersCache ??= createTtlCache(60000);
            const cached = this._filtersCache.get(queryString);
            if (cached) {
                this.applyAvailableFilters(cached);
                return;
            }

            const separator = filtersActionUrl.includes('?') ? '&' : '?';
            const url = `${filtersActionUrl}${separator}${queryString}`;

            // Cancel any in-flight request so a slow earlier response cannot land
            // after (and overwrite) a newer one (out-of-order protection).
            this._filtersAbort?.abort();
            this._filtersAbort = new AbortController();

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Dynamic-Filtering': this.config?.dynamicFiltering ? '1' : '0',
                        'X-Module-ID': this.moduleId
                    },
                    signal: this._filtersAbort.signal
                });

                const data = await parseJsonResponse(response);

                if (!data.success) {
                    throw new Error(data.message || 'Unknown server error');
                }

                const result = {
                    html: data.data?.html ?? '',
                    priceRange: data.data?.priceRange ?? null
                };

                this._filtersCache.set(queryString, result);
                this.applyAvailableFilters(result);

            } catch (error) {
                if (error.name === 'AbortError') {
                    return; // superseded by a newer request
                }
                console.error(`[AlfaFilters:${this.moduleId}] Failed to fetch filters:`, error);
            }
        }

        /**
         * Apply an available-filters result (fresh or cached) to the DOM.
         */
        applyAvailableFilters({ html, priceRange }) {
            const container = qs('.af-form-content', this.wrapper);
            if (container && html) {
                // Choices goes BEFORE the markup does: it listens on the document, so an
                // instance dropped with its select would keep handling clicks for it.
                this.destroyFacetSelects();
                container.innerHTML = html;
                this.reinitFormElements();
            }

            // Update absoluteMin/Max from server response for the submit handler.
            if (priceRange) {
                this.absoluteMin = priceRange.min;
                this.absoluteMax = priceRange.max;
            }
        }

        /*
           FETCH FILTERED ITEMS (AJAX, dynamic mode only)
           */

        async fetchFilteredItems(queryString) {
            const itemsActionUrl = this.filterForm?.dataset?.action;
            if (!itemsActionUrl) return;

            const componentSelector = "#alfa-app[data-view='items']";

            // TTL cache: safe now that applyFilteredItems is a PURE function of
            // (html, queryString): the URL is rebuilt deterministically from the
            // query string, not the live form, so replaying cached markup for an
            // identical combination can't desync the form/URL.
            this._itemsCache ??= createTtlCache(60000);
            const cachedHtml = this._itemsCache.get(queryString);
            if (cachedHtml !== undefined) {
                this.applyFilteredItems(cachedHtml, queryString, componentSelector);
                return;
            }

            // Probed, not assumed; same as fetchAvailableFilters above. A hardcoded '&'
            // is correct only while the action carries ?tmpl=component, which makes URL
            // construction depend on a transport detail; without a '?' already there it
            // would emit /en/katastema&filter[...], a path with an ampersand in it.
            const separator = itemsActionUrl.includes('?') ? '&' : '?';
            const url = `${itemsActionUrl}${separator}${queryString}`;

            this._itemsAbort?.abort();
            this._itemsAbort = new AbortController();

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: this._itemsAbort.signal
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const html = await response.text();
                this._itemsCache.set(queryString, html);
                this.applyFilteredItems(html, queryString, componentSelector);

            } catch (error) {
                if (error.name === 'AbortError') {
                    return; // superseded by a newer request
                }
                console.error(`[AlfaFilters:${this.moduleId}] Failed to fetch items:`, error);
            }
        }

        /**
         * Swap in the items markup (fresh or cached) and sync the address bar to
         * the active filters.
         */
        applyFilteredItems(html, queryString, componentSelector = "#alfa-app[data-view='items']") {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newItems = qs(componentSelector, doc);
            const oldItems = qs(componentSelector);

            if (newItems && oldItems) {
                oldItems.innerHTML = newItems.innerHTML;

                // The swap replaced live nodes: searchtools' Filter Options toggle,
                // the fancy-selects and anything else Joomla booted on load are now
                // fresh elements with no listeners. `joomla:updated` is the CMS's own
                // signal for exactly this (searchtools.js subscribes to it), so one
                // dispatch re-initialises everything in the new subtree.
                oldItems.dispatchEvent(new CustomEvent('joomla:updated', { bubbles: true }));
            }

            // Update URL DETERMINISTICALLY from the query string, never by reading
            // the live form (which may be mid-swap -> missed keys -> accumulating
            // duplicates like filter[fields][1][]=3&...=2&...=3). Drop every filter[*]/
            // list[*] param, then set them from queryString. This makes the swap a
            // pure function of (html, queryString), so it is safe to cache/replay.
            const params = new URLSearchParams(location.search);

            for (const key of [...params.keys()]) {
                if (key.startsWith('filter[') || key.startsWith('list[')) {
                    params.delete(key);
                }
            }

            for (const [key, value] of new URLSearchParams(queryString)) {
                if (value) {
                    params.append(key, value);
                }
            }

            const mergedQuery = params.toString();
            history.replaceState(
                null,
                '',
                mergedQuery ? `${location.pathname}?${mergedQuery}` : location.pathname,
            );

            // The address bar is half of hasActiveFilterState(), and the facet refresh that
            // also recomputes it runs on its own request, so the button is only correct once
            // this rewrite has landed.
            this.updateResetVisibility();
        }

        /*
           FILTER REFRESH (handles both dynamic and non-dynamic modes)
           */

        initFilterRefresh() {
            const filtersActionUrl = this.filterForm.dataset.filtersAction;

            if (!filtersActionUrl) {
                console.error(`[AlfaFilters:${this.moduleId}] Missing filters action URL.`);
                return;
            }

            // Track product-set changes (category or manufacturer)
            this.filterForm.addEventListener('change', (e) => {
                if (e.target.matches('input[name="filter[category][]"]')) {
                    this._isProductSetChange = true;
                    // Clear manufacturer selections when category changes
                    this.filterForm.querySelectorAll('input[name="filter[manufacturer][]"]').forEach(cb => {
                        cb.checked = false;
                    });
                }

                if (e.target.matches('input[name="filter[manufacturer][]"]')) {
                    this._isProductSetChange = true;
                }
            });

            // No debounce: every change fires immediately (instant feedback). Rapid
            // bursts are handled downstream: AbortController cancels superseded
            // in-flight requests and the TTL cache replays identical combinations.
            const handleFormChange = (event) => {
                // A pick inside an OPEN facet dropdown waits for the dropdown to close:
                // refreshing at once would swap the panel under the shopper's cursor and
                // shut the list after every single value. Choices fires `hideDropdown`
                // on the <select> when the list closes, and that bubbles to the form.
                const facetSelect = event?.target?.closest?.('select.af-field-select');

                if (facetSelect && this.isFacetDropdownOpen(facetSelect)) {
                    this._facetSelectPending = true;
                    return;
                }

                const isPriceChange = this._isPriceChange;
                const isProductSetChange = this._isProductSetChange;
                this._isPriceChange = false;
                this._isProductSetChange = false;

                // In non-dynamic mode, price changes don't trigger a refresh
                if (isPriceChange && !isProductSetChange && !this.config?.dynamicFiltering) {
                    return;
                }

                // Price filters ONLY when the user actually moved the slider off
                // its full range. Strip it when the slider is untouched (so the
                // default bounds never silently exclude unpriced items) or on a
                // product-set reset. A user-set price PERSISTS across field and
                // category changes; it is the customer's explicit choice.
                // Price persists across ALL filter changes; it is the customer's
                // explicit choice, cleared only via the price reset. Strip it only
                // when not user-set (the untouched default must never filter).
                const resetPrice = !this.isPriceActive();
                const formData = new FormData(this.filterForm);

                if (resetPrice) {
                    formData.delete('filter[price_min]');
                    formData.delete('filter[price_max]');
                }

                const queryString = new URLSearchParams(formData).toString();

                this.showLoading();

                const promises = [
                    this.fetchAvailableFilters(queryString, { resetPrice })
                ];

                // Dynamic mode: also update items view
                if (this.config?.dynamicFiltering) {
                    promises.push(this.fetchFilteredItems(queryString));
                }

                Promise.all(promises).catch(error => {
                    console.error(`[AlfaFilters:${this.moduleId}] Filter update error:`, error);
                }).finally(() => {
                    this.hideLoading();
                });

            };

            this.filterForm.addEventListener('change', handleFormChange);
            this.filterForm.addEventListener('hideDropdown', () => {
                if (this._facetSelectPending) {
                    this._facetSelectPending = false;
                    handleFormChange();
                }
            });
        }

        /*
           Off-canvas panel
           */

        initOffCanvas() {
            if (!this.offCanvas || !this.toggler) return;

            const closeBtn = qs('.alfa-filters-close-btn', this.offCanvas);
            const innerWrapper = qs('.alfa-filters-wrapper-inner', this.offCanvas);
            const htmlElement = document.documentElement;

            if (!innerWrapper) return;

            const self = this;

            this.open = ({ fullscreen = false } = {}) => {
                self.offCanvas.classList.add(
                    'alfa-filters-offcanvas--animatable',
                    'alfa-filters-offcanvas--visible',
                    'alfa-filters-offcanvas--visible-inner'
                );

                if (fullscreen) {
                    htmlElement.classList.remove('alfa-filters-noscroll');
                } else {
                    htmlElement.classList.add('alfa-filters-noscroll');
                }

                self.toggler.setAttribute('aria-expanded', 'true');
                self.isVisible = true;
            };

            this.close = ({ skipTransition = false } = {}) => {
                if (skipTransition) {
                    self.offCanvas.classList.remove(
                        'alfa-filters-offcanvas--animatable',
                        'alfa-filters-offcanvas--visible-inner',
                        'alfa-filters-offcanvas--visible'
                    );
                    htmlElement.classList.remove('alfa-filters-noscroll');
                    self.toggler.setAttribute('aria-expanded', 'false');
                } else {
                    self.offCanvas.classList.add('alfa-filters-offcanvas--animatable');
                    self.offCanvas.classList.remove('alfa-filters-offcanvas--visible-inner');

                    const handleTransitionEnd = (event) => {
                        if (event.target !== innerWrapper) return;

                        self.offCanvas.classList.remove('alfa-filters-offcanvas--visible');
                        htmlElement.classList.remove('alfa-filters-noscroll');
                        self.toggler.setAttribute('aria-expanded', 'false');

                        innerWrapper.removeEventListener('transitionend', handleTransitionEnd);
                    };

                    innerWrapper.addEventListener('transitionend', handleTransitionEnd);
                }

                self.isVisible = false;
            };

            this.toggle = (options = {}) => {
                self.isVisible ? self.close(options) : self.open(options);
            };

            this.offCanvas.addEventListener('transitionend', (event) => {
                if (event.target === innerWrapper) {
                    self.offCanvas.classList.remove('alfa-filters-offcanvas--animatable');
                }
            });

            this.offCanvas.addEventListener('click', () => this.toggle());
            innerWrapper.addEventListener('click', (event) => event.stopPropagation());
            this.toggler.addEventListener('click', () => this.toggle());

            if (closeBtn) {
                closeBtn.addEventListener('click', () => this.toggle());
            }
        }

        /*
           Responsive behavior
           */

        initResponsiveBehavior() {
            const isFixed = this.config?.fixedPos ?? false;
            const breakpoint = this.config?.responsiveChange ?? 768;

            if (isFixed) return;
            if (!this.filterForm || !this.offCanvas || !this.toggler) return;

            const updateLayout = () => {
                const isMobile = window.innerWidth <= breakpoint;
                const newMode = isMobile ? 'mobile' : 'desktop';

                if (newMode === this.currentMode) return;
                this.currentMode = newMode;

                if (newMode === 'desktop') {
                    this.open?.({ fullscreen: true });
                } else {
                    this.close?.({ skipTransition: true });
                }
            };

            window.addEventListener('resize', updateLayout);
            updateLayout();
        }

        /*
           Price range slider
           */

        initPriceSlider() {
            const minRange = qs('.af-range-min', this.wrapper);
            const maxRange = qs('.af-range-max', this.wrapper);
            const minInput = qs('.af-price-min', this.wrapper);
            const maxInput = qs('.af-price-max', this.wrapper);
            const rangeHighlight = qs('.af-range-highlight', this.wrapper);
            const resetButton = qs('.af-price-reset', this.wrapper);
            const applyButton = qs('.af-price-apply', this.wrapper);

            if (!minRange || !maxRange || !minInput || !maxInput || !rangeHighlight) return;

            this.absoluteMin = parseInt(minRange.min, 10);
            this.absoluteMax = parseInt(maxRange.max, 10);

            // Seed the user-set flag ONCE (first init) from whether a price arrived
            // via the URL/server (slider already off its full range). Re-inits must
            // NOT recompute it from position (the range changes across renders), so
            // afterwards only the user's slider/reset edits flip it.
            if (this._priceUserSet === undefined) {
                const seedMin = Number(this.absoluteMin);
                const seedMax = Number(this.absoluteMax);
                const seedVal0 = parseInt(minRange.value, 10);
                const seedVal1 = parseInt(maxRange.value, 10);

                this._priceUserSet = Number.isFinite(seedMin) && Number.isFinite(seedMax) && seedMax > seedMin
                    && Number.isFinite(seedVal0) && Number.isFinite(seedVal1)
                    && (seedVal0 > seedMin || seedVal1 < seedMax);
            }

            // When min === max (single price point), widen range inputs by 1 unit
            // so the browser can position the max thumb at the end of the track.
            // The fake +1 is visual only; number inputs always show the real value.
            const isSinglePrice = this.absoluteMin === this.absoluteMax;
            if (isSinglePrice) {
                const visualMax = this.absoluteMin + 1;
                minRange.max = visualMax;
                maxRange.max = visualMax;
                maxRange.value = visualMax;
            }

            const lockScroll = () => this.filterForm.classList.add('alfa-filters-form-noscroll');
            const unlockScroll = () => this.filterForm.classList.remove('alfa-filters-form-noscroll');

            [minRange, maxRange].forEach(slider => {
                slider.addEventListener('touchstart', lockScroll, { passive: true });
                slider.addEventListener('touchend', unlockScroll);
                slider.addEventListener('touchcancel', unlockScroll);
            });

            const clamp = (value, min, max) => Math.min(Math.max(value, min), max);

            const updateHighlight = (minValue, maxValue) => {
                const rangeSpan = this.absoluteMax - this.absoluteMin;

                if (rangeSpan === 0) {
                    rangeHighlight.style.left = '0%';
                    rangeHighlight.style.width = '100%';
                    return;
                }

                const percentMin = ((minValue - this.absoluteMin) / rangeSpan) * 100;
                const percentMax = ((maxValue - this.absoluteMin) / rangeSpan) * 100;

                rangeHighlight.style.left = `${percentMin}%`;
                rangeHighlight.style.width = `${percentMax - percentMin}%`;
            };

            const syncAll = (source = 'slider') => {
                let minValue, maxValue;

                if (source === 'slider') {
                    minValue = parseInt(minRange.value, 10);
                    maxValue = parseInt(maxRange.value, 10);
                    // Clamp the fake +1 visual value back to the real max for number inputs
                    maxValue = Math.min(maxValue, this.absoluteMax);
                } else {
                    minValue = parseFloat(minInput.value) || this.absoluteMin;
                    maxValue = parseFloat(maxInput.value) || this.absoluteMax;
                }

                minValue = clamp(minValue, this.absoluteMin, this.absoluteMax);
                maxValue = clamp(maxValue, this.absoluteMin, this.absoluteMax);

                if (minValue > maxValue) {
                    [minValue, maxValue] = [maxValue, minValue];
                }

                // Range slider uses integers
                minRange.value = Math.round(minValue);
                maxRange.value = isSinglePrice ? this.absoluteMin + 1 : Math.round(maxValue);
                // Number inputs preserve floats
                minInput.value = minValue;
                maxInput.value = maxValue;

                updateHighlight(minValue, maxValue);
            };

            const triggerPriceChange = (userSet = true) => {
                this._isPriceChange = true;
                this._priceUserSet = userSet; // explicit intent (reset passes false)
                this.filterForm.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const resetSlider = () => {
                minRange.value = this.absoluteMin;
                maxRange.value = isSinglePrice ? this.absoluteMin + 1 : this.absoluteMax;
                minInput.value = this.absoluteMin;
                maxInput.value = this.absoluteMax;
                updateHighlight(this.absoluteMin, this.absoluteMax);
                triggerPriceChange(false); // reset clears the user-set intent
            };

            // Range slider input (live visual feedback)
            minRange.addEventListener('input', () => syncAll('slider'));
            maxRange.addEventListener('input', () => syncAll('slider'));

            // Number input (live visual feedback)
            minInput.addEventListener('input', () => {
                const val = parseFloat(minInput.value);
                if (!isNaN(val)) {
                    const clamped = clamp(val, this.absoluteMin, this.absoluteMax);
                    minRange.value = Math.round(clamped);
                    updateHighlight(clamped, parseFloat(maxInput.value) || this.absoluteMax);
                }
            });
            maxInput.addEventListener('input', () => {
                const val = parseFloat(maxInput.value);
                if (!isNaN(val)) {
                    const clamped = clamp(val, this.absoluteMin, this.absoluteMax);
                    maxRange.value = Math.round(clamped);
                    updateHighlight(parseFloat(minInput.value) || this.absoluteMin, clamped);
                }
            });

            // Range slider commit
            minRange.addEventListener('change', (e) => {
                e.stopPropagation();
                syncAll('slider');
                triggerPriceChange();
            });
            maxRange.addEventListener('change', (e) => {
                e.stopPropagation();
                syncAll('slider');
                triggerPriceChange();
            });

            // Number input commit
            minInput.addEventListener('change', (e) => {
                e.stopPropagation();
                syncAll('number');
            });
            maxInput.addEventListener('change', (e) => {
                e.stopPropagation();
                syncAll('number');
            });

            // Enter key triggers price change in dynamic mode
            if (this.config?.dynamicFiltering) {
                minInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        syncAll('number');
                        triggerPriceChange();
                    }
                });

                maxInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        syncAll('number');
                        triggerPriceChange();
                    }
                });
            }

            // Apply button (dynamic mode only)
            if (applyButton && this.config?.dynamicFiltering) {
                applyButton.addEventListener('click', () => {
                    syncAll('number');
                    triggerPriceChange();
                });
            }

            if (resetButton) {
                resetButton.addEventListener('click', resetSlider);
            }

            syncAll('slider');

            // Form submit handler (non-dynamic mode, bind only once)
            if (!this.config?.dynamicFiltering && !this._submitHandlerBound) {
                this._submitHandlerBound = true;

                this.filterForm.addEventListener('submit', () => {
                    const minR = qs('.af-range-min', this.wrapper);
                    const maxR = qs('.af-range-max', this.wrapper);
                    if (!minR || !maxR) return;

                    const currentMin = parseInt(minR.value, 10);
                    const currentMax = parseInt(maxR.value, 10);

                    if (currentMin <= this.absoluteMin && currentMax >= this.absoluteMax) {
                        minR.disabled = true;
                        maxR.disabled = true;
                    }
                });
            }
        }

        /*
           Discount filters
           */

        initDiscountFilters() {
            const discountAmountInput = qs(`#filter_alfa_filters_discount_amount_min_${this.moduleId}`, this.wrapper);
            const discountPercentInput = qs(`#filter_alfa_filters_discount_percent_min_${this.moduleId}`, this.wrapper);
            const discountAmountBtn = qs('.af-discount-amount-btn', this.wrapper);
            const discountPercentBtn = qs('.af-discount-percent-btn', this.wrapper);

            const setupDiscountInput = (input) => {
                if (!input) return;

                input.addEventListener('change', (e) => e.stopPropagation());

                if (this.config?.dynamicFiltering) {
                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            this.filterForm.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    });
                }
            };

            setupDiscountInput(discountAmountInput);
            setupDiscountInput(discountPercentInput);

            const triggerChange = () => {
                this.filterForm.dispatchEvent(new Event('change', { bubbles: true }));
            };

            discountAmountBtn?.addEventListener('click', triggerChange);
            discountPercentBtn?.addEventListener('click', triggerChange);
        }

        /*
           Subcategory togglers
           */

        initSubcategoryTogglers() {
            const toggleButtons = qsAll('.af-toggle', this.wrapper);

            if (toggleButtons.length === 0) return;

            // Persist expanded state across AJAX by category data-id
            if (!this.expandedSubcategories) {
                this.expandedSubcategories = new Set();
            }

            toggleButtons.forEach((button) => {
                const listItem = button.closest('.af-item');
                const childContainer = listItem?.querySelector(':scope > .af-children');
                const categoryId = listItem?.dataset.id;

                if (!childContainer || !categoryId) return;

                const wasManuallyExpanded = this.expandedSubcategories.has(categoryId);
                const hasCheckedChild = childContainer.querySelector('input[type="checkbox"]:checked') !== null;
                const shouldBeExpanded = wasManuallyExpanded || hasCheckedChild;

                if (shouldBeExpanded) {
                    button.setAttribute('aria-expanded', 'true');
                    childContainer.style.display = 'block';
                    this.expandedSubcategories.add(categoryId);
                } else {
                    button.setAttribute('aria-expanded', 'false');
                    childContainer.style.display = 'none';
                }

                const handleClick = (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    const currentlyExpanded = button.getAttribute('aria-expanded') === 'true';

                    if (currentlyExpanded) {
                        button.setAttribute('aria-expanded', 'false');
                        childContainer.style.display = 'none';
                        this.expandedSubcategories.delete(categoryId);
                    } else {
                        button.setAttribute('aria-expanded', 'true');
                        childContainer.style.display = 'block';
                        this.expandedSubcategories.add(categoryId);
                    }
                };

                if (button._alfaClickHandler) {
                    button.removeEventListener('click', button._alfaClickHandler);
                }

                button.addEventListener('click', handleClick);
                button._alfaClickHandler = handleClick;
            });
        }
    }

    /*
       Find and initialize all module instances
       */

    const wrappers = document.querySelectorAll('.mod-alfa-filters-wrapper[data-module-id]');

    if (wrappers.length === 0) return;

    window.alfaFiltersInstances = window.alfaFiltersInstances || {};

    wrappers.forEach(wrapper => {
        // Free and Pro ship this same script under different asset names. With both
        // editions published, each copy sees BOTH wrappers; bind once per wrapper
        // or every filter change fires twice.
        if (wrapper.dataset.afInitialised) {
            return;
        }

        wrapper.dataset.afInitialised = '1';

        const moduleId = wrapper.dataset.moduleId;
        window.alfaFiltersInstances[moduleId] = new AlfaFiltersInstance(wrapper);
    });
});
