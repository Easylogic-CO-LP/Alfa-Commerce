/**
 * Orders List: Client-side interactions
 *
 * Handles list-view-specific behavior only:
 *   - Inline status dropdown AJAX (order.updateOrder endpoint)
 *   - Tooltip initialization (with Joomla 5 ES module retry)
 *   - Detail panel lazy-loading of plugin action buttons
 *   - Toast notifications via Alfa.notify() on status change
 *   - Dynamic list refresh by replacing the list table (no full reload)
 *
 * Action execution is delegated to actions.js (Alfa.openAction). This file
 * only renders the buttons and registers Alfa.actionReload so that after an
 * action the orders list refreshes in-place rather than doing a full page
 * reload: every row reflects the latest state (badges, statuses, amounts),
 * since a plugin action can affect anything.
 *
 * Refresh strategy:
 *   fetch(window.location.href)         - same URL, same filters/pagination
 *   -> DOMParser                         - parse full HTML response
 *   -> surface its Joomla messages       - the fetch drains the session queue
 *   -> extract [data-alfa-list]          - just the table, not the chrome
 *   -> replace the live table            - seamless, no navigation
 *   -> dispatch joomla:updated           - searchtools / list-view re-bind
 *   -> re-init tooltips + clear cache    - ready for next interaction
 *
 * The searchtools bar, bulk bar and the form's control fields (task,
 * boxchecked, CSRF) sit outside the table and are deliberately never
 * touched; see the DYNAMIC LIST REFRESH block for why that matters.
 *
 * Dependencies (loaded before this file via web asset manager):
 *   - com_alfa.actions  (Alfa.openAction, Alfa.actionReload)
 *   - com_alfa.admin.notifications  (Alfa.notify)
 *
 * Registered as web asset: com_alfa.admin.orders-list
 * Loaded by: administrator/components/com_alfa/tmpl/orders/default.php
 *
 * @package    Alfa Commerce
 * @subpackage Administrator.Assets
 * @version    5.1.0
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 *
 * @since  1.0.0
 */
