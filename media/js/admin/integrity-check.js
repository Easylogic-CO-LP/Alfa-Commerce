/**
 * Alfa Admin — background integrity check.
 * ---------------------------------------------------------------------------
 * Independent of the notification UI on purpose. The badge + panel are server-rendered
 * (instant, no flicker); after the page has loaded this asks the notification centre to
 * refresh once — that refresh hits the panel endpoint, which runs the 24h-cached
 * integrity check (CDN fetch + file hashing) server-side and returns the fresh component.
 * So the one potentially-slow check never touches page render, and the badge updates only
 * if the integrity state actually changed.
 *
 * Event-driven, no polling: it triggers as soon as the notification centre is ready —
 * either it already is (immediate) or on its 'alfa-notifications-ready' event.
 *
 * @package  Com_Alfa
 * @since    1.0.5
 */
(function () {
    'use strict';

    /**
     * Run the integrity refresh, if the notification centre has exposed its API.
     */
    function trigger() {
        if (window.alfaNotifications && typeof window.alfaNotifications.refresh === 'function') {
            window.alfaNotifications.refresh();
        }
    }

    /**
     * Trigger now if the centre is already initialised, otherwise wait for its ready
     * event (fired once by notifications.js). Checking first closes the race where the
     * event fired before this listener was attached.
     */
    function start() {
        if (window.alfaNotifications && typeof window.alfaNotifications.refresh === 'function') {
            trigger();
        } else {
            window.addEventListener('alfa-notifications-ready', trigger, { once: true });
        }
    }

    // Defer to after page load — this is a background task, not part of render.
    if (document.readyState === 'complete') {
        start();
    } else {
        window.addEventListener('load', start);
    }
})();
