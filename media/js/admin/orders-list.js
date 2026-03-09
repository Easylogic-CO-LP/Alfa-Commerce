/**
 * Orders List — Client-side interactions
 *
 * Handles list-view-specific behavior only:
 *   - Inline status dropdown AJAX (order.updateOrder endpoint)
 *   - Tooltip initialization (with Joomla 5 ES module retry)
 *   - Detail panel lazy-loading of plugin action buttons
 *   - Toast notifications via Alfa.notify() on status change
 *   - Dynamic list refresh via #alfa-app innerHTML swap (no full reload)
 *
 * Action execution is fully delegated to order-actions.js (Alfa.executeAction).
 * This file contains ZERO duplicated action logic — it only renders the
 * buttons and passes a list-specific onSuccess callback so that after an
 * action, the entire orders list is refreshed in-place rather than doing
 * a full page reload. This ensures all order rows reflect the latest state
 * (badges, statuses, amounts) because a plugin action can affect anything.
 *
 * Refresh strategy:
 *   fetch(window.location.href)         — same URL, same filters/pagination
 *   → DOMParser                         — parse full HTML response
 *   → extract #alfa-app innerHTML       — just the list, not chrome
 *   → swap into current #alfa-app       — seamless, no navigation
 *   → re-init tooltips + clear cache    — ready for next interaction
 *
 * Dependencies (loaded before this file via web asset manager):
 *   - com_alfa.admin.order-actions  (Alfa.executeAction, Alfa.showActionModal)
 *   - com_alfa.admin.notifications  (Alfa.notify)
 *
 * Registered as web asset: com_alfa.admin.orders-list
 * Loaded by: administrator/components/com_alfa/tmpl/orders/default.php
 *
 * @package    Com_Alfa
 * @subpackage Administrator.Assets
 * @version    5.1.0
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  2026 Easylogic CO LP
 * @license    GNU General Public License version 2 or later
 *
 * @since  4.1.0
 */
