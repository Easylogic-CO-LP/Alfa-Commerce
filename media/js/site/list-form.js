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
 */

(function () {
    'use strict';

    var FORM_SELECTOR = 'form#adminForm';
    var STRIPPED = 'data-alfa-stripped';

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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initFilterForm(document);
        });
    } else {
        initFilterForm(document);
    }
})();
