/**
 * Add to cart: posts the buy form, then mounts the outcome card the server rendered
 * (the `cart.added` layout) and announces `alfaProductAdded`. This script builds no
 * markup: the card, its words and its close controls come from the layout; here it is
 * only inserted, timed and removed, through data attributes. A listener that presents
 * the add itself (event.preventDefault() on `alfaProductAdded`, e.g. a cart module
 * opening its drawer) keeps the card away.
 *
 * The route comes from the layout: `cart.add` draws it on the button as data-add-url,
 * the add task in the language of the request that drew the button, so a button a
 * later request brings in (a lazily fetched tab, a re-drawn card) carries its own.
 * This script builds no URL; a button without that route, or whose route resolves to
 * another origin, sends nothing: the buy form and the session token stay on this site.
 *
 * Nor is anything sent that could be answered by another task than the cart's add. The
 * component, the task and the controller a request names decide what answers it, and a
 * posted field overrides the route, so both are judged before the request: the route's
 * query must name the add task once, no other component and no controller, and no
 * posted field (every control of the buy form, those attached to it by a form
 * attribute included) may name another. Names are read as the server registers them.
 * The script refuses rather than repairs: the retry state, nothing sent.
 *
 * A route's path can select a component as well, and no query shows it. So once both
 * are judged the request names this component and the add task itself: a posted value
 * outranks a routed one, and whatever the path selects, the cart's add is what answers.
 *
 * Button states: data-state="adding" while the request runs, "added" briefly after a
 * success, "retry" for a while when the add was not sent or got no answer from the
 * cart (the network failed, the request was redirected, the reply was not declared as
 * JSON or was not the cart's JSON, or a refusal came back without its card, as a
 * rejected security token does); the labels come from the button's data-label-adding /
 * data-label-added / data-label-retry. A click in the retry state sends a new request.
 * The script never re-sends by itself: a reply that was not the cart's may follow an
 * add that completed.
 */
