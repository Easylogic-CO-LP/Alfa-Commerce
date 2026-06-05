/**
 * Alfa Admin — Notification System
 *
 * Provides Alfa.notify() for toast notifications anywhere in admin.
 * Auto-creates the DOM element on first use.
 *
 * Usage:
 *   Alfa.notify('Order updated');                                // dark, auto-dismiss 4s
 *   Alfa.notify('Status → Shipped', 'success');                  // green, auto-dismiss 4s
 *   Alfa.notify('Failed to update', 'error');                    // red, STICKY until clicked
 *   Alfa.notify('Stock is low', 'warning');                      // amber, STICKY until clicked
 *   Alfa.notify('Copied!', 'success', 'short');                  // green, 1.8s
 *   Alfa.notify('Some info', 'info', 'sticky');                  // blue, STICKY (forced)
 *
 * Types:    'default' | 'success' | 'error' | 'warning' | 'info'
 *
 * Duration:
 *   'short'  — auto-dismiss 1.8s
 *   'medium' — auto-dismiss 2.5s
 *   'long'   — auto-dismiss 4s (default for success/default/info)
 *   'sticky' — stays until clicked (default for error/warning)
 *
 * Error/warning default to sticky so the admin must acknowledge.
 * Success/info auto-dismiss so the admin doesn't click after every save.
 * Any type can be forced to any duration by passing it explicitly.
 *
 * @package    Com_Alfa
 * @version    4.2.0
 * @since      4.1.0
 */
(function() {
    'use strict';

    window.Alfa = window.Alfa || {};

    /** Icon map — added before message text */
    var ICONS = {
        success: '✓',
        error:   '✗',
        warning: '⚠',
        info:    'ℹ',
        default: ''
    };

    /** Default duration per type — errors/warnings stick, others auto-dismiss */
    var DEFAULT_DURATION = {
        success: 'long',
        error:   'sticky',
        warning: 'sticky',
        info:    'long',
        default: 'long'
    };

    /**
     * Get or create the notification DOM element.
     * Auto-creates on first call — no PHP template changes needed.
     */
    function getNotificationElement() {
        var el = document.getElementById('alfa-notification');

        if (!el) {
            el = document.createElement('div');
            el.id = 'alfa-notification';
            el.setAttribute('role', 'alert');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);

            // Click anywhere on the notification to dismiss it
            el.addEventListener('click', function() {
                dismissNotification(el);
            });
        }

        return el;
    }

    /**
     * Immediately hide the notification (used by click handler + API).
     */
    function dismissNotification(el) {
        el.classList.remove('notify-in', 'notify-sticky', 'short', 'medium', 'long');
        el.style.opacity = '0';
        el.style.visibility = 'hidden';
        el.style.transform = 'translate(0, 100px)';
    }

    /**
     * Show a toast notification.
     *
     * @param  {string}  message   Text to display (can contain HTML)
     * @param  {string}  type      'success' | 'error' | 'warning' | 'info' | 'default'
     * @param  {string}  duration  'short' | 'medium' | 'long' | 'sticky' (or omit for type default)
     */
    window.Alfa.notify = function(message, type, duration) {
        type     = type || 'default';
        duration = duration || DEFAULT_DURATION[type] || 'long';

        var el       = getNotificationElement();
        var isSticky = (duration === 'sticky');

        // ── Build message HTML ──────────────────────────────
        var icon = ICONS[type] || '';
        var iconHtml = icon
            ? '<span class="notify-icon">' + icon + '</span>'
            : '';

        // Sticky notifications get a dismiss hint
        var dismissHtml = isSticky
            ? '<span class="notify-dismiss">✕</span>'
            : '';

        el.innerHTML = '<span class="notify-content">'
            + iconHtml + message
            + '</span>'
            + dismissHtml;

        // Color variant via data attribute (CSS handles styling)
        el.setAttribute('data-type', type);

        // ── Reset any existing animation ────────────────────
        el.classList.remove('notify-in', 'notify-sticky', 'short', 'medium', 'long');
        el.style.opacity = '';
        el.style.visibility = '';
        el.style.transform = '';

        // Force reflow so animation restarts cleanly
        void el.offsetWidth;

        // ── Start animation ─────────────────────────────────
        if (isSticky) {
            // Slides up and stays — admin must click to dismiss
            el.classList.add('notify-sticky');
        } else {
            // Slides up, stays visible, then slides back down
            el.classList.add('notify-in', duration);
        }
    };

    /**
     * Programmatic dismiss (for use by other scripts).
     * Alfa.dismissNotify()
     */
    window.Alfa.dismissNotify = function() {
        var el = document.getElementById('alfa-notification');
        if (el) {
            dismissNotification(el);
        }
    };

})();