(function () {
    'use strict';

    // ═══════════════════════════════════════════════════════════════
    //  TOOLTIP INITIALIZATION
    //
    //  Joomla 5 loads Bootstrap as an ES module — it may not be
    //  available when DOMContentLoaded fires. We use data-tip /
    //  alfa-tip (not data-bs-toggle="tooltip") and retry at 500ms
    //  and 1500ms as fallback. Each element is only initialized once.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Initialize Bootstrap tooltips on elements with data-tip attribute.
     *
     * Called multiple times (on load + retries) because Bootstrap may
     * not be available immediately in Joomla 5's ES module system.
     * Also called after a dynamic list refresh to cover new DOM nodes.
     * Each element is only initialized once (tracked via _tipInit flag).
     */
    function initTooltips() {
        document.querySelectorAll('.alfa-tip[data-tip]').forEach(function (el) {
            if (el._tipInit) return;
            el._tipInit = true;

            var tip = el.getAttribute('data-tip');
            if (!tip) return;

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                new bootstrap.Tooltip(el, {
                    title:     tip,
                    html:      true,
                    placement: 'top',
                    trigger:   'hover'
                });
            } else {
                // Fallback: plain title attribute (works everywhere)
                el.setAttribute('title', tip.replace(/<br\s*\/?>/gi, ' | '));
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  SERVER MESSAGE PROCESSING
    //
    //  The controller includes Joomla's message queue in the JSON
    //  response (getMessageQueue). Messages come from save(), stock
    //  operations, and activity logging.
    //
    //  Only errors and warnings are shown as sticky toasts. Info/
    //  success messages go to console — the main action toast covers
    //  the primary result already.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Map Joomla message types → Alfa.notify types.
     *
     * Joomla uses: 'error', 'warning', 'message' (success), 'notice', 'info'
     * Alfa.notify:  'error', 'warning', 'success', 'info', 'default'
     */
    var MESSAGE_TYPE_MAP = {
        error:   'error',
        warning: 'warning',
        message: 'success',
        success: 'success',
        notice:  'info',
        info:    'info'
    };

    /**
     * Display server messages from Joomla's message queue.
     *
     * Errors and warnings become sticky toasts (admin must dismiss).
     * All other types are logged to console only.
     *
     * @param  {Array|null}  messages  From result.messages (Joomla getMessageQueue)
     */
    function showServerMessages(messages) {
        if (!messages || !Array.isArray(messages) || messages.length === 0) return;

        messages.forEach(function (msg, index) {
            if (!msg.message) return;

            if (msg.type === 'error' || msg.type === 'warning') {
                if (window.Alfa && Alfa.notify) {
                    setTimeout(function () {
                        Alfa.notify(msg.message, MESSAGE_TYPE_MAP[msg.type] || 'default');
                    }, (index + 1) * 400);
                }
            } else {
                console.log('[Alfa Orders] Server:', msg.message);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  DYNAMIC LIST REFRESH
    //
    //  Fetches the current page URL (preserving all active filters,
    //  search terms, pagination and sort order from window.location)
    //  and swaps only the #alfa-app div's innerHTML. The rest of the
    //  Joomla chrome (toolbar, sidebar, breadcrumbs) stays untouched.
    //
    //  Why fetch the whole page vs. a dedicated AJAX endpoint?
    //   - Zero extra PHP/controller code — existing view renders it
    //   - Filters, pagination, sort already in window.location.href
    //   - DOMParser extracts just the #alfa-app slice cleanly
    //   - Scripts in the parsed doc do NOT execute (DOMParser is safe)
    //
    //  Called by the onSuccess callback in handleActionClick() when
    //  result.refresh = true. A plugin action can affect any order row
    //  (delivery badges, payment status, fulfillment, amounts) so
    //  refreshing the full list is the only correct approach.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Whether a refresh fetch is already in progress.
     * Prevents double-firing if the admin clicks multiple buttons quickly.
     *
     * @type {boolean}
     */
    var refreshInProgress = false;

    /**
     * Dynamically refresh the orders list by swapping #alfa-app innerHTML.
     *
     * Flow:
     *   1. Guard against concurrent refreshes
     *   2. Dim the #alfa-app container (loading feedback)
     *   3. Fetch window.location.href with GET
     *   4. Parse full HTML response via DOMParser (scripts do NOT run)
     *   5. Extract #alfa-app from the parsed document
     *   6. Replace current #alfa-app innerHTML
     *   7. Clear loadedActions cache (panels collapse on swap)
     *   8. Re-initialize tooltips on new DOM nodes
     *   9. Restore opacity
     *
     * On any failure: restores the container and shows a warning toast.
     */
    function refreshOrdersList() {
        if (refreshInProgress) return;
        refreshInProgress = true;

        // ── Remember which panel was open before the swap ────────────
        // After innerHTML replacement all panels are collapsed.
        // We re-expand the same one so the admin keeps their context
        // and can see the updated state of the order they just acted on.
        var openPanelId = null;
        var openPanel   = document.querySelector('.collapse.show[data-order-id]');
        if (openPanel) {
            openPanelId = openPanel.getAttribute('data-order-id');
        }

        var appEl = document.getElementById('alfa-app');
        if (appEl) {
            appEl.style.opacity       = '0.5';
            appEl.style.pointerEvents = 'none';
            appEl.style.transition    = 'opacity 0.15s ease';
        }

        var refreshUrl = new URL(window.location.href);
        refreshUrl.searchParams.set('tmpl', 'component');

        fetch(refreshUrl.toString(), {
            method:  'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.text();
            })
            .then(function (html) {
                var parser   = new DOMParser();
                var doc      = parser.parseFromString(html, 'text/html');
                var freshApp = doc.getElementById('alfa-app');

                if (!freshApp) {
                    throw new Error('#alfa-app not found in response. Session may have expired.');
                }

                if (appEl) {
                    appEl.innerHTML = freshApp.innerHTML;
                }

                loadedActions = {};

                initTooltips();
                setTimeout(initTooltips, 300);

                if (appEl) {
                    appEl.style.opacity       = '';
                    appEl.style.pointerEvents = '';
                    appEl.style.transition    = '';
                }

                // ── Restore the previously open panel ────────────────
                // Use Bootstrap's Collapse API to re-expand the panel.
                // This also fires 'shown.bs.collapse' which triggers
                // loadOrderActions() — so fresh action buttons are loaded
                // automatically, no extra call needed.
                if (openPanelId) {
                    var restoredPanel = document.getElementById('od-' + openPanelId);
                    if (restoredPanel && typeof bootstrap !== 'undefined') {
                        // Small delay so the DOM settles after the innerHTML swap
                        setTimeout(function () {
                            bootstrap.Collapse.getOrCreateInstance(restoredPanel).show();
                        }, 50);
                    }
                }

                refreshInProgress = false;
            })
            .catch(function (error) {
                console.error('[Alfa Orders] List refresh failed:', error);

                if (appEl) {
                    appEl.style.opacity       = '';
                    appEl.style.pointerEvents = '';
                    appEl.style.transition    = '';
                }

                refreshInProgress = false;

                if (window.Alfa && Alfa.notify) {
                    Alfa.notify('List refresh failed — reload the page manually', 'warning');
                }
            });
    }

    // ═══════════════════════════════════════════════════════════════
    //  INLINE STATUS CHANGE — AJAX via updateOrder endpoint
    //
    //  When admin changes the status dropdown:
    //    1. Show "saving" CSS class (opacity + cursor)
    //    2. POST to order.updateOrder with { order_id, data: { id_order_status } }
    //    3. Parse response as text (handles PHP notices before JSON)
    //    4. Success: update colors, flash green, show toast
    //    5. Error: revert value, flash red, show toast
    //
    //  Status changes do NOT trigger refreshOrdersList() — the dropdown
    //  already reflects the new value visually and only the one row is
    //  affected. A full list refresh would be wasteful here.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Get the Joomla CSRF token via the framework options API.
     *
     * Joomla.getOptions('csrf.token') is the reliable Joomla 5 method.
     * Never scrape the DOM for the token — the key name is random per session.
     *
     * @return  {string}  Token name, or empty string
     */
    function getToken() {
        return (typeof Joomla !== 'undefined' && Joomla.getOptions('csrf.token')) || '';
    }

    /**
     * Sync dropdown background/text color from the selected option's
     * data-bg and data-color attributes.
     *
     * @param  {HTMLSelectElement}  select  The status dropdown
     */
    function updateSelectColors(select) {
        var opt = select.options[select.selectedIndex];
        if (opt) {
            select.style.backgroundColor = opt.getAttribute('data-bg')    || '#f0f0f0';
            select.style.color           = opt.getAttribute('data-color') || '#000';
        }
    }

    /**
     * Temporarily add a CSS class for visual feedback (green/red flash).
     *
     * @param  {HTMLElement}  el         Target element
     * @param  {string}       className  CSS class to add temporarily
     * @param  {number}       duration   Milliseconds (default 1500)
     */
    function flashClass(el, className, duration) {
        el.classList.add(className);
        setTimeout(function () { el.classList.remove(className); }, duration || 1500);
    }

    /**
     * Handle status dropdown change event.
     *
     * POSTs to order.updateOrder and manages visual state.
     * Does NOT trigger a full list refresh — only one row is affected
     * and the dropdown already shows the new value.
     *
     * @param  {Event}  event  The change event from the <select>
     */
    function handleStatusChange(event) {
        var select           = event.target;
        var orderId          = parseInt(select.getAttribute('data-order-id'), 10);
        var newStatusId      = parseInt(select.value, 10);
        var originalStatusId = parseInt(select.getAttribute('data-original'), 10);

        if (newStatusId === originalStatusId) return;

        select.classList.add('saving');

        var token = getToken();
        var url   = 'index.php?option=com_alfa&task=order.updateOrder';
        if (token) url += '&' + encodeURIComponent(token) + '=1';

        fetch(url, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                order_id: orderId,
                data:     { id_order_status: newStatusId }
            })
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    return { text: text, ok: response.ok };
                });
            })
            .then(function (raw) {
                select.classList.remove('saving');

                var result;
                try {
                    result = JSON.parse(raw.text);
                } catch (e) {
                    console.error('[Alfa Orders] Invalid JSON:', raw.text.substring(0, 500));
                    select.value = originalStatusId;
                    updateSelectColors(select);
                    flashClass(select, 'error', 2000);
                    if (window.Alfa && Alfa.notify) {
                        var cleanMsg = raw.text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 200);
                        Alfa.notify('Server error: ' + (cleanMsg || 'Invalid response'), 'error');
                    }
                    return;
                }

                if (result.success) {
                    updateSelectColors(select);
                    select.setAttribute('data-original', newStatusId);
                    flashClass(select, 'saved', 1500);
                    if (window.Alfa && Alfa.notify) {
                        var statusName = select.options[select.selectedIndex].text.trim();
                        Alfa.notify('Order #' + orderId + ' → ' + statusName, 'success', 'medium');
                    }
                    showServerMessages(result.messages);
                } else {
                    console.error('[Alfa Orders] Save failed:', result.message);
                    select.value = originalStatusId;
                    updateSelectColors(select);
                    flashClass(select, 'error', 2000);
                    if (window.Alfa && Alfa.notify) {
                        Alfa.notify(result.message || 'Failed to update status', 'error');
                    }
                    showServerMessages(result.messages);
                }
            })
            .catch(function (error) {
                console.error('[Alfa Orders] Network error:', error);
                select.classList.remove('saving');
                select.value = originalStatusId;
                updateSelectColors(select);
                flashClass(select, 'error', 2000);
                if (window.Alfa && Alfa.notify) {
                    Alfa.notify('Network error — status not saved', 'error');
                }
            });
    }

    // ═══════════════════════════════════════════════════════════════
    //  PLUGIN ACTIONS — Lazy-loaded for detail panels
    //
    //  When a detail panel opens, fetch available plugin actions for
    //  that order via getOrderActions. Buttons are rendered from the
    //  JSON response into .dp-actions placeholder divs.
    //
    //  On click, Alfa.executeAction() is called with a list-specific
    //  onSuccess callback that calls refreshOrdersList() on refresh,
    //  or navigates on redirect.
    // ═══════════════════════════════════════════════════════════════

    /**
     * Track which orders already have actions loaded.
     * Reassigned to {} by refreshOrdersList() after every list swap,
     * since panels collapse and buttons are replaced.
     *
     * @type {Object.<number, boolean>}
     */
    var loadedActions = {};

    /**
     * Load plugin actions for an order's shipments and payments.
     *
     * Fetches from order.getOrderActions and calls renderEntityActions()
     * for shipments and payments once the response arrives.
     *
     * @param  {number}  orderId  The order ID
     */
    function loadOrderActions(orderId) {
        if (loadedActions[orderId]) return;

        var token = getToken();
        var url   = 'index.php?option=com_alfa&task=order.getOrderActions&order_id=' + orderId;
        if (token) url += '&' + encodeURIComponent(token) + '=1';

        var panel = document.getElementById('od-' + orderId);
        if (panel) {
            panel.querySelectorAll('.dp-actions').forEach(function (el) {
                el.innerHTML = '<span class="action-loading">⟳</span>';
            });
        }

        fetch(url)
            .then(function (r) { return r.text(); })
            .then(function (text) {
                var result;
                try { result = JSON.parse(text); }
                catch (e) {
                    console.error('[Alfa Orders] Invalid JSON from getOrderActions:', text.substring(0, 200));
                    if (panel) panel.querySelectorAll('.dp-actions').forEach(function (el) { el.innerHTML = ''; });
                    return;
                }

                if (!result.success || !result.data) {
                    if (panel) panel.querySelectorAll('.dp-actions').forEach(function (el) { el.innerHTML = ''; });
                    return;
                }

                loadedActions[orderId] = true;
                renderEntityActions(panel, 'shipment', result.data.shipments || {});
                renderEntityActions(panel, 'payment',  result.data.payments  || {});
            })
            .catch(function (error) {
                console.error('[Alfa Orders] Error loading actions:', error);
                if (panel) panel.querySelectorAll('.dp-actions').forEach(function (el) { el.innerHTML = ''; });
            });
    }

    /**
     * Render action buttons into .dp-actions placeholder divs.
     *
     * Uses data attributes only (no inline onclick) — CSP-friendly.
     * Clicks bubble up to the delegated listener on #orderList.
     *
     * @param  {HTMLElement}  panel       The detail panel (div#od-N)
     * @param  {string}       entityType  'shipment' or 'payment'
     * @param  {object}       actionsMap  { entityId: [action, ...], ... }
     */
    function renderEntityActions(panel, entityType, actionsMap) {
        if (!panel) return;

        Object.keys(actionsMap).forEach(function (entityId) {
            var actions     = actionsMap[entityId];
            var placeholder = panel.querySelector(
                '.dp-actions[data-entity="' + entityType + '"][data-entity-id="' + entityId + '"]'
            );
            if (!placeholder) return;

            if (!actions || actions.length === 0) { placeholder.innerHTML = ''; return; }

            actions.sort(function (a, b) { return (b.priority || 0) - (a.priority || 0); });

            var html = '<div class="btn-group" role="group">';
            actions.forEach(function (action) {
                html +=
                    '<button type="button"'
                    + ' class="btn btn-sm ' + (action.class || 'btn-primary') + (action.enabled === false ? ' disabled' : '') + '"'
                    + ' data-action="'    + action.id  + '"'
                    + ' data-entity="'    + entityType + '"'
                    + ' data-entity-id="' + entityId   + '"'
                    + (action.tooltip ? ' title="' + action.tooltip + '"' : '')
                    + (action.requires_confirmation ? ' data-confirm="' + (action.confirmation_message || 'Are you sure?') + '"' : '')
                    + '>'
                    + (action.icon ? '<span class="icon-' + action.icon + ' me-1"></span>' : '')
                    + action.label
                    + '</button>';
            });
            html += '</div>';
            placeholder.innerHTML = html;
        });
    }

    /**
     * Handle action button clicks in detail panels.
     *
     * Delegates entirely to Alfa.executeAction() (order-actions.js).
     * The only list-specific logic is the onSuccess callback which
     * calls refreshOrdersList() instead of location.reload().
     *
     * Why refresh the whole list on every plugin action?
     *   A plugin can change anything: mark a payment refunded, update
     *   a shipment to delivered, trigger stock restoration — all of which
     *   affect row data outside the current detail panel. Refreshing the
     *   full list guarantees consistency with zero extra backend code.
     *
     * @param  {Event}  event  Click event delegated from #orderList
     */
    function handleActionClick(event) {
        var btn = event.target.closest('button[data-action]');
        if (!btn) return;

        var actionId   = btn.getAttribute('data-action');
        var entityType = btn.getAttribute('data-entity');
        var entityId   = parseInt(btn.getAttribute('data-entity-id'), 10);
        var confirmMsg = btn.getAttribute('data-confirm');

        if (confirmMsg && !confirm(confirmMsg)) return;

        /**
         * List-specific post-action callback.
         * Replaces Alfa._handleNavigation() for the list context.
         *
         * @param  {object}  result  Server response
         * @param  {string}  action  Executed action ID
         */
        var onSuccess = function (result, action) {
            if (result.redirect) {
                setTimeout(function () { window.location.href = result.redirect; }, 800);
            } else {
                // Fetch and swap the entire list — not just the actions panel.
                // Preserves current filters, search, and pagination exactly.
                refreshOrdersList();
            }
        };

        Alfa.executeAction(entityType, entityId, actionId, btn, {}, onSuccess);
    }

    // ═══════════════════════════════════════════════════════════════
    //  INITIALIZATION
    // ═══════════════════════════════════════════════════════════════

    document.addEventListener('DOMContentLoaded', function () {

        // ── Tooltips with retries for ES module Bootstrap ────────
        initTooltips();
        setTimeout(initTooltips, 500);
        setTimeout(initTooltips, 1500);

        var appContainer = document.getElementById('alfa-app');

        if (appContainer) {
            appContainer.addEventListener('change', function (event) {
                if (event.target.classList.contains('alfa-status-select')) {
                    handleStatusChange(event);
                }
            });

            appContainer.addEventListener('click', function (event) {
                if (event.target.closest('button[data-action]')) {
                    handleActionClick(event);
                }
            });
        }

        // ── Load actions when a detail panel opens ───────────────
        // Fires after Bootstrap collapse animation completes.
        // After refreshOrdersList() all panels are closed and
        // loadedActions is empty, so re-opening fetches fresh data.
        document.addEventListener('shown.bs.collapse', function (event) {
            var panel   = event.target;
            var orderId = panel.getAttribute('data-order-id');

            if (orderId && panel.id && panel.id.startsWith('od-')) {
                loadOrderActions(parseInt(orderId, 10));
            }
        });
    });

}());