/**
 * @package    Alfa Commerce
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * The listing form submits only the state it actually holds.
 *
 * Searchtools renders every declared filter as a control, empty or not, so a plain submit
 * serialises `filter[price_min]=&filter[price_max]=&filter[stock]=...` alongside the one field
 * the shopper touched. Those are crawlable URLs the site publishes about ITSELF, and each
 * combination is a separate address for the same result set: the same index-bloat concern
 * that gave UrlListModel its filter-key whitelist, arriving through the form instead of
 * through pagination. So empty controls are disabled for the duration of the submit.
 *
 * BOUND TO A LIFECYCLE, NOT TO A MOMENT. This behaviour used to be an inline script that ran
 * once on DOMContentLoaded and captured the form node it found. The filters module re-renders
 * the listing by replacing `#alfa-app`'s innerHTML, which destroys that node: from the first
 * dynamic filter apply onward the replacement form had no override at all, and every search
 * submitted from it published the six empty parameters again. `joomla:updated` is the CMS's
 * own signal for a replaced subtree and the module already dispatches it immediately after the
 * swap, so initialisation follows the DOM rather than the page load.
 *
 * IDEMPOTENT, AND KEYED ON THE NODE. Installation is remembered in a WeakSet rather than in a
 * data-attribute. An attribute would also work against today's swap, because the markup comes
 * from a fresh server render and the server never emits such an attribute, but it ties that to
 * remaining true. A WeakSet cannot be serialised into HTML at all, so it stays correct if
 * anything ever round-trips this subtree through its own innerHTML, where an attribute would
 * come back set on a node whose listeners did not.
 *
 * THE RESULTS ARE BROUGHT TO THE SHOPPER (2.2.0, design 2.20; 2.2.2). revealResults() scrolls the results region
 * (`[data-results]`, the pagination and the list) so that its top sits right under the page's sticky header, every
 * time a new result set comes in, wherever the region was, instantly under prefers-reduced-motion and smoothly
 * otherwise; then it gives the region the focus without scrolling again, so keyboard and screen-reader users land on the
 * results and hear the region's name, the pagination's count. The header's height is the template's
 * --alfa-scroll-offset when the template sets it; when it does not, it is measured (headerOffset()) before the scroll and
 * again where the scroll ends, and the place corrected once. It runs once for each new result set: after the filters
 * module's swap (`alfa:results:updated`), and on a page the server marked (`data-results-reveal`: a later page reached
 * by its link, a submitted filter), once its layout is final (the window's load), unless the shopper came back to that
 * page by a reload or the back button. Never on `joomla:updated` alone or on a card's reload: a variant or a quantity
 * changed in a card is the same results, and moves nothing.
 */