(function () {
    'use strict';

    const CARD = '[data-cart-added]';
    const CLOSE = '[data-action="cart-added-close"]';
    const ADDED_STATE_MS = 1500;
    const RETRY_STATE_MS = 4000;
    const LEAVE_FALLBACK_MS = 400;
    const RESUME_MIN_MS = 1500;

    // The request parameters that choose which component and which task answer, and the
    // only values the add may carry under them (the task compared in lower case).
    const ROUTING_NAMES = ['option', 'task', 'controller'];
    const ADD_OPTION = 'com_alfa';
    const ADD_TASK = 'cart.addtocart';

    // The add task as the request itself names it.
    const POSTED_TASK = 'cart.addToCart';

    let closeTimer = 0;
    let closeAt = 0;
    let remaining = 0;

    // Per button: the pending return to idle, and the title a retry label replaced.
    const idleTimers = new WeakMap();
    const keptTitles = new WeakMap();

    /**
     * Stamp a button state and swap its label; the idle label is kept on data-label
     * for the restore. A button with child elements (an override with an icon) keeps
     * its content: only the state attribute changes, and the retry words go to its
     * title until the next state. A new state cancels a pending return to idle.
     */
    function setButtonState(button, state) {
        if (!button || !button.isConnected) {
            return;
        }

        clearTimeout(idleTimers.get(button));
        idleTimers.delete(button);

        const kept = keptTitles.get(button);

        if (kept) {
            keptTitles.delete(button);

            // Put the title back only while it is still the retry label set here.
            if (button.getAttribute('title') === kept.label) {
                if (kept.title === null) {
                    button.removeAttribute('title');
                } else {
                    button.setAttribute('title', kept.title);
                }
            }
        }

        if (!state) {
            button.removeAttribute('data-state');
            button.removeAttribute('aria-busy');

            if (button.hasAttribute('data-label') && button.children.length === 0) {
                button.textContent = button.getAttribute('data-label');
            }

            return;
        }

        if (!button.hasAttribute('data-label')) {
            button.setAttribute('data-label', button.textContent.trim());
        }

        button.setAttribute('data-state', state);

        if (state === 'adding') {
            button.setAttribute('aria-busy', 'true');
        } else {
            button.removeAttribute('aria-busy');
        }

        const label = button.getAttribute('data-label-' + state);

        if (label && button.children.length === 0) {
            button.textContent = label;
        } else if (label && state === 'retry') {
            keptTitles.set(button, { title: button.getAttribute('title'), label: label });
            button.setAttribute('title', label);
        }
    }

    /** Return the button to idle after `ms`, unless another state is stamped first. */
    function idleAfter(button, ms) {
        idleTimers.set(button, setTimeout(() => setButtonState(button, null), ms));
    }

    /**
     * The add got no answer from the cart: show the retry state for a while. Nothing is
     * re-sent here; only the shopper's next click sends a new request.
     */
    function showRetry(button) {
        setButtonState(button, 'retry');
        idleAfter(button, RETRY_STATE_MS);
    }

    /**
     * The add route resolved against this page: its absolute URL while it stays on this
     * origin, otherwise null. Whatever address markup puts on a button, the buy form and
     * the session token are posted to this site only.
     */
    function sameOriginUrl(route) {
        let target;

        try {
            target = new URL(route, window.location.href);
        } catch (error) {
            return null;
        }

        return target.origin === window.location.origin ? target.href : null;
    }

    /**
     * The routing parameter a request parameter name stands for, or null when it is none
     * of them. The name is read as the server registers it: leading spaces are dropped
     * and it ends at the first NUL or '[', so " task", "task[x]" and a name continued
     * after a NUL are all the task. Case is ignored: a look-alike is judged, not passed.
     */
    function routingName(name) {
        const registered = String(name).replace(/^ +/, '').split('\u0000', 1)[0];
        const base = registered.split('[', 1)[0].toLowerCase();

        return ROUTING_NAMES.includes(base) ? base : null;
    }

    /**
     * Why a list of [name, value] request parameters would not be answered by the cart's
     * add task alone: the offending parameter's name, or null when the list is safe.
     * Refused are any controller, any array form of a routing parameter, any value under
     * one that is not text, another component than this one and another task than the
     * add. With taskRequired the list must itself name the add task, exactly once.
     */
    function dispatchRefusal(pairs, taskRequired) {
        let tasks = 0;

        for (const [name, value] of pairs) {
            const routing = routingName(name);

            if (routing === null) {
                continue;
            }

            if (routing === 'controller' || String(name).includes('[') || typeof value !== 'string') {
                return String(name);
            }

            if (routing === 'option' && value !== ADD_OPTION) {
                return String(name);
            }

            if (routing === 'task') {
                if (value.toLowerCase() !== ADD_TASK) {
                    return String(name);
                }

                tasks++;
            }
        }

        return taskRequired && tasks !== 1 ? 'task' : null;
    }

    /** Leave: stamp `closing`, remove once the layout's transition ends (or after a fallback delay). */
    function closeCard(card) {
        if (!card || card.getAttribute('data-state') === 'closing') {
            return;
        }

        clearTimeout(closeTimer);
        closeTimer = 0;
        card.setAttribute('data-state', 'closing');

        let done = false;
        const finish = () => {
            if (!done) {
                done = true;
                card.remove();
            }
        };

        card.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, LEAVE_FALLBACK_MS);
    }

    function armClose(card, ms) {
        clearTimeout(closeTimer);
        closeAt = Date.now() + ms;
        closeTimer = setTimeout(() => closeCard(card), ms);
    }

    /** Hovering or focusing the card holds it; leaving resumes with at least a short grace. */
    function pauseClose() {
        if (closeTimer) {
            clearTimeout(closeTimer);
            closeTimer = 0;
            remaining = Math.max(closeAt - Date.now(), RESUME_MIN_MS);
        }
    }

    function resumeClose(card) {
        if (!closeTimer && remaining > 0 && card.getAttribute('data-state') === 'open') {
            armClose(card, remaining);
        }
    }

    /**
     * Mount the outcome card: parse the server fragment, drop any card already showing,
     * append the new one and open it. Nothing mounts when the fragment carries no
     * [data-cart-added] root: an override that removed it fails closed.
     */
    function showCard(html) {
        // No card key at all = the confirmation is switched off; an empty card = a broken layout.
        if (html === undefined || html === null) {
            return;
        }

        const card = typeof html === 'string' && html.trim() !== ''
            ? new DOMParser().parseFromString(html, 'text/html').querySelector(CARD)
            : null;

        if (!card) {
            console.warn('addtocart: the response carries no [data-cart-added] card; check the cart/added layout (or its override)');
            return;
        }

        clearTimeout(closeTimer);
        closeTimer = 0;
        remaining = 0;
        document.querySelectorAll(CARD).forEach((previous) => previous.remove());

        document.body.appendChild(document.adoptNode(card));
        // Commit the closed styles first so the opening transition runs.
        void card.offsetWidth;
        card.setAttribute('data-state', 'open');

        const timeout = parseInt(card.getAttribute('data-timeout') || '0', 10);

        if (timeout > 0) {
            armClose(card, timeout);
            card.addEventListener('mouseenter', pauseClose);
            card.addEventListener('focusin', pauseClose);
            card.addEventListener('mouseleave', () => resumeClose(card));
            card.addEventListener('focusout', () => resumeClose(card));
        }
    }

    async function addToCart(button) {
        const item = button.closest('[data-item-id]');

        if (!item) {
            console.error('addtocart: [data-item-id] container not found');
            return;
        }

        const quantity = item.querySelector('input[name="quantity"]');

        if (!quantity) {
            console.error('addtocart: input[name="quantity"] not found');
            return;
        }

        const itemId = item.getAttribute('data-item-id');

        if (!itemId) {
            console.error('addtocart: data-item-id is empty');
            return;
        }

        // Send the whole buy form as multipart so the chosen configuration AND any
        // uploaded files travel with the add: sel[fieldId] (combination), opt[fieldId]
        // (options), conf[fieldId] (plugin payloads), jform[itemfields_payload] (full
        // composition) and conf_file[fieldId][slot] (artwork). FormData (not
        // URLSearchParams, which would drop files); the browser sets the multipart
        // Content-Type + boundary itself. The server resolves + validates + persists
        // it (CartService::add -> CartLineWriter -> onPrepareForCart).
        const form = item.querySelector('[data-item-form]');

        // Client-side gate (progressive enhancement: the server re-validates in
        // onPrepareForCart): an AJAX add bypasses the submit event, so ask the
        // engine directly.
        if (form && window.Alfa && window.Alfa.Validate) {
            const invalid = window.Alfa.Validate.init(form).checkAll();

            if (invalid) {
                invalid.focus();
                invalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }
        }

        const params = form ? new FormData(form) : new FormData();
        params.set('item_id', itemId);
        params.set('quantity', quantity.value);

        // The route the layout drew on this button; without it nothing is sent.
        const url = button.getAttribute('data-add-url');

        if (typeof url !== 'string' || url === '') {
            console.error('addtocart: this button carries no add-to-cart route (data-add-url); nothing was sent');
            showRetry(button);
            return;
        }

        // A route that leaves this origin is refused like a missing one.
        const target = sameOriginUrl(url);

        if (target === null) {
            console.error('addtocart: the add-to-cart route (data-add-url) is not on this site; nothing was sent');
            showRetry(button);
            return;
        }

        // The dispatch gate: the route must name the add task, and no posted field may
        // name another component, task or controller, since a posted one overrides the route.
        const routeRefusal = dispatchRefusal(new URL(target).searchParams, true);
        const refusal = routeRefusal !== null ? routeRefusal : dispatchRefusal(params.entries(), false);

        if (refusal !== null) {
            console.error('addtocart: the ' + (routeRefusal !== null ? 'add-to-cart route (data-add-url)' : 'buy form')
                + ' would not be answered by the cart\'s add task alone (parameter ' + JSON.stringify(refusal)
                + '); nothing was sent');
            showRetry(button);
            return;
        }

        // The request names its component and its task itself. A posted value outranks a
        // routed one, so whatever the route's path selects, only the cart's add answers.
        params.set('option', ADD_OPTION);
        params.set('task', POSTED_TASK);

        setButtonState(button, 'adding');

        let responseData;

        try {
            const response = await fetch(target, {
                method: 'POST',
                headers: {
                    // Says which representation this wants. The controller answers a native
                    // form post (which accepts text/html) with a redirect instead, so the
                    // same form works without JavaScript.
                    Accept: 'application/json',
                    'X-CSRF-Token': Joomla.getOptions('csrf.token', ''),
                },
                body: params,
            });

            // Answered from another address (a redirect the browser followed re-sends
            // the post as a GET) or not answered by the cart: no verdict on the add.
            if (response.redirected) {
                throw new Error('redirected to ' + response.url);
            }

            if (!response.ok) {
                throw new Error(response.status + ' ' + response.statusText);
            }

            // The cart declares its reply as JSON. A reply of another type is some other
            // page's answer, whatever its body would parse as.
            const replyType = (response.headers.get('Content-Type') || '').split(';', 1)[0].trim().toLowerCase();

            if (replyType !== 'application/json') {
                throw new Error('the reply is declared as ' + (replyType || 'no type') + ', not as JSON');
            }

            // A body that is not JSON (an error or login page) throws here.
            responseData = await response.json();

            if (responseData === null || typeof responseData !== 'object') {
                throw new Error('the reply is not the cart\'s JSON');
            }
        } catch (error) {
            console.error('addtocart: the cart gave no answer', error);
            showRetry(button);
            return;
        }

        const card = responseData.data && responseData.data.added;

        if (!responseData.success) {
            console.error('addtocart: rejected -', responseData.message);

            // A refusal that brought no card (a rejected security token) has no words
            // for the shopper: show the retry state rather than nothing.
            if (!responseData.data || typeof responseData.data !== 'object' || !('added' in responseData.data)) {
                showRetry(button);
                return;
            }

            setButtonState(button, null);
            showCard(card);
            return;
        }

        setButtonState(button, 'added');
        idleAfter(button, ADDED_STATE_MS);

        // Cancelable: a listener that presents the add itself claims the feedback.
        const event = new CustomEvent('alfaProductAdded', {
            cancelable: true,
            detail: { data: responseData },
        });
        document.dispatchEvent(event);

        if (event.defaultPrevented) {
            // Someone else presents this add (a cart drawer opening): no new card,
            // and a card still on screen from an earlier add leaves.
            closeCard(document.querySelector(CARD));
            return;
        }

        showCard(card);
    }

    // Delegated on document so the button survives variant.js container swaps (a
    // non-delegated listener is lost when the [data-item-id] container is replaced).
    document.addEventListener('click', (event) => {
        const close = event.target.closest(CLOSE);

        if (close) {
            event.preventDefault();
            closeCard(close.closest(CARD));
            return;
        }

        const button = event.target.closest('[data-action="cart-add"]');

        if (!button || button.disabled || button.getAttribute('data-state') === 'adding') {
            return;
        }

        event.preventDefault();
        addToCart(button);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeCard(document.querySelector(CARD));
        }
    });
})();
