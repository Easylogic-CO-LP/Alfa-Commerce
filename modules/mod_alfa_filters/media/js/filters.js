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

    /* ==========================================================================
       UTILITY FUNCTIONS
       ========================================================================== */

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

    const debounce = (func, wait) => {
        let timeoutId = null;
        return (...args) => {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => func.apply(this, args), wait);
        };
    };

    const parseJsonResponse = async (response) => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        return response.json();
    };

    /* ==========================================================================
       ALFA FILTERS INSTANCE CLASS
       ========================================================================== */

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
            } catch (error) {
                console.error(`[AlfaFilters:${this.moduleId}] Init failed:`, error);
            }
        }

        /* ==================================================================
            LOADING OVERLAY
        ================================================================== */

        showLoading() {
            this.loadingOverlay?.classList.add('is-active');
        }

        hideLoading() {
            this.loadingOverlay?.classList.remove('is-active');
        }

        /* ==================================================================
           RESET BUTTONS
           ================================================================== */

        initResetButtons() {
            // Global reset (in header, outside .af-form-content — bind only once)
            if (!this._globalResetBound) {
                this._globalResetBound = true;

                const resetAllBtn = qs('.af-reset-all', this.wrapper);
                resetAllBtn?.addEventListener('click', () => {
                    // Uncheck all checkboxes
                    this.filterForm.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                        cb.checked = false;
                    });

                    // Reset selects to first option
                    this.filterForm.querySelectorAll('select').forEach(sel => {
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
                });

                // Listen for form changes to update reset button visibility
                this.filterForm.addEventListener('change', () => {
                    this.updateResetVisibility();
                });
            }

            // Per-section resets (inside .af-form-content — rebind after AJAX)
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

        updateResetVisibility() {
            const toggle = (selector, hasActive) => {
                const btn = qs(selector, this.wrapper);
                btn?.classList.toggle('is-visible', hasActive);
            };

            // Category: any checked
            const hasCategory = this.filterForm.querySelector('input[name="filter[category][]"]:checked') !== null;
            toggle('.af-reset-category', hasCategory);

            // Manufacturer: any checked
            const hasManufacturer = this.filterForm.querySelector('input[name="filter[manufacturer][]"]:checked') !== null;
            toggle('.af-reset-manufacturer', hasManufacturer);

            // Price: slider moved from absolute range
            const minRange = qs('.af-range-min', this.wrapper);
            const maxRange = qs('.af-range-max', this.wrapper);
            let hasPrice = false;
            if (minRange && maxRange) {
                const currentMin = parseInt(minRange.value, 10);
                const currentMax = parseInt(maxRange.value, 10);
                hasPrice = currentMin > this.absoluteMin || currentMax < this.absoluteMax;
            }
            toggle('.af-price-reset', hasPrice);

            // Global: any section has active filters
            const hasAny = hasCategory || hasManufacturer || hasPrice;
            toggle('.af-reset-all', hasAny);
        }

        /* ==================================================================
           REINITIALIZE FORM ELEMENTS (after AJAX content replacement)
           ================================================================== */

        reinitFormElements() {
            this.initSectionResetButtons();
            this.initPriceSlider();
            this.initDiscountFilters();
            this.initSubcategoryTogglers();
            this.updateResetVisibility();
        }

        /* ==================================================================
           FETCH AVAILABLE FILTERS (AJAX)
           ================================================================== */

        async fetchAvailableFilters(queryString, { resetPrice = false } = {}) {
            const filtersActionUrl = this.filterForm?.dataset?.filtersAction;
            if (!filtersActionUrl) return;

            const separator = filtersActionUrl.includes('?') ? '&' : '?';
            const url = `${filtersActionUrl}${separator}${queryString}`;

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Dynamic-Filtering': this.config?.dynamicFiltering ? '1' : '0',
                        'X-Module-ID': this.moduleId
                    }
                });

                const data = await parseJsonResponse(response);

                if (!data.success) {
                    throw new Error(data.message || 'Unknown server error');
                }

                const container = qs('.af-form-content', this.wrapper);
                if (container && data.data?.html) {
                    container.innerHTML = data.data.html;
                    this.reinitFormElements();
                }

                // Update absoluteMin/Max from server response for submit handler
                if (data.data?.priceRange) {
                    this.absoluteMin = data.data.priceRange.min;
                    this.absoluteMax = data.data.priceRange.max;
                }

            } catch (error) {
                console.error(`[AlfaFilters:${this.moduleId}] Failed to fetch filters:`, error);
            }
        }

        /* ==================================================================
           FETCH FILTERED ITEMS (AJAX — dynamic mode only)
           ================================================================== */

        async fetchFilteredItems(queryString) {
            const itemsActionUrl = this.filterForm?.dataset?.action;
            if (!itemsActionUrl) return;

            const componentSelector = "#alfa-app[data-view='items']";
            const url = `${itemsActionUrl}&${queryString}`;

            try {
                const response = await fetch(url, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newItems = qs(componentSelector, doc);
                const oldItems = qs(componentSelector);

                if (newItems && oldItems) {
                    oldItems.innerHTML = newItems.innerHTML;
                }

                // Update URL
                const currentParams = new URLSearchParams(location.search);
                const newParams = new URLSearchParams(queryString);

                const allFormKeys = new Set();
                this.filterForm.querySelectorAll('[name]').forEach(input => {
                    allFormKeys.add(input.name);
                });

                for (const key of allFormKeys) {
                    currentParams.delete(key);
                }

                for (const [key, value] of newParams) {
                    if (value) currentParams.append(key, value);
                }

                const mergedQuery = currentParams.toString();
                const finalUrl = mergedQuery
                    ? `${location.pathname}?${mergedQuery}`
                    : location.pathname;

                history.replaceState(null, '', finalUrl);

            } catch (error) {
                console.error(`[AlfaFilters:${this.moduleId}] Failed to fetch items:`, error);
            }
        }

        /* ==================================================================
           FILTER REFRESH (handles both dynamic and non-dynamic modes)
           ================================================================== */

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

            const handleFormChange = debounce(() => {
                const isPriceChange = this._isPriceChange;
                const isProductSetChange = this._isProductSetChange;
                this._isPriceChange = false;
                this._isProductSetChange = false;

                // In non-dynamic mode, price changes don't trigger a refresh
                if (isPriceChange && !isProductSetChange && !this.config?.dynamicFiltering) {
                    return;
                }

                const resetPrice = isProductSetChange;
                const formData = new FormData(this.filterForm);

                // Strip price params when product set changes (server returns full range)
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

            }, 500);

            this.filterForm.addEventListener('change', handleFormChange);
        }

        /* ==================================================================
           OFF-CANVAS PANEL
           ================================================================== */

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

        /* ==================================================================
           RESPONSIVE BEHAVIOR
           ================================================================== */

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

        /* ==================================================================
           PRICE RANGE SLIDER
           ================================================================== */

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

            // When min === max (single price point), widen range inputs by 1 unit
            // so the browser can position the max thumb at the end of the track.
            // The fake +1 is visual only — number inputs always show the real value.
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

            const triggerPriceChange = () => {
                this._isPriceChange = true;
                this.filterForm.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const resetSlider = () => {
                minRange.value = this.absoluteMin;
                maxRange.value = isSinglePrice ? this.absoluteMin + 1 : this.absoluteMax;
                minInput.value = this.absoluteMin;
                maxInput.value = this.absoluteMax;
                updateHighlight(this.absoluteMin, this.absoluteMax);
                triggerPriceChange();
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

        /* ==================================================================
           DISCOUNT FILTERS
           ================================================================== */

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

        /* ==================================================================
           SUBCATEGORY TOGGLERS
           ================================================================== */

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

    /* ==========================================================================
       FIND AND INITIALIZE ALL MODULE INSTANCES
       ========================================================================== */

    const wrappers = document.querySelectorAll('.mod-alfa-filters-wrapper[data-module-id]');

    if (wrappers.length === 0) return;

    window.alfaFiltersInstances = window.alfaFiltersInstances || {};

    wrappers.forEach(wrapper => {
        const moduleId = wrapper.dataset.moduleId;
        window.alfaFiltersInstances[moduleId] = new AlfaFiltersInstance(wrapper);
    });
});