(function () {
    'use strict';

    var FORM_SELECTOR = 'form#adminForm';
    var STRIPPED = 'data-alfa-stripped';
    var REGION_SELECTOR = '[data-results]';
    var MARKED_SELECTOR = '[data-results][data-results-reveal]';

    /** Forms whose behaviour is already installed. Entries die with their nodes. */
    var installed = new WeakSet();

    /**
     * Install the submit behaviour on one listing form.
     *
     * @param {HTMLFormElement} form
     */
    function install(form) {
        if (installed.has(form)) {
            return;
        }

        installed.add(form);

        // Override both programmatic submit() calls and native form submissions
        // so empty fields are stripped from the URL in both cases.
        var nativeSubmit = HTMLFormElement.prototype.submit;

        // A CHANGED KEYWORD IS A NEW SEARCH, and a new search means relevance order and
        // page one. Searchtools' hidden ordering/limitstart fields would otherwise ride
        // along and silently pin the previous sort (the engine's ranking survives only
        // when no explicit ordering travels) and the previous page. "Changed" is observed
        // against the value the page RENDERED (a fact, never an inference); re-submitting
        // the same keyword keeps the shopper's chosen sort untouched.
        //
        // Read per install, not once per page: after a dynamic re-render the rendered
        // keyword and ordering are the NEW form's, which is exactly what "what the page
        // rendered" has to mean for the form the shopper is now looking at.
        var searchField = form.querySelector('[name="filter[search]"]');
        var renderedKeyword = searchField ? searchField.value.trim() : '';
        var orderField = form.querySelector('[name="list[fullordering]"]');
        var renderedOrdering = orderField ? orderField.value : '';

        var stripEmptyFields = function () {
            form.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (field.name && field.value.trim() === '') {
                    field.disabled = true;
                    field.setAttribute(STRIPPED, '');
                }
            });

            if (searchField && searchField.value.trim() !== renderedKeyword) {
                form.querySelectorAll('[name="limitstart"]').forEach(function (field) {
                    field.disabled = true;
                    field.setAttribute(STRIPPED, '');
                });

                // The ordering is stripped only when it still says what the page rendered
                // - a sort the shopper JUST picked is the submit's whole point and must
                // travel, edited keyword or not.
                form.querySelectorAll('[name="list[fullordering]"]').forEach(function (field) {
                    if (field.value === renderedOrdering) {
                        field.disabled = true;
                        field.setAttribute(STRIPPED, '');
                    }
                });
            }
        };

        form.submit = function () {
            stripEmptyFields();
            nativeSubmit.call(form);
        };

        form.addEventListener('submit', function () {
            stripEmptyFields();
        });
    }

    /**
     * Install the behaviour on every listing form inside a root.
     *
     * @param {Document|Element} [root=document] The subtree to scan. The root itself counts.
     */
    function initFilterForm(root) {
        var scope = root || document;

        if (scope.matches && scope.matches(FORM_SELECTOR)) {
            install(scope);
        }

        if (scope.querySelectorAll) {
            scope.querySelectorAll(FORM_SELECTOR).forEach(install);
        }
    }

    /**
     * The height the page's sticky header covers at the viewport's top: the template's --alfa-scroll-offset (the
     * region's scroll-margin-top) when the template sets it; otherwise measured now, from the boxes of the page's
     * elements outside the region: those whose position is sticky or fixed, that reach into the viewport's top 8 px and
     * that are no taller than half the viewport (a cookie wall's backdrop or an open off-canvas menu is a layer, not a
     * header): the lowest bottom among them. The boxes, not a hit test at the top edge: right after a smooth scroll a
     * hit test there can find nothing but the page itself.
     *
     * @param {Element} region The results region.
     *
     * @return {number} Pixels from the viewport's top.
     */
    function headerOffset(region) {
        var style = window.getComputedStyle(region);

        if (style.getPropertyValue('--alfa-scroll-offset').trim() !== '') {
            return parseFloat(style.scrollMarginTop) || 0;
        }

        var band = 8;
        var half = window.innerHeight / 2;
        var bottom = 0;
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_ELEMENT, {
            acceptNode: function (node) {
                return node === region ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            },
        });

        for (var node = walker.nextNode(); node; node = walker.nextNode()) {
            if (node.contains(region)) {
                continue;
            }

            var box = node.getBoundingClientRect();

            if (box.top > band || box.bottom <= bottom || box.height > half || box.width === 0) {
                continue;
            }

            var position = window.getComputedStyle(node).position;

            if (position === 'sticky' || position === 'fixed') {
                bottom = box.bottom;
            }
        }

        return bottom;
    }

    /**
     * Call back once the page's scroll has come to rest: at the scroll's end, or soon after when no scroll happens
     * (already in place) or the browser has no scrollend event; whichever comes first, once, the other then dropped.
     * Not at all when the shopper takes over meanwhile (a wheel, a touch or a key): the place is theirs from then on.
     *
     * @param {Function} done What to run.
     */
    function whenScrollEnds(done) {
        var timer = 0;
        var takeovers = ['wheel', 'touchstart', 'keydown'];
        var stop = function () {
            window.clearTimeout(timer);
            window.removeEventListener('scrollend', finish);
            takeovers.forEach(function (name) {
                window.removeEventListener(name, stop, true);
            });
        };
        var finish = function () {
            stop();
            done();
        };

        window.addEventListener('scrollend', finish);
        takeovers.forEach(function (name) {
            window.addEventListener(name, stop, { capture: true, passive: true });
        });
        timer = window.setTimeout(finish, 900);
    }

    /**
     * Bring the results region's top under the page's sticky header and focus it; its name (the pagination's count)
     * is what the focus announces. Where the scroll ends the header is measured again and the place corrected once: a
     * header that becomes sticky or fixed only once the page has scrolled (a bar under a static top bar, a header a
     * template's script fixes past a threshold) covers the region's top only then.
     *
     * @param {Element|null} region The results region.
     */
    function revealResults(region) {
        if (!region) {
            return;
        }

        var behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth';

        window.scrollTo({ top: window.scrollY + region.getBoundingClientRect().top - headerOffset(region), behavior: behavior });
        region.focus({ preventScroll: true });

        whenScrollEnds(function () {
            var gap = region.getBoundingClientRect().top - headerOffset(region);

            if (Math.abs(gap) > 1) {
                window.scrollTo({ top: window.scrollY + gap, behavior: 'instant' });
            }
        });
    }

    /**
     * Whether the page was loaded again by a reload or a back or forward move: the shopper's own scroll is restored
     * then, and is kept.
     *
     * @return {boolean}
     */
    function returnedToPage() {
        var entries = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType('navigation') : [];
        var type = entries.length > 0 ? entries[0].type : '';

        return type === 'reload' || type === 'back_forward';
    }

    // Back/forward cache restores the DOM as submitted, with the stripped fields still
    // disabled. Re-enable exactly the ones this script disabled, nothing else. Registered
    // once, on the document rather than on a form, so it survives every re-render and
    // cannot stack a second copy of itself per initialisation.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('[' + STRIPPED + ']').forEach(function (field) {
            field.disabled = false;
            field.removeAttribute(STRIPPED);
        });
    });

    // The replaced subtree is the event's target, so a re-render initialises only what it
    // actually changed. Anything else dispatching joomla:updated simply finds no form.
    document.addEventListener('joomla:updated', function (event) {
        initFilterForm(event.target);
    });

    // A new result set swapped in: the region inside the replaced container, once per swap.
    document.addEventListener('alfa:results:updated', function (event) {
        revealResults(event.target && event.target.querySelector ? event.target.querySelector(REGION_SELECTOR) : null);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initFilterForm(document);
        });
    } else {
        initFilterForm(document);
    }

    // A page the server marked is revealed once its layout is final: an image above the region (a subcategory tile)
    // that arrives after the scroll began would leave the region short of the top. Not when the shopper came back to
    // the page by a reload or the back button (2.2.2): the scroll the browser restores is theirs.
    if (!returnedToPage()) {
        if (document.readyState === 'complete') {
            revealResults(document.querySelector(MARKED_SELECTOR));
        } else {
            window.addEventListener('load', function () {
                revealResults(document.querySelector(MARKED_SELECTOR));
            }, { once: true });
        }
    }
})();
