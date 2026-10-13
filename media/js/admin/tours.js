/**
 * Alfa Admin: Guided Tours grammar supplement
 * plg_system_guidedtours renders every step (Shepherd). Its "press" steps (
 * interactive types submit/button) offer NO Next: the learner must click the
 * target, and when the target is Save & Close the only way out is the X. For OUR
 * tours only (ids published by the Dispatcher under `com_alfa.tours`) this adds:
 *
 *   - Next on every press step: performs the target click for the learner, so the
 *     engine's own listener carries the tour on (page reload or `tour.next()`).
 *   - "Finish without saving" on a Save step: leaves the form through the toolbar
 *     Cancel and lands on the tour's closing step; no record written.
 *
 * No markup is invented: the buttons are clones of the engine's own footer button,
 * so classes and markup stay the engine's. Deliberate, documented coupling to the
 * engine: `sessionStorage.currentStepId` (its own continuity key, exactly what its
 * submit listener writes before a page change), `data-shepherd-step-id`,
 * `.shepherd-target` and `.shepherd-footer`.
 *
 * @package  Alfa Commerce
 * @since  2.0.0
 */
(function () {
    'use strict';

    /** Joomla.getOptions key the Dispatcher publishes our tour ids under. */
    var OPTIONS_KEY = 'com_alfa.tours';

    /** The engine/Shepherd DOM contract: every selector this script touches. */
    var SEL = {
        step:       '.shepherd-element',                          // one node per step, appended to body
        active:     '.shepherd-element.shepherd-enabled:not([hidden])',
        content:    '.shepherd-content',
        footer:     '.shepherd-footer',
        button:     '.shepherd-button',
        primary:    '.shepherd-button-primary',                   // Start / Next / Complete
        target:     '.shepherd-target',                           // the element the step is attached to
        saveLike:   '.button-save, .button-apply, .button-save-new, .button-save-copy',
        cancel:     '#toolbar .button-cancel',
        cancelIcon: '.shepherd-cancel-icon'
    };

    /** Marker: the step id this element was already augmented for. */
    var MARK = 'data-alfa-tour-step';

    /** Marks our own close button so a re-scan never adds a second one. */
    var CLOSE_ATTR = 'data-alfa-tour-close';

    /**
     * Is the running tour one of ours? The engine keeps the numeric id in session
     * storage; the Dispatcher publishes the published com_alfa.* ids.
     */
    function ownTour() {
        var opts    = Joomla.getOptions(OPTIONS_KEY) || {};
        var ids     = (opts.ids || []).map(String);
        var current = sessionStorage.getItem('tourId');

        return current !== null && ids.indexOf(String(current)) !== -1;
    }

    /** Clone the engine's footer button so ours is styled exactly like its siblings. */
    function makeButton(footer, text, primary) {
        var proto  = footer.querySelector(SEL.button);
        var button = proto ? proto.cloneNode(false) : document.createElement('button');

        button.type      = 'button';
        button.className = 'shepherd-button btn ' + (primary
            ? 'btn-primary shepherd-button-primary'
            : 'btn-secondary shepherd-button-secondary');
        button.textContent = text;
        button.removeAttribute('disabled');

        return button;
    }

    /**
     * Leave the form without saving and continue at the tour's next step, the same
     * continuity the engine's submit listener writes before Save & Close reloads.
     */
    function finishWithoutSaving(stepEl, stepId) {
        var cancel = document.querySelector(SEL.cancel);

        if (!cancel) {
            var icon = stepEl.querySelector(SEL.cancelIcon);
            if (icon) { icon.click(); }
            return;
        }

        sessionStorage.setItem('currentStepId', String(Number(stepId) + 1));
        cancel.click();
    }

    /**
     * End the tour here and leave the page exactly as it is: no save, no cancel,
     * no navigation. Delegates to the engine's own cancel control so the tour is
     * closed the way the engine expects (state recorded, listeners cleaned up).
     */
    function addClose(stepEl, footer) {
        if (footer.querySelector('[' + CLOSE_ATTR + ']')) { return; }

        var close = makeButton(footer, Joomla.Text._('COM_ALFA_TOUR_CLOSE', 'Close tutorial'), false);

        close.setAttribute(CLOSE_ATTR, '');
        close.addEventListener('click', function () {
            var cancel = stepEl.querySelector(SEL.cancelIcon);

            if (cancel) { cancel.click(); }
        });

        // First in the footer: an exit belongs at the far end from the action.
        footer.insertBefore(close, footer.firstChild);
    }

    /**
     * Last resort for a step whose target does not exist on this page: hand the
     * learner the engine's own continuity key (the next step index) and reload:
     * how the engine itself resumes a tour after a page change.
     */
    function addSkip(stepEl, footer, stepId) {
        footer = footer || ensureFooter(stepEl);

        var skip = makeButton(footer, Joomla.Text._('COM_ALFA_TOUR_SKIP', 'Skip this step'), true);

        skip.addEventListener('click', function () {
            sessionStorage.setItem('currentStepId', String(Number(stepId) + 1));
            window.location.reload();
        });

        footer.appendChild(skip);
    }

    /** Shepherd renders no footer for a step with no buttons; give it one. */
    function ensureFooter(stepEl) {
        var footer = document.createElement('footer');

        footer.className = 'shepherd-footer';
        (stepEl.querySelector(SEL.content) || stepEl).appendChild(footer);

        return footer;
    }

    /**
     * Add what the engine leaves out on a press step. Idempotent per step, but the
     * step is only MARKED once the work is done: Shepherd attaches the target (the
     * `.shepherd-target` class) after an async beforeShow, so an early pass must stay
     * retryable; every later mutation of the step node calls back here.
     */
    function augment(stepEl) {
        var stepId = stepEl.getAttribute('data-shepherd-step-id');

        if (stepId === null || stepEl.getAttribute(MARK) === stepId || !ownTour()) { return; }

        var footer = stepEl.querySelector(SEL.footer);

        // Leaving the tutorial is its own decision, separate from whatever the step
        // is teaching: the engine only offers the X in the corner, which reads as
        // dismissing a dialog rather than as ending the tour on purpose.
        addClose(stepEl, footer = footer || ensureFooter(stepEl));

        var primary = footer.querySelector(SEL.primary);

        // Start, Next or Complete already there => the engine's grammar is complete,
        // except that it disables Next until a required field is filled. A tour is a
        // tutorial, not a form: the learner may always read on and skip.
        if (primary) {
            stepEl.setAttribute(MARK, stepId);
            primary.removeAttribute('disabled');
            primary.classList.remove('disabled');

            return;
        }

        var target = document.querySelector(SEL.target);

        // Shepherd falls back to <body> when the step's selector matched nothing, so a
        // <body> "target" means the step is floating: pressing it would do nothing. Such
        // a step is a broken tour, and the learner still needs a way past it.
        if (target === document.body) {
            stepEl.setAttribute(MARK, stepId);
            addSkip(stepEl, footer, stepId);

            return;
        }

        // Shepherd marks its target AFTER the step node lands, so a first pass can be
        // too early: keep retrying on a short timer instead of waiting for a mutation
        // that may never come. A centred step has no target at all and simply runs out.
        if (!target) {
            retry(stepEl);

            return;
        }

        stepEl.setAttribute(MARK, stepId);

        // Shepherd renders no footer at all for a step without buttons (the engine
        // drops Back on the first step after a page change), so give it one.
        footer = footer || ensureFooter(stepEl);

        var next = makeButton(footer, Joomla.Text._('PLG_SYSTEM_GUIDEDTOURS_NEXT', 'Next'), true);

        next.addEventListener('click', function () {
            // Write the continuity key ourselves before pressing. The engine binds its
            // own "remember the next step" listener with querySelector() over the WHOLE
            // fallback list (first in document order) while Shepherd highlights the
            // first selector in the list that matches. When those differ, pressing the
            // highlighted element would reload straight back into the same step.
            sessionStorage.setItem('currentStepId', String(Number(stepId) + 1));
            target.click();
        });

        footer.appendChild(next);

        if (target.matches(SEL.saveLike)) {
            var finish = makeButton(footer, Joomla.Text._('COM_ALFA_TOUR_FINISH', 'Finish without saving'), false);
            finish.addEventListener('click', function () { finishWithoutSaving(stepEl, stepId); });
            footer.insertBefore(finish, next);
        }
    }

    var scheduled = false;

    /** How long one burst keeps looking for a step's target: 40 tries, 50ms apart. */
    var RETRY_TRIES = 40;
    var RETRY_MS    = 50;
    var RETRY_ATTR  = MARK + '-retry';

    /**
     * Open the tab a step is pointing into.
     *
     * An edit screen keeps most of itself in closed tabs. When a step targets
     * something in one of them the engine still marks the element (so
     * `.shepherd-target` exists) but it measures 0x0 and the highlight lands on
     * nothing: the learner reads about the default category, or the image drop
     * zone, and sees an empty box. Revealing what the step is talking about is
     * the tour doing its job, not clicking on the learner's behalf, so the tab
     * that holds the target is activated through its own button.
     *
     * @return {boolean} True when a tab was opened (the step needs repositioning).
     */
    function revealTarget() {
        var target = document.querySelector('.shepherd-target');

        if (!target || target.getBoundingClientRect().height > 0) {
            return false;
        }

        var panel = target.closest('joomla-tab-element');

        if (!panel || !panel.id) {
            return false;
        }

        var button = document.querySelector('joomla-tab button[aria-controls="' + panel.id + '"]');

        if (!button || button.getAttribute('aria-selected') === 'true') {
            return false;
        }

        button.click();

        return true;
    }

    function scan() {
        scheduled = false;

        // Before anything else: if the step points into a closed tab, open it;
        // otherwise the highlight is drawn around a collapsed element.
        if (revealTarget()) {
            window.setTimeout(schedule, 60);
        }

        // A mutation on the step (Shepherd showing it, re-attaching, re-positioning)
        // re-arms the burst: the target may be about to appear.
        document.querySelectorAll(SEL.active).forEach(function (stepEl) {
            stepEl.removeAttribute(RETRY_ATTR);
            augment(stepEl);
        });
    }

    /** Re-run augment() on this step shortly, while its target may still be pending. */
    function retry(stepEl) {
        var left = Number(stepEl.getAttribute(RETRY_ATTR) || RETRY_TRIES);

        if (left <= 0) { return; }
        stepEl.setAttribute(RETRY_ATTR, String(left - 1));

        window.setTimeout(function () { augment(stepEl); }, RETRY_MS);
    }

    /**
     * Coalesce a burst of mutations into one scan. A timer, NOT requestAnimationFrame:
     * rAF is suspended while the tab paints nothing (backgrounded, occluded), which
     * would leave a tour the learner comes back to without its buttons.
     */
    function schedule() {
        if (scheduled) { return; }
        scheduled = true;
        window.setTimeout(scan, 0);
    }

    /** Shepherd toggles `shepherd-enabled` / `hidden` per step: watch each step node. */
    var stepObserver = new MutationObserver(schedule);

    function watchStep(node) {
        if (node.getAttribute(MARK + '-watched') !== null) { return; }
        node.setAttribute(MARK + '-watched', '');
        stepObserver.observe(node, { attributes: true, attributeFilter: ['class', 'hidden'] });
        schedule();
    }

    new MutationObserver(function (records) {
        records.forEach(function (record) {
            record.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) { return; }
                if (node.matches(SEL.step)) { watchStep(node); return; }
                node.querySelectorAll(SEL.step).forEach(watchStep);
            });
        });
    }).observe(document.body, { childList: true, subtree: true });

    document.querySelectorAll(SEL.step).forEach(watchStep);
})();
