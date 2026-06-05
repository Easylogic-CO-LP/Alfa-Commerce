/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * Defines Joomla.typeHasChanged: re-submits the edit form with the view's
 * `reload` task so the server re-renders fields that depend on another
 * field's value (e.g. the order-status email layout drives which position
 * editors appear). Bound via onchange on those driving controls. Because
 * the reload is a full round-trip, the active scroll position is preserved
 * across it (saved before submit, restored as the page settles).
 */
(Joomla => {
    // The layout-change reload is a full server round-trip (positions are
    // discovered server-side, so a new layout needs re-rendering). Stash the
    // scroll position before submit and restore it after the reload so the
    // admin stays where they were instead of jumping to the top. Keyed per
    // edit URL so it can't bleed across pages. (Restore strategy below.)
    const SCROLL_KEY = 'alfa.reload.scroll:' + window.location.pathname + window.location.search;

    // Take over scroll restoration from the browser so it doesn't fight ours.
    if ('scrollRestoration' in history) {
        try { history.scrollRestoration = 'manual'; } catch (e) {}
    }

    Joomla.typeHasChanged = element => {
        const url = new URL(window.location.href);
        const view = url.searchParams.get('view') || '';

        if (view === '') {
            console.error('View not found to call the controller reload');
            return;
        }

        try { sessionStorage.setItem(SCROLL_KEY, String(window.scrollY || window.pageYOffset || 0)); } catch (e) {}

        // Show loading indicator
        document.body.appendChild(document.createElement('joomla-core-loader'));

        // Set the task dynamically
        document.querySelector('input[name=task]').value = `${view}.reload`;

        // Submit the form
        element.form.submit();
    };

    // Restore after the reload. Waiting for `load` is too late — it blocks on
    // every image + TinyMCE boot, so the page sits at top and then visibly
    // jumps. Instead start at DOMContentLoaded and re-assert the scroll each
    // animation frame until the document is tall enough to actually reach the
    // saved position (editors grow the page as they boot), bounded by a short
    // timeout so it can't loop forever on an unreachable target.
    function restoreScroll() {
        let raw;
        try { raw = sessionStorage.getItem(SCROLL_KEY); } catch (e) { return; }
        if (raw === null) return;
        try { sessionStorage.removeItem(SCROLL_KEY); } catch (e) {}

        const target = parseInt(raw, 10) || 0;
        if (target <= 0) return;

        const deadline = Date.now() + 3000; // give editors time to grow the page

        const tick = () => {
            window.scrollTo(0, target);
            const maxReach = document.documentElement.scrollHeight - window.innerHeight;
            // Keep re-asserting until we've actually reached the target (page
            // grew tall enough) or the deadline passes.
            if (window.scrollY < target - 2 && maxReach >= target && Date.now() < deadline) {
                window.requestAnimationFrame(tick);
            } else if (Date.now() < deadline && maxReach < target) {
                // Page not tall enough yet — wait for it to grow.
                window.requestAnimationFrame(tick);
            }
        };

        window.requestAnimationFrame(tick);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreScroll);
    } else {
        restoreScroll();
    }
})(Joomla);
