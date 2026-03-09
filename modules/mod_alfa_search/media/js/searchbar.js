/**
 * Alfa Search Module
 * Professional search implementation with class structure
 *
 * @package     Alfa.Module
 * @subpackage  mod_alfa_search
 * @since       1.0.0
 */

(function() {
    'use strict';

    /**
     * Search Module Class
     */
    class AlfaSearchModule {
        /**
         * Constructor
         */
        constructor() {
            // Configuration
            this.config = {
                typingDelay: 300,
                focusOutDelay: 150,
                maxHeightOffset: 20
            };

            // DOM elements
            this.elements = {
                searchInput: null,
                searchPopup: null,
                searchLoading: null,
                form: null
            };

            // State
            this.state = {
                typingTimer: null,
                abortController: null,
                isPopupFocused: false,
                minChars: 2,
                ajaxUrl: ''
            };
        }

        /**
         * Initialize the module
         *
         * @returns {boolean} Success status
         */
        init() {
            try {
                // Get DOM elements
                if (!this.getDOMElements()) {
                    this.logError('Required DOM elements not found');
                    return false;
                }

                // Get configuration from form attributes
                this.getConfiguration();

                // Set up event listeners
                this.setupEventListeners();

                this.log('Search module initialized successfully');
                return true;

            } catch (error) {
                this.logError('Initialization failed', error);
                return false;
            }
        }

        /**
         * Get required DOM elements
         *
         * @returns {boolean} Success status
         */
        getDOMElements() {
            this.elements.searchInput = document.querySelector('#search-container-input');
            this.elements.searchPopup = document.querySelector('#search-container-popup');
            this.elements.searchLoading = document.querySelector('#search-container-loading-img');

            if (!this.elements.searchInput || !this.elements.searchPopup) {
                return false;
            }

            this.elements.form = this.elements.searchInput.closest('form');
            return !!this.elements.form;
        }

        /**
         * Get configuration from form attributes
         */
        getConfiguration() {
            this.state.minChars = parseInt(
                this.elements.form.getAttribute('data-minchars') || '2',
                10
            );
            this.state.ajaxUrl = this.elements.form.getAttribute('data-action') || '';
        }

        /**
         * Set up all event listeners
         */
        setupEventListeners() {
            // Focus events
            this.elements.searchInput.addEventListener('focus', () => this.handleFocusIn());
            this.elements.form.addEventListener('focusout', () => this.handleFocusOut());
            this.elements.searchPopup.addEventListener('focusout', () => this.handleFocusOut());

            // Input event
            this.elements.searchInput.addEventListener('input', () => this.handleInput());

            // Click outside to close
            document.addEventListener('click', (e) => this.handleOutsideClick(e));

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => this.handleKeyboard(e));
        }

        /**
         * Handle focus in event
         */
        handleFocusIn() {
            this.elements.searchPopup.style.maxHeight = this.calculateMaxHeight() + 'px';
            this.state.isPopupFocused = true;

            // Show popup if it has content
            if (this.elements.searchPopup.innerHTML.trim()) {
                this.elements.searchPopup.classList.add('active');
            }
        }

        /**
         * Handle focus out event
         */
        handleFocusOut() {
            setTimeout(() => {
                if (!this.state.isPopupFocused) {
                    this.elements.searchPopup.classList.remove('active');
                }
            }, this.config.focusOutDelay);
        }

        /**
         * Handle outside click
         *
         * @param {Event} event Click event
         */
        handleOutsideClick(event) {
            const isInsideSearch = event.target.closest(
                '#search-container-input, #search-container-popup'
            );

            if (!isInsideSearch) {
                this.state.isPopupFocused = false;
                this.elements.searchPopup.classList.remove('active');
            }
        }

        /**
         * Handle input event with debouncing
         */
        handleInput() {
            clearTimeout(this.state.typingTimer);

            const query = this.elements.searchInput.value.trim();

            // Clear results if query too short
            if (query.length < this.state.minChars) {
                this.clearResults();
                return;
            }

            // Validate max length
            if (query.length > 100) {
                this.showError('Search query is too long');
                return;
            }

            // Debounce - wait for user to stop typing
            this.state.typingTimer = setTimeout(() => {
                this.performSearch(query);
            }, this.config.typingDelay);
        }

        /**
         * Handle keyboard shortcuts
         *
         * @param {KeyboardEvent} event Keyboard event
         */
        handleKeyboard(event) {
            // "/" key to focus search
            if (event.key === '/' && !event.ctrlKey && !event.altKey && !event.metaKey) {
                const activeElement = document.activeElement;

                // Don't trigger if already in an input
                if (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA') {
                    return;
                }

                event.preventDefault();
                this.elements.searchInput.focus();
            }

            // "Escape" key to close
            if (event.key === 'Escape') {
                if (this.isSearchFocused()) {
                    event.preventDefault();
                    this.closeSearch();
                }
            }
        }

        /**
         * Perform search request
         *
         * @param {string} query Search query
         */
        async performSearch(query) {
            // Cancel previous request
            if (this.state.abortController) {
                this.state.abortController.abort();
            }
            this.state.abortController = new AbortController();

            // Show loading indicator
            this.showLoading();

            try {
                // Prepare request
                const params = new URLSearchParams();
                params.append('query', query);

                // Make request
                const response = await fetch(this.state.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: params,
                    signal: this.state.abortController.signal
                });

                // Check response status
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                // Parse JSON response
                const data = await response.json();

                // Handle response
                if (data.error || data.success === false) {
                    this.showError(data.message || 'Search error occurred');
                } else {
                    this.displayResults(data.data);
                }

            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.logError('Search request failed', error);
                    this.showError('Network error. Please try again.');
                }
            } finally {
                this.hideLoading();
            }
        }

        /**
         * Display search results
         *
         * @param {Object} data Response data
         */
        displayResults(data) {
            if (!data || !data.html) {
                this.clearResults();
                return;
            }

            this.elements.searchPopup.innerHTML = data.html;
            this.elements.searchPopup.classList.add('active');

            this.log('Results displayed', { count: data.count || 0 });
        }

        /**
         * Show error message
         *
         * @param {string} message Error message
         */
        showError(message) {
            const errorHtml = `
                <div class="search-error">
                    <span class="search-error-icon">⚠</span>
                    <span class="search-error-text">${this.escapeHtml(message)}</span>
                </div>
            `;

            this.elements.searchPopup.innerHTML = errorHtml;
            this.elements.searchPopup.classList.add('active');
        }

        /**
         * Clear search results
         */
        clearResults() {
            this.elements.searchPopup.innerHTML = '';
            this.elements.searchPopup.classList.remove('active');
        }

        /**
         * Show loading indicator
         */
        showLoading() {
            if (this.elements.searchLoading) {
                this.elements.searchLoading.classList.add('show');
            }
        }

        /**
         * Hide loading indicator
         */
        hideLoading() {
            if (this.elements.searchLoading) {
                this.elements.searchLoading.classList.remove('show');
            }
        }

        /**
         * Close search popup
         */
        closeSearch() {
            this.elements.searchInput.blur();
            this.elements.searchPopup.classList.remove('active');
            this.state.isPopupFocused = false;
        }

        /**
         * Check if search is focused
         *
         * @returns {boolean} True if search is focused
         */
        isSearchFocused() {
            const activeElement = document.activeElement;
            return activeElement === this.elements.searchInput
                || this.elements.searchPopup.contains(activeElement);
        }

        /**
         * Calculate maximum height for popup
         *
         * @returns {number} Maximum height in pixels
         */
        calculateMaxHeight() {
            try {
                const maxAvailableHeight = Math.min(
                    window.innerHeight,
                    document.body.scrollHeight
                );

                const inputRect = this.elements.searchInput.getBoundingClientRect();
                const inputTop = inputRect.top + window.scrollY;

                return maxAvailableHeight
                    - inputTop
                    - this.elements.searchInput.offsetHeight
                    - this.config.maxHeightOffset;

            } catch (error) {
                this.logError('Error calculating max height', error);
                return 400; // Fallback value
            }
        }

        /**
         * Escape HTML to prevent XSS
         *
         * @param {string} text Text to escape
         * @returns {string} Escaped text
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Log message (for debugging)
         *
         * @param {string} message Log message
         * @param {Object} data Additional data
         */
        log(message, data = null) {
            if (console && console.log) {
                const logMessage = '[AlfaSearch] ' + message;
                data ? console.log(logMessage, data) : console.log(logMessage);
            }
        }

        /**
         * Log error
         *
         * @param {string} message Error message
         * @param {Error|Object} error Error object
         */
        logError(message, error = null) {
            if (console && console.error) {
                const errorMessage = '[AlfaSearch ERROR] ' + message;
                error ? console.error(errorMessage, error) : console.error(errorMessage);
            }
        }
    }

    /**
     * Initialize module when DOM is ready
     */
    function initialize() {
        const searchModule = new AlfaSearchModule();
        if (searchModule.init()) {
            // Expose to the window for debugging (optional)
            window.alfaSearch = searchModule;
        }
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

})();