(function () {
    'use strict';

    //  Tooltip initialization
    //
    //  Joomla 5 loads Bootstrap as an ES module; it may not be
    //  available when DOMContentLoaded fires. We use data-tip /
    //  alfa-tip (not data-bs-toggle="tooltip") and retry at 500ms
    //  and 1500ms as fallback. Each element is only initialized once.

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

    //  Server message processing
    //
    //  The controller includes Joomla's message queue in the JSON
    //  response (getMessageQueue). Messages come from save(), stock
    //  operations, and activity logging.
    //
    //  Only errors and warnings are shown as sticky toasts. Info/
    //  success messages go to console; the main action toast covers
    //  the primary result already.

    /**
     * Map Joomla message types -> Alfa.notify types.
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
            }
        });
    }

    //  Dynamic list refresh
    //
    //  Fetches the current page URL (preserving all active filters,
    //  search terms, pagination and sort order from window.location)
    //  and replaces ONLY the list table. Everything above and below it
    //  inside #adminForm - the searchtools bar, the bulk bar, and the
    //  control fields (task, boxchecked, CSRF) - is left alone, plus
    //  all the Joomla chrome (toolbar, sidebar, breadcrumbs).
    //
    //  Why the table and not the whole #alfa-app wrapper?
    //  #alfa-app holds stable chrome as well as volatile data. Replacing
    //  it destroys the nodes Joomla bound listeners to at page load,
    //  most damagingly the boxchecked input, which every list-selection
    //  <joomla-toolbar-button> (Trash, Restore, Check-in, Empty Trash,
    //  the status dropdown) caches in connectedCallback and never re-reads.
    //  The toolbar sits outside #alfa-app, so it is never reconnected and
    //  those buttons freeze: worst case still enabled over an empty cid[].
    //  Keeping the swap inside the table means none of that is ours to
    //  resurrect: only the table's own contents need re-binding.
    //
    //  Why fetch the whole page vs. a dedicated AJAX endpoint?
    //   - Zero extra PHP/controller code; existing view renders it
    //   - Filters, pagination, sort already in window.location.href
    //   - DOMParser extracts just the table cleanly
    //   - Scripts in the parsed doc do NOT execute (DOMParser is safe)
    //
    //  Called by the onSuccess callback in handleActionClick() when
    //  result.refresh = true. A plugin action can affect any order row
    //  (delivery badges, payment status, fulfillment, amounts) so
    //  refreshing every row is the only correct approach.

    /**
     * Surface the Joomla messages carried by a refresh response.
     *
     * Rendering that response server-side drains the session message queue:
     * CMSApplication::getMessageQueue() moves it out of the session and clears
     * it. The message container is emitted outside the component (atum's
     * component.php renders jdoc message and component as siblings), so without
     * this the messages are lost twice over: discarded here, and already gone
     * from the session, so the next real page load shows nothing either.
     *
     * The payload rides in the head as script options; the rendered container is
     * <noscript>-wrapped and useless to copy. Shape is a list of {type: [text]},
     * matching Joomla's own boot in media/system/js/messages.js.
     *
     * @param  {Document}  doc  The parsed refresh response
     */
    function showResponseMessages(doc) {
        if (typeof Joomla === 'undefined' || !Joomla.renderMessages) return;

        var payload = null;

        doc.querySelectorAll('script.joomla-script-options').forEach(function (el) {
            if (payload) return;

            try {
                var opts = JSON.parse(el.textContent || '{}');
                if (opts['joomla.messages']) payload = opts['joomla.messages'];
            } catch (e) {
                // Not a parseable options blob; skip it.
            }
        });

        if (!payload) return;

        // keepOld: never wipe messages or toasts already on screen.
        Object.keys(payload).forEach(function (key) {
            Joomla.renderMessages(payload[key], undefined, true, undefined);
        });
    }

    /**
     * The refreshable list region, matched in both the live page and the
     * fetched response. Targets the data attribute rather than the id so a
     * template override can rename #orderList without breaking the refresh.
     *
     * Emitted by administrator/components/com_alfa/tmpl/orders/default.php.
     *
     * @type {string}
     */
    var LIST_SELECTOR = '[data-alfa-list="orders"]';

    /**
     * Whether a refresh fetch is already in progress.
     * Prevents double-firing if the admin clicks multiple buttons quickly.
     *
     * @type {boolean}
     */
    var refreshInProgress = false;

    /**
     * Dynamically refresh the orders list by replacing the list table.
     *
     * Flow:
     *   1. Guard against concurrent refreshes
     *   2. Dim the #alfa-app container (loading feedback)
     *   3. Fetch window.location.href with GET
     *   4. Parse full HTML response via DOMParser (scripts do NOT run)
     *   5. Render any Joomla messages the response carried
     *   6. Extract the list table from the parsed document
     *   7. Dispose the Collapse instances on the outgoing panels
     *   8. Replace the live table with the fetched one
     *   9. Dispatch joomla:updated so searchtools / list-view re-bind
     *  10. Clear loadedActions cache (panels collapse on swap)
     *  11. Re-initialize tooltips on new DOM nodes
     *  12. Restore opacity + re-expand the panels that were open
     *
     * On any failure: restores the container and shows a warning toast.
     */
    function refreshOrdersList() {
        if (refreshInProgress) return;
        refreshInProgress = true;

        // Remember which panels were open before the swap
        // The replacement table arrives with every panel collapsed. Panels carry
        // no data-bs-parent, so any number can be open at once; re-expand every
        // one of them, or the admin silently loses the context they had.
        var openPanelIds = [];
        document.querySelectorAll('.collapse.show[data-order-id]').forEach(function (panel) {
            openPanelIds.push(panel.getAttribute('data-order-id'));
        });

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
                var parser    = new DOMParser();
                var doc       = parser.parseFromString(html, 'text/html');

                // Before the not-found guards below: if the server refused the
                // refresh it usually said why, and that message is the only clue.
                showResponseMessages(doc);

                var freshList = doc.querySelector(LIST_SELECTOR);
                var liveList  = document.querySelector(LIST_SELECTOR);

                if (!freshList) {
                    throw new Error(LIST_SELECTOR + ' not found in response. Session may have expired.');
                }
                if (!liveList) {
                    throw new Error(LIST_SELECTOR + ' is gone from the page.');
                }

                // Bootstrap holds element->instance in a strong Map, so every panel we
                // detach stays reachable and pins the old list DOM behind it. Release
                // them before the node is thrown away.
                if (typeof bootstrap !== 'undefined') {
                    liveList.querySelectorAll('.collapse[data-order-id]').forEach(function (panel) {
                        var inst = bootstrap.Collapse.getInstance(panel);
                        if (inst) inst.dispose();
                    });
                }

                var newList = document.importNode(freshList, true);
                liveList.replaceWith(newList);

                // Joomla's "this subtree was replaced, re-bind" signal. The sort headers
                // (.js-stools-column-order), row checkboxes (.js-grid-item-is-checked) and
                // check-in padlocks (.js-grid-item-action) live inside the table and only
                // re-bind on this event. Dispatch it on the table, never on document:
                // searchtools and list-view.js scope their re-init to event.target, and
                // list-view.js has no re-entrancy guard; a document-wide target would
                // double-bind everything outside the list too.
                newList.dispatchEvent(new CustomEvent('joomla:updated', { bubbles: true }));

                loadedActions = {};

                initTooltips();
                setTimeout(initTooltips, 300);

                if (appEl) {
                    appEl.style.opacity       = '';
                    appEl.style.pointerEvents = '';
                    appEl.style.transition    = '';
                }

                // Restore the previously open panels
                // Use Bootstrap's Collapse API to re-expand each one. This also
                // fires 'shown.bs.collapse' which triggers loadOrderActions(),
                // so fresh action buttons are loaded automatically for every
                // restored panel, no extra call needed.
                if (openPanelIds.length && typeof bootstrap !== 'undefined') {
                    // Small delay so the DOM settles after the table swap
                    setTimeout(function () {
                        openPanelIds.forEach(function (panelId) {
                            var restoredPanel = document.getElementById('od-' + panelId);
                            if (restoredPanel) {
                                bootstrap.Collapse.getOrCreateInstance(restoredPanel).show();
                            }
                        });
                    }, 50);
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
                    Alfa.notify('List refresh failed; reload the page manually', 'warning');
                }
            });
    }

    //  INLINE STATUS CHANGE: AJAX via updateOrder endpoint
    //
    //  When admin changes the status dropdown:
    //    1. Show "saving" CSS class (opacity + cursor)
    //    2. POST to order.updateOrder with { order_id, data: { id_order_status } }
    //    3. Parse response as text (handles PHP notices before JSON)
    //    4. Success: update colors, flash green, show toast
    //    5. Error: revert value, flash red, show toast
    //
    //  Status changes do NOT trigger refreshOrdersList(); the dropdown
    //  already reflects the new value visually and only the one row is
    //  affected. A full list refresh would be wasteful here.

    /**
     * Get the Joomla CSRF token via the framework options API.
     *
     * Joomla.getOptions('csrf.token') is the reliable Joomla 5 method.
     * Never scrape the DOM for the token; the key name is random per session.
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
     * Does NOT trigger a full list refresh; only one row is affected
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
                        Alfa.notify('Order #' + orderId + ' -> ' + statusName, 'success', 'medium');
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
                    Alfa.notify('Network error: status not saved', 'error');
                }
            });
    }

    //  PLUGIN ACTIONS: Lazy-loaded for detail panels
    //
    //  When a detail panel opens, fetch available plugin actions for
    //  that order via getOrderActions. Buttons are rendered from the
    //  JSON response into .dp-actions placeholder divs.
    //
    //  On click, Alfa.openAction() opens the action page; the registered
    //  Alfa.actionReload (refreshOrdersList) swaps the rows when it finishes.

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
     * Uses data attributes only (no inline onclick), CSP-friendly.
     * Clicks bubble up to the delegated listener on #alfa-app.
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

            // Plugin-supplied action metadata is untrusted markup; escape every value
            // before it enters this innerHTML string, or a label/tooltip/class could
            // break out of its attribute (or inject script) into the admin list.
            var esc = function (s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            };

            var html = '<div class="btn-group" role="group">';
            actions.forEach(function (action) {
                html +=
                    '<button type="button"'
                    + ' class="btn btn-sm ' + esc(action.class || 'btn-primary') + (action.enabled === false ? ' disabled' : '') + '"'
                    + ' data-action="'    + esc(action.id)  + '"'
                    // A state-changing action must be SUBMITTED, not linked:
                    // the dispatcher refuses it over GET.
                    + ' data-mutates="'   + (action.mutates ? '1' : '0') + '"'
                    + ' data-entity="'    + esc(entityType) + '"'
                    + ' data-entity-id="' + esc(entityId)   + '"'
                    + (action.tooltip ? ' title="' + esc(action.tooltip) + '"' : '')
                    + (action.requires_confirmation ? ' data-confirm="' + esc(action.confirmation_message || 'Are you sure?') + '"' : '')
                    // Fields the action posts, e.g. the figure its confirmation named.
                    + ' data-fields="' + esc(JSON.stringify(action.data || {})) + '"'
                    + '>'
                    + (action.icon ? '<span class="icon-' + esc(action.icon) + ' me-1"></span>' : '')
                    + esc(action.label)
                    + '</button>';
            });
            html += '</div>';
            placeholder.innerHTML = html;
        });
    }

    /**
     * Handle action button clicks in detail panels.
     *
     * Opens the action page (Alfa.openAction) like every other surface; when
     * it finishes, the registered Alfa.actionReload (refreshOrdersList) swaps
     * the list rows in place, preserving filters, search and pagination.
     *
     * @param  {Event}  event  Click event delegated from #alfa-app
     */
    function handleActionClick(event) {
        var btn = event.target.closest('button[data-action]');
        if (!btn) return;

        var confirmMsg = btn.getAttribute('data-confirm');
        if (confirmMsg && !confirm(confirmMsg)) return;

        Alfa.openAction({
            context: btn.getAttribute('data-entity'),
            id:      parseInt(btn.getAttribute('data-entity-id'), 10),
            action:  btn.getAttribute('data-action'),
            data:    JSON.parse(btn.getAttribute('data-fields') || '{}'),
            title:   (btn.textContent || '').trim(),
            mutates: btn.getAttribute('data-mutates') === '1'
        });
    }

    //  Initialization

    document.addEventListener('DOMContentLoaded', function () {

        // Tooltips with retries for ES module Bootstrap
        initTooltips();
        setTimeout(initTooltips, 500);
        setTimeout(initTooltips, 1500);

        // After any action finishes, swap the list rows in place rather than a
        // full reload: keeps filters, search and pagination exactly as they are.
        if (window.Alfa) {
            Alfa.actionReload = refreshOrdersList;
        }

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

        // Load actions when a detail panel opens
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
