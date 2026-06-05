/**
 * Alfa Admin — Notification Center (toolbar badge + quick panel)
 * ---------------------------------------------------------------------------
 * LAYOUT-DRIVEN component. The whole thing (button + panel) is server-rendered as one
 * `.alfa-notify` node by the badge layout; this script never builds content — on refresh
 * it re-fetches that same layout and swaps the node in place. All state is declarative:
 * the panel carries `data-open`, so the server renders it open/closed and the JS only
 * re-positions. The panel is position:fixed and rendered in place (not moved to <body>);
 * the JS just sets its top/left from the button.
 *
 * NOTE: unrelated to Alfa.notify() (toast.js) — that is transient toasts; this is the
 * persistent notification centre.
 *
 * Public API:   window.alfaNotifications.refresh()
 * Live refresh: 'alfa-notifications-changed' event · postMessage {alfaNotifications:'refresh'} · poll
 *
 * @package  Com_Alfa
 * @since    1.0.5
 */
(function () {
    'use strict';

    /**
     * Joomla.getOptions key the badge layout publishes its endpoints/token under
     * (see NotificationHelper::toolbarBadge()).
     */
    var OPTIONS_KEY = 'com_alfa.notifications';

    /**
     * The contract with the badge/panel layouts (badge.php, panel.php). Every selector
     * and state token the script touches lives here — change the markup, change one line.
     */
    var SEL = {
        root:      '.alfa-notify',              // component wrapper (the swapped node)
        button:    '[data-region="badge"]',     // the bell button (toggles + anchors)
        panel:     '[data-region="panel"]',     // the dropdown (carries open state)
        read:      '[data-action="read"]',       // per-item "mark read" action
        dismiss:   '[data-action="dismiss"]',    // per-item "dismiss" action
        openClass: 'show',                       // panel visible-state class
        openAttr:  'data-open',                  // panel open-state attribute ('1'/'0')
        openParam: 'open'                        // query param that preserves open across refresh
    };

    var opts; // resolved script options (panelUrl, markUrl, dismissUrl, token, poll)
    var root; // current `.alfa-notify` node (reassigned on every refresh swap)

    /**
     * The panel (dropdown) element within the current component.
     *
     * @return {Element|null}
     */
    function panel() {
        return root ? root.querySelector(SEL.panel) : null;
    }

    /**
     * The bell button within the current component.
     *
     * @return {Element|null}
     */
    function button() {
        return root ? root.querySelector(SEL.button) : null;
    }

    /**
     * Whether the panel is currently open (read from its declarative state attribute).
     *
     * @return {boolean}
     */
    function isOpen() {
        var p = panel();

        return !!p && p.getAttribute(SEL.openAttr) === '1';
    }

    /**
     * Set the panel's open state — updates both the data attribute (so a refresh can
     * read + preserve it) and the visibility class.
     *
     * @param {boolean} state
     */
    function setOpen(state) {
        var p = panel();

        if (!p) {
            return;
        }

        p.setAttribute(SEL.openAttr, state ? '1' : '0');
        p.classList.toggle(SEL.openClass, state);
    }

    /**
     * Anchor the position:fixed panel just under the bell, clamped inside the viewport
     * (it's fixed, so we set explicit top/left/width in viewport coordinates).
     */
    function position() {
        var b = button();
        var p = panel();

        if (!b || !p) {
            return;
        }

        var rect  = b.getBoundingClientRect();
        var vw    = window.innerWidth;
        var width = Math.min(380, vw - 16);
        var left  = rect.right - width;

        if (left + width > vw - 8) {
            left = vw - 8 - width;
        }

        if (left < 8) {
            left = 8;
        }

        p.style.width = width + 'px';
        p.style.left  = Math.round(left) + 'px';
        p.style.top   = Math.round(rect.bottom + 8) + 'px';
    }

    /**
     * Open the panel (and position it).
     */
    function open() {
        setOpen(true);
        position();
    }

    /**
     * Close the panel.
     */
    function close() {
        setOpen(false);
    }

    /**
     * Wire the current markup: the bell toggles the panel, and each item's read/dismiss
     * action POSTs then refreshes. Called on init and after every refresh swap.
     */
    function bind() {
        var b = button();
        var p = panel();

        if (!b || !p) {
            return;
        }

        b.addEventListener('click', function (e) {
            e.preventDefault();
            isOpen() ? close() : open();
        });

        p.querySelectorAll(SEL.read).forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                post(opts.markUrl, el.getAttribute('data-id'));
            });
        });

        p.querySelectorAll(SEL.dismiss).forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                post(opts.dismissUrl, el.getAttribute('data-id'));
            });
        });

        // The markup may arrive already-open (preserved across a refresh) — re-anchor it.
        if (isOpen()) {
            position();
        }
    }

    /**
     * POST a token-protected action for one notification (mark-read / dismiss), then
     * refresh so the swapped-in layout reflects the change.
     *
     * @param {string} url  Endpoint (opts.markUrl / opts.dismissUrl).
     * @param {string} id   Notification id.
     */
    function post(url, id) {
        if (!url || !id) {
            return;
        }

        var body = new URLSearchParams();
        body.append('id', id);
        body.append(opts.token, '1');

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        })
            .then(function () { refresh(); })
            .catch(function () {});
    }

    /**
     * Re-fetch the badge layout (passing the current open state so it comes back open if
     * it was open) and swap the whole `.alfa-notify` node in place. This is the only way
     * content changes — no DOM is built here. The endpoint also runs the integrity sync.
     */
    function refresh() {
        var url = opts.panelUrl + '&' + SEL.openParam + '=' + (isOpen() ? 1 : 0);

        fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (html) {
                if (html == null) {
                    return;
                }

                var tmp = document.createElement('div');
                tmp.innerHTML = html.trim();

                var fresh = tmp.querySelector(SEL.root);

                if (!fresh) {
                    return;
                }

                root.replaceWith(fresh);
                root = fresh;
                bind();

                if (isOpen()) {
                    position();
                }
            })
            .catch(function () {});
    }

    /**
     * Bootstrap: resolve options, grab the server-rendered component, bind it, and expose
     * the refresh API + live-refresh triggers. No initial fetch — the markup is already
     * rendered; integrity-check.js calls refresh() after load.
     */
    function init() {
        opts = (window.Joomla && Joomla.getOptions) ? Joomla.getOptions(OPTIONS_KEY, null) : null;

        if (!opts || !opts.panelUrl) {
            return;
        }

        root = document.querySelector(SEL.root);

        if (!root) {
            return;
        }

        bind();

        window.alfaNotifications = { refresh: refresh };
        window.addEventListener('alfa-notifications-changed', refresh);
        window.addEventListener('message', function (e) {
            // Only trust same-origin messages (or a same-window post) — never act on a
            // refresh request from an arbitrary origin.
            var sameOrigin = e.origin === window.location.origin;
            var sameWindow = !e.origin && e.source === window;

            if (!sameOrigin && !sameWindow) {
                return;
            }

            if (e.data && e.data.alfaNotifications === 'refresh') {
                refresh();
            }
        });

        if (opts.poll && opts.poll > 0) {
            setInterval(refresh, opts.poll * 1000);
        }

        // Announce readiness so integrity-check.js (and any other consumer) can call
        // refresh() on cue instead of polling for the API to appear.
        window.dispatchEvent(new Event('alfa-notifications-ready'));
    }

    // Global dismissers: close on outside-click / Escape, keep anchored on resize/scroll.
    document.addEventListener('click', function (e) {
        if (root && isOpen() && !root.contains(e.target)) {
            close();
        }
    });
    document.addEventListener('keydown', function (e) {
        if (root && isOpen() && e.key === 'Escape') {
            close();
        }
    });
    window.addEventListener('resize', function () {
        if (root && isOpen()) {
            position();
        }
    });
    window.addEventListener('scroll', function () {
        if (root && isOpen()) {
            position();
        }
    }, true);

    // Async-safe bootstrap (the script is loaded async).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
